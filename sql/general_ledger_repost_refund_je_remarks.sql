-- One-time per clinic DB after recreating sql/general_ledger_triggers.sql
-- (needs gl_je_from_gl refund remarks = refund # + transaction_no).
-- Drains every mytemr_master.refund for this branch (including deleted).
-- gl_post_refund clears source='refund' GL and that system JE, then re-posts
-- DR 440-010 / CR 201-111 when live and status='refunded'. The GL insert
-- trigger rebuilds the JE with remarks refund # + transaction_no.
-- Does not drain APV, PMM, or later-day cancels.
-- Recreate sql/general_ledger_triggers.sql after this file.
-- Safe to re-run. Do not run from chat.

DROP TEMPORARY TABLE IF EXISTS gl_bf_ids;
CREATE TEMPORARY TABLE gl_bf_ids (
    id INT NOT NULL,
    PRIMARY KEY (id)
);

INSERT INTO gl_bf_ids
SELECT refund.id
FROM mytemr_master.refund
WHERE refund.branch_id = gl_branch_id();

CALL gl_bf_drain('refund');

DROP TEMPORARY TABLE IF EXISTS gl_bf_ids;

-- Recreate sql/general_ledger_triggers.sql after this file.
