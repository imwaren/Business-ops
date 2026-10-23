-- Add nullable item.form to all eight configured UAT inventory databases.
-- Safe to re-run; the pre-existing UAT master column is left unchanged.
USE `mytemr_master_uat`;
DELIMITER $$

DROP PROCEDURE IF EXISTS add_item_form_if_missing$$
CREATE PROCEDURE add_item_form_if_missing()
BEGIN
    DECLARE finished INT DEFAULT 0;
    DECLARE current_schema VARCHAR(64);
    DECLARE form_column_exists INT DEFAULT 0;

    DECLARE schema_cursor CURSOR FOR
        SELECT schema_name
        FROM information_schema.schemata
        WHERE schema_name IN (
            'mytemr_master_uat',
            'mytemr_diagnostic_mobile_uat',
            'mytemr_diagnostic_uat',
            'mytemr_diagnostic_mactan_uat',
            'mytemr_diagnostic_conso_uat',
            'mytemr_diagnostic_mantlewood_uat',
            'mytemr_oakridge_warehouse_uat',
            'mytemr_oakridge_pharmacy_uat'
        )
        ORDER BY schema_name;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET finished = 1;

    OPEN schema_cursor;
    schema_loop: LOOP
        FETCH schema_cursor INTO current_schema;
        IF finished = 1 THEN
            LEAVE schema_loop;
        END IF;

        SELECT COUNT(*)
        INTO form_column_exists
        FROM information_schema.columns
        WHERE table_schema = current_schema
          AND table_name = 'item'
          AND column_name = 'form';

        IF form_column_exists = 0 THEN
            SET @item_form_ddl = CONCAT(
                'ALTER TABLE `', current_schema,
                '`.`item` ADD COLUMN `form` VARCHAR(100) NULL'
            );
            PREPARE item_form_statement FROM @item_form_ddl;
            EXECUTE item_form_statement;
            DEALLOCATE PREPARE item_form_statement;
        END IF;
    END LOOP;
    CLOSE schema_cursor;
END$$

CALL add_item_form_if_missing()$$
DROP PROCEDURE add_item_form_if_missing$$

DELIMITER ;
