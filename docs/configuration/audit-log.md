---
currentMenu: audit-log
---

## Audit Log service

Records a global, admin-only trail of file **write-mutations** — uploads,
new folders, copies, moves, renames, deletes, zip/unzip, chmod, and in-app
edits — across **all** users and folders. Reads (listing, downloads) are not
recorded. Administrators view it from the **Audit Log** item in the top
navigation.

```
        'Filegator\Services\Audit\AuditLog' => [
            'handler' => '\Filegator\Services\Audit\AuditLog',
            'config' => [
                'log_file' => __DIR__.'/private/audit_log.jsonl',
                'key_path' => __DIR__.'/private/audit_encryption.key',
                'max_age_days' => 30,
            ],
        ],
```

### What each event stores

One record per action: timestamp, username, role, action, the **root-relative**
path the action touched (so two users' `/return.pdf` are distinguishable),
an optional detail (e.g. the source path for a move, the mode for chmod), and
the client IP. This is personal data — treat the file as sensitive.

### Encryption at rest

Each line is encrypted with libsodium (the same primitive used for MFA
secrets), using a **dedicated** key auto-generated at `key_path` with `0600`
permissions on first use. A leaked log file or backup is useless without the
key, so:

- **Back the key up separately from the log.** If the key is lost, the
  existing history becomes permanently unreadable.
- The file is intentionally opaque to `tail`/`grep`.

Encryption protects a *leaked file*; it does not protect against a fully
compromised web process (which holds the key).

### Retention

Entries older than `max_age_days` (default **40**) are **physically deleted**,
not just hidden. The purge runs lazily on write, at most once per day, so
normal activity is unaffected. Set `max_age_days` to match your data-retention
policy.

The default is 40 rather than 30 because the [monthly report](reports.html)
needs a whole calendar month to still be inside the retention window — `query()`
applies its cutoff *before* any date filter, so a 30-day log cannot answer for a
31-day month and every report would be silently short. **32 is the floor.**
Raising retention also means raw PII, including source IPs, stays on disk
longer; that is a privacy decision, not just a tuning knob.

Reports generated from this log have their own, separate retention — see
[Monthly activity reports](reports.html). Purging the log does not purge them.

Note that a user with `write` or `chmod` permission can inflate the log
cheaply — a single bulk request records one entry per item, and `chmod` does
not require the paths to differ. The file is unbounded within the retention
window, so `private/audit_log.jsonl` is worth watching on a busy or untrusted
deployment.

### Reports and CSV export

There are **two producers of this CSV**, and they emit the same format:

1. **On demand, in the browser.** Administrators pull a 30-day rollup from the
   **Reports** item in the top navigation and export it. Plaintext, straight to
   the admin's disk.
2. **Monthly, on the server.** A cron job builds a per-calendar-month CSV,
   stores it *encrypted* under `private/reports/`, and emails admins a
   notification carrying no event data. See
   [Monthly activity reports](reports.html).

The two agree on columns, quoting and the formula-injection guard — a shared
vector fixture is asserted by both the PHP and JavaScript test suites, so a
change to one that is not made to the other fails the build. They differ in one
column: `timestamp_local` renders in the *viewer's* timezone in the browser and
in the *server's* configured timezone from cron. Join or order on
`timestamp_unix`, which is timezone-independent and never modified.

**An exported CSV is outside everything this service guarantees.** The log on
disk is encrypted, mode `0600`, and purged at `max_age_days`; the export is
plaintext, written with the browser's default permissions, and **never
expires**. Treat it as a deliberate act with a retention obligation attached:

- Delete exports in line with the same policy that sets `max_age_days`.
- Watch out for cloud-synced download folders — an export dropped in a synced
  `Downloads` directory propagates client file paths off the machine.
- The filename is marked `CONFIDENTIAL` so it stays recognisable if forwarded.

The export deliberately **omits the source IP**, which is recorded in the log
but has never been shown in the UI. The remaining columns are the timestamp
(unix, UTC ISO-8601, and the viewer's local time), user, role, action, path,
folder, and detail. `role` is the role at the time of the event, not the
user's current role.

Values are quoted per RFC 4180, and any value whose first meaningful character
is `=`, `+`, `-`, or `@` is prefixed with an apostrophe so a spreadsheet does
not evaluate it as a formula.

That prefix is not perfectly reversible, and machine consumers should know it:
an added apostrophe is indistinguishable from one that was genuinely part of
the value. Stripping a leading `'` before `=`, `+`, `-`, `@` or whitespace
recovers every value the guard modified, but it also corrupts the rare value
that legitimately began with an apostrophe followed by one of those
characters. The `timestamp_unix` column is never modified, so use it for
joins and ordering rather than re-parsing text columns.

Fetching the log — by the Audit Log view or by loading the Reports page — is
recorded in the application log with the requesting administrator and the
window requested. Note that the CSV is generated in the browser from data
already fetched, so repeated exports from a single page load produce one log
entry, not one per download.

### Disabling

Leave the service block out of `configuration.php` entirely. The feature then
safely no-ops — file operations are unaffected and nothing is written. The
Audit Log and Reports views then show no activity; they cannot distinguish an
unconfigured service from a genuinely quiet one.
