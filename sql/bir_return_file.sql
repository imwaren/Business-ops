-- Apply on MASTER DB only (mytemr_master).
-- Tracks background BIR 1702-RT / 1702Q PDF generation per closed month and branch.

CREATE TABLE IF NOT EXISTS `bir_return_file` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `closed_month_id` int(11) DEFAULT NULL,
  `type` varchar(20) NOT NULL,
  `branch_id` int(11) NOT NULL DEFAULT 2,
  `year` year NOT NULL,
  `month` int(11) DEFAULT NULL,
  `quarter` int(11) DEFAULT NULL,
  `date_from` date DEFAULT NULL,
  `date_to` date DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `file_path` varchar(512) DEFAULT NULL,
  `file_url` varchar(512) DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `added_by` int(11) DEFAULT NULL,
  `added_on` datetime DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` datetime DEFAULT NULL,
  `is_deleted` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_bir_return_closed_month` (`closed_month_id`),
  KEY `idx_bir_return_lookup` (`type`, `branch_id`, `year`, `month`, `quarter`, `is_deleted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
