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
