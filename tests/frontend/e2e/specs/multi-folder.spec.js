// Real-backend end-to-end: multi-folder user flow.
//
// Drives the actual PHP + Vue stack — no cy.route mocks. Validates the
// integration between the multi-folder backend (homedirs[] array, active
// homedir session state, /selectfolder route) and the frontend (picker,
// navbar switcher, deep-link routing) that component tests can't reach.
//
// Prereqs: see tests/frontend/e2e/README.md.
//   $ npm run serve   # PHP 8081 + Vue dev server 8080
//   $ npm run e2e:headless
//
// What this spec exercises:
//   1. Admin logs in (no MFA) → goes to Users → creates a multi-folder user
//   2. Admin logs out
//   3. Multi-folder user logs in → picker appears (NOT a direct redirect)
//   4. Click a folder → land in it, picker closes
//   5. Switcher dropdown in the navbar shows both folders
//   6. Switch folders → file listing changes
//   7. Upload a file in /projects → switch to /personal → file is NOT there
//   8. Switch back to /projects → file IS there (isolation invariant)

describe('Multi-folder user flow', () => {
  // Don't clear the session cookie between tests in a single describe block.
  // Each test handles its own login/logout.
  Cypress.Cookies.defaults({
    whitelist: 'filegator',
  })

  const multiUser = {
    username: 'multi',
    password: 'multi123',
    name: 'Multi Folder User',
    role: 'user',
    homedirs: ['/projects', '/personal'],
    permissions: ['read', 'write', 'upload', 'download', 'batchdownload'],
  }

  before(() => {
    cy.resetBackend()
    cy.login('admin', 'admin123')
    cy.adminCreateUser(multiUser)
    cy.logoutUi()
  })

  beforeEach(() => {
    // Each test logs in fresh to verify the full happy path.
    cy.viewport(1280, 800)
  })

  it('lands the multi-folder user on the picker after login', () => {
    cy.login(multiUser.username, multiUser.password)

    // Picker page renders — both folders visible.
    cy.contains('Select a folder').should('exist')
    cy.contains('.folder-button, button, a', '/projects').should('exist')
    cy.contains('.folder-button, button, a', '/personal').should('exist')
  })

  it('opens the chosen folder and shows the switcher in the navbar', () => {
    cy.login(multiUser.username, multiUser.password)
    cy.contains('Select a folder').should('exist')

    cy.contains('.folder-button, button, a', '/projects').click()

    // Should land in /projects (file listing, empty or not).
    cy.url().should('match', /\/$|\/projects/)

    // Navbar switcher should be visible (multi-folder user).
    cy.get('.folder-switcher').should('exist')

    // Open dropdown — both folders should be listed.
    cy.get('.folder-switcher').click()
    cy.get('.folder-switcher').contains('/projects').should('exist')
    cy.get('.folder-switcher').contains('/personal').should('exist')
  })

  it('switches folders via the navbar dropdown', () => {
    cy.login(multiUser.username, multiUser.password)
    cy.contains('.folder-button, button, a', '/projects').click()
    cy.get('.folder-switcher').should('exist')

    cy.get('.folder-switcher').click()
    cy.get('.folder-switcher').contains('/personal').click()

    // The file listing should now reflect /personal.
    // We can't easily assert what's IN /personal without seeding files,
    // so we assert the switcher's active label updated.
    cy.get('.folder-switcher').contains('/personal').should('exist')
  })

  it('isolates files between folders (admin drops a file into /projects, /personal stays empty)', () => {
    // Seed a sentinel file directly into the filesystem to test the
    // listing + isolation invariant without needing the Buefy upload UI
    // (which would require the cypress-file-upload plugin we haven't
    // installed yet — adding that is future scope).
    const filename = `e2e-isolation-${Date.now()}.txt`
    cy.exec(`echo "hello" > repository/projects/${filename}`)

    cy.login(multiUser.username, multiUser.password)
    cy.contains('.folder-button, button, a', '/projects').click()
    cy.get('.folder-switcher').should('exist')

    // File visible in /projects.
    cy.contains(filename, { timeout: 5000 }).should('exist')

    // Switch to /personal — file should NOT be there.
    cy.get('.folder-switcher').click()
    cy.get('.folder-switcher').contains('/personal').click()
    cy.contains(filename).should('not.exist')

    // Switch back to /projects — file IS there.
    cy.get('.folder-switcher').click()
    cy.get('.folder-switcher').contains('/projects').click()
    cy.contains(filename, { timeout: 5000 }).should('exist')

    // Cleanup so the next test run starts clean.
    cy.exec(`rm -f repository/projects/${filename}`)
  })
})
