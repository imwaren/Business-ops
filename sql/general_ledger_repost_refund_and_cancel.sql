-- One-time per clinic DB after recreating sql/general_ledger_triggers.sql
-- (needs clinic gl_post_refund DR 440-010 / CR 201-111, refund APV skip,
-- gl_post_sales_return, gl_bf_drain sales_return).
-- Drain refund so sales return + AP post when status=refunded (and leftover
-- refund GL clears). Drain refund APVs so duplicate SR/AP from old APV create
-- is cleared. Drain later-day deleted transactions as sales_return (original
-- CSR GL is kept). Pay refund APV is unchanged.
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

INSERT IGNORE INTO gl_bf_ids
SELECT apv.id
FROM accounts_payable_voucher AS apv
WHERE apv.type = 'refund';

CALL gl_bf_drain('apv');

INSERT IGNORE INTO gl_bf_ids
SELECT txn.id
FROM `transaction` AS txn
WHERE txn.is_deleted = 1
    AND DATE(IFNULL(txn.updated_on, NOW())) <> DATE(txn.transaction_date);

CALL gl_bf_drain('sales_return');

DROP TEMPORARY TABLE IF EXISTS gl_bf_ids;

-- Recreate sql/general_ledger_triggers.sql after this file.
