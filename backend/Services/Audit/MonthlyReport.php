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
use Filegator\Services\Service;

/**
 * Builds the monthly file-activity report and hands it to ReportStore.
 *
 * Driven by system cron through bin/filegator, NOT piggy-backed on an HTTP
 * request the way WeeklyDigest is. WeeklyDigest deliberately chose in-app
 * firing so the deployment stays self-contained, accepting that "weekly" means
 * "the next admin who loads the user list". A compliance artifact cannot
 * inherit that: a month with no admin logins would simply have no report.
 *
 * RUN THE CRON DAILY, not monthly. The job is idempotent per calendar period,
 * so a daily tick is a no-op on ~30 days out of 31 and RETRIES a month that
 * failed. `0 3 1 * *` gives exactly one attempt per month, and a host, mail
 * server or config error at that moment loses the month permanently.
 *
 * Two departures from the WeeklyDigest precedent, both deliberate:
 *
 *  - State is keyed by CALENDAR PERIOD ("2026-07"), not by an elapsed-seconds
 *    timestamp. Thirty days is not a month; an interval drifts out of alignment
 *    within a year, and a gap in a period map is detectable where a stale
 *    timestamp is not.
 *
 *  - Failure RETRIES. WeeklyDigest advances its timestamp before dispatching
 *    because it fires from an HTTP path, where retrying against a down mail
 *    server would mean dozens of attempts a day. Cron has neither property, so
 *    a period is marked complete only once its artifact is on disk.
 *
 * The two failure domains are tracked separately, because conflating them
 * costs the artifact. Generation failing means there is no report and must be
 * retried; NOTIFICATION failing means the report exists and is downloadable,
 * so it must never trigger regeneration — that would mint a new report id and
 * orphan a link an admin may already be holding.
 *
 * This class deliberately does NOT compute per-user or per-folder rollups. The
 * notification carries no PII, and computing PII you then have to remember not
 * to send is a trap that survives exactly one refactor.
 */
class MonthlyReport implements Service
{
    const DEFAULT_BACKFILL_MONTHS = 3;

    const DEFAULT_MAX_ATTEMPTS = 10;

    const DEFAULT_NOTIFY_MAX_ATTEMPTS = 3;

    const DEFAULT_MAX_EVENTS = 250000;

    const STATUS_OK = 'ok';

    const STATUS_FAILED = 'failed';

    const STATUS_ABANDONED = 'abandoned';

    const STATUS_BLOCKED_COVERAGE = 'blocked_coverage';

    const STATUS_TOO_LARGE = 'too_large';

    protected $audit;

    protected $store;

    protected $mailer;

    protected $logger;

    protected $enabled = true;

    protected $stateFile = null;

    protected $timezone = 'UTC';

    protected $backfillMonths = self::DEFAULT_BACKFILL_MONTHS;

    protected $requireFullCoverage = true;

    protected $maxEvents = self::DEFAULT_MAX_EVENTS;

    protected $maxAttempts = self::DEFAULT_MAX_ATTEMPTS;

    protected $notifyMaxAttempts = self::DEFAULT_NOTIFY_MAX_ATTEMPTS;

    protected $reportUrlBase = null;

    public function __construct(
        AuditLog $audit,
        ReportStore $store,
        AuditMailer $mailer,
        LoggerInterface $logger
    ) {
        $this->audit = $audit;
        $this->store = $store;
        $this->mailer = $mailer;
        $this->logger = $logger;
    }

    public function init(array $config = [])
    {
        if (isset($config['enabled'])) {
            $this->enabled = (bool) $config['enabled'];
        }
        if (! empty($config['state_file'])) {
            $this->stateFile = (string) $config['state_file'];
        }
        if (! empty($config['timezone'])) {
            $this->timezone = (string) $config['timezone'];
        }
        if (isset($config['backfill_months']) && (int) $config['backfill_months'] > 0) {
            $this->backfillMonths = (int) $config['backfill_months'];
        }
        if (isset($config['require_full_coverage'])) {
            $this->requireFullCoverage = (bool) $config['require_full_coverage'];
        }
        if (isset($config['max_events']) && (int) $config['max_events'] > 0) {
            $this->maxEvents = (int) $config['max_events'];
        }
        if (isset($config['max_attempts']) && (int) $config['max_attempts'] > 0) {
            $this->maxAttempts = (int) $config['max_attempts'];
        }
        if (isset($config['notify_max_attempts']) && (int) $config['notify_max_attempts'] > 0) {
            $this->notifyMaxAttempts = (int) $config['notify_max_attempts'];
        }
        if (! empty($config['report_url_base'])) {
            $this->reportUrlBase = $this->validateUrlBase((string) $config['report_url_base']);
        }
    }

    public function isConfigured(): bool
    {
        return $this->enabled && $this->stateFile !== null && $this->store->isConfigured();
    }

    /**
     * Generate any due periods. Returns a result array per period handled.
     *
     * @param string|null $only  restrict to one "YYYY-MM" period
     * @param bool        $force regenerate even if already marked ok
     */
    public function run(?string $only = null, bool $force = false): array
    {
        if (! $this->isConfigured()) {
            $this->logger->log(
                'MonthlyReport: not configured (enabled, state_file and ReportStore are all required)',
                \Monolog\Logger::WARNING
            );

            return [];
        }

        if (! $this->audit->isConfigured()) {
            // Without this guard an unregistered AuditLog autowires to an
            // un-init()'d instance whose query() returns [], and the job would
            // cheerfully write a well-formed EMPTY report and mark the period
            // ok. "Disabled" must never look like "a quiet month".
            $this->logger->log(
                'MonthlyReport: AuditLog is not configured; refusing to write an empty report',
                \Monolog\Logger::WARNING
            );

            return [];
        }

        $fh = @fopen($this->stateFile, 'c+b');
        if ($fh === false) {
            $this->logger->log(
                'MonthlyReport: cannot open state file '.$this->stateFile,
                \Monolog\Logger::WARNING
            );

            return [];
        }

        // Non-blocking: a second concurrent run skips rather than queueing
        // behind our handle.
        if (! flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);

            return [];
        }

        try {
            $state = $this->readState($fh);
            $periods = $only !== null ? [$only] : $this->duePeriods($state, $force);

            $results = [];
            foreach ($periods as $period) {
                $results[] = $this->generate($period, $state, $force);
                $this->writeState($fh, $state);
            }

            $removed = $this->store->collectGarbage($this->now());
            if ($removed > 0) {
                $this->logger->log(
                    "MonthlyReport: retention removed {$removed} stored report(s)",
                    \Monolog\Logger::WARNING
                );
            }

            return $results;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /**
     * Complete calendar months, oldest first, that still need a report.
     *
     * Looking back `backfill_months` rather than only at last month is what
     * makes a missed month self-healing instead of merely detectable.
     */
    protected function duePeriods(array $state, bool $force): array
    {
        $due = [];
        for ($i = $this->backfillMonths; $i >= 1; $i--) {
            $period = $this->periodFor($i);
            $entry = $state['periods'][$period] ?? null;

            if ($force || $this->needsGeneration($entry)) {
                $due[] = $period;
            }
        }

        return $due;
    }

    protected function needsGeneration(?array $entry): bool
    {
        if ($entry === null) {
            return true;
        }
        $status = $entry['status'] ?? null;

        if (in_array($status, [self::STATUS_OK, self::STATUS_ABANDONED, self::STATUS_TOO_LARGE], true)) {
            return false;
        }
        // A period blocked purely on retention can never become coverable
        // later — the raw events are gone — so it is not retried either.
        if ($status === self::STATUS_BLOCKED_COVERAGE) {
            return false;
        }

        return ($entry['attempts'] ?? 0) < $this->maxAttempts;
    }

    protected function generate(string $period, array &$state, bool $force): array
    {
        [$from, $to] = $this->windowFor($period);
        $entry = $state['periods'][$period] ?? ['attempts' => 0];
        $entry['attempts'] = ($entry['attempts'] ?? 0) + 1;
        $entry['last_attempt_at'] = $this->now();

        $cutoff = $this->audit->retentionCutoff();
        $complete = $cutoff <= $from;

        if (! $complete && $this->requireFullCoverage) {
            $missing = $cutoff - $from;
            $entry['status'] = self::STATUS_BLOCKED_COVERAGE;
            $entry['coverage'] = 'blocked';
            $entry['missing_seconds'] = $missing;
            $state['periods'][$period] = $entry;

            $this->logger->log(sprintf(
                'MonthlyReport: %s not generated — AuditLog max_age_days is %d, which cannot cover '
                .'that month (short by %d hours). Raise it to at least 32 (40 recommended), then re-run.',
                $period,
                $this->audit->getMaxAgeDays(),
                (int) ceil($missing / 3600)
            ), \Monolog\Logger::WARNING);

            $this->notify($period, $entry, $state, false);

            return ['period' => $period, 'status' => self::STATUS_BLOCKED_COVERAGE];
        }

        $events = $this->audit->query(['from' => $from, 'to' => $to]);

        if (count($events) > $this->maxEvents) {
            // Refuse loudly rather than risk an OOM. A fatal in cron is a line
            // on stderr nobody reads; this is actionable.
            $entry['status'] = self::STATUS_TOO_LARGE;
            $entry['events'] = count($events);
            $state['periods'][$period] = $entry;

            $this->logger->log(sprintf(
                'MonthlyReport: %s has %d events, above max_events %d — refusing to build. '
                .'Raise max_events and memory_limit, or narrow the window.',
                $period,
                count($events),
                $this->maxEvents
            ), \Monolog\Logger::WARNING);

            return ['period' => $period, 'status' => self::STATUS_TOO_LARGE];
        }

        $csv = (new ActivityCsv($this->timezone))->build($events);
        $byAction = $this->rollupByAction($events);
        $id = $this->store->write($period, $csv, [
            'events' => count($events),
            'coverage' => $complete ? 'complete' : 'partial',
            'coverage_from' => $complete ? $from : max($from, $cutoff),
            'window_from' => $from,
            'window_to' => $to,
            'filename' => (new ActivityCsv($this->timezone))->filename($from, $to, ! $complete),
            'by_action' => $byAction,
        ]);

        if ($id === null) {
            // Not marked ok: the daily tick retries until maxAttempts.
            $entry['status'] = $entry['attempts'] >= $this->maxAttempts
                ? self::STATUS_ABANDONED
                : self::STATUS_FAILED;
            $entry['last_error'] = 'ReportStore::write failed';
            $state['periods'][$period] = $entry;

            $this->logger->log(
                "MonthlyReport: {$period} generation failed (attempt {$entry['attempts']})",
                \Monolog\Logger::WARNING
            );

            return ['period' => $period, 'status' => $entry['status']];
        }

        // Verify the artifact is readable before declaring the month done —
        // "success" means a usable report exists, not that a write returned.
        if ($this->store->readDecrypted($id) === null) {
            $entry['status'] = self::STATUS_FAILED;
            $entry['last_error'] = 'written report could not be read back';
            $state['periods'][$period] = $entry;

            $this->logger->log(
                "MonthlyReport: {$period} wrote a report that cannot be decrypted; not marking complete",
                \Monolog\Logger::WARNING
            );

            return ['period' => $period, 'status' => self::STATUS_FAILED];
        }

        $entry['status'] = self::STATUS_OK;
        $entry['report_id'] = $id;
        $entry['events'] = count($events);
        $entry['coverage'] = $complete ? 'complete' : 'partial';
        $entry['generated_at'] = $this->now();
        $entry['by_action'] = $byAction;
        unset($entry['last_error']);
        $state['periods'][$period] = $entry;

        $this->logger->log(sprintf(
            'MonthlyReport: %s generated — %d events, coverage %s, report %s',
            $period,
            count($events),
            $entry['coverage'],
            $id
        ), \Monolog\Logger::WARNING);

        $this->notify($period, $entry, $state, true);

        return ['period' => $period, 'status' => self::STATUS_OK, 'report_id' => $id];
    }

    /**
     * Send the "report ready" notice. Capped, and NEVER able to affect the
     * artifact's status — the CSV is the deliverable, mail is best-effort.
     */
    protected function notify(string $period, array &$entry, array &$state, bool $generated): void
    {
        $attempts = $entry['notify_attempts'] ?? 0;
        if ($attempts >= $this->notifyMaxAttempts || ! empty($entry['notified_at'])) {
            return;
        }

        $entry['notify_attempts'] = $attempts + 1;

        $sent = $this->mailer->sendReportReady([
            'period' => $period,
            'generated' => $generated,
            'events' => $entry['events'] ?? 0,
            'coverage' => $entry['coverage'] ?? 'complete',
            'by_action' => $entry['by_action'] ?? [],
            'url_base' => $this->reportUrlBase,
        ]);

        if ($sent === true) {
            $entry['notified_at'] = $this->now();
        } elseif ($sent === null) {
            // Mailer not configured: not a failure to retry, and not worth
            // logging every day forever.
            $entry['notified_at'] = 0;
        } else {
            $this->logger->log(
                "MonthlyReport: {$period} notification failed (attempt {$entry['notify_attempts']}); "
                .'the report itself is available for download',
                \Monolog\Logger::WARNING
            );
        }

        $state['periods'][$period] = $entry;
    }

    /**
     * Counts per action. A fixed enum, so it carries no PII and is safe to put
     * in the notification body — unlike per-user or per-folder rollups, which
     * this class never computes.
     */
    protected function rollupByAction(array $events): array
    {
        $counts = array_fill_keys(AuditLog::ACTIONS, 0);
        foreach ($events as $event) {
            $action = $event['action'] ?? 'unknown';
            $counts[$action] = ($counts[$action] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * "YYYY-MM" for the month $back months before the current one.
     */
    public function periodFor(int $back): string
    {
        $tz = new \DateTimeZone($this->timezone);

        return (new \DateTimeImmutable('@'.$this->now()))
            ->setTimezone($tz)
            ->modify('first day of this month')
            ->modify("-{$back} months")
            ->format('Y-m');
    }

    /**
     * Inclusive [from, to] bounds of a "YYYY-MM" period.
     *
     * Built with DateTimeImmutable rather than arithmetic so DST transitions
     * land correctly. `to` is the last second of the month because
     * AuditLog::query() treats `to` as inclusive — using the first second of
     * the next month instead would double-count the boundary second in two
     * consecutive reports.
     */
    public function windowFor(string $period): array
    {
        $tz = new \DateTimeZone($this->timezone);
        $start = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $period.'-01 00:00:00', $tz);
        $next = $start->modify('first day of next month');

        return [$start->getTimestamp(), $next->getTimestamp() - 1];
    }

    public function readStateFile(): array
    {
        if ($this->stateFile === null || ! is_file($this->stateFile)) {
            return [];
        }
        $raw = @file_get_contents($this->stateFile);
        $decoded = $raw === false || $raw === '' ? [] : json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function readState($fh): array
    {
        rewind($fh);
        $raw = stream_get_contents($fh);
        $decoded = $raw === '' ? [] : json_decode($raw, true);
        $state = is_array($decoded) ? $decoded : [];

        if (! isset($state['periods']) || ! is_array($state['periods'])) {
            $state['periods'] = [];
        }

        return $state;
    }

    protected function writeState($fh, array $state): void
    {
        $state['version'] = 1;
        $state['last_run_at'] = $this->now();

        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fflush($fh);
        @chmod($this->stateFile, 0600);
    }

    /**
     * Refuse a non-https base rather than walking an admin onto cleartext:
     * session cookies are Secure only when the request arrived over TLS, and
     * HSTS is only emitted on HTTPS.
     */
    protected function validateUrlBase(string $base): ?string
    {
        if (stripos($base, 'https://') !== 0) {
            $this->logger->log(
                'MonthlyReport: report_url_base must start with https:// — link omitted from notifications',
                \Monolog\Logger::WARNING
            );

            return null;
        }

        return rtrim($base, '/').'/';
    }

    /**
     * Single clock seam, following AuditLog, so tests can pin calendar
     * boundaries deterministically. WeeklyDigest calls time() inline and its
     * tests work around it with relative timestamps — that does not generalise
     * to calendar periods, where a test would flip across a month boundary.
     */
    protected function now(): int
    {
        return time();
    }
}
