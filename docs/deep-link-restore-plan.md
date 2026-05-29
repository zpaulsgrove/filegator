# Deep-link / folder restoration on reload + after login

## Context

The file browser already encodes the current folder in the URL hash as `/#/?cd=<path>`
(`Browser.vue` `goTo()` → `router.push({name:'browser', query:{cd}})`, with a `$route` watcher
that calls `api.changeDir({to: cd})`). But the folder is **not restored** on a cold load:

- `frontend/main.js` `created()` always calls `routeAfterLogin(...)` after `getUser` (except on
  `forgot-password`/`reset-password`), and `routeAfterLogin` (`frontend/mixins/postLogin.js`)
  unconditionally pushes `'/'` (or `'/select-folder'`) — **dropping the `?cd=` query**.
- `Browser.vue mounted()` always calls `loadFiles()` (`getDir({to:''})`, the session CWD) and
  **never reads `$route.query.cd`** — only the `$route` *watcher* honors `cd`, and that doesn't
  fire on initial render.

Net effect: **refreshing while inside a subfolder bounces you to the folder root**, and a
bookmarked/shared deep link is lost. PR #18's `multi-folder-isolation.cy.js` and the
`staging/UAT-checklist.md` already flag this as not-implemented. Key facts that shape the fix
(verified in code): `cd` is **relative to the active homedir**, and `GET /getuser` returns
`active_homedir` from the session (`AuthController::userResponsePayload`), so on a live-session
reload the active folder is known and `cd` resolves correctly without any URL change. A `cd` that
escapes the homedir is collapsed to root by `Filesystem::applyPathPrefix` (safe).

**Shipping:** Phase 1 first as its own PR; Phase 2 (multi-folder cross-session deep links) as a
follow-up. Phase 2 recommendation: **encode the homedir in the URL** — the only approach that
truly delivers the multi-folder picker-bypass the UAT checklist promised.

---

## Phase 1 — restore `cd` on reload (all users, live session) + after login (single-folder)

`cd` is homedir-relative and the active homedir comes from the session, so **no URL-scheme change
is needed** for Phase 1. Four small frontend changes; no backend changes.

1. **`frontend/views/Browser.vue` — honor an initial `cd` on mount.**
   Extract the `$route` watcher body into a method `loadDir(cd)` (does `api.changeDir({to: cd})` →
   `setCwd` with `filterEntries`, same as today) and have the watcher call it. In `mounted()`:
   `if (this.can('read')) { this.$route.query.cd ? this.loadDir(this.$route.query.cd) : this.loadFiles() }`.
   The watcher still handles in-app navigations (no double-load: mount loads once; the watcher only
   fires on later route changes).

2. **`frontend/store.js` — add a cross-login stash.**
   New state `pendingCd: null` + mutation `setPendingCd(state, cd)`. Clear it in the existing
   `initialize` mutation (so logout/bootstrap reset it) — `initialize` already runs before the
   stash is set in `main.js`, and on logout (`Menu.logout`).

3. **`frontend/main.js` — preserve the browser deep link on bootstrap.**
   After `setUser`, replace the blanket `routeAfterLogin` call with:
   - authenticated **and** `this.$route.name === 'browser'` **and** `!needsFolderPicker(user)`
     → **skip** `routeAfterLogin` (leave the route; `Browser.mounted` restores `cd`). This also
     fixes multi-folder reload, since `getUser` returns the session `active_homedir`.
   - guest **and** route is `browser` with a `cd` → `store.commit('setPendingCd', cd)` then the
     existing `routeAfterLogin(guest…)` (preserves the intent across the trip to login).
   - everything else → unchanged (`routeAfterLogin`, still skipping `forgot/reset-password`).
   Reuse `needsFolderPicker` from `postLogin.js`.

4. **`frontend/mixins/postLogin.js` — `routeAfterLogin` restores a stashed `cd`.**
   In the **authenticated** browser-landing pushes only — the single-folder branch (both the
   `active === only` push *and* the `.finally()` push after the defensive `selectFolder`) and the
   multi-with-valid-active push — if `store && store.state.pendingCd`, push
   `{ path:'/', query:{ cd: store.state.pendingCd } }` and `store.commit('setPendingCd', null)`;
   otherwise `push('/')` as today. **Do NOT restore in the guest branch** (`homedirs.length === 0`):
   bootstrap stashes `pendingCd` and then immediately calls `routeAfterLogin(guest)`, so consuming
   it there would clear the stash before the user ever logs in — the guest branch must keep pushing
   plain `'/'` and leave the stash intact for the post-login call. The picker branch is unchanged
   (Phase 2). All call sites already pass `store`, so no signature change.

### Tests (reproduce-first)

- **E2E `tests/frontend/e2e/specs/deep-link.cy.js`** (new), runs in the default `cypress` job:
  - *Reload restores the folder (single-folder admin):* login → create folder `sub` → open it →
    create `inside.txt` (now at `/#/?cd=/sub`) → `cy.reload()` → assert `inside.txt` is listed
    (proves the subfolder reloaded, not root). This is the test that currently fails.
  - *Logged-out deep link → login:* seed `/sub/inside.txt` via `apiPost('/changedir')` +
    `apiPost('/createnew')`, `apiPost('/logout')`, `cy.visit('/#/?cd=/sub')`, then log in via the
    **in-app** `login-nav` → login form (must NOT `cy.visit('/login')`, which would re-bootstrap and
    drop the stash) → assert `inside.txt` listed.
  - *(bonus)* multi-folder reload keeps folder+cd: extend `multi-folder-isolation.cy.js`.
- **Unit `tests/frontend/unit/postLogin.spec.js`** (extend/create): `routeAfterLogin` pushes
  `{path:'/', query:{cd}}` and clears `pendingCd` when stashed (single-folder + multi-with-active);
  plain `'/'` when not. (`Browser.vue` has no unit spec → its mount behavior is covered by E2E.)
- **Docs:** flip the now-covered deep-link items in `staging/UAT-checklist.md` /
  `UAT-checklist-mfa-password-reset.md` from manual/not-implemented to `(automated: deep-link.cy.js)`;
  update the "intentionally NOT tested" comment in `multi-folder-isolation.cy.js`. Leave the
  **multi-folder cross-session** deep-link line as Phase 2.

### Verification
1. `npm run lint` clean on the 3 changed `.vue`/js files; `npm run test:unit` green (incl. new
   postLogin cases).
2. `node --check` the new spec.
3. Local `php -S` smoke (seam config): confirm `GET /getuser` returns `active_homedir` after
   `/selectfolder`, and `POST /changedir {to:/sub}` lists the subfolder — i.e. the backend already
   supports what the restored route calls (no backend change).
4. CI: `deep-link.cy.js` passes in the default cypress job; forced-MFA job unchanged;
   `phpunit tests/backend` still green.

### Branch / commits / PR
- Branch `deep-link-restore` off latest `origin/master`. Commits: (1) `Browser.mounted` + `store`
  + `main.js` + `postLogin` restore; (2) `deep-link.cy.js` + postLogin unit; (3) UAT/comment docs.
- One PR against `master`; offer to watch CI. Do not open the PR until asked.

---

## Phase 2 — multi-folder cross-session deep links (follow-up PR)

**Problem:** after login, a multi-folder user has no `active_homedir` → the router guard forces the
picker, and a bare `cd` can't say which homedir it belongs to. **Recommended approach: encode the
homedir in the URL** so links are self-describing and can bypass the picker.

- **`Browser.vue goTo()`** (and the folder-switcher): for multi-folder users, push
  `{name:'browser', query:{folder: active_homedir, cd}}`; omit `folder` for single-folder users
  (keep their URLs clean). Phase 1's restore logic already handles `cd`.
- **Stash + restore:** add `pendingFolder` alongside `pendingCd`. On a cold deep link / after login,
  if `folder` is present and in `user.homedirs`, call `api.selectFolder({homedir: folder})` →
  `setActiveHomedir` → restore `cd` (push `{path:'/', query:{folder, cd}}`), bypassing the picker.
  If `folder` is missing/invalid → fall back to the picker and drop `cd` (today's behavior).
  Touches `frontend/mixins/postLogin.js`, `frontend/main.js`, and `SelectFolder.vue` `pick()`
  (honor a pending `cd` after a manual pick too). `selectFolder` already validates the homedir
  server-side (`FileController::selectFolder` `in_array` check).
- **Tests:** multi-folder cross-session deep link `/#/?folder=/personal&cd=/sub` → after UI login,
  lands in `/personal` at `/sub` with **no** picker; invalid `folder` → picker. Extend the
  multi-folder specs; flip the last UAT deep-link line to automated.

## Risks / out of scope
- **Cypress can't run in this sandbox** → browser specs validate on CI (as in #17–#19); keep
  assertions deterministic (assert a file known to live in the subfolder, not breadcrumb cosmetics).
- **Stash hygiene:** `pendingCd` must be cleared on consume and on `initialize`/logout so a stale
  deep link can't attach to a later unrelated login.
- **Guest→login E2E** depends on in-app navigation (`login-nav`), not a fresh `cy.visit('/login')`,
  or the single-SPA-load stash is lost — noted in the spec.
- **No backend changes** in either phase; `changeDir`/`selectFolder` already do path validation and
  collapse out-of-homedir `cd` to root.
- Broader route preservation (keeping `/security`, `/users` on reload) is **out of scope** — Phase 1
  only preserves the `browser` route's `cd`.
