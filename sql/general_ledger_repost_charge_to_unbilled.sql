-- One-time per clinic DB after recreating sql/general_ledger_triggers.sql.
-- Reposts charge-to transactions so the remaining balance becomes:
--   DR 102-113 AR Cebu (new)
--   CR 102-113B AR unbilled
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
INNER JOIN `transaction` AS txn ON txn.id = pay.transaction_id
    AND txn.is_deleted = 0
WHERE IFNULL(pmm.amount, 0) > 0
    AND EXISTS (
        SELECT 1
        FROM booking_details AS bd
        INNER JOIN booking AS b ON b.id = bd.booking_id
            AND b.is_deleted = 0
            AND LOWER(IFNULL(b.status, '')) NOT IN ('cancelled', 'canceled', 'void', 'disapproved')
        WHERE bd.is_deleted = 0
            AND b.transaction_id = txn.id
            AND IFNULL(bd.charge_to, 0) > 0
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
WHERE txn.is_deleted = 0
    AND EXISTS (
        SELECT 1
        FROM booking_details AS bd
        INNER JOIN booking AS b ON b.id = bd.booking_id
            AND b.is_deleted = 0
            AND LOWER(IFNULL(b.status, '')) NOT IN ('cancelled', 'canceled', 'void', 'disapproved')
        WHERE bd.is_deleted = 0
            AND b.transaction_id = txn.id
            AND IFNULL(bd.charge_to, 0) > 0
    );

CALL gl_bf_drain('unbilled');
