-- Run in each affected diagnostic/master schema before installing remark helpers.
-- Repeating this migration is safe. It does not rewrite remarks or accounting rows.
ALTER TABLE journal_entry MODIFY COLUMN remarks LONGTEXT DEFAULT NULL;
ALTER TABLE journal_entry_item MODIFY COLUMN remarks LONGTEXT DEFAULT NULL;
