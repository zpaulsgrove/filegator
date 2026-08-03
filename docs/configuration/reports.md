---
currentMenu: reports
---

## Monthly activity reports

A scheduled job turns the [audit log](audit-log.html) into a per-calendar-month
CSV, stores it **encrypted on the server**, and emails admins a notification
that carries no event data. Admins download the report through an
authenticated, logged route.

The CSV is never attached to an email. It is a decrypted month of usernames and
full file paths; mailing it would put that in inboxes, at the mail provider and
in backups indefinitely — well beyond the audit log's own retention.

### Setup

Register both service blocks in `configuration.php` (they must come **before**
`Router`, which dispatches immediately on boot), then add a crontab line:

```
0 3 * * *  cd /path/to/filegator && php bin/filegator report:monthly
```

**Daily, not monthly.** The job is idempotent per calendar month, so a daily
tick does nothing on about 30 days out of 31 and *retries* a month that failed.
`0 3 1 * *` gives exactly one attempt per month — a host, mail server or config
error at that moment loses the month permanently.

Run it as the **same user as PHP-FPM**. Files the job creates (reports, the
report encryption key) must be readable by the web process, and vice versa.
Getting this wrong is the most common failure and it is quiet: a cron running
as root on a fresh install creates `private/audit_log.jsonl` root-owned `0600`,
after which the web process can never write to it and every file mutation goes
unrecorded.

Then check the wiring before you need the first report:

```
php bin/filegator report:preflight
```

It prints the window that would be reported, whether retention can cover it,
and whether this user can write `private/`. Exit code 2 means something needs
fixing. Run it again after changing `max_age_days`.

### Commands

| Command | Purpose |
|---|---|
| `report:monthly` | Generate any due months and notify. `--period=YYYY-MM` restricts to one month; `--force` regenerates a month already marked complete. |

`--period` obeys the same two guards the scheduled path does. It refuses the
current or a future month — reporting an unfinished window would store an
artifact labelled complete and then permanently skip the real month — and
without `--force` it will not regenerate a month that already succeeded, since
that mints a new report id and orphans any link an admin holds. With `--force`
the new report **replaces** the old one rather than accumulating beside it, so a
period never has two copies of the same PII at rest.
| `report:preflight` | Show the window, retention coverage and file ownership. Run after install and after config changes. |
| `report:status` | Show what has been generated per period. |

Exit codes: **0** ok or nothing due, **1** generation failed (retryable), **2**
misconfigured.

Exit 0 means the job genuinely ran. A misconfigured audit log or an unwritable
state file exits **2**, never 0 — otherwise cron would go green forever while
producing no reports, which is the failure this job exists to prevent. Lock
contention (two runs overlapping) is exit 0 by design: it is normal and
self-correcting.

A **corrupt** `report_state.json` is also a hard stop rather than a fresh
start. Treating an unreadable period map as "no state yet" would make every
period look never-generated, so the next run would rebuild them under new ids
and orphan any download link an admin holds. Inspect the file, then repair or
remove it — and note that removing it has exactly that regenerating effect, so
prefer repairing.

### Timezone

Month boundaries and the `timestamp_local` column follow the app's top-level
`timezone` setting. A per-service `timezone` key is available on the
`MonthlyReport` block for the rare deployment that wants reports on a different
calendar to the rest of the app; leave it unset otherwise.

### Retention coupling — read this before you deploy

`AuditLog::query()` applies its own retention cutoff **before** any date
filter, so a 30-day log physically cannot answer for a 31-day month. With the
old default of `max_age_days => 30`, every monthly report would be silently
short by a day or two.

`max_age_days` must therefore be **at least 32**, and ships as **40** to leave
slack for a run that is a day or two late. Raising it is a privacy decision,
not just a tuning knob — it keeps raw PII, including source IPs, on disk for
longer.

By default the job **refuses** to write a month it cannot fully cover
(`require_full_coverage => true`), logs exactly which value to raise, and exits
2. A partial compliance report is invisible until an audit; an absent one is
loud on day one. Set the flag to `false` to accept partial months instead —
they are then marked in three independently travelling places: `-PARTIAL` in
the filename, `coverage: partial` in the metadata, and `INCOMPLETE` in the
email subject.

`backfill_months` defaults to **1** because the useful backfill horizon is
bounded by retention: with `max_age_days => 40` only the previous month is ever
fully inside the window. Raise it only alongside `max_age_days`, roughly 31 days
per extra month, or you simply create a period that is permanently short.

A month whose last second already predates the cutoff is recorded once as
`unrecoverable` and never retried — no config change brings those events back.

### Storage and retention of the reports themselves

Reports live in `private/reports/` (`0700`), each file `0600`, encrypted with a
**dedicated** key at `private/reports_encryption.key`. Key separation means a
leaked reports key decrypts neither the audit log nor MFA secrets.

**Back this key up separately from the reports themselves.** Encryption
protects a leaked file or backup; if the key sits in the same archive as the
ciphertext, `tar czf backup.tgz private/` defeats it entirely. As with the
audit log, it does not protect against a compromised web process, which holds
the key.

`ReportStore.max_age_days` is a **second retention policy**, not a cache.
It defaults to 100 days (~3 months) deliberately: a long report archive quietly
rebuilds the PII store that `AuditLog.max_age_days` exists to physically
destroy, in blobs that can only be searched by decrypting all of them. Set it
from your data-retention policy. Once collected, a report is unrecoverable —
the raw events are long gone — so copy reports off-box if you need them longer.

Note also that the encryption authenticates the report *body* only. The
metadata an admin reads — period, event count, coverage — lives unencrypted in
`private/reports/index.json`, and ciphertext length still discloses roughly how
busy a month was.

### The notification

Sent to the single `recipient` configured on `AuditMailer`. That address is
often a shared mailbox with no account and no role, so the message carries only
the period, the event count, coverage status and a per-action breakdown — a
fixed enum, never a username or a path. The count stays out of the subject
line, which would otherwise reach mail-server logs, lock-screen previews and
mailbox search indexes.

`report_url_base` adds a "sign in and open Reports" link. It must be `https://`
— session cookies are only marked Secure over TLS — and is never derived from
the request `Host` header, for the same reason `PasswordResetService` refuses
to: an attacker who can set that header could otherwise point admins at a host
they control. Leave it null to omit the link.

A failed send is capped at three attempts and **never** regenerates the CSV;
that would mint a new report id and orphan a link an admin may already hold.
The report is the deliverable, the email is a convenience.

### Downloading

In the app, open **Reports** as an admin: generated months are listed under
*Monthly reports* with a per-row download button. Downloading prompts for
step-up credentials when the admin has MFA enrolled.

Under the hood, `GET /admin/reports` lists metadata and
`POST /admin/reports/download` streams one report, identified by period.

The download is a POST on purpose: `Security` skips CSRF validation for GET, and
`SameSite=Lax` cookies still ride a top-level cross-site navigation, so a GET
route would let an attacker page force a logged-in admin's browser to save a
CONFIDENTIAL CSV. It also requires step-up auth, matching how admin user CRUD
is gated. Every download is logged at WARNING with the admin's username — that
is the moment a month of PII leaves the encrypted store.

### Docker

The shipped image has **no cron daemon**, and `private/` is a volume. Run the
CLI from host cron or a sidecar container against that volume:

```
docker exec -u www-data <container> php /var/www/filegator/bin/filegator report:monthly
```

`bin/` must stay outside the web root (the document root is `dist/`). It ships
with a `deny from all` `.htaccess` as defence in depth, and the script refuses
to run under any SAPI other than CLI.

### Size limits

The whole month is loaded into memory to build the CSV, and decryption on
download holds several copies of the plaintext at once. `max_events` (default
250 000) makes the job refuse loudly rather than risk an out-of-memory fatal
that nobody reads in a cron log. On a busy deployment, expect the practical
ceiling to be tens of thousands of events per month on a default 128 MB
`memory_limit`; raise the limit for the CLI, or narrow the window.

Note that the log is cheap to inflate: a single bulk request records one entry
per item, and `chmod` does not require the paths to differ.
