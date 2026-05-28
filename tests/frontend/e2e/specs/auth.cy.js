// Authentication critical-path E2E spec.
//
// Pins the password-only login surface that every other spec depends on:
// - UI login as the default admin lands in the browser
// - wrong password surfaces an inline error and grants no session
// - UI logout clears the session (the logout control disappears and the
//   login form returns — App.vue force-renders Login for a permission-less
//   guest, so the form, not a navbar link, is the post-logout signal)
//
// Runs against a live PHP backend on :8081 booted with the E2E seam
// config (mfa_required_for_admins=false), so admin/admin123 logs in
// without an MFA step. State resets to users.json.blank before each test.

describe('Authentication', () => {
  beforeEach(() => {
    cy.resetBackend()
  })

  it('logs in via the UI and lands in the browser', () => {
    cy.visit('/login')
    cy.get('[data-test="login-username"]').type('admin')
    cy.get('[data-test="login-password"]').type('admin123')
    cy.get('[data-test="login-submit"]').click()

    // The authenticated navbar shows the user's name and a logout control.
    cy.get('[data-test="user-menu"]').should('contain.text', 'Admin')
    cy.get('[data-test="logout"]').should('be.visible')
  })

  it('shows an inline error on wrong password and grants no session', () => {
    cy.visit('/login')
    cy.get('[data-test="login-username"]').type('admin')
    cy.get('[data-test="login-password"]').type('nope-wrong-pass')
    cy.get('[data-test="login-submit"]').click()

    cy.get('[data-test="login-error"]').should('be.visible')
    // Still on the login form, no authenticated chrome.
    cy.get('[data-test="login-username"]').should('exist')
    cy.get('[data-test="logout"]').should('not.exist')
  })

  it('logs out via the user menu and clears the session', () => {
    // Programmatic login (real /login with CSRF round-trip), then drive
    // the logout UI to verify the post-logout app state.
    cy.login('admin', 'admin123')
    cy.visit('/')
    cy.get('[data-test="logout"]').should('be.visible').click()

    // Logged-out chrome: logout gone, login form re-rendered (App.vue
    // force-renders Login for a permission-less guest).
    cy.get('[data-test="logout"]').should('not.exist')
    cy.get('[data-test="login-username"]').should('be.visible')
  })
})
