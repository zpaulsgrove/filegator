// Dismissal state for the "set up MFA" nudge banner shown in Browser.vue.
//
// The banner is dismissable so it stays out of the way while a user works, but
// the dismissal must NOT be permanent: an unenrolled client should be nudged
// again on their next login until they actually set up MFA. We persist the
// dismissal (so it survives in-session refreshes and route changes) keyed per
// user, and clear it on each successful login — see resetMfaNudgeDismissals,
// called from Login.vue. Login is the only unambiguous "new session" signal on
// the client: the bootstrap path runs initialize()/destroyUser() on every page
// load, so neither logout nor refresh can be told apart there.
const PREFIX = 'mfa_banner_dismissed_'

function keyFor(username) {
  return PREFIX + (username || '')
}

// True when this user has dismissed the banner in the current login session.
// Falls back to "not dismissed" (show the banner) when storage is unavailable,
// e.g. Safari private mode.
export function isMfaNudgeDismissed(username) {
  try {
    return window.localStorage.getItem(keyFor(username)) === '1'
  } catch (e) {
    return false
  }
}

// Remember that this user dismissed the banner. Best-effort: if storage is
// unavailable the dismissal simply doesn't persist, which is acceptable.
export function markMfaNudgeDismissed(username) {
  try {
    window.localStorage.setItem(keyFor(username), '1')
  } catch (e) {
    // No storage — nothing to persist.
  }
}

// Drop every stored dismissal so the banner reappears after a fresh login.
// Clears all users' flags (a browser only ever has one portal user signed in at
// a time) so the caller doesn't need to know the username. Best-effort.
export function resetMfaNudgeDismissals() {
  try {
    const storage = window.localStorage
    const stale = []
    for (let i = 0; i < storage.length; i++) {
      const key = storage.key(i)
      if (key && key.indexOf(PREFIX) === 0) {
        stale.push(key)
      }
    }
    stale.forEach(key => storage.removeItem(key))
  } catch (e) {
    // No storage — nothing was persisted, so nothing to clear.
  }
}
