-- Apply on EACH clinic DB (mobile, oakridge, mactan, consolacion, mantlewood)
-- and on master for column parity. Do not expect asset rows to stay in sync.

ALTER TABLE `asset`
  ADD COLUMN `item_id` int(11) DEFAULT NULL AFTER `cost_center_id`;
