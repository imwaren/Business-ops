-- One-time per clinic DB after recreating sql/general_ledger_triggers.sql
-- (needs Charge-to DR 102-113B / CR per-exam revenue, YAKAP 0/0 on
-- 102-113B / 400-060, and gl_item_history_remarks).
-- Drains live transactions as unbilled so Charge-to rebuilds on the txn JE.
-- Drains live perform / Machine Run / lab supplies / pharmacy item_history
-- so GL and JE remarks become item_history #id, item #item_id, transaction #txn_no.
-- Leftover HMO/Corp unbilled is rebuilt by the same unbilled drain.
-- Recreate sql/general_ledger_triggers.sql after this file.
-- Safe to re-run. Do not run from chat.

DROP TEMPORARY TABLE IF EXISTS gl_bf_ids;
CREATE TEMPORARY TABLE gl_bf_ids (
    id INT NOT NULL,
    PRIMARY KEY (id)
);

INSERT INTO gl_bf_ids
SELECT `transaction`.id
FROM `transaction`
WHERE `transaction`.is_deleted = 0;

CALL gl_bf_drain('unbilled');

DROP TEMPORARY TABLE IF EXISTS gl_bf_ids;
CREATE TEMPORARY TABLE gl_bf_ids (
    id INT NOT NULL,
    PRIMARY KEY (id)
);

INSERT INTO gl_bf_ids
SELECT item_history.id
FROM item_history
WHERE item_history.is_deleted = 0
    AND item_history.activity IN ('perform', 'Machine Run', 'lab supplies', 'pharmacy');

CALL gl_bf_drain('item_history');

DROP TEMPORARY TABLE IF EXISTS gl_bf_ids;

-- Recreate sql/general_ledger_triggers.sql after this file.
