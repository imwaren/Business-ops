-- Insert migrated collections for InvoiceAmountPaid > 0. Triggers must stay dropped.

INSERT INTO billing_payment (
  charge_to, is_unidentified, mop, date, reference_no, others, paid_date,
  remarks, grand_total, added_by, added_on, is_deleted, is_migrated
)
SELECT
  b.charge_to,
  0,
  'others',
  COALESCE(MIN(sl.invoice_date), b.date_from),
  b.invoice_no,
  'Xero migrated',
  COALESCE(MIN(sl.invoice_date), b.date_from),
  'Xero SOA migration',
  MAX(sl.invoice_paid),
  0,
  NOW(),
  0,
  1
FROM billing b
INNER JOIN soa_line sl ON sl.invoice_no = b.invoice_no
WHERE b.is_migrated = 1
  AND b.is_deleted = 0
  AND sl.invoice_paid > 0
  AND NOT EXISTS (
      SELECT 1
      FROM billing_payment bp
      WHERE bp.is_migrated = 1
        AND bp.is_deleted = 0
        AND bp.reference_no = b.invoice_no
  )
GROUP BY b.id, b.charge_to, b.invoice_no, b.date_from;

INSERT INTO billing_payment_entry (
  billing_payment_id, billing_entry_id, amount, added_by, added_on, is_deleted, is_migrated
)
SELECT
  bp.id,
  e.id,
  GREATEST(0, LEAST(e.amount, bp.grand_total - (e.cum_amount - e.amount))),
  0,
  NOW(),
  0,
  1
FROM (
    SELECT
      be.id,
      be.billing_id,
      be.amount,
      SUM(be.amount) OVER (PARTITION BY be.billing_id ORDER BY be.id) AS cum_amount
    FROM billing_entry be
    WHERE be.is_migrated = 1
      AND be.is_deleted = 0
) e
INNER JOIN billing b ON b.id = e.billing_id AND b.is_migrated = 1 AND b.is_deleted = 0
INNER JOIN billing_payment bp
  ON bp.reference_no = b.invoice_no
 AND bp.is_migrated = 1
 AND bp.is_deleted = 0
WHERE GREATEST(0, LEAST(e.amount, bp.grand_total - (e.cum_amount - e.amount))) > 0
  AND NOT EXISTS (
      SELECT 1
      FROM billing_payment_entry bpe
      WHERE bpe.billing_payment_id = bp.id
        AND bpe.billing_entry_id = e.id
        AND bpe.is_migrated = 1
        AND bpe.is_deleted = 0
  );

UPDATE billing_entry be
INNER JOIN billing b ON b.id = be.billing_id AND b.is_migrated = 1
LEFT JOIN (
    SELECT billing_entry_id, SUM(amount) AS paid
    FROM billing_payment_entry
    WHERE is_deleted = 0
    GROUP BY billing_entry_id
) p ON p.billing_entry_id = be.id
SET be.balance = GREATEST(0, be.amount - IFNULL(p.paid, 0)),
    be.status = CASE
        WHEN GREATEST(0, be.amount - IFNULL(p.paid, 0)) = 0 THEN 'closed_bill'
        ELSE 'open_bill'
    END
WHERE be.is_migrated = 1
  AND be.is_deleted = 0;

UPDATE billing b
INNER JOIN (
    SELECT billing_id, SUM(balance) AS bal
    FROM billing_entry
    WHERE is_migrated = 1 AND is_deleted = 0
    GROUP BY billing_id
) e ON e.billing_id = b.id
SET b.balance = e.bal,
    b.status = CASE WHEN e.bal = 0 THEN 'closed_bill' ELSE 'open_bill' END
WHERE b.is_migrated = 1
  AND b.is_deleted = 0;

SELECT
  (SELECT COUNT(*) FROM billing_payment WHERE is_migrated = 1 AND is_deleted = 0) AS migrated_payments,
  (SELECT COUNT(*) FROM billing_payment_entry WHERE is_migrated = 1 AND is_deleted = 0) AS migrated_payment_entries;
