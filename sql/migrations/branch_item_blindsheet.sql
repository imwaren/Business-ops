-- Run in master and all seven inventory schemas before enabling the PHP endpoints.
-- Idempotent additive schema only: no historical linkage, inventory updates, or GL/JE rebuilds.
CREATE TABLE IF NOT EXISTS branch_item_blindsheet (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    branch_item_id INT NOT NULL,
    branch_id INT NOT NULL,
    department_id INT DEFAULT NULL,
    item_id INT NOT NULL,
    counted_by_id VARCHAR(255) DEFAULT NULL,
    physical_count DECIMAL(13,2) DEFAULT 0.00,
    difference DECIMAL(13,2) DEFAULT 0.00,
    prev_qty DECIMAL(13,2) NOT NULL DEFAULT 0.00,
    date_counted DATE NOT NULL,
    remarks TEXT DEFAULT NULL,
    status ENUM('pending','approved','disapproved') NOT NULL DEFAULT 'pending',
    approved_by INT DEFAULT NULL,
    approved_on DATETIME DEFAULT NULL,
    disapproved_by INT DEFAULT NULL,
    disapproved_on DATETIME DEFAULT NULL,
    added_by INT NOT NULL,
    added_on DATETIME NOT NULL,
    updated_by INT DEFAULT NULL,
    updated_on DATETIME DEFAULT NULL,
    is_deleted TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
DROP PROCEDURE IF EXISTS blindsheet_schema_prepare$$
CREATE PROCEDURE blindsheet_schema_prepare()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'branch_item_blindsheet' AND COLUMN_NAME = 'added_by_branch_id') THEN
        ALTER TABLE branch_item_blindsheet ADD COLUMN added_by_branch_id INT DEFAULT NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'branch_item_blindsheet' AND COLUMN_NAME = 'updated_by_branch_id') THEN
        ALTER TABLE branch_item_blindsheet ADD COLUMN updated_by_branch_id INT DEFAULT NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'branch_item_blindsheet' AND COLUMN_NAME = 'approved_by_branch_id') THEN
        ALTER TABLE branch_item_blindsheet ADD COLUMN approved_by_branch_id INT DEFAULT NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'branch_item_blindsheet' AND COLUMN_NAME = 'disapproved_by_branch_id') THEN
        ALTER TABLE branch_item_blindsheet ADD COLUMN disapproved_by_branch_id INT DEFAULT NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'branch_item_blindsheet' AND INDEX_NAME = 'idx_blindsheet_pending') THEN
        ALTER TABLE branch_item_blindsheet ADD INDEX idx_blindsheet_pending (branch_item_id, branch_id, status, is_deleted);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'branch_item_blindsheet' AND INDEX_NAME = 'idx_blindsheet_branch') THEN
        ALTER TABLE branch_item_blindsheet ADD INDEX idx_blindsheet_branch (branch_id, item_id, is_deleted);
    END IF;
END$$
CALL blindsheet_schema_prepare()$$
DROP PROCEDURE blindsheet_schema_prepare$$
DELIMITER ;
