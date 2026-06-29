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
        @unlink(dirname($this->mock_file).'/INITIAL_ADMIN_PASSWORD.txt');
    }

    /**
     * Security regression: seeding users.json from the shipped .blank must NOT
     * leave the well-known default admin password in place. The first-run path
     * replaces it with a random password surfaced once in a sidecar file.
     */
    public function testFirstRunRandomizesSeededAdminPassword()
    {
        // A blank carrying the publicly-documented default admin password hash
        // ('admin123'), exactly as the shipped private/users.json.blank does.
        $defaultHash = '$2y$10$Nu35w4pteLfc7BDCIkDPkecjw8wsH8Y2GMfIewUbXLT7zzW6WOxwq';
        $blank = ['1' => [
            'username' => 'admin', 'name' => 'Admin', 'role' => 'admin',
            'homedir' => '/', 'permissions' => 'read|write',
            'password' => $defaultHash, 'email' => null,
            'mfa_enabled' => false, 'mfa_secret' => null,
            'mfa_backup_codes' => null, 'mfa_enrolled_at' => null,
        ]];

        @unlink($this->mock_file);
        file_put_contents($this->mock_file.'.blank', json_encode($blank));

        $auth = new JsonFile($this->session);
        $auth->init(['file' => $this->mock_file]);

        $passwordFile = dirname($this->mock_file).'/INITIAL_ADMIN_PASSWORD.txt';

        // The generated password is surfaced once...
        $this->assertFileExists($passwordFile);

        // ...the universal default no longer authenticates...
        $this->assertFalse($auth->verifyPasswordOnly('admin', 'admin123'));

        // ...and the surfaced password is the one that now works.
        $contents = (string) file_get_contents($passwordFile);
        $this->assertSame(1, preg_match('/Password:\s*([0-9a-f]+)/', $contents, $m));
        $this->assertTrue($auth->verifyPasswordOnly('admin', $m[1]));
    }

    /**
     * The randomization must only touch a freshly-seeded file, never an
     * existing users.json on subsequent boots.
     */
    public function testExistingUsersFileIsNotReseededOrRewritten()
    {
        $defaultHash = '$2y$10$Nu35w4pteLfc7BDCIkDPkecjw8wsH8Y2GMfIewUbXLT7zzW6WOxwq';
        $existing = ['1' => [
            'username' => 'admin', 'name' => 'Admin', 'role' => 'admin',
            'homedir' => '/', 'permissions' => 'read|write', 'password' => $defaultHash,
        ]];

        // users.json already exists -> init must leave it byte-for-byte alone.
        file_put_contents($this->mock_file, json_encode($existing));
        @touch($this->mock_file.'.blank');

        $auth = new JsonFile($this->session);
        $auth->init(['file' => $this->mock_file]);

        $this->assertSame(json_encode($existing), file_get_contents($this->mock_file));
        $this->assertFileDoesNotExist(dirname($this->mock_file).'/INITIAL_ADMIN_PASSWORD.txt');
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
