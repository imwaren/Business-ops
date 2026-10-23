-- Backfill mytemr_reports_uat.sendout_census from diagnostic booking lines.
-- Run after sql/sendout_census.sql. Can run from any DB that can see
-- mytemr_reports_uat, mytemr_master_uat, and the five diagnostic databases.
--
-- Qualify (same as sendout_inter_branch_census_triggers.sql):
--   status = 'send out'
--   AND performed_at IS NOT NULL
--   AND test_id IS NOT NULL
--   AND line / booking / transaction is_deleted = 0
--   date = DATE(COALESCE(detail.updated_on, detail.added_on))

TRUNCATE TABLE mytemr_reports_uat.sendout_census;

INSERT INTO mytemr_reports_uat.sendout_census (
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
    updated_on
)
-- ---------------------------------------------------------------------------
-- Lab / non-package booking_details
-- ---------------------------------------------------------------------------
SELECT
        1 AS branch_id,
        DATE(COALESCE(bd.updated_on, bd.added_on)) AS `date`,
        b.transaction_id,
        'booking_details' AS channel,
        bd.id AS channel_id,
        bd.performed_at AS facility_id,
        bd.test_id AS service_id,
        CASE
            WHEN bd.is_billed_externally = 1 AND bd.endorsement_id IS NOT NULL
            THEN COALESCE(es.pro_rate_price, bd.amount, 0)
            ELSE IFNULL(bd.amount, 0)
        END AS total_sales,
        IFNULL(flt.rate, 0) AS total_expense,
        COALESCE(bd.updated_by, bd.added_by, 0) AS added_by,
        COALESCE(bd.updated_on, bd.added_on) AS added_on,
        COALESCE(bd.updated_by, bd.added_by, 0) AS updated_by,
        COALESCE(bd.updated_on, bd.added_on) AS updated_on
    FROM mytemr_diagnostic_mobile_uat.booking_details bd
    INNER JOIN mytemr_diagnostic_mobile_uat.booking b
        ON b.id = bd.booking_id AND b.is_deleted = 0
    INNER JOIN mytemr_diagnostic_mobile_uat.transaction t
        ON t.id = b.transaction_id AND t.is_deleted = 0
    LEFT JOIN (
        SELECT endorsement_id, type_id, type, MIN(pro_rate_price) AS pro_rate_price
        FROM mytemr_master_uat.endorsement_service
        WHERE is_deleted = 0
        GROUP BY endorsement_id, type_id, type
    ) es
        ON es.endorsement_id = bd.endorsement_id
       AND es.type_id = bd.test_id
       AND es.type = CASE WHEN bd.type = 'lab' THEN 'lab_test' ELSE bd.type END
    LEFT JOIN (
        SELECT facility_id, lab_test_id, MIN(rate) AS rate
        FROM mytemr_diagnostic_mobile_uat.facility_lab_test
        WHERE is_deleted = 0
        GROUP BY facility_id, lab_test_id
    ) flt
        ON flt.facility_id = bd.performed_at
       AND flt.lab_test_id = bd.test_id
    WHERE bd.is_deleted = 0
      AND bd.status = 'send out'
      AND bd.performed_at IS NOT NULL
      AND bd.test_id IS NOT NULL
      AND bd.type != 'package'
      AND COALESCE(bd.updated_on, bd.added_on) IS NOT NULL

    UNION ALL

    SELECT
        2 AS branch_id,
        DATE(COALESCE(bd.updated_on, bd.added_on)),
        b.transaction_id,
        'booking_details',
        bd.id,
        bd.performed_at,
        bd.test_id,
        CASE
            WHEN bd.is_billed_externally = 1 AND bd.endorsement_id IS NOT NULL
            THEN COALESCE(es.pro_rate_price, bd.amount, 0)
            ELSE IFNULL(bd.amount, 0)
        END,
        IFNULL(flt.rate, 0),
        COALESCE(bd.updated_by, bd.added_by, 0),
        COALESCE(bd.updated_on, bd.added_on),
        COALESCE(bd.updated_by, bd.added_by, 0),
        COALESCE(bd.updated_on, bd.added_on)
    FROM mytemr_diagnostic_uat.booking_details bd
    INNER JOIN mytemr_diagnostic_uat.booking b
        ON b.id = bd.booking_id AND b.is_deleted = 0
    INNER JOIN mytemr_diagnostic_uat.transaction t
        ON t.id = b.transaction_id AND t.is_deleted = 0
    LEFT JOIN (
        SELECT endorsement_id, type_id, type, MIN(pro_rate_price) AS pro_rate_price
        FROM mytemr_master_uat.endorsement_service
        WHERE is_deleted = 0
        GROUP BY endorsement_id, type_id, type
    ) es
        ON es.endorsement_id = bd.endorsement_id
       AND es.type_id = bd.test_id
       AND es.type = CASE WHEN bd.type = 'lab' THEN 'lab_test' ELSE bd.type END
    LEFT JOIN (
        SELECT facility_id, lab_test_id, MIN(rate) AS rate
        FROM mytemr_diagnostic_uat.facility_lab_test
        WHERE is_deleted = 0
        GROUP BY facility_id, lab_test_id
    ) flt
        ON flt.facility_id = bd.performed_at
       AND flt.lab_test_id = bd.test_id
    WHERE bd.is_deleted = 0
      AND bd.status = 'send out'
      AND bd.performed_at IS NOT NULL
      AND bd.test_id IS NOT NULL
      AND bd.type != 'package'
      AND COALESCE(bd.updated_on, bd.added_on) IS NOT NULL

    UNION ALL

    SELECT
        3 AS branch_id,
        DATE(COALESCE(bd.updated_on, bd.added_on)),
        b.transaction_id,
        'booking_details',
        bd.id,
        bd.performed_at,
        bd.test_id,
        CASE
            WHEN bd.is_billed_externally = 1 AND bd.endorsement_id IS NOT NULL
            THEN COALESCE(es.pro_rate_price, bd.amount, 0)
            ELSE IFNULL(bd.amount, 0)
        END,
        IFNULL(flt.rate, 0),
        COALESCE(bd.updated_by, bd.added_by, 0),
        COALESCE(bd.updated_on, bd.added_on),
        COALESCE(bd.updated_by, bd.added_by, 0),
        COALESCE(bd.updated_on, bd.added_on)
    FROM mytemr_diagnostic_mactan_uat.booking_details bd
    INNER JOIN mytemr_diagnostic_mactan_uat.booking b
        ON b.id = bd.booking_id AND b.is_deleted = 0
    INNER JOIN mytemr_diagnostic_mactan_uat.transaction t
        ON t.id = b.transaction_id AND t.is_deleted = 0
    LEFT JOIN (
        SELECT endorsement_id, type_id, type, MIN(pro_rate_price) AS pro_rate_price
        FROM mytemr_master_uat.endorsement_service
        WHERE is_deleted = 0
        GROUP BY endorsement_id, type_id, type
    ) es
        ON es.endorsement_id = bd.endorsement_id
       AND es.type_id = bd.test_id
       AND es.type = CASE WHEN bd.type = 'lab' THEN 'lab_test' ELSE bd.type END
    LEFT JOIN (
        SELECT facility_id, lab_test_id, MIN(rate) AS rate
        FROM mytemr_diagnostic_mactan_uat.facility_lab_test
        WHERE is_deleted = 0
        GROUP BY facility_id, lab_test_id
    ) flt
        ON flt.facility_id = bd.performed_at
       AND flt.lab_test_id = bd.test_id
    WHERE bd.is_deleted = 0
      AND bd.status = 'send out'
      AND bd.performed_at IS NOT NULL
      AND bd.test_id IS NOT NULL
      AND bd.type != 'package'
      AND COALESCE(bd.updated_on, bd.added_on) IS NOT NULL

    UNION ALL

    SELECT
        4 AS branch_id,
        DATE(COALESCE(bd.updated_on, bd.added_on)),
        b.transaction_id,
        'booking_details',
        bd.id,
        bd.performed_at,
        bd.test_id,
        CASE
            WHEN bd.is_billed_externally = 1 AND bd.endorsement_id IS NOT NULL
            THEN COALESCE(es.pro_rate_price, bd.amount, 0)
            ELSE IFNULL(bd.amount, 0)
        END,
        IFNULL(flt.rate, 0),
        COALESCE(bd.updated_by, bd.added_by, 0),
        COALESCE(bd.updated_on, bd.added_on),
        COALESCE(bd.updated_by, bd.added_by, 0),
        COALESCE(bd.updated_on, bd.added_on)
    FROM mytemr_diagnostic_conso_uat.booking_details bd
    INNER JOIN mytemr_diagnostic_conso_uat.booking b
        ON b.id = bd.booking_id AND b.is_deleted = 0
    INNER JOIN mytemr_diagnostic_conso_uat.transaction t
        ON t.id = b.transaction_id AND t.is_deleted = 0
    LEFT JOIN (
        SELECT endorsement_id, type_id, type, MIN(pro_rate_price) AS pro_rate_price
        FROM mytemr_master_uat.endorsement_service
        WHERE is_deleted = 0
        GROUP BY endorsement_id, type_id, type
    ) es
        ON es.endorsement_id = bd.endorsement_id
       AND es.type_id = bd.test_id
       AND es.type = CASE WHEN bd.type = 'lab' THEN 'lab_test' ELSE bd.type END
    LEFT JOIN (
        SELECT facility_id, lab_test_id, MIN(rate) AS rate
        FROM mytemr_diagnostic_conso_uat.facility_lab_test
        WHERE is_deleted = 0
        GROUP BY facility_id, lab_test_id
    ) flt
        ON flt.facility_id = bd.performed_at
       AND flt.lab_test_id = bd.test_id
    WHERE bd.is_deleted = 0
      AND bd.status = 'send out'
      AND bd.performed_at IS NOT NULL
      AND bd.test_id IS NOT NULL
      AND bd.type != 'package'
      AND COALESCE(bd.updated_on, bd.added_on) IS NOT NULL

    UNION ALL

    SELECT
        7 AS branch_id,
        DATE(COALESCE(bd.updated_on, bd.added_on)),
        b.transaction_id,
        'booking_details',
        bd.id,
        bd.performed_at,
        bd.test_id,
        CASE
            WHEN bd.is_billed_externally = 1 AND bd.endorsement_id IS NOT NULL
            THEN COALESCE(es.pro_rate_price, bd.amount, 0)
            ELSE IFNULL(bd.amount, 0)
        END,
        IFNULL(flt.rate, 0),
        COALESCE(bd.updated_by, bd.added_by, 0),
        COALESCE(bd.updated_on, bd.added_on),
        COALESCE(bd.updated_by, bd.added_by, 0),
        COALESCE(bd.updated_on, bd.added_on)
    FROM mytemr_diagnostic_mantlewood_uat.booking_details bd
    INNER JOIN mytemr_diagnostic_mantlewood_uat.booking b
        ON b.id = bd.booking_id AND b.is_deleted = 0
    INNER JOIN mytemr_diagnostic_mantlewood_uat.transaction t
        ON t.id = b.transaction_id AND t.is_deleted = 0
    LEFT JOIN (
        SELECT endorsement_id, type_id, type, MIN(pro_rate_price) AS pro_rate_price
        FROM mytemr_master_uat.endorsement_service
        WHERE is_deleted = 0
        GROUP BY endorsement_id, type_id, type
    ) es
        ON es.endorsement_id = bd.endorsement_id
       AND es.type_id = bd.test_id
       AND es.type = CASE WHEN bd.type = 'lab' THEN 'lab_test' ELSE bd.type END
    LEFT JOIN (
        SELECT facility_id, lab_test_id, MIN(rate) AS rate
        FROM mytemr_diagnostic_mantlewood_uat.facility_lab_test
        WHERE is_deleted = 0
        GROUP BY facility_id, lab_test_id
    ) flt
        ON flt.facility_id = bd.performed_at
       AND flt.lab_test_id = bd.test_id
    WHERE bd.is_deleted = 0
      AND bd.status = 'send out'
      AND bd.performed_at IS NOT NULL
      AND bd.test_id IS NOT NULL
      AND bd.type != 'package'
      AND COALESCE(bd.updated_on, bd.added_on) IS NOT NULL

    UNION ALL

    -- -----------------------------------------------------------------------
    -- Package booking_package_details
    -- -----------------------------------------------------------------------
    SELECT
        1 AS branch_id,
        DATE(COALESCE(bpd.updated_on, bpd.added_on)),
        b.transaction_id,
        'booking_package_details',
        bpd.id,
        bpd.performed_at,
        bpd.test_id,
        CASE
            WHEN bd.is_billed_externally = 1 AND bd.endorsement_id IS NOT NULL
            THEN COALESCE(espd.pro_rate_price, pd.pro_rate_price, bd.amount, 0)
            ELSE COALESCE(pd.pro_rate_price, bd.amount, 0)
        END,
        IFNULL(flt.rate, 0),
        COALESCE(bpd.updated_by, bpd.added_by, 0),
        COALESCE(bpd.updated_on, bpd.added_on),
        COALESCE(bpd.updated_by, bpd.added_by, 0),
        COALESCE(bpd.updated_on, bpd.added_on)
    FROM mytemr_diagnostic_mobile_uat.booking_package_details bpd
    INNER JOIN mytemr_diagnostic_mobile_uat.booking_details bd
        ON bd.id = bpd.booking_detail_id
       AND bd.is_deleted = 0
       AND bd.type = 'package'
    INNER JOIN mytemr_diagnostic_mobile_uat.booking b
        ON b.id = bd.booking_id AND b.is_deleted = 0
    INNER JOIN mytemr_diagnostic_mobile_uat.transaction t
        ON t.id = b.transaction_id AND t.is_deleted = 0
    LEFT JOIN (
        SELECT es.endorsement_id, es.type_id, es.type, espd.lab_test_id, MIN(espd.pro_rate_price) AS pro_rate_price
        FROM mytemr_master_uat.endorsement_service es
        INNER JOIN mytemr_master_uat.endorsement_service_package_detail espd
            ON espd.endorsement_service_id = es.id AND espd.is_deleted = 0
        WHERE es.is_deleted = 0
        GROUP BY es.endorsement_id, es.type_id, es.type, espd.lab_test_id
    ) espd
        ON espd.endorsement_id = bd.endorsement_id
       AND espd.type_id = bd.test_id
       AND espd.type = CASE WHEN bd.type = 'lab' THEN 'lab_test' ELSE bd.type END
       AND espd.lab_test_id = bpd.test_id
    LEFT JOIN (
        SELECT package_id, lab_test_id, MIN(pro_rate_price) AS pro_rate_price
        FROM mytemr_diagnostic_mobile_uat.package_detail
        WHERE is_deleted = 0
        GROUP BY package_id, lab_test_id
    ) pd
        ON pd.package_id = bd.test_id
       AND pd.lab_test_id = bpd.test_id
    LEFT JOIN (
        SELECT facility_id, lab_test_id, MIN(rate) AS rate
        FROM mytemr_diagnostic_mobile_uat.facility_lab_test
        WHERE is_deleted = 0
        GROUP BY facility_id, lab_test_id
    ) flt
        ON flt.facility_id = bpd.performed_at
       AND flt.lab_test_id = bpd.test_id
    WHERE bpd.is_deleted = 0
      AND bpd.status = 'send out'
      AND bpd.performed_at IS NOT NULL
      AND bpd.test_id IS NOT NULL
      AND COALESCE(bpd.updated_on, bpd.added_on) IS NOT NULL

    UNION ALL

    SELECT
        2 AS branch_id,
        DATE(COALESCE(bpd.updated_on, bpd.added_on)),
        b.transaction_id,
        'booking_package_details',
        bpd.id,
        bpd.performed_at,
        bpd.test_id,
        CASE
            WHEN bd.is_billed_externally = 1 AND bd.endorsement_id IS NOT NULL
            THEN COALESCE(espd.pro_rate_price, pd.pro_rate_price, bd.amount, 0)
            ELSE COALESCE(pd.pro_rate_price, bd.amount, 0)
        END,
        IFNULL(flt.rate, 0),
        COALESCE(bpd.updated_by, bpd.added_by, 0),
        COALESCE(bpd.updated_on, bpd.added_on),
        COALESCE(bpd.updated_by, bpd.added_by, 0),
        COALESCE(bpd.updated_on, bpd.added_on)
    FROM mytemr_diagnostic_uat.booking_package_details bpd
    INNER JOIN mytemr_diagnostic_uat.booking_details bd
        ON bd.id = bpd.booking_detail_id
       AND bd.is_deleted = 0
       AND bd.type = 'package'
    INNER JOIN mytemr_diagnostic_uat.booking b
        ON b.id = bd.booking_id AND b.is_deleted = 0
    INNER JOIN mytemr_diagnostic_uat.transaction t
        ON t.id = b.transaction_id AND t.is_deleted = 0
    LEFT JOIN (
        SELECT es.endorsement_id, es.type_id, es.type, espd.lab_test_id, MIN(espd.pro_rate_price) AS pro_rate_price
        FROM mytemr_master_uat.endorsement_service es
        INNER JOIN mytemr_master_uat.endorsement_service_package_detail espd
            ON espd.endorsement_service_id = es.id AND espd.is_deleted = 0
        WHERE es.is_deleted = 0
        GROUP BY es.endorsement_id, es.type_id, es.type, espd.lab_test_id
    ) espd
        ON espd.endorsement_id = bd.endorsement_id
       AND espd.type_id = bd.test_id
       AND espd.type = CASE WHEN bd.type = 'lab' THEN 'lab_test' ELSE bd.type END
       AND espd.lab_test_id = bpd.test_id
    LEFT JOIN (
        SELECT package_id, lab_test_id, MIN(pro_rate_price) AS pro_rate_price
        FROM mytemr_diagnostic_uat.package_detail
        WHERE is_deleted = 0
        GROUP BY package_id, lab_test_id
    ) pd
        ON pd.package_id = bd.test_id
       AND pd.lab_test_id = bpd.test_id
    LEFT JOIN (
        SELECT facility_id, lab_test_id, MIN(rate) AS rate
        FROM mytemr_diagnostic_uat.facility_lab_test
        WHERE is_deleted = 0
        GROUP BY facility_id, lab_test_id
    ) flt
        ON flt.facility_id = bpd.performed_at
       AND flt.lab_test_id = bpd.test_id
    WHERE bpd.is_deleted = 0
      AND bpd.status = 'send out'
      AND bpd.performed_at IS NOT NULL
      AND bpd.test_id IS NOT NULL
      AND COALESCE(bpd.updated_on, bpd.added_on) IS NOT NULL

    UNION ALL

    SELECT
        3 AS branch_id,
        DATE(COALESCE(bpd.updated_on, bpd.added_on)),
        b.transaction_id,
        'booking_package_details',
        bpd.id,
        bpd.performed_at,
        bpd.test_id,
        CASE
            WHEN bd.is_billed_externally = 1 AND bd.endorsement_id IS NOT NULL
            THEN COALESCE(espd.pro_rate_price, pd.pro_rate_price, bd.amount, 0)
            ELSE COALESCE(pd.pro_rate_price, bd.amount, 0)
        END,
        IFNULL(flt.rate, 0),
        COALESCE(bpd.updated_by, bpd.added_by, 0),
        COALESCE(bpd.updated_on, bpd.added_on),
        COALESCE(bpd.updated_by, bpd.added_by, 0),
        COALESCE(bpd.updated_on, bpd.added_on)
    FROM mytemr_diagnostic_mactan_uat.booking_package_details bpd
    INNER JOIN mytemr_diagnostic_mactan_uat.booking_details bd
        ON bd.id = bpd.booking_detail_id
       AND bd.is_deleted = 0
       AND bd.type = 'package'
    INNER JOIN mytemr_diagnostic_mactan_uat.booking b
        ON b.id = bd.booking_id AND b.is_deleted = 0
    INNER JOIN mytemr_diagnostic_mactan_uat.transaction t
        ON t.id = b.transaction_id AND t.is_deleted = 0
    LEFT JOIN (
        SELECT es.endorsement_id, es.type_id, es.type, espd.lab_test_id, MIN(espd.pro_rate_price) AS pro_rate_price
        FROM mytemr_master_uat.endorsement_service es
        INNER JOIN mytemr_master_uat.endorsement_service_package_detail espd
            ON espd.endorsement_service_id = es.id AND espd.is_deleted = 0
        WHERE es.is_deleted = 0
        GROUP BY es.endorsement_id, es.type_id, es.type, espd.lab_test_id
    ) espd
        ON espd.endorsement_id = bd.endorsement_id
       AND espd.type_id = bd.test_id
       AND espd.type = CASE WHEN bd.type = 'lab' THEN 'lab_test' ELSE bd.type END
       AND espd.lab_test_id = bpd.test_id
    LEFT JOIN (
        SELECT package_id, lab_test_id, MIN(pro_rate_price) AS pro_rate_price
        FROM mytemr_diagnostic_mactan_uat.package_detail
        WHERE is_deleted = 0
        GROUP BY package_id, lab_test_id
    ) pd
        ON pd.package_id = bd.test_id
       AND pd.lab_test_id = bpd.test_id
    LEFT JOIN (
        SELECT facility_id, lab_test_id, MIN(rate) AS rate
        FROM mytemr_diagnostic_mactan_uat.facility_lab_test
        WHERE is_deleted = 0
        GROUP BY facility_id, lab_test_id
    ) flt
        ON flt.facility_id = bpd.performed_at
       AND flt.lab_test_id = bpd.test_id
    WHERE bpd.is_deleted = 0
      AND bpd.status = 'send out'
      AND bpd.performed_at IS NOT NULL
      AND bpd.test_id IS NOT NULL
      AND COALESCE(bpd.updated_on, bpd.added_on) IS NOT NULL

    UNION ALL

    SELECT
        4 AS branch_id,
        DATE(COALESCE(bpd.updated_on, bpd.added_on)),
        b.transaction_id,
        'booking_package_details',
        bpd.id,
        bpd.performed_at,
        bpd.test_id,
        CASE
            WHEN bd.is_billed_externally = 1 AND bd.endorsement_id IS NOT NULL
            THEN COALESCE(espd.pro_rate_price, pd.pro_rate_price, bd.amount, 0)
            ELSE COALESCE(pd.pro_rate_price, bd.amount, 0)
        END,
        IFNULL(flt.rate, 0),
        COALESCE(bpd.updated_by, bpd.added_by, 0),
        COALESCE(bpd.updated_on, bpd.added_on),
        COALESCE(bpd.updated_by, bpd.added_by, 0),
        COALESCE(bpd.updated_on, bpd.added_on)
    FROM mytemr_diagnostic_conso_uat.booking_package_details bpd
    INNER JOIN mytemr_diagnostic_conso_uat.booking_details bd
        ON bd.id = bpd.booking_detail_id
       AND bd.is_deleted = 0
       AND bd.type = 'package'
    INNER JOIN mytemr_diagnostic_conso_uat.booking b
        ON b.id = bd.booking_id AND b.is_deleted = 0
    INNER JOIN mytemr_diagnostic_conso_uat.transaction t
        ON t.id = b.transaction_id AND t.is_deleted = 0
    LEFT JOIN (
        SELECT es.endorsement_id, es.type_id, es.type, espd.lab_test_id, MIN(espd.pro_rate_price) AS pro_rate_price
        FROM mytemr_master_uat.endorsement_service es
        INNER JOIN mytemr_master_uat.endorsement_service_package_detail espd
            ON espd.endorsement_service_id = es.id AND espd.is_deleted = 0
        WHERE es.is_deleted = 0
        GROUP BY es.endorsement_id, es.type_id, es.type, espd.lab_test_id
    ) espd
        ON espd.endorsement_id = bd.endorsement_id
       AND espd.type_id = bd.test_id
       AND espd.type = CASE WHEN bd.type = 'lab' THEN 'lab_test' ELSE bd.type END
       AND espd.lab_test_id = bpd.test_id
    LEFT JOIN (
        SELECT package_id, lab_test_id, MIN(pro_rate_price) AS pro_rate_price
        FROM mytemr_diagnostic_conso_uat.package_detail
        WHERE is_deleted = 0
        GROUP BY package_id, lab_test_id
    ) pd
        ON pd.package_id = bd.test_id
       AND pd.lab_test_id = bpd.test_id
    LEFT JOIN (
        SELECT facility_id, lab_test_id, MIN(rate) AS rate
        FROM mytemr_diagnostic_conso_uat.facility_lab_test
        WHERE is_deleted = 0
        GROUP BY facility_id, lab_test_id
    ) flt
        ON flt.facility_id = bpd.performed_at
       AND flt.lab_test_id = bpd.test_id
    WHERE bpd.is_deleted = 0
      AND bpd.status = 'send out'
      AND bpd.performed_at IS NOT NULL
      AND bpd.test_id IS NOT NULL
      AND COALESCE(bpd.updated_on, bpd.added_on) IS NOT NULL

    UNION ALL

    SELECT
        7 AS branch_id,
        DATE(COALESCE(bpd.updated_on, bpd.added_on)),
        b.transaction_id,
        'booking_package_details',
        bpd.id,
        bpd.performed_at,
        bpd.test_id,
        CASE
            WHEN bd.is_billed_externally = 1 AND bd.endorsement_id IS NOT NULL
            THEN COALESCE(espd.pro_rate_price, pd.pro_rate_price, bd.amount, 0)
            ELSE COALESCE(pd.pro_rate_price, bd.amount, 0)
        END,
        IFNULL(flt.rate, 0),
        COALESCE(bpd.updated_by, bpd.added_by, 0),
        COALESCE(bpd.updated_on, bpd.added_on),
        COALESCE(bpd.updated_by, bpd.added_by, 0),
        COALESCE(bpd.updated_on, bpd.added_on)
    FROM mytemr_diagnostic_mantlewood_uat.booking_package_details bpd
    INNER JOIN mytemr_diagnostic_mantlewood_uat.booking_details bd
        ON bd.id = bpd.booking_detail_id
       AND bd.is_deleted = 0
       AND bd.type = 'package'
    INNER JOIN mytemr_diagnostic_mantlewood_uat.booking b
        ON b.id = bd.booking_id AND b.is_deleted = 0
    INNER JOIN mytemr_diagnostic_mantlewood_uat.transaction t
        ON t.id = b.transaction_id AND t.is_deleted = 0
    LEFT JOIN (
        SELECT es.endorsement_id, es.type_id, es.type, espd.lab_test_id, MIN(espd.pro_rate_price) AS pro_rate_price
        FROM mytemr_master_uat.endorsement_service es
        INNER JOIN mytemr_master_uat.endorsement_service_package_detail espd
            ON espd.endorsement_service_id = es.id AND espd.is_deleted = 0
        WHERE es.is_deleted = 0
        GROUP BY es.endorsement_id, es.type_id, es.type, espd.lab_test_id
    ) espd
        ON espd.endorsement_id = bd.endorsement_id
       AND espd.type_id = bd.test_id
       AND espd.type = CASE WHEN bd.type = 'lab' THEN 'lab_test' ELSE bd.type END
       AND espd.lab_test_id = bpd.test_id
    LEFT JOIN (
        SELECT package_id, lab_test_id, MIN(pro_rate_price) AS pro_rate_price
        FROM mytemr_diagnostic_mantlewood_uat.package_detail
        WHERE is_deleted = 0
        GROUP BY package_id, lab_test_id
    ) pd
        ON pd.package_id = bd.test_id
       AND pd.lab_test_id = bpd.test_id
    LEFT JOIN (
        SELECT facility_id, lab_test_id, MIN(rate) AS rate
        FROM mytemr_diagnostic_mantlewood_uat.facility_lab_test
        WHERE is_deleted = 0
        GROUP BY facility_id, lab_test_id
    ) flt
        ON flt.facility_id = bpd.performed_at
       AND flt.lab_test_id = bpd.test_id
    WHERE bpd.is_deleted = 0
      AND bpd.status = 'send out'
      AND bpd.performed_at IS NOT NULL
      AND bpd.test_id IS NOT NULL
      AND COALESCE(bpd.updated_on, bpd.added_on) IS NOT NULL;
