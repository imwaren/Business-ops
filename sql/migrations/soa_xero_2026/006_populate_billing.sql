-- Parse soa_dump, match clinic bookings, insert billing + billing_entry (is_migrated=1).
-- Requires session vars set by the import script:
--   @soa_enc_key, @soa_oakridge_db, @soa_mac_db, @soa_conso_db, @soa_mobile_db, @soa_mantlewood_db

DROP TABLE IF EXISTS soa_line;
DROP TABLE IF EXISTS soa_clinic_booking;
DROP TABLE IF EXISTS soa_booking_match;
DROP FUNCTION IF EXISTS soa_parse_date;

DELIMITER $$

CREATE FUNCTION soa_parse_date(s VARCHAR(512))
RETURNS DATE
DETERMINISTIC
BEGIN
    DECLARE v VARCHAR(512);
    DECLARE a INT;
    DECLARE b INT;
    SET v = TRIM(IFNULL(s, ''));
    IF v NOT REGEXP '^[0-9]{1,2}/[0-9]{1,2}/[0-9]{4}$' THEN
        RETURN NULL;
    END IF;
    SET a = CAST(SUBSTRING_INDEX(v, '/', 1) AS UNSIGNED);
    SET b = CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(v, '/', 2), '/', -1) AS UNSIGNED);
    IF a > 12 THEN
        RETURN STR_TO_DATE(v, '%d/%m/%Y');
    END IF;
    IF b > 12 THEN
        RETURN STR_TO_DATE(v, '%m/%d/%Y');
    END IF;
    RETURN STR_TO_DATE(v, '%d/%m/%Y');
END$$

DELIMITER ;

CREATE TABLE soa_line (
  id INT(11) NOT NULL AUTO_INCREMENT,
  soa_dump_id INT(11) NOT NULL,
  invoice_no VARCHAR(64) NOT NULL,
  contact_name VARCHAR(255) DEFAULT NULL,
  company_id INT(11) NOT NULL,
  branch_id INT(11) NOT NULL DEFAULT 0,
  invoice_date DATE DEFAULT NULL,
  due_date DATE DEFAULT NULL,
  service_date DATE DEFAULT NULL,
  patient_name VARCHAR(255) DEFAULT NULL,
  last_name VARCHAR(255) DEFAULT NULL,
  first_name VARCHAR(255) DEFAULT NULL,
  line_amount DECIMAL(13,4) NOT NULL DEFAULT 0.0000,
  description TEXT,
  invoice_total DECIMAL(13,4) NOT NULL DEFAULT 0.0000,
  invoice_paid DECIMAL(13,4) NOT NULL DEFAULT 0.0000,
  invoice_due DECIMAL(13,4) NOT NULL DEFAULT 0.0000,
  invoice_status VARCHAR(64) DEFAULT NULL,
  matched_booking_id INT(11) DEFAULT NULL,
  matched_transaction_id INT(11) DEFAULT NULL,
  matched_master_patient_id INT(11) DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_soa_line_invoice (invoice_no),
  KEY idx_soa_line_match (branch_id, last_name, first_name, service_date, company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE soa_clinic_booking (
  branch_id INT(11) NOT NULL,
  booking_id INT(11) NOT NULL,
  transaction_id INT(11) DEFAULT NULL,
  master_patient_id INT(11) DEFAULT NULL,
  last_name VARCHAR(255) DEFAULT NULL,
  first_name VARCHAR(255) DEFAULT NULL,
  transaction_date DATE DEFAULT NULL,
  company_id INT(11) DEFAULT NULL,
  charge_tos TEXT,
  amount DECIMAL(13,4) DEFAULT 0.0000,
  billing_id INT(11) DEFAULT NULL,
  PRIMARY KEY (branch_id, booking_id),
  KEY idx_soa_cb_match (branch_id, last_name, first_name, transaction_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO soa_line (
  soa_dump_id, invoice_no, contact_name, company_id, branch_id,
  invoice_date, due_date, service_date, patient_name, last_name, first_name,
  line_amount, description, invoice_total, invoice_paid, invoice_due, invoice_status
)
SELECT
  d.id,
  TRIM(d.InvoiceNumber),
  TRIM(d.ContactName),
  m.company_id,
  CASE LEFT(TRIM(d.InvoiceNumber), 1)
    WHEN 'O' THEN 2
    WHEN 'M' THEN 3
    WHEN 'C' THEN 4
    ELSE 0
  END,
  soa_parse_date(d.InvoiceDate),
  soa_parse_date(d.DueDate),
  soa_parse_date(REGEXP_SUBSTR(IFNULL(d.Description, ''), '[0-9]{1,2}/[0-9]{1,2}/[0-9]{4}')),
  TRIM(SUBSTRING_INDEX(IFNULL(d.Description, ''), ' | ', 1)),
  UPPER(TRIM(SUBSTRING_INDEX(TRIM(SUBSTRING_INDEX(IFNULL(d.Description, ''), ' | ', 1)), ',', 1))),
  UPPER(TRIM(SUBSTRING_INDEX(TRIM(SUBSTRING_INDEX(TRIM(SUBSTRING_INDEX(IFNULL(d.Description, ''), ' | ', 1)), ',', -1)), ' ', 1))),
  CAST(NULLIF(TRIM(d.LineAmount), '') AS DECIMAL(13,4)),
  d.Description,
  CAST(NULLIF(TRIM(d.Total), '') AS DECIMAL(13,4)),
  CAST(NULLIF(TRIM(d.InvoiceAmountPaid), '') AS DECIMAL(13,4)),
  CAST(NULLIF(TRIM(d.InvoiceAmountDue), '') AS DECIMAL(13,4)),
  TRIM(d.Status)
FROM soa_dump d
INNER JOIN soa_company_map m ON m.contact_name = TRIM(d.ContactName) AND m.company_id IS NOT NULL
WHERE TRIM(IFNULL(d.Type, '')) = 'Sales invoice'
  AND TRIM(IFNULL(d.Status, '')) <> 'Draft'
  AND TRIM(IFNULL(d.InvoiceNumber, '')) <> '';

DROP PROCEDURE IF EXISTS soa_xero_load_clinic_bookings;

DELIMITER $$

CREATE PROCEDURE soa_xero_load_clinic_bookings(
    IN p_branch_id INT,
    IN p_db VARCHAR(64),
    IN p_key VARCHAR(255)
)
BEGIN
    DECLARE v_sql TEXT;
    IF p_db IS NULL OR p_db = '' THEN
        SELECT CONCAT('skip clinic load branch ', p_branch_id, ': empty db name') AS info;
    ELSE
        SET v_sql = CONCAT(
            'INSERT INTO soa_clinic_booking (',
            'branch_id, booking_id, transaction_id, master_patient_id, last_name, first_name, ',
            'transaction_date, company_id, charge_tos, amount, billing_id) ',
            'SELECT ', p_branch_id, ', b.id, b.transaction_id, p.master_id, ',
            'UPPER(TRIM(CONVERT(CAST(AES_DECRYPT(p.last_name, ?) AS BINARY) USING latin1))), ',
            'UPPER(TRIM(SUBSTRING_INDEX(TRIM(CONVERT(CAST(AES_DECRYPT(p.first_name, ?) AS BINARY) USING latin1)), '' '', 1))), ',
            't.transaction_date, IF(CAST(IFNULL(b.company_id, 0) AS UNSIGNED) = 0, NULL, CAST(b.company_id AS UNSIGNED)), ',
            'GROUP_CONCAT(DISTINCT IF(CAST(IFNULL(bd.charge_to, 0) AS UNSIGNED) = 0, NULL, CAST(bd.charge_to AS UNSIGNED)) SEPARATOR '',''), ',
            '0, ',
            'IF(CAST(IFNULL(b.billing_id, 0) AS CHAR) IN ('''', ''0''), NULL, b.billing_id) ',
            'FROM `', p_db, '`.booking b ',
            'INNER JOIN `', p_db, '`.transaction t ON t.id = b.transaction_id AND t.is_deleted = 0 ',
            'INNER JOIN `', p_db, '`.patient p ON p.id = b.patient_id ',
            'LEFT JOIN `', p_db, '`.booking_details bd ON bd.booking_id = b.id ',
            'WHERE b.is_deleted = 0 ',
            'AND t.transaction_date BETWEEN ''2025-01-01'' AND ''2026-12-31'' ',
            'GROUP BY b.id'
        );
        SET @soa_clinic_sql = v_sql;
        PREPARE stmt FROM @soa_clinic_sql;
        SET @soa_k1 = p_key;
        SET @soa_k2 = p_key;
        EXECUTE stmt USING @soa_k1, @soa_k2;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DELIMITER ;

CALL soa_xero_load_clinic_bookings(2, @soa_oakridge_db, @soa_enc_key);
CALL soa_xero_load_clinic_bookings(3, @soa_mac_db, @soa_enc_key);
CALL soa_xero_load_clinic_bookings(4, @soa_conso_db, @soa_enc_key);

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

INSERT INTO billing (
  company_id, charge_to, due_date, date_from, date_to,
  grand_total, balance, status, invoice_no, invoice_status,
  added_by, added_on, is_deleted, is_migrated
)
SELECT
  sl.company_id,
  sl.company_id,
  MAX(sl.due_date),
  COALESCE(MIN(sl.service_date), MIN(sl.invoice_date), '2026-01-01'),
  COALESCE(MAX(sl.service_date), MAX(sl.invoice_date), '2026-01-01'),
  MAX(IFNULL(sl.invoice_total, 0)),
  MAX(IFNULL(sl.invoice_total, 0)),
  CASE WHEN MAX(sl.invoice_status) = 'Paid' THEN 'closed_bill' ELSE 'open_bill' END,
  sl.invoice_no,
  MAX(sl.invoice_status),
  0,
  NOW(),
  0,
  1
FROM soa_line sl
LEFT JOIN billing existing
  ON existing.invoice_no = sl.invoice_no
 AND existing.is_deleted = 0
WHERE existing.id IS NULL
GROUP BY sl.invoice_no, sl.company_id;

INSERT INTO billing_entry (
  billing_id, branch_id, company_id, booking_id, transaction_id,
  endorsement_id, floating_patient_no, master_patient_id, as_of_date,
  amount, balance, status, remarks, added_by, added_on, is_deleted, is_migrated
)
SELECT
  b.id,
  sl.branch_id,
  sl.company_id,
  IFNULL(sl.matched_booking_id, 0),
  IFNULL(sl.matched_transaction_id, 0),
  NULL,
  NULL,
  sl.matched_master_patient_id,
  sl.service_date,
  IFNULL(sl.line_amount, 0),
  IFNULL(sl.line_amount, 0),
  b.status,
  sl.description,
  0,
  NOW(),
  0,
  1
FROM soa_line sl
INNER JOIN billing b ON b.invoice_no = sl.invoice_no AND b.is_migrated = 1 AND b.is_deleted = 0
WHERE NOT EXISTS (
    SELECT 1
    FROM billing_entry be
    WHERE be.billing_id = b.id
      AND be.is_migrated = 1
      AND be.is_deleted = 0
      AND IFNULL(be.remarks, '') = IFNULL(sl.description, '')
      AND be.amount = IFNULL(sl.line_amount, 0)
);

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
  (SELECT COUNT(*) FROM soa_line) AS soa_lines,
  (SELECT COUNT(*) FROM soa_line WHERE matched_booking_id IS NOT NULL) AS matched_lines,
  (SELECT COUNT(*) FROM soa_line WHERE matched_booking_id IS NULL) AS unmatched_lines;
