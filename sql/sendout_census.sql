-- mytemr_reports.sendout_census — one row per send-out booking line.
-- Replaces the old hourly slot table (tat_date / time_from / time_to / count).
-- Run on mytemr_reports.

DROP TABLE IF EXISTS `sendout_census`;

CREATE TABLE `sendout_census` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `branch_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `transaction_id` int(11) NOT NULL,
  `channel` varchar(255) NOT NULL,
  `channel_id` int(11) NOT NULL,
  `facility_id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL,
  `total_sales` decimal(13,2) NOT NULL DEFAULT 0.00,
  `total_expense` decimal(13,2) NOT NULL DEFAULT 0.00,
  `added_by` int(11) NOT NULL,
  `added_on` datetime DEFAULT NULL,
  `updated_by` int(11) NOT NULL,
  `updated_on` datetime DEFAULT NULL,
  `is_deleted` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sendout_census_slot` (`branch_id`,`date`,`transaction_id`,`channel`,`channel_id`,`facility_id`,`service_id`),
  KEY `idx_sendout_census_channel` (`branch_id`,`channel`,`channel_id`,`is_deleted`),
  KEY `idx_sendout_census_txn` (`branch_id`,`transaction_id`,`is_deleted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
