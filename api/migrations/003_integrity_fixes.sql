-- ============================================================
-- Migration 003: Correções de Integridade do Banco planni40_okr
-- Data: 2026-02-23
-- ============================================================
-- EXECUTAR BACKUP ANTES:
-- /c/xampp/mysql/bin/mysqldump.exe -h br822.hostgator.com.br -u planni40_wendrew -p'V064tt=QJr]P' planni40_okr > backup_pre_migration_003.sql
--
-- EXECUTAR COM:
-- /c/xampp/mysql/bin/mysql.exe -h br822.hostgator.com.br -u planni40_wendrew -p'V064tt=QJr]P' planni40_okr < api/migrations/003_integrity_fixes.sql
-- ============================================================

-- ============================================================
-- FASE 0 — Segurança
-- ============================================================
SET FOREIGN_KEY_CHECKS = 0;
SET @OLD_SQL_MODE = @@SQL_MODE;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- ============================================================
-- FASE 1 — Charset: Padronizar tudo para utf8mb4_unicode_ci
-- ============================================================

-- 1a) Tabelas vindas de utf8_unicode_ci (9 tabelas)
ALTER TABLE aprovacao_movimentos   CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE dom_cargos             CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE dom_status_financeiro  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE objetivo_links         CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE okr_kr_envolvidos      CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE orcamentos             CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE usuarios_credenciais   CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE usuarios_perfis        CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE usuarios_planos        CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 1b) Tabelas vindas de utf8mb4_general_ci (30 tabelas)
ALTER TABLE apontamentos_kr                  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE apontamentos_kr_anexos           CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE apontamentos_status_iniciativas  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE aprovadores                      CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE avatars                          CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE dom_modulo_aprovacao             CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE dom_natureza_kr                  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE dom_paginas                      CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE dom_permissoes                   CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE dom_pilar_bsc                    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE dom_qualidade_objetivo           CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE dom_status_aprovacao             CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE dom_status_kr                    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE dom_tipo_frequencia_milestone    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE dom_tipo_objetivo                CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE fluxo_aprovacoes                 CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE iniciativas                      CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE iniciativas_envolvidos           CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE key_results                      CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE milestones_kr                    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE notificacoes                     CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE objetivos                        CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE orcamentos_detalhes              CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE orcamentos_envolvidos            CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE permissoes_aprovador             CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE rbac_user_roles                  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE usuarios                         CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE usuarios_paginas                 CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE usuarios_password_resets         CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE wp_leads                         CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ============================================================
-- FASE 2 — PKs e AUTO_INCREMENT
-- ============================================================

-- 2a) aprovacao_movimentos: ADD PK(id_movimento) + AUTO_INCREMENT
--     Coluna atual: INT(11) NOT NULL sem PK nem AUTO_INCREMENT
ALTER TABLE aprovacao_movimentos
  MODIFY id_movimento INT(11) NOT NULL AUTO_INCREMENT,
  ADD PRIMARY KEY (id_movimento);

-- 2b) aprovadores: DROP id_aprovador (valores todos 0, inútil)
ALTER TABLE aprovadores
  DROP COLUMN id_aprovador;

-- 2c) company_style: ADD PK(id_style) + AUTO_INCREMENT, ADD UNIQUE(id_company)
--     Coluna atual: INT(10) UNSIGNED NOT NULL sem PK nem AUTO_INCREMENT
ALTER TABLE company_style
  MODIFY id_style INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  ADD PRIMARY KEY (id_style),
  ADD UNIQUE KEY uk_company_style_company (id_company);

-- ============================================================
-- FASE 3 — Conversão de tipos (VARCHAR→INT / UNSIGNED→signed)
-- ============================================================

-- 3a) objetivos.dono: VARCHAR(100) NOT NULL → INT(11) NOT NULL
ALTER TABLE objetivos
  MODIFY dono INT(11) NOT NULL;

-- 3b) key_results.responsavel: VARCHAR(100) NULL → INT(11) NULL
ALTER TABLE key_results
  MODIFY responsavel INT(11) NULL DEFAULT NULL;

-- 3c) iniciativas.id_user_criador: VARCHAR(50) NOT NULL → INT(11) NOT NULL
ALTER TABLE iniciativas
  MODIFY id_user_criador INT(11) NOT NULL;

-- 3d) iniciativas.id_user_responsavel: VARCHAR(50) NULL → INT(11) NULL
ALTER TABLE iniciativas
  MODIFY id_user_responsavel INT(11) NULL DEFAULT NULL;

-- 3e) okr_kr_envolvidos.id_user: BIGINT(20) UNSIGNED → INT(11) signed
--     (usuarios.id_user é INT(11) signed; FK exige tipos idênticos)
ALTER TABLE okr_kr_envolvidos
  MODIFY id_user INT(11) NOT NULL;

-- 3f) aprovadores.id_user: VARCHAR(50) NOT NULL → INT(11) NOT NULL + PK
ALTER TABLE aprovadores
  MODIFY id_user INT(11) NOT NULL,
  ADD PRIMARY KEY (id_user);

-- 3g) company_style.okr_master_user_id: INT(10) UNSIGNED NULL → INT(11) signed NULL
--     (FK para usuarios.id_user que é INT(11) signed)
ALTER TABLE company_style
  MODIFY okr_master_user_id INT(11) NULL DEFAULT NULL;

-- 3h) company_style.created_by: INT(10) UNSIGNED NOT NULL → INT(11) signed NULL
--     (FK SET NULL requer coluna nullable; tipo deve casar com usuarios.id_user)
ALTER TABLE company_style
  MODIFY created_by INT(11) NULL DEFAULT NULL;

-- Nota: objetivos.id_user_criador já é INT(11) NULL — nenhuma conversão necessária.

-- ============================================================
-- FASE 4 — Foreign Keys novas
-- ============================================================

-- Limpar FK duplicada em iniciativas (fk_iniciativas_kr + iniciativas_ibfk_1 ambas em id_kr)
ALTER TABLE iniciativas DROP FOREIGN KEY iniciativas_ibfk_1;

-- 4a) objetivos.dono → usuarios.id_user (RESTRICT — impede deletar user que é dono)
ALTER TABLE objetivos
  ADD CONSTRAINT fk_objetivos_dono
  FOREIGN KEY (dono) REFERENCES usuarios (id_user)
  ON DELETE RESTRICT ON UPDATE CASCADE;

-- 4b) objetivos.id_user_criador → usuarios.id_user (SET NULL)
ALTER TABLE objetivos
  ADD CONSTRAINT fk_objetivos_criador
  FOREIGN KEY (id_user_criador) REFERENCES usuarios (id_user)
  ON DELETE SET NULL ON UPDATE CASCADE;

-- 4c) key_results.responsavel → usuarios.id_user (SET NULL)
ALTER TABLE key_results
  ADD CONSTRAINT fk_kr_responsavel
  FOREIGN KEY (responsavel) REFERENCES usuarios (id_user)
  ON DELETE SET NULL ON UPDATE CASCADE;

-- 4d) iniciativas.id_user_criador → usuarios.id_user (CASCADE)
ALTER TABLE iniciativas
  ADD CONSTRAINT fk_iniciativas_criador
  FOREIGN KEY (id_user_criador) REFERENCES usuarios (id_user)
  ON DELETE CASCADE ON UPDATE CASCADE;

-- 4e) iniciativas.id_user_responsavel → usuarios.id_user (SET NULL)
ALTER TABLE iniciativas
  ADD CONSTRAINT fk_iniciativas_responsavel
  FOREIGN KEY (id_user_responsavel) REFERENCES usuarios (id_user)
  ON DELETE SET NULL ON UPDATE CASCADE;

-- 4f) orcamentos.id_iniciativa → iniciativas.id_iniciativa (CASCADE)
ALTER TABLE orcamentos
  ADD CONSTRAINT fk_orcamentos_iniciativa
  FOREIGN KEY (id_iniciativa) REFERENCES iniciativas (id_iniciativa)
  ON DELETE CASCADE ON UPDATE CASCADE;

-- 4g) apontamentos_kr.id_kr → key_results.id_kr (CASCADE)
ALTER TABLE apontamentos_kr
  ADD CONSTRAINT fk_apontamentos_kr_kr
  FOREIGN KEY (id_kr) REFERENCES key_results (id_kr)
  ON DELETE CASCADE ON UPDATE CASCADE;

-- 4h) apontamentos_kr.id_milestone → milestones_kr.id_milestone (SET NULL)
ALTER TABLE apontamentos_kr
  ADD CONSTRAINT fk_apontamentos_kr_milestone
  FOREIGN KEY (id_milestone) REFERENCES milestones_kr (id_milestone)
  ON DELETE SET NULL ON UPDATE CASCADE;

-- 4i) okr_kr_envolvidos.id_kr → key_results.id_kr (CASCADE)
ALTER TABLE okr_kr_envolvidos
  ADD CONSTRAINT fk_kr_envolvidos_kr
  FOREIGN KEY (id_kr) REFERENCES key_results (id_kr)
  ON DELETE CASCADE ON UPDATE CASCADE;

-- 4j) okr_kr_envolvidos.id_user → usuarios.id_user (CASCADE)
ALTER TABLE okr_kr_envolvidos
  ADD CONSTRAINT fk_kr_envolvidos_user
  FOREIGN KEY (id_user) REFERENCES usuarios (id_user)
  ON DELETE CASCADE ON UPDATE CASCADE;

-- 4k) aprovadores.id_user → usuarios.id_user (CASCADE)
ALTER TABLE aprovadores
  ADD CONSTRAINT fk_aprovadores_user
  FOREIGN KEY (id_user) REFERENCES usuarios (id_user)
  ON DELETE CASCADE ON UPDATE CASCADE;

-- 4l) company_style.id_company → company.id_company (CASCADE)
--     Ambos INT(10) UNSIGNED — tipos já casam
ALTER TABLE company_style
  ADD CONSTRAINT fk_company_style_company
  FOREIGN KEY (id_company) REFERENCES company (id_company)
  ON DELETE CASCADE ON UPDATE CASCADE;

-- 4m) company_style.okr_master_user_id → usuarios.id_user (SET NULL)
--     Agora INT(11) signed NULL — casa com usuarios.id_user
ALTER TABLE company_style
  ADD CONSTRAINT fk_company_style_master
  FOREIGN KEY (okr_master_user_id) REFERENCES usuarios (id_user)
  ON DELETE SET NULL ON UPDATE CASCADE;

-- 4n) company_style.created_by → usuarios.id_user (SET NULL)
--     Agora INT(11) signed NULL — casa com usuarios.id_user
ALTER TABLE company_style
  ADD CONSTRAINT fk_company_style_created_by
  FOREIGN KEY (created_by) REFERENCES usuarios (id_user)
  ON DELETE SET NULL ON UPDATE CASCADE;

-- ============================================================
-- FASE 5 — Drop tabela duplicada (rbac_user_roles → backup)
-- ============================================================
-- rbac_user_roles é duplicata de rbac_user_role. 0 referências no PHP.
-- Precisa dropar as FKs antes de renomear para não bloquear rbac_roles.
ALTER TABLE rbac_user_roles DROP FOREIGN KEY fk_rbac_user_roles__role;
ALTER TABLE rbac_user_roles DROP FOREIGN KEY fk_rbac_user_roles__user;
RENAME TABLE rbac_user_roles TO _bak_rbac_user_roles;

-- ============================================================
-- FASE 6 — Limpeza de orphans em apontamentos_status_iniciativas
-- ============================================================

-- 6a) Backup dos orphans (19 rows sem iniciativa correspondente)
CREATE TABLE _bak_apontamentos_status_orphans AS
  SELECT a.*
  FROM apontamentos_status_iniciativas a
  LEFT JOIN iniciativas i ON a.id_iniciativa = i.id_iniciativa
  WHERE i.id_iniciativa IS NULL;

-- 6b) DELETE orphans
DELETE a
FROM apontamentos_status_iniciativas a
LEFT JOIN iniciativas i ON a.id_iniciativa = i.id_iniciativa
WHERE i.id_iniciativa IS NULL;

-- 6c) ADD FK apontamentos_status_iniciativas.id_iniciativa → iniciativas.id_iniciativa
ALTER TABLE apontamentos_status_iniciativas
  ADD CONSTRAINT fk_apontamentos_status_iniciativa
  FOREIGN KEY (id_iniciativa) REFERENCES iniciativas (id_iniciativa)
  ON DELETE CASCADE ON UPDATE CASCADE;

-- ============================================================
-- FASE 7 — Re-habilitar FK checks + Restaurar SQL_MODE
-- ============================================================
SET FOREIGN_KEY_CHECKS = 1;
SET SQL_MODE = @OLD_SQL_MODE;

-- ============================================================
-- FIM DA MIGRAÇÃO
-- ============================================================
SELECT 'Migration 003 completed successfully!' AS result;
