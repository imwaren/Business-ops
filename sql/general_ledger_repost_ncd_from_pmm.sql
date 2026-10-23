-- One-time per clinic after recreating sql/general_ledger_triggers.sql
-- (needs gl_mop_code, gl_ncd_mop_label, gl_ncd_bank_id, gl_is_refund_new_payment, gl_payment_has_refund).
-- Inserts unposted non_cash_deposit from existing non-cash PMM.
-- Maps bank_id / bank_service_fee from bank.mop.
-- Skips cash, leftover refund.new_payment_id, and PMM that already have a live NCD.
-- Includes soft-deleted PMM that have a live refund on payment_id.
-- Safe to re-run.

INSERT INTO non_cash_deposit (
    transaction_id, transaction_no, transaction_date,
    payment_id, payment_multiple_method_id,
    bank_id, bank_service_fee_id, bank_service_fee_percentage,
    amount, mop, reference_no, remarks,
    status, added_by, added_on, is_deleted
)
SELECT
    pay.transaction_id,
    COALESCE(NULLIF(txn.transaction_no, ''), CAST(pay.transaction_id AS CHAR)),
    DATE(txn.transaction_date),
    pmm.payment_id,
    pmm.id,
    gl_ncd_bank_id(gl_ncd_mop_label(pmm.type, pmm.other_payment_details)),
    fee.id,
    fee.percentage,
    IFNULL(pmm.amount, 0),
    gl_ncd_mop_label(pmm.type, pmm.other_payment_details),
    pmm.reference_no,
    pmm.remarks,
    'unposted',
    IFNULL(pmm.added_by, 0),
    NOW(),
    0
FROM payment_multiple_method AS pmm
INNER JOIN payment AS pay ON pay.id = pmm.payment_id
INNER JOIN `transaction` AS txn ON txn.id = pay.transaction_id
LEFT JOIN bank_service_fee AS fee
    ON fee.id = (
        SELECT MIN(f.id)
        FROM bank_service_fee AS f
        WHERE f.bank_id = gl_ncd_bank_id(gl_ncd_mop_label(pmm.type, pmm.other_payment_details))
            AND f.is_deleted = 0
    )
WHERE gl_mop_code(pmm.type, pmm.other_payment_details) <> '100-004'
    AND gl_mop_code(pmm.type, pmm.other_payment_details) IS NOT NULL
    AND gl_is_refund_new_payment(pmm.payment_id) = 0
    AND (pmm.is_deleted = 0 OR gl_payment_has_refund(pmm.payment_id) = 1)
    AND IFNULL(pmm.amount, 0) > 0
    AND NOT EXISTS (
        SELECT 1
        FROM non_cash_deposit AS ncd
        WHERE ncd.payment_multiple_method_id = pmm.id
            AND ncd.is_deleted = 0
    );

UPDATE non_cash_deposit AS ncd
INNER JOIN (
    SELECT n.id,
        gl_ncd_bank_id(n.mop) AS bank_id
    FROM non_cash_deposit AS n
    WHERE n.is_deleted = 0
        AND n.status = 'unposted'
        AND n.bank_id IS NULL
) AS mapped ON mapped.id = ncd.id
LEFT JOIN bank_service_fee AS fee
    ON fee.id = (
        SELECT MIN(f.id)
        FROM bank_service_fee AS f
        WHERE f.bank_id = mapped.bank_id
            AND f.is_deleted = 0
    )
SET ncd.bank_id = mapped.bank_id,
    ncd.bank_service_fee_id = fee.id,
    ncd.bank_service_fee_percentage = fee.percentage,
    ncd.updated_on = NOW()
WHERE mapped.bank_id IS NOT NULL;
