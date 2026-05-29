// MFA enrollment UI E2E spec.
//
// Drives the full browser enrollment flow on the Security page: Enable MFA →
// read the displayed secret → enter a real TOTP → confirm → see backup codes →
// dismiss → MFA shows as enabled. Complements step-up.cy.js / mfa-login.cy.js,
// which enroll via the API (cy.enrollMfa); here enrollment itself is the
// system under test.
//
// Runs against the live PHP backend on :8081 (E2E seam config). State resets
// before each test; the admin starts with no MFA.

describe('MFA enrollment', () => {
  beforeEach(() => {
    cy.resetBackend()
    cy.login('admin', 'admin123') // single-step: admin has no MFA yet
    cy.visit('/')
    cy.get('[data-test="user-menu"]').click() // navigates to /security
  })

  it('enrolls through the UI and shows backup codes', () => {
    cy.get('[data-test="security-enable-mfa"]').click()

    // Read the displayed base32 secret, compute the current TOTP, confirm.
    cy.get('[data-test="security-enroll-secret"]').invoke('text').then((secret) => {
      cy.totp(secret.trim()).then((code) => {
        cy.get('[data-test="security-enroll-code"]').type(code)
        cy.get('[data-test="security-enroll-verify"]').click()
      })
    })

    // Backup codes are shown once.
    cy.get('[data-test="security-backup-codes"]').should('be.visible')
    cy.get('[data-test="security-backup-codes"] li').should('have.length', 10)
    cy.get('[data-test="security-backup-codes-dismiss"]').click()

    // MFA is now enabled on the account.
    cy.contains('MFA is enabled').should('be.visible')
  })

  it('rejects enrollment with a wrong code', () => {
    cy.get('[data-test="security-enable-mfa"]').click()
    cy.get('[data-test="security-enroll-secret"]').should('be.visible')
    cy.get('[data-test="security-enroll-code"]').type('000000')
    cy.get('[data-test="security-enroll-verify"]').click()

    cy.get('.toast').should('contain.text', 'Invalid code')
    // Still on the enrollment step (code field present), not enabled.
    cy.get('[data-test="security-enroll-code"]').should('be.visible')
  })

  it('cancels enrollment and returns to the enable state', () => {
    cy.get('[data-test="security-enable-mfa"]').click()
    cy.get('[data-test="security-enroll-secret"]').should('be.visible')
    cy.get('[data-test="security-enroll-cancel"]').click()

    cy.get('[data-test="security-enable-mfa"]').should('be.visible')
  })
})
