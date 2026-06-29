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
        $this->resetTempDir();
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

        $this->assertNotEmpty($this->logger->messages, 'a failure should be logged');
        $this->assertStringContainsString('AuditLog', $this->logger->messages[0]);
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
