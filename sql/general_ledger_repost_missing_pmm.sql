-- One-time per clinic DB (mobile, oakridge, mactan, consolacion, manticwood).
-- 1. Recreate clinic triggers from sql/general_ledger_triggers.sql
--    (phpMyAdmin: delimiter `;`, then INSERT IGNORE INTO gl_settings).
-- 2. Run this file to re-post live payment_multiple_method rows that have no live GL
--    (sibling MOP voided when gl_post_transaction's cursor aborted on walk-in unbilled).
-- Example: transaction 200165 / PMM 423 and 424.
-- After the trigger fix you can also CALL gl_post_transaction(<transaction_id>);

DROP TEMPORARY TABLE IF EXISTS gl_bf_ids;
CREATE TEMPORARY TABLE gl_bf_ids (
    id INT NOT NULL,
    PRIMARY KEY (id)
);

INSERT INTO gl_bf_ids
SELECT pmm.id
FROM payment_multiple_method AS pmm
INNER JOIN payment AS pay ON pay.id = pmm.payment_id
    AND pay.is_deleted = 0
INNER JOIN `transaction` AS txn ON txn.id = pay.transaction_id
    AND txn.is_deleted = 0
WHERE pmm.is_deleted = 0
    AND IFNULL(pmm.amount, 0) > 0
    AND NOT EXISTS (
        SELECT 1
        FROM general_ledger
        WHERE general_ledger.source = 'payment_multiple_method'
            AND general_ledger.source_id = pmm.id
            AND general_ledger.is_deleted = 0
    );

CALL gl_bf_drain('csr_mop');
