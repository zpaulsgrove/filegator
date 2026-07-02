<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Tests\Unit;

use Filegator\Kernel\Request;
use Filegator\Kernel\Response;
use Filegator\Services\Auth\AuthInterface;
use Filegator\Services\Session\SessionStorageInterface;
use Tests\TestCase;

/**
 * @internal
 */
class AppTest extends TestCase
{
    public function testAppWithoutSession()
    {
        $app = $this->bootFreshApp();

        $request = $app->resolve(Request::class);
        $response = $app->resolve(Response::class);
        $session = $app->resolve(SessionStorageInterface::class);
        $auth = $app->resolve(AuthInterface::class);

        $this->assertNotNull($request);
        $this->assertNotNull($response);
        $this->assertNotNull($session);
        $this->assertNull($auth->user());
    }

    public function testAppWithSession()
    {
        // first login request
        $request1 = Request::create(
            '?r=/login',
            'POST',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            '{"username":"admin@example.com","password":"admin123"}'
        );

        $config = $this->getMockConfig();

        $this->bootFreshApp($config, $request1, null, true);
        $prev_session = $request1->getSession();

        // another request with previous session
        $request2 = Request::create(
            '?r=/',
            'GET'
            );
        $request2->setSession($prev_session);

        $app2 = $this->bootFreshApp($config, $request2);

        $auth = $app2->resolve(AuthInterface::class);

        $this->assertNotNull($auth->user());
    }

    public function testBootstrapFailureIsCaught()
    {
        // Replace the FIRST service (Logger) with one whose init() throws, so
        // the failure happens during App's service-init loop, before dispatch.
        // Overriding an existing early key is deliberate: array_replace_recursive
        // appends brand-new keys at the end (after Router), which would be too
        // late to exercise the catch.
        $this->overrideConfig([
            'services' => [
                'Filegator\Services\Logger\LoggerInterface' => [
                    'handler' => '\Tests\Fakes\ThrowingInitService',
                ],
            ],
        ]);

        // App only re-throws (rather than emitting a generic 500 and die-ing)
        // when headers are already sent. Under CLI, headers_sent() flips true
        // after any output, so emit a byte to force the assertable re-throw path
        // instead of killing the test runner.
        echo ' ';

        $this->expectException(\Throwable::class);

        $this->bootFreshApp($this->getMockConfig());
    }
}
