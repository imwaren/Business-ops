# Security verification

## Phase 2 decisions applied

- CSRF remains off for this API-KEY + body-token API (enabling it would break clients).
- Authorization first on high-risk endpoints: users create/update/delete (admin role.id 4), change_password (self or admin), roles/permissions (admin), portal reset (admin), patients scoped by requester branch unless admin.
- `ROLE_ADMIN` (default 4) is the privileged API gate. `ROLE_IT` (17) is only for endorsement / email notification recipients.
- `password` / `token` / `token_expiry` stripped from list payloads.
- Cron requires CLI, `X-Cron-Secret`, or `?cron_secret=` (Hestia wget). Same `/cron/*` URLs.
- Methods named `get` or `get_*` skip `api_response` inserts.
- `forceGlobalSecureRequests` / `cookie.secure` stay env-gated (`app.forceGlobalSecureRequests`, `cookie.secure`). Enable on Hestia when TLS is on the vhost.
- Production base URL: `https://accounting-back.myt-emr.com/`. Deploy steps: [HESTIA_DEPLOY.md](HESTIA_DEPLOY.md).

## Page / permission

- Seed CSVs: [seeds/page.csv](seeds/page.csv), [seeds/permission.csv](seeds/permission.csv). All roles start with `is_allowed=1`.
- `_remap` enforces the matrix after API-key/token auth. Admin always allowed. Missing `page` row is allowed (new methods do not 403 until re-export).
- Later deny list: set `permission.is_allowed = 0` for those role/page rows.

## Remaining / accepted risks

- Shared API keys are now env-driven and support a comma-separated list, but clients still ship a long-lived key. Rotate and move to per-client keys when the mobile/SPA can change.
- Legacy auto-routing is still on. Dangerous helpers were not all made `protected`; inventory before flipping `autoRoutesImproved`.
- Document root is still the repo root. `.htaccess` now denies `.env`, composer files, `.git`, `vendor/`, `tests/`. Prefer a `public/` vhost later.
- `ENCRYPTION_KEY` must stay the current value until AES-encrypted patient fields are re-encrypted. Rotate after a data migration.
- Credentials previously committed in git history remain leaked until rotated (DB, SMTP, VM4, DICOM, API key, encryption keys).
- Query-string cron secrets appear in access logs.

## Phase 3 tool results (local)

- `composer audit`: no advisories (codeigniter4/framework v4.7.4).
- `phpunit --filter SecurityTest`: run after this pass.
- `phpstan analyse` (level 6, `app/Filters`): no errors expected.
- `semgrep` / `gitleaks`: not installed locally; both run on every PR via `.github/workflows/security.yml`.
- `npm audit`: N/A (no `package.json`).

## Manual VAPT retest

- `/login`: uniform error, 429 after 5 attempts / 15 minutes.
- `/cron/*` without secret → 401; with `?cron_secret=` or `X-Cron-Secret` → job runs.
- `users/change_password` as a non-admin user targeting another `user_id` → 401.
- `users/get` JSON has no `password` / `token`; `get` / `get_*` do not insert `api_response`.
- `files/get_file` with `../` and a non-VM4 URL → rejected.
- CORS from an origin not in `CORS_ALLOWED_ORIGINS`.
- Header checklist: CSP, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy.
- Authz matrix: admin vs non-admin on users/roles/permissions; later `is_allowed=0` → 403.
