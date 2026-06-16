-- =============================================================
-- Landing Pages Module - PlanningBI / OKR_system
-- Database target: planni40_lp  (schema dedicado, isolado do OKR e do CRM)
-- Modelo MULTI-LANDING: todas as tabelas usam landing_id para permitir
-- reaproveitamento por futuras landing pages da PlanningBI.
-- Compatível com MySQL 5.7+ / MariaDB 10.x / InnoDB / utf8mb4_unicode_ci
-- =============================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Cada landing page é um registro aqui (multi-landing).
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `lp_landings` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug`       VARCHAR(80)  NOT NULL,
  `title`      VARCHAR(180) NOT NULL,
  `subtitle`   VARCHAR(400) DEFAULT NULL,
  `active`     TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lp_landings_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Configurações editáveis sem deploy (links PagBank, datas, vagas, preços).
-- value_type apenas documenta como a aplicação deve interpretar o valor.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `lp_settings` (
  `landing_id`    INT UNSIGNED NOT NULL,
  `setting_key`   VARCHAR(120) NOT NULL,
  `setting_value` TEXT DEFAULT NULL,
  `value_type`    ENUM('string','int','decimal','bool','json','date','url') NOT NULL DEFAULT 'string',
  `description`   VARCHAR(500) DEFAULT NULL,
  `updated_at`    DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`landing_id`, `setting_key`),
  CONSTRAINT `fk_lp_settings_landing`
    FOREIGN KEY (`landing_id`) REFERENCES `lp_landings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Cupons de desconto. O preço é SEMPRE resolvido a partir daqui (server-side).
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `lp_coupons` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `landing_id`  INT UNSIGNED NOT NULL,
  `code`        VARCHAR(60)  NOT NULL,
  `price_cents` INT UNSIGNED NOT NULL,
  `label`       VARCHAR(180) DEFAULT NULL,
  `max_uses`    INT UNSIGNED DEFAULT NULL,
  `used_count`  INT UNSIGNED NOT NULL DEFAULT 0,
  `valid_until` DATETIME     DEFAULT NULL,
  `active`      TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lp_coupons_code` (`landing_id`, `code`),
  CONSTRAINT `fk_lp_coupons_landing`
    FOREIGN KEY (`landing_id`) REFERENCES `lp_landings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Leads capturados. lead_token é público e não sequencial (usado no checkout).
-- ip_address: VARCHAR(45) (suporta IPv6) para facilitar consulta manual.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `lp_leads` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `landing_id`      INT UNSIGNED NOT NULL,
  `lead_token`      CHAR(32) NOT NULL,
  `nome`            VARCHAR(180) NOT NULL,
  `email`           VARCHAR(190) NOT NULL,
  `whatsapp`        VARCHAR(40)  NOT NULL,
  `cidade`          VARCHAR(120) DEFAULT NULL,
  `area_atuacao`    VARCHAR(120) DEFAULT NULL,
  `coupon_code`     VARCHAR(60)  DEFAULT NULL,
  `utm_source`      VARCHAR(120) DEFAULT NULL,
  `utm_medium`      VARCHAR(120) DEFAULT NULL,
  `utm_campaign`    VARCHAR(120) DEFAULT NULL,
  `referrer`        VARCHAR(400) DEFAULT NULL,
  `consent`         TINYINT(1)   NOT NULL DEFAULT 0,
  `consent_version` VARCHAR(20)  DEFAULT NULL,
  `ip_address`      VARCHAR(45)  DEFAULT NULL,
  `user_agent`      VARCHAR(255) DEFAULT NULL,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lp_leads_token` (`lead_token`),
  KEY `idx_lp_leads_landing_created` (`landing_id`, `created_at`),
  KEY `idx_lp_leads_email` (`email`),
  CONSTRAINT `fk_lp_leads_landing`
    FOREIGN KEY (`landing_id`) REFERENCES `lp_landings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Prova de consentimento (LGPD), separada e imutável (append-only).
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `lp_consents` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `lead_id`         BIGINT UNSIGNED NOT NULL,
  `consent_text`    TEXT NOT NULL,
  `consent_version` VARCHAR(20) DEFAULT NULL,
  `ip_address`      VARCHAR(45) DEFAULT NULL,
  `user_agent`      VARCHAR(255) DEFAULT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_lp_consents_lead` (`lead_id`),
  CONSTRAINT `fk_lp_consents_lead`
    FOREIGN KEY (`lead_id`) REFERENCES `lp_leads` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Eventos / tracking. metadata_json é LONGTEXT (JSON válido gravado pela app).
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `lp_events` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `landing_id`    INT UNSIGNED NOT NULL,
  `lead_id`       BIGINT UNSIGNED DEFAULT NULL,
  `event_type`    ENUM('page_view','coupon_applied','coupon_failed','lead_submit','checkout_click','checkout_blocked') NOT NULL,
  `coupon_code`   VARCHAR(60)  DEFAULT NULL,
  `utm_source`    VARCHAR(120) DEFAULT NULL,
  `utm_medium`    VARCHAR(120) DEFAULT NULL,
  `utm_campaign`  VARCHAR(120) DEFAULT NULL,
  `referrer`      VARCHAR(400) DEFAULT NULL,
  `metadata_json` LONGTEXT     DEFAULT NULL,
  `ip_address`    VARCHAR(45)  DEFAULT NULL,
  `user_agent`    VARCHAR(255) DEFAULT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_lp_events_landing_type` (`landing_id`, `event_type`, `created_at`),
  KEY `idx_lp_events_lead` (`lead_id`),
  CONSTRAINT `fk_lp_events_landing`
    FOREIGN KEY (`landing_id`) REFERENCES `lp_landings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Rate limit simples (janela fixa por bucket + IP). Sem dependência de Redis.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `lp_rate_limits` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bucket`       VARCHAR(80)  NOT NULL,
  `ip_address`   VARCHAR(45)  NOT NULL,
  `hits`         INT UNSIGNED NOT NULL DEFAULT 0,
  `window_start` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lp_rate_bucket_ip` (`bucket`, `ip_address`),
  KEY `idx_lp_rate_window` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================
-- SEED — Landing "IA Aplicada ao Dia a Dia Financeiro"
-- Idempotente: usa INSERT ... ON DUPLICATE KEY / IGNORE.
-- =============================================================

INSERT INTO `lp_landings` (`slug`, `title`, `subtitle`, `active`)
VALUES (
  'ia-financeiro',
  'IA Aplicada ao Dia a Dia Financeiro',
  'Treinamento presencial e prático para profissionais financeiros e administrativos que querem ganhar produtividade, melhorar controles e se diferenciar no mercado.',
  1
)
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`);

-- Settings (não sobrescreve valores já preenchidos por você: usa INSERT IGNORE).
INSERT IGNORE INTO `lp_settings` (`landing_id`, `setting_key`, `setting_value`, `value_type`, `description`)
SELECT l.`id`, s.`k`, s.`v`, s.`t`, s.`d`
FROM `lp_landings` l
JOIN (
  SELECT 'pagbank_url_oficial'  AS k, ''       AS v, 'url'     AS t, 'Link PagBank do valor oficial (R$ 297,00). Preencher manualmente.' AS d
  UNION ALL SELECT 'pagbank_url_desconto', '',       'url',     'Link PagBank do valor com cupom (R$ 147,00). Preencher manualmente.'
  UNION ALL SELECT 'official_price_cents', '29700',  'int',     'Valor oficial em centavos.'
  UNION ALL SELECT 'discount_price_cents', '14700',  'int',     'Valor com cupom em centavos (referência exibida).'
  UNION ALL SELECT 'checkout_enabled',     '0',      'bool',    'Habilita o redirecionamento para o PagBank (0=off, 1=on).'
  UNION ALL SELECT 'btn_text_oficial',     'Garantir minha vaga', 'string', 'Texto do botão de pagamento (valor oficial).'
  UNION ALL SELECT 'btn_text_desconto',    'Garantir minha vaga com desconto', 'string', 'Texto do botão de pagamento (com cupom).'
  UNION ALL SELECT 'training_date',        '',       'date',    'Data do treinamento (ex.: 12/07/2026).'
  UNION ALL SELECT 'training_time',        '',       'string',  'Horário do treinamento (ex.: 09h00 às 13h00).'
  UNION ALL SELECT 'training_location',    '',       'string',  'Local do treinamento (endereço/cidade).'
  UNION ALL SELECT 'spots_total',          '',       'int',     'Total de vagas (opcional, apenas referência interna).'
  UNION ALL SELECT 'spots_status_text',    'Turma limitada para garantir prática assistida.', 'string', 'Texto configurável do bloco de vagas.'
) s ON l.`slug` = 'ia-financeiro';

-- Cupom inicial LOPA-ENTREVISTAS (R$ 147,00).
INSERT INTO `lp_coupons` (`landing_id`, `code`, `price_cents`, `label`, `max_uses`, `valid_until`, `active`)
SELECT l.`id`, 'LOPA-ENTREVISTAS', 14700, 'Valor especial liberado', NULL, NULL, 1
FROM `lp_landings` l
WHERE l.`slug` = 'ia-financeiro'
ON DUPLICATE KEY UPDATE `price_cents` = VALUES(`price_cents`), `active` = VALUES(`active`);
