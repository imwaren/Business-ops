-- Run in the selected UAT or production master schema. No historical backfill.
DELIMITER $$
DROP PROCEDURE IF EXISTS migrate_yakap_period_columns$$
CREATE PROCEDURE migrate_yakap_period_columns()
BEGIN
 IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='billing_entry' AND column_name='yakap_year') THEN
  ALTER TABLE billing_entry ADD COLUMN yakap_year SMALLINT UNSIGNED NULL;
 END IF;
 IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='billing_entry' AND column_name='yakap_month') THEN
  ALTER TABLE billing_entry ADD COLUMN yakap_month TINYINT UNSIGNED NULL;
 END IF;
 IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='billing_entry' AND column_name='yakap_calculation') THEN
  ALTER TABLE billing_entry ADD COLUMN yakap_calculation LONGTEXT NULL;
 END IF;
END$$
CALL migrate_yakap_period_columns()$$
DROP PROCEDURE migrate_yakap_period_columns$$
DELIMITER ;
CREATE TABLE IF NOT EXISTS yakap_billing_period (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 branch_id INT NOT NULL,
 tranche_number TINYINT UNSIGNED NOT NULL,
 year SMALLINT UNSIGNED NOT NULL,
 month TINYINT UNSIGNED NOT NULL DEFAULT 0,
 billing_entry_id INT NULL,
 added_on DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_on DATETIME NULL,
 UNIQUE KEY unique_yakap_period (branch_id,tranche_number,year,month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
