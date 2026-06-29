<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Tests\Unit;

use Filegator\Controllers\Concerns\RecordsAuditEvents;
use Filegator\Kernel\Request;
use Filegator\Services\Audit\AuditLog;
use Filegator\Services\Auth\User;
use Filegator\Services\Logger\LoggerInterface;
use Tests\TestCase;

/**
 * Unit-tests the RecordsAuditEvents trait in isolation via a tiny harness, so
 * the guest/null-user fallback and the relative-vs-absolute path handling are
 * covered without needing a guest-write deployment config.
 *
 * @internal
 */
class RecordsAuditEventsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetTempDir();
        @unlink(TEST_TMP_PATH.'ra.jsonl');
        @unlink(TEST_TMP_PATH.'ra.jsonl.pruned');
        @unlink(TEST_TMP_PATH.'ra.key');
    }

    private function makeAudit(): AuditLog
    {
        $audit = new AuditLog(new class() implements LoggerInterface {
            public function log(string $message, int $level = self::INFO) {}
        });
        $audit->init([
            'log_file' => TEST_TMP_PATH.'ra.jsonl',
            'key_path' => TEST_TMP_PATH.'ra.key',
            'max_age_days' => 30,
        ]);

        return $audit;
    }

    /** A minimal object that uses the trait, with stubbed auth + storage. */
    private function harness($auth)
    {
        return new class($auth) {
            use RecordsAuditEvents;

            public $auth;

            public $storage;

            public $resolvedActiveHomedir = '/clientA';

            public function __construct($auth)
            {
                $this->auth = $auth;
                $this->storage = new class() {
                    public function getSeparator()
                    {
                        return '/';
                    }
                };
            }

            public function fireRelative(Request $request, AuditLog $audit)
            {
                $this->recordAudit($request, $audit, AuditLog::ACTION_DELETE, '/return.pdf');
            }

            public function fireAbsolute(Request $request, AuditLog $audit)
            {
                // Already root-relative (with a doubled separator to prove
                // normalisation), as Filesystem would return it.
                $this->recordAuditAbsolute($request, $audit, AuditLog::ACTION_UPLOAD, '/clientA//x.pdf', 'overwritten');
            }
        };
    }

    private function request(string $ip): Request
    {
        return Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => $ip]);
    }

    public function testGuestFallbackRecordsGuestIdentityAndGlobalPath()
    {
        $audit = $this->makeAudit();
        $auth = new class() {
            public function user()
            {
                return null; // unauthenticated / guest write path
            }

            public function getGuest(): User
            {
                $u = new User();
                $u->setUsername('guest');
                $u->setRole('guest');

                return $u;
            }
        };

        $this->harness($auth)->fireRelative($this->request('9.9.9.9'), $audit);

        $events = $audit->query();
        $this->assertCount(1, $events);
        $this->assertSame('guest', $events[0]['user']);
        $this->assertSame('guest', $events[0]['role']);
        // Homedir-relative '/return.pdf' prefixed with the resolved homedir.
        $this->assertSame('/clientA/return.pdf', $events[0]['path']);
        $this->assertSame('9.9.9.9', $events[0]['ip']);
    }

    public function testAbsolutePathIsNormalisedNotDoublePrefixed()
    {
        $audit = $this->makeAudit();
        $auth = new class() {
            public function user(): User
            {
                $u = new User();
                $u->setUsername('alice');
                $u->setRole('user');

                return $u;
            }

            public function getGuest(): User
            {
                return new User();
            }
        };

        $this->harness($auth)->fireAbsolute($this->request('1.1.1.1'), $audit);

        $events = $audit->query();
        $this->assertCount(1, $events);
        $this->assertSame('alice', $events[0]['user']);
        // Doubled separator collapsed; the homedir is NOT prepended again.
        $this->assertSame('/clientA/x.pdf', $events[0]['path']);
        $this->assertSame('overwritten', $events[0]['detail']);
    }
}
