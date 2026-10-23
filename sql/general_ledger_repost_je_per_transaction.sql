-- One-time per clinic after recreating sql/general_ledger_triggers.sql
-- (needs gl_csr_txn_id, gl_rebuild_txn_je, gl_bf_drain txn_je).
-- Soft-deletes unmigrated system journal_entry + items (source IS NOT NULL).
-- Keeps manual JEs (source NULL) and is_migrated = 1.
-- Rebuilds one JE per CSR transaction from live GL, then other sources
-- one JE per (source, source_id).
-- Drops trg_gl_journal_entry_* so this load cannot post GL.
-- Recreate sql/general_ledger_triggers.sql after this file.
-- Safe to re-run. Do not run from chat.

DROP TRIGGER IF EXISTS trg_gl_journal_entry_ai;
DROP TRIGGER IF EXISTS trg_gl_journal_entry_au;
DROP TRIGGER IF EXISTS trg_gl_journal_entry_item_ai;
DROP TRIGGER IF EXISTS trg_gl_journal_entry_item_au;

UPDATE journal_entry_item
INNER JOIN journal_entry
    ON journal_entry.id = journal_entry_item.journal_entry_id
    AND journal_entry.is_deleted = 0
    AND IFNULL(journal_entry.is_migrated, 0) = 0
    AND journal_entry.source IS NOT NULL
SET journal_entry_item.is_deleted = 1,
    journal_entry_item.updated_on = NOW()
WHERE journal_entry_item.is_deleted = 0;

UPDATE journal_entry
SET is_deleted = 1,
    updated_on = NOW()
WHERE is_deleted = 0
    AND IFNULL(is_migrated, 0) = 0
    AND source IS NOT NULL;

DROP TEMPORARY TABLE IF EXISTS gl_bf_ids;
CREATE TEMPORARY TABLE gl_bf_ids (
    id INT NOT NULL,
    PRIMARY KEY (id)
);

INSERT INTO gl_bf_ids
SELECT DISTINCT gl_csr_txn_id(gl.source, gl.source_id)
FROM general_ledger AS gl
WHERE gl.is_deleted = 0
    AND gl.source IN ('payment_multiple_method', 'payment', 'transaction')
    AND gl_csr_txn_id(gl.source, gl.source_id) IS NOT NULL;

CALL gl_bf_drain('txn_je');

DROP TEMPORARY TABLE IF EXISTS gl_je_snap;
CREATE TEMPORARY TABLE gl_je_snap (
    id INT NOT NULL,
    source VARCHAR(250) NOT NULL,
    source_id INT NOT NULL,
    account_type_id INT DEFAULT NULL,
    date DATE NOT NULL,
    debit DECIMAL(13,4) DEFAULT 0.0000,
    credit DECIMAL(13,4) DEFAULT 0.0000,
    remark LONGTEXT DEFAULT NULL,
    added_by INT DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_gl_je_snap_source (source, source_id)
);

INSERT INTO gl_je_snap (
    id, source, source_id, account_type_id, date, debit, credit, remark, added_by
)
SELECT
    gl.id,
    gl.source,
    gl.source_id,
    gl.account_type_id,
    gl.date,
    gl.debit,
    gl.credit,
    gl.remark,
    gl.added_by
FROM general_ledger AS gl
WHERE gl.is_deleted = 0
    AND IFNULL(gl.source, '') <> ''
    AND gl.source NOT IN (
        'journal_entry',
        'payment_multiple_method',
        'payment',
        'transaction'
    );

INSERT INTO journal_entry (
    cost_center_id, date, type, remarks, status,
    total_debit, total_credit, source, source_id,
    added_by, added_on, is_deleted
)
SELECT
    gl_branch_id(),
    MIN(snap.date),
    LEFT(snap.source, 64),
    CONCAT(snap.source, ' #', snap.source_id),
    'approved',
    IFNULL(SUM(snap.debit), 0),
    IFNULL(SUM(snap.credit), 0),
    snap.source,
    snap.source_id,
    IFNULL(MIN(snap.added_by), 0),
    NOW(),
    0
FROM gl_je_snap AS snap
WHERE NOT EXISTS (
    SELECT 1
    FROM journal_entry AS je
    WHERE je.source = snap.source
        AND je.source_id = snap.source_id
        AND je.is_deleted = 0
)
GROUP BY snap.source, snap.source_id;

INSERT INTO journal_entry_item (
    journal_entry_id, account_type_id,
    debit, credit, remarks, added_by, added_on, is_deleted
)
SELECT
    je.id,
    snap.account_type_id,
    IFNULL(snap.debit, 0),
    IFNULL(snap.credit, 0),
    snap.remark,
    IFNULL(snap.added_by, 0),
    NOW(),
    0
FROM gl_je_snap AS snap
INNER JOIN journal_entry AS je
    ON je.source = snap.source
    AND je.source_id = snap.source_id
    AND je.is_deleted = 0
WHERE NOT EXISTS (
    SELECT 1
    FROM journal_entry_item AS ji
    WHERE ji.journal_entry_id = je.id
        AND ji.is_deleted = 0
)
ORDER BY snap.id;

DROP TEMPORARY TABLE IF EXISTS gl_je_snap;

-- Recreate sql/general_ledger_triggers.sql after this file.
