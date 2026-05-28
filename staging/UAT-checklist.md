# FileGator UAT checklist — multi-folder + MFA hardening

**Tester:** ______________________ **Date:** _______________ **Role:** Admin / User (circle one)

> Successor to `UAT-checklist.md` (MFA + password reset). Everything in that earlier checklist still applies — if you haven't run it yet on this staging build, do that first, then come back here.

---

## What's new in this update

You're testing **two waves of changes** since the MFA + password reset UAT:

1. **Multi-folder access** — a single user can now be assigned to more than one folder. They pick which folder to work in after login, and switch between folders from a dropdown in the top nav.
2. **Tighter admin protections** — admins now have to re-enter their MFA code to create, update, delete, or reset MFA on another user. The code is only consumed if the request actually does something (no penalty for typos).
3. **Tighter MFA login** — the browser that finishes MFA must be the same one that started it. If you start MFA in Chrome and try to finish it in Firefox, it'll reject.
4. **Operator alerts on backup-code use** — when anyone (including admins) logs in with a backup code instead of their authenticator app, an alert email fires to the operator address.
5. **Weekly user digest email** — operators get a weekly summary of all users, their roles, folder assignments, and MFA status.

There are also under-the-hood security improvements (TOTP secrets are now encrypted at rest, rotating-IP brute force gets locked out, two-tab MFA races resolve cleanly) but those should be invisible to you in normal use.

**Heads-up:**

- Existing single-folder users see exactly what they saw before. Multi-folder behavior only kicks in for users assigned more than one folder.
- Admins who previously enrolled MFA: nothing changes about your own login. The step-up prompt is new and appears only when you click Create / Edit / Delete on the users screen.
- The first time you log in after this deploy, your old session won't carry over — that's expected (the same one-time logout as the MFA deploy).

---

## Tasks (please tick as you go)

### A. Multi-folder users (regular user)

You'll need at least one user account configured with 2+ folders for this section. Ask the admin running UAT to set one up if you don't have one. Suggested fixture: a test user assigned `/projects` AND `/personal`.

- [ ] **Single-folder user, no change:** Log in with a regular single-folder user. You should land directly in your folder as before, with no picker screen. Browse around briefly to confirm.
- [ ] **Multi-folder user, first login:** Log out, then log in as the multi-folder test user. After password (and MFA if enabled), you should see a **folder picker** screen listing your assigned folders.
- [ ] Click one of the folders → you land in that folder's file listing.
- [ ] In the top navigation bar, find the **"Switch folder"** dropdown. Open it.
- [ ] Switch to a different folder using the dropdown → the file listing updates to that folder.
- [ ] Upload a small file to folder A.
- [ ] Switch to folder B → confirm the file from folder A does **not** appear here.
- [ ] Switch back to folder A → file is still there.

**Deep-link behavior:**

- [ ] Copy the URL of a file or subfolder inside folder A while logged in.
- [ ] Log out.
- [ ] Paste the URL into the browser → you land on the login page.
- [ ] Log in → you should arrive at the deep-linked location (folder A's subfolder), **not** the picker. Active folder follows the deep link.

### B. Multi-folder users (admin assignment)

- [ ] Log in as an admin.
- [ ] Open the user management screen (the gear / admin menu).
- [ ] Click **Edit** on an existing user.
- [ ] Find the folder assignment area. There should be a list of folder rows with a **+** button to add another row.
- [ ] Add a second folder, save.
  - The step-up prompt asks for your admin password + 6-digit MFA code. Enter both.
- [ ] Log out and log in as that user → you should now see the picker.
- [ ] Back as admin: edit the same user, remove the extra folder, save (step-up prompt again).
- [ ] Log in as that user again → back to single-folder behavior, no picker.

**Create a multi-folder user from scratch:**

- [ ] Admin → **Add user** → fill in name, username, password, role.
- [ ] In the folder list, add 2 (or more) folder rows.
- [ ] Save (step-up prompt asks for your code).
- [ ] Log in as the new user → picker appears with both folders listed.

### C. Admin step-up confirmation (MFA-enrolled admins only)

This whole section only applies if your admin account has MFA enabled. If you've intentionally not enrolled, skip — but flag it in the "Anything weird?" box.

- [ ] **Create user:** Try to create a new user without filling in the step-up password / code → the form should reject and ask for them.
- [ ] **Update user:** Same — try updating without step-up → rejected.
- [ ] **Delete user:** Same — try deleting without step-up → rejected.
- [ ] **Reset another user's MFA:** From the admin panel, try to reset another user's MFA without step-up → rejected.
- [ ] All four succeed when you provide the correct password + a current 6-digit code.

**The "fat-finger" protection (this is the important one):**

- [ ] As an MFA admin, intentionally try to **reset your OWN MFA** from the admin panel. Provide a valid password + a valid 6-digit code.
  - You should get a "Cannot reset your own MFA from the admin panel" error.
- [ ] Immediately use the same 6-digit code to perform a real admin action (e.g., create a test user). It should be **accepted** — proof that the failed self-reset attempt did NOT burn your code.

### D. Tighter MFA login

This needs two browsers (Chrome + Firefox, or Chrome + Chrome Incognito).

- [ ] In browser A, go to the login page → enter username + password → arrive at the 6-digit code prompt.
- [ ] Copy the URL of the code prompt page.
- [ ] In browser B, paste the URL and try to enter a code. Or attempt to drive the `/login/mfa` endpoint from browser B with the same session cookie if you're API-testing.
  - Browser B should reject with "MFA challenge expired or missing" or similar.
- [ ] Back in browser A → enter the correct code → log in works as normal.

**Two-tab race (single browser, two tabs):**

- [ ] Open the login page in **tab 1**, enter password, arrive at the code prompt.
- [ ] Open the login page in **tab 2** (same browser), enter password, arrive at the code prompt.
- [ ] In **tab 1**, enter a code → it should reject ("expired"). Tab 2's password step overwrote tab 1's pending challenge.
- [ ] In **tab 2**, enter a code → it works.

### E. Operator alerts on backup-code use

You'll need access to the operator audit-alert inbox for this.

- [ ] Log out. Log in with your **backup code** instead of your authenticator app (toggle "Use a backup code" on the code screen).
- [ ] Login succeeds.
- [ ] The operator should receive an email titled **"MFA backup code used: \<your username\>"** within a minute.
- [ ] The email shows: username, source IP, and how many backup codes you have left.
- [ ] If your remaining count is **2 or fewer**, the email includes a "WARNING" line telling the user to regenerate.
- [ ] Try the same backup code again → rejected (single-use, unchanged from prior UAT).

**Failed backup-code attempts do NOT alert:**

- [ ] On the login code screen, enter an obviously-wrong backup code like `WRONG-00000` → rejected.
- [ ] Confirm the operator does **not** receive a "backup code used" email for that wrong attempt.

### F. Weekly user digest (operator-facing)

The weekly digest piggy-backs on the admin "List users" page — opening it triggers the scheduler if a digest is due.

- [ ] Log in as an admin → open the users list.
- [ ] If 7 days have passed since the last digest (or this is the first one after deploy), the operator inbox receives an email titled **"Weekly audit digest — N users (M with MFA)"**.
- [ ] The digest body shows every user (excluding the guest account) with: username, name, role, folder(s), permissions, MFA status, email.
- [ ] Multi-folder users show all their assigned folders, not just one.

### G. Existing flows from the MFA + password-reset UAT

Quick pin to make sure nothing regressed:

- [ ] Log in (with MFA where applicable) — still works.
- [ ] Upload, download, rename, move, delete a file.
- [ ] Password reset round-trip: Forgot password → email → reset → log in with new password.
- [ ] Regenerate MFA backup codes from your profile (still requires password + current code).
- [ ] Disable MFA on a test user account (still requires password + current code, still allowed for non-required roles).

### H. Smoke check (everyone)

- [ ] Page loads are not noticeably slower than before this deploy.
- [ ] No JavaScript errors visible in the browser console during normal use.
- [ ] Logout button still works and clears your session.

---

## Anything weird?

Note anything that surprised you — slow, confusing wording, ugly layout, missing buttons, errors, anything:

```
________________________________________________________________
________________________________________________________________
________________________________________________________________
________________________________________________________________
________________________________________________________________
________________________________________________________________
```

---

## Sign-off

> I confirm staging behaves correctly for my role and I'm OK with these changes going to production.

Signed: ______________________  Date: _______________

---

## Notes for the operator running the UAT session

(Strip this section before printing for testers — internal reference only.)

**Multi-folder fixture user.** If one doesn't exist on staging, create:
- Username: `uat-multi@example.test`
- Password: `uat-multi-2026`
- Folders: `/projects`, `/personal`
- Role: `user`
- Permissions: `read|write|download|upload|delete|zip|chmod|batchdownload`

**Operator audit inbox** is whatever address is set in `configuration.php`'s `Filegator\Services\Audit\AuditMailer` block → `recipient`. On staging this typically points to the staff alias.

**Step-up dialog UX for testers without MFA.** The dialog always opens regardless of MFA state. When the acting admin has no MFA enrolled, the dialog shows the password field only (no code field, no backup-code toggle). The tester still has to type their password to confirm sensitive actions — this is the new re-auth defense against stolen-session attacks. If the tester insists they should NOT see the dialog at all, that's a regression and worth flagging in "Anything weird?".

**Encryption-key file.** This deploy creates `private/mfa_encryption.key` on first MFA enrollment or first login by an existing MFA user. Mode `0600`. **Back it up alongside `users.json`** — losing one without the other makes every enrolled TOTP secret unrecoverable. Document this in your runbook before promoting to production.

**Per-username MFA lockout** isn't easily testable by hand without coordinating IPs. The behavior is covered by automated tests (`tests/backend/Feature/MfaTest.php::testMfaStep2LocksOutByUsernameAcrossRotatingIps`). For a manual smoke, you can verify the same-IP lockout still works: enter 5 wrong codes in a row → next attempt 429s for ~15 seconds.

**TOTP encryption at rest** is invisible from the UI. Verify implicitly by completing any MFA login after deploy (works → decryption works) and by confirming `private/users.json` no longer contains a base32 secret in the `mfa_secret` field for enrolled users (it should start with `v1$` after their first successful login post-deploy — lazy migration).
