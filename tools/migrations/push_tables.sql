-- =============================================================
-- Push Notifications Module — DDL
-- OKR System / PlanningBI
-- Compativel com MySQL 5.7+ / InnoDB / utf8mb4_unicode_ci
-- =============================================================

-- 1) push_devices — tokens FCM dos dispositivos
CREATE TABLE IF NOT EXISTS `push_devices` (
  `id_device`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_user`                INT(11)         NOT NULL,
  `id_company`             INT(10) UNSIGNED DEFAULT NULL,
  `platform`               ENUM('android','ios','web') NOT NULL DEFAULT 'android',
  `push_provider`          VARCHAR(20)     NOT NULL DEFAULT 'fcm',
  `token`                  TEXT            NOT NULL,
  `token_hash`             CHAR(64)        NOT NULL COMMENT 'SHA-256 do token para unicidade',
  `app_version`            VARCHAR(30)     DEFAULT NULL,
  `os_version`             VARCHAR(30)     DEFAULT NULL,
  `device_model`           VARCHAR(80)     DEFAULT NULL,
  `locale`                 VARCHAR(10)     DEFAULT NULL,
  `timezone`               VARCHAR(60)     DEFAULT NULL,
  `notifications_enabled`  TINYINT(1)      NOT NULL DEFAULT 1,
  `last_seen_at`           DATETIME        DEFAULT NULL,
  `last_token_refresh_at`  DATETIME        DEFAULT NULL,
  `is_active`              TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at`             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`             DATETIME        DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_device`),
  UNIQUE KEY `uq_push_devices_token_hash` (`token_hash`),
  KEY `idx_push_devices_user`     (`id_user`),
  KEY `idx_push_devices_company`  (`id_company`),
  KEY `idx_push_devices_active`   (`is_active`, `notifications_enabled`),
  CONSTRAINT `fk_push_devices_user`    FOREIGN KEY (`id_user`)    REFERENCES `usuarios`(`id_user`) ON DELETE CASCADE,
  CONSTRAINT `fk_push_devices_company` FOREIGN KEY (`id_company`) REFERENCES `company`(`id_company`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) push_assets — imagens de campanhas
CREATE TABLE IF NOT EXISTS `push_assets` (
  `id_asset`       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `original_name`  VARCHAR(255)    NOT NULL,
  `mime_type`      VARCHAR(50)     NOT NULL,
  `ext`            VARCHAR(10)     NOT NULL,
  `path`           VARCHAR(500)    NOT NULL COMMENT 'caminho relativo em /uploads/push/',
  `public_url`     VARCHAR(500)    DEFAULT NULL,
  `width`          SMALLINT UNSIGNED NOT NULL,
  `height`         SMALLINT UNSIGNED NOT NULL,
  `size_bytes`     INT UNSIGNED    NOT NULL,
  `sha256_hash`    CHAR(64)        NOT NULL,
  `created_by`     INT(11)         NOT NULL,
  `created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_asset`),
  KEY `idx_push_assets_hash` (`sha256_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) push_segments — segmentos reutilizaveis
CREATE TABLE IF NOT EXISTS `push_segments` (
  `id_segment`   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nome`         VARCHAR(120)    NOT NULL,
  `descricao`    VARCHAR(500)    DEFAULT NULL,
  `filters_json` LONGTEXT        NOT NULL COMMENT 'JSON com filtros combinados',
  `created_by`   INT(11)         NOT NULL,
  `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME        DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_segment`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) push_campaigns — campanhas de push
CREATE TABLE IF NOT EXISTS `push_campaigns` (
  `id_campaign`       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nome_interno`      VARCHAR(200)    NOT NULL,
  `canal`             ENUM('push','inbox','push_inbox') NOT NULL DEFAULT 'push',
  `categoria`         VARCHAR(60)     DEFAULT NULL,
  `titulo`            VARCHAR(200)    NOT NULL,
  `descricao`         TEXT            NOT NULL,
  `image_asset_id`    BIGINT UNSIGNED DEFAULT NULL,
  `route`             VARCHAR(200)    DEFAULT NULL COMMENT 'deep link / rota interna do app',
  `url_web`           VARCHAR(500)    DEFAULT NULL,
  `priority`          ENUM('normal','high') NOT NULL DEFAULT 'normal',
  `status`            ENUM('draft','scheduled','sending','sent','error','cancelled') NOT NULL DEFAULT 'draft',
  `scheduled_at`      DATETIME        DEFAULT NULL,
  `timezone`          VARCHAR(60)     NOT NULL DEFAULT 'America/Sao_Paulo',
  `is_recurring`      TINYINT(1)      NOT NULL DEFAULT 0,
  `recurrence_rule`   VARCHAR(200)    DEFAULT NULL COMMENT 'ex: weekly:mon,wed|monthly:15',
  `next_recurrence_at` DATETIME       DEFAULT NULL,
  `prompt_ia`         TEXT            DEFAULT NULL,
  `audience_estimate` INT UNSIGNED    DEFAULT NULL,
  `filters_json`      LONGTEXT        DEFAULT NULL,
  `id_segment`        BIGINT UNSIGNED DEFAULT NULL,
  `created_by`        INT(11)         NOT NULL,
  `updated_by`        INT(11)         DEFAULT NULL,
  `approved_by`       INT(11)         DEFAULT NULL,
  `sent_at`           DATETIME        DEFAULT NULL,
  `cancelled_at`      DATETIME        DEFAULT NULL,
  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME        DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_campaign`),
  KEY `idx_push_campaigns_status`    (`status`),
  KEY `idx_push_campaigns_scheduled` (`status`, `scheduled_at`),
  KEY `idx_push_campaigns_segment`   (`id_segment`),
  CONSTRAINT `fk_push_campaigns_asset`   FOREIGN KEY (`image_asset_id`) REFERENCES `push_assets`(`id_asset`) ON DELETE SET NULL,
  CONSTRAINT `fk_push_campaigns_segment` FOREIGN KEY (`id_segment`)     REFERENCES `push_segments`(`id_segment`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5) push_campaign_recipients — destinatarios por campanha
CREATE TABLE IF NOT EXISTS `push_campaign_recipients` (
  `id_recipient`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_campaign`          BIGINT UNSIGNED NOT NULL,
  `id_user`              INT(11)         NOT NULL,
  `id_device`            BIGINT UNSIGNED DEFAULT NULL,
  `id_company`           INT(10) UNSIGNED DEFAULT NULL,
  `status_envio`         ENUM('pending','sent','delivered','opened','clicked','failed','skipped') NOT NULL DEFAULT 'pending',
  `provider_message_id`  VARCHAR(200)    DEFAULT NULL,
  `error_code`           VARCHAR(60)     DEFAULT NULL,
  `error_message`        VARCHAR(500)    DEFAULT NULL,
  `sent_at`              DATETIME        DEFAULT NULL,
  `delivered_at`         DATETIME        DEFAULT NULL,
  `opened_at`            DATETIME        DEFAULT NULL,
  `clicked_at`           DATETIME        DEFAULT NULL,
  `created_at`           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_recipient`),
  UNIQUE KEY `uq_push_recip_campaign_device` (`id_campaign`, `id_device`),
  KEY `idx_push_recip_campaign`  (`id_campaign`, `status_envio`),
  KEY `idx_push_recip_user`      (`id_user`),
  KEY `idx_push_recip_device`    (`id_device`),
  CONSTRAINT `fk_push_recip_campaign` FOREIGN KEY (`id_campaign`) REFERENCES `push_campaigns`(`id_campaign`) ON DELETE CASCADE,
  CONSTRAINT `fk_push_recip_device`   FOREIGN KEY (`id_device`)   REFERENCES `push_devices`(`id_device`)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6) push_campaign_runs — execucoes de campanhas
CREATE TABLE IF NOT EXISTS `push_campaign_runs` (
  `id_run`        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_campaign`   BIGINT UNSIGNED NOT NULL,
  `run_type`      ENUM('immediate','scheduled','recurring','test') NOT NULL DEFAULT 'immediate',
  `status`        ENUM('pending','running','completed','failed','cancelled') NOT NULL DEFAULT 'pending',
  `total_target`  INT UNSIGNED    NOT NULL DEFAULT 0,
  `total_sent`    INT UNSIGNED    NOT NULL DEFAULT 0,
  `total_failed`  INT UNSIGNED    NOT NULL DEFAULT 0,
  `started_at`    DATETIME        DEFAULT NULL,
  `finished_at`   DATETIME        DEFAULT NULL,
  `log_json`      LONGTEXT        DEFAULT NULL,
  `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_run`),
  KEY `idx_push_runs_campaign` (`id_campaign`, `status`),
  CONSTRAINT `fk_push_runs_campaign` FOREIGN KEY (`id_campaign`) REFERENCES `push_campaigns`(`id_campaign`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7) push_delivery_events — eventos de entrega (delivered/opened/clicked)
CREATE TABLE IF NOT EXISTS `push_delivery_events` (
  `id_event`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_campaign`        BIGINT UNSIGNED NOT NULL,
  `id_recipient`       BIGINT UNSIGNED DEFAULT NULL,
  `event_type`         ENUM('sent','delivered','opened','clicked','dismissed','failed') NOT NULL,
  `event_payload_json` TEXT            DEFAULT NULL,
  `created_at`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_event`),
  KEY `idx_push_events_campaign` (`id_campaign`, `event_type`),
  KEY `idx_push_events_recipient` (`id_recipient`),
  CONSTRAINT `fk_push_events_campaign`  FOREIGN KEY (`id_campaign`)  REFERENCES `push_campaigns`(`id_campaign`)          ON DELETE CASCADE,
  CONSTRAINT `fk_push_events_recipient` FOREIGN KEY (`id_recipient`) REFERENCES `push_campaign_recipients`(`id_recipient`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8) push_ai_suggestions — sugestoes de IA
CREATE TABLE IF NOT EXISTS `push_ai_suggestions` (
  `id_suggestion`   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_campaign`     BIGINT UNSIGNED DEFAULT NULL,
  `prompt`          TEXT            NOT NULL,
  `response_json`   LONGTEXT        NOT NULL,
  `selected_option` TEXT            DEFAULT NULL,
  `created_by`      INT(11)         NOT NULL,
  `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_suggestion`),
  KEY `idx_push_ai_campaign` (`id_campaign`),
  CONSTRAINT `fk_push_ai_campaign` FOREIGN KEY (`id_campaign`) REFERENCES `push_campaigns`(`id_campaign`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
