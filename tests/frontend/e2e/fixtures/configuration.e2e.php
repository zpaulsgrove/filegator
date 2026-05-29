<?php

/*
 * =====================================================================
 *  TEST-ONLY CONFIGURATION — DO NOT USE IN PRODUCTION.
 * =====================================================================
 *
 * This file is copied to ./configuration.php by the E2E orchestration
 * wrapper (tests/frontend/e2e/support/with-e2e-config.js) for the
 * duration of a Cypress run, then restored. It relaxes admin-MFA
 * enforcement so the harness can drive password-only logins.
 *
 * It is NOT a starting point for a real deployment. The runtime guard
 * below refuses to boot unless the E2E harness env flag is set, so a
 * crashed run that leaves this file in place as configuration.php will
 * fail loudly rather than silently serve a relaxed-security app.
 *
 * CSRF protection is deliberately LEFT ON — the harness performs the
 * real GET -> X-CSRF-Token -> POST round-trip (see support/commands.js),
 * so the suite exercises CSRF rather than disabling it.
 */

if (getenv('FILEGATOR_E2E') !== '1') {
    http_response_code(503);
    exit(
        "configuration.e2e.php is a test-only config and must not be loaded "
        ."outside the FileGator E2E harness. Set FILEGATOR_E2E=1 (the harness "
        ."does this automatically) or restore your real configuration.php "
        ."from configuration.php.bak.\n"
    );
}

// After the wrapper copies this file to the project root as
// configuration.php, __DIR__ resolves to the project root — the same
// directory configuration_sample.php lives in. Deriving from the sample
// keeps this seam config from drifting as the sample evolves.
$config = require __DIR__.'/configuration_sample.php';

// Relaxed admin MFA so admin/admin123 logs in directly for the bulk of
// specs. Step-up / MFA specs use a separately seeded enrolled admin so
// the step-up gate is genuinely live. Enabled only for the isolated
// forced-setup run, which sets FILEGATOR_E2E_MFA_REQUIRED=1 (the default
// run leaves it unset → false → single-step admin login).
$config['mfa_required_for_admins'] = getenv('FILEGATOR_E2E_MFA_REQUIRED') === '1';

// Capture outgoing email to a file the Cypress runner can read (password
// reset emails the plaintext token in the URL; only its hash is persisted).
$config['services']['Filegator\Services\Mailer\MailerInterface'] = [
    'handler' => '\Tests\Fakes\FileMailer',
    'config' => ['file' => __DIR__.'/private/tmp/e2e_last_email.json'],
];

// Enable the password-reset feature (disabled when reset_url_base is null).
$config['services']['Filegator\Services\PasswordReset\PasswordResetService']['config']['reset_url_base'] = 'http://localhost:8081/';
$config['frontend_config']['password_reset_enabled'] = true;

return $config;
