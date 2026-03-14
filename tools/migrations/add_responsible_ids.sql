-- =============================================================
-- Adiciona colunas de ID para responsaveis em objetivos e KRs
-- Melhoria estrutural SEM quebrar retrocompatibilidade
-- Os campos textuais (dono, responsavel) sao preservados
-- =============================================================

-- objetivos.id_user_dono — FK para usuarios, nullable
ALTER TABLE `objetivos`
  ADD COLUMN `id_user_dono` INT(11) DEFAULT NULL AFTER `dono`,
  ADD KEY `idx_objetivos_user_dono` (`id_user_dono`),
  ADD CONSTRAINT `fk_objetivos_user_dono` FOREIGN KEY (`id_user_dono`)
    REFERENCES `usuarios`(`id_user`) ON DELETE SET NULL ON UPDATE CASCADE;

-- key_results.id_user_responsavel — FK para usuarios, nullable
ALTER TABLE `key_results`
  ADD COLUMN `id_user_responsavel` INT(11) DEFAULT NULL AFTER `responsavel`,
  ADD KEY `idx_kr_user_responsavel` (`id_user_responsavel`),
  ADD CONSTRAINT `fk_kr_user_responsavel` FOREIGN KEY (`id_user_responsavel`)
    REFERENCES `usuarios`(`id_user`) ON DELETE SET NULL ON UPDATE CASCADE;

-- Preencher automaticamente onde possivel (match por nome)
UPDATE `objetivos` o
  JOIN `usuarios` u ON CONCAT(u.primeiro_nome, ' ', COALESCE(u.ultimo_nome,'')) = o.dono
   SET o.id_user_dono = u.id_user
 WHERE o.id_user_dono IS NULL AND o.dono IS NOT NULL AND o.dono != '';

-- Fallback: match por id_user_criador se dono = nome do criador
UPDATE `objetivos` o
  JOIN `usuarios` u ON u.id_user = o.id_user_criador
   SET o.id_user_dono = u.id_user
 WHERE o.id_user_dono IS NULL AND o.id_user_criador IS NOT NULL;

UPDATE `key_results` k
  JOIN `usuarios` u ON CONCAT(u.primeiro_nome, ' ', COALESCE(u.ultimo_nome,'')) = k.responsavel
   SET k.id_user_responsavel = u.id_user
 WHERE k.id_user_responsavel IS NULL AND k.responsavel IS NOT NULL AND k.responsavel != '';

UPDATE `key_results` k
  JOIN `usuarios` u ON u.id_user = k.id_user_criador
   SET k.id_user_responsavel = u.id_user
 WHERE k.id_user_responsavel IS NULL AND k.id_user_criador IS NOT NULL;
