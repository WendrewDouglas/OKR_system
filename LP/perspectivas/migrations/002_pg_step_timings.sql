-- =============================================================
-- Módulo "Perspectivas de Gestão" (FMX) — PlanningBI / OKR_system
-- Migration 002: tempo gasto pelo respondente em CADA etapa da trilha.
-- Database target: BANCO PRINCIPAL DO OKR (mesmo schema de pg_form_sessions).
--   >>> ATENÇÃO: NÃO rodar em planni40_lp. Rodar no schema principal do OKR. <<<
-- Compatível com MySQL 5.7+ / MariaDB 10.x / InnoDB / utf8mb4_unicode_ci
-- Idempotente: CREATE TABLE IF NOT EXISTS. Pode rodar mais de uma vez.
-- =============================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- pg_step_timings — uma linha por (sessão, etapa).
--   step_key = 'identificacao' | <block_key> (alinhamento, mercado, ...).
--   elapsed_ms acumula o tempo ATIVO na etapa (soma de deltas enviados
--   pelo front; revisitas somam). O front pausa a contagem quando a aba
--   fica em segundo plano, então o valor aproxima o tempo real de foco.
--   flushes = nº de gravações recebidas (aproxima quantas vezes a etapa
--   foi revisitada/salva).
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pg_step_timings` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id` BIGINT UNSIGNED NOT NULL,
  `id_company` INT UNSIGNED NOT NULL,
  `id_user`    INT(11)      DEFAULT NULL,
  `step_key`   VARCHAR(80)  NOT NULL,
  `elapsed_ms` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `flushes`    INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pg_step_session_step` (`session_id`, `step_key`),
  KEY `idx_pg_step_company` (`id_company`),
  KEY `idx_pg_step_user` (`id_user`),
  CONSTRAINT `fk_pg_step_session`
    FOREIGN KEY (`session_id`) REFERENCES `pg_form_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================
-- FIM.
-- =============================================================
