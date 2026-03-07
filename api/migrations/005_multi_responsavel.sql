-- Migration 005: Múltiplos responsáveis por iniciativa
-- Usa tabela junction `iniciativas_envolvidos` existente (PK composta id_iniciativa, id_user)
-- 2026-02-24

-- ============================================================
-- 1. Corrige tipo de id_user: VARCHAR(50) → INT(11) UNSIGNED
-- ============================================================
-- Limpa registros com id_user inválido (não-numérico ou que não existam em usuarios)
DELETE FROM `iniciativas_envolvidos`
WHERE `id_user` IS NULL
   OR CAST(`id_user` AS UNSIGNED) = 0;

DELETE ie FROM `iniciativas_envolvidos` ie
LEFT JOIN `usuarios` u ON u.`id_user` = CAST(ie.`id_user` AS UNSIGNED)
WHERE u.`id_user` IS NULL;

-- Altera tipo da coluna
ALTER TABLE `iniciativas_envolvidos`
  MODIFY COLUMN `id_user` INT(11) NOT NULL;

-- ============================================================
-- 2. Índice em id_user + FK para usuarios
-- ============================================================
-- Índice (se não existir)
SET @idx_exists = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'iniciativas_envolvidos'
    AND INDEX_NAME = 'idx_ie_id_user'
);
SET @sql_idx = IF(@idx_exists = 0,
  'ALTER TABLE `iniciativas_envolvidos` ADD INDEX `idx_ie_id_user` (`id_user`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql_idx;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- FK para usuarios (ON DELETE CASCADE — se user é deletado, remove da junction)
SET @fk_exists = (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'iniciativas_envolvidos'
    AND CONSTRAINT_NAME = 'fk_ie_usuario'
);
SET @sql_fk = IF(@fk_exists = 0,
  'ALTER TABLE `iniciativas_envolvidos` ADD CONSTRAINT `fk_ie_usuario` FOREIGN KEY (`id_user`) REFERENCES `usuarios`(`id_user`) ON DELETE CASCADE ON UPDATE CASCADE',
  'SELECT 1'
);
PREPARE stmt FROM @sql_fk;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- 3. Semeia dados: copia id_user_responsavel existentes para junction
--    (apenas iniciativas que ainda não têm registro em envolvidos)
-- ============================================================
INSERT IGNORE INTO `iniciativas_envolvidos` (`id_iniciativa`, `id_user`)
SELECT i.`id_iniciativa`, i.`id_user_responsavel`
FROM `iniciativas` i
WHERE i.`id_user_responsavel` IS NOT NULL
  AND i.`id_user_responsavel` > 0
  AND NOT EXISTS (
    SELECT 1 FROM `iniciativas_envolvidos` ie
    WHERE ie.`id_iniciativa` = i.`id_iniciativa`
  );
