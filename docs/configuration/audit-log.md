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

Entries older than `max_age_days` (default **30**) are **physically deleted**,
not just hidden. The purge runs lazily on write, at most once per day, so
normal activity is unaffected. Set `max_age_days` to match your data-retention
policy.

Note that a user with `write` or `chmod` permission can inflate the log
cheaply — a single bulk request records one entry per item, and `chmod` does
not require the paths to differ. The file is unbounded within the retention
window, so `private/audit_log.jsonl` is worth watching on a busy or untrusted
deployment.

### Reports and CSV export

Administrators can pull a 30-day rollup of this log from the **Reports** item
in the top navigation, and export the underlying events as a CSV.

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
not evaluate it as a formula. To recover an original value, strip a single
leading apostrophe **only** when the next character is one of those four or
whitespace.

Each read of the log — by the Audit Log view or the Reports export — is
recorded in the application log with the requesting administrator and the
window requested.

### Disabling

Leave the service block out of `configuration.php` entirely. The feature then
safely no-ops — file operations are unaffected and nothing is written. The
Audit Log and Reports views then show no activity; they cannot distinguish an
unconfigured service from a genuinely quiet one.
