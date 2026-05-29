// chmod E2E spec.
//
// The row-menu "Permissions" action opens the Permissions modal; setting the
// octal value and saving fires POST /chmoditems and reloads the listing, whose
// Permissions column then shows the new mode. admin has the `chmod` permission
// in users.json.blank.

describe('File permissions (chmod)', () => {
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

  it('changes a file\'s permissions via the Permissions modal', () => {
    createFile('perm.txt')
    cy.contains('.file-row a.name', 'perm.txt').should('exist')

    cy.contains('.file-row', 'perm.txt').within(() => {
      cy.get('[data-test="row-menu"]').click()
      cy.get('[data-test="row-permissions"]').click({ force: true })
    })

    cy.get('[data-test="perm-octal"]').clear().type('750')
    cy.get('[data-test="perm-save"]').click()

    cy.contains('.file-row', 'perm.txt').should('contain.text', '750')
  })
})
