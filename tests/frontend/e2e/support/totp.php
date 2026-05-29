<?php

/*
 * Standalone TOTP generator for the E2E specs.
 *
 * Computes the current 6-digit code for a base32 secret (as returned by
 * POST /mfa/enroll/begin) using the exact library the backend verifies
 * with (OTPHP), so there is zero algorithm-parity risk and no JS crypto
 * dependency. Deliberately does NOT bootstrap the app: no configuration.php
 * (and therefore no FILEGATOR_E2E seam guard), no container, no encryption
 * key — the secret is passed in as plaintext base32.
 *
 * Usage: php tests/frontend/e2e/support/totp.php <base32-secret>
 */

require __DIR__.'/../../../../vendor/autoload.php';

$secret = $argv[1] ?? '';
if ($secret === '') {
    fwrite(STDERR, "usage: totp.php <base32-secret>\n");
    exit(1);
}

echo \OTPHP\TOTP::createFromSecret($secret)->now();
