-- Facility catalog tables. Apply CREATE on every catalog DB
-- (master, mobile, oakridge, mactan, consolacion, mantlewood, warehouse, pharmacy).
-- ALTER statements are safe to re-run on master (or any DB whose tables already exist).

CREATE TABLE IF NOT EXISTS `facility` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `supplier_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `address` varchar(255) NOT NULL,
  `added_by` int(11) DEFAULT NULL,
  `added_on` datetime DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` datetime DEFAULT NULL,
  `is_deleted` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `facility_lab_test` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `facility_id` int(11) NOT NULL,
  `lab_test_id` int(11) NOT NULL,
  `rate` decimal(13,2) NOT NULL,
  `added_by` int(11) DEFAULT NULL,
  `added_on` datetime DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` datetime DEFAULT NULL,
  `is_deleted` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Existing tables (especially master): add missing columns / widen address.
ALTER TABLE `facility`
  ADD COLUMN IF NOT EXISTS `supplier_id` int(11) NOT NULL DEFAULT 0 AFTER `id`,
  MODIFY COLUMN `address` varchar(255) NOT NULL,
  ADD COLUMN IF NOT EXISTS `added_by` int(11) DEFAULT NULL AFTER `address`,
  ADD COLUMN IF NOT EXISTS `added_on` datetime DEFAULT NULL AFTER `added_by`,
  ADD COLUMN IF NOT EXISTS `updated_by` int(11) DEFAULT NULL AFTER `added_on`,
  ADD COLUMN IF NOT EXISTS `updated_on` datetime DEFAULT NULL AFTER `updated_by`;

ALTER TABLE `facility_lab_test`
  ADD COLUMN IF NOT EXISTS `added_by` int(11) DEFAULT NULL AFTER `rate`,
  ADD COLUMN IF NOT EXISTS `added_on` datetime DEFAULT NULL AFTER `added_by`,
  ADD COLUMN IF NOT EXISTS `updated_by` int(11) DEFAULT NULL AFTER `added_on`,
  ADD COLUMN IF NOT EXISTS `updated_on` datetime DEFAULT NULL AFTER `updated_by`;
