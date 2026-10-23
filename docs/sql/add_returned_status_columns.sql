-- Add returned_* audit columns (mirror disapproved_*) for Request → Endorsement pipeline.
-- Run against the business-ops database, then re-apply updated *_history_trigger.sql files.

ALTER TABLE request
  ADD COLUMN returned_by INT NULL AFTER disapproved_reason,
  ADD COLUMN returned_on DATETIME NULL AFTER returned_by,
  ADD COLUMN returned_reason TEXT NULL AFTER returned_on;

ALTER TABLE costing
  ADD COLUMN returned_by INT NULL AFTER disapproved_reason,
  ADD COLUMN returned_on DATETIME NULL AFTER returned_by,
  ADD COLUMN returned_reason TEXT NULL AFTER returned_on;

ALTER TABLE quotation
  ADD COLUMN returned_by INT NULL AFTER disapproved_reason,
  ADD COLUMN returned_on DATETIME NULL AFTER returned_by,
  ADD COLUMN returned_reason TEXT NULL AFTER returned_on;

ALTER TABLE endorsement
  ADD COLUMN returned_by INT NULL AFTER disapproved_reason,
  ADD COLUMN returned_on DATETIME NULL AFTER returned_by,
  ADD COLUMN returned_reason TEXT NULL AFTER returned_on;

ALTER TABLE quotation_service
  ADD COLUMN returned_by INT NULL AFTER disapproved_reason,
  ADD COLUMN returned_on DATETIME NULL AFTER returned_by,
  ADD COLUMN returned_reason TEXT NULL AFTER returned_on;

-- Optional: endorsement_revision may only need returned_by/on (disapprove has no reason there).
ALTER TABLE endorsement_revision
  ADD COLUMN returned_by INT NULL AFTER disapproved_on,
  ADD COLUMN returned_on DATETIME NULL AFTER returned_by;
