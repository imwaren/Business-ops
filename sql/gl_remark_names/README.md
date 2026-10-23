# Scoped accounting remark deployment

## Completed rollout

Deployed on October 12, 2026, UAT first, then production. Each environment includes
its master database and Mobile, Oakridge, Mactan, Consolacion, and Mantlewood.

Only remark helpers, the affected journal/posting procedures, the GL `BEFORE INSERT`
hook, and journal remark `LONGTEXT` alterations were installed. No API PHP files,
account mappings, historical source records, schedules, or unrelated definitions
were deployed or changed. No posting, rebuild, or backfill procedure was called.

Verification passed:

- All 12 schemas have matching scoped definitions; all 24 journal remark columns
  use `LONGTEXT`.
- Complete GL, journal-header, and journal-item row counts and SHA-256 fingerprints
  matched before/after installation in both environments, including deleted history.
- Protected routine/trigger inventories remained identical, including audit hooks.
- Read-only checks passed for patient decryption, branch-specific payload agreement,
  catalog-linked medicines, receipt-note item lists, long Unicode text, missing
  transaction/unknown-branch fallbacks, and billing references.
- Local tests passed 126 full/scoped installation checks, 90 billing checks,
  101 APV checks, and 64 exact-definition recovery checks: **381 checks total**.
- Original server settings are restored: `read_only=0`, `event_scheduler=OFF`.
  There were no enabled database events or privileged database-writing cron jobs
  requiring suspension; schedules remained untouched.

An initial UAT attempt stopped before any schema/routine change because MariaDB's
privilege view conflicted with the installation connection's collation. The guard
now compares privilege flags as binary strings. Write settings were confirmed
restored before retry; the subsequent UAT and production installations passed.

## Backups and evidence

VPS6 recovery root:

```text
/root/gl_remark_names_20261012_010922/uat
/root/gl_remark_names_20261012_010922/prod
```

Each directory contains original scoped definitions, journal table definitions,
per-schema restoration SQL, settings snapshots, complete accounting fingerprints,
`verified.json`, and `readonly_checks.json`. Private rendered installers and their
manifest remain in the sibling `package/` directory. They contain encryption key
material and must not be displayed or committed. Only sanitized summary evidence
is retained in the repository.

Completed backup directories are immutable to the runner. Use `--verify-only` for
later read-only verification; use a new timestamped release for another rollout.

## Package preparation

```sh
python3 sql/gl_remark_names/build_release.py /private/tmp/gl_remark_names_TIMESTAMP/package
python3 sql/gl_remark_names/stage_release.py /private/tmp/gl_remark_names_TIMESTAMP
```

The builder extracts only reviewed objects from the four full templates. The stage
helper reads each environment's actual runtime `ENCRYPTION_KEY` on VPS5 without
printing it, renders files with mode `0600` inside a `0700` directory outside the
checkout, and uploads them to VPS6 using its supported SCP transport. UAT reads
its environment configuration; production's legacy key comes from its existing
application constants. Neither configuration is edited.

Before installation, the root runner compares live bodies with the reviewed Git
baseline or the desired definitions, checks source columns and branch settings,
and saves exact restoration material. It preserves existing definers and SQL modes,
holds normal database writes with `read_only`, drains write transactions, performs
the additive/scoped installation, and verifies definitions and accounting history.

## Recovery

During a protected maintenance window, restore each schema using its saved
`SCHEMA.restore.sql` with `mariadb --comments --binary-mode`; retain the additive
`LONGTEXT` columns. Verify restored definitions, unaffected inventories, and
accounting fingerprints before restoring the settings from `settings_before.json`.
The runner performs these steps automatically on installation failure and leaves
writes paused if recovery verification fails. Do not restore table data or run a
historical rebuild to undo this release.

Existing remarks change only when their movements are ordinarily reposted. The
master duplicate-prevention fix also takes effect during ordinary reposting, retaining
the previous journal rows as deleted history.
