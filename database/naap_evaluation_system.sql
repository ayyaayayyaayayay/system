-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jul 14, 2026 at 01:10 PM
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
-- Database: `naap_evaluation_system`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_log`
--

CREATE TABLE `activity_log` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `log_code` varchar(30) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `description` text NOT NULL,
  `entry_type` varchar(50) NOT NULL DEFAULT 'system',
  `ip_address` varchar(45) NOT NULL DEFAULT '',
  `happened_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `activity_log`
--

INSERT INTO `activity_log` (`id`, `user_id`, `log_code`, `action`, `description`, `entry_type`, `ip_address`, `happened_at`) VALUES
(1, 1, 'LOG-0001', 'Login', 'Admin logged in as admin', 'login', '::1', '2026-07-14 18:54:42'),
(2, 1, 'LOG-0002', 'Bulk Users Saved', '151 user records changed: User u10 Campus: [empty] -> villamor; User u10 Department: [empty] -> ics; User u10 Email: [empty] -> professor.002@naap.edu.ph; User u10 Employee ID: [empty] -> PRF-2026-002; User u10 Employment Type: [empty] -> Regular; User u10 Name: [empty] -> Alden B Valencia; User u10 Password: [empty] -> [set]; User u10 Position: [empty] -> Associate Professor; User u10 Program Code: [empty] -> BSAIS; User u10 Program Name: [empty] -> Bachelor of Science in Accounting Information Systems; User u10 Role: [empty] -> professor; User u10 Status: [empty] -> active; User u10 Student Number: [empty] -> [empty]; User u10 Year Section: [empty] -> [empty]; User u100 Campus: [empty] -> villamor; User u100 Department: [empty] -> ics; User u100 Email: [empty] -> student.062@naap.edu.ph; User u100 Employee ID: [empty] -> [empty]; User u100 Employment Type: [empty] -> [empty]; User u100 Name: [empty] -> Camille Troy Quintos; User u100 Password: [empty] -> [set]; User u100 Position: [empty] -> [empty]; User u100 Program Code: [empty] -> BSIT; User u100 Program Name: [empty] -> Bachelor of Science in Information Technology; User u100 Role: [empty] -> student; User u100 Status: [empty] -> active; User u100 Student Number: [empty] -> 12324MN-000062; User u100 Year Section: [empty] -> 3-1; User u101 Campus: [empty] -> villamor; User u101 Department: [empty] -> ics; User u101 Email: [empty] -> student.063@naap.edu.ph; User u101 Employee ID: [empty] -> [empty]; User u101 Employment Type: [empty] -> [empty]; User u101 Name: [empty] -> Carlo F Aguilar; User u101 Password: [empty] -> [set]; User u101 Position: [empty] -> [empty]; User u101 Program Code: [empty] -> BSAIS; User u101 Program Name: [empty] -> Bachelor of Science in Accounting Information Systems; User u101 Role: [empty] -> student; User u101 Status: [empty] -> active; User u101 Student Number: [empty] -> 12324MN-000063; User u101 Year Section: [empty] -> 4-1; (+2058 more changes)', 'user', '::1', '2026-07-14 18:55:31'),
(3, 1, 'LOG-0003', 'Subjects Imported', 'Subject import changed: Subject 1 Campus: [empty] -> villamor; Subject 1 Code: [empty] -> AIS101; Subject 1 Department: [empty] -> ics; Subject 1 Name: [empty] -> Introduction to Accounting Information Systems; Subject 10 Campus: [empty] -> villamor; Subject 10 Code: [empty] -> AVC101; Subject 10 Department: [empty] -> ilas; Subject 10 Name: [empty] -> Fundamentals of Aviation Communication; Subject 11 Campus: [empty] -> villamor; Subject 11 Code: [empty] -> ENG102; Subject 11 Department: [empty] -> ilas; Subject 11 Name: [empty] -> Technical Writing and Communication; Subject 12 Campus: [empty] -> villamor; Subject 12 Code: [empty] -> AVC201; Subject 12 Department: [empty] -> ilas; Subject 12 Name: [empty] -> Air Traffic Communication Procedures; Subject 13 Campus: [empty] -> mactan; Subject 13 Code: [empty] -> AET101; Subject 13 Department: [empty] -> inet; Subject 13 Name: [empty] -> Basic Electronics; Subject 14 Campus: [empty] -> mactan; Subject 14 Code: [empty] -> AET102; Subject 14 Department: [empty] -> inet; Subject 14 Name: [empty] -> Aircraft Electrical Systems; Subject 15 Campus: [empty] -> mactan; Subject 15 Code: [empty] -> AET201; Subject 15 Department: [empty] -> inet; Subject 15 Name: [empty] -> Avionics Systems; Subject 16 Campus: [empty] -> mactan; Subject 16 Code: [empty] -> AMT101; Subject 16 Department: [empty] -> inet; Subject 16 Name: [empty] -> Aircraft Structures and Materials; Subject 17 Campus: [empty] -> mactan; Subject 17 Code: [empty] -> AMT102; Subject 17 Department: [empty] -> inet; Subject 17 Name: [empty] -> Aircraft Powerplant Systems; Subject 18 Campus: [empty] -> mactan; Subject 18 Code: [empty] -> AMT201; Subject 18 Department: [empty] -> inet; Subject 18 Name: [empty] -> Aircraft Maintenance Practices; Subject 2 Campus: [empty] -> villamor; Subject 2 Code: [empty] -> ACC102; Subject 2 Department: [empty] -> ics; Subject 2 Name: [empty] -> Financial Accounting and Reporting; (+28 more changes)', 'system', '::1', '2026-07-14 18:55:57'),
(4, 1, 'LOG-0004', 'Course Offerings Imported', 'Course offering import changed: Offering 1 Active: [empty] -> Yes; Offering 1 Campus: [empty] -> mactan; Offering 1 Department: [empty] -> inet; Offering 1 Professor Employee ID: [empty] -> PRF-2026-004; Offering 1 Professor Name: [empty] -> Allen Grace Francisco; Offering 1 Professor User: [empty] -> u12; Offering 1 Program Code: [empty] -> BSAET; Offering 1 Section: [empty] -> 1/1; Offering 1 Semester: [empty] -> 2nd-semester-2025-2026; Offering 1 Students: [empty] -> 12324MN-000009, 12324MN-000025, 12324MN-000026, 12324MN-000041, 12324MN-000057, 12324MN-000058; Offering 1 Subject Code: [empty] -> AET101; Offering 1 Subject ID: [empty] -> 13; Offering 1 Subject Name: [empty] -> Basic Electronics; Offering 10 Active: [empty] -> Yes; Offering 10 Campus: [empty] -> mactan; Offering 10 Department: [empty] -> inet; Offering 10 Professor Employee ID: [empty] -> PRF-2026-004; Offering 10 Professor Name: [empty] -> Allen Grace Francisco; Offering 10 Professor User: [empty] -> u12; Offering 10 Program Code: [empty] -> BSAET; Offering 10 Section: [empty] -> 4/1; Offering 10 Semester: [empty] -> 2nd-semester-2025-2026; Offering 10 Students: [empty] -> 12324MN-000007, 12324MN-000008, 12324MN-000023, 12324MN-000024, 12324MN-000039, 12324MN-000040, 12324MN-000055, 12324MN-000056; Offering 10 Subject Code: [empty] -> AET101; Offering 10 Subject ID: [empty] -> 13; Offering 10 Subject Name: [empty] -> Basic Electronics; Offering 11 Active: [empty] -> Yes; Offering 11 Campus: [empty] -> mactan; Offering 11 Department: [empty] -> inet; Offering 11 Professor Employee ID: [empty] -> PRF-2026-005; Offering 11 Professor Name: [empty] -> Amara Noel Mendoza; Offering 11 Professor User: [empty] -> u13; Offering 11 Program Code: [empty] -> BSAET; Offering 11 Section: [empty] -> 4/1; Offering 11 Semester: [empty] -> 2nd-semester-2025-2026; (+667 more changes)', 'system', '::1', '2026-07-14 18:56:03'),
(5, 1, 'LOG-0005', 'Semester Saved', 'Semester list changed: Semester 1st-semester-2026-2027 Label: [empty] -> 1st Semester 2026-2027', 'system', '::1', '2026-07-14 18:59:27'),
(6, 1, 'LOG-0006', 'Current Semester Updated', 'Current semester changed: Current Semester: 2nd-semester-2025-2026 -> 1st-semester-2026-2027', 'system', '::1', '2026-07-14 18:59:37'),
(7, 1, 'LOG-0007', 'Course Offerings Imported', 'Course offering import changed: Offering 100 Active: [empty] -> Yes; Offering 100 Campus: [empty] -> villamor; Offering 100 Department: [empty] -> ilas; Offering 100 Professor Employee ID: [empty] -> professor; Offering 100 Professor Name: [empty] -> Aira Paz Pascual; Offering 100 Professor User: [empty] -> u9; Offering 100 Program Code: [empty] -> BSAVTour; Offering 100 Section: [empty] -> 4/1; Offering 100 Semester: [empty] -> 1st-semester-2026-2027; Offering 100 Students: [empty] -> 12324MN-000016, 12324MN-000032, 12324MN-000048; Offering 100 Subject Code: [empty] -> AVT101; Offering 100 Subject ID: [empty] -> 7; Offering 100 Subject Name: [empty] -> Introduction to Aviation Tourism; Offering 101 Active: [empty] -> Yes; Offering 101 Campus: [empty] -> villamor; Offering 101 Department: [empty] -> ilas; Offering 101 Professor Employee ID: [empty] -> PRF-2026-016; Offering 101 Professor Name: [empty] -> Cedric J Alonzo; Offering 101 Professor User: [empty] -> u24; Offering 101 Program Code: [empty] -> BSAVTour; Offering 101 Section: [empty] -> 4/1; Offering 101 Semester: [empty] -> 1st-semester-2026-2027; Offering 101 Students: [empty] -> 12324MN-000016, 12324MN-000032, 12324MN-000048; Offering 101 Subject Code: [empty] -> THC102; Offering 101 Subject ID: [empty] -> 8; Offering 101 Subject Name: [empty] -> Tourism and Hospitality Management; Offering 102 Active: [empty] -> Yes; Offering 102 Campus: [empty] -> villamor; Offering 102 Department: [empty] -> ilas; Offering 102 Professor Employee ID: [empty] -> PRF-2026-023; Offering 102 Professor Name: [empty] -> Alexa Troy Abad; Offering 102 Professor User: [empty] -> u31; Offering 102 Program Code: [empty] -> BSAVTour; Offering 102 Section: [empty] -> 4/1; Offering 102 Semester: [empty] -> 1st-semester-2026-2027; Offering 102 Students: [empty] -> 12324MN-000016, 12324MN-000032, 12324MN-000048; Offering 102 Subject Code: [empty] -> AVT201; Offering 102 Subject ID: [empty] -> 9; (+664 more changes)', 'system', '::1', '2026-07-14 19:04:00'),
(8, 11, 'LOG-0008', 'Login', 'Alexa I Cabrera logged in as professor', 'login', '::1', '2026-07-14 19:05:06'),
(9, 1, 'LOG-0009', 'Login', 'Admin logged in as admin', 'login', '::1', '2026-07-14 19:05:33'),
(10, 1, 'LOG-0010', 'Evaluation Periods Updated', 'Evaluation periods changed: Evaluation Period professor-professor End: 2026-02-18 -> 2026-07-14; Evaluation Period student-professor End: 2026-02-15 -> 2026-07-14; Evaluation Period supervisor-professor End: 2026-02-20 -> 2026-07-14', 'system', '::1', '2026-07-14 19:07:53');

-- --------------------------------------------------------

--
-- Table structure for table `announcements`
--

CREATE TABLE `announcements` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `created_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `title` varchar(200) NOT NULL,
  `message` text NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `announcement_targets`
--

CREATE TABLE `announcement_targets` (
  `announcement_id` bigint(20) UNSIGNED NOT NULL,
  `role_id` smallint(5) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `campuses`
--

CREATE TABLE `campuses` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `slug` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `campuses`
--

INSERT INTO `campuses` (`id`, `slug`, `name`, `created_at`) VALUES
(1, 'villamor', 'Villamor', '2026-07-14 18:54:31'),
(2, 'mactan', 'Mactan', '2026-07-14 18:54:31');

-- --------------------------------------------------------

--
-- Table structure for table `course_offerings`
--

CREATE TABLE `course_offerings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `subject_id` bigint(20) UNSIGNED NOT NULL,
  `semester_id` bigint(20) UNSIGNED NOT NULL,
  `professor_id` bigint(20) UNSIGNED NOT NULL,
  `section_name` varchar(50) NOT NULL DEFAULT '',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `load_type` varchar(20) NOT NULL DEFAULT 'main',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `course_offerings`
--

INSERT INTO `course_offerings` (`id`, `subject_id`, `semester_id`, `professor_id`, `section_name`, `is_active`, `created_at`, `load_type`) VALUES
(1, 13, 1, 12, '1/1', 1, '2026-07-14 18:56:03', 'main'),
(2, 14, 1, 13, '1/1', 1, '2026-07-14 18:56:03', 'main'),
(3, 15, 1, 14, '1/1', 1, '2026-07-14 18:56:03', 'main'),
(4, 13, 1, 12, '2/1', 1, '2026-07-14 18:56:03', 'main'),
(5, 14, 1, 13, '2/1', 1, '2026-07-14 18:56:03', 'main'),
(6, 15, 1, 14, '2/1', 1, '2026-07-14 18:56:03', 'main'),
(7, 13, 1, 12, '3/1', 1, '2026-07-14 18:56:03', 'main'),
(8, 14, 1, 13, '3/1', 1, '2026-07-14 18:56:03', 'main'),
(9, 15, 1, 14, '3/1', 1, '2026-07-14 18:56:03', 'main'),
(10, 13, 1, 12, '4/1', 1, '2026-07-14 18:56:03', 'main'),
(11, 14, 1, 13, '4/1', 1, '2026-07-14 18:56:03', 'main'),
(12, 15, 1, 14, '4/1', 1, '2026-07-14 18:56:03', 'main'),
(13, 1, 1, 10, '1/1', 1, '2026-07-14 18:56:03', 'main'),
(14, 2, 1, 23, '1/1', 1, '2026-07-14 18:56:03', 'main'),
(15, 3, 1, 30, '1/1', 1, '2026-07-14 18:56:03', 'main'),
(16, 1, 1, 10, '4/1', 1, '2026-07-14 18:56:03', 'main'),
(17, 2, 1, 23, '4/1', 1, '2026-07-14 18:56:03', 'main'),
(18, 3, 1, 30, '4/1', 1, '2026-07-14 18:56:03', 'main'),
(19, 13, 1, 26, '1/1', 1, '2026-07-14 18:56:03', 'main'),
(20, 14, 1, 33, '1/1', 1, '2026-07-14 18:56:03', 'main'),
(21, 15, 1, 26, '1/1', 1, '2026-07-14 18:56:03', 'main'),
(22, 13, 1, 26, '2/1', 1, '2026-07-14 18:56:03', 'main'),
(23, 14, 1, 33, '2/1', 1, '2026-07-14 18:56:03', 'main'),
(24, 15, 1, 26, '2/1', 1, '2026-07-14 18:56:03', 'main'),
(25, 13, 1, 26, '3/1', 1, '2026-07-14 18:56:03', 'main'),
(26, 14, 1, 33, '3/1', 1, '2026-07-14 18:56:03', 'main'),
(27, 15, 1, 26, '3/1', 1, '2026-07-14 18:56:03', 'main'),
(28, 7, 1, 11, '1/1', 1, '2026-07-14 18:56:03', 'main'),
(29, 8, 1, 11, '1/1', 1, '2026-07-14 18:56:03', 'main'),
(30, 9, 1, 11, '1/1', 1, '2026-07-14 18:56:03', 'main'),
(31, 7, 1, 11, '2/1', 1, '2026-07-14 18:56:03', 'main'),
(32, 8, 1, 11, '2/1', 1, '2026-07-14 18:56:03', 'main'),
(33, 9, 1, 11, '2/1', 1, '2026-07-14 18:56:03', 'main'),
(34, 7, 1, 11, '3/1', 1, '2026-07-14 18:56:03', 'main'),
(35, 8, 1, 11, '3/1', 1, '2026-07-14 18:56:03', 'main'),
(36, 9, 1, 11, '3/1', 1, '2026-07-14 18:56:03', 'main'),
(37, 7, 1, 11, '4/1', 1, '2026-07-14 18:56:03', 'main'),
(38, 8, 1, 11, '4/1', 1, '2026-07-14 18:56:03', 'main'),
(39, 9, 1, 11, '4/1', 1, '2026-07-14 18:56:03', 'main'),
(40, 7, 1, 9, '1/1', 1, '2026-07-14 18:56:03', 'main'),
(41, 8, 1, 24, '1/1', 1, '2026-07-14 18:56:03', 'main'),
(42, 9, 1, 31, '1/1', 1, '2026-07-14 18:56:03', 'main'),
(43, 7, 1, 9, '2/1', 1, '2026-07-14 18:56:03', 'main'),
(44, 8, 1, 24, '2/1', 1, '2026-07-14 18:56:03', 'main'),
(45, 9, 1, 31, '2/1', 1, '2026-07-14 18:56:03', 'main'),
(46, 7, 1, 9, '4/1', 1, '2026-07-14 18:56:03', 'main'),
(47, 8, 1, 24, '4/1', 1, '2026-07-14 18:56:03', 'main'),
(48, 9, 1, 31, '4/1', 1, '2026-07-14 18:56:03', 'main'),
(49, 1, 1, 22, '1/1', 1, '2026-07-14 18:56:03', 'main'),
(50, 2, 1, 25, '1/1', 1, '2026-07-14 18:56:03', 'main'),
(51, 3, 1, 22, '1/1', 1, '2026-07-14 18:56:03', 'main'),
(52, 1, 1, 22, '3/1', 1, '2026-07-14 18:56:03', 'main'),
(53, 2, 1, 25, '3/1', 1, '2026-07-14 18:56:03', 'main'),
(54, 3, 1, 22, '3/1', 1, '2026-07-14 18:56:03', 'main'),
(55, 13, 2, 12, '1/1', 1, '2026-07-14 19:04:00', 'main'),
(56, 14, 2, 13, '1/1', 1, '2026-07-14 19:04:00', 'main'),
(57, 15, 2, 14, '1/1', 1, '2026-07-14 19:04:00', 'main'),
(58, 13, 2, 12, '2/1', 1, '2026-07-14 19:04:00', 'main'),
(59, 14, 2, 13, '2/1', 1, '2026-07-14 19:04:00', 'main'),
(60, 15, 2, 14, '2/1', 1, '2026-07-14 19:04:00', 'main'),
(61, 13, 2, 12, '3/1', 1, '2026-07-14 19:04:00', 'main'),
(62, 14, 2, 13, '3/1', 1, '2026-07-14 19:04:00', 'main'),
(63, 15, 2, 14, '3/1', 1, '2026-07-14 19:04:00', 'main'),
(64, 13, 2, 12, '4/1', 1, '2026-07-14 19:04:00', 'main'),
(65, 14, 2, 13, '4/1', 1, '2026-07-14 19:04:00', 'main'),
(66, 15, 2, 14, '4/1', 1, '2026-07-14 19:04:00', 'main'),
(67, 1, 2, 10, '1/1', 1, '2026-07-14 19:04:00', 'main'),
(68, 2, 2, 23, '1/1', 1, '2026-07-14 19:04:00', 'main'),
(69, 3, 2, 30, '1/1', 1, '2026-07-14 19:04:00', 'main'),
(70, 1, 2, 10, '4/1', 1, '2026-07-14 19:04:00', 'main'),
(71, 2, 2, 23, '4/1', 1, '2026-07-14 19:04:00', 'main'),
(72, 3, 2, 30, '4/1', 1, '2026-07-14 19:04:00', 'main'),
(73, 13, 2, 26, '1/1', 1, '2026-07-14 19:04:00', 'main'),
(74, 14, 2, 33, '1/1', 1, '2026-07-14 19:04:00', 'main'),
(75, 15, 2, 26, '1/1', 1, '2026-07-14 19:04:00', 'main'),
(76, 13, 2, 26, '2/1', 1, '2026-07-14 19:04:00', 'main'),
(77, 14, 2, 33, '2/1', 1, '2026-07-14 19:04:00', 'main'),
(78, 15, 2, 26, '2/1', 1, '2026-07-14 19:04:00', 'main'),
(79, 13, 2, 26, '3/1', 1, '2026-07-14 19:04:00', 'main'),
(80, 14, 2, 33, '3/1', 1, '2026-07-14 19:04:00', 'main'),
(81, 15, 2, 26, '3/1', 1, '2026-07-14 19:04:00', 'main'),
(82, 7, 2, 11, '1/1', 1, '2026-07-14 19:04:00', 'main'),
(83, 8, 2, 11, '1/1', 1, '2026-07-14 19:04:00', 'main'),
(84, 9, 2, 11, '1/1', 1, '2026-07-14 19:04:00', 'main'),
(85, 7, 2, 11, '2/1', 1, '2026-07-14 19:04:00', 'main'),
(86, 8, 2, 11, '2/1', 1, '2026-07-14 19:04:00', 'main'),
(87, 9, 2, 11, '2/1', 1, '2026-07-14 19:04:00', 'main'),
(88, 7, 2, 11, '3/1', 1, '2026-07-14 19:04:00', 'main'),
(89, 8, 2, 11, '3/1', 1, '2026-07-14 19:04:00', 'main'),
(90, 9, 2, 11, '3/1', 1, '2026-07-14 19:04:00', 'main'),
(91, 7, 2, 11, '4/1', 1, '2026-07-14 19:04:00', 'main'),
(92, 8, 2, 11, '4/1', 1, '2026-07-14 19:04:00', 'main'),
(93, 9, 2, 11, '4/1', 1, '2026-07-14 19:04:00', 'main'),
(94, 7, 2, 9, '1/1', 1, '2026-07-14 19:04:00', 'main'),
(95, 8, 2, 24, '1/1', 1, '2026-07-14 19:04:00', 'main'),
(96, 9, 2, 31, '1/1', 1, '2026-07-14 19:04:00', 'main'),
(97, 7, 2, 9, '2/1', 1, '2026-07-14 19:04:00', 'main'),
(98, 8, 2, 24, '2/1', 1, '2026-07-14 19:04:00', 'main'),
(99, 9, 2, 31, '2/1', 1, '2026-07-14 19:04:00', 'main'),
(100, 7, 2, 9, '4/1', 1, '2026-07-14 19:04:00', 'main'),
(101, 8, 2, 24, '4/1', 1, '2026-07-14 19:04:00', 'main'),
(102, 9, 2, 31, '4/1', 1, '2026-07-14 19:04:00', 'main'),
(103, 1, 2, 22, '1/1', 1, '2026-07-14 19:04:00', 'main'),
(104, 2, 2, 25, '1/1', 1, '2026-07-14 19:04:00', 'main'),
(105, 3, 2, 22, '1/1', 1, '2026-07-14 19:04:00', 'main'),
(106, 1, 2, 22, '3/1', 1, '2026-07-14 19:04:00', 'main'),
(107, 2, 2, 25, '3/1', 1, '2026-07-14 19:04:00', 'main'),
(108, 3, 2, 22, '3/1', 1, '2026-07-14 19:04:00', 'main');

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `campus_id` bigint(20) UNSIGNED NOT NULL,
  `code` varchar(30) NOT NULL,
  `name` varchar(100) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`id`, `campus_id`, `code`, `name`, `created_at`) VALUES
(1, 2, 'inet', 'inet', '2026-07-14 18:54:31'),
(2, 1, 'ilas', 'ilas', '2026-07-14 18:54:31'),
(3, 1, 'ics', 'ics', '2026-07-14 18:54:31');

-- --------------------------------------------------------

--
-- Table structure for table `employment_types`
--

CREATE TABLE `employment_types` (
  `id` smallint(5) UNSIGNED NOT NULL,
  `code` varchar(30) NOT NULL,
  `label` varchar(80) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `employment_types`
--

INSERT INTO `employment_types` (`id`, `code`, `label`) VALUES
(1, 'regular', 'Regular'),
(2, 'temporary', 'Temporary');

-- --------------------------------------------------------

--
-- Table structure for table `evaluations`
--

CREATE TABLE `evaluations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `semester_id` bigint(20) UNSIGNED NOT NULL,
  `questionnaire_id` bigint(20) UNSIGNED DEFAULT NULL,
  `evaluation_type_id` smallint(5) UNSIGNED NOT NULL,
  `evaluator_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `evaluatee_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `course_offering_id` bigint(20) UNSIGNED DEFAULT NULL,
  `general_comments` text DEFAULT NULL,
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `status` enum('submitted','draft','archived') NOT NULL DEFAULT 'submitted'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `evaluation_periods`
--

CREATE TABLE `evaluation_periods` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `semester_id` bigint(20) UNSIGNED NOT NULL,
  `evaluation_type_id` smallint(5) UNSIGNED NOT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `evaluation_periods`
--

INSERT INTO `evaluation_periods` (`id`, `semester_id`, `evaluation_type_id`, `start_date`, `end_date`, `created_at`, `updated_at`) VALUES
(1, 1, 2, '2026-02-08', '2026-02-18', '2026-07-14 18:54:31', '2026-07-14 18:54:31'),
(2, 1, 1, '2026-02-01', '2026-02-15', '2026-07-14 18:54:31', '2026-07-14 18:54:31'),
(3, 1, 3, '2026-02-10', '2026-02-20', '2026-07-14 18:54:31', '2026-07-14 18:54:31'),
(4, 2, 2, '2026-02-08', '2026-07-14', '2026-07-14 19:07:53', '2026-07-14 19:07:53'),
(5, 2, 1, '2026-02-01', '2026-07-14', '2026-07-14 19:07:53', '2026-07-14 19:07:53'),
(6, 2, 3, '2026-02-10', '2026-07-14', '2026-07-14 19:07:53', '2026-07-14 19:07:53');

-- --------------------------------------------------------

--
-- Table structure for table `evaluation_responses`
--

CREATE TABLE `evaluation_responses` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `evaluation_id` bigint(20) UNSIGNED NOT NULL,
  `question_id` bigint(20) UNSIGNED NOT NULL,
  `rating_value` decimal(5,2) DEFAULT NULL,
  `text_value` text DEFAULT NULL,
  `display_order` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `evaluation_types`
--

CREATE TABLE `evaluation_types` (
  `id` smallint(5) UNSIGNED NOT NULL,
  `code` varchar(40) NOT NULL,
  `label` varchar(120) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `evaluation_types`
--

INSERT INTO `evaluation_types` (`id`, `code`, `label`) VALUES
(1, 'student-professor', 'Student to Professor'),
(2, 'professor-professor', 'Professor to Professor'),
(3, 'supervisor-professor', 'Supervisor to Professor');

-- --------------------------------------------------------

--
-- Table structure for table `peer_evaluation_assignments`
--

CREATE TABLE `peer_evaluation_assignments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `semester_id` bigint(20) UNSIGNED NOT NULL,
  `room_id` bigint(20) UNSIGNED NOT NULL,
  `evaluator_user_id` bigint(20) UNSIGNED NOT NULL,
  `evaluatee_user_id` bigint(20) UNSIGNED NOT NULL,
  `status` enum('pending','submitted') NOT NULL DEFAULT 'pending',
  `submitted_evaluation_id` varchar(120) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `peer_evaluation_rooms`
--

CREATE TABLE `peer_evaluation_rooms` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `semester_id` bigint(20) UNSIGNED NOT NULL,
  `dean_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `program_id` bigint(20) UNSIGNED DEFAULT NULL,
  `requested_peer_count` int(10) UNSIGNED NOT NULL DEFAULT 5,
  `room_name` varchar(150) NOT NULL,
  `coordinator_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `peer_evaluation_room_members`
--

CREATE TABLE `peer_evaluation_room_members` (
  `room_id` bigint(20) UNSIGNED NOT NULL,
  `professor_user_id` bigint(20) UNSIGNED NOT NULL,
  `assigned_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `profile_photos`
--

CREATE TABLE `profile_photos` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `photo_data` longblob NOT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `programs`
--

CREATE TABLE `programs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `department_id` bigint(20) UNSIGNED NOT NULL,
  `code` varchar(30) NOT NULL,
  `name` varchar(120) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `programs`
--

INSERT INTO `programs` (`id`, `department_id`, `code`, `name`, `created_at`) VALUES
(1, 1, 'BSAMT', 'Bachelor of Science in Aviation Maintenance Technology', '2026-07-14 18:54:31'),
(2, 1, 'BSAET', 'Bachelor of Science in Aviation Electronics Technology', '2026-07-14 18:54:31'),
(3, 3, 'BSIT', 'Bachelor of Science in Information Technology', '2026-07-14 18:54:31'),
(4, 3, 'BSAIS', 'Bachelor of Science in Accounting Information Systems', '2026-07-14 18:54:31'),
(5, 2, 'BSAVComm', 'Bachelor of Science in Aviation Communication', '2026-07-14 18:54:31'),
(6, 2, 'BSAVTour', 'Bachelor of Science in Aviation Tourism', '2026-07-14 18:54:31');

-- --------------------------------------------------------

--
-- Table structure for table `questionnaires`
--

CREATE TABLE `questionnaires` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `semester_id` bigint(20) UNSIGNED NOT NULL,
  `evaluation_type_id` smallint(5) UNSIGNED NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('draft','published','archived') NOT NULL DEFAULT 'published',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `questionnaires`
--

INSERT INTO `questionnaires` (`id`, `semester_id`, `evaluation_type_id`, `title`, `description`, `status`, `created_at`, `updated_at`) VALUES
(1, 1, 2, 'Peer Evaluation Form', 'Questionnaire for professors evaluating fellow professors.', 'published', '2026-07-14 18:54:31', '2026-07-14 18:54:31'),
(2, 1, 1, 'Student Evaluation Form', 'Questionnaire for students evaluating professors.', 'published', '2026-07-14 18:54:31', '2026-07-14 18:54:31'),
(3, 1, 3, 'Supervisor Evaluation Form', 'Questionnaire for academic supervisors evaluating professors.', 'published', '2026-07-14 18:54:31', '2026-07-14 18:54:31');

-- --------------------------------------------------------

--
-- Table structure for table `questionnaire_sections`
--

CREATE TABLE `questionnaire_sections` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `questionnaire_id` bigint(20) UNSIGNED NOT NULL,
  `section_code` varchar(10) NOT NULL DEFAULT '',
  `title` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `questionnaire_sections`
--

INSERT INTO `questionnaire_sections` (`id`, `questionnaire_id`, `section_code`, `title`, `description`, `sort_order`, `created_at`) VALUES
(1, 1, 'B', 'Professional Contribution', 'Professional conduct and support.', 2, '2026-07-14 18:54:31'),
(2, 1, 'A', 'Collegiality', 'Collaboration and teamwork.', 1, '2026-07-14 18:54:31'),
(3, 2, 'B', 'Classroom Environment', 'Respect and learning climate.', 2, '2026-07-14 18:54:31'),
(4, 2, 'A', 'Teaching Effectiveness', 'Instructional delivery and preparation.', 1, '2026-07-14 18:54:31'),
(5, 3, 'B', 'Compliance and Development', 'Reports, initiatives, and growth.', 2, '2026-07-14 18:54:31'),
(6, 3, 'A', 'Instructional Leadership', 'Planning and academic alignment.', 1, '2026-07-14 18:54:31');

-- --------------------------------------------------------

--
-- Table structure for table `questions`
--

CREATE TABLE `questions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `questionnaire_id` bigint(20) UNSIGNED NOT NULL,
  `section_id` bigint(20) UNSIGNED DEFAULT NULL,
  `question_type_id` smallint(5) UNSIGNED NOT NULL,
  `question_text` text NOT NULL,
  `rating_max` tinyint(3) UNSIGNED NOT NULL DEFAULT 5,
  `max_length` smallint(5) UNSIGNED NOT NULL DEFAULT 500,
  `is_required` tinyint(1) NOT NULL DEFAULT 0,
  `is_exception_reporting` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `questions`
--

INSERT INTO `questions` (`id`, `questionnaire_id`, `section_id`, `question_type_id`, `question_text`, `rating_max`, `max_length`, `is_required`, `is_exception_reporting`, `sort_order`, `created_at`) VALUES
(1, 3, 5, 2, 'What development area should be prioritized?', 5, 500, 0, 0, 4, '2026-07-14 18:54:31'),
(2, 1, 1, 2, 'What peer contribution stands out the most?', 5, 500, 0, 0, 4, '2026-07-14 18:54:31'),
(3, 2, 3, 2, 'What should the professor continue doing?', 5, 500, 0, 0, 4, '2026-07-14 18:54:31'),
(4, 3, 5, 1, 'This professor shows initiative in academic improvement.', 5, 500, 1, 0, 3, '2026-07-14 18:54:31'),
(5, 3, 6, 1, 'This professor aligns instruction with program outcomes.', 5, 500, 1, 0, 2, '2026-07-14 18:54:31'),
(6, 3, 6, 1, 'This professor submits required reports on time.', 5, 500, 1, 0, 1, '2026-07-14 18:54:31'),
(7, 1, 1, 1, 'This professor demonstrates professionalism in the department.', 5, 500, 1, 0, 3, '2026-07-14 18:54:31'),
(8, 1, 2, 1, 'This professor shares resources and best practices.', 5, 500, 1, 0, 2, '2026-07-14 18:54:31'),
(9, 1, 2, 1, 'This professor collaborates well with colleagues.', 5, 500, 1, 0, 1, '2026-07-14 18:54:31'),
(10, 2, 3, 1, 'The professor treats students with respect.', 5, 500, 1, 0, 3, '2026-07-14 18:54:31'),
(11, 2, 4, 1, 'The professor comes to class prepared.', 5, 500, 1, 0, 2, '2026-07-14 18:54:31'),
(12, 2, 4, 1, 'The professor explains concepts clearly.', 5, 500, 1, 0, 1, '2026-07-14 18:54:31');

-- --------------------------------------------------------

--
-- Table structure for table `question_types`
--

CREATE TABLE `question_types` (
  `id` smallint(5) UNSIGNED NOT NULL,
  `code` varchar(30) NOT NULL,
  `label` varchar(80) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `question_types`
--

INSERT INTO `question_types` (`id`, `code`, `label`) VALUES
(1, 'rating', 'Rating'),
(2, 'qualitative', 'Qualitative');

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` smallint(5) UNSIGNED NOT NULL,
  `code` varchar(30) NOT NULL,
  `label` varchar(80) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `code`, `label`) VALUES
(1, 'admin', 'Administrator'),
(2, 'hr', 'Human Resources'),
(3, 'osa', 'Office of Student Affairs'),
(4, 'vpaa', 'Vice President for Academic Affairs'),
(5, 'dean', 'Dean'),
(6, 'professor', 'Professor'),
(7, 'student', 'Student'),
(8, 'procoor', 'Program Coordinator');

-- --------------------------------------------------------

--
-- Table structure for table `semesters`
--

CREATE TABLE `semesters` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `slug` varchar(100) NOT NULL,
  `label` varchar(150) NOT NULL,
  `academic_year` varchar(20) NOT NULL,
  `is_current` tinyint(1) NOT NULL DEFAULT 0,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `semesters`
--

INSERT INTO `semesters` (`id`, `slug`, `label`, `academic_year`, `is_current`, `start_date`, `end_date`, `created_at`, `updated_at`) VALUES
(1, '2nd-semester-2025-2026', '2nd Semester 2025-2026', '2025-2026', 0, '2025-11-03', '2026-03-28', '2026-07-14 18:54:31', '2026-07-14 18:59:37'),
(2, '1st-semester-2026-2027', '1st Semester 2026-2027', '2026-2027', 1, NULL, NULL, '2026-07-14 18:59:27', '2026-07-14 18:59:37');

-- --------------------------------------------------------

--
-- Table structure for table `staff_profiles`
--

CREATE TABLE `staff_profiles` (
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` varchar(50) NOT NULL,
  `employment_type_id` smallint(5) UNSIGNED DEFAULT NULL,
  `program_id` bigint(20) UNSIGNED DEFAULT NULL,
  `position` varchar(150) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `staff_profiles`
--

INSERT INTO `staff_profiles` (`user_id`, `employee_id`, `employment_type_id`, `program_id`, `position`, `created_at`, `updated_at`) VALUES
(1, 'admin', 1, NULL, 'System Administrator', '2026-07-14 18:54:31', '2026-07-14 18:54:31'),
(3, 'osa', 1, NULL, 'OSA Officer', '2026-07-14 18:55:23', '2026-07-14 18:55:23'),
(4, 'vpaa', 1, NULL, 'Vice President for Academic Affairs', '2026-07-14 18:55:23', '2026-07-14 18:55:23'),
(5, 'hr', 1, NULL, 'HR Manager', '2026-07-14 18:55:23', '2026-07-14 18:55:23'),
(6, 'dean', 1, NULL, 'Dean', '2026-07-14 18:55:23', '2026-07-14 18:55:23'),
(7, 'DEAN-2026-002', 1, NULL, 'Dean', '2026-07-14 18:55:23', '2026-07-14 18:55:23'),
(8, 'DEAN-2026-003', 1, NULL, 'Dean', '2026-07-14 18:55:23', '2026-07-14 18:55:23'),
(9, 'professor', 1, 6, 'Assistant Professor', '2026-07-14 18:55:23', '2026-07-14 18:55:23'),
(10, 'PRF-2026-002', 1, 4, 'Associate Professor', '2026-07-14 18:55:23', '2026-07-14 18:55:23'),
(11, 'PRF-2026-003', 2, 5, 'Instructor I', '2026-07-14 18:55:23', '2026-07-14 18:55:23'),
(12, 'PRF-2026-004', 1, 2, 'Assistant Professor', '2026-07-14 18:55:23', '2026-07-14 18:55:23'),
(13, 'PRF-2026-005', 1, 2, 'Associate Professor', '2026-07-14 18:55:23', '2026-07-14 18:55:23'),
(14, 'PRF-2026-006', 2, 2, 'Instructor I', '2026-07-14 18:55:23', '2026-07-14 18:55:23'),
(15, 'PRF-2026-007', 1, 2, 'Assistant Professor', '2026-07-14 18:55:23', '2026-07-14 18:55:23'),
(16, 'PRF-2026-008', 1, 2, 'Associate Professor', '2026-07-14 18:55:23', '2026-07-14 18:55:23'),
(17, 'PRF-2026-009', 2, 2, 'Instructor I', '2026-07-14 18:55:23', '2026-07-14 18:55:23'),
(18, 'PRF-2026-010', 1, 2, 'Assistant Professor', '2026-07-14 18:55:24', '2026-07-14 18:55:24'),
(19, 'PRF-2026-011', 1, 2, 'Associate Professor', '2026-07-14 18:55:24', '2026-07-14 18:55:24'),
(20, 'PRF-2026-012', 2, 2, 'Instructor I', '2026-07-14 18:55:24', '2026-07-14 18:55:24'),
(21, 'PRF-2026-013', 1, 2, 'Assistant Professor', '2026-07-14 18:55:24', '2026-07-14 18:55:24'),
(22, 'PRF-2026-014', 1, 3, 'Associate Professor', '2026-07-14 18:55:24', '2026-07-14 18:55:24'),
(23, 'PRF-2026-015', 2, 4, 'Instructor I', '2026-07-14 18:55:24', '2026-07-14 18:55:24'),
(24, 'PRF-2026-016', 1, 6, 'Assistant Professor', '2026-07-14 18:55:24', '2026-07-14 18:55:24'),
(25, 'PRF-2026-017', 1, 3, 'Associate Professor', '2026-07-14 18:55:24', '2026-07-14 18:55:24'),
(26, 'PRF-2026-018', 2, 1, 'Instructor I', '2026-07-14 18:55:24', '2026-07-14 18:55:24'),
(27, 'PRF-2026-019', 1, 2, 'Assistant Professor', '2026-07-14 18:55:24', '2026-07-14 18:55:24'),
(28, 'PRF-2026-020', 1, 2, 'Associate Professor', '2026-07-14 18:55:24', '2026-07-14 18:55:24'),
(29, 'PRF-2026-021', 2, 2, 'Instructor I', '2026-07-14 18:55:24', '2026-07-14 18:55:24'),
(30, 'PRF-2026-022', 2, 4, 'Associate Professor', '2026-07-14 18:55:24', '2026-07-14 18:55:24'),
(31, 'PRF-2026-023', 1, 6, 'Instructor I', '2026-07-14 18:55:24', '2026-07-14 18:55:24'),
(32, 'PRF-2026-024', 2, 2, 'Instructor II', '2026-07-14 18:55:24', '2026-07-14 18:55:24'),
(33, 'PRF-2026-025', 1, 1, 'Assistant Professor', '2026-07-14 18:55:24', '2026-07-14 18:55:24'),
(34, 'PRF-2026-026', 2, 6, 'Associate Professor', '2026-07-14 18:55:24', '2026-07-14 18:55:24'),
(35, 'PRF-2026-027', 1, 6, 'Instructor I', '2026-07-14 18:55:25', '2026-07-14 18:55:25'),
(36, 'PRF-2026-028', 2, 2, 'Instructor II', '2026-07-14 18:55:25', '2026-07-14 18:55:25'),
(37, 'PRF-2026-029', 1, 2, 'Assistant Professor', '2026-07-14 18:55:25', '2026-07-14 18:55:25'),
(38, 'PRF-2026-030', 2, 2, 'Associate Professor', '2026-07-14 18:55:25', '2026-07-14 18:55:25');

-- --------------------------------------------------------

--
-- Table structure for table `student_course_enrollments`
--

CREATE TABLE `student_course_enrollments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `student_id` bigint(20) UNSIGNED NOT NULL,
  `course_offering_id` bigint(20) UNSIGNED NOT NULL,
  `status` enum('enrolled','dropped','completed') NOT NULL DEFAULT 'enrolled',
  `enrolled_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `student_course_enrollments`
--

INSERT INTO `student_course_enrollments` (`id`, `student_id`, `course_offering_id`, `status`, `enrolled_at`) VALUES
(1, 47, 1, 'enrolled', '2026-07-14 18:56:03'),
(2, 63, 1, 'enrolled', '2026-07-14 18:56:03'),
(3, 64, 1, 'enrolled', '2026-07-14 18:56:03'),
(4, 79, 1, 'enrolled', '2026-07-14 18:56:03'),
(5, 95, 1, 'enrolled', '2026-07-14 18:56:03'),
(6, 96, 1, 'enrolled', '2026-07-14 18:56:03'),
(7, 47, 2, 'enrolled', '2026-07-14 18:56:03'),
(8, 63, 2, 'enrolled', '2026-07-14 18:56:03'),
(9, 64, 2, 'enrolled', '2026-07-14 18:56:03'),
(10, 79, 2, 'enrolled', '2026-07-14 18:56:03'),
(11, 95, 2, 'enrolled', '2026-07-14 18:56:03'),
(12, 96, 2, 'enrolled', '2026-07-14 18:56:03'),
(13, 47, 3, 'enrolled', '2026-07-14 18:56:03'),
(14, 63, 3, 'enrolled', '2026-07-14 18:56:03'),
(15, 64, 3, 'enrolled', '2026-07-14 18:56:03'),
(16, 79, 3, 'enrolled', '2026-07-14 18:56:03'),
(17, 95, 3, 'enrolled', '2026-07-14 18:56:03'),
(18, 96, 3, 'enrolled', '2026-07-14 18:56:03'),
(19, 49, 4, 'enrolled', '2026-07-14 18:56:03'),
(20, 58, 4, 'enrolled', '2026-07-14 18:56:03'),
(21, 66, 4, 'enrolled', '2026-07-14 18:56:03'),
(22, 81, 4, 'enrolled', '2026-07-14 18:56:03'),
(23, 90, 4, 'enrolled', '2026-07-14 18:56:03'),
(24, 98, 4, 'enrolled', '2026-07-14 18:56:03'),
(25, 49, 5, 'enrolled', '2026-07-14 18:56:03'),
(26, 58, 5, 'enrolled', '2026-07-14 18:56:03'),
(27, 66, 5, 'enrolled', '2026-07-14 18:56:03'),
(28, 81, 5, 'enrolled', '2026-07-14 18:56:03'),
(29, 90, 5, 'enrolled', '2026-07-14 18:56:03'),
(30, 98, 5, 'enrolled', '2026-07-14 18:56:03'),
(31, 49, 6, 'enrolled', '2026-07-14 18:56:03'),
(32, 58, 6, 'enrolled', '2026-07-14 18:56:03'),
(33, 66, 6, 'enrolled', '2026-07-14 18:56:03'),
(34, 81, 6, 'enrolled', '2026-07-14 18:56:03'),
(35, 90, 6, 'enrolled', '2026-07-14 18:56:03'),
(36, 98, 6, 'enrolled', '2026-07-14 18:56:03'),
(37, 43, 7, 'enrolled', '2026-07-14 18:56:03'),
(38, 51, 7, 'enrolled', '2026-07-14 18:56:03'),
(39, 60, 7, 'enrolled', '2026-07-14 18:56:03'),
(40, 67, 7, 'enrolled', '2026-07-14 18:56:03'),
(41, 75, 7, 'enrolled', '2026-07-14 18:56:03'),
(42, 83, 7, 'enrolled', '2026-07-14 18:56:03'),
(43, 92, 7, 'enrolled', '2026-07-14 18:56:03'),
(44, 99, 7, 'enrolled', '2026-07-14 18:56:03'),
(45, 43, 8, 'enrolled', '2026-07-14 18:56:03'),
(46, 51, 8, 'enrolled', '2026-07-14 18:56:03'),
(47, 60, 8, 'enrolled', '2026-07-14 18:56:03'),
(48, 67, 8, 'enrolled', '2026-07-14 18:56:03'),
(49, 75, 8, 'enrolled', '2026-07-14 18:56:03'),
(50, 83, 8, 'enrolled', '2026-07-14 18:56:03'),
(51, 92, 8, 'enrolled', '2026-07-14 18:56:03'),
(52, 99, 8, 'enrolled', '2026-07-14 18:56:03'),
(53, 43, 9, 'enrolled', '2026-07-14 18:56:03'),
(54, 51, 9, 'enrolled', '2026-07-14 18:56:03'),
(55, 60, 9, 'enrolled', '2026-07-14 18:56:03'),
(56, 67, 9, 'enrolled', '2026-07-14 18:56:03'),
(57, 75, 9, 'enrolled', '2026-07-14 18:56:03'),
(58, 83, 9, 'enrolled', '2026-07-14 18:56:03'),
(59, 92, 9, 'enrolled', '2026-07-14 18:56:03'),
(60, 99, 9, 'enrolled', '2026-07-14 18:56:03'),
(61, 45, 10, 'enrolled', '2026-07-14 18:56:03'),
(62, 46, 10, 'enrolled', '2026-07-14 18:56:03'),
(63, 61, 10, 'enrolled', '2026-07-14 18:56:03'),
(64, 62, 10, 'enrolled', '2026-07-14 18:56:03'),
(65, 77, 10, 'enrolled', '2026-07-14 18:56:03'),
(66, 78, 10, 'enrolled', '2026-07-14 18:56:03'),
(67, 93, 10, 'enrolled', '2026-07-14 18:56:03'),
(68, 94, 10, 'enrolled', '2026-07-14 18:56:03'),
(69, 45, 11, 'enrolled', '2026-07-14 18:56:03'),
(70, 46, 11, 'enrolled', '2026-07-14 18:56:03'),
(71, 61, 11, 'enrolled', '2026-07-14 18:56:03'),
(72, 62, 11, 'enrolled', '2026-07-14 18:56:03'),
(73, 77, 11, 'enrolled', '2026-07-14 18:56:03'),
(74, 78, 11, 'enrolled', '2026-07-14 18:56:03'),
(75, 93, 11, 'enrolled', '2026-07-14 18:56:03'),
(76, 94, 11, 'enrolled', '2026-07-14 18:56:03'),
(77, 45, 12, 'enrolled', '2026-07-14 18:56:03'),
(78, 46, 12, 'enrolled', '2026-07-14 18:56:03'),
(79, 61, 12, 'enrolled', '2026-07-14 18:56:03'),
(80, 62, 12, 'enrolled', '2026-07-14 18:56:03'),
(81, 77, 12, 'enrolled', '2026-07-14 18:56:03'),
(82, 78, 12, 'enrolled', '2026-07-14 18:56:03'),
(83, 93, 12, 'enrolled', '2026-07-14 18:56:03'),
(84, 94, 12, 'enrolled', '2026-07-14 18:56:03'),
(85, 40, 13, 'enrolled', '2026-07-14 18:56:03'),
(86, 72, 13, 'enrolled', '2026-07-14 18:56:03'),
(87, 40, 14, 'enrolled', '2026-07-14 18:56:03'),
(88, 72, 14, 'enrolled', '2026-07-14 18:56:03'),
(89, 40, 15, 'enrolled', '2026-07-14 18:56:03'),
(90, 72, 15, 'enrolled', '2026-07-14 18:56:03'),
(91, 53, 16, 'enrolled', '2026-07-14 18:56:03'),
(92, 69, 16, 'enrolled', '2026-07-14 18:56:03'),
(93, 85, 16, 'enrolled', '2026-07-14 18:56:03'),
(94, 101, 16, 'enrolled', '2026-07-14 18:56:03'),
(95, 53, 17, 'enrolled', '2026-07-14 18:56:03'),
(96, 69, 17, 'enrolled', '2026-07-14 18:56:03'),
(97, 85, 17, 'enrolled', '2026-07-14 18:56:03'),
(98, 101, 17, 'enrolled', '2026-07-14 18:56:03'),
(99, 53, 18, 'enrolled', '2026-07-14 18:56:03'),
(100, 69, 18, 'enrolled', '2026-07-14 18:56:03'),
(101, 85, 18, 'enrolled', '2026-07-14 18:56:03'),
(102, 101, 18, 'enrolled', '2026-07-14 18:56:03'),
(103, 48, 19, 'enrolled', '2026-07-14 18:56:03'),
(104, 80, 19, 'enrolled', '2026-07-14 18:56:03'),
(105, 48, 20, 'enrolled', '2026-07-14 18:56:03'),
(106, 80, 20, 'enrolled', '2026-07-14 18:56:03'),
(107, 48, 21, 'enrolled', '2026-07-14 18:56:03'),
(108, 80, 21, 'enrolled', '2026-07-14 18:56:03'),
(109, 42, 22, 'enrolled', '2026-07-14 18:56:03'),
(110, 50, 22, 'enrolled', '2026-07-14 18:56:03'),
(111, 65, 22, 'enrolled', '2026-07-14 18:56:03'),
(112, 74, 22, 'enrolled', '2026-07-14 18:56:03'),
(113, 82, 22, 'enrolled', '2026-07-14 18:56:03'),
(114, 97, 22, 'enrolled', '2026-07-14 18:56:03'),
(115, 42, 23, 'enrolled', '2026-07-14 18:56:03'),
(116, 50, 23, 'enrolled', '2026-07-14 18:56:03'),
(117, 65, 23, 'enrolled', '2026-07-14 18:56:03'),
(118, 74, 23, 'enrolled', '2026-07-14 18:56:03'),
(119, 82, 23, 'enrolled', '2026-07-14 18:56:03'),
(120, 97, 23, 'enrolled', '2026-07-14 18:56:03'),
(121, 42, 24, 'enrolled', '2026-07-14 18:56:03'),
(122, 50, 24, 'enrolled', '2026-07-14 18:56:03'),
(123, 65, 24, 'enrolled', '2026-07-14 18:56:03'),
(124, 74, 24, 'enrolled', '2026-07-14 18:56:03'),
(125, 82, 24, 'enrolled', '2026-07-14 18:56:03'),
(126, 97, 24, 'enrolled', '2026-07-14 18:56:03'),
(127, 44, 25, 'enrolled', '2026-07-14 18:56:03'),
(128, 59, 25, 'enrolled', '2026-07-14 18:56:03'),
(129, 76, 25, 'enrolled', '2026-07-14 18:56:03'),
(130, 91, 25, 'enrolled', '2026-07-14 18:56:03'),
(131, 44, 26, 'enrolled', '2026-07-14 18:56:03'),
(132, 59, 26, 'enrolled', '2026-07-14 18:56:03'),
(133, 76, 26, 'enrolled', '2026-07-14 18:56:03'),
(134, 91, 26, 'enrolled', '2026-07-14 18:56:03'),
(135, 44, 27, 'enrolled', '2026-07-14 18:56:03'),
(136, 59, 27, 'enrolled', '2026-07-14 18:56:03'),
(137, 76, 27, 'enrolled', '2026-07-14 18:56:03'),
(138, 91, 27, 'enrolled', '2026-07-14 18:56:03'),
(139, 39, 28, 'enrolled', '2026-07-14 18:56:03'),
(140, 71, 28, 'enrolled', '2026-07-14 18:56:03'),
(141, 102, 28, 'enrolled', '2026-07-14 18:56:03'),
(142, 106, 28, 'enrolled', '2026-07-14 18:56:03'),
(143, 110, 28, 'enrolled', '2026-07-14 18:56:03'),
(144, 114, 28, 'enrolled', '2026-07-14 18:56:03'),
(145, 118, 28, 'enrolled', '2026-07-14 18:56:03'),
(146, 122, 28, 'enrolled', '2026-07-14 18:56:03'),
(147, 126, 28, 'enrolled', '2026-07-14 18:56:03'),
(148, 130, 28, 'enrolled', '2026-07-14 18:56:03'),
(149, 134, 28, 'enrolled', '2026-07-14 18:56:03'),
(150, 138, 28, 'enrolled', '2026-07-14 18:56:03'),
(151, 142, 28, 'enrolled', '2026-07-14 18:56:03'),
(152, 146, 28, 'enrolled', '2026-07-14 18:56:03'),
(153, 150, 28, 'enrolled', '2026-07-14 18:56:03'),
(154, 39, 29, 'enrolled', '2026-07-14 18:56:03'),
(155, 71, 29, 'enrolled', '2026-07-14 18:56:03'),
(156, 102, 29, 'enrolled', '2026-07-14 18:56:03'),
(157, 106, 29, 'enrolled', '2026-07-14 18:56:03'),
(158, 110, 29, 'enrolled', '2026-07-14 18:56:03'),
(159, 114, 29, 'enrolled', '2026-07-14 18:56:03'),
(160, 118, 29, 'enrolled', '2026-07-14 18:56:03'),
(161, 122, 29, 'enrolled', '2026-07-14 18:56:03'),
(162, 126, 29, 'enrolled', '2026-07-14 18:56:03'),
(163, 130, 29, 'enrolled', '2026-07-14 18:56:03'),
(164, 134, 29, 'enrolled', '2026-07-14 18:56:03'),
(165, 138, 29, 'enrolled', '2026-07-14 18:56:03'),
(166, 142, 29, 'enrolled', '2026-07-14 18:56:03'),
(167, 146, 29, 'enrolled', '2026-07-14 18:56:03'),
(168, 150, 29, 'enrolled', '2026-07-14 18:56:03'),
(169, 39, 30, 'enrolled', '2026-07-14 18:56:03'),
(170, 71, 30, 'enrolled', '2026-07-14 18:56:03'),
(171, 102, 30, 'enrolled', '2026-07-14 18:56:03'),
(172, 106, 30, 'enrolled', '2026-07-14 18:56:03'),
(173, 110, 30, 'enrolled', '2026-07-14 18:56:03'),
(174, 114, 30, 'enrolled', '2026-07-14 18:56:03'),
(175, 118, 30, 'enrolled', '2026-07-14 18:56:03'),
(176, 122, 30, 'enrolled', '2026-07-14 18:56:03'),
(177, 126, 30, 'enrolled', '2026-07-14 18:56:03'),
(178, 130, 30, 'enrolled', '2026-07-14 18:56:03'),
(179, 134, 30, 'enrolled', '2026-07-14 18:56:03'),
(180, 138, 30, 'enrolled', '2026-07-14 18:56:03'),
(181, 142, 30, 'enrolled', '2026-07-14 18:56:03'),
(182, 146, 30, 'enrolled', '2026-07-14 18:56:03'),
(183, 150, 30, 'enrolled', '2026-07-14 18:56:03'),
(184, 57, 31, 'enrolled', '2026-07-14 18:56:03'),
(185, 89, 31, 'enrolled', '2026-07-14 18:56:03'),
(186, 103, 31, 'enrolled', '2026-07-14 18:56:03'),
(187, 107, 31, 'enrolled', '2026-07-14 18:56:03'),
(188, 111, 31, 'enrolled', '2026-07-14 18:56:03'),
(189, 115, 31, 'enrolled', '2026-07-14 18:56:03'),
(190, 119, 31, 'enrolled', '2026-07-14 18:56:03'),
(191, 123, 31, 'enrolled', '2026-07-14 18:56:03'),
(192, 127, 31, 'enrolled', '2026-07-14 18:56:03'),
(193, 131, 31, 'enrolled', '2026-07-14 18:56:03'),
(194, 135, 31, 'enrolled', '2026-07-14 18:56:03'),
(195, 139, 31, 'enrolled', '2026-07-14 18:56:03'),
(196, 143, 31, 'enrolled', '2026-07-14 18:56:03'),
(197, 147, 31, 'enrolled', '2026-07-14 18:56:03'),
(198, 151, 31, 'enrolled', '2026-07-14 18:56:03'),
(199, 57, 32, 'enrolled', '2026-07-14 18:56:03'),
(200, 89, 32, 'enrolled', '2026-07-14 18:56:03'),
(201, 103, 32, 'enrolled', '2026-07-14 18:56:03'),
(202, 107, 32, 'enrolled', '2026-07-14 18:56:03'),
(203, 111, 32, 'enrolled', '2026-07-14 18:56:03'),
(204, 115, 32, 'enrolled', '2026-07-14 18:56:03'),
(205, 119, 32, 'enrolled', '2026-07-14 18:56:03'),
(206, 123, 32, 'enrolled', '2026-07-14 18:56:03'),
(207, 127, 32, 'enrolled', '2026-07-14 18:56:03'),
(208, 131, 32, 'enrolled', '2026-07-14 18:56:03'),
(209, 135, 32, 'enrolled', '2026-07-14 18:56:03'),
(210, 139, 32, 'enrolled', '2026-07-14 18:56:03'),
(211, 143, 32, 'enrolled', '2026-07-14 18:56:03'),
(212, 147, 32, 'enrolled', '2026-07-14 18:56:03'),
(213, 151, 32, 'enrolled', '2026-07-14 18:56:03'),
(214, 57, 33, 'enrolled', '2026-07-14 18:56:03'),
(215, 89, 33, 'enrolled', '2026-07-14 18:56:03'),
(216, 103, 33, 'enrolled', '2026-07-14 18:56:03'),
(217, 107, 33, 'enrolled', '2026-07-14 18:56:03'),
(218, 111, 33, 'enrolled', '2026-07-14 18:56:03'),
(219, 115, 33, 'enrolled', '2026-07-14 18:56:03'),
(220, 119, 33, 'enrolled', '2026-07-14 18:56:03'),
(221, 123, 33, 'enrolled', '2026-07-14 18:56:03'),
(222, 127, 33, 'enrolled', '2026-07-14 18:56:03'),
(223, 131, 33, 'enrolled', '2026-07-14 18:56:03'),
(224, 135, 33, 'enrolled', '2026-07-14 18:56:03'),
(225, 139, 33, 'enrolled', '2026-07-14 18:56:03'),
(226, 143, 33, 'enrolled', '2026-07-14 18:56:03'),
(227, 147, 33, 'enrolled', '2026-07-14 18:56:03'),
(228, 151, 33, 'enrolled', '2026-07-14 18:56:03'),
(229, 104, 34, 'enrolled', '2026-07-14 18:56:03'),
(230, 108, 34, 'enrolled', '2026-07-14 18:56:03'),
(231, 112, 34, 'enrolled', '2026-07-14 18:56:03'),
(232, 116, 34, 'enrolled', '2026-07-14 18:56:03'),
(233, 120, 34, 'enrolled', '2026-07-14 18:56:03'),
(234, 124, 34, 'enrolled', '2026-07-14 18:56:03'),
(235, 128, 34, 'enrolled', '2026-07-14 18:56:03'),
(236, 132, 34, 'enrolled', '2026-07-14 18:56:03'),
(237, 136, 34, 'enrolled', '2026-07-14 18:56:03'),
(238, 140, 34, 'enrolled', '2026-07-14 18:56:03'),
(239, 144, 34, 'enrolled', '2026-07-14 18:56:03'),
(240, 148, 34, 'enrolled', '2026-07-14 18:56:03'),
(241, 104, 35, 'enrolled', '2026-07-14 18:56:03'),
(242, 108, 35, 'enrolled', '2026-07-14 18:56:03'),
(243, 112, 35, 'enrolled', '2026-07-14 18:56:03'),
(244, 116, 35, 'enrolled', '2026-07-14 18:56:03'),
(245, 120, 35, 'enrolled', '2026-07-14 18:56:03'),
(246, 124, 35, 'enrolled', '2026-07-14 18:56:03'),
(247, 128, 35, 'enrolled', '2026-07-14 18:56:03'),
(248, 132, 35, 'enrolled', '2026-07-14 18:56:03'),
(249, 136, 35, 'enrolled', '2026-07-14 18:56:03'),
(250, 140, 35, 'enrolled', '2026-07-14 18:56:03'),
(251, 144, 35, 'enrolled', '2026-07-14 18:56:03'),
(252, 148, 35, 'enrolled', '2026-07-14 18:56:03'),
(253, 104, 36, 'enrolled', '2026-07-14 18:56:03'),
(254, 108, 36, 'enrolled', '2026-07-14 18:56:03'),
(255, 112, 36, 'enrolled', '2026-07-14 18:56:03'),
(256, 116, 36, 'enrolled', '2026-07-14 18:56:03'),
(257, 120, 36, 'enrolled', '2026-07-14 18:56:03'),
(258, 124, 36, 'enrolled', '2026-07-14 18:56:03'),
(259, 128, 36, 'enrolled', '2026-07-14 18:56:03'),
(260, 132, 36, 'enrolled', '2026-07-14 18:56:03'),
(261, 136, 36, 'enrolled', '2026-07-14 18:56:03'),
(262, 140, 36, 'enrolled', '2026-07-14 18:56:03'),
(263, 144, 36, 'enrolled', '2026-07-14 18:56:03'),
(264, 148, 36, 'enrolled', '2026-07-14 18:56:03'),
(265, 105, 37, 'enrolled', '2026-07-14 18:56:03'),
(266, 109, 37, 'enrolled', '2026-07-14 18:56:03'),
(267, 113, 37, 'enrolled', '2026-07-14 18:56:03'),
(268, 117, 37, 'enrolled', '2026-07-14 18:56:03'),
(269, 121, 37, 'enrolled', '2026-07-14 18:56:03'),
(270, 125, 37, 'enrolled', '2026-07-14 18:56:03'),
(271, 129, 37, 'enrolled', '2026-07-14 18:56:03'),
(272, 133, 37, 'enrolled', '2026-07-14 18:56:03'),
(273, 137, 37, 'enrolled', '2026-07-14 18:56:03'),
(274, 141, 37, 'enrolled', '2026-07-14 18:56:03'),
(275, 145, 37, 'enrolled', '2026-07-14 18:56:03'),
(276, 149, 37, 'enrolled', '2026-07-14 18:56:03'),
(277, 105, 38, 'enrolled', '2026-07-14 18:56:03'),
(278, 109, 38, 'enrolled', '2026-07-14 18:56:03'),
(279, 113, 38, 'enrolled', '2026-07-14 18:56:03'),
(280, 117, 38, 'enrolled', '2026-07-14 18:56:03'),
(281, 121, 38, 'enrolled', '2026-07-14 18:56:03'),
(282, 125, 38, 'enrolled', '2026-07-14 18:56:03'),
(283, 129, 38, 'enrolled', '2026-07-14 18:56:03'),
(284, 133, 38, 'enrolled', '2026-07-14 18:56:03'),
(285, 137, 38, 'enrolled', '2026-07-14 18:56:03'),
(286, 141, 38, 'enrolled', '2026-07-14 18:56:03'),
(287, 145, 38, 'enrolled', '2026-07-14 18:56:03'),
(288, 149, 38, 'enrolled', '2026-07-14 18:56:03'),
(289, 105, 39, 'enrolled', '2026-07-14 18:56:03'),
(290, 109, 39, 'enrolled', '2026-07-14 18:56:03'),
(291, 113, 39, 'enrolled', '2026-07-14 18:56:03'),
(292, 117, 39, 'enrolled', '2026-07-14 18:56:03'),
(293, 121, 39, 'enrolled', '2026-07-14 18:56:03'),
(294, 125, 39, 'enrolled', '2026-07-14 18:56:03'),
(295, 129, 39, 'enrolled', '2026-07-14 18:56:03'),
(296, 133, 39, 'enrolled', '2026-07-14 18:56:03'),
(297, 137, 39, 'enrolled', '2026-07-14 18:56:03'),
(298, 141, 39, 'enrolled', '2026-07-14 18:56:03'),
(299, 145, 39, 'enrolled', '2026-07-14 18:56:03'),
(300, 149, 39, 'enrolled', '2026-07-14 18:56:03'),
(301, 55, 40, 'enrolled', '2026-07-14 18:56:03'),
(302, 87, 40, 'enrolled', '2026-07-14 18:56:03'),
(303, 55, 41, 'enrolled', '2026-07-14 18:56:03'),
(304, 87, 41, 'enrolled', '2026-07-14 18:56:03'),
(305, 55, 42, 'enrolled', '2026-07-14 18:56:03'),
(306, 87, 42, 'enrolled', '2026-07-14 18:56:03'),
(307, 41, 43, 'enrolled', '2026-07-14 18:56:03'),
(308, 73, 43, 'enrolled', '2026-07-14 18:56:03'),
(309, 41, 44, 'enrolled', '2026-07-14 18:56:03'),
(310, 73, 44, 'enrolled', '2026-07-14 18:56:03'),
(311, 41, 45, 'enrolled', '2026-07-14 18:56:03'),
(312, 73, 45, 'enrolled', '2026-07-14 18:56:03'),
(313, 54, 46, 'enrolled', '2026-07-14 18:56:03'),
(314, 70, 46, 'enrolled', '2026-07-14 18:56:03'),
(315, 86, 46, 'enrolled', '2026-07-14 18:56:03'),
(316, 54, 47, 'enrolled', '2026-07-14 18:56:03'),
(317, 70, 47, 'enrolled', '2026-07-14 18:56:03'),
(318, 86, 47, 'enrolled', '2026-07-14 18:56:03'),
(319, 54, 48, 'enrolled', '2026-07-14 18:56:03'),
(320, 70, 48, 'enrolled', '2026-07-14 18:56:03'),
(321, 86, 48, 'enrolled', '2026-07-14 18:56:03'),
(322, 56, 49, 'enrolled', '2026-07-14 18:56:03'),
(323, 88, 49, 'enrolled', '2026-07-14 18:56:03'),
(324, 56, 50, 'enrolled', '2026-07-14 18:56:03'),
(325, 88, 50, 'enrolled', '2026-07-14 18:56:03'),
(326, 56, 51, 'enrolled', '2026-07-14 18:56:03'),
(327, 88, 51, 'enrolled', '2026-07-14 18:56:03'),
(328, 52, 52, 'enrolled', '2026-07-14 18:56:03'),
(329, 68, 52, 'enrolled', '2026-07-14 18:56:03'),
(330, 84, 52, 'enrolled', '2026-07-14 18:56:03'),
(331, 100, 52, 'enrolled', '2026-07-14 18:56:03'),
(332, 52, 53, 'enrolled', '2026-07-14 18:56:03'),
(333, 68, 53, 'enrolled', '2026-07-14 18:56:03'),
(334, 84, 53, 'enrolled', '2026-07-14 18:56:03'),
(335, 100, 53, 'enrolled', '2026-07-14 18:56:03'),
(336, 52, 54, 'enrolled', '2026-07-14 18:56:03'),
(337, 68, 54, 'enrolled', '2026-07-14 18:56:03'),
(338, 84, 54, 'enrolled', '2026-07-14 18:56:03'),
(339, 100, 54, 'enrolled', '2026-07-14 18:56:03'),
(340, 47, 55, 'enrolled', '2026-07-14 19:04:00'),
(341, 63, 55, 'enrolled', '2026-07-14 19:04:00'),
(342, 64, 55, 'enrolled', '2026-07-14 19:04:00'),
(343, 79, 55, 'enrolled', '2026-07-14 19:04:00'),
(344, 95, 55, 'enrolled', '2026-07-14 19:04:00'),
(345, 96, 55, 'enrolled', '2026-07-14 19:04:00'),
(346, 47, 56, 'enrolled', '2026-07-14 19:04:00'),
(347, 63, 56, 'enrolled', '2026-07-14 19:04:00'),
(348, 64, 56, 'enrolled', '2026-07-14 19:04:00'),
(349, 79, 56, 'enrolled', '2026-07-14 19:04:00'),
(350, 95, 56, 'enrolled', '2026-07-14 19:04:00'),
(351, 96, 56, 'enrolled', '2026-07-14 19:04:00'),
(352, 47, 57, 'enrolled', '2026-07-14 19:04:00'),
(353, 63, 57, 'enrolled', '2026-07-14 19:04:00'),
(354, 64, 57, 'enrolled', '2026-07-14 19:04:00'),
(355, 79, 57, 'enrolled', '2026-07-14 19:04:00'),
(356, 95, 57, 'enrolled', '2026-07-14 19:04:00'),
(357, 96, 57, 'enrolled', '2026-07-14 19:04:00'),
(358, 49, 58, 'enrolled', '2026-07-14 19:04:00'),
(359, 58, 58, 'enrolled', '2026-07-14 19:04:00'),
(360, 66, 58, 'enrolled', '2026-07-14 19:04:00'),
(361, 81, 58, 'enrolled', '2026-07-14 19:04:00'),
(362, 90, 58, 'enrolled', '2026-07-14 19:04:00'),
(363, 98, 58, 'enrolled', '2026-07-14 19:04:00'),
(364, 49, 59, 'enrolled', '2026-07-14 19:04:00'),
(365, 58, 59, 'enrolled', '2026-07-14 19:04:00'),
(366, 66, 59, 'enrolled', '2026-07-14 19:04:00'),
(367, 81, 59, 'enrolled', '2026-07-14 19:04:00'),
(368, 90, 59, 'enrolled', '2026-07-14 19:04:00'),
(369, 98, 59, 'enrolled', '2026-07-14 19:04:00'),
(370, 49, 60, 'enrolled', '2026-07-14 19:04:00'),
(371, 58, 60, 'enrolled', '2026-07-14 19:04:00'),
(372, 66, 60, 'enrolled', '2026-07-14 19:04:00'),
(373, 81, 60, 'enrolled', '2026-07-14 19:04:00'),
(374, 90, 60, 'enrolled', '2026-07-14 19:04:00'),
(375, 98, 60, 'enrolled', '2026-07-14 19:04:00'),
(376, 43, 61, 'enrolled', '2026-07-14 19:04:00'),
(377, 51, 61, 'enrolled', '2026-07-14 19:04:00'),
(378, 60, 61, 'enrolled', '2026-07-14 19:04:00'),
(379, 67, 61, 'enrolled', '2026-07-14 19:04:00'),
(380, 75, 61, 'enrolled', '2026-07-14 19:04:00'),
(381, 83, 61, 'enrolled', '2026-07-14 19:04:00'),
(382, 92, 61, 'enrolled', '2026-07-14 19:04:00'),
(383, 99, 61, 'enrolled', '2026-07-14 19:04:00'),
(384, 43, 62, 'enrolled', '2026-07-14 19:04:00'),
(385, 51, 62, 'enrolled', '2026-07-14 19:04:00'),
(386, 60, 62, 'enrolled', '2026-07-14 19:04:00'),
(387, 67, 62, 'enrolled', '2026-07-14 19:04:00'),
(388, 75, 62, 'enrolled', '2026-07-14 19:04:00'),
(389, 83, 62, 'enrolled', '2026-07-14 19:04:00'),
(390, 92, 62, 'enrolled', '2026-07-14 19:04:00'),
(391, 99, 62, 'enrolled', '2026-07-14 19:04:00'),
(392, 43, 63, 'enrolled', '2026-07-14 19:04:00'),
(393, 51, 63, 'enrolled', '2026-07-14 19:04:00'),
(394, 60, 63, 'enrolled', '2026-07-14 19:04:00'),
(395, 67, 63, 'enrolled', '2026-07-14 19:04:00'),
(396, 75, 63, 'enrolled', '2026-07-14 19:04:00'),
(397, 83, 63, 'enrolled', '2026-07-14 19:04:00'),
(398, 92, 63, 'enrolled', '2026-07-14 19:04:00'),
(399, 99, 63, 'enrolled', '2026-07-14 19:04:00'),
(400, 45, 64, 'enrolled', '2026-07-14 19:04:00'),
(401, 46, 64, 'enrolled', '2026-07-14 19:04:00'),
(402, 61, 64, 'enrolled', '2026-07-14 19:04:00'),
(403, 62, 64, 'enrolled', '2026-07-14 19:04:00'),
(404, 77, 64, 'enrolled', '2026-07-14 19:04:00'),
(405, 78, 64, 'enrolled', '2026-07-14 19:04:00'),
(406, 93, 64, 'enrolled', '2026-07-14 19:04:00'),
(407, 94, 64, 'enrolled', '2026-07-14 19:04:00'),
(408, 45, 65, 'enrolled', '2026-07-14 19:04:00'),
(409, 46, 65, 'enrolled', '2026-07-14 19:04:00'),
(410, 61, 65, 'enrolled', '2026-07-14 19:04:00'),
(411, 62, 65, 'enrolled', '2026-07-14 19:04:00'),
(412, 77, 65, 'enrolled', '2026-07-14 19:04:00'),
(413, 78, 65, 'enrolled', '2026-07-14 19:04:00'),
(414, 93, 65, 'enrolled', '2026-07-14 19:04:00'),
(415, 94, 65, 'enrolled', '2026-07-14 19:04:00'),
(416, 45, 66, 'enrolled', '2026-07-14 19:04:00'),
(417, 46, 66, 'enrolled', '2026-07-14 19:04:00'),
(418, 61, 66, 'enrolled', '2026-07-14 19:04:00'),
(419, 62, 66, 'enrolled', '2026-07-14 19:04:00'),
(420, 77, 66, 'enrolled', '2026-07-14 19:04:00'),
(421, 78, 66, 'enrolled', '2026-07-14 19:04:00'),
(422, 93, 66, 'enrolled', '2026-07-14 19:04:00'),
(423, 94, 66, 'enrolled', '2026-07-14 19:04:00'),
(424, 40, 67, 'enrolled', '2026-07-14 19:04:00'),
(425, 72, 67, 'enrolled', '2026-07-14 19:04:00'),
(426, 40, 68, 'enrolled', '2026-07-14 19:04:00'),
(427, 72, 68, 'enrolled', '2026-07-14 19:04:00'),
(428, 40, 69, 'enrolled', '2026-07-14 19:04:00'),
(429, 72, 69, 'enrolled', '2026-07-14 19:04:00'),
(430, 53, 70, 'enrolled', '2026-07-14 19:04:00'),
(431, 69, 70, 'enrolled', '2026-07-14 19:04:00'),
(432, 85, 70, 'enrolled', '2026-07-14 19:04:00'),
(433, 101, 70, 'enrolled', '2026-07-14 19:04:00'),
(434, 53, 71, 'enrolled', '2026-07-14 19:04:00'),
(435, 69, 71, 'enrolled', '2026-07-14 19:04:00'),
(436, 85, 71, 'enrolled', '2026-07-14 19:04:00'),
(437, 101, 71, 'enrolled', '2026-07-14 19:04:00'),
(438, 53, 72, 'enrolled', '2026-07-14 19:04:00'),
(439, 69, 72, 'enrolled', '2026-07-14 19:04:00'),
(440, 85, 72, 'enrolled', '2026-07-14 19:04:00'),
(441, 101, 72, 'enrolled', '2026-07-14 19:04:00'),
(442, 48, 73, 'enrolled', '2026-07-14 19:04:00'),
(443, 80, 73, 'enrolled', '2026-07-14 19:04:00'),
(444, 48, 74, 'enrolled', '2026-07-14 19:04:00'),
(445, 80, 74, 'enrolled', '2026-07-14 19:04:00'),
(446, 48, 75, 'enrolled', '2026-07-14 19:04:00'),
(447, 80, 75, 'enrolled', '2026-07-14 19:04:00'),
(448, 42, 76, 'enrolled', '2026-07-14 19:04:00'),
(449, 50, 76, 'enrolled', '2026-07-14 19:04:00'),
(450, 65, 76, 'enrolled', '2026-07-14 19:04:00'),
(451, 74, 76, 'enrolled', '2026-07-14 19:04:00'),
(452, 82, 76, 'enrolled', '2026-07-14 19:04:00'),
(453, 97, 76, 'enrolled', '2026-07-14 19:04:00'),
(454, 42, 77, 'enrolled', '2026-07-14 19:04:00'),
(455, 50, 77, 'enrolled', '2026-07-14 19:04:00'),
(456, 65, 77, 'enrolled', '2026-07-14 19:04:00'),
(457, 74, 77, 'enrolled', '2026-07-14 19:04:00'),
(458, 82, 77, 'enrolled', '2026-07-14 19:04:00'),
(459, 97, 77, 'enrolled', '2026-07-14 19:04:00'),
(460, 42, 78, 'enrolled', '2026-07-14 19:04:00'),
(461, 50, 78, 'enrolled', '2026-07-14 19:04:00'),
(462, 65, 78, 'enrolled', '2026-07-14 19:04:00'),
(463, 74, 78, 'enrolled', '2026-07-14 19:04:00'),
(464, 82, 78, 'enrolled', '2026-07-14 19:04:00'),
(465, 97, 78, 'enrolled', '2026-07-14 19:04:00'),
(466, 44, 79, 'enrolled', '2026-07-14 19:04:00'),
(467, 59, 79, 'enrolled', '2026-07-14 19:04:00'),
(468, 76, 79, 'enrolled', '2026-07-14 19:04:00'),
(469, 91, 79, 'enrolled', '2026-07-14 19:04:00'),
(470, 44, 80, 'enrolled', '2026-07-14 19:04:00'),
(471, 59, 80, 'enrolled', '2026-07-14 19:04:00'),
(472, 76, 80, 'enrolled', '2026-07-14 19:04:00'),
(473, 91, 80, 'enrolled', '2026-07-14 19:04:00'),
(474, 44, 81, 'enrolled', '2026-07-14 19:04:00'),
(475, 59, 81, 'enrolled', '2026-07-14 19:04:00'),
(476, 76, 81, 'enrolled', '2026-07-14 19:04:00'),
(477, 91, 81, 'enrolled', '2026-07-14 19:04:00'),
(478, 39, 82, 'enrolled', '2026-07-14 19:04:00'),
(479, 71, 82, 'enrolled', '2026-07-14 19:04:00'),
(480, 102, 82, 'enrolled', '2026-07-14 19:04:00'),
(481, 106, 82, 'enrolled', '2026-07-14 19:04:00'),
(482, 110, 82, 'enrolled', '2026-07-14 19:04:00'),
(483, 114, 82, 'enrolled', '2026-07-14 19:04:00'),
(484, 118, 82, 'enrolled', '2026-07-14 19:04:00'),
(485, 122, 82, 'enrolled', '2026-07-14 19:04:00'),
(486, 126, 82, 'enrolled', '2026-07-14 19:04:00'),
(487, 130, 82, 'enrolled', '2026-07-14 19:04:00'),
(488, 134, 82, 'enrolled', '2026-07-14 19:04:00'),
(489, 138, 82, 'enrolled', '2026-07-14 19:04:00'),
(490, 142, 82, 'enrolled', '2026-07-14 19:04:00'),
(491, 146, 82, 'enrolled', '2026-07-14 19:04:00'),
(492, 150, 82, 'enrolled', '2026-07-14 19:04:00'),
(493, 39, 83, 'enrolled', '2026-07-14 19:04:00'),
(494, 71, 83, 'enrolled', '2026-07-14 19:04:00'),
(495, 102, 83, 'enrolled', '2026-07-14 19:04:00'),
(496, 106, 83, 'enrolled', '2026-07-14 19:04:00'),
(497, 110, 83, 'enrolled', '2026-07-14 19:04:00'),
(498, 114, 83, 'enrolled', '2026-07-14 19:04:00'),
(499, 118, 83, 'enrolled', '2026-07-14 19:04:00'),
(500, 122, 83, 'enrolled', '2026-07-14 19:04:00'),
(501, 126, 83, 'enrolled', '2026-07-14 19:04:00'),
(502, 130, 83, 'enrolled', '2026-07-14 19:04:00'),
(503, 134, 83, 'enrolled', '2026-07-14 19:04:00'),
(504, 138, 83, 'enrolled', '2026-07-14 19:04:00'),
(505, 142, 83, 'enrolled', '2026-07-14 19:04:00'),
(506, 146, 83, 'enrolled', '2026-07-14 19:04:00'),
(507, 150, 83, 'enrolled', '2026-07-14 19:04:00'),
(508, 39, 84, 'enrolled', '2026-07-14 19:04:00'),
(509, 71, 84, 'enrolled', '2026-07-14 19:04:00'),
(510, 102, 84, 'enrolled', '2026-07-14 19:04:00'),
(511, 106, 84, 'enrolled', '2026-07-14 19:04:00'),
(512, 110, 84, 'enrolled', '2026-07-14 19:04:00'),
(513, 114, 84, 'enrolled', '2026-07-14 19:04:00'),
(514, 118, 84, 'enrolled', '2026-07-14 19:04:00'),
(515, 122, 84, 'enrolled', '2026-07-14 19:04:00'),
(516, 126, 84, 'enrolled', '2026-07-14 19:04:00'),
(517, 130, 84, 'enrolled', '2026-07-14 19:04:00'),
(518, 134, 84, 'enrolled', '2026-07-14 19:04:00'),
(519, 138, 84, 'enrolled', '2026-07-14 19:04:00'),
(520, 142, 84, 'enrolled', '2026-07-14 19:04:00'),
(521, 146, 84, 'enrolled', '2026-07-14 19:04:00'),
(522, 150, 84, 'enrolled', '2026-07-14 19:04:00'),
(523, 57, 85, 'enrolled', '2026-07-14 19:04:00'),
(524, 89, 85, 'enrolled', '2026-07-14 19:04:00'),
(525, 103, 85, 'enrolled', '2026-07-14 19:04:00'),
(526, 107, 85, 'enrolled', '2026-07-14 19:04:00'),
(527, 111, 85, 'enrolled', '2026-07-14 19:04:00'),
(528, 115, 85, 'enrolled', '2026-07-14 19:04:00'),
(529, 119, 85, 'enrolled', '2026-07-14 19:04:00'),
(530, 123, 85, 'enrolled', '2026-07-14 19:04:00'),
(531, 127, 85, 'enrolled', '2026-07-14 19:04:00'),
(532, 131, 85, 'enrolled', '2026-07-14 19:04:00'),
(533, 135, 85, 'enrolled', '2026-07-14 19:04:00'),
(534, 139, 85, 'enrolled', '2026-07-14 19:04:00'),
(535, 143, 85, 'enrolled', '2026-07-14 19:04:00'),
(536, 147, 85, 'enrolled', '2026-07-14 19:04:00'),
(537, 151, 85, 'enrolled', '2026-07-14 19:04:00'),
(538, 57, 86, 'enrolled', '2026-07-14 19:04:00'),
(539, 89, 86, 'enrolled', '2026-07-14 19:04:00'),
(540, 103, 86, 'enrolled', '2026-07-14 19:04:00'),
(541, 107, 86, 'enrolled', '2026-07-14 19:04:00'),
(542, 111, 86, 'enrolled', '2026-07-14 19:04:00'),
(543, 115, 86, 'enrolled', '2026-07-14 19:04:00'),
(544, 119, 86, 'enrolled', '2026-07-14 19:04:00'),
(545, 123, 86, 'enrolled', '2026-07-14 19:04:00'),
(546, 127, 86, 'enrolled', '2026-07-14 19:04:00'),
(547, 131, 86, 'enrolled', '2026-07-14 19:04:00'),
(548, 135, 86, 'enrolled', '2026-07-14 19:04:00'),
(549, 139, 86, 'enrolled', '2026-07-14 19:04:00'),
(550, 143, 86, 'enrolled', '2026-07-14 19:04:00'),
(551, 147, 86, 'enrolled', '2026-07-14 19:04:00'),
(552, 151, 86, 'enrolled', '2026-07-14 19:04:00'),
(553, 57, 87, 'enrolled', '2026-07-14 19:04:00'),
(554, 89, 87, 'enrolled', '2026-07-14 19:04:00'),
(555, 103, 87, 'enrolled', '2026-07-14 19:04:00'),
(556, 107, 87, 'enrolled', '2026-07-14 19:04:00'),
(557, 111, 87, 'enrolled', '2026-07-14 19:04:00'),
(558, 115, 87, 'enrolled', '2026-07-14 19:04:00'),
(559, 119, 87, 'enrolled', '2026-07-14 19:04:00'),
(560, 123, 87, 'enrolled', '2026-07-14 19:04:00'),
(561, 127, 87, 'enrolled', '2026-07-14 19:04:00'),
(562, 131, 87, 'enrolled', '2026-07-14 19:04:00'),
(563, 135, 87, 'enrolled', '2026-07-14 19:04:00'),
(564, 139, 87, 'enrolled', '2026-07-14 19:04:00'),
(565, 143, 87, 'enrolled', '2026-07-14 19:04:00'),
(566, 147, 87, 'enrolled', '2026-07-14 19:04:00'),
(567, 151, 87, 'enrolled', '2026-07-14 19:04:00'),
(568, 104, 88, 'enrolled', '2026-07-14 19:04:00'),
(569, 108, 88, 'enrolled', '2026-07-14 19:04:00'),
(570, 112, 88, 'enrolled', '2026-07-14 19:04:00'),
(571, 116, 88, 'enrolled', '2026-07-14 19:04:00'),
(572, 120, 88, 'enrolled', '2026-07-14 19:04:00'),
(573, 124, 88, 'enrolled', '2026-07-14 19:04:00'),
(574, 128, 88, 'enrolled', '2026-07-14 19:04:00'),
(575, 132, 88, 'enrolled', '2026-07-14 19:04:00'),
(576, 136, 88, 'enrolled', '2026-07-14 19:04:00'),
(577, 140, 88, 'enrolled', '2026-07-14 19:04:00'),
(578, 144, 88, 'enrolled', '2026-07-14 19:04:00'),
(579, 148, 88, 'enrolled', '2026-07-14 19:04:00'),
(580, 104, 89, 'enrolled', '2026-07-14 19:04:00'),
(581, 108, 89, 'enrolled', '2026-07-14 19:04:00'),
(582, 112, 89, 'enrolled', '2026-07-14 19:04:00'),
(583, 116, 89, 'enrolled', '2026-07-14 19:04:00'),
(584, 120, 89, 'enrolled', '2026-07-14 19:04:00'),
(585, 124, 89, 'enrolled', '2026-07-14 19:04:00'),
(586, 128, 89, 'enrolled', '2026-07-14 19:04:00'),
(587, 132, 89, 'enrolled', '2026-07-14 19:04:00'),
(588, 136, 89, 'enrolled', '2026-07-14 19:04:00'),
(589, 140, 89, 'enrolled', '2026-07-14 19:04:00'),
(590, 144, 89, 'enrolled', '2026-07-14 19:04:00'),
(591, 148, 89, 'enrolled', '2026-07-14 19:04:00'),
(592, 104, 90, 'enrolled', '2026-07-14 19:04:00'),
(593, 108, 90, 'enrolled', '2026-07-14 19:04:00'),
(594, 112, 90, 'enrolled', '2026-07-14 19:04:00'),
(595, 116, 90, 'enrolled', '2026-07-14 19:04:00'),
(596, 120, 90, 'enrolled', '2026-07-14 19:04:00'),
(597, 124, 90, 'enrolled', '2026-07-14 19:04:00'),
(598, 128, 90, 'enrolled', '2026-07-14 19:04:00'),
(599, 132, 90, 'enrolled', '2026-07-14 19:04:00'),
(600, 136, 90, 'enrolled', '2026-07-14 19:04:00'),
(601, 140, 90, 'enrolled', '2026-07-14 19:04:00'),
(602, 144, 90, 'enrolled', '2026-07-14 19:04:00'),
(603, 148, 90, 'enrolled', '2026-07-14 19:04:00'),
(604, 105, 91, 'enrolled', '2026-07-14 19:04:00'),
(605, 109, 91, 'enrolled', '2026-07-14 19:04:00'),
(606, 113, 91, 'enrolled', '2026-07-14 19:04:00'),
(607, 117, 91, 'enrolled', '2026-07-14 19:04:00'),
(608, 121, 91, 'enrolled', '2026-07-14 19:04:00'),
(609, 125, 91, 'enrolled', '2026-07-14 19:04:00'),
(610, 129, 91, 'enrolled', '2026-07-14 19:04:00'),
(611, 133, 91, 'enrolled', '2026-07-14 19:04:00'),
(612, 137, 91, 'enrolled', '2026-07-14 19:04:00'),
(613, 141, 91, 'enrolled', '2026-07-14 19:04:00'),
(614, 145, 91, 'enrolled', '2026-07-14 19:04:00'),
(615, 149, 91, 'enrolled', '2026-07-14 19:04:00'),
(616, 105, 92, 'enrolled', '2026-07-14 19:04:00'),
(617, 109, 92, 'enrolled', '2026-07-14 19:04:00'),
(618, 113, 92, 'enrolled', '2026-07-14 19:04:00'),
(619, 117, 92, 'enrolled', '2026-07-14 19:04:00'),
(620, 121, 92, 'enrolled', '2026-07-14 19:04:00'),
(621, 125, 92, 'enrolled', '2026-07-14 19:04:00'),
(622, 129, 92, 'enrolled', '2026-07-14 19:04:00'),
(623, 133, 92, 'enrolled', '2026-07-14 19:04:00'),
(624, 137, 92, 'enrolled', '2026-07-14 19:04:00'),
(625, 141, 92, 'enrolled', '2026-07-14 19:04:00'),
(626, 145, 92, 'enrolled', '2026-07-14 19:04:00'),
(627, 149, 92, 'enrolled', '2026-07-14 19:04:00'),
(628, 105, 93, 'enrolled', '2026-07-14 19:04:00'),
(629, 109, 93, 'enrolled', '2026-07-14 19:04:00'),
(630, 113, 93, 'enrolled', '2026-07-14 19:04:00'),
(631, 117, 93, 'enrolled', '2026-07-14 19:04:00'),
(632, 121, 93, 'enrolled', '2026-07-14 19:04:00'),
(633, 125, 93, 'enrolled', '2026-07-14 19:04:00'),
(634, 129, 93, 'enrolled', '2026-07-14 19:04:00'),
(635, 133, 93, 'enrolled', '2026-07-14 19:04:00'),
(636, 137, 93, 'enrolled', '2026-07-14 19:04:00'),
(637, 141, 93, 'enrolled', '2026-07-14 19:04:00'),
(638, 145, 93, 'enrolled', '2026-07-14 19:04:00'),
(639, 149, 93, 'enrolled', '2026-07-14 19:04:00'),
(640, 55, 94, 'enrolled', '2026-07-14 19:04:00'),
(641, 87, 94, 'enrolled', '2026-07-14 19:04:00'),
(642, 55, 95, 'enrolled', '2026-07-14 19:04:00'),
(643, 87, 95, 'enrolled', '2026-07-14 19:04:00'),
(644, 55, 96, 'enrolled', '2026-07-14 19:04:00'),
(645, 87, 96, 'enrolled', '2026-07-14 19:04:00'),
(646, 41, 97, 'enrolled', '2026-07-14 19:04:00'),
(647, 73, 97, 'enrolled', '2026-07-14 19:04:00'),
(648, 41, 98, 'enrolled', '2026-07-14 19:04:00'),
(649, 73, 98, 'enrolled', '2026-07-14 19:04:00'),
(650, 41, 99, 'enrolled', '2026-07-14 19:04:00'),
(651, 73, 99, 'enrolled', '2026-07-14 19:04:00'),
(652, 54, 100, 'enrolled', '2026-07-14 19:04:00'),
(653, 70, 100, 'enrolled', '2026-07-14 19:04:00'),
(654, 86, 100, 'enrolled', '2026-07-14 19:04:00'),
(655, 54, 101, 'enrolled', '2026-07-14 19:04:00'),
(656, 70, 101, 'enrolled', '2026-07-14 19:04:00'),
(657, 86, 101, 'enrolled', '2026-07-14 19:04:00'),
(658, 54, 102, 'enrolled', '2026-07-14 19:04:00'),
(659, 70, 102, 'enrolled', '2026-07-14 19:04:00'),
(660, 86, 102, 'enrolled', '2026-07-14 19:04:00'),
(661, 56, 103, 'enrolled', '2026-07-14 19:04:00'),
(662, 88, 103, 'enrolled', '2026-07-14 19:04:00'),
(663, 56, 104, 'enrolled', '2026-07-14 19:04:00'),
(664, 88, 104, 'enrolled', '2026-07-14 19:04:00'),
(665, 56, 105, 'enrolled', '2026-07-14 19:04:00'),
(666, 88, 105, 'enrolled', '2026-07-14 19:04:00'),
(667, 52, 106, 'enrolled', '2026-07-14 19:04:00'),
(668, 68, 106, 'enrolled', '2026-07-14 19:04:00'),
(669, 84, 106, 'enrolled', '2026-07-14 19:04:00'),
(670, 100, 106, 'enrolled', '2026-07-14 19:04:00'),
(671, 52, 107, 'enrolled', '2026-07-14 19:04:00'),
(672, 68, 107, 'enrolled', '2026-07-14 19:04:00'),
(673, 84, 107, 'enrolled', '2026-07-14 19:04:00'),
(674, 100, 107, 'enrolled', '2026-07-14 19:04:00'),
(675, 52, 108, 'enrolled', '2026-07-14 19:04:00'),
(676, 68, 108, 'enrolled', '2026-07-14 19:04:00'),
(677, 84, 108, 'enrolled', '2026-07-14 19:04:00'),
(678, 100, 108, 'enrolled', '2026-07-14 19:04:00');

-- --------------------------------------------------------

--
-- Table structure for table `student_profiles`
--

CREATE TABLE `student_profiles` (
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `student_number` varchar(50) NOT NULL,
  `program_id` bigint(20) UNSIGNED DEFAULT NULL,
  `year_section` varchar(100) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `student_profiles`
--

INSERT INTO `student_profiles` (`user_id`, `student_number`, `program_id`, `year_section`, `created_at`, `updated_at`) VALUES
(39, 'student', 5, '1-1', '2026-07-14 18:55:25', '2026-07-14 18:55:25'),
(40, '12324MN-000002', 4, '1-1', '2026-07-14 18:55:25', '2026-07-14 18:55:25'),
(41, '12324MN-000003', 6, '2-1', '2026-07-14 18:55:25', '2026-07-14 18:55:25'),
(42, '12324MN-000004', 1, '2-1', '2026-07-14 18:55:25', '2026-07-14 18:55:25'),
(43, '12324MN-000005', 2, '3-1', '2026-07-14 18:55:25', '2026-07-14 18:55:25'),
(44, '12324MN-000006', 1, '3-1', '2026-07-14 18:55:25', '2026-07-14 18:55:25'),
(45, '12324MN-000007', 2, '4-1', '2026-07-14 18:55:25', '2026-07-14 18:55:25'),
(46, '12324MN-000008', 2, '4-1', '2026-07-14 18:55:25', '2026-07-14 18:55:25'),
(47, '12324MN-000009', 2, '1-1', '2026-07-14 18:55:25', '2026-07-14 18:55:25'),
(48, '12324MN-000010', 1, '1-1', '2026-07-14 18:55:25', '2026-07-14 18:55:25'),
(49, '12324MN-000011', 2, '2-1', '2026-07-14 18:55:25', '2026-07-14 18:55:25'),
(50, '12324MN-000012', 1, '2-1', '2026-07-14 18:55:25', '2026-07-14 18:55:25'),
(51, '12324MN-000013', 2, '3-1', '2026-07-14 18:55:25', '2026-07-14 18:55:25'),
(52, '12324MN-000014', 3, '3-1', '2026-07-14 18:55:25', '2026-07-14 18:55:25'),
(53, '12324MN-000015', 4, '4-1', '2026-07-14 18:55:25', '2026-07-14 18:55:25'),
(54, '12324MN-000016', 6, '4-1', '2026-07-14 18:55:26', '2026-07-14 18:55:26'),
(55, '12324MN-000017', 6, '1-1', '2026-07-14 18:55:26', '2026-07-14 18:55:26'),
(56, '12324MN-000018', 3, '1-1', '2026-07-14 18:55:26', '2026-07-14 18:55:26'),
(57, '12324MN-000019', 5, '2-1', '2026-07-14 18:55:26', '2026-07-14 18:55:26'),
(58, '12324MN-000020', 2, '2-1', '2026-07-14 18:55:26', '2026-07-14 18:55:26'),
(59, '12324MN-000021', 1, '3-1', '2026-07-14 18:55:26', '2026-07-14 18:55:26'),
(60, '12324MN-000022', 2, '3-1', '2026-07-14 18:55:26', '2026-07-14 18:55:26'),
(61, '12324MN-000023', 2, '4-1', '2026-07-14 18:55:26', '2026-07-14 18:55:26'),
(62, '12324MN-000024', 2, '4-1', '2026-07-14 18:55:26', '2026-07-14 18:55:26'),
(63, '12324MN-000025', 2, '1-1', '2026-07-14 18:55:26', '2026-07-14 18:55:26'),
(64, '12324MN-000026', 2, '1-1', '2026-07-14 18:55:26', '2026-07-14 18:55:26'),
(65, '12324MN-000027', 1, '2-1', '2026-07-14 18:55:26', '2026-07-14 18:55:26'),
(66, '12324MN-000028', 2, '2-1', '2026-07-14 18:55:26', '2026-07-14 18:55:26'),
(67, '12324MN-000029', 2, '3-1', '2026-07-14 18:55:26', '2026-07-14 18:55:26'),
(68, '12324MN-000030', 3, '3-1', '2026-07-14 18:55:26', '2026-07-14 18:55:26'),
(69, '12324MN-000031', 4, '4-1', '2026-07-14 18:55:26', '2026-07-14 18:55:26'),
(70, '12324MN-000032', 6, '4-1', '2026-07-14 18:55:26', '2026-07-14 18:55:26'),
(71, '12324MN-000033', 5, '1-1', '2026-07-14 18:55:26', '2026-07-14 18:55:26'),
(72, '12324MN-000034', 4, '1-1', '2026-07-14 18:55:26', '2026-07-14 18:55:26'),
(73, '12324MN-000035', 6, '2-1', '2026-07-14 18:55:27', '2026-07-14 18:55:27'),
(74, '12324MN-000036', 1, '2-1', '2026-07-14 18:55:27', '2026-07-14 18:55:27'),
(75, '12324MN-000037', 2, '3-1', '2026-07-14 18:55:27', '2026-07-14 18:55:27'),
(76, '12324MN-000038', 1, '3-1', '2026-07-14 18:55:27', '2026-07-14 18:55:27'),
(77, '12324MN-000039', 2, '4-1', '2026-07-14 18:55:27', '2026-07-14 18:55:27'),
(78, '12324MN-000040', 2, '4-1', '2026-07-14 18:55:27', '2026-07-14 18:55:27'),
(79, '12324MN-000041', 2, '1-1', '2026-07-14 18:55:27', '2026-07-14 18:55:27'),
(80, '12324MN-000042', 1, '1-1', '2026-07-14 18:55:27', '2026-07-14 18:55:27'),
(81, '12324MN-000043', 2, '2-1', '2026-07-14 18:55:27', '2026-07-14 18:55:27'),
(82, '12324MN-000044', 1, '2-1', '2026-07-14 18:55:27', '2026-07-14 18:55:27'),
(83, '12324MN-000045', 2, '3-1', '2026-07-14 18:55:27', '2026-07-14 18:55:27'),
(84, '12324MN-000046', 3, '3-1', '2026-07-14 18:55:27', '2026-07-14 18:55:27'),
(85, '12324MN-000047', 4, '4-1', '2026-07-14 18:55:27', '2026-07-14 18:55:27'),
(86, '12324MN-000048', 6, '4-1', '2026-07-14 18:55:27', '2026-07-14 18:55:27'),
(87, '12324MN-000049', 6, '1-1', '2026-07-14 18:55:27', '2026-07-14 18:55:27'),
(88, '12324MN-000050', 3, '1-1', '2026-07-14 18:55:27', '2026-07-14 18:55:27'),
(89, '12324MN-000051', 5, '2-1', '2026-07-14 18:55:27', '2026-07-14 18:55:27'),
(90, '12324MN-000052', 2, '2-1', '2026-07-14 18:55:27', '2026-07-14 18:55:27'),
(91, '12324MN-000053', 1, '3-1', '2026-07-14 18:55:27', '2026-07-14 18:55:27'),
(92, '12324MN-000054', 2, '3-1', '2026-07-14 18:55:28', '2026-07-14 18:55:28'),
(93, '12324MN-000055', 2, '4-1', '2026-07-14 18:55:28', '2026-07-14 18:55:28'),
(94, '12324MN-000056', 2, '4-1', '2026-07-14 18:55:28', '2026-07-14 18:55:28'),
(95, '12324MN-000057', 2, '1-1', '2026-07-14 18:55:28', '2026-07-14 18:55:28'),
(96, '12324MN-000058', 2, '1-1', '2026-07-14 18:55:28', '2026-07-14 18:55:28'),
(97, '12324MN-000059', 1, '2-1', '2026-07-14 18:55:28', '2026-07-14 18:55:28'),
(98, '12324MN-000060', 2, '2-1', '2026-07-14 18:55:28', '2026-07-14 18:55:28'),
(99, '12324MN-000061', 2, '3-1', '2026-07-14 18:55:28', '2026-07-14 18:55:28'),
(100, '12324MN-000062', 3, '3-1', '2026-07-14 18:55:28', '2026-07-14 18:55:28'),
(101, '12324MN-000063', 4, '4-1', '2026-07-14 18:55:28', '2026-07-14 18:55:28'),
(102, '12324MN-000064', 5, '1-1', '2026-07-14 18:55:28', '2026-07-14 18:55:28'),
(103, '12324MN-000065', 5, '2-1', '2026-07-14 18:55:28', '2026-07-14 18:55:28'),
(104, '12324MN-000066', 5, '3-1', '2026-07-14 18:55:28', '2026-07-14 18:55:28'),
(105, '12324MN-000067', 5, '4-1', '2026-07-14 18:55:28', '2026-07-14 18:55:28'),
(106, '12324MN-000068', 5, '1-1', '2026-07-14 18:55:28', '2026-07-14 18:55:28'),
(107, '12324MN-000069', 5, '2-1', '2026-07-14 18:55:28', '2026-07-14 18:55:28'),
(108, '12324MN-000070', 5, '3-1', '2026-07-14 18:55:28', '2026-07-14 18:55:28'),
(109, '12324MN-000071', 5, '4-1', '2026-07-14 18:55:28', '2026-07-14 18:55:28'),
(110, '12324MN-000072', 5, '1-1', '2026-07-14 18:55:28', '2026-07-14 18:55:28'),
(111, '12324MN-000073', 5, '2-1', '2026-07-14 18:55:28', '2026-07-14 18:55:28'),
(112, '12324MN-000074', 5, '3-1', '2026-07-14 18:55:29', '2026-07-14 18:55:29'),
(113, '12324MN-000075', 5, '4-1', '2026-07-14 18:55:29', '2026-07-14 18:55:29'),
(114, '12324MN-000076', 5, '1-1', '2026-07-14 18:55:29', '2026-07-14 18:55:29'),
(115, '12324MN-000077', 5, '2-1', '2026-07-14 18:55:29', '2026-07-14 18:55:29'),
(116, '12324MN-000078', 5, '3-1', '2026-07-14 18:55:29', '2026-07-14 18:55:29'),
(117, '12324MN-000079', 5, '4-1', '2026-07-14 18:55:29', '2026-07-14 18:55:29'),
(118, '12324MN-000080', 5, '1-1', '2026-07-14 18:55:29', '2026-07-14 18:55:29'),
(119, '12324MN-000081', 5, '2-1', '2026-07-14 18:55:29', '2026-07-14 18:55:29'),
(120, '12324MN-000082', 5, '3-1', '2026-07-14 18:55:29', '2026-07-14 18:55:29'),
(121, '12324MN-000083', 5, '4-1', '2026-07-14 18:55:29', '2026-07-14 18:55:29'),
(122, '12324MN-000084', 5, '1-1', '2026-07-14 18:55:29', '2026-07-14 18:55:29'),
(123, '12324MN-000085', 5, '2-1', '2026-07-14 18:55:29', '2026-07-14 18:55:29'),
(124, '12324MN-000086', 5, '3-1', '2026-07-14 18:55:29', '2026-07-14 18:55:29'),
(125, '12324MN-000087', 5, '4-1', '2026-07-14 18:55:29', '2026-07-14 18:55:29'),
(126, '12324MN-000088', 5, '1-1', '2026-07-14 18:55:29', '2026-07-14 18:55:29'),
(127, '12324MN-000089', 5, '2-1', '2026-07-14 18:55:29', '2026-07-14 18:55:29'),
(128, '12324MN-000090', 5, '3-1', '2026-07-14 18:55:29', '2026-07-14 18:55:29'),
(129, '12324MN-000091', 5, '4-1', '2026-07-14 18:55:29', '2026-07-14 18:55:29'),
(130, '12324MN-000092', 5, '1-1', '2026-07-14 18:55:29', '2026-07-14 18:55:29'),
(131, '12324MN-000093', 5, '2-1', '2026-07-14 18:55:29', '2026-07-14 18:55:29'),
(132, '12324MN-000094', 5, '3-1', '2026-07-14 18:55:30', '2026-07-14 18:55:30'),
(133, '12324MN-000095', 5, '4-1', '2026-07-14 18:55:30', '2026-07-14 18:55:30'),
(134, '12324MN-000096', 5, '1-1', '2026-07-14 18:55:30', '2026-07-14 18:55:30'),
(135, '12324MN-000097', 5, '2-1', '2026-07-14 18:55:30', '2026-07-14 18:55:30'),
(136, '12324MN-000098', 5, '3-1', '2026-07-14 18:55:30', '2026-07-14 18:55:30'),
(137, '12324MN-000099', 5, '4-1', '2026-07-14 18:55:30', '2026-07-14 18:55:30'),
(138, '12324MN-000100', 5, '1-1', '2026-07-14 18:55:30', '2026-07-14 18:55:30'),
(139, '12324MN-000101', 5, '2-1', '2026-07-14 18:55:30', '2026-07-14 18:55:30'),
(140, '12324MN-000102', 5, '3-1', '2026-07-14 18:55:30', '2026-07-14 18:55:30'),
(141, '12324MN-000103', 5, '4-1', '2026-07-14 18:55:30', '2026-07-14 18:55:30'),
(142, '12324MN-000104', 5, '1-1', '2026-07-14 18:55:30', '2026-07-14 18:55:30'),
(143, '12324MN-000105', 5, '2-1', '2026-07-14 18:55:30', '2026-07-14 18:55:30'),
(144, '12324MN-000106', 5, '3-1', '2026-07-14 18:55:30', '2026-07-14 18:55:30'),
(145, '12324MN-000107', 5, '4-1', '2026-07-14 18:55:30', '2026-07-14 18:55:30'),
(146, '12324MN-000108', 5, '1-1', '2026-07-14 18:55:30', '2026-07-14 18:55:30'),
(147, '12324MN-000109', 5, '2-1', '2026-07-14 18:55:30', '2026-07-14 18:55:30'),
(148, '12324MN-000110', 5, '3-1', '2026-07-14 18:55:30', '2026-07-14 18:55:30'),
(149, '12324MN-000111', 5, '4-1', '2026-07-14 18:55:30', '2026-07-14 18:55:30'),
(150, '12324MN-000112', 5, '1-1', '2026-07-14 18:55:30', '2026-07-14 18:55:30'),
(151, '12324MN-000113', 5, '2-1', '2026-07-14 18:55:31', '2026-07-14 18:55:31');

-- --------------------------------------------------------

--
-- Table structure for table `subjects`
--

CREATE TABLE `subjects` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `department_id` bigint(20) UNSIGNED NOT NULL,
  `subject_code` varchar(50) NOT NULL,
  `subject_name` varchar(150) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `subjects`
--

INSERT INTO `subjects` (`id`, `department_id`, `subject_code`, `subject_name`, `created_at`) VALUES
(1, 3, 'AIS101', 'Introduction to Accounting Information Systems', '2026-07-14 18:55:57'),
(2, 3, 'ACC102', 'Financial Accounting and Reporting', '2026-07-14 18:55:57'),
(3, 3, 'IT103', 'Database Management Systems', '2026-07-14 18:55:57'),
(4, 3, 'IT101', 'Introduction to Computing', '2026-07-14 18:55:57'),
(5, 3, 'IT102', 'Computer Programming 1', '2026-07-14 18:55:57'),
(6, 3, 'IT201', 'Networking Fundamentals', '2026-07-14 18:55:57'),
(7, 2, 'AVT101', 'Introduction to Aviation Tourism', '2026-07-14 18:55:57'),
(8, 2, 'THC102', 'Tourism and Hospitality Management', '2026-07-14 18:55:57'),
(9, 2, 'AVT201', 'Airline Operations and Ticketing', '2026-07-14 18:55:57'),
(10, 2, 'AVC101', 'Fundamentals of Aviation Communication', '2026-07-14 18:55:57'),
(11, 2, 'ENG102', 'Technical Writing and Communication', '2026-07-14 18:55:57'),
(12, 2, 'AVC201', 'Air Traffic Communication Procedures', '2026-07-14 18:55:57'),
(13, 1, 'AET101', 'Basic Electronics', '2026-07-14 18:55:57'),
(14, 1, 'AET102', 'Aircraft Electrical Systems', '2026-07-14 18:55:57'),
(15, 1, 'AET201', 'Avionics Systems', '2026-07-14 18:55:57'),
(16, 1, 'AMT101', 'Aircraft Structures and Materials', '2026-07-14 18:55:57'),
(17, 1, 'AMT102', 'Aircraft Powerplant Systems', '2026-07-14 18:55:57'),
(18, 1, 'AMT201', 'Aircraft Maintenance Practices', '2026-07-14 18:55:57');

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` longtext DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`id`, `setting_key`, `setting_value`, `updated_at`) VALUES
(1, 'systemName', 'NAAP Evaluation System', '2026-07-14 18:54:31'),
(2, 'academicYear', '2025-2026', '2026-07-14 18:54:31'),
(3, 'evaluationPeriodOpen', 'false', '2026-07-14 18:54:31'),
(4, 'credentialDistributorConfig', '{\"senderEmail\":\"\",\"senderName\":\"NAAP Evaluation System\",\"appPassword\":\"\"}', '2026-07-14 18:54:31'),
(5, 'userProfileDataMigrationV2', 'done', '2026-07-14 18:54:41'),
(6, 'profileImageFilesystemMigrationV1', 'done', '2026-07-14 18:54:41'),
(7, 'loginSecurityState', '[]', '2026-07-14 18:54:41'),
(8, 'sharedCampusData', '[{\"id\":\"all\",\"name\":\"All Campuses\",\"departments\":[]},{\"id\":\"mactan\",\"name\":\"Mactan Campus\",\"departments\":[\"inet\"]},{\"id\":\"villamor\",\"name\":\"Villamor Campus\",\"departments\":[\"ics\",\"ilas\"]}]', '2026-07-14 18:54:42'),
(9, 'currentSemester', '1st-semester-2026-2027', '2026-07-14 18:59:37'),
(10, 'questionnairesBySemester', '{\"2nd-semester-2025-2026\":{\"student-to-professor\":{\"header\":{\"title\":\"Student Evaluation Form\",\"description\":\"Questionnaire for students evaluating professors.\"},\"sections\":[{\"id\":4,\"letter\":\"A\",\"title\":\"Teaching Effectiveness\",\"description\":\"Instructional delivery and preparation.\",\"order\":1},{\"id\":3,\"letter\":\"B\",\"title\":\"Classroom Environment\",\"description\":\"Respect and learning climate.\",\"order\":2}],\"questions\":[{\"id\":12,\"text\":\"The professor explains concepts clearly.\",\"type\":\"rating\",\"required\":true,\"exceptionReporting\":false,\"sectionId\":4,\"order\":1,\"ratingMax\":5,\"ratingScale\":\"1-5\"},{\"id\":11,\"text\":\"The professor comes to class prepared.\",\"type\":\"rating\",\"required\":true,\"exceptionReporting\":false,\"sectionId\":4,\"order\":2,\"ratingMax\":5,\"ratingScale\":\"1-5\"},{\"id\":10,\"text\":\"The professor treats students with respect.\",\"type\":\"rating\",\"required\":true,\"exceptionReporting\":false,\"sectionId\":3,\"order\":3,\"ratingMax\":5,\"ratingScale\":\"1-5\"},{\"id\":3,\"text\":\"What should the professor continue doing?\",\"type\":\"qualitative\",\"required\":false,\"exceptionReporting\":false,\"sectionId\":3,\"order\":4,\"maxLength\":500}]},\"professor-to-professor\":{\"header\":{\"title\":\"Peer Evaluation Form\",\"description\":\"Questionnaire for professors evaluating fellow professors.\"},\"sections\":[{\"id\":2,\"letter\":\"A\",\"title\":\"Collegiality\",\"description\":\"Collaboration and teamwork.\",\"order\":1},{\"id\":1,\"letter\":\"B\",\"title\":\"Professional Contribution\",\"description\":\"Professional conduct and support.\",\"order\":2}],\"questions\":[{\"id\":9,\"text\":\"This professor collaborates well with colleagues.\",\"type\":\"rating\",\"required\":true,\"exceptionReporting\":false,\"sectionId\":2,\"order\":1,\"ratingMax\":5,\"ratingScale\":\"1-5\"},{\"id\":8,\"text\":\"This professor shares resources and best practices.\",\"type\":\"rating\",\"required\":true,\"exceptionReporting\":false,\"sectionId\":2,\"order\":2,\"ratingMax\":5,\"ratingScale\":\"1-5\"},{\"id\":7,\"text\":\"This professor demonstrates professionalism in the department.\",\"type\":\"rating\",\"required\":true,\"exceptionReporting\":false,\"sectionId\":1,\"order\":3,\"ratingMax\":5,\"ratingScale\":\"1-5\"},{\"id\":2,\"text\":\"What peer contribution stands out the most?\",\"type\":\"qualitative\",\"required\":false,\"exceptionReporting\":false,\"sectionId\":1,\"order\":4,\"maxLength\":500}]},\"supervisor-to-professor\":{\"header\":{\"title\":\"Supervisor Evaluation Form\",\"description\":\"Questionnaire for academic supervisors evaluating professors.\"},\"sections\":[{\"id\":6,\"letter\":\"A\",\"title\":\"Instructional Leadership\",\"description\":\"Planning and academic alignment.\",\"order\":1},{\"id\":5,\"letter\":\"B\",\"title\":\"Compliance and Development\",\"description\":\"Reports, initiatives, and growth.\",\"order\":2}],\"questions\":[{\"id\":6,\"text\":\"This professor submits required reports on time.\",\"type\":\"rating\",\"required\":true,\"exceptionReporting\":false,\"sectionId\":6,\"order\":1,\"ratingMax\":5,\"ratingScale\":\"1-5\"},{\"id\":5,\"text\":\"This professor aligns instruction with program outcomes.\",\"type\":\"rating\",\"required\":true,\"exceptionReporting\":false,\"sectionId\":6,\"order\":2,\"ratingMax\":5,\"ratingScale\":\"1-5\"},{\"id\":4,\"text\":\"This professor shows initiative in academic improvement.\",\"type\":\"rating\",\"required\":true,\"exceptionReporting\":false,\"sectionId\":5,\"order\":3,\"ratingMax\":5,\"ratingScale\":\"1-5\"},{\"id\":1,\"text\":\"What development area should be prioritized?\",\"type\":\"qualitative\",\"required\":false,\"exceptionReporting\":false,\"sectionId\":5,\"order\":4,\"maxLength\":500}]}}}', '2026-07-14 18:54:42'),
(11, 'sharedAnnouncements', '[]', '2026-07-14 18:54:42'),
(12, 'sharedEvalPeriods', '{\"student-professor\":{\"start\":\"2026-02-01\",\"end\":\"2026-07-14\"},\"professor-professor\":{\"start\":\"2026-02-08\",\"end\":\"2026-07-14\"},\"supervisor-professor\":{\"start\":\"2026-02-10\",\"end\":\"2026-07-14\"}}', '2026-07-14 19:07:53'),
(13, 'sharedSemesterList', '[{\"value\":\"2nd-semester-2025-2026\",\"label\":\"2nd Semester 2025-2026\"},{\"value\":\"1st-semester-2026-2027\",\"label\":\"1st Semester 2026-2027\"}]', '2026-07-14 18:59:27');
INSERT INTO `system_settings` (`id`, `setting_key`, `setting_value`, `updated_at`) VALUES
(15, 'sharedUsersData', '[{\"id\":\"u8\",\"name\":\"Aaron D Aquino\",\"email\":\"dean.ilas.03@naap.edu.ph\",\"role\":\"dean\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"DEAN-2026-003\",\"employmentType\":\"Regular\",\"position\":\"Dean\",\"yearSection\":\"\",\"studentNumber\":\"\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"\",\"programName\":\"\",\"status\":\"active\"},{\"id\":\"u85\",\"name\":\"Aaron Dela Ignacio\",\"email\":\"student.047@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ics\",\"institute\":\"ics\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"4-1\",\"studentNumber\":\"12324MN-000047\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAIS\",\"programName\":\"Bachelor of Science in Accounting Information Systems\",\"status\":\"active\"},{\"id\":\"u86\",\"name\":\"Abigail Lou Ortega\",\"email\":\"student.048@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"4-1\",\"studentNumber\":\"12324MN-000048\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVTour\",\"programName\":\"Bachelor of Science in Aviation Tourism\",\"status\":\"active\"},{\"id\":\"u1\",\"name\":\"Admin\",\"email\":\"admin@naap.edu.ph\",\"role\":\"admin\",\"campus\":\"villamor\",\"department\":\"\",\"institute\":\"\",\"employeeId\":\"admin\",\"employmentType\":\"Regular\",\"position\":\"System Administrator\",\"yearSection\":\"\",\"studentNumber\":\"\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"\",\"programName\":\"\",\"status\":\"active\"},{\"id\":\"u2\",\"name\":\"admin.alexis.\",\"email\":\"admin.alexis.navarro@naap.edu.ph\",\"role\":\"admin\",\"campus\":\"villamor\",\"department\":\"ics\",\"institute\":\"ics\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"\",\"studentNumber\":\"\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"\",\"programName\":\"\",\"status\":\"active\"},{\"id\":\"u87\",\"name\":\"Adrian Skye Soriano\",\"email\":\"student.049@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"1-1\",\"studentNumber\":\"12324MN-000049\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVTour\",\"programName\":\"Bachelor of Science in Aviation Tourism\",\"status\":\"active\"},{\"id\":\"u88\",\"name\":\"Aira E Bernardo\",\"email\":\"student.050@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ics\",\"institute\":\"ics\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"1-1\",\"studentNumber\":\"12324MN-000050\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSIT\",\"programName\":\"Bachelor of Science in Information Technology\",\"status\":\"active\"},{\"id\":\"u9\",\"name\":\"Aira Paz Pascual\",\"email\":\"professor.001@naap.edu.ph\",\"role\":\"professor\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"professor\",\"employmentType\":\"Regular\",\"position\":\"Assistant Professor\",\"yearSection\":\"\",\"studentNumber\":\"\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVTour\",\"programName\":\"Bachelor of Science in Aviation Tourism\",\"status\":\"active\"},{\"id\":\"u10\",\"name\":\"Alden B Valencia\",\"email\":\"professor.002@naap.edu.ph\",\"role\":\"professor\",\"campus\":\"villamor\",\"department\":\"ics\",\"institute\":\"ics\",\"employeeId\":\"PRF-2026-002\",\"employmentType\":\"Regular\",\"position\":\"Associate Professor\",\"yearSection\":\"\",\"studentNumber\":\"\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAIS\",\"programName\":\"Bachelor of Science in Accounting Information Systems\",\"status\":\"active\"},{\"id\":\"u89\",\"name\":\"Alden Belle Estrada\",\"email\":\"student.051@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"2-1\",\"studentNumber\":\"12324MN-000051\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u30\",\"name\":\"Alden Mae Perez\",\"email\":\"professor.022@naap.edu.ph\",\"role\":\"professor\",\"campus\":\"villamor\",\"department\":\"ics\",\"institute\":\"ics\",\"employeeId\":\"PRF-2026-022\",\"employmentType\":\"Temporary\",\"position\":\"Associate Professor\",\"yearSection\":\"\",\"studentNumber\":\"\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAIS\",\"programName\":\"Bachelor of Science in Accounting Information Systems\",\"status\":\"active\"},{\"id\":\"u11\",\"name\":\"Alexa I Cabrera\",\"email\":\"professor.003@naap.edu.ph\",\"role\":\"professor\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"PRF-2026-003\",\"employmentType\":\"Temporary\",\"position\":\"Instructor I\",\"yearSection\":\"\",\"studentNumber\":\"\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u90\",\"name\":\"Alexa Jane Magtibay\",\"email\":\"student.052@naap.edu.ph\",\"role\":\"student\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"2-1\",\"studentNumber\":\"12324MN-000052\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAET\",\"programName\":\"Bachelor of Science in Aviation Electronics Technology\",\"status\":\"active\"},{\"id\":\"u31\",\"name\":\"Alexa Troy Abad\",\"email\":\"professor.023@naap.edu.ph\",\"role\":\"professor\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"PRF-2026-023\",\"employmentType\":\"Regular\",\"position\":\"Instructor I\",\"yearSection\":\"\",\"studentNumber\":\"\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVTour\",\"programName\":\"Bachelor of Science in Aviation Tourism\",\"status\":\"active\"},{\"id\":\"u32\",\"name\":\"Allen F Calderon\",\"email\":\"professor.024@naap.edu.ph\",\"role\":\"professor\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"PRF-2026-024\",\"employmentType\":\"Temporary\",\"position\":\"Instructor II\",\"yearSection\":\"\",\"studentNumber\":\"\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAET\",\"programName\":\"Bachelor of Science in Aviation Electronics Technology\",\"status\":\"active\"},{\"id\":\"u12\",\"name\":\"Allen Grace Francisco\",\"email\":\"professor.004@naap.edu.ph\",\"role\":\"professor\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"PRF-2026-004\",\"employmentType\":\"Regular\",\"position\":\"Assistant Professor\",\"yearSection\":\"\",\"studentNumber\":\"\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAET\",\"programName\":\"Bachelor of Science in Aviation Electronics Technology\",\"status\":\"active\"},{\"id\":\"u91\",\"name\":\"Allen Quinn Ramos\",\"email\":\"student.053@naap.edu.ph\",\"role\":\"student\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"3-1\",\"studentNumber\":\"12324MN-000053\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAMT\",\"programName\":\"Bachelor of Science in Aviation Maintenance Technology\",\"status\":\"active\"},{\"id\":\"u7\",\"name\":\"Alvin Jay Reyes\",\"email\":\"dean.ics.02@naap.edu.ph\",\"role\":\"dean\",\"campus\":\"villamor\",\"department\":\"ics\",\"institute\":\"ics\",\"employeeId\":\"DEAN-2026-002\",\"employmentType\":\"Regular\",\"position\":\"Dean\",\"yearSection\":\"\",\"studentNumber\":\"\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"\",\"programName\":\"\",\"status\":\"active\"},{\"id\":\"u92\",\"name\":\"Amara C Alcantara\",\"email\":\"student.054@naap.edu.ph\",\"role\":\"student\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"3-1\",\"studentNumber\":\"12324MN-000054\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAET\",\"programName\":\"Bachelor of Science in Aviation Electronics Technology\",\"status\":\"active\"},{\"id\":\"u33\",\"name\":\"Amara Cruz Garcia\",\"email\":\"professor.025@naap.edu.ph\",\"role\":\"professor\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"PRF-2026-025\",\"employmentType\":\"Regular\",\"position\":\"Assistant Professor\",\"yearSection\":\"\",\"studentNumber\":\"\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAMT\",\"programName\":\"Bachelor of Science in Aviation Maintenance Technology\",\"status\":\"active\"},{\"id\":\"u13\",\"name\":\"Amara Noel Mendoza\",\"email\":\"professor.005@naap.edu.ph\",\"role\":\"professor\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"PRF-2026-005\",\"employmentType\":\"Regular\",\"position\":\"Associate Professor\",\"yearSection\":\"\",\"studentNumber\":\"\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAET\",\"programName\":\"Bachelor of Science in Aviation Electronics Technology\",\"status\":\"active\"},{\"id\":\"u93\",\"name\":\"Angelo J Castro\",\"email\":\"student.055@naap.edu.ph\",\"role\":\"student\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"4-1\",\"studentNumber\":\"12324MN-000055\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAET\",\"programName\":\"Bachelor of Science in Aviation Electronics Technology\",\"status\":\"active\"},{\"id\":\"u34\",\"name\":\"Angelo Kate Miranda\",\"email\":\"professor.026@naap.edu.ph\",\"role\":\"professor\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"PRF-2026-026\",\"employmentType\":\"Temporary\",\"position\":\"Associate Professor\",\"yearSection\":\"\",\"studentNumber\":\"\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVTour\",\"programName\":\"Bachelor of Science in Aviation Tourism\",\"status\":\"active\"},{\"id\":\"u14\",\"name\":\"Angelo Uma Rivera\",\"email\":\"professor.006@naap.edu.ph\",\"role\":\"professor\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"PRF-2026-006\",\"employmentType\":\"Temporary\",\"position\":\"Instructor I\",\"yearSection\":\"\",\"studentNumber\":\"\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAET\",\"programName\":\"Bachelor of Science in Aviation Electronics Technology\",\"status\":\"active\"},{\"id\":\"u15\",\"name\":\"Anika G Andrada\",\"email\":\"professor.007@naap.edu.ph\",\"role\":\"professor\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"PRF-2026-007\",\"employmentType\":\"Regular\",\"position\":\"Assistant Professor\",\"yearSection\":\"\",\"studentNumber\":\"\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAET\",\"programName\":\"Bachelor of Science in Aviation Electronics Technology\",\"status\":\"active\"},{\"id\":\"u94\",\"name\":\"Anika Hope Hernandez\",\"email\":\"student.056@naap.edu.ph\",\"role\":\"student\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"4-1\",\"studentNumber\":\"12324MN-000056\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAET\",\"programName\":\"Bachelor of Science in Aviation Electronics Technology\",\"status\":\"active\"},{\"id\":\"u35\",\"name\":\"Anika Rain Robles\",\"email\":\"professor.027@naap.edu.ph\",\"role\":\"professor\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"PRF-2026-027\",\"employmentType\":\"Regular\",\"position\":\"Instructor I\",\"yearSection\":\"\",\"studentNumber\":\"\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVTour\",\"programName\":\"Bachelor of Science in Aviation Tourism\",\"status\":\"active\"},{\"id\":\"u36\",\"name\":\"Arielle D Aquino\",\"email\":\"professor.028@naap.edu.ph\",\"role\":\"professor\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"PRF-2026-028\",\"employmentType\":\"Temporary\",\"position\":\"Instructor II\",\"yearSection\":\"\",\"studentNumber\":\"\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAET\",\"programName\":\"Bachelor of Science in Aviation Electronics Technology\",\"status\":\"active\"},{\"id\":\"u16\",\"name\":\"Arielle Dela Cruz\",\"email\":\"professor.008@naap.edu.ph\",\"role\":\"professor\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"PRF-2026-008\",\"employmentType\":\"Regular\",\"position\":\"Associate Professor\",\"yearSection\":\"\",\"studentNumber\":\"\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAET\",\"programName\":\"Bachelor of Science in Aviation Electronics Technology\",\"status\":\"active\"},{\"id\":\"u95\",\"name\":\"Arielle Owen Natividad\",\"email\":\"student.057@naap.edu.ph\",\"role\":\"student\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"1-1\",\"studentNumber\":\"12324MN-000057\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAET\",\"programName\":\"Bachelor of Science in Aviation Electronics Technology\",\"status\":\"active\"},{\"id\":\"u96\",\"name\":\"Bea A Santos\",\"email\":\"student.058@naap.edu.ph\",\"role\":\"student\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"1-1\",\"studentNumber\":\"12324MN-000058\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAET\",\"programName\":\"Bachelor of Science in Aviation Electronics Technology\",\"status\":\"active\"},{\"id\":\"u37\",\"name\":\"Bea Anne Dela Cruz\",\"email\":\"professor.029@naap.edu.ph\",\"role\":\"professor\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"PRF-2026-029\",\"employmentType\":\"Regular\",\"position\":\"Assistant Professor\",\"yearSection\":\"\",\"studentNumber\":\"\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAET\",\"programName\":\"Bachelor of Science in Aviation Electronics Technology\",\"status\":\"active\"},{\"id\":\"u17\",\"name\":\"Bea Lou Jimenez\",\"email\":\"professor.009@naap.edu.ph\",\"role\":\"professor\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"PRF-2026-009\",\"employmentType\":\"Temporary\",\"position\":\"Instructor I\",\"yearSection\":\"\",\"studentNumber\":\"\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAET\",\"programName\":\"Bachelor of Science in Aviation Electronics Technology\",\"status\":\"active\"},{\"id\":\"u97\",\"name\":\"Benj H Bautista\",\"email\":\"student.059@naap.edu.ph\",\"role\":\"student\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"2-1\",\"studentNumber\":\"12324MN-000059\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAMT\",\"programName\":\"Bachelor of Science in Aviation Maintenance Technology\",\"status\":\"active\"},{\"id\":\"u38\",\"name\":\"Benj Iris Lacsamana\",\"email\":\"professor.030@naap.edu.ph\",\"role\":\"professor\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"PRF-2026-030\",\"employmentType\":\"Temporary\",\"position\":\"Associate Professor\",\"yearSection\":\"\",\"studentNumber\":\"\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAET\",\"programName\":\"Bachelor of Science in Aviation Electronics Technology\",\"status\":\"active\"},{\"id\":\"u18\",\"name\":\"Benj Skye Panganiban\",\"email\":\"professor.010@naap.edu.ph\",\"role\":\"professor\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"PRF-2026-010\",\"employmentType\":\"Regular\",\"position\":\"Assistant Professor\",\"yearSection\":\"\",\"studentNumber\":\"\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAET\",\"programName\":\"Bachelor of Science in Aviation Electronics Technology\",\"status\":\"active\"},{\"id\":\"u19\",\"name\":\"Bianca E Torres\",\"email\":\"professor.011@naap.edu.ph\",\"role\":\"professor\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"PRF-2026-011\",\"employmentType\":\"Regular\",\"position\":\"Associate Professor\",\"yearSection\":\"\",\"studentNumber\":\"\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAET\",\"programName\":\"Bachelor of Science in Aviation Electronics Technology\",\"status\":\"active\"},{\"id\":\"u98\",\"name\":\"Bianca Faith Enriquez\",\"email\":\"student.060@naap.edu.ph\",\"role\":\"student\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"2-1\",\"studentNumber\":\"12324MN-000060\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAET\",\"programName\":\"Bachelor of Science in Aviation Electronics Technology\",\"status\":\"active\"},{\"id\":\"u20\",\"name\":\"Bryan Belle Bonifacio\",\"email\":\"professor.012@naap.edu.ph\",\"role\":\"professor\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"PRF-2026-012\",\"employmentType\":\"Temporary\",\"position\":\"Instructor I\",\"yearSection\":\"\",\"studentNumber\":\"\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAET\",\"programName\":\"Bachelor of Science in Aviation Electronics Technology\",\"status\":\"active\"},{\"id\":\"u99\",\"name\":\"Bryan Mae Mabini\",\"email\":\"student.061@naap.edu.ph\",\"role\":\"student\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"3-1\",\"studentNumber\":\"12324MN-000061\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAET\",\"programName\":\"Bachelor of Science in Aviation Electronics Technology\",\"status\":\"active\"},{\"id\":\"u21\",\"name\":\"Camille Jane Flores\",\"email\":\"professor.013@naap.edu.ph\",\"role\":\"professor\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"PRF-2026-013\",\"employmentType\":\"Regular\",\"position\":\"Assistant Professor\",\"yearSection\":\"\",\"studentNumber\":\"\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAET\",\"programName\":\"Bachelor of Science in Aviation Electronics Technology\",\"status\":\"active\"},{\"id\":\"u100\",\"name\":\"Camille Troy Quintos\",\"email\":\"student.062@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ics\",\"institute\":\"ics\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"3-1\",\"studentNumber\":\"12324MN-000062\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSIT\",\"programName\":\"Bachelor of Science in Information Technology\",\"status\":\"active\"},{\"id\":\"u101\",\"name\":\"Carlo F Aguilar\",\"email\":\"student.063@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ics\",\"institute\":\"ics\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"4-1\",\"studentNumber\":\"12324MN-000063\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAIS\",\"programName\":\"Bachelor of Science in Accounting Information Systems\",\"status\":\"active\"},{\"id\":\"u22\",\"name\":\"Carlo Quinn Manalo\",\"email\":\"professor.014@naap.edu.ph\",\"role\":\"professor\",\"campus\":\"villamor\",\"department\":\"ics\",\"institute\":\"ics\",\"employeeId\":\"PRF-2026-014\",\"employmentType\":\"Regular\",\"position\":\"Associate Professor\",\"yearSection\":\"\",\"studentNumber\":\"\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSIT\",\"programName\":\"Bachelor of Science in Information Technology\",\"status\":\"active\"},{\"id\":\"u23\",\"name\":\"Carmela C Reyes\",\"email\":\"professor.015@naap.edu.ph\",\"role\":\"professor\",\"campus\":\"villamor\",\"department\":\"ics\",\"institute\":\"ics\",\"employeeId\":\"PRF-2026-015\",\"employmentType\":\"Temporary\",\"position\":\"Instructor I\",\"yearSection\":\"\",\"studentNumber\":\"\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAIS\",\"programName\":\"Bachelor of Science in Accounting Information Systems\",\"status\":\"active\"},{\"id\":\"u24\",\"name\":\"Cedric J Alonzo\",\"email\":\"professor.016@naap.edu.ph\",\"role\":\"professor\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"PRF-2026-016\",\"employmentType\":\"Regular\",\"position\":\"Assistant Professor\",\"yearSection\":\"\",\"studentNumber\":\"\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVTour\",\"programName\":\"Bachelor of Science in Aviation Tourism\",\"status\":\"active\"},{\"id\":\"u25\",\"name\":\"Chloe Hope Cortez\",\"email\":\"professor.017@naap.edu.ph\",\"role\":\"professor\",\"campus\":\"villamor\",\"department\":\"ics\",\"institute\":\"ics\",\"employeeId\":\"PRF-2026-017\",\"employmentType\":\"Regular\",\"position\":\"Associate Professor\",\"yearSection\":\"\",\"studentNumber\":\"\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSIT\",\"programName\":\"Bachelor of Science in Information Technology\",\"status\":\"active\"},{\"id\":\"u26\",\"name\":\"Daniel Owen Ignacio\",\"email\":\"professor.018@naap.edu.ph\",\"role\":\"professor\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"PRF-2026-018\",\"employmentType\":\"Temporary\",\"position\":\"Instructor I\",\"yearSection\":\"\",\"studentNumber\":\"\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAMT\",\"programName\":\"Bachelor of Science in Aviation Maintenance Technology\",\"status\":\"active\"},{\"id\":\"u27\",\"name\":\"Daphne A Ortega\",\"email\":\"professor.019@naap.edu.ph\",\"role\":\"professor\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"PRF-2026-019\",\"employmentType\":\"Regular\",\"position\":\"Assistant Professor\",\"yearSection\":\"\",\"studentNumber\":\"\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAET\",\"programName\":\"Bachelor of Science in Aviation Electronics Technology\",\"status\":\"active\"},{\"id\":\"u28\",\"name\":\"Daryl H Soriano\",\"email\":\"professor.020@naap.edu.ph\",\"role\":\"professor\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"PRF-2026-020\",\"employmentType\":\"Regular\",\"position\":\"Associate Professor\",\"yearSection\":\"\",\"studentNumber\":\"\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAET\",\"programName\":\"Bachelor of Science in Aviation Electronics Technology\",\"status\":\"active\"},{\"id\":\"u29\",\"name\":\"Dianne Faith Bernardo\",\"email\":\"professor.021@naap.edu.ph\",\"role\":\"professor\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"PRF-2026-021\",\"employmentType\":\"Temporary\",\"position\":\"Instructor I\",\"yearSection\":\"\",\"studentNumber\":\"\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAET\",\"programName\":\"Bachelor of Science in Aviation Electronics Technology\",\"status\":\"active\"},{\"id\":\"u39\",\"name\":\"Elijah Mae Estrada\",\"email\":\"student.001@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"1-1\",\"studentNumber\":\"student\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u40\",\"name\":\"Ella Troy Magtibay\",\"email\":\"student.002@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ics\",\"institute\":\"ics\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"1-1\",\"studentNumber\":\"12324MN-000002\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAIS\",\"programName\":\"Bachelor of Science in Accounting Information Systems\",\"status\":\"active\"},{\"id\":\"u41\",\"name\":\"Ethan F Ramos\",\"email\":\"student.003@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"2-1\",\"studentNumber\":\"12324MN-000003\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVTour\",\"programName\":\"Bachelor of Science in Aviation Tourism\",\"status\":\"active\"},{\"id\":\"u42\",\"name\":\"Faye Cruz Alcantara\",\"email\":\"student.004@naap.edu.ph\",\"role\":\"student\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"2-1\",\"studentNumber\":\"12324MN-000004\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAMT\",\"programName\":\"Bachelor of Science in Aviation Maintenance Technology\",\"status\":\"active\"},{\"id\":\"u43\",\"name\":\"Gabriel Kate Castro\",\"email\":\"student.005@naap.edu.ph\",\"role\":\"student\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"3-1\",\"studentNumber\":\"12324MN-000005\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAET\",\"programName\":\"Bachelor of Science in Aviation Electronics Technology\",\"status\":\"active\"},{\"id\":\"u44\",\"name\":\"Giselle Rain Hernandez\",\"email\":\"student.006@naap.edu.ph\",\"role\":\"student\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"3-1\",\"studentNumber\":\"12324MN-000006\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAMT\",\"programName\":\"Bachelor of Science in Aviation Maintenance Technology\",\"status\":\"active\"},{\"id\":\"u45\",\"name\":\"Hannah D Natividad\",\"email\":\"student.007@naap.edu.ph\",\"role\":\"student\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"4-1\",\"studentNumber\":\"12324MN-000007\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAET\",\"programName\":\"Bachelor of Science in Aviation Electronics Technology\",\"status\":\"active\"},{\"id\":\"u46\",\"name\":\"Harvey Anne Santos\",\"email\":\"student.008@naap.edu.ph\",\"role\":\"student\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"4-1\",\"studentNumber\":\"12324MN-000008\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAET\",\"programName\":\"Bachelor of Science in Aviation Electronics Technology\",\"status\":\"active\"},{\"id\":\"u5\",\"name\":\"Helena M. Cruz\",\"email\":\"hr.helena.cruz@naap.edu.ph\",\"role\":\"hr\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"hr\",\"employmentType\":\"Regular\",\"position\":\"HR Manager\",\"yearSection\":\"\",\"studentNumber\":\"\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"\",\"programName\":\"\",\"status\":\"active\"},{\"id\":\"u47\",\"name\":\"Ian Iris Bautista\",\"email\":\"student.009@naap.edu.ph\",\"role\":\"student\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"1-1\",\"studentNumber\":\"12324MN-000009\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAET\",\"programName\":\"Bachelor of Science in Aviation Electronics Technology\",\"status\":\"active\"},{\"id\":\"u48\",\"name\":\"Isabel Paz Enriquez\",\"email\":\"student.010@naap.edu.ph\",\"role\":\"student\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"1-1\",\"studentNumber\":\"12324MN-000010\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAMT\",\"programName\":\"Bachelor of Science in Aviation Maintenance Technology\",\"status\":\"active\"},{\"id\":\"u49\",\"name\":\"Jared B Mabini\",\"email\":\"student.011@naap.edu.ph\",\"role\":\"student\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"2-1\",\"studentNumber\":\"12324MN-000011\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAET\",\"programName\":\"Bachelor of Science in Aviation Electronics Technology\",\"status\":\"active\"},{\"id\":\"u50\",\"name\":\"Jasmine I Quintos\",\"email\":\"student.012@naap.edu.ph\",\"role\":\"student\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"2-1\",\"studentNumber\":\"12324MN-000012\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAMT\",\"programName\":\"Bachelor of Science in Aviation Maintenance Technology\",\"status\":\"active\"},{\"id\":\"u51\",\"name\":\"Jericho Grace Aguilar\",\"email\":\"student.013@naap.edu.ph\",\"role\":\"student\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"3-1\",\"studentNumber\":\"12324MN-000013\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAET\",\"programName\":\"Bachelor of Science in Aviation Electronics Technology\",\"status\":\"active\"},{\"id\":\"u52\",\"name\":\"Joanna Noel Castillo\",\"email\":\"student.014@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ics\",\"institute\":\"ics\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"3-1\",\"studentNumber\":\"12324MN-000014\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSIT\",\"programName\":\"Bachelor of Science in Information Technology\",\"status\":\"active\"},{\"id\":\"u53\",\"name\":\"Joshua Uma Gonzales\",\"email\":\"student.015@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ics\",\"institute\":\"ics\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"4-1\",\"studentNumber\":\"12324MN-000015\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAIS\",\"programName\":\"Bachelor of Science in Accounting Information Systems\",\"status\":\"active\"},{\"id\":\"u54\",\"name\":\"Justine G Navarro\",\"email\":\"student.016@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"4-1\",\"studentNumber\":\"12324MN-000016\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVTour\",\"programName\":\"Bachelor of Science in Aviation Tourism\",\"status\":\"active\"},{\"id\":\"u55\",\"name\":\"Karl Dela Salazar\",\"email\":\"student.017@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"1-1\",\"studentNumber\":\"12324MN-000017\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVTour\",\"programName\":\"Bachelor of Science in Aviation Tourism\",\"status\":\"active\"},{\"id\":\"u56\",\"name\":\"Katrina Lou Bacani\",\"email\":\"student.018@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ics\",\"institute\":\"ics\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"1-1\",\"studentNumber\":\"12324MN-000018\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSIT\",\"programName\":\"Bachelor of Science in Information Technology\",\"status\":\"active\"},{\"id\":\"u57\",\"name\":\"Kevin Skye Domingo\",\"email\":\"student.019@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"2-1\",\"studentNumber\":\"12324MN-000019\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u58\",\"name\":\"Kyla E Luna\",\"email\":\"student.020@naap.edu.ph\",\"role\":\"student\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"2-1\",\"studentNumber\":\"12324MN-000020\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAET\",\"programName\":\"Bachelor of Science in Aviation Electronics Technology\",\"status\":\"active\"},{\"id\":\"u59\",\"name\":\"Lance Belle Perez\",\"email\":\"student.021@naap.edu.ph\",\"role\":\"student\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"3-1\",\"studentNumber\":\"12324MN-000021\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAMT\",\"programName\":\"Bachelor of Science in Aviation Maintenance Technology\",\"status\":\"active\"},{\"id\":\"u60\",\"name\":\"Leah Jane Abad\",\"email\":\"student.022@naap.edu.ph\",\"role\":\"student\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"3-1\",\"studentNumber\":\"12324MN-000022\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAET\",\"programName\":\"Bachelor of Science in Aviation Electronics Technology\",\"status\":\"active\"},{\"id\":\"u61\",\"name\":\"Liam Quinn Calderon\",\"email\":\"student.023@naap.edu.ph\",\"role\":\"student\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"4-1\",\"studentNumber\":\"12324MN-000023\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAET\",\"programName\":\"Bachelor of Science in Aviation Electronics Technology\",\"status\":\"active\"},{\"id\":\"u62\",\"name\":\"Liza C Garcia\",\"email\":\"student.024@naap.edu.ph\",\"role\":\"student\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"4-1\",\"studentNumber\":\"12324MN-000024\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAET\",\"programName\":\"Bachelor of Science in Aviation Electronics Technology\",\"status\":\"active\"},{\"id\":\"u63\",\"name\":\"Marco J Miranda\",\"email\":\"student.025@naap.edu.ph\",\"role\":\"student\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"1-1\",\"studentNumber\":\"12324MN-000025\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAET\",\"programName\":\"Bachelor of Science in Aviation Electronics Technology\",\"status\":\"active\"},{\"id\":\"u3\",\"name\":\"Marian P. Salazar\",\"email\":\"jemrojo12@gmail.com\",\"role\":\"osa\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"osa\",\"employmentType\":\"Regular\",\"position\":\"OSA Officer\",\"yearSection\":\"\",\"studentNumber\":\"\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"\",\"programName\":\"\",\"status\":\"active\"},{\"id\":\"u6\",\"name\":\"Mark Antjony C Pascual\",\"email\":\"dean.communication.01@naap.edu.ph\",\"role\":\"dean\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"dean\",\"employmentType\":\"Regular\",\"position\":\"Dean\",\"yearSection\":\"\",\"studentNumber\":\"\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"\",\"programName\":\"\",\"status\":\"active\"},{\"id\":\"u64\",\"name\":\"Mia Hope Robles\",\"email\":\"student.026@naap.edu.ph\",\"role\":\"student\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"1-1\",\"studentNumber\":\"12324MN-000026\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAET\",\"programName\":\"Bachelor of Science in Aviation Electronics Technology\",\"status\":\"active\"},{\"id\":\"u65\",\"name\":\"Miguel Owen Aquino\",\"email\":\"student.027@naap.edu.ph\",\"role\":\"student\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"2-1\",\"studentNumber\":\"12324MN-000027\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAMT\",\"programName\":\"Bachelor of Science in Aviation Maintenance Technology\",\"status\":\"active\"},{\"id\":\"u66\",\"name\":\"Nadine A Dela Cruz\",\"email\":\"student.028@naap.edu.ph\",\"role\":\"student\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"2-1\",\"studentNumber\":\"12324MN-000028\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAET\",\"programName\":\"Bachelor of Science in Aviation Electronics Technology\",\"status\":\"active\"},{\"id\":\"u67\",\"name\":\"Nathan H Lacsamana\",\"email\":\"student.029@naap.edu.ph\",\"role\":\"student\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"3-1\",\"studentNumber\":\"12324MN-000029\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAET\",\"programName\":\"Bachelor of Science in Aviation Electronics Technology\",\"status\":\"active\"},{\"id\":\"u68\",\"name\":\"Nica Faith Pascual\",\"email\":\"student.030@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ics\",\"institute\":\"ics\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"3-1\",\"studentNumber\":\"12324MN-000030\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSIT\",\"programName\":\"Bachelor of Science in Information Technology\",\"status\":\"active\"},{\"id\":\"u69\",\"name\":\"Noel Mae Valencia\",\"email\":\"student.031@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ics\",\"institute\":\"ics\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"4-1\",\"studentNumber\":\"12324MN-000031\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAIS\",\"programName\":\"Bachelor of Science in Accounting Information Systems\",\"status\":\"active\"},{\"id\":\"u70\",\"name\":\"Olivia Troy Cabrera\",\"email\":\"student.032@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"4-1\",\"studentNumber\":\"12324MN-000032\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVTour\",\"programName\":\"Bachelor of Science in Aviation Tourism\",\"status\":\"active\"},{\"id\":\"u71\",\"name\":\"Paolo F Francisco\",\"email\":\"student.033@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"1-1\",\"studentNumber\":\"12324MN-000033\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u72\",\"name\":\"Patricia Cruz Mendoza\",\"email\":\"student.034@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ics\",\"institute\":\"ics\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"1-1\",\"studentNumber\":\"12324MN-000034\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAIS\",\"programName\":\"Bachelor of Science in Accounting Information Systems\",\"status\":\"active\"},{\"id\":\"u73\",\"name\":\"Rafael Kate Rivera\",\"email\":\"student.035@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"2-1\",\"studentNumber\":\"12324MN-000035\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVTour\",\"programName\":\"Bachelor of Science in Aviation Tourism\",\"status\":\"active\"},{\"id\":\"u74\",\"name\":\"Rica Rain Andrada\",\"email\":\"student.036@naap.edu.ph\",\"role\":\"student\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"2-1\",\"studentNumber\":\"12324MN-000036\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAMT\",\"programName\":\"Bachelor of Science in Aviation Maintenance Technology\",\"status\":\"active\"},{\"id\":\"u75\",\"name\":\"Rico D Cruz\",\"email\":\"student.037@naap.edu.ph\",\"role\":\"student\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"3-1\",\"studentNumber\":\"12324MN-000037\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAET\",\"programName\":\"Bachelor of Science in Aviation Electronics Technology\",\"status\":\"active\"},{\"id\":\"u76\",\"name\":\"Samantha Anne Jimenez\",\"email\":\"student.038@naap.edu.ph\",\"role\":\"student\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"3-1\",\"studentNumber\":\"12324MN-000038\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAMT\",\"programName\":\"Bachelor of Science in Aviation Maintenance Technology\",\"status\":\"active\"},{\"id\":\"u77\",\"name\":\"Sean Iris Panganiban\",\"email\":\"student.039@naap.edu.ph\",\"role\":\"student\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"4-1\",\"studentNumber\":\"12324MN-000039\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAET\",\"programName\":\"Bachelor of Science in Aviation Electronics Technology\",\"status\":\"active\"},{\"id\":\"u78\",\"name\":\"Sophia Paz Torres\",\"email\":\"student.040@naap.edu.ph\",\"role\":\"student\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"4-1\",\"studentNumber\":\"12324MN-000040\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAET\",\"programName\":\"Bachelor of Science in Aviation Electronics Technology\",\"status\":\"active\"},{\"id\":\"u102\",\"name\":\"Student 064\",\"email\":\"student.064@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"1-1\",\"studentNumber\":\"12324MN-000064\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u103\",\"name\":\"Student 065\",\"email\":\"student.065@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"2-1\",\"studentNumber\":\"12324MN-000065\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u104\",\"name\":\"Student 066\",\"email\":\"student.066@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"3-1\",\"studentNumber\":\"12324MN-000066\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u105\",\"name\":\"Student 067\",\"email\":\"student.067@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"4-1\",\"studentNumber\":\"12324MN-000067\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u106\",\"name\":\"Student 068\",\"email\":\"student.068@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"1-1\",\"studentNumber\":\"12324MN-000068\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u107\",\"name\":\"Student 069\",\"email\":\"student.069@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"2-1\",\"studentNumber\":\"12324MN-000069\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u108\",\"name\":\"Student 070\",\"email\":\"student.070@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"3-1\",\"studentNumber\":\"12324MN-000070\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u109\",\"name\":\"Student 071\",\"email\":\"student.071@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"4-1\",\"studentNumber\":\"12324MN-000071\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u110\",\"name\":\"Student 072\",\"email\":\"student.072@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"1-1\",\"studentNumber\":\"12324MN-000072\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u111\",\"name\":\"Student 073\",\"email\":\"student.073@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"2-1\",\"studentNumber\":\"12324MN-000073\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u112\",\"name\":\"Student 074\",\"email\":\"student.074@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"3-1\",\"studentNumber\":\"12324MN-000074\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u113\",\"name\":\"Student 075\",\"email\":\"student.075@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"4-1\",\"studentNumber\":\"12324MN-000075\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u114\",\"name\":\"Student 076\",\"email\":\"student.076@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"1-1\",\"studentNumber\":\"12324MN-000076\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u115\",\"name\":\"Student 077\",\"email\":\"student.077@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"2-1\",\"studentNumber\":\"12324MN-000077\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u116\",\"name\":\"Student 078\",\"email\":\"student.078@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"3-1\",\"studentNumber\":\"12324MN-000078\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u117\",\"name\":\"Student 079\",\"email\":\"student.079@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"4-1\",\"studentNumber\":\"12324MN-000079\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u118\",\"name\":\"Student 080\",\"email\":\"student.080@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"1-1\",\"studentNumber\":\"12324MN-000080\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u119\",\"name\":\"Student 081\",\"email\":\"student.081@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"2-1\",\"studentNumber\":\"12324MN-000081\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u120\",\"name\":\"Student 082\",\"email\":\"student.082@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"3-1\",\"studentNumber\":\"12324MN-000082\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u121\",\"name\":\"Student 083\",\"email\":\"student.083@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"4-1\",\"studentNumber\":\"12324MN-000083\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u122\",\"name\":\"Student 084\",\"email\":\"student.084@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"1-1\",\"studentNumber\":\"12324MN-000084\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u123\",\"name\":\"Student 085\",\"email\":\"student.085@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"2-1\",\"studentNumber\":\"12324MN-000085\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u124\",\"name\":\"Student 086\",\"email\":\"student.086@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"3-1\",\"studentNumber\":\"12324MN-000086\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u125\",\"name\":\"Student 087\",\"email\":\"student.087@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"4-1\",\"studentNumber\":\"12324MN-000087\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u126\",\"name\":\"Student 088\",\"email\":\"student.088@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"1-1\",\"studentNumber\":\"12324MN-000088\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u127\",\"name\":\"Student 089\",\"email\":\"student.089@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"2-1\",\"studentNumber\":\"12324MN-000089\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u128\",\"name\":\"Student 090\",\"email\":\"student.090@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"3-1\",\"studentNumber\":\"12324MN-000090\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u129\",\"name\":\"Student 091\",\"email\":\"student.091@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"4-1\",\"studentNumber\":\"12324MN-000091\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u130\",\"name\":\"Student 092\",\"email\":\"student.092@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"1-1\",\"studentNumber\":\"12324MN-000092\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u131\",\"name\":\"Student 093\",\"email\":\"student.093@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"2-1\",\"studentNumber\":\"12324MN-000093\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u132\",\"name\":\"Student 094\",\"email\":\"student.094@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"3-1\",\"studentNumber\":\"12324MN-000094\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u133\",\"name\":\"Student 095\",\"email\":\"student.095@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"4-1\",\"studentNumber\":\"12324MN-000095\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u134\",\"name\":\"Student 096\",\"email\":\"student.096@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"1-1\",\"studentNumber\":\"12324MN-000096\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u135\",\"name\":\"Student 097\",\"email\":\"student.097@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"2-1\",\"studentNumber\":\"12324MN-000097\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u136\",\"name\":\"Student 098\",\"email\":\"student.098@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"3-1\",\"studentNumber\":\"12324MN-000098\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u137\",\"name\":\"Student 099\",\"email\":\"student.099@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"4-1\",\"studentNumber\":\"12324MN-000099\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u138\",\"name\":\"Student 100\",\"email\":\"student.100@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"1-1\",\"studentNumber\":\"12324MN-000100\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u139\",\"name\":\"Student 101\",\"email\":\"student.101@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"2-1\",\"studentNumber\":\"12324MN-000101\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u140\",\"name\":\"Student 102\",\"email\":\"student.102@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"3-1\",\"studentNumber\":\"12324MN-000102\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u141\",\"name\":\"Student 103\",\"email\":\"student.103@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"4-1\",\"studentNumber\":\"12324MN-000103\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u142\",\"name\":\"Student 104\",\"email\":\"student.104@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"1-1\",\"studentNumber\":\"12324MN-000104\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u143\",\"name\":\"Student 105\",\"email\":\"student.105@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"2-1\",\"studentNumber\":\"12324MN-000105\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u144\",\"name\":\"Student 106\",\"email\":\"student.106@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"3-1\",\"studentNumber\":\"12324MN-000106\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u145\",\"name\":\"Student 107\",\"email\":\"student.107@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"4-1\",\"studentNumber\":\"12324MN-000107\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u146\",\"name\":\"Student 108\",\"email\":\"student.108@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"1-1\",\"studentNumber\":\"12324MN-000108\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u147\",\"name\":\"Student 109\",\"email\":\"student.109@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"2-1\",\"studentNumber\":\"12324MN-000109\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u148\",\"name\":\"Student 110\",\"email\":\"student.110@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"3-1\",\"studentNumber\":\"12324MN-000110\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u149\",\"name\":\"Student 111\",\"email\":\"student.111@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"4-1\",\"studentNumber\":\"12324MN-000111\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u150\",\"name\":\"Student 112\",\"email\":\"student.112@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"1-1\",\"studentNumber\":\"12324MN-000112\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u151\",\"name\":\"Student 113\",\"email\":\"student.113@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ilas\",\"institute\":\"ilas\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"2-1\",\"studentNumber\":\"12324MN-000113\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAVComm\",\"programName\":\"Bachelor of Science in Aviation Communication\",\"status\":\"active\"},{\"id\":\"u79\",\"name\":\"Tristan B Bonifacio\",\"email\":\"student.041@naap.edu.ph\",\"role\":\"student\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"1-1\",\"studentNumber\":\"12324MN-000041\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAET\",\"programName\":\"Bachelor of Science in Aviation Electronics Technology\",\"status\":\"active\"},{\"id\":\"u80\",\"name\":\"Vanessa I Flores\",\"email\":\"student.042@naap.edu.ph\",\"role\":\"student\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"1-1\",\"studentNumber\":\"12324MN-000042\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAMT\",\"programName\":\"Bachelor of Science in Aviation Maintenance Technology\",\"status\":\"active\"},{\"id\":\"u4\",\"name\":\"Victor A. Pineda\",\"email\":\"vpaa.victor.pineda@naap.edu.ph\",\"role\":\"vpaa\",\"campus\":\"villamor\",\"department\":\"ics\",\"institute\":\"ics\",\"employeeId\":\"vpaa\",\"employmentType\":\"Regular\",\"position\":\"Vice President for Academic Affairs\",\"yearSection\":\"\",\"studentNumber\":\"\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"\",\"programName\":\"\",\"status\":\"active\"},{\"id\":\"u81\",\"name\":\"Vince Grace Manalo\",\"email\":\"student.043@naap.edu.ph\",\"role\":\"student\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"2-1\",\"studentNumber\":\"12324MN-000043\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAET\",\"programName\":\"Bachelor of Science in Aviation Electronics Technology\",\"status\":\"active\"},{\"id\":\"u82\",\"name\":\"Yana Noel Reyes\",\"email\":\"student.044@naap.edu.ph\",\"role\":\"student\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"2-1\",\"studentNumber\":\"12324MN-000044\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAMT\",\"programName\":\"Bachelor of Science in Aviation Maintenance Technology\",\"status\":\"active\"},{\"id\":\"u83\",\"name\":\"Zach Uma Alonzo\",\"email\":\"student.045@naap.edu.ph\",\"role\":\"student\",\"campus\":\"mactan\",\"department\":\"inet\",\"institute\":\"inet\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"3-1\",\"studentNumber\":\"12324MN-000045\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSAET\",\"programName\":\"Bachelor of Science in Aviation Electronics Technology\",\"status\":\"active\"},{\"id\":\"u84\",\"name\":\"Zoe G Cortez\",\"email\":\"student.046@naap.edu.ph\",\"role\":\"student\",\"campus\":\"villamor\",\"department\":\"ics\",\"institute\":\"ics\",\"employeeId\":\"\",\"employmentType\":\"\",\"position\":\"\",\"yearSection\":\"3-1\",\"studentNumber\":\"12324MN-000046\",\"photoData\":\"\",\"profileImage\":\"\",\"profileImageUrl\":\"\",\"programCode\":\"BSIT\",\"programName\":\"Bachelor of Science in Information Technology\",\"status\":\"active\"}]', '2026-07-14 18:55:31');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `role_id` smallint(5) UNSIGNED NOT NULL,
  `campus_id` bigint(20) UNSIGNED NOT NULL,
  `department_id` bigint(20) UNSIGNED DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `email` varchar(190) NOT NULL,
  `password` varchar(255) NOT NULL DEFAULT '',
  `profile_image` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `active_session_token_hash` char(64) DEFAULT NULL,
  `active_session_started_at` datetime DEFAULT NULL,
  `active_session_last_seen_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `role_id`, `campus_id`, `department_id`, `name`, `email`, `password`, `profile_image`, `status`, `created_at`, `updated_at`) VALUES
(1, 1, 1, NULL, 'Admin', 'admin@naap.edu.ph', '$2y$10$aUeolsY7bQ8Q6C6Lwgi/NuiqvFcCWN0xThAFlKGYHzfisxacNRNbu', NULL, 'active', '2026-07-14 18:54:31', '2026-07-14 18:54:31'),
(2, 1, 1, 3, 'admin.alexis.', 'admin.alexis.navarro@naap.edu.ph', '$2y$10$HKiXCWUTTim.J8koqUmcmuvQ/a5g7/Bn4PBl6dvpSNnonqoG.Je4m', NULL, 'active', '2026-07-14 18:55:22', '2026-07-14 18:55:22'),
(3, 3, 1, 2, 'Marian P. Salazar', 'jemrojo12@gmail.com', '$2y$10$n7crW.69sJAlQk4eYWlw7.bSxeXmt6u/wZ9jH3nm8873RetVfVNfW', NULL, 'active', '2026-07-14 18:55:23', '2026-07-14 18:55:23'),
(4, 4, 1, 3, 'Victor A. Pineda', 'vpaa.victor.pineda@naap.edu.ph', '$2y$10$3lUShWw3l33yTHmcwlxmUekSTVbgkvBhM673LZxWcPfnfFBQ5DvcW', NULL, 'active', '2026-07-14 18:55:23', '2026-07-14 18:55:23'),
(5, 2, 2, 1, 'Helena M. Cruz', 'hr.helena.cruz@naap.edu.ph', '$2y$10$bTlt66BtxezFixqJLvQio.y.n3WYhY06I5.mDL58fCKEJBoXHLWzK', NULL, 'active', '2026-07-14 18:55:23', '2026-07-14 18:55:23'),
(6, 5, 1, 2, 'Mark Antjony C Pascual', 'dean.communication.01@naap.edu.ph', '$2y$10$KWtBcUjU11RTvAmBNp/qEOrY7i2b6tviWqWuGBFN/38KY47meAvTi', NULL, 'active', '2026-07-14 18:55:23', '2026-07-14 18:55:23'),
(7, 5, 1, 3, 'Alvin Jay Reyes', 'dean.ics.02@naap.edu.ph', '$2y$10$EYuFW0M3xCLdzWPB5pK3HunXr5EOB/4njZqc6DirTyNwKm9OLzR9K', NULL, 'active', '2026-07-14 18:55:23', '2026-07-14 18:55:23'),
(8, 5, 2, 1, 'Aaron D Aquino', 'dean.ilas.03@naap.edu.ph', '$2y$10$ewbnSNAdY8ESYreuie8Ru.dsvpf.KJaGZ1dbfHyb.zXQt.p0uSyQ6', NULL, 'active', '2026-07-14 18:55:23', '2026-07-14 18:55:23'),
(9, 6, 1, 2, 'Aira Paz Pascual', 'professor.001@naap.edu.ph', '$2y$10$OzurWjwAsZ./nb0w3A1fR.24O2wj520hfO8LTUd78RX6H9b5spydG', NULL, 'active', '2026-07-14 18:55:23', '2026-07-14 18:55:23'),
(10, 6, 1, 3, 'Alden B Valencia', 'professor.002@naap.edu.ph', '$2y$10$hbymYWbHy/4txoqSVGBAk.pUwQw0V4iRUFFO4PjE02fkT9Df94fj.', NULL, 'active', '2026-07-14 18:55:23', '2026-07-14 18:55:23'),
(11, 6, 1, 2, 'Alexa I Cabrera', 'professor.003@naap.edu.ph', '$2y$10$ZOyRhtd.TVsIqBu6PbniaOEJZWVMJtVAUWmdVSCBHvnPK3YpQ/koO', NULL, 'active', '2026-07-14 18:55:23', '2026-07-14 18:55:23'),
(12, 6, 2, 1, 'Allen Grace Francisco', 'professor.004@naap.edu.ph', '$2y$10$nYdn.uP.bPnjYkcrLCckc.zCmgEjk36m35seVlmclByihGJHMMZAS', NULL, 'active', '2026-07-14 18:55:23', '2026-07-14 18:55:23'),
(13, 6, 2, 1, 'Amara Noel Mendoza', 'professor.005@naap.edu.ph', '$2y$10$uiTSXOLleweqtdAkyk6d0u.Q/AjaTuEZIB9M09rz9F8eFjGTOKC5W', NULL, 'active', '2026-07-14 18:55:23', '2026-07-14 18:55:23'),
(14, 6, 2, 1, 'Angelo Uma Rivera', 'professor.006@naap.edu.ph', '$2y$10$L5Ieyz1B.6e1vvaz3gOuR.fytvZaWUSi6cDFrkGJtnwUqUhPG/HXy', NULL, 'active', '2026-07-14 18:55:23', '2026-07-14 18:55:23'),
(15, 6, 2, 1, 'Anika G Andrada', 'professor.007@naap.edu.ph', '$2y$10$nzJ5mwfVg6aAWkWpsD/oK.T4i22UYn9frzXiHcs02jYE6Nocl9Zmy', NULL, 'active', '2026-07-14 18:55:23', '2026-07-14 18:55:23'),
(16, 6, 2, 1, 'Arielle Dela Cruz', 'professor.008@naap.edu.ph', '$2y$10$NpeYeHiFDBIeH6S53ku4HuQaECA./w3smtNkocBZu.5ER9/EQuT0q', NULL, 'active', '2026-07-14 18:55:23', '2026-07-14 18:55:23'),
(17, 6, 2, 1, 'Bea Lou Jimenez', 'professor.009@naap.edu.ph', '$2y$10$KKNdLVV/YfnbzjMivcXAKOzWyHkmgImKbZyOL6./qRXje5XgaDxXi', NULL, 'active', '2026-07-14 18:55:23', '2026-07-14 18:55:23'),
(18, 6, 2, 1, 'Benj Skye Panganiban', 'professor.010@naap.edu.ph', '$2y$10$GEd.Ixzl7P28N7HKA1wpyOhcd2U/WUfBNn.Ln4jjVawGSYkJAD7wq', NULL, 'active', '2026-07-14 18:55:24', '2026-07-14 18:55:24'),
(19, 6, 2, 1, 'Bianca E Torres', 'professor.011@naap.edu.ph', '$2y$10$LNWUL9IvcxMcUa4Jt7Rqf.irDF8eyKLQQY9m0YGEOExFR21bOlJ.G', NULL, 'active', '2026-07-14 18:55:24', '2026-07-14 18:55:24'),
(20, 6, 2, 1, 'Bryan Belle Bonifacio', 'professor.012@naap.edu.ph', '$2y$10$W3PTgkKVYKpPHq28SnpD0e6.udN9KpHDfv8EX0pZQChC/mr6aT5Ea', NULL, 'active', '2026-07-14 18:55:24', '2026-07-14 18:55:24'),
(21, 6, 2, 1, 'Camille Jane Flores', 'professor.013@naap.edu.ph', '$2y$10$c1TihoMSkoKhEHSQSfXcteaIoH4UhiFilatXnqN6VoqVS5ecBB.y6', NULL, 'active', '2026-07-14 18:55:24', '2026-07-14 18:55:24'),
(22, 6, 1, 3, 'Carlo Quinn Manalo', 'professor.014@naap.edu.ph', '$2y$10$XpPJaV3KMz1Xhk9zf.rap.HaHjSkLPrWP2IvW90pdtLUpAjC62Ytu', NULL, 'active', '2026-07-14 18:55:24', '2026-07-14 18:55:24'),
(23, 6, 1, 3, 'Carmela C Reyes', 'professor.015@naap.edu.ph', '$2y$10$ftY2IvUvdAifpJmffAfNtOJphEXDZBhCkYvHbX6vdV6T2ojJZnxB2', NULL, 'active', '2026-07-14 18:55:24', '2026-07-14 18:55:24'),
(24, 6, 1, 2, 'Cedric J Alonzo', 'professor.016@naap.edu.ph', '$2y$10$nXMDQdyFhFxsFNkfsWa/f.5wiu2vr9gnLw3TAGK1aLMELIrDNyAu6', NULL, 'active', '2026-07-14 18:55:24', '2026-07-14 18:55:24'),
(25, 6, 1, 3, 'Chloe Hope Cortez', 'professor.017@naap.edu.ph', '$2y$10$VmvwQWEIS1BtAhG/i6W0mOReURr7Z1V3i7luhF5b2lqXRZ.ntFrYG', NULL, 'active', '2026-07-14 18:55:24', '2026-07-14 18:55:24'),
(26, 6, 2, 1, 'Daniel Owen Ignacio', 'professor.018@naap.edu.ph', '$2y$10$gesAiMPBLAMBMJeQw2cj3O4t0IVerkuQAbxiaowRj4.ze1T4hplWy', NULL, 'active', '2026-07-14 18:55:24', '2026-07-14 18:55:24'),
(27, 6, 2, 1, 'Daphne A Ortega', 'professor.019@naap.edu.ph', '$2y$10$IkpuzxpkWOu0zTprS9hnDuBMdJ.kP9K/cxIfeGPft7L8PQRLRcZue', NULL, 'active', '2026-07-14 18:55:24', '2026-07-14 18:55:24'),
(28, 6, 2, 1, 'Daryl H Soriano', 'professor.020@naap.edu.ph', '$2y$10$sMIViKFUyAZ6x9ZSL2Hle.WqXi8ZnGugkjaRQPIttggR3kLs3KbWi', NULL, 'active', '2026-07-14 18:55:24', '2026-07-14 18:55:24'),
(29, 6, 2, 1, 'Dianne Faith Bernardo', 'professor.021@naap.edu.ph', '$2y$10$eZqphQFDOmaVyedRLoLikuD2uoUJwW4f0InNVpGc9Dzmu9b7E.07i', NULL, 'active', '2026-07-14 18:55:24', '2026-07-14 18:55:24'),
(30, 6, 1, 3, 'Alden Mae Perez', 'professor.022@naap.edu.ph', '$2y$10$RP3PFInR/xjr92qRmbbBm.sF7zJfYRP1pvjLrJ0/qtdT/rsbzfA0O', NULL, 'active', '2026-07-14 18:55:24', '2026-07-14 18:55:24'),
(31, 6, 1, 2, 'Alexa Troy Abad', 'professor.023@naap.edu.ph', '$2y$10$rpkPoU5mJVV9TSarJ5kqxe5GHmpjt0cGeMKtPQDWzT4iPfRX9O.yW', NULL, 'active', '2026-07-14 18:55:24', '2026-07-14 18:55:24'),
(32, 6, 2, 1, 'Allen F Calderon', 'professor.024@naap.edu.ph', '$2y$10$2hDogaVHREeqCYbcMItaOuiW3yqmnfuu23MrCExhh7N1vidW7vhzy', NULL, 'active', '2026-07-14 18:55:24', '2026-07-14 18:55:24'),
(33, 6, 2, 1, 'Amara Cruz Garcia', 'professor.025@naap.edu.ph', '$2y$10$qFTIpBY3nzMMDqjufNQNouM679Z2s3OPcO4W8xiztIPUDjhBwbLyO', NULL, 'active', '2026-07-14 18:55:24', '2026-07-14 18:55:24'),
(34, 6, 1, 2, 'Angelo Kate Miranda', 'professor.026@naap.edu.ph', '$2y$10$FYN4PHgRiln8drUKUklJ6OcFXrWmT4IuVhJZvheuWfntwHGeAUHkG', NULL, 'active', '2026-07-14 18:55:24', '2026-07-14 18:55:24'),
(35, 6, 1, 2, 'Anika Rain Robles', 'professor.027@naap.edu.ph', '$2y$10$W2ecszkhGUF.ZMgshnsmteGGWjcwKiSoxcV64YYXlGn5Gam2aICfC', NULL, 'active', '2026-07-14 18:55:25', '2026-07-14 18:55:25'),
(36, 6, 2, 1, 'Arielle D Aquino', 'professor.028@naap.edu.ph', '$2y$10$r.UIGGHxgnJlqrfS8.zDxuph84nj9.cBm13AlDJR6cQgs.MmK.g4a', NULL, 'active', '2026-07-14 18:55:25', '2026-07-14 18:55:25'),
(37, 6, 2, 1, 'Bea Anne Dela Cruz', 'professor.029@naap.edu.ph', '$2y$10$SsC2lERcAlLI8ly/txrz9OCgh0OBvGrkvDKFKxWUcz7trHXKXPnDi', NULL, 'active', '2026-07-14 18:55:25', '2026-07-14 18:55:25'),
(38, 6, 2, 1, 'Benj Iris Lacsamana', 'professor.030@naap.edu.ph', '$2y$10$RyPW4TtaI.82zLivUyvlQ.UfxTgbMsezeYc5Dkya4rj2CMaHRXjrC', NULL, 'active', '2026-07-14 18:55:25', '2026-07-14 18:55:25'),
(39, 7, 1, 2, 'Elijah Mae Estrada', 'student.001@naap.edu.ph', '$2y$10$TfeXkNPa7Jd/jJaGibKopuNHrbRSNBdkT/kJp0iiwQheZ6X55U2iG', NULL, 'active', '2026-07-14 18:55:25', '2026-07-14 18:55:25'),
(40, 7, 1, 3, 'Ella Troy Magtibay', 'student.002@naap.edu.ph', '$2y$10$gXkkGq41Cw47ZtwuOFk.8OdYFAwpwmVTpnM.1NF/qnT7E7AYznBR6', NULL, 'active', '2026-07-14 18:55:25', '2026-07-14 18:55:25'),
(41, 7, 1, 2, 'Ethan F Ramos', 'student.003@naap.edu.ph', '$2y$10$UgvATpifCT1.LxhBcQBcxuQArQGzFo/8q8jTHIr5ahLNTHLl854uy', NULL, 'active', '2026-07-14 18:55:25', '2026-07-14 18:55:25'),
(42, 7, 2, 1, 'Faye Cruz Alcantara', 'student.004@naap.edu.ph', '$2y$10$mx4t6gIjjhLSChSuZKc4KePEwCAg32H8enVwvpefoNwnjuIINdi96', NULL, 'active', '2026-07-14 18:55:25', '2026-07-14 18:55:25'),
(43, 7, 2, 1, 'Gabriel Kate Castro', 'student.005@naap.edu.ph', '$2y$10$RImbYU2vlUB7XGcADB.FV.Nc06YRuJ12bwN84WbmPeTU2u.pX3mem', NULL, 'active', '2026-07-14 18:55:25', '2026-07-14 18:55:25'),
(44, 7, 2, 1, 'Giselle Rain Hernandez', 'student.006@naap.edu.ph', '$2y$10$kiRflzdXDA1j3pTkyHSmBu7fmtNw8ZxJ4Cdm9/69u4WLTh43PhgNC', NULL, 'active', '2026-07-14 18:55:25', '2026-07-14 18:55:25'),
(45, 7, 2, 1, 'Hannah D Natividad', 'student.007@naap.edu.ph', '$2y$10$q7vsN.1ao.661jYsQ5WWmOppsdHfirhWN0Y9Ft/2BxIH8qey4pPeO', NULL, 'active', '2026-07-14 18:55:25', '2026-07-14 18:55:25'),
(46, 7, 2, 1, 'Harvey Anne Santos', 'student.008@naap.edu.ph', '$2y$10$5BA258kUpAeKyfxcOZ29vOpTDNyd0kGPgvBt7xOp/cpsokYM9pRfi', NULL, 'active', '2026-07-14 18:55:25', '2026-07-14 18:55:25'),
(47, 7, 2, 1, 'Ian Iris Bautista', 'student.009@naap.edu.ph', '$2y$10$W1XS0Ya66OcLIpQ72LZd4uvg3I6DLA1qwesnpvBCocRILU0hnhvV6', NULL, 'active', '2026-07-14 18:55:25', '2026-07-14 18:55:25'),
(48, 7, 2, 1, 'Isabel Paz Enriquez', 'student.010@naap.edu.ph', '$2y$10$W9Y23KhzTKUcTs/Q8GLVXu7J/SOEPWi9n9R47Y7CO4X4yEktv18/e', NULL, 'active', '2026-07-14 18:55:25', '2026-07-14 18:55:25'),
(49, 7, 2, 1, 'Jared B Mabini', 'student.011@naap.edu.ph', '$2y$10$2XQNveICfjy.IBAl8.yxlerzsYZz/TwCu.Mx8EAXOFuhiWokXVRW.', NULL, 'active', '2026-07-14 18:55:25', '2026-07-14 18:55:25'),
(50, 7, 2, 1, 'Jasmine I Quintos', 'student.012@naap.edu.ph', '$2y$10$iqTSbjW5ZBLph60v4GwqGupvroIWJQCcQEw7/PER64J9dTKOsIgJC', NULL, 'active', '2026-07-14 18:55:25', '2026-07-14 18:55:25'),
(51, 7, 2, 1, 'Jericho Grace Aguilar', 'student.013@naap.edu.ph', '$2y$10$Cow67mX/xSqAWNIY31JkuuurMKszKg.jHYPg1WBtpPZ6eHGRvYE7a', NULL, 'active', '2026-07-14 18:55:25', '2026-07-14 18:55:25'),
(52, 7, 1, 3, 'Joanna Noel Castillo', 'student.014@naap.edu.ph', '$2y$10$kdpUFHyH2YrifvlxPKwKbukyazMzAiSf5NFr/lg6fr.9G/wAdlVJW', NULL, 'active', '2026-07-14 18:55:25', '2026-07-14 18:55:25'),
(53, 7, 1, 3, 'Joshua Uma Gonzales', 'student.015@naap.edu.ph', '$2y$10$eV4UBfCpptiRGSX3Juphx.QMvrvlABG/eUS6/9Ob1kanQLM40JYRS', NULL, 'active', '2026-07-14 18:55:25', '2026-07-14 18:55:25'),
(54, 7, 1, 2, 'Justine G Navarro', 'student.016@naap.edu.ph', '$2y$10$nSL5CdNf6.1nak/eka9jZe7ZhN5Kr/.AKoi4UA3RPwDv2h7Pb6c7q', NULL, 'active', '2026-07-14 18:55:26', '2026-07-14 18:55:26'),
(55, 7, 1, 2, 'Karl Dela Salazar', 'student.017@naap.edu.ph', '$2y$10$wB/jm2ACj6.hfrUkhcz5EO7j.u0gc4Yiby2GW2ajJUfQp.jp.idou', NULL, 'active', '2026-07-14 18:55:26', '2026-07-14 18:55:26'),
(56, 7, 1, 3, 'Katrina Lou Bacani', 'student.018@naap.edu.ph', '$2y$10$c7b9ZOUQfGHXzWVRWANZC.dcTxw6HNt8OYSdzE6FmZjmAEMlvxuni', NULL, 'active', '2026-07-14 18:55:26', '2026-07-14 18:55:26'),
(57, 7, 1, 2, 'Kevin Skye Domingo', 'student.019@naap.edu.ph', '$2y$10$4IFqmdZPRoLPKr9V4SXhWO4ZhAIT.Z0ZUxZ6ZI64LfFti6G0bRbZi', NULL, 'active', '2026-07-14 18:55:26', '2026-07-14 18:55:26'),
(58, 7, 2, 1, 'Kyla E Luna', 'student.020@naap.edu.ph', '$2y$10$E1SdApmJgmbHbXUsui4rqupjKneCM0s9Hek4luqSRF2FrWNmK0RiO', NULL, 'active', '2026-07-14 18:55:26', '2026-07-14 18:55:26'),
(59, 7, 2, 1, 'Lance Belle Perez', 'student.021@naap.edu.ph', '$2y$10$Gnei/Ad8K9M004SYLCc1re38cyoxCqoxLPApg1JULQL0nC0TDxece', NULL, 'active', '2026-07-14 18:55:26', '2026-07-14 18:55:26'),
(60, 7, 2, 1, 'Leah Jane Abad', 'student.022@naap.edu.ph', '$2y$10$yuRp57NeV0rWSD2n1KCp5.EWx6WkZG9O.zZctMIsculoDv70V8Js6', NULL, 'active', '2026-07-14 18:55:26', '2026-07-14 18:55:26'),
(61, 7, 2, 1, 'Liam Quinn Calderon', 'student.023@naap.edu.ph', '$2y$10$LGi130cxUyM5PWBkwLLUcuQbDckJzMp2HGLwI2RwKgwRr7TrNjh.6', NULL, 'active', '2026-07-14 18:55:26', '2026-07-14 18:55:26'),
(62, 7, 2, 1, 'Liza C Garcia', 'student.024@naap.edu.ph', '$2y$10$AY5SAtQzvJNYmAacQKKKSOKnYpc67TqygtNWWJEmqDyfdC4mgM.He', NULL, 'active', '2026-07-14 18:55:26', '2026-07-14 18:55:26'),
(63, 7, 2, 1, 'Marco J Miranda', 'student.025@naap.edu.ph', '$2y$10$ncFaGbDSAErRX5tFtDjsY.CF5ywojKH1wYp/TqfEBAL1PQ/IypgcO', NULL, 'active', '2026-07-14 18:55:26', '2026-07-14 18:55:26'),
(64, 7, 2, 1, 'Mia Hope Robles', 'student.026@naap.edu.ph', '$2y$10$QO.gLK0dJ7Vp9s5SSvLWCeOcYbrIhL0qQmhwhBROXd86LE3e4CIny', NULL, 'active', '2026-07-14 18:55:26', '2026-07-14 18:55:26'),
(65, 7, 2, 1, 'Miguel Owen Aquino', 'student.027@naap.edu.ph', '$2y$10$avVUPXbZHuyXMoYfcjNwoOKtYzzlMVicI5NpGIcopaLubMoZIUMgu', NULL, 'active', '2026-07-14 18:55:26', '2026-07-14 18:55:26'),
(66, 7, 2, 1, 'Nadine A Dela Cruz', 'student.028@naap.edu.ph', '$2y$10$wknSEgCC/wXI6ArlaVbbYuZkffoBvgAXhDHGYx2d4Y9FsAs2mKiLq', NULL, 'active', '2026-07-14 18:55:26', '2026-07-14 18:55:26'),
(67, 7, 2, 1, 'Nathan H Lacsamana', 'student.029@naap.edu.ph', '$2y$10$5BG6PDy0F3cZd0Gv8qTUrOSg1NXDKEGGmzkqOejmQMLt1MW4G9sPK', NULL, 'active', '2026-07-14 18:55:26', '2026-07-14 18:55:26'),
(68, 7, 1, 3, 'Nica Faith Pascual', 'student.030@naap.edu.ph', '$2y$10$f94087YCkpCiIi.BYdAczeV2iDrTC6no1GE4Nv.Ry6d0GEgQUCNim', NULL, 'active', '2026-07-14 18:55:26', '2026-07-14 18:55:26'),
(69, 7, 1, 3, 'Noel Mae Valencia', 'student.031@naap.edu.ph', '$2y$10$aH5fHfnFWg.QxMNtz0xwFe0FC7bIZV56zfChA.XaR5yLkmDeXhhdi', NULL, 'active', '2026-07-14 18:55:26', '2026-07-14 18:55:26'),
(70, 7, 1, 2, 'Olivia Troy Cabrera', 'student.032@naap.edu.ph', '$2y$10$mCiF57cQCBCfflaKNYpMsu6z.30b/2RfuRPutZm7/O92e/Rmm57t.', NULL, 'active', '2026-07-14 18:55:26', '2026-07-14 18:55:26'),
(71, 7, 1, 2, 'Paolo F Francisco', 'student.033@naap.edu.ph', '$2y$10$RaEVNgKt.ebDZsUX52MZaObcwSv02DLiTgfAQo0XqUdzOWJ1OZL.K', NULL, 'active', '2026-07-14 18:55:26', '2026-07-14 18:55:26'),
(72, 7, 1, 3, 'Patricia Cruz Mendoza', 'student.034@naap.edu.ph', '$2y$10$MoNMriF0eo2NehVmvl6vf.QRqemmU/NDca7apCoMQOzyOfYMJfsKK', NULL, 'active', '2026-07-14 18:55:26', '2026-07-14 18:55:26'),
(73, 7, 1, 2, 'Rafael Kate Rivera', 'student.035@naap.edu.ph', '$2y$10$6c4urreIBVk4iQZ2sTbwxu2i4PoP0AMi3HM9/2FxVdaId0jZo9vyG', NULL, 'active', '2026-07-14 18:55:27', '2026-07-14 18:55:27'),
(74, 7, 2, 1, 'Rica Rain Andrada', 'student.036@naap.edu.ph', '$2y$10$7/9fUJSz1olENjzsMPrv1.TIOXlei.T1.iYeZuasxBCXVt/nBlXYS', NULL, 'active', '2026-07-14 18:55:27', '2026-07-14 18:55:27'),
(75, 7, 2, 1, 'Rico D Cruz', 'student.037@naap.edu.ph', '$2y$10$/Rsk4rJBO0WrBjSu8EblIekyrmuMEApxN0b85wD0YFWbM7fF1pJ2O', NULL, 'active', '2026-07-14 18:55:27', '2026-07-14 18:55:27'),
(76, 7, 2, 1, 'Samantha Anne Jimenez', 'student.038@naap.edu.ph', '$2y$10$cfpnu/iiXsVpuwu/2A4mU.twqXfG7Tid152FD5nh5CueBQeSo/0Me', NULL, 'active', '2026-07-14 18:55:27', '2026-07-14 18:55:27'),
(77, 7, 2, 1, 'Sean Iris Panganiban', 'student.039@naap.edu.ph', '$2y$10$GwrHX.1EIVH/QWPHxoLqkOiAjnFnwR1UHdM0tA5PJC.ISukVyhld6', NULL, 'active', '2026-07-14 18:55:27', '2026-07-14 18:55:27'),
(78, 7, 2, 1, 'Sophia Paz Torres', 'student.040@naap.edu.ph', '$2y$10$kx0LmopeOzrJWdHgoAJxrOaWLTCV/8ZUQryG5NMzej1.ChZUvLfXG', NULL, 'active', '2026-07-14 18:55:27', '2026-07-14 18:55:27'),
(79, 7, 2, 1, 'Tristan B Bonifacio', 'student.041@naap.edu.ph', '$2y$10$1VV9PZA5DQRjOFD0XNDC..tv1Ffy0DXTTgmIixbCn50ABAcKBNA/C', NULL, 'active', '2026-07-14 18:55:27', '2026-07-14 18:55:27'),
(80, 7, 2, 1, 'Vanessa I Flores', 'student.042@naap.edu.ph', '$2y$10$P0iFGsY2OJeYlWRhP4pjBeZT80IqBL0kb.JqSNYKLUyNSLVCP6mS2', NULL, 'active', '2026-07-14 18:55:27', '2026-07-14 18:55:27'),
(81, 7, 2, 1, 'Vince Grace Manalo', 'student.043@naap.edu.ph', '$2y$10$AIOqx7Qso28m.GeGfklF3.tdZGWHM7x0uOuaS5wYkQFeHtZjHYHN6', NULL, 'active', '2026-07-14 18:55:27', '2026-07-14 18:55:27'),
(82, 7, 2, 1, 'Yana Noel Reyes', 'student.044@naap.edu.ph', '$2y$10$EE/Im8UochB0CoDQwU/to.D0DXXvw8nkO8bNQLiPZm7X/t9uN/rBS', NULL, 'active', '2026-07-14 18:55:27', '2026-07-14 18:55:27'),
(83, 7, 2, 1, 'Zach Uma Alonzo', 'student.045@naap.edu.ph', '$2y$10$JxxRACOUzQKvjzVKsqlQf.Fhi0LQa0hIDazmWms.xXkMJrh9qsyy6', NULL, 'active', '2026-07-14 18:55:27', '2026-07-14 18:55:27'),
(84, 7, 1, 3, 'Zoe G Cortez', 'student.046@naap.edu.ph', '$2y$10$CQtO2z2TkT/7xZNiaw1WYeS6g0hnMHjFMEXaOkF9hpHHFfOVVZooK', NULL, 'active', '2026-07-14 18:55:27', '2026-07-14 18:55:27'),
(85, 7, 1, 3, 'Aaron Dela Ignacio', 'student.047@naap.edu.ph', '$2y$10$XTH4Ic/RsgqUfRQiyfoTX.58UJ4s1C5igcLwAoS/a13y3D2727DqS', NULL, 'active', '2026-07-14 18:55:27', '2026-07-14 18:55:27'),
(86, 7, 1, 2, 'Abigail Lou Ortega', 'student.048@naap.edu.ph', '$2y$10$zXy2jWb8IFAw2Ua88Q.TguI/bqPX518tLWs4rXwa6ccXGu/F1N7uG', NULL, 'active', '2026-07-14 18:55:27', '2026-07-14 18:55:27'),
(87, 7, 1, 2, 'Adrian Skye Soriano', 'student.049@naap.edu.ph', '$2y$10$TAQOQGQqD3Lnkku7mot1peXp.J0.wmck74snG36ry2Z1P2Mn0wNgK', NULL, 'active', '2026-07-14 18:55:27', '2026-07-14 18:55:27'),
(88, 7, 1, 3, 'Aira E Bernardo', 'student.050@naap.edu.ph', '$2y$10$0/KcxslZkFRiMJ7SNgWXUued.O7uU6m7ggbLy611AEBqWiX2FcvrG', NULL, 'active', '2026-07-14 18:55:27', '2026-07-14 18:55:27'),
(89, 7, 1, 2, 'Alden Belle Estrada', 'student.051@naap.edu.ph', '$2y$10$J36ZZj99NS7pZDsZrBb/DOzsCBnsBqj0KcO5G3S/svwGRyi5q1482', NULL, 'active', '2026-07-14 18:55:27', '2026-07-14 18:55:27'),
(90, 7, 2, 1, 'Alexa Jane Magtibay', 'student.052@naap.edu.ph', '$2y$10$OIp9.VXvs8aExjLB6dW.wum.xTQU2M59AUaPt1jrtoB1wtz6kucKK', NULL, 'active', '2026-07-14 18:55:27', '2026-07-14 18:55:27'),
(91, 7, 2, 1, 'Allen Quinn Ramos', 'student.053@naap.edu.ph', '$2y$10$/Qe7yTPXvGbi2vVzoBaEvebfF1UIJzVDan3VISdgyPHKRvpa/PjZi', NULL, 'active', '2026-07-14 18:55:27', '2026-07-14 18:55:27'),
(92, 7, 2, 1, 'Amara C Alcantara', 'student.054@naap.edu.ph', '$2y$10$80bylOmEiNA/RFFXjA6y5uES3cbFu4NDStR3Ezq4qjq1.szfCZ6Vu', NULL, 'active', '2026-07-14 18:55:28', '2026-07-14 18:55:28'),
(93, 7, 2, 1, 'Angelo J Castro', 'student.055@naap.edu.ph', '$2y$10$Q6SRcJJaFyaCj7MLCDNB/epYKmFXxU1uE4Vf4reLhniAwPlXGsccm', NULL, 'active', '2026-07-14 18:55:28', '2026-07-14 18:55:28'),
(94, 7, 2, 1, 'Anika Hope Hernandez', 'student.056@naap.edu.ph', '$2y$10$NYfffBgQgXKSu89N14Wl3eciglEU4BCQesh2zOg.QA4fJ5va/D6A.', NULL, 'active', '2026-07-14 18:55:28', '2026-07-14 18:55:28'),
(95, 7, 2, 1, 'Arielle Owen Natividad', 'student.057@naap.edu.ph', '$2y$10$L3hTa9UWe74HdUYwB.QJ8OsPXFIFD8jMFrs3Px6Iy/kgeGMJTlqay', NULL, 'active', '2026-07-14 18:55:28', '2026-07-14 18:55:28'),
(96, 7, 2, 1, 'Bea A Santos', 'student.058@naap.edu.ph', '$2y$10$XkDEEd8lxxPcFjYxDZtlD.IOchdZT.tGi6kNY3dg5T/KRAXL5uDL2', NULL, 'active', '2026-07-14 18:55:28', '2026-07-14 18:55:28'),
(97, 7, 2, 1, 'Benj H Bautista', 'student.059@naap.edu.ph', '$2y$10$QgcKDenlgcEGRqejZ86l7OPxp4Rmpv7RAb82hXSYxYOX.mAzS7dOy', NULL, 'active', '2026-07-14 18:55:28', '2026-07-14 18:55:28'),
(98, 7, 2, 1, 'Bianca Faith Enriquez', 'student.060@naap.edu.ph', '$2y$10$xpmwb/HdtQgsRK5/CFYhUOZfhgS4ikqSCzY2E7DIkKYWGHE/lU3We', NULL, 'active', '2026-07-14 18:55:28', '2026-07-14 18:55:28'),
(99, 7, 2, 1, 'Bryan Mae Mabini', 'student.061@naap.edu.ph', '$2y$10$acr.6.xvVG5OfjHh48h23Op7dnR8OgMD2YpDZ6yyiY4yfCtrgDaZK', NULL, 'active', '2026-07-14 18:55:28', '2026-07-14 18:55:28'),
(100, 7, 1, 3, 'Camille Troy Quintos', 'student.062@naap.edu.ph', '$2y$10$o65V4sljDjTF2UZTUdMyP.xFZe0mdrTPPeCFUTFgOV65KlH4FTZsy', NULL, 'active', '2026-07-14 18:55:28', '2026-07-14 18:55:28'),
(101, 7, 1, 3, 'Carlo F Aguilar', 'student.063@naap.edu.ph', '$2y$10$GsWfMXFGGDEYNCzpFmWgJuCTsYfEi9oKeqD9Hbx2Jw27KbTIQ/2Ta', NULL, 'active', '2026-07-14 18:55:28', '2026-07-14 18:55:28'),
(102, 7, 1, 2, 'Student 064', 'student.064@naap.edu.ph', '$2y$10$aEo3Pj5RtfA0uIwqQ0r5V.hbTvVXHVb8eKAN6VXnoec9a1Xx.afdS', NULL, 'active', '2026-07-14 18:55:28', '2026-07-14 18:55:28'),
(103, 7, 1, 2, 'Student 065', 'student.065@naap.edu.ph', '$2y$10$7C3HyjqfOt89A1aa.n4/6.N4GkrqXGgqsxKdZ6B/UJSuzo7Yzn9W2', NULL, 'active', '2026-07-14 18:55:28', '2026-07-14 18:55:28'),
(104, 7, 1, 2, 'Student 066', 'student.066@naap.edu.ph', '$2y$10$3t6NAyyMF2OtOcbWaSKF8uBEo0TQepRYJJvu78eZlTvmOZeH/RjYC', NULL, 'active', '2026-07-14 18:55:28', '2026-07-14 18:55:28'),
(105, 7, 1, 2, 'Student 067', 'student.067@naap.edu.ph', '$2y$10$Z8iCMyWFlISzTWKupvhMFexqjVmZIlXo6LQ2rAe79XYA5x0tgjmFO', NULL, 'active', '2026-07-14 18:55:28', '2026-07-14 18:55:28'),
(106, 7, 1, 2, 'Student 068', 'student.068@naap.edu.ph', '$2y$10$0xJ2tG9DP3XltcARtmxLaebDUOR8zenrcF3W91c43gxxy3bI1XHE.', NULL, 'active', '2026-07-14 18:55:28', '2026-07-14 18:55:28'),
(107, 7, 1, 2, 'Student 069', 'student.069@naap.edu.ph', '$2y$10$K13TxFDZtvPnM3MnUTD.juXmEbGojAl3oOOYo5tHzJrt9u0Dy6PAW', NULL, 'active', '2026-07-14 18:55:28', '2026-07-14 18:55:28'),
(108, 7, 1, 2, 'Student 070', 'student.070@naap.edu.ph', '$2y$10$0.avYaf3zPc7MS9tdvWkn.j0YUtdqfgBZu6N0GY6/RUFXBDAK1kk2', NULL, 'active', '2026-07-14 18:55:28', '2026-07-14 18:55:28'),
(109, 7, 1, 2, 'Student 071', 'student.071@naap.edu.ph', '$2y$10$Cs4BO8ARKssYa60dERtXauqJpH5WtTbQApftj7/2JPoft9.Bz5T1e', NULL, 'active', '2026-07-14 18:55:28', '2026-07-14 18:55:28'),
(110, 7, 1, 2, 'Student 072', 'student.072@naap.edu.ph', '$2y$10$8SkykMR76F1/xdhbZKR4VOzFe0FaVVD4V2rSp2C1byJocoI3RcYI.', NULL, 'active', '2026-07-14 18:55:28', '2026-07-14 18:55:28'),
(111, 7, 1, 2, 'Student 073', 'student.073@naap.edu.ph', '$2y$10$t.8Z1JHRCvsfwH8RtwDrPOUCWwuPqnHTr85hKRc4g.GpI362/7Anm', NULL, 'active', '2026-07-14 18:55:28', '2026-07-14 18:55:28'),
(112, 7, 1, 2, 'Student 074', 'student.074@naap.edu.ph', '$2y$10$3VY2kHpb6jQjQeGJJ5gLMeeRqo5qCJE.nXAuDDSiMKEOs56QMDVqG', NULL, 'active', '2026-07-14 18:55:29', '2026-07-14 18:55:29'),
(113, 7, 1, 2, 'Student 075', 'student.075@naap.edu.ph', '$2y$10$fAoPYs.wSS0Ft3ZKgVapBuA.vgoCj/J1YAG0TyRLwCVCYsVhSU/bG', NULL, 'active', '2026-07-14 18:55:29', '2026-07-14 18:55:29'),
(114, 7, 1, 2, 'Student 076', 'student.076@naap.edu.ph', '$2y$10$Gwcs4KbQD7zUsNdikM1N6eYcB57DdNeBEfLu..iDBJNin7MaX8aIC', NULL, 'active', '2026-07-14 18:55:29', '2026-07-14 18:55:29'),
(115, 7, 1, 2, 'Student 077', 'student.077@naap.edu.ph', '$2y$10$eY84/0dGAZmdsOi.JRL.HO.YPDrwXs0bFnycFxthwEf01hAhY7n86', NULL, 'active', '2026-07-14 18:55:29', '2026-07-14 18:55:29'),
(116, 7, 1, 2, 'Student 078', 'student.078@naap.edu.ph', '$2y$10$Yd5N4Kvduo4VrtGY5znSmeRIgQrx5BpTeER35.Z.3sUvHvo44NcFK', NULL, 'active', '2026-07-14 18:55:29', '2026-07-14 18:55:29'),
(117, 7, 1, 2, 'Student 079', 'student.079@naap.edu.ph', '$2y$10$mMTf1xdvfSMmAUAynjao0.Ik3CrdaGFQntFT1VYdrux.awAvemg82', NULL, 'active', '2026-07-14 18:55:29', '2026-07-14 18:55:29'),
(118, 7, 1, 2, 'Student 080', 'student.080@naap.edu.ph', '$2y$10$iTFhoPAn9qJCek9ipThu9OeqaCr4hUsWqe01nLpQd1joUFZsHON/y', NULL, 'active', '2026-07-14 18:55:29', '2026-07-14 18:55:29'),
(119, 7, 1, 2, 'Student 081', 'student.081@naap.edu.ph', '$2y$10$3SapiL1.8B4uqubb7hf8T.cdnNGI4tNFySOl3VJqEvfbrDL6zHFfC', NULL, 'active', '2026-07-14 18:55:29', '2026-07-14 18:55:29'),
(120, 7, 1, 2, 'Student 082', 'student.082@naap.edu.ph', '$2y$10$uhh0aCfMZrLM3kEHGJDW5.h3CYO6Xqq4HOqeDLkvtqCG2pJJTtoqy', NULL, 'active', '2026-07-14 18:55:29', '2026-07-14 18:55:29'),
(121, 7, 1, 2, 'Student 083', 'student.083@naap.edu.ph', '$2y$10$4EFrdHBLaq3vanlw8seaIuVmONA8QXoLcXcCA9WAnatl9nld/8oW.', NULL, 'active', '2026-07-14 18:55:29', '2026-07-14 18:55:29'),
(122, 7, 1, 2, 'Student 084', 'student.084@naap.edu.ph', '$2y$10$BYBeKBycz1NO81W8LNxbEuw.41yEwDig5TNHTA3XMC7IoPQ43oP.O', NULL, 'active', '2026-07-14 18:55:29', '2026-07-14 18:55:29'),
(123, 7, 1, 2, 'Student 085', 'student.085@naap.edu.ph', '$2y$10$8aF22GE4Cu03HlKjOls0KeN2qhXQ37CbdyAuJORwvzbUIRRD.lgne', NULL, 'active', '2026-07-14 18:55:29', '2026-07-14 18:55:29'),
(124, 7, 1, 2, 'Student 086', 'student.086@naap.edu.ph', '$2y$10$If9QKAb.217ie/qeFzlTs.Y6UE0LZTpUGg7eNunA.4GrDek2DFuc2', NULL, 'active', '2026-07-14 18:55:29', '2026-07-14 18:55:29'),
(125, 7, 1, 2, 'Student 087', 'student.087@naap.edu.ph', '$2y$10$qLdI/5k3ss6VvUC1MXxb.erv3ONi8zqTTEAKLjsmShgIVXVXy7Phi', NULL, 'active', '2026-07-14 18:55:29', '2026-07-14 18:55:29'),
(126, 7, 1, 2, 'Student 088', 'student.088@naap.edu.ph', '$2y$10$aWTO50IB3M4DLDiInMqZpO7A1rO3rN6dsKJWLf3.ZfGxEMRRG3hpq', NULL, 'active', '2026-07-14 18:55:29', '2026-07-14 18:55:29'),
(127, 7, 1, 2, 'Student 089', 'student.089@naap.edu.ph', '$2y$10$sH6WW8SfuafNfXMIRvnVIOsCbRd8e./MDsYCtjRSvkyjPN4ksSrfe', NULL, 'active', '2026-07-14 18:55:29', '2026-07-14 18:55:29'),
(128, 7, 1, 2, 'Student 090', 'student.090@naap.edu.ph', '$2y$10$/3AQocq9K6c2aJkkTwSEb.ZI/g1GAU.fTjgKE7X/rIe4L/y8ZZnka', NULL, 'active', '2026-07-14 18:55:29', '2026-07-14 18:55:29'),
(129, 7, 1, 2, 'Student 091', 'student.091@naap.edu.ph', '$2y$10$tu7IvOKZcL5xaxpDXn7MLef5.gZ.BbZTIadBfBG0Su0BJRTb1Jdau', NULL, 'active', '2026-07-14 18:55:29', '2026-07-14 18:55:29'),
(130, 7, 1, 2, 'Student 092', 'student.092@naap.edu.ph', '$2y$10$40nm0WpO1Zd/CLD7YMy3LeZKlwkd.h3wa6mrLJ4UPw58L0CtUxdty', NULL, 'active', '2026-07-14 18:55:29', '2026-07-14 18:55:29'),
(131, 7, 1, 2, 'Student 093', 'student.093@naap.edu.ph', '$2y$10$cGgn/LiG4/hfKgmcyBrRmuQ5pTbGRvCwchp1g20BPlW2y7Osmktz6', NULL, 'active', '2026-07-14 18:55:29', '2026-07-14 18:55:29'),
(132, 7, 1, 2, 'Student 094', 'student.094@naap.edu.ph', '$2y$10$6bhRCt1ghJarHN7zUKAeheJv3e/3sae8dSuFODWZLm7X0F5coEV1e', NULL, 'active', '2026-07-14 18:55:30', '2026-07-14 18:55:30'),
(133, 7, 1, 2, 'Student 095', 'student.095@naap.edu.ph', '$2y$10$SxVobW3YKv0z/hUwiJNKoePa11oOmSpphoF6t3MXWzTm1TfKEAvnC', NULL, 'active', '2026-07-14 18:55:30', '2026-07-14 18:55:30'),
(134, 7, 1, 2, 'Student 096', 'student.096@naap.edu.ph', '$2y$10$N06mXaUwoDt9VIfU1u.15.u3Ma55h/ZN0Pq.RDlEEbvf7mhsnw2om', NULL, 'active', '2026-07-14 18:55:30', '2026-07-14 18:55:30'),
(135, 7, 1, 2, 'Student 097', 'student.097@naap.edu.ph', '$2y$10$zCcWFNzcpSZPKs.7fTZXQe4aPj6.ZGtaBVwCC8qZh0oEKa3O62I7e', NULL, 'active', '2026-07-14 18:55:30', '2026-07-14 18:55:30'),
(136, 7, 1, 2, 'Student 098', 'student.098@naap.edu.ph', '$2y$10$iTdAhbFTyYGXwOr6ARC6W.REmjTHzXNLoj.ihCda2bXKiqd5I9SfK', NULL, 'active', '2026-07-14 18:55:30', '2026-07-14 18:55:30'),
(137, 7, 1, 2, 'Student 099', 'student.099@naap.edu.ph', '$2y$10$bQvWg1rh1pYHFy2o0cXy6Oq3jOAsYWF1OjTZN4/nFN1otWJwn5yqm', NULL, 'active', '2026-07-14 18:55:30', '2026-07-14 18:55:30'),
(138, 7, 1, 2, 'Student 100', 'student.100@naap.edu.ph', '$2y$10$E4iAiNvJ/t9ahLtB5uoVOe.xfz8VSTKQ8pzACDKw.SDwappRVRV02', NULL, 'active', '2026-07-14 18:55:30', '2026-07-14 18:55:30'),
(139, 7, 1, 2, 'Student 101', 'student.101@naap.edu.ph', '$2y$10$2Wq4aY0/qcNot2pfu3FgdOFxCcFxepZfZmKc8WGDkSbekdXT0K5JG', NULL, 'active', '2026-07-14 18:55:30', '2026-07-14 18:55:30'),
(140, 7, 1, 2, 'Student 102', 'student.102@naap.edu.ph', '$2y$10$ksjlM42Zt4mE5ax3tbeGpuqtjfWrVKgb7XkYR5w4kBWWSDkLNPBfO', NULL, 'active', '2026-07-14 18:55:30', '2026-07-14 18:55:30'),
(141, 7, 1, 2, 'Student 103', 'student.103@naap.edu.ph', '$2y$10$3OJIvQWu/r1oH/Glj0BeL.qDqaTMRdUamo3TvO/1ww9dNScUMNxly', NULL, 'active', '2026-07-14 18:55:30', '2026-07-14 18:55:30'),
(142, 7, 1, 2, 'Student 104', 'student.104@naap.edu.ph', '$2y$10$GDe6l8uf7jFzfhY4XJ4/Q.NOcZ0BhHZZs5WQmIzQbV72f25fHYCrG', NULL, 'active', '2026-07-14 18:55:30', '2026-07-14 18:55:30'),
(143, 7, 1, 2, 'Student 105', 'student.105@naap.edu.ph', '$2y$10$yTviuNniFbr5XggeO8y9auUluspO1CBXvlAQKSwbCQw6RoVFU4iIO', NULL, 'active', '2026-07-14 18:55:30', '2026-07-14 18:55:30'),
(144, 7, 1, 2, 'Student 106', 'student.106@naap.edu.ph', '$2y$10$RT0R5dxYOrh3U97r2SvOoeyPljG0Wjw3sza7VHWuZ3P56bZzoS3LW', NULL, 'active', '2026-07-14 18:55:30', '2026-07-14 18:55:30'),
(145, 7, 1, 2, 'Student 107', 'student.107@naap.edu.ph', '$2y$10$SzoXu72rwoP6wqFylFVbJ.V0qEGqvNC0opgJ35m.55GVJz0D5xXFK', NULL, 'active', '2026-07-14 18:55:30', '2026-07-14 18:55:30'),
(146, 7, 1, 2, 'Student 108', 'student.108@naap.edu.ph', '$2y$10$GV7Ou7QJ2JSvJaVrn.1n5u4AZK3EoHJQmcImJzFlnv03Av8TBk/V2', NULL, 'active', '2026-07-14 18:55:30', '2026-07-14 18:55:30'),
(147, 7, 1, 2, 'Student 109', 'student.109@naap.edu.ph', '$2y$10$0dmtnqMZERDenSvZiJkgduQ3sljFaewYs6mja0LyEXhnvcAYAFjzC', NULL, 'active', '2026-07-14 18:55:30', '2026-07-14 18:55:30'),
(148, 7, 1, 2, 'Student 110', 'student.110@naap.edu.ph', '$2y$10$jkoIYdaZGyVnrjVEDhTie.d/o29LXQt4nvrOkKuzzX2g6cApMpx7q', NULL, 'active', '2026-07-14 18:55:30', '2026-07-14 18:55:30'),
(149, 7, 1, 2, 'Student 111', 'student.111@naap.edu.ph', '$2y$10$8Z6vyjhD3FmoJj/KLoIZHOMSmEOVC4sa9rgxBEKtJHhg3/FAF5Wca', NULL, 'active', '2026-07-14 18:55:30', '2026-07-14 18:55:30'),
(150, 7, 1, 2, 'Student 112', 'student.112@naap.edu.ph', '$2y$10$k8AXjJl4j21tFcuuOHO0Iu3tU/B4OAK4XOKyNKo2DdzyU2BdlFLhe', NULL, 'active', '2026-07-14 18:55:30', '2026-07-14 18:55:30'),
(151, 7, 1, 2, 'Student 113', 'student.113@naap.edu.ph', '$2y$10$G8dPamps1f1QS2wRZqQeTeLm92K11cZCSXmSfgoks05D8NiqZMs06', NULL, 'active', '2026-07-14 18:55:31', '2026-07-14 18:55:31');

-- --------------------------------------------------------

--
-- Table structure for table `user_profile_data`
--

CREATE TABLE `user_profile_data` (
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `profile_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_log`
--
ALTER TABLE `activity_log`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_activity_log_code` (`log_code`),
  ADD KEY `idx_activity_log_user` (`user_id`),
  ADD KEY `idx_activity_log_type` (`entry_type`),
  ADD KEY `idx_activity_log_happened_at` (`happened_at`);

--
-- Indexes for table `announcements`
--
ALTER TABLE `announcements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_announcements_created_by` (`created_by_user_id`),
  ADD KEY `idx_announcements_active` (`is_active`);

--
-- Indexes for table `announcement_targets`
--
ALTER TABLE `announcement_targets`
  ADD PRIMARY KEY (`announcement_id`,`role_id`),
  ADD KEY `fk_announcement_targets_role` (`role_id`);

--
-- Indexes for table `campuses`
--
ALTER TABLE `campuses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_campuses_slug` (`slug`),
  ADD UNIQUE KEY `uq_campuses_name` (`name`);

--
-- Indexes for table `course_offerings`
--
ALTER TABLE `course_offerings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_course_offerings` (`subject_id`,`semester_id`,`professor_id`,`section_name`),
  ADD KEY `idx_course_offerings_semester` (`semester_id`),
  ADD KEY `idx_course_offerings_professor` (`professor_id`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_departments_campus_code` (`campus_id`,`code`),
  ADD UNIQUE KEY `uq_departments_campus_name` (`campus_id`,`name`);

--
-- Indexes for table `employment_types`
--
ALTER TABLE `employment_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_employment_types_code` (`code`);

--
-- Indexes for table `evaluations`
--
ALTER TABLE `evaluations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_evaluations_semester` (`semester_id`),
  ADD KEY `idx_evaluations_type` (`evaluation_type_id`),
  ADD KEY `idx_evaluations_evaluator` (`evaluator_user_id`),
  ADD KEY `idx_evaluations_evaluatee` (`evaluatee_user_id`),
  ADD KEY `idx_evaluations_course` (`course_offering_id`),
  ADD KEY `fk_evaluations_questionnaire` (`questionnaire_id`);

--
-- Indexes for table `evaluation_periods`
--
ALTER TABLE `evaluation_periods`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_evaluation_periods_semester_type` (`semester_id`,`evaluation_type_id`),
  ADD KEY `fk_evaluation_periods_type` (`evaluation_type_id`);

--
-- Indexes for table `evaluation_responses`
--
ALTER TABLE `evaluation_responses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_evaluation_responses_evaluation` (`evaluation_id`),
  ADD KEY `idx_evaluation_responses_question` (`question_id`);

--
-- Indexes for table `evaluation_types`
--
ALTER TABLE `evaluation_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_evaluation_types_code` (`code`);

--
-- Indexes for table `peer_evaluation_assignments`
--
ALTER TABLE `peer_evaluation_assignments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_peer_eval_assignments_pair` (`semester_id`,`evaluator_user_id`,`evaluatee_user_id`),
  ADD KEY `idx_peer_eval_assignments_room` (`room_id`),
  ADD KEY `idx_peer_eval_assignments_evaluator_status` (`evaluator_user_id`,`status`),
  ADD KEY `idx_peer_eval_assignments_evaluatee_status` (`evaluatee_user_id`,`status`);

--
-- Indexes for table `peer_evaluation_rooms`
--
ALTER TABLE `peer_evaluation_rooms`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_peer_evaluation_rooms` (`semester_id`,`dean_user_id`,`room_name`),
  ADD UNIQUE KEY `uq_peer_evaluation_rooms_program_batch` (`semester_id`,`dean_user_id`,`program_id`),
  ADD KEY `idx_peer_evaluation_rooms_program_id` (`program_id`),
  ADD KEY `idx_peer_evaluation_rooms_semester_dean_program` (`semester_id`,`dean_user_id`,`program_id`),
  ADD KEY `fk_peer_evaluation_rooms_dean` (`dean_user_id`),
  ADD KEY `fk_peer_evaluation_rooms_coordinator` (`coordinator_user_id`);

--
-- Indexes for table `peer_evaluation_room_members`
--
ALTER TABLE `peer_evaluation_room_members`
  ADD PRIMARY KEY (`room_id`,`professor_user_id`),
  ADD KEY `fk_peer_evaluation_room_members_professor` (`professor_user_id`);

--
-- Indexes for table `profile_photos`
--
ALTER TABLE `profile_photos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_profile_photos_user` (`user_id`);

--
-- Indexes for table `programs`
--
ALTER TABLE `programs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_programs_department_code` (`department_id`,`code`),
  ADD UNIQUE KEY `uq_programs_department_name` (`department_id`,`name`);

--
-- Indexes for table `questionnaires`
--
ALTER TABLE `questionnaires`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_questionnaires_semester_type` (`semester_id`,`evaluation_type_id`),
  ADD KEY `fk_questionnaires_type` (`evaluation_type_id`);

--
-- Indexes for table `questionnaire_sections`
--
ALTER TABLE `questionnaire_sections`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_questionnaire_sections_code` (`questionnaire_id`,`section_code`),
  ADD KEY `idx_questionnaire_sections_order` (`questionnaire_id`,`sort_order`);

--
-- Indexes for table `questions`
--
ALTER TABLE `questions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_questions_questionnaire` (`questionnaire_id`),
  ADD KEY `idx_questions_section` (`section_id`),
  ADD KEY `idx_questions_type` (`question_type_id`);

--
-- Indexes for table `question_types`
--
ALTER TABLE `question_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_question_types_code` (`code`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_roles_code` (`code`);

--
-- Indexes for table `semesters`
--
ALTER TABLE `semesters`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_semesters_slug` (`slug`);

--
-- Indexes for table `staff_profiles`
--
ALTER TABLE `staff_profiles`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `uq_staff_profiles_employee_id` (`employee_id`),
  ADD KEY `idx_staff_profiles_program` (`program_id`),
  ADD KEY `fk_staff_profiles_employment_type` (`employment_type_id`);

--
-- Indexes for table `student_course_enrollments`
--
ALTER TABLE `student_course_enrollments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_student_course_enrollment` (`student_id`,`course_offering_id`),
  ADD KEY `fk_student_course_enrollments_course` (`course_offering_id`);

--
-- Indexes for table `student_profiles`
--
ALTER TABLE `student_profiles`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `uq_student_profiles_student_number` (`student_number`),
  ADD KEY `idx_student_profiles_program` (`program_id`);

--
-- Indexes for table `subjects`
--
ALTER TABLE `subjects`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_subjects_department_code` (`department_id`,`subject_code`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_system_settings_key` (`setting_key`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_users_email` (`email`),
  ADD KEY `idx_users_role` (`role_id`),
  ADD KEY `idx_users_campus` (`campus_id`),
  ADD KEY `idx_users_department` (`department_id`),
  ADD KEY `idx_users_status` (`status`),
  ADD KEY `idx_users_active_session_token_hash` (`active_session_token_hash`);

--
-- Indexes for table `user_profile_data`
--
ALTER TABLE `user_profile_data`
  ADD PRIMARY KEY (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_log`
--
ALTER TABLE `activity_log`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `announcements`
--
ALTER TABLE `announcements`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `campuses`
--
ALTER TABLE `campuses`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `course_offerings`
--
ALTER TABLE `course_offerings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=109;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=40;

--
-- AUTO_INCREMENT for table `employment_types`
--
ALTER TABLE `employment_types`
  MODIFY `id` smallint(5) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `evaluations`
--
ALTER TABLE `evaluations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `evaluation_periods`
--
ALTER TABLE `evaluation_periods`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `evaluation_responses`
--
ALTER TABLE `evaluation_responses`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `evaluation_types`
--
ALTER TABLE `evaluation_types`
  MODIFY `id` smallint(5) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `peer_evaluation_assignments`
--
ALTER TABLE `peer_evaluation_assignments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `peer_evaluation_rooms`
--
ALTER TABLE `peer_evaluation_rooms`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `profile_photos`
--
ALTER TABLE `profile_photos`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `programs`
--
ALTER TABLE `programs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `questionnaires`
--
ALTER TABLE `questionnaires`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `questionnaire_sections`
--
ALTER TABLE `questionnaire_sections`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `questions`
--
ALTER TABLE `questions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `question_types`
--
ALTER TABLE `question_types`
  MODIFY `id` smallint(5) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` smallint(5) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=92;

--
-- AUTO_INCREMENT for table `semesters`
--
ALTER TABLE `semesters`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `student_course_enrollments`
--
ALTER TABLE `student_course_enrollments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=679;

--
-- AUTO_INCREMENT for table `subjects`
--
ALTER TABLE `subjects`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=43;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=152;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_log`
--
ALTER TABLE `activity_log`
  ADD CONSTRAINT `fk_activity_log_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `announcements`
--
ALTER TABLE `announcements`
  ADD CONSTRAINT `fk_announcements_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `announcement_targets`
--
ALTER TABLE `announcement_targets`
  ADD CONSTRAINT `fk_announcement_targets_announcement` FOREIGN KEY (`announcement_id`) REFERENCES `announcements` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_announcement_targets_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `course_offerings`
--
ALTER TABLE `course_offerings`
  ADD CONSTRAINT `fk_course_offerings_professor` FOREIGN KEY (`professor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_course_offerings_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_course_offerings_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `departments`
--
ALTER TABLE `departments`
  ADD CONSTRAINT `fk_departments_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `evaluations`
--
ALTER TABLE `evaluations`
  ADD CONSTRAINT `fk_evaluations_course` FOREIGN KEY (`course_offering_id`) REFERENCES `course_offerings` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_evaluations_evaluatee` FOREIGN KEY (`evaluatee_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_evaluations_evaluator` FOREIGN KEY (`evaluator_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_evaluations_questionnaire` FOREIGN KEY (`questionnaire_id`) REFERENCES `questionnaires` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_evaluations_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_evaluations_type` FOREIGN KEY (`evaluation_type_id`) REFERENCES `evaluation_types` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `evaluation_periods`
--
ALTER TABLE `evaluation_periods`
  ADD CONSTRAINT `fk_evaluation_periods_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_evaluation_periods_type` FOREIGN KEY (`evaluation_type_id`) REFERENCES `evaluation_types` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `evaluation_responses`
--
ALTER TABLE `evaluation_responses`
  ADD CONSTRAINT `fk_evaluation_responses_evaluation` FOREIGN KEY (`evaluation_id`) REFERENCES `evaluations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_evaluation_responses_question` FOREIGN KEY (`question_id`) REFERENCES `questions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `peer_evaluation_assignments`
--
ALTER TABLE `peer_evaluation_assignments`
  ADD CONSTRAINT `fk_peer_eval_assignments_evaluatee` FOREIGN KEY (`evaluatee_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_peer_eval_assignments_evaluator` FOREIGN KEY (`evaluator_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_peer_eval_assignments_room` FOREIGN KEY (`room_id`) REFERENCES `peer_evaluation_rooms` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_peer_eval_assignments_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `peer_evaluation_rooms`
--
ALTER TABLE `peer_evaluation_rooms`
  ADD CONSTRAINT `fk_peer_evaluation_rooms_coordinator` FOREIGN KEY (`coordinator_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_peer_evaluation_rooms_dean` FOREIGN KEY (`dean_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_peer_evaluation_rooms_program` FOREIGN KEY (`program_id`) REFERENCES `programs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_peer_evaluation_rooms_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `peer_evaluation_room_members`
--
ALTER TABLE `peer_evaluation_room_members`
  ADD CONSTRAINT `fk_peer_evaluation_room_members_professor` FOREIGN KEY (`professor_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_peer_evaluation_room_members_room` FOREIGN KEY (`room_id`) REFERENCES `peer_evaluation_rooms` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `profile_photos`
--
ALTER TABLE `profile_photos`
  ADD CONSTRAINT `fk_profile_photos_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `programs`
--
ALTER TABLE `programs`
  ADD CONSTRAINT `fk_programs_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `questionnaires`
--
ALTER TABLE `questionnaires`
  ADD CONSTRAINT `fk_questionnaires_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_questionnaires_type` FOREIGN KEY (`evaluation_type_id`) REFERENCES `evaluation_types` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `questionnaire_sections`
--
ALTER TABLE `questionnaire_sections`
  ADD CONSTRAINT `fk_questionnaire_sections_questionnaire` FOREIGN KEY (`questionnaire_id`) REFERENCES `questionnaires` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `questions`
--
ALTER TABLE `questions`
  ADD CONSTRAINT `fk_questions_questionnaire` FOREIGN KEY (`questionnaire_id`) REFERENCES `questionnaires` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_questions_section` FOREIGN KEY (`section_id`) REFERENCES `questionnaire_sections` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_questions_type` FOREIGN KEY (`question_type_id`) REFERENCES `question_types` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `staff_profiles`
--
ALTER TABLE `staff_profiles`
  ADD CONSTRAINT `fk_staff_profiles_employment_type` FOREIGN KEY (`employment_type_id`) REFERENCES `employment_types` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_staff_profiles_program` FOREIGN KEY (`program_id`) REFERENCES `programs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_staff_profiles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `student_course_enrollments`
--
ALTER TABLE `student_course_enrollments`
  ADD CONSTRAINT `fk_student_course_enrollments_course` FOREIGN KEY (`course_offering_id`) REFERENCES `course_offerings` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_student_course_enrollments_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `student_profiles`
--
ALTER TABLE `student_profiles`
  ADD CONSTRAINT `fk_student_profiles_program` FOREIGN KEY (`program_id`) REFERENCES `programs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_student_profiles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `subjects`
--
ALTER TABLE `subjects`
  ADD CONSTRAINT `fk_subjects_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_users_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `user_profile_data`
--
ALTER TABLE `user_profile_data`
  ADD CONSTRAINT `fk_user_profile_data_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
