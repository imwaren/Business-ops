-- One-time per clinic DB after recreating sql/general_ledger_triggers.sql.
-- Exam inventory consumption (perform / Machine Run / lab supplies) always
-- DR 500-205 LABORATORY SUPPLIES / CR inventory. Does not use
-- item.account_type_id (often a VAT account).
-- Safe to re-run because gl_post_item_history clears its own live GL first.
-- Recreate sql/general_ledger_triggers.sql after this file.
-- Do not run from chat.

DROP TEMPORARY TABLE IF EXISTS gl_bf_ids;
CREATE TEMPORARY TABLE gl_bf_ids (
    id INT NOT NULL,
    PRIMARY KEY (id)
);

INSERT INTO gl_bf_ids
SELECT item_history.id
FROM item_history
WHERE item_history.is_deleted = 0
    AND item_history.activity IN ('perform', 'Machine Run', 'lab supplies');

CALL gl_bf_drain('item_history');

-- Recreate sql/general_ledger_triggers.sql after this file.
