-- Each result set must be empty. Run in one diagnostic schema after rebuild.
SELECT v.id AS voucher_id, 'GL totals/count differ from active entries' AS issue
FROM accounts_payable_voucher v
LEFT JOIN (SELECT accounts_payable_voucher_id AS id,COUNT(*) AS n,SUM(debit) AS dr,SUM(credit) AS cr
    FROM accounts_payable_voucher_entry WHERE is_deleted=0 GROUP BY accounts_payable_voucher_id) e ON e.id=v.id
LEFT JOIN (SELECT source_id AS id,COUNT(*) AS n,SUM(debit) AS dr,SUM(credit) AS cr
    FROM general_ledger WHERE source='accounts_payable_voucher' AND is_deleted=0 GROUP BY source_id) g ON g.id=v.id
WHERE v.is_deleted=0 AND v.status IN ('approved','open','closed')
    AND (IFNULL(e.n,0)=0 OR IFNULL(g.n,0)<>e.n OR IFNULL(g.dr,0)<>e.dr OR IFNULL(g.cr,0)<>e.cr);

SELECT v.id AS voucher_id, 'Missing or duplicate active journal' AS issue
FROM accounts_payable_voucher v LEFT JOIN journal_entry j
    ON j.source='accounts_payable_voucher' AND j.source_id=v.id AND j.is_deleted=0
WHERE v.is_deleted=0 AND v.status IN ('approved','open','closed')
GROUP BY v.id HAVING COUNT(j.id)<>1;

SELECT j.id AS journal_id, 'Journal totals/items do not match active GL' AS issue
FROM journal_entry j
LEFT JOIN (SELECT journal_entry_id AS id,COUNT(*) AS n,SUM(debit) AS dr,SUM(credit) AS cr
    FROM journal_entry_item WHERE is_deleted=0 GROUP BY journal_entry_id) i ON i.id=j.id
LEFT JOIN (SELECT source_id AS id,COUNT(*) AS n,SUM(debit) AS dr,SUM(credit) AS cr
    FROM general_ledger WHERE source='accounts_payable_voucher' AND is_deleted=0 GROUP BY source_id) g ON g.id=j.source_id
WHERE j.source='accounts_payable_voucher' AND j.is_deleted=0
    AND (IFNULL(i.n,0)<>IFNULL(g.n,0) OR IFNULL(i.dr,0)<>IFNULL(g.dr,0)
        OR IFNULL(i.cr,0)<>IFNULL(g.cr,0) OR j.total_debit<>i.dr OR j.total_credit<>i.cr);

SELECT v.id AS voucher_id, 'Inactive voucher still has active GL' AS issue
FROM general_ledger g LEFT JOIN accounts_payable_voucher v ON v.id=g.source_id
WHERE g.source='accounts_payable_voucher' AND g.is_deleted=0
    AND (v.id IS NULL OR v.is_deleted<>0 OR v.status NOT IN ('approved','open','closed'));

SELECT id AS ledger_id, 'Ordinary direct-payment GL still active' AS issue
FROM general_ledger WHERE source='direct_payment' AND is_deleted=0;

SELECT TRIGGER_NAME AS dependency FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA=DATABASE() AND (EVENT_OBJECT_TABLE='direct_payment_entry' OR ACTION_STATEMENT LIKE '%direct_payment_entry%');
SELECT ROUTINE_NAME AS dependency FROM information_schema.ROUTINES
WHERE ROUTINE_SCHEMA=DATABASE() AND ROUTINE_DEFINITION LIKE '%direct_payment_entry%';
