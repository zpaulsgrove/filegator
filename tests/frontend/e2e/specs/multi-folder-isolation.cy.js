// Multi-folder isolation E2E spec.
//
// Complements multi-folder.cy.js (which covers the picker + switcher wiring)
// by proving the user-visible isolation guarantee: a file created in one
// homedir does not appear in another, and reappears when you switch back.
// Server-side isolation is covered by FilesTest; here it's the browser-level
// folder-switch behavior under test.
//
// Deep-link-after-login restoration is now covered by deep-link.cy.js (single-
// folder reload, logged-out deep link, traversal confinement, and multi-folder
// cross-session `?folder=&cd=` restore), so it is no longer exercised here.

describe('Multi-folder isolation', () => {
  beforeEach(() => {
    cy.resetBackend()
    cy.login('admin', 'admin123')
    cy.adminCreateUser({
      username: 'jane',
      password: 'jane12345',
      name: 'Jane Doe',
      role: 'user',
      homedirs: ['/projects', '/personal'],
      permissions: ['read', 'write', 'upload', 'download'],
    })
    cy.apiPost('/logout')
  })

  function createFile(name) {
    cy.get('[data-test="new-menu"]').click()
    cy.get('[data-test="create-file"]').click()
    cy.get('.dialog input').clear().type(name)
    cy.get('.dialog').contains('button', 'Create').click()
  }

  function switchTo(folder) {
    cy.get('[data-test="folder-switcher"]').click()
    cy.get('[data-test="folder-switcher-item"]').contains(folder).click()
    cy.get('[data-test="current-folder"]').should('contain.text', folder)
  }

  it('keeps a file in one folder invisible from the other', () => {
    cy.visit('/')
    cy.get('[data-test="login-username"]').type('jane')
    cy.get('[data-test="login-password"]').type('jane12345')
    cy.get('[data-test="login-submit"]').click()

    // Picker -> projects (folder A).
    cy.get('[data-test="folder-button"][data-test-path="/projects"]').click()
    cy.get('[data-test="current-folder"]').should('contain.text', 'projects')

    createFile('iso.txt')
    cy.contains('.file-row a.name', 'iso.txt').should('exist')

    // Folder B (personal) — the file must not be there.
    switchTo('personal')
    cy.contains('.file-row a.name', 'iso.txt').should('not.exist')

    // Back to folder A — the file is still there.
    switchTo('projects')
    cy.contains('.file-row a.name', 'iso.txt').should('exist')
  })
})
