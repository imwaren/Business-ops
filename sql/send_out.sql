-- Send-out redo: drop old header/entry/payment tables, add send_out to APV type ENUM,
-- then recreate send_out + send_out_entry (no payment tables) on the five diagnostic DBs.
--
-- send_out.facility_id is a required comma-separated list of facility IDs.
-- get_transaction does not require facility_id, so FE must collect those IDs and pass them on generate.

DROP TABLE mytemr_diagnostic.`send_out`, mytemr_diagnostic.`send_out_entry`, mytemr_diagnostic.`send_out_payment`, mytemr_diagnostic.`send_out_payment_entry`;
DROP TABLE mytemr_diagnostic_conso.`send_out`, mytemr_diagnostic_conso.`send_out_entry`, mytemr_diagnostic_conso.`send_out_payment`, mytemr_diagnostic_conso.`send_out_payment_entry`;
DROP TABLE mytemr_diagnostic_mactan.`send_out`, mytemr_diagnostic_mactan.`send_out_entry`, mytemr_diagnostic_mactan.`send_out_payment`, mytemr_diagnostic_mactan.`send_out_payment_entry`;
DROP TABLE mytemr_diagnostic_mantlewood.`send_out`, mytemr_diagnostic_mantlewood.`send_out_entry`, mytemr_diagnostic_mantlewood.`send_out_payment`, mytemr_diagnostic_mantlewood.`send_out_payment_entry`;
DROP TABLE mytemr_diagnostic_mobile.`send_out`, mytemr_diagnostic_mobile.`send_out_entry`, mytemr_diagnostic_mobile.`send_out_payment`, mytemr_diagnostic_mobile.`send_out_payment_entry`;

ALTER TABLE mytemr_diagnostic.`accounts_payable_voucher` CHANGE `type` `type` ENUM('receipt_note','petty_cash','reimbursement','cash_advance','liquidation','change_fund','direct_payment','replenishment','excess','send_out') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL;
ALTER TABLE mytemr_diagnostic_conso.`accounts_payable_voucher` CHANGE `type` `type` ENUM('receipt_note','petty_cash','reimbursement','cash_advance','liquidation','change_fund','direct_payment','replenishment','excess','send_out') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL;
ALTER TABLE mytemr_diagnostic_mactan.`accounts_payable_voucher` CHANGE `type` `type` ENUM('receipt_note','petty_cash','reimbursement','cash_advance','liquidation','change_fund','direct_payment','replenishment','excess','send_out') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL;
ALTER TABLE mytemr_diagnostic_mantlewood.`accounts_payable_voucher` CHANGE `type` `type` ENUM('receipt_note','petty_cash','reimbursement','cash_advance','liquidation','change_fund','direct_payment','replenishment','excess','send_out') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL;
ALTER TABLE mytemr_diagnostic_mobile.`accounts_payable_voucher` CHANGE `type` `type` ENUM('receipt_note','petty_cash','reimbursement','cash_advance','liquidation','change_fund','direct_payment','replenishment','excess','send_out') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL;

CREATE TABLE mytemr_diagnostic.`send_out` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `branch_id` int(11) NOT NULL,
  `facility_id` varchar(200) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `due_date` date DEFAULT NULL,
  `total_sales` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `total_expense` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `added_by` int(11) NOT NULL,
  `added_on` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `is_deleted` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`)
);

CREATE TABLE mytemr_diagnostic.`send_out_entry` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `send_out_id` int(11) NOT NULL,
  `sendout_census_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `facility_id` int(11) NOT NULL,
  `transaction_id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL,
  `total_sales` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `total_expense` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `added_by` int(11) NOT NULL,
  `added_on` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `is_deleted` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`)
);

CREATE TABLE mytemr_diagnostic_conso.`send_out` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `branch_id` int(11) NOT NULL,
  `facility_id` varchar(200) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `due_date` date DEFAULT NULL,
  `total_sales` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `total_expense` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `added_by` int(11) NOT NULL,
  `added_on` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `is_deleted` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`)
);

CREATE TABLE mytemr_diagnostic_conso.`send_out_entry` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `send_out_id` int(11) NOT NULL,
  `sendout_census_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `facility_id` int(11) NOT NULL,
  `transaction_id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL,
  `total_sales` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `total_expense` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `added_by` int(11) NOT NULL,
  `added_on` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `is_deleted` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`)
);

CREATE TABLE mytemr_diagnostic_mactan.`send_out` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `branch_id` int(11) NOT NULL,
  `facility_id` varchar(200) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `due_date` date DEFAULT NULL,
  `total_sales` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `total_expense` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `added_by` int(11) NOT NULL,
  `added_on` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `is_deleted` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`)
);

CREATE TABLE mytemr_diagnostic_mactan.`send_out_entry` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `send_out_id` int(11) NOT NULL,
  `sendout_census_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `facility_id` int(11) NOT NULL,
  `transaction_id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL,
  `total_sales` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `total_expense` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `added_by` int(11) NOT NULL,
  `added_on` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `is_deleted` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`)
);

CREATE TABLE mytemr_diagnostic_mantlewood.`send_out` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `branch_id` int(11) NOT NULL,
  `facility_id` varchar(200) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `due_date` date DEFAULT NULL,
  `total_sales` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `total_expense` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `added_by` int(11) NOT NULL,
  `added_on` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `is_deleted` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`)
);

CREATE TABLE mytemr_diagnostic_mantlewood.`send_out_entry` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `send_out_id` int(11) NOT NULL,
  `sendout_census_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `facility_id` int(11) NOT NULL,
  `transaction_id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL,
  `total_sales` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `total_expense` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `added_by` int(11) NOT NULL,
  `added_on` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `is_deleted` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`)
);

CREATE TABLE mytemr_diagnostic_mobile.`send_out` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `branch_id` int(11) NOT NULL,
  `facility_id` varchar(200) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `due_date` date DEFAULT NULL,
  `total_sales` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `total_expense` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `added_by` int(11) NOT NULL,
  `added_on` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `is_deleted` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`)
);

CREATE TABLE mytemr_diagnostic_mobile.`send_out_entry` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `send_out_id` int(11) NOT NULL,
  `sendout_census_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `facility_id` int(11) NOT NULL,
  `transaction_id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL,
  `total_sales` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `total_expense` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `added_by` int(11) NOT NULL,
  `added_on` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `is_deleted` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`)
);
