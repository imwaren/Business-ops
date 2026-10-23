-- Per-line journal type. Manual lines send financial_reporting / bank_adjustments;
-- trigger-generated items use the default.
-- Run on mytemr_master and each clinic DB. Safe to re-run.

SET @db := DATABASE();

SET @exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'journal_entry_item'
      AND COLUMN_NAME = 'type'
);
SET @sql := IF(
    @exists = 0,
    'ALTER TABLE journal_entry_item ADD COLUMN type VARCHAR(64) NOT NULL DEFAULT ''Automated''',
    'SELECT ''journal_entry_item.type already exists'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
