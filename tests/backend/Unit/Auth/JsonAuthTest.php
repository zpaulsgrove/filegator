<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Tests\Unit\Auth;

use Filegator\Services\Auth\Adapters\JsonFile;
use Filegator\Services\Mfa\BackupCodeGenerator;

/**
 * @internal
 */
class JsonFileTest extends AuthTest
{
    private $mock_file = TEST_DIR.'/mockusers.json';

    protected function tearDown(): void
    {
        @unlink($this->mock_file);
        @unlink($this->mock_file.'.blank');
    }

    public function setAuth()
    {
        @unlink($this->mock_file);
        @touch($this->mock_file.'.blank');

        $this->auth = new JsonFile($this->session);
        $this->auth->init([
            'file' => $this->mock_file,
        ]);
    }

    /**
     * Exercises the REAL consumeBackupCode + mutateUser (flock-based RMW) path.
     * The MockUsers test double overrides mutateUser with a non-locking variant,
     * so the production single-use logic is never otherwise covered.
     */
    public function testConsumeBackupCodeIsSingleUseAgainstRealAdapter()
    {
        $this->addAdmin();
        $this->auth->setMfaSecret('admin@example.com', 'JBSWY3DPEHPK3PXP');
        $this->auth->enableMfa('admin@example.com', BackupCodeGenerator::hashAll(['AAAAA-11111', 'BBBBB-22222']));

        $this->assertSame(2, $this->auth->getMfaState('admin@example.com')['backup_codes_remaining']);

        // The adapter receives the NORMALIZED code (MfaService normalizes before
        // delegating). First use of a valid code succeeds and decrements.
        $this->assertTrue($this->auth->consumeBackupCode('admin@example.com', BackupCodeGenerator::normalize('AAAAA-11111')));
        $this->assertSame(1, $this->auth->getMfaState('admin@example.com')['backup_codes_remaining']);

        // Re-using the SAME code must fail — it was removed from storage.
        $this->assertFalse($this->auth->consumeBackupCode('admin@example.com', BackupCodeGenerator::normalize('AAAAA-11111')));
        $this->assertSame(1, $this->auth->getMfaState('admin@example.com')['backup_codes_remaining']);

        // The OTHER code still works exactly once.
        $this->assertTrue($this->auth->consumeBackupCode('admin@example.com', BackupCodeGenerator::normalize('BBBBB-22222')));
        $this->assertSame(0, $this->auth->getMfaState('admin@example.com')['backup_codes_remaining']);
    }

    public function testConsumeWrongBackupCodeDoesNotDecrement()
    {
        $this->addAdmin();
        $this->auth->setMfaSecret('admin@example.com', 'JBSWY3DPEHPK3PXP');
        $this->auth->enableMfa('admin@example.com', BackupCodeGenerator::hashAll(['AAAAA-11111']));

        $this->assertFalse($this->auth->consumeBackupCode('admin@example.com', BackupCodeGenerator::normalize('ZZZZZ-99999')));
        $this->assertSame(1, $this->auth->getMfaState('admin@example.com')['backup_codes_remaining']);
    }

    /**
     * The locked mutateUser() RMW must reject an unknown username (the
     * $found guard) rather than silently writing nothing — otherwise an
     * MFA state change against a bad username would no-op without error.
     */
    public function testMutatingUnknownUserThrows()
    {
        $this->addAdmin();

        $this->expectException(\Exception::class);
        $this->auth->setMfaSecret('ghost@example.com', 'JBSWY3DPEHPK3PXP');
    }
}
