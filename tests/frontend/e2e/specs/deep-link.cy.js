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

    // Confirm the URL reflects the new location. vue-router percent-encodes
    // the slash in query values (cd=%2Fsub), so assert encoding-agnostically.
    cy.hash().should('include', 'cd=').and('include', 'sub')

    // Create a file inside the subfolder (createFile reloads, and the app
    // restores /sub from the cd= param, so it lists the file in place).
    cy.createFile('inside.txt')

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

  it('traversal cd path is confined to the homedir root', () => {
    cy.login('admin', 'admin123')

    // Attempt a path-traversal via the URL hash.
    cy.visit('/#/?cd=/../../etc')

    // The browser UI must still render successfully.
    cy.get('[data-test="new-menu"]').should('be.visible')

    // The backend (Filesystem::applyPathPrefix) collapses any '..' segment to
    // the homedir root, so the *listing* is the admin's root — where the
    // resetBackend fixtures 'projects' and 'personal' live — and never the
    // system /etc directory. We assert on the listing (the real confinement
    // guarantee) rather than the breadcrumb, which cosmetically echoes the raw
    // requested path.
    cy.contains('.file-row a.name', 'projects').should('exist')
    cy.contains('.file-row a.name', 'passwd').should('not.exist')
  })

  // ─────────────────────────────────────────────────────────────────────
  // 4. (multi-folder cross-session deep link, Phase 2)
  // ─────────────────────────────────────────────────────────────────────

  // NB: these three scenarios are deliberately separate `it` blocks. Cypress
  // (and the browser) does NOT reload on a hash-only URL change, so a second
  // `cy.visit('/#/...')` within one test after the SPA already loaded is a
  // no-op (main.js never re-bootstraps). Splitting gives each scenario a fresh
  // browser via testIsolation, so its first `cy.visit` is a real page load.

  function createJane() {
    cy.adminCreateUser({
      username: 'jane',
      password: 'jane12345',
      name: 'Jane Doe',
      role: 'user',
      homedirs: ['/projects', '/personal'],
      permissions: ['read', 'write', 'upload', 'download'],
    })
  }

  it('multi-folder users encode the active folder in deep links (goTo)', () => {
    cy.login('admin', 'admin123')
    createJane()
    cy.apiPost('/logout')

    // Log in as jane and pick a folder via the picker.
    cy.visit('/')
    cy.get('[data-test="login-username"]').type('jane')
    cy.get('[data-test="login-password"]').type('jane12345')
    cy.get('[data-test="login-submit"]').click()
    cy.get('[data-test="folder-picker"]').should('be.visible')
    cy.get('[data-test="folder-button"][data-test-path="/personal"]').click()
    cy.get('[data-test="current-folder"]').should('contain.text', 'personal')

    // Create a subfolder and enter it. The deep link must now encode BOTH the
    // active homedir (?folder=) and the subdirectory (?cd=) so a bookmark is
    // self-describing. vue-router percent-encodes the slashes, so assert on
    // the encoding-agnostic substrings.
    cy.get('[data-test="new-menu"]').click()
    cy.get('[data-test="create-folder"]').click()
    cy.get('.dialog input').clear().type('deep')
    cy.get('.dialog').contains('button', 'Create').click()
    cy.contains('.file-row a.name', 'deep').click()
    cy.hash().should('include', 'folder=').and('include', 'personal')
    cy.hash().should('include', 'cd=').and('include', 'deep')
  })

  it('multi-folder cross-session deep link restores folder + path after login', () => {
    // Seed /personal/deep/mark.txt for jane via the API. A multi-folder user
    // has no active homedir until one is selected, so selectfolder comes first.
    cy.login('admin', 'admin123')
    createJane()
    cy.apiPost('/logout')
    cy.login('jane', 'jane12345')
    cy.apiPost('/selectfolder', { homedir: '/personal' })
    cy.apiPost('/createnew', { type: 'dir', name: 'deep' })
    cy.apiPost('/changedir', { to: '/deep' })
    cy.apiPost('/createnew', { type: 'file', name: 'mark.txt' })
    cy.apiPost('/logout')

    // Visit the self-describing deep link while logged out. This is the first
    // page load in this test, so main.js bootstraps and stashes ?folder= and
    // ?cd= before the guest auth check. Do NOT cy.visit('/login') — that would
    // drop the in-memory stash.
    cy.visit('/#/?folder=/personal&cd=/deep')
    cy.get('[data-test="login-username"]').type('jane')
    cy.get('[data-test="login-password"]').type('jane12345')
    cy.get('[data-test="login-submit"]').click()

    // routeAfterLogin selects /personal and restores /deep — no picker.
    cy.contains('.file-row a.name', 'mark.txt').should('exist')
    cy.get('[data-test="current-folder"]').should('contain.text', 'personal')
    cy.get('[data-test="folder-picker"]').should('not.exist')
  })

  it('multi-folder deep link with an invalid folder falls back to the picker', () => {
    cy.login('admin', 'admin123')
    createJane()
    cy.apiPost('/logout')

    // A folder hint that isn't one of jane's homedirs must not bypass the
    // picker. First page load in this test, so the deep link is bootstrapped.
    cy.visit('/#/?folder=/nonexistent&cd=/deep')
    cy.get('[data-test="login-username"]').type('jane')
    cy.get('[data-test="login-password"]').type('jane12345')
    cy.get('[data-test="login-submit"]').click()

    cy.get('[data-test="folder-picker"]').should('be.visible')
  })
})
