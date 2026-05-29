// MFA management UI E2E spec — disable MFA / regenerate backup codes.
//
// Both flows open the re-auth modal (MfaStepUpForm: password + current code)
// and call a step-up-gated endpoint. We seed an enrolled admin via the API
// (cy.enrollMfa) and then drive the management UI in the browser.
//
// confirmEnrollment writes no replay marker, so the same-window TOTP captured
// at enrollment is reusable for the step-up action under test. One step-up
// TOTP per test; resetBackend clears replay markers between tests.

describe('MFA management', () => {
  let secret

  beforeEach(() => {
    cy.resetBackend()
    cy.login('admin', 'admin123')
    cy.enrollMfa().then((mfa) => { secret = mfa.secret })
    cy.visit('/')
    cy.get('[data-test="user-menu"]').click() // /security
  })

  it('regenerates backup codes with a valid step-up', () => {
    cy.get('[data-test="security-mfa-regenerate"]').click()
    cy.get('[data-test="security-manage-stepup-password"]').type('admin123')
    cy.totp(secret).then((code) => {
      cy.get('[data-test="security-manage-stepup-code"]').type(code)
      cy.get('[data-test="security-manage-continue"]').click()
    })

    // A fresh set of backup codes is shown.
    cy.get('[data-test="security-backup-codes"]').should('be.visible')
    cy.get('[data-test="security-backup-codes"] li').should('have.length', 10)
  })

  it('disables MFA with a valid step-up', () => {
    cy.get('[data-test="security-mfa-disable"]').click()
    cy.get('[data-test="security-manage-stepup-password"]').type('admin123')
    cy.totp(secret).then((code) => {
      cy.get('[data-test="security-manage-stepup-code"]').type(code)
      cy.get('[data-test="security-manage-continue"]').click()
    })

    cy.get('.toast').should('contain.text', 'MFA disabled')
    // Section flips back to the enable state.
    cy.get('[data-test="security-enable-mfa"]').should('be.visible')
  })

  it('rejects management with a wrong step-up password', () => {
    cy.get('[data-test="security-mfa-disable"]').click()
    cy.get('[data-test="security-manage-stepup-password"]').type('wrong-password')
    cy.totp(secret).then((code) => {
      cy.get('[data-test="security-manage-stepup-code"]').type(code)
      cy.get('[data-test="security-manage-continue"]').click()
    })

    // 422 {password:'Wrong password'} maps inline; the modal stays open.
    cy.contains('Wrong password').should('be.visible')
    cy.get('[data-test="security-manage-continue"]').should('be.visible')
  })
})
