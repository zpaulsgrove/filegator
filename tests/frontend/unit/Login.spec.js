// Login.vue — MFA nonce round-trip tests
//
// Verifies that the mfa_nonce returned by /login is stored on the component
// and echoed back on /login/mfa (verifyMfa) and /login/mfa/setup (completeSetup),
// and that cancel() clears the nonce.

// ── Module mocks ──────────────────────────────────────────────────────────────

jest.mock('@/api/api', () => ({
  __esModule: true,
  default: {
    login: jest.fn(),
    loginMfa: jest.fn(),
    loginMfaSetup: jest.fn(),
    loginMfaCancel: jest.fn().mockResolvedValue({}),
  },
}))

// routeAfterLogin imports api transitively; stub the whole mixin so router
// calls don't crash under jsdom.
jest.mock('@/mixins/postLogin', () => ({
  routeAfterLogin: jest.fn(),
}))

// QRCode.toCanvas is called during the mfa_setup step; stub it so jsdom
// canvas-API errors don't surface in unrelated tests.
jest.mock('qrcode', () => ({
  toCanvas: jest.fn((canvas, uri, opts, cb) => cb && cb()),
}))

// ── Imports ───────────────────────────────────────────────────────────────────

import { shallowMount } from '@vue/test-utils'
import Login from '@/views/Login.vue'

const api = require('@/api/api').default

// ── Helpers ───────────────────────────────────────────────────────────────────

function flushPromises() {
  return new Promise(resolve => setTimeout(resolve, 0))
}

// ── Stubs ─────────────────────────────────────────────────────────────────────

// b-input stub that exposes focus() on its instance so that Login.vue's
// mounted() guard `this.$refs.username && this.$refs.username.focus()` works.
// Without this the guard is truthy (stub object exists) but .focus is missing.
const BInputStub = {
  name: 'b-input',
  template: '<input />',
  methods: { focus: jest.fn() },
}

// ── Mount helper ──────────────────────────────────────────────────────────────

function mountLogin() {
  return shallowMount(Login, {
    mocks: {
      lang: (s, ...rest) => (rest.length ? s + ' ' + rest.join(' ') : s),
      $store: {
        state: {
          config: {
            logo: '',
            guest_redirection: '',
            password_reset_enabled: false,
          },
        },
        commit: jest.fn(),
      },
      $router: { push: jest.fn() },
      $route: { path: '/login' },
      handleError: jest.fn(),
      can: () => false,
    },
    stubs: {
      'b-field': true,
      'b-input': BInputStub,
      'b-icon': true,
    },
  })
}

// ── Reset mocks between tests ─────────────────────────────────────────────────

beforeEach(() => {
  jest.clearAllMocks()
  // loginMfaCancel is used by cancel(); keep it resolved by default.
  api.loginMfaCancel.mockResolvedValue({})
})

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('Login.vue — MFA nonce round-trip', () => {

  // 1. /login response with mfa_required stores mfa_nonce on the component
  it('/login response with mfa_required stores mfa_nonce on the component', async () => {
    api.login.mockResolvedValue({ mfa_required: true, mfa_nonce: 'abc123def456' })

    const wrapper = mountLogin()
    wrapper.vm.username = 'admin'
    wrapper.vm.password = 'secret'

    wrapper.vm.login()
    await flushPromises()

    expect(wrapper.vm.mfaNonce).toBe('abc123def456')
    expect(wrapper.vm.step).toBe('mfa')
  })

  // 2. verifyMfa() echoes the nonce in the api.loginMfa call
  it('verifyMfa() echoes the nonce in the api.loginMfa call', async () => {
    const mockUser = { id: 1, name: 'Admin', homedirs: ['/home/admin'], active_homedir: '/home/admin' }
    api.loginMfa.mockResolvedValue(mockUser)

    const wrapper = mountLogin()
    wrapper.vm.mfaNonce = 'XYZ'
    wrapper.vm.mfaCode = '654321'
    wrapper.vm.useBackup = false

    wrapper.vm.verifyMfa()
    await flushPromises()

    expect(api.loginMfa).toHaveBeenCalledTimes(1)
    expect(api.loginMfa).toHaveBeenCalledWith({ code: '654321', useBackup: false, nonce: 'XYZ' })
  })

  // 3. completeSetup() echoes the nonce in the api.loginMfaSetup call
  it('completeSetup() echoes the nonce in the api.loginMfaSetup call', async () => {
    const mockUser = { id: 1, name: 'Admin', homedirs: ['/home/admin'], active_homedir: '/home/admin' }
    api.loginMfaSetup.mockResolvedValue({
      user: mockUser,
      backup_codes: ['AAAAA-11111', 'BBBBB-22222'],
    })

    const wrapper = mountLogin()
    wrapper.vm.mfaNonce = 'ENROLL_NONCE'
    wrapper.vm.mfaCode = '123456'

    wrapper.vm.completeSetup()
    await flushPromises()

    expect(api.loginMfaSetup).toHaveBeenCalledTimes(1)
    expect(api.loginMfaSetup).toHaveBeenCalledWith({ code: '123456', nonce: 'ENROLL_NONCE' })
  })

  // 4. cancel() resets mfaNonce to empty string
  it('cancel() resets mfaNonce to empty string', async () => {
    const wrapper = mountLogin()
    wrapper.vm.mfaNonce = 'something'

    wrapper.vm.cancel()
    await flushPromises()

    expect(wrapper.vm.mfaNonce).toBe('')
  })

})
