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

### Disabling

Leave the service block out of `configuration.php` entirely. The feature then
safely no-ops — file operations are unaffected and nothing is written.
