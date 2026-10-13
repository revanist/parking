-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: campus_parking
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Current Database: `campus_parking`
--

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `campus_parking` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci */;

USE `campus_parking`;

--
-- Table structure for table `audit_logs`
--

DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit_user` (`user_id`),
  KEY `idx_audit_action` (`action`),
  KEY `idx_audit_created` (`created_at`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_logs`
--

LOCK TABLES `audit_logs` WRITE;
/*!40000 ALTER TABLE `audit_logs` DISABLE KEYS */;
INSERT INTO `audit_logs` VALUES (5,NULL,'password_reset_success','Password successfully reset for account admin@gmail.com',NULL,'2026-10-13 12:43:06'),(6,NULL,'password_reset_request','Password reset link requested for admin@gmail.com','::1','2026-10-13 12:45:11'),(7,NULL,'password_reset_success','Password successfully reset for account admin@gmail.com','::1','2026-10-13 12:45:11'),(8,1,'user_created','Created account cardo@gmail.com with role security.','::1','2026-10-13 14:13:11'),(9,1,'user_created','Created account trump@gmail.com with role student.','::1','2026-10-13 14:13:37');
/*!40000 ALTER TABLE `audit_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `parking_areas`
--

DROP TABLE IF EXISTS `parking_areas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `parking_areas` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `area_name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `area_type` enum('student','faculty','visitor','motorcycle','general') NOT NULL DEFAULT 'general',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_parking_area_name` (`area_name`),
  KEY `idx_area_type` (`area_type`),
  KEY `idx_area_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `parking_areas`
--

LOCK TABLES `parking_areas` WRITE;
/*!40000 ALTER TABLE `parking_areas` DISABLE KEYS */;
INSERT INTO `parking_areas` VALUES (1,'Student Parking','Main parking area for students.','student','active','2026-10-10 01:38:30'),(2,'Faculty Parking','Reserved parking area for faculty members.','faculty','active','2026-10-10 01:38:30'),(3,'Visitor Parking','Temporary parking for campus visitors.','visitor','active','2026-10-10 01:38:30'),(4,'Motorcycle Parking','Designated motorcycle parking area.','motorcycle','active','2026-10-10 01:38:30');
/*!40000 ALTER TABLE `parking_areas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `parking_logs`
--

DROP TABLE IF EXISTS `parking_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `parking_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `vehicle_id` int(10) unsigned DEFAULT NULL,
  `visitor_id` int(10) unsigned DEFAULT NULL,
  `parking_slot_id` int(10) unsigned DEFAULT NULL,
  `entry_time` datetime NOT NULL DEFAULT current_timestamp(),
  `exit_time` datetime DEFAULT NULL,
  `recorded_by` int(10) unsigned DEFAULT NULL,
  `status` enum('inside','completed','cancelled') NOT NULL DEFAULT 'inside',
  `notes` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_logs_vehicle` (`vehicle_id`),
  KEY `idx_logs_visitor` (`visitor_id`),
  KEY `idx_logs_slot` (`parking_slot_id`),
  KEY `idx_logs_recorder` (`recorded_by`),
  KEY `idx_logs_status` (`status`),
  KEY `idx_logs_entry` (`entry_time`),
  CONSTRAINT `fk_log_recorder` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_log_slot` FOREIGN KEY (`parking_slot_id`) REFERENCES `parking_slots` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_log_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_log_visitor` FOREIGN KEY (`visitor_id`) REFERENCES `visitors` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `parking_logs`
--

LOCK TABLES `parking_logs` WRITE;
/*!40000 ALTER TABLE `parking_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `parking_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `parking_permits`
--

DROP TABLE IF EXISTS `parking_permits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `parking_permits` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `vehicle_id` int(10) unsigned NOT NULL,
  `parking_slot_id` int(10) unsigned DEFAULT NULL,
  `permit_number` varchar(40) DEFAULT NULL,
  `qr_token` char(64) DEFAULT NULL,
  `status` enum('pending','approved','rejected','expired','cancelled') NOT NULL DEFAULT 'pending',
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `expires_at` date DEFAULT NULL,
  `rejection_reason` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_permit_number` (`permit_number`),
  UNIQUE KEY `uq_permit_qr_token` (`qr_token`),
  KEY `idx_permit_user` (`user_id`),
  KEY `idx_permit_vehicle` (`vehicle_id`),
  KEY `idx_permit_slot` (`parking_slot_id`),
  KEY `idx_permit_status` (`status`),
  KEY `idx_permit_applied` (`applied_at`),
  CONSTRAINT `fk_permit_slot` FOREIGN KEY (`parking_slot_id`) REFERENCES `parking_slots` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_permit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_permit_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `parking_permits`
--

LOCK TABLES `parking_permits` WRITE;
/*!40000 ALTER TABLE `parking_permits` DISABLE KEYS */;
/*!40000 ALTER TABLE `parking_permits` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `parking_slots`
--

DROP TABLE IF EXISTS `parking_slots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `parking_slots` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `parking_area_id` int(10) unsigned NOT NULL,
  `slot_code` varchar(30) NOT NULL,
  `status` enum('available','reserved','maintenance','inactive') NOT NULL DEFAULT 'available',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_slot_code` (`slot_code`),
  KEY `idx_slots_area` (`parking_area_id`),
  KEY `idx_slots_status` (`status`),
  CONSTRAINT `fk_slot_area` FOREIGN KEY (`parking_area_id`) REFERENCES `parking_areas` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `parking_slots`
--

LOCK TABLES `parking_slots` WRITE;
/*!40000 ALTER TABLE `parking_slots` DISABLE KEYS */;
INSERT INTO `parking_slots` VALUES (1,1,'STU-01','available','2026-10-10 01:38:30'),(2,1,'STU-02','available','2026-10-10 01:38:30'),(3,1,'STU-03','available','2026-10-10 01:38:30'),(4,2,'FAC-01','available','2026-10-10 01:38:30'),(5,2,'FAC-02','available','2026-10-10 01:38:30'),(6,3,'VIS-01','available','2026-10-10 01:38:30'),(7,3,'VIS-02','available','2026-10-10 01:38:30'),(8,4,'MOT-01','available','2026-10-10 01:38:30'),(9,4,'MOT-02','available','2026-10-10 01:38:30'),(10,2,'FAC-03','available','2026-10-13 12:50:06'),(11,2,'FAC-04','available','2026-10-13 12:50:06'),(12,2,'FAC-05','available','2026-10-13 12:50:06'),(13,2,'FAC-06','available','2026-10-13 12:50:06'),(14,2,'FAC-07','available','2026-10-13 12:50:06'),(15,2,'FAC-08','available','2026-10-13 12:50:06'),(16,2,'FAC-09','available','2026-10-13 12:50:06'),(17,2,'FAC-10','available','2026-10-13 12:50:06'),(18,4,'MOT-03','available','2026-10-13 12:50:06'),(19,4,'MOT-04','available','2026-10-13 12:50:06'),(20,4,'MOT-05','available','2026-10-13 12:50:06'),(21,4,'MOT-06','available','2026-10-13 12:50:06'),(22,4,'MOT-07','available','2026-10-13 12:50:06'),(23,4,'MOT-08','available','2026-10-13 12:50:06'),(24,4,'MOT-09','available','2026-10-13 12:50:06'),(25,4,'MOT-10','available','2026-10-13 12:50:06'),(26,4,'MOT-11','available','2026-10-13 12:50:06'),(27,4,'MOT-12','available','2026-10-13 12:50:06'),(28,4,'MOT-13','available','2026-10-13 12:50:06'),(29,4,'MOT-14','available','2026-10-13 12:50:06'),(30,4,'MOT-15','available','2026-10-13 12:50:06'),(31,1,'STU-04','available','2026-10-13 12:50:06'),(32,1,'STU-05','available','2026-10-13 12:50:06'),(33,1,'STU-06','available','2026-10-13 12:50:06'),(34,1,'STU-07','available','2026-10-13 12:50:06'),(35,1,'STU-08','available','2026-10-13 12:50:06'),(36,1,'STU-09','available','2026-10-13 12:50:06'),(37,1,'STU-10','available','2026-10-13 12:50:06'),(38,3,'VIS-03','available','2026-10-13 12:50:06'),(39,3,'VIS-04','available','2026-10-13 12:50:06'),(40,3,'VIS-05','available','2026-10-13 12:50:06');
/*!40000 ALTER TABLE `parking_slots` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_resets`
--

DROP TABLE IF EXISTS `password_resets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_resets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pwd_reset_user` (`user_id`),
  KEY `idx_pwd_reset_token` (`token_hash`),
  KEY `idx_pwd_reset_expires` (`expires_at`),
  CONSTRAINT `fk_pwd_reset_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_resets`
--

LOCK TABLES `password_resets` WRITE;
/*!40000 ALTER TABLE `password_resets` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_resets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `system_settings`
--

DROP TABLE IF EXISTS `system_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `system_settings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(80) NOT NULL,
  `setting_value` varchar(255) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_setting_key` (`setting_key`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `system_settings`
--

LOCK TABLES `system_settings` WRITE;
/*!40000 ALTER TABLE `system_settings` DISABLE KEYS */;
INSERT INTO `system_settings` VALUES (1,'parking_open_time','06:00','2026-10-10 01:38:30'),(2,'parking_close_time','22:00','2026-10-10 01:38:30'),(3,'default_permit_months','6','2026-10-10 01:38:30'),(4,'system_name','CampusPark','2026-10-10 01:38:30'),(5,'timezone','Asia/Manila','2026-10-13 10:51:15');
/*!40000 ALTER TABLE `system_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `fullname` varchar(120) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','security','faculty','student') NOT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `is_super_admin` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`),
  KEY `idx_users_role` (`role`),
  KEY `idx_users_status` (`status`),
  KEY `idx_users_super_admin` (`is_super_admin`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'Super Admin','admin@gmail.com','$2y$10$5iHyzzkctTBh2cZGMlq23.1RB8JQ5tPUiAMVticufkpNJJm44uZ.2','admin','active',1,'2026-10-10 01:38:30','2026-10-13 12:45:11'),(4,'ricardo dalisay','cardo@gmail.com','$2y$10$j7.Itnx6tn3ltPqACQ6VV..pPzR1aW5.3XirQddKWo8hX1/0fxFlO','security','active',0,'2026-10-13 14:13:11','2026-10-13 14:13:11'),(5,'donard trump','trump@gmail.com','$2y$10$BeshS9YtQCPTOGuJavMA4uH.Wg2/lzL5r7c/V4QdG7m4c3kKG0fZC','student','active',0,'2026-10-13 14:13:37','2026-10-13 14:13:37');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `vehicles`
--

DROP TABLE IF EXISTS `vehicles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `vehicles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `plate_number` varchar(20) NOT NULL,
  `vehicle_type` enum('car','motorcycle','van','other') NOT NULL DEFAULT 'car',
  `make` varchar(60) NOT NULL,
  `model` varchar(60) NOT NULL,
  `color` varchar(40) NOT NULL,
  `vehicle_year` year(4) DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vehicle_plate` (`plate_number`),
  KEY `idx_vehicle_user` (`user_id`),
  KEY `idx_vehicle_status` (`status`),
  CONSTRAINT `fk_vehicle_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `vehicles`
--

LOCK TABLES `vehicles` WRITE;
/*!40000 ALTER TABLE `vehicles` DISABLE KEYS */;
/*!40000 ALTER TABLE `vehicles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `violations`
--

DROP TABLE IF EXISTS `violations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `violations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `vehicle_id` int(10) unsigned DEFAULT NULL,
  `visitor_id` int(10) unsigned DEFAULT NULL,
  `violation_type` enum('unauthorized_parking','wrong_slot','expired_permit','restricted_area','blocking','other') NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `status` enum('unresolved','resolved') NOT NULL DEFAULT 'unresolved',
  `recorded_by` int(10) unsigned DEFAULT NULL,
  `violation_date` datetime NOT NULL DEFAULT current_timestamp(),
  `resolved_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_violation_user` (`user_id`),
  KEY `idx_violation_vehicle` (`vehicle_id`),
  KEY `idx_violation_visitor` (`visitor_id`),
  KEY `idx_violation_recorder` (`recorded_by`),
  KEY `idx_violation_status` (`status`),
  KEY `idx_violation_date` (`violation_date`),
  CONSTRAINT `fk_violation_recorder` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_violation_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_violation_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_violation_visitor` FOREIGN KEY (`visitor_id`) REFERENCES `visitors` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `violations`
--

LOCK TABLES `violations` WRITE;
/*!40000 ALTER TABLE `violations` DISABLE KEYS */;
/*!40000 ALTER TABLE `violations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `visitors`
--

DROP TABLE IF EXISTS `visitors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `visitors` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `visitor_name` varchar(120) NOT NULL,
  `contact` varchar(30) DEFAULT NULL,
  `purpose` varchar(255) DEFAULT NULL,
  `person_to_visit` varchar(120) DEFAULT NULL,
  `vehicle_plate` varchar(20) NOT NULL,
  `vehicle_type` enum('car','motorcycle','van','other') NOT NULL DEFAULT 'car',
  `parking_slot_id` int(10) unsigned DEFAULT NULL,
  `status` enum('inside','exited','cancelled') NOT NULL DEFAULT 'inside',
  `entry_time` datetime DEFAULT NULL,
  `exit_time` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_visitor_plate` (`vehicle_plate`),
  KEY `idx_visitor_status` (`status`),
  KEY `idx_visitor_slot` (`parking_slot_id`),
  KEY `idx_visitor_entry` (`entry_time`),
  CONSTRAINT `fk_visitor_slot` FOREIGN KEY (`parking_slot_id`) REFERENCES `parking_slots` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `visitors`
--

LOCK TABLES `visitors` WRITE;
/*!40000 ALTER TABLE `visitors` DISABLE KEYS */;
/*!40000 ALTER TABLE `visitors` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping events for database 'campus_parking'
--

--
-- Dumping routines for database 'campus_parking'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;
SET FOREIGN_KEY_CHECKS = 1;


-- Dump completed on 2026-10-13 22:17:36
