-- Add nullable asset hierarchy and master-group references (UAT); safe to rerun.
SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'mytemr_master_uat' AND TABLE_NAME = 'asset' AND COLUMN_NAME = 'parent_id') = 0,
  'ALTER TABLE `mytemr_master_uat`.`asset` ADD COLUMN `parent_id` INT(11) NULL AFTER `id`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'mytemr_master_uat' AND TABLE_NAME = 'asset' AND COLUMN_NAME = 'group_id') = 0,
  'ALTER TABLE `mytemr_master_uat`.`asset` ADD COLUMN `group_id` INT(11) NULL AFTER `asset_group_id`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'mytemr_diagnostic_mobile_uat' AND TABLE_NAME = 'asset' AND COLUMN_NAME = 'parent_id') = 0,
  'ALTER TABLE `mytemr_diagnostic_mobile_uat`.`asset` ADD COLUMN `parent_id` INT(11) NULL AFTER `id`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'mytemr_diagnostic_mobile_uat' AND TABLE_NAME = 'asset' AND COLUMN_NAME = 'group_id') = 0,
  'ALTER TABLE `mytemr_diagnostic_mobile_uat`.`asset` ADD COLUMN `group_id` INT(11) NULL AFTER `asset_group_id`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'mytemr_diagnostic_uat' AND TABLE_NAME = 'asset' AND COLUMN_NAME = 'parent_id') = 0,
  'ALTER TABLE `mytemr_diagnostic_uat`.`asset` ADD COLUMN `parent_id` INT(11) NULL AFTER `id`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'mytemr_diagnostic_uat' AND TABLE_NAME = 'asset' AND COLUMN_NAME = 'group_id') = 0,
  'ALTER TABLE `mytemr_diagnostic_uat`.`asset` ADD COLUMN `group_id` INT(11) NULL AFTER `asset_group_id`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'mytemr_diagnostic_mactan_uat' AND TABLE_NAME = 'asset' AND COLUMN_NAME = 'parent_id') = 0,
  'ALTER TABLE `mytemr_diagnostic_mactan_uat`.`asset` ADD COLUMN `parent_id` INT(11) NULL AFTER `id`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'mytemr_diagnostic_mactan_uat' AND TABLE_NAME = 'asset' AND COLUMN_NAME = 'group_id') = 0,
  'ALTER TABLE `mytemr_diagnostic_mactan_uat`.`asset` ADD COLUMN `group_id` INT(11) NULL AFTER `asset_group_id`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'mytemr_diagnostic_conso_uat' AND TABLE_NAME = 'asset' AND COLUMN_NAME = 'parent_id') = 0,
  'ALTER TABLE `mytemr_diagnostic_conso_uat`.`asset` ADD COLUMN `parent_id` INT(11) NULL AFTER `id`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'mytemr_diagnostic_conso_uat' AND TABLE_NAME = 'asset' AND COLUMN_NAME = 'group_id') = 0,
  'ALTER TABLE `mytemr_diagnostic_conso_uat`.`asset` ADD COLUMN `group_id` INT(11) NULL AFTER `asset_group_id`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'mytemr_diagnostic_mantlewood_uat' AND TABLE_NAME = 'asset' AND COLUMN_NAME = 'parent_id') = 0,
  'ALTER TABLE `mytemr_diagnostic_mantlewood_uat`.`asset` ADD COLUMN `parent_id` INT(11) NULL AFTER `id`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'mytemr_diagnostic_mantlewood_uat' AND TABLE_NAME = 'asset' AND COLUMN_NAME = 'group_id') = 0,
  'ALTER TABLE `mytemr_diagnostic_mantlewood_uat`.`asset` ADD COLUMN `group_id` INT(11) NULL AFTER `asset_group_id`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'mytemr_oakridge_warehouse_uat' AND TABLE_NAME = 'asset' AND COLUMN_NAME = 'parent_id') = 0,
  'ALTER TABLE `mytemr_oakridge_warehouse_uat`.`asset` ADD COLUMN `parent_id` INT(11) NULL AFTER `id`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'mytemr_oakridge_warehouse_uat' AND TABLE_NAME = 'asset' AND COLUMN_NAME = 'group_id') = 0,
  'ALTER TABLE `mytemr_oakridge_warehouse_uat`.`asset` ADD COLUMN `group_id` INT(11) NULL AFTER `asset_group_id`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'mytemr_oakridge_pharmacy_uat' AND TABLE_NAME = 'asset' AND COLUMN_NAME = 'parent_id') = 0,
  'ALTER TABLE `mytemr_oakridge_pharmacy_uat`.`asset` ADD COLUMN `parent_id` INT(11) NULL AFTER `id`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'mytemr_oakridge_pharmacy_uat' AND TABLE_NAME = 'asset' AND COLUMN_NAME = 'group_id') = 0,
  'ALTER TABLE `mytemr_oakridge_pharmacy_uat`.`asset` ADD COLUMN `group_id` INT(11) NULL AFTER `asset_group_id`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
