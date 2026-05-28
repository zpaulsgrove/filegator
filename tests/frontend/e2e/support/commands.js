// FileGator Cypress harness commands.
//
// Used by every E2E spec. Targets a live FileGator backend running on
// http://localhost:8081 (started by start-server-and-test in the npm
// script). State is reset between specs via cy.resetBackend.

/**
 * Reset the backend filesystem and auth state to a known baseline.
 *
 * Copies users.json.blank → users.json so each spec starts with the
 * canonical fixture users. Also clears any per-user MFA lockout files
 * and the encryption key so MFA flows can re-enroll cleanly. Finally
 * (re)creates the two storage roots used by the multi-folder fixtures.
 *
 * Safe to call from beforeEach. Idempotent.
 */
Cypress.Commands.add('resetBackend', () => {
  cy.exec('cp private/users.json.blank private/users.json')
  cy.exec('rm -f private/tmp/*.lock', { failOnNonZeroExit: false })
  cy.exec('rm -f private/mfa_encryption.key', { failOnNonZeroExit: false })
  cy.exec('mkdir -p repository/projects repository/personal')
})

/**
 * Programmatic login via the real /login endpoint.
 *
 * Faster and more deterministic than driving the UI form for every spec
 * — we still cover the UI login path in its own dedicated spec.
 * Persists the session cookie for the remainder of the test.
 */
Cypress.Commands.add('login', (username, password) => {
  cy.request({
    method: 'POST',
    url: '/?r=/login',
    body: { username, password },
    failOnStatusCode: true,
  })
})

/**
 * UI-driven logout. Hits the user-menu dropdown and clicks Logout.
 *
 * Used when a spec needs to verify the post-logout app state, not just
 * tear down session for the next test (resetBackend handles that).
 */
Cypress.Commands.add('logoutUi', () => {
  cy.get('[data-test="user-menu"]').click()
  cy.get('[data-test="logout"]').click()
})

/**
 * Programmatic admin-side user creation via /storeuser.
 *
 * Caller must be logged in as an admin first. `params` matches the
 * /storeuser request body shape — see backend/Controllers/AdminController.php
 * storeUser() for the field list.
 */
Cypress.Commands.add('adminCreateUser', (params) => {
  cy.request({
    method: 'POST',
    url: '/?r=/storeuser',
    body: params,
    failOnStatusCode: true,
  })
})
