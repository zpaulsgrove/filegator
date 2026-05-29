// Upload E2E spec.
//
// The toolbar "Add files" control is a native file input; selecting a file
// hands it to Upload.vue, which uploads via resumable.js and, on fileSuccess,
// reloads the directory listing. A small fixture is a single chunk. Chunking
// and size limits are backend-tested (UploadTest); here we prove the browser
// upload path end to end by waiting for the new row to appear.

describe('File upload', () => {
  beforeEach(() => {
    cy.resetBackend()
    cy.login('admin', 'admin123')
    cy.visit('/')
    cy.get('[data-test="new-menu"]').should('be.visible')
  })

  it('uploads a file and shows it in the listing', () => {
    cy.get('input[type="file"]').first()
      .selectFile('tests/frontend/e2e/fixtures/upload-sample.txt', { force: true })

    // Listing auto-refreshes on fileSuccess; allow time for the XHR chunk.
    cy.contains('.file-row a.name', 'upload-sample.txt', { timeout: 15000 }).should('exist')
  })
})
