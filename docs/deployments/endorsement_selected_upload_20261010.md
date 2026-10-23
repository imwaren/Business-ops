# Endorsement selected-service upload fix — October 10, 2026

## Behavior

`endorsements/upload_patients` saves only the explicit selections in `patient_file`
or `patients`. It no longer loads package add-ons from other endorsement-service
variants with the same package ID. Standard package components still expand in
the DICOM worklist query; only category 43 services participate, as before.

Repeated selections use the existing patient/type/service/branch/payer key.
Only the matched active non-add-on row receives the mobile-van and audit update.
Package and lab selections are inserted together in a batch. Their persisted
row IDs constrain the worklist query using bound parameters. An empty selection
never expands to every service for a patient. Failed save, resolution or commit
rolls back before external worklist/email delivery.

Existing erroneous add-on rows remain unchanged. No schema changes, cleanup,
live upload replay or real verification emails were performed.

## Verification

- 57 isolated checks passed for the local controller and each deployment copy.
- The supplied file was exercised with its selection/pricing data retained and
  patient demographics replaced by synthetic fixture data.
- Fixtures cover three selected services, repeated uploads, duplicate objects,
  historical add-ons, multiple patients/payers, scoped updates/worklists,
  standard package expansion, restricted-test behavior, safe query binding,
  batch lookup, rollback, commit failure and external-delivery warnings.
- Local and remote PHP syntax and local diff checks passed.
- UAT read-only sample lookup resolved IDs 7123, 7124 and 7125; excluded the two
  historical add-ons despite five existing active rows. Its existing worklist
  category filter returned one component row.
- Production read-only lookup resolved an existing selection, ID 728. Empty
  selection behavior and worklist queries passed in both environments.

Run the isolated checks only against the disposable local socket database:

```sh
/opt/homebrew/bin/php tests/endorsement_selected_upload_check.php
```

An optional filename argument exercises a supplied JSON array while replacing
all `patient_*` demographic fields with synthetic fixture values. The fixtures
do not connect to branch servers, send emails or upload DICOM worklists.

## Deployment

Only `app/Controllers/Endorsements.php` and
`app/Models/Endorsement_patient.php` were deployed on VPS5, UAT first.
Existing deployed notification changes were preserved by applying the three
affected controller regions to each environment's original file. Consequently,
controller checksums differ by environment; the deployed upload regions match
the local implementation. The model matches the local file exactly.

Verified SHA-256:

- UAT controller: `4b017babda91559dc10a11fec2b20b15b9c5244a13cc2c7a7bd4d1dea26966c1`
- Production controller: `45b2b70920f79d31542ec6ceb32668e47905978a028cc2598c974319c5afac2a`
- Both models: `33b1e07ee7f7c16c8c9cde9504becf0bfe50f61d3c7202121b1418b1328f2116`

Original files, staged deployment copies and the read-only verification script:

- `/root/endorsement_selected_upload_20261010_0106/zuat-accounting-back/`
- `/root/endorsement_selected_upload_20261010_0106/accounting-back/`

Original files are under `before/app/`; installed copies are under `stage/app/`.
Preserve original ownership and permissions when restoring both files. The
model was installed before the controller so its optional worklist parameter
remained compatible with the old controller during rollout.

The first UAT verification attempt selected the CLI test connection and failed;
the deployment guard restored both originals automatically. The verifier was
corrected to explicitly use `db_connect('default')`; the subsequent UAT and
production deployments passed all checks.
