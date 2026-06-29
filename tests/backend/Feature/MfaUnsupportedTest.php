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
 * MfaController guards every endpoint with a 501 when the active auth adapter
 * is not MfaCapable (e.g. LDAP/WPAuth deployments). The NonMfaCapableAuth fake
 * exercised AuthController branches but was never wired against MfaController,
 * so the guard was untested — a dropped guard would let these run against an
 * adapter lacking getMfaState()/setEmail() and fatal at runtime.
 *
 * @internal
 */
class MfaUnsupportedTest extends TestCase
{
    private function useNonMfaAuth(): void
    {
        $this->overrideConfig(['services' => [
            'Filegator\Services\Auth\AuthInterface' => ['handler' => '\Tests\Fakes\NonMfaCapableAuth'],
        ]]);
    }

    /**
     * @dataProvider mfaEndpoints
     */
    public function testMfaEndpointReturns501ForNonCapableAdapter(string $method, string $route)
    {
        $this->useNonMfaAuth();
        $this->signIn('john@example.com', 'john123'); // legacy (non-MFA) login path

        $this->sendRequest($method, $route);

        $this->assertStatus(501);
    }

    public function mfaEndpoints(): array
    {
        return [
            'state' => ['GET', '/mfa/state'],
            'begin enroll' => ['POST', '/mfa/enroll/begin'],
            'confirm enroll' => ['POST', '/mfa/enroll/confirm'],
            'disable' => ['POST', '/mfa/disable'],
            'regenerate backup codes' => ['POST', '/mfa/backup_codes/regenerate'],
            'update email' => ['POST', '/me/email'],
        ];
    }
}
