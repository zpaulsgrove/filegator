// Admin audit-log E2E spec.
//
// Verifies the browser wiring of the Audit Log screen (Audit.vue + the
// /admin/audit-log endpoint + the admin route guard): a write-mutation
// performed in the app shows up as a row, and a non-admin cannot reach the
// page. The encryption / retention / path-normalisation internals are
// covered by backend AuditLogTest + FilesTest; here it's the UI under test.

describe('Admin audit log', () => {
  beforeEach(() => {
    cy.resetBackend()
    cy.login('admin', 'admin123')
  })

  it('shows a recorded file action to an admin', () => {
    // /createnew records a `create` audit event in the admin's home (/).
    cy.createFile('audit-target.txt')

    cy.visit('/')
    cy.get('[data-test="nav-audit-log"]').click()

    cy.get('[data-test="audit-table"]').should('be.visible')
    cy.contains('[data-test="audit-table"] tr', 'audit-target.txt').within(() => {
      cy.contains('Create').should('exist')
      cy.contains('admin').should('exist')
      cy.contains('/audit-target.txt').should('exist')
    })
  })

  it('renders an attacker-controlled filename inert (no stored XSS)', () => {
    // Audit.vue carries an explicit "never v-html" contract: filenames are
    // attacker-controlled and rendered via {{ }} only. A crafted name must
    // appear as escaped text, never as live markup in the admin's session.
    cy.createFile('<img src=x onerror=alert(1)>.txt')

    cy.visit('/')
    cy.get('[data-test="nav-audit-log"]').click()

    cy.get('[data-test="audit-table"]').should('be.visible')
    // The literal markup shows as text...
    cy.contains('[data-test="audit-table"]', 'onerror=alert(1)').should('exist')
    // ...and is NOT parsed into a live <img> element.
    cy.get('[data-test="audit-table"] img[src="x"]').should('not.exist')
  })

  it('filters the activity list by action', () => {
    cy.createFile('only-create.txt')

    cy.visit('/')
    cy.get('[data-test="nav-audit-log"]').click()

    // Filtering to a non-matching action hides the create row.
    cy.get('[data-test="audit-action-filter"]').select('Delete')
    cy.contains('[data-test="audit-table"]', 'only-create.txt').should('not.exist')

    cy.get('[data-test="audit-action-filter"]').select('Create')
    cy.contains('[data-test="audit-table"]', 'only-create.txt').should('exist')
  })

  it('redirects a non-admin away from the audit log', () => {
    cy.adminCreateUser({
      username: 'plainuser', password: 'plainuser123', name: 'Plain User',
      role: 'user', homedirs: ['/plainuser'], permissions: ['read'],
    })
    cy.apiPost('/logout')
    cy.login('plainuser', 'plainuser123')

    // Deep-link straight at the guarded route; the admin guard must bounce
    // a non-admin back to the browser and never render the audit nav link.
    cy.visit('/#/audit-log')
    cy.get('[data-test="audit-table"]').should('not.exist')
    cy.get('[data-test="nav-audit-log"]').should('not.exist')
  })
})
