-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: 5star
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
-- Table structure for table `vendors`
--

DROP TABLE IF EXISTS `vendors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `vendors` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `vendor_code` varchar(20) DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `company_name` varchar(160) DEFAULT NULL,
  `contact_person` varchar(120) DEFAULT NULL,
  `phone` varchar(15) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `address_line1` varchar(255) DEFAULT NULL,
  `address_line2` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `pincode` varchar(10) DEFAULT NULL,
  `country` varchar(60) NOT NULL DEFAULT 'India',
  `gstin` varchar(20) DEFAULT NULL,
  `pan` varchar(10) DEFAULT NULL,
  `bank_account_name` varchar(160) DEFAULT NULL,
  `bank_account_number` varchar(30) DEFAULT NULL,
  `bank_ifsc` varchar(11) DEFAULT NULL,
  `bank_name` varchar(120) DEFAULT NULL,
  `payment_terms` varchar(120) DEFAULT NULL COMMENT 'Free text, e.g. "Net 30"',
  `notes` varchar(500) DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_date` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `updated_date` datetime DEFAULT NULL,
  `deleted_by` bigint(20) unsigned DEFAULT NULL,
  `deleted_date` datetime DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `version` int(10) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vendors_uuid` (`uuid`),
  UNIQUE KEY `uq_vendors_code` (`vendor_code`),
  KEY `idx_vendors_state` (`is_deleted`,`is_active`),
  KEY `idx_vendors_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `vendors`
--

LOCK TABLES `vendors` WRITE;
/*!40000 ALTER TABLE `vendors` DISABLE KEYS */;
INSERT INTO `vendors` VALUES (1,'4fbcb418-461d-437c-a6f3-5f509e129c89','VEN0000000001','Phase 3 Verification Vendor',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'India',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-14 17:59:57',2,'2026-09-15 11:08:51',NULL,NULL,0,0,2),(2,'6cf0d81d-ce55-4b9e-bc5f-4bf784589a1e','VEN0000000002','Mobile Verify Vendor 5B4283',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'India',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-14 20:30:35',NULL,NULL,NULL,NULL,0,0,1),(3,'176c419a-8f60-4a31-b9a1-6015919303e3','VEN0000000003','Ummehabiba Dhalayat',NULL,'Arshiya Dhalayat','arshiyadhalayat',NULL,'ward no 9 sai nagar mudhol',NULL,'Bagalkote','Karnataka','587313','India',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,2,'2026-09-15 11:13:44',NULL,NULL,NULL,NULL,1,0,1),(4,'598d7896-c28c-492d-bc23-57e71b682146','VEN0000000004','Verify Country Fix Vendor 6aa8de75c9a4f',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'India',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-09-15 07:58:13',NULL,NULL,NULL,NULL,0,1,1),(5,'4df06f2a-67ab-4ad3-acc2-16cb40b68a23','VEN0000000005','Arshiya Dhalayat',NULL,'Arshiya Dhalayat','08951012004',NULL,'ward no 9 sai nagar mudhol',NULL,'Bagalkote','Karnataka','587313','India',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,2,'2026-09-15 11:53:59',NULL,NULL,NULL,NULL,1,0,1),(6,'4efa7f07-cac2-4d83-b21f-fcd642fe8452','VEN0000000006','arshi',NULL,'8966571992',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'India',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,2,'2026-09-16 13:33:26',NULL,NULL,NULL,NULL,1,0,1),(7,'35d7155c-678e-4598-80b1-04b517667864','VEN2627000001','Golden Spice Traders','Golden Spice Traders Pvt Ltd',NULL,'9876543210','vendor@goldenspice.test',NULL,NULL,NULL,NULL,NULL,'India','27AAAAA0000A1Z5','AAAAA0000A','Golden Spice Traders','123456789012','HDFC0001234','HDFC Bank','Net 30',NULL,2,'2026-09-18 12:25:08',NULL,NULL,NULL,NULL,1,0,1),(8,'752519ec-fa7d-4cac-bf51-93ea7a09fdfe','VEN2627000002','Fresh Import Vendor','Fresh Import Pvt Ltd','Priya Sharma','9123456780','fresh@example.com',NULL,NULL,NULL,NULL,NULL,'India',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,2,'2026-09-18 12:44:59',NULL,NULL,NULL,NULL,1,0,1),(9,'39a53b03-fa44-4ff3-812a-232f7708a12b','VEN2627000003','Demo Day Vendor','Demo Day Traders Pvt Ltd',NULL,'9000000001','demo@vendor.test',NULL,NULL,NULL,NULL,NULL,'India','29DEMOD0001A1Z1',NULL,NULL,NULL,NULL,NULL,'Net 15',NULL,2,'2026-09-19 11:48:49',2,'2026-09-19 12:30:35',NULL,NULL,0,0,2),(10,'4b49b597-d428-4ec2-a949-0577544b099e','VEN2627000004','Test Oil Suppliers',NULL,'Anita Shah','9876500002','sample2@example.com','45 Industrial Area',NULL,'Pune','Maharashtra','411001','India',NULL,NULL,NULL,NULL,NULL,NULL,'Net 15',NULL,2,'2026-09-19 14:55:41',NULL,NULL,NULL,NULL,0,1,1);
/*!40000 ALTER TABLE `vendors` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inventory_stock`
--

DROP TABLE IF EXISTS `inventory_stock`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_stock` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `product_variant_id` bigint(20) unsigned NOT NULL,
  `warehouse_id` bigint(20) unsigned NOT NULL,
  `quantity` decimal(12,3) NOT NULL DEFAULT 0.000 COMMENT 'May go negative: checkout is never blocked on stock',
  `reorder_threshold` decimal(12,3) DEFAULT NULL,
  `average_cost` decimal(12,4) DEFAULT NULL COMMENT 'Weighted-average purchase cost per unit',
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_date` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `updated_date` datetime DEFAULT NULL,
  `deleted_by` bigint(20) unsigned DEFAULT NULL,
  `deleted_date` datetime DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `version` int(10) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inventory_stock_uuid` (`uuid`),
  UNIQUE KEY `uq_inventory_stock_variant_warehouse` (`product_variant_id`,`warehouse_id`),
  KEY `idx_inventory_stock_warehouse` (`warehouse_id`),
  KEY `idx_inventory_stock_low` (`reorder_threshold`,`quantity`),
  CONSTRAINT `fk_inventory_stock_variant` FOREIGN KEY (`product_variant_id`) REFERENCES `product_variants` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_inventory_stock_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory_stock`
--

LOCK TABLES `inventory_stock` WRITE;
/*!40000 ALTER TABLE `inventory_stock` DISABLE KEYS */;
INSERT INTO `inventory_stock` VALUES (1,'33d79054-0143-4843-a7ee-cae0d6a77968',87,1,-1.000,NULL,NULL,2,'2026-09-21 12:02:00',2,'2026-09-21 12:02:00',NULL,NULL,1,0,2),(2,'c5cbd945-8166-4249-9011-7d5129a3a2ce',78,1,-3.000,NULL,NULL,2,'2026-09-21 12:02:00',NULL,'2026-09-21 12:48:25',NULL,NULL,1,0,3),(3,'190a9fda-2d22-4afd-9f9d-2c79651c50e5',75,1,-1.000,NULL,NULL,2,'2026-09-21 12:02:00',2,'2026-09-21 12:02:00',NULL,NULL,1,0,2),(4,'22304cfb-79bc-452c-9c47-dc0a4cd00a83',91,1,-2.000,NULL,NULL,2,'2026-09-21 12:04:16',2,'2026-09-21 12:04:16',NULL,NULL,1,0,2),(5,'ef67b5f9-6d07-4e45-9657-e7cf1a3518e2',81,1,-1.000,NULL,NULL,2,'2026-09-21 12:04:16',2,'2026-09-21 12:04:16',NULL,NULL,1,0,2),(6,'74997c40-7ca1-48a5-b5f2-ab194c767e03',99,1,-1.000,NULL,NULL,2,'2026-09-21 12:04:16',2,'2026-09-21 12:04:16',NULL,NULL,1,0,2),(7,'e9aacdf2-a102-4ea2-be56-0f25469ad55c',67,1,-1.000,NULL,NULL,2,'2026-09-21 12:04:16',2,'2026-09-21 12:04:16',NULL,NULL,1,0,2),(8,'8cc81263-de5e-45ff-b242-469bfb5442fa',71,1,-1.000,NULL,NULL,2,'2026-09-21 12:04:16',2,'2026-09-21 12:04:16',NULL,NULL,1,0,2),(9,'ed310d19-e0b2-4ea9-a696-a642e946438f',105,1,-1.000,NULL,NULL,NULL,'2026-09-21 12:48:25',NULL,'2026-09-21 12:48:25',NULL,NULL,1,0,2),(10,'784f6c3f-c110-4925-8052-d9ed387f9c66',80,1,-2.000,NULL,NULL,NULL,'2026-09-21 12:48:25',NULL,'2026-09-21 12:48:25',NULL,NULL,1,0,2),(11,'0bb9f325-314c-4e39-9bb4-99089dc3f06f',84,1,-1.000,NULL,NULL,NULL,'2026-09-21 12:48:25',NULL,'2026-09-21 12:48:25',NULL,NULL,1,0,2);
/*!40000 ALTER TABLE `inventory_stock` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inventory_movements`
--

DROP TABLE IF EXISTS `inventory_movements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_movements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `product_variant_id` bigint(20) unsigned NOT NULL,
  `warehouse_id` bigint(20) unsigned NOT NULL,
  `movement_type` enum('inward','sale','return','damage','lost','adjustment','transfer_in','transfer_out','return_to_vendor') NOT NULL,
  `reference_type` enum('order','purchase_order','csv_import','mobile_app','manual','pos_sale','opening_balance','purchase_return') DEFAULT NULL,
  `reference_id` bigint(20) unsigned DEFAULT NULL,
  `reference_uuid` char(36) DEFAULT NULL,
  `quantity_delta` decimal(12,3) NOT NULL COMMENT 'Signed: positive for inward/return, negative for sale/damage/transfer_out',
  `quantity_after` decimal(12,3) NOT NULL COMMENT 'Running balance snapshot, for audit reconstruction',
  `unit_cost` decimal(12,4) DEFAULT NULL COMMENT 'Purchase price for this inward only. Never the same field as average_cost or selling_price.',
  `batch_no` varchar(60) DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `performed_by` bigint(20) unsigned DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_date` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `updated_date` datetime DEFAULT NULL,
  `deleted_by` bigint(20) unsigned DEFAULT NULL,
  `deleted_date` datetime DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `version` int(10) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inventory_movements_uuid` (`uuid`),
  KEY `idx_inventory_movements_variant` (`product_variant_id`,`warehouse_id`,`created_date`),
  KEY `idx_inventory_movements_reference` (`reference_type`,`reference_id`),
  KEY `idx_inventory_movements_batch` (`batch_no`),
  KEY `idx_inventory_movements_type` (`movement_type`,`created_date`),
  KEY `fk_inventory_movements_warehouse` (`warehouse_id`),
  CONSTRAINT `fk_inventory_movements_variant` FOREIGN KEY (`product_variant_id`) REFERENCES `product_variants` (`id`),
  CONSTRAINT `fk_inventory_movements_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory_movements`
--

LOCK TABLES `inventory_movements` WRITE;
/*!40000 ALTER TABLE `inventory_movements` DISABLE KEYS */;
INSERT INTO `inventory_movements` VALUES (1,'695611e1-4345-4066-a99b-5b0edfe2d470',87,1,'sale','pos_sale',1,NULL,-1.000,-1.000,NULL,NULL,NULL,'POS sale POS2627000001',2,2,'2026-09-21 12:02:00',NULL,NULL,NULL,NULL,1,0,1),(2,'e55ea3d6-c126-4505-b0ad-6ddccaece0b7',78,1,'sale','pos_sale',1,NULL,-2.000,-2.000,NULL,NULL,NULL,'POS sale POS2627000001',2,2,'2026-09-21 12:02:00',NULL,NULL,NULL,NULL,1,0,1),(3,'ad844794-b7f8-40f4-b8c7-8fbaad99d4fd',75,1,'sale','pos_sale',1,NULL,-1.000,-1.000,NULL,NULL,NULL,'POS sale POS2627000001',2,2,'2026-09-21 12:02:00',NULL,NULL,NULL,NULL,1,0,1),(4,'b73e1415-511f-4ade-b952-37b22f412971',91,1,'sale','pos_sale',2,NULL,-2.000,-2.000,NULL,NULL,NULL,'POS sale POS2627000002',2,2,'2026-09-21 12:04:16',NULL,NULL,NULL,NULL,1,0,1),(5,'ae8d4a53-2459-4404-b20d-31fd4fa47162',81,1,'sale','pos_sale',2,NULL,-1.000,-1.000,NULL,NULL,NULL,'POS sale POS2627000002',2,2,'2026-09-21 12:04:16',NULL,NULL,NULL,NULL,1,0,1),(6,'cae48d96-4ab4-4fc7-b58b-3fd89b138650',99,1,'sale','pos_sale',2,NULL,-1.000,-1.000,NULL,NULL,NULL,'POS sale POS2627000002',2,2,'2026-09-21 12:04:16',NULL,NULL,NULL,NULL,1,0,1),(7,'1cd85b4f-b18a-4341-b3fe-63a353e677a2',67,1,'sale','pos_sale',2,NULL,-1.000,-1.000,NULL,NULL,NULL,'POS sale POS2627000002',2,2,'2026-09-21 12:04:16',NULL,NULL,NULL,NULL,1,0,1),(8,'b862550e-6d98-4ca0-ae86-46eb62016542',71,1,'sale','pos_sale',2,NULL,-1.000,-1.000,NULL,NULL,NULL,'POS sale POS2627000002',2,2,'2026-09-21 12:04:16',NULL,NULL,NULL,NULL,1,0,1),(9,'5d0ae6fe-47c5-49f0-aba0-adc774f26744',105,1,'sale','order',3,NULL,-1.000,-1.000,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-21 12:48:25',NULL,NULL,NULL,NULL,1,0,1),(10,'b443ea23-6579-4371-a0a9-869370fc1ecf',80,1,'sale','order',3,NULL,-2.000,-2.000,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-21 12:48:25',NULL,NULL,NULL,NULL,1,0,1),(11,'9a0b9927-4466-4ce7-9a85-55ec75c282e0',84,1,'sale','order',3,NULL,-1.000,-1.000,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-21 12:48:25',NULL,NULL,NULL,NULL,1,0,1),(12,'55a7d0e3-49e8-46a9-a334-92e1b74cc9f5',78,1,'sale','order',3,NULL,-1.000,-3.000,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-21 12:48:25',NULL,NULL,NULL,NULL,1,0,1);
/*!40000 ALTER TABLE `inventory_movements` ENABLE KEYS */;
UNLOCK TABLES;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER `trg_inventory_movements_immutable`
BEFORE UPDATE ON `inventory_movements`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'inventory_movements is an append-only ledger: post a reversing movement instead of editing one';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `inventory_batches`
--

DROP TABLE IF EXISTS `inventory_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_batches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `product_variant_id` bigint(20) unsigned NOT NULL,
  `warehouse_id` bigint(20) unsigned NOT NULL,
  `batch_no` varchar(60) NOT NULL,
  `expiry_date` date DEFAULT NULL,
  `quantity` decimal(12,3) NOT NULL DEFAULT 0.000,
  `unit_cost` decimal(12,4) DEFAULT NULL,
  `mrp` decimal(10,2) DEFAULT NULL,
  `selling_price` decimal(10,2) DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_date` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `updated_date` datetime DEFAULT NULL,
  `deleted_by` bigint(20) unsigned DEFAULT NULL,
  `deleted_date` datetime DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `version` int(10) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inventory_batches_uuid` (`uuid`),
  UNIQUE KEY `uq_inventory_batch` (`product_variant_id`,`warehouse_id`,`batch_no`),
  KEY `idx_inventory_batches_expiry` (`expiry_date`),
  KEY `fk_inventory_batches_warehouse` (`warehouse_id`),
  CONSTRAINT `fk_inventory_batches_variant` FOREIGN KEY (`product_variant_id`) REFERENCES `product_variants` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_inventory_batches_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory_batches`
--

LOCK TABLES `inventory_batches` WRITE;
/*!40000 ALTER TABLE `inventory_batches` DISABLE KEYS */;
/*!40000 ALTER TABLE `inventory_batches` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-09-21 13:06:59
