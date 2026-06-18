-- Sócios do KR (envolvidos comprometidos com o resultado).
-- Cada linha é um CONVITE de sociedade (id_convite). Regras:
--  - até 3 sócios ativos (pendente|aprovado) por KR; rejeitados não contam;
--  - o sócio convidado aprova/rejeita seu próprio convite (fluxo de Aprovações);
--  - rejeição exige justificativa; rejeitado pode ser reconvidado (novo id_convite);
--  - ao aprovar, o sócio é espelhado em okr_kr_envolvidos (papel 'socio') p/ ACL.
-- Sem FK para key_results (mesmo padrão de okr_kr_envolvidos) para evitar
-- conflito de charset/collation; a limpeza em cascata é tratada na aplicação.
CREATE TABLE IF NOT EXISTS `kr_socios` (
  `id_convite`             BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_kr`                  VARCHAR(50) NOT NULL,
  `id_user`                INT(11) NOT NULL,           -- sócio convidado
  `motivo`                 TEXT NOT NULL,              -- descrição/motivo do convite (obrigatória)
  `status`                 VARCHAR(15) NOT NULL DEFAULT 'pendente', -- pendente|aprovado|rejeitado
  `justificativa_rejeicao` TEXT NULL,                  -- obrigatória na rejeição
  `id_user_convidou`       INT(11) NOT NULL,           -- quem convidou (responsável ou admin)
  `dt_convite`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `dt_decisao`             DATETIME NULL,
  PRIMARY KEY (`id_convite`),
  KEY `idx_kr` (`id_kr`),
  KEY `idx_user_status` (`id_user`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
