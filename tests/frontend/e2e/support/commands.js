// FileGator Cypress harness commands.
//
// Used by every E2E spec. Targets a live FileGator backend running on
// http://localhost:8081 (started by start-server-and-test in the npm
// script). State is reset between specs via cy.resetBackend.

/**
 * Reset the backend filesystem and auth state to a known baseline.
 *
 * - Copies users.json.blank → users.json so each spec starts with the
 *   canonical fixture users (admin/admin123, passwordless guest).
 * - Clears per-user MFA lockout files.
 * - Removes stale password-reset tokens (so the reset spec starts clean).
 * - Wipes uploaded content and recreates the two storage roots used by
 *   the multi-folder fixtures.
 *
 * Note: we deliberately do NOT delete private/mfa_encryption.key — the
 * backup-code path needs no key, and removing it would orphan any
 * encrypted TOTP secret carried by a seeded enrolled-admin fixture.
 *
 * Safe to call from beforeEach. Idempotent.
 */
Cypress.Commands.add('resetBackend', () => {
  cy.exec('cp private/users.json.blank private/users.json')
  cy.exec('rm -f private/tmp/*.lock', { failOnNonZeroExit: false })
  cy.exec('rm -f private/password_resets.json', { failOnNonZeroExit: false })
  cy.exec('rm -rf repository/*', { failOnNonZeroExit: false })
  cy.exec('mkdir -p repository/projects repository/personal')
})

/**
 * CSRF-aware POST against the FileGator API.
 *
 * CSRF is ON in the live config: every GET response carries a fresh
 * X-CSRF-Token header (Security.php), and the matching POST must echo it
 * back or the request is rejected with 403. We GET /getuser to harvest a
 * token, then replay it on the POST — exercising the real CSRF path
 * rather than disabling the control the suite exists to protect.
 *
 * `path` is the router path (e.g. '/login'); `body` is the JSON payload.
 */
Cypress.Commands.add('apiPost', (path, body = {}) => {
  return cy.request({ method: 'GET', url: '/?r=/getuser' }).then((res) => {
    const token = res.headers['x-csrf-token']
    return cy.request({
      method: 'POST',
      url: `/?r=${path}`,
      headers: { 'X-CSRF-Token': token },
      body,
      failOnStatusCode: true,
    })
  })
})

/**
 * Programmatic login via the real /login endpoint (CSRF round-trip).
 *
 * Faster and more deterministic than driving the UI form for every spec
 * — we still cover the UI login path in its own dedicated spec.
 * Persists the session cookie for the remainder of the test.
 */
Cypress.Commands.add('login', (username, password) => {
  cy.apiPost('/login', { username, password })
})

/**
 * UI-driven logout. Clicks the Logout control in the navbar.
 *
 * Used when a spec needs to verify the post-logout app state, not just
 * tear down session for the next test (resetBackend handles that).
 */
Cypress.Commands.add('logoutUi', () => {
  cy.get('[data-test="logout"]').click()
})

/**
 * Programmatic admin-side user creation via /storeuser (CSRF round-trip).
 *
 * Caller must be logged in as an admin first. `params` matches the
 * /storeuser request body shape — see backend/Controllers/AdminController.php
 * storeUser() for the field list.
 */
Cypress.Commands.add('adminCreateUser', (params) => {
  cy.apiPost('/storeuser', params)
})

/**
 * Create an empty file in the caller's current folder via the real /createnew
 * endpoint, then reload so the listing shows it.
 *
 * The UI used to expose a "New File" entry in the New menu, but that was
 * removed from the product (folders only). Specs that need a file fixture
 * create it through the API instead — createNew writes to the session's
 * current working directory (it ignores any destination param), so this lands
 * wherever the app last navigated. The reload re-lists that folder; the app
 * restores the location from the URL (`cd=`/`folder=`), so a file made in a
 * subfolder reappears there, not at the root.
 *
 * Caller must be logged in (cookie session). Yields nothing.
 */
Cypress.Commands.add('createFile', (name) => {
  cy.apiPost('/createnew', { type: 'file', name })
  cy.reload()
})

/**
 * Check a file row's selection checkbox by entry name.
 *
 * The Browser toolbar actions (Copy/Move/Zip/Download) only render once rows
 * are checked. Buefy renders an identical checkbox per row, so scope to the
 * row and force past the cell overlay.
 */
Cypress.Commands.add('selectRow', (name) => {
  cy.contains('.file-row', name).find('input[type="checkbox"]').check({ force: true })
})

/**
 * Generate the current TOTP for a base32 secret using the backend's own
 * OTPHP library via a tiny standalone PHP helper (tests/.../support/totp.php).
 * Exact algorithm parity with the server, no JS crypto dependency. Yields
 * the trimmed 6-digit code string.
 */
Cypress.Commands.add('totp', (secret) => {
  return cy.exec(`php tests/frontend/e2e/support/totp.php ${secret}`)
    .then((res) => res.stdout.trim())
})

/**
 * Enroll the currently-authenticated user in MFA through the REAL HTTP
 * endpoints (begin → confirm), letting the app own secret storage,
 * at-rest encryption and the post-confirm session refresh
 * (establishSessionFor) — so the session stays valid afterwards. Yields
 * { secret, backupCodes }.
 *
 * Why this is safe to pair with a TOTP step-up in the same test:
 * confirmEnrollment verifies the code via verifyTotpAgainstSecret, which
 * does NOT write a replay marker (only the login / step-up paths do). So
 * the same-window TOTP can be re-used immediately for the action under
 * test without tripping replay protection.
 */
Cypress.Commands.add('enrollMfa', () => {
  return cy.apiPost('/mfa/enroll/begin').then((begin) => {
    const secret = begin.body.data.secret
    return cy.totp(secret).then((code) => {
      return cy.apiPost('/mfa/enroll/confirm', { code }).then((confirm) => ({
        secret,
        backupCodes: confirm.body.data.backup_codes,
      }))
    })
  })
})
