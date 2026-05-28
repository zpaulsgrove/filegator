jest.mock('@/api/api', () => ({ __esModule: true, default: { getUser: jest.fn().mockResolvedValue({}) } }))

import api from '@/api/api'
import withStepUp, { isStepUpCancelled, _resetRefreshCache } from '@/utils/withStepUp'
import StepUpDialog from '@/views/partials/StepUpDialog.vue'

// ── Helpers ──────────────────────────────────────────────────────────────────

function flushPromises() {
  return new Promise(resolve => setTimeout(resolve, 0))
}

function makeVm(userOverrides = {}) {
  return {
    $modal: { open: jest.fn() },
    $store: {
      state: { user: { mfa_enabled: false, ...userOverrides } },
      commit: jest.fn(),
    },
  }
}

function captureModalArgs(vm) {
  return vm.$modal.open.mock.calls[0][0]
}

// ── Setup ────────────────────────────────────────────────────────────────────

beforeEach(() => {
  // Reset the 5-second cache so each test starts with a stale timestamp.
  // This is cheaper and more explicit than jest.resetModules() — it reuses
  // the same module instance while zeroing the module-level `lastRefreshAt`.
  _resetRefreshCache()
  api.getUser.mockClear()
  api.getUser.mockResolvedValue({})
})

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('withStepUp', () => {

  // 1. Opens StepUpDialog with the expected props
  it('opens StepUpDialog with the expected props', async () => {
    const vm = makeVm()
    const action = jest.fn().mockResolvedValue('done')

    withStepUp(vm, { actionDescription: 'Delete user', dangerWarning: 'Irreversible!', action })
    await flushPromises()

    expect(vm.$modal.open).toHaveBeenCalledTimes(1)
    const args = captureModalArgs(vm)
    expect(args.component).toBe(StepUpDialog)
    expect(args.hasModalCard).toBe(true)
    expect(args.parent).toBe(vm)
    expect(args.props.actionDescription).toBe('Delete user')
    expect(args.props.dangerWarning).toBe('Irreversible!')
  })

  // 2a. mfaEnabled prop reflects store state — true
  it('mfaEnabled prop is true when store user has mfa_enabled=true', async () => {
    const vm = makeVm({ mfa_enabled: true })
    withStepUp(vm, { actionDescription: 'Test', action: jest.fn() })
    await flushPromises()

    const args = captureModalArgs(vm)
    expect(args.props.mfaEnabled).toBe(true)
  })

  // 2b. mfaEnabled prop reflects store state — false
  it('mfaEnabled prop is false when store user has mfa_enabled=false', async () => {
    const vm = makeVm({ mfa_enabled: false })
    withStepUp(vm, { actionDescription: 'Test', action: jest.fn() })
    await flushPromises()

    const args = captureModalArgs(vm)
    expect(args.props.mfaEnabled).toBe(false)
  })

  // 3. Refreshes user state on first call
  it('refreshes user state on first call and commits to store', async () => {
    const freshUser = { mfa_enabled: true, role: 'admin' }
    api.getUser.mockResolvedValue(freshUser)

    const vm = makeVm()
    withStepUp(vm, { actionDescription: 'Test', action: jest.fn() })
    await flushPromises()

    expect(api.getUser).toHaveBeenCalledTimes(1)
    expect(vm.$store.commit).toHaveBeenCalledWith('setUser', freshUser)
  })

  // 4. Does NOT refresh on second call within 5s
  it('does NOT refresh again on second call within 5s', async () => {
    const vm = makeVm()
    const action = jest.fn()

    withStepUp(vm, { actionDescription: 'First', action })
    await flushPromises()

    withStepUp(vm, { actionDescription: 'Second', action })
    await flushPromises()

    expect(api.getUser).toHaveBeenCalledTimes(1)
  })

  // 5. DOES refresh again after TTL expires
  it('refreshes again after the 5s TTL has passed', async () => {
    const vm = makeVm()
    const action = jest.fn()

    // First call — sets lastRefreshAt to now.
    withStepUp(vm, { actionDescription: 'First', action })
    await flushPromises()
    expect(api.getUser).toHaveBeenCalledTimes(1)

    // Rewind the cache by resetting it (simulates 5s+ elapsing).
    _resetRefreshCache()

    // Second call — cache is stale again, should re-fetch.
    withStepUp(vm, { actionDescription: 'Second', action })
    await flushPromises()

    expect(api.getUser).toHaveBeenCalledTimes(2)
  })

  // 6. Refresh error is swallowed; dialog still opens
  it('swallows refresh errors and still opens the dialog', async () => {
    api.getUser.mockRejectedValue(new Error('Network error'))

    const vm = makeVm()
    let thrownBeforeOpen = null

    // The helper promise should not reject at this point (dialog hasn't emitted yet)
    const helperPromise = withStepUp(vm, { actionDescription: 'Test', action: jest.fn() })
    helperPromise.catch(err => { thrownBeforeOpen = err })

    await flushPromises()

    // Dialog still opened despite refresh failure
    expect(vm.$modal.open).toHaveBeenCalledTimes(1)
    // No rejection yet — the promise is still pending (awaiting dialog events)
    expect(thrownBeforeOpen).toBeNull()
  })

  // 7. Resolves on 'confirmed' event with the result
  it('resolves with the result when dialog emits confirmed', async () => {
    const vm = makeVm()
    const promise = withStepUp(vm, { actionDescription: 'Test', action: jest.fn() })
    await flushPromises()

    const { events } = captureModalArgs(vm)
    events.confirmed({ ok: true, data: 'payload' })

    await expect(promise).resolves.toEqual({ ok: true, data: 'payload' })
  })

  // 8. Rejects with cancel-sentinel on 'cancel' event
  it('rejects with cancel-sentinel error on cancel event', async () => {
    const vm = makeVm()
    const promise = withStepUp(vm, { actionDescription: 'Test', action: jest.fn() })
    await flushPromises()

    const { events } = captureModalArgs(vm)
    events.cancel()

    await expect(promise).rejects.toMatchObject({
      message: 'Step-up cancelled',
      stepUpCancelled: true,
    })
  })

  // 9. Rejects with raw error on 'error' event
  it('rejects with the raw error when dialog emits error', async () => {
    const vm = makeVm()
    const promise = withStepUp(vm, { actionDescription: 'Test', action: jest.fn() })
    await flushPromises()

    const serverError = new Error('Internal Server Error')
    const { events } = captureModalArgs(vm)
    events.error(serverError)

    const caught = await promise.catch(err => err)
    expect(caught).toBe(serverError)
    expect(caught.stepUpCancelled).toBeUndefined()
  })

  // 10. isStepUpCancelled helper
  it('isStepUpCancelled returns true for cancel-sentinel errors, false otherwise', async () => {
    const vm = makeVm()
    const promise = withStepUp(vm, { actionDescription: 'Test', action: jest.fn() })
    await flushPromises()

    const { events } = captureModalArgs(vm)
    events.cancel()

    const cancelErr = await promise.catch(err => err)
    expect(isStepUpCancelled(cancelErr)).toBe(true)

    // Other errors — helper returns a falsy value (false/null/undefined) for non-sentinel errors
    expect(isStepUpCancelled(new Error('something else'))).toBeFalsy()
    expect(isStepUpCancelled({ response: { status: 500 } })).toBeFalsy()
    expect(isStepUpCancelled(null)).toBeFalsy()
    expect(isStepUpCancelled(undefined)).toBeFalsy()
  })
})
