------ DAILY BRANCH SALES REPORT (DIAGNOSTIC DATABASE) ------

SELECT
    1 AS branch_id,
    t.transaction_date AS sales_date,

    -- Transactions
    COUNT(DISTINCT p.transaction_id) AS total_transactions,

    -- Sales
    SUM(t.grand_total)              AS gross_sales,
    SUM(p.discount)                 AS total_discount,
    SUM(p.subtotal)                 AS net_sales,
    SUM(p.grand_total)              AS total_collected,
    SUM(p.change)                   AS total_change,

    -- VAT (12% VAT computation)
    0.00                      AS vat_sales,
    0.00       				  AS vat_amount,
    0.00                      AS vat_exempt,
    0.00                      AS vat_exemption,

    -- Payment Methods (from payment_multiple_method)
    SUM(CASE WHEN pmm.type = 'cash' THEN pmm.amount ELSE 0 END) AS cash_amount,
    SUM(CASE WHEN pmm.type = 'card' THEN pmm.amount ELSE 0 END) AS card_amount,
    SUM(CASE WHEN pmm.type = 'others' AND pmm.other_payment_details = 'gcash'
        THEN pmm.amount ELSE 0 END) AS gcash_amount,
    SUM(CASE WHEN pmm.type = 'others' AND pmm.other_payment_details = 'check'
        THEN pmm.amount ELSE 0 END) AS check_amount,
    SUM(CASE WHEN pmm.type = 'others' AND pmm.other_payment_details = 'maya'
        THEN pmm.amount ELSE 0 END) AS maya_amount,
    SUM(CASE WHEN pmm.type = 'others' AND pmm.other_payment_details = 'qrph'
        THEN pmm.amount ELSE 0 END) AS qrph_amount,
    SUM(CASE WHEN pmm.type = 'gc'
        THEN pmm.amount ELSE 0 END) AS gc_amount,
    SUM(CASE WHEN pmm.type = 'ar'
        THEN pmm.amount ELSE 0 END) AS ar_amount,
    SUM(CASE WHEN pmm.type = 'company_wallet'
        THEN pmm.amount ELSE 0 END) AS company_wallet_amount,
    SUM(CASE WHEN pmm.type = 'others' AND (
            pmm.other_payment_details IS NULL OR
            pmm.other_payment_details NOT IN ('gcash', 'maya', 'qrph', 'check')
        ) THEN pmm.amount ELSE 0 END) AS others_amount,

    NOW() AS added_on

FROM payment p
JOIN `transaction` t
    ON t.id = p.transaction_id
   AND t.is_deleted = 0
LEFT JOIN payment_multiple_method pmm
    ON pmm.payment_id = p.id
   AND pmm.is_deleted = 0
WHERE p.is_deleted = 0
AND p.grand_total > 0
AND t.transaction_date = CURDATE()
GROUP BY t.transaction_date
ORDER BY t.transaction_date;


------ PATIENT BRANCH TAT REPORT (EMR DATABASE) ------

-- Version 1: Patient naa nay previous record before today (for old)
SELECT
    1                    AS branch_id,
    CURDATE()            AS tat_date,

    -- APE/PEME OLD: patients nga naa nay previous physical_exam record before today
    COUNT(DISTINCT CASE
        WHEN pe.patient_id IN (
            SELECT patient_id
            FROM physical_exam
            WHERE DATE(added_on) < CURDATE()
              AND is_deleted = 0
        )
        THEN pe.patient_id
    END)                 AS ape_peme_old,

    -- APE/PEME NEW: patients nga first time mag-appear sa physical_exam today
    COUNT(DISTINCT CASE
        WHEN pe.patient_id NOT IN (
            SELECT patient_id
            FROM physical_exam
            WHERE DATE(added_on) < CURDATE()
              AND is_deleted = 0
        )
        THEN pe.patient_id
    END)                 AS ape_peme_new,

    -- YAKAP OLD: returning patients sa queue (ekonsulta) before today
    COUNT(DISTINCT CASE
        WHEN q_ekonsulta.patient_id IN (
            SELECT patient_id
            FROM queue
            WHERE assessment_type = 'ekonsulta'
              AND DATE(added_on) < CURDATE()
              AND is_deleted = 0
        )
        THEN q_ekonsulta.patient_id
    END)                 AS yakap_old,

    -- YAKAP NEW: first time sa queue (ekonsulta) today
    COUNT(DISTINCT CASE
        WHEN q_ekonsulta.patient_id NOT IN (
            SELECT patient_id
            FROM queue
            WHERE assessment_type = 'ekonsulta'
              AND DATE(added_on) < CURDATE()
              AND is_deleted = 0
        )
        THEN q_ekonsulta.patient_id
    END)                 AS yakap_new,

    -- REGULAR OLD: returning patients sa queue (non-ekonsulta) before today
    COUNT(DISTINCT CASE
        WHEN q_regular.patient_id IN (
            SELECT patient_id
            FROM queue
            WHERE assessment_type <> 'ekonsulta'
              AND DATE(added_on) < CURDATE()
              AND is_deleted = 0
        )
        THEN q_regular.patient_id
    END)                 AS regular_old,

    -- REGULAR NEW: first time sa queue (non-ekonsulta) today
    COUNT(DISTINCT CASE
        WHEN q_regular.patient_id NOT IN (
            SELECT patient_id
            FROM queue
            WHERE assessment_type <> 'ekonsulta'
              AND DATE(added_on) < CURDATE()
              AND is_deleted = 0
        )
        THEN q_regular.patient_id
    END)                 AS regular_new

FROM
    -- Today's physical_exam records
    (SELECT DISTINCT patient_id
     FROM physical_exam
     WHERE DATE(added_on) = CURDATE()
       AND is_deleted = 0
    ) pe

    -- Today's ekonsulta queue records
    CROSS JOIN (
        SELECT DISTINCT patient_id
        FROM queue
        WHERE assessment_type = 'ekonsulta'
          AND DATE(added_on) = CURDATE()
          AND is_deleted = 0
    ) q_ekonsulta

    -- Today's non-ekonsulta queue records
    CROSS JOIN (
        SELECT DISTINCT patient_id
        FROM queue
        WHERE assessment_type <> 'ekonsulta'
          AND DATE(added_on) = CURDATE()
          AND is_deleted = 0
    ) q_regular;


-- Version 2: Patient naa'y multiple records today (for old)
SELECT
    1                    AS branch_id,
    CURDATE()            AS tat_date,

    -- APE/PEME OLD: patients nga naa'y 2+ physical_exam records TODAY
    COUNT(DISTINCT CASE
        WHEN pe.patient_id IN (
            SELECT patient_id
            FROM physical_exam
            WHERE DATE(added_on) = CURDATE()
              AND is_deleted = 0
            GROUP BY patient_id
            HAVING COUNT(*) > 1
        )
        THEN pe.patient_id
    END)                 AS ape_peme_old,

    -- APE/PEME NEW: patients nga 1 record lang TODAY
    COUNT(DISTINCT CASE
        WHEN pe.patient_id IN (
            SELECT patient_id
            FROM physical_exam
            WHERE DATE(added_on) = CURDATE()
              AND is_deleted = 0
            GROUP BY patient_id
            HAVING COUNT(*) = 1
        )
        THEN pe.patient_id
    END)                 AS ape_peme_new,

    -- YAKAP OLD: patients nga naa'y 2+ ekonsulta queue records TODAY
    COUNT(DISTINCT CASE
        WHEN q_ekonsulta.patient_id IN (
            SELECT patient_id
            FROM queue
            WHERE assessment_type = 'ekonsulta'
              AND DATE(added_on) = CURDATE()
              AND is_deleted = 0
            GROUP BY patient_id
            HAVING COUNT(*) > 1
        )
        THEN q_ekonsulta.patient_id
    END)                 AS yakap_old,

    -- YAKAP NEW: patients nga 1 ekonsulta record lang TODAY
    COUNT(DISTINCT CASE
        WHEN q_ekonsulta.patient_id IN (
            SELECT patient_id
            FROM queue
            WHERE assessment_type = 'ekonsulta'
              AND DATE(added_on) = CURDATE()
              AND is_deleted = 0
            GROUP BY patient_id
            HAVING COUNT(*) = 1
        )
        THEN q_ekonsulta.patient_id
    END)                 AS yakap_new,

    -- REGULAR OLD: patients nga naa'y 2+ non-ekonsulta queue records TODAY
    COUNT(DISTINCT CASE
        WHEN q_regular.patient_id IN (
            SELECT patient_id
            FROM queue
            WHERE assessment_type <> 'ekonsulta'
              AND DATE(added_on) = CURDATE()
              AND is_deleted = 0
            GROUP BY patient_id
            HAVING COUNT(*) > 1
        )
        THEN q_regular.patient_id
    END)                 AS regular_old,

    -- REGULAR NEW: patients nga 1 non-ekonsulta record lang TODAY
    COUNT(DISTINCT CASE
        WHEN q_regular.patient_id IN (
            SELECT patient_id
            FROM queue
            WHERE assessment_type <> 'ekonsulta'
              AND DATE(added_on) = CURDATE()
              AND is_deleted = 0
            GROUP BY patient_id
            HAVING COUNT(*) = 1
        )
        THEN q_regular.patient_id
    END)                 AS regular_new

FROM
    (SELECT DISTINCT patient_id
     FROM physical_exam
     WHERE DATE(added_on) = CURDATE()
       AND is_deleted = 0
    ) pe

    CROSS JOIN (
        SELECT DISTINCT patient_id
        FROM queue
        WHERE assessment_type = 'ekonsulta'
          AND DATE(added_on) = CURDATE()
          AND is_deleted = 0
    ) q_ekonsulta

    CROSS JOIN (
        SELECT DISTINCT patient_id
        FROM queue
        WHERE assessment_type <> 'ekonsulta'
          AND DATE(added_on) = CURDATE()
          AND is_deleted = 0
    ) q_regular;

------ PCP CLINIC BRANCH TAT REPORT (EMR DATABASE) ------

-- Version 1: Patient naa nay previous record before today (for old)
SELECT
    1             AS branch_id,
    CURDATE()     AS tat_date,
    d.doctor_id,

    -- APE/PEME OLD
    COUNT(DISTINCT CASE
        WHEN pe.patient_id IN (
            SELECT patient_id FROM physical_exam
            WHERE DATE(added_on) < CURDATE() AND is_deleted = 0
        ) THEN pe.patient_id
    END)          AS ape_peme_old,

    -- APE/PEME NEW
    COUNT(DISTINCT CASE
        WHEN pe.patient_id NOT IN (
            SELECT patient_id FROM physical_exam
            WHERE DATE(added_on) < CURDATE() AND is_deleted = 0
        ) THEN pe.patient_id
    END)          AS ape_peme_new,

    -- YAKAP OLD
    COUNT(DISTINCT CASE
        WHEN qe.patient_id IN (
            SELECT patient_id FROM queue
            WHERE assessment_type = 'ekonsulta'
              AND DATE(added_on) < CURDATE() AND is_deleted = 0
        ) THEN qe.patient_id
    END)          AS yakap_old,

    -- YAKAP NEW
    COUNT(DISTINCT CASE
        WHEN qe.patient_id NOT IN (
            SELECT patient_id FROM queue
            WHERE assessment_type = 'ekonsulta'
              AND DATE(added_on) < CURDATE() AND is_deleted = 0
        ) THEN qe.patient_id
    END)          AS yakap_new,

    -- REGULAR OLD
    COUNT(DISTINCT CASE
        WHEN qr.patient_id IN (
            SELECT patient_id FROM queue
            WHERE assessment_type <> 'ekonsulta'
              AND DATE(added_on) < CURDATE() AND is_deleted = 0
        ) THEN qr.patient_id
    END)          AS regular_old,

    -- REGULAR NEW
    COUNT(DISTINCT CASE
        WHEN qr.patient_id NOT IN (
            SELECT patient_id FROM queue
            WHERE assessment_type <> 'ekonsulta'
              AND DATE(added_on) < CURDATE() AND is_deleted = 0
        ) THEN qr.patient_id
    END)          AS regular_new

FROM (
    -- Get all distinct doctor_ids active today from all 3 sources
    SELECT doctor_id FROM physical_exam
    WHERE DATE(added_on) = CURDATE() AND is_deleted = 0 AND doctor_id IS NOT NULL
    UNION
    SELECT doctor_id FROM queue
    WHERE DATE(added_on) = CURDATE() AND is_deleted = 0 AND doctor_id IS NOT NULL
) d
LEFT JOIN (
    SELECT DISTINCT patient_id, doctor_id FROM physical_exam
    WHERE DATE(added_on) = CURDATE() AND is_deleted = 0
) pe ON d.doctor_id = pe.doctor_id
LEFT JOIN (
    SELECT DISTINCT patient_id, doctor_id FROM queue
    WHERE assessment_type = 'ekonsulta'
      AND DATE(added_on) = CURDATE() AND is_deleted = 0
) qe ON d.doctor_id = qe.doctor_id
LEFT JOIN (
    SELECT DISTINCT patient_id, doctor_id FROM queue
    WHERE assessment_type <> 'ekonsulta'
      AND DATE(added_on) = CURDATE() AND is_deleted = 0
) qr ON d.doctor_id = qr.doctor_id

GROUP BY d.doctor_id
ORDER BY d.doctor_id;

-- Version 2: Patient naa'y multiple records today (for old)
SELECT
    1             AS branch_id,
    CURDATE()     AS tat_date,
    d.doctor_id,

    -- APE/PEME OLD
    COUNT(DISTINCT CASE
        WHEN pe.patient_id IN (
            SELECT patient_id FROM physical_exam
            WHERE DATE(added_on) = CURDATE() AND is_deleted = 0
            GROUP BY patient_id HAVING COUNT(*) > 1
        ) THEN pe.patient_id
    END)          AS ape_peme_old,

    -- APE/PEME NEW
    COUNT(DISTINCT CASE
        WHEN pe.patient_id IN (
            SELECT patient_id FROM physical_exam
            WHERE DATE(added_on) = CURDATE() AND is_deleted = 0
            GROUP BY patient_id HAVING COUNT(*) = 1
        ) THEN pe.patient_id
    END)          AS ape_peme_new,

    -- YAKAP OLD
    COUNT(DISTINCT CASE
        WHEN qe.patient_id IN (
            SELECT patient_id FROM queue
            WHERE assessment_type = 'ekonsulta'
              AND DATE(added_on) = CURDATE() AND is_deleted = 0
            GROUP BY patient_id HAVING COUNT(*) > 1
        ) THEN qe.patient_id
    END)          AS yakap_old,

    -- YAKAP NEW
    COUNT(DISTINCT CASE
        WHEN qe.patient_id IN (
            SELECT patient_id FROM queue
            WHERE assessment_type = 'ekonsulta'
              AND DATE(added_on) = CURDATE() AND is_deleted = 0
            GROUP BY patient_id HAVING COUNT(*) = 1
        ) THEN qe.patient_id
    END)          AS yakap_new,

    -- REGULAR OLD
    COUNT(DISTINCT CASE
        WHEN qr.patient_id IN (
            SELECT patient_id FROM queue
            WHERE assessment_type <> 'ekonsulta'
              AND DATE(added_on) = CURDATE() AND is_deleted = 0
            GROUP BY patient_id HAVING COUNT(*) > 1
        ) THEN qr.patient_id
    END)          AS regular_old,

    -- REGULAR NEW
    COUNT(DISTINCT CASE
        WHEN qr.patient_id IN (
            SELECT patient_id FROM queue
            WHERE assessment_type = 'ekonsulta'
              AND DATE(added_on) = CURDATE() AND is_deleted = 0
            GROUP BY patient_id HAVING COUNT(*) = 1
        ) THEN qr.patient_id
    END)          AS regular_new

FROM (
    SELECT doctor_id FROM physical_exam
    WHERE DATE(added_on) = CURDATE() AND is_deleted = 0 AND doctor_id IS NOT NULL
    UNION
    SELECT doctor_id FROM queue
    WHERE DATE(added_on) = CURDATE() AND is_deleted = 0 AND doctor_id IS NOT NULL
) d
LEFT JOIN (
    SELECT DISTINCT patient_id, doctor_id FROM physical_exam
    WHERE DATE(added_on) = CURDATE() AND is_deleted = 0
) pe ON d.doctor_id = pe.doctor_id
LEFT JOIN (
    SELECT DISTINCT patient_id, doctor_id FROM queue
    WHERE assessment_type = 'ekonsulta'
      AND DATE(added_on) = CURDATE() AND is_deleted = 0
) qe ON d.doctor_id = qe.doctor_id
LEFT JOIN (
    SELECT DISTINCT patient_id, doctor_id FROM queue
    WHERE assessment_type <> 'ekonsulta'
      AND DATE(added_on) = CURDATE() AND is_deleted = 0
) qr ON d.doctor_id = qr.doctor_id

GROUP BY d.doctor_id
ORDER BY d.doctor_id;

------ SPECIALIST MD BRANCH TAT REPORT (EMR DATABASE) ------

-- Version 1: Patient naa nay previous record before today (for old)
SELECT
    1             AS branch_id,
    CURDATE()     AS tat_date,
    d.doctor_id,

    -- WALK-IN OLD: naa nay walk-in record before today (per doctor)
    COUNT(DISTINCT CASE
        WHEN qw.patient_id IN (
            SELECT patient_id FROM queue
            WHERE transaction_type = 'walk-in'
              AND DATE(added_on) < CURDATE()
              AND is_deleted = 0
        ) THEN qw.patient_id
    END)          AS walk_in_old,

    -- WALK-IN NEW: first time walk-in today (per doctor)
    COUNT(DISTINCT CASE
        WHEN qw.patient_id NOT IN (
            SELECT patient_id FROM queue
            WHERE transaction_type = 'walk-in'
              AND DATE(added_on) < CURDATE()
              AND is_deleted = 0
        ) THEN qw.patient_id
    END)          AS walk_in_new,

    -- REFERRED OLD: naa nay referred record before today (per doctor)
    COUNT(DISTINCT CASE
        WHEN qr.patient_id IN (
            SELECT patient_id FROM queue
            WHERE transaction_type = 'referred'
              AND DATE(added_on) < CURDATE()
              AND is_deleted = 0
        ) THEN qr.patient_id
    END)          AS referred_old,

    -- REFERRED NEW: first time referred today (per doctor)
    COUNT(DISTINCT CASE
        WHEN qr.patient_id NOT IN (
            SELECT patient_id FROM queue
            WHERE transaction_type = 'referred'
              AND DATE(added_on) < CURDATE()
              AND is_deleted = 0
        ) THEN qr.patient_id
    END)          AS referred_new

FROM (
    -- All distinct doctor_ids active today from queue (walk-in or referred)
    SELECT doctor_id FROM queue
    WHERE transaction_type IN ('walk-in', 'referred')
      AND DATE(added_on) = CURDATE()
      AND is_deleted = 0
      AND doctor_id IS NOT NULL
) d
LEFT JOIN (
    SELECT DISTINCT patient_id, doctor_id FROM queue
    WHERE transaction_type = 'walk-in'
      AND DATE(added_on) = CURDATE()
      AND is_deleted = 0
      AND doctor_id IS NOT NULL
) qw ON d.doctor_id = qw.doctor_id
LEFT JOIN (
    SELECT DISTINCT patient_id, doctor_id FROM queue
    WHERE transaction_type = 'referred'
      AND DATE(added_on) = CURDATE()
      AND is_deleted = 0
      AND doctor_id IS NOT NULL
) qr ON d.doctor_id = qr.doctor_id

GROUP BY d.doctor_id
ORDER BY d.doctor_id;


-- Version 2: Patient naa'y multiple records today (for old)
SELECT
    1             AS branch_id,
    CURDATE()     AS tat_date,
    d.doctor_id,

    -- WALK-IN OLD: 2+ walk-in records today (per doctor)
    COUNT(DISTINCT CASE
        WHEN qw.patient_id IN (
            SELECT patient_id FROM queue
            WHERE transaction_type = 'walk-in'
              AND DATE(added_on) = CURDATE()
              AND is_deleted = 0
            GROUP BY patient_id HAVING COUNT(*) > 1
        ) THEN qw.patient_id
    END)          AS walk_in_old,

    -- WALK-IN NEW: 1 walk-in record lang today (per doctor)
    COUNT(DISTINCT CASE
        WHEN qw.patient_id IN (
            SELECT patient_id FROM queue
            WHERE transaction_type = 'walk-in'
              AND DATE(added_on) = CURDATE()
              AND is_deleted = 0
            GROUP BY patient_id HAVING COUNT(*) = 1
        ) THEN qw.patient_id
    END)          AS walk_in_new,

    -- REFERRED OLD: 2+ referred records today (per doctor)
    COUNT(DISTINCT CASE
        WHEN qr.patient_id IN (
            SELECT patient_id FROM queue
            WHERE transaction_type = 'referred'
              AND DATE(added_on) = CURDATE()
              AND is_deleted = 0
            GROUP BY patient_id HAVING COUNT(*) > 1
        ) THEN qr.patient_id
    END)          AS referred_old,

    -- REFERRED NEW: 1 referred record lang today (per doctor)
    COUNT(DISTINCT CASE
        WHEN qr.patient_id IN (
            SELECT patient_id FROM queue
            WHERE transaction_type = 'referred'
              AND DATE(added_on) = CURDATE()
              AND is_deleted = 0
            GROUP BY patient_id HAVING COUNT(*) = 1
        ) THEN qr.patient_id
    END)          AS referred_new

FROM (
    SELECT doctor_id FROM queue
    WHERE transaction_type IN ('walk-in', 'referred')
      AND DATE(added_on) = CURDATE()
      AND is_deleted = 0
      AND doctor_id IS NOT NULL
) d
LEFT JOIN (
    SELECT DISTINCT patient_id, doctor_id FROM queue
    WHERE transaction_type = 'walk-in'
      AND DATE(added_on) = CURDATE()
      AND is_deleted = 0
      AND doctor_id IS NOT NULL
) qw ON d.doctor_id = qw.doctor_id
LEFT JOIN (
    SELECT DISTINCT patient_id, doctor_id FROM queue
    WHERE transaction_type = 'referred'
      AND DATE(added_on) = CURDATE()
      AND is_deleted = 0
      AND doctor_id IS NOT NULL
) qr ON d.doctor_id = qr.doctor_id

GROUP BY d.doctor_id
ORDER BY d.doctor_id;

------ MEDRECORD REPORT (REPORT DATABASE) ------
-- Grain: one row per lab_test_id under a booking (branch diagnostic DB)
-- Bookings with no bucket tests emit one sentinel row (lab_test_id NULL)
-- Labels: 0=no, 1=yes, 2=done, 3=n_a
-- ETL uses DELETE for target transaction_date (is_force_evaluate = 0 only) + INSERT ... ON DUPLICATE KEY UPDATE
-- Unique key: (branch_id, booking_id, lab_test_id, transaction_date)
--   endorsement_id is NOT part of the unique key (must be updatable when inherited from parent branch)
-- Audit: is_force_evaluate / force_evaluate_by / force_evaluate_on
-- Forced rows keep for_evaluation / for_release / for_release_log on upsert
-- Force evaluate is scoped to the single medrecord row (medrecord.id) and also stamps
--   that row's own source line in the branch diagnostic DB (resolved via medrecord.branch_id):
--   booking_package_details (when booking_package_details_id set) else booking_details
--   -> status='done', result_status='finalized', force_evaluate_by, force_evaluate_on
-- Inter-branch: exclude transferred-out lines (booking_details.new_transaction_id IS NOT NULL)
-- Recollection = same-branch parent only (parent_branch_id NULL or = this branch);
--   inbound inter-branch children (parent_branch_id from another branch) are NOT recollection
-- Effective endorsement: COALESCE(NULLIF(local,0), local bd.endorsement_id, scalar parent txn lookups, local)
--   scalar correlated subqueries only (no derived-table wrap — MySQL cannot correlate into FROM (...))
--   so dest-branch rows inherit source endorsement (e.g. Oakridge from Mobile) for for_evaluation
-- Source line ids: booking_details_id = MAX(bd.id) of the booking+test group (newest line, e.g. recollection child);
--   booking_package_details_id = MAX(bpd.id) only when the owning bd.type = 'package', else NULL
-- Implemented in app/Models/Report.php::_build_medrecord_insert_sql()
-- Refresh: reports/get_medrecord queues spark medrecord:refresh for the requested branch
--   and today/yesterday only (30s debounce + flock). Do not cron this every minute.
--   Safety net: hourly (`0 * * * *`) or nightly (`0 23 * * *`) cron/get_medrecord
--   (optional transaction_date + branch_id). Historical days are not rebuilt on list.
--
-- ALTER unique key (run once on report DB, e.g. mytemr_reports / mytemr_reports_uat):
--   ALTER TABLE medrecord DROP INDEX `branch_id`;
--   ALTER TABLE medrecord
--     ADD UNIQUE KEY `uk_medrecord_booking_test`
--       (branch_id, booking_id, lab_test_id, transaction_date);

SELECT
    1                                           AS branch_id,
    COALESCE(bd.charge_to, b.company_id, 0)      AS company_id,
    t.id                                        AS transaction_id,
    b.id                                        AS booking_id,
    MAX(bd.id)                                  AS booking_details_id,
    MAX(CASE WHEN bd.type = 'package' THEN bpd.id END)
                                                AS booking_package_details_id,
    COALESCE(
        NULLIF(t.endorsement_id, 0),
        (
            SELECT cbd.endorsement_id
            FROM mytemr_diagnostic_uat.booking_details cbd
            WHERE cbd.booking_id = b.id
              AND cbd.is_deleted = 0
              AND cbd.endorsement_id IS NOT NULL
              AND cbd.endorsement_id != 0
            LIMIT 1
        ),
        (
            SELECT pt.endorsement_id
            FROM mytemr_diagnostic_uat.booking_details cbd
            INNER JOIN mytemr_diagnostic_mobile_uat.booking_details pbd
                ON pbd.id = cbd.parent_id AND pbd.is_deleted = 0
            INNER JOIN mytemr_diagnostic_mobile_uat.booking pb
                ON pb.id = pbd.booking_id AND pb.is_deleted = 0
            INNER JOIN mytemr_diagnostic_mobile_uat.`transaction` pt
                ON pt.id = pb.transaction_id AND pt.is_deleted = 0
            WHERE cbd.booking_id = b.id
              AND cbd.is_deleted = 0
              AND cbd.parent_id IS NOT NULL
              AND cbd.parent_branch_id = 1
              AND pt.endorsement_id IS NOT NULL
              AND pt.endorsement_id != 0
            LIMIT 1
        ),
        t.endorsement_id
    )                                           AS endorsement_id,
    t.transaction_date                          AS transaction_date,
    t.added_on                                  AS transaction_date_time,
    (
        SELECT NULLIF(
            GREATEST(
                IFNULL(MAX(bd_po.performed_on), '1970-01-01'),
                IFNULL(MAX(bpd_po.performed_on), '1970-01-01')
            ),
            '1970-01-01'
        )
        FROM mytemr_diagnostic_uat.booking_details bd_po
        LEFT JOIN mytemr_diagnostic_uat.booking_package_details bpd_po
            ON bpd_po.booking_detail_id = bd_po.id
           AND bpd_po.is_deleted = 0
        WHERE bd_po.booking_id = b.id
          AND bd_po.is_deleted = 0
    )                                           AS performed_on,
    p.master_id                                 AS patient_id,
    CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
                                                AS lab_test_id,
    MAX(CASE
        WHEN (
            (bd.type != 'package'
                AND bd.new_transaction_id IS NULL
                AND EXISTS (
                SELECT 1 FROM mytemr_emr_uat.lab_result_evaluation lre
                WHERE lre.source_id = bd.id
                  AND lre.source_table = 'booking_details'
                  AND lre.branch_id = 1
                  AND lre.is_deleted = 0
            ))
            OR (bd.type = 'package'
                AND bd.new_transaction_id IS NULL
                AND EXISTS (
                SELECT 1 FROM mytemr_emr_uat.lab_result_evaluation lre
                WHERE lre.source_id = bpd.id
                  AND lre.source_table = 'booking_package_details'
                  AND lre.branch_id = 1
                  AND lre.is_deleted = 0
            ))
        ) THEN CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
    END)                                        AS significant_findings,
    MAX(CASE
        WHEN (
            (bd.type != 'package'
                AND bd.new_transaction_id IS NULL
                AND bd.status NOT IN ('waived', 'pending')
                AND (bd.result_status IS NULL OR bd.result_status != 'finalized')
                AND bd.is_deleted = 0)
            OR (bd.type = 'package'
                AND bd.new_transaction_id IS NULL
                AND bpd.status NOT IN ('waived', 'pending')
                AND (bpd.result_status IS NULL OR bpd.result_status != 'finalized')
                AND bpd.is_deleted = 0)
        ) THEN CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
    END)                                        AS pending_result,
    MAX(CASE
        WHEN (
            (bd.type != 'package'
                AND bd.new_transaction_id IS NULL
                AND bd.status IN ('pending', 'to follow')
                AND bd.is_deleted = 0)
            OR (bd.type = 'package'
                AND bd.new_transaction_id IS NULL
                AND bpd.status IN ('pending', 'to follow')
                AND bpd.is_deleted = 0)
        ) THEN CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
    END)                                        AS pending_specimen,
    MAX(CASE
        WHEN (
            (bd.type != 'package'
                AND bd.parent_id IS NOT NULL
                AND (bd.parent_branch_id IS NULL OR bd.parent_branch_id = 1)
                AND bd.new_transaction_id IS NULL
                AND bd.is_deleted = 0)
            OR (bd.type = 'package'
                AND bpd.parent_id IS NOT NULL
                AND (bpd.parent_branch_id IS NULL OR bpd.parent_branch_id = 1)
                AND bd.new_transaction_id IS NULL
                AND bpd.is_deleted = 0)
        ) THEN CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
    END)                                        AS recollection,
    CASE
        WHEN COALESCE(
                NULLIF(t.endorsement_id, 0),
                (
            SELECT cbd.endorsement_id
            FROM mytemr_diagnostic_uat.booking_details cbd
            WHERE cbd.booking_id = b.id
              AND cbd.is_deleted = 0
              AND cbd.endorsement_id IS NOT NULL
              AND cbd.endorsement_id != 0
            LIMIT 1
        ),
        (
            SELECT pt.endorsement_id
            FROM mytemr_diagnostic_uat.booking_details cbd
            INNER JOIN mytemr_diagnostic_mobile_uat.booking_details pbd
                ON pbd.id = cbd.parent_id AND pbd.is_deleted = 0
            INNER JOIN mytemr_diagnostic_mobile_uat.booking pb
                ON pb.id = pbd.booking_id AND pb.is_deleted = 0
            INNER JOIN mytemr_diagnostic_mobile_uat.`transaction` pt
                ON pt.id = pb.transaction_id AND pt.is_deleted = 0
            WHERE cbd.booking_id = b.id
              AND cbd.is_deleted = 0
              AND cbd.parent_id IS NOT NULL
              AND cbd.parent_branch_id = 1
              AND pt.endorsement_id IS NOT NULL
              AND pt.endorsement_id != 0
            LIMIT 1
        ),
                t.endorsement_id
            ) IS NULL
            OR COALESCE(
                NULLIF(t.endorsement_id, 0),
                (
            SELECT cbd.endorsement_id
            FROM mytemr_diagnostic_uat.booking_details cbd
            WHERE cbd.booking_id = b.id
              AND cbd.is_deleted = 0
              AND cbd.endorsement_id IS NOT NULL
              AND cbd.endorsement_id != 0
            LIMIT 1
        ),
        (
            SELECT pt.endorsement_id
            FROM mytemr_diagnostic_uat.booking_details cbd
            INNER JOIN mytemr_diagnostic_mobile_uat.booking_details pbd
                ON pbd.id = cbd.parent_id AND pbd.is_deleted = 0
            INNER JOIN mytemr_diagnostic_mobile_uat.booking pb
                ON pb.id = pbd.booking_id AND pb.is_deleted = 0
            INNER JOIN mytemr_diagnostic_mobile_uat.`transaction` pt
                ON pt.id = pb.transaction_id AND pt.is_deleted = 0
            WHERE cbd.booking_id = b.id
              AND cbd.is_deleted = 0
              AND cbd.parent_id IS NOT NULL
              AND cbd.parent_branch_id = 1
              AND pt.endorsement_id IS NOT NULL
              AND pt.endorsement_id != 0
            LIMIT 1
        ),
                t.endorsement_id
            ) = 0 THEN 3
        WHEN NOT EXISTS (
                SELECT 1 FROM mytemr_diagnostic_uat.booking_details bd2
                WHERE bd2.booking_id = b.id
                  AND bd2.type != 'package'
                  AND bd2.is_deleted = 0
                  AND bd2.new_transaction_id IS NULL
                  AND NOT EXISTS (
                      SELECT 1 FROM mytemr_emr_uat.lab_result_evaluation lre
                      WHERE lre.source_id = bd2.id
                        AND lre.source_table = 'booking_details'
                        AND lre.branch_id = 1
                        AND lre.is_deleted = 0
                  )
            )
            AND NOT EXISTS (
                SELECT 1 FROM mytemr_diagnostic_uat.booking_package_details bpd2
                JOIN mytemr_diagnostic_uat.booking_details bd2
                    ON bd2.id = bpd2.booking_detail_id
                WHERE bd2.booking_id = b.id
                  AND bpd2.is_deleted = 0
                  AND bd2.new_transaction_id IS NULL
                  AND NOT EXISTS (
                      SELECT 1 FROM mytemr_emr_uat.lab_result_evaluation lre
                      WHERE lre.source_id = bpd2.id
                        AND lre.source_table = 'booking_package_details'
                        AND lre.branch_id = 1
                        AND lre.is_deleted = 0
                  )
            ) THEN 2
        WHEN NOT EXISTS (
                SELECT 1 FROM mytemr_diagnostic_uat.booking_details bd2
                WHERE bd2.booking_id = b.id
                  AND bd2.type != 'package'
                  AND bd2.is_deleted = 0
                  AND bd2.new_transaction_id IS NULL
                  AND (
                    bd2.status IN ('pending', 'to follow')
                    OR NOT (bd2.status = 'waived' OR bd2.result_status = 'finalized')
                  )
            )
            AND NOT EXISTS (
                SELECT 1 FROM mytemr_diagnostic_uat.booking_package_details bpd2
                JOIN mytemr_diagnostic_uat.booking_details bd2
                    ON bd2.id = bpd2.booking_detail_id
                WHERE bd2.booking_id = b.id
                  AND bpd2.is_deleted = 0
                  AND bd2.new_transaction_id IS NULL
                  AND (
                    bpd2.status IN ('pending', 'to follow')
                    OR NOT (bpd2.status = 'waived' OR bpd2.result_status = 'finalized')
                  )
            ) THEN 1
        ELSE 0
    END                                         AS for_evaluation,
    CASE
        WHEN (
                COALESCE(
                    NULLIF(t.endorsement_id, 0),
                    (
            SELECT cbd.endorsement_id
            FROM mytemr_diagnostic_uat.booking_details cbd
            WHERE cbd.booking_id = b.id
              AND cbd.is_deleted = 0
              AND cbd.endorsement_id IS NOT NULL
              AND cbd.endorsement_id != 0
            LIMIT 1
        ),
        (
            SELECT pt.endorsement_id
            FROM mytemr_diagnostic_uat.booking_details cbd
            INNER JOIN mytemr_diagnostic_mobile_uat.booking_details pbd
                ON pbd.id = cbd.parent_id AND pbd.is_deleted = 0
            INNER JOIN mytemr_diagnostic_mobile_uat.booking pb
                ON pb.id = pbd.booking_id AND pb.is_deleted = 0
            INNER JOIN mytemr_diagnostic_mobile_uat.`transaction` pt
                ON pt.id = pb.transaction_id AND pt.is_deleted = 0
            WHERE cbd.booking_id = b.id
              AND cbd.is_deleted = 0
              AND cbd.parent_id IS NOT NULL
              AND cbd.parent_branch_id = 1
              AND pt.endorsement_id IS NOT NULL
              AND pt.endorsement_id != 0
            LIMIT 1
        ),
                    t.endorsement_id
                ) IS NULL
                OR COALESCE(
                    NULLIF(t.endorsement_id, 0),
                    (
            SELECT cbd.endorsement_id
            FROM mytemr_diagnostic_uat.booking_details cbd
            WHERE cbd.booking_id = b.id
              AND cbd.is_deleted = 0
              AND cbd.endorsement_id IS NOT NULL
              AND cbd.endorsement_id != 0
            LIMIT 1
        ),
        (
            SELECT pt.endorsement_id
            FROM mytemr_diagnostic_uat.booking_details cbd
            INNER JOIN mytemr_diagnostic_mobile_uat.booking_details pbd
                ON pbd.id = cbd.parent_id AND pbd.is_deleted = 0
            INNER JOIN mytemr_diagnostic_mobile_uat.booking pb
                ON pb.id = pbd.booking_id AND pb.is_deleted = 0
            INNER JOIN mytemr_diagnostic_mobile_uat.`transaction` pt
                ON pt.id = pb.transaction_id AND pt.is_deleted = 0
            WHERE cbd.booking_id = b.id
              AND cbd.is_deleted = 0
              AND cbd.parent_id IS NOT NULL
              AND cbd.parent_branch_id = 1
              AND pt.endorsement_id IS NOT NULL
              AND pt.endorsement_id != 0
            LIMIT 1
        ),
                    t.endorsement_id
                ) = 0
            )
            AND NOT EXISTS (
                SELECT 1 FROM mytemr_diagnostic_uat.booking_details bd2
                WHERE bd2.booking_id = b.id
                  AND bd2.type != 'package'
                  AND bd2.is_deleted = 0
                  AND bd2.new_transaction_id IS NULL
                  AND (
                    bd2.status IN ('pending', 'to follow')
                    OR NOT (bd2.status = 'waived' OR bd2.result_status = 'finalized')
                  )
            )
            AND NOT EXISTS (
                SELECT 1 FROM mytemr_diagnostic_uat.booking_package_details bpd2
                JOIN mytemr_diagnostic_uat.booking_details bd2
                    ON bd2.id = bpd2.booking_detail_id
                WHERE bd2.booking_id = b.id
                  AND bpd2.is_deleted = 0
                  AND bd2.new_transaction_id IS NULL
                  AND (
                    bpd2.status IN ('pending', 'to follow')
                    OR NOT (bpd2.status = 'waived' OR bpd2.result_status = 'finalized')
                  )
            ) THEN 1
        WHEN COALESCE(
                NULLIF(t.endorsement_id, 0),
                (
            SELECT cbd.endorsement_id
            FROM mytemr_diagnostic_uat.booking_details cbd
            WHERE cbd.booking_id = b.id
              AND cbd.is_deleted = 0
              AND cbd.endorsement_id IS NOT NULL
              AND cbd.endorsement_id != 0
            LIMIT 1
        ),
        (
            SELECT pt.endorsement_id
            FROM mytemr_diagnostic_uat.booking_details cbd
            INNER JOIN mytemr_diagnostic_mobile_uat.booking_details pbd
                ON pbd.id = cbd.parent_id AND pbd.is_deleted = 0
            INNER JOIN mytemr_diagnostic_mobile_uat.booking pb
                ON pb.id = pbd.booking_id AND pb.is_deleted = 0
            INNER JOIN mytemr_diagnostic_mobile_uat.`transaction` pt
                ON pt.id = pb.transaction_id AND pt.is_deleted = 0
            WHERE cbd.booking_id = b.id
              AND cbd.is_deleted = 0
              AND cbd.parent_id IS NOT NULL
              AND cbd.parent_branch_id = 1
              AND pt.endorsement_id IS NOT NULL
              AND pt.endorsement_id != 0
            LIMIT 1
        ),
                t.endorsement_id
            ) IS NOT NULL
            AND COALESCE(
                NULLIF(t.endorsement_id, 0),
                (
            SELECT cbd.endorsement_id
            FROM mytemr_diagnostic_uat.booking_details cbd
            WHERE cbd.booking_id = b.id
              AND cbd.is_deleted = 0
              AND cbd.endorsement_id IS NOT NULL
              AND cbd.endorsement_id != 0
            LIMIT 1
        ),
        (
            SELECT pt.endorsement_id
            FROM mytemr_diagnostic_uat.booking_details cbd
            INNER JOIN mytemr_diagnostic_mobile_uat.booking_details pbd
                ON pbd.id = cbd.parent_id AND pbd.is_deleted = 0
            INNER JOIN mytemr_diagnostic_mobile_uat.booking pb
                ON pb.id = pbd.booking_id AND pb.is_deleted = 0
            INNER JOIN mytemr_diagnostic_mobile_uat.`transaction` pt
                ON pt.id = pb.transaction_id AND pt.is_deleted = 0
            WHERE cbd.booking_id = b.id
              AND cbd.is_deleted = 0
              AND cbd.parent_id IS NOT NULL
              AND cbd.parent_branch_id = 1
              AND pt.endorsement_id IS NOT NULL
              AND pt.endorsement_id != 0
            LIMIT 1
        ),
                t.endorsement_id
            ) != 0
            AND NOT EXISTS (
                SELECT 1 FROM mytemr_diagnostic_uat.booking_details bd2
                WHERE bd2.booking_id = b.id
                  AND bd2.type != 'package'
                  AND bd2.is_deleted = 0
                  AND bd2.new_transaction_id IS NULL
                  AND NOT EXISTS (
                      SELECT 1 FROM mytemr_emr_uat.lab_result_evaluation lre
                      WHERE lre.source_id = bd2.id
                        AND lre.source_table = 'booking_details'
                        AND lre.branch_id = 1
                        AND lre.is_deleted = 0
                  )
            )
            AND NOT EXISTS (
                SELECT 1 FROM mytemr_diagnostic_uat.booking_package_details bpd2
                JOIN mytemr_diagnostic_uat.booking_details bd2
                    ON bd2.id = bpd2.booking_detail_id
                WHERE bd2.booking_id = b.id
                  AND bpd2.is_deleted = 0
                  AND bd2.new_transaction_id IS NULL
                  AND NOT EXISTS (
                      SELECT 1 FROM mytemr_emr_uat.lab_result_evaluation lre
                      WHERE lre.source_id = bpd2.id
                        AND lre.source_table = 'booking_package_details'
                        AND lre.branch_id = 1
                        AND lre.is_deleted = 0
                  )
            ) THEN 2
        ELSE 0
    END                                         AS for_release,
    NOW()                                       AS added_on

FROM mytemr_diagnostic_uat.transaction t
INNER JOIN mytemr_diagnostic_uat.booking b
    ON b.transaction_id = t.id
   AND b.is_deleted = 0
INNER JOIN mytemr_diagnostic_uat.patient p
    ON p.id = b.patient_id
   AND p.master_id IS NOT NULL
INNER JOIN mytemr_diagnostic_uat.booking_details bd
    ON bd.booking_id = b.id
   AND bd.is_deleted = 0
LEFT JOIN mytemr_diagnostic_uat.booking_package_details bpd
    ON bpd.booking_detail_id = bd.id
   AND bpd.is_deleted = 0
WHERE t.is_deleted = 0
  AND DATE(t.transaction_date) = CURDATE()
  -- Cron/model pass an explicit Y-m-d instead of CURDATE() when rebuilding a past day
  AND CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END IS NOT NULL

GROUP BY
    b.id,
    t.id,
    p.master_id,
    COALESCE(
        NULLIF(t.endorsement_id, 0),
        (
            SELECT cbd.endorsement_id
            FROM mytemr_diagnostic_uat.booking_details cbd
            WHERE cbd.booking_id = b.id
              AND cbd.is_deleted = 0
              AND cbd.endorsement_id IS NOT NULL
              AND cbd.endorsement_id != 0
            LIMIT 1
        ),
        (
            SELECT pt.endorsement_id
            FROM mytemr_diagnostic_uat.booking_details cbd
            INNER JOIN mytemr_diagnostic_mobile_uat.booking_details pbd
                ON pbd.id = cbd.parent_id AND pbd.is_deleted = 0
            INNER JOIN mytemr_diagnostic_mobile_uat.booking pb
                ON pb.id = pbd.booking_id AND pb.is_deleted = 0
            INNER JOIN mytemr_diagnostic_mobile_uat.`transaction` pt
                ON pt.id = pb.transaction_id AND pt.is_deleted = 0
            WHERE cbd.booking_id = b.id
              AND cbd.is_deleted = 0
              AND cbd.parent_id IS NOT NULL
              AND cbd.parent_branch_id = 1
              AND pt.endorsement_id IS NOT NULL
              AND pt.endorsement_id != 0
            LIMIT 1
        ),
        t.endorsement_id
    ),
    COALESCE(bd.charge_to, b.company_id, 0),
    t.transaction_date,
    t.added_on,
    CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END

HAVING
    significant_findings IS NOT NULL
    OR pending_result IS NOT NULL
    OR pending_specimen IS NOT NULL
    OR recollection IS NOT NULL

ORDER BY b.id, lab_test_id;