# Purchasing approval reminders

## Workflow

New PRs and receipt notes notify Department Managers (25). New AOQs notify Finance
Managers (23). New POs notify Department Directors (28); the existing PO approval
threshold controls the handoff to President (29). A PO close request notifies
Finance Directors (26). The creator's immediate head is included at every level.

Only new stage entries receive sequences. Updating a document in its existing
pending stage does not backfill notifications. Resolution or deletion cancels the
sequence. Each new approval stage starts a separate sequence.

## Timing

All times are Asia/Manila, Monday–Friday. Holidays count as business days.

- First: immediately during business hours; otherwise the next business opening.
- Second: before 1 PM, floor the stage-entry hour and add four hours. At or after
  1 PM, use 8 AM the next business day.
- EOD: 5 PM on the second reminder's business day, then 5 PM each business day
  until the stage is resolved.
- For out-of-hours entries, the scheduling anchor is the next business opening.
- EOD CC: Finance Manager (23), AP Staff, General Accountant (27). Recipients are
  active users with valid emails, deduplicated across To and CC.

## Operations

`php spark approval:reminders --check` performs read-only schema, role, mode and
SMTP-configuration checks. `php spark approval:reminders` processes due reminders.
The HTTP alternative, `/approval_reminder_jobs/process`, uses the existing cron
authorization (`X-Cron-Secret`); ordinary unauthenticated requests are rejected.

Each environment runs hourly on weekdays, at 8 AM through 5 PM Asia/Manila, using
`0 8-17 * * 1-5` under
`/etc/cron.d/accounting-approval-reminders-uat` or
`/etc/cron.d/accounting-approval-reminders-prod`. `flock` prevents overlapping CLI
workers, and database advisory locks plus unique delivery keys prevent competing
workers from claiming the same send. Work is bounded to 50 deliveries and a
45-second budget, checked between sends. Branch starting order rotates each hour.
There are ten scheduled runs per weekday and none overnight or on weekends.
The first reminder still runs immediately after a successful stage transition
during business hours. Overdue jobs and five-minute retries are processed at the
next permitted hourly run.

The two reminder tables are branch-local in all seven purchasing databases.
Document changes and queue changes use the same branch transaction. Email runs
after commit. A mail failure cannot roll back a committed document.

`APPROVAL_REMINDER_MODE=log` records intended recipients without emailing them.
`APPROVAL_REMINDER_MODE=send` uses the existing Office365 SMTP configuration.
`APPROVAL_REMINDER_MODE=hold` preserves pending deliveries without logging or sending them.
`APPROVAL_REMINDER_AP_ROLE` identifies AP Staff, confirmed as `apv_officer` (35).
UAT uses log mode. Production uses send mode with its hourly weekday cron stanza enabled.
Production was activated on October 8, 2026 at 5:28 PM Asia/Manila. Since activation
occurred after business hours, pending deliveries wait for the next business opening.

Explicit rejections retry after five minutes, up to three attempts. A retry outside
business hours moves to the next opening. Unknown SMTP outcomes and interrupted
sends are marked `uncertain` for review and are not automatically resent. After
downtime, only the latest overdue daily EOD is materialized; older pending EOD
escalations are marked `skipped`.

## Verification and recovery

Run `php tests/approval_reminders_check.php` only against the isolated local socket
fixture documented in that script. It does not connect to configured live databases
and sends no real mail. Checks cover scheduling boundaries, role recipients,
rollback, repeated sends, exclusive locks, recurring EOD, failures and PO handoffs.

Deployment files and backups reside on VPS5 at
`/root/approval_reminders_20261008/`. Each environment's backup includes the old
controllers, `.env`, file-presence inventory, readiness output and deployment
checksums. VPS6 stores the additive migration at the same release directory.
Restore that environment's files and `.env` and remove/restore its reminder cron
file to roll back. Additive notification tables may remain in place.

Production activation backups are on VPS5 at
`/root/approval_reminders_20261008/activation_prod_20261008_172831/`. They include
the previous `.env` and paused cron file, readiness output, worker output and the
activation record. Restore those two configuration files to return production to
hold mode with its worker paused. Activation changed no PHP files or database schema.

The hourly weekday rollout was verified in both environments on October 8, 2026
at 5:56 PM Asia/Manila. Cron and library backups are under
`/root/approval_reminders_20261008/hourly_20261008_175651/uat/` and `prod/`.
The rollout preserved delivery configuration and queued reminders. No purchasing
records or artificial test emails were created. Restoring that rollout's cron and
library backups returns the prior minute schedule and minute-based branch rotation.

UAT and production syntax, schema and checksum checks passed. Both HTTP worker
endpoints reject unauthenticated calls with HTTP 401. No live purchasing test
records or backlog reminder sequences were created. Production's pre-existing
`spark` script emits a missing-directory warning at line 56; the command still
boots and completes, and that unrelated bootstrap file was not changed.
