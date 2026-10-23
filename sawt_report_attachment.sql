-- Master DB: SAWT report file attachments keyed by reporting period.

CREATE TABLE IF NOT EXISTS `sawt_report_attachment` (
  `id`         BIGINT NOT NULL AUTO_INCREMENT,
  `date_from`  DATE NOT NULL,
  `date_to`    DATE NOT NULL,
  `branch_id`  INT NULL COMMENT 'NULL = all branches',
  `file_name`  VARCHAR(255) NOT NULL,
  `file_path`  VARCHAR(255) DEFAULT NULL,
  `file_url`   LONGTEXT DEFAULT NULL,
  `mime`       VARCHAR(255) NOT NULL,
  `added_on`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `added_by`   BIGINT DEFAULT NULL,
  `updated_on` DATETIME DEFAULT NULL,
  `updated_by` BIGINT DEFAULT NULL,
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_sawt_period` (`date_from`, `date_to`, `branch_id`, `is_deleted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
