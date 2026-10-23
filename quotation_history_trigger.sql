DROP TRIGGER IF EXISTS `after_update_quotation`;

DELIMITER $$

CREATE TRIGGER `after_update_quotation` 
AFTER UPDATE ON `quotation`
 FOR EACH ROW BEGIN
    -- fields to track changes
    IF (NEW.status <> OLD.status) THEN
        INSERT INTO quotation_history (source_id, version_no, col_name, old_value, new_value, added_by, added_on) 
        VALUES (OLD.id, NEW.version_no, 'status', OLD.status, NEW.status, NEW.updated_by, NEW.updated_on);
    END IF;

    IF (NEW.approved_by <> OLD.approved_by) THEN
        INSERT INTO quotation_history (source_id, version_no, col_name, old_value, new_value, added_by, added_on) 
        VALUES (OLD.id, NEW.version_no, 'approved_by', OLD.approved_by, NEW.approved_by, NEW.updated_by, NEW.updated_on);
    END IF;

    IF (NEW.approved_on <> OLD.approved_on) THEN
        INSERT INTO quotation_history (source_id, version_no, col_name, old_value, new_value, added_by, added_on) 
        VALUES (OLD.id, NEW.version_no, 'approved_on', OLD.approved_on, NEW.approved_on, NEW.updated_by, NEW.updated_on);
    END IF;

    IF (NOT (NEW.approved_reason <=> OLD.approved_reason)) THEN
        INSERT INTO quotation_history (source_id, version_no, col_name, old_value, new_value, added_by, added_on) 
        VALUES (OLD.id, NEW.version_no, 'approved_reason', OLD.approved_reason, NEW.approved_reason, NEW.updated_by, NEW.updated_on);
    END IF;

    IF (NEW.disapproved_by <> OLD.disapproved_by) THEN
        INSERT INTO quotation_history (source_id, version_no, col_name, old_value, new_value, added_by, added_on) 
        VALUES (OLD.id, NEW.version_no, 'disapproved_by', OLD.disapproved_by, NEW.disapproved_by, NEW.updated_by, NEW.updated_on);
    END IF;

    IF (NEW.disapproved_on <> OLD.disapproved_on) THEN
        INSERT INTO quotation_history (source_id, version_no, col_name, old_value, new_value, added_by, added_on) 
        VALUES (OLD.id, NEW.version_no, 'disapproved_on', OLD.disapproved_on, NEW.disapproved_on, NEW.updated_by, NEW.updated_on);
    END IF;

    IF (NOT (NEW.disapproved_reason <=> OLD.disapproved_reason)) THEN
        INSERT INTO quotation_history (source_id, version_no, col_name, old_value, new_value, added_by, added_on) 
        VALUES (OLD.id, NEW.version_no, 'disapproved_reason', OLD.disapproved_reason, NEW.disapproved_reason, NEW.updated_by, NEW.updated_on);
    END IF;

    IF (NEW.returned_by <> OLD.returned_by) THEN
        INSERT INTO quotation_history (source_id, version_no, col_name, old_value, new_value, added_by, added_on) 
        VALUES (OLD.id, NEW.version_no, 'returned_by', OLD.returned_by, NEW.returned_by, NEW.updated_by, NEW.updated_on);
    END IF;

    IF (NEW.returned_on <> OLD.returned_on) THEN
        INSERT INTO quotation_history (source_id, version_no, col_name, old_value, new_value, added_by, added_on) 
        VALUES (OLD.id, NEW.version_no, 'returned_on', OLD.returned_on, NEW.returned_on, NEW.updated_by, NEW.updated_on);
    END IF;

    IF (NOT (NEW.returned_reason <=> OLD.returned_reason)) THEN
        INSERT INTO quotation_history (source_id, version_no, col_name, old_value, new_value, added_by, added_on) 
        VALUES (OLD.id, NEW.version_no, 'returned_reason', OLD.returned_reason, NEW.returned_reason, NEW.updated_by, NEW.updated_on);
    END IF;

    IF (NEW.approved_by_sales <> OLD.approved_by_sales) THEN
        INSERT INTO quotation_history (source_id, version_no, col_name, old_value, new_value, added_by, added_on) 
        VALUES (OLD.id, NEW.version_no, 'approved_by_sales', OLD.approved_by_sales, NEW.approved_by_sales, NEW.updated_by, NEW.updated_on);
    END IF;

    IF (NEW.approved_on_sales <> OLD.approved_on_sales) THEN
        INSERT INTO quotation_history (source_id, version_no, col_name, old_value, new_value, added_by, added_on) 
        VALUES (OLD.id, NEW.version_no, 'approved_on_sales', OLD.approved_on_sales, NEW.approved_on_sales, NEW.updated_by, NEW.updated_on);
    END IF;

    IF (NOT (NEW.approved_reason_sales <=> OLD.approved_reason_sales)) THEN
        INSERT INTO quotation_history (source_id, version_no, col_name, old_value, new_value, added_by, added_on) 
        VALUES (OLD.id, NEW.version_no, 'approved_reason_sales', OLD.approved_reason_sales, NEW.approved_reason_sales, NEW.updated_by, NEW.updated_on);
    END IF;

    IF (NEW.approved_by_sales_manager <> OLD.approved_by_sales_manager) THEN
        INSERT INTO quotation_history (source_id, version_no, col_name, old_value, new_value, added_by, added_on) 
        VALUES (OLD.id, NEW.version_no, 'approved_by_sales_manager', OLD.approved_by_sales_manager, NEW.approved_by_sales_manager, NEW.updated_by, NEW.updated_on);
    END IF;

    IF (NEW.approved_on_sales_manager <> OLD.approved_on_sales_manager) THEN
        INSERT INTO quotation_history (source_id, version_no, col_name, old_value, new_value, added_by, added_on) 
        VALUES (OLD.id, NEW.version_no, 'approved_on_sales_manager', OLD.approved_on_sales_manager, NEW.approved_on_sales_manager, NEW.updated_by, NEW.updated_on);
    END IF;

    IF (NOT (NEW.approved_reason_sales_manager <=> OLD.approved_reason_sales_manager)) THEN
        INSERT INTO quotation_history (source_id, version_no, col_name, old_value, new_value, added_by, added_on) 
        VALUES (OLD.id, NEW.version_no, 'approved_reason_sales_manager', OLD.approved_reason_sales_manager, NEW.approved_reason_sales_manager, NEW.updated_by, NEW.updated_on);
    END IF;

    IF (NEW.approved_by_sales_director <> OLD.approved_by_sales_director) THEN
        INSERT INTO quotation_history (source_id, version_no, col_name, old_value, new_value, added_by, added_on) 
        VALUES (OLD.id, NEW.version_no, 'approved_by_sales_director', OLD.approved_by_sales_director, NEW.approved_by_sales_director, NEW.updated_by, NEW.updated_on);
    END IF;

    IF (NEW.approved_on_sales_director <> OLD.approved_on_sales_director) THEN
        INSERT INTO quotation_history (source_id, version_no, col_name, old_value, new_value, added_by, added_on) 
        VALUES (OLD.id, NEW.version_no, 'approved_on_sales_director', OLD.approved_on_sales_director, NEW.approved_on_sales_director, NEW.updated_by, NEW.updated_on);
    END IF;

    IF (NOT (NEW.approved_reason_sales_director <=> OLD.approved_reason_sales_director)) THEN
        INSERT INTO quotation_history (source_id, version_no, col_name, old_value, new_value, added_by, added_on) 
        VALUES (OLD.id, NEW.version_no, 'approved_reason_sales_director', OLD.approved_reason_sales_director, NEW.approved_reason_sales_director, NEW.updated_by, NEW.updated_on);
    END IF;

    IF (NEW.approved_by_finance_manager <> OLD.approved_by_finance_manager) THEN
        INSERT INTO quotation_history (source_id, version_no, col_name, old_value, new_value, added_by, added_on) 
        VALUES (OLD.id, NEW.version_no, 'approved_by_finance_manager', OLD.approved_by_finance_manager, NEW.approved_by_finance_manager, NEW.updated_by, NEW.updated_on);
    END IF;

    IF (NEW.approved_on_finance_manager <> OLD.approved_on_finance_manager) THEN
        INSERT INTO quotation_history (source_id, version_no, col_name, old_value, new_value, added_by, added_on) 
        VALUES (OLD.id, NEW.version_no, 'approved_on_finance_manager', OLD.approved_on_finance_manager, NEW.approved_on_finance_manager, NEW.updated_by, NEW.updated_on);
    END IF;

    IF (NOT (NEW.approved_reason_finance_manager <=> OLD.approved_reason_finance_manager)) THEN
        INSERT INTO quotation_history (source_id, version_no, col_name, old_value, new_value, added_by, added_on) 
        VALUES (OLD.id, NEW.version_no, 'approved_reason_finance_manager', OLD.approved_reason_finance_manager, NEW.approved_reason_finance_manager, NEW.updated_by, NEW.updated_on);
    END IF;

    IF (NEW.approved_by_finance_director <> OLD.approved_by_finance_director) THEN
        INSERT INTO quotation_history (source_id, version_no, col_name, old_value, new_value, added_by, added_on) 
        VALUES (OLD.id, NEW.version_no, 'approved_by_finance_director', OLD.approved_by_finance_director, NEW.approved_by_finance_director, NEW.updated_by, NEW.updated_on);
    END IF;

    IF (NEW.approved_on_finance_director <> OLD.approved_on_finance_director) THEN
        INSERT INTO quotation_history (source_id, version_no, col_name, old_value, new_value, added_by, added_on) 
        VALUES (OLD.id, NEW.version_no, 'approved_on_finance_director', OLD.approved_on_finance_director, NEW.approved_on_finance_director, NEW.updated_by, NEW.updated_on);
    END IF;

    IF (NOT (NEW.approved_reason_finance_director <=> OLD.approved_reason_finance_director)) THEN
        INSERT INTO quotation_history (source_id, version_no, col_name, old_value, new_value, added_by, added_on) 
        VALUES (OLD.id, NEW.version_no, 'approved_reason_finance_director', OLD.approved_reason_finance_director, NEW.approved_reason_finance_director, NEW.updated_by, NEW.updated_on);
    END IF;

    IF (NEW.archived_by <> OLD.archived_by) THEN
        INSERT INTO quotation_history (source_id, version_no, col_name, old_value, new_value, added_by, added_on) 
        VALUES (OLD.id, NEW.version_no, 'archived_by', OLD.archived_by, NEW.archived_by, NEW.updated_by, NEW.updated_on);
    END IF;

    IF (NEW.archived_on <> OLD.archived_on) THEN
        INSERT INTO quotation_history (source_id, version_no, col_name, old_value, new_value, added_by, added_on) 
        VALUES (OLD.id, NEW.version_no, 'archived_on', OLD.archived_on, NEW.archived_on, NEW.updated_by, NEW.updated_on);
    END IF;

    IF (NOT (NEW.archived_reason <=> OLD.archived_reason)) THEN
        INSERT INTO quotation_history (source_id, version_no, col_name, old_value, new_value, added_by, added_on) 
        VALUES (OLD.id, NEW.version_no, 'archived_reason', OLD.archived_reason, NEW.archived_reason, NEW.updated_by, NEW.updated_on);
    END IF;

END$$

DELIMITER ;
