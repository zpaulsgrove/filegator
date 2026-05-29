// Edit-content (savecontent) E2E spec.
//
// A text file is opened through the row menu's "View" action — which
// `preview()` routes to the Editor for text files (clicking the file name
// itself triggers a download, so it can't be used to edit). Saving fires
// POST /savecontent and toasts "Updated". Content *persistence* is covered by
// the backend FilesTest; here the value is the browser wiring (View -> Editor
// -> save), so we assert the success toast rather than bet on the embedded
// prism-editor's internal DOM.

describe('File editing', () => {
  beforeEach(() => {
    cy.resetBackend()
    cy.login('admin', 'admin123')
    cy.visit('/')
    cy.get('[data-test="new-menu"]').should('be.visible')
  })

  function createFile(name) {
    cy.get('[data-test="new-menu"]').click()
    cy.get('[data-test="create-file"]').click()
    cy.get('.dialog input').clear().type(name)
    cy.get('.dialog').contains('button', 'Create').click()
  }

  it('opens a text file in the Editor and saves it', () => {
    createFile('notes.txt')
    cy.contains('.file-row a.name', 'notes.txt').should('exist')

    cy.contains('.file-row', 'notes.txt').within(() => {
      cy.get('[data-test="row-menu"]').click()
      cy.get('[data-test="row-view"]').click({ force: true })
    })

    // Editor modal opens with the filename as its title.
    cy.get('.modal-card').contains('notes.txt').should('be.visible')
    cy.get('[data-test="editor-save"]').click()

    cy.get('.toast').should('contain.text', 'Updated')
  })
})
