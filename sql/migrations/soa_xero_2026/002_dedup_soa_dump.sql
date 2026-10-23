-- Same invoice line can appear in more than one date-range CSV.
-- Rebuild is faster than DELETE ... LEFT JOIN on 100k+ rows.

DELETE FROM soa_dump
WHERE source_file LIKE '._%'
   OR IFNULL(TRIM(InvoiceNumber), '') = '';

DROP TABLE IF EXISTS soa_dump_keep;
CREATE TABLE soa_dump_keep (
  id INT(11) NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB;

INSERT INTO soa_dump_keep (id)
SELECT MIN(id)
FROM soa_dump
GROUP BY InvoiceNumber, Description, LineAmount, Quantity;

DROP TABLE IF EXISTS soa_dump_new;
CREATE TABLE soa_dump_new LIKE soa_dump;

INSERT INTO soa_dump_new
SELECT d.*
FROM soa_dump d
INNER JOIN soa_dump_keep k ON k.id = d.id;

RENAME TABLE soa_dump TO soa_dump_old, soa_dump_new TO soa_dump;
DROP TABLE soa_dump_old;
DROP TABLE soa_dump_keep;

SELECT
  COUNT(*) AS soa_dump_rows,
  COUNT(DISTINCT NULLIF(InvoiceNumber, '')) AS distinct_invoices,
  COUNT(DISTINCT NULLIF(ContactName, '')) AS distinct_contacts
FROM soa_dump;
