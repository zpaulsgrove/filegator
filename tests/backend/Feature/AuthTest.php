<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Tests\Feature;

use Filegator\Kernel\Request;
use Filegator\Kernel\Response;
use Filegator\Services\Auth\AuthInterface;
use Filegator\Services\Mfa\BackupCodeGenerator;
use OTPHP\TOTP;
use Tests\TestCase;

/**
 * @internal
 */
class AuthTest extends TestCase
{
    public function testSuccessfulLogin()
    {
        $this->sendRequest('POST', '/login', [
            'username' => 'john@example.com',
            'password' => 'john123',
        ]);

        $this->assertOk();
    }

    public function testLoginResponsePayloadShape()
    {
        // Pin the exact user-object payload returned by POST /login so the
        // upcoming multi-folder refactor (which adds a 'homedirs' array
        // alongside the existing 'homedir' scalar) doesn't accidentally
        // drop the legacy key the frontend currently consumes.
        $this->sendRequest('POST', '/login', [
            'username' => 'john@example.com',
            'password' => 'john123',
        ]);

        $this->assertOk();
        $this->assertResponseJsonHas([
            'data' => [
                'username'    => 'john@example.com',
                'name'        => 'John Doe',
                'role'        => 'user',
                'homedir'     => '/john',
                'permissions' => ['read', 'write', 'upload', 'download', 'batchdownload'],
            ],
        ]);
    }

    public function testBadLogin()
    {
        $this->sendRequest('POST', '/login', [
            'username' => 'fake',
            'password' => 'fake',
        ]);

        $this->assertUnprocessable();
    }

    public function testBruteForceLogin()
    {
        // standard 422 bad response code
        $this->sendRequest('POST', '/login', [
            'username' => 'bad',
            'password' => 'bad',
        ], [], ['REMOTE_ADDR' => '10.10.10.10']);
        $this->assertStatus(422);

        // too many requests should change the response code to 429
        for ($i = 0; $i < 20; $i++) {
            $this->sendRequest('POST', '/login', [
                'username' => 'bad',
                'password' => 'bad',
            ], [], ['REMOTE_ADDR' => '10.10.10.10']);
        }
        $this->assertStatus(429);

        // now even the good one from this ip should fail as 429
        $this->sendRequest('POST', '/login', [
            'username' => 'john@example.com',
            'password' => 'john123',
        ], [], ['REMOTE_ADDR' => '10.10.10.10']);
        $this->assertStatus(429);

        // another ip should fail as a standard 422 bad response (unaffected)
        $this->sendRequest('POST', '/login', [
            'username' => 'bad',
            'password' => 'bad',
        ], [], ['REMOTE_ADDR' => '2001:db8:3333:4444:5555:6666:7777:8888']);
        $this->assertStatus(422);

        // another ip with valid credentials should be ok (unaffected)
        $this->sendRequest('POST', '/login', [
            'username' => 'john@example.com',
            'password' => 'john123',
        ], [], ['REMOTE_ADDR' => '20.20.20.20']);
        $this->assertOk();
    }

    public function testAlreadyLoggedIn()
    {
        $username = 'john@example.com';
        $this->signIn($username, 'john123');

        $this->sendRequest('POST', '/login', ['username' => $username, 'password' => 'john123']);

        $this->assertStatus(404);
    }

    public function testGetUser()
    {
        $user = 'john@example.com';
        $this->signIn($user, 'john123');

        $this->sendRequest('GET', '/getuser');

        $this->assertOk();
        $this->assertResponseJsonHas([
            'data' => [
                'username' => $user,
                'name' => 'John Doe',
                'role' => 'user',
                'homedir' => '/john',
            ],
        ]);
    }

    public function testGetAdmin()
    {
        $admin = 'admin@example.com';
        $this->signIn($admin, 'admin123');

        $this->sendRequest('GET', '/getuser');

        $this->assertOk();
        $this->assertResponseJsonHas([
            'data' => [
                'username' => $admin,
                'name' => 'Admin',
                'role' => 'admin',
                'homedir' => '/',
            ],
        ]);
    }

    public function testReceiveGuestIfNoUserIsLoggedIn()
    {
        $this->sendRequest('GET', '/getuser');

        $this->assertOk();
        $this->assertResponseJsonHas([
            'data' => [
                'role' => 'guest',
            ],
        ]);
    }

    public function testLogout()
    {
        $this->signIn('john@example.com', 'john123');
        $this->sendRequest('POST', '/logout');

        $this->assertOk();
    }

    public function testResponseThrows404()
    {
        $request = Request::create(
            '?r=/notfound',
            'GET'
            );

        $app = $this->bootFreshApp(null, $request);

        $response = $app->resolve(Response::class);

        $this->assertEquals($response->getStatusCode(), 404);
    }

    public function testChangePassword()
    {
        $this->signIn('john@example.com', 'john123');
        $this->sendRequest('POST', '/changepassword', [
            'oldpassword' => 'john123',
            'newpassword' => '',
        ]);
        $this->assertStatus(422);

        $this->signIn('john@example.com', 'john123');
        $this->sendRequest('POST', '/changepassword', [
            'oldpassword' => 'wrongpass',
            'newpassword' => 'password123',
        ]);
        $this->assertStatus(422);

        $this->signIn('john@example.com', 'john123');
        $this->sendRequest('POST', '/changepassword', [
            'oldpassword' => 'john123',
            'newpassword' => 'password123',
        ]);
        $this->assertOk();
    }

    // -------------------------------------------------------------------------
    // Workstream 1 — mfa_enabled in /getuser response
    // -------------------------------------------------------------------------

    /**
     * Enroll MFA for john, establish a session, GET /getuser — mfa_enabled must be true.
     */
    public function testGetUserIncludesMfaEnabledTrueForEnrolledUser()
    {
        // Enroll MFA directly via the auth adapter (mirrors MfaTest::enrollMfa).
        $app = $this->sendRequest('GET', '/getuser');
        $auth = $app->resolve(AuthInterface::class);
        $secret = TOTP::create()->getSecret();
        $auth->setMfaSecret('john@example.com', $secret);
        $auth->enableMfa('john@example.com', BackupCodeGenerator::hashAll(BackupCodeGenerator::generate(3, 10)));

        // Use establishSessionFor to bypass the two-step MFA login flow —
        // signIn() would stall at the mfa_required step for an enrolled user.
        $auth->establishSessionFor('john@example.com');
        $this->captureSession();

        $this->sendRequest('GET', '/getuser');

        $this->assertOk();
        $data = $this->decodeResponseJson();
        $this->assertArrayHasKey('mfa_enabled', $data['data'], '/getuser should include mfa_enabled for MFA-capable adapters');
        $this->assertTrue($data['data']['mfa_enabled'], 'mfa_enabled should be true for an enrolled user');
    }

    /**
     * Sign in as john without enrolling MFA — mfa_enabled must be false.
     */
    public function testGetUserIncludesMfaEnabledFalseForNonEnrolledUser()
    {
        $this->signIn('john@example.com', 'john123');

        $this->sendRequest('GET', '/getuser');

        $this->assertOk();
        $data = $this->decodeResponseJson();
        $this->assertArrayHasKey('mfa_enabled', $data['data'], '/getuser should include mfa_enabled for MFA-capable adapters');
        $this->assertFalse($data['data']['mfa_enabled'], 'mfa_enabled should be false for a user without MFA enrolled');
    }

    /**
     * Swap the auth adapter to one that does NOT implement MfaCapableInterface.
     * GET /getuser must succeed and must NOT include mfa_enabled (or at minimum
     * must not throw). This is the regression guard for LDAP/WPAuth adapters.
     */
    public function testGetUserOmitsMfaEnabledForNonMfaCapableAdapter()
    {
        $this->overrideConfig([
            'services' => [
                'Filegator\Services\Auth\AuthInterface' => [
                    'handler' => '\Tests\Fakes\NonMfaCapableAuth',
                ],
            ],
        ]);

        $this->signIn('john@example.com', 'john123');

        $this->sendRequest('GET', '/getuser');

        $this->assertOk();
        $data = $this->decodeResponseJson();
        // The adapter has no MFA capability — the field must be absent so no
        // false signal is sent to the frontend step-up dialog.
        $this->assertArrayNotHasKey('mfa_enabled', $data['data'], 'mfa_enabled must be absent when the auth adapter is not MFA-capable');
    }
}
