-- =============================================================
-- LP_IA / Landing Pages — Pagamentos (webhook PagBank)
-- Aplicar no schema planni40_lp. Idempotente.
-- =============================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lp_payments` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `landing_id`       INT UNSIGNED NOT NULL,
  `lead_id`          BIGINT UNSIGNED DEFAULT NULL,
  `provider`         VARCHAR(20)  NOT NULL DEFAULT 'pagbank',
  `order_id`         VARCHAR(80)  DEFAULT NULL,
  `charge_id`        VARCHAR(80)  DEFAULT NULL,
  `reference_id`     VARCHAR(120) DEFAULT NULL,
  `status`           VARCHAR(30)  NOT NULL,
  `amount_cents`     INT UNSIGNED DEFAULT NULL,
  `customer_name`    VARCHAR(180) DEFAULT NULL,
  `customer_email`   VARCHAR(190) DEFAULT NULL,
  `customer_tax_id`  VARCHAR(20)  DEFAULT NULL,
  `raw_json`         LONGTEXT     DEFAULT NULL,
  `buyer_notified_at` DATETIME    DEFAULT NULL,
  `admin_notified_at` DATETIME    DEFAULT NULL,
  `paid_at`          DATETIME     DEFAULT NULL,
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lp_payments_charge` (`charge_id`),
  KEY `idx_lp_payments_email` (`customer_email`),
  KEY `idx_lp_payments_landing` (`landing_id`, `created_at`),
  CONSTRAINT `fk_lp_payments_landing`
    FOREIGN KEY (`landing_id`) REFERENCES `lp_landings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
