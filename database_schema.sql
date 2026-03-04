-- Compiled SQL Database Schema
-- Complete schema for cspc-rms application
-- ============================================================================

-- ============================================================================
-- Account Table
-- ============================================================================
CREATE TABLE IF NOT EXISTS `account` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uname` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `emailadd` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `disabled` tinyint(1) NOT NULL DEFAULT '0',
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `account_uname_unique` (`uname`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Office Table
-- ============================================================================
CREATE TABLE IF NOT EXISTS `office` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Category Table
-- ============================================================================
CREATE TABLE IF NOT EXISTS `category` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Status Table
-- ============================================================================
CREATE TABLE IF NOT EXISTS `status` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `barcode_value` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `office_id` bigint(20) unsigned DEFAULT NULL,
  `date_in` datetime DEFAULT NULL,
  `date_out` datetime DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `originating_office` bigint(20) unsigned DEFAULT NULL,
  `forwarded_by` bigint(20) unsigned DEFAULT NULL,
  `received_by` bigint(20) unsigned DEFAULT NULL,
  `flow` int(11) NOT NULL DEFAULT '0',
  `chrono` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `status_office_id_foreign` (`office_id`),
  KEY `status_originating_office_foreign` (`originating_office`),
  KEY `status_forwarded_by_foreign` (`forwarded_by`),
  KEY `status_received_by_foreign` (`received_by`),
  CONSTRAINT `status_forwarded_by_foreign` FOREIGN KEY (`forwarded_by`) REFERENCES `account` (`id`) ON DELETE SET NULL,
  CONSTRAINT `status_office_id_foreign` FOREIGN KEY (`office_id`) REFERENCES `office` (`id`) ON DELETE SET NULL,
  CONSTRAINT `status_originating_office_foreign` FOREIGN KEY (`originating_office`) REFERENCES `office` (`id`) ON DELETE SET NULL,
  CONSTRAINT `status_received_by_foreign` FOREIGN KEY (`received_by`) REFERENCES `account` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Transaction Nature Table
-- ============================================================================
CREATE TABLE IF NOT EXISTS `transaction_nature` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `office_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `transaction_nature_office_id_foreign` (`office_id`),
  CONSTRAINT `transaction_nature_office_id_foreign` FOREIGN KEY (`office_id`) REFERENCES `office` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Transaction Flow Table
-- ============================================================================
CREATE TABLE IF NOT EXISTS `transaction_flow` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `transaction_nature_id` bigint(20) unsigned DEFAULT NULL,
  `office_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `transaction_flow_transaction_nature_id_foreign` (`transaction_nature_id`),
  KEY `transaction_flow_office_id_foreign` (`office_id`),
  CONSTRAINT `transaction_flow_office_id_foreign` FOREIGN KEY (`office_id`) REFERENCES `office` (`id`) ON DELETE CASCADE,
  CONSTRAINT `transaction_flow_transaction_nature_id_foreign` FOREIGN KEY (`transaction_nature_id`) REFERENCES `transaction_nature` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Record Table
-- ============================================================================
CREATE TABLE IF NOT EXISTS `record` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `barcode` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Office Records Table
-- ============================================================================
CREATE TABLE IF NOT EXISTS `office_records` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `office_id` bigint(20) unsigned NOT NULL,
  `record_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `office_records_office_id_foreign` (`office_id`),
  KEY `office_records_record_id_foreign` (`record_id`),
  CONSTRAINT `office_records_office_id_foreign` FOREIGN KEY (`office_id`) REFERENCES `office` (`id`) ON DELETE CASCADE,
  CONSTRAINT `office_records_record_id_foreign` FOREIGN KEY (`record_id`) REFERENCES `record` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Office Category Table
-- ============================================================================
CREATE TABLE IF NOT EXISTS `office_category` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `office_id` bigint(20) unsigned NOT NULL,
  `category_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `office_category_office_id_foreign` (`office_id`),
  KEY `office_category_category_id_foreign` (`category_id`),
  CONSTRAINT `office_category_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `category` (`id`) ON DELETE CASCADE,
  CONSTRAINT `office_category_office_id_foreign` FOREIGN KEY (`office_id`) REFERENCES `office` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Transaction Table
-- ============================================================================
CREATE TABLE IF NOT EXISTS `transaction` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `Barcode` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `transaction_barcode_unique` (`Barcode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Barcoded Documents Table
-- ============================================================================
CREATE TABLE IF NOT EXISTS `barcoded_documents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `Barcode` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `completed` tinyint(1) NOT NULL DEFAULT '0',
  `Date_created` datetime DEFAULT NULL,
  `requestorid` bigint(20) unsigned DEFAULT NULL,
  `current_office` bigint(20) unsigned DEFAULT NULL,
  `nature_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `barcoded_documents_barcode_unique` (`Barcode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Transaction CF Table
-- ============================================================================
CREATE TABLE IF NOT EXISTS `transaction_cf` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `barcode_value` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `office_id` bigint(20) unsigned DEFAULT NULL,
  `date_in` datetime DEFAULT NULL,
  `received_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `transaction_cf_office_id_foreign` (`office_id`),
  KEY `transaction_cf_received_by_foreign` (`received_by`),
  CONSTRAINT `transaction_cf_office_id_foreign` FOREIGN KEY (`office_id`) REFERENCES `office` (`id`) ON DELETE SET NULL,
  CONSTRAINT `transaction_cf_received_by_foreign` FOREIGN KEY (`received_by`) REFERENCES `account` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Nature of Transaction Table
-- ============================================================================
CREATE TABLE IF NOT EXISTS `nature_of_transaction` (
  `Nature_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `office_id` bigint(20) unsigned DEFAULT NULL,
  `isfreeflow` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`Nature_id`),
  KEY `nature_of_transaction_office_id_foreign` (`office_id`),
  CONSTRAINT `nature_of_transaction_office_id_foreign` FOREIGN KEY (`office_id`) REFERENCES `office` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Categories Table
-- ============================================================================
CREATE TABLE IF NOT EXISTS `categories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `parent_id` bigint(20) unsigned DEFAULT NULL,
  `type` int(11) NOT NULL DEFAULT '1',
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `categories_parent_id_foreign` (`parent_id`),
  CONSTRAINT `categories_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- End of Database Schema
-- ============================================================================
