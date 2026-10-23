# Local validation

Validated against an isolated MariaDB 10.4 instance using the supplied schema definitions and company/customer data. Production MariaDB 11.4 was not accessed.

- Embedded snapshot contains all 221 supplied retained company rows; approved mapping contains 245 unique duplicate IDs with all three confirmed corrections.
- Reference inventory contains 122 explicit columns; unrelated suppliers, contract IDs, and staging dumps are excluded.
- Preflight rejected the 20 projected company uniqueness conflicts in the supplied original data, before business updates.
- After removing pre-existing deleted collision rows **only from the isolated test fixture**, the full transactional SQL executed successfully and retained metadata assertions passed. Production conflicts are unresolved and remain blocked by preflight.
- CSV fixture `1209,15,1209,11209` became `4,2226,4,11209`; adjacent repeated tokens were updated, while the unrelated longer ID was preserved.
- A fixture UPDATE trigger was backed up, suspended, restored, and checked against its saved definition; its side-effect counter stayed unchanged.
- A repeated apply completed successfully; final references and active/deleted flags remained valid.
- An intentional SQL failure rolled back the booking reference to 1209 and restored its trigger. Recovery artifacts were saved before trigger removal.
- Full GL/JE/item-table row fingerprints matched in the isolated fixture runs. Live accounting figures remain unverified until the VPS6 runbook is executed.
- Python syntax and CLI argument checks passed.

## Revised semantics and completed production checks

The final revision uses company IDs for every customer_account_id field and resolves conflicting deleted-company names with approved ID suffixes. A fresh isolated import of the original data completed without removing historical collision rows: for example, 473 became `3D MANPOWER [merged #473]`, while deleted row 1589 kept its original name. A company-based customer reference fixture became `2124,4,2226,4,11209`, preserving unrelated longer tokens and using company mapping for 2122.

Production was subsequently executed and verified; the earlier validation notes describe the original package version. See DEPLOYMENT.md for exact production results. In particular, all 18 production accounting-table fingerprints were unchanged and all 64 UPDATE trigger definitions/order were restored exactly.
