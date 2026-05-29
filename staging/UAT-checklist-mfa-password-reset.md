# FileGator UAT checklist — staging

**Tester:** ______________________ **Date:** _______________ **Role:** Admin / User (circle one)

---

## What's new in this update

You're testing three changes that will go live on the production URL once we sign off:

1. **Multi-factor authentication (MFA)** — admins are now required to enroll an authenticator app on first login. Other users can opt in.
2. **Self-service password reset** — a "Forgot password?" link on the login screen emails you a reset link.
3. **Smoother session handling** after MFA/email/password changes — you should no longer get logged out unexpectedly mid-task.

**Heads-up:** when you log in for the first time on staging, your old session won't carry over — that's expected. If you're an admin, you'll be asked to set up an authenticator app immediately.

---

## Automated vs. manual

Most of this checklist is now covered by the automated E2E suite (`tests/frontend/e2e/`).
Items tagged **_(automated: spec)_** run on every CI build — a quick spot-check is enough.
Items tagged **_(manual)_** can't be meaningfully automated and still need a human:
the real authenticator-app scan, email **delivery + visual rendering**, and large-file "feel".

---

## Tasks (please tick as you go)

### Login & MFA

- [ ] Log in with your existing username and password _(automated: auth.cy.js, mfa-login.cy.js)_
- [ ] **(Admins only)** Complete MFA setup using Google Authenticator, Microsoft Authenticator, 1Password, or similar _(automated: mfa-setup.cy.js, mfa-enroll.cy.js — TOTP computed in-test; **the real-app scan itself is manual**)_
  - [ ] Scan the QR code or type the secret manually _(manual — real authenticator app)_
  - [ ] Enter the 6-digit code; reach the "backup codes" screen _(automated: mfa-enroll.cy.js)_
  - [ ] **Save the backup codes somewhere safe** — these get you in if you lose your phone
  - [ ] Refresh the page on the backup-codes screen → codes are still visible (this used to be a bug) _(automated: mfa-enroll.cy.js)_
- [ ] Log out and log back in — verify it prompts for your authenticator code _(automated: mfa-login.cy.js)_
- [ ] **(Admins only)** Log in once using a backup code instead of the authenticator _(automated: mfa-login.cy.js)_
- [ ] Try logging in again with the same backup code → should be rejected (codes are single-use) _(automated: backend MfaTest)_

### File operations (use a real folder you actually work in)

- [ ] Browse to your normal working folder — file list matches what you expect _(automated: file-ops.cy.js)_
- [ ] Upload a small file _(automated: file-upload.cy.js)_
- [ ] Download a file you uploaded _(manual — single-file download is `window.open`; batch download is automated in file-batchdownload.cy.js)_
- [ ] Rename a file _(automated: file-ops.cy.js)_
- [ ] Move a file between folders _(automated: file-move-copy.cy.js)_
- [ ] Delete a file (and restore from trash, if your role allows) _(automated: file-ops.cy.js)_
- [ ] Upload a large file (>50 MB) if relevant to your workflow _(manual — large-file feel; chunking is backend-tested)_
- [x] Open a deep link (a saved bookmark to a specific folder) while logged out → after logging in, you should land back at that folder, not the root _(automated: deep-link.cy.js)_

### Password reset round-trip

- [ ] On the login page, click **Forgot password?** _(automated: password-reset.cy.js)_
- [ ] Enter your email; the page should say "if an account exists, an email is on the way" _(automated: password-reset.cy.js)_
- [ ] Check your inbox (subject starts with **[STAGING]**) — usually arrives within a minute _(manual — email deliverability)_
- [ ] Email looks correctly branded (logo, color, support address) _(manual — email visual rendering)_
- [ ] Click the reset link → land on a "set new password" page _(automated: password-reset.cy.js)_
- [ ] Enter a new password → land on a confirmation, then log in with it _(automated: password-reset.cy.js)_

### Admin-only

- [ ] Create a new user, set their role and homedir _(automated: admin-users.cy.js)_
- [ ] Regenerate your MFA backup codes from your profile _(automated: mfa-manage.cy.js)_
  - [ ] Old backup codes stop working _(automated: backend MfaTest)_
  - [ ] New codes are shown immediately and survive a page refresh _(automated: mfa-manage.cy.js)_
- [ ] Disable MFA on a test account (requires the password + a current authenticator code) _(automated: mfa-manage.cy.js)_

---

## Anything weird?

Note anything that surprised you — slow, confusing wording, ugly layout, missing buttons, errors, anything:

```
________________________________________________________________
________________________________________________________________
________________________________________________________________
________________________________________________________________
```

---

## Sign-off

> I confirm staging behaves correctly for my role and I'm OK with these changes going to production.

Signed: ______________________  Date: _______________
