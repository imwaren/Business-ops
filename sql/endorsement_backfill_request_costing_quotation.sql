-- Backfill request / costing / quotation for endorsements encoded without those IDs.
-- Live INSERT ... SELECT on mytemr_master. Do NOT import the phpMyAdmin dump
-- (that file CREATE TABLEs request/costing/quotation/endorsement and would wipe data).
--
-- Scope:
--   endorsement.is_deleted = 0
--   AND (IFNULL(request_id, 0) = 0 OR IFNULL(costing_id, 0) = 0 OR IFNULL(quotation_id, 0) = 0)
-- Endorsements 16-19 (deleted request 67, live costing/quotation) are left alone.
-- Endorsement-only children (patient, employer, attachment, detail, revision) stay as-is.
--
-- Idempotent: request.reference_no = BF-END-{endorsement_id}
--             quotation.quotation_no = QT-BF-{endorsement_id}
-- Safe to re-run. On error the procedure rolls back.
--
-- Backup first, then:
--   mysql -u USER -p mytemr_master < sql/endorsement_backfill_request_costing_quotation.sql

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Preview (no writes)
-- ---------------------------------------------------------------------------
SELECT
    e.id AS endorsement_id,
    e.company_id,
    e.status,
    e.request_id,
    e.costing_id,
    e.quotation_id,
    (SELECT COUNT(*) FROM endorsement_service es WHERE es.endorsement_id = e.id AND es.is_deleted = 0) AS service_count,
    (SELECT COUNT(*) FROM endorsement_service_package_detail espd WHERE espd.endorsement_id = e.id AND espd.is_deleted = 0) AS package_detail_count
FROM endorsement e
WHERE e.is_deleted = 0
    AND (
        IFNULL(e.request_id, 0) = 0
        OR IFNULL(e.costing_id, 0) = 0
        OR IFNULL(e.quotation_id, 0) = 0
    )
ORDER BY e.id;

SELECT COUNT(*) AS endorsements_to_backfill
FROM endorsement e
WHERE e.is_deleted = 0
    AND (
        IFNULL(e.request_id, 0) = 0
        OR IFNULL(e.costing_id, 0) = 0
        OR IFNULL(e.quotation_id, 0) = 0
    );

DELIMITER $$

DROP PROCEDURE IF EXISTS endorsement_bf_request_costing_quotation$$

CREATE PROCEDURE endorsement_bf_request_costing_quotation()
BEGIN
    DECLARE v_now DATETIME;
    DECLARE v_missing_after INT DEFAULT 0;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    SET v_now = NOW();

    START TRANSACTION;

    DROP TEMPORARY TABLE IF EXISTS tmp_endorsement_bf;
    CREATE TEMPORARY TABLE tmp_endorsement_bf (
        endorsement_id INT NOT NULL,
        request_id INT DEFAULT NULL,
        costing_id INT DEFAULT NULL,
        quotation_id INT DEFAULT NULL,
        need_request TINYINT NOT NULL DEFAULT 0,
        need_costing TINYINT NOT NULL DEFAULT 0,
        need_quotation TINYINT NOT NULL DEFAULT 0,
        added_by INT NOT NULL,
        added_on DATETIME NOT NULL,
        PRIMARY KEY (endorsement_id)
    ) ENGINE=Memory;

    INSERT INTO tmp_endorsement_bf (
        endorsement_id, request_id, costing_id, quotation_id,
        need_request, need_costing, need_quotation, added_by, added_on
    )
    SELECT
        e.id,
        NULLIF(e.request_id, 0),
        NULLIF(e.costing_id, 0),
        NULLIF(e.quotation_id, 0),
        IF(IFNULL(e.request_id, 0) = 0, 1, 0),
        IF(IFNULL(e.costing_id, 0) = 0, 1, 0),
        IF(IFNULL(e.quotation_id, 0) = 0, 1, 0),
        e.added_by,
        IFNULL(e.added_on, v_now)
    FROM endorsement e
    WHERE e.is_deleted = 0
        AND (
            IFNULL(e.request_id, 0) = 0
            OR IFNULL(e.costing_id, 0) = 0
            OR IFNULL(e.quotation_id, 0) = 0
        );

    -- Reuse a prior backfill request
    UPDATE tmp_endorsement_bf t
    INNER JOIN request r
        ON r.reference_no = CONCAT('BF-END-', t.endorsement_id)
        AND r.is_deleted = 0
    SET t.request_id = r.id
    WHERE t.need_request = 1
        AND t.request_id IS NULL;

    INSERT INTO request (
        version_no, company_id, reference_no, email, delivery_address, payment_terms,
        date, preferred_branch_id, estimated_headcount, with_masterlist, with_walk_in,
        service_duration_date_from, service_duration_date_to, transaction_type,
        remarks, guaranteed_headcount, mobile_site, email_result, evaluation_data_source,
        estimated_patient_per_shift, mode_of_releasing,
        status, approved_by, approved_on, approved_reason,
        added_by, added_on, is_deleted
    )
    SELECT
        IFNULL(e.version_no, 1),
        e.company_id,
        CONCAT('BF-END-', e.id),
        NULLIF(e.email, ''),
        NULLIF(e.address, ''),
        NULLIF(e.payment_terms, ''),
        DATE(COALESCE(e.endorsement_date, e.added_on, v_now)),
        NULLIF(e.preferred_branch_id, ''),
        NULLIF(e.head_count, ''),
        IFNULL(e.with_masterlist, 0),
        IFNULL(e.with_walk_in, 0),
        e.service_duration_date_from,
        e.service_duration_date_to,
        NULLIF(e.transaction_type, ''),
        e.remarks,
        NULLIF(e.guaranteed_headcount, ''),
        NULLIF(e.mobile_site, ''),
        NULLIF(e.email_result, ''),
        NULLIF(e.evaluation_data_source, ''),
        NULLIF(e.estimated_patient_per_shift, ''),
        NULLIF(e.mode_of_releasing, ''),
        'approved',
        t.added_by,
        v_now,
        'Backfilled from endorsement',
        t.added_by,
        t.added_on,
        0
    FROM tmp_endorsement_bf t
    INNER JOIN endorsement e ON e.id = t.endorsement_id
    WHERE t.need_request = 1
        AND t.request_id IS NULL;

    UPDATE tmp_endorsement_bf t
    INNER JOIN request r
        ON r.reference_no = CONCAT('BF-END-', t.endorsement_id)
        AND r.is_deleted = 0
    SET t.request_id = r.id
    WHERE t.need_request = 1
        AND t.request_id IS NULL;

    -- Reuse costing already hanging off a BF-END request
    UPDATE tmp_endorsement_bf t
    INNER JOIN request r
        ON r.id = t.request_id
        AND r.reference_no = CONCAT('BF-END-', t.endorsement_id)
        AND r.is_deleted = 0
    INNER JOIN (
        SELECT request_id, MAX(id) AS costing_id
        FROM costing
        WHERE is_deleted = 0
        GROUP BY request_id
    ) c ON c.request_id = r.id
    SET t.costing_id = c.costing_id
    WHERE t.need_costing = 1
        AND t.costing_id IS NULL;

    INSERT INTO costing (
        version_no, request_id, company_id, date, preferred_branch_id,
        estimated_headcount, payment_terms, with_masterlist, with_walk_in,
        service_duration_date_from, service_duration_date_to, transaction_type,
        package_name, guaranteed_headcount, remarks, mobile_site, email_result,
        evaluation_data_source, estimated_patient_per_shift, mode_of_releasing,
        cost_total, estimated_margin_total, estimated_margin_amount_total,
        status, approved_by, approved_on,
        approved_by_sales_manager, approved_on_sales_manager,
        approved_by_sales_director, approved_on_sales_director,
        approved_reason, added_by, added_on, is_deleted
    )
    SELECT
        IFNULL(e.version_no, 1),
        t.request_id,
        e.company_id,
        DATE(COALESCE(e.endorsement_date, e.added_on, v_now)),
        NULLIF(e.preferred_branch_id, ''),
        NULLIF(e.head_count, ''),
        NULLIF(e.payment_terms, ''),
        IFNULL(e.with_masterlist, 0),
        IFNULL(e.with_walk_in, 0),
        e.service_duration_date_from,
        e.service_duration_date_to,
        NULLIF(e.transaction_type, ''),
        NULLIF(e.package_name, ''),
        NULLIF(e.guaranteed_headcount, ''),
        e.remarks,
        NULLIF(e.mobile_site, ''),
        NULLIF(e.email_result, ''),
        NULLIF(e.evaluation_data_source, ''),
        NULLIF(e.estimated_patient_per_shift, ''),
        NULLIF(e.mode_of_releasing, ''),
        IFNULL(e.cost_total, 0),
        IFNULL(e.estimated_margin_total, 0),
        IFNULL(e.estimated_margin_amount_total, 0),
        'approved',
        t.added_by,
        v_now,
        t.added_by,
        v_now,
        t.added_by,
        v_now,
        'Backfilled from endorsement',
        t.added_by,
        t.added_on,
        0
    FROM tmp_endorsement_bf t
    INNER JOIN endorsement e ON e.id = t.endorsement_id
    WHERE t.need_costing = 1
        AND t.costing_id IS NULL
        AND t.request_id IS NOT NULL;

    UPDATE tmp_endorsement_bf t
    INNER JOIN (
        SELECT request_id, MAX(id) AS costing_id
        FROM costing
        WHERE is_deleted = 0
        GROUP BY request_id
    ) c ON c.request_id = t.request_id
    SET t.costing_id = c.costing_id
    WHERE t.need_costing = 1
        AND t.costing_id IS NULL;

    -- Reuse a prior backfill quotation
    UPDATE tmp_endorsement_bf t
    INNER JOIN quotation q
        ON q.quotation_no = CONCAT('QT-BF-', t.endorsement_id)
        AND q.is_deleted = 0
    SET t.quotation_id = q.id
    WHERE t.need_quotation = 1
        AND t.quotation_id IS NULL;

    INSERT INTO quotation (
        version_no, company_id, request_id, costing_id, quotation_no,
        effectivity_date_from, effectivity_date_to, remarks, mobile_site,
        email_result, evaluation_data_source, payment_terms, contact_email,
        delivery_address, with_masterlist, with_walk_in, head_count_total,
        cost_total, estimated_margin_total, estimated_margin_amount_total,
        estimated_patient_per_shift, package_name, guaranteed_headcount,
        mode_of_releasing, note,
        status, approved_by, approved_on,
        approved_by_sales, approved_on_sales,
        approved_by_sales_manager, approved_on_sales_manager,
        approved_by_sales_director, approved_on_sales_director,
        approved_by_finance_manager, approved_on_finance_manager,
        approved_reason, added_by, added_on, is_deleted
    )
    SELECT
        IFNULL(e.version_no, 1),
        e.company_id,
        t.request_id,
        t.costing_id,
        CONCAT('QT-BF-', e.id),
        e.effectivity_date_from,
        e.effectivity_date_to,
        e.remarks,
        NULLIF(e.mobile_site, ''),
        NULLIF(e.email_result, ''),
        NULLIF(e.evaluation_data_source, ''),
        NULLIF(e.payment_terms, ''),
        NULLIF(e.email, ''),
        NULLIF(e.address, ''),
        IFNULL(e.with_masterlist, 0),
        IFNULL(e.with_walk_in, 0),
        IF(e.head_count IS NULL OR e.head_count = '', 0, e.head_count),
        IFNULL(e.cost_total, 0),
        IFNULL(e.estimated_margin_total, 0),
        IFNULL(e.estimated_margin_amount_total, 0),
        NULLIF(e.estimated_patient_per_shift, ''),
        NULLIF(e.package_name, ''),
        NULLIF(e.guaranteed_headcount, ''),
        NULLIF(e.mode_of_releasing, ''),
        e.note,
        'approved',
        t.added_by,
        v_now,
        t.added_by,
        v_now,
        t.added_by,
        v_now,
        t.added_by,
        v_now,
        t.added_by,
        v_now,
        'Backfilled from endorsement',
        t.added_by,
        t.added_on,
        0
    FROM tmp_endorsement_bf t
    INNER JOIN endorsement e ON e.id = t.endorsement_id
    WHERE t.need_quotation = 1
        AND t.quotation_id IS NULL
        AND t.request_id IS NOT NULL
        AND t.costing_id IS NOT NULL;

    UPDATE tmp_endorsement_bf t
    INNER JOIN quotation q
        ON q.quotation_no = CONCAT('QT-BF-', t.endorsement_id)
        AND q.is_deleted = 0
    SET t.quotation_id = q.id
    WHERE t.need_quotation = 1
        AND t.quotation_id IS NULL;

    -- -----------------------------------------------------------------------
    -- Children: request
    -- -----------------------------------------------------------------------
    INSERT INTO request_service (
        request_id, type, type_id, type_price, type_cost, branch_id, charge_to,
        charge_to_ids, package_ids, is_add_on, head_count, discount_amount, margin_amount,
        pre_negotiated_rate, is_guaranteed, guaranteed_headcount,
        added_by, added_on, is_deleted
    )
    SELECT
        t.request_id,
        es.type,
        IFNULL(es.type_id, 0),
        IFNULL(es.type_price, 0),
        IFNULL(es.cost, 0),
        es.branch_id,
        es.charge_to,
        es.charge_to_ids,
        es.package_ids,
        IF(IFNULL(es.package_ids, 0) = 0, 0, 1),
        es.head_count,
        es.discount_amount,
        es.margin_amount,
        IFNULL(es.pre_negotiated_rate, 0),
        IFNULL(es.is_guaranteed, 0),
        es.guaranteed_headcount,
        t.added_by,
        t.added_on,
        0
    FROM tmp_endorsement_bf t
    INNER JOIN endorsement_service es
        ON es.endorsement_id = t.endorsement_id
        AND es.is_deleted = 0
    WHERE t.request_id IS NOT NULL
        AND NOT EXISTS (
            SELECT 1
            FROM request_service rs
            WHERE rs.request_id = t.request_id
                AND rs.is_deleted = 0
        );

    INSERT INTO request_shift (
        request_id, name, start_shift, end_shift, registration_cut_off,
        specimen_submission_cut_off, total_hours, `break`, ingress,
        added_by, added_on, is_deleted
    )
    SELECT
        t.request_id,
        sh.name,
        sh.start_shift,
        sh.end_shift,
        sh.registration_cut_off,
        sh.specimen_submission_cut_off,
        IFNULL(sh.total_hours, 0),
        sh.`break`,
        sh.ingress,
        t.added_by,
        t.added_on,
        0
    FROM tmp_endorsement_bf t
    INNER JOIN endorsement_shift sh
        ON sh.endorsement_id = t.endorsement_id
        AND sh.is_deleted = 0
    WHERE t.request_id IS NOT NULL
        AND NOT EXISTS (
            SELECT 1
            FROM request_shift rs
            WHERE rs.request_id = t.request_id
                AND rs.is_deleted = 0
        );

    INSERT INTO request_consultation (
        request_id, company_id, specialization_id, fee_amount, doctor_share,
        ahmopi_fee, pahmoc_fee, charge_to, charge_to_ids,
        added_by, added_on, is_deleted
    )
    SELECT
        t.request_id,
        c.company_id,
        c.specialization_id,
        IFNULL(c.fee_amount, 0),
        IFNULL(c.doctor_share, 0),
        IFNULL(c.ahmopi_fee, 0),
        IFNULL(c.pahmoc_fee, 0),
        IFNULL(c.charge_to, 'company'),
        IFNULL(c.charge_to_ids, c.company_id),
        t.added_by,
        t.added_on,
        0
    FROM tmp_endorsement_bf t
    INNER JOIN endorsement_consultation c
        ON c.endorsement_id = t.endorsement_id
        AND c.is_deleted = 0
    WHERE t.request_id IS NOT NULL
        AND NOT EXISTS (
            SELECT 1
            FROM request_consultation rc
            WHERE rc.request_id = t.request_id
                AND rc.is_deleted = 0
        );

    -- -----------------------------------------------------------------------
    -- Children: costing
    -- -----------------------------------------------------------------------
    INSERT INTO costing_service (
        costing_id, type, type_id, type_price, margin, cost, pro_rate_price,
        discount, total_amount, branch_id, charge_to, charge_to_ids, package_ids, is_add_on, head_count,
        discount_amount, margin_amount, sales, admin_fee, direct_cost,
        manpower_cost, mobilization_cost, total_cost, estimated_margin,
        direct_cost_amount_total, manpower_cost_amount_total,
        mobilization_cost_amount_total, total_cost_amount_total,
        estimated_margin_amount_total, estimated_revenue, pre_negotiated_rate,
        is_guaranteed, guaranteed_headcount, theoretical_cost,
        added_by, added_on, is_deleted
    )
    SELECT
        t.costing_id,
        es.type,
        IFNULL(es.type_id, 0),
        IFNULL(es.type_price, 0),
        IFNULL(es.margin, 0),
        IFNULL(es.cost, 0),
        IFNULL(es.pro_rate_price, 0),
        IFNULL(es.discount, 0),
        IFNULL(es.total_amount, 0),
        es.branch_id,
        es.charge_to,
        es.charge_to_ids,
        es.package_ids,
        IF(IFNULL(es.package_ids, 0) = 0, 0, 1),
        es.head_count,
        es.discount_amount,
        es.margin_amount,
        es.sales,
        es.admin_fee,
        es.direct_cost,
        es.manpower_cost,
        es.mobilization_cost,
        es.total_cost,
        es.estimated_margin,
        es.direct_cost_amount_total,
        es.manpower_cost_amount_total,
        es.mobilization_cost_amount_total,
        es.total_cost_amount_total,
        es.estimated_margin_amount_total,
        es.estimated_revenue,
        IFNULL(es.pre_negotiated_rate, 0),
        IFNULL(es.is_guaranteed, 0),
        es.guaranteed_headcount,
        IFNULL(es.theoretical_cost, 0),
        t.added_by,
        t.added_on,
        0
    FROM tmp_endorsement_bf t
    INNER JOIN endorsement_service es
        ON es.endorsement_id = t.endorsement_id
        AND es.is_deleted = 0
    WHERE t.costing_id IS NOT NULL
        AND NOT EXISTS (
            SELECT 1
            FROM costing_service cs
            WHERE cs.costing_id = t.costing_id
                AND cs.is_deleted = 0
        );

    INSERT INTO costing_shift (
        costing_id, name, start_shift, end_shift, registration_cut_off,
        specimen_submission_cut_off, total_hours, `break`, ingress,
        added_by, added_on, is_deleted
    )
    SELECT
        t.costing_id,
        sh.name,
        sh.start_shift,
        sh.end_shift,
        sh.registration_cut_off,
        sh.specimen_submission_cut_off,
        IFNULL(sh.total_hours, 0),
        sh.`break`,
        sh.ingress,
        t.added_by,
        t.added_on,
        0
    FROM tmp_endorsement_bf t
    INNER JOIN endorsement_shift sh
        ON sh.endorsement_id = t.endorsement_id
        AND sh.is_deleted = 0
    WHERE t.costing_id IS NOT NULL
        AND NOT EXISTS (
            SELECT 1
            FROM costing_shift cs
            WHERE cs.costing_id = t.costing_id
                AND cs.is_deleted = 0
        );

    INSERT INTO costing_consultation (
        costing_id, company_id, specialization_id, fee_amount, doctor_share,
        ahmopi_fee, pahmoc_fee, charge_to, charge_to_ids,
        added_by, added_on, is_deleted
    )
    SELECT
        t.costing_id,
        c.company_id,
        c.specialization_id,
        IFNULL(c.fee_amount, 0),
        IFNULL(c.doctor_share, 0),
        IFNULL(c.ahmopi_fee, 0),
        IFNULL(c.pahmoc_fee, 0),
        IFNULL(c.charge_to, 'company'),
        IFNULL(c.charge_to_ids, c.company_id),
        t.added_by,
        t.added_on,
        0
    FROM tmp_endorsement_bf t
    INNER JOIN endorsement_consultation c
        ON c.endorsement_id = t.endorsement_id
        AND c.is_deleted = 0
    WHERE t.costing_id IS NOT NULL
        AND NOT EXISTS (
            SELECT 1
            FROM costing_consultation cc
            WHERE cc.costing_id = t.costing_id
                AND cc.is_deleted = 0
        );

    INSERT INTO costing_discount (
        costing_id, type, type_id, type_price, discount, discount_amount,
        total_amount, branch_id, added_by, added_on, is_deleted
    )
    SELECT
        t.costing_id,
        d.type,
        d.type_id,
        IFNULL(d.type_price, 0),
        IFNULL(d.discount, 0),
        IFNULL(d.discount_amount, 0),
        IFNULL(d.total_amount, 0),
        d.branch_id,
        t.added_by,
        t.added_on,
        0
    FROM tmp_endorsement_bf t
    INNER JOIN endorsement_discount d
        ON d.endorsement_id = t.endorsement_id
        AND d.is_deleted = 0
    WHERE t.costing_id IS NOT NULL
        AND NOT EXISTS (
            SELECT 1
            FROM costing_discount cd
            WHERE cd.costing_id = t.costing_id
                AND cd.is_deleted = 0
        );

    INSERT INTO costing_consulta (
        costing_id, type, type_id, type_price, mark_up, mark_up_amount,
        total_amount, branch_id, added_by, added_on, is_deleted
    )
    SELECT
        t.costing_id,
        c.type,
        c.type_id,
        IFNULL(c.type_price, 0),
        IFNULL(c.mark_up, 0),
        IFNULL(c.mark_up_amount, 0),
        IFNULL(c.total_amount, 0),
        c.branch_id,
        t.added_by,
        t.added_on,
        0
    FROM tmp_endorsement_bf t
    INNER JOIN endorsement_consulta c
        ON c.endorsement_id = t.endorsement_id
        AND c.is_deleted = 0
    WHERE t.costing_id IS NOT NULL
        AND NOT EXISTS (
            SELECT 1
            FROM costing_consulta cc
            WHERE cc.costing_id = t.costing_id
                AND cc.is_deleted = 0
        );

    -- -----------------------------------------------------------------------
    -- Children: quotation
    -- -----------------------------------------------------------------------
    INSERT INTO quotation_service (
        quotation_id, type, type_id, type_price, margin, cost, pro_rate_price,
        discount, total_amount, branch_id, charge_to, charge_to_ids, package_ids, is_add_on, head_count,
        discount_amount, margin_amount, sales, admin_fee, direct_cost,
        manpower_cost, mobilization_cost, total_cost, estimated_margin,
        direct_cost_amount_total, manpower_cost_amount_total,
        mobilization_cost_amount_total, total_cost_amount_total,
        estimated_margin_amount_total, estimated_revenue, pre_negotiated_rate,
        is_guaranteed, guaranteed_headcount, theoretical_cost,
        added_by, added_on, is_deleted
    )
    SELECT
        t.quotation_id,
        es.type,
        IFNULL(es.type_id, 0),
        IFNULL(es.type_price, 0),
        IFNULL(es.margin, 0),
        IFNULL(es.cost, 0),
        IFNULL(es.pro_rate_price, 0),
        IFNULL(es.discount, 0),
        IFNULL(es.total_amount, 0),
        es.branch_id,
        es.charge_to,
        es.charge_to_ids,
        es.package_ids,
        IF(IFNULL(es.package_ids, 0) = 0, 0, 1),
        es.head_count,
        es.discount_amount,
        es.margin_amount,
        es.sales,
        es.admin_fee,
        es.direct_cost,
        es.manpower_cost,
        es.mobilization_cost,
        es.total_cost,
        es.estimated_margin,
        es.direct_cost_amount_total,
        es.manpower_cost_amount_total,
        es.mobilization_cost_amount_total,
        es.total_cost_amount_total,
        es.estimated_margin_amount_total,
        es.estimated_revenue,
        IFNULL(es.pre_negotiated_rate, 0),
        IFNULL(es.is_guaranteed, 0),
        es.guaranteed_headcount,
        IFNULL(es.theoretical_cost, 0),
        t.added_by,
        t.added_on,
        0
    FROM tmp_endorsement_bf t
    INNER JOIN endorsement_service es
        ON es.endorsement_id = t.endorsement_id
        AND es.is_deleted = 0
    WHERE t.quotation_id IS NOT NULL
        AND NOT EXISTS (
            SELECT 1
            FROM quotation_service qs
            WHERE qs.quotation_id = t.quotation_id
                AND qs.is_deleted = 0
        );

    INSERT INTO quotation_shift (
        quotation_id, name, start_shift, end_shift, registration_cut_off,
        specimen_submission_cut_off, total_hours, `break`, ingress,
        added_by, added_on, is_deleted
    )
    SELECT
        t.quotation_id,
        sh.name,
        sh.start_shift,
        sh.end_shift,
        sh.registration_cut_off,
        sh.specimen_submission_cut_off,
        IFNULL(sh.total_hours, 0),
        sh.`break`,
        sh.ingress,
        t.added_by,
        t.added_on,
        0
    FROM tmp_endorsement_bf t
    INNER JOIN endorsement_shift sh
        ON sh.endorsement_id = t.endorsement_id
        AND sh.is_deleted = 0
    WHERE t.quotation_id IS NOT NULL
        AND NOT EXISTS (
            SELECT 1
            FROM quotation_shift qs
            WHERE qs.quotation_id = t.quotation_id
                AND qs.is_deleted = 0
        );

    INSERT INTO quotation_consultation (
        quotation_id, company_id, specialization_id, fee_amount, doctor_share,
        ahmopi_fee, pahmoc_fee, charge_to, charge_to_ids,
        added_by, added_on, is_deleted
    )
    SELECT
        t.quotation_id,
        c.company_id,
        c.specialization_id,
        IFNULL(c.fee_amount, 0),
        IFNULL(c.doctor_share, 0),
        IFNULL(c.ahmopi_fee, 0),
        IFNULL(c.pahmoc_fee, 0),
        IFNULL(c.charge_to, 'company'),
        IFNULL(c.charge_to_ids, c.company_id),
        t.added_by,
        t.added_on,
        0
    FROM tmp_endorsement_bf t
    INNER JOIN endorsement_consultation c
        ON c.endorsement_id = t.endorsement_id
        AND c.is_deleted = 0
    WHERE t.quotation_id IS NOT NULL
        AND NOT EXISTS (
            SELECT 1
            FROM quotation_consultation qc
            WHERE qc.quotation_id = t.quotation_id
                AND qc.is_deleted = 0
        );

    -- -----------------------------------------------------------------------
    -- Service-id map for package details (ROW_NUMBER when keys collide)
    -- -----------------------------------------------------------------------
    DROP TEMPORARY TABLE IF EXISTS tmp_es_rank;
    CREATE TEMPORARY TABLE tmp_es_rank (
        endorsement_service_id INT NOT NULL,
        endorsement_id INT NOT NULL,
        costing_id INT DEFAULT NULL,
        quotation_id INT DEFAULT NULL,
        type_key VARCHAR(100) NOT NULL,
        type_id_key INT NOT NULL,
        type_price_key DECIMAL(13,4) NOT NULL,
        branch_key VARCHAR(20) NOT NULL,
        head_count_key INT NOT NULL,
        rn INT NOT NULL,
        PRIMARY KEY (endorsement_service_id),
        KEY idx_es_rank_cost (costing_id, type_key, type_id_key, type_price_key, branch_key, head_count_key, rn),
        KEY idx_es_rank_quot (quotation_id, type_key, type_id_key, type_price_key, branch_key, head_count_key, rn)
    );

    INSERT INTO tmp_es_rank (
        endorsement_service_id, endorsement_id, costing_id, quotation_id,
        type_key, type_id_key, type_price_key, branch_key, head_count_key, rn
    )
    SELECT
        es.id,
        t.endorsement_id,
        t.costing_id,
        t.quotation_id,
        es.type,
        IFNULL(es.type_id, 0),
        IFNULL(es.type_price, 0),
        IFNULL(es.branch_id, ''),
        IFNULL(es.head_count, 0),
        ROW_NUMBER() OVER (
            PARTITION BY t.endorsement_id, es.type, IFNULL(es.type_id, 0), IFNULL(es.type_price, 0), IFNULL(es.branch_id, ''), IFNULL(es.head_count, 0)
            ORDER BY es.id
        )
    FROM tmp_endorsement_bf t
    INNER JOIN endorsement_service es
        ON es.endorsement_id = t.endorsement_id
        AND es.is_deleted = 0;

    DROP TEMPORARY TABLE IF EXISTS tmp_cs_rank;
    CREATE TEMPORARY TABLE tmp_cs_rank (
        costing_service_id INT NOT NULL,
        costing_id INT NOT NULL,
        type_key VARCHAR(100) NOT NULL,
        type_id_key INT NOT NULL,
        type_price_key DECIMAL(13,4) NOT NULL,
        branch_key VARCHAR(20) NOT NULL,
        head_count_key INT NOT NULL,
        rn INT NOT NULL,
        PRIMARY KEY (costing_service_id),
        KEY idx_cs_rank (costing_id, type_key, type_id_key, type_price_key, branch_key, head_count_key, rn)
    );

    INSERT INTO tmp_cs_rank (
        costing_service_id, costing_id, type_key, type_id_key, type_price_key, branch_key, head_count_key, rn
    )
    SELECT
        cs.id,
        cs.costing_id,
        cs.type,
        IFNULL(cs.type_id, 0),
        IFNULL(cs.type_price, 0),
        IFNULL(cs.branch_id, ''),
        IFNULL(cs.head_count, 0),
        ROW_NUMBER() OVER (
            PARTITION BY cs.costing_id, cs.type, IFNULL(cs.type_id, 0), IFNULL(cs.type_price, 0), IFNULL(cs.branch_id, ''), IFNULL(cs.head_count, 0)
            ORDER BY cs.id
        )
    FROM tmp_endorsement_bf t
    INNER JOIN costing_service cs
        ON cs.costing_id = t.costing_id
        AND cs.is_deleted = 0
    WHERE t.costing_id IS NOT NULL;

    DROP TEMPORARY TABLE IF EXISTS tmp_qs_rank;
    CREATE TEMPORARY TABLE tmp_qs_rank (
        quotation_service_id INT NOT NULL,
        quotation_id INT NOT NULL,
        type_key VARCHAR(100) NOT NULL,
        type_id_key INT NOT NULL,
        type_price_key DECIMAL(13,4) NOT NULL,
        branch_key VARCHAR(20) NOT NULL,
        head_count_key INT NOT NULL,
        rn INT NOT NULL,
        PRIMARY KEY (quotation_service_id),
        KEY idx_qs_rank (quotation_id, type_key, type_id_key, type_price_key, branch_key, head_count_key, rn)
    );

    INSERT INTO tmp_qs_rank (
        quotation_service_id, quotation_id, type_key, type_id_key, type_price_key, branch_key, head_count_key, rn
    )
    SELECT
        qs.id,
        qs.quotation_id,
        qs.type,
        IFNULL(qs.type_id, 0),
        IFNULL(qs.type_price, 0),
        IFNULL(qs.branch_id, ''),
        IFNULL(qs.head_count, 0),
        ROW_NUMBER() OVER (
            PARTITION BY qs.quotation_id, qs.type, IFNULL(qs.type_id, 0), IFNULL(qs.type_price, 0), IFNULL(qs.branch_id, ''), IFNULL(qs.head_count, 0)
            ORDER BY qs.id
        )
    FROM tmp_endorsement_bf t
    INNER JOIN quotation_service qs
        ON qs.quotation_id = t.quotation_id
        AND qs.is_deleted = 0
    WHERE t.quotation_id IS NOT NULL;

    DROP TEMPORARY TABLE IF EXISTS tmp_svc_map;
    CREATE TEMPORARY TABLE tmp_svc_map (
        endorsement_service_id INT NOT NULL,
        costing_service_id INT DEFAULT NULL,
        quotation_service_id INT DEFAULT NULL,
        PRIMARY KEY (endorsement_service_id)
    );

    INSERT INTO tmp_svc_map (endorsement_service_id, costing_service_id, quotation_service_id)
    SELECT
        er.endorsement_service_id,
        cr.costing_service_id,
        qr.quotation_service_id
    FROM tmp_es_rank er
    LEFT JOIN tmp_cs_rank cr
        ON cr.costing_id = er.costing_id
        AND cr.type_key = er.type_key
        AND cr.type_id_key = er.type_id_key
        AND cr.type_price_key = er.type_price_key
        AND cr.branch_key = er.branch_key
        AND cr.head_count_key = er.head_count_key
        AND cr.rn = er.rn
    LEFT JOIN tmp_qs_rank qr
        ON qr.quotation_id = er.quotation_id
        AND qr.type_key = er.type_key
        AND qr.type_id_key = er.type_id_key
        AND qr.type_price_key = er.type_price_key
        AND qr.branch_key = er.branch_key
        AND qr.head_count_key = er.head_count_key
        AND qr.rn = er.rn;

    INSERT INTO costing_service_package_detail (
        costing_service_id, costing_id, package_id, lab_test_id, is_add_on, price, margin,
        cost, pro_rate_price, discount, total_amount, branch_id, charge_to,
        head_count, discount_amount, margin_amount, sales, admin_fee, direct_cost,
        manpower_cost, mobilization_cost, total_cost, estimated_margin,
        direct_cost_amount_total, manpower_cost_amount_total,
        mobilization_cost_amount_total, total_cost_amount_total,
        estimated_margin_amount_total, estimated_revenue, pre_negotiated_rate,
        theoretical_cost, added_by, added_on, is_deleted
    )
    SELECT
        m.costing_service_id,
        t.costing_id,
        pd.package_id,
        pd.lab_test_id,
        IFNULL(pd.is_add_on, 0),
        IFNULL(pd.price, 0),
        IFNULL(pd.margin, 0),
        IFNULL(pd.cost, 0),
        IFNULL(pd.pro_rate_price, 0),
        IFNULL(pd.discount, 0),
        IFNULL(pd.total_amount, 0),
        pd.branch_id,
        pd.charge_to,
        pd.head_count,
        pd.discount_amount,
        pd.margin_amount,
        pd.sales,
        pd.admin_fee,
        pd.direct_cost,
        pd.manpower_cost,
        pd.mobilization_cost,
        pd.total_cost,
        pd.estimated_margin,
        pd.direct_cost_amount_total,
        pd.manpower_cost_amount_total,
        pd.mobilization_cost_amount_total,
        pd.total_cost_amount_total,
        pd.estimated_margin_amount_total,
        pd.estimated_revenue,
        IFNULL(pd.pre_negotiated_rate, 0),
        IFNULL(pd.theoretical_cost, 0),
        t.added_by,
        t.added_on,
        0
    FROM tmp_endorsement_bf t
    INNER JOIN endorsement_service_package_detail pd
        ON pd.endorsement_id = t.endorsement_id
        AND pd.is_deleted = 0
    INNER JOIN tmp_svc_map m ON m.endorsement_service_id = pd.endorsement_service_id
    WHERE t.costing_id IS NOT NULL
        AND m.costing_service_id IS NOT NULL
        AND NOT EXISTS (
            SELECT 1
            FROM costing_service_package_detail cpd
            WHERE cpd.costing_id = t.costing_id
                AND cpd.is_deleted = 0
        );

    INSERT INTO quotation_service_package_detail (
        quotation_service_id, quotation_id, package_id, lab_test_id, is_add_on, price, margin,
        cost, pro_rate_price, discount, total_amount, branch_id, charge_to,
        head_count, discount_amount, margin_amount, sales, admin_fee, direct_cost,
        manpower_cost, mobilization_cost, total_cost, estimated_margin,
        direct_cost_amount_total, manpower_cost_amount_total,
        mobilization_cost_amount_total, total_cost_amount_total,
        estimated_margin_amount_total, estimated_revenue, pre_negotiated_rate,
        theoretical_cost, added_by, added_on, is_deleted
    )
    SELECT
        m.quotation_service_id,
        t.quotation_id,
        pd.package_id,
        pd.lab_test_id,
        IFNULL(pd.is_add_on, 0),
        IFNULL(pd.price, 0),
        IFNULL(pd.margin, 0),
        IFNULL(pd.cost, 0),
        IFNULL(pd.pro_rate_price, 0),
        IFNULL(pd.discount, 0),
        IFNULL(pd.total_amount, 0),
        pd.branch_id,
        pd.charge_to,
        pd.head_count,
        pd.discount_amount,
        pd.margin_amount,
        pd.sales,
        pd.admin_fee,
        pd.direct_cost,
        pd.manpower_cost,
        pd.mobilization_cost,
        pd.total_cost,
        pd.estimated_margin,
        pd.direct_cost_amount_total,
        pd.manpower_cost_amount_total,
        pd.mobilization_cost_amount_total,
        pd.total_cost_amount_total,
        pd.estimated_margin_amount_total,
        pd.estimated_revenue,
        IFNULL(pd.pre_negotiated_rate, 0),
        IFNULL(pd.theoretical_cost, 0),
        t.added_by,
        t.added_on,
        0
    FROM tmp_endorsement_bf t
    INNER JOIN endorsement_service_package_detail pd
        ON pd.endorsement_id = t.endorsement_id
        AND pd.is_deleted = 0
    INNER JOIN tmp_svc_map m ON m.endorsement_service_id = pd.endorsement_service_id
    WHERE t.quotation_id IS NOT NULL
        AND m.quotation_service_id IS NOT NULL
        AND NOT EXISTS (
            SELECT 1
            FROM quotation_service_package_detail qpd
            WHERE qpd.quotation_id = t.quotation_id
                AND qpd.is_deleted = 0
        );

    -- -----------------------------------------------------------------------
    -- Link endorsements
    -- -----------------------------------------------------------------------
    UPDATE endorsement e
    INNER JOIN tmp_endorsement_bf t ON t.endorsement_id = e.id
    SET
        e.request_id = t.request_id,
        e.costing_id = t.costing_id,
        e.quotation_id = t.quotation_id,
        e.updated_on = v_now
    WHERE t.request_id IS NOT NULL
        AND t.costing_id IS NOT NULL
        AND t.quotation_id IS NOT NULL;

    SELECT COUNT(*) INTO v_missing_after
    FROM endorsement e
    INNER JOIN tmp_endorsement_bf t ON t.endorsement_id = e.id
    WHERE e.is_deleted = 0
        AND (
            IFNULL(e.request_id, 0) = 0
            OR IFNULL(e.costing_id, 0) = 0
            OR IFNULL(e.quotation_id, 0) = 0
        );

    -- Verification
    SELECT
        t.endorsement_id,
        t.request_id,
        t.costing_id,
        t.quotation_id,
        t.need_request,
        t.need_costing,
        t.need_quotation
    FROM tmp_endorsement_bf t
    ORDER BY t.endorsement_id;

    SELECT
        (SELECT COUNT(*) FROM request WHERE reference_no LIKE 'BF-END-%' AND is_deleted = 0) AS backfill_requests,
        (SELECT COUNT(*) FROM quotation WHERE quotation_no LIKE 'QT-BF-%' AND is_deleted = 0) AS backfill_quotations,
        (SELECT COUNT(*) FROM endorsement e INNER JOIN tmp_endorsement_bf t ON t.endorsement_id = e.id WHERE IFNULL(e.request_id, 0) = 0 OR IFNULL(e.costing_id, 0) = 0 OR IFNULL(e.quotation_id, 0) = 0) AS still_missing_in_batch,
        v_missing_after AS still_missing_in_batch_var;

    SELECT
        t.endorsement_id,
        (SELECT COUNT(*) FROM endorsement_service es WHERE es.endorsement_id = t.endorsement_id AND es.is_deleted = 0) AS endorsement_services,
        (SELECT COUNT(*) FROM request_service rs WHERE rs.request_id = t.request_id AND rs.is_deleted = 0) AS request_services,
        (SELECT COUNT(*) FROM costing_service cs WHERE cs.costing_id = t.costing_id AND cs.is_deleted = 0) AS costing_services,
        (SELECT COUNT(*) FROM quotation_service qs WHERE qs.quotation_id = t.quotation_id AND qs.is_deleted = 0) AS quotation_services,
        (SELECT COUNT(*) FROM endorsement_service_package_detail pd WHERE pd.endorsement_id = t.endorsement_id AND pd.is_deleted = 0) AS endorsement_package_details,
        (SELECT COUNT(*) FROM costing_service_package_detail cpd WHERE cpd.costing_id = t.costing_id AND cpd.is_deleted = 0) AS costing_package_details,
        (SELECT COUNT(*) FROM quotation_service_package_detail qpd WHERE qpd.quotation_id = t.quotation_id AND qpd.is_deleted = 0) AS quotation_package_details
    FROM tmp_endorsement_bf t
    ORDER BY t.endorsement_id;

    SELECT id, request_id, costing_id, quotation_id, is_deleted
    FROM endorsement
    WHERE id IN (16, 17, 18, 19)
    ORDER BY id;

    IF v_missing_after > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Backfill left endorsements still missing request, costing, or quotation';
    END IF;

    COMMIT;
END$$

DELIMITER ;

CALL endorsement_bf_request_costing_quotation();

DROP PROCEDURE IF EXISTS endorsement_bf_request_costing_quotation;
