-- ============================================================
-- Migration 003b: Continuação — corrigir aprovadores duplicados + restante
-- ============================================================
-- Fase 3f falhou por duplicata em aprovadores.id_user
-- Este script resolve e continua de onde parou.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- FIX: Deduplicar aprovadores (id_user=1 tem 2 linhas)
-- Mantém o registro mais antigo (menor dt_cadastro)
-- ============================================================
DELETE a1
FROM aprovadores a1
INNER JOIN aprovadores a2
  ON a1.id_user = a2.id_user
  AND a1.dt_cadastro > a2.dt_cadastro;

-- ============================================================
-- FASE 3f (retomada) — aprovadores.id_user: VARCHAR→INT + PK
-- ============================================================
ALTER TABLE aprovadores
  MODIFY id_user INT(11) NOT NULL,
  ADD PRIMARY KEY (id_user);

-- ============================================================
-- FASE 3g — company_style.okr_master_user_id: UNSIGNED→signed
-- ============================================================
ALTER TABLE company_style
  MODIFY okr_master_user_id INT(11) NULL DEFAULT NULL;

-- ============================================================
-- FASE 3h — company_style.created_by: UNSIGNED NOT NULL→signed NULL
-- ============================================================
ALTER TABLE company_style
  MODIFY created_by INT(11) NULL DEFAULT NULL;

-- ============================================================
-- FASE 4 — Foreign Keys novas
-- ============================================================

-- Limpar FK duplicada em iniciativas
ALTER TABLE iniciativas DROP FOREIGN KEY iniciativas_ibfk_1;

-- 4a) objetivos.dono → usuarios.id_user (RESTRICT)
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
ALTER TABLE company_style
  ADD CONSTRAINT fk_company_style_company
  FOREIGN KEY (id_company) REFERENCES company (id_company)
  ON DELETE CASCADE ON UPDATE CASCADE;

-- 4m) company_style.okr_master_user_id → usuarios.id_user (SET NULL)
ALTER TABLE company_style
  ADD CONSTRAINT fk_company_style_master
  FOREIGN KEY (okr_master_user_id) REFERENCES usuarios (id_user)
  ON DELETE SET NULL ON UPDATE CASCADE;

-- 4n) company_style.created_by → usuarios.id_user (SET NULL)
ALTER TABLE company_style
  ADD CONSTRAINT fk_company_style_created_by
  FOREIGN KEY (created_by) REFERENCES usuarios (id_user)
  ON DELETE SET NULL ON UPDATE CASCADE;

-- ============================================================
-- FASE 5 — Drop tabela duplicada
-- ============================================================
ALTER TABLE rbac_user_roles DROP FOREIGN KEY fk_rbac_user_roles__role;
ALTER TABLE rbac_user_roles DROP FOREIGN KEY fk_rbac_user_roles__user;
RENAME TABLE rbac_user_roles TO _bak_rbac_user_roles;

-- ============================================================
-- FASE 6 — Limpeza de orphans
-- ============================================================
CREATE TABLE _bak_apontamentos_status_orphans AS
  SELECT a.*
  FROM apontamentos_status_iniciativas a
  LEFT JOIN iniciativas i ON a.id_iniciativa = i.id_iniciativa
  WHERE i.id_iniciativa IS NULL;

DELETE a
FROM apontamentos_status_iniciativas a
LEFT JOIN iniciativas i ON a.id_iniciativa = i.id_iniciativa
WHERE i.id_iniciativa IS NULL;

ALTER TABLE apontamentos_status_iniciativas
  ADD CONSTRAINT fk_apontamentos_status_iniciativa
  FOREIGN KEY (id_iniciativa) REFERENCES iniciativas (id_iniciativa)
  ON DELETE CASCADE ON UPDATE CASCADE;

-- ============================================================
-- FASE 7 — Re-habilitar FK checks
-- ============================================================
SET FOREIGN_KEY_CHECKS = 1;

SELECT 'Migration 003b completed successfully!' AS result;
