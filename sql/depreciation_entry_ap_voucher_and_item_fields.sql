-- Apply on EACH clinic DB (mobile, oakridge, mactan, consolacion, mantlewood).

ALTER TABLE `depreciation_entry`
  ADD COLUMN `accounts_payable_voucher_id` int(11) DEFAULT NULL AFTER `cost_center_id`;

ALTER TABLE `depreciation_entry_item`
  ADD COLUMN `useful_life_months` int(11) DEFAULT NULL AFTER `credit`,
  ADD COLUMN `start_date` date DEFAULT NULL AFTER `useful_life_months`,
  ADD COLUMN `end_date` date DEFAULT NULL AFTER `start_date`,
  ADD COLUMN `debit_account_type_id` int(11) DEFAULT NULL AFTER `end_date`,
  ADD COLUMN `credit_account_type_id` int(11) DEFAULT NULL AFTER `debit_account_type_id`,
  ADD COLUMN `amount` decimal(13,2) DEFAULT NULL AFTER `credit_account_type_id`,
  ADD COLUMN `serial_number` varchar(255) DEFAULT NULL AFTER `amount`,
  ADD COLUMN `asset_tag_number` varchar(255) DEFAULT NULL AFTER `serial_number`,
  DROP COLUMN `account_type_id`;