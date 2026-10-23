DELIMITER $$
DROP PROCEDURE IF EXISTS assert_shared_procurement_install$$
CREATE PROCEDURE assert_shared_procurement_install()
BEGIN
 IF DATABASE() NOT IN ('mytemr_master','mytemr_master_uat') THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Shared procurement requires the selected master database';
 END IF;
 IF (SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()
  AND ROUTINE_NAME IN ('gl_shared_clear','gl_shared_je','gl_account_id','gl_post_apv'))<>4 THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Install current shared-fund accounting prerequisites first';
 END IF;
 IF (SELECT COUNT(*) FROM account_type WHERE code IN ('104-030','106-000','201-110','201-111') AND status='active' AND is_deleted=0)<>4 OR (SELECT COUNT(DISTINCT code) FROM account_type WHERE code IN ('104-030','106-000','201-110','201-111') AND status='active' AND is_deleted=0)<>4 THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Missing procurement inventory/VAT/transitory/payable account mappings';
 END IF;
 IF EXISTS (SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()
  AND EVENT_OBJECT_TABLE IN ('purchase_request','purchase_request_item','purchase_order','purchase_order_item','receipt_note','receipt_note_item')
  AND ACTION_STATEMENT REGEXP 'gl_post_|gl_shared_post_'
  AND TRIGGER_NAME NOT LIKE 'trg_gl_shared_%') THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Conflicting existing procurement posting hooks; reconcile before installation';
 END IF;
END$$
CALL assert_shared_procurement_install()$$
DROP PROCEDURE assert_shared_procurement_install$$
DELIMITER ;

-- Master procurement storage only. Additive definitions; no document backfill.

CREATE TABLE IF NOT EXISTS `purchase_request` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `branch_id` int(11) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `cost_center_id` int(11) DEFAULT NULL COMMENT 'branch_id',
  `capital_expenditure_id` int(11) DEFAULT NULL,
  `expenditure_type` enum('capex','opex') DEFAULT NULL,
  `type` enum('goods','services') NOT NULL DEFAULT 'goods',
  `needed_date` date NOT NULL,
  `request_date` date DEFAULT NULL,
  `delivery_date` date DEFAULT NULL,
  `payment_date` date DEFAULT NULL,
  `company_name` varchar(255) DEFAULT NULL,
  `billing_address` varchar(255) DEFAULT NULL,
  `delivery_branch_id` int(11) DEFAULT NULL,
  `vat_registered_tin` varchar(255) DEFAULT NULL,
  `contact` varchar(255) DEFAULT NULL,
  `contact_ref` varchar(255) DEFAULT NULL,
  `shipping_address` varchar(255) DEFAULT NULL,
  `payment_terms` varchar(255) DEFAULT NULL,
  `buyer_assigned` varchar(255) DEFAULT NULL,
  `buyer_contact` varchar(255) DEFAULT NULL,
  `shipping_delivery_notes` varchar(255) DEFAULT NULL,
  `expected_expiration_date_from` date DEFAULT NULL,
  `expected_expiration_date_to` date DEFAULT NULL,
  `country` varchar(255) DEFAULT NULL,
  `region` varchar(255) DEFAULT NULL,
  `province` varchar(255) DEFAULT NULL,
  `city` varchar(255) DEFAULT NULL,
  `barangay` varchar(255) DEFAULT NULL,
  `tax_type` varchar(255) DEFAULT NULL,
  `delivery_fee` decimal(13,4) DEFAULT 0.0000,
  `subtotal` decimal(13,4) DEFAULT 0.0000,
  `discount` decimal(13,4) DEFAULT 0.0000,
  `general_discount` decimal(13,4) DEFAULT 0.0000,
  `grand_total` decimal(13,4) DEFAULT 0.0000,
  `paid_amount` decimal(13,4) DEFAULT 0.0000,
  `remarks` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','disapproved') NOT NULL DEFAULT 'pending',
  `approved_by` int(1) DEFAULT NULL,
  `approved_on` datetime DEFAULT NULL,
  `disapproved_reason` text DEFAULT NULL,
  `disapproved_by` int(1) DEFAULT NULL,
  `disapproved_on` datetime DEFAULT NULL,
  `added_by` int(11) DEFAULT NULL,
  `added_on` datetime NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` datetime DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `purchase_request_item` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `purchase_request_id` int(11) NOT NULL,
  `account_type_id` int(11) DEFAULT NULL,
  `item_id` int(11) NOT NULL,
  `qty` decimal(13,4) DEFAULT 0.0000,
  `unit` int(11) DEFAULT NULL,
  `cost` decimal(13,4) DEFAULT 0.0000,
  `total` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `vat` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `vat_type` varchar(250) DEFAULT NULL,
  `total_value` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `discount` decimal(13,4) DEFAULT 0.0000,
  `price` decimal(13,4) DEFAULT 0.0000,
  `amount` decimal(13,4) DEFAULT 0.0000,
  `remarks` text DEFAULT NULL,
  `added_by` int(11) NOT NULL,
  `added_on` datetime NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` datetime DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `purchase_request_attachment` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `purchase_request_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `file_url` longtext DEFAULT NULL,
  `mime` varchar(255) NOT NULL,
  `type` enum('pr','quotation') NOT NULL DEFAULT 'pr',
  `added_on` datetime NOT NULL DEFAULT current_timestamp(),
  `added_by` bigint(20) DEFAULT NULL,
  `updated_on` datetime DEFAULT NULL,
  `updated_by` bigint(20) DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `aoq` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `purchase_request_id` int(11) NOT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `cost_center_id` int(11) DEFAULT NULL,
  `recommended_bidder_id` int(11) DEFAULT NULL COMMENT 'supplier',
  `recommended_by_id` int(11) DEFAULT NULL COMMENT 'user',
  `justification` varchar(250) DEFAULT NULL,
  `type` enum('service','goods') NOT NULL,
  `needed_date` date NOT NULL,
  `project_name` varchar(250) DEFAULT NULL,
  `subtotal` decimal(13,4) DEFAULT 0.0000,
  `discount` decimal(13,4) DEFAULT 0.0000,
  `grand_total` decimal(13,4) DEFAULT 0.0000,
  `remarks` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','disapproved') NOT NULL DEFAULT 'pending',
  `approved_by` int(1) DEFAULT NULL,
  `approved_on` datetime DEFAULT NULL,
  `disapproved_by` int(1) DEFAULT NULL,
  `disapproved_on` datetime DEFAULT NULL,
  `disapproved_reason` varchar(255) DEFAULT NULL,
  `added_by` int(11) DEFAULT NULL,
  `added_on` datetime NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` datetime DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `aoq_detail` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `aoq_id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `name` varchar(250) DEFAULT NULL,
  `cost` decimal(13,4) DEFAULT 0.0000,
  `remarks` varchar(255) DEFAULT NULL,
  `added_by` int(11) DEFAULT NULL,
  `added_on` datetime NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` datetime DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `purchase_order` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `purchase_request_id` int(11) NOT NULL,
  `po_number` varchar(250) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `type` enum('goods','services') NOT NULL DEFAULT 'goods',
  `request_date` date DEFAULT NULL,
  `delivery_branch_id` int(11) DEFAULT NULL,
  `expenditure_type` varchar(255) DEFAULT NULL,
  `capital_expenditure_id` int(11) DEFAULT NULL,
  `expected_expiration_date_from` date DEFAULT NULL,
  `expected_expiration_date_to` date DEFAULT NULL,
  `needed_date` date NOT NULL,
  `purchase_order_no` varchar(255) DEFAULT NULL,
  `purchase_date` date DEFAULT NULL,
  `delivery_date` date DEFAULT NULL,
  `payment_date` date DEFAULT NULL,
  `company_name` varchar(255) DEFAULT NULL,
  `billing_address` varchar(255) DEFAULT NULL,
  `vat_registered_tin` varchar(255) DEFAULT NULL,
  `contact` varchar(255) DEFAULT NULL,
  `revision` varchar(255) DEFAULT NULL,
  `contact_ref` varchar(255) DEFAULT NULL,
  `shipping_address` varchar(255) DEFAULT NULL,
  `incoterms` varchar(255) DEFAULT NULL,
  `payment_terms` varchar(255) DEFAULT NULL,
  `terms` varchar(250) DEFAULT NULL,
  `buyer_assigned` varchar(255) DEFAULT NULL,
  `buyer_contact` varchar(255) DEFAULT NULL,
  `shipping_delivery_notes` varchar(255) DEFAULT NULL,
  `tax_type` varchar(255) DEFAULT NULL,
  `delivery_fee` decimal(13,4) DEFAULT 0.0000,
  `vat_total` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `subtotal` decimal(13,4) DEFAULT 0.0000,
  `discount` decimal(13,4) DEFAULT 0.0000,
  `general_discount` decimal(13,4) DEFAULT 0.0000,
  `grand_total` decimal(13,4) DEFAULT 0.0000,
  `paid_amount` decimal(13,4) DEFAULT 0.0000,
  `remarks` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','disapproved') NOT NULL DEFAULT 'pending',
  `received_status` enum('pending','open','closed') NOT NULL DEFAULT 'pending',
  `is_sent` int(11) NOT NULL DEFAULT 0,
  `sent_by` int(11) DEFAULT NULL,
  `sent_on` datetime DEFAULT NULL,
  `is_closed_request` int(11) NOT NULL DEFAULT 0,
  `closed_request_status` enum('pending','requested','approved','disapproved') NOT NULL DEFAULT 'pending',
  `closed_request_reason` text DEFAULT NULL,
  `closed_request_by` int(11) DEFAULT NULL,
  `closed_request_on` datetime DEFAULT NULL,
  `closed_request_approved_by` int(11) DEFAULT NULL,
  `closed_request_approved_on` datetime DEFAULT NULL,
  `closed_request_disapproved_by` int(11) DEFAULT NULL,
  `closed_request_disapproved_on` datetime DEFAULT NULL,
  `closed_request_disapproved_reason` text DEFAULT NULL,
  `approved_by_department_director` int(11) DEFAULT NULL,
  `approved_on_department_director` datetime DEFAULT NULL,
  `approved_by_president` int(11) DEFAULT NULL,
  `approved_on_president` datetime DEFAULT NULL,
  `disapproved_reason` varchar(250) DEFAULT NULL,
  `approved_by` int(1) DEFAULT NULL,
  `approved_on` datetime DEFAULT NULL,
  `disapproved_by` int(1) DEFAULT NULL,
  `disapproved_on` datetime DEFAULT NULL,
  `added_by` int(11) DEFAULT NULL,
  `added_on` datetime NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` datetime DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `purchase_order_item` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `purchase_order_id` int(11) NOT NULL,
  `purchase_request_id` int(11) NOT NULL,
  `account_type_id` int(11) DEFAULT NULL,
  `cost_center_id` int(11) DEFAULT NULL,
  `expenditure_type` enum('capex','opex') DEFAULT NULL,
  `item_id` int(11) NOT NULL,
  `qty` decimal(13,4) DEFAULT 0.0000,
  `unit` int(11) DEFAULT NULL,
  `cost` decimal(13,4) DEFAULT 0.0000,
  `total` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `vat` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `vat_type` varchar(250) DEFAULT NULL,
  `total_value` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `discount` decimal(13,4) DEFAULT 0.0000,
  `price` decimal(13,4) DEFAULT 0.0000,
  `amount` decimal(13,4) DEFAULT 0.0000,
  `remarks` text DEFAULT NULL,
  `added_by` int(11) NOT NULL,
  `added_on` datetime NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` datetime DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `receipt_note` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `purchase_order_id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `type` enum('goods','services') NOT NULL DEFAULT 'goods',
  `request_date` date DEFAULT NULL,
  `delivery_branch_id` int(11) DEFAULT NULL,
  `expenditure_type` varchar(255) DEFAULT NULL,
  `capital_expenditure_id` int(11) DEFAULT NULL,
  `expected_expiration_date_from` date DEFAULT NULL,
  `expected_expiration_date_to` date DEFAULT NULL,
  `needed_date` date NOT NULL,
  `receive_date` date DEFAULT NULL,
  `delivery_receipt_no` varchar(255) DEFAULT NULL,
  `sales_invoice_no` varchar(255) DEFAULT NULL,
  `company_name` varchar(255) DEFAULT NULL,
  `billing_address` varchar(255) DEFAULT NULL,
  `vat_registered_tin` varchar(255) DEFAULT NULL,
  `contact` varchar(255) DEFAULT NULL,
  `contact_ref` varchar(255) DEFAULT NULL,
  `buyer_assigned` varchar(255) DEFAULT NULL,
  `buyer_contact` varchar(255) DEFAULT NULL,
  `revision` varchar(255) DEFAULT NULL,
  `incoterms` varchar(255) DEFAULT NULL,
  `payment_terms` varchar(255) DEFAULT NULL,
  `tax_type` varchar(255) DEFAULT NULL,
  `shipping_address` varchar(255) DEFAULT NULL,
  `shipping_delivery_notes` varchar(255) DEFAULT NULL,
  `delivery_fee` decimal(13,4) DEFAULT 0.0000,
  `subtotal` decimal(13,4) DEFAULT 0.0000,
  `discount` decimal(13,4) DEFAULT 0.0000,
  `general_discount` decimal(13,4) DEFAULT 0.0000,
  `grand_total` decimal(13,4) DEFAULT 0.0000,
  `paid_amount` decimal(13,4) DEFAULT 0.0000,
  `remarks` varchar(255) DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `status` enum('pending','approved','disapproved') NOT NULL DEFAULT 'pending',
  `disapproved_reason` varchar(250) DEFAULT NULL,
  `approved_by` int(1) DEFAULT NULL,
  `approved_on` datetime DEFAULT NULL,
  `disapproved_by` int(1) DEFAULT NULL,
  `disapproved_on` datetime DEFAULT NULL,
  `added_by` int(11) DEFAULT NULL,
  `added_on` datetime NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` datetime DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `receipt_note_item` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `receipt_note_id` int(11) NOT NULL,
  `purchase_order_id` int(11) NOT NULL,
  `purchase_order_item_id` int(11) NOT NULL,
  `account_type_id` int(11) DEFAULT NULL,
  `cost_center_id` int(11) DEFAULT NULL,
  `expenditure_type` enum('capex','opex') DEFAULT NULL,
  `item_id` int(11) NOT NULL,
  `ordered_qty` decimal(13,2) NOT NULL DEFAULT 0.00,
  `qty` decimal(13,4) DEFAULT 0.0000,
  `unit` int(11) DEFAULT NULL,
  `cost` decimal(13,4) DEFAULT 0.0000,
  `total` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `vat` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `vat_type` varchar(250) DEFAULT NULL,
  `total_value` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `discount` decimal(13,4) DEFAULT 0.0000,
  `price` decimal(13,4) DEFAULT 0.0000,
  `amount` decimal(13,4) DEFAULT 0.0000,
  `remarks` text DEFAULT NULL,
  `added_by` int(11) NOT NULL,
  `added_on` datetime NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` datetime DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `receipt_note_additional_item` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `receipt_note_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `qty` decimal(13,4) DEFAULT 0.0000,
  `unit` int(11) NOT NULL,
  `account_type_id` int(11) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `added_by` int(11) NOT NULL,
  `added_on` datetime NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` datetime DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `receipt_note_attachment` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `receipt_note_id` int(11) NOT NULL,
  `type` varchar(255) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `file_url` longtext DEFAULT NULL,
  `mime` varchar(255) NOT NULL,
  `added_on` datetime NOT NULL DEFAULT current_timestamp(),
  `added_by` bigint(20) DEFAULT NULL,
  `updated_on` datetime DEFAULT NULL,
  `updated_by` bigint(20) DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `receipt_note_item_expiry_date` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `receipt_note_id` int(11) NOT NULL,
  `receipt_note_item_id` int(11) NOT NULL,
  `date` date DEFAULT NULL,
  `qty` decimal(13,4) DEFAULT 0.0000,
  `added_by` int(11) NOT NULL,
  `added_on` datetime NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` datetime DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `purchase_return` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `receipt_note_id` int(11) NOT NULL,
  `purchase_order_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `return_date` date NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `grand_total` decimal(13,2) NOT NULL DEFAULT 0.00,
  `status` enum('pending','approved','disapproved') NOT NULL DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `approved_on` datetime DEFAULT NULL,
  `disapproved_by` int(11) DEFAULT NULL,
  `disapproved_on` datetime DEFAULT NULL,
  `disapproved_reason` text DEFAULT NULL,
  `added_by` int(11) NOT NULL,
  `added_on` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `is_deleted` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `purchase_return_item` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `purchase_return_id` int(11) NOT NULL,
  `receipt_note_id` int(11) NOT NULL,
  `receipt_note_item_id` int(11) NOT NULL,
  `purchase_order_id` int(11) DEFAULT NULL,
  `purchase_order_item_id` int(11) DEFAULT NULL,
  `account_type_id` int(11) NOT NULL,
  `cost_center_id` int(11) DEFAULT NULL,
  `item_id` int(11) DEFAULT NULL,
  `qty` decimal(13,2) NOT NULL DEFAULT 0.00,
  `unit` int(11) DEFAULT NULL,
  `price` decimal(13,2) DEFAULT NULL,
  `amount` decimal(13,2) DEFAULT NULL,
  `total` decimal(13,2) DEFAULT NULL,
  `vat` decimal(13,2) DEFAULT NULL,
  `vat_type` varchar(50) DEFAULT NULL,
  `total_value` decimal(13,2) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `added_by` int(11) NOT NULL,
  `added_on` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `is_deleted` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `purchase_request` ADD COLUMN IF NOT EXISTS branch_id INT NULL;
CREATE INDEX IF NOT EXISTS idx_shared_owner ON `purchase_request`(branch_id,is_deleted,id);

ALTER TABLE `aoq` ADD COLUMN IF NOT EXISTS branch_id INT NULL;
CREATE INDEX IF NOT EXISTS idx_shared_owner ON `aoq`(branch_id,is_deleted,id);

ALTER TABLE `purchase_order` ADD COLUMN IF NOT EXISTS branch_id INT NULL;
CREATE INDEX IF NOT EXISTS idx_shared_owner ON `purchase_order`(branch_id,is_deleted,id);

ALTER TABLE `receipt_note` ADD COLUMN IF NOT EXISTS branch_id INT NULL;
CREATE INDEX IF NOT EXISTS idx_shared_owner ON `receipt_note`(branch_id,is_deleted,id);

ALTER TABLE `purchase_return` ADD COLUMN IF NOT EXISTS branch_id INT NULL;
CREATE INDEX IF NOT EXISTS idx_shared_owner ON `purchase_return`(branch_id,is_deleted,id);

ALTER TABLE receipt_note ADD COLUMN IF NOT EXISTS inventory_branch_id INT NULL;
CREATE TABLE IF NOT EXISTS shared_procurement_number(code VARCHAR(50) NOT NULL,year INT NOT NULL,last_number INT NOT NULL,PRIMARY KEY(code,year)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS shared_procurement_inventory(id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,document_type VARCHAR(40) NOT NULL,document_id INT NOT NULL,branch_id INT NOT NULL,inventory_branch_id INT NOT NULL,added_by INT NOT NULL,added_on DATETIME NOT NULL,UNIQUE KEY document_once(document_type,document_id)) ENGINE=InnoDB;

-- Apply to each of the seven purchasing schemas, using the matching environment.
-- Additive only: no source records, GL rows or journal entries are changed.
CREATE TABLE IF NOT EXISTS approval_reminder_sequence (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    module VARCHAR(32) NOT NULL,
    document_id INT NOT NULL,
    branch_id INT NOT NULL,
    stage VARCHAR(40) NOT NULL,
    creator_id INT NULL,
    triggered_by INT NULL,
    triggered_on DATETIME NOT NULL,
    next_eod_on DATETIME NOT NULL,
    status ENUM('active','cancelled') NOT NULL DEFAULT 'active',
    cancelled_on DATETIME NULL,
    PRIMARY KEY (id),
    KEY document_stage (module,document_id,status),
    KEY eod_due (status,next_eod_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS approval_reminder_delivery (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sequence_id BIGINT UNSIGNED NOT NULL,
    delivery_key VARCHAR(40) NOT NULL,
    due_on DATETIME NOT NULL,
    status ENUM('pending','processing','sent','logged','failed','uncertain','cancelled','skipped') NOT NULL DEFAULT 'pending',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    claimed_on DATETIME NULL,
    completed_on DATETIME NULL,
    recipient_to TEXT NULL,
    recipient_cc TEXT NULL,
    error_message TEXT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY sequence_delivery (sequence_id,delivery_key),
    KEY due_delivery (status,due_on),
    CONSTRAINT approval_reminder_delivery_sequence FOREIGN KEY (sequence_id)
        REFERENCES approval_reminder_sequence(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Ownership traversal indexes.
CREATE INDEX IF NOT EXISTS idx_shared_parent ON `purchase_request_item`(purchase_request_id,is_deleted,id);
CREATE INDEX IF NOT EXISTS idx_shared_parent ON `purchase_request_attachment`(purchase_request_id,is_deleted,id);
CREATE INDEX IF NOT EXISTS idx_shared_parent ON `aoq_detail`(aoq_id,is_deleted,id);
CREATE INDEX IF NOT EXISTS idx_shared_parent ON `purchase_order_item`(purchase_order_id,is_deleted,id);
CREATE INDEX IF NOT EXISTS idx_shared_parent ON `receipt_note_item`(receipt_note_id,is_deleted,id);
CREATE INDEX IF NOT EXISTS idx_shared_parent ON `receipt_note_additional_item`(receipt_note_id,is_deleted,id);
CREATE INDEX IF NOT EXISTS idx_shared_parent ON `receipt_note_attachment`(receipt_note_id,is_deleted,id);
CREATE INDEX IF NOT EXISTS idx_shared_parent ON `receipt_note_item_expiry_date`(receipt_note_id,is_deleted,id);
CREATE INDEX IF NOT EXISTS idx_shared_parent ON `purchase_return_item`(purchase_return_id,is_deleted,id);

-- Shared procurement only: master accounting at 0. No backfill.
DELIMITER $$
DROP FUNCTION IF EXISTS gl_rn_vat_type$$
CREATE FUNCTION gl_rn_vat_type(p_line VARCHAR(100), p_item VARCHAR(100))
RETURNS VARCHAR(100)
DETERMINISTIC
NO SQL
BEGIN
    RETURN LOWER(TRIM(COALESCE(NULLIF(p_line, ''), NULLIF(p_item, ''), '')));
END$$
DROP FUNCTION IF EXISTS gl_rn_net$$
CREATE FUNCTION gl_rn_net(p_amount DECIMAL(13,4), p_vat DECIMAL(13,4), p_type VARCHAR(100))
RETURNS DECIMAL(13,4)
DETERMINISTIC
NO SQL
BEGIN
    IF IFNULL(p_amount, 0) = 0 THEN
        RETURN 0;
    END IF;
    IF p_type = 'vat_in' THEN
        IF p_vat IS NOT NULL THEN
            RETURN p_amount - p_vat;
        END IF;
        RETURN ROUND(p_amount / 1.12, 4);
    END IF;
    RETURN p_amount;
END$$
DROP FUNCTION IF EXISTS gl_rn_vat_amt$$
CREATE FUNCTION gl_rn_vat_amt(p_amount DECIMAL(13,4), p_vat DECIMAL(13,4), p_type VARCHAR(100))
RETURNS DECIMAL(13,4)
DETERMINISTIC
NO SQL
BEGIN
    IF p_type IN ('nvat', 'exempt', 'vat_exempt', 'zero_rated', 'zero-rated', '0rated') THEN
        RETURN 0;
    END IF;
    IF p_type = 'vat_in' THEN
        IF p_vat IS NOT NULL THEN
            RETURN p_vat;
        END IF;
        RETURN ROUND(p_amount - (p_amount / 1.12), 4);
    END IF;
    IF p_vat IS NOT NULL THEN
        RETURN p_vat;
    END IF;
    IF IFNULL(p_type, '') = '' THEN
        RETURN 0;
    END IF;
    RETURN ROUND(p_amount * 0.12, 4);
END$$
DROP PROCEDURE IF EXISTS gl_shared_post_purchase_request$$
CREATE PROCEDURE gl_shared_post_purchase_request(IN p_id INT)
proc: BEGIN
    DECLARE v_done INT DEFAULT 0;
    DECLARE v_item_id INT;
    DECLARE item_cursor CURSOR FOR SELECT id FROM purchase_request_item WHERE purchase_request_id = p_id;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

    IF NOT EXISTS (SELECT 1 FROM purchase_request s JOIN branch b ON b.id=s.branch_id WHERE s.id=p_id AND b.type='shared' AND b.is_deleted=0) THEN LEAVE proc; END IF;
    OPEN item_cursor;
    clear_loop: LOOP
        FETCH item_cursor INTO v_item_id;
        IF v_done = 1 THEN
            LEAVE clear_loop;
        END IF;
        CALL gl_shared_clear('purchase_request_item', v_item_id);
    END LOOP;
    CLOSE item_cursor;

END$$
DROP PROCEDURE IF EXISTS gl_shared_post_purchase_order$$
CREATE PROCEDURE gl_shared_post_purchase_order(IN p_id INT)
proc: BEGIN
    DECLARE v_pr_id INT;
    DECLARE v_done INT DEFAULT 0;
    DECLARE v_item_id INT;
    DECLARE item_cursor CURSOR FOR SELECT id FROM purchase_order_item WHERE purchase_order_id = p_id;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

    IF NOT EXISTS (SELECT 1 FROM purchase_order s JOIN branch b ON b.id=s.branch_id WHERE s.id=p_id AND b.type='shared' AND b.is_deleted=0) THEN LEAVE proc; END IF;
    OPEN item_cursor;
    clear_loop: LOOP
        FETCH item_cursor INTO v_item_id;
        IF v_done = 1 THEN
            LEAVE clear_loop;
        END IF;
        CALL gl_shared_clear('purchase_order_item', v_item_id);
    END LOOP;
    CLOSE item_cursor;


    SELECT purchase_request_id INTO v_pr_id FROM purchase_order WHERE id = p_id LIMIT 1;
    IF v_pr_id IS NOT NULL THEN
        CALL gl_shared_post_purchase_request(v_pr_id);
    END IF;
END$$
DROP PROCEDURE IF EXISTS gl_shared_post_receipt_note$$
CREATE PROCEDURE gl_shared_post_receipt_note(IN p_id INT)
proc: BEGIN
    DECLARE v_po_id INT;
    DECLARE v_done INT DEFAULT 0;
    DECLARE v_item_id INT;
    DECLARE item_cursor CURSOR FOR SELECT id FROM receipt_note_item WHERE receipt_note_id = p_id;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

    IF NOT EXISTS (SELECT 1 FROM receipt_note s JOIN branch b ON b.id=s.branch_id WHERE s.id=p_id AND b.type='shared' AND b.is_deleted=0) THEN LEAVE proc; END IF;
    OPEN item_cursor;
    clear_loop: LOOP
        FETCH item_cursor INTO v_item_id;
        IF v_done = 1 THEN
            LEAVE clear_loop;
        END IF;
        CALL gl_shared_clear('receipt_note_item', v_item_id);
    END LOOP;
    CLOSE item_cursor;

    INSERT INTO general_ledger (
        branch_id, source, source_id, account_type_id, date,
        debit, credit, remark, added_by, added_on, is_deleted
    )
    SELECT 0,
        'receipt_note_item',
        line.id,
        line.inv_account_id,
        line.txn_date,
        line.net_amt,
        0,
        CONCAT('Goods received: ', line.item_name, ' #', line.id, ' | receipt_note #', line.receipt_note_id),
        line.added_by,
        NOW(),
        0
    FROM (
        SELECT receipt_note_item.id,
            receipt_note_item.receipt_note_id,
            receipt_note_item.added_by,
            COALESCE(NULLIF(item.name, ''), CONCAT('Item #', receipt_note_item.item_id)) AS item_name,
            COALESCE(receipt_note.receive_date, receipt_note.needed_date, DATE(receipt_note.added_on)) AS txn_date,
            COALESCE(receipt_note_item.account_type_id, item.account_type_id, gl_account_id('104-030')) AS inv_account_id,
            gl_rn_net(
                COALESCE(receipt_note_item.amount * COALESCE(receipt_note_item.qty, 0), receipt_note_item.total, 0),
                receipt_note_item.vat,
                gl_rn_vat_type(receipt_note_item.vat_type, item.vat_type)
            ) AS net_amt
        FROM receipt_note_item
        INNER JOIN receipt_note ON receipt_note.id = receipt_note_item.receipt_note_id
            AND receipt_note.is_deleted = 0
            AND receipt_note.status = 'approved'
        LEFT JOIN item AS item ON item.id = receipt_note_item.item_id AND item.is_deleted = 0
        WHERE receipt_note.id = p_id
            AND receipt_note_item.is_deleted = 0
            AND COALESCE(receipt_note_item.amount * COALESCE(receipt_note_item.qty, 0), receipt_note_item.total, 0) <> 0
    ) AS line
    WHERE line.net_amt <> 0
        AND line.inv_account_id IS NOT NULL;

    INSERT INTO general_ledger (
        branch_id, source, source_id, account_type_id, date,
        debit, credit, remark, added_by, added_on, is_deleted
    )
    SELECT 0,
        'receipt_note_item',
        line.id,
        line.vat_account_id,
        line.txn_date,
        line.vat_amt,
        0,
        CONCAT('Input VAT goods received: ', line.item_name, ' #', line.id, ' | receipt_note #', line.receipt_note_id),
        line.added_by,
        NOW(),
        0
    FROM (
        SELECT receipt_note_item.id,
            receipt_note_item.receipt_note_id,
            receipt_note_item.added_by,
            COALESCE(NULLIF(item.name, ''), CONCAT('Item #', receipt_note_item.item_id)) AS item_name,
            COALESCE(receipt_note.receive_date, receipt_note.needed_date, DATE(receipt_note.added_on)) AS txn_date,
            gl_account_id('106-000') AS vat_account_id,
            gl_rn_vat_amt(
                COALESCE(receipt_note_item.amount * COALESCE(receipt_note_item.qty, 0), receipt_note_item.total, 0),
                receipt_note_item.vat,
                gl_rn_vat_type(receipt_note_item.vat_type, item.vat_type)
            ) AS vat_amt
        FROM receipt_note_item
        INNER JOIN receipt_note ON receipt_note.id = receipt_note_item.receipt_note_id
            AND receipt_note.is_deleted = 0
            AND receipt_note.status = 'approved'
        LEFT JOIN item AS item ON item.id = receipt_note_item.item_id AND item.is_deleted = 0
        WHERE receipt_note.id = p_id
            AND receipt_note_item.is_deleted = 0
            AND COALESCE(receipt_note_item.amount * COALESCE(receipt_note_item.qty, 0), receipt_note_item.total, 0) <> 0
    ) AS line
    WHERE line.vat_amt <> 0
        AND line.vat_account_id IS NOT NULL;

    INSERT INTO general_ledger (
        branch_id, source, source_id, account_type_id, date,
        debit, credit, remark, added_by, added_on, is_deleted
    )
    SELECT 0,
        'receipt_note_item',
        line.id,
        gl_account_id('201-110'),
        line.txn_date,
        0,
        line.net_amt + line.vat_amt,
        CONCAT('AP transitory goods received: ', line.item_name, ' #', line.id, ' | receipt_note #', line.receipt_note_id),
        line.added_by,
        NOW(),
        0
    FROM (
        SELECT receipt_note_item.id,
            receipt_note_item.receipt_note_id,
            receipt_note_item.added_by,
            COALESCE(NULLIF(item.name, ''), CONCAT('Item #', receipt_note_item.item_id)) AS item_name,
            COALESCE(receipt_note.receive_date, receipt_note.needed_date, DATE(receipt_note.added_on)) AS txn_date,
            gl_rn_net(
                COALESCE(receipt_note_item.amount * COALESCE(receipt_note_item.qty, 0), receipt_note_item.total, 0),
                receipt_note_item.vat,
                gl_rn_vat_type(receipt_note_item.vat_type, item.vat_type)
            ) AS net_amt,
            gl_rn_vat_amt(
                COALESCE(receipt_note_item.amount * COALESCE(receipt_note_item.qty, 0), receipt_note_item.total, 0),
                receipt_note_item.vat,
                gl_rn_vat_type(receipt_note_item.vat_type, item.vat_type)
            ) AS vat_amt
        FROM receipt_note_item
        INNER JOIN receipt_note ON receipt_note.id = receipt_note_item.receipt_note_id
            AND receipt_note.is_deleted = 0
            AND receipt_note.status = 'approved'
        LEFT JOIN item AS item ON item.id = receipt_note_item.item_id AND item.is_deleted = 0
        WHERE receipt_note.id = p_id
            AND receipt_note_item.is_deleted = 0
            AND COALESCE(receipt_note_item.amount * COALESCE(receipt_note_item.qty, 0), receipt_note_item.total, 0) <> 0
    ) AS line
    WHERE (line.net_amt + line.vat_amt) <> 0
        AND gl_account_id('201-110') IS NOT NULL;

    SELECT purchase_order_id INTO v_po_id FROM receipt_note WHERE id = p_id LIMIT 1;
    IF v_po_id IS NOT NULL THEN
        CALL gl_shared_post_purchase_order(v_po_id);
    END IF;
    BEGIN
        DECLARE done INT DEFAULT 0;
        DECLARE cid INT;
        DECLARE c CURSOR FOR SELECT id FROM receipt_note_item WHERE receipt_note_id=p_id;
        DECLARE CONTINUE HANDLER FOR NOT FOUND SET done=1;
        OPEN c;
        journals: LOOP
            FETCH c INTO cid;
            IF done=1 THEN LEAVE journals; END IF;
            CALL gl_shared_je('receipt_note_item',cid);
        END LOOP;
        CLOSE c;
    END;
END$$
DROP TRIGGER IF EXISTS trg_gl_shared_purchase_request_ai$$
CREATE TRIGGER trg_gl_shared_purchase_request_ai AFTER INSERT ON purchase_request
FOR EACH ROW BEGIN CALL gl_shared_post_purchase_request(NEW.id); END$$
DROP TRIGGER IF EXISTS trg_gl_shared_purchase_request_au$$
CREATE TRIGGER trg_gl_shared_purchase_request_au AFTER UPDATE ON purchase_request
FOR EACH ROW BEGIN CALL gl_shared_post_purchase_request(NEW.id); END$$
DROP TRIGGER IF EXISTS trg_gl_shared_purchase_order_ai$$
CREATE TRIGGER trg_gl_shared_purchase_order_ai AFTER INSERT ON purchase_order
FOR EACH ROW BEGIN CALL gl_shared_post_purchase_order(NEW.id); END$$
DROP TRIGGER IF EXISTS trg_gl_shared_purchase_order_au$$
CREATE TRIGGER trg_gl_shared_purchase_order_au AFTER UPDATE ON purchase_order
FOR EACH ROW BEGIN CALL gl_shared_post_purchase_order(NEW.id); END$$
DROP TRIGGER IF EXISTS trg_gl_shared_receipt_note_ai$$
CREATE TRIGGER trg_gl_shared_receipt_note_ai AFTER INSERT ON receipt_note
FOR EACH ROW BEGIN CALL gl_shared_post_receipt_note(NEW.id); END$$
DROP TRIGGER IF EXISTS trg_gl_shared_receipt_note_au$$
CREATE TRIGGER trg_gl_shared_receipt_note_au AFTER UPDATE ON receipt_note
FOR EACH ROW BEGIN CALL gl_shared_post_receipt_note(NEW.id); END$$
DROP TRIGGER IF EXISTS trg_gl_shared_purchase_request_item_ai$$
CREATE TRIGGER trg_gl_shared_purchase_request_item_ai AFTER INSERT ON purchase_request_item
FOR EACH ROW BEGIN CALL gl_shared_post_purchase_request(NEW.purchase_request_id); END$$
DROP TRIGGER IF EXISTS trg_gl_shared_purchase_request_item_au$$
CREATE TRIGGER trg_gl_shared_purchase_request_item_au AFTER UPDATE ON purchase_request_item
FOR EACH ROW BEGIN CALL gl_shared_post_purchase_request(NEW.purchase_request_id); IF OLD.purchase_request_id<>NEW.purchase_request_id THEN CALL gl_shared_post_purchase_request(OLD.purchase_request_id); END IF; END$$
DROP TRIGGER IF EXISTS trg_gl_shared_purchase_request_item_ad$$
CREATE TRIGGER trg_gl_shared_purchase_request_item_ad AFTER DELETE ON purchase_request_item
FOR EACH ROW BEGIN CALL gl_shared_clear('purchase_request_item',OLD.id); CALL gl_shared_post_purchase_request(OLD.purchase_request_id); END$$
DROP TRIGGER IF EXISTS trg_gl_shared_purchase_order_item_ai$$
CREATE TRIGGER trg_gl_shared_purchase_order_item_ai AFTER INSERT ON purchase_order_item
FOR EACH ROW BEGIN CALL gl_shared_post_purchase_order(NEW.purchase_order_id); END$$
DROP TRIGGER IF EXISTS trg_gl_shared_purchase_order_item_au$$
CREATE TRIGGER trg_gl_shared_purchase_order_item_au AFTER UPDATE ON purchase_order_item
FOR EACH ROW BEGIN CALL gl_shared_post_purchase_order(NEW.purchase_order_id); IF OLD.purchase_order_id<>NEW.purchase_order_id THEN CALL gl_shared_post_purchase_order(OLD.purchase_order_id); END IF; END$$
DROP TRIGGER IF EXISTS trg_gl_shared_purchase_order_item_ad$$
CREATE TRIGGER trg_gl_shared_purchase_order_item_ad AFTER DELETE ON purchase_order_item
FOR EACH ROW BEGIN CALL gl_shared_clear('purchase_order_item',OLD.id); CALL gl_shared_post_purchase_order(OLD.purchase_order_id); END$$
DROP TRIGGER IF EXISTS trg_gl_shared_receipt_note_item_ai$$
CREATE TRIGGER trg_gl_shared_receipt_note_item_ai AFTER INSERT ON receipt_note_item
FOR EACH ROW BEGIN CALL gl_shared_post_receipt_note(NEW.receipt_note_id); END$$
DROP TRIGGER IF EXISTS trg_gl_shared_receipt_note_item_au$$
CREATE TRIGGER trg_gl_shared_receipt_note_item_au AFTER UPDATE ON receipt_note_item
FOR EACH ROW BEGIN CALL gl_shared_post_receipt_note(NEW.receipt_note_id); IF OLD.receipt_note_id<>NEW.receipt_note_id THEN CALL gl_shared_post_receipt_note(OLD.receipt_note_id); END IF; END$$
DROP TRIGGER IF EXISTS trg_gl_shared_receipt_note_item_ad$$
CREATE TRIGGER trg_gl_shared_receipt_note_item_ad AFTER DELETE ON receipt_note_item
FOR EACH ROW BEGIN CALL gl_shared_clear('receipt_note_item',OLD.id); CALL gl_shared_post_receipt_note(OLD.receipt_note_id); END$$
DELIMITER ;
