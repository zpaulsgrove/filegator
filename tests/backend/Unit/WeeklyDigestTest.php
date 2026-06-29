<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Tests\Unit;

use Filegator\Services\Audit\AuditMailer;
use Filegator\Services\Audit\WeeklyDigest;
use Filegator\Services\Auth\AuthInterface;
use Tests\Fakes\InMemoryMailer;
use Tests\Fakes\RecordingLogger;
use Tests\TestCase;

/**
 * Unit coverage for the WeeklyDigest scheduler — the flock'd state-file
 * interval machine. Previously only feature-tested and excluded from mutation
 * scope; these pin the interval/timestamp logic and nextDueAt directly.
 *
 * @internal
 */
class WeeklyDigestTest extends TestCase
{
    protected $stateFile;

    /** @var AuthInterface */
    protected $auth;

    protected function setUp(): void
    {
        parent::setUp();
        InMemoryMailer::reset();
        $this->stateFile = TEST_TMP_PATH.'wd_state_test.json';
        @unlink($this->stateFile);

        // Resolve the seeded MockUsers (guest + admin + john + jane + multi)
        // from a booted app — JsonFile-derived adapters need DI construction.
        $app = $this->sendRequest('GET', '/getuser');
        $this->auth = $app->resolve(AuthInterface::class);
    }

    private function make(int $interval = 100): WeeklyDigest
    {
        $mailer = new InMemoryMailer();
        $auditMailer = new AuditMailer($mailer, new RecordingLogger());
        $auditMailer->init(['recipient' => 'audit@example.com', 'from_email' => 'from@example.com']);

        $digest = new WeeklyDigest($auditMailer, new RecordingLogger());
        $digest->init(['state_file' => $this->stateFile, 'interval_seconds' => $interval]);

        return $digest;
    }

    public function testUnconfiguredNeverFires()
    {
        $mailer = new InMemoryMailer();
        $auditMailer = new AuditMailer($mailer, new RecordingLogger());
        $auditMailer->init(['recipient' => 'audit@example.com', 'from_email' => 'from@example.com']);
        $digest = new WeeklyDigest($auditMailer, new RecordingLogger());
        $digest->init([]); // no state_file

        $this->assertFalse($digest->maybeFire($this->auth));
        $this->assertSame([], InMemoryMailer::$messages);
    }

    public function testFirstCallFiresAndWritesState()
    {
        $digest = $this->make();

        $this->assertTrue($digest->maybeFire($this->auth));
        $this->assertFileExists($this->stateFile);
        $msg = InMemoryMailer::last();
        $this->assertNotNull($msg);
        $this->assertStringContainsString('Weekly audit digest', $msg['subject']);
        // Guest is filtered upstream: MockUsers has guest+admin+john+jane+multi = 5,
        // so the digest covers the 4 real accounts.
        $this->assertStringContainsString('4 user', $msg['subject']);
    }

    public function testSecondCallWithinIntervalIsSkipped()
    {
        $digest = $this->make(100);
        $this->assertTrue($digest->maybeFire($this->auth));
        InMemoryMailer::reset();

        // Immediately again, well within the 100s interval.
        $this->assertFalse($digest->maybeFire($this->auth));
        $this->assertSame([], InMemoryMailer::$messages);
    }

    public function testFiresAgainAfterIntervalElapsed()
    {
        $digest = $this->make(100);
        $this->assertTrue($digest->maybeFire($this->auth));
        InMemoryMailer::reset();

        // Backdate the recorded timestamp beyond the interval.
        file_put_contents($this->stateFile, json_encode(['last_weekly_digest_at' => time() - 200]));

        $this->assertTrue($digest->maybeFire($this->auth));
        $this->assertNotNull(InMemoryMailer::last());
    }

    public function testNextDueAtIsZeroBeforeFiringThenLastPlusInterval()
    {
        $digest = $this->make(100);
        $this->assertSame(0, $digest->nextDueAt());

        $before = time();
        $digest->maybeFire($this->auth);
        $next = $digest->nextDueAt();

        $this->assertGreaterThanOrEqual($before + 100, $next);
        $this->assertLessThanOrEqual(time() + 100, $next);
    }
}
