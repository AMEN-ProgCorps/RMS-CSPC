-- Compiled SQL from all Laravel migrations
-- Generated from database/migrations directory
-- This file represents the complete database schema

-- ============================================================================
-- Migration: 2014_10_12_000000_create_users_table
-- ============================================================================
-- CREATE TABLE IF NOT EXISTS `users` (
--   `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
--   `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
--   `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
--   `email_verified_at` timestamp NULL DEFAULT NULL,
--   `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
--   `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
--   `created_at` timestamp NULL DEFAULT NULL,
--   `updated_at` timestamp NULL DEFAULT NULL,
--   PRIMARY KEY (`id`),
--   UNIQUE KEY `users_email_unique` (`email`)
-- ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Migration: 2014_10_12_100000_create_password_resets_table
-- ============================================================================
-- CREATE TABLE IF NOT EXISTS `password_resets` (
--   `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
--   `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
--   `created_at` timestamp NULL DEFAULT NULL,
--   KEY `password_resets_email_index` (`email`)
-- ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Migration: 2020_06_16_121335_create_record_histories_table
-- ============================================================================
-- CREATE TABLE IF NOT EXISTS `record_histories` (
--   `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
--   `created_at` timestamp NULL DEFAULT NULL,
--   `updated_at` timestamp NULL DEFAULT NULL,
--   PRIMARY KEY (`id`)
-- ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Migration: 2020_07_10_220412_create_schools_table
-- ============================================================================
-- CREATE TABLE IF NOT EXISTS `schools` (
--   `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
--   `created_at` timestamp NULL DEFAULT NULL,
--   `updated_at` timestamp NULL DEFAULT NULL,
--   PRIMARY KEY (`id`)
-- ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Migration: 2020_08_01_000000_create_account_table
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
-- Migration: 2020_08_02_000000_create_office_table
-- ============================================================================
CREATE TABLE IF NOT EXISTS `office` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Migration: 2020_08_03_000000_create_category_table
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
-- Migration: 2020_08_04_000000_create_status_table
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
-- KEY indexes are database indexes used to optimize query performance on frequently searched or joined columns.
-- Unlike PRIMARY KEY (unique identifier) or FOREIGN KEY (referential integrity constraint),
-- a KEY (or INDEX) is simply a performance optimization that speeds up SELECT queries and WHERE clauses.
-- In this example, indexes are created on office_id, originating_office, forwarded_by, and received_by
-- to accelerate lookups and joins on these columns without enforcing uniqueness or referential constraints.
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
-- Migration: 2020_08_05_000000_create_transaction_nature_table
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
-- Migration: 2020_08_06_000000_create_transaction_flow_table
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
-- Migration: 2020_08_07_000000_create_record_table
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
-- Migration: 2020_08_08_000000_create_office_records_table
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
-- Migration: 2020_08_09_000000_create_office_category_table
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
-- Migration: 2020_08_10_000000_create_transaction_table
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
-- Migration: 2020_08_11_000000_create_barcoded_documents_table
-- ============================================================================
CREATE TABLE IF NOT EXISTS `barcoded_documents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `Barcode` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `completed` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `barcoded_documents_barcode_unique` (`Barcode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Migration: 2020_08_12_000000_update_barcoded_documents_table
-- ============================================================================
-- Add new columns to barcoded_documents table
ALTER TABLE `barcoded_documents` 
ADD COLUMN IF NOT EXISTS `Date_created` datetime DEFAULT NULL,
ADD COLUMN IF NOT EXISTS `requestorid` bigint(20) unsigned DEFAULT NULL,
ADD COLUMN IF NOT EXISTS `current_office` bigint(20) unsigned DEFAULT NULL,
ADD COLUMN IF NOT EXISTS `nature_id` bigint(20) unsigned DEFAULT NULL;

-- ============================================================================
-- Migration: 2020_08_13_000000_add_flow_and_chrono_to_status_table
-- ============================================================================
-- Add new columns to status table
ALTER TABLE `status` 
ADD COLUMN IF NOT EXISTS `flow` int(11) NOT NULL DEFAULT '0',
ADD COLUMN IF NOT EXISTS `chrono` int(11) DEFAULT NULL;

-- ============================================================================
-- Migration: 2020_08_14_000000_create_transaction_cf_table
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
-- Migration: 2020_08_15_000000_add_description_to_office_table
-- ============================================================================
-- Add description column to office table
ALTER TABLE `office` 
ADD COLUMN IF NOT EXISTS `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `name`;

-- ============================================================================
-- Migration: 2020_08_16_000000_create_nature_of_transaction_table
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
-- Migration: 2020_08_17_000000_create_categories_table
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
-- Database Schema Complete
-- ============================================================================
