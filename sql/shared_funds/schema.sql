-- Selected MASTER schema only. Additive, no seeds/backfill.
ALTER TABLE accounts_payable_voucher ADD COLUMN IF NOT EXISTS cost_center_id INT NULL;
CREATE INDEX IF NOT EXISTS idx_apv_shared_center ON accounts_payable_voucher(cost_center_id,is_deleted,id);
CREATE TABLE IF NOT EXISTS accounts_payable_voucher_entry (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    accounts_payable_voucher_id INT NOT NULL,
    account_type_id INT NOT NULL,
    debit DECIMAL(13,4) DEFAULT 0.0000,
    credit DECIMAL(13,4) DEFAULT 0.0000,
    remarks VARCHAR(250) NULL,
    added_by INT NOT NULL,
    added_on DATETIME NOT NULL,
    updated_by INT NULL,
    updated_on DATETIME NULL,
    is_deleted TINYINT DEFAULT 0,
    KEY idx_apv_entry_parent (accounts_payable_voucher_id, is_deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounts_payable_voucher_amortization_entry (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    accounts_payable_voucher_id INT NOT NULL,
    expense_account_type_id INT NOT NULL,
    prepaid_account_type_id INT NOT NULL,
    amount DECIMAL(13,4) NOT NULL,
    added_by INT NOT NULL,
    added_on DATETIME NOT NULL,
    updated_by INT NULL,
    updated_on DATETIME NULL,
    is_deleted TINYINT NOT NULL DEFAULT 0,
    KEY idx_apv_amortization_parent (accounts_payable_voucher_id, is_deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Execute under the release write pause. Reruns must never advance the cutoffs.
CREATE TABLE IF NOT EXISTS apv_accounting_cutover (
    id TINYINT NOT NULL PRIMARY KEY,
    legacy_max_apv_id INT NOT NULL,
    legacy_max_direct_payment_id INT NOT NULL,
    cutover_on DATETIME NOT NULL
) ENGINE=InnoDB;
INSERT IGNORE INTO apv_accounting_cutover
SELECT 1, COALESCE((SELECT MAX(id) FROM accounts_payable_voucher),0),
    COALESCE((SELECT MAX(id) FROM direct_payment),0), NOW();
CREATE TABLE IF NOT EXISTS `liquidation_detail` (
 id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 `liquidation_id` INT NOT NULL,
 `account_type_id` INT NULL,
 `invoice_no` VARCHAR(500) NULL,
 `expense_date` DATE NULL,
 `amount` DECIMAL(13,4) NULL,
 `description` TEXT NULL,
 `supplier_id` INT NULL,
 `tin` VARCHAR(500) NULL,
 `address` VARCHAR(500) NULL,
 `vat` DECIMAL(13,4) NULL,
 `total` DECIMAL(13,4) NULL,
 `is_vat` INT NULL,
 added_by INT NOT NULL DEFAULT 0,added_on DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_by INT NULL,updated_on DATETIME NULL,is_deleted TINYINT DEFAULT 0,
 KEY idx_parent(liquidation_id,is_deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `reimbursement_item` (
 id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 `reimbursement_id` INT NOT NULL,
 `account_type_id` INT NULL,
 `supplier_id` INT NULL,
 `invoice_no` VARCHAR(500) NULL,
 `tin` VARCHAR(500) NULL,
 `address` VARCHAR(500) NULL,
 `description` TEXT NULL,
 `expense_date` DATE NULL,
 `amount` DECIMAL(13,4) NULL,
 `vat` DECIMAL(13,4) NULL,
 `total_value` DECIMAL(13,4) NULL,
 `total` DECIMAL(13,4) NULL,
 `remarks` TEXT NULL,
 `is_vat` INT NULL,
 added_by INT NOT NULL DEFAULT 0,added_on DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_by INT NULL,updated_on DATETIME NULL,is_deleted TINYINT DEFAULT 0,
 KEY idx_parent(reimbursement_id,is_deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `replenishment_item` (
 id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 `replenishment_id` INT NOT NULL,
 `account_type_id` INT NULL,
 `supplier_id` INT NULL,
 `invoice_no` VARCHAR(500) NULL,
 `tin` VARCHAR(500) NULL,
 `address` VARCHAR(500) NULL,
 `description` TEXT NULL,
 `expense_date` DATE NULL,
 `amount` DECIMAL(13,4) NULL,
 `vat` DECIMAL(13,4) NULL,
 `total_value` DECIMAL(13,4) NULL,
 `total` DECIMAL(13,4) NULL,
 `remarks` TEXT NULL,
 `is_vat` INT NULL,
 added_by INT NOT NULL DEFAULT 0,added_on DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_by INT NULL,updated_on DATETIME NULL,is_deleted TINYINT DEFAULT 0,
 KEY idx_parent(replenishment_id,is_deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `deposit_detail` (
 id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 `cash_advance_id` INT NOT NULL,
 `amount` DECIMAL(13,4) NULL,
 added_by INT NOT NULL DEFAULT 0,added_on DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_by INT NULL,updated_on DATETIME NULL,is_deleted TINYINT DEFAULT 0,
 KEY idx_parent(cash_advance_id,is_deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `liquidation_attachment` (
 id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 `liquidation_id` INT NOT NULL,
 `file_name` VARCHAR(500) NULL,
 `file_path` VARCHAR(500) NULL,
 `file_url` VARCHAR(500) NULL,
 `mime` VARCHAR(500) NULL,
 added_by INT NOT NULL DEFAULT 0,added_on DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_by INT NULL,updated_on DATETIME NULL,is_deleted TINYINT DEFAULT 0,
 KEY idx_parent(liquidation_id,is_deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `reimbursement_attachment` (
 id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 `reimbursement_id` INT NOT NULL,
 `file_name` VARCHAR(500) NULL,
 `file_path` VARCHAR(500) NULL,
 `file_url` VARCHAR(500) NULL,
 `mime` VARCHAR(500) NULL,
 added_by INT NOT NULL DEFAULT 0,added_on DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_by INT NULL,updated_on DATETIME NULL,is_deleted TINYINT DEFAULT 0,
 KEY idx_parent(reimbursement_id,is_deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `replenishment_attachment` (
 id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 `replenishment_id` INT NOT NULL,
 `file_name` VARCHAR(500) NULL,
 `file_path` VARCHAR(500) NULL,
 `file_url` VARCHAR(500) NULL,
 `mime` VARCHAR(500) NULL,
 added_by INT NOT NULL DEFAULT 0,added_on DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_by INT NULL,updated_on DATETIME NULL,is_deleted TINYINT DEFAULT 0,
 KEY idx_parent(replenishment_id,is_deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `direct_payment_attachment` (
 id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 `direct_payment_id` INT NOT NULL,
 `file_name` VARCHAR(500) NULL,
 `file_path` VARCHAR(500) NULL,
 `file_url` VARCHAR(500) NULL,
 `mime` VARCHAR(500) NULL,
 added_by INT NOT NULL DEFAULT 0,added_on DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_by INT NULL,updated_on DATETIME NULL,is_deleted TINYINT DEFAULT 0,
 KEY idx_parent(direct_payment_id,is_deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `accounts_payable_voucher_attachment` (
 id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 `accounts_payable_voucher_id` INT NOT NULL,
 `file_name` VARCHAR(500) NULL,
 `file_path` VARCHAR(500) NULL,
 `file_url` VARCHAR(500) NULL,
 `mime` VARCHAR(500) NULL,
 added_by INT NOT NULL DEFAULT 0,added_on DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_by INT NULL,updated_on DATETIME NULL,is_deleted TINYINT DEFAULT 0,
 KEY idx_parent(accounts_payable_voucher_id,is_deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX IF NOT EXISTS idx_shared_center ON `petty_cash`(cost_center_id,is_deleted,id);
CREATE INDEX IF NOT EXISTS idx_shared_center ON `cash_advance`(cost_center_id,is_deleted,id);
CREATE INDEX IF NOT EXISTS idx_shared_center ON `liquidation`(cost_center_id,is_deleted,id);
CREATE INDEX IF NOT EXISTS idx_shared_center ON `reimbursement`(cost_center_id,is_deleted,id);
CREATE INDEX IF NOT EXISTS idx_shared_center ON `replenishment`(cost_center_id,is_deleted,id);
CREATE INDEX IF NOT EXISTS idx_shared_center ON `change_fund`(cost_center_id,is_deleted,id);
CREATE INDEX IF NOT EXISTS idx_shared_center ON `direct_payment`(cost_center_id,is_deleted,id);
