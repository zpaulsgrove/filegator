/**
 * Users.vue — admin user-list step-up integration tests
 *
 * Pins the new wire-up of `remove(user)` through the `withStepUp` helper.
 * Before the step-up dialog landed, `remove` went through `$dialog.confirm` →
 * `api.deleteUser`. Now it goes through `withStepUp` → dialog (with
 * dangerWarning) → `api.deleteUser({ username, ...creds })`.
 */

import { shallowMount } from '@vue/test-utils'
import Users from '@/views/Users.vue'

// ── Module mocks ─────────────────────────────────────────────────────────────

jest.mock('@/api/api', () => ({
  __esModule: true,
  default: {
    listUsers: jest.fn().mockResolvedValue([]),
    deleteUser: jest.fn(),
  },
}))

// withStepUp is the key dependency under test. Replace it with a deterministic
// jest mock so we can assert on its arguments and control its outcome.
jest.mock('@/utils/withStepUp', () => ({
  __esModule: true,
  default: jest.fn(),
  isStepUpCancelled: jest.fn(err => !!(err && err.stepUpCancelled === true)),
}))

const api = require('@/api/api').default
const withStepUpModule = require('@/utils/withStepUp')

// ── Helpers ──────────────────────────────────────────────────────────────────

function flushPromises() {
  return new Promise(resolve => setTimeout(resolve, 0))
}

function mountUsers() {
  return shallowMount(Users, {
    mocks: {
      $store: {
        state: {
          user: { name: 'Admin', role: 'admin', mfa_enabled: true },
          config: { pagination: [10, 25, 50] },
        },
        commit: jest.fn(),
      },
      $router: { push: jest.fn() },
      $route: {},
      $modal: { open: jest.fn() },
      $dialog: { confirm: jest.fn() },
      $toast: { open: jest.fn() },
      lang: (s, ...rest) => rest.length ? s + ' ' + rest.join(' ') : s,
      handleError: jest.fn(),
      is: () => true,
      can: () => true,
    },
    stubs: {
      Menu: true,
      UserEdit: true,
      Pagination: true,
      'b-table': true,
      'b-table-column': true,
      'b-checkbox': true,
      'b-icon': true,
      'b-tooltip': true,
      'b-tag': true,
      'b-input': true,
      'b-field': true,
    },
  })
}

// ── Tests ─────────────────────────────────────────────────────────────────────

beforeEach(() => {
  jest.clearAllMocks()
})

describe('Users.vue — step-up integration on remove()', () => {

  it('remove() opens withStepUp with the deletion danger warning', async () => {
    withStepUpModule.default.mockReturnValue(new Promise(() => {})) // never resolves
    const wrapper = mountUsers()
    await flushPromises() // let api.listUsers settle first
    wrapper.vm.users = [{ username: 'mike@example.com' }]

    wrapper.vm.remove({ username: 'mike@example.com' })

    expect(withStepUpModule.default).toHaveBeenCalledTimes(1)
    const opts = withStepUpModule.default.mock.calls[0][1]
    expect(opts.actionDescription).toContain('Delete user')
    expect(opts.actionDescription).toContain('mike@example.com')
    expect(opts.dangerWarning).toContain('permanently removes')
    expect(typeof opts.action).toBe('function')
  })

  it('action callback forwards step-up creds to api.deleteUser', async () => {
    withStepUpModule.default.mockReturnValue(new Promise(() => {}))
    api.deleteUser.mockResolvedValue({})
    const wrapper = mountUsers()
    await flushPromises()
    wrapper.vm.users = [{ username: 'mike@example.com' }]

    wrapper.vm.remove({ username: 'mike@example.com' })

    const opts = withStepUpModule.default.mock.calls[0][1]
    opts.action({ stepup_password: 'pw', stepup_code: '123456', stepup_use_backup: false })

    expect(api.deleteUser).toHaveBeenCalledWith({
      username: 'mike@example.com',
      stepup_password: 'pw',
      stepup_code: '123456',
      stepup_use_backup: false,
    })
  })

  it('successful step-up removes the user from the local array and toasts success', async () => {
    withStepUpModule.default.mockResolvedValue({})
    const wrapper = mountUsers()
    await flushPromises()
    wrapper.vm.users = [
      { username: 'mike@example.com' },
      { username: 'alice@example.com' },
    ]

    wrapper.vm.remove({ username: 'mike@example.com' })
    await flushPromises()

    expect(wrapper.vm.users.map(u => u.username)).toEqual(['alice@example.com'])
    expect(wrapper.vm.$toast.open).toHaveBeenCalledWith(expect.objectContaining({
      type: 'is-success',
    }))
  })

  it('cancel-sentinel rejection is silenced — no toast, no handleError', async () => {
    const cancelErr = Object.assign(new Error('Step-up cancelled'), { stepUpCancelled: true })
    withStepUpModule.default.mockRejectedValue(cancelErr)
    const wrapper = mountUsers()
    await flushPromises()
    wrapper.vm.users = [{ username: 'mike@example.com' }]

    wrapper.vm.remove({ username: 'mike@example.com' })
    await flushPromises()

    expect(wrapper.vm.handleError).not.toHaveBeenCalled()
    // User stays in the list — delete didn't happen.
    expect(wrapper.vm.users).toHaveLength(1)
  })

  it('non-cancel error routes to handleError', async () => {
    const realErr = new Error('Network down')
    withStepUpModule.default.mockRejectedValue(realErr)
    const wrapper = mountUsers()
    await flushPromises()
    wrapper.vm.users = [{ username: 'mike@example.com' }]

    wrapper.vm.remove({ username: 'mike@example.com' })
    await flushPromises()

    expect(wrapper.vm.handleError).toHaveBeenCalledWith(realErr)
    expect(wrapper.vm.users).toHaveLength(1)
  })
})
