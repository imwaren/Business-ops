-- One-time per clinic DB after recreating sql/general_ledger_triggers.sql
-- (needs gl_pmm_net_amount).
-- Reposts payments that have change on a cash PMM so GL uses tender minus change.
-- Example: Mantlewood #230101023 cash 500 / change 50 -> 450.
-- Safe to re-run because each poster clears its own live GL first.
-- Do not run from chat.

DROP TEMPORARY TABLE IF EXISTS gl_bf_ids;
CREATE TEMPORARY TABLE gl_bf_ids (
    id INT NOT NULL,
    PRIMARY KEY (id)
);

INSERT INTO gl_bf_ids
SELECT DISTINCT pmm.id
FROM payment_multiple_method AS pmm
INNER JOIN payment AS pay ON pay.id = pmm.payment_id
    AND pay.is_deleted = 0
    AND IFNULL(pay.change, 0) > 0
INNER JOIN `transaction` AS txn ON txn.id = pay.transaction_id
    AND txn.is_deleted = 0
WHERE IFNULL(pmm.amount, 0) > 0
    AND EXISTS (
        SELECT 1
        FROM payment_multiple_method AS cash_pmm
        WHERE cash_pmm.payment_id = pay.id
            AND cash_pmm.is_deleted = 0
            AND LOWER(IFNULL(cash_pmm.type, '')) = 'cash'
    );

CALL gl_bf_drain('csr_mop');

DROP TEMPORARY TABLE IF EXISTS gl_bf_ids;
CREATE TEMPORARY TABLE gl_bf_ids (
    id INT NOT NULL,
    PRIMARY KEY (id)
);

INSERT INTO gl_bf_ids
SELECT DISTINCT txn.id
FROM `transaction` AS txn
INNER JOIN payment AS pay ON pay.transaction_id = txn.id
    AND pay.is_deleted = 0
    AND IFNULL(pay.change, 0) > 0
WHERE txn.is_deleted = 0
    AND EXISTS (
        SELECT 1
        FROM payment_multiple_method AS cash_pmm
        WHERE cash_pmm.payment_id = pay.id
            AND cash_pmm.is_deleted = 0
            AND LOWER(IFNULL(cash_pmm.type, '')) = 'cash'
    );

CALL gl_bf_drain('unbilled');
