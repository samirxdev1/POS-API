-- ============================================================================
-- UltimatePOS REST API: Database Schema for api_tokens & api_logs
-- Engine: MySQL / MariaDB (InnoDB, UTF8mb4)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `api_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `business_id` INT UNSIGNED NULL DEFAULT NULL COMMENT 'Associated business ID if restricted',
  `user_id` INT UNSIGNED NULL DEFAULT NULL COMMENT 'Associated user ID for auditing actions',
  `name` VARCHAR(191) NOT NULL COMMENT 'Descriptive name or agent identifier',
  `token_hash` VARCHAR(64) NOT NULL UNIQUE COMMENT 'SHA-256 hash of the 64-char hex Bearer token',
  `permissions` JSON NULL DEFAULT NULL COMMENT 'Optional scope array e.g. ["*"] or ["products:read"]',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = active, 0 = revoked',
  `last_used_at` TIMESTAMP NULL DEFAULT NULL,
  `expires_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `api_tokens_token_hash_idx` (`token_hash`),
  INDEX `api_tokens_is_active_idx` (`is_active`),
  INDEX `api_tokens_business_id_idx` (`business_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `api_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `token_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `business_id` INT UNSIGNED NULL DEFAULT NULL,
  `endpoint` VARCHAR(255) NOT NULL,
  `method` VARCHAR(10) NOT NULL,
  `status_code` INT NOT NULL,
  `ip_address` VARCHAR(45) NULL DEFAULT NULL,
  `user_agent` VARCHAR(255) NULL DEFAULT NULL,
  `duration_ms` DECIMAL(8, 2) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `api_logs_token_id_idx` (`token_id`),
  INDEX `api_logs_endpoint_idx` (`endpoint`),
  INDEX `api_logs_status_code_idx` (`status_code`),
  INDEX `api_logs_created_at_idx` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

