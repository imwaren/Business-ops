-- One-time: mark posted GL deleted when the source document is already is_deleted = 1.
-- Does not touch is_migrated = 1 rows unless that source document is also deleted.
--
-- Clinic DBs (mobile / oakridge / mactan / consolacion / mantlewood): run Section A.
-- Master (mytemr_master): run Section B.
-- Then recreate triggers from sql/general_ledger_triggers.sql (clinics)
-- and sql/general_ledger_triggers_master.sql (master).

-- =============================================================================
-- Section A — clinic
-- =============================================================================

UPDATE general_ledger
INNER JOIN `transaction` ON `transaction`.id = general_ledger.source_id
    AND general_ledger.source = 'transaction'
SET general_ledger.is_deleted = 1,
    general_ledger.updated_on = NOW()
WHERE `transaction`.is_deleted = 1
    AND general_ledger.is_deleted = 0;

UPDATE general_ledger
INNER JOIN payment ON payment.id = general_ledger.source_id
    AND general_ledger.source = 'payment'
LEFT JOIN `transaction` ON `transaction`.id = payment.transaction_id
SET general_ledger.is_deleted = 1,
    general_ledger.updated_on = NOW()
WHERE general_ledger.is_deleted = 0
    AND (payment.is_deleted = 1 OR IFNULL(`transaction`.is_deleted, 0) = 1);

UPDATE general_ledger
INNER JOIN payment_multiple_method ON payment_multiple_method.id = general_ledger.source_id
    AND general_ledger.source = 'payment_multiple_method'
LEFT JOIN payment ON payment.id = payment_multiple_method.payment_id
LEFT JOIN `transaction` ON `transaction`.id = payment.transaction_id
SET general_ledger.is_deleted = 1,
    general_ledger.updated_on = NOW()
WHERE general_ledger.is_deleted = 0
    AND (
        payment_multiple_method.is_deleted = 1
        OR IFNULL(payment.is_deleted, 0) = 1
        OR IFNULL(`transaction`.is_deleted, 0) = 1
    );

UPDATE general_ledger
INNER JOIN mytemr_master.refund ON refund.id = general_ledger.source_id
    AND general_ledger.source = 'refund'
    AND refund.branch_id = (
        SELECT gl_settings.branch_id FROM gl_settings LIMIT 1
    )
SET general_ledger.is_deleted = 1,
    general_ledger.updated_on = NOW()
WHERE refund.is_deleted = 1
    AND general_ledger.is_deleted = 0;

UPDATE general_ledger
INNER JOIN item_history ON item_history.id = general_ledger.source_id
    AND general_ledger.source = 'item_history'
SET general_ledger.is_deleted = 1,
    general_ledger.updated_on = NOW()
WHERE item_history.is_deleted = 1
    AND general_ledger.is_deleted = 0;

UPDATE general_ledger
INNER JOIN receipt_note_item ON receipt_note_item.id = general_ledger.source_id
    AND general_ledger.source = 'receipt_note_item'
LEFT JOIN receipt_note ON receipt_note.id = receipt_note_item.receipt_note_id
SET general_ledger.is_deleted = 1,
    general_ledger.updated_on = NOW()
WHERE general_ledger.is_deleted = 0
    AND (receipt_note_item.is_deleted = 1 OR IFNULL(receipt_note.is_deleted, 0) = 1);

UPDATE general_ledger
INNER JOIN purchase_order_item ON purchase_order_item.id = general_ledger.source_id
    AND general_ledger.source = 'purchase_order_item'
LEFT JOIN purchase_order ON purchase_order.id = purchase_order_item.purchase_order_id
SET general_ledger.is_deleted = 1,
    general_ledger.updated_on = NOW()
WHERE general_ledger.is_deleted = 0
    AND (purchase_order_item.is_deleted = 1 OR IFNULL(purchase_order.is_deleted, 0) = 1);

UPDATE general_ledger
INNER JOIN purchase_request_item ON purchase_request_item.id = general_ledger.source_id
    AND general_ledger.source = 'purchase_request_item'
LEFT JOIN purchase_request ON purchase_request.id = purchase_request_item.purchase_request_id
SET general_ledger.is_deleted = 1,
    general_ledger.updated_on = NOW()
WHERE general_ledger.is_deleted = 0
    AND (purchase_request_item.is_deleted = 1 OR IFNULL(purchase_request.is_deleted, 0) = 1);

UPDATE general_ledger
INNER JOIN accounts_payable_voucher ON accounts_payable_voucher.id = general_ledger.source_id
    AND general_ledger.source = 'accounts_payable_voucher'
SET general_ledger.is_deleted = 1,
    general_ledger.updated_on = NOW()
WHERE accounts_payable_voucher.is_deleted = 1
    AND general_ledger.is_deleted = 0;

UPDATE general_ledger
INNER JOIN accounts_payable_voucher_payment_detail
    ON accounts_payable_voucher_payment_detail.id = general_ledger.source_id
    AND general_ledger.source = 'accounts_payable_voucher_payment_detail'
LEFT JOIN accounts_payable_voucher
    ON accounts_payable_voucher.id = accounts_payable_voucher_payment_detail.accounts_payable_voucher_id
SET general_ledger.is_deleted = 1,
    general_ledger.updated_on = NOW()
WHERE general_ledger.is_deleted = 0
    AND (
        accounts_payable_voucher_payment_detail.is_deleted = 1
        OR IFNULL(accounts_payable_voucher.is_deleted, 0) = 1
    );

UPDATE general_ledger
INNER JOIN direct_payment ON direct_payment.id = general_ledger.source_id
    AND general_ledger.source = 'direct_payment'
SET general_ledger.is_deleted = 1,
    general_ledger.updated_on = NOW()
WHERE direct_payment.is_deleted = 1
    AND general_ledger.is_deleted = 0;

UPDATE general_ledger
INNER JOIN change_fund ON change_fund.id = general_ledger.source_id
    AND general_ledger.source = 'change_fund'
SET general_ledger.is_deleted = 1,
    general_ledger.updated_on = NOW()
WHERE change_fund.is_deleted = 1
    AND general_ledger.is_deleted = 0;

UPDATE general_ledger
INNER JOIN liquidation_detail ON liquidation_detail.id = general_ledger.source_id
    AND general_ledger.source = 'liquidation_detail'
LEFT JOIN liquidation ON liquidation.id = liquidation_detail.liquidation_id
SET general_ledger.is_deleted = 1,
    general_ledger.updated_on = NOW()
WHERE general_ledger.is_deleted = 0
    AND (liquidation_detail.is_deleted = 1 OR IFNULL(liquidation.is_deleted, 0) = 1);

UPDATE general_ledger
INNER JOIN cash_advance ON cash_advance.id = general_ledger.source_id
    AND general_ledger.source = 'cash_advance'
SET general_ledger.is_deleted = 1,
    general_ledger.updated_on = NOW()
WHERE cash_advance.is_deleted = 1
    AND general_ledger.is_deleted = 0;

UPDATE general_ledger
INNER JOIN reimbursement_item ON reimbursement_item.id = general_ledger.source_id
    AND general_ledger.source = 'reimbursement_item'
LEFT JOIN reimbursement ON reimbursement.id = reimbursement_item.reimbursement_id
SET general_ledger.is_deleted = 1,
    general_ledger.updated_on = NOW()
WHERE general_ledger.is_deleted = 0
    AND (reimbursement_item.is_deleted = 1 OR IFNULL(reimbursement.is_deleted, 0) = 1);

UPDATE general_ledger
INNER JOIN replenishment_item ON replenishment_item.id = general_ledger.source_id
    AND general_ledger.source = 'replenishment_item'
LEFT JOIN replenishment ON replenishment.id = replenishment_item.replenishment_id
SET general_ledger.is_deleted = 1,
    general_ledger.updated_on = NOW()
WHERE general_ledger.is_deleted = 0
    AND (replenishment_item.is_deleted = 1 OR IFNULL(replenishment.is_deleted, 0) = 1);

UPDATE general_ledger
INNER JOIN petty_cash ON petty_cash.id = general_ledger.source_id
    AND general_ledger.source = 'petty_cash'
SET general_ledger.is_deleted = 1,
    general_ledger.updated_on = NOW()
WHERE petty_cash.is_deleted = 1
    AND general_ledger.is_deleted = 0;

UPDATE general_ledger
INNER JOIN journal_entry ON journal_entry.id = general_ledger.source_id
    AND general_ledger.source = 'journal_entry'
SET general_ledger.is_deleted = 1,
    general_ledger.updated_on = NOW()
WHERE journal_entry.is_deleted = 1
    AND general_ledger.is_deleted = 0;

UPDATE general_ledger
INNER JOIN cash_deposit ON cash_deposit.id = general_ledger.source_id
    AND general_ledger.source = 'cash_deposit'
SET general_ledger.is_deleted = 1,
    general_ledger.updated_on = NOW()
WHERE cash_deposit.is_deleted = 1
    AND general_ledger.is_deleted = 0;

UPDATE general_ledger
INNER JOIN non_cash_deposit ON non_cash_deposit.id = general_ledger.source_id
    AND general_ledger.source = 'non_cash_deposit'
SET general_ledger.is_deleted = 1,
    general_ledger.updated_on = NOW()
WHERE non_cash_deposit.is_deleted = 1
    AND general_ledger.is_deleted = 0;

-- =============================================================================
-- Section B — master
-- =============================================================================

UPDATE general_ledger
INNER JOIN billing_entry ON billing_entry.id = general_ledger.source_id
    AND general_ledger.source = 'billing_entry'
LEFT JOIN billing ON billing.id = billing_entry.billing_id
SET general_ledger.is_deleted = 1,
    general_ledger.updated_on = NOW()
WHERE general_ledger.is_deleted = 0
    AND (billing_entry.is_deleted = 1 OR IFNULL(billing.is_deleted, 0) = 1);

UPDATE general_ledger
INNER JOIN billing_payment ON billing_payment.id = general_ledger.source_id
    AND general_ledger.source = 'billing_payment'
SET general_ledger.is_deleted = 1,
    general_ledger.updated_on = NOW()
WHERE billing_payment.is_deleted = 1
    AND general_ledger.is_deleted = 0;

UPDATE general_ledger
INNER JOIN billing_payment_deposit ON billing_payment_deposit.id = general_ledger.source_id
    AND general_ledger.source = 'billing_payment_deposit'
LEFT JOIN billing_payment ON billing_payment.id = billing_payment_deposit.billing_payment_id
SET general_ledger.is_deleted = 1,
    general_ledger.updated_on = NOW()
WHERE general_ledger.is_deleted = 0
    AND (
        billing_payment_deposit.is_deleted = 1
        OR IFNULL(billing_payment.is_deleted, 0) = 1
    );

UPDATE general_ledger
INNER JOIN credit_memo ON credit_memo.id = general_ledger.source_id
    AND general_ledger.source = 'credit_memo'
SET general_ledger.is_deleted = 1,
    general_ledger.updated_on = NOW()
WHERE credit_memo.is_deleted = 1
    AND general_ledger.is_deleted = 0;

UPDATE general_ledger
INNER JOIN debit_memo ON debit_memo.id = general_ledger.source_id
    AND general_ledger.source = 'debit_memo'
SET general_ledger.is_deleted = 1,
    general_ledger.updated_on = NOW()
WHERE debit_memo.is_deleted = 1
    AND general_ledger.is_deleted = 0;
