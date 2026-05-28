import api from '@/api/api'
import StepUpDialog from '@/views/partials/StepUpDialog.vue'

export const STEP_UP_CANCELLED = Symbol('STEP_UP_CANCELLED')

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
  return new Promise(async (resolve, reject) => {
    // Step 1: refresh user state (best-effort) so mfa_enabled is fresh.
    await refreshUserIfStale(vm)

    const mfaEnabled = !!(vm.$store.state.user && vm.$store.state.user.mfa_enabled)

    // Step 2: open the dialog.
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
          err.cancelToken = STEP_UP_CANCELLED
          reject(err)
        },
        error: (err) => reject(err),
      },
    })
  })
}

// Helper for callers that want a tidy `.catch` filter:
//   .catch(err => { if (isStepUpCancelled(err)) return; throw err })
export function isStepUpCancelled(err) {
  return err && (err.stepUpCancelled === true || err.cancelToken === STEP_UP_CANCELLED)
}
