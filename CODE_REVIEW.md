# FileGator Code Review Report

This report documents the findings of a multi-agent code review of the FileGator codebase, conducted on 2026-06-29. The scope covered the entire codebase, spanning the PHP backend and the Vue frontend. Findings were produced by independent review agents and then subjected to adversarial verification; only findings that survived that verification are included below, each with the verifier's confidence and corrected severity.

## Summary

| # | Category | Severity | Title | Location |
|---|----------|----------|-------|----------|
| 1 | Bug | High | Password change dropped when username is also changed (Database adapter) | backend/Services/Auth/Adapters/Database.php:114-142 |
| 2 | Bug | Medium | Overwrite store() reports success even when the final rename fails | backend/Services/Storage/Filesystem.php:229-242 |
| 3 | Bug | Low | Chunk-size sum counts the leftover 'assembled' scratch file, triggering premature assembly | backend/Controllers/UploadController.php:111-141 |
| 4 | Bug | Low | Empty permission string stored as `['']` instead of `[]` | backend/Services/Auth/User.php:143-157 |
| 5 | Bug | Low | BackupCodeGenerator `$length` parameter broken for any value other than 10 | backend/Services/Mfa/BackupCodeGenerator.php:26-37 |
| 6 | Bug | Low | loginMfa burns a TOTP/backup code even when the user lookup fails | backend/Controllers/AuthController.php:160-175 |
| 7 | Bug | Low | confirmReset burns the token before applying the password | backend/Services/PasswordReset/PasswordResetService.php:167-184 |
| 8 | Bug | Low | Tmpfs GC remove() can race and warn during concurrent cleanup | backend/Services/Tmpfs/Adapters/Tmpfs.php:100-115 |
| 9 | Bug | Low | checkUser passes a dead payload to destroyUser | frontend/mixins/shared.js:127-136 |
| 10 | Bug | Low | confirmEnroll collapses every failure into a generic 'Invalid code' toast | frontend/views/Security.vue:344-353 |
| 11 | Logic error | Medium | JsonFile CRUD bypasses the mutateUser lock, allowing lost writes vs MFA mutations | backend/Services/Auth/Adapters/JsonFile.php:173-249, 545-555 |
| 12 | Logic error | Low | uncompress() splits extracted tree on colliding destination folder | backend/Services/Archiver/Adapters/ZipArchiver.php:142-150 |
| 13 | Logic error | Low | Tree.vue 'selected' handler relies on $emit truthiness to chain $parent.close() | frontend/views/partials/Tree.vue:11 |
| 14 | Simplification | Low | MfaLockout re-implements a byte counter; lockfile grows unbounded while locked | backend/Services/Auth/MfaLockout.php:50-83 |
| 15 | Simplification | Low | SessionStorage invalidate/migrate bypass the null-safe getSession() helper | backend/Services/Session/Adapters/SessionStorage.php:56-63, 81-84 |
| 16 | Simplification | Low | Router declares an unused `$auth` property | backend/Services/Router/Router.php:23, 29-34 |
| 17 | Simplification | Low | Redundant double-check of `$routes` in route registration | backend/Services/Router/Router.php:49 |
| 18 | Simplification | Low | totalCount wraps a boolean-summing `_.sumBy` in `Number()` | frontend/views/Browser.vue:267-271 |

## Bugs

### 1. Password change dropped when the username is also changed (Database adapter)

Location: backend/Services/Auth/Adapters/Database.php:114-142

When an admin renames a user and sets a new password in the same update call, the password is silently not written. The first UPDATE (lines 127-133) renames the row: `SET username = <new>` (from `$user->getUsername()`) `WHERE username = ?` bound to the OLD `$username`. After it runs, the row's username is the new value. The second UPDATE (lines 135-139) then writes the password `WHERE username = ?` still bound to the OLD `$username`, which now matches zero rows, so the password column is never written. The `return` on line 141 re-fetches by the new username, masking the failure with no exception or error. The caller `AdminController.php:362-371` sets the new username on `$user` and passes `update($username=old, $user, password)` together, so the standard admin edit form triggers this whenever a rename and a password change are submitted together. The JsonFile adapter (JsonFile.php:181-201) does this correctly by mutating both fields in a single row iteration; only the Database adapter is affected.

Verifier confidence: high.

Suggested fix: combine both columns into a single UPDATE, or run the password UPDATE first (still keyed on the old username) before the rename, or key the password UPDATE on the NEW username (`$user->getUsername()`) since the row has been renamed by then.

### 2. Overwrite store() reports success even when the final rename fails

Location: backend/Services/Storage/Filesystem.php:229-242

In the overwrite branch of `store()`, after the new content is safely written to the temp sibling, the code runs `$this->storage->delete($destination)` followed by `$this->storage->rename($temp, $destination)` without checking either return value, then unconditionally returns `$destination` (a truthy success signal). Flysystem's `rename()` returns a bool and can fail (permission change on the target dir, target reappearing, adapter error). Because the original is deleted before the rename, a failed rename strands the new bytes in the `*.filegator-tmp.*` file with the destination missing, while `store()` still signals success. The caller `saveContent()` in FileController.php:285 records an audit success via `if ($stored !== false)` (line 291), producing a phantom success. This defeats the temp-first data-safety design noted in the issue #57 comment. Notably, `renameFile()` in the same file (line 177) correctly does `return $this->storage->rename($from, $to) ? $to : false;`, proving the codebase treats the bool result as a real failure signal everywhere except this branch.

Verifier confidence: high.

Suggested fix: check the rename result, e.g. `if (! $this->storage->rename($temp, $destination)) return false;`, and ideally guard the delete so a failed delete aborts before the rename. Return false on any failure so callers and the audit log do not record a phantom success.

### 3. Chunk-size sum counts the leftover 'assembled' scratch file, triggering premature assembly

Location: backend/Controllers/UploadController.php:111-141

The size accounting `foreach ($this->tmpfs->findAll($prefix.'*') as $chunk) { $chunks_size += $chunk['size']; }` globs `$prefix.'*'`, which also matches the assembly scratch file `$assembled = $prefix.'assembled'`. If a previous assembly for the same user+identifier crashed (or was killed mid-stream, or interrupted partway through the cleanup at lines 150-153) after writing `assembled` but before removing it, that stale full-size scratch file persists in tmpfs. On a later chunk POST, `$chunks_size` then equals roughly the full file size plus whatever real parts exist, reaching `>= $total_size` even though real parts are missing. The completion branch (line 127) runs the assembly loop, and for a missing part `readStream()` does `fopen(..., 'r')` which returns false, so `$part['stream']` is false and `file_put_contents($path, false)` writes nothing. There is no existence guard and no abort, so a truncated/corrupted final file is silently stored. The verifier downgraded this from medium to low because the trigger requires a specific crash/kill window (a surviving `assembled` plus a missing part) followed by a re-triggering POST.

Verifier confidence: medium.

Suggested fix: exclude scratch and trap files from the size sum (skip names ending in `assembled`/`_error`, or sum only names matching the `.partN` pattern), and guard the assembly loop by verifying each `$prefix.$file_name.'.part'.$i` exists before reading, aborting with an error if a part is missing.

### 4. Empty permission string is stored as `['']` instead of `[]`

Location: backend/Services/Auth/User.php:143-157

`setPermissions('', true)` runs `explode('|', '')`, which returns `['']` (one element, an empty string), not `[]`. `checkValidPermissions` skips the empty entry via the `$permission &&` guard (line 189), so no exception is thrown and `['']` is stored. The guest in `private/users.json.blank` has `"permissions":""`; `JsonFile::mapToUserObject` calls `setPermissions('', true)`, so `getPermissions(false)` returns `['']` and `User::jsonSerialize` (line 166) emits `permissions => ['']` for the guest instead of `[]`. There is no security or functional consequence — `hasPermissions` uses `array_intersect`/`in_array`, so a stray `''` grants nothing — but any consumer treating permissions as a clean list sees a spurious empty element.

Verifier confidence: high.

Suggested fix: after exploding (or in `getPermissions`), filter out empty strings, e.g. `$permissions = array_values(array_filter($permissions, fn($p) => $p !== ''));`, so an empty encoded permission set normalizes to `[]`.

### 5. BackupCodeGenerator `$length` parameter is broken for any value other than 10

Location: backend/Services/Mfa/BackupCodeGenerator.php:26-37

`generate(int $count = 10, int $length = 10)` builds a raw string of `$length` characters but formats it with a hardcoded split at offset 5: `substr($raw,0,5).'-'.substr($raw,5)`. The `XXXXX-XXXXX` grouping only makes sense when `$length === 10`. For `$length=6` you get `XXXXX-X`; for `$length=4`, `XXXX-` (trailing hyphen, empty second group); for `$length=14`, the second group silently grows to nine characters. The advertised configurable length therefore produces malformed codes. All current callers (`MfaService::generate`/`regenerate` and every test) pass length 10, so the defect is latent, but the parameter is non-functional as documented and a trap for future reuse.

Verifier confidence: high.

Suggested fix: either drop the `$length` parameter and document the fixed 10-char/`XXXXX-XXXXX` format, or derive the split point from `$length` (e.g. `intdiv($length, 2)`) so the grouping tracks the requested length.

### 6. loginMfa burns a TOTP/backup code even when the subsequent user lookup fails

Location: backend/Controllers/AuthController.php:160-175

`verifyTotp()` (which plants a single-use replay marker) or `consumeBackupCode()` (which permanently removes a backup code) runs at lines 160-162. Only afterwards does the controller call `$auth->find($username)`; if that returns null (lines 170-173) it returns 422 and returns BEFORE reaching `clearForUsername` at line 175. So a valid second factor is consumed/burned, the username lockout counter is not cleared, and the login still fails — a backup code consumed this way is gone forever. Reachability is narrow: a successful consume/verify implies the user record existed, so a null `find()` immediately afterward requires a TOCTOU race (user deleted/renamed in between) or an inconsistency between the MFA adapter and the auth lookup. In that case the impact is limited to one lost backup code (or an occupied replay slot) plus a stale lockout counter; the login correctly fails with no auth bypass.

Verifier confidence: high.

Suggested fix: resolve the user (`find`) before consuming the second factor, or treat a post-verify `find()` failure as a server-error path that does not strand a burned single-use credential. At minimum, move the existence check ahead of the verify/consume call.

### 7. confirmReset burns the token before applying the password

Location: backend/Services/PasswordReset/PasswordResetService.php:167-184

`confirmReset()` calls `store->markUsed($hash)` (line 173), which atomically flips `used=true`, BEFORE calling `resettable()->setPasswordDirect()` (line 175), with no try/catch and no rollback. If `setPasswordDirect` throws (auth-store write error, disk full), the token has already been consumed and is permanently invalid, but the password was never changed, leaving the user to restart the whole forgot-password flow. Two caveats from verification: `setPasswordDirect` is declared `: void`, so there is no return value to check and no "soft failure" path; and a hard failure propagates as an exception rather than a bogus `return true`. The mark-used-first ordering also appears deliberate to prevent a double-confirm TOCTOU race (comment at TokenStore.php:85-86), so a naive reorder would trade this edge case for a concurrency risk. The genuine residual defect is the non-atomic ordering causing a recoverable usability inconvenience.

Verifier confidence: medium.

Suggested fix: apply the password first, or wrap `setPasswordDirect` in try/catch and only call `markUsed` once the password write is confirmed, while preserving protection against the double-confirm race.

### 8. Tmpfs GC remove() can race and warn during concurrent cleanup

Location: backend/Services/Tmpfs/Adapters/Tmpfs.php:100-115

`clean()` (lines 107-115) snapshots the file list via `findAll('*')` then calls `remove()` on each; `remove()` (lines 100-105) calls `unlink($this->getPath().$filename)` unconditionally, with no `file_exists()` guard or `@` suppression. GC fires probabilistically on every `init()` (line 28), and multiple call sites operate on the same tmpfs directory (chunk finalizers, `MfaService::gcExpiredReplayMarkers`). Because `clean('*')` matches every file including `mfa_used_*.lock`, a tmpfs-wide GC pass can race a concurrent namespaced GC over the same expired marker, so a file can vanish between the snapshot and the unlink. MonoLogger registers a process-global error handler (MonoLogger.php:42-47), so the resulting "No such file or directory" warning is logged rather than silently dropped. Impact is limited to log noise — no crash, exception, or data corruption, since the file's removal is the intended end state.

Verifier confidence: high.

Suggested fix: guard the unlink, e.g. `if (file_exists($path)) { @unlink($path); }`, or otherwise suppress/ignore the missing-file case so concurrent GC passes do not raise warnings.

### 9. checkUser passes a dead payload to destroyUser

Location: frontend/mixins/shared.js:127-136

When `checkUser()` detects that the freshly fetched user's username differs from the one in the store, it calls `this.$store.commit('destroyUser', user)`, passing the new `user` object as the mutation payload. The `destroyUser` mutation (store.js:102-111) is defined as `destroyUser(state)` with no second parameter; it ignores any payload and unconditionally resets to the guest fixture. The argument is therefore silently discarded. The runtime behavior is correct for the intended flow (detect mismatch, log out, show a toast), so this is a clarity/maintainability issue, not a functional bug — the call site reads as if it intends to adopt the new user but actually wipes to guest.

Verifier confidence: high.

Suggested fix: drop the unused argument: `this.$store.commit('destroyUser')`. If the intent was to adopt the newly-detected session, commit `setUser` instead.

### 10. confirmEnroll collapses every enroll failure into a generic 'Invalid code' toast

Location: frontend/views/Security.vue:344-353

`confirmEnroll` catches all errors from `api.mfaConfirmEnroll` with a bare `.catch(() => { ... 'Invalid code' ... })`, discarding the actual error. The backend (MfaController::confirmEnroll, lines 75-101) returns several distinct outcomes — 501 'MFA not supported', 422 'This field is required', 422 'Invalid code' — plus transient failures (no-response network error, 5xx, 401 session expiry), all of which collapse into the same 'Invalid code' toast. A user hitting a transient network error or an expired session is wrongly told their (possibly correct) code is invalid. Sibling handlers in the same file inspect `err.response.status`/`data`: `changePassword` (318-330) and `performManage` (393-416), which explicitly maps 422 field errors and defers other cases. Note the 429/rate-limit example is somewhat speculative here, since `confirmEnroll` has no `MfaLockout` dependency, and the most common failure (a genuinely wrong code) does map to the right message — keeping this a low-severity UX issue.

Verifier confidence: high.

Suggested fix: inspect the error — on 422/field errors show the server message, on no-response defer to `handleError(err)`, and only show 'Invalid code' for an actual invalid-code response.

## Logic errors

### 11. JsonFile CRUD bypasses the mutateUser lock, allowing lost writes against MFA mutations

Location: backend/Services/Auth/Adapters/JsonFile.php:173-249, 545-555

`mutateUser()` (437-461) holds an advisory `flock(LOCK_EX)` across a read-modify-write via `openLocked`/`readLocked`/`writeLocked`, and its docblock (426-436) claims this prevents two concurrent FPM workers from each reading the same snapshot, mutating one row, and clobbering each other on save. The CRUD methods defeat this: `update()` (175), `add()` (212), `delete()` (237), and `setEmail()`'s dedup loop (408) read via `getUsers()` (545-549), which is plain `file_get_contents` with NO flock, so their read is not serialized against `mutateUser`'s lock. `saveUsers()` (552-554) passes `LOCK_EX` to `file_put_contents`, but that only makes the write atomic; it does not cover the read-modify-write window. Concrete interleaving: worker B's `update()` reads snapshot S unlocked; worker A's `enableMfa()` acquires the lock, writes S+mfa, releases; worker B then writes S+role_change, overwriting A's `mfa_enabled=true`. The most security-relevant casualty is `consumeBackupCode` single-use enforcement (a consumed code could effectively reappear), alongside `enableMfa`/`disableMfa`/`setMfaSecret`. Severity is medium rather than high because it requires a narrow concurrent interleaving on the file-based adapter (typically small/single-admin installs), and the worst case still requires the attacker to also possess the backup code.

Verifier confidence: high.

Suggested fix: route `add`/`update`/`delete` (and the `setEmail` dedup check) through the same `openLocked`/`readLocked`/`writeLocked` cycle used by `mutateUser`, so all writers serialize on one exclusive lock.

### 12. uncompress() splits extracted tree when destination contains a colliding folder

Location: backend/Services/Archiver/Adapters/ZipArchiver.php:142-150

During extraction, directory entries are created with `storage->createDir($destination, $item['path'])` (line 145), whose return value is discarded. `createDir` (Filesystem.php:42-51) upcounts when the target dir already exists and is non-empty: `while (! empty(listContents($destination, true))) $destination = upcountName($destination);`. The file branch (line 149) independently computes its target as `$destination.'/'.$item['dirname']` using the original, un-upcounted zip dirname. So when the destination already contains a same-named non-empty folder, the zip's directory entry is created as `folder (1)` (empty) while the zip's files (dirname `folder`) are written into the pre-existing `folder` — the two diverge and the extracted tree is split. No upcounted path is captured, so no mapping exists to reconcile them. Impact is low: it triggers only when the destination already holds a colliding non-empty directory, and there is no data loss because `store()` upcounts per-file collisions; files are merely misplaced/merged while an empty `folder (1)` is created.

Verifier confidence: high.

Suggested fix: track a mapping from original zip directory paths to the actually-created (possibly upcounted) destination directory and rewrite each file's dirname through that map, or extract into a single freshly-created top-level directory so collisions are resolved once at the root.

### 13. Tree.vue 'selected' handler relies on $emit truthiness to chain $parent.close()

Location: frontend/views/partials/Tree.vue:11

The selection handler is written `@selected="$emit('selected', $event) && $parent.close()"`. This depends on `$emit` returning a truthy value so the `&&` short-circuit lets `$parent.close()` run. On Vue 2.6.11 (the project's version), `vm.$emit` returns the component instance (truthy), so it currently works and the folder picker modal closes correctly. The intent ("always emit, then always close") is not what `&&` expresses, and a future Vue 3 migration — where `$emit` returns `undefined` — would silently stop the close from firing. This is a latent-fragility/clarity finding with no present functional impact; severity was downgraded from medium to low for that reason.

Verifier confidence: high.

Suggested fix: make both effects unconditional: `@selected="$emit('selected', $event); $parent.close()"`, or move to a method that calls both.

## Simplifications

### 14. MfaLockout re-implements a byte counter; lockfile grows unbounded while locked

Location: backend/Services/Auth/MfaLockout.php:50-83

`recordFailure()` (50-54) calls `$this->tmpfs->write($key, 'x', true)`, which maps to `file_put_contents` with `FILE_APPEND` (Tmpfs.php:40-43), appending a byte unconditionally with no cap check; `isCounterLocked()` (70-83) separately reads the file and tests `strlen(...) >= $attempts`. `TmpfsInterface` already exposes `incrementCounterIfBelow($file, $max)` (interface line 56; Tmpfs adapter 136-165), which performs a LOCK_EX read-modify-write, returns -1 once `current >= max` WITHOUT appending, and is already used by `PasswordResetService::checkAndIncrementLimit` (line 213). Because `recordFailure()` appends with no cap, every failed attempt while the account is already locked keeps growing the file until the timeout-based GC removes it. Using the helper also closes a real concurrency race: the current append path is non-atomic across concurrent failures, whereas `incrementCounterIfBelow` holds `flock(LOCK_EX)` across the read+append. Severity stays low because growth is bounded by the lockout-timeout GC and the non-atomic counting only marginally affects the brute-force budget.

Verifier confidence: high.

Suggested fix: have `recordFailure()` call `incrementCounterIfBelow($key, $attempts)`; this reuses the existing atomic primitive and stops growing the file once the cap is reached, while `isLocked()` can keep relying on the existing `strlen` check.

### 15. SessionStorage invalidate/migrate bypass the null-safe getSession() helper

Location: backend/Services/Session/Adapters/SessionStorage.php:56-63, 81-84

`getSession()` (70-79) is declared `?Session` and deliberately returns null when `!hasSession()` to preserve the pre-Symfony-5 nullable contract (comment 72-73), because Symfony 5 throws `SessionNotFoundException` when no session is bound. But `invalidate()` (line 58) dereferences `$this->getSession()->isStarted()` with no null guard — unlike `get()` on line 53, which does guard — so it would fatal on a null return. `migrate()` (line 83) calls `$this->request->getSession()->migrate(...)` directly, bypassing the helper entirely and would throw the very `SessionNotFoundException` the helper exists to avoid. In current call paths a session always exists by the time these run (init() creates one via DI before any controller call), so it does not crash today; this is an internal inconsistency with the stated nullable contract.

Verifier confidence: high.

Suggested fix: use the `getSession()` helper with a null guard in both `invalidate()` and `migrate()`, e.g. `$s = $this->getSession(); if (! $s) return false; ...`, so the nullable contract is honored uniformly.

### 16. Router declares an unused `$auth` property

Location: backend/Services/Router/Router.php:23, 29-34

Line 23 declares `protected $auth;`, but the constructor (29-34) never assigns it. The `AuthInterface` argument is consumed inline as `$auth->user() ?: $auth->getGuest()` to set `$this->user` and is then discarded. A grep for `this->auth` in Router.php returns no matches, and no subclass consumes it, so the property is dead. Removing it is behavior-preserving.

Verifier confidence: high.

Suggested fix: remove the `protected $auth;` declaration, since the dependency is fully consumed in the constructor body.

### 17. Redundant double-check of `$routes` in route registration

Location: backend/Services/Router/Router.php:49

The guard `if ($routes && ! empty($routes))` is redundant. `$routes` is assigned on line 46 via `require $config['routes_file']`, which returns an array literal, so it is always defined. In PHP, `!empty($x)` is exactly the truthiness of `$x`, so `$routes && !empty($routes)` is `truthy($routes) && truthy($routes)`, which always equals `truthy($routes)`. The two terms can never disagree.

Verifier confidence: high.

Suggested fix: replace with `if (! empty($routes)) {` to express the same condition once.

### 18. totalCount wraps a boolean-summing `_.sumBy` in `Number()`

Location: frontend/views/Browser.vue:267-271

`totalCount` does `Number(_.sumBy(content, o => o.type == 'file' || o.type == 'dir'))`. It sums booleans relying on implicit boolean-to-number coercion inside lodash's accumulator, then wraps in `Number()` to guard the single-element case. The wrapper is required because `_.sumBy` seeds its accumulator with the first iteratee value verbatim, so with a single element and a boolean iteratee it returns `true`/`false` rather than `1`/`0`; with two or more elements the `+` operator coerces. The construction is non-obvious and brittle. A clearer, behavior-preserving replacement was verified to produce identical results across all cases (single, mixed, empty).

Verifier confidence: high.

Suggested fix: use a clear count: `_.filter(this.$store.state.cwd.content, o => o.type == 'file' || o.type == 'dir').length`, which removes both the coercion and the `Number()` wrapper.