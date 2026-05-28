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
