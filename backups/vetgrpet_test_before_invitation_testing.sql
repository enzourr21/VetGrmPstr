-- MySQL dump 10.13  Distrib 8.4.13, for Win64 (x86_64)
--
-- Host: 127.0.0.1    Database: vetgrpet_test
-- ------------------------------------------------------
-- Server version	8.4.13

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Current Database: `vetgrpet_test`
--

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `vetgrpet_test` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci */ /*!80016 DEFAULT ENCRYPTION='N' */;

USE `vetgrpet_test`;

--
-- Table structure for table `account_role`
--

DROP TABLE IF EXISTS `account_role`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `account_role` (
  `account_role_id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_account_id` int unsigned NOT NULL,
  `role_id` int unsigned NOT NULL,
  `business_unit_id` int unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`account_role_id`),
  UNIQUE KEY `uk_account_role` (`user_account_id`,`role_id`,`business_unit_id`),
  KEY `role_id` (`role_id`),
  KEY `business_unit_id` (`business_unit_id`),
  CONSTRAINT `account_role_ibfk_1` FOREIGN KEY (`user_account_id`) REFERENCES `user_account` (`user_account_id`),
  CONSTRAINT `account_role_ibfk_2` FOREIGN KEY (`role_id`) REFERENCES `role` (`role_id`),
  CONSTRAINT `account_role_ibfk_3` FOREIGN KEY (`business_unit_id`) REFERENCES `business_unit` (`business_unit_id`)
) ENGINE=InnoDB AUTO_INCREMENT=507 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `account_role`
--

LOCK TABLES `account_role` WRITE;
/*!40000 ALTER TABLE `account_role` DISABLE KEYS */;
INSERT INTO `account_role` VALUES (79,211,6,1,'2026-11-17 08:15:48'),(469,592,10,2,'2026-11-18 03:31:37'),(470,593,8,3,'2026-11-18 03:31:37'),(471,594,8,2,'2026-11-18 03:31:37'),(472,595,8,1,'2026-11-18 03:31:37'),(473,596,11,2,'2026-11-18 03:31:37'),(474,597,9,3,'2026-11-18 03:31:37'),(475,597,4,3,'2026-11-18 03:31:37'),(476,597,5,3,'2026-11-18 03:31:37'),(477,604,14,1,'2026-11-27 11:14:27'),(478,605,14,1,'2026-11-27 11:15:17'),(479,606,13,1,'2026-11-27 11:42:37'),(480,607,7,1,'2026-11-27 16:16:18'),(481,608,7,2,'2026-11-27 16:16:18'),(482,609,7,3,'2026-11-27 16:16:18');
/*!40000 ALTER TABLE `account_role` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `appointment`
--

DROP TABLE IF EXISTS `appointment`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `appointment` (
  `appointment_id` int unsigned NOT NULL AUTO_INCREMENT,
  `business_unit_id` int unsigned NOT NULL,
  `branch_id` int unsigned NOT NULL,
  `customer_id` int unsigned NOT NULL,
  `appointment_status_id` int unsigned NOT NULL,
  `appointment_type` enum('medical','grooming') NOT NULL,
  `preferred_date` date NOT NULL,
  `preferred_time` time DEFAULT NULL,
  `appointment_begin` datetime DEFAULT NULL,
  `appointment_end` datetime DEFAULT NULL,
  `notes` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`appointment_id`),
  KEY `business_unit_id` (`business_unit_id`),
  KEY `branch_id` (`branch_id`),
  KEY `customer_id` (`customer_id`),
  KEY `appointment_status_id` (`appointment_status_id`),
  CONSTRAINT `appointment_ibfk_1` FOREIGN KEY (`business_unit_id`) REFERENCES `business_unit` (`business_unit_id`),
  CONSTRAINT `appointment_ibfk_2` FOREIGN KEY (`branch_id`) REFERENCES `branch` (`branch_id`),
  CONSTRAINT `appointment_ibfk_3` FOREIGN KEY (`customer_id`) REFERENCES `customer` (`customer_id`),
  CONSTRAINT `appointment_ibfk_4` FOREIGN KEY (`appointment_status_id`) REFERENCES `appointment_status` (`appointment_status_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `appointment`
--

LOCK TABLES `appointment` WRITE;
/*!40000 ALTER TABLE `appointment` DISABLE KEYS */;
/*!40000 ALTER TABLE `appointment` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `appointment_pet`
--

DROP TABLE IF EXISTS `appointment_pet`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `appointment_pet` (
  `appointment_pet_id` int unsigned NOT NULL AUTO_INCREMENT,
  `appointment_id` int unsigned NOT NULL,
  `pet_id` int unsigned NOT NULL,
  PRIMARY KEY (`appointment_pet_id`),
  UNIQUE KEY `uk_appointment_pet` (`appointment_id`,`pet_id`),
  KEY `pet_id` (`pet_id`),
  CONSTRAINT `appointment_pet_ibfk_1` FOREIGN KEY (`appointment_id`) REFERENCES `appointment` (`appointment_id`),
  CONSTRAINT `appointment_pet_ibfk_2` FOREIGN KEY (`pet_id`) REFERENCES `pet` (`pet_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `appointment_pet`
--

LOCK TABLES `appointment_pet` WRITE;
/*!40000 ALTER TABLE `appointment_pet` DISABLE KEYS */;
/*!40000 ALTER TABLE `appointment_pet` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `appointment_status`
--

DROP TABLE IF EXISTS `appointment_status`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `appointment_status` (
  `appointment_status_id` int unsigned NOT NULL AUTO_INCREMENT,
  `status_name` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`appointment_status_id`),
  UNIQUE KEY `status_name` (`status_name`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `appointment_status`
--

LOCK TABLES `appointment_status` WRITE;
/*!40000 ALTER TABLE `appointment_status` DISABLE KEYS */;
INSERT INTO `appointment_status` VALUES (1,'Pending','Request submitted by customer, awaiting receptionist',1,'2026-11-18 03:31:37'),(2,'Awaiting Customer Response','Receptionist is waiting for the customer',2,'2026-11-18 03:31:37'),(3,'Confirmed','Request accepted by receptionist',3,'2026-11-18 03:31:37'),(4,'Rejected','Request declined',4,'2026-11-18 03:31:37'),(5,'Cancelled','Cancelled',5,'2026-11-18 03:31:37'),(6,'In Progress','Service underway',6,'2026-11-18 03:31:37'),(7,'Completed','Service finished',7,'2026-11-18 03:31:37'),(8,'No-Show','Customer did not attend',8,'2026-11-18 03:31:37');
/*!40000 ALTER TABLE `appointment_status` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `audit_log`
--

DROP TABLE IF EXISTS `audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_log` (
  `log_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `actor_user_id` int unsigned DEFAULT NULL,
  `business_unit_id` int unsigned DEFAULT NULL,
  `action_code` varchar(100) NOT NULL,
  `target_type` varchar(60) DEFAULT NULL,
  `target_id` int unsigned DEFAULT NULL,
  `affected_employee_id` int unsigned DEFAULT NULL,
  `summary` varchar(500) NOT NULL,
  `old_value` varchar(255) DEFAULT NULL,
  `new_value` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`),
  KEY `actor_user_id` (`actor_user_id`),
  KEY `idx_audit_unit_created` (`business_unit_id`,`created_at`),
  KEY `idx_audit_affected` (`affected_employee_id`),
  CONSTRAINT `audit_log_ibfk_1` FOREIGN KEY (`actor_user_id`) REFERENCES `user_account` (`user_account_id`) ON DELETE SET NULL,
  CONSTRAINT `audit_log_ibfk_2` FOREIGN KEY (`business_unit_id`) REFERENCES `business_unit` (`business_unit_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2449 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_log`
--

LOCK TABLES `audit_log` WRITE;
/*!40000 ALTER TABLE `audit_log` DISABLE KEYS */;
INSERT INTO `audit_log` VALUES (711,211,NULL,'super_admin.provisioned','user_account',211,NULL,'Initial Super Administrator account (superadmin@vetgrpet.local) provisioned via controlled setup.',NULL,NULL,'CLI','2026-11-17 08:15:48'),(712,211,NULL,'auth.login.employee','user_account',211,NULL,'Authenticated in employee/admin session via email (/super-admin).',NULL,NULL,'127.0.0.1','2026-11-17 08:16:37'),(826,211,NULL,'auth.login.employee','user_account',211,NULL,'Authenticated in employee/admin session via email (/super-admin).',NULL,NULL,'127.0.0.1','2026-11-17 09:35:21'),(2355,211,NULL,'auth.login.employee','user_account',211,NULL,'Authenticated in employee/admin session via email (/super-admin).',NULL,NULL,'127.0.0.1','2026-11-17 11:55:24'),(2356,211,NULL,'auth.login.employee','user_account',211,NULL,'Authenticated in employee/admin session via email (/super-admin).',NULL,NULL,'127.0.0.1','2026-11-19 07:05:05'),(2357,211,NULL,'auth.logout','user_account',211,NULL,'User signed out and session invalidated.',NULL,NULL,'127.0.0.1','2026-11-19 07:05:35'),(2358,211,NULL,'auth.login.employee','user_account',211,NULL,'Authenticated in employee/admin session via email (/super-admin).',NULL,NULL,'127.0.0.1','2026-11-19 07:12:15'),(2359,211,NULL,'auth.login.employee','user_account',211,NULL,'Authenticated in employee/admin session via email (/super-admin).',NULL,NULL,'127.0.0.1','2026-11-19 11:50:43'),(2360,211,NULL,'auth.login.employee','user_account',211,NULL,'Authenticated in employee/admin session via email (/super-admin).',NULL,NULL,'127.0.0.1','2026-11-20 16:55:31'),(2361,211,NULL,'auth.login.employee','user_account',211,NULL,'Authenticated in employee/admin session via email (/super-admin).',NULL,NULL,'127.0.0.1','2026-11-27 10:41:27'),(2362,211,1,'employee.created','employee',476,476,'Created employee account Gian Lorenzo Machutes (gian.machutes6921@gmail.com) in Pet Store as \'Delivery Personnel\' with role(s): DELIVERY_PERSONNEL.',NULL,'Position: Delivery Personnel; Roles: DELIVERY_PERSONNEL','127.0.0.1','2026-11-27 11:14:27'),(2363,211,1,'invitation.created','employee_invitation',1,476,'Created employee invitation #1 for gian.machutes6921@gmail.com in Pet Store (expires 2026-11-29 11:14:27 UTC).',NULL,'pending','127.0.0.1','2026-11-27 11:14:27'),(2364,211,1,'employee.created','employee',477,477,'Created employee account Gian Lorenzo Machutes (ty202500497@wmsu.edu.ph) in Pet Store as \'Delivery Personnel\' with role(s): DELIVERY_PERSONNEL.',NULL,'Position: Delivery Personnel; Roles: DELIVERY_PERSONNEL','127.0.0.1','2026-11-27 11:15:17'),(2365,211,1,'invitation.created','employee_invitation',2,477,'Created employee invitation #2 for ty202500497@wmsu.edu.ph in Pet Store (expires 2026-11-29 11:15:17 UTC).',NULL,'pending','127.0.0.1','2026-11-27 11:15:17'),(2366,211,1,'employee.deactivated','employee',476,476,'Deactivated account of Gian Lorenzo Machutes (gian.machutes6921@gmail.com) in Pet Store.','active','inactive','127.0.0.1','2026-11-27 11:38:40'),(2367,211,1,'employee.deactivated','employee',477,477,'Deactivated account of Gian Lorenzo Machutes (ty202500497@wmsu.edu.ph) in Pet Store.','active','inactive','127.0.0.1','2026-11-27 11:38:44'),(2368,211,1,'employee.created','employee',478,478,'Created employee account Cruz Cruz (crutcrutmeow@gmail.com) in Pet Store as \'Inventory Manager\' with role(s): INVENTORY_MANAGER.',NULL,'Position: Inventory Manager; Roles: INVENTORY_MANAGER','127.0.0.1','2026-11-27 11:42:37'),(2369,211,1,'invitation.created','employee_invitation',3,478,'Created employee invitation #3 for crutcrutmeow@gmail.com in Pet Store (expires 2026-11-29 11:42:37 UTC).',NULL,'pending','127.0.0.1','2026-11-27 11:42:37'),(2370,211,1,'employee.reactivated','employee',477,477,'Reactivated account of Gian Lorenzo Machutes (ty202500497@wmsu.edu.ph) in Pet Store.','inactive','active','127.0.0.1','2026-11-27 11:47:36'),(2371,211,1,'invitation.revoked','employee_invitation',3,478,'Revoked pending employee invitation #3 (reason: admin_revoked).','pending','revoked','127.0.0.1','2026-11-27 13:18:53'),(2372,211,1,'invitation.revoked','employee_invitation',2,477,'Revoked pending employee invitation #2 (reason: admin_revoked).','pending','revoked','127.0.0.1','2026-11-27 13:18:58'),(2373,211,NULL,'auth.logout','user_account',211,NULL,'User signed out and session invalidated.',NULL,NULL,'127.0.0.1','2026-11-27 15:01:30'),(2374,211,NULL,'auth.login.employee','user_account',211,NULL,'Authenticated in employee/admin session via email (/super-admin).',NULL,NULL,'127.0.0.1','2026-11-27 16:01:18'),(2375,211,1,'sysadmin.provisioned','user_account',607,NULL,'System Administrator account (sysadmin.petstore@vetgrpet.local) created for Pet Store.',NULL,NULL,'CLI','2026-11-27 16:16:18'),(2376,211,2,'sysadmin.provisioned','user_account',608,NULL,'System Administrator account (sysadmin.vetclinic@vetgrpet.local) created for Veterinary Clinic.',NULL,NULL,'CLI','2026-11-27 16:16:18'),(2377,211,3,'sysadmin.provisioned','user_account',609,NULL,'System Administrator account (sysadmin.grooming@vetgrpet.local) created for Grooming Services.',NULL,NULL,'CLI','2026-11-27 16:16:18'),(2378,607,NULL,'auth.login.staff','user_account',607,NULL,'Staff email sign-in (/sysadmin).',NULL,NULL,'127.0.0.1','2026-11-27 16:16:21'),(2379,607,1,'invitation.created','employee_invitation',4,NULL,'Created employee invitation #4 for Pat Tester (ps.test.pat@vetgrpet.local) in Pet Store as \'Inventory Manager\' with role(s): INVENTORY_MANAGER, DELIVERY_PERSONNEL (new employee onboarding; expires 2026-11-29 16:16:21 UTC).',NULL,'Position: Inventory Manager; Roles: INVENTORY_MANAGER, DELIVERY_PERSONNEL','127.0.0.1','2026-11-27 16:16:21'),(2380,607,1,'invitation.created','employee_invitation',5,NULL,'Created employee invitation #5 for Auto Role Employee (ps.test.autorole@vetgrpet.local) in Pet Store as \'Inventory Manager\' with role(s): INVENTORY_MANAGER (new employee onboarding; expires 2026-11-29 16:16:27 UTC).',NULL,'Position: Inventory Manager; Roles: INVENTORY_MANAGER','127.0.0.1','2026-11-27 16:16:27'),(2381,608,NULL,'auth.login.staff','user_account',608,NULL,'Staff email sign-in (/sysadmin).',NULL,NULL,'127.0.0.1','2026-11-27 16:16:31'),(2382,609,NULL,'auth.login.staff','user_account',609,NULL,'Staff email sign-in (/sysadmin).',NULL,NULL,'127.0.0.1','2026-11-27 16:16:32'),(2410,608,NULL,'auth.login.staff','user_account',608,NULL,'Staff email sign-in (/sysadmin).',NULL,NULL,'127.0.0.1','2026-11-27 16:22:35'),(2415,607,NULL,'auth.login.staff','user_account',607,NULL,'Staff email sign-in (/sysadmin).',NULL,NULL,'127.0.0.1','2026-11-27 16:22:40'),(2429,609,NULL,'auth.login.staff','user_account',609,NULL,'Staff email sign-in (/sysadmin).',NULL,NULL,'127.0.0.1','2026-11-27 16:22:51'),(2431,608,NULL,'auth.login.staff','user_account',608,NULL,'Staff email sign-in (/sysadmin).',NULL,NULL,'127.0.0.1','2026-11-27 16:26:21'),(2436,607,NULL,'auth.login.staff','user_account',607,NULL,'Staff email sign-in (/sysadmin).',NULL,NULL,'127.0.0.1','2026-11-27 16:26:26'),(2437,608,NULL,'auth.login.staff','user_account',608,NULL,'Staff email sign-in (/sysadmin).',NULL,NULL,'127.0.0.1','2026-11-27 16:26:26'),(2442,607,NULL,'auth.login.staff','user_account',607,NULL,'Staff email sign-in (/sysadmin).',NULL,NULL,'127.0.0.1','2026-11-27 16:26:32'),(2443,608,NULL,'auth.login.staff','user_account',608,NULL,'Staff email sign-in (/sysadmin).',NULL,NULL,'127.0.0.1','2026-11-27 16:40:32'),(2448,607,NULL,'auth.login.staff','user_account',607,NULL,'Staff email sign-in (/sysadmin).',NULL,NULL,'127.0.0.1','2026-11-27 16:40:36');
/*!40000 ALTER TABLE `audit_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `branch`
--

DROP TABLE IF EXISTS `branch`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `branch` (
  `branch_id` int unsigned NOT NULL AUTO_INCREMENT,
  `business_unit_id` int unsigned NOT NULL,
  `branch_name` varchar(150) NOT NULL,
  `address_line1` varchar(255) DEFAULT NULL,
  `address_line2` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `province` varchar(100) DEFAULT NULL,
  `postal_code` varchar(20) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `branch_status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`branch_id`),
  UNIQUE KEY `uk_branch_name_unit` (`business_unit_id`,`branch_name`),
  CONSTRAINT `branch_ibfk_1` FOREIGN KEY (`business_unit_id`) REFERENCES `business_unit` (`business_unit_id`)
) ENGINE=InnoDB AUTO_INCREMENT=80 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `branch`
--

LOCK TABLES `branch` WRITE;
/*!40000 ALTER TABLE `branch` DISABLE KEYS */;
INSERT INTO `branch` VALUES (62,1,'Pet Store Test Branch',NULL,NULL,'Test City',NULL,NULL,'000-0000',NULL,'active','2026-11-17 09:26:19','2026-11-17 09:26:19'),(77,3,'Grooming Services - Main Branch',NULL,NULL,'Sample City',NULL,NULL,'000-000-0000',NULL,'active','2026-11-18 03:31:37','2026-11-18 03:31:37'),(78,1,'Pet Store - Main Branch',NULL,NULL,'Sample City',NULL,NULL,'000-000-0000',NULL,'active','2026-11-18 03:31:37','2026-11-18 03:31:37'),(79,2,'Veterinary Clinic - Main Branch',NULL,NULL,'Sample City',NULL,NULL,'000-000-0000',NULL,'active','2026-11-18 03:31:37','2026-11-18 03:31:37');
/*!40000 ALTER TABLE `branch` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `business_position`
--

DROP TABLE IF EXISTS `business_position`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `business_position` (
  `position_id` int unsigned NOT NULL AUTO_INCREMENT,
  `business_unit_id` int unsigned NOT NULL,
  `position_name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `position_status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`position_id`),
  UNIQUE KEY `uk_unit_position` (`business_unit_id`,`position_name`),
  CONSTRAINT `business_position_ibfk_1` FOREIGN KEY (`business_unit_id`) REFERENCES `business_unit` (`business_unit_id`)
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `business_position`
--

LOCK TABLES `business_position` WRITE;
/*!40000 ALTER TABLE `business_position` DISABLE KEYS */;
INSERT INTO `business_position` VALUES (6,2,'Inventory Manager','Oversees medical supplies, pharmaceuticals, receiving, and inventory accuracy','active','2026-11-14 14:22:09','2026-11-14 14:22:09'),(7,3,'Bather','Bathing, brushing, ear cleaning, and nail trimming','active','2026-11-14 14:22:09','2026-11-14 14:22:09'),(8,3,'Inventory Manager','Grooming stock oversight: tracks inventory levels, receiving, and supplies','active','2026-11-14 14:22:09','2026-11-14 14:22:09'),(9,1,'System Administrator','Manages Pet Store accounts, roles, and positions','active','2026-11-18 03:31:37','2026-11-18 03:31:37'),(10,1,'Business Manager — Pet Store','Operational manager for Pet Store','active','2026-11-18 03:31:37','2026-11-18 03:31:37'),(11,1,'Inventory Manager','Oversees Pet Store stock levels, receiving, and inventory accuracy','active','2026-11-18 03:31:37','2026-11-18 03:31:37'),(12,1,'Receptionist','Front-desk customer assistance for Pet Store','active','2026-11-18 03:31:37','2026-11-18 03:31:37'),(13,2,'System Administrator','Manages Veterinary Clinic accounts, roles, and positions','active','2026-11-18 03:31:37','2026-11-18 03:31:37'),(14,2,'Business Manager — Veterinary Clinic','Operational manager for Veterinary Clinic','active','2026-11-18 03:31:37','2026-11-18 03:31:37'),(15,2,'Lead Veterinarian','Senior veterinarian overseeing clinical consultations and records','active','2026-11-18 03:31:37','2026-11-18 03:31:37'),(16,2,'Associate Veterinarian','Licensed veterinarian performing exams and treatments','active','2026-11-18 03:31:37','2026-11-18 03:31:37'),(17,2,'Veterinary Nurse','Administers prescribed treatments and documents nursing care','active','2026-11-18 03:31:37','2026-11-18 03:31:37'),(18,2,'Veterinary Technician','Performs diagnostic and technical clinical procedures','inactive','2026-11-18 03:31:37','2026-11-19 12:34:00'),(19,2,'Receptionist','Clinical front-desk and appointment coordination','active','2026-11-18 03:31:37','2026-11-18 03:31:37'),(20,3,'System Administrator','Manages Grooming Services accounts, roles, and positions','active','2026-11-18 03:31:37','2026-11-18 03:31:37'),(21,3,'Business Manager — Grooming Services','Operational manager for Grooming Services','active','2026-11-18 03:31:37','2026-11-18 03:31:37'),(22,3,'Master Stylist','Advanced styling, breed standard cuts, and specialized grooming','active','2026-11-18 03:31:37','2026-11-18 03:31:37'),(23,3,'Receptionist','Grooming appointment intake and scheduling','active','2026-11-18 03:31:37','2026-11-18 03:31:37'),(24,3,'Stylist','Pet styling, haircuts, and coat finishing','active','2026-11-18 03:31:37','2026-11-18 03:31:37'),(26,1,'Delivery Personnel','Handles Pet Store order dispatch, delivery tracking, and COD collection','active','2026-11-19 12:27:31','2026-11-19 12:27:31'),(27,2,'Assistant Nurse','Assists veterinary nurses and doctors with patient care and clinical preparation','active','2026-11-19 12:34:00','2026-11-19 12:34:00');
/*!40000 ALTER TABLE `business_position` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `business_product`
--

DROP TABLE IF EXISTS `business_product`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `business_product` (
  `business_product_id` int unsigned NOT NULL AUTO_INCREMENT,
  `business_unit_id` int unsigned NOT NULL,
  `product_id` int unsigned NOT NULL,
  `selling_price` decimal(10,2) NOT NULL,
  `product_status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `requires_batch_tracking` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`business_product_id`),
  UNIQUE KEY `uk_business_product` (`business_unit_id`,`product_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `business_product_ibfk_1` FOREIGN KEY (`business_unit_id`) REFERENCES `business_unit` (`business_unit_id`),
  CONSTRAINT `business_product_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `product` (`product_id`)
) ENGINE=InnoDB AUTO_INCREMENT=469 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `business_product`
--

LOCK TABLES `business_product` WRITE;
/*!40000 ALTER TABLE `business_product` DISABLE KEYS */;
INSERT INTO `business_product` VALUES (462,1,456,13.50,'active',0,'2026-11-18 03:31:37','2026-11-18 03:31:37'),(463,3,456,12.50,'active',0,'2026-11-18 03:31:37','2026-11-18 03:31:37'),(464,3,457,18.00,'active',0,'2026-11-18 03:31:37','2026-11-18 03:31:37'),(465,3,458,9.75,'active',0,'2026-11-18 03:31:37','2026-11-18 03:31:37'),(466,3,459,11.00,'active',0,'2026-11-18 03:31:37','2026-11-18 03:31:37'),(467,1,460,24.00,'active',0,'2026-11-18 03:31:37','2026-11-18 03:31:37'),(468,1,461,5.50,'active',0,'2026-11-18 03:31:37','2026-11-18 03:31:37');
/*!40000 ALTER TABLE `business_product` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `business_settings`
--

DROP TABLE IF EXISTS `business_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `business_settings` (
  `setting_id` int unsigned NOT NULL AUTO_INCREMENT,
  `business_unit_id` int unsigned NOT NULL,
  `business_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `business_email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_number` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `max_appointment_overlap_minutes` int unsigned NOT NULL DEFAULT '15',
  `cod_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `delivery_fee` decimal(10,2) DEFAULT NULL,
  `auto_verify_cod_remittance` tinyint(1) NOT NULL DEFAULT '0',
  `gcash_mobile` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gcash_verified` tinyint(1) NOT NULL DEFAULT '0',
  `maya_mobile` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `maya_verified` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_id`),
  UNIQUE KEY `business_unit_id` (`business_unit_id`),
  CONSTRAINT `business_settings_ibfk_1` FOREIGN KEY (`business_unit_id`) REFERENCES `business_unit` (`business_unit_id`),
  CONSTRAINT `chk_biz_settings_auto_verify_bool` CHECK ((`auto_verify_cod_remittance` in (0,1))),
  CONSTRAINT `chk_biz_settings_delivery_fee_nonneg` CHECK (((`delivery_fee` is null) or (`delivery_fee` >= 0)))
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `business_settings`
--

LOCK TABLES `business_settings` WRITE;
/*!40000 ALTER TABLE `business_settings` DISABLE KEYS */;
INSERT INTO `business_settings` VALUES (1,1,'VETGRPET Pet Store','petstore@vetgrpet.local','09175550100',15,1,NULL,0,NULL,0,NULL,0,'2026-11-14 14:22:09','2026-11-17 11:50:32'),(11,2,'VETGRPET Veterinary Clinic','veterinaryclinic@vetgrpet.local','09175550100',15,1,NULL,0,NULL,0,NULL,0,'2026-11-17 11:49:01','2026-11-17 11:49:01');
/*!40000 ALTER TABLE `business_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `business_unit`
--

DROP TABLE IF EXISTS `business_unit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `business_unit` (
  `business_unit_id` int unsigned NOT NULL AUTO_INCREMENT,
  `business_unit_name` varchar(100) NOT NULL,
  `business_unit_status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`business_unit_id`),
  UNIQUE KEY `business_unit_name` (`business_unit_name`)
) ENGINE=InnoDB AUTO_INCREMENT=85 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `business_unit`
--

LOCK TABLES `business_unit` WRITE;
/*!40000 ALTER TABLE `business_unit` DISABLE KEYS */;
INSERT INTO `business_unit` VALUES (1,'Pet Store','active','2026-11-14 14:21:54','2026-11-14 14:21:54'),(2,'Veterinary Clinic','active','2026-11-14 14:21:54','2026-11-14 14:21:54'),(3,'Grooming Services','active','2026-11-14 14:21:54','2026-11-17 09:27:18');
/*!40000 ALTER TABLE `business_unit` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cart`
--

DROP TABLE IF EXISTS `cart`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cart` (
  `cart_id` int unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` int unsigned NOT NULL,
  `business_unit_id` int unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`cart_id`),
  UNIQUE KEY `uk_customer_cart` (`customer_id`,`business_unit_id`),
  KEY `business_unit_id` (`business_unit_id`),
  CONSTRAINT `cart_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customer` (`customer_id`),
  CONSTRAINT `cart_ibfk_2` FOREIGN KEY (`business_unit_id`) REFERENCES `business_unit` (`business_unit_id`)
) ENGINE=InnoDB AUTO_INCREMENT=178 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cart`
--

LOCK TABLES `cart` WRITE;
/*!40000 ALTER TABLE `cart` DISABLE KEYS */;
/*!40000 ALTER TABLE `cart` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cart_item`
--

DROP TABLE IF EXISTS `cart_item`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cart_item` (
  `cart_item_id` int unsigned NOT NULL AUTO_INCREMENT,
  `cart_id` int unsigned NOT NULL,
  `business_product_id` int unsigned NOT NULL,
  `quantity` int NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`cart_item_id`),
  UNIQUE KEY `uk_cart_product` (`cart_id`,`business_product_id`),
  KEY `business_product_id` (`business_product_id`),
  CONSTRAINT `cart_item_ibfk_1` FOREIGN KEY (`cart_id`) REFERENCES `cart` (`cart_id`),
  CONSTRAINT `cart_item_ibfk_2` FOREIGN KEY (`business_product_id`) REFERENCES `business_product` (`business_product_id`)
) ENGINE=InnoDB AUTO_INCREMENT=79 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cart_item`
--

LOCK TABLES `cart_item` WRITE;
/*!40000 ALTER TABLE `cart_item` DISABLE KEYS */;
/*!40000 ALTER TABLE `cart_item` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `checkout_attempt`
--

DROP TABLE IF EXISTS `checkout_attempt`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `checkout_attempt` (
  `attempt_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `attempt_token` char(64) NOT NULL,
  `idempotency_key` varchar(128) NOT NULL,
  `customer_id` int unsigned NOT NULL,
  `business_unit_id` int unsigned NOT NULL,
  `branch_id` int unsigned NOT NULL,
  `cart_id` int unsigned DEFAULT NULL,
  `payment_channel` enum('cod','online') NOT NULL,
  `fulfillment_method` enum('pickup','delivery') NOT NULL,
  `delivery_address_line1` varchar(255) DEFAULT NULL,
  `delivery_address_line2` varchar(255) DEFAULT NULL,
  `delivery_city` varchar(100) DEFAULT NULL,
  `delivery_province` varchar(100) DEFAULT NULL,
  `delivery_postal_code` varchar(20) DEFAULT NULL,
  `notes` text,
  `subtotal_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `delivery_fee` decimal(12,2) NOT NULL DEFAULT '0.00',
  `expected_amount` decimal(12,2) NOT NULL,
  `attempt_status` enum('initiated','awaiting_payment','completed','expired','failed','reconciliation_required') NOT NULL DEFAULT 'initiated',
  `active_guard_key` varchar(64) GENERATED ALWAYS AS (if((`attempt_status` in (_utf8mb4'initiated',_utf8mb4'awaiting_payment')),concat(`customer_id`,_utf8mb4':',`business_unit_id`),NULL)) VIRTUAL,
  `order_id` int unsigned DEFAULT NULL,
  `provider_name` varchar(50) DEFAULT NULL,
  `provider_session_id` varchar(191) DEFAULT NULL,
  `provider_payment_id` varchar(191) DEFAULT NULL,
  `provider_checkout_url` varchar(500) DEFAULT NULL,
  `failure_reason` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`attempt_id`),
  UNIQUE KEY `uk_checkout_attempt_token` (`attempt_token`),
  UNIQUE KEY `uk_checkout_attempt_idempotency` (`customer_id`,`business_unit_id`,`idempotency_key`),
  UNIQUE KEY `uk_checkout_attempt_order` (`order_id`),
  UNIQUE KEY `uk_checkout_attempt_provider_payment` (`provider_name`,`provider_payment_id`),
  UNIQUE KEY `uk_checkout_attempt_active_customer_bu` (`active_guard_key`),
  KEY `idx_checkout_attempt_customer_bu_status` (`customer_id`,`business_unit_id`,`attempt_status`),
  KEY `idx_checkout_attempt_provider_session` (`provider_name`,`provider_session_id`),
  KEY `fk_chk_att_bu` (`business_unit_id`),
  KEY `fk_chk_att_branch` (`branch_id`),
  KEY `fk_chk_att_cart` (`cart_id`),
  CONSTRAINT `fk_chk_att_branch` FOREIGN KEY (`branch_id`) REFERENCES `branch` (`branch_id`),
  CONSTRAINT `fk_chk_att_bu` FOREIGN KEY (`business_unit_id`) REFERENCES `business_unit` (`business_unit_id`),
  CONSTRAINT `fk_chk_att_cart` FOREIGN KEY (`cart_id`) REFERENCES `cart` (`cart_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_chk_att_customer` FOREIGN KEY (`customer_id`) REFERENCES `customer` (`customer_id`),
  CONSTRAINT `fk_chk_att_order` FOREIGN KEY (`order_id`) REFERENCES `customer_order` (`order_id`),
  CONSTRAINT `chk_chk_att_amount_nonneg` CHECK ((`expected_amount` >= 0)),
  CONSTRAINT `chk_chk_att_delivery_fee_nonneg` CHECK ((`delivery_fee` >= 0)),
  CONSTRAINT `chk_chk_att_subtotal_nonneg` CHECK ((`subtotal_amount` >= 0))
) ENGINE=InnoDB AUTO_INCREMENT=56 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `checkout_attempt`
--

LOCK TABLES `checkout_attempt` WRITE;
/*!40000 ALTER TABLE `checkout_attempt` DISABLE KEYS */;
/*!40000 ALTER TABLE `checkout_attempt` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `courier_cod_remittance`
--

DROP TABLE IF EXISTS `courier_cod_remittance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `courier_cod_remittance` (
  `remittance_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `remittance_reference` varchar(50) NOT NULL,
  `business_unit_id` int unsigned NOT NULL,
  `courier_employee_id` int unsigned NOT NULL,
  `expected_total` decimal(12,2) NOT NULL,
  `remitted_total` decimal(12,2) NOT NULL,
  `verified_total` decimal(12,2) DEFAULT NULL,
  `remittance_status` enum('submitted','completed','verified','discrepancy','cancelled') NOT NULL DEFAULT 'submitted',
  `submitted_at` datetime NOT NULL,
  `verified_by_employee_id` int unsigned DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`remittance_id`),
  UNIQUE KEY `uk_cod_remittance_ref` (`remittance_reference`),
  UNIQUE KEY `uk_cod_remit_id_bu` (`remittance_id`,`business_unit_id`),
  KEY `idx_cod_remit_bu_status` (`business_unit_id`,`remittance_status`),
  KEY `idx_cod_remit_courier` (`courier_employee_id`,`remittance_status`),
  KEY `fk_cod_remit_verifier` (`verified_by_employee_id`),
  CONSTRAINT `fk_cod_remit_bu` FOREIGN KEY (`business_unit_id`) REFERENCES `business_unit` (`business_unit_id`),
  CONSTRAINT `fk_cod_remit_courier` FOREIGN KEY (`courier_employee_id`) REFERENCES `employee` (`employee_id`),
  CONSTRAINT `fk_cod_remit_verifier` FOREIGN KEY (`verified_by_employee_id`) REFERENCES `employee` (`employee_id`),
  CONSTRAINT `chk_cod_remit_expected_pos` CHECK ((`expected_total` > 0)),
  CONSTRAINT `chk_cod_remit_header_verified_tuple` CHECK ((((`remittance_status` in (_utf8mb4'submitted',_utf8mb4'completed')) and (`verified_total` is null) and (`verified_by_employee_id` is null) and (`verified_at` is null)) or ((`remittance_status` = _utf8mb4'verified') and (`verified_total` is not null) and (`verified_total` = `expected_total`) and (`verified_at` is not null)) or (`remittance_status` in (_utf8mb4'discrepancy',_utf8mb4'cancelled')))),
  CONSTRAINT `chk_cod_remit_no_self_verify` CHECK (((`verified_by_employee_id` is null) or (`verified_by_employee_id` <> `courier_employee_id`))),
  CONSTRAINT `chk_cod_remit_remitted_nonneg` CHECK ((`remitted_total` >= 0)),
  CONSTRAINT `chk_cod_remit_verified_nonneg` CHECK (((`verified_total` is null) or (`verified_total` >= 0)))
) ENGINE=InnoDB AUTO_INCREMENT=118 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `courier_cod_remittance`
--

LOCK TABLES `courier_cod_remittance` WRITE;
/*!40000 ALTER TABLE `courier_cod_remittance` DISABLE KEYS */;
/*!40000 ALTER TABLE `courier_cod_remittance` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `courier_cod_remittance_allocation`
--

DROP TABLE IF EXISTS `courier_cod_remittance_allocation`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `courier_cod_remittance_allocation` (
  `allocation_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `remittance_id` bigint unsigned NOT NULL,
  `payment_id` int unsigned NOT NULL,
  `order_id` int unsigned NOT NULL,
  `business_unit_id` int unsigned NOT NULL,
  `expected_amount` decimal(12,2) NOT NULL,
  `allocated_amount` decimal(12,2) NOT NULL,
  `allocation_status` enum('active','released') NOT NULL DEFAULT 'active',
  `handover_status` enum('submitted','completed') NOT NULL DEFAULT 'submitted',
  `completed_by_employee_id` int unsigned DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `verification_status` enum('pending','verified') NOT NULL DEFAULT 'pending',
  `verified_by_employee_id` int unsigned DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `verification_mode` enum('manual','auto_setting','manager_collection') DEFAULT NULL,
  `active_payment_id` int unsigned GENERATED ALWAYS AS (if((`allocation_status` = _utf8mb4'active'),`payment_id`,NULL)) VIRTUAL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`allocation_id`),
  UNIQUE KEY `uk_cod_alloc_remit_payment` (`remittance_id`,`payment_id`),
  UNIQUE KEY `uk_cod_alloc_active_payment` (`active_payment_id`),
  KEY `idx_cod_alloc_order` (`order_id`),
  KEY `idx_cod_alloc_bu_status` (`business_unit_id`,`allocation_status`),
  KEY `idx_cod_alloc_payment_order` (`payment_id`,`order_id`),
  KEY `idx_cod_alloc_order_bu` (`order_id`,`business_unit_id`),
  KEY `idx_cod_alloc_remit_bu` (`remittance_id`,`business_unit_id`),
  KEY `idx_cod_alloc_remit_lifecycle` (`remittance_id`,`allocation_status`,`handover_status`,`verification_status`),
  KEY `idx_cod_alloc_completed_emp` (`completed_by_employee_id`),
  KEY `idx_cod_alloc_verified_emp` (`verified_by_employee_id`),
  CONSTRAINT `fk_cod_alloc_bu` FOREIGN KEY (`business_unit_id`) REFERENCES `business_unit` (`business_unit_id`),
  CONSTRAINT `fk_cod_alloc_completed_emp` FOREIGN KEY (`completed_by_employee_id`) REFERENCES `employee` (`employee_id`),
  CONSTRAINT `fk_cod_alloc_order` FOREIGN KEY (`order_id`) REFERENCES `customer_order` (`order_id`),
  CONSTRAINT `fk_cod_alloc_order_bu` FOREIGN KEY (`order_id`, `business_unit_id`) REFERENCES `customer_order` (`order_id`, `business_unit_id`),
  CONSTRAINT `fk_cod_alloc_payment` FOREIGN KEY (`payment_id`) REFERENCES `payment` (`payment_id`),
  CONSTRAINT `fk_cod_alloc_payment_order` FOREIGN KEY (`payment_id`, `order_id`) REFERENCES `payment` (`payment_id`, `order_id`),
  CONSTRAINT `fk_cod_alloc_remittance` FOREIGN KEY (`remittance_id`) REFERENCES `courier_cod_remittance` (`remittance_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_cod_alloc_remittance_bu` FOREIGN KEY (`remittance_id`, `business_unit_id`) REFERENCES `courier_cod_remittance` (`remittance_id`, `business_unit_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_cod_alloc_verified_emp` FOREIGN KEY (`verified_by_employee_id`) REFERENCES `employee` (`employee_id`),
  CONSTRAINT `chk_cod_alloc_amount_bounds` CHECK (((`allocated_amount` >= 0) and (`allocated_amount` <= `expected_amount`))),
  CONSTRAINT `chk_cod_alloc_expected_pos` CHECK ((`expected_amount` > 0)),
  CONSTRAINT `chk_cod_alloc_handover_tuple` CHECK ((((`handover_status` = _utf8mb4'submitted') and (`completed_by_employee_id` is null) and (`completed_at` is null) and (`verification_status` = _utf8mb4'pending') and (`verified_by_employee_id` is null) and (`verified_at` is null) and (`verification_mode` is null)) or ((`handover_status` = _utf8mb4'completed') and (`completed_by_employee_id` is not null) and (`completed_at` is not null)))),
  CONSTRAINT `chk_cod_alloc_verification_mode_actor` CHECK (((`verification_mode` is null) or ((`verification_mode` = _utf8mb4'auto_setting') and (`verified_by_employee_id` is null)) or ((`verification_mode` = _utf8mb4'manual') and (`verified_by_employee_id` is not null)) or ((`verification_mode` = _utf8mb4'manager_collection') and (`verified_by_employee_id` is not null) and (`verified_by_employee_id` = `completed_by_employee_id`)))),
  CONSTRAINT `chk_cod_alloc_verification_tuple` CHECK ((((`verification_status` = _utf8mb4'pending') and (`verified_by_employee_id` is null) and (`verified_at` is null) and (`verification_mode` is null)) or ((`verification_status` = _utf8mb4'verified') and (`handover_status` = _utf8mb4'completed') and (`verified_at` is not null) and (`verification_mode` is not null))))
) ENGINE=InnoDB AUTO_INCREMENT=185 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `courier_cod_remittance_allocation`
--

LOCK TABLES `courier_cod_remittance_allocation` WRITE;
/*!40000 ALTER TABLE `courier_cod_remittance_allocation` DISABLE KEYS */;
/*!40000 ALTER TABLE `courier_cod_remittance_allocation` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customer`
--

DROP TABLE IF EXISTS `customer`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer` (
  `customer_id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_account_id` int unsigned NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `address_line1` varchar(255) DEFAULT NULL,
  `address_line2` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `province` varchar(100) DEFAULT NULL,
  `postal_code` varchar(20) DEFAULT NULL,
  `customer_status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`customer_id`),
  UNIQUE KEY `user_account_id` (`user_account_id`),
  CONSTRAINT `customer_ibfk_1` FOREIGN KEY (`user_account_id`) REFERENCES `user_account` (`user_account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=139 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customer`
--

LOCK TABLES `customer` WRITE;
/*!40000 ALTER TABLE `customer` DISABLE KEYS */;
INSERT INTO `customer` VALUES (62,211,'Evelyn',NULL,'Sterling',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-11-17 08:15:48','2026-11-17 08:15:48'),(118,598,'Oliver',NULL,'Vance','555-0101','123 Pinecrest Ave',NULL,'Sample City','Metro Province','1001','active','2026-11-18 03:31:37','2026-11-18 03:31:37'),(119,599,'Sophia',NULL,'Martinez','555-0102','456 Oakwood Blvd',NULL,'Sample City','Metro Province','1002','active','2026-11-18 03:31:37','2026-11-18 03:31:37'),(120,600,'Liam',NULL,'Gallagher','555-0103','789 Birch Lane',NULL,'Sample City','Metro Province','1003','active','2026-11-18 03:31:37','2026-11-18 03:31:37'),(121,601,'Emma',NULL,'Watson','555-0104','321 Maple St',NULL,'Sample City','Metro Province','1004','active','2026-11-18 03:31:37','2026-11-18 03:31:37'),(122,602,'Noah',NULL,'Bennett','555-0105','654 Cedar Way',NULL,'Sample City','Metro Province','1005','active','2026-11-18 03:31:37','2026-11-18 03:31:37'),(126,604,'Gian',NULL,'Lorenzo Machutes',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-11-27 11:14:27','2026-11-27 11:14:27'),(127,605,'Gian',NULL,'Lorenzo Machutes',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-11-27 11:15:17','2026-11-27 11:15:17'),(128,606,'Cruz',NULL,'Cruz',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-11-27 11:42:37','2026-11-27 11:42:37'),(129,607,'Adrian',NULL,'Mercer',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-11-27 16:16:18','2026-11-27 16:16:18'),(130,608,'Beatrice',NULL,'Solis',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-11-27 16:16:18','2026-11-27 16:16:18'),(131,609,'Caleb',NULL,'Thorne',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-11-27 16:16:18','2026-11-27 16:16:18');
/*!40000 ALTER TABLE `customer` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customer_order`
--

DROP TABLE IF EXISTS `customer_order`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_order` (
  `order_id` int unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` int unsigned NOT NULL,
  `business_unit_id` int unsigned NOT NULL,
  `branch_id` int unsigned NOT NULL,
  `order_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fulfillment_method` enum('pickup','delivery') NOT NULL DEFAULT 'pickup',
  `order_status` enum('pending','confirmed','processing','ready_for_pickup','out_for_delivery','completed','cancelled','delivery_failed') NOT NULL DEFAULT 'pending',
  `total_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `subtotal_amount` decimal(12,2) DEFAULT NULL,
  `delivery_fee` decimal(12,2) NOT NULL DEFAULT '0.00',
  `delivery_address_line1` varchar(255) DEFAULT NULL,
  `delivery_address_line2` varchar(255) DEFAULT NULL,
  `delivery_city` varchar(100) DEFAULT NULL,
  `delivery_province` varchar(100) DEFAULT NULL,
  `delivery_postal_code` varchar(20) DEFAULT NULL,
  `notes` text,
  `delivery_failure_reason` varchar(255) DEFAULT NULL,
  `delivery_employee_id` int unsigned DEFAULT NULL,
  `delivery_failed_at` datetime DEFAULT NULL,
  `order_completed_at` datetime DEFAULT NULL,
  `rescheduled_date` date DEFAULT NULL,
  `failure_classification` enum('customer_caused','business_caused','courier_caused','disputed') DEFAULT NULL,
  `refund_eligibility` enum('ineligible','eligible','manual_review') DEFAULT NULL,
  `failure_classified_by_employee_id` int unsigned DEFAULT NULL,
  `failure_classified_at` datetime DEFAULT NULL,
  `dispute_notes` varchar(500) DEFAULT NULL,
  `dispute_resolved_by_employee_id` int unsigned DEFAULT NULL,
  `dispute_resolved_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`order_id`),
  UNIQUE KEY `uk_order_id_bu` (`order_id`,`business_unit_id`),
  KEY `customer_id` (`customer_id`),
  KEY `branch_id` (`branch_id`),
  KEY `fk_order_deliv_emp` (`delivery_employee_id`),
  KEY `fk_order_failure_classified_emp` (`failure_classified_by_employee_id`),
  KEY `fk_order_dispute_resolved_emp` (`dispute_resolved_by_employee_id`),
  KEY `idx_order_failure_classification` (`business_unit_id`,`failure_classification`,`refund_eligibility`),
  KEY `idx_order_bu_completed_at` (`business_unit_id`,`order_status`,`order_completed_at`),
  CONSTRAINT `customer_order_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customer` (`customer_id`),
  CONSTRAINT `customer_order_ibfk_2` FOREIGN KEY (`business_unit_id`) REFERENCES `business_unit` (`business_unit_id`),
  CONSTRAINT `customer_order_ibfk_3` FOREIGN KEY (`branch_id`) REFERENCES `branch` (`branch_id`),
  CONSTRAINT `fk_order_deliv_emp` FOREIGN KEY (`delivery_employee_id`) REFERENCES `employee` (`employee_id`),
  CONSTRAINT `fk_order_dispute_resolved_emp` FOREIGN KEY (`dispute_resolved_by_employee_id`) REFERENCES `employee` (`employee_id`),
  CONSTRAINT `fk_order_failure_classified_emp` FOREIGN KEY (`failure_classified_by_employee_id`) REFERENCES `employee` (`employee_id`),
  CONSTRAINT `chk_order_customer_failure_ineligible` CHECK (((`failure_classification` is null) or (`refund_eligibility` is null) or (`failure_classification` <> _utf8mb4'customer_caused') or (`refund_eligibility` = _utf8mb4'ineligible'))),
  CONSTRAINT `chk_order_customer_failure_strict` CHECK (((`failure_classification` is null) or (`failure_classification` <> _utf8mb4'customer_caused') or ((`refund_eligibility` is not null) and (`refund_eligibility` = _utf8mb4'ineligible')))),
  CONSTRAINT `chk_order_delivery_fee_nonneg` CHECK ((`delivery_fee` >= 0)),
  CONSTRAINT `chk_order_disputed_manual_review` CHECK (((`failure_classification` is null) or (`refund_eligibility` is null) or (`failure_classification` <> _utf8mb4'disputed') or (`refund_eligibility` = _utf8mb4'manual_review'))),
  CONSTRAINT `chk_order_disputed_strict` CHECK (((`failure_classification` is null) or (`failure_classification` <> _utf8mb4'disputed') or ((`refund_eligibility` is not null) and (`refund_eligibility` = _utf8mb4'manual_review')))),
  CONSTRAINT `chk_order_failure_eligibility_pair` CHECK ((((`failure_classification` is null) and (`refund_eligibility` is null)) or ((`failure_classification` is not null) and (`refund_eligibility` is not null)))),
  CONSTRAINT `chk_order_subtotal_nonneg` CHECK (((`subtotal_amount` is null) or (`subtotal_amount` >= 0)))
) ENGINE=InnoDB AUTO_INCREMENT=826 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customer_order`
--

LOCK TABLES `customer_order` WRITE;
/*!40000 ALTER TABLE `customer_order` DISABLE KEYS */;
/*!40000 ALTER TABLE `customer_order` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customer_registration_pending`
--

DROP TABLE IF EXISTS `customer_registration_pending`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_registration_pending` (
  `pending_id` int unsigned NOT NULL AUTO_INCREMENT,
  `session_token_hash` char(64) NOT NULL,
  `email` varchar(255) NOT NULL,
  `username` varchar(100) DEFAULT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `address_line1` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `province` varchar(100) DEFAULT NULL,
  `postal_code` varchar(20) DEFAULT NULL,
  `otp_hash` char(64) DEFAULT NULL,
  `otp_expires_at` datetime DEFAULT NULL,
  `otp_attempts` tinyint unsigned NOT NULL DEFAULT '0',
  `cumulative_failed_attempts` tinyint unsigned NOT NULL DEFAULT '0',
  `last_sent_at` datetime DEFAULT NULL,
  `otp_verified_at` datetime DEFAULT NULL,
  `conflict_field` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`pending_id`),
  UNIQUE KEY `uk_pending_session_token` (`session_token_hash`),
  KEY `idx_pending_email` (`email`),
  KEY `idx_pending_expires` (`expires_at`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customer_registration_pending`
--

LOCK TABLES `customer_registration_pending` WRITE;
/*!40000 ALTER TABLE `customer_registration_pending` DISABLE KEYS */;
INSERT INTO `customer_registration_pending` VALUES (1,'32fbf3804c9dbd3c9583bb4e92502a09595f43d27f1d4e86ad3ca0ef3ec947d2','ty202500497@wmsu.edu.ph',NULL,'Crut',NULL,'Dela Cruz','123123','123','City','Province','1000','ff101daa476aef01fa602164718c83b2b7acaa4657780f92518794e78bf5716e','2026-11-14 14:49:41',2,7,'2026-11-14 14:44:41','2026-11-14 14:47:27',NULL,'2026-11-14 14:31:42','2026-11-14 15:01:42');
/*!40000 ALTER TABLE `customer_registration_pending` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customer_review`
--

DROP TABLE IF EXISTS `customer_review`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_review` (
  `review_id` int unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` int unsigned NOT NULL,
  `business_unit_id` int unsigned DEFAULT NULL,
  `rating` tinyint unsigned NOT NULL,
  `review_title` varchar(150) NOT NULL,
  `comment` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`review_id`),
  KEY `customer_id` (`customer_id`),
  KEY `business_unit_id` (`business_unit_id`),
  CONSTRAINT `customer_review_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customer` (`customer_id`),
  CONSTRAINT `customer_review_ibfk_2` FOREIGN KEY (`business_unit_id`) REFERENCES `business_unit` (`business_unit_id`),
  CONSTRAINT `chk_review_rating` CHECK ((`rating` between 1 and 5))
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customer_review`
--

LOCK TABLES `customer_review` WRITE;
/*!40000 ALTER TABLE `customer_review` DISABLE KEYS */;
/*!40000 ALTER TABLE `customer_review` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `email_otp_failure_log`
--

DROP TABLE IF EXISTS `email_otp_failure_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_otp_failure_log` (
  `failure_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `failed_at` datetime NOT NULL,
  PRIMARY KEY (`failure_id`),
  KEY `idx_failure_email_failed` (`email`,`failed_at`),
  KEY `idx_failure_failed_at` (`failed_at`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `email_otp_failure_log`
--

LOCK TABLES `email_otp_failure_log` WRITE;
/*!40000 ALTER TABLE `email_otp_failure_log` DISABLE KEYS */;
INSERT INTO `email_otp_failure_log` VALUES (1,'ty202500497@wmsu.edu.ph','127.0.0.1','2026-11-14 14:32:08'),(2,'ty202500497@wmsu.edu.ph','127.0.0.1','2026-11-14 14:32:22'),(3,'ty202500497@wmsu.edu.ph','127.0.0.1','2026-11-14 14:32:24'),(4,'ty202500497@wmsu.edu.ph','127.0.0.1','2026-11-14 14:32:25'),(5,'ty202500497@wmsu.edu.ph','127.0.0.1','2026-11-14 14:32:26'),(6,'ty202500497@wmsu.edu.ph','127.0.0.1','2026-11-14 14:34:58'),(7,'ty202500497@wmsu.edu.ph','127.0.0.1','2026-11-14 14:44:51');
/*!40000 ALTER TABLE `email_otp_failure_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `email_otp_send_log`
--

DROP TABLE IF EXISTS `email_otp_send_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_otp_send_log` (
  `log_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `sent_at` datetime NOT NULL,
  PRIMARY KEY (`log_id`),
  KEY `idx_send_email_sent` (`email`,`sent_at`),
  KEY `idx_send_ip_sent` (`ip_address`,`sent_at`),
  KEY `idx_send_sent_at` (`sent_at`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `email_otp_send_log`
--

LOCK TABLES `email_otp_send_log` WRITE;
/*!40000 ALTER TABLE `email_otp_send_log` DISABLE KEYS */;
INSERT INTO `email_otp_send_log` VALUES (1,'ty202500497@wmsu.edu.ph','127.0.0.1','2026-11-14 14:31:42'),(2,'ty202500497@wmsu.edu.ph','127.0.0.1','2026-11-14 14:34:51'),(3,'ty202500497@wmsu.edu.ph','127.0.0.1','2026-11-14 14:43:13'),(4,'ty202500497@wmsu.edu.ph','127.0.0.1','2026-11-14 14:44:41');
/*!40000 ALTER TABLE `email_otp_send_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employee`
--

DROP TABLE IF EXISTS `employee`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee` (
  `employee_id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_account_id` int unsigned NOT NULL,
  `business_unit_id` int unsigned DEFAULT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `position` varchar(100) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `employee_status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`employee_id`),
  UNIQUE KEY `user_account_id` (`user_account_id`),
  KEY `fk_employee_business_unit` (`business_unit_id`),
  CONSTRAINT `employee_ibfk_1` FOREIGN KEY (`user_account_id`) REFERENCES `user_account` (`user_account_id`),
  CONSTRAINT `fk_employee_business_unit` FOREIGN KEY (`business_unit_id`) REFERENCES `business_unit` (`business_unit_id`)
) ENGINE=InnoDB AUTO_INCREMENT=489 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employee`
--

LOCK TABLES `employee` WRITE;
/*!40000 ALTER TABLE `employee` DISABLE KEYS */;
INSERT INTO `employee` VALUES (1,1,3,'Maya','Lin','Inventory Manager','0917-555-0304','active','2026-11-14 14:22:09','2026-11-14 14:22:09'),(150,211,1,'Evelyn','Sterling','Super Administrator',NULL,'active','2026-11-17 08:15:48','2026-11-17 08:15:48'),(470,592,2,'Dr. Elena','Vance','Lead Veterinarian',NULL,'active','2026-11-18 03:31:37','2026-11-18 03:31:37'),(471,593,3,'Marcus','Chen','Business Manager — Grooming Services',NULL,'active','2026-11-18 03:31:37','2026-11-18 03:31:37'),(472,594,2,'Claire','Dubois','Business Manager — Veterinary Clinic',NULL,'active','2026-11-18 03:31:37','2026-11-18 03:31:37'),(473,595,1,'David','Reyes','Business Manager — Pet Store',NULL,'active','2026-11-18 03:31:37','2026-11-18 03:31:37'),(474,596,2,'Hannah','Kim','Veterinary Nurse',NULL,'active','2026-11-18 03:31:37','2026-11-18 03:31:37'),(475,597,3,'Owen','Bennett','Master Stylist',NULL,'active','2026-11-18 03:31:37','2026-11-18 03:31:37'),(476,604,1,'Gian','Lorenzo Machutes','Delivery Personnel',NULL,'inactive','2026-11-27 11:14:27','2026-11-27 11:38:40'),(477,605,1,'Gian','Lorenzo Machutes','Delivery Personnel',NULL,'active','2026-11-27 11:15:17','2026-11-27 11:47:36'),(478,606,1,'Cruz','Cruz','Inventory Manager',NULL,'active','2026-11-27 11:42:37','2026-11-27 11:42:37'),(479,607,1,'Adrian','Mercer','System Administrator',NULL,'active','2026-11-27 16:16:18','2026-11-27 16:16:18'),(480,608,2,'Beatrice','Solis','System Administrator',NULL,'active','2026-11-27 16:16:18','2026-11-27 16:16:18'),(481,609,3,'Caleb','Thorne','System Administrator',NULL,'active','2026-11-27 16:16:18','2026-11-27 16:16:18');
/*!40000 ALTER TABLE `employee` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employee_invitation`
--

DROP TABLE IF EXISTS `employee_invitation`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_invitation` (
  `invitation_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_account_id` int unsigned DEFAULT NULL,
  `employee_id` int unsigned DEFAULT NULL,
  `business_unit_id` int unsigned NOT NULL,
  `invited_email` varchar(255) NOT NULL,
  `active_pending_email` varchar(255) GENERATED ALWAYS AS ((case when (`status` = _utf8mb4'pending') then `invited_email` else NULL end)) STORED,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `username` varchar(100) DEFAULT NULL,
  `active_pending_username` varchar(100) GENERATED ALWAYS AS ((case when ((`status` = _utf8mb4'pending') and (`username` is not null)) then `username` else NULL end)) STORED,
  `phone` varchar(30) DEFAULT NULL,
  `position_id` int unsigned DEFAULT NULL,
  `position_name` varchar(100) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `status` enum('pending','accepted','revoked','expired') NOT NULL DEFAULT 'pending',
  `expires_at` datetime NOT NULL,
  `accepted_at` datetime DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `revoke_reason` varchar(100) DEFAULT NULL,
  `replaced_invitation_id` bigint unsigned DEFAULT NULL,
  `email_submission_status` enum('pending','submitted','failed') NOT NULL DEFAULT 'pending',
  `email_submitted_at` datetime DEFAULT NULL,
  `email_failed_at` datetime DEFAULT NULL,
  `email_error` varchar(255) DEFAULT NULL,
  `created_by_user_id` int unsigned DEFAULT NULL,
  `is_legacy_quarantined` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`invitation_id`),
  UNIQUE KEY `uk_emp_inv_token_hash` (`token_hash`),
  UNIQUE KEY `uk_emp_inv_active_pending_email` (`active_pending_email`),
  UNIQUE KEY `uk_emp_inv_active_pending_username` (`active_pending_username`),
  KEY `idx_emp_inv_employee_status` (`employee_id`,`status`),
  KEY `idx_emp_inv_user_status` (`user_account_id`,`status`),
  KEY `idx_emp_inv_unit_status_created` (`business_unit_id`,`status`,`created_at`),
  KEY `idx_emp_inv_status_expires` (`status`,`expires_at`),
  KEY `fk_emp_inv_creator` (`created_by_user_id`),
  KEY `fk_emp_inv_position` (`position_id`),
  KEY `fk_emp_inv_replaced` (`replaced_invitation_id`),
  KEY `idx_emp_inv_email_status` (`invited_email`,`status`,`invitation_id`),
  KEY `idx_emp_inv_unit_status_expires` (`business_unit_id`,`status`,`expires_at`,`created_at`),
  CONSTRAINT `fk_emp_inv_business_unit` FOREIGN KEY (`business_unit_id`) REFERENCES `business_unit` (`business_unit_id`),
  CONSTRAINT `fk_emp_inv_creator` FOREIGN KEY (`created_by_user_id`) REFERENCES `user_account` (`user_account_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_emp_inv_employee` FOREIGN KEY (`employee_id`) REFERENCES `employee` (`employee_id`),
  CONSTRAINT `fk_emp_inv_position` FOREIGN KEY (`position_id`) REFERENCES `business_position` (`position_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_emp_inv_replaced` FOREIGN KEY (`replaced_invitation_id`) REFERENCES `employee_invitation` (`invitation_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_emp_inv_user` FOREIGN KEY (`user_account_id`) REFERENCES `user_account` (`user_account_id`),
  CONSTRAINT `chk_emp_inv_email_ts` CHECK ((((`email_submission_status` <> _utf8mb4'submitted') or (`email_submitted_at` is not null)) and ((`email_submission_status` <> _utf8mb4'failed') or (`email_failed_at` is not null)))),
  CONSTRAINT `chk_emp_inv_expiry_window` CHECK ((`expires_at` > `created_at`)),
  CONSTRAINT `chk_emp_inv_identity_pair` CHECK ((((`user_account_id` is null) and (`employee_id` is null)) or ((`user_account_id` is not null) and (`employee_id` is not null)))),
  CONSTRAINT `chk_emp_inv_mutually_exclusive` CHECK (((`accepted_at` is null) or (`revoked_at` is null))),
  CONSTRAINT `chk_emp_inv_status_links` CHECK ((((`status` = _utf8mb4'accepted') and (`user_account_id` is not null) and (`employee_id` is not null) and (`accepted_at` is not null)) or ((`status` <> _utf8mb4'accepted') and (`accepted_at` is null) and (((`user_account_id` is null) and (`employee_id` is null)) or (`is_legacy_quarantined` = 1))))),
  CONSTRAINT `chk_emp_inv_terminal_ts` CHECK ((((`status` <> _utf8mb4'accepted') or (`accepted_at` is not null)) and ((`status` <> _utf8mb4'revoked') or (`revoked_at` is not null)))),
  CONSTRAINT `chk_emp_inv_token_hash_len` CHECK ((char_length(`token_hash`) = 64))
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employee_invitation`
--

LOCK TABLES `employee_invitation` WRITE;
/*!40000 ALTER TABLE `employee_invitation` DISABLE KEYS */;
INSERT INTO `employee_invitation` (`invitation_id`, `user_account_id`, `employee_id`, `business_unit_id`, `invited_email`, `first_name`, `last_name`, `username`, `phone`, `position_id`, `position_name`, `token_hash`, `status`, `expires_at`, `accepted_at`, `revoked_at`, `revoke_reason`, `replaced_invitation_id`, `email_submission_status`, `email_submitted_at`, `email_failed_at`, `email_error`, `created_by_user_id`, `is_legacy_quarantined`, `created_at`, `updated_at`) VALUES (1,604,476,1,'gian.machutes6921@gmail.com','Gian','Lorenzo Machutes',NULL,NULL,26,'Delivery Personnel','65ee578b12f62c26c175e7c953695072f6f297ef3df64e2230ecd3b5e237121b','pending','2026-11-29 11:14:27',NULL,NULL,NULL,NULL,'failed',NULL,'2026-11-27 11:14:27','Application URL configuration is unavailable (missing APP_URL).',211,1,'2026-11-27 11:14:27','2026-11-27 11:14:27'),(2,605,477,1,'ty202500497@wmsu.edu.ph','Gian','Lorenzo Machutes',NULL,NULL,26,'Delivery Personnel','dd4fee4b8abd7b90cb5f76ffeb25c8b253321feab43f15049fc78a639ba1b942','revoked','2026-11-29 11:15:17',NULL,'2026-11-27 13:18:58','admin_revoked',NULL,'failed',NULL,'2026-11-27 11:15:17','Application URL configuration is unavailable (missing APP_URL).',211,1,'2026-11-27 11:15:17','2026-11-27 13:18:58'),(3,606,478,1,'crutcrutmeow@gmail.com','Cruz','Cruz',NULL,NULL,11,'Inventory Manager','1394de40e693aabcde933efa3c2e0f4ba67bd06fa2de0204f25e239c1ce88eaf','revoked','2026-11-29 11:42:37',NULL,'2026-11-27 13:18:53','admin_revoked',NULL,'failed',NULL,'2026-11-27 11:42:37','Application URL configuration is unavailable (missing APP_URL).',211,1,'2026-11-27 11:42:37','2026-11-27 13:18:53');
/*!40000 ALTER TABLE `employee_invitation` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employee_invitation_role`
--

DROP TABLE IF EXISTS `employee_invitation_role`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_invitation_role` (
  `invitation_id` bigint unsigned NOT NULL,
  `role_id` int unsigned NOT NULL,
  PRIMARY KEY (`invitation_id`,`role_id`),
  KEY `fk_emp_inv_role_role` (`role_id`),
  CONSTRAINT `fk_emp_inv_role_invitation` FOREIGN KEY (`invitation_id`) REFERENCES `employee_invitation` (`invitation_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_emp_inv_role_role` FOREIGN KEY (`role_id`) REFERENCES `role` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employee_invitation_role`
--

LOCK TABLES `employee_invitation_role` WRITE;
/*!40000 ALTER TABLE `employee_invitation_role` DISABLE KEYS */;
INSERT INTO `employee_invitation_role` VALUES (3,13),(1,14),(2,14);
/*!40000 ALTER TABLE `employee_invitation_role` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employee_work_status`
--

DROP TABLE IF EXISTS `employee_work_status`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_work_status` (
  `status_id` int unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` int unsigned NOT NULL,
  `work_status` enum('On Duty','Free','Not Present') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Free',
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`status_id`),
  UNIQUE KEY `employee_id` (`employee_id`),
  CONSTRAINT `employee_work_status_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employee` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employee_work_status`
--

LOCK TABLES `employee_work_status` WRITE;
/*!40000 ALTER TABLE `employee_work_status` DISABLE KEYS */;
/*!40000 ALTER TABLE `employee_work_status` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inventory`
--

DROP TABLE IF EXISTS `inventory`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventory` (
  `inventory_id` int unsigned NOT NULL AUTO_INCREMENT,
  `business_product_id` int unsigned NOT NULL,
  `on_hand` int NOT NULL DEFAULT '0',
  `committed` int NOT NULL DEFAULT '0',
  `in_transit` int NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`inventory_id`),
  UNIQUE KEY `business_product_id` (`business_product_id`),
  CONSTRAINT `inventory_ibfk_1` FOREIGN KEY (`business_product_id`) REFERENCES `business_product` (`business_product_id`),
  CONSTRAINT `chk_inventory_committed_ge_in_transit` CHECK ((`committed` >= `in_transit`)),
  CONSTRAINT `chk_inventory_committed_nonneg` CHECK ((`committed` >= 0)),
  CONSTRAINT `chk_inventory_in_transit_nonneg` CHECK ((`in_transit` >= 0)),
  CONSTRAINT `chk_inventory_on_hand_nonneg` CHECK ((`on_hand` >= 0))
) ENGINE=InnoDB AUTO_INCREMENT=457 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory`
--

LOCK TABLES `inventory` WRITE;
/*!40000 ALTER TABLE `inventory` DISABLE KEYS */;
INSERT INTO `inventory` VALUES (450,463,40,0,0,'2026-11-18 03:31:37','2026-11-18 03:31:37'),(451,464,15,0,0,'2026-11-18 03:31:37','2026-11-18 03:31:37'),(452,465,3,0,0,'2026-11-18 03:31:37','2026-11-18 03:31:37'),(453,466,0,0,0,'2026-11-18 03:31:37','2026-11-18 03:31:37'),(454,462,25,0,0,'2026-11-18 03:31:37','2026-11-18 03:31:37'),(455,467,30,0,0,'2026-11-18 03:31:37','2026-11-18 03:31:37'),(456,468,50,0,0,'2026-11-18 03:31:37','2026-11-18 03:31:37');
/*!40000 ALTER TABLE `inventory` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inventory_access_key`
--

DROP TABLE IF EXISTS `inventory_access_key`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventory_access_key` (
  `key_id` int unsigned NOT NULL AUTO_INCREMENT,
  `business_unit_id` int unsigned NOT NULL,
  `key_code` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by_employee_id` int unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` datetime NOT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `revoked_by_employee_id` int unsigned DEFAULT NULL,
  PRIMARY KEY (`key_id`),
  UNIQUE KEY `key_code` (`key_code`),
  KEY `idx_inv_key_unit_active_exp` (`business_unit_id`,`is_active`,`expires_at`),
  KEY `fk_inv_key_emp` (`created_by_employee_id`),
  KEY `fk_inv_key_revoked_by` (`revoked_by_employee_id`),
  CONSTRAINT `fk_inv_key_emp` FOREIGN KEY (`created_by_employee_id`) REFERENCES `employee` (`employee_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_inv_key_revoked_by` FOREIGN KEY (`revoked_by_employee_id`) REFERENCES `employee` (`employee_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_inv_key_unit` FOREIGN KEY (`business_unit_id`) REFERENCES `business_unit` (`business_unit_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory_access_key`
--

LOCK TABLES `inventory_access_key` WRITE;
/*!40000 ALTER TABLE `inventory_access_key` DISABLE KEYS */;
/*!40000 ALTER TABLE `inventory_access_key` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inventory_batch`
--

DROP TABLE IF EXISTS `inventory_batch`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventory_batch` (
  `batch_id` int unsigned NOT NULL AUTO_INCREMENT,
  `inventory_id` int unsigned NOT NULL,
  `batch_number` varchar(100) NOT NULL,
  `expiration_date` date DEFAULT NULL,
  `quantity` int NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`batch_id`),
  KEY `inventory_id` (`inventory_id`),
  CONSTRAINT `inventory_batch_ibfk_1` FOREIGN KEY (`inventory_id`) REFERENCES `inventory` (`inventory_id`)
) ENGINE=InnoDB AUTO_INCREMENT=249 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory_batch`
--

LOCK TABLES `inventory_batch` WRITE;
/*!40000 ALTER TABLE `inventory_batch` DISABLE KEYS */;
/*!40000 ALTER TABLE `inventory_batch` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inventory_hold`
--

DROP TABLE IF EXISTS `inventory_hold`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventory_hold` (
  `hold_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `attempt_id` bigint unsigned NOT NULL,
  `customer_id` int unsigned NOT NULL,
  `business_unit_id` int unsigned NOT NULL,
  `hold_status` enum('active','converted','released','expired') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `converted_at` datetime DEFAULT NULL,
  `released_at` datetime DEFAULT NULL,
  `release_reason` varchar(100) DEFAULT NULL,
  `converted_order_id` int unsigned DEFAULT NULL,
  PRIMARY KEY (`hold_id`),
  UNIQUE KEY `uk_inv_hold_attempt` (`attempt_id`),
  UNIQUE KEY `uk_inv_hold_converted_order` (`converted_order_id`),
  KEY `idx_inv_hold_status_expires` (`hold_status`,`expires_at`),
  KEY `idx_inv_hold_customer_bu_status` (`customer_id`,`business_unit_id`,`hold_status`),
  KEY `fk_inv_hold_bu` (`business_unit_id`),
  CONSTRAINT `fk_inv_hold_attempt` FOREIGN KEY (`attempt_id`) REFERENCES `checkout_attempt` (`attempt_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_inv_hold_bu` FOREIGN KEY (`business_unit_id`) REFERENCES `business_unit` (`business_unit_id`),
  CONSTRAINT `fk_inv_hold_customer` FOREIGN KEY (`customer_id`) REFERENCES `customer` (`customer_id`),
  CONSTRAINT `fk_inv_hold_order` FOREIGN KEY (`converted_order_id`) REFERENCES `customer_order` (`order_id`),
  CONSTRAINT `chk_inv_hold_window` CHECK ((`expires_at` > `created_at`))
) ENGINE=InnoDB AUTO_INCREMENT=42 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory_hold`
--

LOCK TABLES `inventory_hold` WRITE;
/*!40000 ALTER TABLE `inventory_hold` DISABLE KEYS */;
/*!40000 ALTER TABLE `inventory_hold` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inventory_hold_item`
--

DROP TABLE IF EXISTS `inventory_hold_item`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventory_hold_item` (
  `hold_item_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `hold_id` bigint unsigned NOT NULL,
  `inventory_id` int unsigned NOT NULL,
  `business_product_id` int unsigned NOT NULL,
  `quantity` int NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`hold_item_id`),
  KEY `idx_hold_item_inv` (`inventory_id`,`hold_id`),
  KEY `idx_hold_item_hold` (`hold_id`),
  KEY `fk_hold_item_bp` (`business_product_id`),
  CONSTRAINT `fk_hold_item_bp` FOREIGN KEY (`business_product_id`) REFERENCES `business_product` (`business_product_id`),
  CONSTRAINT `fk_hold_item_hold` FOREIGN KEY (`hold_id`) REFERENCES `inventory_hold` (`hold_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hold_item_inv` FOREIGN KEY (`inventory_id`) REFERENCES `inventory` (`inventory_id`),
  CONSTRAINT `chk_hold_item_price_nonneg` CHECK ((`unit_price` >= 0)),
  CONSTRAINT `chk_hold_item_qty_positive` CHECK ((`quantity` > 0))
) ENGINE=InnoDB AUTO_INCREMENT=42 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory_hold_item`
--

LOCK TABLES `inventory_hold_item` WRITE;
/*!40000 ALTER TABLE `inventory_hold_item` DISABLE KEYS */;
/*!40000 ALTER TABLE `inventory_hold_item` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inventory_movement`
--

DROP TABLE IF EXISTS `inventory_movement`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventory_movement` (
  `movement_id` int unsigned NOT NULL AUTO_INCREMENT,
  `inventory_id` int unsigned NOT NULL,
  `batch_id` int unsigned DEFAULT NULL,
  `sale_item_id` int unsigned DEFAULT NULL,
  `reservation_id` int unsigned DEFAULT NULL,
  `movement_type` varchar(50) NOT NULL,
  `quantity` int NOT NULL,
  `movement_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`movement_id`),
  KEY `inventory_id` (`inventory_id`),
  KEY `batch_id` (`batch_id`),
  KEY `sale_item_id` (`sale_item_id`),
  KEY `reservation_id` (`reservation_id`),
  CONSTRAINT `inventory_movement_ibfk_1` FOREIGN KEY (`inventory_id`) REFERENCES `inventory` (`inventory_id`),
  CONSTRAINT `inventory_movement_ibfk_2` FOREIGN KEY (`batch_id`) REFERENCES `inventory_batch` (`batch_id`),
  CONSTRAINT `inventory_movement_ibfk_3` FOREIGN KEY (`sale_item_id`) REFERENCES `sale_item` (`sale_item_id`),
  CONSTRAINT `inventory_movement_ibfk_4` FOREIGN KEY (`reservation_id`) REFERENCES `inventory_reservation` (`reservation_id`)
) ENGINE=InnoDB AUTO_INCREMENT=834 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory_movement`
--

LOCK TABLES `inventory_movement` WRITE;
/*!40000 ALTER TABLE `inventory_movement` DISABLE KEYS */;
/*!40000 ALTER TABLE `inventory_movement` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inventory_reservation`
--

DROP TABLE IF EXISTS `inventory_reservation`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventory_reservation` (
  `reservation_id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_item_id` int unsigned NOT NULL,
  `inventory_id` int unsigned NOT NULL,
  `batch_id` int unsigned DEFAULT NULL,
  `reserved_quantity` int NOT NULL,
  `reservation_status` enum('active','in_transit','returned_pending_inspection','fulfilled','cancelled') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`reservation_id`),
  KEY `batch_id` (`batch_id`),
  KEY `order_item_id` (`order_item_id`),
  KEY `inventory_id` (`inventory_id`),
  KEY `idx_inv_res_inv_status` (`inventory_id`,`reservation_status`),
  KEY `idx_inv_res_item_status` (`order_item_id`,`reservation_status`),
  CONSTRAINT `inventory_reservation_ibfk_1` FOREIGN KEY (`order_item_id`) REFERENCES `order_item` (`order_item_id`),
  CONSTRAINT `inventory_reservation_ibfk_2` FOREIGN KEY (`inventory_id`) REFERENCES `inventory` (`inventory_id`),
  CONSTRAINT `inventory_reservation_ibfk_3` FOREIGN KEY (`batch_id`) REFERENCES `inventory_batch` (`batch_id`),
  CONSTRAINT `chk_inv_res_qty_positive` CHECK ((`reserved_quantity` > 0))
) ENGINE=InnoDB AUTO_INCREMENT=634 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory_reservation`
--

LOCK TABLES `inventory_reservation` WRITE;
/*!40000 ALTER TABLE `inventory_reservation` DISABLE KEYS */;
/*!40000 ALTER TABLE `inventory_reservation` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `invoice_item`
--

DROP TABLE IF EXISTS `invoice_item`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoice_item` (
  `invoice_item_id` int unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` int unsigned NOT NULL,
  `performed_service_id` int unsigned NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `quantity` int NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`invoice_item_id`),
  KEY `invoice_id` (`invoice_id`),
  KEY `performed_service_id` (`performed_service_id`),
  CONSTRAINT `invoice_item_ibfk_1` FOREIGN KEY (`invoice_id`) REFERENCES `service_invoice` (`invoice_id`),
  CONSTRAINT `invoice_item_ibfk_2` FOREIGN KEY (`performed_service_id`) REFERENCES `performed_service` (`performed_service_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `invoice_item`
--

LOCK TABLES `invoice_item` WRITE;
/*!40000 ALTER TABLE `invoice_item` DISABLE KEYS */;
/*!40000 ALTER TABLE `invoice_item` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `operating_hours`
--

DROP TABLE IF EXISTS `operating_hours`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `operating_hours` (
  `hours_id` int unsigned NOT NULL AUTO_INCREMENT,
  `business_unit_id` int unsigned NOT NULL,
  `day_of_week` tinyint unsigned NOT NULL,
  `day_name` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_open` tinyint(1) NOT NULL DEFAULT '1',
  `open_time` time DEFAULT '08:00:00',
  `close_time` time DEFAULT '19:00:00',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`hours_id`),
  UNIQUE KEY `uq_unit_day` (`business_unit_id`,`day_of_week`),
  CONSTRAINT `operating_hours_ibfk_1` FOREIGN KEY (`business_unit_id`) REFERENCES `business_unit` (`business_unit_id`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `operating_hours`
--

LOCK TABLES `operating_hours` WRITE;
/*!40000 ALTER TABLE `operating_hours` DISABLE KEYS */;
INSERT INTO `operating_hours` VALUES (1,1,1,'Monday',1,'08:00:00','19:00:00','2026-11-14 14:22:09','2026-11-14 14:22:09'),(2,1,2,'Tuesday',1,'08:00:00','19:00:00','2026-11-14 14:22:09','2026-11-14 14:22:09'),(3,1,3,'Wednesday',1,'08:00:00','19:00:00','2026-11-14 14:22:09','2026-11-14 14:22:09'),(4,1,4,'Thursday',1,'08:00:00','19:00:00','2026-11-14 14:22:09','2026-11-14 14:22:09'),(5,1,5,'Friday',1,'08:00:00','19:00:00','2026-11-14 14:22:09','2026-11-14 14:22:09'),(6,1,6,'Saturday',1,'09:00:00','18:00:00','2026-11-14 14:22:09','2026-11-14 14:22:09'),(7,1,7,'Sunday',1,'09:00:00','17:00:00','2026-11-14 14:22:09','2026-11-14 14:22:09'),(8,2,1,'Monday',1,'08:00:00','19:00:00','2026-11-17 11:49:01','2026-11-17 11:49:01'),(9,2,2,'Tuesday',1,'08:00:00','19:00:00','2026-11-17 11:49:01','2026-11-17 11:49:01'),(10,2,3,'Wednesday',1,'08:00:00','19:00:00','2026-11-17 11:49:01','2026-11-17 11:49:01'),(11,2,4,'Thursday',1,'08:00:00','19:00:00','2026-11-17 11:49:01','2026-11-17 11:49:01'),(12,2,5,'Friday',1,'08:00:00','19:00:00','2026-11-17 11:49:01','2026-11-17 11:49:01'),(13,2,6,'Saturday',1,'09:00:00','18:00:00','2026-11-17 11:49:01','2026-11-17 11:49:01'),(14,2,7,'Sunday',1,'09:00:00','17:00:00','2026-11-17 11:49:01','2026-11-17 11:49:01');
/*!40000 ALTER TABLE `operating_hours` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `order_item`
--

DROP TABLE IF EXISTS `order_item`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `order_item` (
  `order_item_id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int unsigned NOT NULL,
  `business_product_id` int unsigned NOT NULL,
  `quantity` int NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`order_item_id`),
  KEY `order_id` (`order_id`),
  KEY `business_product_id` (`business_product_id`),
  CONSTRAINT `order_item_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `customer_order` (`order_id`),
  CONSTRAINT `order_item_ibfk_2` FOREIGN KEY (`business_product_id`) REFERENCES `business_product` (`business_product_id`)
) ENGINE=InnoDB AUTO_INCREMENT=873 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `order_item`
--

LOCK TABLES `order_item` WRITE;
/*!40000 ALTER TABLE `order_item` DISABLE KEYS */;
/*!40000 ALTER TABLE `order_item` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `otp_verification`
--

DROP TABLE IF EXISTS `otp_verification`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `otp_verification` (
  `otp_id` int unsigned NOT NULL AUTO_INCREMENT,
  `business_unit_id` int unsigned NOT NULL,
  `service_type` enum('gcash','maya') COLLATE utf8mb4_unicode_ci NOT NULL,
  `mobile_number` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `otp_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `is_verified` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`otp_id`),
  KEY `business_unit_id` (`business_unit_id`),
  CONSTRAINT `otp_verification_ibfk_1` FOREIGN KEY (`business_unit_id`) REFERENCES `business_unit` (`business_unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `otp_verification`
--

LOCK TABLES `otp_verification` WRITE;
/*!40000 ALTER TABLE `otp_verification` DISABLE KEYS */;
/*!40000 ALTER TABLE `otp_verification` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payment`
--

DROP TABLE IF EXISTS `payment`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment` (
  `payment_id` int unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` int unsigned DEFAULT NULL,
  `sale_id` int unsigned DEFAULT NULL,
  `order_id` int unsigned DEFAULT NULL,
  `attempt_id` bigint unsigned DEFAULT NULL,
  `original_payment_id` int unsigned DEFAULT NULL,
  `payment_type` enum('payment','refund') NOT NULL DEFAULT 'payment',
  `amount` decimal(12,2) NOT NULL,
  `payment_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `payment_method` varchar(50) NOT NULL DEFAULT 'cash',
  `payment_status` enum('pending','completed','failed','refunded') NOT NULL DEFAULT 'pending',
  `provider_name` varchar(50) DEFAULT NULL,
  `provider_payment_id` varchar(191) DEFAULT NULL,
  `collection_channel` enum('courier_cod','in_store_pickup','online_gateway','in_store_pos') DEFAULT NULL,
  `collected_by_employee_id` int unsigned DEFAULT NULL,
  `collected_at` datetime DEFAULT NULL,
  `refund_request_id` int unsigned DEFAULT NULL,
  `refund_execution_notes` varchar(255) DEFAULT NULL,
  `active_refund_request_key` int unsigned GENERATED ALWAYS AS (if(((`payment_type` = _utf8mb4'refund') and (`payment_status` in (_utf8mb4'pending',_utf8mb4'completed'))),`refund_request_id`,NULL)) VIRTUAL,
  `completed_provider_ref` varchar(255) GENERATED ALWAYS AS (if(((`payment_type` = _utf8mb4'payment') and (`payment_status` in (_utf8mb4'completed',_utf8mb4'refunded')) and (`provider_name` is not null) and (`provider_payment_id` is not null)),concat(`provider_name`,_utf8mb4':',`provider_payment_id`),NULL)) VIRTUAL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`payment_id`),
  UNIQUE KEY `uk_payment_id_order` (`payment_id`,`order_id`),
  UNIQUE KEY `uk_payment_completed_provider_ref` (`completed_provider_ref`),
  UNIQUE KEY `uk_payment_active_refund_request` (`active_refund_request_key`),
  KEY `invoice_id` (`invoice_id`),
  KEY `sale_id` (`sale_id`),
  KEY `original_payment_id` (`original_payment_id`),
  KEY `idx_payment_attempt` (`attempt_id`),
  KEY `idx_payment_provider_ref` (`provider_name`,`provider_payment_id`,`payment_type`),
  KEY `fk_payment_collected_emp` (`collected_by_employee_id`),
  KEY `fk_payment_refund_request` (`refund_request_id`),
  KEY `idx_payment_order_type_status` (`order_id`,`payment_type`,`payment_status`),
  KEY `idx_payment_collection` (`collection_channel`,`collected_at`),
  CONSTRAINT `fk_payment_attempt` FOREIGN KEY (`attempt_id`) REFERENCES `checkout_attempt` (`attempt_id`),
  CONSTRAINT `fk_payment_collected_emp` FOREIGN KEY (`collected_by_employee_id`) REFERENCES `employee` (`employee_id`),
  CONSTRAINT `fk_payment_refund_request` FOREIGN KEY (`refund_request_id`) REFERENCES `refund_request` (`refund_request_id`),
  CONSTRAINT `payment_ibfk_1` FOREIGN KEY (`invoice_id`) REFERENCES `service_invoice` (`invoice_id`),
  CONSTRAINT `payment_ibfk_2` FOREIGN KEY (`sale_id`) REFERENCES `sale` (`sale_id`),
  CONSTRAINT `payment_ibfk_3` FOREIGN KEY (`order_id`) REFERENCES `customer_order` (`order_id`),
  CONSTRAINT `payment_ibfk_4` FOREIGN KEY (`original_payment_id`) REFERENCES `payment` (`payment_id`),
  CONSTRAINT `chk_payment_amount_positive` CHECK ((`amount` > 0)),
  CONSTRAINT `chk_payment_channel_context` CHECK (((`collection_channel` is null) or ((`collection_channel` = _utf8mb4'courier_cod') and (`order_id` is not null) and (`sale_id` is null) and (`invoice_id` is null) and (lower(`payment_method`) in (_utf8mb4'cod',_utf8mb4'cash'))) or ((`collection_channel` = _utf8mb4'in_store_pickup') and (`order_id` is not null) and (`sale_id` is null) and (`invoice_id` is null)) or ((`collection_channel` = _utf8mb4'in_store_pos') and (`sale_id` is not null) and (`order_id` is null) and (`invoice_id` is null)) or ((`collection_channel` = _utf8mb4'online_gateway') and (`provider_name` is not null) and (`provider_payment_id` is not null)))),
  CONSTRAINT `chk_payment_collected_status` CHECK (((`collected_at` is null) or ((`payment_type` = _utf8mb4'payment') and (`payment_status` in (_utf8mb4'completed',_utf8mb4'refunded'))))),
  CONSTRAINT `chk_payment_collection_requires_collected_state` CHECK ((((`collection_channel` is null) and (`collected_at` is null) and (`collected_by_employee_id` is null)) or ((`payment_type` = _utf8mb4'payment') and (`payment_status` in (_utf8mb4'completed',_utf8mb4'refunded'))))),
  CONSTRAINT `chk_payment_collection_tuple` CHECK ((((`collection_channel` is null) and (`collected_at` is null) and (`collected_by_employee_id` is null)) or ((`collection_channel` in (_utf8mb4'courier_cod',_utf8mb4'in_store_pickup',_utf8mb4'in_store_pos')) and (`collected_at` is not null) and (`collected_by_employee_id` is not null)) or ((`collection_channel` = _utf8mb4'online_gateway') and (`collected_at` is not null) and (`collected_by_employee_id` is null)))),
  CONSTRAINT `chk_payment_courier_cod_status` CHECK (((`collection_channel` is null) or (`collection_channel` <> _utf8mb4'courier_cod') or ((`payment_type` = _utf8mb4'payment') and (`payment_status` in (_utf8mb4'completed',_utf8mb4'refunded'))))),
  CONSTRAINT `chk_payment_refund_parent` CHECK (((`payment_type` <> _utf8mb4'refund') or (`original_payment_id` is not null))),
  CONSTRAINT `chk_payment_refund_req_type` CHECK (((`refund_request_id` is null) or (`payment_type` = _utf8mb4'refund'))),
  CONSTRAINT `chk_payment_regular_no_parent` CHECK (((`payment_type` <> _utf8mb4'payment') or (`original_payment_id` is null)))
) ENGINE=InnoDB AUTO_INCREMENT=902 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payment`
--

LOCK TABLES `payment` WRITE;
/*!40000 ALTER TABLE `payment` DISABLE KEYS */;
/*!40000 ALTER TABLE `payment` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payment_reconciliation`
--

DROP TABLE IF EXISTS `payment_reconciliation`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_reconciliation` (
  `reconciliation_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `attempt_id` bigint unsigned NOT NULL,
  `hold_id` bigint unsigned DEFAULT NULL,
  `payment_id` int unsigned DEFAULT NULL,
  `business_unit_id` int unsigned NOT NULL,
  `customer_id` int unsigned NOT NULL,
  `provider_name` varchar(50) DEFAULT NULL,
  `provider_payment_id` varchar(191) DEFAULT NULL,
  `captured_amount` decimal(12,2) NOT NULL,
  `reconciliation_status` enum('pending_review','resolved_order_created','resolved_refunded','dismissed') NOT NULL DEFAULT 'pending_review',
  `reason` varchar(255) NOT NULL,
  `resolved_order_id` int unsigned DEFAULT NULL,
  `resolved_by_user_id` int unsigned DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `resolution_notes` text,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`reconciliation_id`),
  UNIQUE KEY `uk_pay_recon_attempt` (`attempt_id`),
  KEY `idx_pay_recon_bu_status` (`business_unit_id`,`reconciliation_status`),
  KEY `fk_pay_recon_hold` (`hold_id`),
  KEY `fk_pay_recon_payment` (`payment_id`),
  KEY `fk_pay_recon_customer` (`customer_id`),
  KEY `fk_pay_recon_order` (`resolved_order_id`),
  KEY `fk_pay_recon_user` (`resolved_by_user_id`),
  CONSTRAINT `fk_pay_recon_attempt` FOREIGN KEY (`attempt_id`) REFERENCES `checkout_attempt` (`attempt_id`),
  CONSTRAINT `fk_pay_recon_bu` FOREIGN KEY (`business_unit_id`) REFERENCES `business_unit` (`business_unit_id`),
  CONSTRAINT `fk_pay_recon_customer` FOREIGN KEY (`customer_id`) REFERENCES `customer` (`customer_id`),
  CONSTRAINT `fk_pay_recon_hold` FOREIGN KEY (`hold_id`) REFERENCES `inventory_hold` (`hold_id`),
  CONSTRAINT `fk_pay_recon_order` FOREIGN KEY (`resolved_order_id`) REFERENCES `customer_order` (`order_id`),
  CONSTRAINT `fk_pay_recon_payment` FOREIGN KEY (`payment_id`) REFERENCES `payment` (`payment_id`),
  CONSTRAINT `fk_pay_recon_user` FOREIGN KEY (`resolved_by_user_id`) REFERENCES `user_account` (`user_account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payment_reconciliation`
--

LOCK TABLES `payment_reconciliation` WRITE;
/*!40000 ALTER TABLE `payment_reconciliation` DISABLE KEYS */;
/*!40000 ALTER TABLE `payment_reconciliation` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payment_webhook_event`
--

DROP TABLE IF EXISTS `payment_webhook_event`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_webhook_event` (
  `event_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `provider_name` varchar(50) NOT NULL,
  `provider_event_id` varchar(191) NOT NULL,
  `provider_payment_id` varchar(191) DEFAULT NULL,
  `attempt_id` bigint unsigned DEFAULT NULL,
  `event_type` varchar(80) NOT NULL,
  `processing_status` enum('received','processed','ignored_duplicate','reconciliation_required','failed') NOT NULL DEFAULT 'received',
  `payload_json` mediumtext,
  `processing_notes` varchar(255) DEFAULT NULL,
  `processed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`event_id`),
  UNIQUE KEY `uk_pay_webhook_provider_event` (`provider_name`,`provider_event_id`),
  KEY `idx_pay_webhook_attempt` (`attempt_id`),
  KEY `idx_pay_webhook_payment` (`provider_name`,`provider_payment_id`),
  CONSTRAINT `fk_pay_webhook_attempt` FOREIGN KEY (`attempt_id`) REFERENCES `checkout_attempt` (`attempt_id`)
) ENGINE=InnoDB AUTO_INCREMENT=42 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payment_webhook_event`
--

LOCK TABLES `payment_webhook_event` WRITE;
/*!40000 ALTER TABLE `payment_webhook_event` DISABLE KEYS */;
/*!40000 ALTER TABLE `payment_webhook_event` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `performed_service`
--

DROP TABLE IF EXISTS `performed_service`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `performed_service` (
  `performed_service_id` int unsigned NOT NULL AUTO_INCREMENT,
  `requested_pet_service_id` int unsigned DEFAULT NULL,
  `appointment_pet_id` int unsigned NOT NULL,
  `service_id` int unsigned NOT NULL,
  `performed_price` decimal(10,2) NOT NULL,
  `quantity` int NOT NULL DEFAULT '1',
  `performed_status` enum('pending','completed','cancelled') NOT NULL DEFAULT 'pending',
  `performer_employee_id` int unsigned DEFAULT NULL,
  `notes` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`performed_service_id`),
  KEY `requested_pet_service_id` (`requested_pet_service_id`),
  KEY `appointment_pet_id` (`appointment_pet_id`),
  KEY `service_id` (`service_id`),
  KEY `performer_employee_id` (`performer_employee_id`),
  CONSTRAINT `performed_service_ibfk_1` FOREIGN KEY (`requested_pet_service_id`) REFERENCES `requested_pet_service` (`requested_pet_service_id`),
  CONSTRAINT `performed_service_ibfk_2` FOREIGN KEY (`appointment_pet_id`) REFERENCES `appointment_pet` (`appointment_pet_id`),
  CONSTRAINT `performed_service_ibfk_3` FOREIGN KEY (`service_id`) REFERENCES `service` (`service_id`),
  CONSTRAINT `performed_service_ibfk_4` FOREIGN KEY (`performer_employee_id`) REFERENCES `employee` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `performed_service`
--

LOCK TABLES `performed_service` WRITE;
/*!40000 ALTER TABLE `performed_service` DISABLE KEYS */;
/*!40000 ALTER TABLE `performed_service` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `permission`
--

DROP TABLE IF EXISTS `permission`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `permission` (
  `permission_id` int unsigned NOT NULL AUTO_INCREMENT,
  `permission_code` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`permission_id`),
  UNIQUE KEY `permission_code` (`permission_code`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `permission`
--

LOCK TABLES `permission` WRITE;
/*!40000 ALTER TABLE `permission` DISABLE KEYS */;
INSERT INTO `permission` VALUES (1,'appointment.view','View appointment requests of the assigned business unit','2026-11-14 14:23:41'),(2,'appointment.process','Move appointment requests through receptionist-allowed statuses','2026-11-14 14:23:41'),(3,'admin.employees.view','View employees within the assigned business unit','2026-11-14 14:23:41'),(4,'admin.employees.manage','Create and manage employee accounts within the assigned business unit','2026-11-14 14:23:41'),(5,'admin.roles.manage','Manage employee role and permission assignments within the assigned business unit','2026-11-14 14:23:41'),(6,'admin.positions.manage','Manage job positions within the assigned business unit','2026-11-14 14:23:41'),(7,'admin.sysadmin.grant','Grant System Administrator access within the assigned business unit','2026-11-14 14:23:41'),(8,'manager.operations.view','View operational metrics, inventory, and orders within the assigned business unit','2026-11-14 14:23:41'),(9,'clinical.records.view','View clinical consultation and medical records','2026-11-14 14:23:41'),(10,'clinical.records.manage','Create and finalize clinical medical records','2026-11-14 14:23:41'),(11,'inventory.view','View stock levels of the assigned business unit','2026-11-14 14:23:41'),(12,'inventory.manage','Record stock receiving and adjustments in the assigned business unit','2026-11-14 14:23:41'),(13,'delivery.view','View customer delivery orders of the assigned business unit','2026-11-14 14:23:41'),(14,'delivery.update','Update delivery progress of customer orders in the assigned business unit','2026-11-14 14:23:41');
/*!40000 ALTER TABLE `permission` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pet`
--

DROP TABLE IF EXISTS `pet`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pet` (
  `pet_id` int unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` int unsigned NOT NULL,
  `pet_name` varchar(100) NOT NULL,
  `species` varchar(50) NOT NULL,
  `breed` varchar(100) DEFAULT NULL,
  `sex` enum('male','female','unknown') NOT NULL DEFAULT 'unknown',
  `date_of_birth` date DEFAULT NULL,
  `weight_kg` decimal(6,2) DEFAULT NULL,
  `color_markings` varchar(255) DEFAULT NULL,
  `notes` text,
  `pet_status` enum('active','inactive','deceased') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`pet_id`),
  KEY `customer_id` (`customer_id`),
  CONSTRAINT `pet_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customer` (`customer_id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pet`
--

LOCK TABLES `pet` WRITE;
/*!40000 ALTER TABLE `pet` DISABLE KEYS */;
INSERT INTO `pet` VALUES (1,118,'Barnaby','Dog','Golden Retriever','male','2021-04-12',28.50,'Golden cream','[SAMPLE] Friendly retriever.','active','2026-11-18 03:31:37','2026-11-18 03:31:37'),(2,118,'Whiskers','Cat','Domestic Shorthair','female','2023-01-10',4.20,'Calico','[SAMPLE] Quiet and curious.','active','2026-11-18 03:31:37','2026-11-18 03:31:37'),(3,119,'Bella','Dog','French Bulldog','female','2022-08-19',11.00,'Brindle','[SAMPLE] Playful frenchie.','active','2026-11-18 03:31:37','2026-11-18 03:31:37'),(4,120,'Milo','Cat','Maine Coon','male','2020-11-05',7.80,'Tabby grey','[SAMPLE] Large gentle giant.','active','2026-11-18 03:31:37','2026-11-18 03:31:37'),(5,120,'Pip','Bird','Canary','unknown','2023-05-14',0.05,'Yellow feathering','[SAMPLE] Healthy canary.','active','2026-11-18 03:31:37','2026-11-18 03:31:37'),(6,121,'Daisy','Rabbit','Holland Lop','female','2022-03-22',1.80,'White & brown patches','[SAMPLE] Sweet pet rabbit.','active','2026-11-18 03:31:37','2026-11-18 03:31:37'),(7,122,'Rocky','Dog','German Shepherd','male','2021-09-30',34.00,'Black & tan','[SAMPLE] Active working dog.','active','2026-11-18 03:31:37','2026-11-18 03:31:37');
/*!40000 ALTER TABLE `pet` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product`
--

DROP TABLE IF EXISTS `product`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product` (
  `product_id` int unsigned NOT NULL AUTO_INCREMENT,
  `product_name` varchar(200) NOT NULL,
  `description` text,
  `product_category` varchar(100) DEFAULT NULL,
  `sku` varchar(64) DEFAULT NULL,
  `image_url` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`product_id`)
) ENGINE=InnoDB AUTO_INCREMENT=462 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product`
--

LOCK TABLES `product` WRITE;
/*!40000 ALTER TABLE `product` DISABLE KEYS */;
INSERT INTO `product` VALUES (456,'Oatmeal Pet Shampoo 250ml','[SAMPLE] Gentle oatmeal shampoo for sensitive skin.','Grooming Supplies',NULL,NULL,'2026-11-18 03:31:37','2026-11-18 03:31:37'),(457,'Deshedding Brush','[SAMPLE] Reduces shedding for dogs and cats.','Grooming Supplies',NULL,NULL,'2026-11-18 03:31:37','2026-11-18 03:31:37'),(458,'Nail Clippers','[SAMPLE] Stainless steel clippers with safety guard.','Grooming Supplies',NULL,NULL,'2026-11-18 03:31:37','2026-11-18 03:31:37'),(459,'Leave-in Conditioner Spray','[SAMPLE] Detangling spray (seeded out of stock for testing).','Grooming Supplies',NULL,NULL,'2026-11-18 03:31:37','2026-11-18 03:31:37'),(460,'Adult Dog Kibble 2kg','[SAMPLE] Dry food for adult dogs.','Food',NULL,NULL,'2026-11-18 03:31:37','2026-11-18 03:31:37'),(461,'Cat Feather Toy','[SAMPLE] Interactive wand toy.','Toys',NULL,NULL,'2026-11-18 03:31:37','2026-11-18 03:31:37');
/*!40000 ALTER TABLE `product` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `purchase_order`
--

DROP TABLE IF EXISTS `purchase_order`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_order` (
  `purchase_order_id` int unsigned NOT NULL AUTO_INCREMENT,
  `po_number` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `business_unit_id` int unsigned NOT NULL,
  `supplier_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_person` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `supplier_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `supplier_phone` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `order_date` datetime NOT NULL,
  `po_status` enum('Draft','Ordered','Partially Received','Fully Received','Cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Ordered',
  `created_by_employee_id` int unsigned NOT NULL,
  `total_cost` decimal(12,2) NOT NULL DEFAULT '0.00',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`purchase_order_id`),
  UNIQUE KEY `po_number` (`po_number`),
  KEY `business_unit_id` (`business_unit_id`),
  KEY `created_by_employee_id` (`created_by_employee_id`),
  CONSTRAINT `purchase_order_ibfk_1` FOREIGN KEY (`business_unit_id`) REFERENCES `business_unit` (`business_unit_id`),
  CONSTRAINT `purchase_order_ibfk_2` FOREIGN KEY (`created_by_employee_id`) REFERENCES `employee` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `purchase_order`
--

LOCK TABLES `purchase_order` WRITE;
/*!40000 ALTER TABLE `purchase_order` DISABLE KEYS */;
/*!40000 ALTER TABLE `purchase_order` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `purchase_order_item`
--

DROP TABLE IF EXISTS `purchase_order_item`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_order_item` (
  `po_item_id` int unsigned NOT NULL AUTO_INCREMENT,
  `purchase_order_id` int unsigned NOT NULL,
  `business_product_id` int unsigned NOT NULL,
  `quantity_ordered` int NOT NULL,
  `quantity_received` int NOT NULL DEFAULT '0',
  `unit_cost` decimal(10,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`po_item_id`),
  KEY `purchase_order_id` (`purchase_order_id`),
  KEY `business_product_id` (`business_product_id`),
  CONSTRAINT `purchase_order_item_ibfk_1` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_order` (`purchase_order_id`) ON DELETE CASCADE,
  CONSTRAINT `purchase_order_item_ibfk_2` FOREIGN KEY (`business_product_id`) REFERENCES `business_product` (`business_product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `purchase_order_item`
--

LOCK TABLES `purchase_order_item` WRITE;
/*!40000 ALTER TABLE `purchase_order_item` DISABLE KEYS */;
/*!40000 ALTER TABLE `purchase_order_item` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `purchase_order_request`
--

DROP TABLE IF EXISTS `purchase_order_request`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_order_request` (
  `request_id` int unsigned NOT NULL AUTO_INCREMENT,
  `request_number` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `business_unit_id` int unsigned NOT NULL,
  `requested_by_employee_id` int unsigned NOT NULL,
  `request_date` datetime NOT NULL,
  `reason` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `priority` enum('Low','Medium','High','Urgent') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Medium',
  `needed_by_date` date DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `request_status` enum('Pending','Approved','Rejected','Fulfilled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Pending',
  `reviewed_by_employee_id` int unsigned DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `review_notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`request_id`),
  UNIQUE KEY `request_number` (`request_number`),
  KEY `business_unit_id` (`business_unit_id`),
  KEY `requested_by_employee_id` (`requested_by_employee_id`),
  KEY `reviewed_by_employee_id` (`reviewed_by_employee_id`),
  CONSTRAINT `purchase_order_request_ibfk_1` FOREIGN KEY (`business_unit_id`) REFERENCES `business_unit` (`business_unit_id`),
  CONSTRAINT `purchase_order_request_ibfk_2` FOREIGN KEY (`requested_by_employee_id`) REFERENCES `employee` (`employee_id`),
  CONSTRAINT `purchase_order_request_ibfk_3` FOREIGN KEY (`reviewed_by_employee_id`) REFERENCES `employee` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `purchase_order_request`
--

LOCK TABLES `purchase_order_request` WRITE;
/*!40000 ALTER TABLE `purchase_order_request` DISABLE KEYS */;
/*!40000 ALTER TABLE `purchase_order_request` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `purchase_order_request_item`
--

DROP TABLE IF EXISTS `purchase_order_request_item`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_order_request_item` (
  `request_item_id` int unsigned NOT NULL AUTO_INCREMENT,
  `request_id` int unsigned NOT NULL,
  `business_product_id` int unsigned NOT NULL,
  `quantity_requested` int NOT NULL,
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`request_item_id`),
  KEY `request_id` (`request_id`),
  KEY `business_product_id` (`business_product_id`),
  CONSTRAINT `purchase_order_request_item_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `purchase_order_request` (`request_id`) ON DELETE CASCADE,
  CONSTRAINT `purchase_order_request_item_ibfk_2` FOREIGN KEY (`business_product_id`) REFERENCES `business_product` (`business_product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `purchase_order_request_item`
--

LOCK TABLES `purchase_order_request_item` WRITE;
/*!40000 ALTER TABLE `purchase_order_request_item` DISABLE KEYS */;
/*!40000 ALTER TABLE `purchase_order_request_item` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `refund_request`
--

DROP TABLE IF EXISTS `refund_request`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `refund_request` (
  `refund_request_id` int unsigned NOT NULL AUTO_INCREMENT,
  `refund_reference` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `business_unit_id` int unsigned NOT NULL,
  `customer_id` int unsigned NOT NULL,
  `order_id` int unsigned DEFAULT NULL,
  `sale_id` int unsigned DEFAULT NULL,
  `payment_id` int unsigned DEFAULT NULL,
  `channel` enum('online','walkin') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'online',
  `requested_amount` decimal(12,2) NOT NULL,
  `approved_amount` decimal(12,2) DEFAULT NULL,
  `customer_reason` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `supporting_evidence` text COLLATE utf8mb4_unicode_ci,
  `request_status` enum('pending','approved','rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `decided_by_employee_id` int unsigned DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `rejection_reason` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`refund_request_id`),
  UNIQUE KEY `refund_reference` (`refund_reference`),
  KEY `business_unit_id` (`business_unit_id`),
  KEY `customer_id` (`customer_id`),
  KEY `order_id` (`order_id`),
  KEY `sale_id` (`sale_id`),
  KEY `payment_id` (`payment_id`),
  KEY `decided_by_employee_id` (`decided_by_employee_id`),
  CONSTRAINT `refund_request_ibfk_1` FOREIGN KEY (`business_unit_id`) REFERENCES `business_unit` (`business_unit_id`),
  CONSTRAINT `refund_request_ibfk_2` FOREIGN KEY (`customer_id`) REFERENCES `customer` (`customer_id`),
  CONSTRAINT `refund_request_ibfk_3` FOREIGN KEY (`order_id`) REFERENCES `customer_order` (`order_id`),
  CONSTRAINT `refund_request_ibfk_4` FOREIGN KEY (`sale_id`) REFERENCES `sale` (`sale_id`),
  CONSTRAINT `refund_request_ibfk_5` FOREIGN KEY (`payment_id`) REFERENCES `payment` (`payment_id`),
  CONSTRAINT `refund_request_ibfk_6` FOREIGN KEY (`decided_by_employee_id`) REFERENCES `employee` (`employee_id`),
  CONSTRAINT `chk_refund_req_amount_positive` CHECK ((`requested_amount` > 0)),
  CONSTRAINT `chk_refund_req_approved_bounds` CHECK (((`approved_amount` is null) or ((`approved_amount` > 0) and (`approved_amount` <= `requested_amount`))))
) ENGINE=InnoDB AUTO_INCREMENT=63 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `refund_request`
--

LOCK TABLES `refund_request` WRITE;
/*!40000 ALTER TABLE `refund_request` DISABLE KEYS */;
/*!40000 ALTER TABLE `refund_request` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `requested_pet_service`
--

DROP TABLE IF EXISTS `requested_pet_service`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `requested_pet_service` (
  `requested_pet_service_id` int unsigned NOT NULL AUTO_INCREMENT,
  `appointment_pet_id` int unsigned NOT NULL,
  `service_id` int unsigned NOT NULL,
  `requested_price` decimal(10,2) NOT NULL,
  `request_status` enum('pending','approved','declined') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`requested_pet_service_id`),
  UNIQUE KEY `uk_pet_service` (`appointment_pet_id`,`service_id`),
  KEY `service_id` (`service_id`),
  CONSTRAINT `requested_pet_service_ibfk_1` FOREIGN KEY (`appointment_pet_id`) REFERENCES `appointment_pet` (`appointment_pet_id`),
  CONSTRAINT `requested_pet_service_ibfk_2` FOREIGN KEY (`service_id`) REFERENCES `service` (`service_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `requested_pet_service`
--

LOCK TABLES `requested_pet_service` WRITE;
/*!40000 ALTER TABLE `requested_pet_service` DISABLE KEYS */;
/*!40000 ALTER TABLE `requested_pet_service` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `role`
--

DROP TABLE IF EXISTS `role`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `role` (
  `role_id` int unsigned NOT NULL AUTO_INCREMENT,
  `role_name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`role_id`),
  UNIQUE KEY `role_name` (`role_name`)
) ENGINE=InnoDB AUTO_INCREMENT=48 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `role`
--

LOCK TABLES `role` WRITE;
/*!40000 ALTER TABLE `role` DISABLE KEYS */;
INSERT INTO `role` VALUES (1,'VETERINARIAN_WORKSPACE','Veterinarian Workspace — for employees assigned veterinarian access.','2026-11-14 14:16:25'),(2,'VETERINARY_NURSE_WORKSPACE','Veterinary Nurse Workspace — for employees assigned veterinary nurse access.','2026-11-14 14:16:25'),(3,'VETERINARY_TECHNICIAN_WORKSPACE','Veterinary Technician Workspace — for employees assigned veterinary technician access.','2026-11-14 14:16:25'),(4,'STYLIST_WORKSPACE','Stylist Workspace — for employees assigned pet stylist access.','2026-11-14 14:22:09'),(5,'BATHER_WORKSPACE','Bather Workspace — for employees assigned pet bather access.','2026-11-14 14:22:09'),(6,'SUPER_ADMINISTRATOR','Highest-level permanent account with unrestricted system-wide administrative access','2026-11-14 14:23:36'),(7,'SYSTEM_ADMINISTRATOR','Manages accounts, roles, positions, and permissions within the assigned business unit','2026-11-14 14:23:36'),(8,'BUSINESS_MANAGER','Operational management within the assigned business unit','2026-11-14 14:23:36'),(9,'RECEPTIONIST','Customer-facing administration and appointment coordination','2026-11-14 14:23:36'),(10,'VETERINARIAN','Consultation, diagnosis, treatment and medical records','2026-11-14 14:23:36'),(11,'NURSE','Administers authorized treatments and documents nursing work','2026-11-14 14:23:36'),(12,'TECHNICIAN','Technical tasks and diagnostic processing','2026-11-14 14:23:36'),(13,'INVENTORY_MANAGER','Pet Store stock oversight: tracks inventory levels, receiving, and stock accuracy','2026-11-14 14:23:36'),(14,'DELIVERY_PERSONNEL','Pet Store order delivery: views assigned delivery orders and updates delivery progress','2026-11-14 14:23:36');
/*!40000 ALTER TABLE `role` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `role_permission`
--

DROP TABLE IF EXISTS `role_permission`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_permission` (
  `role_id` int unsigned NOT NULL,
  `permission_id` int unsigned NOT NULL,
  PRIMARY KEY (`role_id`,`permission_id`),
  KEY `permission_id` (`permission_id`),
  CONSTRAINT `role_permission_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `role` (`role_id`),
  CONSTRAINT `role_permission_ibfk_2` FOREIGN KEY (`permission_id`) REFERENCES `permission` (`permission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `role_permission`
--

LOCK TABLES `role_permission` WRITE;
/*!40000 ALTER TABLE `role_permission` DISABLE KEYS */;
INSERT INTO `role_permission` VALUES (1,1),(2,1),(3,1),(6,1),(7,1),(8,1),(9,1),(10,1),(11,1),(12,1),(6,2),(9,2),(6,3),(7,3),(6,4),(7,4),(6,5),(7,5),(6,6),(7,6),(6,7),(7,7),(6,8),(8,8),(1,9),(2,9),(6,9),(8,9),(10,9),(11,9),(1,10),(6,10),(10,10),(6,11),(13,11),(6,12),(13,12),(6,13),(14,13),(6,14),(14,14);
/*!40000 ALTER TABLE `role_permission` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sale`
--

DROP TABLE IF EXISTS `sale`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sale` (
  `sale_id` int unsigned NOT NULL AUTO_INCREMENT,
  `business_unit_id` int unsigned NOT NULL,
  `branch_id` int unsigned NOT NULL,
  `customer_id` int unsigned DEFAULT NULL,
  `employee_id` int unsigned NOT NULL,
  `sale_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `total_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `sale_status` enum('completed','voided') NOT NULL DEFAULT 'completed',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`sale_id`),
  KEY `business_unit_id` (`business_unit_id`),
  KEY `branch_id` (`branch_id`),
  KEY `customer_id` (`customer_id`),
  KEY `employee_id` (`employee_id`),
  CONSTRAINT `sale_ibfk_1` FOREIGN KEY (`business_unit_id`) REFERENCES `business_unit` (`business_unit_id`),
  CONSTRAINT `sale_ibfk_2` FOREIGN KEY (`branch_id`) REFERENCES `branch` (`branch_id`),
  CONSTRAINT `sale_ibfk_3` FOREIGN KEY (`customer_id`) REFERENCES `customer` (`customer_id`),
  CONSTRAINT `sale_ibfk_4` FOREIGN KEY (`employee_id`) REFERENCES `employee` (`employee_id`)
) ENGINE=InnoDB AUTO_INCREMENT=151 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sale`
--

LOCK TABLES `sale` WRITE;
/*!40000 ALTER TABLE `sale` DISABLE KEYS */;
/*!40000 ALTER TABLE `sale` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sale_item`
--

DROP TABLE IF EXISTS `sale_item`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sale_item` (
  `sale_item_id` int unsigned NOT NULL AUTO_INCREMENT,
  `sale_id` int unsigned NOT NULL,
  `business_product_id` int unsigned NOT NULL,
  `quantity` int NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`sale_item_id`),
  KEY `sale_id` (`sale_id`),
  KEY `business_product_id` (`business_product_id`),
  CONSTRAINT `sale_item_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sale` (`sale_id`),
  CONSTRAINT `sale_item_ibfk_2` FOREIGN KEY (`business_product_id`) REFERENCES `business_product` (`business_product_id`)
) ENGINE=InnoDB AUTO_INCREMENT=185 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sale_item`
--

LOCK TABLES `sale_item` WRITE;
/*!40000 ALTER TABLE `sale_item` DISABLE KEYS */;
/*!40000 ALTER TABLE `sale_item` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `schema_migrations`
--

DROP TABLE IF EXISTS `schema_migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `schema_migrations` (
  `filename` varchar(255) NOT NULL,
  `applied_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `schema_migrations`
--

LOCK TABLES `schema_migrations` WRITE;
/*!40000 ALTER TABLE `schema_migrations` DISABLE KEYS */;
INSERT INTO `schema_migrations` VALUES ('001_initial_schema.sql','2026-11-14 14:16:25'),('002_add_customer_middle_name.sql','2026-11-14 14:16:25'),('003_create_customer_reviews.sql','2026-11-14 14:16:25'),('004_super_and_sysadmin_rbac.sql','2026-11-14 14:16:25'),('005_audit_activity_and_petstore_positions.sql','2026-11-14 14:16:25'),('006_veterinary_clinic_system_roles.sql','2026-11-14 14:16:25'),('007_veterinary_clinic_positions_and_roles.sql','2026-11-14 14:16:25'),('008_vetclinic_common_and_clinical_positions.sql','2026-11-14 14:22:09'),('009_rename_senior_veterinary_nurse.sql','2026-11-14 14:22:09'),('010_grooming_services_positions_and_roles.sql','2026-11-14 14:22:09'),('011_petstore_business_manager_schema.sql','2026-11-14 14:22:09'),('012_refund_request_schema.sql','2026-11-14 14:22:09'),('013_grooming_services_catalog_schema.sql','2026-11-14 14:22:09'),('014_inventory_access_keys.sql','2026-11-14 14:22:09'),('015_add_inventory_access_key_revocation.sql','2026-11-14 14:22:09'),('016_petstore_inventory_manager.sql','2026-11-14 14:22:09'),('017_grooming_inventory_manager.sql','2026-11-14 14:22:09'),('018_add_max_appointment_overlap_to_business_settings.sql','2026-11-14 14:22:09'),('019_create_customer_registration_pending.sql','2026-11-14 14:22:09'),('020_od09_inventory_schema_foundation.sql','2026-11-15 11:44:49'),('021_e2_checkout_holds_and_payment_attempts.sql','2026-11-16 10:07:56'),('022_e2_corrective_checkout_schema.sql','2026-11-16 10:07:57'),('023_s3_fulfillment_accounting_foundation.sql','2026-11-16 10:33:32'),('024_s3_fulfillment_accounting_integrity.sql','2026-11-16 10:44:03'),('025_s3_cod_remittance_lifecycle.sql','2026-11-17 10:20:12'),('026_customer_order_completed_at.sql','2026-11-17 10:20:12'),('027_petstore_delivery_personnel_position.sql','2026-11-19 12:27:31'),('028_vetclinic_assistant_nurse_position.sql','2026-11-19 12:34:00'),('029_employee_invitations.sql','2026-11-27 16:14:45'),('030_decouple_employee_invitations.sql','2026-11-27 16:15:16');
/*!40000 ALTER TABLE `schema_migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `service`
--

DROP TABLE IF EXISTS `service`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service` (
  `service_id` int unsigned NOT NULL AUTO_INCREMENT,
  `service_name` varchar(200) NOT NULL,
  `service_category` enum('medical','grooming') NOT NULL,
  `grooming_category` enum('Bathing & Brushing','Styling','Wellness','Specialized') DEFAULT NULL,
  `description` text,
  `base_price` decimal(10,2) NOT NULL,
  `estimated_duration_minutes` int NOT NULL DEFAULT '30',
  `additional_duration_rate` decimal(10,2) NOT NULL DEFAULT '0.00',
  `additional_employee_fee` decimal(10,2) NOT NULL DEFAULT '0.00',
  `service_status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`service_id`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service`
--

LOCK TABLES `service` WRITE;
/*!40000 ALTER TABLE `service` DISABLE KEYS */;
INSERT INTO `service` VALUES (1,'Basic Bath & Dry','grooming',NULL,'[SAMPLE] Warm oatmeal shampoo bath, conditioning rinse, towel dry, and gentle blow dry.',25.00,45,0.00,0.00,'active','2026-11-18 03:31:37','2026-11-18 03:31:37'),(2,'Full Grooming (Bath + Haircut)','grooming',NULL,'[SAMPLE] Complete bath, fluff dry, breed-standard or custom scissoring, ear care, and sanitary trim.',55.00,90,0.00,0.00,'active','2026-11-18 03:31:37','2026-11-18 03:31:37'),(3,'Nail Trim','grooming',NULL,'[SAMPLE] Precision nail clipping and smooth paw-pad filing for comfortable walking.',8.00,15,0.00,0.00,'active','2026-11-18 03:31:37','2026-11-18 03:31:37'),(4,'Teeth Brushing','grooming',NULL,'[SAMPLE] Gentle enzymatic tooth cleaning and breath-freshening gum care.',10.00,15,0.00,0.00,'active','2026-11-18 03:31:37','2026-11-18 03:31:37'),(5,'De-shedding Treatment','grooming',NULL,'[SAMPLE] Deep undercoat release bath and specialized carding brush-out.',30.00,40,0.00,0.00,'active','2026-11-18 03:31:37','2026-11-18 03:31:37'),(8,'Comprehensive Wellness Exam','medical',NULL,'[SAMPLE] Full nose-to-tail physical assessment, weight and vitals check, and preventive care consultation.',48.00,30,0.00,0.00,'active','2026-11-18 03:31:37','2026-11-18 03:31:37'),(9,'Core Vaccination Visit','medical',NULL,'[SAMPLE] Administration of age-appropriate core vaccines following a veterinary physical check.',38.00,20,0.00,0.00,'active','2026-11-18 03:31:37','2026-11-18 03:31:37'),(10,'Diagnostic Blood & Wellness Panel','medical',NULL,'[SAMPLE] Complete blood count (CBC) and organ chemistry profile for early health screening.',85.00,35,0.00,0.00,'active','2026-11-18 03:31:37','2026-11-18 03:31:37'),(11,'Veterinary Dental Assessment & Scaling','medical',NULL,'[SAMPLE] Ultrasonic dental scaling, polishing, and oral health charting under clinical supervision.',140.00,60,0.00,0.00,'active','2026-11-18 03:31:37','2026-11-18 03:31:37'),(12,'Microchip Identification Implant','medical',NULL,'[SAMPLE] ISO-standard permanent microchip placement and registry verification.',32.00,15,0.00,0.00,'active','2026-11-18 03:31:37','2026-11-18 03:31:37');
/*!40000 ALTER TABLE `service` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `service_invoice`
--

DROP TABLE IF EXISTS `service_invoice`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_invoice` (
  `invoice_id` int unsigned NOT NULL AUTO_INCREMENT,
  `appointment_id` int unsigned NOT NULL,
  `invoice_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `total_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `invoice_status` enum('draft','issued','paid','cancelled') NOT NULL DEFAULT 'draft',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`invoice_id`),
  UNIQUE KEY `appointment_id` (`appointment_id`),
  CONSTRAINT `service_invoice_ibfk_1` FOREIGN KEY (`appointment_id`) REFERENCES `appointment` (`appointment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_invoice`
--

LOCK TABLES `service_invoice` WRITE;
/*!40000 ALTER TABLE `service_invoice` DISABLE KEYS */;
/*!40000 ALTER TABLE `service_invoice` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stock_adjustment`
--

DROP TABLE IF EXISTS `stock_adjustment`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_adjustment` (
  `adjustment_id` int unsigned NOT NULL AUTO_INCREMENT,
  `business_unit_id` int unsigned NOT NULL,
  `business_product_id` int unsigned NOT NULL,
  `employee_id` int unsigned NOT NULL,
  `adjustment_type` enum('increase','decrease') COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity_change` int NOT NULL,
  `previous_on_hand` int NOT NULL,
  `new_on_hand` int NOT NULL,
  `reason` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `related_reference` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`adjustment_id`),
  KEY `business_unit_id` (`business_unit_id`),
  KEY `business_product_id` (`business_product_id`),
  KEY `employee_id` (`employee_id`),
  CONSTRAINT `stock_adjustment_ibfk_1` FOREIGN KEY (`business_unit_id`) REFERENCES `business_unit` (`business_unit_id`),
  CONSTRAINT `stock_adjustment_ibfk_2` FOREIGN KEY (`business_product_id`) REFERENCES `business_product` (`business_product_id`),
  CONSTRAINT `stock_adjustment_ibfk_3` FOREIGN KEY (`employee_id`) REFERENCES `employee` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stock_adjustment`
--

LOCK TABLES `stock_adjustment` WRITE;
/*!40000 ALTER TABLE `stock_adjustment` DISABLE KEYS */;
/*!40000 ALTER TABLE `stock_adjustment` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stock_receiving`
--

DROP TABLE IF EXISTS `stock_receiving`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_receiving` (
  `receiving_id` int unsigned NOT NULL AUTO_INCREMENT,
  `receiving_reference` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `purchase_order_id` int unsigned NOT NULL,
  `business_unit_id` int unsigned NOT NULL,
  `employee_id` int unsigned NOT NULL,
  `receiving_date` datetime NOT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`receiving_id`),
  UNIQUE KEY `receiving_reference` (`receiving_reference`),
  KEY `purchase_order_id` (`purchase_order_id`),
  KEY `business_unit_id` (`business_unit_id`),
  KEY `employee_id` (`employee_id`),
  CONSTRAINT `stock_receiving_ibfk_1` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_order` (`purchase_order_id`),
  CONSTRAINT `stock_receiving_ibfk_2` FOREIGN KEY (`business_unit_id`) REFERENCES `business_unit` (`business_unit_id`),
  CONSTRAINT `stock_receiving_ibfk_3` FOREIGN KEY (`employee_id`) REFERENCES `employee` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stock_receiving`
--

LOCK TABLES `stock_receiving` WRITE;
/*!40000 ALTER TABLE `stock_receiving` DISABLE KEYS */;
/*!40000 ALTER TABLE `stock_receiving` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stock_receiving_item`
--

DROP TABLE IF EXISTS `stock_receiving_item`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_receiving_item` (
  `receiving_item_id` int unsigned NOT NULL AUTO_INCREMENT,
  `receiving_id` int unsigned NOT NULL,
  `po_item_id` int unsigned NOT NULL,
  `business_product_id` int unsigned NOT NULL,
  `quantity_received` int NOT NULL,
  `quantity_accepted` int NOT NULL,
  `quantity_rejected` int NOT NULL DEFAULT '0',
  `rejection_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`receiving_item_id`),
  KEY `receiving_id` (`receiving_id`),
  KEY `po_item_id` (`po_item_id`),
  KEY `business_product_id` (`business_product_id`),
  CONSTRAINT `stock_receiving_item_ibfk_1` FOREIGN KEY (`receiving_id`) REFERENCES `stock_receiving` (`receiving_id`) ON DELETE CASCADE,
  CONSTRAINT `stock_receiving_item_ibfk_2` FOREIGN KEY (`po_item_id`) REFERENCES `purchase_order_item` (`po_item_id`),
  CONSTRAINT `stock_receiving_item_ibfk_3` FOREIGN KEY (`business_product_id`) REFERENCES `business_product` (`business_product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stock_receiving_item`
--

LOCK TABLES `stock_receiving_item` WRITE;
/*!40000 ALTER TABLE `stock_receiving_item` DISABLE KEYS */;
/*!40000 ALTER TABLE `stock_receiving_item` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_account`
--

DROP TABLE IF EXISTS `user_account`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_account` (
  `user_account_id` int unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `username` varchar(100) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `account_status` enum('active','inactive','suspended') NOT NULL DEFAULT 'active',
  `is_super_admin` tinyint(1) NOT NULL DEFAULT '0',
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_account_id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=617 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_account`
--

LOCK TABLES `user_account` WRITE;
/*!40000 ALTER TABLE `user_account` DISABLE KEYS */;
INSERT INTO `user_account` VALUES (1,'maya.lin@staff.sample.vetgrpet',NULL,'$2y$10$GQHImahPTzoVsbLPYk8YL.u.QjdW.b6DfZEZJ7hmosTxj75GWWrwe','active',0,NULL,'2026-11-14 14:22:09','2026-11-14 14:22:09'),(211,'superadmin@vetgrpet.local','superadmin','$2y$10$qRuxtUTRTFeezkR5PnaBzea64.tB925IiIiYB4vBaklR2aagWYPiq','active',1,'2026-11-17 08:15:48','2026-11-17 08:15:48','2026-11-17 08:15:48'),(592,'elena.vance@staff.sample.vetgrpet',NULL,'$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234','inactive',0,NULL,'2026-11-18 03:31:37','2026-11-18 03:31:37'),(593,'marcus.chen@staff.sample.vetgrpet',NULL,'$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234','inactive',0,NULL,'2026-11-18 03:31:37','2026-11-18 03:31:37'),(594,'claire.dubois@staff.sample.vetgrpet',NULL,'$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234','inactive',0,NULL,'2026-11-18 03:31:37','2026-11-18 03:31:37'),(595,'david.reyes@staff.sample.vetgrpet',NULL,'$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234','inactive',0,NULL,'2026-11-18 03:31:37','2026-11-18 03:31:37'),(596,'hannah.kim@staff.sample.vetgrpet',NULL,'$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234','inactive',0,NULL,'2026-11-18 03:31:37','2026-11-18 03:31:37'),(597,'owen.bennett@staff.sample.vetgrpet',NULL,'$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234','inactive',0,NULL,'2026-11-18 03:31:37','2026-11-18 03:31:37'),(598,'oliver.vance.sample@vetgrpet.local',NULL,'$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234','active',0,NULL,'2026-11-18 03:31:37','2026-11-18 03:31:37'),(599,'sophia.martinez.sample@vetgrpet.local',NULL,'$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234','active',0,NULL,'2026-11-18 03:31:37','2026-11-18 03:31:37'),(600,'liam.gallagher.sample@vetgrpet.local',NULL,'$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234','active',0,NULL,'2026-11-18 03:31:37','2026-11-18 03:31:37'),(601,'emma.watson.sample@vetgrpet.local',NULL,'$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234','active',0,NULL,'2026-11-18 03:31:37','2026-11-18 03:31:37'),(602,'noah.bennett.sample@vetgrpet.local',NULL,'$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234','active',0,NULL,'2026-11-18 03:31:37','2026-11-18 03:31:37'),(604,'gian.machutes6921@gmail.com',NULL,'$2y$10$p/KrsXcPxbqapD2IDlD92.4D7wuFKYZeSdBD6xMceHUMsfmXDuPz2','inactive',0,NULL,'2026-11-27 11:14:27','2026-11-27 11:38:40'),(605,'ty202500497@wmsu.edu.ph',NULL,'$2y$10$oLafXbjv0va.6YsyXFm4DeqYfLTMlvaLR0JVei35uDvcUV.GsdNVa','active',0,NULL,'2026-11-27 11:15:17','2026-11-27 11:47:36'),(606,'crutcrutmeow@gmail.com',NULL,'$2y$10$B8.e7Fe.PPCH1H85ZT0o6Og3RvWVB.kP71pFaVEscap2KIUm7uP3O','active',0,NULL,'2026-11-27 11:42:37','2026-11-27 11:42:37'),(607,'sysadmin.petstore@vetgrpet.local','sysadmin_petstore','$2y$10$Eg3UNokdqWNwNym6uzBYkuq60.ZMOyojVAtN5JfVMb7JxkG9FaZtO','active',0,'2026-11-27 16:16:18','2026-11-27 16:16:18','2026-11-27 16:16:18'),(608,'sysadmin.vetclinic@vetgrpet.local','sysadmin_vetclinic','$2y$10$B6/yqdYZhqSLcXALq9LI1u66qqvyGjTWIM..OapMESoATW7FG3ks2','active',0,'2026-11-27 16:16:18','2026-11-27 16:16:18','2026-11-27 16:16:18'),(609,'sysadmin.grooming@vetgrpet.local','sysadmin_grooming','$2y$10$fLDKRYRk.rrGzttr.L6/Q.t4IlC8uTk0hPwVgt4roI/xrBJg6vErC','active',0,'2026-11-27 16:16:18','2026-11-27 16:16:18','2026-11-27 16:16:18');
/*!40000 ALTER TABLE `user_account` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping events for database 'vetgrpet_test'
--

--
-- Dumping routines for database 'vetgrpet_test'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-11-28 22:52:54
