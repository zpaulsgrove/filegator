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
    // Selections at or below zip_threshold (default 5) now download individually,
    // so select more than the threshold (6 files) to stay on the archive path and
    // exercise the POST /batchdownload "Your file is ready" wiring.
    const files = ['dl1.txt', 'dl2.txt', 'dl3.txt', 'dl4.txt', 'dl5.txt', 'dl6.txt']
    files.forEach(name => {
      cy.createFile(name)
      cy.contains('.file-row a.name', name).should('exist')
    })

    files.forEach(name => cy.selectRow(name))
    cy.get('[data-test="batch-download"]').click()

    cy.contains('Your file is ready').should('be.visible')
  })
})
