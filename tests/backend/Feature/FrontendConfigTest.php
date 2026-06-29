<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Tests\Feature;

use Tests\Fakes\InMemoryMailer;
use Tests\TestCase;

/**
 * ViewController::getFrontendConfig computes several flags the SPA gates on.
 * Existing coverage asserts only the static frontend_config keys; these pin the
 * DYNAMIC ones — notably password_reset_enabled, which controls whether the
 * whole reset feature is exposed in the UI.
 *
 * @internal
 */
class FrontendConfigTest extends TestCase
{
    private function config(): array
    {
        $this->sendRequest('GET', '/getconfig');
        $this->assertOk();

        return $this->decodeResponseJson()['data'];
    }

    public function testComputedFlagsReflectAConfiguredDeployment()
    {
        // Test config: InMemoryMailer is configured + reset_url_base is set.
        $data = $this->config();

        $this->assertTrue($data['password_reset_enabled']);
        $this->assertSame(3600, $data['password_reset_token_ttl']);
        $this->assertFalse($data['mfa_required_for_admins']); // test-config default
        $this->assertTrue($data['step_up_auth']);             // defaults to true when unset
    }

    public function testPasswordResetDisabledWhenMailerIsNotConfigured()
    {
        // password_reset_enabled = mailer->isConfigured() && reset->isConfigured().
        // Flip the mailer to unconfigured and the SPA must not advertise reset.
        $this->overrideConfig(['services' => [
            'Filegator\Services\Mailer\MailerInterface' => ['config' => ['configured' => false]],
        ]]);
        InMemoryMailer::$configured = false;

        $this->assertFalse($this->config()['password_reset_enabled']);
    }

    public function testPasswordResetDisabledWhenResetUrlBaseMissing()
    {
        // reset->isConfigured() is false without a reset_url_base (the
        // host-header-injection guard), so the feature stays hidden.
        $this->overrideConfig(['services' => [
            'Filegator\Services\PasswordReset\PasswordResetService' => ['config' => ['reset_url_base' => null]],
        ]]);

        $this->assertFalse($this->config()['password_reset_enabled']);
    }

    public function testFlagsTrackConfigOverrides()
    {
        $this->overrideConfig([
            'mfa_required_for_admins' => true,
            'step_up_auth' => false,
            'password_reset_token_ttl' => 1800,
        ]);

        $data = $this->config();
        $this->assertTrue($data['mfa_required_for_admins']);
        $this->assertFalse($data['step_up_auth']);
        $this->assertSame(1800, $data['password_reset_token_ttl']);
    }
}
