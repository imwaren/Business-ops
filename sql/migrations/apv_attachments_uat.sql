-- APV and APV-payment attachment metadata for UAT.
-- APV metadata lives beside branch-local accounts_payable_voucher records.
-- Payment metadata lives in mytemr_master_uat beside payment records.

CREATE TABLE IF NOT EXISTS `mytemr_diagnostic_mobile_uat`.`accounts_payable_voucher_attachment` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `accounts_payable_voucher_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_url` varchar(500) NOT NULL,
  `mime` varchar(100) DEFAULT NULL,
  `added_by` int(11) NOT NULL,
  `added_on` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_apv_attachment_parent` (`accounts_payable_voucher_id`, `is_deleted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mytemr_diagnostic_uat`.`accounts_payable_voucher_attachment` LIKE `mytemr_diagnostic_mobile_uat`.`accounts_payable_voucher_attachment`;
CREATE TABLE IF NOT EXISTS `mytemr_diagnostic_mactan_uat`.`accounts_payable_voucher_attachment` LIKE `mytemr_diagnostic_mobile_uat`.`accounts_payable_voucher_attachment`;
CREATE TABLE IF NOT EXISTS `mytemr_diagnostic_conso_uat`.`accounts_payable_voucher_attachment` LIKE `mytemr_diagnostic_mobile_uat`.`accounts_payable_voucher_attachment`;
CREATE TABLE IF NOT EXISTS `mytemr_diagnostic_mantlewood_uat`.`accounts_payable_voucher_attachment` LIKE `mytemr_diagnostic_mobile_uat`.`accounts_payable_voucher_attachment`;

CREATE TABLE IF NOT EXISTS `mytemr_master_uat`.`accounts_payable_voucher_payment_attachment` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `accounts_payable_voucher_payment_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_url` varchar(500) NOT NULL,
  `mime` varchar(100) DEFAULT NULL,
  `added_by` int(11) NOT NULL,
  `added_on` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_apv_payment_attachment_parent` (`accounts_payable_voucher_payment_id`, `is_deleted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
