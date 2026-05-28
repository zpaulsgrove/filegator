// Defensive mock: shared.js transitively imports api.js via store references
jest.mock('@/api/api', () => ({ __esModule: true, default: {} }))

import { shallowMount } from '@vue/test-utils'
import StepUpDialog from '@/views/partials/StepUpDialog.vue'

// ── Helpers ──────────────────────────────────────────────────────────────────

function flushPromises() {
  return new Promise(resolve => setTimeout(resolve, 0))
}

// ── Stubs ────────────────────────────────────────────────────────────────────

// Stub MfaStepUpForm so tests can assert on props without exercising the inner
// form implementation.
const MfaStepUpFormStub = {
  name: 'MfaStepUpForm',
  props: ['value', 'showCode', 'errors', 'autofocus'],
  template: '<div class="mfa-step-up-form-stub" />',
}

// b-notification stub that renders its slot content so we can assert on text.
const BNotificationStub = {
  name: 'b-notification',
  props: ['type', 'closable'],
  template: '<div class="b-notification-stub" :data-type="type"><slot /></div>',
}

// ── Mount helper ──────────────────────────────────────────────────────────────

function makeOnConfirm(result = {}) {
  return jest.fn().mockResolvedValue(result)
}

function mountDialog(propsData = {}, onConfirmFn = null) {
  const wrapper = shallowMount(StepUpDialog, {
    propsData: {
      actionDescription: 'Test action',
      mfaEnabled: false,
      onConfirm: onConfirmFn || makeOnConfirm(),
      ...propsData,
    },
    mocks: {
      lang: s => s,
    },
    stubs: {
      MfaStepUpForm: MfaStepUpFormStub,
      'b-notification': BNotificationStub,
    },
  })

  // Vue 2 quirk: $parent is the root wrapper by default; assign close() directly.
  // Provide $children so Vue's destroy() can splice us out without crashing.
  wrapper.vm.$parent = { close: jest.fn(), $children: [wrapper.vm] }

  return wrapper
}

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('StepUpDialog.vue', () => {

  // 1. actionDescription renders in the header
  it('renders actionDescription in header', () => {
    const wrapper = mountDialog({ actionDescription: 'Delete john' })
    expect(wrapper.find('.modal-card-head .modal-card-title').text()).toBe('Delete john')
  })

  // 2. XSS hardening — actionDescription rendered as text, not raw HTML
  it('XSS-hardens actionDescription — script tags are escaped, not executed', () => {
    const wrapper = mountDialog({ actionDescription: '<script>alert(1)<\/script>' })
    const html = wrapper.html()
    // Vue must escape the tag — the literal &lt;script&gt; must appear in the markup.
    expect(html).toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
    // There must be NO executable <script> element inside the component root.
    expect(wrapper.find('script').exists()).toBe(false)
  })

  // 3. Shows dangerWarning when provided
  it('shows dangerWarning when provided', () => {
    const wrapper = mountDialog({ dangerWarning: 'This permanently removes the user.' })
    // Find the warning notification (is-warning type)
    const notifications = wrapper.findAll('.b-notification-stub')
    const warningNotification = Array.from({ length: notifications.length }, (_, i) => notifications.at(i))
      .find(n => n.attributes('data-type') === 'is-warning')
    expect(warningNotification).toBeTruthy()
    expect(warningNotification.text()).toContain('This permanently removes the user.')
  })

  // 4. Hides dangerWarning when null
  it('hides dangerWarning when dangerWarning is null', () => {
    const wrapper = mountDialog({ dangerWarning: null })
    const notifications = wrapper.findAll('.b-notification-stub')
    const warningNotification = Array.from({ length: notifications.length }, (_, i) => notifications.at(i))
      .find(n => n.attributes('data-type') === 'is-warning')
    expect(warningNotification).toBeFalsy()
  })

  // 5. Password-only form when mfaEnabled=false
  it('shows password-only form when mfaEnabled=false', () => {
    const wrapper = mountDialog({ mfaEnabled: false })
    const form = wrapper.find('.mfa-step-up-form-stub')
    expect(form.exists()).toBe(true)
    // The stub renders as a Vue instance — check the parent's prop binding via vm
    const formVm = wrapper.find({ name: 'MfaStepUpForm' })
    expect(formVm.props('showCode')).toBe(false)
  })

  // 6. Password + code form when mfaEnabled=true
  it('shows password+code form when mfaEnabled=true', () => {
    const wrapper = mountDialog({ mfaEnabled: true })
    const formVm = wrapper.find({ name: 'MfaStepUpForm' })
    expect(formVm.props('showCode')).toBe(true)
  })

  // 7. Confirm button disabled until canSubmit is true
  it('Confirm button is disabled until canSubmit is true', async () => {
    const wrapper = mountDialog({ mfaEnabled: true })
    const confirmBtn = wrapper.findAll('button').wrappers.find(b => b.text() === 'Confirm')

    // Initially both password and code are empty → disabled
    expect(confirmBtn.attributes('disabled')).toBeTruthy()

    // Set password but leave code empty → still disabled
    wrapper.vm.form = { password: 'secret', code: '', useBackup: false }
    await wrapper.vm.$nextTick()
    expect(confirmBtn.attributes('disabled')).toBeTruthy()

    // Now set code as well → enabled
    wrapper.vm.form = { password: 'secret', code: '123456', useBackup: false }
    await wrapper.vm.$nextTick()
    expect(confirmBtn.attributes('disabled')).toBeFalsy()
  })

  // 8. Successful confirm: calls onConfirm with stepup_* fields, emits 'confirmed', closes
  it('successful confirm calls onConfirm with stepup_* fields, emits confirmed, closes', async () => {
    const onConfirm = jest.fn().mockResolvedValue({ ok: true })
    const wrapper = mountDialog({ mfaEnabled: true }, onConfirm)

    // Fill form
    wrapper.vm.form = { password: 'pass', code: '123456', useBackup: false }
    await wrapper.vm.$nextTick()

    wrapper.vm.confirm()
    await flushPromises()

    expect(onConfirm).toHaveBeenCalledTimes(1)
    expect(onConfirm).toHaveBeenCalledWith({
      stepup_password: 'pass',
      stepup_code: '123456',
      stepup_use_backup: false,
    })
    expect(wrapper.emitted('confirmed')).toBeTruthy()
    expect(wrapper.emitted('confirmed')[0][0]).toEqual({ ok: true })
    expect(wrapper.vm.$parent.close).toHaveBeenCalledTimes(1)
  })

  // 9. 422 with object body maps field errors (password) and stays open
  it('422 with object body maps password error and stays open', async () => {
    const onConfirm = jest.fn().mockRejectedValue({
      response: {
        status: 422,
        data: { data: { password: 'Wrong password' } },
      },
    })
    const wrapper = mountDialog({ mfaEnabled: false }, onConfirm)

    wrapper.vm.form = { password: 'wrong', code: '', useBackup: false }
    await wrapper.vm.$nextTick()

    wrapper.vm.confirm()
    await flushPromises()

    // Password error surfaces on errors prop of the form component
    expect(wrapper.vm.errors.password).toBe('Wrong password')
    // Password field cleared on wrong password
    expect(wrapper.vm.form.password).toBe('')
    // Modal NOT closed — form is still present
    expect(wrapper.vm.$parent.close).not.toHaveBeenCalled()
    expect(wrapper.find('.mfa-step-up-form-stub').exists()).toBe(true)
  })

  // 10. 422 with object body maps code field error
  it('422 with object body maps code error and stays open', async () => {
    const onConfirm = jest.fn().mockRejectedValue({
      response: {
        status: 422,
        data: { data: { code: 'Invalid code' } },
      },
    })
    const wrapper = mountDialog({ mfaEnabled: true }, onConfirm)

    wrapper.vm.form = { password: 'pass', code: '000000', useBackup: false }
    await wrapper.vm.$nextTick()

    wrapper.vm.confirm()
    await flushPromises()

    expect(wrapper.vm.errors.code).toBe('Invalid code')
    // Code field cleared on wrong code
    expect(wrapper.vm.form.code).toBe('')
    expect(wrapper.vm.$parent.close).not.toHaveBeenCalled()
    expect(wrapper.find('.mfa-step-up-form-stub').exists()).toBe(true)
  })

  // 11. 429 disables Confirm and shows lockout banner
  it('429 disables Confirm and shows lockout banner', async () => {
    const onConfirm = jest.fn().mockRejectedValue({
      response: {
        status: 429,
        data: { data: 'Not Allowed' },
      },
    })
    const wrapper = mountDialog({ mfaEnabled: true }, onConfirm)

    wrapper.vm.form = { password: 'pass', code: '123456', useBackup: false }
    await wrapper.vm.$nextTick()

    wrapper.vm.confirm()
    await flushPromises()

    expect(wrapper.vm.lockedOut).toBe(true)

    // Lockout banner visible
    const notifications = wrapper.findAll('.b-notification-stub')
    const dangerNotification = Array.from({ length: notifications.length }, (_, i) => notifications.at(i))
      .find(n => n.attributes('data-type') === 'is-danger')
    expect(dangerNotification).toBeTruthy()
    expect(dangerNotification.text()).toContain('Too many attempts')

    // Confirm button disabled (lockedOut=true)
    const confirmBtn = wrapper.findAll('button').wrappers.find(b => b.text() === 'Confirm')
    expect(confirmBtn.attributes('disabled')).toBeTruthy()

    // Form hidden when locked out
    expect(wrapper.find('.mfa-step-up-form-stub').exists()).toBe(false)
  })

  // 12. Non-422/429 error emits 'error' and closes
  it('non-422/429 error emits error and closes', async () => {
    const serverError = { response: { status: 500 } }
    const onConfirm = jest.fn().mockRejectedValue(serverError)
    const wrapper = mountDialog({ mfaEnabled: false }, onConfirm)

    wrapper.vm.form = { password: 'pass', code: '', useBackup: false }
    await wrapper.vm.$nextTick()

    wrapper.vm.confirm()
    await flushPromises()

    expect(wrapper.vm.$parent.close).toHaveBeenCalledTimes(1)
    expect(wrapper.emitted('error')).toBeTruthy()
    expect(wrapper.emitted('error')[0][0]).toBe(serverError)
  })

  // 13. Cancel emits 'cancel' and closes
  it('Cancel emits cancel and closes', () => {
    const wrapper = mountDialog()

    const cancelBtn = wrapper.findAll('button').wrappers.find(b => b.text() === 'Cancel')
    cancelBtn.trigger('click')

    expect(wrapper.emitted('cancel')).toBeTruthy()
    expect(wrapper.vm.$parent.close).toHaveBeenCalledTimes(1)
  })

  // 14. R-1: beforeDestroy fallback — modal closes without a button click
  //     (backdrop, Escape key, programmatic close, parent unmount) emit cancel.
  it('emits cancel on beforeDestroy when no event has fired (backdrop/Escape/unmount path)', () => {
    const wrapper = mountDialog()

    // Simulate destruction without any button having been clicked first.
    wrapper.destroy()

    expect(wrapper.emitted('cancel')).toBeTruthy()
    expect(wrapper.emitted('cancel').length).toBe(1)
  })

  // 15. R-1: beforeDestroy is a no-op once Confirm has already settled the dialog.
  //     Otherwise the helper's Promise would resolve AND then reject.
  it('does NOT double-emit cancel after a successful confirm settles the dialog', async () => {
    const onConfirmFn = makeOnConfirm({ ok: true })
    const wrapper = mountDialog({ mfaEnabled: true }, onConfirmFn)
    wrapper.vm.form = { password: 'pw', code: '123456', useBackup: false }
    const confirmBtn = wrapper.findAll('button').wrappers.find(b => b.text() === 'Confirm')
    confirmBtn.trigger('click')
    await flushPromises()

    // Confirmed was emitted once.
    expect(wrapper.emitted('confirmed')).toBeTruthy()
    expect(wrapper.emitted('confirmed').length).toBe(1)
    // No cancel before destroy.
    expect(wrapper.emitted('cancel')).toBeFalsy()

    // Destroy AFTER confirm — beforeDestroy should NOT add a cancel emission.
    wrapper.destroy()
    expect(wrapper.emitted('cancel')).toBeFalsy()
  })

  // 16. R-1: same guard for the error path — emit('error') already settled.
  it('does NOT double-emit cancel after an error has settled the dialog', async () => {
    const onConfirmFn = jest.fn().mockRejectedValue({ response: { status: 500 } })
    const wrapper = mountDialog({ mfaEnabled: true }, onConfirmFn)
    wrapper.vm.form = { password: 'pw', code: '123456', useBackup: false }
    wrapper.findAll('button').wrappers.find(b => b.text() === 'Confirm').trigger('click')
    await flushPromises()

    expect(wrapper.emitted('error')).toBeTruthy()
    expect(wrapper.emitted('cancel')).toBeFalsy()

    wrapper.destroy()
    expect(wrapper.emitted('cancel')).toBeFalsy()
  })
})
