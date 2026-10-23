-- Map Xero ContactName to company.id. Safe to re-run: keeps manual company_id values.

DROP FUNCTION IF EXISTS soa_norm_name;

DELIMITER $$

CREATE FUNCTION soa_norm_name(s VARCHAR(512))
RETURNS VARCHAR(512)
DETERMINISTIC
BEGIN
    DECLARE n VARCHAR(512);
    DECLARE n2 VARCHAR(512);
    SET n = UPPER(TRIM(IFNULL(s, '')));
    SET n = REGEXP_REPLACE(n, '[^A-Z0-9]+', '');
    REPEAT
        SET n2 = n;
        SET n = REGEXP_REPLACE(n, '(INCORPORATED|CORPORATION|COMPANY|LIMITED|LTD|INC|CORP|OPC|CO)$', '');
    UNTIL n = n2 END REPEAT;
    RETURN n;
END$$

DELIMITER ;

CREATE TABLE IF NOT EXISTS soa_company_map (
  contact_name VARCHAR(255) NOT NULL,
  company_id INT(11) DEFAULT NULL,
  match_type VARCHAR(32) DEFAULT NULL,
  PRIMARY KEY (contact_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO soa_company_map (contact_name, company_id, match_type)
SELECT DISTINCT TRIM(d.ContactName), NULL, NULL
FROM soa_dump d
WHERE d.ContactName IS NOT NULL AND TRIM(d.ContactName) <> '';

UPDATE soa_company_map m
INNER JOIN (
    SELECT TRIM(c.name) AS name, MIN(c.id) AS company_id
    FROM company c
    WHERE c.is_deleted = 0
    GROUP BY TRIM(c.name)
) c ON LOWER(c.name) = LOWER(m.contact_name)
SET m.company_id = c.company_id,
    m.match_type = 'exact'
WHERE m.company_id IS NULL;

UPDATE soa_company_map m
INNER JOIN (
    SELECT soa_norm_name(c.name) AS n, MIN(c.id) AS company_id
    FROM company c
    WHERE c.is_deleted = 0
      AND soa_norm_name(c.name) <> ''
    GROUP BY soa_norm_name(c.name)
    HAVING COUNT(DISTINCT c.id) = 1
) c ON c.n = soa_norm_name(m.contact_name)
SET m.company_id = c.company_id,
    m.match_type = 'normalized'
WHERE m.company_id IS NULL;

SELECT
    COUNT(*) AS contacts,
    SUM(company_id IS NOT NULL) AS mapped,
    SUM(company_id IS NULL) AS unmatched
FROM soa_company_map;

SELECT contact_name
FROM soa_company_map
WHERE company_id IS NULL
ORDER BY contact_name;
