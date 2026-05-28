// Multi-folder critical-path E2E spec.
//
// Pins the user-facing behavior of the multi-folder feature:
// - Admin creates a user with two homedirs (projects + personal)
// - That user logs in; the folder switcher dropdown is rendered
// - Switching folders changes the file listing root and persists across
//   page reloads
//
// Runs against a live PHP backend on :8081 booted by start-server-and-test.
// State is reset to the users.json.blank baseline before each test.

describe('Multi-folder homedirs', () => {
  beforeEach(() => {
    cy.resetBackend()
  })

  it('admin can create a multi-folder user, who then switches roots in the UI', () => {
    // 1. Log in as admin programmatically and create a two-folder user.
    cy.login('admin', 'admin123')
    cy.adminCreateUser({
      username: 'jane',
      password: 'jane12345',
      name: 'Jane Doe',
      role: 'user',
      homedirs: ['/projects', '/personal'],
      permissions: 'read|write|upload|download',
    })

    // 2. Switch to Jane's session (admin logs out via API to keep teardown tight).
    cy.request({ method: 'POST', url: '/?r=/logout' })
    cy.visit('/')

    cy.get('[data-test="login-username"]').type('jane')
    cy.get('[data-test="login-password"]').type('jane12345')
    cy.get('[data-test="login-submit"]').click()

    // 3. Folder switcher dropdown should be visible in the top menu with
    //    both folders listed.
    cy.get('[data-test="folder-switcher"]').should('be.visible').click()
    cy.get('[data-test="folder-switcher-item"]').should('have.length', 2)
    cy.get('[data-test="folder-switcher-item"]').eq(0).should('contain.text', 'projects')
    cy.get('[data-test="folder-switcher-item"]').eq(1).should('contain.text', 'personal')

    // 4. Switch to /personal — the breadcrumb / current-folder indicator
    //    must update to reflect the new root.
    cy.get('[data-test="folder-switcher-item"]').eq(1).click()
    cy.get('[data-test="current-folder"]').should('contain.text', 'personal')

    // 5. Reload — the active folder selection persists (server-side state
    //    via /changedir is the source of truth; verify it round-trips).
    cy.reload()
    cy.get('[data-test="current-folder"]').should('contain.text', 'personal')
  })
})
