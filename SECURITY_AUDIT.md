# FileGator Security Audit

**Date:** 2026-06-29
**Scope:** Entire application — backend (PHP), frontend (Vue), configuration, and dependencies
**Method:** Multi-agent code review (one reviewer per attack surface) with adversarial
verification of every candidate finding, plus dependency CVE scanning.

> Each finding below was independently verified by a second reviewer that attempted to
> *disprove* it and trace a concrete code path from untrusted input to the sink. Findings
> that could not survive that scrutiny are listed under
> [Reviewed and dismissed](#reviewed-and-dismissed) for transparency.

---

## Methodology

10 attack surfaces were reviewed in parallel, each by a dedicated reviewer that read the
actual code and traced request input to sinks:

1. Path traversal & filesystem confinement
2. Upload & archive handling (zip-slip)
3. Download & IDOR
4. AuthN/AuthZ & role/permission gating
5. Multi-factor auth (TOTP, backup codes, lockout, step-up)
6. Password reset (tokens, expiry, enumeration)
7. Session & CSRF
8. Dangerous sinks, injection & crypto hygiene
9. Frontend (Vue) XSS & client-side trust
10. Configuration, secrets & deployment

Every candidate finding was then passed to an independent verifier with instructions to
refute it. Dependency advisories were checked with `composer audit --locked` and `npm audit`.

**Outcome:** 16 candidates → **8 confirmed**, 8 dismissed as false positives. 2 of the
confirmed findings were duplicates of the same root cause (session cookie `Secure` flag),
so they are merged below into **7 distinct code/config findings** plus dependency findings.

---

## Severity summary

> **Remediation status:** all 8 confirmed findings are fixed — findings 1, 2, 5, 6,
> 7, 8 on branch `claude/app-security-audit-l9xp3i`, and findings 3, 4 upstream in
> PR #71 (adopted here via merge). See [Remediation](#remediation) for details.

| # | Severity | Finding | Location | Status |
|---|----------|---------|----------|--------|
| 1 | **High** | LDAP injection in login username → `ldap_search` filter | `backend/Services/Auth/Adapters/LDAP.php` | ✅ Fixed |
| 2 | **High** | Vulnerable TOTP library `spomky-labs/otphp` 11.2.2 (2 advisories) | `composer.lock` | ✅ Fixed |
| 3 | **Medium** | IDOR / predictable token: any user can download another user's batch archive | `backend/Controllers/DownloadController.php` | ✅ Fixed upstream (PR #71) |
| 4 | **Medium** | Upload reassembly uses non-namespaced filename in shared tmpfs (race / cross-user) | `backend/Controllers/UploadController.php` | ✅ Fixed upstream (PR #71) |
| 5 | **Medium** | Default admin ships with publicly-known credentials (`admin` / `admin123`) | `private/users.json.blank` | ✅ Fixed |
| 6 | **Low** | Session cookie `Secure` flag off by default (`cookie_secure => null`) | `configuration_sample.php` | ✅ Fixed |
| 7 | **Low** | User enumeration via password-reset response timing | `backend/Services/PasswordReset/PasswordResetService.php` | ✅ Fixed |
| 8 | **Low** | Latent XSS: shared error toast renders server strings via Buefy `v-html` | `frontend/mixins/shared.js` | ✅ Fixed |

Dev-only dependency advisories (`npm audit`) are noted under
[Dependency findings](#dependency-findings) but are **not shipped to production**.

---

## Findings

### 1. [High] LDAP injection in login username → `ldap_search` filter

- **File:** `backend/Services/Auth/Adapters/LDAP.php:201` (sink); input enters at `AuthController::login()` (`backend/Controllers/AuthController.php:44`)
- **CWE:** CWE-90 (LDAP Injection)

**Description.** The login `username` is taken verbatim from `POST /login` and passed to
`LDAP::getUsers($username)`, where it is concatenated directly into an LDAP search filter
with no escaping:

```php
$filter = '(&' . $this->ldap_filter . '(' . $this->ldap_userFieldMapping['username'] . '=' . $username . '))';
```

There is **no `ldap_escape()` anywhere in the backend** (grep: 0 matches). The only
preprocessing (`username_RemoveDomains` `str_replace`, `username_AddDomain` append) does not
neutralise LDAP filter metacharacters `( ) * \ & |`. The same sink is also reachable from
`find()` (`LDAP.php:138`). LDAP is a documented, selectable auth handler.

**Exploit.** An unauthenticated attacker POSTs a crafted `username`. Because the post-search
match loop requires an exact username match **and** a real `ldap_bind` with the supplied
password, full authentication bypass is *not* achievable — but blind-boolean injection,
username/attribute enumeration, and directory information disclosure (executed under the
service bind DN's privileges) all are. A payload like `admin)(objectClass=*` changes whether
`ldap_search` returns rows or throws, producing an observable response difference.

**Fix.** Escape the value before interpolation:

```php
'(' . $this->ldap_userFieldMapping['username'] . '=' . ldap_escape($username, '', LDAP_ESCAPE_FILTER) . ')'
```

Apply the escaping *after* the RemoveDomains/AddDomain transforms. Optionally reject
usernames containing filter metacharacters outright.

---

### 2. [High] Vulnerable TOTP library `spomky-labs/otphp` 11.2.2

- **File:** `composer.lock` (pinned `spomky-labs/otphp` **11.2.2**); used by `backend/Services/Mfa/`
- **CWE:** CWE-1395 (Dependency on Vulnerable Third-Party Component)

**Description.** `composer audit --locked` reports two advisories against the exact library
that powers FileGator's MFA/TOTP, both fixed in **11.4.3**:

- **High** — [GHSA-g7m4-839x-ch6v](https://github.com/Spomky-Labs/otphp/security/advisories/GHSA-g7m4-839x-ch6v): unbounded `digits` parameter in a provisioning URI triggers an uncaught `DivisionByZeroError` in OTP generation.
- **Medium** — [GHSA-2jx3-65f3-xr8r](https://github.com/Spomky-Labs/otphp/security/advisories/GHSA-2jx3-65f3-xr8r): mass-assignment in `Factory::loadFromProvisioningUri` lets a hostile provisioning URI corrupt OTP state or raise an uncaught `TypeError`.

Reachability depends on whether FileGator ever constructs an OTP object from an
externally-supplied provisioning URI; even if not directly reachable today, running a
known-vulnerable crypto/MFA dependency is a High-priority hygiene issue.

**Fix.** Bump the constraint to `^11.4.3` (currently `^11.2`) and run `composer update spomky-labs/otphp`. Re-run `composer audit` in CI to prevent regressions.

---

### 3. [Medium] IDOR: any user can download another user's batch archive

- **File:** `backend/Controllers/DownloadController.php:148-153` (read); created at `123-146`
- **CWE:** CWE-639 (Authorization Bypass Through User-Controlled Key)

**Description.** `batchDownloadStart()` streams a file out of the **shared** tmpfs directory
using only the attacker-supplied `uniqid` parameter, with no binding to the session/user
that created the archive:

```php
$uniqid = preg_replace('/[^0-9a-zA-Z_]/','', $request->input('uniqid'));
$file   = $tmpfs->readStream($uniqid);
```

`batchDownloadCreate()` returns the `uniqid` to the client but **never records ownership in
the session**, and the archive name is `uniqid()` (`ZipArchiver.php:44`) — a non-secret,
microtime-derived 13-char value. `ensureActiveHomedir()` only constrains the user's home dir
(`$this->storage`), not this tmpfs read. `Tmpfs::sanitizeFilename` strips `/`, so this is an
in-directory IDOR, not arbitrary-filesystem read.

**Exploit.** Attacker B requests `GET /batchdownload?uniqid=<victim's value>` and streams
victim A's archive. Reachable via (a) a timing race before A's own download removes the file,
(b) an orphaned archive A created but never downloaded (persists up to the 2-day GC), or
(c) reading a victim's freshly-assembled upload by its client-controlled filename.

**Fix.** Bind every tmpfs artifact to its creator: generate the token with a CSPRNG
(`bin2hex(random_bytes(16))`) instead of `uniqid()`, store the created ids in the session in
`batchDownloadCreate()`, and in `batchDownloadStart()` reject any `uniqid` not in that
per-session allowlist. Apply the same owner-binding to upload temp filenames (see Finding 4).

---

### 4. [Medium] Upload reassembly uses non-namespaced filename in shared tmpfs

- **File:** `backend/Controllers/UploadController.php:127-137`
- **CWE:** CWE-362 (Race Condition) / CWE-668 (Exposure of Resource to Wrong Sphere)

**Description.** Per-chunk temp files are namespaced `multipart_<username>_<identifier>_`,
but the **final reassembled file** is written to the shared tmpfs under the bare,
client-controlled `$file_name` (from `resumableFilename`) using `FILE_APPEND`, then read back
and stored:

```php
$this->tmpfs->write($file_name, $part['stream'], true); // append=true, bare name
$final = $this->tmpfs->readStream($file_name);
```

`sanitizeFilename` keeps the basename (e.g. `report.pdf`), the tmpfs directory is shared
across all users/sessions, and there is no `flock`/atomic-rename/truncate guard — despite the
codebase having atomic primitives (`Tmpfs::addIfAbsent`, `incrementCounterIfBelow`) available
but unused here.

**Exploit.** Two concurrent uploads sharing a filename (different users, the same user in two
tabs, or multiple anonymous guests) append into the same tmpfs entry; the appends interleave,
so a stored file can contain another user's bytes (cross-user leak) or be corrupted. The most
reliable angle is corruption; cross-user exfiltration is real but timing-dependent. The final
storage destination is correctly per-user, so this is **not** a home-dir escape.

**Fix.** Build the final file under a namespaced/unique tmpfs name (`$prefix.$file_name` or a
`uniqid`-based name), guard assembly with `addIfAbsent`/`flock` to serialize, and truncate
before the assembly loop so stale bytes cannot accumulate. Ideally stream concatenated chunks
straight into `storage->store()` and skip the intermediate copy.

---

### 5. [Medium] Default admin ships with publicly-known credentials

- **File:** `private/users.json.blank:1`; seeded by `backend/Services/Auth/Adapters/JsonFile.php:43-45`
- **CWE:** CWE-1392 (Use of Default Credentials) / CWE-798 (Hard-coded Credentials)

**Description.** On first run `JsonFile::init()` copies `private/users.json.blank` to
`private/users.json`. That committed blank file contains an `admin` row whose bcrypt hash
matches the password **`admin123`** (verified with `password_verify`) — a value documented
openly in `README.md` and `docs/install.md`. The admin holds full permissions over homedir
`/`, and there is **no forced password change on first login**.

**Exploit.** An attacker who finds an internet-exposed instance left at defaults submits
`admin` / `admin123` and obtains a full-control admin session. Lockout does not help because
the first guess succeeds.

> Verifier note: severity adjusted from High to **Medium** because exploitation depends on
> operator inaction (the docs explicitly instruct changing the password) rather than a
> code-level bypass. Still worth eliminating — defaults that work are defaults that get left.

**Fix.** Don't ship a usable default password. Force a first-run setup that requires setting
the admin password before the app is usable, **or** generate a random admin password at
install and print it once. At minimum, set a `must_change_password` flag on the seeded admin
that blocks all other routes until rotated.

---

### 6. [Low] Session cookie `Secure` flag off by default

- **File:** `configuration_sample.php:66` (`'cookie_secure' => null`)
- **CWE:** CWE-614 (Sensitive Cookie Without 'Secure' Attribute)

**Description.** `NativeSessionStorage` is configured with `cookie_secure => null`. Symfony
skips `ini_set` for a null value, so the `Secure` attribute falls back to php.ini's
`session.cookie_secure` (off in stock images) — the `filegator` session cookie is emitted
**without `Secure`**. No HSTS header is sent (`Security.php` sets only `X-Frame-Options` and a
frame-ancestors CSP), and the shipped Docker images serve plain HTTP behind an
operator-supplied TLS proxy. `HttpOnly` and `SameSite=Lax` are correctly set, so this is a
transport-confidentiality gap only.

**Exploit.** On any deployment reachable over an `http://` hop (TLS-terminating proxy
forwarding HTTP, an http→https redirect, or sslstrip), the browser transmits the session
cookie in cleartext, where an on-path attacker can capture and replay it.

**Fix.** Set `cookie_secure => true` (or `'auto'`) in the shipped config, configure
`Request::setTrustedProxies` so `isSecure()` reflects `X-Forwarded-Proto` behind a proxy, and
emit a `Strict-Transport-Security` header for HTTPS deployments.

---

### 7. [Low] User enumeration via password-reset response timing

- **File:** `backend/Services/PasswordReset/PasswordResetService.php:100-131`
- **CWE:** CWE-204 (Observable Response Discrepancy)

**Description.** In `requestReset()`, an unknown email returns after a fixed
`usleep(random_int(50000,150000))` (50–150 ms), while a known email performs a
**synchronous, blocking** `$this->mailer->send()` (a real SMTP round-trip, clamped only by a
5 s socket timeout). The response body is byte-identical (`GENERIC_OK`) in both branches, so
the latency difference is the sole distinguisher — defeating the in-code anti-enumeration
comment. The fixed padding does not model real SMTP latency, which typically exceeds 150 ms.

**Exploit.** An attacker POSTs candidate emails (within/around the 5/day/email and 30/hr/IP
caps, rotating IPs) and measures latency: hundreds of ms / seconds → real account; 50–150 ms
→ non-existent. Impact is limited to reconnaissance/enumeration, hence Low.

**Fix.** Move delivery off the request path (queue the send, or
`fastcgi_finish_request()` after returning), so both branches are timing-indistinguishable.
If async is infeasible, measure elapsed time at the top of `requestReset()` and pad **both**
branches up to a fixed budget that exceeds worst-case send latency.

---

### 8. [Low] Latent XSS: shared error toast renders server strings via Buefy `v-html`

- **File:** `frontend/mixins/shared.js:134-158` (`handleError`); sink in Buefy 0.7.10 `Toast.vue`
- **CWE:** CWE-79 (Cross-Site Scripting)

**Description.** `handleError()` passes the raw server error string
(`error.response.data.data`) as the Buefy `$toast.open({message})` prop. In Buefy 0.7.10 the
Toast/Dialog/Snackbar components render `message` with **`v-html`**, not text interpolation
(verified in the 0.7.10 source), and `lang()` returns unmatched strings verbatim. The backend
reflects user-controlled input in some 422 bodies — e.g. `User::checkValidRole` throws
`"User role {$role} does not exists."` with the raw value, and `role`/`permissions` are **not**
constrained by the `storeUser`/`updateUser` validator — and `UserEdit.vue` routes
string-typed 422 bodies into `handleError`, reaching the `v-html` sink.

**Exploit.** The only endpoints that reflect attacker-controlled strings into this branch
(`/storeuser`, `/updateuser`) are **admin-only**, so the demonstrable exploit is admin
**self-XSS** — no privilege boundary is crossed today. The real risk is latent: `handleError`
is the single shared error path for the whole app, so any future backend message that
reflects untrusted input (a filename, an email) silently becomes an XSS sink.

> Verifier note: adjusted from Medium to **Low** (self-XSS only at present), but the
> app-wide `v-html` footgun justifies fixing the shared helper, not just the one endpoint.

**Fix.** In `handleError`, HTML-escape the message before display (or use a toast wrapper that
uses text interpolation). Additionally, validate `role`/`permissions` server-side before they
reach the exception string and return generic, non-reflective error messages.

---

## Dependency findings

| Source | Package | Version | Severity | Note |
|--------|---------|---------|----------|------|
| `composer audit --locked` | `spomky-labs/otphp` | 11.2.2 | High + Medium | **Production** dep — see Finding 2. Fix in 11.4.3. |
| `npm audit` | `ws`, `yargs-parser`, others | — | 16 critical / 44 high / 105 mod / 15 low (180 total) | **Dev-only** toolchain (webpack, jest, webpack-dev-server, etc.). Not shipped in `dist/`. |

The 180 `npm audit` advisories are almost entirely in build/test tooling
(`webpack-dev-server`, `jest-environment-jsdom-fifteen`, `concurrently`) and do not reach the
production bundle. They are still worth clearing with `npm audit fix` to protect the build
environment, but they are a much lower priority than the production `otphp` advisory.

---

## Reviewed and dismissed

These candidates were investigated and **dismissed** by adversarial verification — listed so
the audit is auditable. Several are legitimate robustness/hygiene nits that are simply not
*reachable security vulnerabilities*.

| Candidate | Why dismissed |
|-----------|---------------|
| Chunk reassembly triggered by attacker-declared total size (partial files) | Self-inflicted only — attacker corrupts their own upload; no privilege/tenant boundary crossed. Robustness bug, not a vuln. |
| `MfaLockout` counter incremented with unlocked `FILE_APPEND` | Single-byte `O_APPEND` writes are atomic on local FS; loss is bounded, self-correcting, and dual-counter. Negligible. |
| Password reset doesn't invalidate existing sessions | **Contradicted by code:** the session hash incorporates the stored password hash and is re-validated every request (`Router.php:33`), so changing the password deauthenticates all other sessions structurally. |
| CORS reflects arbitrary Origin with `Allow-Credentials: true` | CORS is disabled in production by default **and** `SameSite=Lax` blocks the cross-site credentialed request. Exploit needs two non-default misconfigs. Defense-in-depth only. |
| Session-hash compared with loose `==` in `Database` adapter | Both operands are server-side; right operand is always a string (no type juggling); no timing oracle. Hygiene nit (`hash_equals` preferred). |
| Audit key / PII log not git-ignored | Deployment-hygiene gap outside the app's trust boundary; `private/.htaccess` blocks web serving. No attacker-reachable path. |
| `private/` protected only by Apache `.htaccess` | Supported document root is `dist/` (platform-neutral); exposure needs the operator to misconfigure the doc root against the docs. |
| App log at DEBUG level by default | Config-tuning observation in a `*_sample` file; no untrusted input, and `private/` is not web-served. Info at most. |

---

## Remediation

Findings 1, 2, 5, 6, 7, 8 were fixed on `claude/app-security-audit-l9xp3i`, each as a
focused commit with tests where applicable. Findings 3 and 4 were independently fixed
upstream in **PR #71** (`fix(security): isolate batch archives and chunked uploads
across tenants`); this branch adopts that fix via merge rather than duplicating it.

| # | Fix | Key files |
|---|-----|-----------|
| 1 | Escape the login username with `ldap_escape(..., LDAP_ESCAPE_FILTER)` before it enters the search filter | `backend/Services/Auth/Adapters/LDAP.php` |
| 2 | Bump `spomky-labs/otphp` to `^11.4.3` (locked to 11.5.0); `composer audit` now clean | `composer.json`, `composer.lock` |
| 3 | _(PR #71)_ Bind each batch-archive id to the creating session and reject unowned ids; generate the id with `random_bytes` instead of `uniqid()` | `backend/Controllers/DownloadController.php`, `backend/Services/Archiver/Adapters/ZipArchiver.php` |
| 4 | _(PR #71)_ Reassemble uploads under a per-user namespaced tmpfs key, truncate-first; store under the sanitized name via new `Tmpfs::sanitizeFilename()` | `backend/Controllers/UploadController.php`, `backend/Services/Tmpfs/*` |
| 5 | Randomize the seeded admin password on first run and surface it once in `private/INITIAL_ADMIN_PASSWORD.txt` (git-ignored); docs updated | `backend/Services/Auth/Adapters/JsonFile.php`, `README.md`, `docs/*` |
| 6 | Set the cookie `Secure` flag when the request is HTTPS (incl. `X-Forwarded-Proto`); emit `Strict-Transport-Security` on HTTPS | `configuration_sample.php`, `backend/Services/Security/Security.php` |
| 7 | Pad both `/password/forgot` branches to a common, configurable timing floor (default 1000ms) | `backend/Services/PasswordReset/PasswordResetService.php` |
| 8 | HTML-escape server/error strings before the Buefy `v-html` toast; stop reflecting the submitted role/permission value in 422 bodies | `frontend/mixins/shared.js`, `backend/Services/Auth/User.php` |

New regression tests: first-run admin-password randomization (incl. "existing file is
never re-seeded"). Findings 3/4 ship their own isolation tests via PR #71.

## Recommended remediation order

1. **Finding 2** — bump `spomky-labs/otphp` to `^11.4.3` (one-line, removes a known-vuln MFA dep). Add `composer audit` to CI.
2. **Finding 1** — add `ldap_escape()` in the LDAP adapter (small, contained, High impact for LDAP deployments).
3. **Finding 3 & 4** — fix tmpfs ownership/namespacing together (shared root cause: unscoped, predictable tmpfs names). Use CSPRNG tokens + per-session binding.
4. **Finding 5** — eliminate the working default admin password (force first-run rotation).
5. **Findings 6, 7, 8** — harden cookie `Secure`/HSTS, decouple reset email timing, and escape the shared error toast.

> Re-run this audit (and `composer audit` / `npm audit`) in CI so regressions and newly
> disclosed advisories are caught automatically.
