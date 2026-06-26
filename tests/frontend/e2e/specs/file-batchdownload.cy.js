// Batch-download E2E spec.
//
// Selecting rows and clicking the toolbar "Download" posts to /batchdownload,
// which returns a uniqid and pops a "Your file is ready" dialog (the actual
// stream is a window.open we don't follow). Asserting the dialog appears
// proves the browser -> POST /batchdownload wiring; the zip content itself is
// backend-tested.

describe('Batch download', () => {
  beforeEach(() => {
    cy.resetBackend()
    cy.login('admin', 'admin123')
    cy.visit('/')
    cy.get('[data-test="new-menu"]').should('be.visible')
  })

  it('prepares a batch-download archive for the selected files', () => {
    cy.createFile('dl.txt')
    cy.contains('.file-row a.name', 'dl.txt').should('exist')

    cy.selectRow('dl.txt')
    cy.get('[data-test="batch-download"]').click()

    cy.contains('Your file is ready').should('be.visible')
  })
})
