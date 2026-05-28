/**
 * UserEdit.vue — step-up integration tests (R-3)
 *
 * Pins that save() and resetMfa() route through withStepUp with the right
 * actionDescription / dangerWarning, forward step-up creds to the api method,
 * silence cancel-sentinel rejections, and surface non-step-up 422 field errors
 * back into formErrors.
 */

jest.mock('@/api/api', () => ({
  __esModule: true,
  default: {
    storeUser: jest.fn(),
    updateUser: jest.fn(),
    adminResetMfa: jest.fn(),
  },
}))

jest.mock('@/utils/withStepUp', () => ({
  __esModule: true,
  default: jest.fn(),
  isStepUpCancelled: jest.fn(err => !!(err && err.stepUpCancelled === true)),
}))

// Stub out the Tree picker — it pulls in a heavier dependency tree.
jest.mock('@/views/partials/Tree', () => ({ __esModule: true, default: { name: 'Tree', render: h => h('div') } }))

import { shallowMount } from '@vue/test-utils'
import UserEdit from '@/views/partials/UserEdit.vue'

const api = require('@/api/api').default
const withStepUpModule = require('@/utils/withStepUp')

function flushPromises() {
  return new Promise(resolve => setTimeout(resolve, 0))
}

function mountUserEdit(action = 'edit', userOverrides = {}) {
  const user = {
    role: 'user',
    name: 'John Doe',
    username: 'john@example.com',
    email: 'john@example.com',
    homedir: '/john',
    homedirs: ['/john'],
    permissions: ['read', 'write'],
    ...userOverrides,
  }

  const wrapper = shallowMount(UserEdit, {
    propsData: { user, action },
    mocks: {
      lang: (s, ...rest) => rest.length ? s + ' ' + rest.join(' ') : s,
      handleError: jest.fn(),
      $toast: { open: jest.fn() },
    },
    stubs: {
      Tree: true,
      'b-modal': true,
      'b-field': true,
      'b-input': true,
      'b-select': true,
      'b-checkbox': true,
      'b-icon': true,
      'b-button': true,
    },
  })

  // Provide $parent.close() — Vue 2 quirk: $parent isn't overridable via mocks.
  wrapper.vm.$parent = { close: jest.fn(), $children: [wrapper.vm] }
  return wrapper
}

beforeEach(() => {
  jest.clearAllMocks()
})

describe('UserEdit.vue — step-up integration on save()', () => {

  it('save() with action=edit opens withStepUp with the "Update user" description (no dangerWarning)', () => {
    withStepUpModule.default.mockReturnValue(new Promise(() => {}))
    const wrapper = mountUserEdit('edit')

    wrapper.vm.save()

    expect(withStepUpModule.default).toHaveBeenCalledTimes(1)
    const [, opts] = withStepUpModule.default.mock.calls[0]
    expect(opts.actionDescription).toContain('Update user')
    expect(opts.actionDescription).toContain('john@example.com')
    expect(opts.dangerWarning).toBeFalsy()
    expect(typeof opts.action).toBe('function')
  })

  it('save() with action=add opens withStepUp with the "Create user" description', () => {
    withStepUpModule.default.mockReturnValue(new Promise(() => {}))
    const wrapper = mountUserEdit('add', { username: '', name: '' })
    wrapper.vm.formFields.username = 'newone@example.com'

    wrapper.vm.save()

    const [, opts] = withStepUpModule.default.mock.calls[0]
    expect(opts.actionDescription).toContain('Create user')
    expect(opts.actionDescription).toContain('newone@example.com')
  })

  it('save() action callback forwards step-up creds to api.updateUser on edit', () => {
    withStepUpModule.default.mockReturnValue(new Promise(() => {}))
    api.updateUser.mockResolvedValue({})
    const wrapper = mountUserEdit('edit')

    wrapper.vm.save()
    const [, opts] = withStepUpModule.default.mock.calls[0]
    opts.action({ stepup_password: 'pw', stepup_code: '123456', stepup_use_backup: false })

    expect(api.updateUser).toHaveBeenCalledTimes(1)
    const call = api.updateUser.mock.calls[0][0]
    expect(call.stepup_password).toBe('pw')
    expect(call.stepup_code).toBe('123456')
    expect(call.stepup_use_backup).toBe(false)
    expect(call.key).toBe('john@example.com')  // URL slug for updateUser
  })

  it('save() action callback routes to api.storeUser when action=add (and key field is absent)', () => {
    withStepUpModule.default.mockReturnValue(new Promise(() => {}))
    api.storeUser.mockResolvedValue({})
    const wrapper = mountUserEdit('add', { username: '', name: '' })
    wrapper.vm.formFields.username = 'fresh@example.com'
    wrapper.vm.formFields.password = 'pw123'

    wrapper.vm.save()
    const [, opts] = withStepUpModule.default.mock.calls[0]
    opts.action({ stepup_password: 'admin-pw', stepup_code: '000000', stepup_use_backup: false })

    expect(api.storeUser).toHaveBeenCalledTimes(1)
    const call = api.storeUser.mock.calls[0][0]
    expect(call.username).toBe('fresh@example.com')
    expect(call.stepup_password).toBe('admin-pw')
    // R-? cleanup — the conditional spread keeps `key` off the add payload.
    expect(call.key).toBeUndefined()
  })

  it('save() success emits updated and closes the modal', async () => {
    withStepUpModule.default.mockResolvedValue({ ok: true })
    const wrapper = mountUserEdit('edit')

    wrapper.vm.save()
    await flushPromises()

    expect(wrapper.emitted('updated')).toBeTruthy()
    expect(wrapper.vm.$parent.close).toHaveBeenCalledTimes(1)
    expect(wrapper.vm.$toast.open).toHaveBeenCalledWith(expect.objectContaining({ type: 'is-success' }))
  })

  it('save() cancel-sentinel rejection is silenced — no toast, no handleError, modal stays open', async () => {
    const cancelErr = Object.assign(new Error('Step-up cancelled'), { stepUpCancelled: true })
    withStepUpModule.default.mockRejectedValue(cancelErr)
    const wrapper = mountUserEdit('edit')

    wrapper.vm.save()
    await flushPromises()

    expect(wrapper.vm.handleError).not.toHaveBeenCalled()
    expect(wrapper.vm.$toast.open).not.toHaveBeenCalled()
    expect(wrapper.vm.$parent.close).not.toHaveBeenCalled()
    expect(wrapper.emitted('updated')).toBeFalsy()
  })

  it('save() non-step-up 422 with field errors maps into formErrors', async () => {
    withStepUpModule.default.mockRejectedValue({
      response: {
        status: 422,
        data: {
          data: { username: 'Username already taken' },
          username: 'Username already taken',
        },
      },
    })
    const wrapper = mountUserEdit('edit')

    wrapper.vm.save()
    await flushPromises()

    expect(wrapper.vm.formErrors.username).toBe('Username already taken')
    expect(wrapper.vm.handleError).not.toHaveBeenCalled()
  })

  it('save() non-axios error routes to handleError without crashing the formErrors loop', async () => {
    // The fixed bug: a network/programmatic error (no .response) used to
    // call handleError AND fall through into _.forEach(errors.response.data...)
    // crashing on the next line. Now the catch returns after handleError.
    const networkErr = new Error('Network down')
    withStepUpModule.default.mockRejectedValue(networkErr)
    const wrapper = mountUserEdit('edit')

    wrapper.vm.save()
    await flushPromises()

    expect(wrapper.vm.handleError).toHaveBeenCalledWith(networkErr)
    // No formErrors mutation since there was no response body.
    expect(Object.keys(wrapper.vm.formErrors)).toHaveLength(0)
  })
})

describe('UserEdit.vue — step-up integration on resetMfa()', () => {

  it('resetMfa() opens withStepUp with the danger warning', () => {
    withStepUpModule.default.mockReturnValue(new Promise(() => {}))
    const wrapper = mountUserEdit('edit')

    wrapper.vm.resetMfa()

    expect(withStepUpModule.default).toHaveBeenCalledTimes(1)
    const [, opts] = withStepUpModule.default.mock.calls[0]
    expect(opts.actionDescription).toContain('Reset MFA for')
    expect(opts.actionDescription).toContain('john@example.com')
    expect(opts.dangerWarning).toContain('re-enroll')
  })

  it('resetMfa() forwards creds to api.adminResetMfa', () => {
    withStepUpModule.default.mockReturnValue(new Promise(() => {}))
    api.adminResetMfa.mockResolvedValue({})
    const wrapper = mountUserEdit('edit')

    wrapper.vm.resetMfa()
    const [, opts] = withStepUpModule.default.mock.calls[0]
    opts.action({ stepup_password: 'pw', stepup_code: '111111', stepup_use_backup: false })

    expect(api.adminResetMfa).toHaveBeenCalledTimes(1)
    expect(api.adminResetMfa.mock.calls[0][0]).toEqual({
      username: 'john@example.com',
      stepup_password: 'pw',
      stepup_code: '111111',
      stepup_use_backup: false,
    })
  })

  it('resetMfa() cancel-sentinel rejection is silenced', async () => {
    const cancelErr = Object.assign(new Error('cancelled'), { stepUpCancelled: true })
    withStepUpModule.default.mockRejectedValue(cancelErr)
    const wrapper = mountUserEdit('edit')

    wrapper.vm.resetMfa()
    await flushPromises()

    expect(wrapper.vm.handleError).not.toHaveBeenCalled()
    expect(wrapper.vm.$parent.close).not.toHaveBeenCalled()
  })
})
