-- Removes migrated SOA rows only. Restore clinic booking.billing_id from soa_booking_billing_backup first.

-- Example restore (run per clinic after setting @soa_oakridge_db etc):
-- SET @sql = CONCAT(
--   'UPDATE `', @soa_oakridge_db, '`.booking b ',
--   'INNER JOIN soa_booking_billing_backup bak ON bak.booking_id = b.id AND bak.branch_id = 2 ',
--   'SET b.billing_id = bak.billing_id_before'
-- );

DELETE bpe
FROM billing_payment_entry bpe
INNER JOIN billing_payment bp ON bp.id = bpe.billing_payment_id
WHERE bpe.is_migrated = 1 OR bp.is_migrated = 1;

DELETE FROM billing_payment WHERE is_migrated = 1;
DELETE FROM billing_entry WHERE is_migrated = 1;
DELETE FROM billing WHERE is_migrated = 1;
