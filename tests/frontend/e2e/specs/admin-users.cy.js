// Admin user-CRUD UI E2E spec.
//
// Drives the admin Users screen end to end: create / edit / delete a user and
// reset another user's MFA, each gated by the step-up dialog (password + TOTP).
// The admin is MFA-enrolled in beforeEach so the step-up code field is live;
// targets are seeded via the API *before* the admin enrolls (once enrolled,
// /storeuser itself requires step-up). The reject matrix and "code not burned"
// edge cases are covered by backend AdminStepUpTest; here it's the browser
// wiring (Users.vue / UserEdit.vue / StepUpDialog) under test.

describe('Admin user management', () => {
  let secret

  beforeEach(() => {
    cy.resetBackend()
    cy.login('admin', 'admin123')

    // Seed targets while the admin is NOT yet enrolled (no step-up needed).
    // Non-admins must be scoped to a real subfolder, never the firm root, so
    // seed them under a specific folder (the backend now rejects '/' for a
    // non-admin role).
    cy.adminCreateUser({
      username: 'editme', password: 'editme12345', name: 'Edit Me', role: 'user',
      homedirs: ['/editme'], permissions: ['read'],
    })
    cy.adminCreateUser({
      username: 'deleteme', password: 'deleteme123', name: 'Delete Me', role: 'user',
      homedirs: ['/deleteme'], permissions: ['read'],
    })
    cy.apiPost('/logout')

    // Give editme its own MFA so the Reset-MFA control is available.
    cy.login('editme', 'editme12345')
    cy.enrollMfa()
    cy.apiPost('/logout')

    // Enroll the admin and keep the secret for step-up.
    cy.login('admin', 'admin123')
    cy.enrollMfa().then((mfa) => { secret = mfa.secret })

    cy.visit('/')
    cy.get('[data-test="nav-users"]').click()
    cy.contains('tr', 'editme').should('exist')
  })

  function stepUp(password) {
    cy.get('[data-test="stepup-password"]').type(password)
    cy.totp(secret).then((code) => {
      cy.get('[data-test="stepup-code"]').type(code)
      cy.get('[data-test="stepup-confirm"]').click()
    })
  }

  it('creates a user through the UI with step-up', () => {
    cy.get('[data-test="add-user"]').click()
    cy.get('[data-test="user-username"]').type('newbie')
    cy.get('[data-test="user-name"]').type('New Bie')
    cy.get('[data-test="user-password"]').type('newbie12345')
    cy.get('[data-test="user-perm-read"]').click()
    // The folder input opens the Tree picker on focus, so set it without
    // focusing (mirrors the controlled-input pattern used elsewhere). Use a
    // real subfolder — non-admins can no longer be assigned the firm root.
    cy.get('[data-test="user-folder-0"]').invoke('val', '/newbie').trigger('input')
    cy.get('[data-test="user-save"]').click()

    stepUp('admin123')

    cy.contains('tr', 'newbie').should('exist')
  })

  it('edits a user through the UI with step-up', () => {
    cy.contains('tr', 'editme').find('[data-test="user-edit"]').click()
    cy.get('[data-test="user-name"]').clear().type('Edited Name')
    cy.get('[data-test="user-save"]').click()

    stepUp('admin123')

    cy.contains('Edited Name').should('exist')
  })

  it('deletes a user through the UI with step-up', () => {
    cy.contains('tr', 'deleteme').find('[data-test="user-delete"]').click()
    stepUp('admin123')

    cy.contains('tr', 'deleteme').should('not.exist')
  })

  it('resets another user\'s MFA through the UI with step-up', () => {
    cy.contains('tr', 'editme').find('[data-test="user-edit"]').click()
    cy.get('[data-test="user-reset-mfa"]').click()
    stepUp('admin123')

    // Modal closes on success; confirm editme's MFA is cleared server-side.
    cy.request({ method: 'GET', url: '/?r=/listusers' }).then((res) => {
      const editme = res.body.data.find((u) => u.username === 'editme')
      expect(editme.mfa_enabled).to.eq(false)
    })
  })

  it('assigns a second folder to a user through the UI with step-up', () => {
    cy.contains('tr', 'editme').find('[data-test="user-edit"]').click()
    cy.get('[data-test="add-folder"]').click()
    cy.get('[data-test="user-folder-1"]').invoke('val', '/personal').trigger('input')
    cy.get('[data-test="user-save"]').click()

    stepUp('admin123')

    cy.request({ method: 'GET', url: '/?r=/listusers' }).then((res) => {
      const editme = res.body.data.find((u) => u.username === 'editme')
      expect(editme.homedirs).to.have.length(2)
    })
  })

  it('rejects an action when the step-up password is wrong', () => {
    cy.contains('tr', 'deleteme').find('[data-test="user-delete"]').click()
    stepUp('wrong-password')

    // 422 {password:'Wrong password'} maps inline; dialog stays, user remains.
    cy.contains('Wrong password').should('be.visible')
    cy.get('[data-test="stepup-confirm"]').should('be.visible')
  })
})
