-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 16, 2026 at 10:04 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `nig`
--

-- --------------------------------------------------------

--
-- Table structure for table `accounts`
--

CREATE TABLE `accounts` (
  `account_id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(120) NOT NULL,
  `type` enum('cash','bank','mobile_money') NOT NULL,
  `account_number` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `assets`
--

CREATE TABLE `assets` (
  `asset_id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `purchase_date` date NOT NULL,
  `purchase_value` decimal(14,2) NOT NULL,
  `location` varchar(200) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(10) UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `certificate_file` mediumblob DEFAULT NULL,
  `certificate_name` varchar(255) DEFAULT NULL,
  `certificate_mime` varchar(100) DEFAULT NULL,
  `sold_value` float NOT NULL,
  `sold_date` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `asset_holders`
--

CREATE TABLE `asset_holders` (
  `asset_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `contribution` decimal(14,2) NOT NULL DEFAULT 0.00,
  `assigned_at` datetime NOT NULL DEFAULT current_timestamp(),
  `unassigned_at` datetime DEFAULT NULL,
  `assigned_by` int(10) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `expenses`
--

CREATE TABLE `expenses` (
  `expense_id` bigint(20) UNSIGNED NOT NULL,
  `account_id` int(11) NOT NULL,
  `expense_date` date NOT NULL,
  `category` varchar(100) NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `description` text DEFAULT NULL,
  `created_by` int(10) UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `loans`
--

CREATE TABLE `loans` (
  `loan_id` bigint(20) UNSIGNED NOT NULL,
  `account_id` int(11) NOT NULL,
  `borrower_user_id` int(10) UNSIGNED NOT NULL,
  `principal_amount` decimal(14,2) NOT NULL,
  `monthly_rate` decimal(6,4) NOT NULL,
  `term_months` int(10) UNSIGNED NOT NULL,
  `start_date` date NOT NULL,
  `status` enum('requested','approved','disbursed','closed','defaulted') NOT NULL DEFAULT 'requested',
  `approved_by` int(10) UNSIGNED DEFAULT NULL,
  `disbursed_by` int(10) UNSIGNED DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `reference_name` varchar(255) DEFAULT NULL,
  `reference_mime` varchar(100) DEFAULT NULL,
  `reference_file` mediumblob DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `loan_guarantors`
--

CREATE TABLE `loan_guarantors` (
  `loan_guarantor_id` bigint(20) UNSIGNED NOT NULL,
  `loan_id` bigint(20) UNSIGNED NOT NULL,
  `guarantor_user_id` int(10) UNSIGNED NOT NULL,
  `guarantee_amount` decimal(14,2) NOT NULL,
  `status` enum('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `notification_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `type` enum('loan_due_reminder','payment_received','interest_received') NOT NULL,
  `message` text NOT NULL,
  `channel` enum('sms','email','in_app') NOT NULL DEFAULT 'in_app',
  `status` enum('queued','sent','failed','read') NOT NULL DEFAULT 'queued',
  `scheduled_for` datetime DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` varchar(255) NOT NULL,
  `updated_by` int(10) UNSIGNED NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `trans_id` bigint(20) UNSIGNED NOT NULL,
  `tx_date` date NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `account_id` int(11) NOT NULL,
  `type` enum('contribution','withdrawal_deduction','loan_payment') NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `reference_name` varchar(255) DEFAULT NULL,
  `reference_mime` varchar(100) DEFAULT NULL,
  `reference_file` mediumblob DEFAULT NULL,
  `created_by` int(10) UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `names` varchar(255) NOT NULL,
  `nid_passport` varchar(16) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `phone1` varchar(20) DEFAULT NULL,
  `phone2` varchar(20) DEFAULT NULL,
  `guarantee_name` varchar(255) DEFAULT NULL,
  `guarantee_nid_passport` varchar(16) DEFAULT NULL,
  `guarantee_email` varchar(255) DEFAULT NULL,
  `guarantee_phone1` varchar(20) DEFAULT NULL,
  `guarantee_phone2` varchar(20) DEFAULT NULL,
  `is_member` tinyint(1) NOT NULL DEFAULT 0,
  `is_admin` tinyint(1) NOT NULL DEFAULT 0,
  `profile_image_name` varchar(255) DEFAULT NULL,
  `profile_image_mime` varchar(100) DEFAULT NULL,
  `profile_image_data` mediumblob DEFAULT NULL,
  `nid_image_name` varchar(255) DEFAULT NULL,
  `nid_image_mime` varchar(100) DEFAULT NULL,
  `nid_image_data` mediumblob DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `names`, `nid_passport`, `email`, `password`, `phone1`, `phone2`, `guarantee_name`, `guarantee_nid_passport`, `guarantee_email`, `guarantee_phone1`, `guarantee_phone2`, `is_member`, `is_admin`, `profile_image_name`, `profile_image_mime`, `profile_image_data`, `nid_image_name`, `nid_image_mime`, `nid_image_data`) VALUES
(1, 'Ngendahimana Joseph', '1199080023975090', 'ngendajo@gmail.com', '$2y$10$51nO3kT6VAMh4Ky3JB5m2OCAzEQwApa9lXBtU3HCX2ZZG1Z/SX9uu', '0784921483', NULL, 'Uwase Alice', '1198970025281052', 'alicewase2019@gmail.com', '0782532526', NULL, 1, 1, NULL, NULL, NULL, NULL, NULL, NULL),
(2, 'Mukiza Seraphin', '345678987654323', 'mukiza@gmail.com', '$2y$10$51nO3kT6VAMh4Ky3JB5m2OCAzEQwApa9lXBtU3HCX2ZZG1Z/SX9uu', '5678', NULL, 'Alicia', '6789890', 'alicia@gmail.com', '6789', NULL, 1, 0, NULL, NULL, NULL, NULL, NULL, NULL),
(3, 'Semaza Emmanuel', '67890', NULL, '', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL),
(4, 'Semaza Emmy', '56789', 'semaza@gmail.com', '$2y$10$51nO3kT6VAMh4Ky3JB5m2OCAzEQwApa9lXBtU3HCX2ZZG1Z/SX9uu', '67890', NULL, 'gg', '56789', 'gg@gmail.com', '6789', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `accounts`
--
ALTER TABLE `accounts`
  ADD PRIMARY KEY (`account_id`),
  ADD UNIQUE KEY `uq_account_name` (`name`),
  ADD KEY `idx_accounts_type` (`type`);

--
-- Indexes for table `assets`
--
ALTER TABLE `assets`
  ADD PRIMARY KEY (`asset_id`),
  ADD KEY `idx_assets_created_by` (`created_by`);

--
-- Indexes for table `asset_holders`
--
ALTER TABLE `asset_holders`
  ADD PRIMARY KEY (`asset_id`,`user_id`),
  ADD KEY `idx_asset_holders_user` (`user_id`),
  ADD KEY `idx_asset_holders_assigned_by` (`assigned_by`);

--
-- Indexes for table `expenses`
--
ALTER TABLE `expenses`
  ADD PRIMARY KEY (`expense_id`),
  ADD KEY `idx_expenses_date` (`expense_date`),
  ADD KEY `idx_expenses_category` (`category`),
  ADD KEY `idx_expenses_created_by` (`created_by`);

--
-- Indexes for table `loans`
--
ALTER TABLE `loans`
  ADD PRIMARY KEY (`loan_id`),
  ADD KEY `idx_loans_borrower` (`borrower_user_id`),
  ADD KEY `fk_loans_approved_by` (`approved_by`),
  ADD KEY `fk_loans_disbursed_by` (`disbursed_by`);

--
-- Indexes for table `loan_guarantors`
--
ALTER TABLE `loan_guarantors`
  ADD PRIMARY KEY (`loan_guarantor_id`),
  ADD UNIQUE KEY `uq_loan_guarantor` (`loan_id`,`guarantor_user_id`),
  ADD KEY `idx_loan_guarantors_loan` (`loan_id`),
  ADD KEY `idx_loan_guarantors_guarantor` (`guarantor_user_id`),
  ADD KEY `idx_loan_guarantors_status` (`status`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`notification_id`),
  ADD KEY `idx_notifications_user` (`user_id`),
  ADD KEY `idx_notifications_status` (`status`),
  ADD KEY `idx_notifications_scheduled` (`scheduled_for`),
  ADD KEY `idx_notifications_type` (`type`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`setting_key`),
  ADD KEY `idx_settings_updated_by` (`updated_by`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`trans_id`),
  ADD KEY `idx_transactions_date` (`tx_date`),
  ADD KEY `idx_transactions_type` (`type`),
  ADD KEY `idx_transactions_created_by` (`created_by`),
  ADD KEY `idx_transactions_user` (`user_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_users_nid_passport` (`nid_passport`),
  ADD KEY `idx_users_phone1` (`phone1`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `accounts`
--
ALTER TABLE `accounts`
  MODIFY `account_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `assets`
--
ALTER TABLE `assets`
  MODIFY `asset_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `expenses`
--
ALTER TABLE `expenses`
  MODIFY `expense_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `loans`
--
ALTER TABLE `loans`
  MODIFY `loan_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `loan_guarantors`
--
ALTER TABLE `loan_guarantors`
  MODIFY `loan_guarantor_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `notification_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `trans_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `assets`
--
ALTER TABLE `assets`
  ADD CONSTRAINT `fk_assets_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `asset_holders`
--
ALTER TABLE `asset_holders`
  ADD CONSTRAINT `fk_asset_holders_asset` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`asset_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_asset_holders_assigned_by` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_asset_holders_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `expenses`
--
ALTER TABLE `expenses`
  ADD CONSTRAINT `fk_expenses_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `loans`
--
ALTER TABLE `loans`
  ADD CONSTRAINT `fk_loans_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_loans_borrower` FOREIGN KEY (`borrower_user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_loans_disbursed_by` FOREIGN KEY (`disbursed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `loan_guarantors`
--
ALTER TABLE `loan_guarantors`
  ADD CONSTRAINT `fk_loan_guarantors_guarantor` FOREIGN KEY (`guarantor_user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_loan_guarantors_loan` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`loan_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `settings`
--
ALTER TABLE `settings`
  ADD CONSTRAINT `fk_settings_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `transactions`
--
ALTER TABLE `transactions`
  ADD CONSTRAINT `fk_transactions_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_transactions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
