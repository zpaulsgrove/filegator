// MFA step-up E2E spec — change-password + change-email second factor.
//
// PR #15 gated POST /changepassword and POST /me/email behind an MFA
// step-up (re-prove a TOTP / backup code) for enrolled users. The backend
// feature tests and the Vue unit tests cover the pieces; this spec proves
// the whole thing wires together in a real browser against a live server:
// the inline step-up code field renders in the change-password box, the
// email step-up modal opens, and a freshly-generated TOTP round-trips.
//
// Setup avoids any users.json hand-editing or encryption-key handling by
// enrolling through the REAL HTTP endpoints (cy.enrollMfa): the app stores
// and encrypts the secret and refreshes the session for us. Enrollment
// confirm does NOT write a TOTP replay marker, so the same-window code can
// be reused immediately for the step-up action under test.
//
// Runs against the live PHP backend on :8081 (E2E seam config,
// mfa_required_for_admins=false). State resets before each test.

describe('MFA step-up', () => {
  let secret
  let backupCodes

  beforeEach(() => {
    cy.resetBackend()
    cy.login('admin', 'admin123') // single-step: admin has no MFA yet
    cy.enrollMfa().then((mfa) => {
      secret = mfa.secret
      backupCodes = mfa.backupCodes
    })
    cy.visit('/')
    cy.get('[data-test="user-menu"]').click() // navigates to /security
  })

  it('changes the password with a valid TOTP step-up', () => {
    cy.get('[data-test="security-oldpassword-input"]').type('admin123')
    cy.get('[data-test="security-newpassword-input"]').type('NewPass123!')
    // Inline step-up field only renders once MFA state has loaded.
    cy.get('[data-test="security-stepup-code"]').should('be.visible')
    cy.totp(secret).then((code) => {
      cy.get('[data-test="security-stepup-code"]').type(code)
      cy.get('[data-test="security-password-update"]').click()
    })

    cy.get('.toast').should('contain.text', 'Password updated')
    // On success the form clears — a durable, non-transient success signal.
    cy.get('[data-test="security-oldpassword-input"]').should('have.value', '')
  })

  it('rejects a password change with a wrong TOTP code', () => {
    cy.get('[data-test="security-oldpassword-input"]').type('admin123')
    cy.get('[data-test="security-newpassword-input"]').type('NewPass123!')
    cy.get('[data-test="security-stepup-code"]').type('000000')
    cy.get('[data-test="security-password-update"]').click()

    // Inline field error from the 422 {code:'Invalid code'} body.
    cy.contains('Invalid code').should('be.visible')
    // Fields are NOT cleared on failure (proves the change did not go through).
    cy.get('[data-test="security-oldpassword-input"]').should('have.value', 'admin123')
  })

  it('changes the email with a valid TOTP step-up', () => {
    cy.get('[data-test="security-email-input"]').clear().type('newmail@example.com')
    cy.get('[data-test="security-email-save"]').click()

    // Step-up modal opens (password + code).
    cy.get('[data-test="security-email-stepup-password"]').type('admin123')
    cy.totp(secret).then((code) => {
      cy.get('[data-test="security-email-stepup-code"]').type(code)
      cy.get('[data-test="security-email-stepup-continue"]').click()
    })

    cy.get('.toast').should('contain.text', 'Saved')
    // Durable check: reload and confirm the new (lowercased) email persisted.
    cy.reload()
    cy.get('[data-test="security-email-input"]').should('have.value', 'newmail@example.com')
  })

  it('rejects an email change with a wrong step-up password', () => {
    cy.get('[data-test="security-email-input"]').clear().type('other@example.com')
    cy.get('[data-test="security-email-save"]').click()

    cy.get('[data-test="security-email-stepup-password"]').type('wrong-password')
    cy.totp(secret).then((code) => {
      cy.get('[data-test="security-email-stepup-code"]').type(code)
      cy.get('[data-test="security-email-stepup-continue"]').click()
    })

    // 422 {password:'Wrong password'} maps inline; the modal stays open.
    cy.contains('Wrong password').should('be.visible')
    cy.get('[data-test="security-email-stepup-continue"]').should('be.visible')
  })

  it('accepts a backup code via the step-up toggle', () => {
    cy.get('[data-test="security-oldpassword-input"]').type('admin123')
    cy.get('[data-test="security-newpassword-input"]').type('NewPass123!')
    cy.get('[data-test="security-stepup-backup-toggle"]').click()
    // Backend normalises case/format, so the plaintext code is typed verbatim.
    cy.then(() => cy.get('[data-test="security-stepup-code"]').type(backupCodes[0]))
    cy.get('[data-test="security-password-update"]').click()

    cy.get('.toast').should('contain.text', 'Password updated')
    cy.get('[data-test="security-oldpassword-input"]').should('have.value', '')
  })
})
