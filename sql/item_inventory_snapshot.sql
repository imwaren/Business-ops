-- Apply on EVERY inventory database:
-- master, mobile, oakridge, mactan, consolacion, mantlewood, warehouse, pharmacy

CREATE TABLE IF NOT EXISTS `item_inventory_daily` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `snapshot_date` date NOT NULL,
  `branch_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `current_qty` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `weighted_cost` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `ending_cost` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `added_on` datetime NOT NULL,
  `updated_on` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_item_inventory_daily` (`snapshot_date`, `branch_id`, `item_id`),
  KEY `idx_item_inventory_daily_branch_date` (`branch_id`, `snapshot_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `item_inventory_monthly` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `snapshot_month` date NOT NULL,
  `branch_id` int(11) NOT NULL,
  `current_qty` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `ending_cost` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `added_on` datetime NOT NULL,
  `updated_on` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_item_inventory_monthly` (`snapshot_month`, `branch_id`),
  KEY `idx_item_inventory_monthly_month` (`snapshot_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
