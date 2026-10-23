-- Retired: use sql/apv_entries after resolving historical migration exceptions.
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Direct-payment entry migration is retired; migrate accounting to APV entries';
