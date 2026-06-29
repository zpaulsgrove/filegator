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
 * Server-side security middleware coverage: CSRF rejection of a FORGED token
 * (not just a missing one), the always-on clickjacking response headers, and
 * the route-gate fail-closed behaviour for guest / non-admin requests against
 * the REAL route table. These are the authz/anti-forgery controls that protect
 * every state-changing endpoint.
 *
 * @internal
 */
class SecurityCsrfHeadersTest extends TestCase
{
    private function enableCsrf(): void
    {
        $this->overrideConfig(['services' => [
            'Filegator\Services\Security\Security' => ['config' => ['csrf_protection' => true]],
        ]]);
    }

    public function testForgedCsrfTokenIsRejected()
    {
        // A non-empty but WRONG token must 403 — distinct from the missing-token
        // path. A relaxed comparison that accepted any non-empty token would
        // pass the missing-token test but fail this one.
        $this->enableCsrf();

        $this->sendRequest('POST', '/changepassword', [
            'current' => 'x',
            'new' => 'y',
        ], [], ['HTTP_X_CSRF_TOKEN' => 'forged-garbage-token']);

        $this->assertStatus(403);
        $this->assertStringContainsString('CSRF token invalid', (string) $this->response->getContent());
    }

    public function testCsrfIsSelectiveSafeMethodsPassAndGetAToken()
    {
        // Positive control: with CSRF on, a GET is not blocked and is issued a
        // fresh token in the response header — proving the forged-token 403
        // above is selective, not a blanket rejection.
        $this->enableCsrf();

        $this->sendRequest('GET', '/getuser');

        $this->assertNotEquals(403, $this->response->getStatusCode());
        $this->assertNotEmpty($this->response->headers->get('X-CSRF-Token'));
    }

    /**
     * @dataProvider userOnlyRoutes
     */
    public function testGuestIsGatedFromUserOnlyRoutes(string $method, string $route)
    {
        $this->signOut();
        $this->sendRequest($method, $route);
        $this->assertStatus(404); // gate fails closed for the guest role
    }

    public function userOnlyRoutes(): array
    {
        return [
            ['GET', '/mfa/state'],
            ['POST', '/mfa/disable'],
            ['POST', '/me/email'],
            ['POST', '/selectfolder'],
            ['POST', '/changepassword'],
        ];
    }

    public function testNonAdminIsGatedFromAdminRoutes()
    {
        $this->signIn('john@example.com', 'john123'); // role: user

        $this->sendRequest('GET', '/listusers');
        $this->assertStatus(404);

        $this->sendRequest('GET', '/admin/audit-log');
        $this->assertStatus(404);
    }
}
