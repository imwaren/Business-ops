-- =============================================================================
-- Sendout & Inter-Branch Census Triggers
-- =============================================================================
--
-- PURPOSE:
--   Keep mytemr_reports.sendout_census and mytemr_reports.inter_branch_census
--   in sync when booking_details or booking_package_details change in a
--   diagnostic database.
--
-- DEPLOYMENT (repeat for each diagnostic database):
--   1. Find-replace __BRANCH_ID__ with the branch ID for that database:
--        1 = Mobile       (mytemr_diagnostic_mobile)
--        2 = Oakridge     (mytemr_diagnostic)
--        3 = Mactan       (mytemr_diagnostic_mactan)
--        4 = Consolacion  (mytemr_diagnostic_conso)
--        7 = Mantlewood   (mytemr_diagnostic_mantlewood)
--   2. Connect to the matching diagnostic database (NOT mytemr_reports).
--   3. Run this entire script.
--
-- PREREQUISITES:
--   - sql/sendout_census.sql applied on mytemr_reports (per-line table).
--   - MySQL user needs INSERT/UPDATE on mytemr_reports.sendout_census and
--     UPDATE on mytemr_reports.inter_branch_census.
--   - Inter-branch hourly rows must already exist (cron:
--     inter_branch_census_report).
--   - Sendout census is upserted per booking line — no cron seeder.
--
-- SENDOUT QUALIFY:
--   status = 'send out'
--   AND performed_at IS NOT NULL
--   AND test_id IS NOT NULL
--   AND line is_deleted = 0
--   AND booking.is_deleted = 0
--   AND transaction.is_deleted = 0
--   date = DATE(COALESCE(detail.updated_on, detail.added_on))
--
-- =============================================================================

DELIMITER $$

-- -----------------------------------------------------------------------------
-- Soft-delete all active sendout_census rows for one channel line
-- -----------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_sendout_census_soft_delete_channel$$
CREATE PROCEDURE sp_sendout_census_soft_delete_channel(
    IN p_branch_id INT,
    IN p_channel VARCHAR(255),
    IN p_channel_id INT,
    IN p_updated_by INT,
    IN p_updated_on DATETIME
)
BEGIN
    UPDATE mytemr_reports.sendout_census
    SET is_deleted = 1,
        updated_by = IFNULL(p_updated_by, 0),
        updated_on = p_updated_on
    WHERE branch_id = p_branch_id
      AND channel = p_channel
      AND channel_id = p_channel_id
      AND is_deleted = 0;
END$$

-- -----------------------------------------------------------------------------
-- Soft-delete all active sendout_census rows for a transaction
-- -----------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_sendout_census_soft_delete_transaction$$
CREATE PROCEDURE sp_sendout_census_soft_delete_transaction(
    IN p_branch_id INT,
    IN p_transaction_id INT,
    IN p_updated_by INT,
    IN p_updated_on DATETIME
)
BEGIN
    IF p_transaction_id IS NOT NULL THEN
        UPDATE mytemr_reports.sendout_census
        SET is_deleted = 1,
            updated_by = IFNULL(p_updated_by, 0),
            updated_on = p_updated_on
        WHERE branch_id = p_branch_id
          AND transaction_id = p_transaction_id
          AND is_deleted = 0;
    END IF;
END$$

-- -----------------------------------------------------------------------------
-- Upsert a qualified sendout_census row; retire other slots for the same line
-- -----------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_sendout_census_upsert$$
CREATE PROCEDURE sp_sendout_census_upsert(
    IN p_branch_id INT,
    IN p_date DATE,
    IN p_transaction_id INT,
    IN p_channel VARCHAR(255),
    IN p_channel_id INT,
    IN p_facility_id INT,
    IN p_service_id INT,
    IN p_total_sales DECIMAL(13,2),
    IN p_total_expense DECIMAL(13,2),
    IN p_added_by INT,
    IN p_added_on DATETIME,
    IN p_updated_by INT,
    IN p_updated_on DATETIME
)
BEGIN
    UPDATE mytemr_reports.sendout_census
    SET is_deleted = 1,
        updated_by = IFNULL(p_updated_by, 0),
        updated_on = p_updated_on
    WHERE branch_id = p_branch_id
      AND channel = p_channel
      AND channel_id = p_channel_id
      AND is_deleted = 0
      AND NOT (
            `date` = p_date
        AND transaction_id = p_transaction_id
        AND facility_id = p_facility_id
        AND service_id = p_service_id
      );

    INSERT INTO mytemr_reports.sendout_census (
        branch_id,
        `date`,
        transaction_id,
        channel,
        channel_id,
        facility_id,
        service_id,
        total_sales,
        total_expense,
        added_by,
        added_on,
        updated_by,
        updated_on,
        is_deleted
    ) VALUES (
        p_branch_id,
        p_date,
        p_transaction_id,
        p_channel,
        p_channel_id,
        p_facility_id,
        p_service_id,
        IFNULL(p_total_sales, 0),
        IFNULL(p_total_expense, 0),
        IFNULL(p_added_by, 0),
        p_added_on,
        IFNULL(p_updated_by, 0),
        p_updated_on,
        0
    )
    ON DUPLICATE KEY UPDATE
        total_sales = VALUES(total_sales),
        total_expense = VALUES(total_expense),
        updated_by = VALUES(updated_by),
        updated_on = VALUES(updated_on),
        is_deleted = 0;
END$$

-- -----------------------------------------------------------------------------
-- Apply sendout census for one booking_details row (non-package)
-- -----------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_sendout_census_from_bd$$
CREATE PROCEDURE sp_sendout_census_from_bd(
    IN p_branch_id INT,
    IN p_bd_id INT
)
BEGIN
    DECLARE v_type VARCHAR(50);
    DECLARE v_status VARCHAR(50);
    DECLARE v_performed_at INT;
    DECLARE v_test_id INT;
    DECLARE v_amount DECIMAL(13,2);
    DECLARE v_is_billed_externally TINYINT;
    DECLARE v_endorsement_id INT;
    DECLARE v_is_deleted INT;
    DECLARE v_updated_by INT;
    DECLARE v_updated_on DATETIME;
    DECLARE v_added_by INT;
    DECLARE v_added_on DATETIME;
    DECLARE v_booking_deleted INT;
    DECLARE v_transaction_id INT;
    DECLARE v_transaction_deleted INT;
    DECLARE v_pro_rate DECIMAL(13,2);
    DECLARE v_rate DECIMAL(13,2);
    DECLARE v_date DATE;
    DECLARE v_sales DECIMAL(13,2);
    DECLARE v_audit_by INT;
    DECLARE v_audit_on DATETIME;
    DECLARE v_done INT DEFAULT 0;
    DECLARE v_child_id INT;
    DECLARE cur_children CURSOR FOR
        SELECT bpd.id
        FROM booking_package_details bpd
        WHERE bpd.booking_detail_id = p_bd_id;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

    SELECT
        bd.type,
        bd.status,
        bd.performed_at,
        bd.test_id,
        bd.amount,
        bd.is_billed_externally,
        bd.endorsement_id,
        bd.is_deleted,
        bd.updated_by,
        bd.updated_on,
        bd.added_by,
        bd.added_on,
        b.is_deleted,
        b.transaction_id,
        t.is_deleted
    INTO
        v_type,
        v_status,
        v_performed_at,
        v_test_id,
        v_amount,
        v_is_billed_externally,
        v_endorsement_id,
        v_is_deleted,
        v_updated_by,
        v_updated_on,
        v_added_by,
        v_added_on,
        v_booking_deleted,
        v_transaction_id,
        v_transaction_deleted
    FROM booking_details bd
    LEFT JOIN booking b ON b.id = bd.booking_id
    LEFT JOIN transaction t ON t.id = b.transaction_id
    WHERE bd.id = p_bd_id
    LIMIT 1;

    SET v_audit_by = COALESCE(v_updated_by, v_added_by, 0);
    SET v_audit_on = COALESCE(v_updated_on, v_added_on, NOW());

    IF v_type = 'package' THEN
        CALL sp_sendout_census_soft_delete_channel(
            p_branch_id,
            'booking_details',
            p_bd_id,
            v_audit_by,
            v_audit_on
        );

        OPEN cur_children;
        child_loop: LOOP
            FETCH cur_children INTO v_child_id;
            IF v_done = 1 THEN
                LEAVE child_loop;
            END IF;
            CALL sp_sendout_census_from_bpd(p_branch_id, v_child_id);
        END LOOP;
        CLOSE cur_children;
    ELSEIF
        v_status = 'send out'
        AND v_performed_at IS NOT NULL
        AND v_test_id IS NOT NULL
        AND IFNULL(v_is_deleted, 0) = 0
        AND IFNULL(v_booking_deleted, 1) = 0
        AND v_transaction_id IS NOT NULL
        AND IFNULL(v_transaction_deleted, 1) = 0
        AND COALESCE(v_updated_on, v_added_on) IS NOT NULL
    THEN
        SET v_date = DATE(COALESCE(v_updated_on, v_added_on));

        SET v_pro_rate = (
            SELECT es.pro_rate_price
            FROM mytemr_master.endorsement_service es
            WHERE es.endorsement_id = v_endorsement_id
              AND es.is_deleted = 0
              AND es.type_id = v_test_id
              AND es.type = CASE WHEN v_type = 'lab' THEN 'lab_test' ELSE v_type END
            LIMIT 1
        );

        SET v_rate = (
            SELECT flt.rate
            FROM facility_lab_test flt
            WHERE flt.facility_id = v_performed_at
              AND flt.lab_test_id = v_test_id
              AND flt.is_deleted = 0
            LIMIT 1
        );

        IF v_is_billed_externally = 1 AND v_endorsement_id IS NOT NULL THEN
            SET v_sales = COALESCE(v_pro_rate, v_amount, 0);
        ELSE
            SET v_sales = IFNULL(v_amount, 0);
        END IF;

        CALL sp_sendout_census_upsert(
            p_branch_id,
            v_date,
            v_transaction_id,
            'booking_details',
            p_bd_id,
            v_performed_at,
            v_test_id,
            v_sales,
            IFNULL(v_rate, 0),
            v_audit_by,
            v_audit_on,
            v_audit_by,
            v_audit_on
        );
    ELSE
        CALL sp_sendout_census_soft_delete_channel(
            p_branch_id,
            'booking_details',
            p_bd_id,
            v_audit_by,
            v_audit_on
        );
    END IF;
END$$

-- -----------------------------------------------------------------------------
-- Apply sendout census for one booking_package_details row
-- -----------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_sendout_census_from_bpd$$
CREATE PROCEDURE sp_sendout_census_from_bpd(
    IN p_branch_id INT,
    IN p_bpd_id INT
)
BEGIN
    DECLARE v_status VARCHAR(50);
    DECLARE v_performed_at INT;
    DECLARE v_test_id INT;
    DECLARE v_is_deleted INT;
    DECLARE v_updated_by INT;
    DECLARE v_updated_on DATETIME;
    DECLARE v_added_by INT;
    DECLARE v_added_on DATETIME;
    DECLARE v_parent_type VARCHAR(50);
    DECLARE v_parent_test_id INT;
    DECLARE v_parent_deleted INT;
    DECLARE v_parent_amount DECIMAL(13,2);
    DECLARE v_parent_billed TINYINT;
    DECLARE v_parent_endorsement_id INT;
    DECLARE v_booking_deleted INT;
    DECLARE v_transaction_id INT;
    DECLARE v_transaction_deleted INT;
    DECLARE v_espd_rate DECIMAL(13,2);
    DECLARE v_pd_rate DECIMAL(13,2);
    DECLARE v_flt_rate DECIMAL(13,2);
    DECLARE v_date DATE;
    DECLARE v_sales DECIMAL(13,2);
    DECLARE v_audit_by INT;
    DECLARE v_audit_on DATETIME;

    SELECT
        bpd.status,
        bpd.performed_at,
        bpd.test_id,
        bpd.is_deleted,
        bpd.updated_by,
        bpd.updated_on,
        bpd.added_by,
        bpd.added_on,
        bd.type,
        bd.test_id,
        bd.is_deleted,
        bd.amount,
        bd.is_billed_externally,
        bd.endorsement_id,
        b.is_deleted,
        b.transaction_id,
        t.is_deleted
    INTO
        v_status,
        v_performed_at,
        v_test_id,
        v_is_deleted,
        v_updated_by,
        v_updated_on,
        v_added_by,
        v_added_on,
        v_parent_type,
        v_parent_test_id,
        v_parent_deleted,
        v_parent_amount,
        v_parent_billed,
        v_parent_endorsement_id,
        v_booking_deleted,
        v_transaction_id,
        v_transaction_deleted
    FROM booking_package_details bpd
    LEFT JOIN booking_details bd ON bd.id = bpd.booking_detail_id
    LEFT JOIN booking b ON b.id = bd.booking_id
    LEFT JOIN transaction t ON t.id = b.transaction_id
    WHERE bpd.id = p_bpd_id
    LIMIT 1;

    SET v_audit_by = COALESCE(v_updated_by, v_added_by, 0);
    SET v_audit_on = COALESCE(v_updated_on, v_added_on, NOW());

    IF
        v_parent_type = 'package'
        AND v_status = 'send out'
        AND v_performed_at IS NOT NULL
        AND v_test_id IS NOT NULL
        AND IFNULL(v_is_deleted, 0) = 0
        AND IFNULL(v_parent_deleted, 1) = 0
        AND IFNULL(v_booking_deleted, 1) = 0
        AND v_transaction_id IS NOT NULL
        AND IFNULL(v_transaction_deleted, 1) = 0
        AND COALESCE(v_updated_on, v_added_on) IS NOT NULL
    THEN
        SET v_date = DATE(COALESCE(v_updated_on, v_added_on));

        SET v_espd_rate = (
            SELECT espd.pro_rate_price
            FROM mytemr_master.endorsement_service es
            INNER JOIN mytemr_master.endorsement_service_package_detail espd
                ON espd.endorsement_service_id = es.id
               AND espd.lab_test_id = v_test_id
               AND espd.is_deleted = 0
            WHERE es.endorsement_id = v_parent_endorsement_id
              AND es.is_deleted = 0
              AND es.type_id = v_parent_test_id
              AND es.type = CASE WHEN v_parent_type = 'lab' THEN 'lab_test' ELSE v_parent_type END
            LIMIT 1
        );

        SET v_pd_rate = (
            SELECT pd.pro_rate_price
            FROM package_detail pd
            WHERE pd.lab_test_id = v_test_id
              AND pd.package_id = v_parent_test_id
              AND pd.is_deleted = 0
            LIMIT 1
        );

        SET v_flt_rate = (
            SELECT flt.rate
            FROM facility_lab_test flt
            WHERE flt.facility_id = v_performed_at
              AND flt.lab_test_id = v_test_id
              AND flt.is_deleted = 0
            LIMIT 1
        );

        IF v_parent_billed = 1 AND v_parent_endorsement_id IS NOT NULL THEN
            SET v_sales = COALESCE(v_espd_rate, v_pd_rate, v_parent_amount, 0);
        ELSE
            SET v_sales = COALESCE(v_pd_rate, v_parent_amount, 0);
        END IF;

        CALL sp_sendout_census_upsert(
            p_branch_id,
            v_date,
            v_transaction_id,
            'booking_package_details',
            p_bpd_id,
            v_performed_at,
            v_test_id,
            v_sales,
            IFNULL(v_flt_rate, 0),
            v_audit_by,
            v_audit_on,
            v_audit_by,
            v_audit_on
        );
    ELSE
        CALL sp_sendout_census_soft_delete_channel(
            p_branch_id,
            'booking_package_details',
            p_bpd_id,
            v_audit_by,
            v_audit_on
        );
    END IF;
END$$

-- -----------------------------------------------------------------------------
-- Re-evaluate every booking_details line for a booking
-- -----------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_sendout_census_from_booking$$
CREATE PROCEDURE sp_sendout_census_from_booking(
    IN p_branch_id INT,
    IN p_booking_id INT
)
BEGIN
    DECLARE v_done INT DEFAULT 0;
    DECLARE v_bd_id INT;
    DECLARE cur_bd CURSOR FOR
        SELECT bd.id
        FROM booking_details bd
        WHERE bd.booking_id = p_booking_id;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

    OPEN cur_bd;
    bd_loop: LOOP
        FETCH cur_bd INTO v_bd_id;
        IF v_done = 1 THEN
            LEAVE bd_loop;
        END IF;
        CALL sp_sendout_census_from_bd(p_branch_id, v_bd_id);
    END LOOP;
    CLOSE cur_bd;
END$$

-- -----------------------------------------------------------------------------
-- Re-evaluate every booking for a transaction
-- -----------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_sendout_census_from_transaction$$
CREATE PROCEDURE sp_sendout_census_from_transaction(
    IN p_branch_id INT,
    IN p_transaction_id INT
)
BEGIN
    DECLARE v_done INT DEFAULT 0;
    DECLARE v_booking_id INT;
    DECLARE cur_b CURSOR FOR
        SELECT b.id
        FROM booking b
        WHERE b.transaction_id = p_transaction_id;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

    IF p_transaction_id IS NOT NULL THEN
        OPEN cur_b;
        b_loop: LOOP
            FETCH cur_b INTO v_booking_id;
            IF v_done = 1 THEN
                LEAVE b_loop;
            END IF;
            CALL sp_sendout_census_from_booking(p_branch_id, v_booking_id);
        END LOOP;
        CLOSE cur_b;
    END IF;
END$$

-- -----------------------------------------------------------------------------
-- Helper: resolve hourly slot and adjust inter_branch_census
-- -----------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_inter_branch_census_adjust$$
CREATE PROCEDURE sp_inter_branch_census_adjust(
    IN p_branch_id_from INT,
    IN p_branch_id_to INT,
    IN p_service_id INT,
    IN p_event_datetime DATETIME,
    IN p_sales_delta DECIMAL(13,4),
    IN p_expense_delta DECIMAL(13,4),
    IN p_count_delta INT
)
BEGIN
    DECLARE v_tat_date DATE;
    DECLARE v_event_time TIME;
    DECLARE v_time_from TIME;
    DECLARE v_time_to TIME;

    IF p_event_datetime IS NOT NULL
        AND p_branch_id_to IS NOT NULL
        AND p_service_id IS NOT NULL
    THEN
        SET v_tat_date = DATE(p_event_datetime);
        SET v_event_time = TIME(p_event_datetime);

        SELECT s.time_from, s.time_to
        INTO v_time_from, v_time_to
        FROM (
            SELECT TIME('06:00:00') AS time_from, TIME('07:00:00') AS time_to UNION ALL
            SELECT TIME('07:00:00'), TIME('08:00:00') UNION ALL
            SELECT TIME('08:00:00'), TIME('09:00:00') UNION ALL
            SELECT TIME('09:00:00'), TIME('10:00:00') UNION ALL
            SELECT TIME('10:00:00'), TIME('11:00:00') UNION ALL
            SELECT TIME('11:00:00'), TIME('12:00:00') UNION ALL
            SELECT TIME('12:00:00'), TIME('13:00:00') UNION ALL
            SELECT TIME('13:00:00'), TIME('14:00:00') UNION ALL
            SELECT TIME('14:00:00'), TIME('15:00:00') UNION ALL
            SELECT TIME('15:00:00'), TIME('16:00:00') UNION ALL
            SELECT TIME('16:00:00'), TIME('17:00:00') UNION ALL
            SELECT TIME('17:00:00'), TIME('18:00:00')
        ) AS s
        WHERE v_event_time >= s.time_from
          AND v_event_time < s.time_to
        LIMIT 1;

        IF v_time_from IS NOT NULL THEN
            UPDATE mytemr_reports.inter_branch_census
            SET total_sales = total_sales + p_sales_delta,
                total_expense = total_expense + p_expense_delta,
                count = count + p_count_delta
            WHERE branch_id_from = p_branch_id_from
              AND branch_id_to = p_branch_id_to
              AND service_id = p_service_id
              AND tat_date = v_tat_date
              AND time_from = v_time_from
              AND time_to = v_time_to
              AND is_deleted = 0;
        END IF;
    END IF;
END$$

-- =============================================================================
-- SENDOUT CENSUS — booking_details
-- =============================================================================

DROP PROCEDURE IF EXISTS sp_sendout_census_adjust$$

DROP TRIGGER IF EXISTS trg_bd_sendout_census_after_insert$$
CREATE TRIGGER trg_bd_sendout_census_after_insert
AFTER INSERT ON booking_details
FOR EACH ROW
BEGIN
    CALL sp_sendout_census_from_bd(__BRANCH_ID__, NEW.id);
END$$

DROP TRIGGER IF EXISTS trg_bd_sendout_census_after_update$$
CREATE TRIGGER trg_bd_sendout_census_after_update
AFTER UPDATE ON booking_details
FOR EACH ROW
BEGIN
    CALL sp_sendout_census_from_bd(__BRANCH_ID__, NEW.id);
END$$

-- =============================================================================
-- SENDOUT CENSUS — booking_package_details
-- =============================================================================

DROP TRIGGER IF EXISTS trg_bpd_sendout_census_after_insert$$
CREATE TRIGGER trg_bpd_sendout_census_after_insert
AFTER INSERT ON booking_package_details
FOR EACH ROW
BEGIN
    CALL sp_sendout_census_from_bpd(__BRANCH_ID__, NEW.id);
END$$

DROP TRIGGER IF EXISTS trg_bpd_sendout_census_after_update$$
CREATE TRIGGER trg_bpd_sendout_census_after_update
AFTER UPDATE ON booking_package_details
FOR EACH ROW
BEGIN
    CALL sp_sendout_census_from_bpd(__BRANCH_ID__, NEW.id);
END$$

-- =============================================================================
-- SENDOUT CENSUS — booking / transaction parent deletes
-- =============================================================================

DROP TRIGGER IF EXISTS trg_booking_sendout_census_after_update$$
CREATE TRIGGER trg_booking_sendout_census_after_update
AFTER UPDATE ON booking
FOR EACH ROW
BEGIN
    IF NEW.is_deleted = 1 AND OLD.is_deleted = 0 THEN
        CALL sp_sendout_census_soft_delete_transaction(
            __BRANCH_ID__,
            COALESCE(NEW.transaction_id, OLD.transaction_id),
            COALESCE(NEW.updated_by, NEW.added_by, 0),
            COALESCE(NEW.updated_on, NOW())
        );
    ELSEIF NEW.is_deleted = 0 AND OLD.is_deleted = 1 THEN
        CALL sp_sendout_census_from_booking(__BRANCH_ID__, NEW.id);
    ELSEIF NEW.is_deleted = 0 AND NOT (NEW.transaction_id <=> OLD.transaction_id) THEN
        CALL sp_sendout_census_soft_delete_transaction(
            __BRANCH_ID__,
            OLD.transaction_id,
            COALESCE(NEW.updated_by, NEW.added_by, 0),
            COALESCE(NEW.updated_on, NOW())
        );
        CALL sp_sendout_census_from_booking(__BRANCH_ID__, NEW.id);
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_transaction_sendout_census_after_update$$
CREATE TRIGGER trg_transaction_sendout_census_after_update
AFTER UPDATE ON transaction
FOR EACH ROW
BEGIN
    IF NEW.is_deleted = 1 AND OLD.is_deleted = 0 THEN
        CALL sp_sendout_census_soft_delete_transaction(
            __BRANCH_ID__,
            NEW.id,
            COALESCE(NEW.updated_by, NEW.added_by, 0),
            COALESCE(NEW.updated_on, NOW())
        );
    ELSEIF NEW.is_deleted = 0 AND OLD.is_deleted = 1 THEN
        CALL sp_sendout_census_from_transaction(__BRANCH_ID__, NEW.id);
    END IF;
END$$

-- =============================================================================
-- INTER-BRANCH CENSUS — booking_details (non-package rows)
-- =============================================================================

DROP TRIGGER IF EXISTS trg_bd_inter_branch_census_after_insert$$
CREATE TRIGGER trg_bd_inter_branch_census_after_insert
AFTER INSERT ON booking_details
FOR EACH ROW
BEGIN
    DECLARE v_event_datetime DATETIME;

    IF NEW.is_deleted = 0
        AND NEW.type != 'package'
        AND NEW.inter_branch_id IS NOT NULL
        AND NEW.test_id IS NOT NULL
    THEN
        SET v_event_datetime = COALESCE(NEW.updated_on, NEW.added_on);

        IF v_event_datetime IS NOT NULL THEN
            CALL sp_inter_branch_census_adjust(
                __BRANCH_ID__,
                NEW.inter_branch_id,
                NEW.test_id,
                v_event_datetime,
                IFNULL(NEW.amount, 0),
                IFNULL(NEW.amount, 0),
                1
            );
        END IF;
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_bd_inter_branch_census_after_update$$
CREATE TRIGGER trg_bd_inter_branch_census_after_update
AFTER UPDATE ON booking_details
FOR EACH ROW
BEGIN
    DECLARE v_old_qualified TINYINT DEFAULT 0;
    DECLARE v_new_qualified TINYINT DEFAULT 0;
    DECLARE v_old_event_datetime DATETIME;
    DECLARE v_new_event_datetime DATETIME;

    IF OLD.is_deleted = 0
        AND OLD.type != 'package'
        AND OLD.inter_branch_id IS NOT NULL
        AND OLD.test_id IS NOT NULL
    THEN
        SET v_old_event_datetime = COALESCE(OLD.updated_on, OLD.added_on);
        IF v_old_event_datetime IS NOT NULL THEN
            SET v_old_qualified = 1;
        END IF;
    END IF;

    IF NEW.is_deleted = 0
        AND NEW.type != 'package'
        AND NEW.inter_branch_id IS NOT NULL
        AND NEW.test_id IS NOT NULL
    THEN
        SET v_new_event_datetime = COALESCE(NEW.updated_on, NEW.added_on);
        IF v_new_event_datetime IS NOT NULL THEN
            SET v_new_qualified = 1;
        END IF;
    END IF;

    IF (
        NOT (NEW.inter_branch_id <=> OLD.inter_branch_id)
        OR NOT (NEW.amount <=> OLD.amount)
        OR NOT (NEW.test_id <=> OLD.test_id)
        OR NOT (NEW.updated_on <=> OLD.updated_on)
        OR NOT (NEW.added_on <=> OLD.added_on)
        OR NEW.is_deleted <> OLD.is_deleted
    ) THEN
        IF v_old_qualified = 1 THEN
            CALL sp_inter_branch_census_adjust(
                __BRANCH_ID__,
                OLD.inter_branch_id,
                OLD.test_id,
                v_old_event_datetime,
                -IFNULL(OLD.amount, 0),
                -IFNULL(OLD.amount, 0),
                -1
            );
        END IF;

        IF v_new_qualified = 1 THEN
            CALL sp_inter_branch_census_adjust(
                __BRANCH_ID__,
                NEW.inter_branch_id,
                NEW.test_id,
                v_new_event_datetime,
                IFNULL(NEW.amount, 0),
                IFNULL(NEW.amount, 0),
                1
            );
        END IF;
    END IF;
END$$

-- =============================================================================
-- INTER-BRANCH CENSUS — booking_package_details (package line items)
-- =============================================================================

DROP TRIGGER IF EXISTS trg_bpd_inter_branch_census_after_insert$$
CREATE TRIGGER trg_bpd_inter_branch_census_after_insert
AFTER INSERT ON booking_package_details
FOR EACH ROW
BEGIN
    DECLARE v_parent_type VARCHAR(50);
    DECLARE v_package_id INT;
    DECLARE v_parent_deleted TINYINT;
    DECLARE v_amount DECIMAL(13,4) DEFAULT 0;
    DECLARE v_event_datetime DATETIME;

    SELECT bd.type, bd.test_id, bd.is_deleted
    INTO v_parent_type, v_package_id, v_parent_deleted
    FROM booking_details bd
    WHERE bd.id = NEW.booking_detail_id
    LIMIT 1;

    IF v_parent_type = 'package'
        AND v_parent_deleted = 0
        AND NEW.is_deleted = 0
        AND NEW.inter_branch_id IS NOT NULL
        AND NEW.test_id IS NOT NULL
    THEN
        SET v_event_datetime = COALESCE(NEW.updated_on, NEW.added_on);

        IF v_event_datetime IS NOT NULL THEN
            SELECT IFNULL(pd.pro_rate_price, 0)
            INTO v_amount
            FROM package_detail pd
            WHERE pd.lab_test_id = NEW.test_id
              AND pd.package_id = v_package_id
              AND pd.is_deleted = 0
            LIMIT 1;

            CALL sp_inter_branch_census_adjust(
                __BRANCH_ID__,
                NEW.inter_branch_id,
                NEW.test_id,
                v_event_datetime,
                v_amount,
                v_amount,
                1
            );
        END IF;
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_bpd_inter_branch_census_after_update$$
CREATE TRIGGER trg_bpd_inter_branch_census_after_update
AFTER UPDATE ON booking_package_details
FOR EACH ROW
BEGIN
    DECLARE v_old_parent_type VARCHAR(50);
    DECLARE v_new_parent_type VARCHAR(50);
    DECLARE v_old_package_id INT;
    DECLARE v_new_package_id INT;
    DECLARE v_old_parent_deleted TINYINT;
    DECLARE v_new_parent_deleted TINYINT;
    DECLARE v_old_qualified TINYINT DEFAULT 0;
    DECLARE v_new_qualified TINYINT DEFAULT 0;
    DECLARE v_old_amount DECIMAL(13,4) DEFAULT 0;
    DECLARE v_new_amount DECIMAL(13,4) DEFAULT 0;
    DECLARE v_old_event_datetime DATETIME;
    DECLARE v_new_event_datetime DATETIME;

    SELECT bd.type, bd.test_id, bd.is_deleted
    INTO v_old_parent_type, v_old_package_id, v_old_parent_deleted
    FROM booking_details bd
    WHERE bd.id = OLD.booking_detail_id
    LIMIT 1;

    SELECT bd.type, bd.test_id, bd.is_deleted
    INTO v_new_parent_type, v_new_package_id, v_new_parent_deleted
    FROM booking_details bd
    WHERE bd.id = NEW.booking_detail_id
    LIMIT 1;

    IF v_old_parent_type = 'package'
        AND v_old_parent_deleted = 0
        AND OLD.is_deleted = 0
        AND OLD.inter_branch_id IS NOT NULL
        AND OLD.test_id IS NOT NULL
    THEN
        SET v_old_event_datetime = COALESCE(OLD.updated_on, OLD.added_on);
        IF v_old_event_datetime IS NOT NULL THEN
            SET v_old_qualified = 1;
            SELECT IFNULL(pd.pro_rate_price, 0)
            INTO v_old_amount
            FROM package_detail pd
            WHERE pd.lab_test_id = OLD.test_id
              AND pd.package_id = v_old_package_id
              AND pd.is_deleted = 0
            LIMIT 1;
        END IF;
    END IF;

    IF v_new_parent_type = 'package'
        AND v_new_parent_deleted = 0
        AND NEW.is_deleted = 0
        AND NEW.inter_branch_id IS NOT NULL
        AND NEW.test_id IS NOT NULL
    THEN
        SET v_new_event_datetime = COALESCE(NEW.updated_on, NEW.added_on);
        IF v_new_event_datetime IS NOT NULL THEN
            SET v_new_qualified = 1;
            SELECT IFNULL(pd.pro_rate_price, 0)
            INTO v_new_amount
            FROM package_detail pd
            WHERE pd.lab_test_id = NEW.test_id
              AND pd.package_id = v_new_package_id
              AND pd.is_deleted = 0
            LIMIT 1;
        END IF;
    END IF;

    IF (
        NOT (NEW.inter_branch_id <=> OLD.inter_branch_id)
        OR NOT (NEW.test_id <=> OLD.test_id)
        OR NOT (NEW.updated_on <=> OLD.updated_on)
        OR NOT (NEW.added_on <=> OLD.added_on)
        OR NEW.is_deleted <> OLD.is_deleted
        OR NEW.booking_detail_id <> OLD.booking_detail_id
    ) THEN
        IF v_old_qualified = 1 THEN
            CALL sp_inter_branch_census_adjust(
                __BRANCH_ID__,
                OLD.inter_branch_id,
                OLD.test_id,
                v_old_event_datetime,
                -v_old_amount,
                -v_old_amount,
                -1
            );
        END IF;

        IF v_new_qualified = 1 THEN
            CALL sp_inter_branch_census_adjust(
                __BRANCH_ID__,
                NEW.inter_branch_id,
                NEW.test_id,
                v_new_event_datetime,
                v_new_amount,
                v_new_amount,
                1
            );
        END IF;
    END IF;
END$$

DELIMITER ;
