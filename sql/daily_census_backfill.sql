-- Backfill mytemr_reports.daily_census for 2026-08-01 through 2026-09-24.
-- Same rules as Cron::get_daily_census() / Report::_daily_census_sql().
-- Run from any database that can see mytemr_reports, mytemr_master,
-- and the five EMR and diagnostic databases. Does not change other dates.
--
-- One row per branch per day (56 days x branches 1, 2, 3, 4, 7).
-- Existing rows upsert on UNIQUE (branch_id, date, is_deleted).
-- Days with no activity are stored as 0, matching the cron's scalar SUM/COUNT.
--
-- Yakap new vs old uses the census date, not CURDATE(): a PCP is new on a day
-- when that day is their first non-deleted queue row. That is what the cron
-- stores when it runs on that day (DATE(added_on) < census date).
--
-- opto, cardio_source, and their counts stay 0.00, same as the cron literals.
-- clin_chem and hema both use lab category 18.
-- immuno and sero both use lab category 31.
--
-- On error: ROLLBACK;

START TRANSACTION;

-- ---------------------------------------------------------------------------
-- Branch 1 Mobile
-- emr: mytemr_mobile
-- diagnostic: mytemr_diagnostic_mobile
-- ---------------------------------------------------------------------------
INSERT INTO mytemr_reports.daily_census (
    branch_id,
    `date`,
    specialist_consult,
    total_yakap,
    pcp_ape_peme,
    pcp_yakap_new,
    pcp_yakap_old,
    pcp_consult_hmo_cash,
    xray,
    ecg,
    two_d_echo,
    tst,
    vascular,
    ultrasound,
    womancare,
    clin_chem,
    hema,
    immuno,
    sero,
    clin_mic,
    histo,
    drug_test,
    send_out,
    ophtha,
    opto,
    dental,
    ent,
    audiometry,
    abc,
    clinic_vax,
    cardio_source,
    specialist_consult_count,
    total_yakap_count,
    pcp_ape_peme_count,
    pcp_yakap_new_count,
    pcp_yakap_old_count,
    pcp_consult_hmo_cash_count,
    xray_count,
    ecg_count,
    two_d_echo_count,
    tst_count,
    vascular_count,
    ultrasound_count,
    womancare_count,
    clin_chem_count,
    hema_count,
    immuno_count,
    sero_count,
    clin_mic_count,
    histo_count,
    drug_test_count,
    send_out_count,
    ophtha_count,
    opto_count,
    dental_count,
    ent_count,
    audiometry_count,
    abc_count,
    clinic_vax_count,
    cardio_source_count,
    added_on,
    is_deleted
)
WITH RECURSIVE dates AS (
    SELECT DATE('2026-08-01') AS d
    UNION ALL
    SELECT d + INTERVAL 1 DAY FROM dates WHERE d < DATE('2026-09-24')
),
doc_flags AS (
    SELECT
        d.id,
        MAX(CASE WHEN s.prefix = 'SP' THEN 1 ELSE 0 END) AS is_sp,
        MAX(CASE WHEN s.name = 'PCP' THEN 1 ELSE 0 END) AS is_pcp
    FROM mytemr_master.doctor d
    INNER JOIN mytemr_master.specialization s
        ON s.is_deleted = 0
       AND FIND_IN_SET(s.id, d.specializations)
    WHERE d.is_deleted = 0
    GROUP BY d.id
),
queue_metrics AS (
    SELECT
        DATE(q.added_on) AS d,
        SUM(CASE WHEN doc.is_sp = 1 THEN IFNULL(t.grand_total, 0) ELSE 0 END) AS specialist_consult,
        SUM(CASE WHEN doc.is_sp = 1 THEN 1 ELSE 0 END) AS specialist_consult_count,
        SUM(CASE WHEN doc.is_pcp = 1 AND q.assessment_type = 'ekonsulta' THEN IFNULL(t.grand_total, 0) ELSE 0 END) AS total_yakap,
        SUM(CASE WHEN doc.is_pcp = 1 AND q.assessment_type = 'ekonsulta' THEN 1 ELSE 0 END) AS total_yakap_count,
        SUM(CASE WHEN doc.is_pcp = 1 AND q.assessment_type = 'ekonsulta' AND fs.first_on = DATE(q.added_on) THEN IFNULL(t.grand_total, 0) ELSE 0 END) AS pcp_yakap_new,
        SUM(CASE WHEN doc.is_pcp = 1 AND q.assessment_type = 'ekonsulta' AND fs.first_on = DATE(q.added_on) THEN 1 ELSE 0 END) AS pcp_yakap_new_count,
        SUM(CASE WHEN doc.is_pcp = 1 AND q.assessment_type = 'ekonsulta' AND fs.first_on < DATE(q.added_on) THEN IFNULL(t.grand_total, 0) ELSE 0 END) AS pcp_yakap_old,
        SUM(CASE WHEN doc.is_pcp = 1 AND q.assessment_type = 'ekonsulta' AND fs.first_on < DATE(q.added_on) THEN 1 ELSE 0 END) AS pcp_yakap_old_count,
        SUM(CASE WHEN doc.is_pcp = 1 AND q.assessment_type <> 'ekonsulta' THEN IFNULL(t.grand_total, 0) ELSE 0 END) AS pcp_consult_hmo_cash,
        SUM(CASE WHEN doc.is_pcp = 1 AND q.assessment_type <> 'ekonsulta' THEN 1 ELSE 0 END) AS pcp_consult_hmo_cash_count
    FROM mytemr_mobile.queue q
    LEFT JOIN mytemr_diagnostic_mobile.transaction t
        ON t.id = q.transaction_id
    INNER JOIN doc_flags doc
        ON doc.id = q.doctor_id
    LEFT JOIN (
        SELECT doctor_id, MIN(DATE(added_on)) AS first_on
        FROM mytemr_mobile.queue
        WHERE is_deleted = 0
          AND doctor_id IS NOT NULL
        GROUP BY doctor_id
    ) fs
        ON fs.doctor_id = q.doctor_id
    WHERE q.status = 'finished'
      AND q.is_deleted = 0
      AND q.added_on >= '2026-08-01'
      AND q.added_on < '2026-09-26'
      AND (doc.is_sp = 1 OR doc.is_pcp = 1)
    GROUP BY DATE(q.added_on)
),
exam_metrics AS (
    SELECT
        DATE(pe.added_on) AS d,
        SUM(IFNULL(t.grand_total, 0)) AS pcp_ape_peme,
        COUNT(*) AS pcp_ape_peme_count
    FROM mytemr_mobile.physical_exam pe
    LEFT JOIN mytemr_diagnostic_mobile.transaction t
        ON t.id = pe.transaction_id
    INNER JOIN doc_flags doc
        ON doc.id = pe.doctor_id
       AND doc.is_pcp = 1
    WHERE pe.is_deleted = 0
      AND pe.added_on >= '2026-08-01'
      AND pe.added_on < '2026-09-26'
    GROUP BY DATE(pe.added_on)
),
dx_metrics AS (
    SELECT
        DATE(bd.added_on) AS d,
        SUM(CASE WHEN lt.category_id = 43 THEN bd.amount ELSE 0 END) AS xray,
        SUM(CASE WHEN lt.category_id = 43 THEN 1 ELSE 0 END) AS xray_count,
        SUM(CASE WHEN lt.category_id = 24 THEN bd.amount ELSE 0 END) AS ecg,
        SUM(CASE WHEN lt.category_id = 24 THEN 1 ELSE 0 END) AS ecg_count,
        SUM(CASE WHEN lt.category_id = 25 THEN bd.amount ELSE 0 END) AS two_d_echo,
        SUM(CASE WHEN lt.category_id = 25 THEN 1 ELSE 0 END) AS two_d_echo_count,
        SUM(CASE WHEN lt.category_id = 41 THEN bd.amount ELSE 0 END) AS tst,
        SUM(CASE WHEN lt.category_id = 41 THEN 1 ELSE 0 END) AS tst_count,
        SUM(CASE WHEN lt.category_id = 39 THEN bd.amount ELSE 0 END) AS vascular,
        SUM(CASE WHEN lt.category_id = 39 THEN 1 ELSE 0 END) AS vascular_count,
        SUM(CASE WHEN lt.category_id = 27 THEN bd.amount ELSE 0 END) AS ultrasound,
        SUM(CASE WHEN lt.category_id = 27 THEN 1 ELSE 0 END) AS ultrasound_count,
        SUM(CASE WHEN lt.category_id = 42 THEN bd.amount ELSE 0 END) AS womancare,
        SUM(CASE WHEN lt.category_id = 42 THEN 1 ELSE 0 END) AS womancare_count,
        SUM(CASE WHEN lt.category_id = 18 THEN bd.amount ELSE 0 END) AS clin_chem,
        SUM(CASE WHEN lt.category_id = 18 THEN 1 ELSE 0 END) AS clin_chem_count,
        SUM(CASE WHEN lt.category_id = 18 THEN bd.amount ELSE 0 END) AS hema,
        SUM(CASE WHEN lt.category_id = 18 THEN 1 ELSE 0 END) AS hema_count,
        SUM(CASE WHEN lt.category_id = 31 THEN bd.amount ELSE 0 END) AS immuno,
        SUM(CASE WHEN lt.category_id = 31 THEN 1 ELSE 0 END) AS immuno_count,
        SUM(CASE WHEN lt.category_id = 31 THEN bd.amount ELSE 0 END) AS sero,
        SUM(CASE WHEN lt.category_id = 31 THEN 1 ELSE 0 END) AS sero_count,
        SUM(CASE WHEN lt.category_id = 19 THEN bd.amount ELSE 0 END) AS clin_mic,
        SUM(CASE WHEN lt.category_id = 19 THEN 1 ELSE 0 END) AS clin_mic_count,
        SUM(CASE WHEN lt.category_id = 29 THEN bd.amount ELSE 0 END) AS histo,
        SUM(CASE WHEN lt.category_id = 29 THEN 1 ELSE 0 END) AS histo_count,
        SUM(CASE WHEN lt.category_id IN (2, 23) THEN bd.amount ELSE 0 END) AS drug_test,
        SUM(CASE WHEN lt.category_id IN (2, 23) THEN 1 ELSE 0 END) AS drug_test_count,
        SUM(CASE WHEN lt.category_id = 37 THEN bd.amount ELSE 0 END) AS ophtha,
        SUM(CASE WHEN lt.category_id = 37 THEN 1 ELSE 0 END) AS ophtha_count,
        SUM(CASE WHEN lt.category_id IN (22, 77) THEN bd.amount ELSE 0 END) AS dental,
        SUM(CASE WHEN lt.category_id IN (22, 77) THEN 1 ELSE 0 END) AS dental_count,
        SUM(CASE WHEN lt.category_id = 26 THEN bd.amount ELSE 0 END) AS ent,
        SUM(CASE WHEN lt.category_id = 26 THEN 1 ELSE 0 END) AS ent_count,
        SUM(CASE WHEN lt.category_id = 16 THEN bd.amount ELSE 0 END) AS audiometry,
        SUM(CASE WHEN lt.category_id = 16 THEN 1 ELSE 0 END) AS audiometry_count,
        SUM(CASE WHEN lt.category_id = 14 THEN bd.amount ELSE 0 END) AS abc,
        SUM(CASE WHEN lt.category_id = 14 THEN 1 ELSE 0 END) AS abc_count,
        SUM(CASE WHEN lt.category_id = 17 THEN bd.amount ELSE 0 END) AS clinic_vax,
        SUM(CASE WHEN lt.category_id = 17 THEN 1 ELSE 0 END) AS clinic_vax_count
    FROM mytemr_diagnostic_mobile.booking_details bd
    LEFT JOIN mytemr_diagnostic_mobile.booking_package_details bpd
        ON bpd.booking_detail_id = bd.id
       AND bpd.is_deleted = 0
    INNER JOIN mytemr_diagnostic_mobile.lab_test lt
        ON lt.id = CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
       AND lt.is_deleted = 0
    WHERE bd.is_deleted = 0
      AND bd.added_on >= '2026-08-01'
      AND bd.added_on < '2026-09-26'
      AND (bd.type != 'package' OR bpd.id IS NOT NULL)
    GROUP BY DATE(bd.added_on)
),
send_out_metrics AS (
    SELECT
        DATE(bd.added_on) AS d,
        SUM(bd.amount) AS send_out,
        COUNT(*) AS send_out_count
    FROM mytemr_diagnostic_mobile.booking_details bd
    WHERE bd.is_deleted = 0
      AND bd.status = 'send out'
      AND bd.added_on >= '2026-08-01'
      AND bd.added_on < '2026-09-26'
    GROUP BY DATE(bd.added_on)
)
SELECT
    1 AS branch_id,
    dates.d AS `date`,
    IFNULL(q.specialist_consult, 0) AS specialist_consult,
    IFNULL(q.total_yakap, 0) AS total_yakap,
    IFNULL(e.pcp_ape_peme, 0) AS pcp_ape_peme,
    IFNULL(q.pcp_yakap_new, 0) AS pcp_yakap_new,
    IFNULL(q.pcp_yakap_old, 0) AS pcp_yakap_old,
    IFNULL(q.pcp_consult_hmo_cash, 0) AS pcp_consult_hmo_cash,
    IFNULL(dx.xray, 0) AS xray,
    IFNULL(dx.ecg, 0) AS ecg,
    IFNULL(dx.two_d_echo, 0) AS two_d_echo,
    IFNULL(dx.tst, 0) AS tst,
    IFNULL(dx.vascular, 0) AS vascular,
    IFNULL(dx.ultrasound, 0) AS ultrasound,
    IFNULL(dx.womancare, 0) AS womancare,
    IFNULL(dx.clin_chem, 0) AS clin_chem,
    IFNULL(dx.hema, 0) AS hema,
    IFNULL(dx.immuno, 0) AS immuno,
    IFNULL(dx.sero, 0) AS sero,
    IFNULL(dx.clin_mic, 0) AS clin_mic,
    IFNULL(dx.histo, 0) AS histo,
    IFNULL(dx.drug_test, 0) AS drug_test,
    IFNULL(so.send_out, 0) AS send_out,
    IFNULL(dx.ophtha, 0) AS ophtha,
    0.00 AS opto,
    IFNULL(dx.dental, 0) AS dental,
    IFNULL(dx.ent, 0) AS ent,
    IFNULL(dx.audiometry, 0) AS audiometry,
    IFNULL(dx.abc, 0) AS abc,
    IFNULL(dx.clinic_vax, 0) AS clinic_vax,
    0.00 AS cardio_source,
    IFNULL(q.specialist_consult_count, 0) AS specialist_consult_count,
    IFNULL(q.total_yakap_count, 0) AS total_yakap_count,
    IFNULL(e.pcp_ape_peme_count, 0) AS pcp_ape_peme_count,
    IFNULL(q.pcp_yakap_new_count, 0) AS pcp_yakap_new_count,
    IFNULL(q.pcp_yakap_old_count, 0) AS pcp_yakap_old_count,
    IFNULL(q.pcp_consult_hmo_cash_count, 0) AS pcp_consult_hmo_cash_count,
    IFNULL(dx.xray_count, 0) AS xray_count,
    IFNULL(dx.ecg_count, 0) AS ecg_count,
    IFNULL(dx.two_d_echo_count, 0) AS two_d_echo_count,
    IFNULL(dx.tst_count, 0) AS tst_count,
    IFNULL(dx.vascular_count, 0) AS vascular_count,
    IFNULL(dx.ultrasound_count, 0) AS ultrasound_count,
    IFNULL(dx.womancare_count, 0) AS womancare_count,
    IFNULL(dx.clin_chem_count, 0) AS clin_chem_count,
    IFNULL(dx.hema_count, 0) AS hema_count,
    IFNULL(dx.immuno_count, 0) AS immuno_count,
    IFNULL(dx.sero_count, 0) AS sero_count,
    IFNULL(dx.clin_mic_count, 0) AS clin_mic_count,
    IFNULL(dx.histo_count, 0) AS histo_count,
    IFNULL(dx.drug_test_count, 0) AS drug_test_count,
    IFNULL(so.send_out_count, 0) AS send_out_count,
    IFNULL(dx.ophtha_count, 0) AS ophtha_count,
    0.00 AS opto_count,
    IFNULL(dx.dental_count, 0) AS dental_count,
    IFNULL(dx.ent_count, 0) AS ent_count,
    IFNULL(dx.audiometry_count, 0) AS audiometry_count,
    IFNULL(dx.abc_count, 0) AS abc_count,
    IFNULL(dx.clinic_vax_count, 0) AS clinic_vax_count,
    0.00 AS cardio_source_count,
    NOW() AS added_on,
    0 AS is_deleted
FROM dates
LEFT JOIN queue_metrics q ON q.d = dates.d
LEFT JOIN exam_metrics e ON e.d = dates.d
LEFT JOIN dx_metrics dx ON dx.d = dates.d
LEFT JOIN send_out_metrics so ON so.d = dates.d
ON DUPLICATE KEY UPDATE
    specialist_consult = VALUES(specialist_consult),
    total_yakap = VALUES(total_yakap),
    pcp_ape_peme = VALUES(pcp_ape_peme),
    pcp_yakap_new = VALUES(pcp_yakap_new),
    pcp_yakap_old = VALUES(pcp_yakap_old),
    pcp_consult_hmo_cash = VALUES(pcp_consult_hmo_cash),
    xray = VALUES(xray),
    ecg = VALUES(ecg),
    two_d_echo = VALUES(two_d_echo),
    tst = VALUES(tst),
    vascular = VALUES(vascular),
    ultrasound = VALUES(ultrasound),
    womancare = VALUES(womancare),
    clin_chem = VALUES(clin_chem),
    hema = VALUES(hema),
    immuno = VALUES(immuno),
    sero = VALUES(sero),
    clin_mic = VALUES(clin_mic),
    histo = VALUES(histo),
    drug_test = VALUES(drug_test),
    send_out = VALUES(send_out),
    ophtha = VALUES(ophtha),
    opto = VALUES(opto),
    dental = VALUES(dental),
    ent = VALUES(ent),
    audiometry = VALUES(audiometry),
    abc = VALUES(abc),
    clinic_vax = VALUES(clinic_vax),
    cardio_source = VALUES(cardio_source),
    specialist_consult_count = VALUES(specialist_consult_count),
    total_yakap_count = VALUES(total_yakap_count),
    pcp_ape_peme_count = VALUES(pcp_ape_peme_count),
    pcp_yakap_new_count = VALUES(pcp_yakap_new_count),
    pcp_yakap_old_count = VALUES(pcp_yakap_old_count),
    pcp_consult_hmo_cash_count = VALUES(pcp_consult_hmo_cash_count),
    xray_count = VALUES(xray_count),
    ecg_count = VALUES(ecg_count),
    two_d_echo_count = VALUES(two_d_echo_count),
    tst_count = VALUES(tst_count),
    vascular_count = VALUES(vascular_count),
    ultrasound_count = VALUES(ultrasound_count),
    womancare_count = VALUES(womancare_count),
    clin_chem_count = VALUES(clin_chem_count),
    hema_count = VALUES(hema_count),
    immuno_count = VALUES(immuno_count),
    sero_count = VALUES(sero_count),
    clin_mic_count = VALUES(clin_mic_count),
    histo_count = VALUES(histo_count),
    drug_test_count = VALUES(drug_test_count),
    send_out_count = VALUES(send_out_count),
    ophtha_count = VALUES(ophtha_count),
    opto_count = VALUES(opto_count),
    dental_count = VALUES(dental_count),
    ent_count = VALUES(ent_count),
    audiometry_count = VALUES(audiometry_count),
    abc_count = VALUES(abc_count),
    clinic_vax_count = VALUES(clinic_vax_count),
    cardio_source_count = VALUES(cardio_source_count),
    added_on = VALUES(added_on);

-- ---------------------------------------------------------------------------
-- Branch 2 Oakridge
-- emr: mytemr_emr
-- diagnostic: mytemr_diagnostic
-- ---------------------------------------------------------------------------
INSERT INTO mytemr_reports.daily_census (
    branch_id,
    `date`,
    specialist_consult,
    total_yakap,
    pcp_ape_peme,
    pcp_yakap_new,
    pcp_yakap_old,
    pcp_consult_hmo_cash,
    xray,
    ecg,
    two_d_echo,
    tst,
    vascular,
    ultrasound,
    womancare,
    clin_chem,
    hema,
    immuno,
    sero,
    clin_mic,
    histo,
    drug_test,
    send_out,
    ophtha,
    opto,
    dental,
    ent,
    audiometry,
    abc,
    clinic_vax,
    cardio_source,
    specialist_consult_count,
    total_yakap_count,
    pcp_ape_peme_count,
    pcp_yakap_new_count,
    pcp_yakap_old_count,
    pcp_consult_hmo_cash_count,
    xray_count,
    ecg_count,
    two_d_echo_count,
    tst_count,
    vascular_count,
    ultrasound_count,
    womancare_count,
    clin_chem_count,
    hema_count,
    immuno_count,
    sero_count,
    clin_mic_count,
    histo_count,
    drug_test_count,
    send_out_count,
    ophtha_count,
    opto_count,
    dental_count,
    ent_count,
    audiometry_count,
    abc_count,
    clinic_vax_count,
    cardio_source_count,
    added_on,
    is_deleted
)
WITH RECURSIVE dates AS (
    SELECT DATE('2026-08-01') AS d
    UNION ALL
    SELECT d + INTERVAL 1 DAY FROM dates WHERE d < DATE('2026-09-24')
),
doc_flags AS (
    SELECT
        d.id,
        MAX(CASE WHEN s.prefix = 'SP' THEN 1 ELSE 0 END) AS is_sp,
        MAX(CASE WHEN s.name = 'PCP' THEN 1 ELSE 0 END) AS is_pcp
    FROM mytemr_master.doctor d
    INNER JOIN mytemr_master.specialization s
        ON s.is_deleted = 0
       AND FIND_IN_SET(s.id, d.specializations)
    WHERE d.is_deleted = 0
    GROUP BY d.id
),
queue_metrics AS (
    SELECT
        DATE(q.added_on) AS d,
        SUM(CASE WHEN doc.is_sp = 1 THEN IFNULL(t.grand_total, 0) ELSE 0 END) AS specialist_consult,
        SUM(CASE WHEN doc.is_sp = 1 THEN 1 ELSE 0 END) AS specialist_consult_count,
        SUM(CASE WHEN doc.is_pcp = 1 AND q.assessment_type = 'ekonsulta' THEN IFNULL(t.grand_total, 0) ELSE 0 END) AS total_yakap,
        SUM(CASE WHEN doc.is_pcp = 1 AND q.assessment_type = 'ekonsulta' THEN 1 ELSE 0 END) AS total_yakap_count,
        SUM(CASE WHEN doc.is_pcp = 1 AND q.assessment_type = 'ekonsulta' AND fs.first_on = DATE(q.added_on) THEN IFNULL(t.grand_total, 0) ELSE 0 END) AS pcp_yakap_new,
        SUM(CASE WHEN doc.is_pcp = 1 AND q.assessment_type = 'ekonsulta' AND fs.first_on = DATE(q.added_on) THEN 1 ELSE 0 END) AS pcp_yakap_new_count,
        SUM(CASE WHEN doc.is_pcp = 1 AND q.assessment_type = 'ekonsulta' AND fs.first_on < DATE(q.added_on) THEN IFNULL(t.grand_total, 0) ELSE 0 END) AS pcp_yakap_old,
        SUM(CASE WHEN doc.is_pcp = 1 AND q.assessment_type = 'ekonsulta' AND fs.first_on < DATE(q.added_on) THEN 1 ELSE 0 END) AS pcp_yakap_old_count,
        SUM(CASE WHEN doc.is_pcp = 1 AND q.assessment_type <> 'ekonsulta' THEN IFNULL(t.grand_total, 0) ELSE 0 END) AS pcp_consult_hmo_cash,
        SUM(CASE WHEN doc.is_pcp = 1 AND q.assessment_type <> 'ekonsulta' THEN 1 ELSE 0 END) AS pcp_consult_hmo_cash_count
    FROM mytemr_emr.queue q
    LEFT JOIN mytemr_diagnostic.transaction t
        ON t.id = q.transaction_id
    INNER JOIN doc_flags doc
        ON doc.id = q.doctor_id
    LEFT JOIN (
        SELECT doctor_id, MIN(DATE(added_on)) AS first_on
        FROM mytemr_emr.queue
        WHERE is_deleted = 0
          AND doctor_id IS NOT NULL
        GROUP BY doctor_id
    ) fs
        ON fs.doctor_id = q.doctor_id
    WHERE q.status = 'finished'
      AND q.is_deleted = 0
      AND q.added_on >= '2026-08-01'
      AND q.added_on < '2026-09-26'
      AND (doc.is_sp = 1 OR doc.is_pcp = 1)
    GROUP BY DATE(q.added_on)
),
exam_metrics AS (
    SELECT
        DATE(pe.added_on) AS d,
        SUM(IFNULL(t.grand_total, 0)) AS pcp_ape_peme,
        COUNT(*) AS pcp_ape_peme_count
    FROM mytemr_emr.physical_exam pe
    LEFT JOIN mytemr_diagnostic.transaction t
        ON t.id = pe.transaction_id
    INNER JOIN doc_flags doc
        ON doc.id = pe.doctor_id
       AND doc.is_pcp = 1
    WHERE pe.is_deleted = 0
      AND pe.added_on >= '2026-08-01'
      AND pe.added_on < '2026-09-26'
    GROUP BY DATE(pe.added_on)
),
dx_metrics AS (
    SELECT
        DATE(bd.added_on) AS d,
        SUM(CASE WHEN lt.category_id = 43 THEN bd.amount ELSE 0 END) AS xray,
        SUM(CASE WHEN lt.category_id = 43 THEN 1 ELSE 0 END) AS xray_count,
        SUM(CASE WHEN lt.category_id = 24 THEN bd.amount ELSE 0 END) AS ecg,
        SUM(CASE WHEN lt.category_id = 24 THEN 1 ELSE 0 END) AS ecg_count,
        SUM(CASE WHEN lt.category_id = 25 THEN bd.amount ELSE 0 END) AS two_d_echo,
        SUM(CASE WHEN lt.category_id = 25 THEN 1 ELSE 0 END) AS two_d_echo_count,
        SUM(CASE WHEN lt.category_id = 41 THEN bd.amount ELSE 0 END) AS tst,
        SUM(CASE WHEN lt.category_id = 41 THEN 1 ELSE 0 END) AS tst_count,
        SUM(CASE WHEN lt.category_id = 39 THEN bd.amount ELSE 0 END) AS vascular,
        SUM(CASE WHEN lt.category_id = 39 THEN 1 ELSE 0 END) AS vascular_count,
        SUM(CASE WHEN lt.category_id = 27 THEN bd.amount ELSE 0 END) AS ultrasound,
        SUM(CASE WHEN lt.category_id = 27 THEN 1 ELSE 0 END) AS ultrasound_count,
        SUM(CASE WHEN lt.category_id = 42 THEN bd.amount ELSE 0 END) AS womancare,
        SUM(CASE WHEN lt.category_id = 42 THEN 1 ELSE 0 END) AS womancare_count,
        SUM(CASE WHEN lt.category_id = 18 THEN bd.amount ELSE 0 END) AS clin_chem,
        SUM(CASE WHEN lt.category_id = 18 THEN 1 ELSE 0 END) AS clin_chem_count,
        SUM(CASE WHEN lt.category_id = 18 THEN bd.amount ELSE 0 END) AS hema,
        SUM(CASE WHEN lt.category_id = 18 THEN 1 ELSE 0 END) AS hema_count,
        SUM(CASE WHEN lt.category_id = 31 THEN bd.amount ELSE 0 END) AS immuno,
        SUM(CASE WHEN lt.category_id = 31 THEN 1 ELSE 0 END) AS immuno_count,
        SUM(CASE WHEN lt.category_id = 31 THEN bd.amount ELSE 0 END) AS sero,
        SUM(CASE WHEN lt.category_id = 31 THEN 1 ELSE 0 END) AS sero_count,
        SUM(CASE WHEN lt.category_id = 19 THEN bd.amount ELSE 0 END) AS clin_mic,
        SUM(CASE WHEN lt.category_id = 19 THEN 1 ELSE 0 END) AS clin_mic_count,
        SUM(CASE WHEN lt.category_id = 29 THEN bd.amount ELSE 0 END) AS histo,
        SUM(CASE WHEN lt.category_id = 29 THEN 1 ELSE 0 END) AS histo_count,
        SUM(CASE WHEN lt.category_id IN (2, 23) THEN bd.amount ELSE 0 END) AS drug_test,
        SUM(CASE WHEN lt.category_id IN (2, 23) THEN 1 ELSE 0 END) AS drug_test_count,
        SUM(CASE WHEN lt.category_id = 37 THEN bd.amount ELSE 0 END) AS ophtha,
        SUM(CASE WHEN lt.category_id = 37 THEN 1 ELSE 0 END) AS ophtha_count,
        SUM(CASE WHEN lt.category_id IN (22, 77) THEN bd.amount ELSE 0 END) AS dental,
        SUM(CASE WHEN lt.category_id IN (22, 77) THEN 1 ELSE 0 END) AS dental_count,
        SUM(CASE WHEN lt.category_id = 26 THEN bd.amount ELSE 0 END) AS ent,
        SUM(CASE WHEN lt.category_id = 26 THEN 1 ELSE 0 END) AS ent_count,
        SUM(CASE WHEN lt.category_id = 16 THEN bd.amount ELSE 0 END) AS audiometry,
        SUM(CASE WHEN lt.category_id = 16 THEN 1 ELSE 0 END) AS audiometry_count,
        SUM(CASE WHEN lt.category_id = 14 THEN bd.amount ELSE 0 END) AS abc,
        SUM(CASE WHEN lt.category_id = 14 THEN 1 ELSE 0 END) AS abc_count,
        SUM(CASE WHEN lt.category_id = 17 THEN bd.amount ELSE 0 END) AS clinic_vax,
        SUM(CASE WHEN lt.category_id = 17 THEN 1 ELSE 0 END) AS clinic_vax_count
    FROM mytemr_diagnostic.booking_details bd
    LEFT JOIN mytemr_diagnostic.booking_package_details bpd
        ON bpd.booking_detail_id = bd.id
       AND bpd.is_deleted = 0
    INNER JOIN mytemr_diagnostic.lab_test lt
        ON lt.id = CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
       AND lt.is_deleted = 0
    WHERE bd.is_deleted = 0
      AND bd.added_on >= '2026-08-01'
      AND bd.added_on < '2026-09-26'
      AND (bd.type != 'package' OR bpd.id IS NOT NULL)
    GROUP BY DATE(bd.added_on)
),
send_out_metrics AS (
    SELECT
        DATE(bd.added_on) AS d,
        SUM(bd.amount) AS send_out,
        COUNT(*) AS send_out_count
    FROM mytemr_diagnostic.booking_details bd
    WHERE bd.is_deleted = 0
      AND bd.status = 'send out'
      AND bd.added_on >= '2026-08-01'
      AND bd.added_on < '2026-09-26'
    GROUP BY DATE(bd.added_on)
)
SELECT
    2 AS branch_id,
    dates.d AS `date`,
    IFNULL(q.specialist_consult, 0) AS specialist_consult,
    IFNULL(q.total_yakap, 0) AS total_yakap,
    IFNULL(e.pcp_ape_peme, 0) AS pcp_ape_peme,
    IFNULL(q.pcp_yakap_new, 0) AS pcp_yakap_new,
    IFNULL(q.pcp_yakap_old, 0) AS pcp_yakap_old,
    IFNULL(q.pcp_consult_hmo_cash, 0) AS pcp_consult_hmo_cash,
    IFNULL(dx.xray, 0) AS xray,
    IFNULL(dx.ecg, 0) AS ecg,
    IFNULL(dx.two_d_echo, 0) AS two_d_echo,
    IFNULL(dx.tst, 0) AS tst,
    IFNULL(dx.vascular, 0) AS vascular,
    IFNULL(dx.ultrasound, 0) AS ultrasound,
    IFNULL(dx.womancare, 0) AS womancare,
    IFNULL(dx.clin_chem, 0) AS clin_chem,
    IFNULL(dx.hema, 0) AS hema,
    IFNULL(dx.immuno, 0) AS immuno,
    IFNULL(dx.sero, 0) AS sero,
    IFNULL(dx.clin_mic, 0) AS clin_mic,
    IFNULL(dx.histo, 0) AS histo,
    IFNULL(dx.drug_test, 0) AS drug_test,
    IFNULL(so.send_out, 0) AS send_out,
    IFNULL(dx.ophtha, 0) AS ophtha,
    0.00 AS opto,
    IFNULL(dx.dental, 0) AS dental,
    IFNULL(dx.ent, 0) AS ent,
    IFNULL(dx.audiometry, 0) AS audiometry,
    IFNULL(dx.abc, 0) AS abc,
    IFNULL(dx.clinic_vax, 0) AS clinic_vax,
    0.00 AS cardio_source,
    IFNULL(q.specialist_consult_count, 0) AS specialist_consult_count,
    IFNULL(q.total_yakap_count, 0) AS total_yakap_count,
    IFNULL(e.pcp_ape_peme_count, 0) AS pcp_ape_peme_count,
    IFNULL(q.pcp_yakap_new_count, 0) AS pcp_yakap_new_count,
    IFNULL(q.pcp_yakap_old_count, 0) AS pcp_yakap_old_count,
    IFNULL(q.pcp_consult_hmo_cash_count, 0) AS pcp_consult_hmo_cash_count,
    IFNULL(dx.xray_count, 0) AS xray_count,
    IFNULL(dx.ecg_count, 0) AS ecg_count,
    IFNULL(dx.two_d_echo_count, 0) AS two_d_echo_count,
    IFNULL(dx.tst_count, 0) AS tst_count,
    IFNULL(dx.vascular_count, 0) AS vascular_count,
    IFNULL(dx.ultrasound_count, 0) AS ultrasound_count,
    IFNULL(dx.womancare_count, 0) AS womancare_count,
    IFNULL(dx.clin_chem_count, 0) AS clin_chem_count,
    IFNULL(dx.hema_count, 0) AS hema_count,
    IFNULL(dx.immuno_count, 0) AS immuno_count,
    IFNULL(dx.sero_count, 0) AS sero_count,
    IFNULL(dx.clin_mic_count, 0) AS clin_mic_count,
    IFNULL(dx.histo_count, 0) AS histo_count,
    IFNULL(dx.drug_test_count, 0) AS drug_test_count,
    IFNULL(so.send_out_count, 0) AS send_out_count,
    IFNULL(dx.ophtha_count, 0) AS ophtha_count,
    0.00 AS opto_count,
    IFNULL(dx.dental_count, 0) AS dental_count,
    IFNULL(dx.ent_count, 0) AS ent_count,
    IFNULL(dx.audiometry_count, 0) AS audiometry_count,
    IFNULL(dx.abc_count, 0) AS abc_count,
    IFNULL(dx.clinic_vax_count, 0) AS clinic_vax_count,
    0.00 AS cardio_source_count,
    NOW() AS added_on,
    0 AS is_deleted
FROM dates
LEFT JOIN queue_metrics q ON q.d = dates.d
LEFT JOIN exam_metrics e ON e.d = dates.d
LEFT JOIN dx_metrics dx ON dx.d = dates.d
LEFT JOIN send_out_metrics so ON so.d = dates.d
ON DUPLICATE KEY UPDATE
    specialist_consult = VALUES(specialist_consult),
    total_yakap = VALUES(total_yakap),
    pcp_ape_peme = VALUES(pcp_ape_peme),
    pcp_yakap_new = VALUES(pcp_yakap_new),
    pcp_yakap_old = VALUES(pcp_yakap_old),
    pcp_consult_hmo_cash = VALUES(pcp_consult_hmo_cash),
    xray = VALUES(xray),
    ecg = VALUES(ecg),
    two_d_echo = VALUES(two_d_echo),
    tst = VALUES(tst),
    vascular = VALUES(vascular),
    ultrasound = VALUES(ultrasound),
    womancare = VALUES(womancare),
    clin_chem = VALUES(clin_chem),
    hema = VALUES(hema),
    immuno = VALUES(immuno),
    sero = VALUES(sero),
    clin_mic = VALUES(clin_mic),
    histo = VALUES(histo),
    drug_test = VALUES(drug_test),
    send_out = VALUES(send_out),
    ophtha = VALUES(ophtha),
    opto = VALUES(opto),
    dental = VALUES(dental),
    ent = VALUES(ent),
    audiometry = VALUES(audiometry),
    abc = VALUES(abc),
    clinic_vax = VALUES(clinic_vax),
    cardio_source = VALUES(cardio_source),
    specialist_consult_count = VALUES(specialist_consult_count),
    total_yakap_count = VALUES(total_yakap_count),
    pcp_ape_peme_count = VALUES(pcp_ape_peme_count),
    pcp_yakap_new_count = VALUES(pcp_yakap_new_count),
    pcp_yakap_old_count = VALUES(pcp_yakap_old_count),
    pcp_consult_hmo_cash_count = VALUES(pcp_consult_hmo_cash_count),
    xray_count = VALUES(xray_count),
    ecg_count = VALUES(ecg_count),
    two_d_echo_count = VALUES(two_d_echo_count),
    tst_count = VALUES(tst_count),
    vascular_count = VALUES(vascular_count),
    ultrasound_count = VALUES(ultrasound_count),
    womancare_count = VALUES(womancare_count),
    clin_chem_count = VALUES(clin_chem_count),
    hema_count = VALUES(hema_count),
    immuno_count = VALUES(immuno_count),
    sero_count = VALUES(sero_count),
    clin_mic_count = VALUES(clin_mic_count),
    histo_count = VALUES(histo_count),
    drug_test_count = VALUES(drug_test_count),
    send_out_count = VALUES(send_out_count),
    ophtha_count = VALUES(ophtha_count),
    opto_count = VALUES(opto_count),
    dental_count = VALUES(dental_count),
    ent_count = VALUES(ent_count),
    audiometry_count = VALUES(audiometry_count),
    abc_count = VALUES(abc_count),
    clinic_vax_count = VALUES(clinic_vax_count),
    cardio_source_count = VALUES(cardio_source_count),
    added_on = VALUES(added_on);

-- ---------------------------------------------------------------------------
-- Branch 3 Mactan
-- emr: mytemr_emr_mactan
-- diagnostic: mytemr_diagnostic_mactan
-- ---------------------------------------------------------------------------
INSERT INTO mytemr_reports.daily_census (
    branch_id,
    `date`,
    specialist_consult,
    total_yakap,
    pcp_ape_peme,
    pcp_yakap_new,
    pcp_yakap_old,
    pcp_consult_hmo_cash,
    xray,
    ecg,
    two_d_echo,
    tst,
    vascular,
    ultrasound,
    womancare,
    clin_chem,
    hema,
    immuno,
    sero,
    clin_mic,
    histo,
    drug_test,
    send_out,
    ophtha,
    opto,
    dental,
    ent,
    audiometry,
    abc,
    clinic_vax,
    cardio_source,
    specialist_consult_count,
    total_yakap_count,
    pcp_ape_peme_count,
    pcp_yakap_new_count,
    pcp_yakap_old_count,
    pcp_consult_hmo_cash_count,
    xray_count,
    ecg_count,
    two_d_echo_count,
    tst_count,
    vascular_count,
    ultrasound_count,
    womancare_count,
    clin_chem_count,
    hema_count,
    immuno_count,
    sero_count,
    clin_mic_count,
    histo_count,
    drug_test_count,
    send_out_count,
    ophtha_count,
    opto_count,
    dental_count,
    ent_count,
    audiometry_count,
    abc_count,
    clinic_vax_count,
    cardio_source_count,
    added_on,
    is_deleted
)
WITH RECURSIVE dates AS (
    SELECT DATE('2026-08-01') AS d
    UNION ALL
    SELECT d + INTERVAL 1 DAY FROM dates WHERE d < DATE('2026-09-24')
),
doc_flags AS (
    SELECT
        d.id,
        MAX(CASE WHEN s.prefix = 'SP' THEN 1 ELSE 0 END) AS is_sp,
        MAX(CASE WHEN s.name = 'PCP' THEN 1 ELSE 0 END) AS is_pcp
    FROM mytemr_master.doctor d
    INNER JOIN mytemr_master.specialization s
        ON s.is_deleted = 0
       AND FIND_IN_SET(s.id, d.specializations)
    WHERE d.is_deleted = 0
    GROUP BY d.id
),
queue_metrics AS (
    SELECT
        DATE(q.added_on) AS d,
        SUM(CASE WHEN doc.is_sp = 1 THEN IFNULL(t.grand_total, 0) ELSE 0 END) AS specialist_consult,
        SUM(CASE WHEN doc.is_sp = 1 THEN 1 ELSE 0 END) AS specialist_consult_count,
        SUM(CASE WHEN doc.is_pcp = 1 AND q.assessment_type = 'ekonsulta' THEN IFNULL(t.grand_total, 0) ELSE 0 END) AS total_yakap,
        SUM(CASE WHEN doc.is_pcp = 1 AND q.assessment_type = 'ekonsulta' THEN 1 ELSE 0 END) AS total_yakap_count,
        SUM(CASE WHEN doc.is_pcp = 1 AND q.assessment_type = 'ekonsulta' AND fs.first_on = DATE(q.added_on) THEN IFNULL(t.grand_total, 0) ELSE 0 END) AS pcp_yakap_new,
        SUM(CASE WHEN doc.is_pcp = 1 AND q.assessment_type = 'ekonsulta' AND fs.first_on = DATE(q.added_on) THEN 1 ELSE 0 END) AS pcp_yakap_new_count,
        SUM(CASE WHEN doc.is_pcp = 1 AND q.assessment_type = 'ekonsulta' AND fs.first_on < DATE(q.added_on) THEN IFNULL(t.grand_total, 0) ELSE 0 END) AS pcp_yakap_old,
        SUM(CASE WHEN doc.is_pcp = 1 AND q.assessment_type = 'ekonsulta' AND fs.first_on < DATE(q.added_on) THEN 1 ELSE 0 END) AS pcp_yakap_old_count,
        SUM(CASE WHEN doc.is_pcp = 1 AND q.assessment_type <> 'ekonsulta' THEN IFNULL(t.grand_total, 0) ELSE 0 END) AS pcp_consult_hmo_cash,
        SUM(CASE WHEN doc.is_pcp = 1 AND q.assessment_type <> 'ekonsulta' THEN 1 ELSE 0 END) AS pcp_consult_hmo_cash_count
    FROM mytemr_emr_mactan.queue q
    LEFT JOIN mytemr_diagnostic_mactan.transaction t
        ON t.id = q.transaction_id
    INNER JOIN doc_flags doc
        ON doc.id = q.doctor_id
    LEFT JOIN (
        SELECT doctor_id, MIN(DATE(added_on)) AS first_on
        FROM mytemr_emr_mactan.queue
        WHERE is_deleted = 0
          AND doctor_id IS NOT NULL
        GROUP BY doctor_id
    ) fs
        ON fs.doctor_id = q.doctor_id
    WHERE q.status = 'finished'
      AND q.is_deleted = 0
      AND q.added_on >= '2026-08-01'
      AND q.added_on < '2026-09-26'
      AND (doc.is_sp = 1 OR doc.is_pcp = 1)
    GROUP BY DATE(q.added_on)
),
exam_metrics AS (
    SELECT
        DATE(pe.added_on) AS d,
        SUM(IFNULL(t.grand_total, 0)) AS pcp_ape_peme,
        COUNT(*) AS pcp_ape_peme_count
    FROM mytemr_emr_mactan.physical_exam pe
    LEFT JOIN mytemr_diagnostic_mactan.transaction t
        ON t.id = pe.transaction_id
    INNER JOIN doc_flags doc
        ON doc.id = pe.doctor_id
       AND doc.is_pcp = 1
    WHERE pe.is_deleted = 0
      AND pe.added_on >= '2026-08-01'
      AND pe.added_on < '2026-09-26'
    GROUP BY DATE(pe.added_on)
),
dx_metrics AS (
    SELECT
        DATE(bd.added_on) AS d,
        SUM(CASE WHEN lt.category_id = 43 THEN bd.amount ELSE 0 END) AS xray,
        SUM(CASE WHEN lt.category_id = 43 THEN 1 ELSE 0 END) AS xray_count,
        SUM(CASE WHEN lt.category_id = 24 THEN bd.amount ELSE 0 END) AS ecg,
        SUM(CASE WHEN lt.category_id = 24 THEN 1 ELSE 0 END) AS ecg_count,
        SUM(CASE WHEN lt.category_id = 25 THEN bd.amount ELSE 0 END) AS two_d_echo,
        SUM(CASE WHEN lt.category_id = 25 THEN 1 ELSE 0 END) AS two_d_echo_count,
        SUM(CASE WHEN lt.category_id = 41 THEN bd.amount ELSE 0 END) AS tst,
        SUM(CASE WHEN lt.category_id = 41 THEN 1 ELSE 0 END) AS tst_count,
        SUM(CASE WHEN lt.category_id = 39 THEN bd.amount ELSE 0 END) AS vascular,
        SUM(CASE WHEN lt.category_id = 39 THEN 1 ELSE 0 END) AS vascular_count,
        SUM(CASE WHEN lt.category_id = 27 THEN bd.amount ELSE 0 END) AS ultrasound,
        SUM(CASE WHEN lt.category_id = 27 THEN 1 ELSE 0 END) AS ultrasound_count,
        SUM(CASE WHEN lt.category_id = 42 THEN bd.amount ELSE 0 END) AS womancare,
        SUM(CASE WHEN lt.category_id = 42 THEN 1 ELSE 0 END) AS womancare_count,
        SUM(CASE WHEN lt.category_id = 18 THEN bd.amount ELSE 0 END) AS clin_chem,
        SUM(CASE WHEN lt.category_id = 18 THEN 1 ELSE 0 END) AS clin_chem_count,
        SUM(CASE WHEN lt.category_id = 18 THEN bd.amount ELSE 0 END) AS hema,
        SUM(CASE WHEN lt.category_id = 18 THEN 1 ELSE 0 END) AS hema_count,
        SUM(CASE WHEN lt.category_id = 31 THEN bd.amount ELSE 0 END) AS immuno,
        SUM(CASE WHEN lt.category_id = 31 THEN 1 ELSE 0 END) AS immuno_count,
        SUM(CASE WHEN lt.category_id = 31 THEN bd.amount ELSE 0 END) AS sero,
        SUM(CASE WHEN lt.category_id = 31 THEN 1 ELSE 0 END) AS sero_count,
        SUM(CASE WHEN lt.category_id = 19 THEN bd.amount ELSE 0 END) AS clin_mic,
        SUM(CASE WHEN lt.category_id = 19 THEN 1 ELSE 0 END) AS clin_mic_count,
        SUM(CASE WHEN lt.category_id = 29 THEN bd.amount ELSE 0 END) AS histo,
        SUM(CASE WHEN lt.category_id = 29 THEN 1 ELSE 0 END) AS histo_count,
        SUM(CASE WHEN lt.category_id IN (2, 23) THEN bd.amount ELSE 0 END) AS drug_test,
        SUM(CASE WHEN lt.category_id IN (2, 23) THEN 1 ELSE 0 END) AS drug_test_count,
        SUM(CASE WHEN lt.category_id = 37 THEN bd.amount ELSE 0 END) AS ophtha,
        SUM(CASE WHEN lt.category_id = 37 THEN 1 ELSE 0 END) AS ophtha_count,
        SUM(CASE WHEN lt.category_id IN (22, 77) THEN bd.amount ELSE 0 END) AS dental,
        SUM(CASE WHEN lt.category_id IN (22, 77) THEN 1 ELSE 0 END) AS dental_count,
        SUM(CASE WHEN lt.category_id = 26 THEN bd.amount ELSE 0 END) AS ent,
        SUM(CASE WHEN lt.category_id = 26 THEN 1 ELSE 0 END) AS ent_count,
        SUM(CASE WHEN lt.category_id = 16 THEN bd.amount ELSE 0 END) AS audiometry,
        SUM(CASE WHEN lt.category_id = 16 THEN 1 ELSE 0 END) AS audiometry_count,
        SUM(CASE WHEN lt.category_id = 14 THEN bd.amount ELSE 0 END) AS abc,
        SUM(CASE WHEN lt.category_id = 14 THEN 1 ELSE 0 END) AS abc_count,
        SUM(CASE WHEN lt.category_id = 17 THEN bd.amount ELSE 0 END) AS clinic_vax,
        SUM(CASE WHEN lt.category_id = 17 THEN 1 ELSE 0 END) AS clinic_vax_count
    FROM mytemr_diagnostic_mactan.booking_details bd
    LEFT JOIN mytemr_diagnostic_mactan.booking_package_details bpd
        ON bpd.booking_detail_id = bd.id
       AND bpd.is_deleted = 0
    INNER JOIN mytemr_diagnostic_mactan.lab_test lt
        ON lt.id = CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
       AND lt.is_deleted = 0
    WHERE bd.is_deleted = 0
      AND bd.added_on >= '2026-08-01'
      AND bd.added_on < '2026-09-26'
      AND (bd.type != 'package' OR bpd.id IS NOT NULL)
    GROUP BY DATE(bd.added_on)
),
send_out_metrics AS (
    SELECT
        DATE(bd.added_on) AS d,
        SUM(bd.amount) AS send_out,
        COUNT(*) AS send_out_count
    FROM mytemr_diagnostic_mactan.booking_details bd
    WHERE bd.is_deleted = 0
      AND bd.status = 'send out'
      AND bd.added_on >= '2026-08-01'
      AND bd.added_on < '2026-09-26'
    GROUP BY DATE(bd.added_on)
)
SELECT
    3 AS branch_id,
    dates.d AS `date`,
    IFNULL(q.specialist_consult, 0) AS specialist_consult,
    IFNULL(q.total_yakap, 0) AS total_yakap,
    IFNULL(e.pcp_ape_peme, 0) AS pcp_ape_peme,
    IFNULL(q.pcp_yakap_new, 0) AS pcp_yakap_new,
    IFNULL(q.pcp_yakap_old, 0) AS pcp_yakap_old,
    IFNULL(q.pcp_consult_hmo_cash, 0) AS pcp_consult_hmo_cash,
    IFNULL(dx.xray, 0) AS xray,
    IFNULL(dx.ecg, 0) AS ecg,
    IFNULL(dx.two_d_echo, 0) AS two_d_echo,
    IFNULL(dx.tst, 0) AS tst,
    IFNULL(dx.vascular, 0) AS vascular,
    IFNULL(dx.ultrasound, 0) AS ultrasound,
    IFNULL(dx.womancare, 0) AS womancare,
    IFNULL(dx.clin_chem, 0) AS clin_chem,
    IFNULL(dx.hema, 0) AS hema,
    IFNULL(dx.immuno, 0) AS immuno,
    IFNULL(dx.sero, 0) AS sero,
    IFNULL(dx.clin_mic, 0) AS clin_mic,
    IFNULL(dx.histo, 0) AS histo,
    IFNULL(dx.drug_test, 0) AS drug_test,
    IFNULL(so.send_out, 0) AS send_out,
    IFNULL(dx.ophtha, 0) AS ophtha,
    0.00 AS opto,
    IFNULL(dx.dental, 0) AS dental,
    IFNULL(dx.ent, 0) AS ent,
    IFNULL(dx.audiometry, 0) AS audiometry,
    IFNULL(dx.abc, 0) AS abc,
    IFNULL(dx.clinic_vax, 0) AS clinic_vax,
    0.00 AS cardio_source,
    IFNULL(q.specialist_consult_count, 0) AS specialist_consult_count,
    IFNULL(q.total_yakap_count, 0) AS total_yakap_count,
    IFNULL(e.pcp_ape_peme_count, 0) AS pcp_ape_peme_count,
    IFNULL(q.pcp_yakap_new_count, 0) AS pcp_yakap_new_count,
    IFNULL(q.pcp_yakap_old_count, 0) AS pcp_yakap_old_count,
    IFNULL(q.pcp_consult_hmo_cash_count, 0) AS pcp_consult_hmo_cash_count,
    IFNULL(dx.xray_count, 0) AS xray_count,
    IFNULL(dx.ecg_count, 0) AS ecg_count,
    IFNULL(dx.two_d_echo_count, 0) AS two_d_echo_count,
    IFNULL(dx.tst_count, 0) AS tst_count,
    IFNULL(dx.vascular_count, 0) AS vascular_count,
    IFNULL(dx.ultrasound_count, 0) AS ultrasound_count,
    IFNULL(dx.womancare_count, 0) AS womancare_count,
    IFNULL(dx.clin_chem_count, 0) AS clin_chem_count,
    IFNULL(dx.hema_count, 0) AS hema_count,
    IFNULL(dx.immuno_count, 0) AS immuno_count,
    IFNULL(dx.sero_count, 0) AS sero_count,
    IFNULL(dx.clin_mic_count, 0) AS clin_mic_count,
    IFNULL(dx.histo_count, 0) AS histo_count,
    IFNULL(dx.drug_test_count, 0) AS drug_test_count,
    IFNULL(so.send_out_count, 0) AS send_out_count,
    IFNULL(dx.ophtha_count, 0) AS ophtha_count,
    0.00 AS opto_count,
    IFNULL(dx.dental_count, 0) AS dental_count,
    IFNULL(dx.ent_count, 0) AS ent_count,
    IFNULL(dx.audiometry_count, 0) AS audiometry_count,
    IFNULL(dx.abc_count, 0) AS abc_count,
    IFNULL(dx.clinic_vax_count, 0) AS clinic_vax_count,
    0.00 AS cardio_source_count,
    NOW() AS added_on,
    0 AS is_deleted
FROM dates
LEFT JOIN queue_metrics q ON q.d = dates.d
LEFT JOIN exam_metrics e ON e.d = dates.d
LEFT JOIN dx_metrics dx ON dx.d = dates.d
LEFT JOIN send_out_metrics so ON so.d = dates.d
ON DUPLICATE KEY UPDATE
    specialist_consult = VALUES(specialist_consult),
    total_yakap = VALUES(total_yakap),
    pcp_ape_peme = VALUES(pcp_ape_peme),
    pcp_yakap_new = VALUES(pcp_yakap_new),
    pcp_yakap_old = VALUES(pcp_yakap_old),
    pcp_consult_hmo_cash = VALUES(pcp_consult_hmo_cash),
    xray = VALUES(xray),
    ecg = VALUES(ecg),
    two_d_echo = VALUES(two_d_echo),
    tst = VALUES(tst),
    vascular = VALUES(vascular),
    ultrasound = VALUES(ultrasound),
    womancare = VALUES(womancare),
    clin_chem = VALUES(clin_chem),
    hema = VALUES(hema),
    immuno = VALUES(immuno),
    sero = VALUES(sero),
    clin_mic = VALUES(clin_mic),
    histo = VALUES(histo),
    drug_test = VALUES(drug_test),
    send_out = VALUES(send_out),
    ophtha = VALUES(ophtha),
    opto = VALUES(opto),
    dental = VALUES(dental),
    ent = VALUES(ent),
    audiometry = VALUES(audiometry),
    abc = VALUES(abc),
    clinic_vax = VALUES(clinic_vax),
    cardio_source = VALUES(cardio_source),
    specialist_consult_count = VALUES(specialist_consult_count),
    total_yakap_count = VALUES(total_yakap_count),
    pcp_ape_peme_count = VALUES(pcp_ape_peme_count),
    pcp_yakap_new_count = VALUES(pcp_yakap_new_count),
    pcp_yakap_old_count = VALUES(pcp_yakap_old_count),
    pcp_consult_hmo_cash_count = VALUES(pcp_consult_hmo_cash_count),
    xray_count = VALUES(xray_count),
    ecg_count = VALUES(ecg_count),
    two_d_echo_count = VALUES(two_d_echo_count),
    tst_count = VALUES(tst_count),
    vascular_count = VALUES(vascular_count),
    ultrasound_count = VALUES(ultrasound_count),
    womancare_count = VALUES(womancare_count),
    clin_chem_count = VALUES(clin_chem_count),
    hema_count = VALUES(hema_count),
    immuno_count = VALUES(immuno_count),
    sero_count = VALUES(sero_count),
    clin_mic_count = VALUES(clin_mic_count),
    histo_count = VALUES(histo_count),
    drug_test_count = VALUES(drug_test_count),
    send_out_count = VALUES(send_out_count),
    ophtha_count = VALUES(ophtha_count),
    opto_count = VALUES(opto_count),
    dental_count = VALUES(dental_count),
    ent_count = VALUES(ent_count),
    audiometry_count = VALUES(audiometry_count),
    abc_count = VALUES(abc_count),
    clinic_vax_count = VALUES(clinic_vax_count),
    cardio_source_count = VALUES(cardio_source_count),
    added_on = VALUES(added_on);

-- ---------------------------------------------------------------------------
-- Branch 4 Consolacion
-- emr: mytemr_emr_conso
-- diagnostic: mytemr_diagnostic_conso
-- ---------------------------------------------------------------------------
INSERT INTO mytemr_reports.daily_census (
    branch_id,
    `date`,
    specialist_consult,
    total_yakap,
    pcp_ape_peme,
    pcp_yakap_new,
    pcp_yakap_old,
    pcp_consult_hmo_cash,
    xray,
    ecg,
    two_d_echo,
    tst,
    vascular,
    ultrasound,
    womancare,
    clin_chem,
    hema,
    immuno,
    sero,
    clin_mic,
    histo,
    drug_test,
    send_out,
    ophtha,
    opto,
    dental,
    ent,
    audiometry,
    abc,
    clinic_vax,
    cardio_source,
    specialist_consult_count,
    total_yakap_count,
    pcp_ape_peme_count,
    pcp_yakap_new_count,
    pcp_yakap_old_count,
    pcp_consult_hmo_cash_count,
    xray_count,
    ecg_count,
    two_d_echo_count,
    tst_count,
    vascular_count,
    ultrasound_count,
    womancare_count,
    clin_chem_count,
    hema_count,
    immuno_count,
    sero_count,
    clin_mic_count,
    histo_count,
    drug_test_count,
    send_out_count,
    ophtha_count,
    opto_count,
    dental_count,
    ent_count,
    audiometry_count,
    abc_count,
    clinic_vax_count,
    cardio_source_count,
    added_on,
    is_deleted
)
WITH RECURSIVE dates AS (
    SELECT DATE('2026-08-01') AS d
    UNION ALL
    SELECT d + INTERVAL 1 DAY FROM dates WHERE d < DATE('2026-09-24')
),
doc_flags AS (
    SELECT
        d.id,
        MAX(CASE WHEN s.prefix = 'SP' THEN 1 ELSE 0 END) AS is_sp,
        MAX(CASE WHEN s.name = 'PCP' THEN 1 ELSE 0 END) AS is_pcp
    FROM mytemr_master.doctor d
    INNER JOIN mytemr_master.specialization s
        ON s.is_deleted = 0
       AND FIND_IN_SET(s.id, d.specializations)
    WHERE d.is_deleted = 0
    GROUP BY d.id
),
queue_metrics AS (
    SELECT
        DATE(q.added_on) AS d,
        SUM(CASE WHEN doc.is_sp = 1 THEN IFNULL(t.grand_total, 0) ELSE 0 END) AS specialist_consult,
        SUM(CASE WHEN doc.is_sp = 1 THEN 1 ELSE 0 END) AS specialist_consult_count,
        SUM(CASE WHEN doc.is_pcp = 1 AND q.assessment_type = 'ekonsulta' THEN IFNULL(t.grand_total, 0) ELSE 0 END) AS total_yakap,
        SUM(CASE WHEN doc.is_pcp = 1 AND q.assessment_type = 'ekonsulta' THEN 1 ELSE 0 END) AS total_yakap_count,
        SUM(CASE WHEN doc.is_pcp = 1 AND q.assessment_type = 'ekonsulta' AND fs.first_on = DATE(q.added_on) THEN IFNULL(t.grand_total, 0) ELSE 0 END) AS pcp_yakap_new,
        SUM(CASE WHEN doc.is_pcp = 1 AND q.assessment_type = 'ekonsulta' AND fs.first_on = DATE(q.added_on) THEN 1 ELSE 0 END) AS pcp_yakap_new_count,
        SUM(CASE WHEN doc.is_pcp = 1 AND q.assessment_type = 'ekonsulta' AND fs.first_on < DATE(q.added_on) THEN IFNULL(t.grand_total, 0) ELSE 0 END) AS pcp_yakap_old,
        SUM(CASE WHEN doc.is_pcp = 1 AND q.assessment_type = 'ekonsulta' AND fs.first_on < DATE(q.added_on) THEN 1 ELSE 0 END) AS pcp_yakap_old_count,
        SUM(CASE WHEN doc.is_pcp = 1 AND q.assessment_type <> 'ekonsulta' THEN IFNULL(t.grand_total, 0) ELSE 0 END) AS pcp_consult_hmo_cash,
        SUM(CASE WHEN doc.is_pcp = 1 AND q.assessment_type <> 'ekonsulta' THEN 1 ELSE 0 END) AS pcp_consult_hmo_cash_count
    FROM mytemr_emr_conso.queue q
    LEFT JOIN mytemr_diagnostic_conso.transaction t
        ON t.id = q.transaction_id
    INNER JOIN doc_flags doc
        ON doc.id = q.doctor_id
    LEFT JOIN (
        SELECT doctor_id, MIN(DATE(added_on)) AS first_on
        FROM mytemr_emr_conso.queue
        WHERE is_deleted = 0
          AND doctor_id IS NOT NULL
        GROUP BY doctor_id
    ) fs
        ON fs.doctor_id = q.doctor_id
    WHERE q.status = 'finished'
      AND q.is_deleted = 0
      AND q.added_on >= '2026-08-01'
      AND q.added_on < '2026-09-26'
      AND (doc.is_sp = 1 OR doc.is_pcp = 1)
    GROUP BY DATE(q.added_on)
),
exam_metrics AS (
    SELECT
        DATE(pe.added_on) AS d,
        SUM(IFNULL(t.grand_total, 0)) AS pcp_ape_peme,
        COUNT(*) AS pcp_ape_peme_count
    FROM mytemr_emr_conso.physical_exam pe
    LEFT JOIN mytemr_diagnostic_conso.transaction t
        ON t.id = pe.transaction_id
    INNER JOIN doc_flags doc
        ON doc.id = pe.doctor_id
       AND doc.is_pcp = 1
    WHERE pe.is_deleted = 0
      AND pe.added_on >= '2026-08-01'
      AND pe.added_on < '2026-09-26'
    GROUP BY DATE(pe.added_on)
),
dx_metrics AS (
    SELECT
        DATE(bd.added_on) AS d,
        SUM(CASE WHEN lt.category_id = 43 THEN bd.amount ELSE 0 END) AS xray,
        SUM(CASE WHEN lt.category_id = 43 THEN 1 ELSE 0 END) AS xray_count,
        SUM(CASE WHEN lt.category_id = 24 THEN bd.amount ELSE 0 END) AS ecg,
        SUM(CASE WHEN lt.category_id = 24 THEN 1 ELSE 0 END) AS ecg_count,
        SUM(CASE WHEN lt.category_id = 25 THEN bd.amount ELSE 0 END) AS two_d_echo,
        SUM(CASE WHEN lt.category_id = 25 THEN 1 ELSE 0 END) AS two_d_echo_count,
        SUM(CASE WHEN lt.category_id = 41 THEN bd.amount ELSE 0 END) AS tst,
        SUM(CASE WHEN lt.category_id = 41 THEN 1 ELSE 0 END) AS tst_count,
        SUM(CASE WHEN lt.category_id = 39 THEN bd.amount ELSE 0 END) AS vascular,
        SUM(CASE WHEN lt.category_id = 39 THEN 1 ELSE 0 END) AS vascular_count,
        SUM(CASE WHEN lt.category_id = 27 THEN bd.amount ELSE 0 END) AS ultrasound,
        SUM(CASE WHEN lt.category_id = 27 THEN 1 ELSE 0 END) AS ultrasound_count,
        SUM(CASE WHEN lt.category_id = 42 THEN bd.amount ELSE 0 END) AS womancare,
        SUM(CASE WHEN lt.category_id = 42 THEN 1 ELSE 0 END) AS womancare_count,
        SUM(CASE WHEN lt.category_id = 18 THEN bd.amount ELSE 0 END) AS clin_chem,
        SUM(CASE WHEN lt.category_id = 18 THEN 1 ELSE 0 END) AS clin_chem_count,
        SUM(CASE WHEN lt.category_id = 18 THEN bd.amount ELSE 0 END) AS hema,
        SUM(CASE WHEN lt.category_id = 18 THEN 1 ELSE 0 END) AS hema_count,
        SUM(CASE WHEN lt.category_id = 31 THEN bd.amount ELSE 0 END) AS immuno,
        SUM(CASE WHEN lt.category_id = 31 THEN 1 ELSE 0 END) AS immuno_count,
        SUM(CASE WHEN lt.category_id = 31 THEN bd.amount ELSE 0 END) AS sero,
        SUM(CASE WHEN lt.category_id = 31 THEN 1 ELSE 0 END) AS sero_count,
        SUM(CASE WHEN lt.category_id = 19 THEN bd.amount ELSE 0 END) AS clin_mic,
        SUM(CASE WHEN lt.category_id = 19 THEN 1 ELSE 0 END) AS clin_mic_count,
        SUM(CASE WHEN lt.category_id = 29 THEN bd.amount ELSE 0 END) AS histo,
        SUM(CASE WHEN lt.category_id = 29 THEN 1 ELSE 0 END) AS histo_count,
        SUM(CASE WHEN lt.category_id IN (2, 23) THEN bd.amount ELSE 0 END) AS drug_test,
        SUM(CASE WHEN lt.category_id IN (2, 23) THEN 1 ELSE 0 END) AS drug_test_count,
        SUM(CASE WHEN lt.category_id = 37 THEN bd.amount ELSE 0 END) AS ophtha,
        SUM(CASE WHEN lt.category_id = 37 THEN 1 ELSE 0 END) AS ophtha_count,
        SUM(CASE WHEN lt.category_id IN (22, 77) THEN bd.amount ELSE 0 END) AS dental,
        SUM(CASE WHEN lt.category_id IN (22, 77) THEN 1 ELSE 0 END) AS dental_count,
        SUM(CASE WHEN lt.category_id = 26 THEN bd.amount ELSE 0 END) AS ent,
        SUM(CASE WHEN lt.category_id = 26 THEN 1 ELSE 0 END) AS ent_count,
        SUM(CASE WHEN lt.category_id = 16 THEN bd.amount ELSE 0 END) AS audiometry,
        SUM(CASE WHEN lt.category_id = 16 THEN 1 ELSE 0 END) AS audiometry_count,
        SUM(CASE WHEN lt.category_id = 14 THEN bd.amount ELSE 0 END) AS abc,
        SUM(CASE WHEN lt.category_id = 14 THEN 1 ELSE 0 END) AS abc_count,
        SUM(CASE WHEN lt.category_id = 17 THEN bd.amount ELSE 0 END) AS clinic_vax,
        SUM(CASE WHEN lt.category_id = 17 THEN 1 ELSE 0 END) AS clinic_vax_count
    FROM mytemr_diagnostic_conso.booking_details bd
    LEFT JOIN mytemr_diagnostic_conso.booking_package_details bpd
        ON bpd.booking_detail_id = bd.id
       AND bpd.is_deleted = 0
    INNER JOIN mytemr_diagnostic_conso.lab_test lt
        ON lt.id = CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
       AND lt.is_deleted = 0
    WHERE bd.is_deleted = 0
      AND bd.added_on >= '2026-08-01'
      AND bd.added_on < '2026-09-26'
      AND (bd.type != 'package' OR bpd.id IS NOT NULL)
    GROUP BY DATE(bd.added_on)
),
send_out_metrics AS (
    SELECT
        DATE(bd.added_on) AS d,
        SUM(bd.amount) AS send_out,
        COUNT(*) AS send_out_count
    FROM mytemr_diagnostic_conso.booking_details bd
    WHERE bd.is_deleted = 0
      AND bd.status = 'send out'
      AND bd.added_on >= '2026-08-01'
      AND bd.added_on < '2026-09-26'
    GROUP BY DATE(bd.added_on)
)
SELECT
    4 AS branch_id,
    dates.d AS `date`,
    IFNULL(q.specialist_consult, 0) AS specialist_consult,
    IFNULL(q.total_yakap, 0) AS total_yakap,
    IFNULL(e.pcp_ape_peme, 0) AS pcp_ape_peme,
    IFNULL(q.pcp_yakap_new, 0) AS pcp_yakap_new,
    IFNULL(q.pcp_yakap_old, 0) AS pcp_yakap_old,
    IFNULL(q.pcp_consult_hmo_cash, 0) AS pcp_consult_hmo_cash,
    IFNULL(dx.xray, 0) AS xray,
    IFNULL(dx.ecg, 0) AS ecg,
    IFNULL(dx.two_d_echo, 0) AS two_d_echo,
    IFNULL(dx.tst, 0) AS tst,
    IFNULL(dx.vascular, 0) AS vascular,
    IFNULL(dx.ultrasound, 0) AS ultrasound,
    IFNULL(dx.womancare, 0) AS womancare,
    IFNULL(dx.clin_chem, 0) AS clin_chem,
    IFNULL(dx.hema, 0) AS hema,
    IFNULL(dx.immuno, 0) AS immuno,
    IFNULL(dx.sero, 0) AS sero,
    IFNULL(dx.clin_mic, 0) AS clin_mic,
    IFNULL(dx.histo, 0) AS histo,
    IFNULL(dx.drug_test, 0) AS drug_test,
    IFNULL(so.send_out, 0) AS send_out,
    IFNULL(dx.ophtha, 0) AS ophtha,
    0.00 AS opto,
    IFNULL(dx.dental, 0) AS dental,
    IFNULL(dx.ent, 0) AS ent,
    IFNULL(dx.audiometry, 0) AS audiometry,
    IFNULL(dx.abc, 0) AS abc,
    IFNULL(dx.clinic_vax, 0) AS clinic_vax,
    0.00 AS cardio_source,
    IFNULL(q.specialist_consult_count, 0) AS specialist_consult_count,
    IFNULL(q.total_yakap_count, 0) AS total_yakap_count,
    IFNULL(e.pcp_ape_peme_count, 0) AS pcp_ape_peme_count,
    IFNULL(q.pcp_yakap_new_count, 0) AS pcp_yakap_new_count,
    IFNULL(q.pcp_yakap_old_count, 0) AS pcp_yakap_old_count,
    IFNULL(q.pcp_consult_hmo_cash_count, 0) AS pcp_consult_hmo_cash_count,
    IFNULL(dx.xray_count, 0) AS xray_count,
    IFNULL(dx.ecg_count, 0) AS ecg_count,
    IFNULL(dx.two_d_echo_count, 0) AS two_d_echo_count,
    IFNULL(dx.tst_count, 0) AS tst_count,
    IFNULL(dx.vascular_count, 0) AS vascular_count,
    IFNULL(dx.ultrasound_count, 0) AS ultrasound_count,
    IFNULL(dx.womancare_count, 0) AS womancare_count,
    IFNULL(dx.clin_chem_count, 0) AS clin_chem_count,
    IFNULL(dx.hema_count, 0) AS hema_count,
    IFNULL(dx.immuno_count, 0) AS immuno_count,
    IFNULL(dx.sero_count, 0) AS sero_count,
    IFNULL(dx.clin_mic_count, 0) AS clin_mic_count,
    IFNULL(dx.histo_count, 0) AS histo_count,
    IFNULL(dx.drug_test_count, 0) AS drug_test_count,
    IFNULL(so.send_out_count, 0) AS send_out_count,
    IFNULL(dx.ophtha_count, 0) AS ophtha_count,
    0.00 AS opto_count,
    IFNULL(dx.dental_count, 0) AS dental_count,
    IFNULL(dx.ent_count, 0) AS ent_count,
    IFNULL(dx.audiometry_count, 0) AS audiometry_count,
    IFNULL(dx.abc_count, 0) AS abc_count,
    IFNULL(dx.clinic_vax_count, 0) AS clinic_vax_count,
    0.00 AS cardio_source_count,
    NOW() AS added_on,
    0 AS is_deleted
FROM dates
LEFT JOIN queue_metrics q ON q.d = dates.d
LEFT JOIN exam_metrics e ON e.d = dates.d
LEFT JOIN dx_metrics dx ON dx.d = dates.d
LEFT JOIN send_out_metrics so ON so.d = dates.d
ON DUPLICATE KEY UPDATE
    specialist_consult = VALUES(specialist_consult),
    total_yakap = VALUES(total_yakap),
    pcp_ape_peme = VALUES(pcp_ape_peme),
    pcp_yakap_new = VALUES(pcp_yakap_new),
    pcp_yakap_old = VALUES(pcp_yakap_old),
    pcp_consult_hmo_cash = VALUES(pcp_consult_hmo_cash),
    xray = VALUES(xray),
    ecg = VALUES(ecg),
    two_d_echo = VALUES(two_d_echo),
    tst = VALUES(tst),
    vascular = VALUES(vascular),
    ultrasound = VALUES(ultrasound),
    womancare = VALUES(womancare),
    clin_chem = VALUES(clin_chem),
    hema = VALUES(hema),
    immuno = VALUES(immuno),
    sero = VALUES(sero),
    clin_mic = VALUES(clin_mic),
    histo = VALUES(histo),
    drug_test = VALUES(drug_test),
    send_out = VALUES(send_out),
    ophtha = VALUES(ophtha),
    opto = VALUES(opto),
    dental = VALUES(dental),
    ent = VALUES(ent),
    audiometry = VALUES(audiometry),
    abc = VALUES(abc),
    clinic_vax = VALUES(clinic_vax),
    cardio_source = VALUES(cardio_source),
    specialist_consult_count = VALUES(specialist_consult_count),
    total_yakap_count = VALUES(total_yakap_count),
    pcp_ape_peme_count = VALUES(pcp_ape_peme_count),
    pcp_yakap_new_count = VALUES(pcp_yakap_new_count),
    pcp_yakap_old_count = VALUES(pcp_yakap_old_count),
    pcp_consult_hmo_cash_count = VALUES(pcp_consult_hmo_cash_count),
    xray_count = VALUES(xray_count),
    ecg_count = VALUES(ecg_count),
    two_d_echo_count = VALUES(two_d_echo_count),
    tst_count = VALUES(tst_count),
    vascular_count = VALUES(vascular_count),
    ultrasound_count = VALUES(ultrasound_count),
    womancare_count = VALUES(womancare_count),
    clin_chem_count = VALUES(clin_chem_count),
    hema_count = VALUES(hema_count),
    immuno_count = VALUES(immuno_count),
    sero_count = VALUES(sero_count),
    clin_mic_count = VALUES(clin_mic_count),
    histo_count = VALUES(histo_count),
    drug_test_count = VALUES(drug_test_count),
    send_out_count = VALUES(send_out_count),
    ophtha_count = VALUES(ophtha_count),
    opto_count = VALUES(opto_count),
    dental_count = VALUES(dental_count),
    ent_count = VALUES(ent_count),
    audiometry_count = VALUES(audiometry_count),
    abc_count = VALUES(abc_count),
    clinic_vax_count = VALUES(clinic_vax_count),
    cardio_source_count = VALUES(cardio_source_count),
    added_on = VALUES(added_on);

-- ---------------------------------------------------------------------------
-- Branch 7 Mantlewood
-- emr: mytemr_emr_mantlewood
-- diagnostic: mytemr_diagnostic_mantlewood
-- ---------------------------------------------------------------------------
INSERT INTO mytemr_reports.daily_census (
    branch_id,
    `date`,
    specialist_consult,
    total_yakap,
    pcp_ape_peme,
    pcp_yakap_new,
    pcp_yakap_old,
    pcp_consult_hmo_cash,
    xray,
    ecg,
    two_d_echo,
    tst,
    vascular,
    ultrasound,
    womancare,
    clin_chem,
    hema,
    immuno,
    sero,
    clin_mic,
    histo,
    drug_test,
    send_out,
    ophtha,
    opto,
    dental,
    ent,
    audiometry,
    abc,
    clinic_vax,
    cardio_source,
    specialist_consult_count,
    total_yakap_count,
    pcp_ape_peme_count,
    pcp_yakap_new_count,
    pcp_yakap_old_count,
    pcp_consult_hmo_cash_count,
    xray_count,
    ecg_count,
    two_d_echo_count,
    tst_count,
    vascular_count,
    ultrasound_count,
    womancare_count,
    clin_chem_count,
    hema_count,
    immuno_count,
    sero_count,
    clin_mic_count,
    histo_count,
    drug_test_count,
    send_out_count,
    ophtha_count,
    opto_count,
    dental_count,
    ent_count,
    audiometry_count,
    abc_count,
    clinic_vax_count,
    cardio_source_count,
    added_on,
    is_deleted
)
WITH RECURSIVE dates AS (
    SELECT DATE('2026-08-01') AS d
    UNION ALL
    SELECT d + INTERVAL 1 DAY FROM dates WHERE d < DATE('2026-09-24')
),
doc_flags AS (
    SELECT
        d.id,
        MAX(CASE WHEN s.prefix = 'SP' THEN 1 ELSE 0 END) AS is_sp,
        MAX(CASE WHEN s.name = 'PCP' THEN 1 ELSE 0 END) AS is_pcp
    FROM mytemr_master.doctor d
    INNER JOIN mytemr_master.specialization s
        ON s.is_deleted = 0
       AND FIND_IN_SET(s.id, d.specializations)
    WHERE d.is_deleted = 0
    GROUP BY d.id
),
queue_metrics AS (
    SELECT
        DATE(q.added_on) AS d,
        SUM(CASE WHEN doc.is_sp = 1 THEN IFNULL(t.grand_total, 0) ELSE 0 END) AS specialist_consult,
        SUM(CASE WHEN doc.is_sp = 1 THEN 1 ELSE 0 END) AS specialist_consult_count,
        SUM(CASE WHEN doc.is_pcp = 1 AND q.assessment_type = 'ekonsulta' THEN IFNULL(t.grand_total, 0) ELSE 0 END) AS total_yakap,
        SUM(CASE WHEN doc.is_pcp = 1 AND q.assessment_type = 'ekonsulta' THEN 1 ELSE 0 END) AS total_yakap_count,
        SUM(CASE WHEN doc.is_pcp = 1 AND q.assessment_type = 'ekonsulta' AND fs.first_on = DATE(q.added_on) THEN IFNULL(t.grand_total, 0) ELSE 0 END) AS pcp_yakap_new,
        SUM(CASE WHEN doc.is_pcp = 1 AND q.assessment_type = 'ekonsulta' AND fs.first_on = DATE(q.added_on) THEN 1 ELSE 0 END) AS pcp_yakap_new_count,
        SUM(CASE WHEN doc.is_pcp = 1 AND q.assessment_type = 'ekonsulta' AND fs.first_on < DATE(q.added_on) THEN IFNULL(t.grand_total, 0) ELSE 0 END) AS pcp_yakap_old,
        SUM(CASE WHEN doc.is_pcp = 1 AND q.assessment_type = 'ekonsulta' AND fs.first_on < DATE(q.added_on) THEN 1 ELSE 0 END) AS pcp_yakap_old_count,
        SUM(CASE WHEN doc.is_pcp = 1 AND q.assessment_type <> 'ekonsulta' THEN IFNULL(t.grand_total, 0) ELSE 0 END) AS pcp_consult_hmo_cash,
        SUM(CASE WHEN doc.is_pcp = 1 AND q.assessment_type <> 'ekonsulta' THEN 1 ELSE 0 END) AS pcp_consult_hmo_cash_count
    FROM mytemr_emr_mantlewood.queue q
    LEFT JOIN mytemr_diagnostic_mantlewood.transaction t
        ON t.id = q.transaction_id
    INNER JOIN doc_flags doc
        ON doc.id = q.doctor_id
    LEFT JOIN (
        SELECT doctor_id, MIN(DATE(added_on)) AS first_on
        FROM mytemr_emr_mantlewood.queue
        WHERE is_deleted = 0
          AND doctor_id IS NOT NULL
        GROUP BY doctor_id
    ) fs
        ON fs.doctor_id = q.doctor_id
    WHERE q.status = 'finished'
      AND q.is_deleted = 0
      AND q.added_on >= '2026-08-01'
      AND q.added_on < '2026-09-26'
      AND (doc.is_sp = 1 OR doc.is_pcp = 1)
    GROUP BY DATE(q.added_on)
),
exam_metrics AS (
    SELECT
        DATE(pe.added_on) AS d,
        SUM(IFNULL(t.grand_total, 0)) AS pcp_ape_peme,
        COUNT(*) AS pcp_ape_peme_count
    FROM mytemr_emr_mantlewood.physical_exam pe
    LEFT JOIN mytemr_diagnostic_mantlewood.transaction t
        ON t.id = pe.transaction_id
    INNER JOIN doc_flags doc
        ON doc.id = pe.doctor_id
       AND doc.is_pcp = 1
    WHERE pe.is_deleted = 0
      AND pe.added_on >= '2026-08-01'
      AND pe.added_on < '2026-09-26'
    GROUP BY DATE(pe.added_on)
),
dx_metrics AS (
    SELECT
        DATE(bd.added_on) AS d,
        SUM(CASE WHEN lt.category_id = 43 THEN bd.amount ELSE 0 END) AS xray,
        SUM(CASE WHEN lt.category_id = 43 THEN 1 ELSE 0 END) AS xray_count,
        SUM(CASE WHEN lt.category_id = 24 THEN bd.amount ELSE 0 END) AS ecg,
        SUM(CASE WHEN lt.category_id = 24 THEN 1 ELSE 0 END) AS ecg_count,
        SUM(CASE WHEN lt.category_id = 25 THEN bd.amount ELSE 0 END) AS two_d_echo,
        SUM(CASE WHEN lt.category_id = 25 THEN 1 ELSE 0 END) AS two_d_echo_count,
        SUM(CASE WHEN lt.category_id = 41 THEN bd.amount ELSE 0 END) AS tst,
        SUM(CASE WHEN lt.category_id = 41 THEN 1 ELSE 0 END) AS tst_count,
        SUM(CASE WHEN lt.category_id = 39 THEN bd.amount ELSE 0 END) AS vascular,
        SUM(CASE WHEN lt.category_id = 39 THEN 1 ELSE 0 END) AS vascular_count,
        SUM(CASE WHEN lt.category_id = 27 THEN bd.amount ELSE 0 END) AS ultrasound,
        SUM(CASE WHEN lt.category_id = 27 THEN 1 ELSE 0 END) AS ultrasound_count,
        SUM(CASE WHEN lt.category_id = 42 THEN bd.amount ELSE 0 END) AS womancare,
        SUM(CASE WHEN lt.category_id = 42 THEN 1 ELSE 0 END) AS womancare_count,
        SUM(CASE WHEN lt.category_id = 18 THEN bd.amount ELSE 0 END) AS clin_chem,
        SUM(CASE WHEN lt.category_id = 18 THEN 1 ELSE 0 END) AS clin_chem_count,
        SUM(CASE WHEN lt.category_id = 18 THEN bd.amount ELSE 0 END) AS hema,
        SUM(CASE WHEN lt.category_id = 18 THEN 1 ELSE 0 END) AS hema_count,
        SUM(CASE WHEN lt.category_id = 31 THEN bd.amount ELSE 0 END) AS immuno,
        SUM(CASE WHEN lt.category_id = 31 THEN 1 ELSE 0 END) AS immuno_count,
        SUM(CASE WHEN lt.category_id = 31 THEN bd.amount ELSE 0 END) AS sero,
        SUM(CASE WHEN lt.category_id = 31 THEN 1 ELSE 0 END) AS sero_count,
        SUM(CASE WHEN lt.category_id = 19 THEN bd.amount ELSE 0 END) AS clin_mic,
        SUM(CASE WHEN lt.category_id = 19 THEN 1 ELSE 0 END) AS clin_mic_count,
        SUM(CASE WHEN lt.category_id = 29 THEN bd.amount ELSE 0 END) AS histo,
        SUM(CASE WHEN lt.category_id = 29 THEN 1 ELSE 0 END) AS histo_count,
        SUM(CASE WHEN lt.category_id IN (2, 23) THEN bd.amount ELSE 0 END) AS drug_test,
        SUM(CASE WHEN lt.category_id IN (2, 23) THEN 1 ELSE 0 END) AS drug_test_count,
        SUM(CASE WHEN lt.category_id = 37 THEN bd.amount ELSE 0 END) AS ophtha,
        SUM(CASE WHEN lt.category_id = 37 THEN 1 ELSE 0 END) AS ophtha_count,
        SUM(CASE WHEN lt.category_id IN (22, 77) THEN bd.amount ELSE 0 END) AS dental,
        SUM(CASE WHEN lt.category_id IN (22, 77) THEN 1 ELSE 0 END) AS dental_count,
        SUM(CASE WHEN lt.category_id = 26 THEN bd.amount ELSE 0 END) AS ent,
        SUM(CASE WHEN lt.category_id = 26 THEN 1 ELSE 0 END) AS ent_count,
        SUM(CASE WHEN lt.category_id = 16 THEN bd.amount ELSE 0 END) AS audiometry,
        SUM(CASE WHEN lt.category_id = 16 THEN 1 ELSE 0 END) AS audiometry_count,
        SUM(CASE WHEN lt.category_id = 14 THEN bd.amount ELSE 0 END) AS abc,
        SUM(CASE WHEN lt.category_id = 14 THEN 1 ELSE 0 END) AS abc_count,
        SUM(CASE WHEN lt.category_id = 17 THEN bd.amount ELSE 0 END) AS clinic_vax,
        SUM(CASE WHEN lt.category_id = 17 THEN 1 ELSE 0 END) AS clinic_vax_count
    FROM mytemr_diagnostic_mantlewood.booking_details bd
    LEFT JOIN mytemr_diagnostic_mantlewood.booking_package_details bpd
        ON bpd.booking_detail_id = bd.id
       AND bpd.is_deleted = 0
    INNER JOIN mytemr_diagnostic_mantlewood.lab_test lt
        ON lt.id = CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
       AND lt.is_deleted = 0
    WHERE bd.is_deleted = 0
      AND bd.added_on >= '2026-08-01'
      AND bd.added_on < '2026-09-26'
      AND (bd.type != 'package' OR bpd.id IS NOT NULL)
    GROUP BY DATE(bd.added_on)
),
send_out_metrics AS (
    SELECT
        DATE(bd.added_on) AS d,
        SUM(bd.amount) AS send_out,
        COUNT(*) AS send_out_count
    FROM mytemr_diagnostic_mantlewood.booking_details bd
    WHERE bd.is_deleted = 0
      AND bd.status = 'send out'
      AND bd.added_on >= '2026-08-01'
      AND bd.added_on < '2026-09-26'
    GROUP BY DATE(bd.added_on)
)
SELECT
    7 AS branch_id,
    dates.d AS `date`,
    IFNULL(q.specialist_consult, 0) AS specialist_consult,
    IFNULL(q.total_yakap, 0) AS total_yakap,
    IFNULL(e.pcp_ape_peme, 0) AS pcp_ape_peme,
    IFNULL(q.pcp_yakap_new, 0) AS pcp_yakap_new,
    IFNULL(q.pcp_yakap_old, 0) AS pcp_yakap_old,
    IFNULL(q.pcp_consult_hmo_cash, 0) AS pcp_consult_hmo_cash,
    IFNULL(dx.xray, 0) AS xray,
    IFNULL(dx.ecg, 0) AS ecg,
    IFNULL(dx.two_d_echo, 0) AS two_d_echo,
    IFNULL(dx.tst, 0) AS tst,
    IFNULL(dx.vascular, 0) AS vascular,
    IFNULL(dx.ultrasound, 0) AS ultrasound,
    IFNULL(dx.womancare, 0) AS womancare,
    IFNULL(dx.clin_chem, 0) AS clin_chem,
    IFNULL(dx.hema, 0) AS hema,
    IFNULL(dx.immuno, 0) AS immuno,
    IFNULL(dx.sero, 0) AS sero,
    IFNULL(dx.clin_mic, 0) AS clin_mic,
    IFNULL(dx.histo, 0) AS histo,
    IFNULL(dx.drug_test, 0) AS drug_test,
    IFNULL(so.send_out, 0) AS send_out,
    IFNULL(dx.ophtha, 0) AS ophtha,
    0.00 AS opto,
    IFNULL(dx.dental, 0) AS dental,
    IFNULL(dx.ent, 0) AS ent,
    IFNULL(dx.audiometry, 0) AS audiometry,
    IFNULL(dx.abc, 0) AS abc,
    IFNULL(dx.clinic_vax, 0) AS clinic_vax,
    0.00 AS cardio_source,
    IFNULL(q.specialist_consult_count, 0) AS specialist_consult_count,
    IFNULL(q.total_yakap_count, 0) AS total_yakap_count,
    IFNULL(e.pcp_ape_peme_count, 0) AS pcp_ape_peme_count,
    IFNULL(q.pcp_yakap_new_count, 0) AS pcp_yakap_new_count,
    IFNULL(q.pcp_yakap_old_count, 0) AS pcp_yakap_old_count,
    IFNULL(q.pcp_consult_hmo_cash_count, 0) AS pcp_consult_hmo_cash_count,
    IFNULL(dx.xray_count, 0) AS xray_count,
    IFNULL(dx.ecg_count, 0) AS ecg_count,
    IFNULL(dx.two_d_echo_count, 0) AS two_d_echo_count,
    IFNULL(dx.tst_count, 0) AS tst_count,
    IFNULL(dx.vascular_count, 0) AS vascular_count,
    IFNULL(dx.ultrasound_count, 0) AS ultrasound_count,
    IFNULL(dx.womancare_count, 0) AS womancare_count,
    IFNULL(dx.clin_chem_count, 0) AS clin_chem_count,
    IFNULL(dx.hema_count, 0) AS hema_count,
    IFNULL(dx.immuno_count, 0) AS immuno_count,
    IFNULL(dx.sero_count, 0) AS sero_count,
    IFNULL(dx.clin_mic_count, 0) AS clin_mic_count,
    IFNULL(dx.histo_count, 0) AS histo_count,
    IFNULL(dx.drug_test_count, 0) AS drug_test_count,
    IFNULL(so.send_out_count, 0) AS send_out_count,
    IFNULL(dx.ophtha_count, 0) AS ophtha_count,
    0.00 AS opto_count,
    IFNULL(dx.dental_count, 0) AS dental_count,
    IFNULL(dx.ent_count, 0) AS ent_count,
    IFNULL(dx.audiometry_count, 0) AS audiometry_count,
    IFNULL(dx.abc_count, 0) AS abc_count,
    IFNULL(dx.clinic_vax_count, 0) AS clinic_vax_count,
    0.00 AS cardio_source_count,
    NOW() AS added_on,
    0 AS is_deleted
FROM dates
LEFT JOIN queue_metrics q ON q.d = dates.d
LEFT JOIN exam_metrics e ON e.d = dates.d
LEFT JOIN dx_metrics dx ON dx.d = dates.d
LEFT JOIN send_out_metrics so ON so.d = dates.d
ON DUPLICATE KEY UPDATE
    specialist_consult = VALUES(specialist_consult),
    total_yakap = VALUES(total_yakap),
    pcp_ape_peme = VALUES(pcp_ape_peme),
    pcp_yakap_new = VALUES(pcp_yakap_new),
    pcp_yakap_old = VALUES(pcp_yakap_old),
    pcp_consult_hmo_cash = VALUES(pcp_consult_hmo_cash),
    xray = VALUES(xray),
    ecg = VALUES(ecg),
    two_d_echo = VALUES(two_d_echo),
    tst = VALUES(tst),
    vascular = VALUES(vascular),
    ultrasound = VALUES(ultrasound),
    womancare = VALUES(womancare),
    clin_chem = VALUES(clin_chem),
    hema = VALUES(hema),
    immuno = VALUES(immuno),
    sero = VALUES(sero),
    clin_mic = VALUES(clin_mic),
    histo = VALUES(histo),
    drug_test = VALUES(drug_test),
    send_out = VALUES(send_out),
    ophtha = VALUES(ophtha),
    opto = VALUES(opto),
    dental = VALUES(dental),
    ent = VALUES(ent),
    audiometry = VALUES(audiometry),
    abc = VALUES(abc),
    clinic_vax = VALUES(clinic_vax),
    cardio_source = VALUES(cardio_source),
    specialist_consult_count = VALUES(specialist_consult_count),
    total_yakap_count = VALUES(total_yakap_count),
    pcp_ape_peme_count = VALUES(pcp_ape_peme_count),
    pcp_yakap_new_count = VALUES(pcp_yakap_new_count),
    pcp_yakap_old_count = VALUES(pcp_yakap_old_count),
    pcp_consult_hmo_cash_count = VALUES(pcp_consult_hmo_cash_count),
    xray_count = VALUES(xray_count),
    ecg_count = VALUES(ecg_count),
    two_d_echo_count = VALUES(two_d_echo_count),
    tst_count = VALUES(tst_count),
    vascular_count = VALUES(vascular_count),
    ultrasound_count = VALUES(ultrasound_count),
    womancare_count = VALUES(womancare_count),
    clin_chem_count = VALUES(clin_chem_count),
    hema_count = VALUES(hema_count),
    immuno_count = VALUES(immuno_count),
    sero_count = VALUES(sero_count),
    clin_mic_count = VALUES(clin_mic_count),
    histo_count = VALUES(histo_count),
    drug_test_count = VALUES(drug_test_count),
    send_out_count = VALUES(send_out_count),
    ophtha_count = VALUES(ophtha_count),
    opto_count = VALUES(opto_count),
    dental_count = VALUES(dental_count),
    ent_count = VALUES(ent_count),
    audiometry_count = VALUES(audiometry_count),
    abc_count = VALUES(abc_count),
    clinic_vax_count = VALUES(clinic_vax_count),
    cardio_source_count = VALUES(cardio_source_count),
    added_on = VALUES(added_on);

COMMIT;

-- Expect 56 days for each of branches 1, 2, 3, 4, and 7.
SELECT
    branch_id,
    COUNT(*) AS days,
    SUM(specialist_consult + xray + clin_chem) AS amount_probe
FROM mytemr_reports.daily_census
WHERE is_deleted = 0
  AND `date` BETWEEN '2026-08-01' AND '2026-09-24'
GROUP BY branch_id;
