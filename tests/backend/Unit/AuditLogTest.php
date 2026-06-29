<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Tests\Unit;

use Filegator\Services\Audit\AuditLog;
use Filegator\Services\Logger\LoggerInterface;
use Filegator\Services\Mfa\MfaSecretCrypto;
use Tests\TestCase;

/**
 * Encrypted, append-only file-activity log with lazy 30-day retention.
 * Unit-tested directly (real temp files) so we can drive encryption,
 * retention boundaries, and failure swallowing without HTTP overhead.
 *
 * @internal
 */
class AuditLogTest extends TestCase
{
    protected $logFile;

    protected $keyPath;

    /** @var CapturingLogger */
    protected $logger;

    protected function setUp(): void
    {
        parent::setUp();
        // Don't resetTempDir() — that wipes the shared tmp tree (incl. fixtures
        // other suites rely on, e.g. sample.txt) and caused cross-suite
        // pollution under the single-process mutation run. This unit test only
        // needs its OWN files cleared; parent::setUp() ensures TEST_TMP_PATH.
        $this->logFile = TEST_TMP_PATH.'audit_test.jsonl';
        $this->keyPath = TEST_TMP_PATH.'audit_test.key';
        foreach ([$this->logFile, $this->logFile.'.pruned', $this->keyPath] as $f) {
            if (file_exists($f)) unlink($f);
        }
        $this->logger = new CapturingLogger();
    }

    protected function makeAudit(array $overrides = []): AuditLog
    {
        $audit = new AuditLog($this->logger);
        $audit->init(array_merge([
            'log_file' => $this->logFile,
            'key_path' => $this->keyPath,
            'max_age_days' => 30,
        ], $overrides));
        return $audit;
    }

    private function makeClockAudit(int $now, int $maxAgeDays): ClockableAuditLog
    {
        $audit = new ClockableAuditLog($this->logger);
        $audit->fakeNow = $now;
        $audit->init([
            'log_file' => $this->logFile,
            'key_path' => $this->keyPath,
            'max_age_days' => $maxAgeDays,
        ]);

        return $audit;
    }

    /** Seed the once/day prune marker file directly. */
    private function writeMarker(int $ts): void
    {
        file_put_contents($this->logFile.'.pruned', (string) $ts);
    }

    /** @return string[] non-empty raw lines on disk */
    protected function rawLines(): array
    {
        if (! is_file($this->logFile)) return [];
        return array_values(array_filter(explode("\n", file_get_contents($this->logFile)), function ($l) {
            return $l !== '';
        }));
    }

    protected function event(array $o = []): array
    {
        return array_merge([
            'ts' => time(),
            'user' => 'alice',
            'role' => 'user',
            'action' => AuditLog::ACTION_DELETE,
            'path' => '/clientA/2026/return.pdf',
            'detail' => null,
            'ip' => '203.0.113.7',
        ], $o);
    }

    public function testRecordThenQueryRoundTrips()
    {
        $audit = $this->makeAudit();
        $audit->record($this->event(['action' => AuditLog::ACTION_UPLOAD, 'path' => '/a.txt']));

        $events = $audit->query();
        $this->assertCount(1, $events);
        $this->assertSame('alice', $events[0]['user']);
        $this->assertSame(AuditLog::ACTION_UPLOAD, $events[0]['action']);
        $this->assertSame('/a.txt', $events[0]['path']);
        $this->assertCount(1, $this->rawLines());
    }

    public function testRecordManyAppendsAllEventsInOneFile()
    {
        $audit = $this->makeAudit();
        $audit->recordMany([
            $this->event(['action' => AuditLog::ACTION_DELETE, 'path' => '/a']),
            $this->event(['action' => AuditLog::ACTION_DELETE, 'path' => '/b']),
            $this->event(['action' => AuditLog::ACTION_DELETE, 'path' => '/c']),
        ]);

        $this->assertCount(3, $this->rawLines());
        $paths = array_column($audit->query(), 'path');
        sort($paths);
        $this->assertSame(['/a', '/b', '/c'], $paths);
    }

    public function testLineHasCleartextTsPrefixButEncryptedBody()
    {
        $audit = $this->makeAudit();
        $audit->record($this->event(['ts' => 1700000000, 'path' => '/sekret/clientZ.pdf', 'user' => 'topsecretuser']));

        $raw = trim(file_get_contents($this->logFile));
        // Cleartext unix-ts prefix + TAB (so prune/query filter without decrypt)...
        $this->assertStringStartsWith("1700000000\t", $raw);
        // ...but the sensitive body stays encrypted.
        $this->assertStringNotContainsString('clientZ', $raw);
        $this->assertStringNotContainsString('topsecretuser', $raw);
    }

    public function testRecordFillsTimestampWhenMissing()
    {
        $audit = $this->makeAudit();
        $before = time();
        $audit->record(['user' => 'a', 'role' => 'user', 'action' => 'create', 'path' => '/x']);
        $events = $audit->query();
        $this->assertGreaterThanOrEqual($before, (int) $events[0]['ts']);
    }

    public function testEventsAreEncryptedAtRestNotPlaintext()
    {
        $audit = $this->makeAudit();
        $audit->record($this->event(['path' => '/secret/clientX/return.pdf', 'user' => 'topsecretuser']));

        $raw = file_get_contents($this->logFile);
        // The sensitive cleartext must not appear in the on-disk bytes.
        $this->assertStringNotContainsString('clientX', $raw);
        $this->assertStringNotContainsString('topsecretuser', $raw);
        // But it round-trips through decrypt.
        $events = $audit->query();
        $this->assertSame('/secret/clientX/return.pdf', $events[0]['path']);
    }

    public function testUndecryptableLineIsSkippedNotFatal()
    {
        $audit = $this->makeAudit();
        $audit->record($this->event(['path' => '/good.txt']));
        // Inject a garbage line in the middle.
        file_put_contents($this->logFile, "not-encrypted-garbage\n", FILE_APPEND);
        $audit->record($this->event(['path' => '/good2.txt']));

        $events = $audit->query();
        $paths = array_column($events, 'path');
        $this->assertContains('/good.txt', $paths);
        $this->assertContains('/good2.txt', $paths);
        $this->assertCount(2, $events); // garbage line dropped, valid ones kept
    }

    public function testCraftedFilenameCannotForgeASecondLine()
    {
        $audit = $this->makeAudit();
        // A filename embedding a newline + a fake JSON event must not produce
        // a second parsed log entry.
        $audit->record($this->event([
            'path' => "evil\n".'{"ts":1,"user":"attacker","role":"admin","action":"delete"}',
        ]));

        $this->assertCount(1, $this->rawLines());
        $events = $audit->query();
        $this->assertCount(1, $events);
        $this->assertSame('alice', $events[0]['user']);
    }

    public function testQueryFiltersByActionAndUser()
    {
        $audit = $this->makeAudit();
        $audit->record($this->event(['user' => 'alice', 'action' => AuditLog::ACTION_DELETE, 'path' => '/1']));
        $audit->record($this->event(['user' => 'bob', 'action' => AuditLog::ACTION_UPLOAD, 'path' => '/2']));
        $audit->record($this->event(['user' => 'alice', 'action' => AuditLog::ACTION_UPLOAD, 'path' => '/3']));

        $this->assertCount(2, $audit->query(['action' => AuditLog::ACTION_UPLOAD]));
        $this->assertCount(2, $audit->query(['user' => 'alice']));
        $this->assertCount(1, $audit->query(['user' => 'alice', 'action' => AuditLog::ACTION_UPLOAD]));
    }

    public function testQueryFiltersByDateRange()
    {
        $audit = $this->makeAudit();
        $now = time();
        $audit->record($this->event(['ts' => $now - 100, 'path' => '/old']));
        $audit->record($this->event(['ts' => $now - 50, 'path' => '/mid']));
        $audit->record($this->event(['ts' => $now, 'path' => '/new']));

        // Inclusive bounds; the ts pre-filter (no decrypt) selects /mid only.
        $paths = array_column($audit->query(['from' => $now - 60, 'to' => $now - 10]), 'path');
        $this->assertSame(['/mid'], $paths);
    }

    public function testQueryDateRangeBoundsAreInclusive()
    {
        $audit = $this->makeAudit();
        $now = time();
        $audit->record($this->event(['ts' => $now - 100, 'path' => '/from-edge']));
        $audit->record($this->event(['ts' => $now - 50, 'path' => '/to-edge']));

        // from == ts and to == ts must both be included (inclusive contract).
        $paths = array_column($audit->query(['from' => $now - 100, 'to' => $now - 50]), 'path');
        sort($paths);
        $this->assertSame(['/from-edge', '/to-edge'], $paths);

        // Only-from / only-to pin the null-guard branches.
        $this->assertSame(['/to-edge', '/from-edge'], array_column($audit->query(['from' => $now - 100]), 'path'));
        $this->assertSame(['/from-edge'], array_column($audit->query(['to' => $now - 100]), 'path'));
    }

    public function testQueryRetentionCutoffIsInclusiveAtTheEdge()
    {
        // Pin the clock so "exactly at the cutoff second" is deterministic.
        $now = 2_000_000_000;
        $audit = $this->makeClockAudit($now, 30);
        $cutoff = $now - (30 * 86400);

        // Seed a fresh prune marker so record() does NOT prune — we want all
        // three lines on disk to test the query-side cutoff in isolation.
        $this->writeMarker($now);
        $audit->record($this->event(['ts' => $cutoff - 1, 'path' => '/expired']));
        $audit->record($this->event(['ts' => $cutoff, 'path' => '/edge']));
        $audit->record($this->event(['ts' => $cutoff + 1, 'path' => '/fresh']));

        $paths = array_column($audit->query(), 'path');
        sort($paths);
        // `$ts < $cutoff` drops only /expired; the entry exactly at the cutoff
        // is retained (kills the `<` -> `<=` boundary mutant).
        $this->assertSame(['/edge', '/fresh'], $paths);
    }

    public function testPruneKeepsEntryExactlyAtCutoff()
    {
        $now = 2_000_000_000;
        $audit = $this->makeClockAudit($now, 30);
        $cutoff = $now - (30 * 86400);

        // Seed two old-ish lines with prune disabled (fresh marker).
        $this->writeMarker($now);
        $audit->record($this->event(['ts' => $cutoff - 1, 'path' => '/expired']));
        $audit->record($this->event(['ts' => $cutoff, 'path' => '/edge']));

        // Now force a prune on the next write and confirm the edge survives.
        @unlink($this->logFile.'.pruned');
        $audit->record($this->event(['ts' => $now, 'path' => '/fresh']));

        $this->assertCount(2, $this->rawLines(), '/expired pruned, /edge + /fresh kept');
        $paths = array_column($audit->query(), 'path');
        sort($paths);
        // `>= $cutoff` keeps the exact-edge entry (kills `>=` -> `>`).
        $this->assertSame(['/edge', '/fresh'], $paths);
    }

    public function testPruneOncePerDayGateBoundary()
    {
        $now = 2_000_000_000;
        $cutoff = $now - (30 * 86400);

        // Case A: marker exactly PRUNE_INTERVAL old -> ($now-$last) == 86400,
        // which is NOT < 86400, so the prune RUNS and drops the expired line.
        $auditA = $this->makeClockAudit($now, 30);
        $this->writeMarker($now); // disable prune while seeding
        $auditA->record($this->event(['ts' => $cutoff - 100, 'path' => '/old']));
        $auditA->record($this->event(['ts' => $now, 'path' => '/keep']));
        $this->writeMarker($now - 86400);
        $auditA->record($this->event(['ts' => $now, 'path' => '/trigger']));
        $this->assertCount(2, $this->rawLines(), 'gate open at exactly the interval: prune ran, /old dropped');

        // Case B: marker one second inside the interval -> 86399 < 86400, prune SKIPS.
        // Clear only this test's audit files (NOT resetTempDir, which would wipe
        // the shared tmp dir and pollute other suites under single-process runs).
        @unlink($this->logFile);
        @unlink($this->logFile.'.pruned');
        $auditB = $this->makeClockAudit($now, 30);
        $this->writeMarker($now);
        $auditB->record($this->event(['ts' => $cutoff - 100, 'path' => '/old']));
        $auditB->record($this->event(['ts' => $now, 'path' => '/keep']));
        $this->writeMarker($now - 86400 + 1);
        $auditB->record($this->event(['ts' => $now, 'path' => '/trigger']));
        $this->assertCount(3, $this->rawLines(), 'gate closed one second early: prune skipped, /old retained');
    }

    public function testPruneOfAllExpiredLeavesNoLeadingBlankLine()
    {
        $now = 2_000_000_000;
        $audit = $this->makeClockAudit($now, 30);
        $cutoff = $now - (30 * 86400);

        $this->writeMarker($now);
        $audit->record($this->event(['ts' => $cutoff - 100, 'path' => '/a']));
        $audit->record($this->event(['ts' => $cutoff - 50, 'path' => '/b']));

        // Force a prune where EVERY existing line is expired (kept === []).
        @unlink($this->logFile.'.pruned');
        $audit->record($this->event(['ts' => $now, 'path' => '/fresh']));

        $raw = file_get_contents($this->logFile);
        // The `! empty($kept)` guard prevents writing a lone "\n" that would
        // leave a spurious blank first line.
        $this->assertStringStartsNotWith("\n", $raw);
        $this->assertCount(1, $this->rawLines());
        $this->assertSame(['/fresh'], array_column($audit->query(), 'path'));
    }

    public function testRecordManyWithNoEventsIsANoOp()
    {
        $audit = $this->makeAudit();
        $audit->recordMany([]);
        $this->assertSame([], $this->rawLines());
        $this->assertSame([], $audit->query());
    }

    public function testQuerySortsNewestFirst()
    {
        $audit = $this->makeAudit();
        $now = time();
        $audit->record($this->event(['ts' => $now - 100, 'path' => '/old']));
        $audit->record($this->event(['ts' => $now, 'path' => '/new']));

        $events = $audit->query();
        $this->assertSame('/new', $events[0]['path']);
        $this->assertSame('/old', $events[1]['path']);
    }

    public function testRetentionPurgeDeletesOldEntriesFromDisk()
    {
        $audit = $this->makeAudit(['max_age_days' => 30]);
        $now = time();

        // Seed an old entry (40 days). The first record runs the prune on an
        // empty file, appends this line, and stamps the once/day marker.
        $audit->record($this->event(['ts' => $now - (40 * 86400), 'path' => '/ancient.txt']));
        $this->assertCount(1, $this->rawLines());

        // Force the next prune to run by clearing the once/day gate.
        @unlink($this->logFile.'.pruned');

        // A fresh write now prunes: the 40-day entry is physically removed.
        $audit->record($this->event(['ts' => $now, 'path' => '/fresh.txt']));

        $lines = $this->rawLines();
        $this->assertCount(1, $lines, 'old entry should be purged from disk');
        $remaining = array_column($audit->query(), 'path');
        $this->assertSame(['/fresh.txt'], $remaining);
    }

    public function testRetentionKeepsRecentEntries()
    {
        $audit = $this->makeAudit(['max_age_days' => 30]);
        $now = time();
        $audit->record($this->event(['ts' => $now - (10 * 86400), 'path' => '/recent.txt']));
        @unlink($this->logFile.'.pruned');
        $audit->record($this->event(['ts' => $now, 'path' => '/fresh.txt']));

        $this->assertCount(2, $this->rawLines(), '10-day entry is within the 30-day window');
    }

    public function testPruneGatedToOncePerDay()
    {
        $audit = $this->makeAudit(['max_age_days' => 30]);
        $now = time();

        // First write prunes (empty file) and stamps the marker = now.
        $audit->record($this->event(['ts' => $now - (40 * 86400), 'path' => '/old.txt']));
        // Second write the same "day" must NOT re-run the prune, so the old
        // entry survives on disk even though it is past the window.
        $audit->record($this->event(['ts' => $now, 'path' => '/new.txt']));

        $this->assertCount(2, $this->rawLines(), 'prune is gated to once/day; old entry not yet swept');
    }

    public function testUnconfiguredInstanceNoOps()
    {
        // No init() at all → constructor-default disabled.
        $audit = new AuditLog($this->logger);
        $audit->record($this->event());
        $this->assertSame([], $audit->query());
        $this->assertFileDoesNotExist($this->logFile);

        // init() without log_file/key_path → still disabled.
        $audit2 = new AuditLog($this->logger);
        $audit2->init(['max_age_days' => 30]);
        $audit2->record($this->event());
        $this->assertSame([], $audit2->query());
    }

    public function testRecordSwallowsUnwritablePathAndLogs()
    {
        $audit = new AuditLog($this->logger);
        $audit->init([
            'log_file' => '/this/path/should/not/exist/audit.jsonl',
            'key_path' => $this->keyPath, // writable, so crypto init succeeds
            'max_age_days' => 30,
        ]);

        // Must not throw despite the unwritable log path.
        $audit->record($this->event());

        // Assert the SPECIFIC failure, not just any 'AuditLog' line (every log
        // line starts with 'AuditLog', so the substring alone is tautological).
        $this->assertNotEmpty($this->logger->messages, 'a failure should be logged');
        $this->assertStringContainsString('cannot open log file', $this->logger->messages[0]);
    }

    public function testRecordSwallowsCryptoFailureViaCatchBlock()
    {
        // The fopen-false path returns early; this drives the try/catch swallow
        // (encrypt throwing) so a crypto failure can't break a file op either.
        $audit = new CryptoFailingAuditLog($this->logger);
        $audit->init([
            'log_file' => $this->logFile,
            'key_path' => $this->keyPath,
            'max_age_days' => 30,
        ]);

        $audit->record($this->event());

        $this->assertSame([], $this->rawLines(), 'nothing written when encrypt throws');
        $this->assertNotEmpty($this->logger->messages);
        $this->assertStringContainsString('record failed', $this->logger->messages[0]);
    }

    public function testFirstEverPruneRunsWhenMarkerIsZero()
    {
        // A marker of literal "0" means $last === 0, so the `$last !== 0 && ...`
        // gate must NOT short-circuit — the first prune has to run.
        $now = 2_000_000_000;
        $audit = $this->makeClockAudit($now, 30);
        $cutoff = $now - (30 * 86400);

        $this->writeMarker($now); // disable prune while seeding the expired line
        $audit->record($this->event(['ts' => $cutoff - 100, 'path' => '/old']));

        $this->writeMarker(0);
        $audit->record($this->event(['ts' => $now, 'path' => '/fresh']));

        $this->assertCount(1, $this->rawLines(), 'last==0 forced the prune; /old swept');
        $this->assertSame(['/fresh'], array_column($audit->query(), 'path'));
    }

    public function testMaxAgeDaysConfigIsParsedAndGuarded()
    {
        $now = 2_000_000_000;

        // A string config value must cast to int and take effect.
        $audit45 = new ClockableAuditLog($this->logger);
        $audit45->fakeNow = $now;
        $audit45->init([
            'log_file' => $this->logFile,
            'key_path' => $this->keyPath,
            'max_age_days' => '45',
        ]);
        $this->writeMarker($now); // no prune; test the query-side window
        $audit45->record($this->event(['ts' => $now - (40 * 86400), 'path' => '/in45']));
        $this->assertSame(['/in45'], array_column($audit45->query(), 'path'), '40d is within a parsed 45d window');

        // A non-positive value fails the `> 0` guard and keeps the 30-day default.
        @unlink($this->logFile);
        @unlink($this->logFile.'.pruned');
        $audit0 = new ClockableAuditLog($this->logger);
        $audit0->fakeNow = $now;
        $audit0->init([
            'log_file' => $this->logFile,
            'key_path' => $this->keyPath,
            'max_age_days' => 0,
        ]);
        $this->writeMarker($now);
        $audit0->record($this->event(['ts' => $now - (20 * 86400), 'path' => '/in30']));
        $audit0->record($this->event(['ts' => $now - (40 * 86400), 'path' => '/out30']));
        $this->assertSame(['/in30'], array_column($audit0->query(), 'path'), '0 ignored -> default 30d window');
    }

    public function testLogFileCreatedWith0600()
    {
        $this->makeAudit();
        $this->assertFileExists($this->logFile);
        $this->assertSame(0600, fileperms($this->logFile) & 0777);
    }
}

/**
 * Minimal in-memory logger so tests can assert on swallowed-failure logging.
 *
 * @internal
 */
class CapturingLogger implements LoggerInterface
{
    /** @var string[] */
    public $messages = [];

    public function log(string $message, int $level = self::INFO)
    {
        $this->messages[] = $message;
    }
}

/**
 * AuditLog with a pinnable clock so retention/prune/query boundaries are
 * deterministic in tests.
 *
 * @internal
 */
class ClockableAuditLog extends AuditLog
{
    /** @var int */
    public $fakeNow = 0;

    protected function now(): int
    {
        return $this->fakeNow;
    }
}

/**
 * AuditLog whose encryptor throws, to exercise record()'s try/catch swallow.
 *
 * @internal
 */
class CryptoFailingAuditLog extends AuditLog
{
    protected function makeCrypto(string $keyPath): MfaSecretCrypto
    {
        return new class() extends MfaSecretCrypto {
            public function encrypt(string $plaintext): string
            {
                throw new \RuntimeException('boom');
            }
        };
    }
}
