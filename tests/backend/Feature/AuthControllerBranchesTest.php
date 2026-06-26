<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Branch-coverage tests for AuthController error / edge paths that the
 * happy-path AuthTest / MfaTest suites don't exercise:
 *
 *   - legacy (non-MFA-capable) failLogin
 *   - the vanished-user guard after a successful password verify
 *   - the getMfaState try/catch in both login() and userResponsePayload()
 *   - the loginMfaSetup guards (no pending, wrong phase, binding/nonce
 *     mismatch, per-username lockout, invalid code)
 *   - the wrong-phase guard at /login/mfa
 *   - the IPv6/non-byte-aligned prefix branch in normalizeIpForBinding
 *   - the stale-lockfile reap in isLockedOut
 *
 * @internal
 */
class AuthControllerBranchesTest extends TestCase
{
    private function useNonMfaAuth(): void
    {
        $this->overrideConfig([
            'services' => [
                'Filegator\Services\Auth\AuthInterface' => [
                    'handler' => '\Tests\Fakes\NonMfaCapableAuth',
                ],
            ],
        ]);
    }

    private function useThrowingMfaAuth(): void
    {
        $this->overrideConfig([
            'services' => [
                'Filegator\Services\Auth\AuthInterface' => [
                    'handler' => '\Tests\Fakes\ThrowingMfaStateAuth',
                ],
            ],
        ]);
    }

    private function useVanishingUserAuth(): void
    {
        $this->overrideConfig([
            'services' => [
                'Filegator\Services\Auth\AuthInterface' => [
                    'handler' => '\Tests\Fakes\VanishingUserAuth',
                ],
            ],
        ]);
    }

    // ---------------------------------------------------------------------
    // login(): legacy (non-MFA-capable adapter) bad-credentials failLogin.
    // Targets AuthController.php:62.
    // ---------------------------------------------------------------------
    public function testLegacyAdapterBadCredentialsFailLogin()
    {
        $this->useNonMfaAuth();

        $this->sendRequest('POST', '/login', [
            'username' => 'john@example.com',
            'password' => 'wrongpass',
        ], [], ['REMOTE_ADDR' => '203.0.113.5']);

        $this->assertStatus(422);
    }

    // ---------------------------------------------------------------------
    // login(): MFA-capable adapter where the user record vanishes between
    // verifyPasswordOnly() and find(). Targets the `! $user` guard at
    // AuthController.php:73.
    // ---------------------------------------------------------------------
    public function testUserVanishesAfterPasswordVerifyFailsLogin()
    {
        $this->useVanishingUserAuth();

        $this->sendRequest('POST', '/login', [
            'username' => 'john@example.com',
            'password' => 'anything',
        ], [], ['REMOTE_ADDR' => '203.0.113.6']);

        $this->assertStatus(422);
    }

    // ---------------------------------------------------------------------
    // login(): getMfaState() throws → catch logs and failLogin.
    // Targets AuthController.php:78,79,80.
    // ---------------------------------------------------------------------
    public function testGetMfaStateThrowingDuringLoginFailsClosed()
    {
        $this->useThrowingMfaAuth();

        $this->sendRequest('POST', '/login', [
            'username' => 'john@example.com',
            'password' => 'john123',
        ], [], ['REMOTE_ADDR' => '203.0.113.7']);

        // verifyPasswordOnly + find succeed, getMfaState throws → fail closed.
        $this->assertStatus(422);
    }

    // ---------------------------------------------------------------------
    // userResponsePayload(): getMfaState() throws → catch logs and omits
    // the mfa_enabled field but /getuser still succeeds.
    // Targets AuthController.php:276,281.
    // ---------------------------------------------------------------------
    public function testGetUserSurvivesGetMfaStateThrow()
    {
        $this->useThrowingMfaAuth();

        // No session → guest; userResponsePayload runs getMfaState(guest)
        // which throws and is swallowed.
        $this->sendRequest('GET', '/getuser');
        $this->assertOk();

        $data = $this->decodeResponseJson();
        $this->assertSame('guest', $data['data']['role']);
        // The field is omitted because the state read failed.
        $this->assertArrayNotHasKey('mfa_enabled', $data['data']);
    }

    // ---------------------------------------------------------------------
    // /login/mfa wrong phase: a 'setup' pending submitted to the verify
    // endpoint. Targets AuthController.php:136.
    // ---------------------------------------------------------------------
    public function testLoginMfaRejectsSetupPhasePending()
    {
        $this->overrideConfig(['mfa_required_for_admins' => true]);

        // Forced setup → pending phase = 'setup'.
        $this->sendRequest('POST', '/login', [
            'username' => 'admin@example.com',
            'password' => 'admin123',
        ]);
        $this->assertOk();
        $nonce = (string) ($this->decodeResponseJson()['data']['mfa_nonce'] ?? '');
        $this->captureSession();

        // Submit to the VERIFY endpoint → phase mismatch.
        $this->sendRequest('POST', '/login/mfa', ['code' => '000000', 'mfa_nonce' => $nonce]);
        $this->assertUnprocessable();
    }

    // ---------------------------------------------------------------------
    // loginMfaSetup: no pending state at all. Targets AuthController.php:201.
    // ---------------------------------------------------------------------
    public function testLoginMfaSetupWithoutPendingRejected()
    {
        $this->sendRequest('POST', '/login/mfa/setup', ['code' => '000000', 'mfa_nonce' => 'x']);
        $this->assertUnprocessable();
    }

    // ---------------------------------------------------------------------
    // loginMfaSetup: wrong phase (a 'verify' pending sent to /setup).
    // Targets AuthController.php:204.
    // ---------------------------------------------------------------------
    public function testLoginMfaSetupRejectsVerifyPhasePending()
    {
        // Enroll real MFA so /login produces a 'verify' pending.
        $app = $this->sendRequest('GET', '/getuser');
        $auth = $app->resolve(\Filegator\Services\Auth\AuthInterface::class);
        $secret = \OTPHP\TOTP::create()->getSecret();
        $auth->setMfaSecret('john@example.com', $secret);
        $auth->enableMfa('john@example.com', \Filegator\Services\Mfa\BackupCodeGenerator::hashAll(['AAAAA-11111']));

        $this->sendRequest('POST', '/login', [
            'username' => 'john@example.com',
            'password' => 'john123',
        ]);
        $this->assertOk();
        $nonce = (string) ($this->decodeResponseJson()['data']['mfa_nonce'] ?? '');
        $this->captureSession();

        // 'verify' pending hitting the SETUP endpoint → phase mismatch.
        $this->sendRequest('POST', '/login/mfa/setup', [
            'code' => $this->totpNow($secret),
            'mfa_nonce' => $nonce,
        ]);
        $this->assertUnprocessable();
    }

    // ---------------------------------------------------------------------
    // loginMfaSetup: binding mismatch (different UA than the one that
    // started the forced setup). Targets AuthController.php:208.
    // ---------------------------------------------------------------------
    public function testLoginMfaSetupBindingMismatchRejected()
    {
        $this->overrideConfig(['mfa_required_for_admins' => true]);

        $this->sendRequest('POST', '/login', [
            'username' => 'admin@example.com',
            'password' => 'admin123',
        ], [], ['HTTP_USER_AGENT' => 'Mozilla/Original']);
        $this->assertOk();
        $data = $this->decodeResponseJson()['data'];
        $secret = $data['enrollment']['secret'];
        $nonce = $data['mfa_nonce'];
        $this->captureSession();

        // Same cookie + nonce, different UA → binding mismatch.
        $this->sendRequest('POST', '/login/mfa/setup', [
            'code' => $this->totpNow($secret),
            'mfa_nonce' => $nonce,
        ], [], ['HTTP_USER_AGENT' => 'Mozilla/Attacker']);
        $this->assertUnprocessable();
    }

    // ---------------------------------------------------------------------
    // loginMfaSetup: nonce mismatch (valid binding, tampered nonce).
    // Targets AuthController.php:211.
    // ---------------------------------------------------------------------
    public function testLoginMfaSetupNonceMismatchRejected()
    {
        $this->overrideConfig(['mfa_required_for_admins' => true]);

        $this->sendRequest('POST', '/login', [
            'username' => 'admin@example.com',
            'password' => 'admin123',
        ]);
        $this->assertOk();
        $secret = $this->decodeResponseJson()['data']['enrollment']['secret'];
        $this->captureSession();

        // Correct UA/binding but a bogus nonce.
        $this->sendRequest('POST', '/login/mfa/setup', [
            'code' => $this->totpNow($secret),
            'mfa_nonce' => 'deadbeefdeadbeef',
        ]);
        $this->assertUnprocessable();
    }

    // ---------------------------------------------------------------------
    // loginMfaSetup: invalid code records a failure and 422s; repeated
    // failures trip the per-username lockout (429).
    // Targets AuthController.php:223,224 (invalid code) and 216 (lockout).
    // ---------------------------------------------------------------------
    public function testLoginMfaSetupInvalidCodeThenLockout()
    {
        $this->overrideConfig([
            'mfa_required_for_admins' => true,
            'lockout_attempts' => 3,
            'lockout_timeout' => 60,
        ]);

        // Three invalid-code setup attempts → each records a per-username
        // failure (lines 223,224) and returns 422.
        for ($i = 0; $i < 3; $i++) {
            $this->signOut();
            $this->sendRequest('POST', '/login', [
                'username' => 'admin@example.com',
                'password' => 'admin123',
            ], [], ['REMOTE_ADDR' => '198.51.100.'.($i + 1)]);
            $this->assertOk();
            $nonce = $this->decodeResponseJson()['data']['mfa_nonce'];
            $this->captureSession();

            $this->sendRequest('POST', '/login/mfa/setup', [
                'code' => '000000',
                'mfa_nonce' => $nonce,
            ], [], ['REMOTE_ADDR' => '198.51.100.'.($i + 1)]);
            $this->assertUnprocessable();
        }

        // 4th attempt from a fresh IP is blocked by the per-username lock
        // before the code is even checked (line 216).
        $this->signOut();
        $this->sendRequest('POST', '/login', [
            'username' => 'admin@example.com',
            'password' => 'admin123',
        ], [], ['REMOTE_ADDR' => '198.51.100.250']);
        $this->assertOk();
        $nonce = $this->decodeResponseJson()['data']['mfa_nonce'];
        $this->captureSession();

        $this->sendRequest('POST', '/login/mfa/setup', [
            'code' => '111111',
            'mfa_nonce' => $nonce,
        ], [], ['REMOTE_ADDR' => '198.51.100.250']);
        $this->assertStatus(429);
    }

    // ---------------------------------------------------------------------
    // normalizeIpForBinding: a non-byte-aligned IPv4 prefix (/20) exercises
    // the partial-byte masking branch. Targets AuthController.php:439,440.
    // 192.0.2.x and 192.0.0.x share the /20 (third octet 2 -> high nibble 0),
    // so a same-/20 follow-up must be accepted.
    // ---------------------------------------------------------------------
    public function testNonByteAlignedIpPrefixMasksPartialByte()
    {
        $this->overrideConfig(['mfa_pending_bind_ip_prefix' => '/20']);

        $app = $this->sendRequest('GET', '/getuser');
        $auth = $app->resolve(\Filegator\Services\Auth\AuthInterface::class);
        $secret = \OTPHP\TOTP::create()->getSecret();
        $auth->setMfaSecret('john@example.com', $secret);
        $auth->enableMfa('john@example.com', \Filegator\Services\Mfa\BackupCodeGenerator::hashAll(['AAAAA-11111']));

        $this->sendRequest('POST', '/login', [
            'username' => 'john@example.com',
            'password' => 'john123',
        ], [], ['REMOTE_ADDR' => '192.0.2.10']);
        $this->captureSession();
        $nonce = (string) ($this->decodeResponseJson()['data']['mfa_nonce'] ?? '');

        // 192.0.14.99 is within the same /20 as 192.0.2.10 (third octet 2 and
        // 14 both have high nibble 0) → partial-byte mask matches → accepted.
        $this->sendRequest('POST', '/login/mfa', [
            'code' => $this->totpNow($secret),
            'mfa_nonce' => $nonce,
        ], [], ['REMOTE_ADDR' => '192.0.14.99']);
        $this->assertOk();
    }

    // ---------------------------------------------------------------------
    // isLockedOut: a stale lockfile (older than lockout_timeout) is reaped
    // on the next login. With lockout_timeout=0 the previous attempt's lock
    // is always stale. Targets AuthController.php:461.
    // ---------------------------------------------------------------------
    public function testStaleLockfileIsReaped()
    {
        $this->overrideConfig(['lockout_attempts' => 1, 'lockout_timeout' => 0]);

        $ip = ['REMOTE_ADDR' => '198.51.100.77'];

        // First bad login writes a lockfile.
        $this->sendRequest('POST', '/login', [
            'username' => 'john@example.com',
            'password' => 'wrongpass',
        ], [], $ip);
        $this->assertStatus(422);

        // Second login: isLockedOut() finds the now-stale lockfile (age >= 0)
        // and removes it, so the request is NOT 429 — it proceeds and 422s.
        $this->sendRequest('POST', '/login', [
            'username' => 'john@example.com',
            'password' => 'wrongpass',
        ], [], $ip);
        $this->assertStatus(422);
    }
}
