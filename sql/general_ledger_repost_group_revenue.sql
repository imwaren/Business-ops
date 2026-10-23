-- One-time per clinic DB after recreating sql/general_ledger_triggers.sql.
-- LABORATORY/DIAGNOSTICS (400-010) vs HEALTH SERVICES (400-020) from
-- lab_test.group_id (1 or 2 vs else including NULL). Holter / BP MONITORING
-- is 400-040. Consult / sendout / YAKAP / pharmacy / vaxx / eye stay separate.
-- Drains all live CSR mop, discount, and unbilled. JE rebuilds via
-- gl_rebuild_txn_je. Safe to re-run because each poster clears its own live GL.
-- Recreate sql/general_ledger_triggers.sql after this file.
-- Do not run from chat.

DROP TEMPORARY TABLE IF EXISTS gl_bf_ids;
CREATE TEMPORARY TABLE gl_bf_ids (
    id INT NOT NULL,
    PRIMARY KEY (id)
);

INSERT INTO gl_bf_ids
SELECT payment_multiple_method.id
FROM payment_multiple_method
WHERE payment_multiple_method.is_deleted = 0;

CALL gl_bf_drain('csr_mop');

DROP TEMPORARY TABLE IF EXISTS gl_bf_ids;
CREATE TEMPORARY TABLE gl_bf_ids (
    id INT NOT NULL,
    PRIMARY KEY (id)
);

INSERT INTO gl_bf_ids
SELECT payment.id
FROM payment
WHERE payment.is_deleted = 0
    AND IFNULL(payment.discount, 0) > 0;

CALL gl_bf_drain('csr_discount');

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

-- Recreate sql/general_ledger_triggers.sql after this file.
