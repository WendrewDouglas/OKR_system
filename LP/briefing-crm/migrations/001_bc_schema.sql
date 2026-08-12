-- =============================================================
-- Briefing CRM — schema do módulo (prefixo bc_)
--
-- Banco: planni40_okr (mesmo do OKR_system; usa DB_*, não LP_DB_*).
-- Todas as tabelas com CREATE TABLE IF NOT EXISTS — script idempotente,
-- pode ser reaplicado sem efeito colateral.
--
-- Diferente de pg_* (Perspectivas), este módulo NÃO acopla em
-- `usuarios`/`company`: o respondente é externo e não tem conta no
-- sistema. Por isso não há FK para as tabelas do app.
--
-- Aplicar com o runner PHP/PDO (tools/), NUNCA com o cliente mysql:
-- o grant é por host e a CLI cai em @localhost sem acesso.
-- =============================================================

SET NAMES utf8mb4;

-- -------------------------------------------------------------
-- Uma linha por respondente que abriu o briefing.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bc_sessions` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_token`      VARCHAR(80)  NOT NULL,
  `form_slug`          VARCHAR(80)  NOT NULL,
  `form_version`       VARCHAR(20)  NOT NULL,
  `nome_informado`     VARCHAR(150) NOT NULL,
  `email_informado`    VARCHAR(150) NOT NULL,
  `whatsapp_informado` VARCHAR(30)  DEFAULT NULL,
  `escritorio`         VARCHAR(150) DEFAULT NULL,
  `papel`              VARCHAR(60)  DEFAULT NULL,
  `status`             ENUM('started','in_progress','completed','abandoned') NOT NULL DEFAULT 'started',
  `current_block`      VARCHAR(80)  DEFAULT NULL,
  `consent`            TINYINT(1)   NOT NULL DEFAULT 0,
  `consent_version`    VARCHAR(20)  DEFAULT NULL,
  `owner_notified_at`  DATETIME     DEFAULT NULL,
  `copy_sent_at`       DATETIME     DEFAULT NULL,
  `started_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at`       DATETIME     DEFAULT NULL,
  `ip_address`         VARCHAR(45)  DEFAULT NULL,
  `user_agent`         TEXT         DEFAULT NULL,
  `created_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bc_sessions_token` (`session_token`),
  KEY `ix_bc_sessions_email`  (`email_informado`),
  KEY `ix_bc_sessions_status` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Respostas. UNIQUE(session_id, question_key) faz o reenvio de um
-- bloco virar UPDATE em vez de duplicar — é o que permite salvar
-- parcialmente e voltar depois.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bc_answers` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id`    BIGINT UNSIGNED NOT NULL,
  `block_key`     VARCHAR(80)  NOT NULL,
  `question_key`  VARCHAR(120) NOT NULL,
  `question_text` TEXT         NOT NULL,
  `answer_type`   ENUM('open','short','single','multi','scale') NOT NULL,
  `answer_text`   TEXT         DEFAULT NULL,
  `answer_number` TINYINT      DEFAULT NULL,
  `answer_json`   JSON         DEFAULT NULL,
  `form_version`  VARCHAR(20)  NOT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bc_answers_sess_q` (`session_id`, `question_key`),
  KEY `ix_bc_answers_block` (`session_id`, `block_key`),
  CONSTRAINT `fk_bc_answers_session`
    FOREIGN KEY (`session_id`) REFERENCES `bc_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Consentimento LGPD — guarda o TEXTO exibido, não só o aceite,
-- para que a prova não dependa da versão atual do código.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bc_consents` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id`      BIGINT UNSIGNED DEFAULT NULL,
  `email`           VARCHAR(150) NOT NULL,
  `consent_text`    TEXT         NOT NULL,
  `consent_version` VARCHAR(20)  NOT NULL,
  `ip_address`      VARCHAR(45)  DEFAULT NULL,
  `user_agent`      TEXT         DEFAULT NULL,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_bc_consents_email` (`email`),
  KEY `ix_bc_consents_session` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Rate limit de janela fixa, persistido (o módulo não tem Redis).
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bc_rate_limits` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rate_key`     VARCHAR(190) NOT NULL,
  `hits`         INT NOT NULL DEFAULT 0,
  `window_start` DATETIME NOT NULL,
  `updated_at`   DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bc_rate_key` (`rate_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
