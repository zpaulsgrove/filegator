// E2E helpers for FileGator. Cypress 3.8.3 syntax.
//
// These commands drive the REAL backend (no cy.route mocks). Tests using
// them require the PHP server (port 8081) and Vue dev server (port 8080)
// to be running — see tests/frontend/e2e/README.md.
//
// State-reset commands shell out via cy.exec; that bypasses Cypress's
// browser sandbox and runs on the host. The PHP server picks up the
// re-written files on its next request.

/**
 * Reset backend state to a known-clean baseline: admin + guest only,
 * no MFA, no lockfiles, no replay markers. Should be called in
 * beforeEach() of any spec that mutates users or auth state.
 */
Cypress.Commands.add('resetBackend', () => {
  // users.json.blank ships with admin (admin123) + guest, no MFA.
  cy.exec('cp private/users.json.blank private/users.json')
  // Clear tmpfs lockfiles + replay markers from prior tests.
  cy.exec('rm -f private/tmp/*.lock')
  // Clear any half-written MFA keyfile from prior runs so MfaSecretCrypto
  // generates a fresh key on next enrollment. Optional; keyfile is per-deploy.
  cy.exec('rm -f private/mfa_encryption.key', { failOnNonZeroExit: false })
  // Ensure the multi-folder fixture directories exist so file ops don't
  // fail on missing destinations.
  cy.exec('mkdir -p repository/projects repository/personal')
})

/**
 * Log in via the UI. Fills the password form and waits until the post-
 * login redirect lands (file browser, picker, or MFA setup screen).
 * Does NOT handle MFA — for MFA flows use `cy.loginMfa()`.
 */
Cypress.Commands.add('login', (username, password) => {
  cy.visit('/')
  cy.contains('Log in').click()
  cy.get('input[name="username"]').clear().type(username)
  cy.get('input[name="password"]').clear().type(password)
  cy.get('form').contains('button', 'Login').click()
})

/**
 * Log out via the navbar.
 */
Cypress.Commands.add('logoutUi', () => {
  cy.contains('a.navbar-item', 'Log out').click()
})

/**
 * Open the admin "Add user" dialog and create a user. Assumes the
 * caller is already logged in as a non-MFA admin (no step-up needed).
 * For MFA admins, use `cy.adminCreateUserWithStepUp` (not yet written).
 *
 * @param {object} user
 * @param {string} user.username
 * @param {string} user.password
 * @param {string} user.name
 * @param {string} user.role  - 'user' | 'admin'
 * @param {string[]} user.homedirs - array of folder paths; first is the
 *                                   default homedir for back-compat
 * @param {string[]} user.permissions - e.g. ['read', 'write', 'upload', 'download']
 */
Cypress.Commands.add('adminCreateUser', (user) => {
  cy.contains('a.navbar-item', 'Users').click()
  cy.contains('button', 'Add User').click()
  cy.get('.modal input[name="username"]').type(user.username)
  cy.get('.modal input[name="name"]').type(user.name)
  cy.get('.modal input[type="password"]').first().type(user.password)
  // Default role is `user` in the dialog. Override only if needed.
  if (user.role && user.role !== 'user') {
    cy.get('.modal select').select(user.role)
  }
  // Fill the first folder row.
  cy.get('.modal .folder-row input').first().clear().type(user.homedirs[0])
  // Add additional folder rows.
  for (let i = 1; i < user.homedirs.length; i++) {
    cy.get('.modal').contains('button', '+').click()
    cy.get('.modal .folder-row').eq(i).find('input').type(user.homedirs[i])
  }
  // Toggle permissions (assumes checkboxes; adapt to actual UI shape).
  user.permissions.forEach((perm) => {
    const label = perm.charAt(0).toUpperCase() + perm.slice(1)
    cy.get('.modal').contains('.field', label).find('input[type="checkbox"]').check({ force: true })
  })
  cy.get('.modal').contains('button', 'Save').click()
  // Wait for the modal to close + the new row to appear.
  cy.get('.modal').should('not.exist')
  cy.contains('td', user.username)
})
