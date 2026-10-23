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
