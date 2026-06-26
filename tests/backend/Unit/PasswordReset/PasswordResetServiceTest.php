<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Tests\Unit\PasswordReset;

use Filegator\Config\Config;
use Filegator\Services\Logger\LoggerInterface;
use Filegator\Services\PasswordReset\PasswordResetService;
use Filegator\Services\Tmpfs\Adapters\Tmpfs;
use Tests\Fakes\FakeResettableAuth;
use Tests\Fakes\InMemoryMailer;
use Tests\TestCase;

/**
 * Direct unit coverage for PasswordResetService. The feature suite drives this
 * over HTTP but only checks end-to-end status codes; these tests pin the
 * internal logic mutation testing showed was unchecked: the reset-URL builder,
 * the per-IP/per-email rate-limit caps, and the single-use confirm flow.
 *
 * @internal
 */
class PasswordResetServiceTest extends TestCase
{
    private $tmpfs;

    private $fakeAuth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetTempDir();

        $this->tmpfs = new Tmpfs();
        $this->tmpfs->init([
            'path' => TEST_TMP_PATH,
            'gc_probability_perc' => 0,
            'gc_older_than' => 60 * 60 * 24 * 2,
        ]);

        $this->fakeAuth = new FakeResettableAuth();
    }

    private function makeService(array $config = [], array $init = []): PasswordResetService
    {
        // LoggerInterface::log() now defaults $level, so a plain interface mock
        // accepts the single-argument calls the service makes.
        $logger = $this->createMock(LoggerInterface::class);
        $service = new PasswordResetService(
            $this->fakeAuth,
            new InMemoryMailer(),
            $this->tmpfs,
            $logger,
            new Config($config)
        );
        $service->init(array_merge([
            'token_file' => TEST_TMP_PATH.'pr_tokens.json',
            'reset_url_base' => 'https://reset.example.com/app',
        ], $init));

        return $service;
    }

    public function testIsSupportedAndConfiguredWhenAuthResettableAndUrlSet()
    {
        $service = $this->makeService();
        $this->assertTrue($service->isSupported());
        $this->assertTrue($service->isConfigured());
    }

    public function testIsConfiguredFalseWithoutResetUrl()
    {
        // No reset_url_base => not configured/supported.
        $service = $this->makeService([], ['reset_url_base' => '']);
        $this->assertFalse($service->isConfigured());
        $this->assertFalse($service->isSupported());
    }

    public function testBuildResetUrlEmbedsTokenAndNormalisesTrailingSlash()
    {
        $service = $this->makeService([], ['reset_url_base' => 'https://reset.example.com/app/']);

        $url = $this->invokeMethod($service, 'buildResetUrl', ['TOKEN123']);

        // Exactly one slash between base and fragment, token appended verbatim.
        $this->assertSame('https://reset.example.com/app/#/reset-password?token=TOKEN123', $url);
    }

    public function testEmailRateLimitAllowsUpToMaxThenBlocks()
    {
        $service = $this->makeService(['password_reset_max_per_day_per_email' => 2]);

        $this->assertTrue($service->rateLimitEmail('user@example.com'));
        $this->assertTrue($service->rateLimitEmail('user@example.com'));
        // 3rd within the window exceeds the cap.
        $this->assertFalse($service->rateLimitEmail('user@example.com'));
    }

    public function testEmailRateLimitIsCaseAndWhitespaceInsensitive()
    {
        $service = $this->makeService(['password_reset_max_per_day_per_email' => 1]);

        $this->assertTrue($service->rateLimitEmail('User@Example.com'));
        // Same address, different casing/space => same counter, now over cap.
        $this->assertFalse($service->rateLimitEmail('  user@example.com '));
    }

    public function testIpRateLimitAllowsUpToMaxThenBlocks()
    {
        $service = $this->makeService(['password_reset_max_per_hour_per_ip' => 2]);

        $this->assertTrue($service->rateLimitIp('9.9.9.9'));
        $this->assertTrue($service->rateLimitIp('9.9.9.9'));
        $this->assertFalse($service->rateLimitIp('9.9.9.9'));
    }

    public function testIpRateLimitIsPerIp()
    {
        $service = $this->makeService(['password_reset_max_per_hour_per_ip' => 1]);

        $this->assertTrue($service->rateLimitIp('1.1.1.1'));
        $this->assertFalse($service->rateLimitIp('1.1.1.1'));
        // A different IP has its own budget.
        $this->assertTrue($service->rateLimitIp('2.2.2.2'));
    }

    public function testRequestResetForUnknownEmailSendsNothing()
    {
        $service = $this->makeService();
        $service->requestReset('nobody@example.com', '1.2.3.4');

        $this->assertNull(InMemoryMailer::last());
    }

    public function testConfirmResetRotatesPasswordAndIsStrictlySingleUse()
    {
        $this->fakeAuth->registerUser('alice@example.com', 'alice');
        $service = $this->makeService();

        $service->requestReset('alice@example.com', '1.2.3.4');

        // Recover the emitted token from the reset link in the sent email.
        $sent = InMemoryMailer::last();
        $this->assertNotNull($sent);
        $this->assertSame(1, preg_match('/token=([0-9a-f]+)/', $sent['text'], $m));
        $token = $m[1];

        // First confirm succeeds and rotates the password.
        $this->assertTrue($service->confirmReset($token, 'BrandNewPass1'));
        $this->assertSame('BrandNewPass1', $this->fakeAuth->passwordChanges['alice'] ?? null);

        // Second confirm with the same token must fail (single use).
        $this->assertFalse($service->confirmReset($token, 'AnotherPass2'));
    }

    public function testConfirmResetFailsForUnknownToken()
    {
        $service = $this->makeService();
        $this->assertFalse($service->confirmReset('deadbeef', 'whatever'));
    }

    public function testValidateTokenReturnsRowForLiveTokenAndNullAfterUse()
    {
        $this->fakeAuth->registerUser('bob@example.com', 'bob');
        $service = $this->makeService();

        $service->requestReset('bob@example.com', '1.2.3.4');
        $token = $this->tokenFromLastEmail();

        $row = $service->validateToken($token);
        $this->assertIsArray($row);
        $this->assertSame('bob', $row['username']);

        $service->confirmReset($token, 'NewSecret123');
        $this->assertNull($service->validateToken($token));
    }

    private function tokenFromLastEmail(): string
    {
        $sent = InMemoryMailer::last();
        preg_match('/token=([0-9a-f]+)/', $sent['text'], $m);

        return $m[1];
    }
}
