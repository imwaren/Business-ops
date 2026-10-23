-- Recommended indexes for list get() / get_count() on:
--   request, costing, quotation, endorsement
-- Run on the business-ops (facility) database.
-- Safe to skip if an equivalent index already exists.

-- request list filters + costing NOT EXISTS / disapproved subquery
ALTER TABLE request
  ADD INDEX idx_request_list (is_deleted, status, company_id, added_on),
  ADD INDEX idx_request_company (company_id, is_deleted),
  ADD INDEX idx_request_ref (reference_no);

ALTER TABLE costing
  ADD INDEX idx_costing_list (is_deleted, status, company_id, added_on),
  ADD INDEX idx_costing_request (request_id, is_deleted, status),
  ADD INDEX idx_costing_mgr_sm (is_deleted, status, approved_by_sales_manager, approved_by_sales_director),
  ADD INDEX idx_costing_customer (customer_account_id, is_deleted);

ALTER TABLE quotation
  ADD INDEX idx_quotation_list (is_deleted, status, company_id, added_on),
  ADD INDEX idx_quotation_costing (costing_id, is_deleted),
  ADD INDEX idx_quotation_request (request_id, is_deleted),
  ADD INDEX idx_quotation_mgr (is_deleted, status, approved_by_sales, approved_by_sales_manager, approved_by_sales_director, approved_by_finance_manager);

ALTER TABLE endorsement
  ADD INDEX idx_endorsement_list (is_deleted, status, company_id),
  ADD INDEX idx_endorsement_quotation (quotation_id, is_deleted, status),
  ADD INDEX idx_endorsement_revision (is_deleted, is_pending_revision),
  ADD INDEX idx_endorsement_patient_upload (is_deleted, with_masterlist, status, contract_date_from);

-- supporting joins / NOT EXISTS
ALTER TABLE company
  ADD INDEX idx_company_active (id, is_deleted);

ALTER TABLE customer_account
  ADD INDEX idx_ca_active (id, is_deleted);

-- Child FK indexes for batch enrichment (WHERE parent_id IN (...))
ALTER TABLE request_detail
  ADD INDEX idx_rd_request (request_id, is_deleted);
ALTER TABLE request_service
  ADD INDEX idx_rs_request (request_id, is_deleted);
ALTER TABLE request_shift
  ADD INDEX idx_rsh_request (request_id, is_deleted);
ALTER TABLE request_consultation
  ADD INDEX idx_rc_request (request_id, is_deleted);
ALTER TABLE request_history
  ADD INDEX idx_rh_source (source_id, is_deleted);
ALTER TABLE package_detail
  ADD INDEX idx_pd_package (package_id, is_deleted);

ALTER TABLE costing_detail
  ADD INDEX idx_cd_costing (costing_id, is_deleted);
ALTER TABLE costing_service
  ADD INDEX idx_cs_costing (costing_id, is_deleted);
ALTER TABLE costing_service_package_detail
  ADD INDEX idx_cspd_service (costing_service_id, is_deleted),
  ADD INDEX idx_cspd_costing (costing_id, is_deleted);
ALTER TABLE costing_consulta
  ADD INDEX idx_cc_costing (costing_id, is_deleted);
ALTER TABLE costing_discount
  ADD INDEX idx_cdisc_costing (costing_id, is_deleted);
ALTER TABLE costing_manpower
  ADD INDEX idx_cm_costing (costing_id, is_deleted);
ALTER TABLE costing_manpower_cost
  ADD INDEX idx_cmc_costing (costing_id, is_deleted);
ALTER TABLE costing_manpower_cost_premium_pay
  ADD INDEX idx_cmcpp_costing (costing_id, is_deleted),
  ADD INDEX idx_cmcpp_manpower_cost (costing_manpower_cost_id, is_deleted);
ALTER TABLE costing_mobilization
  ADD INDEX idx_cmob_costing (costing_id, is_deleted);
ALTER TABLE costing_shift
  ADD INDEX idx_csh_costing (costing_id, is_deleted);
ALTER TABLE costing_consultation
  ADD INDEX idx_ccons_costing (costing_id, is_deleted);
ALTER TABLE costing_history
  ADD INDEX idx_ch_source (source_id, is_deleted);

ALTER TABLE quotation_detail
  ADD INDEX idx_qd_quotation (quotation_id, is_deleted);
ALTER TABLE quotation_service
  ADD INDEX idx_qs_quotation (quotation_id, is_deleted);
ALTER TABLE quotation_service_package_detail
  ADD INDEX idx_qspd_service (quotation_service_id, is_deleted);
ALTER TABLE quotation_shift
  ADD INDEX idx_qsh_quotation (quotation_id, is_deleted);
ALTER TABLE quotation_consultation
  ADD INDEX idx_qc_quotation (quotation_id, is_deleted);
ALTER TABLE quotation_history
  ADD INDEX idx_qh_source (source_id, is_deleted);
ALTER TABLE quotation_attachment
  ADD INDEX idx_qa_quotation (quotation_id, is_deleted);
ALTER TABLE quotation_signed_moa
  ADD INDEX idx_qsm_quotation (quotation_id, is_deleted);

ALTER TABLE endorsement_detail
  ADD INDEX idx_ed_endorsement (endorsement_id, is_deleted);
ALTER TABLE endorsement_service
  ADD INDEX idx_es_endorsement (endorsement_id, is_deleted);
ALTER TABLE endorsement_service_package_detail
  ADD INDEX idx_espd_service (endorsement_service_id, is_deleted);
ALTER TABLE endorsement_discount
  ADD INDEX idx_edisc_endorsement (endorsement_id, is_deleted);
ALTER TABLE endorsement_shift
  ADD INDEX idx_esh_endorsement (endorsement_id, is_deleted);
ALTER TABLE endorsement_consultation
  ADD INDEX idx_econ_endorsement (endorsement_id, is_deleted);
ALTER TABLE endorsement_patient
  ADD INDEX idx_ep_endorsement (endorsement_id, is_deleted);
ALTER TABLE endorsement_revision
  ADD INDEX idx_er_endorsement (endorsement_id, is_deleted);
ALTER TABLE endorsement_history
  ADD INDEX idx_eh_source (source_id, is_deleted);
ALTER TABLE endorsement_attachment
  ADD INDEX idx_ea_endorsement (endorsement_id, version_no, is_deleted);
ALTER TABLE endorsement_signed_moa
  ADD INDEX idx_esm_endorsement (endorsement_id, is_deleted);

-- Lab test / package list + child batch enrichment
ALTER TABLE lab_test
  ADD INDEX idx_lab_test_list (is_deleted, category_id, group_id, is_consulta),
  ADD INDEX idx_lab_test_name (name);

ALTER TABLE lab_test_material
  ADD INDEX idx_ltm_lab_test (lab_test_id, is_deleted);
ALTER TABLE lab_test_machine
  ADD INDEX idx_ltmac_lab_test (lab_test_id, is_deleted);
ALTER TABLE lab_test_hmo
  ADD INDEX idx_lth_lab_test (lab_test_id, is_deleted);

ALTER TABLE package
  ADD INDEX idx_package_list (is_deleted, package_status),
  ADD INDEX idx_package_name (name);

ALTER TABLE package_material
  ADD INDEX idx_pm_package (package_id, is_deleted);

ALTER TABLE item_conversion
  ADD INDEX idx_ic_item (item_id, is_deleted);

-- Run on EACH branch diagnostic database (mobile, oakridge, mactan, consolacion, mantlewood):
-- ALTER TABLE booking_details
--   ADD INDEX idx_bd_endorsement (endorsement_id, is_deleted);
