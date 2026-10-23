-- Import 2026 Beginning TB into mytemr_master (master, branch_id NULL)
-- Date 2025-12-31. Do not import through the live API.
--
-- is_migrated was added by sql/migrations/gl_jan2026/001_add_is_migrated.sql
-- Run ../003_add_tb_accounts.sql on mytemr_master (and clinic DBs) BEFORE this LOAD
-- so account_type ids 1801 (AR - MSC) and 1805 (LOANS PAYABLE - BDO) exist.
-- If LOAD DATA LOCAL fails:
--   mysql -u root -p -e "SET GLOBAL local_infile=1;"
--
-- Then from this directory:
--   mysql --local-infile=1 -u root -p mytemr_master < import.sql

SET NAMES utf8mb4;
SET SESSION foreign_key_checks = 0;

-- Master: no JE→GL triggers. Load journal_entry, items, then general_ledger.

LOAD DATA LOCAL INFILE 'journal_entry.csv'
INTO TABLE journal_entry
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(id, cost_center_id, date, type, remarks, total_debit, total_credit, status, is_migrated, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'journal_entry_item.csv'
INTO TABLE journal_entry_item
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(id, journal_entry_id, account_type_id, debit, credit, remarks, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'general_ledger.csv'
INTO TABLE general_ledger
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(id, branch_id, source, source_id, account_type_id, date, debit, credit, remark, is_migrated, added_by, added_on, is_deleted);

SET SESSION foreign_key_checks = 1;
