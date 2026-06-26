// Zip / Unzip E2E spec.
//
// Zip is a toolbar action on checked rows that opens a Buefy prompt for the
// archive name; Unzip is a row-menu action (only on .zip files) behind a
// confirm dialog. We zip a file, remove the original to avoid an extraction
// name-collision, then unzip and confirm the file is restored.

describe('File zip and unzip', () => {
  beforeEach(() => {
    cy.resetBackend()
    cy.login('admin', 'admin123')
    cy.visit('/')
    cy.get('[data-test="new-menu"]').should('be.visible')
  })

  it('zips a file then unzips the archive', () => {
    cy.createFile('a.txt')
    cy.contains('.file-row a.name', 'a.txt').should('exist')

    // Zip (toolbar) -> name prompt.
    cy.selectRow('a.txt')
    cy.get('[data-test="zip-selected"]').click()
    cy.get('.dialog input').clear().type('bundle.zip')
    cy.get('.dialog').contains('button', 'Create').click()
    cy.contains('.file-row a.name', 'bundle.zip').should('exist')

    // Remove the original so the extraction doesn't collide.
    cy.contains('.file-row', 'a.txt').within(() => {
      cy.get('[data-test="row-menu"]').click()
      cy.get('[data-test="row-delete"]').click({ force: true })
    })
    cy.get('.dialog').contains('button', 'Delete').click()
    cy.contains('.file-row a.name', 'a.txt').should('not.exist')

    // Unzip (row menu) -> confirm.
    cy.contains('.file-row', 'bundle.zip').within(() => {
      cy.get('[data-test="row-menu"]').click()
      cy.get('[data-test="row-unzip"]').click({ force: true })
    })
    cy.get('.dialog').contains('button', 'Unzip').click()

    cy.contains('.file-row a.name', 'a.txt').should('exist')
  })
})
