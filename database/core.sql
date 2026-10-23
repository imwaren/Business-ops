-- phpMyAdmin SQL Dump
-- version 5.2.0
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 15, 2023 at 06:37 PM
-- Server version: 10.4.27-MariaDB
-- PHP Version: 7.4.33

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `core`
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
  `data_received` mediumtext NOT NULL,
  `signature` mediumtext DEFAULT NULL,
  `requested_by` bigint(20) NOT NULL,
  `requested_on` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `permission`
--

CREATE TABLE `permission` (
  `id` int(11) NOT NULL,
  `role_id` int(11) DEFAULT NULL,
  `page_id` int(11) DEFAULT NULL,
  `is_allowed` tinyint(4) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `role`
--

CREATE TABLE `role` (
  `id` bigint(20) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `added_by` int(11) NOT NULL DEFAULT 0,
  `added_on` datetime NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` datetime DEFAULT NULL,
  `is_deleted` tinyint(4) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `role`
--

INSERT INTO `role` (`id`, `name`, `description`, `added_by`, `added_on`, `updated_by`, `updated_on`, `is_deleted`) VALUES
(1, 'admin', NULL, 0, '2023-06-23 08:29:17', NULL, NULL, 0),
(2, 'retailer', NULL, 0, '2023-06-23 08:29:17', NULL, NULL, 0),
(3, 'customer', NULL, 0, '2023-06-23 08:32:44', NULL, NULL, 0);

-- --------------------------------------------------------

--
-- Table structure for table `template`
--

CREATE TABLE `template` (
  `id` int(11) NOT NULL,
  `foreign_id` int(11) NOT NULL,
  `text_field` varchar(255) DEFAULT NULL,
  `numeric_field` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `enum_field` enum('queued','cancelled','finished') NOT NULL DEFAULT 'queued',
  `added_by` int(11) NOT NULL,
  `added_on` datetime NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` datetime DEFAULT NULL,
  `is_deleted` tinyint(4) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `template`
--

INSERT INTO `template` (`id`, `foreign_id`, `text_field`, `numeric_field`, `enum_field`, `added_by`, `added_on`, `updated_by`, `updated_on`, `is_deleted`) VALUES
(1, 1, 'test', '0.0000', 'queued', 1, '2023-09-12 00:00:00', 1, '2023-09-12 10:15:27', 0);

-- --------------------------------------------------------

--
-- Table structure for table `template_attachment`
--

CREATE TABLE `template_attachment` (
  `id` bigint(20) NOT NULL,
  `template_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `file_url` longtext NOT NULL,
  `mime` varchar(255) NOT NULL,
  `added_on` datetime NOT NULL DEFAULT current_timestamp(),
  `added_by` bigint(20) NOT NULL,
  `updated_on` datetime DEFAULT NULL,
  `updated_by` bigint(20) DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `template_attachment`
--

INSERT INTO `template_attachment` (`id`, `template_id`, `file_name`, `file_path`, `file_url`, `mime`, `added_on`, `added_by`, `updated_on`, `updated_by`, `is_deleted`) VALUES
(1, 1, 'y2f3944b01b6ac7d5a9d3adf93d23f31_62d2e435715619d37d492888_1120_630_39727.jpeg', 'assets/patients/48', 'http://localhost/ob-emr-api/assets/patients/48/y2f3944b01b6ac7d5a9d3adf93d23f31_62d2e435715619d37d492888_1120_630_39727.jpeg', 'image/jpeg', '2023-08-23 14:56:35', 1, NULL, NULL, 0);

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
  `role_name` varchar(255) DEFAULT NULL,
  `token` text DEFAULT NULL,
  `token_expiry` datetime DEFAULT NULL,
  `added_by` int(11) NOT NULL DEFAULT 0,
  `added_on` datetime NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` datetime DEFAULT NULL,
  `is_deleted` tinyint(4) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user`
--

INSERT INTO `user` (`id`, `full_name`, `username`, `password`, `role_id`, `role_name`, `token`, `token_expiry`, `added_by`, `added_on`, `updated_by`, `updated_on`, `is_deleted`) VALUES
(1, 'Test User', 'test', '$2y$10$k18d056QuigmzehFtPVgS.3sbNvbRJUK1Tf0M3PiESk6q.Z6l1kSW', 1, 'admin', 'nDEA0rlAiJUeS6fDMEOtprH0uFlCVLlujsq2l3eWuG0qjCZ4g9RV9XvDwzix2RAX55UzINKzm2lADMTw1SnRg708iBC9C55OMrxhkBaKV5hTVAgERRaapAQ4GFZ47XOXv3VtvM74js3QOQVvxT9JOMHpv3XcbHVuXRmn91eSKKXT6kN0Y0gOo59LT3fHh07okVXK9aCDRqUwD3wk6a9CIJruRQEv0wzu1PWlRte61iL1KvpgfBcee3Q11LHnyE3i', '2023-09-06 20:02:34', 0, '2023-05-08 00:53:41', 1, '2023-09-06 12:02:34', 0),
(2, 'myt_admin', 'myt_admin', '$2y$10$szh03kQu57yY3dXZLJgUn.QTYVW3kRXqdsR1x7V5biuZaCjXACNzm', 1, 'admin', 'vWF7e0FFcPRJmZ5AL7FDMIYVnkUQj3lLc1K22nOmH2Js82TnjKxmEGidxY4vPBCxyln6RsVu70GrrFwsTFmPQeuncCLp5posZw1PrsKko5QAC1ntIjn2uFM6uzAiQANFwuMXe0wbzMZCXKNDyuoMhp953XkqihmC2rqZT72amydJfhqnu64gbn1KiOLwPIlGJmi152LfS36PinbhRenG14XstdxJIjuUSiZsOznKD67VUGXLrOHPiiGHAFQamvGJ', '2023-09-08 21:10:33', 0, '2023-05-09 07:25:04', 2, '2023-09-08 13:10:33', 0),
(3, 'myt_mayrose', 'myt_mayrose', '$2y$10$szh03kQu57yY3dXZLJgUn.QTYVW3kRXqdsR1x7V5biuZaCjXACNzm', 1, 'admin', 'SCeDlkcuruN08xnfktTBS0bdqQ5Lzcc0AUbap2PmCE5nOCEqnVT2P7NTGzBV0C46lVGuyzLREY3EeLwx8QG3ZIdr76oRMxcADfxtFA2YZpn0jXdB6nmo2ean7C1ap19M3ud8s8c6h4ClxUSqZgUhE2RwDZZvyFi1EADKVdAA24j7zXXuScTZECjGK813rTkMklWdtD9n0Gj9mBCCrdDOFGJXvWN9Mw6Tc0MCYDdFf6L2xw7IurAJEKVGXfEKvDh8', '2023-05-18 00:25:24', 0, '2023-05-09 07:25:04', 3, '2023-05-17 16:25:24', 0),
(4, 'myt_test', 'myt_test', '$2y$10$szh03kQu57yY3dXZLJgUn.QTYVW3kRXqdsR1x7V5biuZaCjXACNzm', 1, 'admin', 'P2rOAuFQIEaIxSBClJTQQFMTPL53LwlW7CoxDKPGlvpos2HwheSQm3fY5JzXZxdYS9AdtzeJWrgMOwDwWoV82RyvICHq3KPeRAtiuiaQmYC7CTIIHrIZXfAtvKf20cGEeJWpg0iDskuwPqIsUKouCqVvrxOEWuXyb4TcB95NdM1nDNCKlWx3dR7t39fbaFY5TnQo6vTvexbwH2vTEjkfOx3mFWlHzGkhDM1KxIKm5trXecUrkTRHHLpr8S4rvOG9', '2023-05-13 23:19:23', 0, '2023-05-09 07:26:38', 4, '2023-05-13 15:19:23', 0),
(5, 'myt_donna', 'myt_donna', '$2y$10$szh03kQu57yY3dXZLJgUn.QTYVW3kRXqdsR1x7V5biuZaCjXACNzm', 1, 'admin', '9oPfs9j296JP38AsNMHQLvYy6VMjCYAUHmXK2RR1XnPPkOPYsbuYAkgoBkQeuCGWnW8j2kWmYfLyWN4wXmlcezfhRkkpYlCJbtYLFpNMZPf7hNlnddr9yHZALZVtemGH3RjKHqSe9j4FyX4rpnkfj226TPA26mHeFltZXSwUHpfMuPLXwhsLOgBuv3c8OH8RvgZsrnkHALHPDRq1oaKDxMkYrlYoTnfzoFz1Sj4y7IE6BcERINDEvxCfPJUxfpBV', '2023-05-17 23:49:23', 0, '2023-05-09 07:32:01', 5, '2023-05-17 15:49:23', 0),
(6, 'Sheila Craus', 'myt_sheila', '$2y$10$k18d056QuigmzehFtPVgS.3sbNvbRJUK1Tf0M3PiESk6q.Z6l1kSW', 1, 'admin', 'bIs3kpSahBiFvln8LUugIKVTQzn3lmy1btbOm6RNkaL2AjEaq6iCbstryOUV6S6wJBN6hxFkUQ4EqQeotCo12Y989wOBxpsrvUJr6L0IL0AUJDz1gFSJndAqgrrPgS2fVPeSKHOO9CrltG4aAww1vsk1stFIpK2wzWdqY5FeoAPjEfX3PwGHojwgHegySQM2uUjUxcFQXQCvA4qOAJbfGd2xO8CHuJO2TKN4nDsfqhccJEuiznZw9FkxUhLuMbNG', '2023-05-17 19:06:45', 1, '2023-05-13 07:36:39', 6, '2023-05-17 11:06:45', 0),
(7, 'myt_clyde', 'myt_clyde', '$2y$10$k18d056QuigmzehFtPVgS.3sbNvbRJUK1Tf0M3PiESk6q.Z6l1kSW', 1, 'admin', 'Fon7GlJqgYkQy0u6Kcvm3Oo0WWLPu0J4HwkS04AWWFX2D6lwHgV8GWLM6Jhq6E2LMaDEmr3T4SzvUSKfSSIHtEoZPbCFHJ4x4RB7xWrPPVfed0LyKZ7JkRUS8dBcDBJOldw8w0fCE0eeapH3kNvXWshWhxoab8RXwK6z1LkgJhSu6HLnWe9lTjGNhiwXjH2Gcl0k9CxLMZwfpnuS7PUh5yqGjnCQPUjQ4EB9oLqPtX8iWpoPVoIfskuAMn5ecpBb', '2023-05-17 21:19:00', 1, '2023-05-17 02:52:06', 7, '2023-05-17 13:19:00', 0),
(8, 'Test User 1', 'test_1_2', '$2y$10$XsHdkrrOuDXLVUyYjSm.TeYpoSL2ctDaWYSPHZ2pNRegJGSd2yd.m', 1, 'User', NULL, NULL, 1, '2023-05-31 10:15:44', NULL, NULL, 0),
(11, 'Dio', 'test_223', '1', 2, 'retailer', NULL, NULL, 1, '2023-06-23 14:57:19', NULL, NULL, 0),
(17, 'Dio', 'teasdsad', '1', 2, 'retailer', NULL, NULL, 1, '2023-06-23 18:53:46', NULL, NULL, 0),
(19, 'Dio', 'teasd', '1', 2, 'retailer', NULL, NULL, 1, '2023-06-23 18:54:09', NULL, NULL, 0),
(21, 'Dio', 'tea123', '1', 2, 'retailer', NULL, NULL, 1, '2023-06-23 19:05:46', NULL, NULL, 0),
(23, 'Dio', 'tea1231', '$2y$10$UMlEj0Io6Ha4b5Jd/YE3hu1jp9W4sGAsjs3og60UixuvYVO3dyHAi', 2, 'retailer', NULL, NULL, 1, '2023-06-24 11:47:41', NULL, NULL, 0),
(24, 'Test User 1', 'test_1_2_3', '$2y$10$ZACpd.mz85C3XqffjG7ZDuYVx.DTyPs6BrszQBO3BQsrqnZFo6Aq2', 1, 'admin', NULL, NULL, 1, '2023-09-08 10:53:05', NULL, NULL, 0);

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `userlog`
--

INSERT INTO `userlog` (`id`, `user_id`, `ip_address`, `user_agent`, `platform`, `token`, `token_expiry`, `type`, `time_check`, `response`, `latitude`, `longitude`, `added_by`, `added_on`, `updated_by`, `updated_on`, `is_deleted`) VALUES
(1, 1, '::1', 'Unidentified User Agent', 'Unknown Platform', 'E88UVbZ3KLILAF1ejjVJNG7yoYswY6LdKnUyrgDpgsasMXfvrYOAlPgWFny74CbzHZX814wjOu0rvNSSB00vlMJmaKwJIO9EbqEdVanbDJnINl82GJlPH4Z4frnWaQZL8uGsTBcxKacTPFLmwFUwkRHhzJDkmtCqc1TjJyveIT6aT7jqA7tBacUjuIjtFcF3Ekd4RHOMIiUPxzfjM4SJSmoBFVgHcGWbpIojZxnzNWR7zsw2caSFoHg4Ef09FM9Y', '2023-05-24 18:18:10', 'login', '2023-05-24 10:18:10', 'Login Successfully', 1.000000, 1.000000, 1, '2023-05-24 10:18:10', NULL, NULL, 0),
(2, 1, '::1', 'Unidentified User Agent', 'Unknown Platform', 'FVY17cvxan2S9WmAKmIJ7G58BwzPT0IGQghnevdmgI2X2ShCxGLTbF2w2zPlJWnRvtFfgYoyiZPbPWcOccpxHPIt3S4ngpniVKggX8yvZeNflEcRvx9DPACgNBuJl3I4OiugWjaroM3nzS9aajfiMDhOY8OtYtQD0W2BfaIXjlxWFsor2lBhZswsdk9MrGqaXSiB2cA7bGx36N2N5ABWlGQrHPOxQDKcZ3unb26HKqFqJtdVJEGTf5Iz8ie2Mnzf', '2023-05-24 18:32:07', 'login', '2023-05-24 10:32:07', 'Login Successfully', 1.000000, 1.000000, 1, '2023-05-24 10:32:07', NULL, NULL, 0),
(3, 1, '::1', 'Unidentified User Agent', 'Unknown Platform', 'QP3XTTHmtkMWFwTz2ucALMtNibxVde4ijAPO566Qpdx2ngeVQAt6qw6L6jFf6x7EZqOg6xyQDhgXCcbGonnwZDdh9F8glmzGjYD8TaZyndJbPkbz4NVCPCg1FUQulWEdLjVgp41nzHTuee8siiarusEnGmUgg1fpaRRNhIxr3TqwyKKjGCElfvlEonzpHdD4JMTsnxN0bOJolW0HbSLTfeU8VvuO9QBObMyeHlIiCM3R8V0oYZRa3yn8lDXI8j6G', '2023-05-25 02:16:10', 'login', '2023-05-24 18:16:10', 'Login Successfully', 1.000000, 1.000000, 1, '2023-05-24 18:16:10', NULL, NULL, 0),
(4, 1, '::1', 'Unidentified User Agent', 'Unknown Platform', 'NksVBLXR2ihJshT0aHrfuHA519A4BQX0A0LBGbGsqdpOoKiQA1c3VLBYOkBodTSphQ528yFYLerg4tPx5X9N1T4cmQd7IBvbgDCFzAlFMWlkBEbpktxsTnCqLbMQO13uilhDdYKcK3IrcKPnsRWwyKkqE1MQYy9CNfIWfLvehZfgEAcKEuBId2QKVvQTojGBxWfQDYDucYIf18T7N3OVCnkpBPvHAsnO2ykkcmt3Uk2upBw7vDll10xNBg0L0eaA', '2023-05-25 07:33:38', 'login', '2023-05-24 23:33:39', 'Login Successfully', 1.000000, 1.000000, 1, '2023-05-24 23:33:39', NULL, NULL, 0),
(5, 1, '89.39.107.204', 'Edge 114.0.1823.51', 'Windows 10', 'eLHqRxWLkKNFfKw7vHKQbYYb65OQsbONKSvUCjDKtSV4HWT93UB1fgTmtCuivGoSkpmbs5Z0Y5twGF1iRiFhoJxRYiZuA5LatBN9HKq2DijNchlmtBls8L718z0R84ToDyfM1qHZf57GGtna7yFHE9Ui7862wL4exF9eGhq3RZFf73fX5JAS4UKR2dLO5nYNEi5DrdYlVKTWts3XfaYelBugrlGODDWZGtae1VmY6DcYaNkQpkGLy3xjfGe2E1CW', '2023-06-21 18:05:20', 'login', '2023-06-21 10:05:20', 'Login Successfully', 1.000000, 1.000000, 1, '2023-06-21 10:05:20', NULL, NULL, 0),
(6, 1, '::1', 'Unidentified User Agent', 'Unknown Platform', '5MJh26Ffcu5WxF7lfFm8zeCNA8fuZqwfwhYFTvNPCMT5h3W0bX4Et2SOKvqlo83ZGEr0ua1I6UYYiofxkc4vdzCdJPbe1h6kMC9zfOcqlnSQwPxn5LthqZVuOKNbxnkPp2mfBQETf3fiiKH8S1kVcZwJd4gfzHadmYO5nSNIIvITpsGpKeaaKeo7nRenB6Uudq0W4kqgR0mpfTJJR8IPN3iwwNWAUBztn7h2mbCT3JleOmjShXNghRHkAZFYHoWl', '2023-09-05 21:14:54', 'login', '2023-09-05 13:14:54', 'Login Successfully', 1.000000, 1.000000, 1, '2023-09-05 13:14:54', NULL, NULL, 0),
(7, 1, '::1', 'Unidentified User Agent', 'Unknown Platform', 'nDEA0rlAiJUeS6fDMEOtprH0uFlCVLlujsq2l3eWuG0qjCZ4g9RV9XvDwzix2RAX55UzINKzm2lADMTw1SnRg708iBC9C55OMrxhkBaKV5hTVAgERRaapAQ4GFZ47XOXv3VtvM74js3QOQVvxT9JOMHpv3XcbHVuXRmn91eSKKXT6kN0Y0gOo59LT3fHh07okVXK9aCDRqUwD3wk6a9CIJruRQEv0wzu1PWlRte61iL1KvpgfBcee3Q11LHnyE3i', '2023-09-06 20:02:34', 'login', '2023-09-06 12:02:35', 'Login Successfully', 1.000000, 1.000000, 1, '2023-09-06 12:02:35', NULL, NULL, 0),
(8, 2, '::1', 'Unidentified User Agent', 'Unknown Platform', 'vWF7e0FFcPRJmZ5AL7FDMIYVnkUQj3lLc1K22nOmH2Js82TnjKxmEGidxY4vPBCxyln6RsVu70GrrFwsTFmPQeuncCLp5posZw1PrsKko5QAC1ntIjn2uFM6uzAiQANFwuMXe0wbzMZCXKNDyuoMhp953XkqihmC2rqZT72amydJfhqnu64gbn1KiOLwPIlGJmi152LfS36PinbhRenG14XstdxJIjuUSiZsOznKD67VUGXLrOHPiiGHAFQamvGJ', '2023-09-08 21:10:33', 'login', '2023-09-08 13:10:33', 'Login Successfully', 1.000000, 1.000000, 2, '2023-09-08 13:10:33', NULL, NULL, 0);

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
-- Indexes for table `role`
--
ALTER TABLE `role`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `template`
--
ALTER TABLE `template`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `template_attachment`
--
ALTER TABLE `template_attachment`
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
-- AUTO_INCREMENT for table `role`
--
ALTER TABLE `role`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `template`
--
ALTER TABLE `template`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `template_attachment`
--
ALTER TABLE `template_attachment`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `user`
--
ALTER TABLE `user`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `userlog`
--
ALTER TABLE `userlog`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
