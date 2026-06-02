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
const { routeAfterLogin } = require('@/mixins/postLogin')

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

function mountLogin(configOverrides = {}) {
  return shallowMount(Login, {
    mocks: {
      lang: (s, ...rest) => (rest.length ? s + ' ' + rest.join(' ') : s),
      $store: {
        state: {
          config: {
            logo: '',
            guest_redirection: '',
            password_reset_enabled: false,
            ...configOverrides,
          },
        },
        commit: jest.fn(),
      },
      // push() is chained with .catch() in the forgot-password handler, so it
      // must return a thenable.
      $router: { push: jest.fn(() => Promise.resolve()) },
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

describe('Login.vue — login() branching', () => {

  it('plain success commits the user and routes (no MFA step)', async () => {
    const user = { username: 'john', role: 'user', homedirs: ['/'], active_homedir: '/' }
    api.login.mockResolvedValue(user)

    const wrapper = mountLogin()
    wrapper.vm.username = 'john'
    wrapper.vm.password = 'pw'
    wrapper.vm.login()
    await flushPromises()

    expect(wrapper.vm.$store.commit).toHaveBeenCalledWith('setUser', user)
    expect(routeAfterLogin).toHaveBeenCalled()
    expect(wrapper.vm.step).toBe('password')
  })

  it('mfa_setup_required moves to the setup step and stores enrollment + nonce', async () => {
    api.login.mockResolvedValue({
      mfa_setup_required: true,
      mfa_nonce: 'NONCE',
      enrollment: { secret: 'S', otpauth_uri: 'otpauth://x' },
    })

    const wrapper = mountLogin()
    wrapper.vm.username = 'admin'
    wrapper.vm.password = 'pw'
    wrapper.vm.login()
    await flushPromises()

    expect(wrapper.vm.step).toBe('mfa_setup')
    expect(wrapper.vm.mfaNonce).toBe('NONCE')
    expect(wrapper.vm.enrollment).toEqual({ secret: 'S', otpauth_uri: 'otpauth://x' })
    // No user committed yet — setup must complete first.
    expect(wrapper.vm.$store.commit).not.toHaveBeenCalledWith('setUser', expect.anything())
  })

  it('maps a structured server error into the inline error and clears the password', async () => {
    api.login.mockRejectedValue({ response: { data: { data: 'Login failed' } } })

    const wrapper = mountLogin()
    wrapper.vm.username = 'john'
    wrapper.vm.password = 'secret'
    wrapper.vm.login()
    await flushPromises()

    expect(wrapper.vm.error).toBe('Login failed')
    expect(wrapper.vm.password).toBe('')
    expect(routeAfterLogin).not.toHaveBeenCalled()
  })

})

describe('Login.vue — credentials required, but validated in JS (no native required)', () => {

  // The inputs intentionally carry no HTML5 `required` attribute, so the
  // browser never fires its "please fill out this field" bubble on stray
  // clicks. login() enforces the same requirement in JS instead.

  it('username and password inputs are not natively required', () => {
    const wrapper = mountLogin()
    // Non-prop attrs fall through to the stub's root <input>; `required`
    // must be absent on both.
    expect(wrapper.find('[data-test="login-username"]').attributes('required')).toBeUndefined()
    expect(wrapper.find('[data-test="login-password"]').attributes('required')).toBeUndefined()
  })

  it('empty username blocks the API call and shows an inline error', async () => {
    const wrapper = mountLogin()
    wrapper.vm.username = ''
    wrapper.vm.password = 'pw'

    wrapper.vm.login()
    await flushPromises()

    expect(api.login).not.toHaveBeenCalled()
    expect(wrapper.vm.error).toBe('Please enter your username and password.')
  })

  it('empty password blocks the API call and shows an inline error', async () => {
    const wrapper = mountLogin()
    wrapper.vm.username = 'john'
    wrapper.vm.password = ''

    wrapper.vm.login()
    await flushPromises()

    expect(api.login).not.toHaveBeenCalled()
    expect(wrapper.vm.error).toBe('Please enter your username and password.')
  })

})

describe('Login.vue — forgot links never submit the login form', () => {

  it('"Forgot your username?" is a non-submitting button that toggles help without calling the API', async () => {
    const wrapper = mountLogin()
    const btn = wrapper.find('[data-test="login-forgot-username"]')

    expect(btn.exists()).toBe(true)
    expect(btn.attributes('type')).toBe('button')
    expect(wrapper.vm.showUsernameHelp).toBe(false)

    await btn.trigger('click')

    expect(wrapper.vm.showUsernameHelp).toBe(true)
    expect(api.login).not.toHaveBeenCalled()
  })

  it('"Forgot password?" is a non-submitting button that routes without requiring credentials', async () => {
    const wrapper = mountLogin({ password_reset_enabled: true })
    const btn = wrapper.find('[data-test="login-forgot-password"]')

    expect(btn.exists()).toBe(true)
    expect(btn.attributes('type')).toBe('button')

    await btn.trigger('click')

    expect(wrapper.vm.$router.push).toHaveBeenCalledWith('/forgot-password')
    expect(api.login).not.toHaveBeenCalled()
  })

})
