-- =============================================================
-- Módulo "Perspectivas de Gestão" (FMX) — PlanningBI / OKR_system
-- Database target: BANCO PRINCIPAL DO OKR (mesmo schema de `usuarios`/`company`).
--   >>> ATENÇÃO: NÃO rodar em planni40_lp. Rodar no schema principal do OKR. <<<
-- Compatível com MySQL 5.7+ / MariaDB 10.x / InnoDB / utf8mb4_unicode_ci
--
-- Idempotente:
--   - Coluna usuarios.origem_cadastro: guardada via INFORMATION_SCHEMA + PREPARE
--     (funciona no MySQL 5.7 que NÃO suporta ADD COLUMN IF NOT EXISTS).
--   - Tabelas pg_*: CREATE TABLE IF NOT EXISTS.
-- Pode ser executada mais de uma vez sem erro.
-- =============================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- 1) usuarios.origem_cadastro  (marca respondentes do formulário)
--    Só adiciona se ainda não existir na tabela do schema atual.
-- -------------------------------------------------------------
SET @pg_col_exists := (
  SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'usuarios'
     AND COLUMN_NAME  = 'origem_cadastro'
);

SET @pg_ddl := IF(
  @pg_col_exists = 0,
  'ALTER TABLE `usuarios` ADD COLUMN `origem_cadastro` VARCHAR(50) NULL AFTER `id_user_alteracao`',
  'DO 0'
);

PREPARE pg_stmt FROM @pg_ddl;
EXECUTE pg_stmt;
DEALLOCATE PREPARE pg_stmt;

-- -------------------------------------------------------------
-- 2) pg_form_sessions — sessão principal do formulário
--    Uma linha por preenchimento (respondente x formulário).
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pg_form_sessions` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_token`      VARCHAR(80)  NOT NULL,
  `id_company`         INT UNSIGNED NOT NULL,
  `id_user`            INT(11)      DEFAULT NULL,
  `respondent_role`    VARCHAR(30)  NOT NULL DEFAULT 'gestor',
  `nome_informado`     VARCHAR(150) NOT NULL,
  `email_informado`    VARCHAR(150) NOT NULL,
  `whatsapp_informado` VARCHAR(30)  DEFAULT NULL,
  `form_slug`          VARCHAR(80)  NOT NULL,
  `form_version`       VARCHAR(20)  NOT NULL,
  `status`             ENUM('started','in_progress','completed','abandoned') NOT NULL DEFAULT 'started',
  `current_block`      VARCHAR(80)  DEFAULT NULL,
  `consent`            TINYINT(1)   NOT NULL DEFAULT 0,
  `consent_version`    VARCHAR(20)  DEFAULT NULL,
  `started_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at`       DATETIME     DEFAULT NULL,
  `ip_address`         VARCHAR(45)  DEFAULT NULL,
  `user_agent`         TEXT         DEFAULT NULL,
  `created_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pg_sessions_token` (`session_token`),
  KEY `idx_pg_sessions_company` (`id_company`),
  KEY `idx_pg_sessions_user` (`id_user`),
  KEY `idx_pg_sessions_email` (`email_informado`),
  KEY `idx_pg_sessions_status` (`status`),
  KEY `idx_pg_sessions_role` (`respondent_role`),
  CONSTRAINT `fk_pg_sessions_user`
    FOREIGN KEY (`id_user`) REFERENCES `usuarios` (`id_user`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_pg_sessions_company`
    FOREIGN KEY (`id_company`) REFERENCES `company` (`id_company`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- 3) pg_form_answers — respostas (uma linha por pergunta)
--    UNIQUE(session_id, question_key) garante idempotência (reenvio = update).
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pg_form_answers` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id`    BIGINT UNSIGNED NOT NULL,
  `id_company`    INT UNSIGNED NOT NULL,
  `id_user`       INT(11)      DEFAULT NULL,
  `block_key`     VARCHAR(80)  NOT NULL,
  `question_key`  VARCHAR(120) NOT NULL,
  `question_text` TEXT         NOT NULL,
  `answer_type`   ENUM('open','scale','single','multi','matrix','json') NOT NULL,
  `answer_text`   TEXT         DEFAULT NULL,
  `answer_number` TINYINT      DEFAULT NULL,
  `answer_json`   JSON         DEFAULT NULL,
  `form_version`  VARCHAR(20)  NOT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pg_answer_session_question` (`session_id`, `question_key`),
  KEY `idx_pg_answers_question` (`question_key`),
  KEY `idx_pg_answers_company` (`id_company`),
  KEY `idx_pg_answers_user` (`id_user`),
  KEY `idx_pg_answers_block` (`block_key`),
  CONSTRAINT `fk_pg_answers_session`
    FOREIGN KEY (`session_id`) REFERENCES `pg_form_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- 4) pg_consents — prova de consentimento LGPD (append-only)
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pg_consents` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id`      BIGINT UNSIGNED DEFAULT NULL,
  `id_company`      INT UNSIGNED NOT NULL,
  `id_user`         INT(11)      DEFAULT NULL,
  `email`           VARCHAR(150) NOT NULL,
  `consent_text`    TEXT         NOT NULL,
  `consent_version` VARCHAR(20)  NOT NULL,
  `ip_address`      VARCHAR(45)  DEFAULT NULL,
  `user_agent`      TEXT         DEFAULT NULL,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pg_consents_session` (`session_id`),
  KEY `idx_pg_consents_email` (`email`),
  CONSTRAINT `fk_pg_consents_session`
    FOREIGN KEY (`session_id`) REFERENCES `pg_form_sessions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- 5) pg_rate_limits — janela fixa por rate_key (bucket:ip / bucket:email)
--    Espelha o conceito de lp_rate_limits, mas com chave única textual.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pg_rate_limits` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rate_key`     VARCHAR(190) NOT NULL,
  `hits`         INT NOT NULL DEFAULT 0,
  `window_start` DATETIME NOT NULL,
  `updated_at`   DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pg_rate_key` (`rate_key`),
  KEY `idx_pg_rate_window` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================
-- FIM. Nada de senha/RBAC/permissão é tocado por este módulo.
-- =============================================================
