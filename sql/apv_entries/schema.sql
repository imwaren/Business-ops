-- Run in each selected diagnostic schema, never the master schema.
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
