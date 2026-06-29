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
use Tests\Fakes\InMemoryMailer;
use Tests\Fakes\RecordingLogger;
use Tests\TestCase;

/**
 * Unit coverage for AuditMailer's pure subject/diff/format logic. Previously
 * exercised only through slow full-stack feature tests (AuditAlertsTest) and
 * excluded from mutation scope; these fast unit tests pin every branch
 * directly so the file can re-enter the Infection gate.
 *
 * @internal
 */
class AuditMailerTest extends TestCase
{
    /** @var RecordingLogger */
    protected $logger;

    protected function setUp(): void
    {
        parent::setUp();
        InMemoryMailer::reset();
        $this->logger = new RecordingLogger();
    }

    private function make(array $configOverrides = []): AuditMailer
    {
        $mailer = new InMemoryMailer();
        $audit = new AuditMailer($mailer, $this->logger);
        $audit->init(array_merge([
            'recipient' => 'audit@example.com',
            'from_email' => 'from@example.com',
            'app_label' => 'Test Portal',
            'enabled' => true,
        ], $configOverrides));

        return $audit;
    }

    private function last(): array
    {
        $msg = InMemoryMailer::last();
        $this->assertNotNull($msg, 'expected an audit email to have been sent');

        return $msg;
    }

    public function testUserCreatedSendsSnapshotAlert()
    {
        $this->make()->userCreated('admin', [
            'username' => 'alice',
            'name' => 'Alice',
            'role' => 'user',
            'homedirs' => ['/clientA'],
            'permissions' => ['read', 'write'],
        ], 'alice@example.com');

        $msg = $this->last();
        $this->assertSame('audit@example.com', $msg['to']);
        $this->assertSame('from@example.com', $msg['from_email']);
        $this->assertSame('New user created: alice', $msg['subject']);
        $this->assertStringContainsString('Role: user', $msg['text']);
        $this->assertStringContainsString('Folder: /clientA', $msg['text']);
        $this->assertStringContainsString('Permissions: read, write', $msg['text']);
        $this->assertStringContainsString('Email: alice@example.com', $msg['text']);
    }

    public function testUserDeletedSendsAlert()
    {
        $this->make()->userDeleted('admin', ['username' => 'bob', 'role' => 'user'], null);

        $msg = $this->last();
        $this->assertSame('User deleted: bob', $msg['subject']);
        $this->assertStringContainsString('Email: (none)', $msg['text']);
    }

    public function testNoOpUpdateIsSilent()
    {
        $before = ['role' => 'user', 'permissions' => ['read'], 'username' => 'x', 'homedirs' => ['/x']];
        $this->make()->userUpdated('admin', 'x', $before, $before, null, null, false);

        $this->assertSame([], InMemoryMailer::$messages);
    }

    public function testCosmeticNameOnlyUpdateIsSilent()
    {
        $before = ['name' => 'Old', 'role' => 'user', 'homedirs' => ['/x']];
        $after = ['name' => 'New', 'role' => 'user', 'homedirs' => ['/x']];
        $this->make()->userUpdated('admin', 'x', $before, $after, null, null, false);

        $this->assertSame([], InMemoryMailer::$messages, 'a name-only change must not alert');
    }

    public function testUpdateSubjectUsesHighestPriorityField()
    {
        // role + permissions both change -> role wins (UPDATE_PRIORITY order).
        $this->make()->userUpdated('admin', 'x',
            ['role' => 'user', 'permissions' => ['read'], 'homedirs' => ['/x']],
            ['role' => 'admin', 'permissions' => ['read', 'write'], 'homedirs' => ['/x']],
            null, null, false);

        $this->assertSame('User x role changed: user → admin', $this->last()['subject']);
    }

    public function testPermissionsReorderIsNotADiff()
    {
        // Set semantics: same perms in a different order must not alert.
        $before = ['permissions' => ['read', 'write'], 'role' => 'user', 'homedirs' => ['/x']];
        $after = ['permissions' => ['write', 'read'], 'role' => 'user', 'homedirs' => ['/x']];
        $this->make()->userUpdated('admin', 'x', $before, $after, null, null, false);

        $this->assertSame([], InMemoryMailer::$messages);
    }

    /**
     * @dataProvider homedirTransitions
     */
    public function testHomedirChangeSubjectPerTransition(array $before, array $after, string $expectedSubject)
    {
        $this->make()->userUpdated('x', 'x',
            ['homedirs' => $before, 'role' => 'user'],
            ['homedirs' => $after, 'role' => 'user'],
            null, null, false);

        $this->assertSame($expectedSubject, $this->last()['subject']);
    }

    public function homedirTransitions(): array
    {
        return [
            '1->1 rename' => [['/a'], ['/b'], 'Folder changed for x: /a → /b'],
            '1->N gain' => [['/a'], ['/a', '/b'], 'Folders changed for x: 1 → 2 folders'],
            'N->1 reduce' => [['/a', '/b'], ['/a'], 'Folders changed for x: 2 → 1 folder'],
            'N->M rearrange' => [['/a', '/b'], ['/a', '/b', '/c'], 'Folders changed for x: /a, /b → /a, /b, /c'],
        ];
    }

    public function testMfaBackupCodeWarningThreshold()
    {
        $this->make()->mfaBackupCodeConsumed('alice', '203.0.113.7', 2);
        $this->assertStringContainsString('WARNING', $this->last()['text']);

        InMemoryMailer::reset();
        $this->make()->mfaBackupCodeConsumed('alice', '203.0.113.7', 3);
        $this->assertStringNotContainsString('WARNING', $this->last()['text']);
    }

    public function testDisabledFlagSuppressesSend()
    {
        $this->make(['enabled' => false])->userCreated('admin', ['username' => 'x'], null);
        $this->assertSame([], InMemoryMailer::$messages);
    }

    public function testUnconfiguredLogsSkipAndDoesNotSend()
    {
        $this->make(['recipient' => ''])->userCreated('admin', ['username' => 'x'], null);

        $this->assertSame([], InMemoryMailer::$messages);
        $joined = implode("\n", $this->logger->messages);
        $this->assertStringContainsString('not configured', $joined);
    }

    public function testWeeklyDigestRendersEscapedHtmlAndCountsMfa()
    {
        $sent = $this->make(['from_name' => 'Audit Bot'])->sendWeeklyDigest([
            ['username' => '<script>', 'name' => 'Eve', 'role' => 'user', 'homedirs' => ['/e'], 'permissions' => ['read'], 'mfa_enabled' => true, 'email' => 'e@x.com'],
            ['username' => 'bob', 'name' => 'Bob', 'role' => 'user', 'homedirs' => ['/b'], 'permissions' => ['read'], 'mfa_enabled' => false, 'email' => null],
        ]);

        $this->assertTrue($sent);
        $msg = $this->last();
        $this->assertSame('Weekly audit digest — 2 users (1 with MFA)', $msg['subject']);
        $this->assertSame('Audit Bot', $msg['from_name']);
        // Attacker-controlled username is HTML-escaped in the digest body.
        $this->assertStringNotContainsString('<script>', $msg['html']);
        $this->assertStringContainsString('&lt;script&gt;', $msg['html']);
    }

    public function testWeeklyDigestWithNoRowsDoesNotSend()
    {
        $this->assertFalse($this->make()->sendWeeklyDigest([]));
        $this->assertSame([], InMemoryMailer::$messages);
    }
}
