# Hestia deploy — accounting-back.myt-emr.com

Panel: `https://vps5.znvme.mytdedi01.filipinowebservices.com:8083/`  
Public API: `https://accounting-back.myt-emr.com`

## Domain and PHP

1. In Hestia, add the web domain `accounting-back.myt-emr.com`.
2. Point the document root at this repo (the folder that contains `index.php` and `.htaccess`). Keep `.htaccess` so `/login` works without `index.php`.
3. Enable SSL (Let's Encrypt) for the domain.
4. Use **PHP 8.1, 8.2, or 8.3** for the domain (not 7.4). CI 4.7 will not boot on older PHP.
5. `vendor/` is **not** in git. On the UAT/prod document root (the folder that contains `index.php`):

```
cd /home/USER/web/zuat-accounting-back.myt-emr.com/public_html
composer install --no-dev --optimize-autoloader
ls vendor/codeigniter4/framework/system/Boot.php
```

If `Boot.php` is missing, every URL is an empty 500 and Chrome shows CORS.

6. Permissions:

```
mkdir -p writable/logs writable/session writable/cache
chmod -R 775 writable
```

If `index.php` cannot boot, it writes `writable/logs/boot-error.log` and returns JSON `{"error":"bootstrap_failed","code":"..."}` instead of a blank 500. Also read the Hestia domain log:

```
~/web/zuat-accounting-back.myt-emr.com/logs/error.log
```

## `.env` (never commit)

Copy `.env.example` to `.env` on the server and fill real values:

```
CI_ENVIRONMENT = production
app.baseURL = 'https://accounting-back.myt-emr.com/'
app.forceGlobalSecureRequests = true
cookie.secure = true
ROLE_ADMIN = 4
ROLE_IT = 17
API_KEY = ...
API_KEYS = ...
CRON_SECRET = <private-environment-secret>
ENCRYPTION_KEY = ...
database.default.hostname = 127.0.0.1
database.default.username = ...
database.default.password = ...
FRONT_END_URL = 'https://accounting-front.myt-emr.com'
CORS_ALLOWED_ORIGINS = 'https://accounting-front.myt-emr.com'
```

After TLS is live, keep `forceGlobalSecureRequests` and `cookie.secure` on.

## UAT (zuat-accounting-front)

On `https://zuat-accounting-back.myt-emr.com` set:

```
app.baseURL = 'https://zuat-accounting-back.myt-emr.com/'
FRONT_END_URL = 'https://zuat-accounting-front.myt-emr.com'
CORS_ALLOWED_ORIGINS = 'https://zuat-accounting-front.myt-emr.com'
MASTER_DB = mytemr_master_uat
```

(and the other `*_DB` / `*_uat` names). `Database.php` now reads those env names; putting `_uat` only in `.env` is enough.

Leave production `.env` on `https://accounting-front.myt-emr.com`. Reload PHP-FPM/Apache after editing `.env`.

### CORS vs HTTP 500

Chrome CORS + Postman empty 500 means PHP died before the app. After this deploy, Postman `GET /login` should return JSON with `error: bootstrap_failed` and a `code` (`vendor_missing`, `php_version`, `writable`, `boot_exception`) if boot still fails.

1. Read `writable/logs/boot-error.log` and `~/web/zuat-accounting-back.myt-emr.com/logs/error.log`.
2. Confirm domain PHP is 8.1+.
3. Run `composer install --no-dev` on **this** document root and confirm `vendor/codeigniter4/framework/system/Boot.php`.

Confirm after a good boot:

Postman `GET https://zuat-accounting-back.myt-emr.com/login` must not be an empty 500.

Confirm preflight after a good deploy:

```
curl -sI -X OPTIONS 'https://zuat-accounting-back.myt-emr.com/branches/get' \
  -H 'Origin: https://zuat-accounting-front.myt-emr.com' \
  -H 'Access-Control-Request-Method: GET' \
  -H 'Access-Control-Request-Headers: API-KEY'
```

Expect `204` and `Access-Control-Allow-Origin: https://zuat-accounting-front.myt-emr.com`. A GET without `Origin` will not show CORS headers; that is expected.

## Page and permission import

1. Confirm `page` and `permission` tables exist (see the `page` unique key on `controller,method`).
2. In phpMyAdmin, import [docs/seeds/page.csv](seeds/page.csv) then [docs/seeds/permission.csv](seeds/permission.csv) into the **master** database (`MASTER_DB`).
3. All seeded `permission.is_allowed` values are `1`. Later, set denied rows to `0`. Role `admin` (`role.id = 4`) always bypasses the matrix.
4. After adding controllers or methods, regenerate and re-import:

```
php spark pages:export-csv
```

## Accounting cron jobs

Both environments use their existing private `.env` `CRON_SECRET`. Scheduled HTTP requests send it in `X-Cron-Secret`; do not put secrets in command arguments or URL query strings.

Private helpers, outside the web root:

- UAT: `/home/mytemr/conf/web/zuat-accounting-back.myt-emr.com/uat-accounting-cron.php` (PHP 8.2).
- Production: `/home/mytemr/conf/web/accounting-back.myt-emr.com/production-accounting-cron.php` (PHP 8.3).

Configure Hestia commands using the appropriate helper and the existing target URL, for example:

```sh
/usr/bin/php8.2 /home/mytemr/conf/web/zuat-accounting-back.myt-emr.com/uat-accounting-cron.php https://zuat-accounting-back.myt-emr.com/cron/snapshot_item_inventory >/dev/null 2>&1
/usr/bin/php8.3 /home/mytemr/conf/web/accounting-back.myt-emr.com/production-accounting-cron.php https://accounting-back.myt-emr.com/cron/snapshot_item_inventory >/dev/null 2>&1
```

Both inventory snapshot schedules are `0 23 * * *` in Asia/Manila. Other existing schedules and CLI jobs are preserved. Keep installed user crontab entries and Hestia's persistent cron configuration consistent; changing only one lets a panel rebuild undo the repair.

The helper restricts each environment to its own HTTPS cron URLs, verifies TLS, refuses redirects, and prevents overlapping invocations of the same job. It writes status, elapsed time and failure categories to `writable/logs/log-YYYY-MM-DD.log` in Manila time. It never logs secrets, query values or response bodies and never retries automatically. Snapshot output contains only the date and seven branch row counts.

Each accounting Apache virtual host uses its supported `apache2.conf_accounting_cron` / `apache2.ssl.conf_accounting_cron` includes for `ProxyTimeout 300`. The global 30-second timeout and unrelated domains are preserved. Validate Apache configuration before reloading.

Cron authorization can be verified without running a job: an unimplemented `/cron/accounting_cron_verification_unimplemented` method returns 401 without a valid secret. After valid authorization it raises `PageNotFoundException`; the currently deployed bootstrap renders that exception as HTTP 500 rather than 404. Check the exception type privately to distinguish it from an unrelated failure. Do not add a public verification endpoint.

For a deliberate current-date snapshot, use the normal helper and `snapshot_date=YYYY-MM-DD` for the actual Manila date. Inspect all seven branch results and branch/master monthly rollups. Never use current inventory to label missing historical dates. After a timeout, check for still-running workers and partial snapshot completion before considering another invocation.
