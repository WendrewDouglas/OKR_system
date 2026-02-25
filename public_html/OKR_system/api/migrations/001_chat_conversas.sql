-- Migration: Create chat_conversas table for AI chat persistence
-- Run this SQL against your OKR System database

CREATE TABLE IF NOT EXISTS `chat_conversas` (
  `id_conversa`  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_user`      INT UNSIGNED    NOT NULL,
  `id_company`   INT UNSIGNED    NOT NULL DEFAULT 0,
  `role`         ENUM('system','user','assistant') NOT NULL,
  `content`      TEXT            NOT NULL,
  `tokens_used`  INT UNSIGNED    NOT NULL DEFAULT 0,
  `session_id`   VARCHAR(64)     NOT NULL DEFAULT '',
  `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_conversa`),
  INDEX `idx_user_created`   (`id_user`, `created_at`),
  INDEX `idx_company`        (`id_company`),
  INDEX `idx_session`        (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
