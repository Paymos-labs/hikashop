CREATE TABLE IF NOT EXISTS `#__hikashop_paymos_installation` (
    `id` TINYINT UNSIGNED NOT NULL,
    `installation_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__hikashop_paymos_connection` (
    `method_id` INT UNSIGNED NOT NULL,
    `mode` VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'sandbox',
    `credentials_envelope` TEXT NULL,
    `device_flow_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    `device_user_id` INT UNSIGNED NULL,
    `device_envelope` TEXT NULL,
    `device_expires_at` BIGINT UNSIGNED NULL,
    `device_poll_at` BIGINT UNSIGNED NULL,
    PRIMARY KEY (`method_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__hikashop_paymos_attempt` (
    `order_id` INT UNSIGNED NOT NULL,
    `generation` INT UNSIGNED NOT NULL,
    `method_id` INT UNSIGNED NOT NULL,
    `environment` VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `project_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `external_order_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `invoice_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
    `amount` DECIMAL(17,5) NOT NULL,
    `currency` VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `payment_url` TEXT NULL,
    `status` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'creating',
    `review_reason` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
    PRIMARY KEY (`order_id`, `generation`),
    UNIQUE KEY `external_order` (`external_order_id`),
    UNIQUE KEY `invoice` (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__hikashop_paymos_event` (
    `method_id` INT UNSIGNED NOT NULL,
    `event_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `token` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `lease_until` BIGINT UNSIGNED NOT NULL,
    `expires_at` BIGINT UNSIGNED NOT NULL,
    `committed` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`method_id`, `event_id`),
    KEY `expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__hikashop_paymos_effect` (
    `order_id` INT UNSIGNED NOT NULL,
    `invoice_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `effect` VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `marker` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (`order_id`, `invoice_id`, `effect`),
    UNIQUE KEY `marker` (`marker`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__hikashop_paymos_refund` (
    `operation_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `installation_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `request_token` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `payment_key` VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `external_order_id` VARCHAR(200) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `withdrawal_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
    `order_id` INT UNSIGNED NOT NULL,
    `method_id` INT UNSIGNED NOT NULL,
    `invoice_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `payload` LONGTEXT NOT NULL,
    `created_at` BIGINT UNSIGNED NOT NULL,
    `next_due` BIGINT UNSIGNED NOT NULL,
    `poll_count` INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`operation_id`),
    UNIQUE KEY `request_token` (`installation_id`, `request_token`),
    UNIQUE KEY `external_order` (`external_order_id`),
    UNIQUE KEY `withdrawal` (`withdrawal_id`),
    KEY `payment` (`payment_key`),
    KEY `invoice` (`invoice_id`),
    KEY `due` (`next_due`, `operation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__hikashop_paymos_refund_projection` (
    `operation_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `order_id` INT UNSIGNED NOT NULL,
    `history_id` INT UNSIGNED NULL,
    `review_history_id` INT UNSIGNED NULL,
    `marker` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `result` LONGTEXT NOT NULL,
    PRIMARY KEY (`operation_id`),
    UNIQUE KEY `history` (`history_id`),
    UNIQUE KEY `review_history` (`review_history_id`),
    UNIQUE KEY `marker` (`marker`),
    KEY `order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__hikashop_paymos_refund_effect` (
    `operation_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `kind` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `generation` INT UNSIGNED NOT NULL,
    `state` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (`operation_id`, `kind`, `generation`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
