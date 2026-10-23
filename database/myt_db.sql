-- phpMyAdmin SQL Dump
-- version 5.2.0
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 03, 2023 at 03:58 AM
-- Server version: 10.4.27-MariaDB
-- PHP Version: 8.0.25

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `myt`
--

-- --------------------------------------------------------

--
-- Table structure for table `api_log`
--

CREATE TABLE `api_log` (
  `id` bigint(20) NOT NULL,
  `controller` varchar(255) NOT NULL,
  `method` varchar(255) NOT NULL,
  `ip_address` varchar(255) NOT NULL,
  `latitude` float(10,6) DEFAULT NULL,
  `longitude` float(10,6) DEFAULT NULL,
  `data_received` text NOT NULL,
  `signature` text DEFAULT NULL,
  `requested_by` bigint(20) NOT NULL,
  `requested_on` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `api_response`
--

CREATE TABLE `api_response` (
  `id` bigint(20) NOT NULL,
  `api_log_id` bigint(20) DEFAULT NULL,
  `response` longtext DEFAULT NULL,
  `status` int(11) DEFAULT NULL,
  `responded_on` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `page`
--

CREATE TABLE `page` (
  `id` int(11) NOT NULL,
  `controller` varchar(50) NOT NULL,
  `method` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `permission`
--

CREATE TABLE `permission` (
  `id` int(11) NOT NULL,
  `role_id` int(11) DEFAULT NULL,
  `page_id` int(11) DEFAULT NULL,
  `is_allowed` tinyint(4) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user`
--

CREATE TABLE `user` (
  `id` bigint(20) NOT NULL,
  `full_name` text NOT NULL,
  `username` varchar(255) NOT NULL DEFAULT '',
  `password` text NOT NULL,
  `role_id` int(11) NOT NULL,
  `token` text DEFAULT NULL,
  `token_expiry` datetime DEFAULT NULL,
  `added_by` int(11) NOT NULL DEFAULT 0,
  `added_on` datetime NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` datetime DEFAULT NULL,
  `is_deleted` tinyint(4) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `userlog`
--

CREATE TABLE `userlog` (
  `id` bigint(20) NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `ip_address` text NOT NULL,
  `user_agent` varchar(255) NOT NULL,
  `platform` varchar(255) NOT NULL,
  `token` text NOT NULL,
  `token_expiry` datetime NOT NULL,
  `type` varchar(255) NOT NULL,
  `time_check` datetime NOT NULL,
  `response` text DEFAULT NULL,
  `latitude` float(10,6) NOT NULL,
  `longitude` float(10,6) NOT NULL,
  `added_by` int(11) NOT NULL,
  `added_on` datetime NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` datetime DEFAULT NULL,
  `is_deleted` tinyint(4) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Triggers `userlog`
--
DELIMITER $$
CREATE TRIGGER `userlog_after_update` AFTER UPDATE ON `userlog` FOR EACH ROW BEGIN
	  #for trails
	 	IF (NEW.user_id <> OLD.user_id) THEN

      INSERT INTO trail SET source_id = OLD.id, table_name = 'userlog', col_name = 'user_id', old_value = OLD.user_id, new_value = NEW.user_id, added_by = NEW.updated_by, added_on = NEW.updated_on;

    	END IF;

    IF (NEW.ip_address <> OLD.ip_address) THEN

      INSERT INTO trail set source_id = OLD.id, table_name = 'userlog', col_name = 'ip_address', old_value = OLD.ip_address, new_value = NEW.ip_address, added_by = NEW.updated_by, added_on = NEW.updated_on;

    END IF;

    IF (NEW.user_agent <> OLD.user_agent) THEN

      INSERT INTO trail set source_id = OLD.id, table_name = 'userlog', col_name = 'user_agent', old_value = OLD.user_agent, new_value = NEW.user_agent, added_by = NEW.updated_by, added_on = NEW.updated_on;

    END IF;

    IF (NEW.platform <> OLD.platform) THEN

      INSERT INTO trail set source_id = OLD.id, table_name = 'userlog', col_name = 'platform', old_value = OLD.platform, new_value = NEW.platform, added_by = NEW.updated_by, added_on = NEW.updated_on;

    END IF;

    IF (NEW.token <> OLD.token) THEN

      INSERT INTO trail set source_id = OLD.id, table_name = 'userlog', col_name = 'token', old_value = OLD.token, new_value = NEW.token, added_by = NEW.updated_by, added_on = NEW.updated_on;

    END IF;

    IF (NEW.token_expiry <> OLD.token_expiry) THEN

      INSERT INTO trail set source_id = OLD.id, table_name = 'userlog', col_name = 'token_expiry', old_value = OLD.token_expiry, new_value = NEW.token_expiry, added_by = NEW.updated_by, added_on = NEW.updated_on;

    END IF;

    IF (NEW.type <> OLD.type) THEN

      INSERT INTO trail set source_id = OLD.id, table_name = 'userlog', col_name = 'type', old_value = OLD.type, new_value = NEW.type, added_by = NEW.updated_by, added_on = NEW.updated_on;

    END IF;

    IF (NEW.time_check <> OLD.time_check) THEN

      INSERT INTO trail set source_id = OLD.id, table_name = 'userlog', col_name = 'time_check', old_value = OLD.time_check, new_value = NEW.time_check, added_by = NEW.updated_by, added_on = NEW.updated_on;

    END IF;

    IF (NEW.response <> OLD.response) THEN

      INSERT INTO trail set source_id = OLD.id, table_name = 'userlog', col_name = 'response', old_value = OLD.response, new_value = NEW.response, added_by = NEW.updated_by, added_on = NEW.updated_on;

    END IF;

    IF (NEW.is_deleted <> OLD.is_deleted) THEN

      INSERT INTO trail set source_id = OLD.id, table_name = 'userlog', col_name = 'is_deleted', old_value = OLD.is_deleted, new_value = NEW.is_deleted, added_by = NEW.updated_by, added_on = NEW.updated_on;

    END IF;
END
$$
DELIMITER ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `api_log`
--
ALTER TABLE `api_log`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `api_response`
--
ALTER TABLE `api_response`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `page`
--
ALTER TABLE `page`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `controller_method` (`controller`,`method`);

--
-- Indexes for table `permission`
--
ALTER TABLE `permission`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `user`
--
ALTER TABLE `user`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `userlog`
--
ALTER TABLE `userlog`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `api_log`
--
ALTER TABLE `api_log`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `api_response`
--
ALTER TABLE `api_response`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `page`
--
ALTER TABLE `page`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `permission`
--
ALTER TABLE `permission`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user`
--
ALTER TABLE `user`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `userlog`
--
ALTER TABLE `userlog`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
