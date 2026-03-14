/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19  Distrib 10.6.23-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: kings_sacco
-- ------------------------------------------------------
-- Server version	10.6.23-MariaDB-0ubuntu0.22.04.1

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
-- Table structure for table `admin_charge_payments`
--

DROP TABLE IF EXISTS `admin_charge_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `admin_charge_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `charge_id` int(11) NOT NULL,
  `amount_paid` decimal(10,2) NOT NULL,
  `payment_date` date NOT NULL,
  `payment_method` enum('cash','bank','mpesa','cheque') NOT NULL,
  `reference_no` varchar(50) DEFAULT NULL,
  `receipt_no` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `receipt_no` (`receipt_no`),
  KEY `charge_id` (`charge_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `admin_charge_payments_ibfk_1` FOREIGN KEY (`charge_id`) REFERENCES `admin_charges` (`id`) ON DELETE CASCADE,
  CONSTRAINT `admin_charge_payments_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admin_charge_payments`
--

LOCK TABLES `admin_charge_payments` WRITE;
/*!40000 ALTER TABLE `admin_charge_payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `admin_charge_payments` ENABLE KEYS */;
UNLOCK TABLES;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb3 */ ;
/*!50003 SET character_set_results = utf8mb3 */ ;
/*!50003 SET collation_connection  = utf8mb3_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = '' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`pos`@`%`*/ /*!50003 TRIGGER after_admin_charge_payment
AFTER INSERT ON admin_charge_payments
FOR EACH ROW
BEGIN
    UPDATE member_charge_summary 
    SET total_paid = total_paid + NEW.amount_paid,
        last_payment_date = NEW.payment_date
    WHERE member_id = (SELECT member_id FROM admin_charges WHERE id = NEW.charge_id);
    
    UPDATE admin_charges 
    SET status = CASE 
        WHEN (SELECT SUM(amount_paid) FROM admin_charge_payments WHERE charge_id = NEW.charge_id) >= amount 
        THEN 'paid' ELSE 'pending' END,
        paid_date = CASE 
            WHEN (SELECT SUM(amount_paid) FROM admin_charge_payments WHERE charge_id = NEW.charge_id) >= amount 
            THEN NEW.payment_date ELSE NULL END
    WHERE id = NEW.charge_id;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `admin_charge_rates`
--

DROP TABLE IF EXISTS `admin_charge_rates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `admin_charge_rates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `charge_type` enum('registration','monthly_fee','annual_fee','statement_fee','sms_charge','ledger_fee','loan_processing','loan_insurance','guarantor_fee','dividend_processing','withdrawal_fee','transfer_fee','other') NOT NULL,
  `charge_name` varchar(100) NOT NULL,
  `calculation_method` enum('fixed','percentage','tiered') NOT NULL,
  `rate_value` decimal(10,2) NOT NULL,
  `min_amount` decimal(10,2) DEFAULT 0.00,
  `max_amount` decimal(10,2) DEFAULT NULL,
  `applies_to` enum('all','new_members','active_members','loan_applications','withdrawals') DEFAULT 'all',
  `frequency` enum('one_time','monthly','quarterly','annual') DEFAULT 'one_time',
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `effective_from` date NOT NULL,
  `effective_to` date DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `admin_charge_rates_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admin_charge_rates`
--

LOCK TABLES `admin_charge_rates` WRITE;
/*!40000 ALTER TABLE `admin_charge_rates` DISABLE KEYS */;
INSERT INTO `admin_charge_rates` VALUES (1,'registration','Registration Fee','fixed',2000.00,2000.00,NULL,'new_members','one_time','One-time membership registration fee',1,'2026-03-14',NULL,1,'2026-03-14 00:31:46',NULL),(2,'monthly_fee','Monthly Maintenance Fee','fixed',100.00,100.00,NULL,'active_members','monthly','Monthly account maintenance fee',1,'2026-03-14',NULL,1,'2026-03-14 00:31:46',NULL),(3,'annual_fee','Annual Subscription Fee','fixed',500.00,500.00,NULL,'active_members','annual','Annual subscription for SACCO services',1,'2026-03-14',NULL,1,'2026-03-14 00:31:46',NULL),(4,'statement_fee','Statement Request Fee','fixed',50.00,50.00,NULL,'all','one_time','Fee for printed/emailed statements',1,'2026-03-14',NULL,1,'2026-03-14 00:31:46',NULL),(5,'sms_charge','SMS Notification Charge','fixed',5.00,5.00,NULL,'all','monthly','Per SMS notification charge',1,'2026-03-14',NULL,1,'2026-03-14 00:31:46',NULL),(6,'ledger_fee','Ledger Fee','fixed',20.00,20.00,NULL,'all','monthly','Monthly ledger maintenance fee',1,'2026-03-14',NULL,1,'2026-03-14 00:31:46',NULL),(7,'loan_processing','Loan Processing Fee','percentage',1.00,500.00,NULL,'loan_applications','one_time','1% of loan amount (min KES 500)',1,'2026-03-14',NULL,1,'2026-03-14 00:31:46',NULL),(8,'loan_insurance','Loan Insurance Fee','percentage',0.50,200.00,NULL,'loan_applications','one_time','0.5% loan insurance premium',1,'2026-03-14',NULL,1,'2026-03-14 00:31:46',NULL),(9,'guarantor_fee','Guarantor Processing Fee','fixed',200.00,200.00,NULL,'loan_applications','one_time','Fee per guarantor verification',1,'2026-03-14',NULL,1,'2026-03-14 00:31:46',NULL),(10,'dividend_processing','Dividend Processing Fee','percentage',1.00,100.00,NULL,'all','annual','1% of dividend amount',1,'2026-03-14',NULL,1,'2026-03-14 00:31:46',NULL),(11,'withdrawal_fee','Withdrawal Fee','percentage',0.50,50.00,NULL,'withdrawals','one_time','0.5% of withdrawal amount',1,'2026-03-14',NULL,1,'2026-03-14 00:31:46',NULL),(12,'transfer_fee','Transfer Fee','fixed',100.00,100.00,NULL,'all','one_time','Fee for inter-member transfers',1,'2026-03-14',NULL,1,'2026-03-14 00:31:46',NULL);
/*!40000 ALTER TABLE `admin_charge_rates` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `admin_charge_waivers`
--

DROP TABLE IF EXISTS `admin_charge_waivers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `admin_charge_waivers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `charge_id` int(11) NOT NULL,
  `waiver_date` date NOT NULL,
  `amount_waived` decimal(10,2) NOT NULL,
  `reason` text NOT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `charge_id` (`charge_id`),
  KEY `approved_by` (`approved_by`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `admin_charge_waivers_ibfk_1` FOREIGN KEY (`charge_id`) REFERENCES `admin_charges` (`id`) ON DELETE CASCADE,
  CONSTRAINT `admin_charge_waivers_ibfk_2` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`),
  CONSTRAINT `admin_charge_waivers_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admin_charge_waivers`
--

LOCK TABLES `admin_charge_waivers` WRITE;
/*!40000 ALTER TABLE `admin_charge_waivers` DISABLE KEYS */;
/*!40000 ALTER TABLE `admin_charge_waivers` ENABLE KEYS */;
UNLOCK TABLES;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb3 */ ;
/*!50003 SET character_set_results = utf8mb3 */ ;
/*!50003 SET collation_connection  = utf8mb3_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = '' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`pos`@`%`*/ /*!50003 TRIGGER after_admin_charge_waiver
AFTER INSERT ON admin_charge_waivers
FOR EACH ROW
BEGIN
    UPDATE member_charge_summary 
    SET total_waived = total_waived + NEW.amount_waived
    WHERE member_id = (SELECT member_id FROM admin_charges WHERE id = NEW.charge_id);
    
    UPDATE admin_charges 
    SET status = 'waived',
        waived_at = NOW()
    WHERE id = NEW.charge_id;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `admin_charges`
--

DROP TABLE IF EXISTS `admin_charges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `admin_charges` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `charge_type` enum('registration','monthly_fee','annual_fee','statement_fee','sms_charge','ledger_fee','loan_processing','loan_insurance','guarantor_fee','dividend_processing','withdrawal_fee','transfer_fee','other') NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `charge_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `paid_date` date DEFAULT NULL,
  `reference_no` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `loan_id` int(11) DEFAULT NULL,
  `status` enum('pending','paid','waived','overdue') DEFAULT 'pending',
  `waived_by` int(11) DEFAULT NULL,
  `waived_at` datetime DEFAULT NULL,
  `waiver_reason` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `reference_no` (`reference_no`),
  KEY `loan_id` (`loan_id`),
  KEY `created_by` (`created_by`),
  KEY `waived_by` (`waived_by`),
  KEY `idx_member` (`member_id`),
  KEY `idx_status` (`status`),
  KEY `idx_date` (`charge_date`),
  KEY `idx_type` (`charge_type`),
  CONSTRAINT `admin_charges_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`),
  CONSTRAINT `admin_charges_ibfk_2` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`),
  CONSTRAINT `admin_charges_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `admin_charges_ibfk_4` FOREIGN KEY (`waived_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admin_charges`
--

LOCK TABLES `admin_charges` WRITE;
/*!40000 ALTER TABLE `admin_charges` DISABLE KEYS */;
/*!40000 ALTER TABLE `admin_charges` ENABLE KEYS */;
UNLOCK TABLES;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb3 */ ;
/*!50003 SET character_set_results = utf8mb3 */ ;
/*!50003 SET collation_connection  = utf8mb3_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = '' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`pos`@`%`*/ /*!50003 TRIGGER after_admin_charge_insert
AFTER INSERT ON admin_charges
FOR EACH ROW
BEGIN
    INSERT INTO member_charge_summary (member_id, total_charges, total_paid, last_charge_date)
    VALUES (NEW.member_id, NEW.amount, 0, NEW.charge_date)
    ON DUPLICATE KEY UPDATE
        total_charges = total_charges + NEW.amount,
        last_charge_date = GREATEST(last_charge_date, NEW.charge_date);
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `amortization_schedule`
--

DROP TABLE IF EXISTS `amortization_schedule`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `amortization_schedule` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `loan_id` int(11) NOT NULL,
  `installment_no` int(11) NOT NULL,
  `due_date` date NOT NULL,
  `principal` decimal(10,2) NOT NULL,
  `interest` decimal(10,2) NOT NULL,
  `total_payment` decimal(10,2) NOT NULL,
  `balance` decimal(10,2) NOT NULL,
  `status` enum('pending','paid','overdue') DEFAULT 'pending',
  `paid_date` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `loan_id` (`loan_id`),
  CONSTRAINT `amortization_schedule_ibfk_1` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `amortization_schedule`
--

LOCK TABLES `amortization_schedule` WRITE;
/*!40000 ALTER TABLE `amortization_schedule` DISABLE KEYS */;
/*!40000 ALTER TABLE `amortization_schedule` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `assets`
--

DROP TABLE IF EXISTS `assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `assets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `asset_code` varchar(50) NOT NULL,
  `asset_name` varchar(100) NOT NULL,
  `asset_type` enum('fixed','current','intangible','investment') NOT NULL,
  `purchase_date` date NOT NULL,
  `purchase_cost` decimal(10,2) NOT NULL,
  `current_value` decimal(10,2) NOT NULL,
  `depreciation_rate` decimal(5,2) DEFAULT NULL,
  `depreciation_method` enum('straight_line','reducing_balance','none') DEFAULT 'straight_line',
  `useful_life_years` int(11) DEFAULT NULL,
  `salvage_value` decimal(10,2) DEFAULT 0.00,
  `location` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `status` enum('active','disposed','sold') DEFAULT 'active',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `asset_code` (`asset_code`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `assets_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `assets`
--

LOCK TABLES `assets` WRITE;
/*!40000 ALTER TABLE `assets` DISABLE KEYS */;
/*!40000 ALTER TABLE `assets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `audit_logs`
--

DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `table_name` varchar(50) DEFAULT NULL,
  `record_id` int(11) DEFAULT NULL,
  `old_data` text DEFAULT NULL,
  `new_data` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_logs`
--

LOCK TABLES `audit_logs` WRITE;
/*!40000 ALTER TABLE `audit_logs` DISABLE KEYS */;
INSERT INTO `audit_logs` VALUES (1,1,'CALCULATE','dividends',0,'null','{\"year\":\"2026\",\"rate\":\"8.68\"}','::1','Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36','2026-03-12 13:04:45'),(2,1,'INSERT','members',1,'null','{\"full_name\":\"Michael\",\"national_id\":\"323232\",\"phone\":\"2333\",\"email\":\"emwenotikenda@gmail.com\",\"date_joined\":\"2026-03-12\",\"address\":\"\"}','::1','Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36','2026-03-12 14:51:35'),(3,1,'APPROVE','members',1,'{\"status\":\"pending\"}','{\"status\":\"active\"}','::1','Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36','2026-03-12 14:53:29'),(4,1,'INSERT','members',2,'null','{\"full_name\":\"Michael\",\"national_id\":\"232332\",\"phone\":\"1212\",\"email\":\"nguremike@gmail.com\",\"date_joined\":\"2025-01-12\",\"address\":\"\"}','::1','Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36','2026-03-12 15:12:34'),(5,1,'INSERT','members',3,'null','{\"full_name\":\"Teresi\",\"national_id\":\"1212121\",\"phone\":\"232323\",\"email\":\"emwenotikenda@gmail.com\",\"date_joined\":\"2026-03-12\",\"address\":\"\"}','::1','Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36','2026-03-12 15:13:30'),(6,1,'INSERT','shares',1,'null','{\"action\":\"purchase\",\"member_id\":\"1\",\"shares_count\":\"1\",\"share_value\":\"2800\",\"total_value_display\":\"KES 2,800.00\",\"reference_no\":\"\",\"date_purchased\":\"2026-03-12\"}','::1','Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36','2026-03-12 15:20:38'),(7,1,'INSERT','deposits',1,'null','{\"action\":\"add_deposit\",\"transaction_type\":\"deposit\",\"member_id\":\"1\",\"amount\":\"100\",\"payment_method\":\"cash\",\"deposit_date\":\"2026-03-12\",\"reference_no\":\"TXN1773336175\",\"description\":\"\"}','::1','Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36','2026-03-12 17:23:06'),(8,1,'INSERT','loans',1,'null','{\"member_id\":\"1\",\"product_id\":\"1\",\"principal\":\"10000\",\"duration\":\"24\"}','::1','Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36','2026-03-12 18:13:29'),(9,1,'INSERT','loan_guarantors',1,'null','{\"guarantor_id\":\"2\",\"guaranteed_amount\":\"5000\",\"add_guarantor\":\"\"}','::1','Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36','2026-03-12 18:13:50'),(10,1,'INSERT','loan_guarantors',1,'null','{\"guarantor_id\":\"3\",\"guaranteed_amount\":\"5000\",\"add_guarantor\":\"\"}','::1','Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36','2026-03-12 18:14:00'),(11,1,'INSERT','members',4,'null','{\"full_name\":\"James\",\"national_id\":\"2121212\",\"phone\":\"323223\",\"email\":\"emwenotikenda@gmail.com\",\"date_joined\":\"2026-03-13\",\"address\":\"\"}','::1','Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36','2026-03-12 22:11:26'),(12,1,'INFO_REQUEST','members',4,'null','{\"remarks\":\"adasdasd\"}','::1','Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36','2026-03-12 22:14:27'),(13,1,'INSERT','deposits',2,'null','{\"action\":\"add_deposit\",\"transaction_type\":\"deposit\",\"member_id\":\"1\",\"amount\":\"900\",\"payment_method\":\"cash\",\"deposit_date\":\"2026-03-13\",\"reference_no\":\"TXN1773354742\",\"description\":\"\"}','::1','Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36','2026-03-12 22:32:52'),(14,1,'INSERT','loans',2,'null','{\"member_id\":\"3\",\"product_id\":\"1\",\"principal\":\"100000\",\"duration\":\"24\"}','::1','Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36','2026-03-12 23:12:21'),(15,1,'INSERT','loan_guarantors',2,'null','{\"guarantor_id\":\"4\",\"guaranteed_amount\":\"70000\",\"add_guarantor\":\"\"}','::1','Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36','2026-03-12 23:13:04'),(16,1,'INSERT','loan_guarantors',2,'null','{\"guarantor_id\":\"1\",\"guaranteed_amount\":\"20000\",\"add_guarantor\":\"\"}','::1','Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36','2026-03-12 23:13:13'),(17,1,'INSERT','loan_guarantors',2,'null','{\"guarantor_id\":\"2\",\"guaranteed_amount\":\"10000\",\"add_guarantor\":\"\"}','::1','Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36','2026-03-12 23:13:21'),(18,1,'APPROVE','loans',2,'null','{\"id\":2,\"loan_no\":\"LN202650125\",\"member_id\":3,\"product_id\":1,\"principal_amount\":\"100000.00\",\"interest_amount\":\"24000.00\",\"total_amount\":\"124000.00\",\"balance\":\"0.00\",\"duration_months\":24,\"interest_rate\":\"12.00\",\"application_date\":\"2026-03-13\",\"approval_date\":null,\"disbursement_date\":null,\"first_payment_date\":null,\"status\":\"pending\",\"created_by\":1,\"approved_by\":null,\"created_at\":\"2026-03-13 02:12:21\",\"updated_at\":\"2026-03-13 02:37:36\",\"remarks\":null,\"full_name\":\"Teresi\",\"member_no\":\"MEM2026036874\",\"date_joined\":\"2025-01-01\",\"product_name\":\"Normal Loan\"}','::1','Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36','2026-03-12 23:38:04'),(19,1,'DISBURSE','loans',2,'null','{\"disbursement_method\":\"cheque\",\"reference_no\":\"12121\",\"disbursement_date\":\"2026-03-13\"}','::1','Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36','2026-03-12 23:38:29'),(20,1,'UPDATE','members',1,'{\"id\":1,\"member_no\":\"MEM2026038357\",\"full_name\":\"Michael\",\"national_id\":\"323232\",\"phone\":\"2333\",\"email\":\"emwenotikenda@gmail.com\",\"address\":\"\",\"date_joined\":\"2026-03-12\",\"membership_status\":\"active\",\"kyc_documents\":null,\"user_id\":null,\"created_by\":1,\"created_at\":\"2026-03-12 17:51:35\",\"updated_at\":\"2026-03-12 19:34:46\",\"updated_by\":null,\"rejected_reason\":null,\"approval_remarks\":null,\"reviewed_at\":null,\"reviewed_by\":null,\"registration_fee_paid\":\"0.00\",\"bylaws_fee_paid\":\"0.00\",\"registration_date\":null,\"registration_receipt_no\":null,\"total_share_contributions\":\"3000.00\",\"full_shares_issued\":0,\"partial_share_balance\":\"0.00\",\"approved_at\":null,\"rejected_by\":null,\"rejected_at\":null,\"approved_by\":null,\"user_account\":null,\"deposit_count\":2,\"loan_count\":1,\"share_count\":0}','{\"date_joined\":\"2025-05-01\",\"full_name\":\"Michael\",\"national_id\":\"323232\",\"phone\":\"2333\",\"email\":\"emwenotikenda@gmail.com\",\"address\":\"\",\"membership_status\":\"active\"}','::1','Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36','2026-03-13 00:14:31'),(21,1,'APPROVE','loans',1,'null','{\"id\":1,\"loan_no\":\"LN202617803\",\"member_id\":1,\"product_id\":1,\"principal_amount\":\"10000.00\",\"interest_amount\":\"2400.00\",\"total_amount\":\"12400.00\",\"balance\":\"0.00\",\"duration_months\":24,\"interest_rate\":\"12.00\",\"application_date\":\"2026-03-12\",\"approval_date\":null,\"disbursement_date\":null,\"first_payment_date\":null,\"status\":\"guarantor_pending\",\"created_by\":1,\"approved_by\":null,\"created_at\":\"2026-03-12 21:13:29\",\"updated_at\":null,\"remarks\":null,\"full_name\":\"Michael\",\"member_no\":\"MEM2026038357\",\"national_id\":\"323232\",\"phone\":\"2333\",\"email\":\"emwenotikenda@gmail.com\",\"date_joined\":\"2025-05-01\",\"product_name\":\"Normal Loan\",\"product_rate\":\"12.00\",\"member_deposits\":\"1000.00\",\"member_shares\":\"0.00\",\"membership_months\":10}','::1','Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36','2026-03-13 00:15:09'),(22,1,'DISBURSE','loans',1,'null','{\"disbursement_method\":\"cheque\",\"reference_no\":\"123123\",\"disbursement_date\":\"2026-03-13\"}','::1','Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36','2026-03-13 00:15:22'),(23,1,'PAYMENT','loan_repayments',1,'null','{\"amount_paid\":\"500\",\"payment_date\":\"2026-03-13\",\"payment_method\":\"mpesa\",\"reference_no\":\"12121\"}','::1','Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36','2026-03-13 00:41:39'),(24,1,'CALCULATE','dividends',0,'null','{\"year\":\"2026\",\"rate\":\"9\"}','::1','Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36','2026-03-13 04:12:18');
/*!40000 ALTER TABLE `audit_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bank_reconciliation`
--

DROP TABLE IF EXISTS `bank_reconciliation`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `bank_reconciliation` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `reconciliation_date` date NOT NULL,
  `bank_account` varchar(50) NOT NULL,
  `bank_balance` decimal(10,2) NOT NULL,
  `book_balance` decimal(10,2) NOT NULL,
  `difference` decimal(10,2) GENERATED ALWAYS AS (`bank_balance` - `book_balance`) STORED,
  `reconciled` tinyint(1) DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `reconciled_by` int(11) DEFAULT NULL,
  `reconciled_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  KEY `reconciled_by` (`reconciled_by`),
  CONSTRAINT `bank_reconciliation_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `bank_reconciliation_ibfk_2` FOREIGN KEY (`reconciled_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bank_reconciliation`
--

LOCK TABLES `bank_reconciliation` WRITE;
/*!40000 ALTER TABLE `bank_reconciliation` DISABLE KEYS */;
/*!40000 ALTER TABLE `bank_reconciliation` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `budgets`
--

DROP TABLE IF EXISTS `budgets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `budgets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `budget_year` year(4) NOT NULL,
  `budget_type` enum('annual','quarterly','monthly') DEFAULT 'annual',
  `account_code` varchar(20) NOT NULL,
  `allocated_amount` decimal(10,2) NOT NULL,
  `actual_amount` decimal(10,2) DEFAULT 0.00,
  `variance` decimal(10,2) GENERATED ALWAYS AS (`actual_amount` - `allocated_amount`) STORED,
  `variance_percentage` decimal(5,2) GENERATED ALWAYS AS (case when `allocated_amount` > 0 then (`actual_amount` - `allocated_amount`) / `allocated_amount` * 100 else 0 end) STORED,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_budget` (`budget_year`,`account_code`,`budget_type`),
  KEY `account_code` (`account_code`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `budgets_ibfk_1` FOREIGN KEY (`account_code`) REFERENCES `chart_of_accounts` (`account_code`),
  CONSTRAINT `budgets_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `budgets`
--

LOCK TABLES `budgets` WRITE;
/*!40000 ALTER TABLE `budgets` DISABLE KEYS */;
/*!40000 ALTER TABLE `budgets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `chart_of_accounts`
--

DROP TABLE IF EXISTS `chart_of_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `chart_of_accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_code` varchar(20) NOT NULL,
  `account_name` varchar(100) NOT NULL,
  `account_type` enum('asset','liability','equity','income','expense') NOT NULL,
  `category` varchar(50) DEFAULT NULL,
  `sub_category` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `normal_balance` enum('debit','credit') NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `parent_account_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `account_code` (`account_code`),
  KEY `parent_account_id` (`parent_account_id`),
  CONSTRAINT `chart_of_accounts_ibfk_1` FOREIGN KEY (`parent_account_id`) REFERENCES `chart_of_accounts` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=43 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `chart_of_accounts`
--

LOCK TABLES `chart_of_accounts` WRITE;
/*!40000 ALTER TABLE `chart_of_accounts` DISABLE KEYS */;
INSERT INTO `chart_of_accounts` VALUES (1,'1000','Cash on Hand','asset','Current Assets',NULL,NULL,'debit',1,NULL,'2026-03-13 00:55:30',NULL),(2,'1010','Cash at Bank - Main Account','asset','Current Assets',NULL,NULL,'debit',1,NULL,'2026-03-13 00:55:30',NULL),(3,'1020','Cash at Bank - Savings','asset','Current Assets',NULL,NULL,'debit',1,NULL,'2026-03-13 00:55:30',NULL),(4,'1030','M-Pesa Account','asset','Current Assets',NULL,NULL,'debit',1,NULL,'2026-03-13 00:55:30',NULL),(5,'1100','Accounts Receivable','asset','Current Assets',NULL,NULL,'debit',1,NULL,'2026-03-13 00:55:30',NULL),(6,'1110','Loans Receivable','asset','Current Assets',NULL,NULL,'debit',1,NULL,'2026-03-13 00:55:30',NULL),(7,'1120','Interest Receivable','asset','Current Assets',NULL,NULL,'debit',1,NULL,'2026-03-13 00:55:30',NULL),(8,'1200','Shares in Cooperatives','asset','Investments',NULL,NULL,'debit',1,NULL,'2026-03-13 00:55:30',NULL),(9,'1300','Fixed Assets','asset','Fixed Assets',NULL,NULL,'debit',1,NULL,'2026-03-13 00:55:30',NULL),(10,'1310','Accumulated Depreciation','asset','Fixed Assets',NULL,NULL,'credit',1,NULL,'2026-03-13 00:55:30',NULL),(11,'1400','Prepaid Expenses','asset','Current Assets',NULL,NULL,'debit',1,NULL,'2026-03-13 00:55:30',NULL),(12,'2000','Accounts Payable','liability','Current Liabilities',NULL,NULL,'credit',1,NULL,'2026-03-13 00:55:30',NULL),(13,'2010','Member Deposits','liability','Current Liabilities',NULL,NULL,'credit',1,NULL,'2026-03-13 00:55:30',NULL),(14,'2020','Savings Accounts','liability','Current Liabilities',NULL,NULL,'credit',1,NULL,'2026-03-13 00:55:30',NULL),(15,'2100','Loans Payable','liability','Long-term Liabilities',NULL,NULL,'credit',1,NULL,'2026-03-13 00:55:30',NULL),(16,'2110','Interest Payable','liability','Current Liabilities',NULL,NULL,'credit',1,NULL,'2026-03-13 00:55:30',NULL),(17,'2200','Accrued Expenses','liability','Current Liabilities',NULL,NULL,'credit',1,NULL,'2026-03-13 00:55:30',NULL),(18,'2300','Tax Payable','liability','Current Liabilities',NULL,NULL,'credit',1,NULL,'2026-03-13 00:55:30',NULL),(19,'3000','Share Capital','equity','Capital',NULL,NULL,'credit',1,NULL,'2026-03-13 00:55:30',NULL),(20,'3010','Retained Earnings','equity','Reserves',NULL,NULL,'credit',1,NULL,'2026-03-13 00:55:30',NULL),(21,'3020','Statutory Reserve','equity','Reserves',NULL,NULL,'credit',1,NULL,'2026-03-13 00:55:30',NULL),(22,'3100','Current Year Earnings','equity','Retained Earnings',NULL,NULL,'credit',1,NULL,'2026-03-13 00:55:30',NULL),(23,'4000','Interest Income','income','Operating Income',NULL,NULL,'credit',1,NULL,'2026-03-13 00:55:30',NULL),(24,'4010','Loan Interest','income','Operating Income',NULL,NULL,'credit',1,NULL,'2026-03-13 00:55:30',NULL),(25,'4020','Penalty Fees','income','Operating Income',NULL,NULL,'credit',1,NULL,'2026-03-13 00:55:30',NULL),(26,'4030','Administrative Fees','income','Operating Income',NULL,NULL,'credit',1,NULL,'2026-03-13 00:55:30',NULL),(27,'4100','Dividend Income','income','Investment Income',NULL,NULL,'credit',1,NULL,'2026-03-13 00:55:30',NULL),(28,'4200','Other Income','income','Other Income',NULL,NULL,'credit',1,NULL,'2026-03-13 00:55:30',NULL),(29,'5000','Salaries and Wages','expense','Operating Expenses',NULL,NULL,'debit',1,NULL,'2026-03-13 00:55:30',NULL),(30,'5010','Rent Expense','expense','Operating Expenses',NULL,NULL,'debit',1,NULL,'2026-03-13 00:55:30',NULL),(31,'5020','Utilities Expense','expense','Operating Expenses',NULL,NULL,'debit',1,NULL,'2026-03-13 00:55:30',NULL),(32,'5030','Office Supplies','expense','Operating Expenses',NULL,NULL,'debit',1,NULL,'2026-03-13 00:55:30',NULL),(33,'5040','Telephone & Internet','expense','Operating Expenses',NULL,NULL,'debit',1,NULL,'2026-03-13 00:55:30',NULL),(34,'5050','Travel Expense','expense','Operating Expenses',NULL,NULL,'debit',1,NULL,'2026-03-13 00:55:30',NULL),(35,'5060','Training Expense','expense','Operating Expenses',NULL,NULL,'debit',1,NULL,'2026-03-13 00:55:30',NULL),(36,'5070','Marketing Expense','expense','Operating Expenses',NULL,NULL,'debit',1,NULL,'2026-03-13 00:55:30',NULL),(37,'5080','Legal & Professional','expense','Operating Expenses',NULL,NULL,'debit',1,NULL,'2026-03-13 00:55:30',NULL),(38,'5090','Bank Charges','expense','Operating Expenses',NULL,NULL,'debit',1,NULL,'2026-03-13 00:55:30',NULL),(39,'5100','Depreciation Expense','expense','Operating Expenses',NULL,NULL,'debit',1,NULL,'2026-03-13 00:55:30',NULL),(40,'5110','Maintenance Expense','expense','Operating Expenses',NULL,NULL,'debit',1,NULL,'2026-03-13 00:55:30',NULL),(41,'5120','Insurance Expense','expense','Operating Expenses',NULL,NULL,'debit',1,NULL,'2026-03-13 00:55:30',NULL),(42,'5200','Other Expenses','expense','Other Expenses',NULL,NULL,'debit',1,NULL,'2026-03-13 00:55:30',NULL);
/*!40000 ALTER TABLE `chart_of_accounts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `deposits`
--

DROP TABLE IF EXISTS `deposits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `deposits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `deposit_date` date NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `balance` decimal(10,2) NOT NULL,
  `transaction_type` enum('deposit','withdrawal','interest') NOT NULL,
  `reference_no` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_opening_balance` tinyint(1) DEFAULT 0,
  `opening_balance_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  KEY `created_by` (`created_by`),
  KEY `opening_balance_id` (`opening_balance_id`),
  CONSTRAINT `deposits_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`),
  CONSTRAINT `deposits_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `deposits_ibfk_3` FOREIGN KEY (`opening_balance_id`) REFERENCES `opening_balances` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=77 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `deposits`
--

LOCK TABLES `deposits` WRITE;
/*!40000 ALTER TABLE `deposits` DISABLE KEYS */;
INSERT INTO `deposits` VALUES (2,1,'2025-01-01',76460.00,76460.00,'deposit','DEP1773395344324','Initial deposit - Import',1,'2026-03-13 09:49:04',0,NULL),(3,2,'2025-01-01',159459.00,159459.00,'deposit','DEP1773395345762','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(4,3,'2025-01-01',168406.00,168406.00,'deposit','DEP1773395345438','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(5,4,'2025-01-01',48146.00,48146.00,'deposit','DEP1773395345871','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(6,5,'2025-01-01',67040.00,67040.00,'deposit','DEP1773395345478','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(7,6,'2025-01-01',237197.00,237197.00,'deposit','DEP1773395345837','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(8,8,'2025-01-01',64566.00,64566.00,'deposit','DEP1773395345726','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(9,9,'2025-01-01',123457.00,123457.00,'deposit','DEP1773395345222','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(10,10,'2025-01-01',5863.00,5863.00,'deposit','DEP1773395345378','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(11,11,'2025-01-01',26198.00,26198.00,'deposit','DEP1773395345504','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(12,12,'2025-01-01',1193.00,1193.00,'deposit','DEP1773395345743','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(13,14,'2025-01-01',24879.00,24879.00,'deposit','DEP1773395345132','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(14,15,'2025-01-01',4188.00,4188.00,'deposit','DEP1773395345930','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(15,16,'2025-01-01',90272.00,90272.00,'deposit','DEP1773395345270','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(16,17,'2025-01-01',90222.00,90222.00,'deposit','DEP1773395345515','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(17,18,'2025-01-01',115502.00,115502.00,'deposit','DEP1773395345301','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(18,20,'2025-01-01',126497.00,126497.00,'deposit','DEP1773395345154','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(19,21,'2025-01-01',90963.00,90963.00,'deposit','DEP1773395345283','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(20,23,'2025-01-01',71508.00,71508.00,'deposit','DEP1773395345802','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(21,24,'2025-01-01',83215.00,83215.00,'deposit','DEP1773395345157','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(22,25,'2025-01-01',276445.00,276445.00,'deposit','DEP1773395345629','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(23,26,'2025-01-01',1334.00,1334.00,'deposit','DEP1773395345325','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(24,27,'2025-01-01',24401.00,24401.00,'deposit','DEP1773395345503','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(25,28,'2025-01-01',8412.00,8412.00,'deposit','DEP1773395345281','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(26,29,'2025-01-01',73042.00,73042.00,'deposit','DEP1773395345838','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(27,30,'2025-01-01',76130.00,76130.00,'deposit','DEP1773395345216','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(28,31,'2025-01-01',73399.00,73399.00,'deposit','DEP1773395345662','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(29,32,'2025-01-01',197.00,197.00,'deposit','DEP1773395345524','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(30,33,'2025-01-01',40600.00,40600.00,'deposit','DEP1773395345585','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(31,35,'2025-01-01',334105.00,334105.00,'deposit','DEP1773395345785','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(32,37,'2025-01-01',96416.00,96416.00,'deposit','DEP1773395345930','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(33,38,'2025-01-01',65792.00,65792.00,'deposit','DEP1773395345792','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(34,39,'2025-01-01',129652.00,129652.00,'deposit','DEP1773395345122','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(35,40,'2025-01-01',105879.00,105879.00,'deposit','DEP1773395345622','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(36,41,'2025-01-01',43888.00,43888.00,'deposit','DEP1773395345966','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(37,42,'2025-01-01',87415.00,87415.00,'deposit','DEP1773395345353','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(38,43,'2025-01-01',194997.00,194997.00,'deposit','DEP1773395345844','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(39,44,'2025-01-01',215400.00,215400.00,'deposit','DEP1773395345274','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(40,45,'2025-01-01',369058.00,369058.00,'deposit','DEP1773395345558','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(41,46,'2025-01-01',89194.00,89194.00,'deposit','DEP1773395345465','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(42,47,'2025-01-01',55889.00,55889.00,'deposit','DEP1773395345133','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(43,48,'2025-01-01',21861.00,21861.00,'deposit','DEP1773395345716','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(44,50,'2025-01-01',47041.00,47041.00,'deposit','DEP1773395345839','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(45,51,'2025-01-01',166966.00,166966.00,'deposit','DEP1773395345245','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(46,52,'2025-01-01',78474.00,78474.00,'deposit','DEP1773395345260','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(47,53,'2025-01-01',72843.00,72843.00,'deposit','DEP1773395345681','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(48,54,'2025-01-01',33124.00,33124.00,'deposit','DEP1773395345523','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(49,55,'2025-01-01',72154.00,72154.00,'deposit','DEP1773395345803','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(50,56,'2025-01-01',69125.00,69125.00,'deposit','DEP1773395345647','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(51,57,'2025-01-01',55376.00,55376.00,'deposit','DEP1773395345205','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(52,58,'2025-01-01',69576.00,69576.00,'deposit','DEP1773395345242','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(53,59,'2025-01-01',76576.00,76576.00,'deposit','DEP1773395345361','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(54,60,'2025-01-01',19943.00,19943.00,'deposit','DEP1773395345311','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(55,61,'2025-01-01',58504.00,58504.00,'deposit','DEP1773395345169','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(56,62,'2025-01-01',116067.00,116067.00,'deposit','DEP1773395345317','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(57,63,'2025-01-01',23108.00,23108.00,'deposit','DEP1773395345642','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(58,64,'2025-01-01',26264.00,26264.00,'deposit','DEP1773395345487','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(59,65,'2025-01-01',199470.00,199470.00,'deposit','DEP1773395345684','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(60,66,'2025-01-01',45575.00,45575.00,'deposit','DEP1773395345204','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(61,67,'2025-01-01',99927.00,99927.00,'deposit','DEP1773395345998','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(62,68,'2025-01-01',29598.00,29598.00,'deposit','DEP1773395345763','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(63,69,'2025-01-01',58106.00,58106.00,'deposit','DEP1773395345422','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(64,70,'2025-01-01',7040.00,7040.00,'deposit','DEP1773395345829','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(65,71,'2025-01-01',10419.00,10419.00,'deposit','DEP1773395345181','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(66,72,'2025-01-01',13129.00,13129.00,'deposit','DEP1773395345280','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(67,73,'2025-01-01',4800.00,4800.00,'deposit','DEP1773395345521','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(68,74,'2025-01-01',123000.00,123000.00,'deposit','DEP1773395345188','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(69,76,'2025-01-01',26400.00,26400.00,'deposit','DEP1773395345931','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(70,77,'2025-01-01',2000.00,2000.00,'deposit','DEP1773395345137','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(71,78,'2025-01-01',1000.00,1000.00,'deposit','DEP1773395345162','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(72,79,'2025-01-01',9000.00,9000.00,'deposit','DEP1773395345778','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(73,80,'2025-01-01',1006000.00,1006000.00,'deposit','DEP1773395345837','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(74,81,'2025-01-01',23000.00,23000.00,'deposit','DEP1773395345397','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(75,82,'2025-01-01',17000.00,17000.00,'deposit','DEP1773395345459','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL),(76,83,'2025-01-01',4000.00,4000.00,'deposit','DEP1773395345655','Initial deposit - Import',1,'2026-03-13 09:49:05',0,NULL);
/*!40000 ALTER TABLE `deposits` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `depreciation_schedule`
--

DROP TABLE IF EXISTS `depreciation_schedule`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `depreciation_schedule` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `asset_id` int(11) NOT NULL,
  `period_date` date NOT NULL,
  `depreciation_amount` decimal(10,2) NOT NULL,
  `accumulated_depreciation` decimal(10,2) NOT NULL,
  `book_value` decimal(10,2) NOT NULL,
  `journal_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `asset_id` (`asset_id`),
  KEY `journal_id` (`journal_id`),
  CONSTRAINT `depreciation_schedule_ibfk_1` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`),
  CONSTRAINT `depreciation_schedule_ibfk_2` FOREIGN KEY (`journal_id`) REFERENCES `journal_entries` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `depreciation_schedule`
--

LOCK TABLES `depreciation_schedule` WRITE;
/*!40000 ALTER TABLE `depreciation_schedule` DISABLE KEYS */;
/*!40000 ALTER TABLE `depreciation_schedule` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `dividend_contributions`
--

DROP TABLE IF EXISTS `dividend_contributions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `dividend_contributions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `dividend_id` int(11) NOT NULL,
  `contribution_month` date NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `weight` decimal(5,2) NOT NULL COMMENT 'Months remaining / 12',
  `weighted_amount` decimal(10,2) NOT NULL,
  `interest_earned` decimal(10,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `dividend_id` (`dividend_id`),
  CONSTRAINT `dividend_contributions_ibfk_1` FOREIGN KEY (`dividend_id`) REFERENCES `dividends` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `dividend_contributions`
--

LOCK TABLES `dividend_contributions` WRITE;
/*!40000 ALTER TABLE `dividend_contributions` DISABLE KEYS */;
/*!40000 ALTER TABLE `dividend_contributions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `dividend_payments`
--

DROP TABLE IF EXISTS `dividend_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `dividend_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `dividend_id` int(11) NOT NULL,
  `payment_date` date NOT NULL,
  `amount_paid` decimal(10,2) NOT NULL,
  `payment_method` enum('cash','bank','mpesa','cheque') NOT NULL,
  `reference_no` varchar(50) DEFAULT NULL,
  `bank_account` varchar(50) DEFAULT NULL,
  `cheque_number` varchar(50) DEFAULT NULL,
  `mpesa_code` varchar(50) DEFAULT NULL,
  `paid_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `dividend_id` (`dividend_id`),
  KEY `paid_by` (`paid_by`),
  CONSTRAINT `dividend_payments_ibfk_1` FOREIGN KEY (`dividend_id`) REFERENCES `dividends` (`id`),
  CONSTRAINT `dividend_payments_ibfk_2` FOREIGN KEY (`paid_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `dividend_payments`
--

LOCK TABLES `dividend_payments` WRITE;
/*!40000 ALTER TABLE `dividend_payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `dividend_payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `dividends`
--

DROP TABLE IF EXISTS `dividends`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `dividends` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `financial_year` year(4) NOT NULL,
  `opening_balance` decimal(10,2) NOT NULL,
  `adjusted_opening` decimal(10,2) DEFAULT 0.00,
  `total_withdrawals` decimal(10,2) DEFAULT 0.00,
  `total_penalties` decimal(10,2) DEFAULT 0.00,
  `total_charges` decimal(10,2) DEFAULT 0.00,
  `total_deposits` decimal(10,2) NOT NULL,
  `interest_rate` decimal(5,2) NOT NULL,
  `gross_dividend` decimal(10,2) NOT NULL,
  `withholding_tax` decimal(10,2) NOT NULL,
  `net_dividend` decimal(10,2) NOT NULL,
  `status` enum('calculated','approved','paid') DEFAULT 'calculated',
  `payment_date` date DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `payment_method` varchar(20) DEFAULT NULL,
  `payment_reference` varchar(50) DEFAULT NULL,
  `paid_by` int(11) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `eligibility_months` int(11) DEFAULT 12 COMMENT 'Number of months member was active in the year',
  `calculation_method` varchar(50) DEFAULT 'pro-rata',
  `calculated_at` timestamp NULL DEFAULT NULL,
  `calculated_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  KEY `created_by` (`created_by`),
  KEY `paid_by` (`paid_by`),
  KEY `approved_by` (`approved_by`),
  KEY `calculated_by` (`calculated_by`),
  CONSTRAINT `dividends_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`),
  CONSTRAINT `dividends_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `dividends_ibfk_3` FOREIGN KEY (`paid_by`) REFERENCES `users` (`id`),
  CONSTRAINT `dividends_ibfk_4` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`),
  CONSTRAINT `dividends_ibfk_5` FOREIGN KEY (`calculated_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `dividends`
--

LOCK TABLES `dividends` WRITE;
/*!40000 ALTER TABLE `dividends` DISABLE KEYS */;
/*!40000 ALTER TABLE `dividends` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `expense_categories`
--

DROP TABLE IF EXISTS `expense_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `expense_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_name` varchar(100) NOT NULL,
  `category_code` varchar(20) NOT NULL,
  `account_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `status` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `category_code` (`category_code`),
  KEY `account_id` (`account_id`),
  CONSTRAINT `expense_categories_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `chart_of_accounts` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `expense_categories`
--

LOCK TABLES `expense_categories` WRITE;
/*!40000 ALTER TABLE `expense_categories` DISABLE KEYS */;
INSERT INTO `expense_categories` VALUES (1,'Salaries','SAL',29,NULL,1,'2026-03-13 01:03:27'),(2,'Rent','RNT',30,NULL,1,'2026-03-13 01:03:27'),(3,'Utilities','UTL',31,NULL,1,'2026-03-13 01:03:27'),(4,'Office Supplies','OFF',32,NULL,1,'2026-03-13 01:03:27'),(5,'Communication','COM',33,NULL,1,'2026-03-13 01:03:27'),(6,'Travel','TRV',34,NULL,1,'2026-03-13 01:03:27'),(7,'Training','TRN',35,NULL,1,'2026-03-13 01:03:27'),(8,'Marketing','MKT',36,NULL,1,'2026-03-13 01:03:27'),(9,'Professional Fees','PRO',37,NULL,1,'2026-03-13 01:03:27'),(10,'Bank Charges','BNK',38,NULL,1,'2026-03-13 01:03:27'),(11,'Depreciation','DEP',39,NULL,1,'2026-03-13 01:03:27'),(12,'Maintenance','MNT',40,NULL,1,'2026-03-13 01:03:27'),(13,'Insurance','INS',41,NULL,1,'2026-03-13 01:03:27');
/*!40000 ALTER TABLE `expense_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `expenses`
--

DROP TABLE IF EXISTS `expenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `expenses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `expense_date` date NOT NULL,
  `expense_type` enum('operational','administrative','salary','rent','utilities','maintenance','travel','training','marketing','legal','consultancy','other') NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` enum('cash','bank','mpesa','cheque','credit_card') NOT NULL,
  `reference_no` varchar(50) DEFAULT NULL,
  `description` text NOT NULL,
  `paid_to` varchar(100) NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `receipt_path` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `category_id` (`category_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `expenses_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `expense_categories` (`id`),
  CONSTRAINT `expenses_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `expenses`
--

LOCK TABLES `expenses` WRITE;
/*!40000 ALTER TABLE `expenses` DISABLE KEYS */;
/*!40000 ALTER TABLE `expenses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `financial_ratios`
--

DROP TABLE IF EXISTS `financial_ratios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `financial_ratios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ratio_date` date NOT NULL,
  `ratio_type` enum('liquidity','solvency','profitability','efficiency') NOT NULL,
  `ratio_name` varchar(100) NOT NULL,
  `value` decimal(10,4) NOT NULL,
  `benchmark` decimal(10,4) DEFAULT NULL,
  `interpretation` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `financial_ratios`
--

LOCK TABLES `financial_ratios` WRITE;
/*!40000 ALTER TABLE `financial_ratios` DISABLE KEYS */;
/*!40000 ALTER TABLE `financial_ratios` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `income`
--

DROP TABLE IF EXISTS `income`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `income` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `income_date` date NOT NULL,
  `income_type` enum('interest','fees','penalties','dividend_income','other') NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `reference_no` varchar(50) DEFAULT NULL,
  `description` text NOT NULL,
  `received_from` varchar(100) DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `payment_method` enum('cash','bank','mpesa','cheque') NOT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `category_id` (`category_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `income_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `expense_categories` (`id`),
  CONSTRAINT `income_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `income`
--

LOCK TABLES `income` WRITE;
/*!40000 ALTER TABLE `income` DISABLE KEYS */;
/*!40000 ALTER TABLE `income` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `journal_details`
--

DROP TABLE IF EXISTS `journal_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `journal_details` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `journal_id` int(11) NOT NULL,
  `account_code` varchar(20) NOT NULL,
  `account_name` varchar(100) DEFAULT NULL,
  `debit_amount` decimal(10,2) DEFAULT 0.00,
  `credit_amount` decimal(10,2) DEFAULT 0.00,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `journal_id` (`journal_id`),
  KEY `account_code` (`account_code`),
  CONSTRAINT `journal_details_ibfk_1` FOREIGN KEY (`journal_id`) REFERENCES `journal_entries` (`id`) ON DELETE CASCADE,
  CONSTRAINT `journal_details_ibfk_2` FOREIGN KEY (`account_code`) REFERENCES `chart_of_accounts` (`account_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `journal_details`
--

LOCK TABLES `journal_details` WRITE;
/*!40000 ALTER TABLE `journal_details` DISABLE KEYS */;
/*!40000 ALTER TABLE `journal_details` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `journal_entries`
--

DROP TABLE IF EXISTS `journal_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `journal_entries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `entry_date` date NOT NULL,
  `journal_no` varchar(50) NOT NULL,
  `reference_type` enum('deposit','withdrawal','loan','repayment','dividend','share','expense','income','transfer','adjustment') NOT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `description` text NOT NULL,
  `total_debit` decimal(10,2) DEFAULT 0.00,
  `total_credit` decimal(10,2) DEFAULT 0.00,
  `status` enum('draft','posted','void') DEFAULT 'draft',
  `created_by` int(11) DEFAULT NULL,
  `posted_by` int(11) DEFAULT NULL,
  `posted_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `journal_no` (`journal_no`),
  KEY `created_by` (`created_by`),
  KEY `posted_by` (`posted_by`),
  CONSTRAINT `journal_entries_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `journal_entries_ibfk_2` FOREIGN KEY (`posted_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `journal_entries`
--

LOCK TABLES `journal_entries` WRITE;
/*!40000 ALTER TABLE `journal_entries` DISABLE KEYS */;
/*!40000 ALTER TABLE `journal_entries` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `loan_guarantors`
--

DROP TABLE IF EXISTS `loan_guarantors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_guarantors` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `loan_id` int(11) NOT NULL,
  `guarantor_member_id` int(11) NOT NULL,
  `guaranteed_amount` decimal(10,2) NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `approval_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `loan_id` (`loan_id`),
  KEY `guarantor_member_id` (`guarantor_member_id`),
  KEY `approved_by` (`approved_by`),
  CONSTRAINT `loan_guarantors_ibfk_1` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`),
  CONSTRAINT `loan_guarantors_ibfk_2` FOREIGN KEY (`guarantor_member_id`) REFERENCES `members` (`id`),
  CONSTRAINT `loan_guarantors_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `loan_guarantors`
--

LOCK TABLES `loan_guarantors` WRITE;
/*!40000 ALTER TABLE `loan_guarantors` DISABLE KEYS */;
/*!40000 ALTER TABLE `loan_guarantors` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `loan_products`
--

DROP TABLE IF EXISTS `loan_products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_name` varchar(50) NOT NULL,
  `interest_rate` decimal(5,2) NOT NULL,
  `max_duration_months` int(11) NOT NULL,
  `min_amount` decimal(10,2) DEFAULT NULL,
  `max_amount` decimal(10,2) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `status` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `loan_products`
--

LOCK TABLES `loan_products` WRITE;
/*!40000 ALTER TABLE `loan_products` DISABLE KEYS */;
INSERT INTO `loan_products` VALUES (1,'Normal Loan',12.00,24,10000.00,500000.00,'Standard loan product for general purposes',1),(2,'Emergency Loan',10.00,12,5000.00,100000.00,'Quick loan for emergencies',1),(3,'Development Loan',14.00,36,50000.00,1000000.00,'Long-term loan for development projects',1);
/*!40000 ALTER TABLE `loan_products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `loan_repayments`
--

DROP TABLE IF EXISTS `loan_repayments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_repayments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `loan_id` int(11) NOT NULL,
  `payment_date` date NOT NULL,
  `amount_paid` decimal(10,2) NOT NULL,
  `principal_paid` decimal(10,2) NOT NULL,
  `interest_paid` decimal(10,2) NOT NULL,
  `penalty_paid` decimal(10,2) DEFAULT 0.00,
  `balance` decimal(10,2) NOT NULL,
  `payment_method` enum('cash','bank','mpesa','mobile') NOT NULL,
  `reference_no` varchar(50) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `loan_id` (`loan_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `loan_repayments_ibfk_1` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`),
  CONSTRAINT `loan_repayments_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `loan_repayments`
--

LOCK TABLES `loan_repayments` WRITE;
/*!40000 ALTER TABLE `loan_repayments` DISABLE KEYS */;
/*!40000 ALTER TABLE `loan_repayments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `loans`
--

DROP TABLE IF EXISTS `loans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `loans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `loan_no` varchar(20) NOT NULL,
  `member_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `principal_amount` decimal(10,2) NOT NULL,
  `interest_amount` decimal(10,2) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `balance` decimal(10,2) DEFAULT 0.00,
  `duration_months` int(11) NOT NULL,
  `interest_rate` decimal(5,2) NOT NULL,
  `application_date` date NOT NULL,
  `approval_date` date DEFAULT NULL,
  `disbursement_date` date DEFAULT NULL,
  `first_payment_date` date DEFAULT NULL,
  `status` enum('pending','guarantor_pending','approved','disbursed','active','completed','defaulted','rejected') DEFAULT 'pending',
  `created_by` int(11) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `remarks` text DEFAULT NULL,
  `is_opening_balance` tinyint(1) DEFAULT 0,
  `opening_balance_id` int(11) DEFAULT NULL,
  `total_penalties` decimal(10,2) DEFAULT 0.00,
  `penalties_paid` decimal(10,2) DEFAULT 0.00,
  `penalties_outstanding` decimal(10,2) GENERATED ALWAYS AS (`total_penalties` - `penalties_paid`) STORED,
  `processing_fee` decimal(10,2) DEFAULT 0.00,
  `insurance_fee` decimal(10,2) DEFAULT 0.00,
  `guarantor_fees` decimal(10,2) DEFAULT 0.00,
  `total_loan_charges` decimal(10,2) GENERATED ALWAYS AS (`processing_fee` + `insurance_fee` + `guarantor_fees`) STORED,
  `loan_charges_paid` decimal(10,2) DEFAULT 0.00,
  PRIMARY KEY (`id`),
  UNIQUE KEY `loan_no` (`loan_no`),
  KEY `member_id` (`member_id`),
  KEY `product_id` (`product_id`),
  KEY `created_by` (`created_by`),
  KEY `approved_by` (`approved_by`),
  KEY `opening_balance_id` (`opening_balance_id`),
  CONSTRAINT `loans_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`),
  CONSTRAINT `loans_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `loan_products` (`id`),
  CONSTRAINT `loans_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `loans_ibfk_4` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`),
  CONSTRAINT `loans_ibfk_5` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`),
  CONSTRAINT `loans_ibfk_6` FOREIGN KEY (`opening_balance_id`) REFERENCES `opening_balances` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `loans`
--

LOCK TABLES `loans` WRITE;
/*!40000 ALTER TABLE `loans` DISABLE KEYS */;
/*!40000 ALTER TABLE `loans` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `member_charge_summary`
--

DROP TABLE IF EXISTS `member_charge_summary`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `member_charge_summary` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `total_charges` decimal(10,2) DEFAULT 0.00,
  `total_paid` decimal(10,2) DEFAULT 0.00,
  `total_waived` decimal(10,2) DEFAULT 0.00,
  `outstanding_balance` decimal(10,2) GENERATED ALWAYS AS (`total_charges` - `total_paid` - `total_waived`) STORED,
  `last_charge_date` date DEFAULT NULL,
  `last_payment_date` date DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `member_id` (`member_id`),
  KEY `idx_outstanding` (`outstanding_balance`),
  CONSTRAINT `member_charge_summary_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `member_charge_summary`
--

LOCK TABLES `member_charge_summary` WRITE;
/*!40000 ALTER TABLE `member_charge_summary` DISABLE KEYS */;
/*!40000 ALTER TABLE `member_charge_summary` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `members`
--

DROP TABLE IF EXISTS `members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `members` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_no` varchar(20) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `national_id` varchar(20) NOT NULL,
  `phone` varchar(15) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `date_joined` date NOT NULL,
  `membership_status` enum('pending','active','suspended','closed') DEFAULT 'pending',
  `kyc_documents` text DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  `rejected_reason` text DEFAULT NULL,
  `approval_remarks` text DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `registration_fee_paid` decimal(10,2) DEFAULT 0.00,
  `bylaws_fee_paid` decimal(10,2) DEFAULT 0.00,
  `registration_date` date DEFAULT NULL,
  `registration_receipt_no` varchar(50) DEFAULT NULL,
  `total_share_contributions` decimal(10,2) DEFAULT 0.00,
  `full_shares_issued` int(11) DEFAULT 0,
  `partial_share_balance` decimal(10,2) DEFAULT 0.00,
  `approved_at` datetime DEFAULT NULL,
  `rejected_by` int(11) DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `opening_balance_initialized` tinyint(1) DEFAULT 0,
  `opening_balance_date` date DEFAULT NULL,
  `imported_contributions` decimal(10,2) DEFAULT 0.00,
  `imported_shares_issued` int(11) DEFAULT 0,
  `total_penalties_paid` decimal(10,2) DEFAULT 0.00,
  `total_penalties_waived` decimal(10,2) DEFAULT 0.00,
  `last_penalty_date` date DEFAULT NULL,
  `total_admin_charges` decimal(10,2) DEFAULT 0.00,
  `admin_charges_paid` decimal(10,2) DEFAULT 0.00,
  `admin_charges_waived` decimal(10,2) DEFAULT 0.00,
  `last_charge_date` date DEFAULT NULL,
  `last_charge_payment_date` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `member_no` (`member_no`),
  UNIQUE KEY `national_id` (`national_id`),
  KEY `user_id` (`user_id`),
  KEY `created_by` (`created_by`),
  KEY `fk_members_updated_by` (`updated_by`),
  KEY `fk_members_reviewed_by` (`reviewed_by`),
  KEY `approved_by` (`approved_by`),
  CONSTRAINT `fk_members_reviewed_by` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_members_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `members_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `members_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `members_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=85 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `members`
--

LOCK TABLES `members` WRITE;
/*!40000 ALTER TABLE `members` DISABLE KEYS */;
INSERT INTO `members` VALUES (1,'1','CHARLES G. NDERITU','1','1','email@sacco.co.ke','1','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:04','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,20000.00,2,0.00,NULL,NULL,NULL,NULL,0,NULL,20000.00,2,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(2,'2','MARAGARA GACHIE','2','2','email@sacco.co.ke','2','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:04','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,20000.00,2,0.00,NULL,NULL,NULL,NULL,0,NULL,20000.00,2,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(3,'3','SIMON K. NDAGURI','3','3','email@sacco.co.ke','3','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,20000.00,2,0.00,NULL,NULL,NULL,NULL,0,NULL,20000.00,2,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(4,'6','DAVID M. MUCHIRI','6','6','email@sacco.co.ke','6','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,5000.00,0,5000.00,NULL,NULL,NULL,NULL,0,NULL,5000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(5,'7','FRANCIS MUNUHE','7','7','email@sacco.co.ke','7','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,7000.00,0,7000.00,NULL,NULL,NULL,NULL,0,NULL,7000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(6,'12','PETER MBUGUA','12','12','email@sacco.co.ke','12','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,30000.00,3,0.00,NULL,NULL,NULL,NULL,0,NULL,30000.00,3,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(7,'13','DANIEL NDEGWA','13','13','email@sacco.co.ke','13','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05',NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,0.00,0,0.00,NULL,NULL,NULL,NULL,0,NULL,0.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(8,'15','DANIEL MAINA NJOROGE','15','15','email@sacco.co.ke','15','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,7000.00,0,7000.00,NULL,NULL,NULL,NULL,0,NULL,7000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(9,'17','SUSAN W. GITAU','17','17','email@sacco.co.ke','17','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,20000.00,2,0.00,NULL,NULL,NULL,NULL,0,NULL,20000.00,2,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(10,'19','KEZIAH WAITHIRA','19','19','email@sacco.co.ke','19','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,20000.00,2,0.00,NULL,NULL,NULL,NULL,0,NULL,20000.00,2,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(11,'20','JAMES M. THUITA','20','20','email@sacco.co.ke','20','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,5000.00,0,5000.00,NULL,NULL,NULL,NULL,0,NULL,5000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(12,'21','JASON KIRAGU','21','21','email@sacco.co.ke','21','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,4000.00,0,4000.00,NULL,NULL,NULL,NULL,0,NULL,4000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(13,'25','PETER WAITHAKA KIMANI','25','25','email@sacco.co.ke','25','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,7000.00,0,7000.00,NULL,NULL,NULL,NULL,0,NULL,7000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(14,'29','TABITHA WANYOIKE','29','29','email@sacco.co.ke','29','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,4000.00,0,4000.00,NULL,NULL,NULL,NULL,0,NULL,4000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(15,'31','ELIUD NJOROGE','31','31','email@sacco.co.ke','31','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,10000.00,1,0.00,NULL,NULL,NULL,NULL,0,NULL,10000.00,1,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(16,'36','CHRISTINE NJERI MAHUGU','36','36','email@sacco.co.ke','36','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,7000.00,0,7000.00,NULL,NULL,NULL,NULL,0,NULL,7000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(17,'37','GRACE WANGUI WANJOHI','37','37','email@sacco.co.ke','37','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,20000.00,2,0.00,NULL,NULL,NULL,NULL,0,NULL,20000.00,2,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(18,'41','NANCY WAMBUI NJOROGE','41','41','email@sacco.co.ke','41','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,7000.00,0,7000.00,NULL,NULL,NULL,NULL,0,NULL,7000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(19,'44','JULIA MUTHONI GITHINJI','44','44','email@sacco.co.ke','44','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,10000.00,1,0.00,NULL,NULL,NULL,NULL,0,NULL,10000.00,1,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(20,'45','JACQUELINE W. KAMAU','45','45','email@sacco.co.ke','45','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,7000.00,0,7000.00,NULL,NULL,NULL,NULL,0,NULL,7000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(21,'48','SAMUEL WAITHAKA','48','48','email@sacco.co.ke','48','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,6000.00,0,6000.00,NULL,NULL,NULL,NULL,0,NULL,6000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(22,'51','MICHAEL KIMANI','51','51','email@sacco.co.ke','51','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,7000.00,0,7000.00,NULL,NULL,NULL,NULL,0,NULL,7000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(23,'52','CHARLES MATHENGE WACHIRA','52','52','email@sacco.co.ke','52','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,10000.00,1,0.00,NULL,NULL,NULL,NULL,0,NULL,10000.00,1,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(24,'53','EDWARD N. KANYONJI','53','53','email@sacco.co.ke','53','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,47000.00,4,7000.00,NULL,NULL,NULL,NULL,0,NULL,47000.00,4,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(25,'54','MUSA MUCHAI','54','54','email@sacco.co.ke','54','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,6000.00,0,6000.00,NULL,NULL,NULL,NULL,0,NULL,6000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(26,'55','ARTHUR NDERITU GITONGA','55','55','email@sacco.co.ke','55','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,3000.00,0,3000.00,NULL,NULL,NULL,NULL,0,NULL,3000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(27,'56','MARY WAITHIEGENI GITONGA','56','56','email@sacco.co.ke','56','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,10000.00,1,0.00,NULL,NULL,NULL,NULL,0,NULL,10000.00,1,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(28,'57','EUNICE N KIONGO','57','57','email@sacco.co.ke','57','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,10000.00,1,0.00,NULL,NULL,NULL,NULL,0,NULL,10000.00,1,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(29,'58','KELLEN MUTHONI MBAE','58','58','email@sacco.co.ke','58','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,10000.00,1,0.00,NULL,NULL,NULL,NULL,0,NULL,10000.00,1,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(30,'62','MARY NJERI NJOROGE','62','62','email@sacco.co.ke','62','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,5000.00,0,5000.00,NULL,NULL,NULL,NULL,0,NULL,5000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(31,'65','MARGARET KAMAU','65','65','email@sacco.co.ke','65','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,7000.00,0,7000.00,NULL,NULL,NULL,NULL,0,NULL,7000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(32,'67','EDITH NJOKI ITUI','67','67','email@sacco.co.ke','67','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,4000.00,0,4000.00,NULL,NULL,NULL,NULL,0,NULL,4000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(33,'73','GEOFFREY NGANGA','73','73','email@sacco.co.ke','73','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,10000.00,1,0.00,NULL,NULL,NULL,NULL,0,NULL,10000.00,1,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(34,'74','SAMMY MAINA','74','74','email@sacco.co.ke','74','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,4000.00,0,4000.00,NULL,NULL,NULL,NULL,0,NULL,4000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(35,'78','LAWRENCE NDERITU','78','78','email@sacco.co.ke','78','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,4000.00,0,4000.00,NULL,NULL,NULL,NULL,0,NULL,4000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(36,'84','DANIEL KAMAU GUCHU','84','84','email@sacco.co.ke','84','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,7000.00,0,7000.00,NULL,NULL,NULL,NULL,0,NULL,7000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(37,'92','JANE WANGECHI MUTURI','92','92','email@sacco.co.ke','92','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,6000.00,0,6000.00,NULL,NULL,NULL,NULL,0,NULL,6000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(38,'94','SALOME WAMBUI KINGI','94','94','email@sacco.co.ke','94','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,3000.00,0,3000.00,NULL,NULL,NULL,NULL,0,NULL,3000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(39,'98','JOSEPH KIHUNYU MAINA','98','98','email@sacco.co.ke','98','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,14000.00,1,4000.00,NULL,NULL,NULL,NULL,0,NULL,14000.00,1,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(40,'99','JOYCE WARINGA GITUKIA','99','99','email@sacco.co.ke','99','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,10000.00,1,0.00,NULL,NULL,NULL,NULL,0,NULL,10000.00,1,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(41,'101','GRACE WANJIKU MAHUGU','101','101','email@sacco.co.ke','101','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,7000.00,0,7000.00,NULL,NULL,NULL,NULL,0,NULL,7000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(42,'113','MARGARET WANJIRU KAMAU','113','113','email@sacco.co.ke','113','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,10000.00,1,0.00,NULL,NULL,NULL,NULL,0,NULL,10000.00,1,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(43,'114','SUSAN WARUKIRA MAINA','114','114','email@sacco.co.ke','114','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,6000.00,0,6000.00,NULL,NULL,NULL,NULL,0,NULL,6000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(44,'116','ALEX WAHOME NDIRANGU','116','116','email@sacco.co.ke','116','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,20000.00,2,0.00,NULL,NULL,NULL,NULL,0,NULL,20000.00,2,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(45,'121','JAMES MUNENE MIRITI','121','121','email@sacco.co.ke','121','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,10000.00,1,0.00,NULL,NULL,NULL,NULL,0,NULL,10000.00,1,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(46,'123','CATHERINE WANJOHI','123','123','email@sacco.co.ke','123','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,7000.00,0,7000.00,NULL,NULL,NULL,NULL,0,NULL,7000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(47,'128','MERCY WANJIRU KAMAU','128','128','email@sacco.co.ke','128','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,7000.00,0,7000.00,NULL,NULL,NULL,NULL,0,NULL,7000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(48,'130','LEAH KAGURE NDECO','130','130','email@sacco.co.ke','130','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,6000.00,0,6000.00,NULL,NULL,NULL,NULL,0,NULL,6000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(49,'135','ALICE NYAMBURA KARANJA','135','135','email@sacco.co.ke','135','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,3000.00,0,3000.00,NULL,NULL,NULL,NULL,0,NULL,3000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(50,'136','RACHAEL WANJIKU','136','136','email@sacco.co.ke','136','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,7000.00,0,7000.00,NULL,NULL,NULL,NULL,0,NULL,7000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(51,'137','EUNICE NJAMBI KAMAU','137','137','email@sacco.co.ke','137','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,7000.00,0,7000.00,NULL,NULL,NULL,NULL,0,NULL,7000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(52,'140','MARY WACHEKE MWARI','140','140','email@sacco.co.ke','140','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,7000.00,0,7000.00,NULL,NULL,NULL,NULL,0,NULL,7000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(53,'143','ANGELA WANJIRU KAMAU','143','143','email@sacco.co.ke','143','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,7000.00,0,7000.00,NULL,NULL,NULL,NULL,0,NULL,7000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(54,'144','EUNICE WANJA WAIGANJO','144','144','email@sacco.co.ke','144','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,7000.00,0,7000.00,NULL,NULL,NULL,NULL,0,NULL,7000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(55,'145','RUTH WANGUI MAINA','145','145','email@sacco.co.ke','145','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,10000.00,1,0.00,NULL,NULL,NULL,NULL,0,NULL,10000.00,1,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(56,'147','JACKSON NDAGURI KAMAU','147','147','email@sacco.co.ke','147','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,10000.00,1,0.00,NULL,NULL,NULL,NULL,0,NULL,10000.00,1,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(57,'148','FLORENCE WAIRIMU WAROTHE','148','148','email@sacco.co.ke','148','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,7000.00,0,7000.00,NULL,NULL,NULL,NULL,0,NULL,7000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(58,'149','CATHERINE NJERI NJAGI','149','149','email@sacco.co.ke','149','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,7000.00,0,7000.00,NULL,NULL,NULL,NULL,0,NULL,7000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(59,'150','LLOYD NJAGI','150','150','email@sacco.co.ke','150','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,7000.00,0,7000.00,NULL,NULL,NULL,NULL,0,NULL,7000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(60,'154','JAMES GICHUHI NJOGU','154','154','email@sacco.co.ke','154','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,1000.00,0,1000.00,NULL,NULL,NULL,NULL,0,NULL,1000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(61,'155','JOHN NJUENI KARANJA','155','155','email@sacco.co.ke','155','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,4000.00,0,4000.00,NULL,NULL,NULL,NULL,0,NULL,4000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(62,'159','JOHN NJOROGE GACHIE','159','159','email@sacco.co.ke','159','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,4000.00,0,4000.00,NULL,NULL,NULL,NULL,0,NULL,4000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(63,'160','JOHN NDUNGU NJOROGE','160','160','email@sacco.co.ke','160','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,7000.00,0,7000.00,NULL,NULL,NULL,NULL,0,NULL,7000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(64,'161','LUCY KIENDE','161','161','email@sacco.co.ke','161','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,5000.00,0,5000.00,NULL,NULL,NULL,NULL,0,NULL,5000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(65,'162','PETER RWERIA','162','162','email@sacco.co.ke','162','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,6000.00,0,6000.00,NULL,NULL,NULL,NULL,0,NULL,6000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(66,'164','LUCY WANJIRU KAMAU','164','164','email@sacco.co.ke','164','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,7000.00,0,7000.00,NULL,NULL,NULL,NULL,0,NULL,7000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(67,'165','WILSON KAMAU KARANJA','165','165','email@sacco.co.ke','165','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,6000.00,0,6000.00,NULL,NULL,NULL,NULL,0,NULL,6000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(68,'166','MARGARET WAMBUI KIBE','166','166','email@sacco.co.ke','166','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,2000.00,0,2000.00,NULL,NULL,NULL,NULL,0,NULL,2000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(69,'167','EUNICE NJAMBI RWERIA','167','167','email@sacco.co.ke','167','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,10000.00,1,0.00,NULL,NULL,NULL,NULL,0,NULL,10000.00,1,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(70,'170','JUDDY WAMBUI NDUNGU','170','170','email@sacco.co.ke','170','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,3000.00,0,3000.00,NULL,NULL,NULL,NULL,0,NULL,3000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(71,'172','SUSAN W. WAMBUGU','172','172','email@sacco.co.ke','172','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,3000.00,0,3000.00,NULL,NULL,NULL,NULL,0,NULL,3000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(72,'176','JOSEPH KAMAU MUCHAI','176','176','email@sacco.co.ke','176','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,4000.00,0,4000.00,NULL,NULL,NULL,NULL,0,NULL,4000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(73,'179','MERCY MUTHONI MUNGAI','179','179','email@sacco.co.ke','179','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,4000.00,0,4000.00,NULL,NULL,NULL,NULL,0,NULL,4000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(74,'180','JOHN MURIITHI','180','180','email@sacco.co.ke','180','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,2000.00,0,2000.00,NULL,NULL,NULL,NULL,0,NULL,2000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(75,'181','JANE W. KIRAGU','181','181','email@sacco.co.ke','181','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,2000.00,0,2000.00,NULL,NULL,NULL,NULL,0,NULL,2000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(76,'182','ESTHER NDUTA MWANIKI','182','182','email@sacco.co.ke','182','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,3000.00,0,3000.00,NULL,NULL,NULL,NULL,0,NULL,3000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(77,'183','NANCY NYAKINYUA GITAU','183','183','email@sacco.co.ke','183','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,1000.00,0,1000.00,NULL,NULL,NULL,NULL,0,NULL,1000.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(78,'184','CAROLINE WANJIRU KANGARUA','184','184','email@sacco.co.ke','184','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05','2026-03-13 23:29:50',NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,10000.00,1,0.00,NULL,NULL,NULL,NULL,0,NULL,10000.00,1,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(79,'89','BETHWEL MBUGUA','89','89','email@sacco.co.ke','89','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05',NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,0.00,0,0.00,NULL,NULL,NULL,NULL,0,NULL,0.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(80,'185','BETHSAIDA CHURCH','185','185','email@sacco.co.ke','185','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05',NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,0.00,0,0.00,NULL,NULL,NULL,NULL,0,NULL,0.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(81,'186','CECILIA NYAMBURA MACHARIA','186','186','email@sacco.co.ke','186','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05',NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,0.00,0,0.00,NULL,NULL,NULL,NULL,0,NULL,0.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(82,'187','TERESIA NJOKI WAMBUI','187','187','email@sacco.co.ke','187','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05',NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,0.00,0,0.00,NULL,NULL,NULL,NULL,0,NULL,0.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(83,'188','STANSLAUS MASILA NGULA','188','188','email@sacco.co.ke','188','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05',NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,0.00,0,0.00,NULL,NULL,NULL,NULL,0,NULL,0.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL),(84,'190','PRINCE MICHAEL MWENDA GITAU','190','190','email@sacco.co.ke','190','2025-01-01','active',NULL,NULL,1,'2026-03-13 09:49:05',NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,NULL,0.00,0,0.00,NULL,NULL,NULL,NULL,0,NULL,0.00,0,0.00,0.00,NULL,0.00,0.00,0.00,NULL,NULL);
/*!40000 ALTER TABLE `members` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `member_id` int(11) DEFAULT NULL,
  `title` varchar(200) NOT NULL,
  `message` text NOT NULL,
  `type` enum('sms','email','app') NOT NULL,
  `status` enum('pending','sent','failed') DEFAULT 'pending',
  `sent_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `member_id` (`member_id`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `notifications_ibfk_2` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notifications`
--

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `opening_balance_batches`
--

DROP TABLE IF EXISTS `opening_balance_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `opening_balance_batches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_no` varchar(50) NOT NULL,
  `batch_date` date NOT NULL,
  `total_members` int(11) NOT NULL,
  `total_shares` decimal(10,2) NOT NULL,
  `total_deposits` decimal(10,2) NOT NULL,
  `total_loans` decimal(10,2) NOT NULL,
  `status` enum('pending','processed','verified','posted') DEFAULT 'pending',
  `verified_by` int(11) DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `posted_by` int(11) DEFAULT NULL,
  `posted_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `batch_no` (`batch_no`),
  KEY `verified_by` (`verified_by`),
  KEY `posted_by` (`posted_by`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `opening_balance_batches_ibfk_1` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`),
  CONSTRAINT `opening_balance_batches_ibfk_2` FOREIGN KEY (`posted_by`) REFERENCES `users` (`id`),
  CONSTRAINT `opening_balance_batches_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `opening_balance_batches`
--

LOCK TABLES `opening_balance_batches` WRITE;
/*!40000 ALTER TABLE `opening_balance_batches` DISABLE KEYS */;
INSERT INTO `opening_balance_batches` VALUES (1,'SHARECONTRIB202603143827','2026-03-14',77,0.00,0.00,0.00,'processed',NULL,NULL,NULL,NULL,'Imported 77 share contributions totaling KES 649,000.00',1,'2026-03-13 23:27:55');
/*!40000 ALTER TABLE `opening_balance_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `opening_balances`
--

DROP TABLE IF EXISTS `opening_balances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `opening_balances` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `balance_type` enum('share','share_contribution','deposit','loan') NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `shares_count` int(11) DEFAULT NULL COMMENT 'For share balances only',
  `share_value` decimal(10,2) DEFAULT NULL COMMENT 'For share balances only',
  `loan_id` int(11) DEFAULT NULL COMMENT 'For loan balances only',
  `effective_date` date NOT NULL,
  `description` text DEFAULT NULL,
  `reference_no` varchar(50) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `contribution_progress` decimal(10,2) DEFAULT 0.00 COMMENT 'Progress towards next full share',
  `full_shares_from_contrib` int(11) DEFAULT 0 COMMENT 'Full shares issued from contributions',
  `remaining_balance` decimal(10,2) DEFAULT 0.00 COMMENT 'Remaining partial balance',
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  KEY `loan_id` (`loan_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `opening_balances_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`),
  CONSTRAINT `opening_balances_ibfk_2` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`),
  CONSTRAINT `opening_balances_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=78 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `opening_balances`
--

LOCK TABLES `opening_balances` WRITE;
/*!40000 ALTER TABLE `opening_balances` DISABLE KEYS */;
INSERT INTO `opening_balances` VALUES (1,1,'share_contribution',20000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 20,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(2,2,'share_contribution',20000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 20,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(3,3,'share_contribution',20000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 20,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(4,4,'share_contribution',5000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 5,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(5,5,'share_contribution',7000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 7,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(6,6,'share_contribution',30000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 30,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(7,8,'share_contribution',7000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 7,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(8,9,'share_contribution',20000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 20,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(9,10,'share_contribution',20000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 20,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(10,11,'share_contribution',5000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 5,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(11,12,'share_contribution',4000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 4,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(12,13,'share_contribution',7000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 7,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(13,14,'share_contribution',4000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 4,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(14,15,'share_contribution',10000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 10,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(15,16,'share_contribution',7000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 7,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(16,17,'share_contribution',20000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 20,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(17,18,'share_contribution',7000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 7,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(18,19,'share_contribution',10000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 10,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(19,20,'share_contribution',7000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 7,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(20,21,'share_contribution',6000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 6,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(21,22,'share_contribution',7000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 7,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(22,23,'share_contribution',10000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 10,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(23,24,'share_contribution',47000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 47,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(24,25,'share_contribution',6000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 6,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(25,26,'share_contribution',3000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 3,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(26,27,'share_contribution',10000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 10,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(27,28,'share_contribution',10000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 10,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(28,29,'share_contribution',10000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 10,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(29,30,'share_contribution',5000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 5,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(30,31,'share_contribution',7000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 7,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(31,32,'share_contribution',4000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 4,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(32,33,'share_contribution',10000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 10,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(33,34,'share_contribution',4000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 4,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(34,35,'share_contribution',4000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 4,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(35,36,'share_contribution',7000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 7,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(36,37,'share_contribution',6000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 6,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(37,38,'share_contribution',3000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 3,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(38,39,'share_contribution',14000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 14,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(39,40,'share_contribution',10000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 10,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(40,41,'share_contribution',7000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 7,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(41,42,'share_contribution',10000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 10,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(42,43,'share_contribution',6000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 6,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(43,44,'share_contribution',20000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 20,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(44,45,'share_contribution',10000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 10,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(45,46,'share_contribution',7000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 7,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(46,47,'share_contribution',7000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 7,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(47,48,'share_contribution',6000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 6,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(48,49,'share_contribution',3000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 3,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(49,50,'share_contribution',7000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 7,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(50,51,'share_contribution',7000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 7,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(51,52,'share_contribution',7000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 7,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(52,53,'share_contribution',7000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 7,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(53,54,'share_contribution',7000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 7,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(54,55,'share_contribution',10000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 10,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(55,56,'share_contribution',10000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 10,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(56,57,'share_contribution',7000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 7,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(57,58,'share_contribution',7000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 7,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(58,59,'share_contribution',7000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 7,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(59,60,'share_contribution',1000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 1,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(60,61,'share_contribution',4000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 4,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(61,62,'share_contribution',4000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 4,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(62,63,'share_contribution',7000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 7,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(63,64,'share_contribution',5000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 5,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:54',0.00,0,0.00),(64,65,'share_contribution',6000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 6,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:55',0.00,0,0.00),(65,66,'share_contribution',7000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 7,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:55',0.00,0,0.00),(66,67,'share_contribution',6000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 6,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:55',0.00,0,0.00),(67,68,'share_contribution',2000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 2,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:55',0.00,0,0.00),(68,69,'share_contribution',10000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 10,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:55',0.00,0,0.00),(69,70,'share_contribution',3000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 3,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:55',0.00,0,0.00),(70,71,'share_contribution',3000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 3,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:55',0.00,0,0.00),(71,72,'share_contribution',4000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 4,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:55',0.00,0,0.00),(72,73,'share_contribution',4000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 4,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:55',0.00,0,0.00),(73,74,'share_contribution',2000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 2,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:55',0.00,0,0.00),(74,75,'share_contribution',2000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 2,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:55',0.00,0,0.00),(75,76,'share_contribution',3000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 3,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:55',0.00,0,0.00),(76,77,'share_contribution',1000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 1,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:55',0.00,0,0.00),(77,78,'share_contribution',10000.00,NULL,NULL,NULL,'2026-03-14','Imported share contribution: KES 10,000','SHARECONTRIB202603143827',1,'2026-03-13 23:27:55',0.00,0,0.00);
/*!40000 ALTER TABLE `opening_balances` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `penalties`
--

DROP TABLE IF EXISTS `penalties`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `penalties` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `penalty_type` enum('loan_penalty','withdrawal_penalty','late_fee','administration_fee','other') NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `penalty_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `paid_date` date DEFAULT NULL,
  `reference_no` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `loan_id` int(11) DEFAULT NULL,
  `status` enum('pending','paid','waived','overdue') DEFAULT 'pending',
  `waived_by` int(11) DEFAULT NULL,
  `waived_at` datetime DEFAULT NULL,
  `waiver_reason` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `reference_no` (`reference_no`),
  KEY `loan_id` (`loan_id`),
  KEY `created_by` (`created_by`),
  KEY `waived_by` (`waived_by`),
  KEY `idx_member` (`member_id`),
  KEY `idx_status` (`status`),
  KEY `idx_date` (`penalty_date`),
  CONSTRAINT `penalties_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`),
  CONSTRAINT `penalties_ibfk_2` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`),
  CONSTRAINT `penalties_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `penalties_ibfk_4` FOREIGN KEY (`waived_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `penalties`
--

LOCK TABLES `penalties` WRITE;
/*!40000 ALTER TABLE `penalties` DISABLE KEYS */;
/*!40000 ALTER TABLE `penalties` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `penalty_payments`
--

DROP TABLE IF EXISTS `penalty_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `penalty_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `penalty_id` int(11) NOT NULL,
  `amount_paid` decimal(10,2) NOT NULL,
  `payment_date` date NOT NULL,
  `payment_method` enum('cash','bank','mpesa','cheque') NOT NULL,
  `reference_no` varchar(50) DEFAULT NULL,
  `receipt_no` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `receipt_no` (`receipt_no`),
  KEY `penalty_id` (`penalty_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `penalty_payments_ibfk_1` FOREIGN KEY (`penalty_id`) REFERENCES `penalties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `penalty_payments_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `penalty_payments`
--

LOCK TABLES `penalty_payments` WRITE;
/*!40000 ALTER TABLE `penalty_payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `penalty_payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `penalty_rates`
--

DROP TABLE IF EXISTS `penalty_rates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `penalty_rates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `rate_type` enum('loan_penalty','withdrawal_penalty','late_fee','administration_fee') NOT NULL,
  `rate_name` varchar(100) NOT NULL,
  `calculation_method` enum('fixed','percentage','per_day') NOT NULL,
  `rate_value` decimal(10,2) NOT NULL,
  `min_amount` decimal(10,2) DEFAULT 0.00,
  `max_amount` decimal(10,2) DEFAULT NULL,
  `applies_after_days` int(11) DEFAULT 0,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `effective_from` date NOT NULL,
  `effective_to` date DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `penalty_rates_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `penalty_rates`
--

LOCK TABLES `penalty_rates` WRITE;
/*!40000 ALTER TABLE `penalty_rates` DISABLE KEYS */;
INSERT INTO `penalty_rates` VALUES (1,'loan_penalty','Loan Late Payment Penalty','per_day',50.00,100.00,NULL,1,'KES 50 per day for late loan payments',1,'2026-03-14',NULL,1,'2026-03-14 00:27:02',NULL),(2,'loan_penalty','Loan Default Penalty','percentage',5.00,500.00,NULL,30,'5% of outstanding balance after 30 days',1,'2026-03-14',NULL,1,'2026-03-14 00:27:02',NULL),(3,'withdrawal_penalty','Early Withdrawal Penalty','percentage',2.00,200.00,NULL,0,'2% fee for early withdrawal',1,'2026-03-14',NULL,1,'2026-03-14 00:27:02',NULL),(4,'late_fee','Monthly Late Fee','fixed',500.00,500.00,NULL,15,'KES 500 flat fee for late payments',1,'2026-03-14',NULL,1,'2026-03-14 00:27:02',NULL),(5,'administration_fee','Administration Penalty','fixed',1000.00,1000.00,NULL,0,'Administrative penalty for violations',1,'2026-03-14',NULL,1,'2026-03-14 00:27:02',NULL);
/*!40000 ALTER TABLE `penalty_rates` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `penalty_waivers`
--

DROP TABLE IF EXISTS `penalty_waivers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `penalty_waivers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `penalty_id` int(11) NOT NULL,
  `waiver_date` date NOT NULL,
  `amount_waived` decimal(10,2) NOT NULL,
  `reason` text NOT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `penalty_id` (`penalty_id`),
  KEY `approved_by` (`approved_by`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `penalty_waivers_ibfk_1` FOREIGN KEY (`penalty_id`) REFERENCES `penalties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `penalty_waivers_ibfk_2` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`),
  CONSTRAINT `penalty_waivers_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `penalty_waivers`
--

LOCK TABLES `penalty_waivers` WRITE;
/*!40000 ALTER TABLE `penalty_waivers` DISABLE KEYS */;
/*!40000 ALTER TABLE `penalty_waivers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `reconciliation_items`
--

DROP TABLE IF EXISTS `reconciliation_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `reconciliation_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `reconciliation_id` int(11) NOT NULL,
  `transaction_type` enum('deposit','withdrawal','bank_charge','interest','error') NOT NULL,
  `transaction_date` date NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `description` text DEFAULT NULL,
  `cleared` tinyint(1) DEFAULT 0,
  `journal_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `reconciliation_id` (`reconciliation_id`),
  KEY `journal_id` (`journal_id`),
  CONSTRAINT `reconciliation_items_ibfk_1` FOREIGN KEY (`reconciliation_id`) REFERENCES `bank_reconciliation` (`id`) ON DELETE CASCADE,
  CONSTRAINT `reconciliation_items_ibfk_2` FOREIGN KEY (`journal_id`) REFERENCES `journal_entries` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `reconciliation_items`
--

LOCK TABLES `reconciliation_items` WRITE;
/*!40000 ALTER TABLE `reconciliation_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `reconciliation_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `share_contribution_imports`
--

DROP TABLE IF EXISTS `share_contribution_imports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `share_contribution_imports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_no` varchar(50) NOT NULL,
  `member_id` int(11) NOT NULL,
  `contribution_amount` decimal(10,2) NOT NULL,
  `contribution_date` date NOT NULL,
  `reference_no` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `processed` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  CONSTRAINT `share_contribution_imports_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=78 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `share_contribution_imports`
--

LOCK TABLES `share_contribution_imports` WRITE;
/*!40000 ALTER TABLE `share_contribution_imports` DISABLE KEYS */;
INSERT INTO `share_contribution_imports` VALUES (1,'SHARECONTRIB202603143827',1,20000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(2,'SHARECONTRIB202603143827',2,20000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(3,'SHARECONTRIB202603143827',3,20000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(4,'SHARECONTRIB202603143827',4,5000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(5,'SHARECONTRIB202603143827',5,7000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(6,'SHARECONTRIB202603143827',6,30000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(7,'SHARECONTRIB202603143827',8,7000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(8,'SHARECONTRIB202603143827',9,20000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(9,'SHARECONTRIB202603143827',10,20000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(10,'SHARECONTRIB202603143827',11,5000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(11,'SHARECONTRIB202603143827',12,4000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(12,'SHARECONTRIB202603143827',13,7000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(13,'SHARECONTRIB202603143827',14,4000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(14,'SHARECONTRIB202603143827',15,10000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(15,'SHARECONTRIB202603143827',16,7000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(16,'SHARECONTRIB202603143827',17,20000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(17,'SHARECONTRIB202603143827',18,7000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(18,'SHARECONTRIB202603143827',19,10000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(19,'SHARECONTRIB202603143827',20,7000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(20,'SHARECONTRIB202603143827',21,6000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(21,'SHARECONTRIB202603143827',22,7000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(22,'SHARECONTRIB202603143827',23,10000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(23,'SHARECONTRIB202603143827',24,47000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(24,'SHARECONTRIB202603143827',25,6000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(25,'SHARECONTRIB202603143827',26,3000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(26,'SHARECONTRIB202603143827',27,10000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(27,'SHARECONTRIB202603143827',28,10000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(28,'SHARECONTRIB202603143827',29,10000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(29,'SHARECONTRIB202603143827',30,5000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(30,'SHARECONTRIB202603143827',31,7000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(31,'SHARECONTRIB202603143827',32,4000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(32,'SHARECONTRIB202603143827',33,10000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(33,'SHARECONTRIB202603143827',34,4000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(34,'SHARECONTRIB202603143827',35,4000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(35,'SHARECONTRIB202603143827',36,7000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(36,'SHARECONTRIB202603143827',37,6000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(37,'SHARECONTRIB202603143827',38,3000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(38,'SHARECONTRIB202603143827',39,14000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(39,'SHARECONTRIB202603143827',40,10000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(40,'SHARECONTRIB202603143827',41,7000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(41,'SHARECONTRIB202603143827',42,10000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(42,'SHARECONTRIB202603143827',43,6000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(43,'SHARECONTRIB202603143827',44,20000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(44,'SHARECONTRIB202603143827',45,10000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(45,'SHARECONTRIB202603143827',46,7000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(46,'SHARECONTRIB202603143827',47,7000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(47,'SHARECONTRIB202603143827',48,6000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(48,'SHARECONTRIB202603143827',49,3000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(49,'SHARECONTRIB202603143827',50,7000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(50,'SHARECONTRIB202603143827',51,7000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(51,'SHARECONTRIB202603143827',52,7000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(52,'SHARECONTRIB202603143827',53,7000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(53,'SHARECONTRIB202603143827',54,7000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(54,'SHARECONTRIB202603143827',55,10000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(55,'SHARECONTRIB202603143827',56,10000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(56,'SHARECONTRIB202603143827',57,7000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(57,'SHARECONTRIB202603143827',58,7000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(58,'SHARECONTRIB202603143827',59,7000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(59,'SHARECONTRIB202603143827',60,1000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(60,'SHARECONTRIB202603143827',61,4000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(61,'SHARECONTRIB202603143827',62,4000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(62,'SHARECONTRIB202603143827',63,7000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(63,'SHARECONTRIB202603143827',64,5000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:54'),(64,'SHARECONTRIB202603143827',65,6000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:55'),(65,'SHARECONTRIB202603143827',66,7000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:55'),(66,'SHARECONTRIB202603143827',67,6000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:55'),(67,'SHARECONTRIB202603143827',68,2000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:55'),(68,'SHARECONTRIB202603143827',69,10000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:55'),(69,'SHARECONTRIB202603143827',70,3000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:55'),(70,'SHARECONTRIB202603143827',71,3000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:55'),(71,'SHARECONTRIB202603143827',72,4000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:55'),(72,'SHARECONTRIB202603143827',73,4000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:55'),(73,'SHARECONTRIB202603143827',74,2000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:55'),(74,'SHARECONTRIB202603143827',75,2000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:55'),(75,'SHARECONTRIB202603143827',76,3000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:55'),(76,'SHARECONTRIB202603143827',77,1000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:55'),(77,'SHARECONTRIB202603143827',78,10000.00,'2025-12-31','opening_balance','Opening Balance',1,'2026-03-13 23:27:55');
/*!40000 ALTER TABLE `share_contribution_imports` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `share_contributions`
--

DROP TABLE IF EXISTS `share_contributions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `share_contributions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `contribution_date` date NOT NULL,
  `reference_no` varchar(50) DEFAULT NULL,
  `payment_method` enum('cash','bank','mpesa','mobile') DEFAULT 'cash',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `share_contributions_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`),
  CONSTRAINT `share_contributions_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=78 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `share_contributions`
--

LOCK TABLES `share_contributions` WRITE;
/*!40000 ALTER TABLE `share_contributions` DISABLE KEYS */;
INSERT INTO `share_contributions` VALUES (1,1,20000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(2,2,20000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(3,3,20000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(4,4,5000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(5,5,7000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(6,6,30000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(7,8,7000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(8,9,20000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(9,10,20000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(10,11,5000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(11,12,4000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(12,13,7000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(13,14,4000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(14,15,10000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(15,16,7000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(16,17,20000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(17,18,7000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(18,19,10000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(19,20,7000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(20,21,6000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(21,22,7000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(22,23,10000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(23,24,47000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(24,25,6000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(25,26,3000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(26,27,10000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(27,28,10000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(28,29,10000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(29,30,5000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(30,31,7000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(31,32,4000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(32,33,10000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(33,34,4000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(34,35,4000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(35,36,7000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(36,37,6000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(37,38,3000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(38,39,14000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(39,40,10000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(40,41,7000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(41,42,10000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(42,43,6000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(43,44,20000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(44,45,10000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(45,46,7000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(46,47,7000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(47,48,6000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(48,49,3000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(49,50,7000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(50,51,7000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(51,52,7000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(52,53,7000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(53,54,7000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(54,55,10000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(55,56,10000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(56,57,7000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(57,58,7000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(58,59,7000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(59,60,1000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(60,61,4000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(61,62,4000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(62,63,7000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(63,64,5000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(64,65,6000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(65,66,7000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(66,67,6000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(67,68,2000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(68,69,10000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(69,70,3000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(70,71,3000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(71,72,4000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(72,73,4000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(73,74,2000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(74,75,2000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(75,76,3000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(76,77,1000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50'),(77,78,10000.00,'2025-12-31','opening_balance','cash','Imported contribution - Opening Balance',1,'2026-03-13 23:29:50');
/*!40000 ALTER TABLE `share_contributions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `shares`
--

DROP TABLE IF EXISTS `shares`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `shares` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `shares_count` int(11) NOT NULL,
  `share_value` decimal(10,2) NOT NULL,
  `total_value` decimal(10,2) GENERATED ALWAYS AS (`shares_count` * `share_value`) STORED,
  `transaction_type` enum('purchase','transfer','refund','opening_balance') NOT NULL,
  `reference_no` varchar(50) DEFAULT NULL,
  `date_purchased` date NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_opening_balance` tinyint(1) DEFAULT 0,
  `opening_balance_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  KEY `created_by` (`created_by`),
  KEY `opening_balance_id` (`opening_balance_id`),
  CONSTRAINT `shares_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`),
  CONSTRAINT `shares_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `shares_ibfk_3` FOREIGN KEY (`opening_balance_id`) REFERENCES `opening_balances` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=39 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `shares`
--

LOCK TABLES `shares` WRITE;
/*!40000 ALTER TABLE `shares` DISABLE KEYS */;
INSERT INTO `shares` VALUES (3,1,1,10000.00,10000.00,'opening_balance','IMP177344459097850','2025-12-31',1,'2026-03-13 23:29:50',1,NULL,'Share issued from imported contribution'),(4,1,1,10000.00,10000.00,'opening_balance','IMP177344459017211','2025-12-31',1,'2026-03-13 23:29:50',1,NULL,'Share issued from imported contribution'),(5,2,1,10000.00,10000.00,'opening_balance','IMP177344459084910','2025-12-31',1,'2026-03-13 23:29:50',1,NULL,'Share issued from imported contribution'),(6,2,1,10000.00,10000.00,'opening_balance','IMP177344459012611','2025-12-31',1,'2026-03-13 23:29:50',1,NULL,'Share issued from imported contribution'),(7,3,1,10000.00,10000.00,'opening_balance','IMP177344459089430','2025-12-31',1,'2026-03-13 23:29:50',1,NULL,'Share issued from imported contribution'),(8,3,1,10000.00,10000.00,'opening_balance','IMP177344459052711','2025-12-31',1,'2026-03-13 23:29:50',1,NULL,'Share issued from imported contribution'),(9,6,1,10000.00,10000.00,'opening_balance','IMP177344459033200','2025-12-31',1,'2026-03-13 23:29:50',1,NULL,'Share issued from imported contribution'),(10,6,1,10000.00,10000.00,'opening_balance','IMP177344459059921','2025-12-31',1,'2026-03-13 23:29:50',1,NULL,'Share issued from imported contribution'),(11,6,1,10000.00,10000.00,'opening_balance','IMP177344459052592','2025-12-31',1,'2026-03-13 23:29:50',1,NULL,'Share issued from imported contribution'),(12,9,1,10000.00,10000.00,'opening_balance','IMP177344459075050','2025-12-31',1,'2026-03-13 23:29:50',1,NULL,'Share issued from imported contribution'),(13,9,1,10000.00,10000.00,'opening_balance','IMP177344459013601','2025-12-31',1,'2026-03-13 23:29:50',1,NULL,'Share issued from imported contribution'),(14,10,1,10000.00,10000.00,'opening_balance','IMP177344459030020','2025-12-31',1,'2026-03-13 23:29:50',1,NULL,'Share issued from imported contribution'),(15,10,1,10000.00,10000.00,'opening_balance','IMP177344459044371','2025-12-31',1,'2026-03-13 23:29:50',1,NULL,'Share issued from imported contribution'),(16,15,1,10000.00,10000.00,'opening_balance','IMP177344459037060','2025-12-31',1,'2026-03-13 23:29:50',1,NULL,'Share issued from imported contribution'),(17,17,1,10000.00,10000.00,'opening_balance','IMP177344459047850','2025-12-31',1,'2026-03-13 23:29:50',1,NULL,'Share issued from imported contribution'),(18,17,1,10000.00,10000.00,'opening_balance','IMP177344459022281','2025-12-31',1,'2026-03-13 23:29:50',1,NULL,'Share issued from imported contribution'),(19,19,1,10000.00,10000.00,'opening_balance','IMP177344459049100','2025-12-31',1,'2026-03-13 23:29:50',1,NULL,'Share issued from imported contribution'),(20,23,1,10000.00,10000.00,'opening_balance','IMP177344459070050','2025-12-31',1,'2026-03-13 23:29:50',1,NULL,'Share issued from imported contribution'),(21,24,1,10000.00,10000.00,'opening_balance','IMP177344459053450','2025-12-31',1,'2026-03-13 23:29:50',1,NULL,'Share issued from imported contribution'),(22,24,1,10000.00,10000.00,'opening_balance','IMP177344459030861','2025-12-31',1,'2026-03-13 23:29:50',1,NULL,'Share issued from imported contribution'),(23,24,1,10000.00,10000.00,'opening_balance','IMP177344459098952','2025-12-31',1,'2026-03-13 23:29:50',1,NULL,'Share issued from imported contribution'),(24,24,1,10000.00,10000.00,'opening_balance','IMP177344459030483','2025-12-31',1,'2026-03-13 23:29:50',1,NULL,'Share issued from imported contribution'),(25,27,1,10000.00,10000.00,'opening_balance','IMP177344459088200','2025-12-31',1,'2026-03-13 23:29:50',1,NULL,'Share issued from imported contribution'),(26,28,1,10000.00,10000.00,'opening_balance','IMP177344459072060','2025-12-31',1,'2026-03-13 23:29:50',1,NULL,'Share issued from imported contribution'),(27,29,1,10000.00,10000.00,'opening_balance','IMP177344459013340','2025-12-31',1,'2026-03-13 23:29:50',1,NULL,'Share issued from imported contribution'),(28,33,1,10000.00,10000.00,'opening_balance','IMP177344459036440','2025-12-31',1,'2026-03-13 23:29:50',1,NULL,'Share issued from imported contribution'),(29,39,1,10000.00,10000.00,'opening_balance','IMP177344459028760','2025-12-31',1,'2026-03-13 23:29:50',1,NULL,'Share issued from imported contribution'),(30,40,1,10000.00,10000.00,'opening_balance','IMP177344459077140','2025-12-31',1,'2026-03-13 23:29:50',1,NULL,'Share issued from imported contribution'),(31,42,1,10000.00,10000.00,'opening_balance','IMP177344459053720','2025-12-31',1,'2026-03-13 23:29:50',1,NULL,'Share issued from imported contribution'),(32,44,1,10000.00,10000.00,'opening_balance','IMP177344459030900','2025-12-31',1,'2026-03-13 23:29:50',1,NULL,'Share issued from imported contribution'),(33,44,1,10000.00,10000.00,'opening_balance','IMP177344459050131','2025-12-31',1,'2026-03-13 23:29:50',1,NULL,'Share issued from imported contribution'),(34,45,1,10000.00,10000.00,'opening_balance','IMP177344459067050','2025-12-31',1,'2026-03-13 23:29:50',1,NULL,'Share issued from imported contribution'),(35,55,1,10000.00,10000.00,'opening_balance','IMP177344459064580','2025-12-31',1,'2026-03-13 23:29:50',1,NULL,'Share issued from imported contribution'),(36,56,1,10000.00,10000.00,'opening_balance','IMP177344459065440','2025-12-31',1,'2026-03-13 23:29:50',1,NULL,'Share issued from imported contribution'),(37,69,1,10000.00,10000.00,'opening_balance','IMP177344459073660','2025-12-31',1,'2026-03-13 23:29:50',1,NULL,'Share issued from imported contribution'),(38,78,1,10000.00,10000.00,'opening_balance','IMP177344459062310','2025-12-31',1,'2026-03-13 23:29:50',1,NULL,'Share issued from imported contribution');
/*!40000 ALTER TABLE `shares` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `shares_issued`
--

DROP TABLE IF EXISTS `shares_issued`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `shares_issued` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `share_number` varchar(20) NOT NULL,
  `share_count` int(11) DEFAULT 1,
  `amount_paid` decimal(10,2) NOT NULL,
  `issue_date` date NOT NULL,
  `certificate_number` varchar(50) DEFAULT NULL,
  `issued_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `share_number` (`share_number`),
  UNIQUE KEY `certificate_number` (`certificate_number`),
  KEY `member_id` (`member_id`),
  KEY `issued_by` (`issued_by`),
  CONSTRAINT `shares_issued_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`),
  CONSTRAINT `shares_issued_ibfk_2` FOREIGN KEY (`issued_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=39 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `shares_issued`
--

LOCK TABLES `shares_issued` WRITE;
/*!40000 ALTER TABLE `shares_issued` DISABLE KEYS */;
INSERT INTO `shares_issued` VALUES (3,1,'SH20260001001',1,10000.00,'2025-12-31','CERT202603140001001298',1,'2026-03-13 23:29:50'),(4,1,'SH20260001002',1,10000.00,'2025-12-31','CERT202603140001002625',1,'2026-03-13 23:29:50'),(5,2,'SH20260002001',1,10000.00,'2025-12-31','CERT202603140002001818',1,'2026-03-13 23:29:50'),(6,2,'SH20260002002',1,10000.00,'2025-12-31','CERT202603140002002920',1,'2026-03-13 23:29:50'),(7,3,'SH20260003001',1,10000.00,'2025-12-31','CERT202603140003001440',1,'2026-03-13 23:29:50'),(8,3,'SH20260003002',1,10000.00,'2025-12-31','CERT202603140003002362',1,'2026-03-13 23:29:50'),(9,6,'SH20260006001',1,10000.00,'2025-12-31','CERT202603140006001434',1,'2026-03-13 23:29:50'),(10,6,'SH20260006002',1,10000.00,'2025-12-31','CERT202603140006002531',1,'2026-03-13 23:29:50'),(11,6,'SH20260006003',1,10000.00,'2025-12-31','CERT202603140006003262',1,'2026-03-13 23:29:50'),(12,9,'SH20260009001',1,10000.00,'2025-12-31','CERT202603140009001394',1,'2026-03-13 23:29:50'),(13,9,'SH20260009002',1,10000.00,'2025-12-31','CERT202603140009002163',1,'2026-03-13 23:29:50'),(14,10,'SH20260010001',1,10000.00,'2025-12-31','CERT202603140010001484',1,'2026-03-13 23:29:50'),(15,10,'SH20260010002',1,10000.00,'2025-12-31','CERT202603140010002542',1,'2026-03-13 23:29:50'),(16,15,'SH20260015001',1,10000.00,'2025-12-31','CERT202603140015001478',1,'2026-03-13 23:29:50'),(17,17,'SH20260017001',1,10000.00,'2025-12-31','CERT202603140017001218',1,'2026-03-13 23:29:50'),(18,17,'SH20260017002',1,10000.00,'2025-12-31','CERT202603140017002240',1,'2026-03-13 23:29:50'),(19,19,'SH20260019001',1,10000.00,'2025-12-31','CERT202603140019001361',1,'2026-03-13 23:29:50'),(20,23,'SH20260023001',1,10000.00,'2025-12-31','CERT202603140023001509',1,'2026-03-13 23:29:50'),(21,24,'SH20260024001',1,10000.00,'2025-12-31','CERT202603140024001225',1,'2026-03-13 23:29:50'),(22,24,'SH20260024002',1,10000.00,'2025-12-31','CERT202603140024002804',1,'2026-03-13 23:29:50'),(23,24,'SH20260024003',1,10000.00,'2025-12-31','CERT202603140024003310',1,'2026-03-13 23:29:50'),(24,24,'SH20260024004',1,10000.00,'2025-12-31','CERT202603140024004237',1,'2026-03-13 23:29:50'),(25,27,'SH20260027001',1,10000.00,'2025-12-31','CERT202603140027001927',1,'2026-03-13 23:29:50'),(26,28,'SH20260028001',1,10000.00,'2025-12-31','CERT202603140028001803',1,'2026-03-13 23:29:50'),(27,29,'SH20260029001',1,10000.00,'2025-12-31','CERT202603140029001147',1,'2026-03-13 23:29:50'),(28,33,'SH20260033001',1,10000.00,'2025-12-31','CERT202603140033001669',1,'2026-03-13 23:29:50'),(29,39,'SH20260039001',1,10000.00,'2025-12-31','CERT202603140039001670',1,'2026-03-13 23:29:50'),(30,40,'SH20260040001',1,10000.00,'2025-12-31','CERT202603140040001192',1,'2026-03-13 23:29:50'),(31,42,'SH20260042001',1,10000.00,'2025-12-31','CERT202603140042001268',1,'2026-03-13 23:29:50'),(32,44,'SH20260044001',1,10000.00,'2025-12-31','CERT202603140044001600',1,'2026-03-13 23:29:50'),(33,44,'SH20260044002',1,10000.00,'2025-12-31','CERT202603140044002559',1,'2026-03-13 23:29:50'),(34,45,'SH20260045001',1,10000.00,'2025-12-31','CERT202603140045001626',1,'2026-03-13 23:29:50'),(35,55,'SH20260055001',1,10000.00,'2025-12-31','CERT202603140055001267',1,'2026-03-13 23:29:50'),(36,56,'SH20260056001',1,10000.00,'2025-12-31','CERT202603140056001422',1,'2026-03-13 23:29:50'),(37,69,'SH20260069001',1,10000.00,'2025-12-31','CERT202603140069001576',1,'2026-03-13 23:29:50'),(38,78,'SH20260078001',1,10000.00,'2025-12-31','CERT202603140078001298',1,'2026-03-13 23:29:50');
/*!40000 ALTER TABLE `shares_issued` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tax_records`
--

DROP TABLE IF EXISTS `tax_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tax_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tax_date` date NOT NULL,
  `tax_type` enum('vat','withholding','income_tax','other') NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `reference_no` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `paid_date` date DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `status` enum('pending','paid','overdue') DEFAULT 'pending',
  `journal_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `journal_id` (`journal_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `tax_records_ibfk_1` FOREIGN KEY (`journal_id`) REFERENCES `journal_entries` (`id`),
  CONSTRAINT `tax_records_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tax_records`
--

LOCK TABLES `tax_records` WRITE;
/*!40000 ALTER TABLE `tax_records` DISABLE KEYS */;
/*!40000 ALTER TABLE `tax_records` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `transactions`
--

DROP TABLE IF EXISTS `transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_no` varchar(50) NOT NULL,
  `transaction_date` date NOT NULL,
  `description` text DEFAULT NULL,
  `debit_account` varchar(50) NOT NULL,
  `credit_account` varchar(50) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `reference_type` enum('deposit','withdrawal','loan','repayment','dividend','share','registration','bylaws','share_contribution') NOT NULL,
  `reference_id` int(11) NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `transaction_no` (`transaction_no`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `transactions`
--

LOCK TABLES `transactions` WRITE;
/*!40000 ALTER TABLE `transactions` DISABLE KEYS */;
/*!40000 ALTER TABLE `transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `role` enum('admin','officer','accountant','member') DEFAULT 'member',
  `status` tinyint(1) DEFAULT 1,
  `last_login` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `failed_attempts` int(11) DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `password_updated_at` datetime DEFAULT NULL,
  `two_factor_secret` varchar(255) DEFAULT NULL,
  `two_factor_enabled` tinyint(1) DEFAULT 0,
  `calculated_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'admin','$2y$10$fVdBu3X.d0bsjspaFEOf/eTHE2BWqVVbfimkD7PEBib.BEYGEgdW2',NULL,'System Administrator','admin',1,'2026-03-14 06:23:09','2026-03-12 13:02:54',0,NULL,NULL,NULL,0,NULL),(2,'MEM2026038357','$2y$10$6QpeXniKazRIpzMqL3H.WOKAD8.gQD/mDPjKEJpQC7X3P4snziW76','emwenotikenda@gmail.com','Michael','member',1,NULL,'2026-03-12 14:51:51',0,NULL,NULL,NULL,0,NULL),(3,'MEM2026034838','$2y$10$3dsLBOwQJ9Nrf9Tc82yuL.D/Te9lOCs/BL5WYlkJoCQymU4Q.VFki','nguremike@gmail.com','Michael','member',1,NULL,'2026-03-12 15:12:51',0,NULL,NULL,NULL,0,NULL),(4,'MEM2026036874','$2y$10$nrOMTqW6Cx35ucZ5WsYRjubY.SER/baT.hTNZ9XS8y2WQcZV085ra','emwenotikenda@gmail.com','Teresi','member',1,NULL,'2026-03-12 15:13:57',0,NULL,NULL,NULL,0,NULL),(5,'MEM2026039597','$2y$10$ALguB0b7YNNMnDfYFFB2rOEu0xgyyafB1bYEwMwxqKbtNbFYD/iEu','emwenotikenda@gmail.com','James','member',1,NULL,'2026-03-12 22:14:41',0,NULL,NULL,NULL,0,NULL);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-03-14  6:36:36
