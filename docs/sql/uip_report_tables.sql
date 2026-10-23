-- UIP report read model.
-- Run the CREATE TABLE statements on mytemr_reports before deploying the API changes.
-- The final ALTER is required only on Oakridge; the other branch patient tables
-- already have idx_master_id according to production information_schema.STATISTICS.

CREATE TABLE IF NOT EXISTS mytemr_reports.uip_transaction (
    branch_id        INT NOT NULL,
    transaction_id   BIGINT NOT NULL,
    master_id        BIGINT NOT NULL,
    transaction_date DATE NOT NULL,
    added_on         DATETIME NOT NULL,
    grand_total      DECIMAL(15, 2) NOT NULL DEFAULT 0,
    is_deleted       TINYINT(1) NOT NULL DEFAULT 0,
    synced_on        DATETIME NOT NULL,
    PRIMARY KEY (branch_id, transaction_id),
    KEY idx_uip_tx_window (added_on, is_deleted, master_id),
    KEY idx_uip_tx_master (master_id, is_deleted, transaction_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mytemr_reports.uip_patient_demo (
    master_id BIGINT NOT NULL,
    gender    VARCHAR(10) NULL,
    birthdate DATE NULL,
    synced_on DATETIME NOT NULL,
    PRIMARY KEY (master_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per patient/activity day. Report date filters scan this compact table
-- instead of every transaction in a multi-year range.
CREATE TABLE IF NOT EXISTS mytemr_reports.uip_patient_activity (
    activity_date DATE NOT NULL,
    master_id     BIGINT NOT NULL,
    synced_on     DATETIME NOT NULL,
    PRIMARY KEY (activity_date, master_id),
    KEY idx_uip_activity_master (master_id, activity_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Current lifetime metrics used by date_to=today requests. Keeping these metrics
-- out of the HTTP request is what prevents multi-year reports from timing out.
CREATE TABLE IF NOT EXISTS mytemr_reports.uip_patient_snapshot (
    master_id             BIGINT NOT NULL,
    transaction_count     BIGINT NOT NULL DEFAULT 0,
    total_sales           DECIMAL(18, 2) NOT NULL DEFAULT 0,
    first_transaction_date DATE NULL,
    last_transaction_date DATE NULL,
    last_return_date      DATE NULL,
    post_lost_tx_count    BIGINT NOT NULL DEFAULT 0,
    gender                VARCHAR(10) NULL,
    birthdate             DATE NULL,
    synced_on             DATETIME NOT NULL,
    PRIMARY KEY (master_id),
    KEY idx_uip_snapshot_last_tx (last_transaction_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The API reads this marker, so an initial backfill is never exposed halfway.
CREATE TABLE IF NOT EXISTS mytemr_reports.uip_refresh_state (
    id            TINYINT NOT NULL,
    coverage_from DATE NOT NULL,
    coverage_to   DATE NOT NULL,
    data_as_of    DATETIME NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Oakridge only. Do not run if the index was added after the production check.
ALTER TABLE mytemr_diagnostic.patient
    ADD INDEX idx_master_id (master_id);
