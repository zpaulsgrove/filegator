// Defensive mock: shared.js transitively imports api.js via store references
jest.mock('@/api/api', () => ({ __esModule: true, default: {} }))

import { shallowMount } from '@vue/test-utils'
import MfaStepUpForm from '@/views/partials/MfaStepUpForm.vue'

// Default value shape used across tests
const defaultValue = { password: '', code: '', useBackup: false }

function mountForm(propsData = {}) {
  return shallowMount(MfaStepUpForm, {
    propsData: {
      value: { ...defaultValue },
      ...propsData,
    },
    mocks: {
      lang: (s) => s,
    },
    stubs: {
      'b-field': {
        template: '<div class="b-field-stub"><slot /></div>',
        props: ['label', 'type', 'message'],
      },
      'b-input': {
        template: '<input class="b-input-stub" />',
        props: ['value', 'type', 'placeholder', 'autocomplete', 'passwordReveal'],
      },
    },
  })
}

describe('MfaStepUpForm.vue', () => {
  it('renders password-only mode when showCode=false', () => {
    const wrapper = mountForm({ showCode: false })

    // Password field must be present
    const inputs = wrapper.findAll('input.b-input-stub')
    expect(inputs.length).toBe(1)

    // Code input and toggle link must not be present
    expect(wrapper.find('.step-up-toggle').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('Use a backup code instead')
  })

  it('renders password + code + backup toggle when showCode=true', () => {
    const wrapper = mountForm({ showCode: true })

    // Both password and code inputs
    const inputs = wrapper.findAll('input.b-input-stub')
    expect(inputs.length).toBe(2)

    // Toggle link is visible
    expect(wrapper.find('.step-up-toggle').exists()).toBe(true)
    expect(wrapper.text()).toContain('Use a backup code instead')
  })

  it('hides the password field when showPassword=false (code-only reuse)', () => {
    // The change-password box owns its own "current password" field and reuses
    // this component only for the code + backup toggle.
    const wrapper = mountForm({ showPassword: false, showCode: true })

    const inputs = wrapper.findAll('input.b-input-stub')
    expect(inputs.length).toBe(1)
    expect(wrapper.text()).not.toContain('Your password')
    // Code input + backup toggle still render.
    expect(wrapper.find('.step-up-toggle').exists()).toBe(true)
  })

  it('emits input event when password changes with correct shape', () => {
    const wrapper = mountForm({ showCode: true })

    // Trigger the @input on the first b-input (password)
    const bInputs = wrapper.findAll(
      { name: 'b-input' } in wrapper.vm.$options.components
        ? { name: 'b-input' }
        : 'input.b-input-stub'
    )

    // Use vm.$emit pathway: find the first b-input-stub and trigger its input
    const passwordInput = wrapper.findAll('input.b-input-stub').at(0)
    passwordInput.trigger('input')

    // Instead rely on calling the method directly, which is what the template
    // calls via @input="updateField('password', $event)"
    wrapper.vm.updateField('password', 'typed')

    const emitted = wrapper.emitted('input')
    expect(emitted).toBeTruthy()
    const lastCall = emitted[emitted.length - 1][0]
    expect(lastCall).toEqual({ password: 'typed', code: '', useBackup: false })
  })

  it('emits input event when code changes with correct shape', () => {
    const wrapper = mountForm({ showCode: true })

    wrapper.vm.updateField('code', '123456')

    const emitted = wrapper.emitted('input')
    expect(emitted).toBeTruthy()
    const lastCall = emitted[emitted.length - 1][0]
    expect(lastCall).toEqual({ password: '', code: '123456', useBackup: false })
  })

  // R-4: per-keystroke JS toUpperCase used to force Vue to re-write the input
  // DOM, moving the cursor to the end on mid-string edits. We now rely on
  // CSS text-transform: uppercase for the visual and let the backend
  // normalise case at verify time. The emitted value is whatever the user
  // typed.
  it('passes backup-code input through verbatim (CSS handles uppercase display)', () => {
    const wrapper = mountForm({
      value: { password: '', code: '', useBackup: true },
      showCode: true,
    })

    wrapper.vm.onCodeInput('abcde-12345')

    const emitted = wrapper.emitted('input')
    expect(emitted).toBeTruthy()
    const lastCall = emitted[emitted.length - 1][0]
    expect(lastCall.code).toBe('abcde-12345')
  })

  it('does not transform code input when useBackup=false', () => {
    const wrapper = mountForm({
      value: { password: '', code: '', useBackup: false },
      showCode: true,
    })

    wrapper.vm.onCodeInput('123456')

    const emitted = wrapper.emitted('input')
    expect(emitted).toBeTruthy()
    const lastCall = emitted[emitted.length - 1][0]
    expect(lastCall.code).toBe('123456')
  })

  it('toggleBackup flips useBackup to true and clears code', () => {
    const wrapper = mountForm({
      value: { password: 'secret', code: '999999', useBackup: false },
      showCode: true,
    })

    wrapper.vm.toggleBackup()

    const emitted = wrapper.emitted('input')
    expect(emitted).toBeTruthy()
    const lastCall = emitted[emitted.length - 1][0]
    expect(lastCall.useBackup).toBe(true)
    expect(lastCall.code).toBe('')
    // Password should be preserved
    expect(lastCall.password).toBe('secret')
  })

  it('errors prop renders inline danger messages', () => {
    const wrapper = shallowMount(MfaStepUpForm, {
      propsData: {
        value: { ...defaultValue },
        errors: { password: 'Wrong password', code: null },
        showCode: false,
      },
      mocks: { lang: (s) => s },
      stubs: {
        'b-field': {
          template: '<div class="b-field-stub">{{ message }}<slot /></div>',
          props: ['label', 'type', 'message'],
        },
        'b-input': {
          template: '<input class="b-input-stub" />',
          props: ['value', 'type', 'placeholder', 'autocomplete', 'passwordReveal'],
        },
      },
    })

    expect(wrapper.text()).toContain('Wrong password')
  })

  it('emits clear-error when updating a field that has an existing error', () => {
    const wrapper = mountForm({
      errors: { password: 'Wrong password', code: null },
    })

    wrapper.vm.updateField('password', 'newval')

    expect(wrapper.emitted('clear-error')).toBeTruthy()
    expect(wrapper.emitted('clear-error')[0]).toEqual(['password'])
  })

  // E2E hook: when `testid` is set, per-instance data-test attributes are
  // emitted on the code/password inputs and the backup toggle so specs can
  // target a single instance unambiguously (the component renders in several
  // places). Absent ⇒ no data-test attribute at all.
  it('emits per-instance data-test hooks when testid is set', () => {
    const wrapper = mountForm({ testid: 'x', showPassword: true, showCode: true })

    expect(wrapper.find('[data-test="x-code"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="x-password"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="x-backup-toggle"]').exists()).toBe(true)
  })

  it('emits no data-test attribute when testid is absent', () => {
    const wrapper = mountForm({ showPassword: true, showCode: true })

    expect(wrapper.find('[data-test]').exists()).toBe(false)
  })
})
