-- Install in each inventory schema, including master. No historical changes.
-- Compare the live trigger before installation; preserve any unrelated custom logic.
DELIMITER $$
DROP TRIGGER IF EXISTS trg_branch_item_update$$
CREATE TRIGGER `trg_branch_item_update` AFTER UPDATE ON `branch_item` FOR EACH ROW BEGIN
    DECLARE qty_diff DECIMAL(13,2) DEFAULT 0;

    
    IF NEW.current_qty <> OLD.current_qty THEN

        
        IF NEW.current_qty > OLD.current_qty THEN
            SET qty_diff = NEW.current_qty - OLD.current_qty;
        ELSE
            SET qty_diff = OLD.current_qty - NEW.current_qty;
        END IF;

        INSERT INTO item_history (
            branch_item_id,
            item_id,
            branch_id,
            department_id,
            beggining_qty,
            current_qty,
            unit,
            activity,
            activity_id,
            quantity,
            adjustment_type,
            added_by,
            added_on
        ) VALUES (
            OLD.id,
            OLD.item_id,
            OLD.branch_id,
            NEW.latest_department_id,
            OLD.beggining_qty,
            NEW.current_qty,
            OLD.unit,
            NEW.latest_activity,
            NEW.latest_activity_id,
            qty_diff,
            NEW.adjustment_type,
            COALESCE(NEW.updated_by, OLD.updated_by, OLD.added_by),
            NOW()
        );

    END IF;
END$$
DELIMITER ;
