-- Medrecord unique key fix: endorsement_id must be updatable (inherited from parent branch).
-- Run once on the report database (e.g. mytemr_reports / mytemr_reports_uat).
--
-- Old unique key: (branch_id, endorsement_id, transaction_date, patient_id, lab_test_id)
-- New unique key: (branch_id, booking_id, lab_test_id, transaction_date)

ALTER TABLE medrecord DROP INDEX `branch_id`;

ALTER TABLE medrecord
  ADD UNIQUE KEY `uk_medrecord_booking_test`
    (branch_id, booking_id, lab_test_id, transaction_date);

-- After ALTER, rebuild the day (example Oakridge 2026-07-20):
--   GET/POST cron get_medrecord?transaction_date=2026-07-20
