// Password-reset E2E spec — forgot-password → emailed token → reset.
//
// The backend emails a PLAINTEXT token (only its sha256 is persisted), so the
// E2E seam config binds a file-writing mailer (Tests\Fakes\FileMailer) that
// dumps the last email to private/tmp/e2e_last_email.json; we read the token
// from it, exactly like the backend feature test extracts it from the fake
// mailer. reset_url_base is set in the seam config to enable the feature.
//
// The blank admin has no email, so beforeEach gives it one (no MFA ⇒ /me/email
// is a no-op step-up) and logs out, then the guest flow drives the UI.

const EMAIL_FILE = 'private/tmp/e2e_last_email.json'
const ADMIN_EMAIL = 'admin@example.com'
const NEW_PASSWORD = 'NewPass123!'

describe('Password reset', () => {
  beforeEach(() => {
    cy.resetBackend()
    cy.login('admin', 'admin123')
    cy.apiPost('/me/email', { email: ADMIN_EMAIL }) // no MFA ⇒ no step-up
    cy.apiPost('/logout')
    cy.exec(`rm -f ${EMAIL_FILE}`)
  })

  it('resets the password via the emailed token', () => {
    // Hash route: the router is in hash mode and bounces guests on non-hash
    // paths to the login view, so the form only renders via the #/ URL
    // (mirrors the reset-password visit below).
    cy.visit('/#/forgot-password')
    cy.get('[data-test="forgot-email"]').type(ADMIN_EMAIL)
    cy.get('[data-test="forgot-submit"]').click()
    cy.get('[data-test="forgot-sent"]').should('be.visible')

    // Pull the plaintext token out of the captured email.
    cy.readFile(EMAIL_FILE).then((mail) => {
      const m = mail.text.match(/token=([a-f0-9]{64})/)
      expect(m, 'reset token in email').to.not.be.null
      const token = m[1]

      cy.visit(`/#/reset-password?token=${token}`)
      cy.get('[data-test="reset-newpassword"]').type(NEW_PASSWORD)
      cy.get('[data-test="reset-confirm"]').type(NEW_PASSWORD)
      cy.get('[data-test="reset-submit"]').click()
      cy.get('[data-test="reset-done"]').should('be.visible')
    })

    // The new password works (and proves the old one was replaced).
    cy.login('admin', NEW_PASSWORD)
    cy.visit('/')
    cy.get('[data-test="user-menu"]').should('contain.text', 'Admin')
  })

  it('shows an invalid-link state for a bad token', () => {
    cy.visit('/#/reset-password?token=deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef')
    cy.get('[data-test="reset-invalid"]').should('be.visible')
  })
})
