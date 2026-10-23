-- Execute under the release write pause. Reruns must never advance the cutoffs.
CREATE TABLE IF NOT EXISTS apv_accounting_cutover (
    id TINYINT NOT NULL PRIMARY KEY,
    legacy_max_apv_id INT NOT NULL,
    legacy_max_direct_payment_id INT NOT NULL,
    cutover_on DATETIME NOT NULL
) ENGINE=InnoDB;
INSERT IGNORE INTO apv_accounting_cutover
SELECT 1, COALESCE((SELECT MAX(id) FROM accounts_payable_voucher),0),
    COALESCE((SELECT MAX(id) FROM direct_payment),0), NOW();
