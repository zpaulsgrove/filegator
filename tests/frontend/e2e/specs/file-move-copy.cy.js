// Move / Copy E2E spec.
//
// Both are toolbar actions on checked rows that open the Tree destination
// picker (TreeNode emits `selected` with the chosen dir, which Browser passes
// to moveItems/copyItems). The Tree's root auto-expands to list child dirs.
// Move removes the original; Copy leaves it. Cross-folder mechanics are
// backend-tested — here we prove the browser wiring through the picker.

describe('File move and copy', () => {
  beforeEach(() => {
    cy.resetBackend()
    cy.login('admin', 'admin123')
    cy.visit('/')
    cy.get('[data-test="new-menu"]').should('be.visible')
  })

  // The New menu only creates folders now; file fixtures come from
  // cy.createFile (API + reload).
  function createFolder(name) {
    cy.get('[data-test="new-menu"]').click()
    cy.get('.dialog input').clear().type(name)
    cy.get('.dialog').contains('button', 'Create').click()
  }

  // The Tree modal auto-expands the root; click the destination folder node.
  function pickDestination(folder) {
    cy.contains('[data-test="tree-node"]', folder).click()
  }

  it('moves a file into a folder', () => {
    createFolder('dest')
    cy.createFile('movable.txt')
    cy.contains('.file-row a.name', 'movable.txt').should('exist')

    cy.selectRow('movable.txt')
    cy.get('[data-test="move-selected"]').click()
    pickDestination('dest')

    // Gone from root...
    cy.contains('.file-row a.name', 'movable.txt').should('not.exist')
    // ...and present inside dest.
    cy.contains('.file-row a.name', 'dest').click()
    cy.contains('.file-row a.name', 'movable.txt').should('exist')
  })

  it('copies a file into a folder, leaving the original', () => {
    createFolder('dest')
    cy.createFile('copyable.txt')
    cy.contains('.file-row a.name', 'copyable.txt').should('exist')

    cy.selectRow('copyable.txt')
    cy.get('[data-test="copy-selected"]').click()
    pickDestination('dest')

    // Original still at root...
    cy.contains('.file-row a.name', 'copyable.txt').should('exist')
    // ...and a copy is inside dest.
    cy.contains('.file-row a.name', 'dest').click()
    cy.contains('.file-row a.name', 'copyable.txt').should('exist')
  })
})
