-- Link automated journal_entry rows to the GL source that created them.
-- Run on mytemr_master and each clinic DB (mobile, oakridge, mactan, consolacion, mantlewood).
-- Safe to re-run.
--   mysql -u root -p mytemr_master < sql/migrations/journal_entry_source.sql
--   mysql -u root -p <clinic_db> < sql/migrations/journal_entry_source.sql
-- Recreate clinic + master GL triggers after this ALTER.

SET @db := DATABASE();

SET @exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'journal_entry'
      AND COLUMN_NAME = 'source'
);
SET @sql := IF(
    @exists = 0,
    'ALTER TABLE journal_entry ADD COLUMN source VARCHAR(250) NULL',
    'SELECT ''journal_entry.source already exists'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'journal_entry'
      AND COLUMN_NAME = 'source_id'
);
SET @sql := IF(
    @exists = 0,
    'ALTER TABLE journal_entry ADD COLUMN source_id INT NULL',
    'SELECT ''journal_entry.source_id already exists'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'journal_entry'
      AND INDEX_NAME = 'idx_je_source_id'
);
SET @sql := IF(
    @exists = 0,
    'CREATE INDEX idx_je_source_id ON journal_entry (source, source_id)',
    'SELECT ''idx_je_source_id already exists'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
