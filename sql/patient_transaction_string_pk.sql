-- Convert patient.id / transaction.id to a 21-char generated PK:
--   YYYYMMDDHHMMSS + 3-letter branch prefix + last 4 hex chars of UUID v4
--   Example: 20260918164501OAKA3F2
--
-- Source dumps (no FOREIGN KEY / ADD CONSTRAINT on these columns):
--   master     patient.id              bigint(20) NOT NULL AUTO_INCREMENT
--   diagnostic patient.id              bigint(20) NOT NULL AUTO_INCREMENT
--   diagnostic patient.master_id       bigint(20) DEFAULT NULL
--   diagnostic transaction.id          int(11) NOT NULL AUTO_INCREMENT
--   emr        patient.id              bigint(20) NOT NULL AUTO_INCREMENT
--   emr        patient.master_patient_id int(11) DEFAULT NULL
--
-- Target: VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin
--   - ascii (1 byte/char) instead of utf8mb4
--   - ascii_bin for binary compares (generator uppercases prefix + UUID hex suffix)
--   - VARCHAR not CHAR so existing numeric ids are not space-padded
--
-- Drop AUTO_INCREMENT by restating the CURRENT numeric type first.
-- Do NOT use MODIFY id INT on patient — that would shrink bigint.
-- Do NOT backfill old integer ids. After convert they remain '12345'.
-- Do NOT ORDER BY id for chronology after mixed old/new ids; use added_on.
-- Do NOT (int)-cast these ids in PHP: (int)'20260918164501OAKA3F2' becomes 20260918164501.
--
-- Skipped (not these PKs):
--   master.patient_membership.patient_id  already varchar(255)
--   diagnostic.medicine.master_id         medicine catalog id
--
-- =============================================================================
-- ROLLOUT (PK type change rebuilds the InnoDB table: ALGORITHM=COPY)
-- =============================================================================
-- 1. Backup. Re-check FKs live (none in dumps):
--      SELECT CONSTRAINT_NAME, TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME,
--             REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
--      FROM information_schema.KEY_COLUMN_USAGE
--      WHERE REFERENCED_TABLE_NAME IN ('patient', 'transaction')
--         OR COLUMN_NAME IN ('patient_id','transaction_id','master_id',
--                            'master_patient_id','patient_master_id');
-- 2. Drop FKs if any exist live, then alter PKs, then companion columns.
-- 3. Prefer pt-online-schema-change or gh-ost on large tables.
--    Fallback (maintenance window, MySQL 8): ALGORITHM=COPY, LOCK=SHARED.
-- 4. ANALYZE TABLE after each convert.
-- 5. Confirm SHOW CREATE TABLE patient / transaction has no AUTO_INCREMENT.
-- 6. Deploy PHP (generator + insert wiring) AFTER schema is live.

-- =============================================================================
-- 1. MASTER — mytemr_master
-- =============================================================================
USE mytemr_master;

ALTER TABLE patient MODIFY COLUMN id BIGINT NOT NULL;
ALTER TABLE patient
  MODIFY COLUMN id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;

ALTER TABLE appointment
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL,
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;
ALTER TABLE assessment
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE billing_entry
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  MODIFY COLUMN master_patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;
ALTER TABLE diagnostic
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE endorsement_detail
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE endorsement_patient
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE informed_consent
  MODIFY COLUMN master_patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;
ALTER TABLE medical_history
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE patient_case_no
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE patient_flagging
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE refund
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE therapeutic
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE vaccination
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE vitals
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE voucher_sequence
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;
ALTER TABLE wallet
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE wallet_history
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;

ANALYZE TABLE patient, appointment, assessment, billing_entry, diagnostic,
  endorsement_detail, endorsement_patient, informed_consent, medical_history,
  patient_case_no, patient_flagging, refund, therapeutic, vaccination, vitals,
  voucher_sequence, wallet, wallet_history;

-- =============================================================================
-- 2. DIAGNOSTIC — PKs + connected columns
--    Same list on: mobile, oakridge, mactan, consolacion, mantlewood
-- =============================================================================

-- --- mytemr_diagnostic_mobile ---
USE mytemr_diagnostic_mobile;

ALTER TABLE patient MODIFY COLUMN id BIGINT NOT NULL;
ALTER TABLE patient
  MODIFY COLUMN id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE patient
  MODIFY COLUMN master_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;

ALTER TABLE transaction MODIFY COLUMN id INT NOT NULL;
ALTER TABLE transaction
  MODIFY COLUMN id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE transaction
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;

ALTER TABLE ar
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;
ALTER TABLE billing_entry
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE booking
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE booking_details
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE booking_details_cannot_perform
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE booking_doctors_fee
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL,
  MODIFY COLUMN master_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL,
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE booking_pdetails_cannot_perform
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE hl7_message
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE informed_consent
  MODIFY COLUMN master_patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;
ALTER TABLE medrecord_request
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE non_cash_deposit
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE patient_membership
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE patient_queue
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL,
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;
ALTER TABLE patient_tat
  MODIFY COLUMN patient_master_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL,
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;
ALTER TABLE payment
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE send_out_entry
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE transaction_discount
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE voucher_sequence
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;

ANALYZE TABLE patient, transaction, ar, billing_entry, booking, booking_details,
  booking_details_cannot_perform, booking_doctors_fee, booking_pdetails_cannot_perform,
  hl7_message, informed_consent, medrecord_request, non_cash_deposit,
  patient_membership, patient_queue, patient_tat, payment, send_out_entry,
  transaction_discount, voucher_sequence;

-- --- mytemr_diagnostic (Oakridge) ---
USE mytemr_diagnostic;

ALTER TABLE patient MODIFY COLUMN id BIGINT NOT NULL;
ALTER TABLE patient
  MODIFY COLUMN id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE patient
  MODIFY COLUMN master_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;

ALTER TABLE transaction MODIFY COLUMN id INT NOT NULL;
ALTER TABLE transaction
  MODIFY COLUMN id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE transaction
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;

ALTER TABLE ar
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;
ALTER TABLE billing_entry
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE booking
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE booking_details
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE booking_details_cannot_perform
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE booking_doctors_fee
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL,
  MODIFY COLUMN master_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL,
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE booking_pdetails_cannot_perform
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE hl7_message
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE informed_consent
  MODIFY COLUMN master_patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;
ALTER TABLE medrecord_request
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE non_cash_deposit
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE patient_membership
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE patient_queue
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL,
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;
ALTER TABLE patient_tat
  MODIFY COLUMN patient_master_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL,
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;
ALTER TABLE payment
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE send_out_entry
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE transaction_discount
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE voucher_sequence
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;

ANALYZE TABLE patient, transaction, ar, billing_entry, booking, booking_details,
  booking_details_cannot_perform, booking_doctors_fee, booking_pdetails_cannot_perform,
  hl7_message, informed_consent, medrecord_request, non_cash_deposit,
  patient_membership, patient_queue, patient_tat, payment, send_out_entry,
  transaction_discount, voucher_sequence;

-- --- mytemr_diagnostic_mactan ---
USE mytemr_diagnostic_mactan;

ALTER TABLE patient MODIFY COLUMN id BIGINT NOT NULL;
ALTER TABLE patient
  MODIFY COLUMN id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE patient
  MODIFY COLUMN master_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;

ALTER TABLE transaction MODIFY COLUMN id INT NOT NULL;
ALTER TABLE transaction
  MODIFY COLUMN id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE transaction
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;

ALTER TABLE ar
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;
ALTER TABLE billing_entry
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE booking
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE booking_details
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE booking_details_cannot_perform
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE booking_doctors_fee
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL,
  MODIFY COLUMN master_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL,
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE booking_pdetails_cannot_perform
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE hl7_message
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE informed_consent
  MODIFY COLUMN master_patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;
ALTER TABLE medrecord_request
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE non_cash_deposit
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE patient_membership
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE patient_queue
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL,
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;
ALTER TABLE patient_tat
  MODIFY COLUMN patient_master_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL,
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;
ALTER TABLE payment
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE send_out_entry
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE transaction_discount
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE voucher_sequence
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;

ANALYZE TABLE patient, transaction, ar, billing_entry, booking, booking_details,
  booking_details_cannot_perform, booking_doctors_fee, booking_pdetails_cannot_perform,
  hl7_message, informed_consent, medrecord_request, non_cash_deposit,
  patient_membership, patient_queue, patient_tat, payment, send_out_entry,
  transaction_discount, voucher_sequence;

-- --- mytemr_diagnostic_conso ---
USE mytemr_diagnostic_conso;

ALTER TABLE patient MODIFY COLUMN id BIGINT NOT NULL;
ALTER TABLE patient
  MODIFY COLUMN id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE patient
  MODIFY COLUMN master_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;

ALTER TABLE transaction MODIFY COLUMN id INT NOT NULL;
ALTER TABLE transaction
  MODIFY COLUMN id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE transaction
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;

ALTER TABLE ar
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;
ALTER TABLE billing_entry
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE booking
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE booking_details
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE booking_details_cannot_perform
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE booking_doctors_fee
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL,
  MODIFY COLUMN master_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL,
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE booking_pdetails_cannot_perform
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE hl7_message
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE informed_consent
  MODIFY COLUMN master_patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;
ALTER TABLE medrecord_request
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE non_cash_deposit
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE patient_membership
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE patient_queue
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL,
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;
ALTER TABLE patient_tat
  MODIFY COLUMN patient_master_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL,
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;
ALTER TABLE payment
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE send_out_entry
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE transaction_discount
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE voucher_sequence
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;

ANALYZE TABLE patient, transaction, ar, billing_entry, booking, booking_details,
  booking_details_cannot_perform, booking_doctors_fee, booking_pdetails_cannot_perform,
  hl7_message, informed_consent, medrecord_request, non_cash_deposit,
  patient_membership, patient_queue, patient_tat, payment, send_out_entry,
  transaction_discount, voucher_sequence;

-- --- mytemr_diagnostic_mantlewood ---
USE mytemr_diagnostic_mantlewood;

ALTER TABLE patient MODIFY COLUMN id BIGINT NOT NULL;
ALTER TABLE patient
  MODIFY COLUMN id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE patient
  MODIFY COLUMN master_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;

ALTER TABLE transaction MODIFY COLUMN id INT NOT NULL;
ALTER TABLE transaction
  MODIFY COLUMN id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE transaction
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;

ALTER TABLE ar
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;
ALTER TABLE billing_entry
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE booking
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE booking_details
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE booking_details_cannot_perform
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE booking_doctors_fee
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL,
  MODIFY COLUMN master_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL,
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE booking_pdetails_cannot_perform
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE hl7_message
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE informed_consent
  MODIFY COLUMN master_patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;
ALTER TABLE medrecord_request
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE non_cash_deposit
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE patient_membership
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE patient_queue
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL,
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;
ALTER TABLE patient_tat
  MODIFY COLUMN patient_master_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL,
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;
ALTER TABLE payment
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE send_out_entry
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE transaction_discount
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE voucher_sequence
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;

ANALYZE TABLE patient, transaction, ar, billing_entry, booking, booking_details,
  booking_details_cannot_perform, booking_doctors_fee, booking_pdetails_cannot_perform,
  hl7_message, informed_consent, medrecord_request, non_cash_deposit,
  patient_membership, patient_queue, patient_tat, payment, send_out_entry,
  transaction_discount, voucher_sequence;

-- =============================================================================
-- 3. EMR — patient.id becomes generated string PK + connected patient_id
--    Same list on: mobile, oakridge, mactan, consolacion, mantlewood EMR
-- =============================================================================

-- --- mytemr_mobile ---
USE mytemr_mobile;

ALTER TABLE patient MODIFY COLUMN id BIGINT NOT NULL;
ALTER TABLE patient
  MODIFY COLUMN id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE patient
  MODIFY COLUMN master_patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;

ALTER TABLE allergy_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE assessment MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE assessment_liveness_check MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE assessment_mca MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE assessment_photo MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE asthma_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE cancer_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE cerebrovascular_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE complaint MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;
ALTER TABLE coronary_artery_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE diabetes_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE diagnostic MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE drinking_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE drug_use_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE emphysema_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE epilepsy_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE family_allergy_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE family_cancer_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE family_hepatitis_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE family_pulmonary_tb_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE followup MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE form MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE gdm_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE hepatitis_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE herbal_use_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE heredofamilial_disease_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE hospitalization_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE hyperlipidemia_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE hypertension_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE immunization MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE inpatient MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE lab_result MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE lab_result_evaluation MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE medical_abstract MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE medical_abstract_diagnosis MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE medical_abstract_therapeutic MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE medication_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE mental_illness_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE ob_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE old_consultation_attachment MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE other_endocrine_disease_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE other_illness_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE outpatient MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE past_med_history MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE patient_attachment MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE patient_fpe MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE patient_ncd_answers MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE patient_queue MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE philhealth_report MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE philhealth_report_documents MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE physical_exam
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;
ALTER TABLE pituitary_disorder_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE pneumonia_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE post_consultation MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE pregnancy_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE pre_gdm_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE pulmonary_tb_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE queue
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;
ALTER TABLE refuse_admission MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE sexual_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE smoking_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE therapeutic MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE thyroid_disorder_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE ulcer_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE uti_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE vax_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE vital_sign MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE vital_sign_draft MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;

ANALYZE TABLE patient, allergy_profile, assessment, assessment_liveness_check,
  assessment_mca, assessment_photo, asthma_profile, cancer_profile,
  cerebrovascular_profile, complaint, coronary_artery_profile, diabetes_profile,
  diagnostic, drinking_profile, drug_use_profile, emphysema_profile,
  epilepsy_profile, family_allergy_profile, family_cancer_profile,
  family_hepatitis_profile, family_pulmonary_tb_profile, followup, form,
  gdm_profile, hepatitis_profile, herbal_use_profile,
  heredofamilial_disease_profile, hospitalization_profile, hyperlipidemia_profile,
  hypertension_profile, immunization, inpatient, lab_result,
  lab_result_evaluation, medical_abstract, medical_abstract_diagnosis,
  medical_abstract_therapeutic, medication_profile, mental_illness_profile,
  ob_profile, old_consultation_attachment, other_endocrine_disease_profile,
  other_illness_profile, outpatient, past_med_history, patient_attachment,
  patient_fpe, patient_ncd_answers, patient_queue, philhealth_report,
  philhealth_report_documents, physical_exam, pituitary_disorder_profile,
  pneumonia_profile, post_consultation, pregnancy_profile, pre_gdm_profile,
  pulmonary_tb_profile, queue, refuse_admission, sexual_profile, smoking_profile,
  therapeutic, thyroid_disorder_profile, ulcer_profile, uti_profile, vax_profile,
  vital_sign, vital_sign_draft;

-- --- mytemr_emr (Oakridge) ---
USE mytemr_emr;

ALTER TABLE patient MODIFY COLUMN id BIGINT NOT NULL;
ALTER TABLE patient
  MODIFY COLUMN id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE patient
  MODIFY COLUMN master_patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;

ALTER TABLE allergy_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE assessment MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE assessment_liveness_check MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE assessment_mca MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE assessment_photo MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE asthma_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE cancer_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE cerebrovascular_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE complaint MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;
ALTER TABLE coronary_artery_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE diabetes_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE diagnostic MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE drinking_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE drug_use_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE emphysema_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE epilepsy_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE family_allergy_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE family_cancer_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE family_hepatitis_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE family_pulmonary_tb_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE followup MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE form MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE gdm_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE hepatitis_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE herbal_use_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE heredofamilial_disease_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE hospitalization_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE hyperlipidemia_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE hypertension_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE immunization MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE inpatient MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE lab_result MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE lab_result_evaluation MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE medical_abstract MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE medical_abstract_diagnosis MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE medical_abstract_therapeutic MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE medication_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE mental_illness_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE ob_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE old_consultation_attachment MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE other_endocrine_disease_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE other_illness_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE outpatient MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE past_med_history MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE patient_attachment MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE patient_fpe MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE patient_ncd_answers MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE patient_queue MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE philhealth_report MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE philhealth_report_documents MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE physical_exam
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;
ALTER TABLE pituitary_disorder_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE pneumonia_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE post_consultation MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE pregnancy_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE pre_gdm_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE pulmonary_tb_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE queue
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;
ALTER TABLE refuse_admission MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE sexual_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE smoking_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE therapeutic MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE thyroid_disorder_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE ulcer_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE uti_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE vax_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE vital_sign MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE vital_sign_draft MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;

ANALYZE TABLE patient, allergy_profile, assessment, assessment_liveness_check,
  assessment_mca, assessment_photo, asthma_profile, cancer_profile,
  cerebrovascular_profile, complaint, coronary_artery_profile, diabetes_profile,
  diagnostic, drinking_profile, drug_use_profile, emphysema_profile,
  epilepsy_profile, family_allergy_profile, family_cancer_profile,
  family_hepatitis_profile, family_pulmonary_tb_profile, followup, form,
  gdm_profile, hepatitis_profile, herbal_use_profile,
  heredofamilial_disease_profile, hospitalization_profile, hyperlipidemia_profile,
  hypertension_profile, immunization, inpatient, lab_result,
  lab_result_evaluation, medical_abstract, medical_abstract_diagnosis,
  medical_abstract_therapeutic, medication_profile, mental_illness_profile,
  ob_profile, old_consultation_attachment, other_endocrine_disease_profile,
  other_illness_profile, outpatient, past_med_history, patient_attachment,
  patient_fpe, patient_ncd_answers, patient_queue, philhealth_report,
  philhealth_report_documents, physical_exam, pituitary_disorder_profile,
  pneumonia_profile, post_consultation, pregnancy_profile, pre_gdm_profile,
  pulmonary_tb_profile, queue, refuse_admission, sexual_profile, smoking_profile,
  therapeutic, thyroid_disorder_profile, ulcer_profile, uti_profile, vax_profile,
  vital_sign, vital_sign_draft;

-- --- mytemr_emr_mactan (Mactan) ---
USE mytemr_emr_mactan;

ALTER TABLE patient MODIFY COLUMN id BIGINT NOT NULL;
ALTER TABLE patient
  MODIFY COLUMN id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE patient
  MODIFY COLUMN master_patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;

ALTER TABLE allergy_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE assessment MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE assessment_liveness_check MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE assessment_mca MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE assessment_photo MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE asthma_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE cancer_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE cerebrovascular_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE complaint MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;
ALTER TABLE coronary_artery_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE diabetes_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE diagnostic MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE drinking_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE drug_use_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE emphysema_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE epilepsy_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE family_allergy_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE family_cancer_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE family_hepatitis_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE family_pulmonary_tb_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE followup MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE form MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE gdm_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE hepatitis_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE herbal_use_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE heredofamilial_disease_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE hospitalization_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE hyperlipidemia_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE hypertension_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE immunization MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE inpatient MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE lab_result MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE lab_result_evaluation MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE medical_abstract MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE medical_abstract_diagnosis MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE medical_abstract_therapeutic MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE medication_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE mental_illness_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE ob_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE old_consultation_attachment MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE other_endocrine_disease_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE other_illness_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE outpatient MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE past_med_history MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE patient_attachment MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE patient_fpe MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE patient_ncd_answers MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE patient_queue MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE philhealth_report MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE philhealth_report_documents MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE physical_exam
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;
ALTER TABLE pituitary_disorder_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE pneumonia_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE post_consultation MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE pregnancy_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE pre_gdm_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE pulmonary_tb_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE queue
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;
ALTER TABLE refuse_admission MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE sexual_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE smoking_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE therapeutic MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE thyroid_disorder_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE ulcer_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE uti_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE vax_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE vital_sign MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE vital_sign_draft MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;

ANALYZE TABLE patient, allergy_profile, assessment, assessment_liveness_check,
  assessment_mca, assessment_photo, asthma_profile, cancer_profile,
  cerebrovascular_profile, complaint, coronary_artery_profile, diabetes_profile,
  diagnostic, drinking_profile, drug_use_profile, emphysema_profile,
  epilepsy_profile, family_allergy_profile, family_cancer_profile,
  family_hepatitis_profile, family_pulmonary_tb_profile, followup, form,
  gdm_profile, hepatitis_profile, herbal_use_profile,
  heredofamilial_disease_profile, hospitalization_profile, hyperlipidemia_profile,
  hypertension_profile, immunization, inpatient, lab_result,
  lab_result_evaluation, medical_abstract, medical_abstract_diagnosis,
  medical_abstract_therapeutic, medication_profile, mental_illness_profile,
  ob_profile, old_consultation_attachment, other_endocrine_disease_profile,
  other_illness_profile, outpatient, past_med_history, patient_attachment,
  patient_fpe, patient_ncd_answers, patient_queue, philhealth_report,
  philhealth_report_documents, physical_exam, pituitary_disorder_profile,
  pneumonia_profile, post_consultation, pregnancy_profile, pre_gdm_profile,
  pulmonary_tb_profile, queue, refuse_admission, sexual_profile, smoking_profile,
  therapeutic, thyroid_disorder_profile, ulcer_profile, uti_profile, vax_profile,
  vital_sign, vital_sign_draft;

-- --- mytemr_emr_conso (Consolacion) ---
USE mytemr_emr_conso;

ALTER TABLE patient MODIFY COLUMN id BIGINT NOT NULL;
ALTER TABLE patient
  MODIFY COLUMN id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE patient
  MODIFY COLUMN master_patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;

ALTER TABLE allergy_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE assessment MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE assessment_liveness_check MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE assessment_mca MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE assessment_photo MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE asthma_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE cancer_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE cerebrovascular_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE complaint MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;
ALTER TABLE coronary_artery_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE diabetes_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE diagnostic MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE drinking_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE drug_use_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE emphysema_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE epilepsy_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE family_allergy_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE family_cancer_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE family_hepatitis_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE family_pulmonary_tb_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE followup MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE form MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE gdm_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE hepatitis_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE herbal_use_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE heredofamilial_disease_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE hospitalization_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE hyperlipidemia_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE hypertension_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE immunization MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE inpatient MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE lab_result MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE lab_result_evaluation MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE medical_abstract MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE medical_abstract_diagnosis MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE medical_abstract_therapeutic MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE medication_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE mental_illness_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE ob_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE old_consultation_attachment MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE other_endocrine_disease_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE other_illness_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE outpatient MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE past_med_history MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE patient_attachment MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE patient_fpe MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE patient_ncd_answers MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE patient_queue MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE philhealth_report MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE philhealth_report_documents MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE physical_exam
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;
ALTER TABLE pituitary_disorder_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE pneumonia_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE post_consultation MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE pregnancy_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE pre_gdm_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE pulmonary_tb_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE queue
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;
ALTER TABLE refuse_admission MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE sexual_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE smoking_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE therapeutic MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE thyroid_disorder_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE ulcer_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE uti_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE vax_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE vital_sign MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE vital_sign_draft MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;

ANALYZE TABLE patient, allergy_profile, assessment, assessment_liveness_check,
  assessment_mca, assessment_photo, asthma_profile, cancer_profile,
  cerebrovascular_profile, complaint, coronary_artery_profile, diabetes_profile,
  diagnostic, drinking_profile, drug_use_profile, emphysema_profile,
  epilepsy_profile, family_allergy_profile, family_cancer_profile,
  family_hepatitis_profile, family_pulmonary_tb_profile, followup, form,
  gdm_profile, hepatitis_profile, herbal_use_profile,
  heredofamilial_disease_profile, hospitalization_profile, hyperlipidemia_profile,
  hypertension_profile, immunization, inpatient, lab_result,
  lab_result_evaluation, medical_abstract, medical_abstract_diagnosis,
  medical_abstract_therapeutic, medication_profile, mental_illness_profile,
  ob_profile, old_consultation_attachment, other_endocrine_disease_profile,
  other_illness_profile, outpatient, past_med_history, patient_attachment,
  patient_fpe, patient_ncd_answers, patient_queue, philhealth_report,
  philhealth_report_documents, physical_exam, pituitary_disorder_profile,
  pneumonia_profile, post_consultation, pregnancy_profile, pre_gdm_profile,
  pulmonary_tb_profile, queue, refuse_admission, sexual_profile, smoking_profile,
  therapeutic, thyroid_disorder_profile, ulcer_profile, uti_profile, vax_profile,
  vital_sign, vital_sign_draft;

-- --- mytemr_emr_mantlewood (Mantlewood) ---
USE mytemr_emr_mantlewood;

ALTER TABLE patient MODIFY COLUMN id BIGINT NOT NULL;
ALTER TABLE patient
  MODIFY COLUMN id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE patient
  MODIFY COLUMN master_patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;

ALTER TABLE allergy_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE assessment MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE assessment_liveness_check MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE assessment_mca MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE assessment_photo MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE asthma_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE cancer_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE cerebrovascular_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE complaint MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;
ALTER TABLE coronary_artery_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE diabetes_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE diagnostic MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE drinking_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE drug_use_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE emphysema_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE epilepsy_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE family_allergy_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE family_cancer_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE family_hepatitis_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE family_pulmonary_tb_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE followup MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE form MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE gdm_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE hepatitis_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE herbal_use_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE heredofamilial_disease_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE hospitalization_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE hyperlipidemia_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE hypertension_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE immunization MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE inpatient MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE lab_result MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE lab_result_evaluation MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE medical_abstract MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE medical_abstract_diagnosis MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE medical_abstract_therapeutic MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE medication_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE mental_illness_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE ob_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE old_consultation_attachment MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE other_endocrine_disease_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE other_illness_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE outpatient MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE past_med_history MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE patient_attachment MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE patient_fpe MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE patient_ncd_answers MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE patient_queue MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE philhealth_report MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE philhealth_report_documents MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE physical_exam
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;
ALTER TABLE pituitary_disorder_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE pneumonia_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE post_consultation MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE pregnancy_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE pre_gdm_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE pulmonary_tb_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE queue
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL;
ALTER TABLE refuse_admission MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE sexual_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE smoking_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE therapeutic MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE thyroid_disorder_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE ulcer_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE uti_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE vax_profile MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE vital_sign MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE vital_sign_draft MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;

ANALYZE TABLE patient, allergy_profile, assessment, assessment_liveness_check,
  assessment_mca, assessment_photo, asthma_profile, cancer_profile,
  cerebrovascular_profile, complaint, coronary_artery_profile, diabetes_profile,
  diagnostic, drinking_profile, drug_use_profile, emphysema_profile,
  epilepsy_profile, family_allergy_profile, family_cancer_profile,
  family_hepatitis_profile, family_pulmonary_tb_profile, followup, form,
  gdm_profile, hepatitis_profile, herbal_use_profile,
  heredofamilial_disease_profile, hospitalization_profile, hyperlipidemia_profile,
  hypertension_profile, immunization, inpatient, lab_result,
  lab_result_evaluation, medical_abstract, medical_abstract_diagnosis,
  medical_abstract_therapeutic, medication_profile, mental_illness_profile,
  ob_profile, old_consultation_attachment, other_endocrine_disease_profile,
  other_illness_profile, outpatient, past_med_history, patient_attachment,
  patient_fpe, patient_ncd_answers, patient_queue, philhealth_report,
  philhealth_report_documents, physical_exam, pituitary_disorder_profile,
  pneumonia_profile, post_consultation, pregnancy_profile, pre_gdm_profile,
  pulmonary_tb_profile, queue, refuse_admission, sexual_profile, smoking_profile,
  therapeutic, thyroid_disorder_profile, ulcer_profile, uti_profile, vax_profile,
  vital_sign, vital_sign_draft;

-- =============================================================================
-- 4. REPORTS — mytemr_reports
-- =============================================================================
USE mytemr_reports;

ALTER TABLE medrecord
  MODIFY COLUMN patient_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NULL,
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE sendout_census
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE uip_patient_activity
  MODIFY COLUMN master_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE uip_patient_demo
  MODIFY COLUMN master_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE uip_patient_snapshot
  MODIFY COLUMN master_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE uip_transaction
  MODIFY COLUMN transaction_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  MODIFY COLUMN master_id VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;

ANALYZE TABLE medrecord, sendout_census, uip_patient_activity, uip_patient_demo,
  uip_patient_snapshot, uip_transaction;
