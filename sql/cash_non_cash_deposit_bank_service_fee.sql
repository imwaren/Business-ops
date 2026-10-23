-- bank_service_fee catalog table: apply on ALL 8 DBs
--   master, mobile, oakridge, mactan, consolacion, warehouse, pharmacy, mantlewood
-- GET reads master only; create/update/delete sync the same row to every connection.
--
-- cash_deposit / non_cash_deposit ALTERs: apply on EACH clinic DB only
--   (mobile, oakridge, mactan, consolacion, mantlewood)

CREATE TABLE IF NOT EXISTS `bank_service_fee` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bank_id` int(11) DEFAULT NULL,
  `percentage` decimal(13,2) NOT NULL,
  `is_deleted` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Clinic DBs only:
ALTER TABLE `cash_deposit`
  ADD COLUMN `bank_service_fee_id` int(11) DEFAULT NULL,
  ADD COLUMN `bank_service_fee_percentage` decimal(13,2) DEFAULT NULL;

ALTER TABLE `non_cash_deposit`
  ADD COLUMN `bank_service_fee_id` int(11) DEFAULT NULL,
  ADD COLUMN `bank_service_fee_percentage` decimal(13,2) DEFAULT NULL;
