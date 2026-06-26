// ForgotPassword.vue — email validated in JS (no native required)
//
// The email input intentionally carries no HTML5 `required` attribute. Buefy
// runs its validity check on blur, so a `required` field popped the browser's
// "please fill out this field" message the instant the user clicked the
// adjacent "Back to login" button — and the inserted message shifted the row
// enough to swallow that click. submit() enforces a non-empty email in JS.

// ── Module mocks ──────────────────────────────────────────────────────────────

jest.mock('@/api/api', () => ({
  __esModule: true,
  default: {
    requestPasswordReset: jest.fn(),
  },
}))

// ── Imports ───────────────────────────────────────────────────────────────────

import { shallowMount } from '@vue/test-utils'
import ForgotPassword from '@/views/ForgotPassword.vue'

const api = require('@/api/api').default

// ── Helpers ───────────────────────────────────────────────────────────────────

function flushPromises() {
  return new Promise(resolve => setTimeout(resolve, 0))
}

const BInputStub = {
  name: 'b-input',
  template: '<input />',
  methods: { focus: jest.fn() },
}

function mountForgot() {
  return shallowMount(ForgotPassword, {
    mocks: {
      lang: (s, ...rest) => (rest.length ? s + ' ' + rest.join(' ') : s),
      $store: { state: { config: { logo: '', password_reset_token_ttl: 3600 } } },
      $router: { push: jest.fn(() => Promise.resolve()) },
      handleError: jest.fn(),
    },
    stubs: { 'b-field': true, 'b-input': BInputStub, 'b-icon': true },
  })
}

beforeEach(() => {
  jest.clearAllMocks()
})

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('ForgotPassword.vue — email validated in JS (no native required)', () => {

  it('the email input is not natively required', () => {
    const wrapper = mountForgot()
    expect(wrapper.find('[data-test="forgot-email"]').attributes('required')).toBeUndefined()
  })

  it('empty submit() blocks the API call and shows an inline error', async () => {
    const wrapper = mountForgot()
    wrapper.vm.email = ''

    wrapper.vm.submit()
    await flushPromises()

    expect(api.requestPasswordReset).not.toHaveBeenCalled()
    expect(wrapper.vm.error).toBe('Please enter your email address.')
  })

  it('a non-empty email calls the API and flips to the sent state', async () => {
    api.requestPasswordReset.mockResolvedValue({})
    const wrapper = mountForgot()
    wrapper.vm.email = 'user@example.com'

    wrapper.vm.submit()
    await flushPromises()

    expect(api.requestPasswordReset).toHaveBeenCalledWith({ email: 'user@example.com' })
    expect(wrapper.vm.error).toBe('')
    expect(wrapper.vm.sent).toBe(true)
  })

})
