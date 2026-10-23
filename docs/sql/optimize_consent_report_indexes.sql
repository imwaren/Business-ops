-- Recommended indexes for get_consent_report() / get_consent_report_count()
-- Run on each branch diagnostic database (not master).
-- Safe to skip if an equivalent index already exists.

-- patient: deleted + date range + stable ORDER BY id (paginate-first subquery)
ALTER TABLE patient
  ADD INDEX idx_patient_deleted_added_id (is_deleted, added_on, id);

-- informed_consent: EXISTS / NOT EXISTS by master_patient_id
ALTER TABLE informed_consent
  ADD INDEX idx_ic_master_deleted (master_patient_id, is_deleted);

-- data_consent: equality lookup after AES_DECRYPT of patient name/DOB
ALTER TABLE data_consent
  ADD INDEX idx_dc_name_dob (last_name, first_name, date_of_birth, is_deleted);
