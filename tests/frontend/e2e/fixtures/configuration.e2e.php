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
// the step-up gate is genuinely live (see plan F4 / S2). The
// `mfa_required_for_admins` enforcement itself is covered by the backend
// Feature suite, not E2E.
$config['mfa_required_for_admins'] = false;

return $config;
