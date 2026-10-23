-- Add package_ids / is_add_on on service rows and is_add_on on package-detail rows.
-- Apply on mytemr_master. Safe to re-run: each column is added only if missing.
--
-- Equivalent unguarded statements:
--   ALTER TABLE request_service ADD package_ids INT NULL AFTER charge_to_ids, ADD is_add_on INT NOT NULL DEFAULT 0 AFTER package_ids;
--   ALTER TABLE costing_service ADD package_ids INT NULL AFTER charge_to_ids, ADD is_add_on INT NOT NULL DEFAULT 0 AFTER package_ids;
--   ALTER TABLE quotation_service ADD package_ids INT NULL AFTER charge_to_ids, ADD is_add_on INT NOT NULL DEFAULT 0 AFTER package_ids;
--   ALTER TABLE endorsement_service ADD package_ids INT NULL AFTER charge_to_ids, ADD is_add_on INT NOT NULL DEFAULT 0 AFTER package_ids;
--   ALTER TABLE costing_service_package_detail ADD is_add_on INT NOT NULL DEFAULT 0 AFTER lab_test_id;
--   ALTER TABLE quotation_service_package_detail ADD is_add_on INT NOT NULL DEFAULT 0 AFTER lab_test_id;
--   ALTER TABLE endorsement_service_package_detail ADD is_add_on INT NOT NULL DEFAULT 0 AFTER lab_test_id;

DELIMITER $$

DROP PROCEDURE IF EXISTS service_add_column_if_missing$$
CREATE PROCEDURE service_add_column_if_missing(
    IN p_table VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_definition VARCHAR(255)
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = p_table
            AND COLUMN_NAME = p_column
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DELIMITER ;

CALL service_add_column_if_missing('request_service', 'package_ids', 'INT NULL AFTER charge_to_ids');
CALL service_add_column_if_missing('request_service', 'is_add_on', 'INT NOT NULL DEFAULT 0 AFTER package_ids');

CALL service_add_column_if_missing('costing_service', 'package_ids', 'INT NULL AFTER charge_to_ids');
CALL service_add_column_if_missing('costing_service', 'is_add_on', 'INT NOT NULL DEFAULT 0 AFTER package_ids');

CALL service_add_column_if_missing('quotation_service', 'package_ids', 'INT NULL AFTER charge_to_ids');
CALL service_add_column_if_missing('quotation_service', 'is_add_on', 'INT NOT NULL DEFAULT 0 AFTER package_ids');

CALL service_add_column_if_missing('endorsement_service', 'package_ids', 'INT NULL AFTER charge_to_ids');
CALL service_add_column_if_missing('endorsement_service', 'is_add_on', 'INT NOT NULL DEFAULT 0 AFTER package_ids');

CALL service_add_column_if_missing('costing_service_package_detail', 'is_add_on', 'INT NOT NULL DEFAULT 0 AFTER lab_test_id');
CALL service_add_column_if_missing('quotation_service_package_detail', 'is_add_on', 'INT NOT NULL DEFAULT 0 AFTER lab_test_id');
CALL service_add_column_if_missing('endorsement_service_package_detail', 'is_add_on', 'INT NOT NULL DEFAULT 0 AFTER lab_test_id');

DROP PROCEDURE IF EXISTS service_add_column_if_missing;
