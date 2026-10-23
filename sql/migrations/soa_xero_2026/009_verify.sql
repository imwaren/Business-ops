-- Post-load checks. Run on mytemr_master_uat.

SELECT 'soa_dump' AS metric, COUNT(*) AS value FROM soa_dump
UNION ALL
SELECT 'distinct_invoices', COUNT(DISTINCT InvoiceNumber) FROM soa_dump WHERE IFNULL(InvoiceNumber, '') <> ''
UNION ALL
SELECT 'mapped_contacts', COUNT(*) FROM soa_company_map WHERE company_id IS NOT NULL
UNION ALL
SELECT 'unmapped_contacts', COUNT(*) FROM soa_company_map WHERE company_id IS NULL
UNION ALL
SELECT 'migrated_billings', COUNT(*) FROM billing WHERE is_migrated = 1 AND is_deleted = 0
UNION ALL
SELECT 'migrated_entries', COUNT(*) FROM billing_entry WHERE is_migrated = 1 AND is_deleted = 0
UNION ALL
SELECT 'matched_entries', COUNT(*) FROM billing_entry WHERE is_migrated = 1 AND is_deleted = 0 AND IFNULL(booking_id, 0) > 0
UNION ALL
SELECT 'unmatched_entries', COUNT(*) FROM billing_entry WHERE is_migrated = 1 AND is_deleted = 0 AND IFNULL(booking_id, 0) = 0
UNION ALL
SELECT 'migrated_payments', COUNT(*) FROM billing_payment WHERE is_migrated = 1 AND is_deleted = 0
UNION ALL
SELECT 'gl_billing_entry', COUNT(*) FROM general_ledger WHERE source = 'billing_entry' AND is_deleted = 0
UNION ALL
SELECT 'gl_billing_payment', COUNT(*) FROM general_ledger WHERE source = 'billing_payment' AND is_deleted = 0;

SHOW TRIGGERS WHERE `Table` IN (
  'billing', 'billing_entry', 'billing_payment', 'billing_payment_deposit'
);
