// Core file-operations critical-path E2E spec.
//
// Drives the browser UI end to end against a live backend:
// create folder -> create file -> rename file -> delete file, asserting
// the table reflects each change. This is the product's actual job, so
// it ships in PR 1 alongside auth.
//
// admin is a single-folder user ('/'), so the active homedir is seeded
// server-side at login and file ops work immediately. State resets to
// users.json.blank and an empty repository before each test (F2).

describe('File operations', () => {
  beforeEach(() => {
    cy.resetBackend()
    cy.login('admin', 'admin123')
    cy.visit('/')
    // Wait for the file browser to be interactive.
    cy.get('[data-test="new-menu"]').should('be.visible')
  })

  // Create a folder via the toolbar "New" dropdown, which opens a Buefy prompt
  // dialog (rendered globally, targeted by its .dialog class). The menu only
  // creates folders now; file fixtures come from cy.createFile (API + reload).
  function createFolder(name) {
    cy.get('[data-test="new-menu"]').click()
    cy.get('[data-test="create-folder"]').click()
    cy.get('.dialog input').clear().type(name)
    cy.get('.dialog').contains('button', 'Create').click()
  }

  it('creates a folder and a file', () => {
    createFolder('cyfolder')
    cy.contains('.file-row a.name', 'cyfolder').should('exist')

    cy.createFile('cyfile.txt')
    cy.contains('.file-row a.name', 'cyfile.txt').should('exist')
  })

  it('renames a file', () => {
    cy.createFile('cyfile.txt')
    cy.contains('.file-row a.name', 'cyfile.txt').should('exist')

    // Open the row's single-action menu and choose Rename. The dropdown
    // lives inside the row, so scope by the row to disambiguate from the
    // identical controls on every other row.
    cy.contains('.file-row', 'cyfile.txt').within(() => {
      cy.get('[data-test="row-menu"]').click()
      cy.get('[data-test="row-rename"]').click({ force: true })
    })
    cy.get('.dialog input').clear().type('renamed.txt')
    cy.get('.dialog').contains('button', 'Rename').click()

    cy.contains('.file-row a.name', 'renamed.txt').should('exist')
    cy.contains('.file-row a.name', 'cyfile.txt').should('not.exist')
  })

  it('deletes a file', () => {
    cy.createFile('doomed.txt')
    cy.contains('.file-row a.name', 'doomed.txt').should('exist')

    cy.contains('.file-row', 'doomed.txt').within(() => {
      cy.get('[data-test="row-menu"]').click()
      cy.get('[data-test="row-delete"]').click({ force: true })
    })
    cy.get('.dialog').contains('button', 'Delete').click()

    cy.contains('.file-row a.name', 'doomed.txt').should('not.exist')
  })
})
