-- Apply on EVERY inventory database:
-- master, mobile, oakridge, mactan, consolacion, mantlewood, warehouse, pharmacy

ALTER TABLE `item`
  ADD COLUMN `weighted_cost` decimal(13,4) NOT NULL DEFAULT 0.0000 AFTER `cost`;

UPDATE `item`
SET `weighted_cost` = `cost`
WHERE `is_deleted` = 0;

-- Rebuild latest item_cost trail so the next receipt uses qty × cost, not cost in all three fields.
UPDATE `item_cost` AS ic
INNER JOIN (
    SELECT MAX(id) AS id
    FROM `item_cost`
    WHERE `is_deleted` = 0
    GROUP BY `item_id`, `branch_id`
) AS latest ON latest.id = ic.id
LEFT JOIN `item_conversion` AS conv
    ON conv.item_id = ic.item_id
   AND conv.conversion_type = 'purchase'
   AND conv.is_deleted = 0
LEFT JOIN `branch_item` AS bi
    ON bi.item_id = ic.item_id
   AND bi.branch_id = ic.branch_id
   AND bi.unit = conv.id
   AND bi.is_deleted = 0
LEFT JOIN `item` AS i
    ON i.id = ic.item_id
SET
    ic.latest_total = IFNULL(bi.current_qty, 0),
    ic.last_value = IFNULL(bi.current_qty, 0) * IFNULL(i.cost, 0),
    ic.current_amount = IFNULL(i.cost, 0);
