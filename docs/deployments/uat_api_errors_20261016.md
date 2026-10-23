# UAT API error responses and daily bootstrap logging

Deployed on October 16, 2026 (Asia/Manila), to VPS5 UAT only.

## Installed scope

- `app/Config/BootstrapGuard.php`: framework-independent error formatter, pre-DotEnv detail flag lookup, redaction, and daily logger fallback.
- `app/Libraries/CorsExceptionHandler.php`: detailed JSON for uncaught web HTTP 500 exceptions when the flag is enabled; preserves framework-managed exception logging.
- `index.php`: startup checks inside the exception boundary, passes the original exception, and removes duplicate outer-catch logging.
- UAT private `.env`: `ERROR_RESPONSE_DETAILS = true`. Credentials and other configuration are preserved.

UAT runs in production mode. Its existing additional CORS origins and Origin-API-Key allowance were preserved. Local `.env.example` defaults the detail flag to false. Local application files can differ from release files because UAT's existing CORS origins and formatting were retained.

## Response contract

Illustrative response; no live failure route was created:

```json
{
  "code": 500,
  "message": "something went wrong",
  "error": {
    "type": "Error",
    "message": "Call to undefined function example()",
    "file": "app/Controllers/Items.php",
    "line": 123
  }
}
```

Details contain no stack trace, arguments or request payload. Known configured credentials, labelled tokens, database error values and SQL text are redacted. File locations within the application are relative; external files show only an external marker and basename. Readiness failures without a Throwable use `BootstrapError` and null file/line.

Non-500 responses and explicit controller responses retain their existing behavior. Disabling or omitting the flag preserves the existing error-response behavior. OPTIONS handling and authentication remain unchanged.

## Daily logging

Bootstrap failures use `writable/logs/log-YYYY-MM-DD.log` with Asia/Manila dates. The framework logger is preferred; an exclusively locked direct append is used when unavailable, and the PHP server log is used only if daily logging cannot succeed. Framework exceptions retain their existing framework daily logging. The old `boot-error.log` is unchanged and receives no new bootstrap entries from the updated guard.

## Verification

- Local PHPUnit: **17 tests, 163 assertions**, all passed (`ErrorHandlingTest`, `CorsHeadersTest`).
- Tests cover the original early exception through `index.php`, production-mode framework rendering, flag disabled/defaults, readiness failures, redaction, invalid UTF-8, relative paths, HTTP 500/JSON/CORS, Manila midnight, logger fallback and single-entry logging.
- PHP syntax passed locally and for all installed PHP files under remote PHP 8.2 and 8.3; diff checks passed.
- Private UAT CLI rendering passed: pre-DotEnv flag, daily filename, redaction, bounded detail fields and production-mode handler. It invoked no application route, database query or live exception logger.
- Normal read-only UAT GET with configured keys returned **200**. Invalid normal key, invalid origin key and ordinary invalid-token authentication returned **401**. All retained JSON and CORS headers.
- Preflight from `http://192.168.1.11:3001` returned **204**, with unchanged origin/method policies and both API-key headers allowed.
- Installed hashes matched the reviewed release. No recent error/critical lines referencing the changed guard or handler were found in the inspected log tail.
- Historical bootstrap-log size and modification time remained unchanged.
- Cron configuration and service states remained unchanged. No maintenance setting was changed. PHP-FPM was gracefully reloaded to refresh opcode caches.
- Protected production files/configuration and unrelated UAT files matched their before-deployment hashes. No database, schema, business-record or historical-log changes were made.

## Installed SHA-256

- `app/Config/BootstrapGuard.php`: `9b35a8152efadc7d682703d0907744cbb846269c33cdfd491f7ed55cb7b5a207`
- `app/Libraries/CorsExceptionHandler.php`: `58f1dd2b931f39060795efc0c2054638daee03c1621df44214f6f20026d64c3a`
- `index.php`: `6ee7aa54e483dde47ea01b9bbffd6fc181c7f7e5f4edcab1bbb04fbcd19f2c41`

The adjacent JSON report records before/after hashes, ownership/modes and sanitized verification results. Private configuration hashes are retained only on the server.

## Backups and recovery

VPS5 backup directory: `/root/uat_api_errors_20261016_004417/uat` (mode 0700).

Exact original PHP files and private `.env` are under `before/`. `manifest.private.json` records original hashes, owners, groups and modes. The reviewed release package, deployment program and private probes are in the parent directory. No test fixture or operational script was installed in the public application.

Recovery restores the three original PHP files and `.env` with staged atomic replacements, using their recorded owners/groups/modes; gracefully reloads active PHP-FPM services; and verifies original hashes and preflight behavior. Restore the detail flag configuration before reverting dependent PHP. The deployment tool performs this recovery automatically if its verification fails. Recovery was not needed.

Production was not deployed. Public failure requests were not performed; exception HTTP behavior was verified with isolated loopback fixtures and the installed renderer was exercised privately over CLI.
