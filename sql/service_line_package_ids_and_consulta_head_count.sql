-- Store package_ids as service-line ids (not catalog type_id) and add consulta head_count.
-- Apply on mytemr_master. Safe to re-run.

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

DROP PROCEDURE IF EXISTS service_modify_package_ids_to_varchar$$
CREATE PROCEDURE service_modify_package_ids_to_varchar(IN p_table VARCHAR(64))
BEGIN
    IF EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = p_table
            AND COLUMN_NAME = 'package_ids'
            AND DATA_TYPE IN ('int', 'tinyint', 'smallint', 'mediumint', 'bigint')
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` MODIFY COLUMN `package_ids` VARCHAR(255) NULL');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DROP PROCEDURE IF EXISTS service_backfill_package_ids_to_line_ids$$
CREATE PROCEDURE service_backfill_package_ids_to_line_ids(
    IN p_table VARCHAR(64),
    IN p_parent_col VARCHAR(64)
)
BEGIN
    SET @sql = CONCAT(
        'UPDATE `', p_table, '` rs ',
        'INNER JOIN (',
            'SELECT addon.id AS addon_id, MIN(parent.id) AS parent_id ',
            'FROM `', p_table, '` addon ',
            'INNER JOIN `', p_table, '` parent ',
                'ON parent.`', p_parent_col, '` = addon.`', p_parent_col, '` ',
                'AND parent.is_deleted = 0 ',
                'AND parent.type = ''package'' ',
                'AND CAST(parent.type_id AS CHAR) = CAST(addon.package_ids AS CHAR) ',
            'LEFT JOIN `', p_table, '` already ',
                'ON already.`', p_parent_col, '` = addon.`', p_parent_col, '` ',
                'AND already.is_deleted = 0 ',
                'AND CAST(already.id AS CHAR) = CAST(addon.package_ids AS CHAR) ',
            'WHERE addon.is_deleted = 0 ',
                'AND addon.package_ids IS NOT NULL ',
                'AND addon.package_ids <> '''' ',
                'AND addon.package_ids <> ''0'' ',
                'AND already.id IS NULL ',
            'GROUP BY addon.id',
        ') mapped ON mapped.addon_id = rs.id ',
        'SET rs.package_ids = mapped.parent_id, rs.is_add_on = 1'
    );
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
END$$

DELIMITER ;

CALL service_modify_package_ids_to_varchar('request_service');
CALL service_modify_package_ids_to_varchar('costing_service');
CALL service_modify_package_ids_to_varchar('quotation_service');
CALL service_modify_package_ids_to_varchar('endorsement_service');

CALL service_backfill_package_ids_to_line_ids('request_service', 'request_id');
CALL service_backfill_package_ids_to_line_ids('costing_service', 'costing_id');
CALL service_backfill_package_ids_to_line_ids('quotation_service', 'quotation_id');
CALL service_backfill_package_ids_to_line_ids('endorsement_service', 'endorsement_id');

CALL service_add_column_if_missing('costing_consulta', 'head_count', 'INT NULL AFTER total_amount');
CALL service_add_column_if_missing('endorsement_consulta', 'head_count', 'INT NULL AFTER total_amount');

DROP PROCEDURE IF EXISTS service_add_column_if_missing;
DROP PROCEDURE IF EXISTS service_modify_package_ids_to_varchar;
DROP PROCEDURE IF EXISTS service_backfill_package_ids_to_line_ids;
