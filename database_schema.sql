-- ============================================================
--  Einkauf-Portal – Vollständiges Datenbankschema
--  Erstellt: 2026-05-15
--  Datenbank: nicosdev_einkauf (UTF-8 / utf8mb4_unicode_ci)
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
--  BENUTZER
-- ============================================================
CREATE TABLE IF NOT EXISTS `users` (
    `id`                   INT            NOT NULL AUTO_INCREMENT,
    `family_name`          VARCHAR(255)   NOT NULL,
    `email`                VARCHAR(255)   NULL DEFAULT NULL,
    `pin_hash`             VARCHAR(255)   NULL DEFAULT NULL,
    `recovery_code_hash`   VARCHAR(255)   NULL DEFAULT NULL,
    `role`                 VARCHAR(50)    NOT NULL DEFAULT 'family',
    `rank`                 TINYINT        NOT NULL DEFAULT 1,
    `avatar`               VARCHAR(500)   NULL DEFAULT NULL,
    `theme_mode`           VARCHAR(10)    NOT NULL DEFAULT 'auto',
    -- Rabatte & Gebühren
    `discount_percent`     INT            NOT NULL DEFAULT 0,
    `service_fee_waived`   TINYINT(1)     NOT NULL DEFAULT 0,
    `shopping_fee_waived`  TINYINT(1)     NOT NULL DEFAULT 0,
    -- Status
    `is_locked`            TINYINT(1)     NOT NULL DEFAULT 0,
    `created_at`           DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_family_name` (`family_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  REMEMBER-ME TOKENS (Dauerhaftes Login)
-- ============================================================
CREATE TABLE IF NOT EXISTS `remember_tokens` (
    `id`          INT           NOT NULL AUTO_INCREMENT,
    `user_id`     INT           NOT NULL,
    `selector`    VARCHAR(64)   NOT NULL,
    `token_hash`  VARCHAR(255)  NOT NULL,
    `expires_at`  DATETIME      NOT NULL,
    `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_selector` (`selector`),
    INDEX `idx_user`     (`user_id`),
    INDEX `idx_selector` (`selector`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  APP-EINSTELLUNGEN (Key-Value-Store)
-- ============================================================
CREATE TABLE IF NOT EXISTS `app_settings` (
    `setting_key`    VARCHAR(100)  NOT NULL,
    `setting_value`  TEXT          NULL,
    `updated_at`     DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  PRODUKTKATEGORIEN
-- ============================================================
CREATE TABLE IF NOT EXISTS `product_categories` (
    `id`          INT           NOT NULL AUTO_INCREMENT,
    `name`        VARCHAR(255)  NOT NULL,
    `active`      TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  PRODUKTE
-- ============================================================
CREATE TABLE IF NOT EXISTS `products` (
    `id`           INT           NOT NULL AUTO_INCREMENT,
    `category_id`  INT           NULL DEFAULT NULL,
    `name`         VARCHAR(255)  NOT NULL,
    `unit`         VARCHAR(50)   NOT NULL DEFAULT 'Stk.',
    `price`        INT           NOT NULL DEFAULT 0   COMMENT 'Preis in Cent',
    `active`       TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_category` (`category_id`),
    INDEX `idx_active`   (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  BESTELLTERMINE
-- ============================================================
CREATE TABLE IF NOT EXISTS `order_dates` (
    `id`           INT           NOT NULL AUTO_INCREMENT,
    `title`        VARCHAR(255)  NOT NULL,
    `deadline_at`  DATETIME      NULL DEFAULT NULL,
    `archived`     TINYINT(1)   NOT NULL DEFAULT 0,
    `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_archived`   (`archived`),
    INDEX `idx_deadline`   (`deadline_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  BESTELLGRUPPEN (eine pro Benutzer & Termin)
-- ============================================================
CREATE TABLE IF NOT EXISTS `order_groups` (
    `id`             INT      NOT NULL AUTO_INCREMENT,
    `user_id`        INT      NOT NULL,
    `order_date_id`  INT      NULL DEFAULT NULL,
    `subtotal`       INT      NOT NULL DEFAULT 0   COMMENT 'Cent',
    `service_fee`    INT      NOT NULL DEFAULT 0   COMMENT 'Cent',
    `shopping_fee`   INT      NOT NULL DEFAULT 0   COMMENT 'Cent',
    `total`          INT      NOT NULL DEFAULT 0   COMMENT 'Cent',
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_user`        (`user_id`),
    INDEX `idx_order_date`  (`order_date_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  BESTELLPOSITIONEN
-- ============================================================
CREATE TABLE IF NOT EXISTS `order_items` (
    `id`              INT            NOT NULL AUTO_INCREMENT,
    `order_group_id`  INT            NOT NULL,
    `product_id`      INT            NULL DEFAULT NULL,
    `product_name`    VARCHAR(255)   NOT NULL,
    `unit`            VARCHAR(50)    NOT NULL DEFAULT 'Stk.',
    `price`           INT            NOT NULL DEFAULT 0   COMMENT 'Cent',
    `quantity`        DECIMAL(10,2)  NOT NULL DEFAULT 1,
    `row_total`       INT            NOT NULL DEFAULT 0   COMMENT 'Cent',
    `created_at`      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_order_group` (`order_group_id`),
    INDEX `idx_product`     (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  RECHNUNGEN
-- ============================================================
CREATE TABLE IF NOT EXISTS `invoices` (
    `id`                    INT           NOT NULL AUTO_INCREMENT,
    `invoice_number`        VARCHAR(50)   NOT NULL,
    `public_token`          VARCHAR(64)   NOT NULL,
    `order_group_id`        INT           NULL DEFAULT NULL,
    `order_date_id`         INT           NULL DEFAULT NULL,
    `user_id`               INT           NOT NULL,
    `subtotal`              INT           NOT NULL DEFAULT 0   COMMENT 'Cent',
    `service_fee`           INT           NOT NULL DEFAULT 0   COMMENT 'Cent',
    `shopping_fee`          INT           NOT NULL DEFAULT 0   COMMENT 'Cent',
    `total`                 INT           NOT NULL DEFAULT 0   COMMENT 'Cent',
    `status`                ENUM('offen','teilbezahlt','bezahlt','überfällig','storniert')
                            NOT NULL DEFAULT 'offen',
    `created_by`            INT           NULL DEFAULT NULL    COMMENT 'Admin-User-ID',
    `payment_reported_at`   DATETIME      NULL DEFAULT NULL,
    `created_at`            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_invoice_number` (`invoice_number`),
    UNIQUE KEY `uq_public_token`   (`public_token`),
    INDEX `idx_user`           (`user_id`),
    INDEX `idx_order_group`    (`order_group_id`),
    INDEX `idx_order_date`     (`order_date_id`),
    INDEX `idx_status`         (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  RECHNUNGSPOSITIONEN
-- ============================================================
CREATE TABLE IF NOT EXISTS `invoice_items` (
    `id`                INT            NOT NULL AUTO_INCREMENT,
    `invoice_id`        INT            NOT NULL,
    `product_id`        INT            NULL DEFAULT NULL,
    `product_name`      VARCHAR(255)   NOT NULL,
    `unit`              VARCHAR(50)    NOT NULL DEFAULT 'Stk.',
    `original_price`    INT            NOT NULL DEFAULT 0   COMMENT 'Cent zum Zeitpunkt der Bestellung',
    `original_quantity` DECIMAL(10,2)  NOT NULL DEFAULT 1,
    `price`             INT            NOT NULL DEFAULT 0   COMMENT 'Abrechnungspreis in Cent',
    `quantity`          DECIMAL(10,2)  NOT NULL DEFAULT 1,
    `row_total`         INT            NOT NULL DEFAULT 0   COMMENT 'Cent',
    `created_at`        DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_invoice` (`invoice_id`),
    INDEX `idx_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  SUPPORT TICKETS
-- ============================================================
CREATE TABLE IF NOT EXISTS `support_tickets` (
    `id`          INT           NOT NULL AUTO_INCREMENT,
    `user_id`     INT           NOT NULL,
    `type`        ENUM('bug','feedback','sonstiges') NOT NULL DEFAULT 'sonstiges',
    `subject`     VARCHAR(255)  NOT NULL,
    `message`     TEXT          NOT NULL,
    `status`      ENUM('offen','in_bearbeitung','geschlossen') NOT NULL DEFAULT 'offen',
    `priority`    ENUM('niedrig','normal','hoch') NOT NULL DEFAULT 'normal',
    `admin_note`  TEXT          NULL,
    `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
    `closed_at`   DATETIME      NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_user`   (`user_id`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  BENACHRICHTIGUNGEN
-- ============================================================
CREATE TABLE IF NOT EXISTS `notifications` (
    `id`          INT           NOT NULL AUTO_INCREMENT,
    `user_id`     INT           NOT NULL,
    `type`        VARCHAR(50)   NOT NULL DEFAULT 'info',
    `title`       VARCHAR(255)  NOT NULL,
    `message`     TEXT          NULL,
    `link`        VARCHAR(500)  NULL,
    `is_read`     TINYINT(1)   NOT NULL DEFAULT 0,
    `read_at`     DATETIME      NULL,
    `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_user_unread` (`user_id`, `is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  AKTIVITÄTSLOGS
-- ============================================================
CREATE TABLE IF NOT EXISTS `activity_logs` (
    `id`           INT           NOT NULL AUTO_INCREMENT,
    `user_id`      INT           NULL,
    `user_name`    VARCHAR(255)  NULL,
    `action`       TEXT          NOT NULL,
    `ip`           VARCHAR(45)   NULL,
    `user_agent`   VARCHAR(500)  NULL,
    `category`     VARCHAR(50)   NULL,
    `severity`     VARCHAR(20)   NULL DEFAULT 'info',
    `target_type`  VARCHAR(50)   NULL,
    `target_id`    INT           NULL,
    `meta`         JSON          NULL,
    `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_user`    (`user_id`),
    INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  INITIALDATEN
--  Erster Admin-Account: Benutzername "Admin", PIN 1234
--  WICHTIG: PIN sofort nach dem ersten Login ändern!
-- ============================================================
INSERT INTO `users`
    (`family_name`, `role`, `rank`, `pin_hash`, `discount_percent`, `service_fee_waived`, `shopping_fee_waived`)
VALUES
    ('Admin', 'admin', 6, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 0, 0, 0);

-- ============================================================
--  Standard-Einstellungen
-- ============================================================
INSERT INTO `app_settings` (`setting_key`, `setting_value`)
VALUES ('maintenance_mode', '0')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
--  HINWEIS: Preise werden in CENT gespeichert.
--  Beispiel: 2,50 € = 250 (Cent)
--
--  Rollen / Ranks:
--    1 = Benutzer
--    2 = Erweitert
--    3 = Leitung
--    4 = Admin
--    5 = Administrator
--    6 = WebDev
--
--  Login nach Import:
--    Benutzername: Admin
--    PIN:          1234
--    → PIN sofort ändern!
-- ============================================================
