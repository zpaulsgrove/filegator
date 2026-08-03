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
 * Encrypted-at-rest store for generated activity reports.
 *
 * A report is a decrypted, flattened copy of a month of the audit log —
 * usernames, roles and full paths — so it gets the same treatment the log
 * itself gets: a dedicated libsodium key (key separation from both the MFA
 * secret key and the audit log key), 0600 files in a 0700 directory, and an
 * index that never leaves private/.
 *
 * Retention is a SECOND retention policy, not a cache. AuditLog::max_age_days
 * physically deletes raw events; if reports outlived that by much, this
 * directory would quietly rebuild the PII store the retention policy exists to
 * destroy, in blobs searchable only by decrypting all of them. `max_age_days`
 * here is therefore deliberately close to the log's, and the config comment
 * says so.
 *
 * What the encryption does and does not buy, stated plainly because it is easy
 * to overestimate: it protects a LEAKED FILE OR BACKUP. It does not protect
 * against a compromised web process, which holds the key — the same caveat
 * docs/configuration/audit-log.md already makes for the log. It also
 * authenticates the report BODY only: secretbox has no AAD here, so the
 * surrounding metadata in index.json is neither encrypted nor authenticated,
 * and ciphertext length still discloses roughly how much activity a month held.
 *
 * Unconfigured = safe no-op, matching AuditLog: PHP-DI will autowire an
 * un-init()'d instance if a deployment never registers the service, so every
 * public method guards on $dir.
 */
class ReportStore implements Service
{
    const DEFAULT_MAX_AGE_DAYS = 100;

    const DEFAULT_MAX_COUNT = 24;

    const INDEX_FILE = 'index.json';

    protected $logger;

    protected $dir = null;

    protected $keyPath = null;

    protected $crypto = null;

    protected $maxAgeDays = self::DEFAULT_MAX_AGE_DAYS;

    protected $maxCount = self::DEFAULT_MAX_COUNT;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function init(array $config = [])
    {
        if (empty($config['dir']) || empty($config['key_path'])) {
            return;
        }

        $this->dir = rtrim((string) $config['dir'], '/');
        $this->keyPath = (string) $config['key_path'];
        $this->crypto = new MfaSecretCrypto();
        $this->crypto->init(['key_path' => $this->keyPath]);

        if (isset($config['max_age_days']) && (int) $config['max_age_days'] > 0) {
            $this->maxAgeDays = (int) $config['max_age_days'];
        }
        if (isset($config['max_count']) && (int) $config['max_count'] > 0) {
            $this->maxCount = (int) $config['max_count'];
        }
    }

    public function isConfigured(): bool
    {
        return $this->dir !== null && $this->crypto !== null;
    }

    /**
     * Encrypt and store one report. Returns its id, or null on failure.
     *
     * The id is 16 random bytes rather than the period string: the period is
     * guessable, and while the route is admin-gated, an unguessable id means a
     * report is not enumerable even if that gate is ever loosened.
     */
    public function write(string $period, string $csv, array $meta = []): ?string
    {
        if (! $this->isConfigured() || ! $this->ensureDir()) {
            return null;
        }

        $id = bin2hex(random_bytes(16));
        $target = $this->dir.'/'.$id.'.csv.enc';
        $tmp = $target.'.tmp';

        $prev = umask(0077);

        try {
            $cipher = $this->crypto->encrypt($csv);

            // Write to a temp name and rename into place. rename() is atomic
            // within a filesystem, so a cron killed mid-write can never leave a
            // truncated ciphertext — which would be indistinguishable from
            // corruption, since a partial secretbox simply fails to open.
            if (@file_put_contents($tmp, $cipher, LOCK_EX) === false) {
                $this->logger->log('ReportStore: cannot write '.$tmp, \Monolog\Logger::WARNING);

                return null;
            }
            @chmod($tmp, 0600);

            if (! @rename($tmp, $target)) {
                @unlink($tmp);
                $this->logger->log('ReportStore: cannot rename into '.$target, \Monolog\Logger::WARNING);

                return null;
            }
            @chmod($target, 0600);
        } catch (\Throwable $e) {
            @unlink($tmp);
            $this->logger->log('ReportStore: write failed: '.$e->getMessage(), \Monolog\Logger::WARNING);

            return null;
        } finally {
            umask($prev);
        }

        $this->updateIndex(function (array $index) use ($id, $period, $meta, $csv) {
            // One report per period. A regeneration (--force, or a recovered
            // failure) must REPLACE, not accumulate: two entries for one month
            // means two copies of the same PII at rest, and findByPeriod would
            // serve whichever happened to sort first.
            foreach ($index as $existingId => $row) {
                if (($row['period'] ?? null) === $period && $existingId !== $id) {
                    @unlink($this->pathFor($existingId));
                    unset($index[$existingId]);
                }
            }

            $index[$id] = array_merge([
                'id' => $id,
                'period' => $period,
                'generated_at' => time(),
                'bytes' => strlen($csv),
            ], $meta);

            return $index;
        });

        // The ciphertext is worthless if it never reached the index: it would be
        // invisible to listReports() and untouchable by collectGarbage(), so a
        // month of PII would sit on disk forever with nothing tracking it. A
        // root-owned index.json (the ownership mismatch checkOwnership warns
        // about) is exactly how this happens. Report the failure instead.
        if (! isset($this->readIndex()[$id])) {
            @unlink($target);
            $this->logger->log(
                'ReportStore: index entry for '.$period.' could not be recorded; discarded the artifact '
                .'rather than orphaning it outside the index',
                \Monolog\Logger::WARNING
            );

            return null;
        }

        return $id;
    }

    /**
     * Metadata for every stored report, newest first. Never includes event
     * data — this is what the admin list endpoint returns.
     */
    public function listReports(): array
    {
        $index = $this->readIndex();

        // An index entry whose file has been removed by hand would otherwise
        // present as a downloadable report that 404s.
        $index = array_filter($index, function ($row) {
            return isset($row['id']) && is_file($this->pathFor($row['id']));
        });

        usort($index, function ($a, $b) {
            return ($b['generated_at'] ?? 0) <=> ($a['generated_at'] ?? 0);
        });

        return array_values($index);
    }

    public function find(string $id): ?array
    {
        if (! $this->isConfigured() || ! $this->validId($id)) {
            return null;
        }
        $index = $this->readIndex();

        if (! isset($index[$id]) || ! is_file($this->pathFor($id))) {
            return null;
        }

        return $index[$id];
    }

    public function findByPeriod(string $period): ?array
    {
        foreach ($this->listReports() as $row) {
            if (($row['period'] ?? null) === $period) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Decrypted CSV, or null if the report is missing or undecryptable.
     *
     * Callers MUST treat null as an error and never stream it as an empty body:
     * MfaSecretCrypto::decrypt() also returns null when the key file cannot be
     * read (e.g. created root-owned by a misconfigured cron), and a 200 OK with
     * an empty CSV would be a silently wrong compliance artifact.
     */
    public function readDecrypted(string $id): ?string
    {
        if ($this->find($id) === null) {
            return null;
        }

        $cipher = @file_get_contents($this->pathFor($id));
        if ($cipher === false) {
            return null;
        }

        return $this->crypto->decrypt($cipher);
    }

    /**
     * Drop reports past `max_age_days`, then trim to `max_count` oldest-first.
     *
     * Deterministic, not probabilistic like Tmpfs's gc_probability_perc: a cron
     * runs on a known schedule and has no reason to sample. Returns the number
     * removed so the caller can log it.
     */
    public function collectGarbage(?int $now = null): int
    {
        if (! $this->isConfigured()) {
            return 0;
        }

        $now = $now ?? time();
        $cutoff = $now - ($this->maxAgeDays * 86400);
        $removed = 0;

        $this->updateIndex(function (array $index) use ($cutoff, &$removed) {
            foreach ($index as $id => $row) {
                if (($row['generated_at'] ?? 0) < $cutoff) {
                    @unlink($this->pathFor($id));
                    unset($index[$id]);
                    $removed++;
                }
            }

            if (count($index) > $this->maxCount) {
                uasort($index, function ($a, $b) {
                    return ($a['generated_at'] ?? 0) <=> ($b['generated_at'] ?? 0);
                });
                foreach (array_slice(array_keys($index), 0, count($index) - $this->maxCount) as $id) {
                    @unlink($this->pathFor($id));
                    unset($index[$id]);
                    $removed++;
                }
            }

            return $index;
        });

        return $removed;
    }

    protected function pathFor(string $id): string
    {
        return $this->dir.'/'.$id.'.csv.enc';
    }

    /**
     * Ids are generated by this class, so anything not matching the generated
     * shape is rejected outright rather than sanitised into something that
     * might still resolve.
     */
    protected function validId(string $id): bool
    {
        return preg_match('/^[0-9a-f]{32}$/', $id) === 1;
    }

    protected function ensureDir(): bool
    {
        if (is_dir($this->dir)) {
            return true;
        }

        // 0700, not the 0775 used elsewhere in the tree: the documented Ubuntu
        // install runs `chmod -R 775`, which would otherwise leave a directory
        // of decrypted-on-demand PII listable by any local user.
        $prev = umask(0077);

        try {
            $ok = @mkdir($this->dir, 0700, true) || is_dir($this->dir);
            if ($ok) {
                @chmod($this->dir, 0700);
            } else {
                $this->logger->log('ReportStore: cannot create '.$this->dir, \Monolog\Logger::WARNING);
            }

            return $ok;
        } finally {
            umask($prev);
        }
    }

    protected function indexPath(): string
    {
        return $this->dir.'/'.self::INDEX_FILE;
    }

    protected function readIndex(): array
    {
        if (! $this->isConfigured() || ! is_file($this->indexPath())) {
            return [];
        }

        $raw = @file_get_contents($this->indexPath());
        if ($raw === false || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        // A truncated index must degrade to "no reports", never a fatal — the
        // admin page should still load so the operator can see something is off.
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Read-modify-write the index under an exclusive lock, so a file and its
     * index entry can never disagree.
     */
    protected function updateIndex(callable $mutate): void
    {
        if (! $this->isConfigured() || ! $this->ensureDir()) {
            return;
        }

        $prev = umask(0077);

        try {
            $fh = @fopen($this->indexPath(), 'c+b');
            if ($fh === false) {
                $this->logger->log('ReportStore: cannot open index', \Monolog\Logger::WARNING);

                return;
            }

            try {
                flock($fh, LOCK_EX);
                $raw = stream_get_contents($fh);
                $index = $raw === '' ? [] : json_decode($raw, true);
                $index = is_array($index) ? $index : [];

                $index = $mutate($index);

                ftruncate($fh, 0);
                rewind($fh);
                fwrite($fh, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                fflush($fh);
            } finally {
                flock($fh, LOCK_UN);
                fclose($fh);
            }

            @chmod($this->indexPath(), 0600);
        } finally {
            umask($prev);
        }
    }
}
