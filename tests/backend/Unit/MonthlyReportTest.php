<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Tests\Unit;

use Filegator\Config\Config;
use Filegator\Services\Audit\AuditLog;
use Filegator\Services\Audit\AuditMailer;
use Filegator\Services\Audit\MonthlyReport;
use Filegator\Services\Audit\ReportStore;
use Tests\Fakes\InMemoryMailer;
use Tests\Fakes\RecordingLogger;
use Tests\TestCase;

/**
 * MonthlyReport with a pinned clock.
 *
 * WeeklyDigest calls time() inline and its tests work around that with relative
 * timestamps; that does not generalise to calendar periods, where a test would
 * flip its expected period across a real month boundary. This follows
 * AuditLog's now() seam instead.
 *
 * @internal
 */
class ClockableMonthlyReport extends MonthlyReport
{
    public $fakeNow = 0;

    protected function now(): int
    {
        return $this->fakeNow;
    }
}

/**
 * ReportStore whose write() always fails, to exercise the retry path.
 *
 * @internal
 */
class FailingReportStore extends ReportStore
{
    public function write(string $period, string $csv, array $meta = []): ?string
    {
        return null;
    }
}

/**
 * @internal
 */
class MonthlyReportTest extends TestCase
{

    protected $logger;

    protected $audit;

    protected $store;

    protected function setUp(): void
    {
        $this->resetTempDir();
        $this->logger = new RecordingLogger();
        InMemoryMailer::reset();
    }

    protected function tearDown(): void
    {
        $this->resetTempDir();
    }

    protected function nowTs(): int
    {
        return (new \DateTimeImmutable('2026-08-01 03:17:00', new \DateTimeZone('UTC')))->getTimestamp();
    }

    protected function makeAudit(int $maxAgeDays = 40): ClockableAuditLog
    {
        $audit = new ClockableAuditLog($this->logger);
        $audit->fakeNow = $this->nowTs();
        $audit->init([
            'log_file' => TEST_TMP_PATH.'audit.jsonl',
            'key_path' => TEST_TMP_PATH.'audit.key',
            'max_age_days' => $maxAgeDays,
        ]);

        return $audit;
    }

    protected function makeStore(bool $failing = false): ReportStore
    {
        $store = $failing
            ? new FailingReportStore($this->logger)
            : new ReportStore($this->logger);
        $store->init([
            'dir' => TEST_TMP_PATH.'reports',
            'key_path' => TEST_TMP_PATH.'reports.key',
            'max_age_days' => 100,
            'max_count' => 24,
        ]);

        return $store;
    }

    protected function makeMailer(array $config = []): AuditMailer
    {
        $auditMailer = new AuditMailer(new InMemoryMailer(), $this->logger);
        $auditMailer->init(array_merge([
            'recipient' => 'audit@example.com',
            'from_email' => 'no-reply@example.com',
            'from_name' => 'FileGator',
            'app_label' => 'Test portal',
            'enabled' => true,
        ], $config));

        return $auditMailer;
    }

    protected function makeReport(array $config = [], $audit = null, $store = null, string $appTimezone = 'UTC'): ClockableMonthlyReport
    {
        $report = new ClockableMonthlyReport(
            $audit ?? $this->makeAudit(),
            $store ?? $this->makeStore(),
            $this->makeMailer(),
            $this->logger,
            new Config(['timezone' => $appTimezone])
        );
        $report->fakeNow = $this->nowTs();
        $report->init(array_merge([
            'enabled' => true,
            'state_file' => TEST_TMP_PATH.'report_state.json',
            'backfill_months' => 1,
            'require_full_coverage' => true,
        ], $config));

        return $report;
    }

    /** Seed n events inside July 2026. */
    protected function seedJuly(AuditLog $audit, int $count = 3, array $overrides = []): void
    {
        $base = (new \DateTimeImmutable('2026-07-15 12:00:00', new \DateTimeZone('UTC')))->getTimestamp();
        for ($i = 0; $i < $count; $i++) {
            $audit->record(array_merge([
                'ts' => $base + $i,
                'user' => 'alice',
                'role' => 'user',
                'action' => 'delete',
                'path' => '/clientA/secret.pdf',
                'detail' => null,
                'ip' => '203.0.113.7',
            ], $overrides));
        }
    }

    // ── Window arithmetic ────────────────────────────────────────────────────

    public function testPeriodForLastCompleteMonth()
    {
        $this->assertSame('2026-07', $this->makeReport()->periodFor(1));
    }

    public function testWindowIsTheWholeCalendarMonthInclusive()
    {
        [$from, $to] = $this->makeReport()->windowFor('2026-07');

        $this->assertSame('2026-07-01 00:00:00', gmdate('Y-m-d H:i:s', $from));
        $this->assertSame('2026-07-31 23:59:59', gmdate('Y-m-d H:i:s', $to));
    }

    /**
     * query()'s `to` is inclusive, so the last second of one month and the
     * first of the next must be adjacent — never overlapping, never leaving a
     * one-second hole.
     */
    public function testAdjacentWindowsNeitherOverlapNorGap()
    {
        $report = $this->makeReport();
        [, $julyTo] = $report->windowFor('2026-07');
        [$augFrom] = $report->windowFor('2026-08');

        $this->assertSame($augFrom, $julyTo + 1);
    }

    /**
     * The timezone must come from the APP's top-level config, not from this
     * service's own config block — which neither configuration_sample.php nor
     * the test config sets. Reading it there made month boundaries silently
     * always-UTC, so on a non-UTC deployment the last hours of a local month
     * fell into the next month's report.
     */
    public function testWindowUsesTheAppTimezoneWithNoServiceOverride()
    {
        $report = $this->makeReport([], null, null, 'America/Chicago');
        [$from, $to] = $report->windowFor('2026-07');

        $tz = new \DateTimeZone('America/Chicago');
        $this->assertSame('2026-07-01 00:00:00', (new \DateTimeImmutable('@'.$from))->setTimezone($tz)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-31 23:59:59', (new \DateTimeImmutable('@'.$to))->setTimezone($tz)->format('Y-m-d H:i:s'));
        // Not UTC midnight — that is exactly the bug this pins.
        $this->assertNotSame('2026-07-01 00:00:00', gmdate('Y-m-d H:i:s', $from));
    }

    public function testServiceConfigCanOverrideTheAppTimezone()
    {
        $report = $this->makeReport(['timezone' => 'Asia/Tokyo'], null, null, 'America/Chicago');
        [$from] = $report->windowFor('2026-07');

        $this->assertSame(
            '2026-07-01 00:00:00',
            (new \DateTimeImmutable('@'.$from))->setTimezone(new \DateTimeZone('Asia/Tokyo'))->format('Y-m-d H:i:s')
        );
    }

    public function testWindowRespectsANonUtcTimezone()
    {
        $report = $this->makeReport(['timezone' => 'America/Chicago']);
        [$from, $to] = $report->windowFor('2026-07');

        $tz = new \DateTimeZone('America/Chicago');
        $this->assertSame('2026-07-01 00:00:00', (new \DateTimeImmutable('@'.$from))->setTimezone($tz)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-31 23:59:59', (new \DateTimeImmutable('@'.$to))->setTimezone($tz)->format('Y-m-d H:i:s'));
    }

    /**
     * March and November cross DST in America/Chicago. Computed with
     * DateTimeImmutable rather than 86400 arithmetic, these still land on
     * midnight local.
     */
    public function testDstCrossingMonthsStillStartAndEndAtLocalMidnight()
    {
        $report = $this->makeReport(['timezone' => 'America/Chicago']);
        $tz = new \DateTimeZone('America/Chicago');

        foreach (['2026-03', '2026-11'] as $period) {
            [$from, $to] = $report->windowFor($period);
            $this->assertSame('00:00:00', (new \DateTimeImmutable('@'.$from))->setTimezone($tz)->format('H:i:s'), $period);
            $this->assertSame('23:59:59', (new \DateTimeImmutable('@'.$to))->setTimezone($tz)->format('H:i:s'), $period);
        }
    }

    // ── Generation, idempotency, retry ───────────────────────────────────────

    public function testGeneratesLastMonthAndRecordsState()
    {
        $audit = $this->makeAudit();
        $this->seedJuly($audit, 3);
        $report = $this->makeReport([], $audit);

        $results = $report->run();

        $this->assertCount(1, $results);
        $this->assertSame('2026-07', $results[0]['period']);
        $this->assertSame(MonthlyReport::STATUS_OK, $results[0]['status']);

        $state = $report->readStateFile();
        $this->assertSame(3, $state['periods']['2026-07']['events']);
        $this->assertSame('complete', $state['periods']['2026-07']['coverage']);
    }

    public function testSecondRunIsIdempotent()
    {
        $audit = $this->makeAudit();
        $this->seedJuly($audit);
        $report = $this->makeReport([], $audit);

        $report->run();
        $sentAfterFirst = count(InMemoryMailer::$messages);
        $second = $report->run();

        $this->assertSame([], $second);
        $this->assertCount($sentAfterFirst, InMemoryMailer::$messages);
    }

    public function testConcurrentRunSkipsRatherThanDuplicating()
    {
        $audit = $this->makeAudit();
        $this->seedJuly($audit);
        $report = $this->makeReport([], $audit);

        // Hold the lock the way a concurrent cron would.
        $held = fopen(TEST_TMP_PATH.'report_state.json', 'c+b');
        flock($held, LOCK_EX);

        $results = $report->run();

        flock($held, LOCK_UN);
        fclose($held);

        // [] not null: contention is normal and self-correcting, so the CLI
        // must exit 0 rather than alerting.
        $this->assertSame([], $results);
        $this->assertSame([], InMemoryMailer::$messages);
    }

    public function testGenerationFailureDoesNotMarkCompleteAndRetries()
    {
        $audit = $this->makeAudit();
        $this->seedJuly($audit);
        $report = $this->makeReport([], $audit, $this->makeStore(true));

        $results = $report->run();

        $this->assertSame(MonthlyReport::STATUS_FAILED, $results[0]['status']);
        $state = $report->readStateFile();
        $this->assertSame(1, $state['periods']['2026-07']['attempts']);
        $this->assertNotSame(MonthlyReport::STATUS_OK, $state['periods']['2026-07']['status']);

        // A later tick with a working store must pick it back up.
        $recovered = $this->makeReport([], $audit, $this->makeStore(false));
        $this->assertSame(MonthlyReport::STATUS_OK, $recovered->run()[0]['status']);
    }

    public function testAbandonsAfterMaxAttempts()
    {
        $audit = $this->makeAudit();
        $this->seedJuly($audit);

        for ($i = 0; $i < 2; $i++) {
            $this->makeReport(['max_attempts' => 2], $audit, $this->makeStore(true))->run();
        }

        $report = $this->makeReport(['max_attempts' => 2], $audit, $this->makeStore(true));
        $state = $report->readStateFile();

        $this->assertSame(MonthlyReport::STATUS_ABANDONED, $state['periods']['2026-07']['status']);
        // Abandoned means it stops being retried on every subsequent daily tick.
        $this->assertSame([], $report->run());
    }

    /**
     * A failed notification must never cause the CSV to be rebuilt: that would
     * mint a new report id and orphan a link an admin may already hold.
     */
    public function testNotificationFailureDoesNotRegenerateTheReport()
    {
        $audit = $this->makeAudit();
        $this->seedJuly($audit);
        $store = $this->makeStore();
        InMemoryMailer::$failNextSend = true;

        $report = $this->makeReport([], $audit, $store);
        $report->run();

        $firstId = $report->readStateFile()['periods']['2026-07']['report_id'];
        $this->assertNotEmpty($firstId);

        $this->makeReport([], $audit, $store)->run();
        $this->assertSame($firstId, $report->readStateFile()['periods']['2026-07']['report_id']);
    }

    public function testRefusesToBuildAboveMaxEvents()
    {
        $audit = $this->makeAudit();
        $this->seedJuly($audit, 3);
        $report = $this->makeReport(['max_events' => 2], $audit);

        $results = $report->run();

        $this->assertSame(MonthlyReport::STATUS_TOO_LARGE, $results[0]['status']);
        $this->assertSame([], glob(TEST_TMP_PATH.'reports/*.csv.enc'));
        $this->assertNotEmpty(array_filter(
            $this->logger->messagesAtLeast(\Monolog\Logger::WARNING),
            function ($m) { return strpos($m, 'above max_events') !== false; }
        ));
    }

    /**
     * --period must not silently regenerate a completed month. Doing so mints a
     * new report id and orphans any link an admin is already holding, and both
     * usage() and the docs say --force is what does that.
     */
    public function testExplicitPeriodDoesNotRegenerateWithoutForce()
    {
        $audit = $this->makeAudit();
        $this->seedJuly($audit);
        $store = $this->makeStore();

        $report = $this->makeReport([], $audit, $store);
        $report->run();
        $firstId = $report->readStateFile()['periods']['2026-07']['report_id'];

        $again = $this->makeReport([], $audit, $store)->run('2026-07');

        $this->assertSame([], $again);
        $this->assertSame($firstId, $report->readStateFile()['periods']['2026-07']['report_id']);
    }

    public function testExplicitPeriodWithForceReplacesRatherThanAccumulates()
    {
        $audit = $this->makeAudit();
        $this->seedJuly($audit);
        $store = $this->makeStore();

        $report = $this->makeReport([], $audit, $store);
        $report->run();
        $firstId = $report->readStateFile()['periods']['2026-07']['report_id'];

        $this->makeReport([], $audit, $store)->run('2026-07', true);

        // Exactly one report for the period, and the superseded ciphertext is
        // gone rather than left on disk holding the same PII twice.
        $this->assertCount(1, $store->listReports());
        $this->assertFileDoesNotExist(TEST_TMP_PATH.'reports/'.$firstId.'.csv.enc');
        $this->assertCount(1, glob(TEST_TMP_PATH.'reports/*.csv.enc'));
    }

    /**
     * Reporting the current month writes an artifact over a window that has not
     * closed, labels it complete, and marks the period ok — after which the
     * scheduled path skips it forever and the real month is never reported.
     */
    public function testRefusesTheCurrentMonth()
    {
        $audit = $this->makeAudit();
        $report = $this->makeReport([], $audit);

        $this->assertNull($report->run('2026-08'));
        $this->assertSame([], glob(TEST_TMP_PATH.'reports/*.csv.enc'));
        $this->assertNotEmpty(array_filter(
            $this->logger->messagesAtLeast(\Monolog\Logger::WARNING),
            function ($m) { return strpos($m, 'has not closed yet') !== false; }
        ));
    }

    public function testRefusesAFutureMonth()
    {
        $report = $this->makeReport([], $this->makeAudit());

        $this->assertNull($report->run('2027-01'));
        $this->assertSame([], glob(TEST_TMP_PATH.'reports/*.csv.enc'));
    }

    // ── Coverage ─────────────────────────────────────────────────────────────

    public function testBlocksWhenRetentionCannotCoverTheMonth()
    {
        $audit = $this->makeAudit(30); // 30 days cannot cover a 31-day month
        $this->seedJuly($audit);
        $report = $this->makeReport(['require_full_coverage' => true], $audit);

        $results = $report->run();

        $this->assertSame(MonthlyReport::STATUS_BLOCKED_COVERAGE, $results[0]['status']);
        $this->assertSame([], glob(TEST_TMP_PATH.'reports/*.csv.enc'));

        $warned = $this->logger->messagesAtLeast(\Monolog\Logger::WARNING);
        $this->assertNotEmpty(array_filter($warned, function ($m) {
            return strpos($m, 'max_age_days is 30') !== false && strpos($m, 'at least 32') !== false;
        }), 'the warning must name both the current value and the required one');
    }

    /**
     * A coverage block is a CONFIG problem, not a transient failure, so raising
     * max_age_days must make the next run produce the report. Pruning is lazy,
     * so events hidden by the read cutoff are usually still on disk.
     *
     * An earlier revision treated blocked_coverage as terminal, which meant an
     * operator who fixed the config silently never got the backfilled months.
     */
    public function testRaisingRetentionUnblocksAPreviouslyBlockedPeriod()
    {
        $short = $this->makeAudit(30);
        $this->seedJuly($short);
        $blocked = $this->makeReport([], $short);

        $this->assertSame(MonthlyReport::STATUS_BLOCKED_COVERAGE, $blocked->run()[0]['status']);

        // Operator raises max_age_days; the next daily tick must pick it up.
        $fixed = $this->makeReport([], $this->makeAudit(40));
        $results = $fixed->run();

        $this->assertSame(MonthlyReport::STATUS_OK, $results[0]['status']);
        $this->assertSame('complete', $fixed->readStateFile()['periods']['2026-07']['coverage']);

        // ...and the operator must actually be TOLD the report now exists. The
        // blocked run already sent a "not generated" notice; if that marks the
        // period notified, the success notice is suppressed and nobody ever
        // learns the report is available.
        $subjects = array_column(InMemoryMailer::$messages, 'subject');
        $this->assertNotEmpty(array_filter($subjects, function ($s) {
            return strpos($s, 'ready') !== false;
        }), 'the success notification must not be suppressed by the earlier blocked notice');
    }

    /**
     * A coverage block must not consume an attempt, or a period could be
     * abandoned before it ever became generatable.
     */
    public function testCoverageBlockDoesNotBurnAttempts()
    {
        $audit = $this->makeAudit(30);
        $this->seedJuly($audit);

        for ($i = 0; $i < 3; $i++) {
            $this->makeReport(['max_attempts' => 2], $audit)->run();
        }

        $state = $this->makeReport(['max_attempts' => 2], $audit)->readStateFile();

        $this->assertSame(0, $state['periods']['2026-07']['attempts'] ?? 0);
        $this->assertSame(MonthlyReport::STATUS_BLOCKED_COVERAGE, $state['periods']['2026-07']['status']);
    }

    /**
     * A month whose LAST second already predates the cutoff is gone — no config
     * change brings it back. It must be recorded once and never retried, and it
     * must NOT fail the cron: backfill looks back further than any sane
     * retention window, so on a fresh install these land on the first run and
     * would otherwise alert every single day forever.
     */
    public function testMonthEntirelyOutsideRetentionIsTerminalNotBlocked()
    {
        $audit = $this->makeAudit(40);
        $report = $this->makeReport(['backfill_months' => 3], $audit);

        $results = $report->run();
        $byPeriod = array_column($results, 'status', 'period');

        $this->assertSame(MonthlyReport::STATUS_UNRECOVERABLE, $byPeriod['2026-05']);
        $this->assertSame(MonthlyReport::STATUS_OK, $byPeriod['2026-07']);

        // Second tick must not mention it again.
        $this->assertNotContains('2026-05', array_column($report->run(), 'period'));
    }

    public function testMarksPartialWhenCoverageNotRequired()
    {
        $audit = $this->makeAudit(30);
        $this->seedJuly($audit);
        $report = $this->makeReport(['require_full_coverage' => false], $audit);

        $report->run();
        $state = $report->readStateFile();

        $this->assertSame(MonthlyReport::STATUS_OK, $state['periods']['2026-07']['status']);
        $this->assertSame('partial', $state['periods']['2026-07']['coverage']);

        // The marker must reach the FILENAME, which is what survives forwarding.
        $store = $this->makeStore();
        $this->assertStringContainsString('-PARTIAL', $store->findByPeriod('2026-07')['filename']);
    }

    public function testFullRetentionYieldsCompleteCoverage()
    {
        $audit = $this->makeAudit(40);
        $this->seedJuly($audit);
        $report = $this->makeReport([], $audit);

        $report->run();

        $this->assertSame('complete', $report->readStateFile()['periods']['2026-07']['coverage']);
        $this->assertStringNotContainsString('-PARTIAL', $this->makeStore()->findByPeriod('2026-07')['filename']);
    }

    // ── Safety rails ─────────────────────────────────────────────────────────

    /**
     * An unregistered AuditLog autowires to an un-init()'d instance whose
     * query() returns []. Without an explicit guard the job would write a
     * well-formed EMPTY report and mark the month done.
     */
    public function testRefusesToWriteAnEmptyReportWhenAuditLogIsUnconfigured()
    {
        $unconfigured = new AuditLog($this->logger);
        $report = $this->makeReport([], $unconfigured);

        // null, not [] — the CLI maps null to a non-zero exit. Returning an
        // empty array here would be indistinguishable from "nothing due", and
        // cron would report success forever while producing no reports.
        $this->assertNull($report->run());
        $this->assertSame([], glob(TEST_TMP_PATH.'reports/*.csv.enc'));
        $this->assertNotEmpty(array_filter(
            $this->logger->messagesAtLeast(\Monolog\Logger::WARNING),
            function ($m) { return strpos($m, 'refusing to write an empty report') !== false; }
        ));
    }

    public function testDisabledIsASafeNoOp()
    {
        $report = $this->makeReport(['enabled' => false]);

        $this->assertFalse($report->isConfigured());
        $this->assertNull($report->run());
    }

    /**
     * Production pins the Monolog handler at WARNING, so a success line logged
     * at the INFO default leaves no evidence the job ever ran.
     */
    public function testSuccessIsLoggedLoudlyEnoughToSurviveProduction()
    {
        $audit = $this->makeAudit();
        $this->seedJuly($audit);
        $this->makeReport([], $audit)->run();

        $this->assertNotEmpty(array_filter(
            $this->logger->messagesAtLeast(\Monolog\Logger::WARNING),
            function ($m) { return strpos($m, '2026-07 generated') !== false; }
        ));
    }

    /**
     * The premise of the whole feature: automation must not turn a pull into a
     * push of PII. If this ever fails, the design has been undone.
     */
    public function testNotificationCarriesNoPii()
    {
        $audit = $this->makeAudit();
        $this->seedJuly($audit);
        $this->makeReport([], $audit)->run();

        $this->assertNotEmpty(InMemoryMailer::$messages);
        $sent = InMemoryMailer::$messages[0];

        foreach (['text', 'html', 'subject'] as $part) {
            $this->assertStringNotContainsString('alice', (string) $sent[$part]);
            $this->assertStringNotContainsString('secret.pdf', (string) $sent[$part]);
            $this->assertStringNotContainsString('clientA', (string) $sent[$part]);
            $this->assertStringNotContainsString('203.0.113.7', (string) $sent[$part]);
        }
        // ...while still carrying the operational signal. Asserting the actual
        // content matters: "contains no PII" passes trivially on an empty body,
        // and an earlier revision did exactly that — the by-action rollup was
        // computed but never reached the notification.
        $this->assertStringContainsString('2026-07', (string) $sent['subject']);
        $this->assertStringContainsString('Events:  3', (string) $sent['text']);
        $this->assertStringContainsString('delete   3', (string) $sent['text']);
        $this->assertStringContainsString('<td>delete</td>', (string) $sent['html']);
    }

    public function testEventCountStaysOutOfTheSubject()
    {
        $audit = $this->makeAudit();
        $this->seedJuly($audit, 7);
        $this->makeReport([], $audit)->run();

        $this->assertStringNotContainsString('7 event', (string) InMemoryMailer::$messages[0]['subject']);
    }

    public function testNonHttpsUrlBaseIsRefused()
    {
        $audit = $this->makeAudit();
        $this->seedJuly($audit);
        $this->makeReport(['report_url_base' => 'http://files.example.com/'], $audit)->run();

        $this->assertStringNotContainsString('http://files.example.com', (string) InMemoryMailer::$messages[0]['text']);
        $this->assertNotEmpty(array_filter(
            $this->logger->messagesAtLeast(\Monolog\Logger::WARNING),
            function ($m) { return strpos($m, 'must start with https') !== false; }
        ));
    }

    public function testBackfillGeneratesMissedMonthsOldestFirst()
    {
        $audit = $this->makeAudit(120);
        $this->seedJuly($audit);
        // Seed June too so both months have content.
        $june = (new \DateTimeImmutable('2026-06-10 09:00:00', new \DateTimeZone('UTC')))->getTimestamp();
        $audit->record(['ts' => $june, 'user' => 'bob', 'role' => 'user', 'action' => 'upload', 'path' => '/x.txt', 'detail' => null, 'ip' => '198.51.100.1']);

        $report = $this->makeReport(['backfill_months' => 2], $audit);
        $results = $report->run();

        $this->assertSame(['2026-06', '2026-07'], array_column($results, 'period'));
    }
}
