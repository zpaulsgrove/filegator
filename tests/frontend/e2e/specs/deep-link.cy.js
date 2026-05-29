// Deep-link / folder-restoration E2E spec.
//
// Covers:
//   1. Reload restores the subfolder (single-folder admin): a ?cd= param
//      survives a hard reload so the user lands back in the right directory.
//   2. Logged-out deep link → login (single-folder): visiting a ?cd= URL
//      while unauthenticated stashes the intent; after logging in via the
//      force-rendered login form the browser lands in the deep-linked folder.
//   3. (security) Traversal ?cd= path is confined to the homedir root: a
//      path like /../../etc cannot escape the home directory.
//   4. (multi-folder cross-session deep link, Phase 2): a ?folder=&cd= URL
//      after login routes directly to the named folder without the picker.
//
// Runs against a live PHP backend on :8081 booted by start-server-and-test.
// State is reset to the users.json.blank baseline before each test.

describe('Deep-link / folder restoration', () => {
  beforeEach(() => {
    cy.resetBackend()
  })

  // ─────────────────────────────────────────────────────────────────────
  // 1. Reload restores the folder (single-folder admin)
  // ─────────────────────────────────────────────────────────────────────

  it('reload restores the subfolder (single-folder admin)', () => {
    cy.login('admin', 'admin123')
    cy.visit('/')

    // Create a subdirectory via the UI new-menu.
    cy.get('[data-test="new-menu"]').click()
    cy.get('[data-test="create-folder"]').click()
    cy.get('.dialog input').clear().type('sub')
    cy.get('.dialog').contains('button', 'Create').click()

    // Enter the new folder by clicking its name link.
    cy.contains('.file-row a.name', 'sub').click()

    // Confirm the URL reflects the new location.
    cy.hash().should('contain', 'cd=/sub')

    // Create a file inside the subfolder.
    cy.get('[data-test="new-menu"]').click()
    cy.get('[data-test="create-file"]').click()
    cy.get('.dialog input').clear().type('inside.txt')
    cy.get('.dialog').contains('button', 'Create').click()

    // File is visible.
    cy.contains('.file-row a.name', 'inside.txt').should('exist')

    // Hard reload — the ?cd= param in the URL must survive.
    cy.reload()

    // The subfolder content should still be visible (not the root).
    cy.contains('.file-row a.name', 'inside.txt').should('exist')
  })

  // ─────────────────────────────────────────────────────────────────────
  // 2. Logged-out deep link → login (single-folder)
  // ─────────────────────────────────────────────────────────────────────

  it('visiting a deep link while logged out restores the folder after login', () => {
    // Seed the subfolder and file via the API as admin.
    cy.login('admin', 'admin123')
    cy.apiPost('/createnew', { type: 'dir', name: 'sub' })
    cy.apiPost('/changedir', { to: '/sub' })
    cy.apiPost('/createnew', { type: 'file', name: 'inside.txt' })
    cy.apiPost('/logout')

    // Visit the deep link while logged out. App.vue force-renders the
    // Login component because the guest user has no permissions.
    //
    // IMPORTANT: do NOT cy.visit('/login') here. That would cause a full
    // SPA navigation that drops the in-memory pendingCd stash which main.js
    // sets when it sees the ?cd= query param on the initial page load.
    cy.visit('/#/?cd=/sub')

    // Fill the force-rendered login form (same selectors as normal login).
    cy.get('[data-test="login-username"]').type('admin')
    cy.get('[data-test="login-password"]').type('admin123')
    cy.get('[data-test="login-submit"]').click()

    // After login, routeAfterLogin restores the stashed cd. The file
    // created inside /sub must now be visible without any extra navigation.
    cy.contains('.file-row a.name', 'inside.txt').should('exist')
  })

  // ─────────────────────────────────────────────────────────────────────
  // 3. (security) Traversal ?cd= path is confined to the homedir root
  // ─────────────────────────────────────────────────────────────────────

  it('traversal cd path cannot escape the homedir', () => {
    cy.login('admin', 'admin123')

    // Attempt a path-traversal via the URL hash.
    cy.visit('/#/?cd=/../../etc')

    // The browser UI must still render successfully.
    cy.get('[data-test="new-menu"]').should('be.visible')

    // The breadcrumb must NOT contain "etc" — the traversal path must be
    // confined within the homedir and never resolve to a system directory.
    cy.get('.breadcrumb').should('not.contain.text', 'etc')
  })

  // ─────────────────────────────────────────────────────────────────────
  // 4. (multi-folder cross-session deep link, Phase 2)
  // ─────────────────────────────────────────────────────────────────────

  it('multi-folder ?folder=&cd= deep link restores folder+path after login', () => {
    // ── Setup: admin creates jane with two homedirs and seeds a deep path ──

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

    // Log in as jane via the force-rendered login form.
    // Visit '/' first so main.js boots without a ?folder= stash yet.
    cy.visit('/')
    cy.get('[data-test="login-username"]').type('jane')
    cy.get('[data-test="login-password"]').type('jane12345')
    cy.get('[data-test="login-submit"]').click()

    // Jane has two homedirs and no active selection — the picker shows.
    cy.get('[data-test="folder-picker"]').should('be.visible')
    cy.get('[data-test="folder-button"][data-test-path="/personal"]').click()

    // Now inside the /personal root. Create subfolder 'deep' and enter it.
    cy.get('[data-test="current-folder"]').should('contain.text', 'personal')
    cy.get('[data-test="new-menu"]').click()
    cy.get('[data-test="create-folder"]').click()
    cy.get('.dialog input').clear().type('deep')
    cy.get('.dialog').contains('button', 'Create').click()
    cy.contains('.file-row a.name', 'deep').click()

    // Confirm URL encodes both the folder and the subdirectory.
    cy.hash().should('contain', 'folder=/personal')
    cy.hash().should('contain', 'cd=/deep')

    // Create a marker file inside the deep subfolder.
    cy.get('[data-test="new-menu"]').click()
    cy.get('[data-test="create-file"]').click()
    cy.get('.dialog input').clear().type('mark.txt')
    cy.get('.dialog').contains('button', 'Create').click()
    cy.contains('.file-row a.name', 'mark.txt').should('exist')

    // Log jane out.
    cy.apiPost('/logout')

    // ── Cross-session restore: visit the deep link while logged out ──

    // IMPORTANT: do NOT cy.visit('/login'). Visiting the raw URL directly
    // ensures main.js can stash ?folder= and ?cd= before the guest auth
    // check, preserving the restore intent through the login flow.
    cy.visit('/#/?folder=/personal&cd=/deep')

    // Log in as jane via the force-rendered login form.
    cy.get('[data-test="login-username"]').type('jane')
    cy.get('[data-test="login-password"]').type('jane12345')
    cy.get('[data-test="login-submit"]').click()

    // routeAfterLogin sees pendingFolder=/personal and pendingCd=/deep.
    // It selects /personal and routes directly — no picker interaction.
    cy.contains('.file-row a.name', 'mark.txt').should('exist')
    cy.get('[data-test="current-folder"]').should('contain.text', 'personal')

    // ── Sub-case: nonexistent folder hint falls back to the picker ──

    cy.apiPost('/logout')
    cy.visit('/#/?folder=/nonexistent&cd=/deep')

    cy.get('[data-test="login-username"]').type('jane')
    cy.get('[data-test="login-password"]').type('jane12345')
    cy.get('[data-test="login-submit"]').click()

    // /nonexistent is not in jane's homedirs; the picker must be shown.
    cy.get('[data-test="folder-picker"]').should('be.visible')
  })
})
