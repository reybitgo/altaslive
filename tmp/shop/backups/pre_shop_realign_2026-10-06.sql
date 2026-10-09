-- MySQL dump 10.13  Distrib 8.4.3, for Win64 (x86_64)
--
-- Host: localhost    Database: u938213108_altas6_db
-- ------------------------------------------------------
-- Server version	8.4.3

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
-- Table structure for table `products`
--

DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `products` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `sku` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `category` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `image` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `stock` int unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int NOT NULL DEFAULT '0',
  `product_pv` decimal(12,2) NOT NULL DEFAULT '0.00',
  `pv_value` decimal(12,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_products_sku` (`sku`),
  KEY `idx_products_active` (`is_active`,`sort_order`,`id`)
) ENGINE=InnoDB AUTO_INCREMENT=219 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `products`
--

LOCK TABLES `products` WRITE;
/*!40000 ALTER TABLE `products` DISABLE KEYS */;
/*!40000 ALTER TABLE `products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `carts`
--

DROP TABLE IF EXISTS `carts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `carts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `member_id` int unsigned NOT NULL,
  `status` enum('active','abandoned','converted') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `abandoned_at` datetime DEFAULT NULL,
  `active_flag` tinyint GENERATED ALWAYS AS (if((`status` = _utf8mb4'active'),1,NULL)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_carts_active_member` (`member_id`,`active_flag`),
  KEY `idx_carts_status` (`status`,`updated_at`),
  CONSTRAINT `carts_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=576 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `carts`
--

LOCK TABLES `carts` WRITE;
/*!40000 ALTER TABLE `carts` DISABLE KEYS */;
/*!40000 ALTER TABLE `carts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cart_items`
--

DROP TABLE IF EXISTS `cart_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cart_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `cart_id` int unsigned NOT NULL,
  `product_id` int unsigned NOT NULL,
  `qty` int unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cart_items_product` (`cart_id`,`product_id`),
  KEY `idx_cart_items_product` (`product_id`),
  CONSTRAINT `cart_items_ibfk_1` FOREIGN KEY (`cart_id`) REFERENCES `carts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cart_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=533 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cart_items`
--

LOCK TABLES `cart_items` WRITE;
/*!40000 ALTER TABLE `cart_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `cart_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `shop_orders`
--

DROP TABLE IF EXISTS `shop_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shop_orders` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_no` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL,
  `member_id` int unsigned NOT NULL,
  `idempotency_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payment_ref` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('pending','payment_review','payment_failed','paid','completed','cancelled','on_hold') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `version` int unsigned NOT NULL DEFAULT '1',
  `payment_method` enum('ewallet','gcash','maya','usdt_trc20','usdt_bep20') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ewallet',
  `subtotal` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `terms_version` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '1.0',
  `terms_accepted_at` datetime DEFAULT NULL,
  `payment_deadline` datetime DEFAULT NULL,
  `correction_deadline` datetime DEFAULT NULL,
  `proof_attempts` tinyint unsigned NOT NULL DEFAULT '0',
  `hold_reason` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `hold_owner_id` int unsigned DEFAULT NULL,
  `hold_deadline` datetime DEFAULT NULL,
  `resume_to` varchar(24) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `carrier` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tracking_no` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `handed_off_at` datetime DEFAULT NULL,
  `ship_to_name` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ship_to_phone` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ship_to_address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ship_to_city` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ship_to_province` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ship_to_postal` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cancel_reason_code` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cancel_note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `admin_note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_shop_orders_no` (`order_no`),
  UNIQUE KEY `uq_shop_orders_idem` (`idempotency_key`),
  UNIQUE KEY `uq_shop_orders_pref` (`payment_ref`),
  KEY `idx_shop_orders_status` (`status`,`created_at`),
  KEY `idx_shop_orders_member` (`member_id`,`created_at`),
  KEY `idx_shop_orders_deadline` (`status`,`payment_deadline`),
  KEY `hold_owner_id` (`hold_owner_id`),
  CONSTRAINT `shop_orders_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `shop_orders_ibfk_2` FOREIGN KEY (`hold_owner_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=477 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `shop_orders`
--

LOCK TABLES `shop_orders` WRITE;
/*!40000 ALTER TABLE `shop_orders` DISABLE KEYS */;
/*!40000 ALTER TABLE `shop_orders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `shop_order_items`
--

DROP TABLE IF EXISTS `shop_order_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shop_order_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int unsigned NOT NULL,
  `product_id` int unsigned DEFAULT NULL,
  `product_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `unit_price` decimal(12,2) NOT NULL,
  `qty` int unsigned NOT NULL,
  `line_total` decimal(12,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_soi_order_product` (`order_id`,`product_id`),
  KEY `idx_soi_product` (`product_id`),
  CONSTRAINT `shop_order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `shop_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `shop_order_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=479 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `shop_order_items`
--

LOCK TABLES `shop_order_items` WRITE;
/*!40000 ALTER TABLE `shop_order_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `shop_order_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `shop_order_events`
--

DROP TABLE IF EXISTS `shop_order_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shop_order_events` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int unsigned NOT NULL,
  `from_status` varchar(24) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `to_status` varchar(24) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `actor_id` int unsigned DEFAULT NULL,
  `actor_role` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reason_code` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source` enum('ui','timer','system') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ui',
  `request_id` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_shop_events_order` (`order_id`,`created_at`),
  KEY `idx_shop_events_actor` (`actor_id`,`created_at`),
  CONSTRAINT `shop_order_events_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `shop_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `shop_order_events_ibfk_2` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1293 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `shop_order_events`
--

LOCK TABLES `shop_order_events` WRITE;
/*!40000 ALTER TABLE `shop_order_events` DISABLE KEYS */;
/*!40000 ALTER TABLE `shop_order_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `shop_order_payments`
--

DROP TABLE IF EXISTS `shop_order_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shop_order_payments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int unsigned NOT NULL,
  `status` enum('awaiting_proof','proof_submitted','awaiting_funds','rejected','verified') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'awaiting_proof',
  `payment_ref` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount_expected` decimal(12,2) NOT NULL DEFAULT '0.00',
  `amount_received` decimal(12,2) DEFAULT NULL,
  `attempts` tinyint unsigned NOT NULL DEFAULT '0',
  `recheck_at` datetime DEFAULT NULL,
  `verified_by` int unsigned DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `reject_reason_code` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reject_note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_shop_payments_ref` (`payment_ref`),
  KEY `idx_shop_payments_order` (`order_id`),
  KEY `idx_shop_payments_status` (`status`,`created_at`),
  KEY `verified_by` (`verified_by`),
  CONSTRAINT `shop_order_payments_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `shop_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `shop_order_payments_ibfk_2` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=477 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `shop_order_payments`
--

LOCK TABLES `shop_order_payments` WRITE;
/*!40000 ALTER TABLE `shop_order_payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `shop_order_payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `shop_payment_proofs`
--

DROP TABLE IF EXISTS `shop_payment_proofs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shop_payment_proofs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `payment_id` int unsigned NOT NULL,
  `file_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `entered_ref` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `entered_amount` decimal(12,2) DEFAULT NULL,
  `entered_date` date DEFAULT NULL,
  `outcome` enum('submitted','accepted','rejected','superseded') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'submitted',
  `reason_code` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `uploaded_by` int unsigned NOT NULL,
  `uploaded_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `viewed_by` int unsigned DEFAULT NULL,
  `viewed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_shop_proofs_payment` (`payment_id`,`uploaded_at`),
  KEY `uploaded_by` (`uploaded_by`),
  KEY `viewed_by` (`viewed_by`),
  CONSTRAINT `shop_payment_proofs_ibfk_1` FOREIGN KEY (`payment_id`) REFERENCES `shop_order_payments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `shop_payment_proofs_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`),
  CONSTRAINT `shop_payment_proofs_ibfk_3` FOREIGN KEY (`viewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=250 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `shop_payment_proofs`
--

LOCK TABLES `shop_payment_proofs` WRITE;
/*!40000 ALTER TABLE `shop_payment_proofs` DISABLE KEYS */;
/*!40000 ALTER TABLE `shop_payment_proofs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `shop_refund_tasks`
--

DROP TABLE IF EXISTS `shop_refund_tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shop_refund_tasks` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int unsigned NOT NULL,
  `payment_id` int unsigned DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL,
  `cause` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `destination` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state` enum('open','paid','receipted','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `promised_at` datetime DEFAULT NULL,
  `approved_by` int unsigned DEFAULT NULL,
  `receipt_ref` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `receipted_at` datetime DEFAULT NULL,
  `note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_shop_refunds_order` (`order_id`),
  KEY `idx_shop_refunds_state` (`state`),
  KEY `payment_id` (`payment_id`),
  KEY `approved_by` (`approved_by`),
  CONSTRAINT `shop_refund_tasks_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `shop_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `shop_refund_tasks_ibfk_2` FOREIGN KEY (`payment_id`) REFERENCES `shop_order_payments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `shop_refund_tasks_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=59 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `shop_refund_tasks`
--

LOCK TABLES `shop_refund_tasks` WRITE;
/*!40000 ALTER TABLE `shop_refund_tasks` DISABLE KEYS */;
/*!40000 ALTER TABLE `shop_refund_tasks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `shop_reason_codes`
--

DROP TABLE IF EXISTS `shop_reason_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shop_reason_codes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `category` enum('payment','admin','refund','hold') COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_text` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `default_fault` enum('customer','shop','carrier','none') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_shop_reason_cat_code` (`category`,`code`),
  KEY `idx_shop_reason_cat` (`category`,`is_active`,`sort_order`)
) ENGINE=InnoDB AUTO_INCREMENT=88 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `shop_reason_codes`
--

LOCK TABLES `shop_reason_codes` WRITE;
/*!40000 ALTER TABLE `shop_reason_codes` DISABLE KEYS */;
INSERT INTO `shop_reason_codes` VALUES (1,'payment','ref_mismatch','Reference does not match','The reference you entered does not match our payment reference.','customer',1,10,'2026-10-05 11:09:46'),(2,'payment','amount_mismatch','Amount does not match','The amount received does not match the order total.','customer',1,20,'2026-10-05 11:09:46'),(3,'payment','unreadable_proof','Proof image unreadable','We could not read the payment proof. Please upload a clearer photo.','customer',1,30,'2026-10-05 11:09:46'),(4,'payment','no_transfer_found','No transfer received','We have not received a matching transfer yet.','none',1,40,'2026-10-05 11:09:46'),(5,'payment','duplicate_transfer','Transfer already used','This transfer reference has already been used for another order.','customer',1,50,'2026-10-05 11:09:46'),(6,'admin','out_of_stock','Out of stock','The item went out of stock and your order was cancelled.','shop',1,10,'2026-10-05 11:09:46'),(7,'admin','cannot_fulfil','Cannot fulfil','We are unable to fulfil this order at this time.','shop',1,20,'2026-10-05 11:09:46'),(8,'admin','suspected_fraud','Suspected fraud','This order was cancelled pending review.','none',1,30,'2026-10-05 11:09:46'),(9,'admin','duplicate_order','Duplicate order','This order was a duplicate of another order.','shop',1,40,'2026-10-05 11:09:46'),(10,'admin','customer_request','Cancelled at member request','Your order was cancelled.','none',1,50,'2026-10-05 11:09:46'),(11,'refund','payment_expired','Payment window expired','Your order expired before payment was received. Funds will be returned if any transfer arrives.','none',1,10,'2026-10-05 11:09:46'),(12,'refund','order_cancelled','Order cancelled','Your order was cancelled and the amount refunded.','shop',1,20,'2026-10-05 11:09:46'),(13,'refund','out_of_stock','Out of stock','The item was unavailable. Your amount is being returned.','shop',1,30,'2026-10-05 11:09:46'),(14,'refund','goodwill','Goodwill adjustment','A goodwill refund was issued for this order.','shop',1,40,'2026-10-05 11:09:46'),(29,'hold','awaiting_courier','Awaiting courier','We are confirming the delivery details with the courier.','none',1,10,'2026-10-05 11:57:57'),(30,'hold','address_confirm','Address confirmation','We are confirming your delivery address.','none',1,20,'2026-10-05 11:57:57'),(31,'hold','bank_query','Bank query open','Our payment provider has an open query on this transfer.','none',1,30,'2026-10-05 11:57:57'),(32,'hold','internal_review','Internal review','This order is under internal review.','none',1,40,'2026-10-05 11:57:57'),(33,'hold','stock_unconfirmed','Stock unconfirmed','We are confirming stock before dispatch.','none',1,50,'2026-10-05 11:57:57'),(67,'hold','proof_attempts_exhausted','Proof attempts exhausted','All payment proof attempts were used, so support will take over.','none',1,60,'2026-10-05 12:16:45');
/*!40000 ALTER TABLE `shop_reason_codes` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-10-06 16:56:11
