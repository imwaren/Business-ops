# Receipt-note VAT and remarks deployment — 2026-10-22

## Result

Completed through SSH on VPS6, port 1969, production first and UAT after production
verification passed. All eight compatible accounting databases in each environment
were updated; none were skipped. Only the scoped receipt posting/context routines
were replaced. Existing trigger definitions were preserved.

Receipt VAT resolves exclusively through INPUT VAT code `106-000` (currently ID169
in all 16 targets). Future GL, JE-header and journal-item remarks include the parent
`receipt_note #<id>`, preserving the existing receipt-item reference and grouping.

## Production

Updated databases:

- `mytemr_master`
- `mytemr_diagnostic_mobile`
- `mytemr_diagnostic`
- `mytemr_diagnostic_mactan`
- `mytemr_diagnostic_conso`
- `mytemr_diagnostic_mantlewood`
- `mytemr_oakridge_warehouse`
- `mytemr_oakridge_pharmacy`

Verification:

- 23 scoped routine definitions installed and matched to the release bodies.
- Original signatures, definers, SQL modes, security attributes, client character
  sets, connection collations and routine creation database collations preserved.
- Historical fingerprints unchanged across 89 source/inventory/accounting tables.
- Unrelated routine and trigger definitions unchanged across all 16 databases.
- Dependency audit: zero missing dependencies and zero unresolved dynamic calls.
- Installation log: zero warning/error lines.
- Read-only application checks passed on all eight connections: INPUT VAT lookup
  resolved correctly; existing receipt samples exercised parent-reference helpers
  on 4 connections. Other connections had no joined receipt sample;
  their routine definitions and dependencies were still verified.
- No live posting calls, synthetic transactions, inventory movements or reposts.

Private backup/evidence directory on VPS6:
`/root/receipt_note_vat_20261022_retry2/prod`

Contains 16 compressed SQL archives (definitions and selected data for each database),
`backup_checksums.json`, exact pre-install definitions/metadata, before/after
fingerprints, `verified.json`, `dependencies_after.json`,
`application_readonly_probe.json`, and maintenance restoration records.


## UAT

Updated databases:

- `mytemr_master_uat`
- `mytemr_diagnostic_mobile_uat`
- `mytemr_diagnostic_uat`
- `mytemr_diagnostic_mactan_uat`
- `mytemr_diagnostic_conso_uat`
- `mytemr_diagnostic_mantlewood_uat`
- `mytemr_oakridge_warehouse_uat`
- `mytemr_oakridge_pharmacy_uat`

Verification:

- 23 scoped routine definitions installed and matched to the release bodies.
- Original signatures, definers, SQL modes, security attributes, client character
  sets, connection collations and routine creation database collations preserved.
- Historical fingerprints unchanged across 89 source/inventory/accounting tables.
- Unrelated routine and trigger definitions unchanged across all 16 databases.
- Dependency audit: zero missing dependencies and zero unresolved dynamic calls.
- Installation log: zero warning/error lines.
- Read-only application checks passed on all eight connections: INPUT VAT lookup
  resolved correctly; existing receipt samples exercised parent-reference helpers
  on 5 connections. Other connections had no joined receipt sample;
  their routine definitions and dependencies were still verified.
- No live posting calls, synthetic transactions, inventory movements or reposts.

Private backup/evidence directory on VPS6:
`/root/receipt_note_vat_20261022_retry2/uat`

Contains 16 compressed SQL archives (definitions and selected data for each database),
`backup_checksums.json`, exact pre-install definitions/metadata, before/after
fingerprints, `verified.json`, `dependencies_after.json`,
`application_readonly_probe.json`, and maintenance restoration records.

## Restored operating settings

Both environments restored their recorded settings: database global `read_only=0`,
event scheduler `OFF`, VPS6 cron active, and VPS5 application-host cron active.
Affected writes were paused and active writers drained during replacement.
No pause remains. Existing database defaults were restored and independently verified.

## Recovery record

Two earlier production attempts failed during routine metadata preservation, before
the successful release. The first exposed differing routine creation character sets
and historical database collations. The second exposed MariaDB universal-collation
aliases that do not have a direct character-set row in the collation catalog.
Both attempts were fully recovered, with exact prior definitions/metadata and
unchanged historical fingerprints verified before writes resumed or a fresh attempt
began. Failed attempts were not blindly retried in place.

Recovery evidence remains private on VPS6 at:

- `/root/receipt_note_vat_20261022_release`
- `/root/receipt_note_vat_20261022_retry1`

The user explicitly approved temporary database-default changes needed for exact
routine recreation. The successful deployment temporarily used the original routine
creation default for two production warehouse routines, and four UAT warehouse/pharmacy
routines, restoring the current default immediately after each CREATE. No table
collation or business rows were changed. Events are recorded in each environment's
`temporary_collation_changes.jsonl`; final defaults in `schema_defaults_verified.json`.

A production application probe initially compared two differently collated strings
using LIKE and failed. The probe was corrected to use a binary comparison and passed;
this required no installed SQL or application-code change.

## Release SHA-256

The remote package checksum manifest is
`/root/receipt_note_vat_20261022_retry2/checksums.json`; consolidated completion evidence
is `completion.json` in the same private directory.

- `install_prod.sql`: `88097111311a64fb7511ecca415b21e9e3d473dd0bcd7f8399ca5e26eb9c685e`
- `install_prod_master.sql`: `57b31f5c398c5706add54c2b463fb57e8b93d5ff8f2f85f8db7c01d515b6b459`
- `install_uat.sql`: `4ac05485e42bf65d1ea1c3301770c1ad6161cfc72558452d1fa9b7458c012e8f`
- `install_uat_master.sql`: `03b3a9861d73849f74367bd55164d44468c11ebef9519ef1b480eeaf3f4044a1`
- `manifest.json`: `3e4672f8fd7c31c983cf7661bfae0f20604e443bcff9de9fbef5c766f84e7ef2`

## Local validation and limits

Before deployment, isolated MariaDB receipt accounting checks passed (64), shared-fund
checks passed (88), and shared-procurement checks passed (91): 243 checks total.
Exact metadata recreation was additionally exercised locally, and relevant collation
aliases were validated read-only on the live server. Live acceptance used definition,
dependency, historical-fingerprint and read-only application checks; it did not create
financial transactions. No application deployment or historical correction was made.

For recovery after this release, pause affected writes/jobs and use the selected
successful environment backup's exact prior definitions and recorded metadata.
Do not blindly restore business data or replace complete trigger scripts.
