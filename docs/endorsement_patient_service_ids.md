# Patient selection service IDs

Local implementation for `endorsements/upload_patients` and
`endorsements/update_patient`. No deployment or database migration is included.

## Payload

Every object in `patients[]` must include a positive integer
`endorsement_service_id` identifying an active `endorsement_service` row in the
supplied endorsement. This also applies to objects inside the uploaded JSON
`patient_file`.

`id` continues to identify an existing `endorsement_patient` row when updating;
it does not identify the endorsement service. New update selections may omit
`id` or send it as an empty string. The top-level `endorsement_patient_id` remains
required as the existing update anchor, together with `endorsement_id` and
`patient_id`.

Example selection fields (illustrative IDs; retain the endpoint's existing
demographic and pricing fields):

```json
{
  "id": 7123,
  "endorsement_service_id": 331,
  "type": "package",
  "type_id": 2,
  "branch_id": "2,3,4,7",
  "charge_to": "company",
  "charge_to_ids": "3731",
  "mobile_van_id": "1,2,3"
}
```

Omitted or empty `type`/`type_id` values are filled from the referenced service.
Conflicting supplied values, missing/invalid service IDs, deleted services and
services from another endorsement return the standard HTTP 400 response before
patient writes. Every update row ID must belong to the target patient and
endorsement; repeated row IDs are rejected.

## Matching and legacy linkage

Uploads match the patient, service ID, type, type ID, branch and payer fields.
Two package variants with the same package ID remain separate. Explicitly
linked matches are reused first.

A zero-ID historical row is linked only if exactly one non-add-on row matches
the old key and only one service variant is submitted for that key. Otherwise
the upload returns an ambiguity error before patient mirroring. Resolve such
cases through `update_patient` with explicit patient-row and service IDs.
There is no bulk backfill or automatic package add-on insertion.

Updates retain their existing full-selection replacement behavior: retained
rows are updated, new selections are inserted, and omitted target-patient rows
are soft-deleted. Standard package-test worklist expansion remains unchanged;
the worklist is restricted to the saved selection row IDs.

## Local verification

136 isolated checks cover upload/update validation, package variants, legacy
adoption and ambiguity, repeats, selected worklists, scoped replacement,
rollback, failed commit and mocked external delivery. The fixture table uses
`endorsement_service_id INT NOT NULL` without a default, matching the supplied
column definition.

Run against the disposable local socket database only:

```sh
/opt/homebrew/bin/php tests/endorsement_selected_upload_check.php
```

The fixtures mock branch patient mirrors, worklist uploads and email, and create
no live records. PHP syntax and diff checks are also part of verification.
