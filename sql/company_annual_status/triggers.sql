-- Install in the selected master database. Preserve existing AFTER/audit/wallet triggers.
DELIMITER $$
DROP TRIGGER IF EXISTS trg_company_status_bi$$
CREATE TRIGGER trg_company_status_bi BEFORE INSERT ON company FOR EACH ROW
BEGIN
 IF NEW.account_status = 'inactive' THEN
  SET NEW.accreditation_status = 'inactive';
 END IF;
END$$
DROP TRIGGER IF EXISTS trg_company_status_bu$$
CREATE TRIGGER trg_company_status_bu BEFORE UPDATE ON company FOR EACH ROW
BEGIN
 IF NEW.account_status = 'inactive' THEN
  SET NEW.accreditation_status = 'inactive';
 END IF;
END$$
DELIMITER ;
