# Background Excel exports

Local implementation only. Generated workbooks stay on the VM serving the API environment. This pipeline makes no VM4 calls.

Import [the Postman collection](postman/Report_excel_exports.postman_collection.json) for all 19 mappings and authenticated status polling. `reports/get_payment_terms_excel` deliberately returns HTTP 400 (`base endpoint doesnt exist`); the JSON base action is absent.

## Request and worker

The Excel wrapper authenticates, checks Excel and base permissions, and calls the existing base action with the original request. Its successful JSON response is the snapshot: filters, pagination, omitted limits, nested enrichment and totals are unchanged. Base errors do not queue jobs. Report query time remains in the request; workbook rendering and publication run in the background.

A successful queue response contains `export_id`, `status`, `file_name`, immutable `file_url` and `status_url`. Poll status with the same authenticated user or valid origin-key context. A requester ID alone cannot claim an export. Status access rechecks both source permissions.

`file_url` is a **direct static URL on the configured application domain**, under `assets/exports/excel/<environment-namespace>/<export_id>/<file_name>`. Anyone possessing this URL can download it without authentication. Download it directly with a browser or GET request; it does not use the VM4-only file proxy. The URL returns 404 before publication. `ready` means the completed workbook is readable at its published filesystem path. Missing completed files change to `failed` on status inspection. Failure messages are sanitized. Jobs are not retried automatically.

Workers run through `php spark exports:generate <generated-id>`, with no request credentials in arguments. The web process selects a CLI binary matching its PHP major/minor version and launches it with `nohup`. `EXCEL_EXPORT_PHP_CLI` can explicitly select that binary. Deployment must allow process execution, ZIP, mbstring and JSON. Run workers as the application owner, using the same configuration and filesystem as PHP FPM.

## Private storage and public publication

`EXCEL_EXPORT_DIR` optionally sets an absolute private storage base **outside the document root**. The default is `/tmp/cpci_excel_exports`; an application-root/environment/application-URL fingerprint creates a separate namespace beneath it. The same settings, including `app.baseURL`, must reach web and CLI processes. Private namespace directories are 0700; private files are 0600. Use a durable private directory if operating-system temporary cleanup or reboots must not discard queued snapshots.

The final destination is `<application-root>/assets/exports/excel/<environment-namespace>/<export_id>/<file_name>`. The worker must be able to create these directories and files; the static web server must be able to traverse the directories and read completed workbooks. Newly created public directories are 0755 and completed files are 0644. The configured application base URL supplies the host and any application path prefix; filenames and destinations are generated internally.

Only report responses and job metadata are stored privately, not request payloads, headers or tokens. Metadata contains a fingerprint of the validated owner context. Atomic manifest replacement and separate state/worker locks protect concurrency. The worker renders privately, streams a copy into a randomly named staging file at the destination, verifies SHA-256 equality and the XLSX archive, then atomically renames it to the promised filename. Staging files start as 0600 and are removed on failure. No incomplete workbook is published at the promised URL. Unexpected symlink directories or mismatched destination metadata are rejected.

The exclusive worker lock covers rendering and publication. Duplicate execution does not publish twice. Status operations detect workers that exited before claiming a job, unclaimed jobs older than 60 seconds, and claimed jobs whose worker lock was released without completion. Rendering checkpoints retain the 15-minute limit. No automatic retry is attempted.

## Retention and recovery

Snapshots, private workbooks and writer spools are removed immediately after success or failure. Final published workbooks and job metadata are retained for **24 hours from queue creation** and removed during subsequent export/status operations. Cleanup is opportunistic: a static URL can remain usable after 24 hours until a subsequent operation prunes it. This feature adds no scheduled cleanup job. A browser's already downloaded/cached copy is unaffected by server cleanup.

Failed or interrupted jobs remove their staging and published output. Expiry removes only that job's generated files and metadata, preserving other jobs. Small synchronization lock files remain to avoid reusing lock inodes while another process may be waiting. An interrupted job is marked failed on status inspection; cleanup also runs when expired jobs are pruned. Investigate failures before submitting a new request. Workers do not replay business or accounting operations.

This file queue requires one shared filesystem for the web process and worker. It is not a distributed queue. Preserve private manifests until their public workbooks are pruned; deleting the private directory manually also removes the catalogue used for retention cleanup.

## Workbook representation

`Summary` contains response metadata and worksheet references. A main array goes to `Data`; multiple root tables and nested objects/lists get separate sheets. Source field names are retained. Four leading export columns hold globally unique row references, parent row references, original indexes and source paths. An export header gains underscores if it conflicts with a source header. Child rows link by parent reference, independent of database-local IDs. Internal hyperlinks navigate to the relevant sheet; source array order is preserved.

Strings are inline text, including numeric-looking strings, leading zeros and formula-like text. Native numbers and booleans remain numeric/boolean. Null creates an explicit empty XML cell; an absent field omits the cell. Native integers exceeding Excel's 15-digit precision additionally have exact text copies in `Numeric_values`, linked by worksheet, cell and parent row. This preserves their exact source representation when Excel rounds numeric cells.

Row overflow uses `_rN` sheets; column overflow uses `_cN` sheets with repeated row/parent references. Cell overflow uses `Long_text`, with chunks identified by parent row, source worksheet, cell and part. Reassemble chunks in ascending part order. Unicode chunking respects the 32767 UTF-16-unit limit. Nothing is silently truncated. Empty tables retain a header-only sheet.

## Before rollout

Verify the configured application base URL, static serving of `assets/exports/excel`, matching CLI runtime, private/public filesystem permissions, available disk space and environment parity. Use isolated local publication and static HTTP tests during development. This change deploys nothing and makes no external upload requests.

No schema migration, permission seed or cron schedule is included. Existing unrelated Excel endpoints and their VM4 behavior are unchanged.
