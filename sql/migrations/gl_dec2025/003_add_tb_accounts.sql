-- Add TB beginning-balance accounts missing from the COA.
-- AR - MSC (102-190) cloned from AR - LHPI; LOANS PAYABLE - BDO (215-122) cloned from LOANS PAYABLE - BPI.
-- Run once on master + each clinic before loading gl_dec2025/master/import.sql:
--   mysql -u root -p < sql/migrations/gl_dec2025/003_add_tb_accounts.sql

SET NAMES utf8mb4;

INSERT IGNORE INTO mytemr_master.account_type (`id`, `parent_id`, `nature`, `name`, `category`, `financial_statement`, `status`, `group_code`, `code`, `no`, `tax_code`, `description`, `is_dashboard`, `is_expense_claims`, `is_enable_payments`, `added_by`, `added_on`, `updated_by`, `updated_on`, `is_deleted`) VALUES
(1801, NULL, 'debit', 'AR - MSC', 'current_asset', 'balance_sheet', 'active', '130', '102-190', NULL, 'no_applicable_0', NULL, 0, 0, 0, 108, '2026-09-01 00:00:00', NULL, NULL, 0),
(1802, 1801, 'debit', 'CURRENT ASSETS', 'current_asset', 'balance_sheet', 'active', '130', '100', '1', NULL, NULL, 0, 0, 0, 108, '2026-09-01 00:00:00', NULL, NULL, 0),
(1803, 1801, 'debit', 'NON TRADE RECEIVABLE', 'current_asset', 'balance_sheet', 'active', '130', '130', '2', NULL, NULL, 0, 0, 0, 108, '2026-09-01 00:00:00', NULL, NULL, 0),
(1804, 1801, 'debit', 'ADVANCES - OTHERS', 'current_asset', 'balance_sheet', 'active', '130', '130.99', '3', NULL, NULL, 0, 0, 0, 108, '2026-09-01 00:00:00', NULL, NULL, 0),
(1805, NULL, 'credit', 'LOANS PAYABLE - BDO', 'non_current_liability', 'balance_sheet', 'active', '410', '215-122', NULL, 'no_applicable_0', NULL, 0, 0, 0, 108, '2026-09-01 00:00:00', NULL, NULL, 0),
(1806, 1805, 'credit', 'NONCURRENT LIABILITIES', 'non_current_liability', 'balance_sheet', 'active', '410', '400', '1', NULL, NULL, 0, 0, 0, 108, '2026-09-01 00:00:00', NULL, NULL, 0),
(1807, 1805, 'credit', 'LOANS PAYABLE', 'non_current_liability', 'balance_sheet', 'active', '410', '410', '2', NULL, NULL, 0, 0, 0, 108, '2026-09-01 00:00:00', NULL, NULL, 0),
(1808, 1805, 'credit', 'LONG TERM LOANS', 'non_current_liability', 'balance_sheet', 'active', '410', '410.1', '3', NULL, NULL, 0, 0, 0, 108, '2026-09-01 00:00:00', NULL, NULL, 0);

INSERT IGNORE INTO mytemr_diagnostic_mobile.account_type (`id`, `parent_id`, `nature`, `name`, `category`, `financial_statement`, `status`, `group_code`, `code`, `no`, `tax_code`, `description`, `is_dashboard`, `is_expense_claims`, `is_enable_payments`, `added_by`, `added_on`, `updated_by`, `updated_on`, `is_deleted`)
SELECT `id`, `parent_id`, `nature`, `name`, `category`, `financial_statement`, `status`, `group_code`, `code`, `no`, `tax_code`, `description`, `is_dashboard`, `is_expense_claims`, `is_enable_payments`, `added_by`, `added_on`, `updated_by`, `updated_on`, `is_deleted`
FROM mytemr_master.account_type WHERE `id` BETWEEN 1801 AND 1808;

INSERT IGNORE INTO mytemr_diagnostic.account_type (`id`, `parent_id`, `nature`, `name`, `category`, `financial_statement`, `status`, `group_code`, `code`, `no`, `tax_code`, `description`, `is_dashboard`, `is_expense_claims`, `is_enable_payments`, `added_by`, `added_on`, `updated_by`, `updated_on`, `is_deleted`)
SELECT `id`, `parent_id`, `nature`, `name`, `category`, `financial_statement`, `status`, `group_code`, `code`, `no`, `tax_code`, `description`, `is_dashboard`, `is_expense_claims`, `is_enable_payments`, `added_by`, `added_on`, `updated_by`, `updated_on`, `is_deleted`
FROM mytemr_master.account_type WHERE `id` BETWEEN 1801 AND 1808;

INSERT IGNORE INTO mytemr_diagnostic_mactan.account_type (`id`, `parent_id`, `nature`, `name`, `category`, `financial_statement`, `status`, `group_code`, `code`, `no`, `tax_code`, `description`, `is_dashboard`, `is_expense_claims`, `is_enable_payments`, `added_by`, `added_on`, `updated_by`, `updated_on`, `is_deleted`)
SELECT `id`, `parent_id`, `nature`, `name`, `category`, `financial_statement`, `status`, `group_code`, `code`, `no`, `tax_code`, `description`, `is_dashboard`, `is_expense_claims`, `is_enable_payments`, `added_by`, `added_on`, `updated_by`, `updated_on`, `is_deleted`
FROM mytemr_master.account_type WHERE `id` BETWEEN 1801 AND 1808;

INSERT IGNORE INTO mytemr_diagnostic_conso.account_type (`id`, `parent_id`, `nature`, `name`, `category`, `financial_statement`, `status`, `group_code`, `code`, `no`, `tax_code`, `description`, `is_dashboard`, `is_expense_claims`, `is_enable_payments`, `added_by`, `added_on`, `updated_by`, `updated_on`, `is_deleted`)
SELECT `id`, `parent_id`, `nature`, `name`, `category`, `financial_statement`, `status`, `group_code`, `code`, `no`, `tax_code`, `description`, `is_dashboard`, `is_expense_claims`, `is_enable_payments`, `added_by`, `added_on`, `updated_by`, `updated_on`, `is_deleted`
FROM mytemr_master.account_type WHERE `id` BETWEEN 1801 AND 1808;

INSERT IGNORE INTO mytemr_diagnostic_mantlewood.account_type (`id`, `parent_id`, `nature`, `name`, `category`, `financial_statement`, `status`, `group_code`, `code`, `no`, `tax_code`, `description`, `is_dashboard`, `is_expense_claims`, `is_enable_payments`, `added_by`, `added_on`, `updated_by`, `updated_on`, `is_deleted`)
SELECT `id`, `parent_id`, `nature`, `name`, `category`, `financial_statement`, `status`, `group_code`, `code`, `no`, `tax_code`, `description`, `is_dashboard`, `is_expense_claims`, `is_enable_payments`, `added_by`, `added_on`, `updated_by`, `updated_on`, `is_deleted`
FROM mytemr_master.account_type WHERE `id` BETWEEN 1801 AND 1808;

ALTER TABLE mytemr_master.account_type AUTO_INCREMENT = 1809;
ALTER TABLE mytemr_diagnostic_mobile.account_type AUTO_INCREMENT = 1809;
ALTER TABLE mytemr_diagnostic.account_type AUTO_INCREMENT = 1809;
ALTER TABLE mytemr_diagnostic_mactan.account_type AUTO_INCREMENT = 1809;
ALTER TABLE mytemr_diagnostic_conso.account_type AUTO_INCREMENT = 1809;
ALTER TABLE mytemr_diagnostic_mantlewood.account_type AUTO_INCREMENT = 1809;
