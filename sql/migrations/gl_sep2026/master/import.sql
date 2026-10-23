-- Import Sep 2026 into mytemr_master only; existing schema/flags required.
-- Verify the generated journal_entry IDs are unused before importing.
-- Not safe to import twice. Do not use mysql --force; inspect warnings/errors.
-- cd /home/mytemr/web/accounting-back.myt-emr.com/public_html/sql/migrations/gl_sep2026/master
-- mysql --local-infile=1 -u root -p mytemr_master < import.sql

SET NAMES utf8mb4;
SET SESSION foreign_key_checks = 0;

-- Master: load journal_entry only if that table exists on mytemr_master.

LOAD DATA LOCAL INFILE 'journal_entry_001.csv'
INTO TABLE journal_entry
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(id, cost_center_id, date, type, remarks, total_debit, total_credit, status, is_migrated, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'journal_entry_002.csv'
INTO TABLE journal_entry
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(id, cost_center_id, date, type, remarks, total_debit, total_credit, status, is_migrated, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'journal_entry_003.csv'
INTO TABLE journal_entry
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(id, cost_center_id, date, type, remarks, total_debit, total_credit, status, is_migrated, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'journal_entry_004.csv'
INTO TABLE journal_entry
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(id, cost_center_id, date, type, remarks, total_debit, total_credit, status, is_migrated, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'journal_entry_005.csv'
INTO TABLE journal_entry
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(id, cost_center_id, date, type, remarks, total_debit, total_credit, status, is_migrated, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'journal_entry_006.csv'
INTO TABLE journal_entry
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(id, cost_center_id, date, type, remarks, total_debit, total_credit, status, is_migrated, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'journal_entry_007.csv'
INTO TABLE journal_entry
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(id, cost_center_id, date, type, remarks, total_debit, total_credit, status, is_migrated, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'journal_entry_008.csv'
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

LOAD DATA LOCAL INFILE 'journal_entry_item_019.csv'
INTO TABLE journal_entry_item
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(journal_entry_id, account_type_id, debit, credit, remarks, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'journal_entry_item_020.csv'
INTO TABLE journal_entry_item
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(journal_entry_id, account_type_id, debit, credit, remarks, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'journal_entry_item_021.csv'
INTO TABLE journal_entry_item
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(journal_entry_id, account_type_id, debit, credit, remarks, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'journal_entry_item_022.csv'
INTO TABLE journal_entry_item
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(journal_entry_id, account_type_id, debit, credit, remarks, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'journal_entry_item_023.csv'
INTO TABLE journal_entry_item
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(journal_entry_id, account_type_id, debit, credit, remarks, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'journal_entry_item_024.csv'
INTO TABLE journal_entry_item
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(journal_entry_id, account_type_id, debit, credit, remarks, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'journal_entry_item_025.csv'
INTO TABLE journal_entry_item
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(journal_entry_id, account_type_id, debit, credit, remarks, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'journal_entry_item_026.csv'
INTO TABLE journal_entry_item
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(journal_entry_id, account_type_id, debit, credit, remarks, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'journal_entry_item_027.csv'
INTO TABLE journal_entry_item
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(journal_entry_id, account_type_id, debit, credit, remarks, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'journal_entry_item_028.csv'
INTO TABLE journal_entry_item
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(journal_entry_id, account_type_id, debit, credit, remarks, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'journal_entry_item_029.csv'
INTO TABLE journal_entry_item
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(journal_entry_id, account_type_id, debit, credit, remarks, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'journal_entry_item_030.csv'
INTO TABLE journal_entry_item
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(journal_entry_id, account_type_id, debit, credit, remarks, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'journal_entry_item_031.csv'
INTO TABLE journal_entry_item
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(journal_entry_id, account_type_id, debit, credit, remarks, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'journal_entry_item_032.csv'
INTO TABLE journal_entry_item
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(journal_entry_id, account_type_id, debit, credit, remarks, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'journal_entry_item_033.csv'
INTO TABLE journal_entry_item
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(journal_entry_id, account_type_id, debit, credit, remarks, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'journal_entry_item_034.csv'
INTO TABLE journal_entry_item
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(journal_entry_id, account_type_id, debit, credit, remarks, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'journal_entry_item_035.csv'
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

LOAD DATA LOCAL INFILE 'general_ledger_019.csv'
INTO TABLE general_ledger
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(branch_id, source, source_id, account_type_id, date, debit, credit, remark, is_migrated, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'general_ledger_020.csv'
INTO TABLE general_ledger
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(branch_id, source, source_id, account_type_id, date, debit, credit, remark, is_migrated, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'general_ledger_021.csv'
INTO TABLE general_ledger
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(branch_id, source, source_id, account_type_id, date, debit, credit, remark, is_migrated, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'general_ledger_022.csv'
INTO TABLE general_ledger
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(branch_id, source, source_id, account_type_id, date, debit, credit, remark, is_migrated, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'general_ledger_023.csv'
INTO TABLE general_ledger
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(branch_id, source, source_id, account_type_id, date, debit, credit, remark, is_migrated, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'general_ledger_024.csv'
INTO TABLE general_ledger
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(branch_id, source, source_id, account_type_id, date, debit, credit, remark, is_migrated, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'general_ledger_025.csv'
INTO TABLE general_ledger
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(branch_id, source, source_id, account_type_id, date, debit, credit, remark, is_migrated, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'general_ledger_026.csv'
INTO TABLE general_ledger
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(branch_id, source, source_id, account_type_id, date, debit, credit, remark, is_migrated, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'general_ledger_027.csv'
INTO TABLE general_ledger
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(branch_id, source, source_id, account_type_id, date, debit, credit, remark, is_migrated, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'general_ledger_028.csv'
INTO TABLE general_ledger
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(branch_id, source, source_id, account_type_id, date, debit, credit, remark, is_migrated, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'general_ledger_029.csv'
INTO TABLE general_ledger
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(branch_id, source, source_id, account_type_id, date, debit, credit, remark, is_migrated, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'general_ledger_030.csv'
INTO TABLE general_ledger
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(branch_id, source, source_id, account_type_id, date, debit, credit, remark, is_migrated, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'general_ledger_031.csv'
INTO TABLE general_ledger
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(branch_id, source, source_id, account_type_id, date, debit, credit, remark, is_migrated, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'general_ledger_032.csv'
INTO TABLE general_ledger
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(branch_id, source, source_id, account_type_id, date, debit, credit, remark, is_migrated, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'general_ledger_033.csv'
INTO TABLE general_ledger
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(branch_id, source, source_id, account_type_id, date, debit, credit, remark, is_migrated, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'general_ledger_034.csv'
INTO TABLE general_ledger
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(branch_id, source, source_id, account_type_id, date, debit, credit, remark, is_migrated, added_by, added_on, is_deleted);

LOAD DATA LOCAL INFILE 'general_ledger_035.csv'
INTO TABLE general_ledger
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(branch_id, source, source_id, account_type_id, date, debit, credit, remark, is_migrated, added_by, added_on, is_deleted);

SET SESSION foreign_key_checks = 1;
