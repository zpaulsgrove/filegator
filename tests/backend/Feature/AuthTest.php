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

    /**
     * Pin for R-9 (MFA hardening review gap).
     *
     * /changepassword currently does NOT require step-up auth, even when
     * the calling user has MFA enrolled. It accepts oldpassword +
     * newpassword only — no stepup_password / stepup_code plumbing — and
     * succeeds. This is an intentional deferral; AdminController's
     * mutating routes were prioritized for step-up.
     *
     * If step-up is added to /changepassword later, this test will fail.
     * That's the point: update the test intentionally to document the
     * shift in policy, don't just delete this assertion.
     */
    public function testChangePasswordDoesNotRequireStepUpEvenWhenMfaEnabled()
    {
        // Enroll MFA on john, then attach a real authenticated session via
        // the same helper MfaTest uses for post-login state. We bypass the
        // /login/mfa second-step on purpose — we want to assert that an
        // MFA-enrolled, authenticated user can mutate their password
        // without re-proving possession of their second factor.
        $secret = \OTPHP\TOTP::create()->getSecret();
        $codes = \Filegator\Services\Mfa\BackupCodeGenerator::generate(3, 10);

        $app = $this->sendRequest('GET', '/getuser');
        $auth = $app->resolve(\Filegator\Services\Auth\AuthInterface::class);
        $auth->setMfaSecret('john@example.com', $secret);
        $auth->enableMfa('john@example.com', \Filegator\Services\Mfa\BackupCodeGenerator::hashAll($codes));
        $auth->establishSessionFor('john@example.com');
        $this->captureSession();

        // POST with ONLY oldpassword + newpassword — no stepup_* fields.
        $this->sendRequest('POST', '/changepassword', [
            'oldpassword' => 'john123',
            'newpassword' => 'password123',
        ]);

        // Today: 200. When R-9 is closed and step-up is required here,
        // this will start returning 422 / 403 and the test will fail.
        $this->assertOk();
    }
}
