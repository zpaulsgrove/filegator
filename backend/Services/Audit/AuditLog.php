<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Filegator\Services\Audit;

use Filegator\Services\Logger\LoggerInterface;
use Filegator\Services\Mfa\MfaSecretCrypto;
use Filegator\Services\Service;

/**
 * Append-only, encrypted activity log of file write-mutations across all
 * users and folders, surfaced to admins via AdminController::auditLog.
 *
 * Storage: one record per line in `log_file`, formatted `<unix-ts>\t<cipher>`.
 * The cipher is the libsodium secretbox of a single json_encode'd event
 * object — so the sensitive body (user/role/path/ip) is opaque at rest and a
 * leaked copy/backup is useless without the key. The cleartext ts prefix lets
 * retention pruning and time-range queries filter without decrypting every
 * line. The plaintext event shape is:
 *
 *   {"ts":<unix>,"user":"alice","role":"user","action":"delete",
 *    "path":"/clientA/2026/return.pdf","detail":null,"ip":"203.0.113.7"}
 *
 * Encryption reuses MfaSecretCrypto (XSalsa20+Poly1305) pointed at a
 * DEDICATED key file (`key_path`) — key separation from the MFA secret key.
 * Reusing the class avoids forking the mutation-tested MFA crypto.
 *
 * Because each line is the ciphertext of a whole json_encode'd object, an
 * attacker-controlled filename cannot forge a second log line: json_encode
 * escapes newlines/quotes, and the bytes are then encrypted anyway.
 *
 * Unconfigured = safe no-op. App.php only calls init() for services listed
 * in the *active* configuration.php. If a deployment never registers this
 * service, PHP-DI still autowires it as a controller-method param — as a
 * fresh, un-init()'d instance. So $logFile defaults to null and record()/
 * query() guard on it; the feature simply does nothing until configured.
 * record() additionally swallows every error (logging it) so a failing audit
 * can never turn a successful file operation into a 500.
 *
 * Retention: entries older than `max_age_days` are physically deleted (not
 * just hidden). The prune is lazy — it runs inside record() under the write
 * lock at most once per day (tracked by a sidecar marker file), so the
 * common append stays O(1) and one write per day pays the rewrite.
 */
class AuditLog implements Service
{
    const ACTION_UPLOAD = 'upload';
    const ACTION_CREATE = 'create';
    const ACTION_COPY = 'copy';
    const ACTION_MOVE = 'move';
    const ACTION_RENAME = 'rename';
    const ACTION_DELETE = 'delete';
    const ACTION_ZIP = 'zip';
    const ACTION_UNZIP = 'unzip';
    const ACTION_CHMOD = 'chmod';
    const ACTION_SAVE = 'save';

    const ACTIONS = [
        self::ACTION_UPLOAD,
        self::ACTION_CREATE,
        self::ACTION_COPY,
        self::ACTION_MOVE,
        self::ACTION_RENAME,
        self::ACTION_DELETE,
        self::ACTION_ZIP,
        self::ACTION_UNZIP,
        self::ACTION_CHMOD,
        self::ACTION_SAVE,
    ];

    const DEFAULT_MAX_AGE_DAYS = 30;

    const PRUNE_INTERVAL_SECONDS = 86400; // run the retention sweep at most once/day

    protected $logger;

    /** @var MfaSecretCrypto|null secretbox helper; null until configured */
    protected $crypto = null;

    /** @var string|null absolute path to the log file; null = disabled */
    protected $logFile = null;

    protected $maxAgeDays = self::DEFAULT_MAX_AGE_DAYS;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function init(array $config = [])
    {
        // Both a log file and a key path are required; absence => disabled.
        if (empty($config['log_file']) || empty($config['key_path'])) {
            return;
        }

        $this->logFile = (string) $config['log_file'];

        if (isset($config['max_age_days']) && (int) $config['max_age_days'] > 0) {
            $this->maxAgeDays = (int) $config['max_age_days'];
        }

        $this->crypto = new MfaSecretCrypto();
        $this->crypto->init(['key_path' => (string) $config['key_path']]);

        $this->ensureLogFile();
    }

    /**
     * Append one event. Fills `ts` when missing, encrypts the line, and
     * appends under an exclusive lock (running the lazy prune first). Never
     * throws: any failure is logged and swallowed so a file operation that
     * already succeeded is not turned into an error.
     */
    public function record(array $event): void
    {
        $this->recordMany([$event]);
    }

    /**
     * Append one or more events under a SINGLE exclusive lock so a bulk
     * operation (delete/copy/move 500 files) pays one open/lock/flush instead
     * of N. Fills `ts` when missing. Never throws: any failure is logged and
     * swallowed so a file operation that already succeeded is not turned into
     * an error.
     *
     * Line format: `<unix-ts>\t<ciphertext>`. The cleartext ts prefix lets
     * retention pruning and time-range queries filter WITHOUT decrypting every
     * line; the event body (user/role/path/detail/ip) stays encrypted.
     *
     * @param array<int,array<string,mixed>> $events
     */
    public function recordMany(array $events): void
    {
        if (! $this->logFile || ! $this->crypto || empty($events)) {
            return;
        }

        try {
            $lines = [];
            foreach ($events as $event) {
                if (! isset($event['ts'])) {
                    $event['ts'] = time();
                }
                $json = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                $lines[] = ((int) $event['ts'])."\t".$this->crypto->encrypt($json);
            }

            $fh = @fopen($this->logFile, 'c+b');
            if ($fh === false) {
                $this->logger->log('AuditLog: cannot open log file '.$this->logFile);
                return;
            }

            try {
                flock($fh, LOCK_EX);
                $this->maybePrune($fh);
                fseek($fh, 0, SEEK_END);
                fwrite($fh, implode("\n", $lines)."\n");
                fflush($fh);
            } finally {
                flock($fh, LOCK_UN);
                fclose($fh);
            }
        } catch (\Throwable $e) {
            // An audit write must never break the user's file operation.
            try {
                $this->logger->log('AuditLog: record failed: '.$e->getMessage());
            } catch (\Throwable $ignored) {
                // logging is best-effort too
            }
        }
    }

    /**
     * Return matching events, newest first. Filters: `action` (exact),
     * `user` (exact), `from`/`to` (inclusive unix-epoch bounds). Entries
     * past the retention window are excluded even if a prune hasn't run yet.
     * Undecryptable/malformed lines are skipped rather than failing the read.
     *
     * @return array<int,array<string,mixed>>
     */
    public function query(array $filters = []): array
    {
        if (! $this->logFile || ! $this->crypto || ! is_file($this->logFile)) {
            return [];
        }

        $action = isset($filters['action']) && $filters['action'] !== '' ? (string) $filters['action'] : null;
        $user = isset($filters['user']) && $filters['user'] !== '' ? (string) $filters['user'] : null;
        $from = isset($filters['from']) && $filters['from'] !== '' ? (int) $filters['from'] : null;
        $to = isset($filters['to']) && $filters['to'] !== '' ? (int) $filters['to'] : null;
        $cutoff = time() - ($this->maxAgeDays * 86400);

        $events = [];
        $fh = @fopen($this->logFile, 'rb');
        if ($fh === false) {
            return [];
        }

        try {
            flock($fh, LOCK_SH);
            while (($raw = fgets($fh)) !== false) {
                $raw = rtrim($raw, "\r\n");
                if ($raw === '') {
                    continue;
                }
                $tab = strpos($raw, "\t");
                if ($tab === false) {
                    continue; // not our format (legacy/corrupt)
                }
                $ts = (int) substr($raw, 0, $tab);

                // Cheap ts pre-filter against the cleartext prefix: skip
                // retention-expired and out-of-range lines WITHOUT decrypting.
                if ($ts < $cutoff || ($from !== null && $ts < $from) || ($to !== null && $ts > $to)) {
                    continue;
                }

                $plain = $this->crypto->decrypt(substr($raw, $tab + 1));
                if ($plain === null) {
                    continue;
                }
                $evt = json_decode($plain, true);
                if (! is_array($evt)) {
                    continue;
                }
                if ($action !== null && ($evt['action'] ?? null) !== $action) {
                    continue;
                }
                if ($user !== null && ($evt['user'] ?? null) !== $user) {
                    continue;
                }
                $events[] = $evt;
            }
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }

        usort($events, function ($a, $b) {
            return ((int) ($b['ts'] ?? 0)) <=> ((int) ($a['ts'] ?? 0));
        });

        return $events;
    }

    /**
     * Delete entries older than the retention window, in place, while the
     * caller holds LOCK_EX on $fh. Gated to once per day via a sidecar
     * marker so most appends skip the O(n) rewrite. Kept lines are written
     * back verbatim (already-encrypted) — no re-encryption needed.
     *
     * @param resource $fh
     */
    protected function maybePrune($fh): void
    {
        $marker = $this->logFile.'.pruned';
        $now = time();
        $last = is_file($marker) ? (int) @file_get_contents($marker) : 0;
        if ($last !== 0 && ($now - $last) < self::PRUNE_INTERVAL_SECONDS) {
            return;
        }

        $cutoff = $now - ($this->maxAgeDays * 86400);

        rewind($fh);
        $kept = [];
        while (($raw = fgets($fh)) !== false) {
            $line = rtrim($raw, "\r\n");
            if ($line === '') {
                continue;
            }
            $tab = strpos($line, "\t");
            if ($tab === false) {
                continue; // drop malformed/legacy lines
            }
            // Retention uses the cleartext ts prefix — no decrypt, so the
            // once/day sweep does not pay crypto while holding the write lock.
            if (((int) substr($line, 0, $tab)) >= $cutoff) {
                $kept[] = $line;
            }
        }

        ftruncate($fh, 0);
        rewind($fh);
        if (! empty($kept)) {
            fwrite($fh, implode("\n", $kept)."\n");
        }
        fflush($fh);

        $this->writeMarker($marker, $now);
    }

    protected function writeMarker(string $marker, int $now): void
    {
        $prev = umask(0077);
        try {
            @file_put_contents($marker, (string) $now);
            @chmod($marker, 0600);
        } finally {
            umask($prev);
        }
    }

    /**
     * Create the log file at 0600 before first write so the PII it holds is
     * never world-readable, even briefly, on shared hosts. umask(0077)
     * closes the create→chmod TOCTOU window.
     */
    protected function ensureLogFile(): void
    {
        if (is_file($this->logFile)) {
            return;
        }
        $prev = umask(0077);
        try {
            @touch($this->logFile);
            @chmod($this->logFile, 0600);
        } finally {
            umask($prev);
        }
    }
}
