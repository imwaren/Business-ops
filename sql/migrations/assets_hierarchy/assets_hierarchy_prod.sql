-- Add nullable asset hierarchy and master-group references (PROD); safe to rerun.
SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'mytemr_master' AND TABLE_NAME = 'asset' AND COLUMN_NAME = 'parent_id') = 0,
  'ALTER TABLE `mytemr_master`.`asset` ADD COLUMN `parent_id` INT(11) NULL AFTER `id`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'mytemr_master' AND TABLE_NAME = 'asset' AND COLUMN_NAME = 'group_id') = 0,
  'ALTER TABLE `mytemr_master`.`asset` ADD COLUMN `group_id` INT(11) NULL AFTER `asset_group_id`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'mytemr_diagnostic_mobile' AND TABLE_NAME = 'asset' AND COLUMN_NAME = 'parent_id') = 0,
  'ALTER TABLE `mytemr_diagnostic_mobile`.`asset` ADD COLUMN `parent_id` INT(11) NULL AFTER `id`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'mytemr_diagnostic_mobile' AND TABLE_NAME = 'asset' AND COLUMN_NAME = 'group_id') = 0,
  'ALTER TABLE `mytemr_diagnostic_mobile`.`asset` ADD COLUMN `group_id` INT(11) NULL AFTER `asset_group_id`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'mytemr_diagnostic' AND TABLE_NAME = 'asset' AND COLUMN_NAME = 'parent_id') = 0,
  'ALTER TABLE `mytemr_diagnostic`.`asset` ADD COLUMN `parent_id` INT(11) NULL AFTER `id`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'mytemr_diagnostic' AND TABLE_NAME = 'asset' AND COLUMN_NAME = 'group_id') = 0,
  'ALTER TABLE `mytemr_diagnostic`.`asset` ADD COLUMN `group_id` INT(11) NULL AFTER `asset_group_id`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'mytemr_diagnostic_mactan' AND TABLE_NAME = 'asset' AND COLUMN_NAME = 'parent_id') = 0,
  'ALTER TABLE `mytemr_diagnostic_mactan`.`asset` ADD COLUMN `parent_id` INT(11) NULL AFTER `id`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'mytemr_diagnostic_mactan' AND TABLE_NAME = 'asset' AND COLUMN_NAME = 'group_id') = 0,
  'ALTER TABLE `mytemr_diagnostic_mactan`.`asset` ADD COLUMN `group_id` INT(11) NULL AFTER `asset_group_id`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'mytemr_diagnostic_conso' AND TABLE_NAME = 'asset' AND COLUMN_NAME = 'parent_id') = 0,
  'ALTER TABLE `mytemr_diagnostic_conso`.`asset` ADD COLUMN `parent_id` INT(11) NULL AFTER `id`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'mytemr_diagnostic_conso' AND TABLE_NAME = 'asset' AND COLUMN_NAME = 'group_id') = 0,
  'ALTER TABLE `mytemr_diagnostic_conso`.`asset` ADD COLUMN `group_id` INT(11) NULL AFTER `asset_group_id`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'mytemr_diagnostic_mantlewood' AND TABLE_NAME = 'asset' AND COLUMN_NAME = 'parent_id') = 0,
  'ALTER TABLE `mytemr_diagnostic_mantlewood`.`asset` ADD COLUMN `parent_id` INT(11) NULL AFTER `id`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'mytemr_diagnostic_mantlewood' AND TABLE_NAME = 'asset' AND COLUMN_NAME = 'group_id') = 0,
  'ALTER TABLE `mytemr_diagnostic_mantlewood`.`asset` ADD COLUMN `group_id` INT(11) NULL AFTER `asset_group_id`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'mytemr_oakridge_warehouse' AND TABLE_NAME = 'asset' AND COLUMN_NAME = 'parent_id') = 0,
  'ALTER TABLE `mytemr_oakridge_warehouse`.`asset` ADD COLUMN `parent_id` INT(11) NULL AFTER `id`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'mytemr_oakridge_warehouse' AND TABLE_NAME = 'asset' AND COLUMN_NAME = 'group_id') = 0,
  'ALTER TABLE `mytemr_oakridge_warehouse`.`asset` ADD COLUMN `group_id` INT(11) NULL AFTER `asset_group_id`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'mytemr_oakridge_pharmacy' AND TABLE_NAME = 'asset' AND COLUMN_NAME = 'parent_id') = 0,
  'ALTER TABLE `mytemr_oakridge_pharmacy`.`asset` ADD COLUMN `parent_id` INT(11) NULL AFTER `id`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'mytemr_oakridge_pharmacy' AND TABLE_NAME = 'asset' AND COLUMN_NAME = 'group_id') = 0,
  'ALTER TABLE `mytemr_oakridge_pharmacy`.`asset` ADD COLUMN `group_id` INT(11) NULL AFTER `asset_group_id`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
