-- Indexes for billing / SOA / collection list GETs after Xero SOA volume.

SET @db := DATABASE();

SET @exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'billing' AND INDEX_NAME = 'idx_billing_list_charge'
);
SET @sql := IF(
    @exists = 0,
    'ALTER TABLE billing ADD INDEX idx_billing_list_charge (is_deleted, charge_to, company_id)',
    'SELECT ''idx_billing_list_charge already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'billing' AND INDEX_NAME = 'idx_billing_list_added'
);
SET @sql := IF(
    @exists = 0,
    'ALTER TABLE billing ADD INDEX idx_billing_list_added (is_deleted, added_on)',
    'SELECT ''idx_billing_list_added already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'billing_entry' AND INDEX_NAME = 'idx_billing_entry_billing'
);
SET @sql := IF(
    @exists = 0,
    'ALTER TABLE billing_entry ADD INDEX idx_billing_entry_billing (billing_id, is_deleted)',
    'SELECT ''idx_billing_entry_billing already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'billing_entry' AND INDEX_NAME = 'idx_billing_entry_booking'
);
SET @sql := IF(
    @exists = 0,
    'ALTER TABLE billing_entry ADD INDEX idx_billing_entry_booking (booking_id, is_deleted)',
    'SELECT ''idx_billing_entry_booking already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'billing_payment_entry' AND INDEX_NAME = 'idx_bpe_entry'
);
SET @sql := IF(
    @exists = 0,
    'ALTER TABLE billing_payment_entry ADD INDEX idx_bpe_entry (billing_entry_id, is_deleted)',
    'SELECT ''idx_bpe_entry already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'billing_payment_entry' AND INDEX_NAME = 'idx_bpe_payment'
);
SET @sql := IF(
    @exists = 0,
    'ALTER TABLE billing_payment_entry ADD INDEX idx_bpe_payment (billing_payment_id, is_deleted)',
    'SELECT ''idx_bpe_payment already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'billing_entry' AND INDEX_NAME = 'idx_billing_entry_branch'
);
SET @sql := IF(
    @exists = 0,
    'ALTER TABLE billing_entry ADD INDEX idx_billing_entry_branch (branch_id, is_deleted)',
    'SELECT ''idx_billing_entry_branch already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
