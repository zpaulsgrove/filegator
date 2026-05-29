// Forced admin MFA-setup E2E spec.
//
// Runs ONLY in the isolated second CI run, which sets
// FILEGATOR_E2E_MFA_REQUIRED=1 so the seam config flips
// mfa_required_for_admins=true. Under that policy an admin who hasn't enrolled
// is pushed into the in-login setup step (Login.vue step === 'mfa_setup'):
// scan/enter the secret -> confirm a TOTP -> see backup codes -> finish ->
// land in the app. This spec lives outside specs/ so the default cypress run
// (mfa_required_for_admins=false) never picks it up.

describe('Forced admin MFA setup', () => {
  beforeEach(() => {
    cy.resetBackend() // admin has no MFA yet
    cy.visit('/login')
  })

  it('forces MFA setup on first admin login and completes it', () => {
    cy.get('[data-test="login-username"]').type('admin')
    cy.get('[data-test="login-password"]').type('admin123')
    cy.get('[data-test="login-submit"]').click()

    // Setup step appears with the provisioning secret.
    cy.get('[data-test="login-mfa-setup-secret"]').invoke('text').then((secret) => {
      cy.totp(secret.trim()).then((code) => {
        cy.get('[data-test="login-mfa-setup-code"]').type(code)
        cy.get('[data-test="login-mfa-setup-submit"]').click()
      })
    })

    // Backup codes are shown; finishing lands in the authenticated app.
    cy.get('[data-test="login-mfa-setup-backup-codes"]').should('be.visible')
    cy.get('[data-test="login-mfa-setup-finish"]').click()

    cy.get('[data-test="user-menu"]').should('contain.text', 'Admin')
    cy.get('[data-test="logout"]').should('be.visible')
  })

  it('rejects a wrong setup code', () => {
    cy.get('[data-test="login-username"]').type('admin')
    cy.get('[data-test="login-password"]').type('admin123')
    cy.get('[data-test="login-submit"]').click()

    cy.get('[data-test="login-mfa-setup-code"]').should('be.visible').type('000000')
    cy.get('[data-test="login-mfa-setup-submit"]').click()

    cy.get('[data-test="login-error"]').should('be.visible')
    // Still on the setup step, not authenticated.
    cy.get('[data-test="login-mfa-setup-code"]').should('be.visible')
    cy.get('[data-test="logout"]').should('not.exist')
  })
})
