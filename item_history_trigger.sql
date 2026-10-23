DELIMITER $$
CREATE TRIGGER `trg_branch_item_insert` AFTER INSERT ON `branch_item`
 FOR EACH ROW BEGIN
    -- Only log if there is initial stock
    IF NEW.current_qty > 0 THEN
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
            added_by,
            added_on
        ) VALUES (
            NEW.id,
            NEW.item_id,
            NEW.branch_id,
            NEW.latest_department_id,
            NEW.beggining_qty,
            NEW.current_qty,
            NEW.unit,
            'Beginning Inventory',
            NEW.item_id,
            NEW.current_qty,
            NEW.added_by,
            NOW()
        );
    END IF;
END$$

CREATE TRIGGER `trg_branch_item_update` AFTER UPDATE ON `branch_item`
 FOR EACH ROW BEGIN
    DECLARE qty_diff DECIMAL(13,2) DEFAULT 0;

    -- Only log if quantity actually changed
    IF NEW.current_qty <> OLD.current_qty THEN

        -- Determine IN or OUT
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
