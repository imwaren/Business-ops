-- Add the receipt-note expiry-date table to the warehouse and pharmacy
-- databases in UAT and production. These statements are safe to re-run.

CREATE TABLE IF NOT EXISTS `mytemr_oakridge_warehouse_uat`.`receipt_note_item_expiry_date` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `receipt_note_id` int(11) NOT NULL,
  `receipt_note_item_id` int(11) NOT NULL,
  `date` date DEFAULT NULL,
  `qty` decimal(13,4) DEFAULT 0.0000,
  `added_by` int(11) NOT NULL,
  `added_on` datetime NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` datetime DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mytemr_oakridge_pharmacy_uat`.`receipt_note_item_expiry_date` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `receipt_note_id` int(11) NOT NULL,
  `receipt_note_item_id` int(11) NOT NULL,
  `date` date DEFAULT NULL,
  `qty` decimal(13,4) DEFAULT 0.0000,
  `added_by` int(11) NOT NULL,
  `added_on` datetime NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` datetime DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mytemr_oakridge_warehouse`.`receipt_note_item_expiry_date` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `receipt_note_id` int(11) NOT NULL,
  `receipt_note_item_id` int(11) NOT NULL,
  `date` date DEFAULT NULL,
  `qty` decimal(13,4) DEFAULT 0.0000,
  `added_by` int(11) NOT NULL,
  `added_on` datetime NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` datetime DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mytemr_oakridge_pharmacy`.`receipt_note_item_expiry_date` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `receipt_note_id` int(11) NOT NULL,
  `receipt_note_item_id` int(11) NOT NULL,
  `date` date DEFAULT NULL,
  `qty` decimal(13,4) DEFAULT 0.0000,
  `added_by` int(11) NOT NULL,
  `added_on` datetime NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` datetime DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
