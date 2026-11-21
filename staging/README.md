# staging/

UAT checklists for business-user testing sessions. Not part of the application source; not loaded by the running app.

| File | Purpose |
|---|---|
| [UAT-checklist.md](UAT-checklist.md) | Printable checklist for business-user testing sessions |
| [UAT-checklist-mfa-password-reset.md](UAT-checklist-mfa-password-reset.md) | Checklist for the MFA and password-reset changes |

The staging environment itself (Dockerfile, compose file, Caddy reverse proxy and runbook) lives in the `filegator-infra` repository under `staging/`. The deploy kit that used to sit here was removed because it built on `php:8.3-apache-bullseye`, which stopped receiving PHP security updates in Aug 2025, and ran an unpinned Caddy. Use the `filegator-infra` setup instead.
