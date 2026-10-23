-- Import Apr 2026 migrated GL into mytemr_diagnostic_mobile (mobile, branch_id 1)
-- Do not import through the live API.
--
-- Once per database (from the month folder, before the first clinic load):
--   mysql -u root -p mytemr_diagnostic_mobile < ../001_add_is_migrated.sql
-- If LOAD DATA LOCAL fails:
--   mysql -u root -p -e "SET GLOBAL local_infile=1;"
--
-- Then from this directory:
--   mysql --local-infile=1 -u root -p mytemr_diagnostic_mobile < import.sql
-- Example (Oakridge / Jan on the app server):
--   cd /home/mytemr/web/accounting-back.myt-emr.com/public_html/sql/migrations/gl_jan2026/oakridge
--   mysql --local-infile=1 -u root -p mytemr_diagnostic < import.sql
-- --local-infile=1 is required. Without it, LOAD DATA LOCAL fails.

SET NAMES utf8mb4;
SET SESSION foreign_key_checks = 0;

-- Drop JE posters so LOAD/INSERT does not double-post GL
DROP TRIGGER IF EXISTS trg_gl_journal_entry_ai;
DROP TRIGGER IF EXISTS trg_gl_journal_entry_au;
DROP TRIGGER IF EXISTS trg_gl_journal_entry_item_ai;
DROP TRIGGER IF EXISTS trg_gl_journal_entry_item_au;

-- Recreate triggers after import from sql/general_ledger_triggers.sql

LOAD DATA LOCAL INFILE 'journal_entry.csv'
INTO TABLE journal_entry
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(id, cost_center_id, date, type, remarks, total_debit, total_credit, status, is_migrated, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'journal_entry_item_001.csv'
INTO TABLE journal_entry_item
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(journal_entry_id, account_type_id, debit, credit, remarks, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'journal_entry_item_002.csv'
INTO TABLE journal_entry_item
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(journal_entry_id, account_type_id, debit, credit, remarks, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'journal_entry_item_003.csv'
INTO TABLE journal_entry_item
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(journal_entry_id, account_type_id, debit, credit, remarks, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'journal_entry_item_004.csv'
INTO TABLE journal_entry_item
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(journal_entry_id, account_type_id, debit, credit, remarks, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'journal_entry_item_005.csv'
INTO TABLE journal_entry_item
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(journal_entry_id, account_type_id, debit, credit, remarks, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'journal_entry_item_006.csv'
INTO TABLE journal_entry_item
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(journal_entry_id, account_type_id, debit, credit, remarks, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'journal_entry_item_007.csv'
INTO TABLE journal_entry_item
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(journal_entry_id, account_type_id, debit, credit, remarks, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'journal_entry_item_008.csv'
INTO TABLE journal_entry_item
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(journal_entry_id, account_type_id, debit, credit, remarks, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'journal_entry_item_009.csv'
INTO TABLE journal_entry_item
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(journal_entry_id, account_type_id, debit, credit, remarks, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'journal_entry_item_010.csv'
INTO TABLE journal_entry_item
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(journal_entry_id, account_type_id, debit, credit, remarks, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'journal_entry_item_011.csv'
INTO TABLE journal_entry_item
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(journal_entry_id, account_type_id, debit, credit, remarks, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'journal_entry_item_012.csv'
INTO TABLE journal_entry_item
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(journal_entry_id, account_type_id, debit, credit, remarks, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'journal_entry_item_013.csv'
INTO TABLE journal_entry_item
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(journal_entry_id, account_type_id, debit, credit, remarks, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'journal_entry_item_014.csv'
INTO TABLE journal_entry_item
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(journal_entry_id, account_type_id, debit, credit, remarks, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'journal_entry_item_015.csv'
INTO TABLE journal_entry_item
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(journal_entry_id, account_type_id, debit, credit, remarks, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'journal_entry_item_016.csv'
INTO TABLE journal_entry_item
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(journal_entry_id, account_type_id, debit, credit, remarks, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'journal_entry_item_017.csv'
INTO TABLE journal_entry_item
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(journal_entry_id, account_type_id, debit, credit, remarks, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'journal_entry_item_018.csv'
INTO TABLE journal_entry_item
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(journal_entry_id, account_type_id, debit, credit, remarks, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'general_ledger_001.csv'
INTO TABLE general_ledger
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(branch_id, source, source_id, account_type_id, date, debit, credit, remark, is_migrated, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'general_ledger_002.csv'
INTO TABLE general_ledger
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(branch_id, source, source_id, account_type_id, date, debit, credit, remark, is_migrated, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'general_ledger_003.csv'
INTO TABLE general_ledger
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(branch_id, source, source_id, account_type_id, date, debit, credit, remark, is_migrated, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'general_ledger_004.csv'
INTO TABLE general_ledger
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(branch_id, source, source_id, account_type_id, date, debit, credit, remark, is_migrated, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'general_ledger_005.csv'
INTO TABLE general_ledger
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(branch_id, source, source_id, account_type_id, date, debit, credit, remark, is_migrated, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'general_ledger_006.csv'
INTO TABLE general_ledger
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(branch_id, source, source_id, account_type_id, date, debit, credit, remark, is_migrated, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'general_ledger_007.csv'
INTO TABLE general_ledger
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(branch_id, source, source_id, account_type_id, date, debit, credit, remark, is_migrated, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'general_ledger_008.csv'
INTO TABLE general_ledger
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(branch_id, source, source_id, account_type_id, date, debit, credit, remark, is_migrated, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'general_ledger_009.csv'
INTO TABLE general_ledger
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(branch_id, source, source_id, account_type_id, date, debit, credit, remark, is_migrated, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'general_ledger_010.csv'
INTO TABLE general_ledger
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(branch_id, source, source_id, account_type_id, date, debit, credit, remark, is_migrated, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'general_ledger_011.csv'
INTO TABLE general_ledger
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(branch_id, source, source_id, account_type_id, date, debit, credit, remark, is_migrated, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'general_ledger_012.csv'
INTO TABLE general_ledger
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(branch_id, source, source_id, account_type_id, date, debit, credit, remark, is_migrated, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'general_ledger_013.csv'
INTO TABLE general_ledger
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(branch_id, source, source_id, account_type_id, date, debit, credit, remark, is_migrated, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'general_ledger_014.csv'
INTO TABLE general_ledger
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(branch_id, source, source_id, account_type_id, date, debit, credit, remark, is_migrated, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'general_ledger_015.csv'
INTO TABLE general_ledger
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(branch_id, source, source_id, account_type_id, date, debit, credit, remark, is_migrated, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'general_ledger_016.csv'
INTO TABLE general_ledger
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(branch_id, source, source_id, account_type_id, date, debit, credit, remark, is_migrated, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'general_ledger_017.csv'
INTO TABLE general_ledger
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(branch_id, source, source_id, account_type_id, date, debit, credit, remark, is_migrated, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'general_ledger_018.csv'
INTO TABLE general_ledger
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(branch_id, source, source_id, account_type_id, date, debit, credit, remark, is_migrated, added_by, added_on, is_deleted);

SET SESSION foreign_key_checks = 1;

-- Recreate trg_gl_journal_entry_* from sql/general_ledger_triggers.sql
