/**
 * Login.vue — MFA nonce round-trip tests
 *
 * Pins the security feature added in the MFA hardening pass: the backend's
 * /login response carries an `mfa_nonce` field when MFA (or MFA setup) is
 * required. The frontend stores that nonce and echoes it back via the `nonce`
 * parameter of api.loginMfa / api.loginMfaSetup. The api wrapper maps the JS
 * `nonce` param → `mfa_nonce` field in the HTTP request body.
 *
 * Defeating the two-tab pending-state pollution attack depends entirely on
 * this round-trip being wired correctly, so these tests must stay green.
 *
 * What we confirmed from the source before writing:
 *   - data property:  mfaNonce  (camelCase)
 *   - step values:    'password' | 'mfa' | 'mfa_setup'
 *   - api.loginMfa   called with { code, useBackup, nonce }  — nonce is the
 *     JS param name; the wrapper maps it to mfa_nonce in the HTTP body
 *   - api.loginMfaSetup called with { code, nonce } — same nonce→mfa_nonce mapping
 *   - api default export (not named)
 *   - lang / handleError / can come from Vue.mixin(shared) — stubbed via mocks
 *   - routeAfterLogin is a named export from @/mixins/postLogin — mocked
 *   - QRCode.toCanvas is called in drawQr() — mocked to prevent canvas errors
 */

import { shallowMount } from '@vue/test-utils'
import Login from '@/views/Login.vue'

// flush-promises is not installed; replicate its one-liner locally.
// Schedules a macrotask that resolves after all currently queued micro-tasks
// (Promise .then chains) have settled.
function flushPromises() {
  return new Promise(resolve => setTimeout(resolve, 0))
}

// ── Module mocks ────────────────────────────────────────────────────────────

jest.mock('@/api/api', () => ({
  __esModule: true,
  default: {
    login: jest.fn(),
    loginMfa: jest.fn(),
    loginMfaSetup: jest.fn(),
    loginMfaCancel: jest.fn().mockResolvedValue({}),
  },
}))

jest.mock('@/mixins/postLogin', () => ({
  __esModule: true,
  routeAfterLogin: jest.fn(),
}))

// QRCode.toCanvas is called inside $nextTick during mfa_setup; stub it so
// canvas APIs don't blow up in jsdom.
jest.mock('qrcode', () => ({ toCanvas: jest.fn((canvas, uri, opts, cb) => cb && cb()) }))

// ── Helpers ─────────────────────────────────────────────────────────────────

// Pull the mock objects AFTER jest.mock() has run.
const api = require('@/api/api').default

/**
 * Build a minimal Vuex-like store stub that satisfies everything Login.vue
 * reads from $store:
 *   - state.config.guest_redirection  (falsy so the template renders)
 *   - state.config.logo               (used in <img :src>)
 *   - state.config.password_reset_enabled
 *   - state.user                      (read by routeAfterLogin after setUser)
 *   - getters.hasPermissions          (used by can() from shared mixin)
 */
function makeStore(overrides = {}) {
  return {
    state: {
      config: {
        guest_redirection: null,
        logo: '',
        password_reset_enabled: false,
        language: 'english',
      },
      user: null,
      ...overrides.state,
    },
    getters: {
      hasPermissions: () => false,
      ...overrides.getters,
    },
    commit: jest.fn(),
  }
}

// A stub for b-input that exposes a focus() method so that the mounted()
// guard `this.$refs.username && this.$refs.username.focus()` doesn't throw.
// shallowMount stubs ordinarily render as plain elements; $refs point at the
// Vue component instance which has no focus(). We give it one explicitly.
const BInputStub = {
  name: 'BInput',
  template: '<input />',
  methods: { focus: jest.fn() },
  props: { value: {} },
}

/**
 * Mount Login.vue with all required stubs in place.
 * lang, handleError, and can come from the global Vue.mixin(shared); we stub
 * them via the `mocks` option so the real shared.js (which imports the full
 * Vuex store singleton) is never executed.
 */
function mountLogin(storeOverrides = {}) {
  const store = makeStore(storeOverrides)

  return shallowMount(Login, {
    mocks: {
      $store: store,
      $router: { push: jest.fn() },
      $route: { query: {} },
      // global-mixin stubs
      lang: s => s,
      handleError: jest.fn(),
      can: () => false,
    },
    // stub Buefy components; b-input needs focus() for the mounted() guard
    stubs: {
      'b-field': true,
      'b-input': BInputStub,
      'b-icon': true,
    },
  })
}

// ── Tests ────────────────────────────────────────────────────────────────────

describe('Login.vue — MFA nonce round-trip', () => {
  beforeEach(() => {
    jest.clearAllMocks()
    // loginMfaCancel is called by cancel(); keep it resolved by default
    api.loginMfaCancel.mockResolvedValue({})
  })

  // ── Test 1 ──────────────────────────────────────────────────────────────
  // Receiving mfa_required: the nonce is stored and step advances to 'mfa'.

  it('stores mfa_nonce from /login response when MFA is required', async () => {
    api.login.mockResolvedValueOnce({
      mfa_required: true,
      mfa_nonce: 'abc123def456',
    })

    const wrapper = mountLogin()
    wrapper.vm.username = 'alice'
    wrapper.vm.password = 's3cr3t'

    wrapper.vm.login()
    await flushPromises()

    expect(wrapper.vm.mfaNonce).toBe('abc123def456')
    expect(wrapper.vm.step).toBe('mfa')
  })

  // ── Test 2 ──────────────────────────────────────────────────────────────
  // verifyMfa() must echo the stored nonce back in the api.loginMfa call.
  // The api wrapper maps the JS `nonce` param → `mfa_nonce` in the HTTP body;
  // this test asserts on the JS parameter layer (the object passed to the mock).

  it('echoes mfa_nonce back in /login/mfa request body', async () => {
    api.loginMfa.mockResolvedValueOnce({ role: 'user', homedirs: ['/'] })

    const wrapper = mountLogin()

    // Pre-position the component at the mfa step with a populated nonce.
    wrapper.vm.step = 'mfa'
    wrapper.vm.mfaNonce = 'abc123def456'
    wrapper.vm.mfaCode = '123456'

    wrapper.vm.verifyMfa()
    await flushPromises()

    expect(api.loginMfa).toHaveBeenCalledTimes(1)
    expect(api.loginMfa).toHaveBeenCalledWith(
      expect.objectContaining({
        code: '123456',
        nonce: 'abc123def456',
      }),
    )
  })

  // ── Test 3 ──────────────────────────────────────────────────────────────
  // completeSetup() must echo the stored nonce back in the api.loginMfaSetup
  // call. Same nonce→mfa_nonce mapping as Test 2 but for the setup branch.

  it('echoes mfa_nonce back in /login/mfa/setup request body', async () => {
    api.loginMfaSetup.mockResolvedValueOnce({
      user: { role: 'admin', username: 'admin' },
      backup_codes: ['AAAAA-11111'],
    })

    const wrapper = mountLogin()

    // Pre-position the component at the mfa_setup step.
    // Set enrollment BEFORE step so the template doesn't re-render with a
    // null enrollment (which would trip on enrollment.secret and emit a
    // Vue warn). shallowMount has no real canvas so drawQr() is a safe no-op.
    wrapper.vm.enrollment = { secret: 'BASE32SECRET', otpauth_uri: 'otpauth://totp/test' }
    wrapper.vm.mfaNonce = 'setup_nonce_xyz'
    wrapper.vm.mfaCode = '654321'
    wrapper.vm.step = 'mfa_setup'

    wrapper.vm.completeSetup()
    await flushPromises()

    expect(api.loginMfaSetup).toHaveBeenCalledTimes(1)
    expect(api.loginMfaSetup).toHaveBeenCalledWith(
      expect.objectContaining({
        code: '654321',
        nonce: 'setup_nonce_xyz',
      }),
    )
  })

  // ── Test 4 ──────────────────────────────────────────────────────────────
  // cancel() must reset mfaNonce to '' and return the component to the
  // 'password' step. This prevents a stale nonce being reused after the
  // user cancels out and retries from scratch.

  it('resets mfaNonce on cancel', async () => {
    const wrapper = mountLogin()

    wrapper.vm.step = 'mfa'
    wrapper.vm.mfaNonce = 'abc123def456'

    wrapper.vm.cancel()
    await flushPromises()

    expect(wrapper.vm.mfaNonce).toBe('')
    expect(wrapper.vm.step).toBe('password')
  })
})
