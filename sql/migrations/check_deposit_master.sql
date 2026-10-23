-- Master check-deposit tracking records for collection checks.
-- Apply to mytemr_master_uat and mytemr_master.

USE `mytemr_master_uat`;

CREATE TABLE IF NOT EXISTS `check_deposit` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `billing_payment_id` int(11) NOT NULL,
  `amount` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `check_no` varchar(255) DEFAULT NULL,
  `check_date` date DEFAULT NULL,
  `bank_name` varchar(255) DEFAULT NULL,
  `reference_no` varchar(255) DEFAULT NULL,
  `paid_date` date NOT NULL,
  `status` enum('unposted','posted') NOT NULL DEFAULT 'unposted',
  `posted_remarks` varchar(250) DEFAULT NULL,
  `posted_by` int(11) DEFAULT NULL,
  `posted_on` datetime DEFAULT NULL,
  `added_by` int(11) NOT NULL,
  `added_on` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_check_deposit_billing_payment_id` (`billing_payment_id`),
  KEY `idx_check_deposit_status_paid_date` (`status`, `paid_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_check_deposit_account_type_id = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'check_deposit' AND COLUMN_NAME = 'account_type_id'
);
SET @sql = IF(@has_check_deposit_account_type_id = 0,
  'ALTER TABLE check_deposit ADD COLUMN account_type_id INT NULL AFTER billing_payment_id',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

USE `mytemr_master`;

CREATE TABLE IF NOT EXISTS `check_deposit` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `billing_payment_id` int(11) NOT NULL,
  `amount` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `check_no` varchar(255) DEFAULT NULL,
  `check_date` date DEFAULT NULL,
  `bank_name` varchar(255) DEFAULT NULL,
  `reference_no` varchar(255) DEFAULT NULL,
  `paid_date` date NOT NULL,
  `status` enum('unposted','posted') NOT NULL DEFAULT 'unposted',
  `posted_remarks` varchar(250) DEFAULT NULL,
  `posted_by` int(11) DEFAULT NULL,
  `posted_on` datetime DEFAULT NULL,
  `added_by` int(11) NOT NULL,
  `added_on` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_check_deposit_billing_payment_id` (`billing_payment_id`),
  KEY `idx_check_deposit_status_paid_date` (`status`, `paid_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_check_deposit_account_type_id = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'check_deposit' AND COLUMN_NAME = 'account_type_id'
);
SET @sql = IF(@has_check_deposit_account_type_id = 0,
  'ALTER TABLE check_deposit ADD COLUMN account_type_id INT NULL AFTER billing_payment_id',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
