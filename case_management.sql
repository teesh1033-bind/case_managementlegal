-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 25, 2026 at 09:58 AM
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
-- Database: `case_management`
--

-- --------------------------------------------------------

--
-- Table structure for table `appointments`
--

CREATE TABLE `appointments` (
  `id` int(11) NOT NULL,
  `client_id` int(11) DEFAULT NULL,
  `case_id` int(11) DEFAULT NULL,
  `lawyer_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `starts_at` datetime DEFAULT NULL,
  `ends_at` datetime DEFAULT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'pending',
  `recurrence_type` varchar(20) DEFAULT NULL,
  `recurrence_end` date DEFAULT NULL,
  `parent_appointment_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `appointments`
--

INSERT INTO `appointments` (`id`, `client_id`, `case_id`, `lawyer_id`, `user_id`, `starts_at`, `ends_at`, `status`, `recurrence_type`, `recurrence_end`, `parent_appointment_id`, `created_at`, `updated_at`, `notes`) VALUES
(3, 5, NULL, NULL, 6, '2025-12-01 20:00:00', '2025-12-02 20:00:00', 'pending', NULL, NULL, NULL, '2025-12-01 10:38:27', '2025-12-09 10:45:47', 'appointment'),
(17, 5, 17, 2, NULL, '2026-05-18 11:00:00', '2026-05-18 12:00:00', 'accepted', NULL, NULL, NULL, '2026-05-18 07:46:56', '2026-05-18 10:30:35', ''),
(18, 5, 17, 2, NULL, '2026-05-18 15:00:00', '2026-05-18 16:00:00', 'accepted', NULL, NULL, NULL, '2026-05-18 10:31:40', '2026-05-18 10:32:21', ''),
(19, 5, 21, 2, NULL, '2026-05-19 11:00:00', '2026-05-19 12:00:00', 'accepted', NULL, NULL, NULL, '2026-05-19 08:27:40', '2026-05-19 08:29:25', ''),
(20, 10, 20, 1, NULL, '2026-05-20 10:00:00', '2026-05-20 11:00:00', 'accepted', NULL, NULL, NULL, '2026-05-19 08:51:00', '2026-05-19 08:51:19', ''),
(21, 10, 20, 1, NULL, '2026-05-21 13:00:00', '2026-05-21 14:00:00', 'accepted', NULL, NULL, NULL, '2026-05-20 08:16:14', '2026-05-20 10:16:07', ''),
(22, 10, 20, 1, NULL, '2026-05-27 10:00:00', '2026-05-27 11:00:00', 'accepted', NULL, NULL, NULL, '2026-05-25 07:26:39', '2026-05-25 07:30:57', '');

-- --------------------------------------------------------

--
-- Table structure for table `cases`
--

CREATE TABLE `cases` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `status` varchar(50) DEFAULT 'open',
  `priority` varchar(50) DEFAULT 'Normal',
  `category` varchar(50) DEFAULT 'Civil',
  `estimated_fees` decimal(10,2) DEFAULT 0.00,
  `start_date` date DEFAULT NULL,
  `expected_completion` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `cases`
--

INSERT INTO `cases` (`id`, `client_id`, `user_id`, `title`, `description`, `status`, `priority`, `category`, `estimated_fees`, `start_date`, `expected_completion`, `created_at`, `updated_at`) VALUES
(17, 5, 1, 'Test Case', 'this is a test', 'open', 'High', 'Criminal', 20000.00, '2025-12-23', '2025-12-31', '2025-12-22 08:26:56', '2026-05-13 10:49:49'),
(19, 9, NULL, 'Contract', 'Not disclosed', 'open', 'Normal', 'Civil', 9999.86, '2026-05-18', '2026-05-18', '2026-05-17 05:19:56', '2026-05-17 05:19:56'),
(20, 10, 14, 'Dispute', '', 'open', 'Normal', 'Civil', 7656.00, '2026-05-15', NULL, '2026-05-17 06:31:55', '2026-05-17 06:31:55'),
(21, 5, 13, 'Crime', '', 'open', 'High', 'Civil', 3000.00, '2026-05-20', NULL, '2026-05-19 08:25:37', '2026-05-19 08:25:37');

-- --------------------------------------------------------

--
-- Table structure for table `case_comments`
--

CREATE TABLE `case_comments` (
  `id` int(11) NOT NULL,
  `case_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `comment` text NOT NULL,
  `comment_type` enum('client','lawyer','admin','staff') DEFAULT 'client',
  `is_private` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `case_comments`
--

INSERT INTO `case_comments` (`id`, `case_id`, `user_id`, `comment`, `comment_type`, `is_private`, `created_at`) VALUES
(4, 17, 9, 'fg', 'lawyer', 0, '2026-05-13 10:32:30'),
(5, 17, 13, 'good', 'client', 0, '2026-05-18 10:33:24'),
(6, 17, 13, 'good', 'client', 0, '2026-05-18 10:39:28'),
(7, 17, 13, 'good', 'client', 0, '2026-05-18 10:42:41'),
(8, 17, 13, 'top', 'client', 0, '2026-05-18 10:42:51'),
(9, 21, 13, 'Im not guilty', 'client', 0, '2026-05-19 10:29:07'),
(10, 21, 13, 'Im not guilty', 'client', 0, '2026-05-19 10:32:14');

-- --------------------------------------------------------

--
-- Table structure for table `case_events`
--

CREATE TABLE `case_events` (
  `id` int(11) NOT NULL,
  `case_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `event_type` varchar(100) NOT NULL,
  `event_description` text NOT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `case_events`
--

INSERT INTO `case_events` (`id`, `case_id`, `user_id`, `event_type`, `event_description`, `old_value`, `new_value`, `ip_address`, `user_agent`, `created_at`) VALUES
(14, 17, NULL, 'task_created', 'Task created: Complete Task', NULL, 'Complete Task', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', '2025-12-22 11:27:22'),
(15, 17, 9, 'task_status_changed', 'Task \'Complete Task\' status changed', 'pending', 'completed', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', '2025-12-22 11:37:14'),
(16, 17, 9, 'task_completed', 'Task completed: Complete Task', 'in_progress', 'completed', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', '2025-12-22 11:37:14'),
(17, 17, NULL, 'task_deleted', 'Task deleted: Complete Task', 'Complete Task', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', '2025-12-22 11:56:06'),
(18, 17, NULL, 'task_created', 'Task created: Complete Task', NULL, 'Complete Task', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', '2025-12-22 12:14:22'),
(19, 17, 9, 'task_status_changed', 'Task \'Complete Task\' status changed', 'pending', 'completed', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', '2025-12-22 12:15:11'),
(20, 17, 9, 'task_completed', 'Task completed: Complete Task', 'in_progress', 'completed', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', '2025-12-22 12:15:11'),
(21, 17, NULL, 'task_deleted', 'Task deleted: Complete Task', 'Complete Task', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', '2025-12-22 12:15:40'),
(22, 17, 1, 'court_date_created', 'Court date created: Hearing TRIAL on Dec 25, 2025 10:00 AM', NULL, 'Hearing TRIAL', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', '2025-12-23 10:26:15'),
(23, 17, 1, 'court_date_created', 'Court date created: Hearing Trial on Jan 08, 2026 10:00 AM', NULL, 'Hearing Trial', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', '2025-12-23 10:32:01'),
(24, 17, 9, 'court_date_created', 'Court date created: TESt on Jan 30, 2026 1:00 PM', NULL, 'TESt', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', '2025-12-23 10:33:26'),
(28, 17, 9, 'comment_added', 'Lawyer comment added', NULL, 'fg', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-13 10:32:30'),
(29, 17, 9, 'court_date_created', 'Court date created: Stealing on May 14, 2026 2:45 PM', NULL, 'Stealing', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-13 10:42:59'),
(30, 17, NULL, 'case_updated', 'Updated Priority', 'Normal', 'High', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-13 10:49:49'),
(31, 17, NULL, 'case_updated', 'Updated Category', 'Civil', 'Criminal', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-13 10:49:49'),
(32, 17, 13, 'comment_added', 'Client comment added', NULL, 'good', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-18 10:33:24'),
(33, 17, 13, 'comment_added', 'Client comment added', NULL, 'good', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-18 10:39:28'),
(34, 17, 13, 'comment_added', 'Client comment added', NULL, 'good', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-18 10:42:41'),
(35, 17, 13, 'comment_added', 'Client comment added', NULL, 'top', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-18 10:42:51'),
(36, 21, 13, 'comment_added', 'Client comment added', NULL, 'Im not guilty', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-19 10:29:07'),
(37, 21, 13, 'comment_added', 'Client comment added', NULL, 'Im not guilty', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-19 10:32:14'),
(38, 21, 13, 'document_uploaded', 'Document uploaded: Case Management System Overview (1).docx', NULL, 'Case Management System Overview (1).docx', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-19 10:32:23'),
(39, 21, NULL, 'task_created', 'Task created: let him out of the jail', NULL, 'let him out of the jail', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-20 07:03:07'),
(40, 21, 10, 'task_status_changed', 'Task \'let him out of the jail\' status changed', 'pending', 'in_progress', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-20 07:03:27'),
(41, 21, 10, 'task_status_changed', 'Task \'let him out of the jail\' status changed', 'in_progress', 'completed', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-20 07:03:30'),
(42, 21, 10, 'task_completed', 'Task completed: let him out of the jail', 'in_progress', 'completed', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-20 07:03:30'),
(43, 21, 10, 'task_completed', 'Task completed: let him out of the jail', 'in_progress', 'completed', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-20 07:03:33'),
(44, 20, NULL, 'payment_added', 'Payment recorded', NULL, '$2,000.00 (cash)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-25 06:49:20');

-- --------------------------------------------------------

--
-- Table structure for table `case_lawyers`
--

CREATE TABLE `case_lawyers` (
  `id` int(11) NOT NULL,
  `case_id` int(11) NOT NULL,
  `lawyer_id` int(11) NOT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_primary` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `case_lawyers`
--

INSERT INTO `case_lawyers` (`id`, `case_id`, `lawyer_id`, `assigned_at`, `is_primary`) VALUES
(6, 17, 1, '2026-05-13 10:49:49', 1),
(7, 17, 2, '2026-05-13 10:49:49', 0),
(9, 19, 2, '2026-05-17 05:19:56', 1),
(10, 20, 1, '2026-05-17 06:31:55', 1),
(11, 21, 1, '2026-05-19 08:25:38', 1);

-- --------------------------------------------------------

--
-- Table structure for table `case_services`
--

CREATE TABLE `case_services` (
  `id` int(11) NOT NULL,
  `case_id` int(11) NOT NULL,
  `service_name` varchar(255) NOT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `case_services`
--

INSERT INTO `case_services` (`id`, `case_id`, `service_name`, `price`, `created_at`) VALUES
(9, 17, '1', 12000.00, '2025-12-22 08:26:56'),
(10, 17, '2', 3000.00, '2025-12-22 08:26:56'),
(11, 17, '3', 5000.00, '2025-12-22 08:26:56'),
(14, 19, 'New', 9999.86, '2026-05-17 05:19:56'),
(15, 20, 'New', 2000.00, '2026-05-17 06:31:55'),
(16, 20, 'kiii', 5656.00, '2026-05-17 06:31:55'),
(17, 21, 'New', 3000.00, '2026-05-19 08:25:38');

-- --------------------------------------------------------

--
-- Table structure for table `case_stages`
--

CREATE TABLE `case_stages` (
  `id` int(11) NOT NULL,
  `case_id` int(11) NOT NULL,
  `stage_number` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `result` text DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `expected_end_date` date DEFAULT NULL,
  `actual_end_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `clients`
--

CREATE TABLE `clients` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `address` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(50) DEFAULT NULL,
  `zip` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `clients`
--

INSERT INTO `clients` (`id`, `user_id`, `first_name`, `last_name`, `email`, `phone`, `updated_at`, `address`, `city`, `state`, `zip`, `created_at`) VALUES
(5, 13, 'Yeshna', 'Ramguth', 'yeshna@gmail.com', '59093180', '2025-12-22 09:47:28', NULL, NULL, NULL, NULL, '2025-11-18 12:56:16'),
(9, NULL, 'james', 'noa', 'jchrinsley@gmail.com', '+23058964548', '2026-05-17 05:18:37', NULL, NULL, NULL, NULL, '2026-05-17 05:18:37'),
(10, 14, 'rio', 'nesh', 'jchrinsley02@gmail.com', '+23058964548', '2026-05-17 06:31:11', NULL, NULL, NULL, NULL, '2026-05-17 06:31:11');

-- --------------------------------------------------------

--
-- Table structure for table `court_dates`
--

CREATE TABLE `court_dates` (
  `id` int(11) NOT NULL,
  `case_id` int(11) NOT NULL,
  `court_date` datetime NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `status` enum('scheduled','completed','cancelled','postponed') DEFAULT 'scheduled',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `court_dates`
--

INSERT INTO `court_dates` (`id`, `case_id`, `court_date`, `title`, `description`, `location`, `status`, `created_by`, `created_at`, `updated_at`) VALUES
(3, 17, '2026-01-30 13:00:00', 'TESt', 'qwerty', 'Pamplemousses', 'scheduled', 2, '2025-12-23 10:33:26', '2025-12-23 10:33:26');

-- --------------------------------------------------------

--
-- Table structure for table `court_hearings`
--

CREATE TABLE `court_hearings` (
  `id` int(11) NOT NULL,
  `case_id` int(11) NOT NULL,
  `hearing_date` datetime NOT NULL,
  `hearing_type` varchar(100) NOT NULL,
  `court_name` varchar(255) DEFAULT NULL,
  `judge_name` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `status` enum('scheduled','completed','cancelled','postponed') DEFAULT 'scheduled',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `documents`
--

CREATE TABLE `documents` (
  `id` int(11) NOT NULL,
  `case_id` int(11) DEFAULT NULL,
  `filename` varchar(255) DEFAULT NULL,
  `label` varchar(255) DEFAULT NULL,
  `uploaded_by` varchar(100) DEFAULT NULL,
  `filepath` varchar(255) DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `documents`
--

INSERT INTO `documents` (`id`, `case_id`, `filename`, `label`, `uploaded_by`, `filepath`, `uploaded_at`) VALUES
(11, 17, '68b5ba9dd2607.png', '68b5ba9dd2607.png', 'Lawyer Two', 'uploads/lawyer_files/6a04534cd83a6_68b5ba9dd2607.png', '2026-05-13 10:32:44'),
(12, 21, 'Case Management System Overview (1).docx', 'Case Management System Overview (1).docx', 'Yeshna Ramguth', 'uploads/client_files/6a0c3baa0adb5_Case Management System Overview (1).docx', '2026-05-19 10:30:02'),
(13, 21, 'Case Management System Overview (1).docx', 'Case Management System Overview (1).docx', 'Yeshna Ramguth', 'uploads/client_files/6a0c3c37565ab_Case Management System Overview (1).docx', '2026-05-19 10:32:23');

-- --------------------------------------------------------

--
-- Table structure for table `document_templates`
--

CREATE TABLE `document_templates` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `category` varchar(50) DEFAULT 'General',
  `description` varchar(255) DEFAULT NULL,
  `body` text NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `document_templates`
--

INSERT INTO `document_templates` (`id`, `name`, `category`, `description`, `body`, `updated_at`) VALUES
(1, 'Retainer Agreement', 'General', 'Standard engagement/retainer letter', 'This Retainer Agreement is made on {{today}} between {{client_name}} and {{firm_name}} regarding case {{case_number}} ({{case_title}}).\n\nScope: {{scope}}\nFee Arrangement: {{fee_structure}}\nPrimary Contact: {{lawyer_name}}\n\nThank you,\n{{firm_name}}', '2025-11-18 08:18:43'),
(2, 'Affidavit Template', 'General', 'Sworn statement placeholder', 'I, {{client_name}}, being duly sworn, depose and state:\n1. {{statement_one}}\n2. {{statement_two}}\n\nDated: {{today}}\nCase: {{case_number}} – {{case_title}}', '2025-11-18 08:18:43'),
(3, 'Invoice Cover Letter', 'General', 'Short cover note for invoices', 'Dear {{client_name}},\n\nPlease find the invoice for {{case_title}} attached. The outstanding balance is {{balance}}.\n\nSincerely,\n{{firm_name}}', '2025-11-18 08:18:43');

-- --------------------------------------------------------

--
-- Table structure for table `invoices`
--

CREATE TABLE `invoices` (
  `id` int(11) NOT NULL,
  `client_id` int(11) DEFAULT NULL,
  `case_id` int(11) DEFAULT NULL,
  `invoice_number` varchar(100) DEFAULT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `issue_date` date DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` varchar(50) DEFAULT 'unpaid',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `Test` int(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `invoices`
--

INSERT INTO `invoices` (`id`, `client_id`, `case_id`, `invoice_number`, `amount`, `issue_date`, `due_date`, `notes`, `status`, `created_at`, `Test`) VALUES
(8, 5, 17, 'Invoice 1', 5000.00, '2025-12-23', '2026-01-06', 'qwerty', 'paid', '2025-12-23 11:38:54', 0);

-- --------------------------------------------------------

--
-- Table structure for table `lawyers`
--

CREATE TABLE `lawyers` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `license_number` varchar(100) DEFAULT NULL,
  `specialization` varchar(255) DEFAULT NULL,
  `experience_years` int(11) DEFAULT 0,
  `bio` text DEFAULT NULL,
  `office_address` text DEFAULT NULL,
  `hourly_rate` decimal(10,2) DEFAULT 0.00,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `lawyers`
--

INSERT INTO `lawyers` (`id`, `user_id`, `first_name`, `last_name`, `email`, `phone`, `license_number`, `specialization`, `experience_years`, `bio`, `office_address`, `hourly_rate`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 10, 'Lawyer', 'One', 'lawyerone@gmail.com', '57654321', '124124134', 'crime law', 2, '', 'Port louis', 50.00, 1, '2025-12-08 11:48:12', '2026-05-19 08:46:42'),
(2, 9, 'Lawyer', 'Two', 'lawyer02@gmail.com', '54344222', '1234543', 'crime law', 4, 'test', 'terre rouge', 0.00, 1, '2025-12-08 12:20:30', '2025-12-22 08:24:38'),
(3, 14, 'Chrinsley', 'James', 'jchrinsley2@gmail.com', '58964548', '122456', 'Criminal Law', 8, '', 'richelieu', 0.00, 1, '2026-05-18 07:30:02', '2026-05-18 07:30:02'),
(5, 1, 'adrian', 'jessy', 'jes@gmail.com', '58964548', '122456', '', 6, '', 'richelieu', 0.00, 1, '2026-05-18 08:06:04', '2026-05-18 08:06:04');

-- --------------------------------------------------------

--
-- Table structure for table `lawyer_availability`
--

CREATE TABLE `lawyer_availability` (
  `id` int(11) NOT NULL,
  `lawyer_id` int(11) NOT NULL,
  `day_of_week` enum('monday','tuesday','wednesday','thursday','friday','saturday','sunday') NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `is_available` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `lawyer_availability`
--

INSERT INTO `lawyer_availability` (`id`, `lawyer_id`, `day_of_week`, `start_time`, `end_time`, `is_available`) VALUES
(50, 2, 'monday', '09:00:00', '17:00:00', 1),
(51, 2, 'tuesday', '09:00:00', '17:00:00', 1),
(52, 2, 'wednesday', '09:00:00', '17:00:00', 1),
(53, 2, 'thursday', '09:00:00', '17:00:00', 1),
(54, 2, 'friday', '09:00:00', '17:00:00', 1),
(55, 2, 'saturday', '09:00:00', '17:00:00', 1),
(56, 2, 'sunday', '09:00:00', '17:00:00', 1),
(57, 3, 'monday', '09:00:00', '17:00:00', 1),
(58, 3, 'tuesday', '09:00:00', '17:00:00', 0),
(59, 3, 'wednesday', '09:00:00', '17:00:00', 0),
(60, 3, 'thursday', '09:00:00', '17:00:00', 1),
(61, 3, 'friday', '09:00:00', '17:00:00', 0),
(62, 3, 'saturday', '09:00:00', '17:00:00', 1),
(63, 3, 'sunday', '09:00:00', '17:00:00', 0),
(64, 5, 'monday', '09:00:00', '17:00:00', 1),
(65, 5, 'tuesday', '09:00:00', '17:00:00', 1),
(66, 5, 'wednesday', '09:00:00', '17:00:00', 1),
(67, 5, 'thursday', '09:00:00', '17:00:00', 1),
(68, 5, 'friday', '09:00:00', '17:00:00', 1),
(69, 5, 'saturday', '09:00:00', '17:00:00', 1),
(70, 5, 'sunday', '09:00:00', '17:00:00', 1),
(92, 1, 'monday', '09:00:00', '17:00:00', 1),
(93, 1, 'tuesday', '09:00:00', '17:00:00', 1),
(94, 1, 'wednesday', '09:00:00', '17:00:00', 1),
(95, 1, 'thursday', '09:00:00', '17:00:00', 1),
(96, 1, 'friday', '09:00:00', '17:00:00', 1),
(97, 1, 'saturday', '09:00:00', '17:00:00', 1),
(98, 1, 'sunday', '09:00:00', '17:00:00', 1);

-- --------------------------------------------------------

--
-- Table structure for table `lawyer_time_slots`
--

CREATE TABLE `lawyer_time_slots` (
  `id` int(11) NOT NULL,
  `lawyer_id` int(11) NOT NULL,
  `day_of_week` enum('monday','tuesday','wednesday','thursday','friday','saturday','sunday') NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `slot_type` enum('available','unavailable') DEFAULT 'available',
  `slot_order` int(11) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `lawyer_time_slots`
--

INSERT INTO `lawyer_time_slots` (`id`, `lawyer_id`, `day_of_week`, `start_time`, `end_time`, `slot_type`, `slot_order`, `created_at`) VALUES
(1, 2, 'monday', '10:00:00', '11:30:00', 'available', 1, '2025-12-09 10:21:49'),
(3, 2, 'monday', '11:30:00', '14:00:00', 'unavailable', 1, '2025-12-09 10:22:43'),
(4, 2, 'monday', '14:00:00', '16:00:00', 'available', 1, '2025-12-09 10:23:17'),
(6, 2, 'wednesday', '10:00:00', '12:00:00', 'available', 1, '2025-12-09 10:23:49'),
(7, 2, 'friday', '15:00:00', '16:00:00', 'available', 1, '2025-12-09 10:24:19'),
(8, 2, 'tuesday', '11:00:00', '12:00:00', 'available', 1, '2025-12-09 12:54:17'),
(9, 2, 'monday', '14:39:00', '16:41:00', 'available', 1, '2026-05-13 10:39:47'),
(10, 1, 'monday', '01:00:00', '00:48:00', 'available', 1, '2026-05-19 08:48:57'),
(11, 1, 'tuesday', '23:49:00', '13:49:00', 'available', 1, '2026-05-19 08:49:26'),
(12, 1, 'wednesday', '07:49:00', '11:50:00', 'available', 1, '2026-05-19 08:49:49'),
(13, 1, 'thursday', '12:49:00', '23:49:00', 'available', 1, '2026-05-19 08:50:03'),
(14, 1, 'friday', '06:50:00', '21:50:00', 'available', 1, '2026-05-19 08:50:20');

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `case_id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `method` varchar(50) DEFAULT 'cash',
  `reference` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `payment_date` date DEFAULT NULL,
  `recorded_by` varchar(100) DEFAULT NULL,
  `invoice_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`id`, `case_id`, `client_id`, `amount`, `method`, `reference`, `notes`, `payment_date`, `recorded_by`, `invoice_id`, `created_at`) VALUES
(1, 17, 5, 5000.00, 'invoice', 'Invoice 1', 'Auto-generated from invoice Invoice 1', '2025-12-23', 'system', 8, '2025-12-23 11:38:54'),
(2, 20, 10, 2000.00, 'cash', '', '', '2026-05-25', 'admin', NULL, '2026-05-25 06:49:20');

-- --------------------------------------------------------

--
-- Table structure for table `services`
--

CREATE TABLE `services` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `services`
--

INSERT INTO `services` (`id`, `name`, `price`, `description`, `created_at`) VALUES
(1, 'Consultation / Legal Advice', 9950.00, 'Standard service', '2025-11-18 12:47:39'),
(2, 'Court Representation', 75000.00, 'Standard service', '2025-11-18 12:47:39'),
(3, 'Mediation', 10000.00, 'Standard service', '2025-11-18 12:47:39');

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` int(11) NOT NULL,
  `key` varchar(100) NOT NULL,
  `value` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `settings`
--

INSERT INTO `settings` (`id`, `key`, `value`, `updated_at`) VALUES
(1, 'currency', 'MUR', '2025-11-18 11:38:31');

-- --------------------------------------------------------

--
-- Table structure for table `tasks`
--

CREATE TABLE `tasks` (
  `id` int(11) NOT NULL,
  `case_id` int(11) NOT NULL,
  `assigned_lawyer_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('pending','in_progress','completed','cancelled') DEFAULT 'pending',
  `priority` enum('low','medium','high') DEFAULT 'medium',
  `due_date` date DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `completed_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tasks`
--

INSERT INTO `tasks` (`id`, `case_id`, `assigned_lawyer_id`, `title`, `description`, `status`, `priority`, `due_date`, `created_by`, `created_at`, `updated_at`, `completed_at`) VALUES
(1, 21, 1, 'let him out of the jail', 'Secret', 'completed', 'medium', NULL, NULL, '2026-05-20 07:03:07', '2026-05-20 07:03:33', '2026-05-20 07:03:33');

-- --------------------------------------------------------

--
-- Table structure for table `task_comments`
--

CREATE TABLE `task_comments` (
  `id` int(11) NOT NULL,
  `task_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `comment` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `task_dependencies`
--

CREATE TABLE `task_dependencies` (
  `id` int(11) NOT NULL,
  `task_id` int(11) NOT NULL,
  `depends_on_task_id` int(11) NOT NULL,
  `dependency_type` enum('finish_to_start','start_to_start','finish_to_finish','start_to_finish') DEFAULT 'finish_to_start',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `task_reminders`
--

CREATE TABLE `task_reminders` (
  `id` int(11) NOT NULL,
  `task_id` int(11) NOT NULL,
  `reminder_type` enum('deadline','overdue','upcoming','custom') DEFAULT 'custom',
  `reminder_date` datetime NOT NULL,
  `message` text DEFAULT NULL,
  `is_sent` tinyint(1) DEFAULT 0,
  `sent_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `task_templates`
--

CREATE TABLE `task_templates` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `case_category` varchar(50) NOT NULL,
  `priority` enum('low','medium','high','urgent') DEFAULT 'medium',
  `estimated_hours` decimal(5,2) DEFAULT NULL,
  `due_date_offset` int(11) DEFAULT NULL,
  `is_default` tinyint(1) DEFAULT 0,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `task_templates`
--

INSERT INTO `task_templates` (`id`, `name`, `description`, `case_category`, `priority`, `estimated_hours`, `due_date_offset`, `is_default`, `sort_order`, `created_at`) VALUES
(1, 'Initial Client Consultation', 'Schedule and conduct initial client consultation to understand case requirements', 'Civil', 'high', 2.00, 3, 1, 1, '2025-12-19 12:00:18'),
(2, 'Document Collection', 'Collect all necessary documents from client', 'Civil', 'medium', 4.00, 7, 1, 2, '2025-12-19 12:00:18'),
(3, 'Legal Research', 'Conduct preliminary legal research on the case', 'Civil', 'medium', 8.00, 14, 1, 3, '2025-12-19 12:00:18'),
(4, 'Draft Initial Pleadings', 'Prepare and draft initial legal documents', 'Civil', 'high', 12.00, 21, 1, 4, '2025-12-19 12:00:18'),
(5, 'Client Communication', 'Regular communication with client about case progress', 'Civil', 'medium', 2.00, NULL, 1, 5, '2025-12-19 12:00:18'),
(6, 'Evidence Gathering', 'Collect and organize evidence for criminal case', 'Criminal', 'high', 16.00, 7, 1, 1, '2025-12-19 12:00:18'),
(7, 'Interview Witnesses', 'Conduct witness interviews and statements', 'Criminal', 'high', 12.00, 14, 1, 2, '2025-12-19 12:00:18'),
(8, 'Prepare Defense Strategy', 'Develop and document defense strategy', 'Criminal', 'urgent', 20.00, 21, 1, 3, '2025-12-19 12:00:18'),
(9, 'Court Preparation', 'Prepare for court appearances and hearings', 'Criminal', 'urgent', 24.00, 30, 1, 4, '2025-12-19 12:00:18'),
(10, 'Due Diligence Review', 'Conduct comprehensive due diligence for corporate transaction', 'Corporate', 'high', 40.00, 14, 1, 1, '2025-12-19 12:00:18'),
(11, 'Contract Drafting', 'Draft all necessary corporate contracts and agreements', 'Corporate', 'high', 32.00, 21, 1, 2, '2025-12-19 12:00:18'),
(12, 'Regulatory Compliance Check', 'Ensure compliance with all relevant regulations', 'Corporate', 'medium', 16.00, 28, 1, 3, '2025-12-19 12:00:18'),
(13, 'Board Approval Process', 'Facilitate board approval for corporate actions', 'Corporate', 'high', 8.00, 35, 1, 4, '2025-12-19 12:00:18'),
(14, 'Custody Assessment', 'Conduct custody and visitation assessments', 'Family', 'high', 24.00, 14, 1, 1, '2025-12-19 12:00:18'),
(15, 'Mediation Sessions', 'Facilitate mediation between parties', 'Family', 'medium', 12.00, 21, 1, 2, '2025-12-19 12:00:18'),
(16, 'Child Welfare Reports', 'Prepare child welfare and best interest reports', 'Family', 'high', 16.00, 28, 1, 3, '2025-12-19 12:00:18'),
(17, 'Court Documentation', 'Prepare all necessary court documents', 'Family', 'high', 20.00, 35, 1, 4, '2025-12-19 12:00:18');

-- --------------------------------------------------------

--
-- Table structure for table `task_time_entries`
--

CREATE TABLE `task_time_entries` (
  `id` int(11) NOT NULL,
  `task_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `hours` decimal(5,2) NOT NULL,
  `description` text DEFAULT NULL,
  `date_worked` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `task_workflows`
--

CREATE TABLE `task_workflows` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `case_category` varchar(50) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `task_workflows`
--

INSERT INTO `task_workflows` (`id`, `name`, `description`, `case_category`, `is_active`, `created_at`) VALUES
(1, 'Standard Civil Litigation', 'Standard workflow for civil litigation cases', 'Civil', 1, '2025-12-19 12:00:18'),
(2, 'Criminal Defense Process', 'Standard workflow for criminal defense cases', 'Criminal', 1, '2025-12-19 12:00:18'),
(3, 'Corporate Transaction', 'Workflow for corporate mergers and acquisitions', 'Corporate', 1, '2025-12-19 12:00:18'),
(4, 'Family Law Process', 'Workflow for divorce and family law cases', 'Family', 1, '2025-12-19 12:00:18');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `role` varchar(50) DEFAULT 'user',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `email`, `role`, `created_at`) VALUES
(1, 'admin', '$2y$10$z3CFnqdKb6afSv5zDF9P.eCIiAVfxTfaaEKIOR8yiPw5FxmWEoS6.', 'admin@lexmate.com', 'admin', '2025-11-12 10:26:55'),
(6, 'lawyer', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'lawyer@example.com', 'lawyer', '2025-12-01 08:26:24'),
(7, 'staff', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'staff@example.com', 'staff', '2025-12-01 08:26:24'),
(8, 'client', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'client@example.com', 'client', '2025-12-01 08:26:24'),
(9, 'lawyer02', '$2y$10$55MBBI6cDbajSRW4wI.kEOr6CiWjXrEBE.uXM9.kRYIZ2h9BygsyG', 'lawyer02@gmail.com', 'lawyer', '2025-12-08 12:20:30'),
(10, 'lawyer01', '$2y$10$thVTasHp2E18t/rM0vF0Z.ihGr.fILqiARVZMV55t5r2OxDOMoPBa', 'lawyer01@gmail.com', 'lawyer', '2025-12-08 12:21:35'),
(11, 'clientone@gmail.com', '$2y$10$zZQvLO6N5WGHqXP.8i20ne9lObBu.aoNHV5cITJ2PB5vpPCF5tE/C', 'clientone@gmail.com', 'client', '2025-12-09 07:44:25'),
(13, 'yeshna', '$2y$10$itqTOUi/iW2D6PbZZPqQeuB4xgyfWICAJvbVAnkXhTg0LxX8gm5CW', 'yeshna@gmail.com', 'client', '2025-12-22 09:47:28'),
(14, 'rio', '$2y$10$g8Jx32PsjAX7ZDvgcFq3/udLmuJ5qvAz.CDDCEHNtPmuPKwPlgis6', 'jchrinsley02@gmail.com', 'client', '2026-05-17 06:31:11');

-- --------------------------------------------------------

--
-- Table structure for table `workflows`
--

CREATE TABLE `workflows` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `matter_type` varchar(100) NOT NULL,
  `is_default` tinyint(1) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `workflow_steps`
--

CREATE TABLE `workflow_steps` (
  `id` int(11) NOT NULL,
  `workflow_id` int(11) NOT NULL,
  `step_number` int(11) NOT NULL,
  `task_template_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `depends_on_step` int(11) DEFAULT NULL,
  `estimated_hours` decimal(5,2) DEFAULT NULL,
  `due_date_offset` int(11) DEFAULT NULL,
  `assigned_role` varchar(50) DEFAULT NULL,
  `is_required` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `appointments`
--
ALTER TABLE `appointments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `client_id` (`client_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `fk_appointments_parent` (`parent_appointment_id`),
  ADD KEY `case_id` (`case_id`),
  ADD KEY `fk_appointments_lawyer` (`lawyer_id`);

--
-- Indexes for table `cases`
--
ALTER TABLE `cases`
  ADD PRIMARY KEY (`id`),
  ADD KEY `client_id` (`client_id`);

--
-- Indexes for table `case_comments`
--
ALTER TABLE `case_comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `case_id` (`case_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `case_events`
--
ALTER TABLE `case_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_case_events_case_id` (`case_id`),
  ADD KEY `idx_case_events_created_at` (`created_at`);

--
-- Indexes for table `case_lawyers`
--
ALTER TABLE `case_lawyers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_case_lawyer` (`case_id`,`lawyer_id`),
  ADD KEY `lawyer_id` (`lawyer_id`);

--
-- Indexes for table `case_services`
--
ALTER TABLE `case_services`
  ADD PRIMARY KEY (`id`),
  ADD KEY `case_id` (`case_id`);

--
-- Indexes for table `case_stages`
--
ALTER TABLE `case_stages`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_case_stage` (`case_id`,`stage_number`);

--
-- Indexes for table `clients`
--
ALTER TABLE `clients`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_clients_user_id` (`user_id`);

--
-- Indexes for table `court_dates`
--
ALTER TABLE `court_dates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_court_dates_case_id` (`case_id`),
  ADD KEY `idx_court_dates_court_date` (`court_date`),
  ADD KEY `idx_court_dates_status` (`status`);

--
-- Indexes for table `court_hearings`
--
ALTER TABLE `court_hearings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_court_hearings_case_id` (`case_id`),
  ADD KEY `idx_court_hearings_date` (`hearing_date`),
  ADD KEY `idx_court_hearings_status` (`status`);

--
-- Indexes for table `documents`
--
ALTER TABLE `documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `case_id` (`case_id`);

--
-- Indexes for table `document_templates`
--
ALTER TABLE `document_templates`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`id`),
  ADD KEY `client_id` (`client_id`);

--
-- Indexes for table `lawyers`
--
ALTER TABLE `lawyers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `lawyer_availability`
--
ALTER TABLE `lawyer_availability`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_lawyer_day` (`lawyer_id`,`day_of_week`);

--
-- Indexes for table `lawyer_time_slots`
--
ALTER TABLE `lawyer_time_slots`
  ADD PRIMARY KEY (`id`),
  ADD KEY `lawyer_id` (`lawyer_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `case_id` (`case_id`),
  ADD KEY `client_id` (`client_id`),
  ADD KEY `fk_payments_invoice` (`invoice_id`);

--
-- Indexes for table `services`
--
ALTER TABLE `services`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `key` (`key`);

--
-- Indexes for table `tasks`
--
ALTER TABLE `tasks`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `task_comments`
--
ALTER TABLE `task_comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_comments_task` (`task_id`);

--
-- Indexes for table `task_dependencies`
--
ALTER TABLE `task_dependencies`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_dependency` (`task_id`,`depends_on_task_id`),
  ADD KEY `depends_on_task_id` (`depends_on_task_id`);

--
-- Indexes for table `task_reminders`
--
ALTER TABLE `task_reminders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_reminders_task` (`task_id`),
  ADD KEY `idx_reminders_date` (`reminder_date`),
  ADD KEY `idx_reminders_sent` (`is_sent`);

--
-- Indexes for table `task_templates`
--
ALTER TABLE `task_templates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_templates_category` (`case_category`),
  ADD KEY `idx_templates_default` (`is_default`);

--
-- Indexes for table `task_time_entries`
--
ALTER TABLE `task_time_entries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_time_task` (`task_id`),
  ADD KEY `idx_time_user` (`user_id`),
  ADD KEY `idx_time_date` (`date_worked`);

--
-- Indexes for table `task_workflows`
--
ALTER TABLE `task_workflows`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_workflows_category` (`case_category`),
  ADD KEY `idx_workflows_active` (`is_active`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `workflows`
--
ALTER TABLE `workflows`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `workflow_steps`
--
ALTER TABLE `workflow_steps`
  ADD PRIMARY KEY (`id`),
  ADD KEY `task_template_id` (`task_template_id`),
  ADD KEY `depends_on_step` (`depends_on_step`),
  ADD KEY `idx_steps_workflow` (`workflow_id`),
  ADD KEY `idx_steps_number` (`step_number`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `appointments`
--
ALTER TABLE `appointments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `cases`
--
ALTER TABLE `cases`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `case_comments`
--
ALTER TABLE `case_comments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `case_events`
--
ALTER TABLE `case_events`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=45;

--
-- AUTO_INCREMENT for table `case_lawyers`
--
ALTER TABLE `case_lawyers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `case_services`
--
ALTER TABLE `case_services`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `case_stages`
--
ALTER TABLE `case_stages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `clients`
--
ALTER TABLE `clients`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `court_dates`
--
ALTER TABLE `court_dates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `court_hearings`
--
ALTER TABLE `court_hearings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `documents`
--
ALTER TABLE `documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `document_templates`
--
ALTER TABLE `document_templates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `invoices`
--
ALTER TABLE `invoices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `lawyers`
--
ALTER TABLE `lawyers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `lawyer_availability`
--
ALTER TABLE `lawyer_availability`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=99;

--
-- AUTO_INCREMENT for table `lawyer_time_slots`
--
ALTER TABLE `lawyer_time_slots`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `services`
--
ALTER TABLE `services`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `tasks`
--
ALTER TABLE `tasks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `task_comments`
--
ALTER TABLE `task_comments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `task_dependencies`
--
ALTER TABLE `task_dependencies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `task_reminders`
--
ALTER TABLE `task_reminders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `task_templates`
--
ALTER TABLE `task_templates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `task_time_entries`
--
ALTER TABLE `task_time_entries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `task_workflows`
--
ALTER TABLE `task_workflows`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `workflows`
--
ALTER TABLE `workflows`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `workflow_steps`
--
ALTER TABLE `workflow_steps`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `appointments`
--
ALTER TABLE `appointments`
  ADD CONSTRAINT `appointments_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `appointments_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `appointments_ibfk_3` FOREIGN KEY (`case_id`) REFERENCES `cases` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_appointments_lawyer` FOREIGN KEY (`lawyer_id`) REFERENCES `lawyers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_appointments_parent` FOREIGN KEY (`parent_appointment_id`) REFERENCES `appointments` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `cases`
--
ALTER TABLE `cases`
  ADD CONSTRAINT `cases_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `case_comments`
--
ALTER TABLE `case_comments`
  ADD CONSTRAINT `case_comments_ibfk_1` FOREIGN KEY (`case_id`) REFERENCES `cases` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `case_comments_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `case_events`
--
ALTER TABLE `case_events`
  ADD CONSTRAINT `case_events_ibfk_1` FOREIGN KEY (`case_id`) REFERENCES `cases` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `case_events_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `case_lawyers`
--
ALTER TABLE `case_lawyers`
  ADD CONSTRAINT `case_lawyers_ibfk_1` FOREIGN KEY (`case_id`) REFERENCES `cases` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `case_lawyers_ibfk_2` FOREIGN KEY (`lawyer_id`) REFERENCES `lawyers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `case_services`
--
ALTER TABLE `case_services`
  ADD CONSTRAINT `case_services_ibfk_1` FOREIGN KEY (`case_id`) REFERENCES `cases` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `case_stages`
--
ALTER TABLE `case_stages`
  ADD CONSTRAINT `case_stages_ibfk_1` FOREIGN KEY (`case_id`) REFERENCES `cases` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `clients`
--
ALTER TABLE `clients`
  ADD CONSTRAINT `fk_clients_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `court_hearings`
--
ALTER TABLE `court_hearings`
  ADD CONSTRAINT `court_hearings_ibfk_1` FOREIGN KEY (`case_id`) REFERENCES `cases` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `court_hearings_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `documents`
--
ALTER TABLE `documents`
  ADD CONSTRAINT `documents_ibfk_1` FOREIGN KEY (`case_id`) REFERENCES `cases` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `invoices`
--
ALTER TABLE `invoices`
  ADD CONSTRAINT `invoices_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `lawyers`
--
ALTER TABLE `lawyers`
  ADD CONSTRAINT `lawyers_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lawyers_ibfk_10` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lawyers_ibfk_11` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lawyers_ibfk_12` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lawyers_ibfk_13` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lawyers_ibfk_14` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lawyers_ibfk_15` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lawyers_ibfk_16` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lawyers_ibfk_17` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lawyers_ibfk_18` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lawyers_ibfk_19` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lawyers_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lawyers_ibfk_20` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lawyers_ibfk_21` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lawyers_ibfk_22` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lawyers_ibfk_23` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lawyers_ibfk_24` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lawyers_ibfk_25` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lawyers_ibfk_26` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lawyers_ibfk_27` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lawyers_ibfk_28` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lawyers_ibfk_29` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lawyers_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lawyers_ibfk_30` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lawyers_ibfk_31` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lawyers_ibfk_32` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lawyers_ibfk_33` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lawyers_ibfk_34` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lawyers_ibfk_35` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lawyers_ibfk_36` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lawyers_ibfk_37` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lawyers_ibfk_38` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lawyers_ibfk_39` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lawyers_ibfk_4` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lawyers_ibfk_40` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lawyers_ibfk_41` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lawyers_ibfk_42` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lawyers_ibfk_43` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lawyers_ibfk_44` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lawyers_ibfk_45` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lawyers_ibfk_46` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lawyers_ibfk_47` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lawyers_ibfk_48` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lawyers_ibfk_5` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lawyers_ibfk_6` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lawyers_ibfk_7` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lawyers_ibfk_8` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lawyers_ibfk_9` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `lawyer_availability`
--
ALTER TABLE `lawyer_availability`
  ADD CONSTRAINT `lawyer_availability_ibfk_1` FOREIGN KEY (`lawyer_id`) REFERENCES `lawyers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `lawyer_time_slots`
--
ALTER TABLE `lawyer_time_slots`
  ADD CONSTRAINT `lawyer_time_slots_ibfk_1` FOREIGN KEY (`lawyer_id`) REFERENCES `lawyers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `fk_payments_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`case_id`) REFERENCES `cases` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `task_comments`
--
ALTER TABLE `task_comments`
  ADD CONSTRAINT `task_comments_ibfk_1` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `task_comments_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `task_dependencies`
--
ALTER TABLE `task_dependencies`
  ADD CONSTRAINT `task_dependencies_ibfk_1` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `task_dependencies_ibfk_2` FOREIGN KEY (`depends_on_task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `task_reminders`
--
ALTER TABLE `task_reminders`
  ADD CONSTRAINT `task_reminders_ibfk_1` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `task_time_entries`
--
ALTER TABLE `task_time_entries`
  ADD CONSTRAINT `task_time_entries_ibfk_1` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `task_time_entries_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `workflow_steps`
--
ALTER TABLE `workflow_steps`
  ADD CONSTRAINT `workflow_steps_ibfk_1` FOREIGN KEY (`workflow_id`) REFERENCES `task_workflows` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `workflow_steps_ibfk_2` FOREIGN KEY (`task_template_id`) REFERENCES `task_templates` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `workflow_steps_ibfk_3` FOREIGN KEY (`depends_on_step`) REFERENCES `workflow_steps` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
