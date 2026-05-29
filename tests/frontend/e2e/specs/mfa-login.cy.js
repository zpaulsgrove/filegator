// MFA login E2E spec — the two-step /login/mfa browser flow.
//
// Deferred since PR #12 (no MFA E2E scaffolding existed). Proves the real
// browser login of an MFA-enrolled user: password step → MFA step →
// TOTP (or backup code) → landed in the app. The frontend manages the
// mfa_nonce internally, so a working login implicitly exercises the
// nonce round-trip.
//
// Selectors already exist on Login.vue from PR #14 (login-username,
// login-password, login-submit, login-error, login-mfa-code,
// login-mfa-backup-toggle, login-mfa-submit) — no production changes here.
//
// Setup enrolls admin via the real HTTP endpoints, then logs out, so the
// next browser login is a clean two-step. Enrollment confirm does not
// write a TOTP replay marker, so the same-window code verifies on /login/mfa.

describe('MFA login', () => {
  let secret
  let backupCodes

  beforeEach(() => {
    cy.resetBackend()
    cy.login('admin', 'admin123')
    cy.enrollMfa().then((mfa) => {
      secret = mfa.secret
      backupCodes = mfa.backupCodes
    })
    cy.apiPost('/logout')
    cy.visit('/login')
  })

  it('logs in through the two-step TOTP flow', () => {
    cy.get('[data-test="login-username"]').type('admin')
    cy.get('[data-test="login-password"]').type('admin123')
    cy.get('[data-test="login-submit"]').click()

    // Step 2 appears.
    cy.get('[data-test="login-mfa-code"]').should('be.visible')
    cy.totp(secret).then((code) => {
      cy.get('[data-test="login-mfa-code"]').type(code)
      cy.get('[data-test="login-mfa-submit"]').click()
    })

    // Landed in the authenticated app.
    cy.get('[data-test="user-menu"]').should('contain.text', 'Admin')
    cy.get('[data-test="logout"]').should('be.visible')
  })

  it('rejects a wrong code at the MFA step and grants no session', () => {
    cy.get('[data-test="login-username"]').type('admin')
    cy.get('[data-test="login-password"]').type('admin123')
    cy.get('[data-test="login-submit"]').click()

    cy.get('[data-test="login-mfa-code"]').should('be.visible').type('000000')
    cy.get('[data-test="login-mfa-submit"]').click()

    cy.get('[data-test="login-error"]').should('be.visible')
    // Still on the MFA step, no authenticated chrome.
    cy.get('[data-test="login-mfa-code"]').should('be.visible')
    cy.get('[data-test="logout"]').should('not.exist')
  })

  it('logs in with a backup code', () => {
    cy.get('[data-test="login-username"]').type('admin')
    cy.get('[data-test="login-password"]').type('admin123')
    cy.get('[data-test="login-submit"]').click()

    cy.get('[data-test="login-mfa-backup-toggle"]').click()
    cy.then(() => cy.get('[data-test="login-mfa-code"]').type(backupCodes[0]))
    cy.get('[data-test="login-mfa-submit"]').click()

    cy.get('[data-test="user-menu"]').should('contain.text', 'Admin')
    cy.get('[data-test="logout"]').should('be.visible')
  })
})
