# Origin-API-Key CORS fix — October 16, 2026

## Deployment result

Deployed to UAT first, then production on VPS5. The browser preflight now permits `Origin-API-Key` alongside the existing headers. The canonical controller lookup was already correct and was not changed.

- **UAT:** patched the allowed-header strings in `app/Common.php` and `app/Config/BootstrapGuard.php`. OPTIONS remains `204` and continues reflecting allowed frontend origins.
- **Production:** patched its existing `app/Common.php` and `.htaccess` header strings. Its existing OPTIONS `200` response, wildcard-origin policy, methods and rewrite behavior were preserved. No UAT bootstrap/filter files were installed in production.
- Production verification also exposed an existing `500`: `csr_api_key_is_valid()` called `myt_env()`, which was missing from its legacy Constants implementation. The existing Core reader was added conditionally to production Common, preserving its configuration lookup and key-comparison rules. The same compatibility fallback is included in the local Common source and covered by an isolated regression test; UAT already has the reader in Constants.

The first production attempt restored its exact original files automatically when verification failed. Those backups remain in `prod_attempt1_recovered`. The compatibility correction was tested before the successful retry.

## Verification

- **7 CORS/compatibility tests, 66 assertions passed.** Both native PHP header emitters were exercised through an isolated loopback server with no database access. Cases include lowercase/mixed-case requested headers, preservation of existing headers, missing/unknown origins, error responses and legacy key validation.
- In both live environments, configured normal/origin keys returned JSON with **HTTP 200**. Invalid normal keys, invalid origin keys, and ordinary authentication with an invalid token returned **HTTP 401**; all retained the expected CORS headers.
- PHP syntax, Apache configuration validation, exact release checksums and byte-level patch audits passed. Only the reviewed header edits and production compatibility helper were installed.
- Existing origin, method, credential and preflight policies were preserved. Controller/authentication files, configuration, nginx, database schemas and business records were unchanged. GET verification retained normal API audit logging.
- The broader SecurityTest run passed 13 of 14 tests. The unchanged `testEmailViewEscapesXss` fails because the email template no longer renders the supplied name. That unrelated template/test was not modified.

## Clean curl examples

Use a plain URL, not a Markdown link. Header names use hyphens. `Origin` is the frontend URL; `Origin-API-Key` is the configured origin key. Browsers supply `Origin` themselves.

### UAT preflight — no credentials required

```bash
curl --silent --show-error --dump-header - --output /dev/null \
  --request OPTIONS \
  --url 'https://zuat-accounting-back.myt-emr.com/items/get_items_list?kind=pharmacy&branch_id=2' \
  --header 'Origin: http://192.168.1.11:3001' \
  --header 'Access-Control-Request-Method: GET' \
  --header 'Access-Control-Request-Headers: api-key,origin-api-key'
```

Expected: `204`, the matching `Access-Control-Allow-Origin`, and an allowed-header list containing both `api-key` and `Origin-API-Key`.

### GET — replace placeholders with your credentials

```bash
API_KEY='YOUR_NORMAL_API_KEY'
ORIGIN_API_KEY='YOUR_CONFIGURED_ORIGIN_API_KEY'
REQUESTER='10'
TOKEN='YOUR_TOKEN'

curl --silent --show-error --dump-header - --output /dev/null \
  --get \
  --url 'https://zuat-accounting-back.myt-emr.com/items/get_items_list' \
  --header 'Origin: http://192.168.1.11:3001' \
  --header "API-KEY: ${API_KEY}" \
  --header "Origin-API-Key: ${ORIGIN_API_KEY}" \
  --header 'Accept: application/json' \
  --data-urlencode 'kind=pharmacy' \
  --data-urlencode 'branch_id=2' \
  --data-urlencode 'page=1' \
  --data-urlencode 'data_size=1' \
  --data-urlencode "requester=${REQUESTER}" \
  --data-urlencode "token=${TOKEN}"
```

This example prints response headers and discards item data. For production, use `https://accounting-back.myt-emr.com/items/get_items_list` and frontend origin `https://accounting-front.myt-emr.com`. Production OPTIONS retains its existing `200` status. No `Referer`, browser fingerprint headers, or underscore-header support is needed for this fix.

Reload the frontend and retry the request after deployment. Normal API-key validation remains required.

## Backups, hashes and recovery

On VPS5:

- `/root/origin_api_key_cors_20261016_000348/uat`
- `/root/origin_api_key_cors_20261016_000348/prod`
- `/root/origin_api_key_cors_20261016_000348/prod_attempt1_recovered`

Each environment folder contains exact previous files, staged release files, SHA-256 manifests, original ownership/modes, preflight results, protected-file hashes and credential-free HTTP summaries. Restore the exact previous files with their saved ownership/modes and refresh the active PHP-FPM opcode caches if recovery is needed. Retain the backups and verify OPTIONS/GET responses after restoration.

[Machine-readable checksums and verification](origin_api_key_cors_20261016.json).
