// router.js — access-control route guards.
//
// The /users (admin-only) and /security (user-or-admin) beforeEnter guards are
// the client-side authorisation gates. A regression here is a direct access
// bug, so they get explicit coverage. The store and the postLogin helper are
// mocked so we can drive the guards without booting the whole app.

jest.mock('@/store', () => ({ __esModule: true, default: { state: { user: { role: 'guest' } } } }))
jest.mock('@/mixins/postLogin', () => ({
  __esModule: true,
  needsFolderPicker: jest.fn(() => false),
  routeAfterLogin: jest.fn(),
}))

// Stub the view components so importing the router doesn't drag in the whole
// component tree (Browser → Editor → prismjs CSS, which jest can't transform
// inside node_modules). The guards never touch the component definitions.
jest.mock('@/views/Browser.vue', () => ({ __esModule: true, default: {} }))
jest.mock('@/views/Users.vue', () => ({ __esModule: true, default: {} }))
jest.mock('@/views/Login.vue', () => ({ __esModule: true, default: {} }))
jest.mock('@/views/Security.vue', () => ({ __esModule: true, default: {} }))
jest.mock('@/views/ForgotPassword.vue', () => ({ __esModule: true, default: {} }))
jest.mock('@/views/ResetPassword.vue', () => ({ __esModule: true, default: {} }))
jest.mock('@/views/SelectFolder.vue', () => ({ __esModule: true, default: {} }))

import store from '@/store'
import router from '@/router'

function guardFor(name) {
  return router.options.routes.find(r => r.name === name).beforeEnter
}

function runGuard(name, role) {
  store.state.user.role = role
  const next = jest.fn()
  guardFor(name)({}, {}, next)
  return next
}

beforeEach(() => {
  store.state.user.role = 'guest'
})

describe('router access guards', () => {

  describe('/users (admin only)', () => {
    it('admin is allowed straight through', () => {
      expect(runGuard('users', 'admin')).toHaveBeenCalledWith()
    })

    it('non-admin user is bounced to "/"', () => {
      expect(runGuard('users', 'user')).toHaveBeenCalledWith('/')
    })

    it('guest is bounced to "/"', () => {
      expect(runGuard('users', 'guest')).toHaveBeenCalledWith('/')
    })
  })

  describe('/security (user or admin)', () => {
    it('admin is allowed', () => {
      expect(runGuard('security', 'admin')).toHaveBeenCalledWith()
    })

    it('user is allowed', () => {
      expect(runGuard('security', 'user')).toHaveBeenCalledWith()
    })

    it('guest is redirected to /login', () => {
      expect(runGuard('security', 'guest')).toHaveBeenCalledWith('/login')
    })
  })
})
