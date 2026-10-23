-- One-time per clinic DB (mobile, oakridge, mactan, consolacion, mantlewood).
-- 1. Recreate clinic procedures from sql/general_ledger_triggers.sql
--    (phpMyAdmin: delimiter `$$` for that file, then delimiter `;` here).
-- 2. Run this file on the clinic DB:
--    - gl_post_refund only clears source='refund' GL (and that system JE)
--    - restore PMM on refund.payment_id only (posted NCD skips those)
--    - drain leftover refund.new_payment_id PMM so their GL is cleared, not rebuilt
--    - refresh posted NCD
--    - rebuild refund APV (DR 440-010 / CR 201-111) and pay details
--
-- Do not also run the master block in the same session.

DROP TEMPORARY TABLE IF EXISTS gl_bf_ids;
CREATE TEMPORARY TABLE gl_bf_ids (
    id INT NOT NULL,
    PRIMARY KEY (id)
);

INSERT INTO gl_bf_ids
SELECT refund.id
FROM mytemr_master.refund
WHERE refund.is_deleted = 0
    AND refund.branch_id = gl_branch_id();

CALL gl_bf_drain('refund');

INSERT IGNORE INTO gl_bf_ids
SELECT pmm.id
FROM payment_multiple_method AS pmm
INNER JOIN mytemr_master.refund
    ON refund.branch_id = gl_branch_id()
    AND refund.is_deleted = 0
    AND refund.payment_id = pmm.payment_id;

INSERT IGNORE INTO gl_bf_ids
SELECT pmm.id
FROM payment_multiple_method AS pmm
INNER JOIN mytemr_master.refund
    ON refund.branch_id = gl_branch_id()
    AND refund.is_deleted = 0
    AND refund.new_payment_id = pmm.payment_id;

CALL gl_bf_drain('csr_mop');

INSERT IGNORE INTO gl_bf_ids
SELECT ncd.id
FROM non_cash_deposit AS ncd
WHERE ncd.is_deleted = 0
    AND ncd.status = 'posted';

CALL gl_bf_drain('non_cash_deposit');

INSERT IGNORE INTO gl_bf_ids
SELECT apv.id
FROM accounts_payable_voucher AS apv
INNER JOIN mytemr_master.refund
    ON refund.id = apv.type_id
    AND refund.branch_id = gl_branch_id()
    AND refund.is_deleted = 0
WHERE apv.type = 'refund'
    AND apv.is_deleted = 0;

CALL gl_bf_drain('apv');

INSERT IGNORE INTO gl_bf_ids
SELECT apvpd.id
FROM accounts_payable_voucher_payment_detail AS apvpd
INNER JOIN accounts_payable_voucher AS apv
    ON apv.id = apvpd.accounts_payable_voucher_id
    AND apv.type = 'refund'
    AND apv.is_deleted = 0
INNER JOIN mytemr_master.refund
    ON refund.id = apv.type_id
    AND refund.branch_id = gl_branch_id()
    AND refund.is_deleted = 0
WHERE apvpd.is_deleted = 0;

CALL gl_bf_drain('apv_payment_detail');

DROP TEMPORARY TABLE IF EXISTS gl_bf_ids;

-- Optional: run on mytemr_master instead (not in the same session as above).
-- Recreate master kick from sql/general_ledger_triggers_master.sql first.
-- Clinic kick only clears source='refund'. PMM / NCD / APV stay on the clinic run.
--
-- DROP TEMPORARY TABLE IF EXISTS gl_bf_ids;
-- CREATE TEMPORARY TABLE gl_bf_ids (
--     id INT NOT NULL,
--     PRIMARY KEY (id)
-- );
-- INSERT INTO gl_bf_ids
-- SELECT refund.id
-- FROM refund
-- WHERE refund.is_deleted = 0
--     AND refund.branch_id IN (1, 2, 3, 4, 7);
-- CALL gl_bf_billing_drain('refund');
