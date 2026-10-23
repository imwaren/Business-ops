CREATE TABLE `non_cash_deposit` (
  `id` int(11) NOT NULL,
  `sales_date_from` date NOT NULL,
  `sales_date_to` date NOT NULL,
  `deposit_date` date NOT NULL,
  `amount` decimal(13,2) NOT NULL,
  `mop` varchar(250) NOT NULL COMMENT 'mode of payment',
  `reference_no` text DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `status` enum('unposted','posted') NOT NULL DEFAULT 'unposted',
  `posted_remarks` varchar(250) DEFAULT NULL,
  `posted_by` int(1) DEFAULT NULL,
  `posted_on` datetime DEFAULT NULL,
  `is_reviewed` int(11) NOT NULL DEFAULT 0,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_on` datetime DEFAULT NULL,
  `reviewed_remarks` text DEFAULT NULL,
  `added_by` int(11) NOT NULL,
  `added_on` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `is_deleted` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `non_cash_deposit_attachment`
--

CREATE TABLE `non_cash_deposit_attachment` (
  `id` bigint(20) NOT NULL,
  `non_cash_deposit_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `file_url` longtext DEFAULT NULL,
  `mime` varchar(255) NOT NULL,
  `added_on` datetime NOT NULL DEFAULT current_timestamp(),
  `added_by` bigint(20) DEFAULT NULL,
  `updated_on` datetime DEFAULT NULL,
  `updated_by` bigint(20) DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for table `non_cash_deposit`
--
ALTER TABLE `non_cash_deposit`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `sales_date_from` (`sales_date_from`,`sales_date_to`,`deposit_date`,`is_deleted`);

--
-- Indexes for table `non_cash_deposit_attachment`
--
ALTER TABLE `non_cash_deposit_attachment`
  ADD PRIMARY KEY (`id`);
