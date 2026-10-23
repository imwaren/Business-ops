-- MANUAL cleanup only: not executed during deployment.
USE `mytemr_master_uat`;
SET @request_from = '2025-01-01';
SET @request_until = '2026-01-01';

-- Preview affected IDs and existing statuses before deciding to run the UPDATE.
SELECT c.id,c.name,c.account_status,c.accreditation_status
FROM company c WHERE c.is_deleted = 0
AND (c.account_status <> 'inactive' OR c.accreditation_status <> 'inactive')
AND (c.account_status = 'inactive' OR NOT EXISTS (
 SELECT 1 FROM request r WHERE r.company_id=c.id AND r.is_deleted=0
 AND r.added_on >= @request_from AND r.added_on < @request_until
)) ORDER BY c.id;
SELECT COUNT(*) AS companies_to_change FROM company c WHERE c.is_deleted = 0
AND (c.account_status <> 'inactive' OR c.accreditation_status <> 'inactive')
AND (c.account_status = 'inactive' OR NOT EXISTS (
 SELECT 1 FROM request r WHERE r.company_id=c.id AND r.is_deleted=0
 AND r.added_on >= @request_from AND r.added_on < @request_until
));

START TRANSACTION;
UPDATE company c SET c.account_status='inactive',c.accreditation_status='inactive',
 c.updated_by=NULL,c.updated_on=NOW()
WHERE c.is_deleted = 0
AND (c.account_status <> 'inactive' OR c.accreditation_status <> 'inactive')
AND (c.account_status = 'inactive' OR NOT EXISTS (
 SELECT 1 FROM request r WHERE r.company_id=c.id AND r.is_deleted=0
 AND r.added_on >= @request_from AND r.added_on < @request_until
));
SELECT ROW_COUNT() AS changed_companies;

-- Postflight: both counts must be zero after a successful cleanup.
SELECT COUNT(*) AS remaining_candidates FROM company c WHERE c.is_deleted = 0
AND (c.account_status <> 'inactive' OR c.accreditation_status <> 'inactive')
AND (c.account_status = 'inactive' OR NOT EXISTS (
 SELECT 1 FROM request r WHERE r.company_id=c.id AND r.is_deleted=0
 AND r.added_on >= @request_from AND r.added_on < @request_until
));
SELECT COUNT(*) AS inconsistent_inactive_companies FROM company
WHERE is_deleted=0 AND account_status='inactive' AND accreditation_status<>'inactive';
COMMIT;
-- If executing statements interactively and checks fail, ROLLBACK instead of COMMIT.
