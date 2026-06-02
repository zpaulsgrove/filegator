import api from '@/api/api'
import StepUpDialog from '@/views/partials/StepUpDialog.vue'

// 5-second cache so back-to-back dialog opens (e.g., admin clicks Edit then immediately Save)
// don't double-fetch /getuser. Module-scoped, not per-vm — fine because the cache is purely a
// freshness signal, not a security boundary.
let lastRefreshAt = 0
const REFRESH_TTL_MS = 5000

// Exposed for tests only — lets specs reset the cache without jest.resetModules().
export function _resetRefreshCache() {
  lastRefreshAt = 0
}

async function refreshUserIfStale(vm) {
  const now = Date.now()
  if (now - lastRefreshAt < REFRESH_TTL_MS) return
  try {
    const user = await api.getUser()
    vm.$store.commit('setUser', user)
    lastRefreshAt = now
  } catch (_) {
    // Refresh failed (network, expired session) — fall through with stale state.
    // The dialog will adapt to whatever's in the store; backend remains authoritative
    // on whether step-up is actually required.
  }
}

export default function withStepUp(vm, { actionDescription, dangerWarning = null, action }) {
  // Admin step-up is opt-out via the `step_up_auth` config flag (default on).
  // When disabled, skip the /getuser refresh and the dialog entirely and run
  // the action with no step-up fields — the backend gate is a matching no-op.
  // Only the admin flows (Users.vue, UserEdit.vue) call withStepUp, so this
  // mirrors the admin-only backend scope; self-service step-up is unaffected.
  const enabled = !(vm.$store.state.config && vm.$store.state.config.step_up_auth === false)
  if (!enabled) {
    return Promise.resolve(action({}))
  }

  // Refresh first, then open the dialog. Chain rather than wrapping `new Promise(async ...)`
  // — the async-executor pattern silently drops synchronous throws inside the executor.
  return refreshUserIfStale(vm).then(() => new Promise((resolve, reject) => {
    const mfaEnabled = !!(vm.$store.state.user && vm.$store.state.user.mfa_enabled)

    vm.$modal.open({
      parent: vm,
      component: StepUpDialog,
      hasModalCard: true,
      props: {
        actionDescription,
        dangerWarning,
        mfaEnabled,
        onConfirm: (stepUpFields) => action(stepUpFields),
      },
      events: {
        confirmed: (result) => resolve(result),
        cancel: () => {
          const err = new Error('Step-up cancelled')
          err.stepUpCancelled = true
          reject(err)
        },
        error: (err) => reject(err),
      },
    })
  }))
}

// Helper for callers that want a tidy `.catch` filter:
//   .catch(err => { if (isStepUpCancelled(err)) return; throw err })
//
// Returns a strict boolean (not the operand) so `=== true` / `.filter()`
// callers behave predictably.
export function isStepUpCancelled(err) {
  return !!(err && err.stepUpCancelled === true)
}
