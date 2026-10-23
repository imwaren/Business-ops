-- Add endorsement_patient.package_ids / is_add_on and backfill add-on lab rows from package details.
-- Apply on mytemr_master. Column ALTERs are safe to re-run. Backfill skips existing add-on rows.
--
-- Equivalent unguarded statements:
--   ALTER TABLE endorsement_patient
--     ADD COLUMN package_ids VARCHAR(255) NULL AFTER charge_to_ids,
--     ADD COLUMN is_add_on INT NOT NULL DEFAULT 0 AFTER package_ids;

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

CALL service_add_column_if_missing('endorsement_patient', 'package_ids', 'VARCHAR(255) NULL AFTER charge_to_ids');
CALL service_add_column_if_missing('endorsement_patient', 'is_add_on', 'INT NOT NULL DEFAULT 0 AFTER package_ids');

DROP PROCEDURE IF EXISTS service_add_column_if_missing;

INSERT INTO endorsement_patient (
    endorsement_id, patient_id, type, type_id,
    with_consultation, type_price, mark_up, total_amount,
    charge_to, charge_to_ids, package_ids, is_add_on, branch_id, mobile_van_id,
    added_on, added_by, is_deleted
)
SELECT DISTINCT
    ep.endorsement_id,
    ep.patient_id,
    'lab_test',
    d.lab_test_id,
    0,
    COALESCE(d.total_amount, d.price, 0),
    0,
    COALESCE(d.total_amount, d.price, 0),
    ep.charge_to,
    ep.charge_to_ids,
    CAST(ep.id AS CHAR),
    1,
    ep.branch_id,
    ep.mobile_van_id,
    NOW(),
    ep.added_by,
    0
FROM endorsement_patient ep
JOIN endorsement_service_package_detail d
    ON d.endorsement_id = ep.endorsement_id
   AND d.package_id = ep.type_id
   AND d.is_add_on = 1
   AND d.is_deleted = 0
LEFT JOIN patient p ON p.id = ep.patient_id
LEFT JOIN endorsement_patient already
    ON already.endorsement_id = ep.endorsement_id
   AND already.patient_id = ep.patient_id
   AND already.type = 'lab_test'
   AND already.type_id = d.lab_test_id
   AND already.is_add_on = 1
   AND already.is_deleted = 0
   AND FIND_IN_SET(ep.id, already.package_ids)
WHERE ep.is_deleted = 0
  AND ep.type = 'package'
  AND IFNULL(ep.is_add_on, 0) = 0
  AND d.lab_test_id IS NOT NULL
  AND already.id IS NULL
  AND NOT (
        d.lab_test_id = 524
        AND UPPER(CAST(AES_DECRYPT(p.sex, 's3cr3tK3y!@#2025') AS CHAR)) = 'MALE'
      );
