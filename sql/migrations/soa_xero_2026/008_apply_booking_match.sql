-- Apply clinic booking matches to already-inserted migrated billing_entry rows.
-- Requires soa_line, soa_clinic_booking, and @soa_* clinic db vars.

DROP TABLE IF EXISTS soa_booking_match;

CREATE TABLE soa_booking_match AS
SELECT
  sl.id AS line_id,
  cb.booking_id,
  cb.transaction_id,
  cb.master_patient_id,
  ROW_NUMBER() OVER (
    PARTITION BY sl.id
    ORDER BY (cb.company_id <=> sl.company_id) DESC,
      (IFNULL(cb.billing_id, 0) = 0) DESC,
      ABS(IFNULL(cb.amount, 0) - sl.line_amount) ASC,
      cb.booking_id ASC
  ) AS rn
FROM soa_line sl
INNER JOIN soa_clinic_booking cb
  ON cb.branch_id = sl.branch_id
 AND cb.transaction_date = sl.service_date
 AND cb.last_name = sl.last_name
 AND cb.first_name = sl.first_name
WHERE sl.branch_id > 0
  AND sl.service_date IS NOT NULL
  AND IFNULL(sl.last_name, '') <> ''
  AND IFNULL(sl.first_name, '') <> '';

UPDATE soa_line sl
INNER JOIN soa_booking_match sm ON sm.line_id = sl.id AND sm.rn = 1
SET sl.matched_booking_id = sm.booking_id,
    sl.matched_transaction_id = sm.transaction_id,
    sl.matched_master_patient_id = sm.master_patient_id;

UPDATE billing_entry be
INNER JOIN billing b ON b.id = be.billing_id AND b.is_migrated = 1 AND b.is_deleted = 0
INNER JOIN soa_line sl
  ON sl.invoice_no = b.invoice_no
 AND IFNULL(be.remarks, '') = IFNULL(sl.description, '')
 AND be.amount = IFNULL(sl.line_amount, 0)
SET be.booking_id = sl.matched_booking_id,
    be.transaction_id = sl.matched_transaction_id,
    be.master_patient_id = sl.matched_master_patient_id,
    be.as_of_date = COALESCE(sl.service_date, be.as_of_date)
WHERE be.is_migrated = 1
  AND be.is_deleted = 0
  AND IFNULL(be.booking_id, 0) = 0
  AND sl.matched_booking_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS soa_booking_billing_backup (
  branch_id INT(11) NOT NULL,
  booking_id INT(11) NOT NULL,
  clinic_db VARCHAR(64) NOT NULL,
  billing_id_before INT(11) DEFAULT NULL,
  billing_id_after INT(11) DEFAULT NULL,
  backed_up_on DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (branch_id, booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS soa_xero_set_booking_billing;

DELIMITER $$

CREATE PROCEDURE soa_xero_set_booking_billing(
    IN p_branch_id INT,
    IN p_db VARCHAR(64)
)
BEGIN
    DECLARE v_sql TEXT;
    IF p_db IS NULL OR p_db = '' THEN
        SELECT CONCAT('skip booking.billing_id branch ', p_branch_id) AS info;
    ELSE
        SET v_sql = CONCAT(
            'INSERT IGNORE INTO soa_booking_billing_backup ',
            '(branch_id, booking_id, clinic_db, billing_id_before, billing_id_after) ',
            'SELECT ', p_branch_id, ', sl.matched_booking_id, ?, b_old.billing_id, bill.id ',
            'FROM soa_line sl ',
            'INNER JOIN billing bill ON bill.invoice_no = sl.invoice_no AND bill.is_migrated = 1 AND bill.is_deleted = 0 ',
            'INNER JOIN `', p_db, '`.booking b_old ON b_old.id = sl.matched_booking_id ',
            'WHERE sl.branch_id = ', p_branch_id, ' ',
            'AND sl.matched_booking_id IS NOT NULL'
        );
        SET @soa_bak_sql = v_sql;
        PREPARE stmt FROM @soa_bak_sql;
        SET @soa_clinic_db = p_db;
        EXECUTE stmt USING @soa_clinic_db;
        DEALLOCATE PREPARE stmt;

        SET v_sql = CONCAT(
            'UPDATE `', p_db, '`.booking b ',
            'INNER JOIN soa_line sl ON sl.matched_booking_id = b.id AND sl.branch_id = ', p_branch_id, ' ',
            'INNER JOIN billing bill ON bill.invoice_no = sl.invoice_no AND bill.is_migrated = 1 AND bill.is_deleted = 0 ',
            'SET b.billing_id = bill.id ',
            'WHERE IFNULL(b.billing_id, 0) = 0'
        );
        SET @soa_upd_sql = v_sql;
        PREPARE stmt FROM @soa_upd_sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DELIMITER ;

CALL soa_xero_set_booking_billing(2, @soa_oakridge_db);
CALL soa_xero_set_booking_billing(3, @soa_mac_db);
CALL soa_xero_set_booking_billing(4, @soa_conso_db);

SELECT
  (SELECT COUNT(*) FROM billing WHERE is_migrated = 1 AND is_deleted = 0) AS migrated_billings,
  (SELECT COUNT(*) FROM billing_entry WHERE is_migrated = 1 AND is_deleted = 0) AS migrated_entries,
  (SELECT COUNT(*) FROM soa_line WHERE matched_booking_id IS NOT NULL) AS matched_lines,
  (SELECT COUNT(*) FROM billing_entry WHERE is_migrated = 1 AND is_deleted = 0 AND IFNULL(booking_id, 0) > 0) AS matched_entries;
