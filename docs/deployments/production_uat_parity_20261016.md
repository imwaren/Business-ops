# Production migration to UAT configuration and security

## Result

Successfully deployed on **October 16, 2026 (Asia/Manila)** on VPS5 to `accounting-back.myt-emr.com`.

Production retains PHP **8.3** and PHPMailer **7.0.2**. CodeIgniter is now **4.7.4**, matching UAT. The locked supporting packages are laminas-escaper **2.18.0** and psr/log **3.0.2**. PHPMailer, previously installed outside the production lock, is now an explicit locked dependency.

UAT source/configuration hashes are unchanged. Production maintenance is removed; nginx, Apache, PHP 8.3-FPM, PHP 8.2-FPM and cron are active. Production CLI schedules and unrelated schedules are restored.

## Installed behavior

- Adopted UAT's compatible web/CLI bootstrap and configuration structure, security filter and exception handler, while preserving production URLs, legacy API routing and unrelated production implementations.
- Moved the existing production configuration into private `.env`, mode **0600**. Application-user access was verified. Existing API/CSR keys, encryption keys, payload encryption settings, database credentials/mappings, SMTP credentials and VM4/DICOM configuration compared equal to the captured production baseline.
- All **15 database connection groups** now read their effective settings from environment configuration. DSNs cannot override those settings with stale schema/host values. All 15 connections passed read-only schema identity checks; no UAT schema is selected.
- Enabled `ERROR_RESPONSE_DETAILS=true`, as requested. Uncaught API HTTP 500 errors use the redacted `code/message/error` envelope with type, message, relative file and line. Stack traces and arguments are omitted. Daily logging uses Manila dates. Explicit controller responses retain their behavior.
- Applied deployed UAT's active API-key/token checks, permission handling, administrator helpers, login throttling, password handling, file/printing protections and sensitive-data handling. No unrelated UAT-only business or recovery endpoints were introduced.
- Preserved all existing page/permission records: **25,064 permission rows**, **0 denied rows** at verification. No permission import, role reassignment, password reset or live login test occurred.
- Production uses the same **60-entry effective CORS origin allowlist** as UAT, as selected, including its UAT/local development origins. OPTIONS now returns **204** through PHP. Both `API-KEY` and `Origin-API-Key` are allowed; the underscore header spelling is not supported.
- Enabled the UAT security-header filter and production HTTPS/secure-cookie settings while retaining existing session cookie/expiration settings.
- Applied email output escaping to four production templates without replacing their content or field choices. The password-reset template continues using the production first/last-name fields.
- Removed **13 embedded encryption-key references** from three production models, replacing them with the existing configured model property. A mock connection captured **nine queries across nine methods**, identical before/after; **zero queries were executed** by that fixture. The encryption key itself was unchanged.

The application/configuration manifest contains **43 affected files including private `.env`**. The accompanying JSON lists hashes for the other 42 files; private environment contents/hashes were not exported. Unrelated production application-file hashes remain unchanged. No PHP controller was replaced wholesale merely because it differed from UAT.

## Web-server and scheduler changes

Production-only nginx includes:

- `/home/mytemr/conf/web/accounting-back.myt-emr.com/nginx.conf_accounting_parity`
- `/home/mytemr/conf/web/accounting-back.myt-emr.com/nginx.ssl.conf_accounting_parity`

These enforce sensitive-path denial before static-file handling. Final HEAD requests to `.env`, Composer manifests, vendor metadata, tests, scripts and deployment documentation returned **404**. Apache `.htaccess` protections are also installed. The final nginx includes contain no temporary maintenance bypass secret or maintenance gate.

All **11 production HTTP cron jobs** now invoke the private helper:

`/home/mytemr/conf/web/accounting-back.myt-emr.com/production-accounting-cron.php`

The helper reads the production cron secret from `.env` and sends `X-Cron-Secret`; the secret is not present in command arguments. Both installed crontab and Hestia's persistent cron configuration contain the updated commands. Schedules, target URLs, parameters, unrelated UAT jobs and production CLI job definitions were preserved. The helper's application-user readability was checked without executing a real job.

## Verification

- Local targeted PHPUnit suite: **33 tests, 222 assertions**, all passed. Includes security, CORS, detailed errors, environment-backed connection settings and configured model encryption references.
- The stale local email-escaping test now supplies the field actually rendered by the local template. Production template escaping was separately exercised with synthetic names/links; no email was sent.
- Staged and installed runtime checks passed for framework/mailer versions, production mode, detail flag, URLs, TLS, existing keys, CORS origins, cron credentials, roles, legacy password verification, configured hashing, sensitive fields, input safeguards, all database groups, headers, error shape, printing visibility, template escaping, routes and sessions.
- Mocked controller/login checks passed for valid/invalid/expired tokens, normal/origin API keys, CSR compatibility, administrator/self access, permission denials and missing-row behavior, remap rejection, cron authorization, uniform login errors, mocked password rehashing and throttling. All model writes were mocked.
- Composer manifest validation, platform requirements, locked dependency audit and CLI route loading passed. The dependency audit reported no advisories. Composer emitted informational version-constraint recommendations; validation exited successfully.
- PHP syntax and diff checks passed. Installed file hashes, staged file hashes and complete vendor-tree fingerprints match.
- Final normal public GET checks, with no maintenance-bypass header: configured credentials **200**; invalid normal key, invalid origin key and ordinary invalid-token authentication **401**. JSON/CORS checks passed and response-field structures matched the previous production baseline.
- Final public preflight **204**, denied unknown browser origin, restored service states, sensitive-file HEAD denials and scheduler preservation passed.
- The final source scan found **zero remaining occurrences of the migrated configured secret values in application PHP files**. This scan is scoped to the captured configured values, not a claim that all possible secrets or vulnerabilities have been audited.
- No new error/critical entries referring to the changed guard/handler were found in the inspected log tail.

No database schema change, historical update, accounting posting/rebuild, business-record creation or test email was performed. Read-only HTTP verification retains the application's normal API audit logging. No public failure endpoint was created and no real cron job was invoked for testing.

## Checksums

Complete before/installed application hashes and vendor fingerprints are in `production_uat_parity_20261016.json` alongside this report.

Selected installed SHA-256 values:

```text
index.php
6ee7aa54e483dde47ea01b9bbffd6fc181c7f7e5f4edcab1bbb04fbcd19f2c41

composer.json
de6384cc34a7f3674bf031badefa95ca66a9c5d7b7cbbcce89d4829ab14439b0

composer.lock
ecbda48f164171024439da8c23fbf637010594635dc8f3b65d74c46146cacb66

app/Config/Database.php
b9fbc26176ac85fae19a4ac1db57316ab0937d9edbc48f0fa52526e1b22d50fd

app/Core/MYT_Controller.php
0eb699245607b51b41d8b720c2e6792f7f400499f0b80510e46b86ede4059989
```

## Backups and recovery

Private VPS5 release/backup root:

`/root/prod_uat_parity_20261016_010730/prod`

The parent directory contains deployment/build programs, scoped fixtures and the coherent staged release, including the exact locked dependency manifests. `before/` contains original application/vendor archives, original front-controller/CLI/Composer/environment files, original vhost files and scheduler definitions. Private metadata records original owners, groups, modes and hashes. The three model originals and query-equivalence evidence are retained under `model_literals/`.

The previous production vendor tree is also retained at:

`/home/mytemr/web/accounting-back.myt-emr.com/private_parity_20261016_010730/vendor.before`

Two earlier cutover attempts were recovered before successful installation: proxy gate placement/readiness was corrected, and final readiness now waits for nginx's graceful worker transition. Exact application/vendor/configuration and scheduler restoration was verified; those recovery results are recorded in the JSON report. No database rollback was involved.

Recovery must first place production under maintenance and pause its affected jobs, then restore a coherent set of the saved PHP files, private environment and old dependency tree. Remove new bootstrap/filter dependencies only after restoring their callers. Restore the original web-server and Hestia/crontab definitions, validate configurations, refresh opcode caches, verify original hashes/readiness, then resume traffic and schedules. Do not restore whole archives over later unrelated deployments or restore business data; stop on deployment drift. Keep the hold if recovery validation fails.
