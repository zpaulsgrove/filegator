import api from '../api/api'

/**
 * True when a user must be routed to the picker before file operations
 * can proceed. Shared by main.js bootstrap, router.beforeEach, and
 * SelectFolder.vue's defensive mount guard so the three sites can't drift.
 */
export function needsFolderPicker(user) {
  if (!user) return false
  const homedirs = Array.isArray(user.homedirs) ? user.homedirs : []
  return homedirs.length > 1 && !user.active_homedir
}

/**
 * Push the user into the browser route, restoring a stashed `?cd=` deep
 * link (and, for multi-folder users, the `?folder=` it belongs to) if one
 * is pending. Consumes the stash so it can't be replayed.
 *
 * `folder` is only emitted when the caller passes it (multi-folder restore);
 * single-folder URLs stay clean (`/#/?cd=...`).
 *
 * NB: the actual directory load happens in Browser.vue — either at mount
 * (cold deep link) or via its `$route` watcher (when this push changes the
 * query). This function only owns the routing + stash bookkeeping.
 */
function landInBrowser(router, store, folder) {
  const cd = store && store.state.pendingCd ? store.state.pendingCd : null
  if (cd) {
    const query = { cd }
    if (folder) query.folder = folder
    router.push({ path: '/', query }).catch(() => {})
  } else {
    router.push('/').catch(() => {})
  }
  // Always clear: whether or not we restored, the intent is now spent.
  if (store) {
    store.commit('setPendingCd', null)
    store.commit('setPendingFolder', null)
  }
}

/**
 * Decide where to route the user immediately after a successful login
 * (or after the bootstrap `getUser` fetch on page load).
 *
 *   guest — route to `/` (where the forced-login form renders) and DO NOT
 *   consume the deep-link stash: it must survive until the user actually
 *   authenticates, at which point the post-login call lands in the
 *   authenticated branches below and does the restore. Detect guests by
 *   ROLE — the guest fixture has homedirs:['/'] (length 1), so an
 *   `homedirs.length === 0` test would misclassify it as single-folder and
 *   consume the stash too early.
 *
 *   homedirs.length === 1 — go straight into the file browser, restoring
 *   any pending `cd`. The backend auto-seeds SESSION_ACTIVE_HOMEDIR at
 *   login; the payload's active_homedir confirms it, and we only fall back
 *   to a defensive selectFolder() when it doesn't already match.
 *
 *   homedirs.length > 1 — Phase 2 deep link: if a valid `pendingFolder`
 *   is stashed, select it and restore `cd`, bypassing the picker. Else if
 *   the server already has a valid active_homedir, land there (restoring
 *   cd). Otherwise route to the picker; keep pendingCd so a manual pick
 *   can still honor the deep link (SelectFolder.pick), but drop the
 *   invalid pendingFolder.
 */
export function routeAfterLogin(user, router, store) {
  const homedirs = (user && Array.isArray(user.homedirs)) ? user.homedirs : []
  const active = user && user.active_homedir ? user.active_homedir : null
  const pendingFolder = store && store.state.pendingFolder ? store.state.pendingFolder : null

  if (!user || user.role === 'guest' || homedirs.length === 0) {
    router.push('/').catch(() => {})
    return
  }

  if (homedirs.length === 1) {
    const only = homedirs[0]
    if (active === only) {
      // Server already knows; no need for a round-trip on every page load.
      landInBrowser(router, store)
      return
    }
    // Bootstrap path didn't seed (or session expired). Fire-and-forget;
    // ensureActiveHomedir on the backend will also auto-seed if this fails.
    api.selectFolder({ homedir: only })
      .then(() => {
        if (store) store.commit('setActiveHomedir', only)
      })
      .catch(() => {})
      .finally(() => {
        landInBrowser(router, store)
      })
    return
  }

  // Multi-folder.
  // Phase 2: a self-describing deep link names the folder it belongs to.
  // Honor it (even if a different folder is currently active) so a
  // cross-session bookmark lands in the right place without the picker.
  if (pendingFolder && homedirs.indexOf(pendingFolder) !== -1) {
    if (pendingFolder === active) {
      landInBrowser(router, store, pendingFolder)
      return
    }
    api.selectFolder({ homedir: pendingFolder })
      .then(() => {
        if (store) store.commit('setActiveHomedir', pendingFolder)
        landInBrowser(router, store, pendingFolder)
      })
      .catch(() => {
        // Folder vanished (admin edit) — fall back to the picker. Drop the
        // stale folder hint but keep pendingCd for a manual pick.
        if (store) store.commit('setPendingFolder', null)
        router.push('/select-folder').catch(() => {})
      })
    return
  }

  // No folder hint: land on a still-valid active selection (restoring cd)...
  if (active && homedirs.indexOf(active) !== -1) {
    landInBrowser(router, store, active)
    return
  }

  // ...otherwise the picker. Keep pendingCd so SelectFolder.pick() can still
  // honor the deep link in whichever folder the user chooses; only the
  // (absent/invalid) folder hint is dropped.
  if (store) store.commit('setPendingFolder', null)
  router.push('/select-folder').catch(() => {})
}
