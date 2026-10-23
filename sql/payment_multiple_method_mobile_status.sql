-- Mobile clinic DB only (mytemr_diagnostic_mobile).
-- Skip the payment.mobile_van_id ALTER if that column already exists.

ALTER TABLE `payment`
  ADD COLUMN `mobile_van_id` int(11) DEFAULT NULL AFTER `transaction_id`;

ALTER TABLE `payment_multiple_method`
  ADD COLUMN `status` enum('posted','unposted') NOT NULL DEFAULT 'unposted' AFTER `remarks`,
  ADD COLUMN `posted_by` int(11) DEFAULT NULL AFTER `status`,
  ADD COLUMN `posted_on` datetime DEFAULT NULL AFTER `posted_by`;
