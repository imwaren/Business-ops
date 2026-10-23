-- DETAILS PACKAGE

BEGIN
    DECLARE remaining_count INT;

    -- Count rows not done/waived
    SELECT COUNT(*)
    INTO remaining_count
    FROM booking_details
    WHERE booking_id = NEW.booking_id
    AND status NOT IN ('done','waived')
    AND is_deleted = 0;

    IF remaining_count = 0 THEN

        -- all done/waived
        UPDATE booking
        SET status = 'completed',
            updated_on = NOW()
        WHERE id = NEW.booking_id
        AND is_deleted = 0;

    ELSE

        -- if any record becomes pending
        UPDATE booking
        SET status = 'pending',
            updated_on = NOW()
        WHERE id = NEW.booking_id
        AND is_deleted = 0;

    END IF;

END


-- DETAILS PACKAGE
BEGIN
    DECLARE remaining_count INT;

    -- Count rows not done/waived
    SELECT COUNT(*)
    INTO remaining_count
    FROM booking_package_details
    WHERE booking_detail_id = NEW.booking_detail_id
    AND status NOT IN ('done','waived')
    AND is_deleted = 0;

    IF remaining_count = 0 THEN

        -- all done or waived
        UPDATE booking_details
        SET status = 'done',
            updated_on = NOW()
        WHERE id = NEW.booking_detail_id
        AND is_deleted = 0;

    ELSE

        -- at least one is pending
        UPDATE booking_details
        SET status = 'pending',
            updated_on = NULL
        WHERE id = NEW.booking_detail_id
        AND is_deleted = 0;

    END IF;

END