-- One-time per clinic after sql/migrations/journal_entry_source.sql.
-- Drops trg_gl_journal_entry_* so this load cannot post GL (#1442).
-- After this file, recreate sql/general_ledger_triggers.sql
-- (phpMyAdmin delimiter $$) so live GL→JE and the source-NULL skip are installed.
--
-- Builds one approved journal_entry + items from live general_ledger
-- (source, source_id), excluding source journal_entry / empty.
-- Skips pairs that already have a live journal_entry.
--
-- Do not also run the master block in the same session.

DROP TRIGGER IF EXISTS trg_gl_journal_entry_ai;
DROP TRIGGER IF EXISTS trg_gl_journal_entry_au;
DROP TRIGGER IF EXISTS trg_gl_journal_entry_item_ai;
DROP TRIGGER IF EXISTS trg_gl_journal_entry_item_au;

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
FROM general_ledger gl
WHERE gl.is_deleted = 0
    AND IFNULL(gl.source, '') <> ''
    AND gl.source <> 'journal_entry';

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
FROM gl_je_snap snap
WHERE NOT EXISTS (
    SELECT 1
    FROM journal_entry je
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
FROM gl_je_snap snap
INNER JOIN journal_entry je
    ON je.source = snap.source
    AND je.source_id = snap.source_id
    AND je.is_deleted = 0
WHERE NOT EXISTS (
    SELECT 1
    FROM journal_entry_item ji
    WHERE ji.journal_entry_id = je.id
        AND ji.is_deleted = 0
)
ORDER BY snap.id;

DROP TEMPORARY TABLE IF EXISTS gl_je_snap;

-- Recreate sql/general_ledger_triggers.sql after this file.

-- Optional: run on mytemr_master instead (not in the same session as above).
-- After the master block, recreate sql/general_ledger_triggers_master.sql.
-- Beginning JEs have source NULL and are skipped (GL source journal_entry / empty).
--
-- DROP TRIGGER IF EXISTS trg_gl_journal_entry_ai;
-- DROP TRIGGER IF EXISTS trg_gl_journal_entry_au;
-- DROP TRIGGER IF EXISTS trg_gl_journal_entry_item_ai;
-- DROP TRIGGER IF EXISTS trg_gl_journal_entry_item_au;
--
-- DROP TEMPORARY TABLE IF EXISTS gl_je_snap;
-- CREATE TEMPORARY TABLE gl_je_snap (
--     id INT NOT NULL,
--     source VARCHAR(250) NOT NULL,
--     source_id INT NOT NULL,
--     account_type_id INT DEFAULT NULL,
--     date DATE NOT NULL,
--     debit DECIMAL(13,4) DEFAULT 0.0000,
--     credit DECIMAL(13,4) DEFAULT 0.0000,
--     remark LONGTEXT DEFAULT NULL,
--     added_by INT DEFAULT NULL,
--     PRIMARY KEY (id),
--     KEY idx_gl_je_snap_source (source, source_id)
-- );
--
-- INSERT INTO gl_je_snap (
--     id, source, source_id, account_type_id, date, debit, credit, remark, added_by
-- )
-- SELECT
--     gl.id,
--     gl.source,
--     gl.source_id,
--     gl.account_type_id,
--     gl.date,
--     gl.debit,
--     gl.credit,
--     gl.remark,
--     gl.added_by
-- FROM general_ledger gl
-- WHERE gl.is_deleted = 0
--     AND IFNULL(gl.source, '') <> ''
--     AND gl.source <> 'journal_entry';
--
-- INSERT INTO journal_entry (
--     cost_center_id, date, type, remarks, status,
--     total_debit, total_credit, source, source_id,
--     added_by, added_on, is_deleted
-- )
-- SELECT
--     NULL,
--     MIN(snap.date),
--     LEFT(snap.source, 64),
--     CONCAT(snap.source, ' #', snap.source_id),
--     'approved',
--     IFNULL(SUM(snap.debit), 0),
--     IFNULL(SUM(snap.credit), 0),
--     snap.source,
--     snap.source_id,
--     IFNULL(MIN(snap.added_by), 0),
--     NOW(),
--     0
-- FROM gl_je_snap snap
-- WHERE NOT EXISTS (
--     SELECT 1
--     FROM journal_entry je
--     WHERE je.source = snap.source
--         AND je.source_id = snap.source_id
--         AND je.is_deleted = 0
-- )
-- GROUP BY snap.source, snap.source_id;
--
-- INSERT INTO journal_entry_item (
--     journal_entry_id, account_type_id,
--     debit, credit, remarks, added_by, added_on, is_deleted
-- )
-- SELECT
--     je.id,
--     snap.account_type_id,
--     IFNULL(snap.debit, 0),
--     IFNULL(snap.credit, 0),
--     snap.remark,
--     IFNULL(snap.added_by, 0),
--     NOW(),
--     0
-- FROM gl_je_snap snap
-- INNER JOIN journal_entry je
--     ON je.source = snap.source
--     AND je.source_id = snap.source_id
--     AND je.is_deleted = 0
-- WHERE NOT EXISTS (
--     SELECT 1
--     FROM journal_entry_item ji
--     WHERE ji.journal_entry_id = je.id
--         AND ji.is_deleted = 0
-- )
-- ORDER BY snap.id;
--
-- DROP TEMPORARY TABLE IF EXISTS gl_je_snap;
