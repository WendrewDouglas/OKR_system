-- DDL dump (estrutura) de `planni40_okr` gerado em 2025-11-13 19:53:53

--
-- Tabela `apontamentos_kr`
--
CREATE TABLE `apontamentos_kr` (
  `id_apontamento` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_kr` varchar(50) NOT NULL,
  `id_milestone` int(11) DEFAULT NULL,
  `dt_evidencia` date NOT NULL,
  `dt_apontamento` datetime DEFAULT NULL,
  `valor_real` decimal(14,4) DEFAULT NULL,
  `usuario_id` varchar(100) DEFAULT NULL,
  `url_evidencia` varchar(500) DEFAULT NULL,
  `observacao` text,
  `justificativa` text,
  `justificativa_edicao` text,
  `origem` varchar(30) DEFAULT NULL COMMENT 'manual|import|api|ajuste|migracao',
  `pendente_exclusao` tinyint(1) DEFAULT NULL,
  `justificativa_exclusao` text,
  `id_user_solicitante_exclusao` int(11) DEFAULT NULL,
  `dt_solicitacao_exclusao` datetime DEFAULT NULL,
  `id_company` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id_apontamento`),
  KEY `idx_kr_data` (`id_kr`,`dt_evidencia`),
  KEY `idx_usuario` (`usuario_id`),
  KEY `idx_milestone` (`id_milestone`),
  CONSTRAINT `fk_apontamentos_kr` FOREIGN KEY (`id_kr`) REFERENCES `key_results` (`id_kr`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_apontamentos_kr__milestone` FOREIGN KEY (`id_milestone`) REFERENCES `milestones_kr` (`id_milestone`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=123 DEFAULT CHARSET=utf8mb4;

--
-- Tabela `apontamentos_kr_anexos`
--
CREATE TABLE `apontamentos_kr_anexos` (
  `id_anexo` int(11) NOT NULL AUTO_INCREMENT,
  `id_apontamento` bigint(20) unsigned NOT NULL,
  `nome_anexo` varchar(255) NOT NULL,
  `descricao_anexo` text NOT NULL,
  `tipo_arquivo` varchar(50) DEFAULT NULL,
  `caminho_arquivo` text NOT NULL,
  `tamanho_bytes` bigint(20) unsigned DEFAULT NULL,
  `sha256_hash` char(64) DEFAULT NULL,
  `ordem` int(11) DEFAULT '1',
  `status_scan` varchar(20) DEFAULT 'ok',
  `data_envio` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `id_user_envio` varchar(50) DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT '0',
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(50) DEFAULT NULL,
  `justificativa_exclusao` text,
  `id_company` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id_anexo`),
  UNIQUE KEY `uq_apont_nome` (`id_apontamento`,`nome_anexo`),
  KEY `idx_anx_apont` (`id_apontamento`),
  KEY `idx_anx_envio` (`data_envio`),
  KEY `idx_anx_hash` (`sha256_hash`),
  KEY `idx_anx_company` (`id_company`),
  KEY `idx_anx_deleted` (`is_deleted`,`deleted_at`),
  CONSTRAINT `fk_anx_apont` FOREIGN KEY (`id_apontamento`) REFERENCES `apontamentos_kr` (`id_apontamento`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Tabela `apontamentos_status_iniciativas`
--
CREATE TABLE `apontamentos_status_iniciativas` (
  `id_apontamento` int(11) NOT NULL AUTO_INCREMENT,
  `id_iniciativa` varchar(100) NOT NULL,
  `status` varchar(20) NOT NULL,
  `data_hora` datetime NOT NULL,
  `id_user` varchar(50) NOT NULL,
  `observacao` text,
  `origem_apontamento` varchar(20) DEFAULT NULL,
  `dt_ultima_alteracao` datetime DEFAULT NULL,
  `id_user_alteracao` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id_apontamento`),
  KEY `fk_apont_status_iniciativa` (`id_iniciativa`),
  KEY `fk_apont_status_valor` (`status`),
  CONSTRAINT `fk_apont_status_iniciativa` FOREIGN KEY (`id_iniciativa`) REFERENCES `iniciativas` (`id_iniciativa`),
  CONSTRAINT `fk_apont_status_valor` FOREIGN KEY (`status`) REFERENCES `dom_status_kr` (`id_status`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4;

--
-- Tabela `aprovacao_movimentos`
--
CREATE TABLE `aprovacao_movimentos` (
  `id_movimento` int(11) NOT NULL AUTO_INCREMENT,
  `tipo_estrutura` enum('objetivo','kr','orcamento','apontamento') COLLATE utf8_unicode_ci NOT NULL,
  `id_referencia` varchar(64) COLLATE utf8_unicode_ci NOT NULL,
  `tipo_movimento` enum('novo','alteracao') COLLATE utf8_unicode_ci NOT NULL,
  `campos_diff_json` text COLLATE utf8_unicode_ci NOT NULL,
  `justificativa` text COLLATE utf8_unicode_ci,
  `status` enum('pendente','aprovado','negado') COLLATE utf8_unicode_ci NOT NULL DEFAULT 'pendente',
  `id_user_criador` int(11) DEFAULT NULL,
  `id_user_aprovador` int(11) DEFAULT NULL,
  `dt_decisao` datetime DEFAULT NULL,
  `dt_registro` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_movimento`),
  KEY `idx_ref` (`tipo_estrutura`,`id_referencia`),
  KEY `idx_dt` (`dt_registro`),
  KEY `idx_tipo_status` (`tipo_estrutura`,`status`),
  KEY `idx_ref_status` (`tipo_estrutura`,`id_referencia`,`status`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

--
-- Tabela `aprovadores`
--
CREATE TABLE `aprovadores` (
  `id_aprovador` int(11) NOT NULL AUTO_INCREMENT,
  `id_user` varchar(50) NOT NULL,
  `tudo` tinyint(1) DEFAULT '0',
  `habilitado` tinyint(1) DEFAULT '1',
  `dt_cadastro` datetime DEFAULT CURRENT_TIMESTAMP,
  `dt_ultima_atividade` datetime DEFAULT NULL,
  PRIMARY KEY (`id_aprovador`),
  UNIQUE KEY `id_user` (`id_user`),
  UNIQUE KEY `uq_aprovadores_id_user` (`id_user`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4;

--
-- Tabela `avatars`
--
CREATE TABLE `avatars` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `filename` varchar(255) NOT NULL,
  `gender` enum('masculino','feminino','todos') NOT NULL DEFAULT 'todos',
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `filename` (`filename`),
  UNIQUE KEY `filename_2` (`filename`)
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4;

--
-- Tabela `company`
--
CREATE TABLE `company` (
  `id_company` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `organizacao` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cnpj` varchar(14) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `razao_social` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `natureza_juridica_code` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `natureza_juridica_desc` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `logradouro` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `numero` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `complemento` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cep` varchar(8) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bairro` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `municipio` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `uf` char(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telefone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `missao` text COLLATE utf8mb4_unicode_ci,
  `visao` text COLLATE utf8mb4_unicode_ci,
  `situacao_cadastral` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data_situacao_cadastral` date DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `created_by` int(10) unsigned NOT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id_company`),
  UNIQUE KEY `unq_company_organizacao` (`organizacao`),
  UNIQUE KEY `uq_company_cnpj` (`cnpj`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Cadastro da organização (Nome Fantasia + dados oficiais do CNPJ)';

--
-- Tabela `company_style`
--
CREATE TABLE `company_style` (
  `id_style` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `id_company` int(10) unsigned NOT NULL,
  `bg1_hex` char(7) COLLATE utf8mb4_unicode_ci NOT NULL,
  `bg2_hex` char(7) COLLATE utf8mb4_unicode_ci NOT NULL,
  `logo_base64` longtext COLLATE utf8mb4_unicode_ci,
  `okr_master_user_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `created_by` int(10) unsigned NOT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id_style`),
  UNIQUE KEY `uq_company_style_company` (`id_company`),
  CONSTRAINT `fk_company_style_company` FOREIGN KEY (`id_company`) REFERENCES `company` (`id_company`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Preferências visuais por organização';

--
-- Tabela `dom_cargos`
--
CREATE TABLE `dom_cargos` (
  `id_cargo` int(11) NOT NULL AUTO_INCREMENT,
  `id_company` int(11) NOT NULL,
  `id_departamento` int(11) NOT NULL,
  `id_nivel_cargo` tinyint(3) unsigned NOT NULL,
  `nome_exibicao` varchar(120) COLLATE utf8_unicode_ci NOT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id_cargo`),
  UNIQUE KEY `uq_cargo` (`id_company`,`id_departamento`,`id_nivel_cargo`),
  KEY `id_departamento` (`id_departamento`),
  KEY `id_nivel_cargo` (`id_nivel_cargo`),
  CONSTRAINT `dom_cargos_ibfk_1` FOREIGN KEY (`id_departamento`) REFERENCES `dom_departamentos` (`id_departamento`),
  CONSTRAINT `dom_cargos_ibfk_2` FOREIGN KEY (`id_nivel_cargo`) REFERENCES `dom_niveis_cargo` (`id_nivel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

--
-- Tabela `dom_ciclos`
--
CREATE TABLE `dom_ciclos` (
  `id_ciclo` tinyint(3) unsigned NOT NULL AUTO_INCREMENT,
  `nome_ciclo` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descricao` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Descrição legível do ciclo',
  PRIMARY KEY (`id_ciclo`),
  UNIQUE KEY `nome_ciclo` (`nome_ciclo`),
  UNIQUE KEY `uq_dom_ciclos_nome_ciclo` (`nome_ciclo`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Domínio dos ciclos de revisão de objetivos OKR';

--
-- Tabela `dom_departamentos`
--
CREATE TABLE `dom_departamentos` (
  `id_departamento` int(11) NOT NULL AUTO_INCREMENT,
  `id_company` int(11) NOT NULL,
  `codigo` varchar(40) COLLATE utf8_unicode_ci NOT NULL,
  `nome` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT '1',
  `display_order` smallint(6) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id_departamento`),
  UNIQUE KEY `uq_dep_company_codigo` (`id_company`,`codigo`),
  KEY `idx_dep_company_nome` (`id_company`,`nome`)
) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

--
-- Tabela `dom_modulo_aprovacao`
--
CREATE TABLE `dom_modulo_aprovacao` (
  `id_modulo` varchar(30) NOT NULL,
  `descricao_exibicao` varchar(100) NOT NULL,
  PRIMARY KEY (`id_modulo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Tabela `dom_natureza_kr`
--
CREATE TABLE `dom_natureza_kr` (
  `id_natureza` varchar(20) NOT NULL,
  `descricao_exibicao` varchar(50) NOT NULL,
  `descricao_detalhada` text,
  PRIMARY KEY (`id_natureza`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Tabela `dom_niveis_cargo`
--
CREATE TABLE `dom_niveis_cargo` (
  `id_nivel` tinyint(3) unsigned NOT NULL,
  `codigo` varchar(20) COLLATE utf8_unicode_ci NOT NULL,
  `nome` varchar(60) COLLATE utf8_unicode_ci NOT NULL,
  `ordem` tinyint(3) unsigned NOT NULL,
  `is_gestao` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id_nivel`),
  UNIQUE KEY `codigo` (`codigo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

--
-- Tabela `dom_paginas`
--
CREATE TABLE `dom_paginas` (
  `id_pagina` varchar(80) NOT NULL,
  `path` varchar(180) NOT NULL,
  `descricao` varchar(180) NOT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT '1',
  `requires_cap` varchar(64) DEFAULT NULL COMMENT 'cap_key mínima exigida para abrir a página',
  PRIMARY KEY (`id_pagina`),
  UNIQUE KEY `uq_dom_paginas_path` (`path`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Tabela `dom_permissoes`
--
CREATE TABLE `dom_permissoes` (
  `id_dominio` int(11) NOT NULL AUTO_INCREMENT,
  `chave_dominio` varchar(50) NOT NULL,
  `descricao` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id_dominio`),
  UNIQUE KEY `chave_dominio` (`chave_dominio`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4;

--
-- Tabela `dom_pilar_bsc`
--
CREATE TABLE `dom_pilar_bsc` (
  `id_pilar` varchar(30) NOT NULL,
  `descricao_exibicao` varchar(50) NOT NULL,
  `ordem_pilar` int(11) NOT NULL,
  PRIMARY KEY (`id_pilar`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Tabela `dom_qualidade_objetivo`
--
CREATE TABLE `dom_qualidade_objetivo` (
  `id_qualidade` varchar(15) NOT NULL,
  `descricao_exibicao` varchar(50) NOT NULL,
  PRIMARY KEY (`id_qualidade`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Tabela `dom_status_aprovacao`
--
CREATE TABLE `dom_status_aprovacao` (
  `id_status` varchar(15) NOT NULL,
  `descricao_exibicao` varchar(50) NOT NULL,
  PRIMARY KEY (`id_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Tabela `dom_status_financeiro`
--
CREATE TABLE `dom_status_financeiro` (
  `id_status` varchar(20) COLLATE utf8_unicode_ci NOT NULL,
  `descricao_exibicao` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
  PRIMARY KEY (`id_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

--
-- Tabela `dom_status_kr`
--
CREATE TABLE `dom_status_kr` (
  `id_status` varchar(20) NOT NULL,
  `descricao_exibicao` varchar(50) NOT NULL,
  PRIMARY KEY (`id_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Tabela `dom_tipo_frequencia_milestone`
--
CREATE TABLE `dom_tipo_frequencia_milestone` (
  `id_frequencia` varchar(20) NOT NULL,
  `descricao_exibicao` varchar(50) NOT NULL,
  PRIMARY KEY (`id_frequencia`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Tabela `dom_tipo_kr`
--
CREATE TABLE `dom_tipo_kr` (
  `id_tipo` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descricao_exibicao` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id_tipo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Tabela `dom_tipo_objetivo`
--
CREATE TABLE `dom_tipo_objetivo` (
  `id_tipo` varchar(20) NOT NULL,
  `descricao_exibicao` varchar(50) NOT NULL,
  PRIMARY KEY (`id_tipo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Tabela `fluxo_aprovacoes`
--
CREATE TABLE `fluxo_aprovacoes` (
  `id_fluxo` int(11) NOT NULL AUTO_INCREMENT,
  `tipo_estrutura` varchar(30) NOT NULL,
  `id_referencia` varchar(100) NOT NULL,
  `id_entidade` varchar(50) DEFAULT NULL,
  `tipo_operacao` varchar(20) NOT NULL,
  `motivo_solicitacao` text,
  `dados_originais` text,
  `dados_solicitados` text NOT NULL,
  `id_user_solicitante` varchar(50) NOT NULL,
  `status` varchar(15) NOT NULL,
  `id_user_aprovador` varchar(50) DEFAULT NULL,
  `justificativa` text,
  `contexto_origem` varchar(100) DEFAULT NULL,
  `data_solicitacao` datetime DEFAULT CURRENT_TIMESTAMP,
  `data_aprovacao` datetime DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id_fluxo`)
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4;

--
-- Tabela `iniciativas`
--
CREATE TABLE `iniciativas` (
  `id_iniciativa` varchar(100) NOT NULL,
  `id_kr` varchar(50) NOT NULL,
  `num_iniciativa` int(11) NOT NULL,
  `descricao` text NOT NULL,
  `status` varchar(20) DEFAULT NULL,
  `status_aprovacao` varchar(15) DEFAULT NULL,
  `id_user_criador` varchar(50) NOT NULL,
  `dt_criacao` date NOT NULL,
  `id_user_aprovador` varchar(50) DEFAULT NULL,
  `dt_aprovacao` datetime DEFAULT NULL,
  `id_user_ult_alteracao` varchar(50) DEFAULT NULL,
  `dt_ultima_atualizacao` datetime DEFAULT NULL,
  `observacoes` text,
  `dt_prazo` date DEFAULT NULL,
  `id_user_responsavel` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id_iniciativa`),
  KEY `status_aprovacao` (`status_aprovacao`),
  KEY `status` (`status`),
  KEY `fk_iniciativas_kr` (`id_kr`),
  CONSTRAINT `fk_iniciativas_kr` FOREIGN KEY (`id_kr`) REFERENCES `key_results` (`id_kr`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `iniciativas_ibfk_1` FOREIGN KEY (`id_kr`) REFERENCES `key_results` (`id_kr`),
  CONSTRAINT `iniciativas_ibfk_2` FOREIGN KEY (`status_aprovacao`) REFERENCES `dom_status_aprovacao` (`id_status`),
  CONSTRAINT `iniciativas_ibfk_3` FOREIGN KEY (`status`) REFERENCES `dom_status_kr` (`id_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Tabela `iniciativas_envolvidos`
--
CREATE TABLE `iniciativas_envolvidos` (
  `id_iniciativa` varchar(100) NOT NULL,
  `id_user` varchar(50) NOT NULL,
  `dt_inclusao` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_iniciativa`,`id_user`),
  CONSTRAINT `iniciativas_envolvidos_ibfk_1` FOREIGN KEY (`id_iniciativa`) REFERENCES `iniciativas` (`id_iniciativa`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Tabela `key_results`
--
CREATE TABLE `key_results` (
  `id_kr` varchar(50) NOT NULL,
  `id_objetivo` int(10) unsigned NOT NULL,
  `key_result_num` int(11) NOT NULL,
  `descricao` text NOT NULL,
  `usuario_criador` varchar(100) NOT NULL,
  `id_user_criador` int(11) DEFAULT NULL,
  `dt_criacao` date NOT NULL,
  `tipo_kr` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `natureza_kr` varchar(20) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'Não Iniciado',
  `status_aprovacao` varchar(15) DEFAULT 'pendente',
  `tipo_frequencia_milestone` varchar(20) DEFAULT NULL,
  `qualidade` varchar(15) DEFAULT NULL,
  `baseline` decimal(10,2) DEFAULT NULL,
  `meta` decimal(10,2) DEFAULT NULL,
  `unidade_medida` varchar(30) DEFAULT NULL,
  `direcao_metrica` varchar(30) DEFAULT NULL,
  `farol` varchar(20) DEFAULT NULL,
  `margem_confianca` decimal(5,2) DEFAULT NULL,
  `data_inicio` date DEFAULT NULL,
  `data_fim` date DEFAULT NULL,
  `responsavel` varchar(100) DEFAULT NULL,
  `dt_novo_prazo` date DEFAULT NULL,
  `dt_conclusao` date DEFAULT NULL,
  `aprovador` varchar(100) DEFAULT NULL,
  `id_user_aprovador` int(11) DEFAULT NULL,
  `dt_aprovacao` datetime(3) DEFAULT NULL,
  `comentarios_aprovacao` text,
  `peso` decimal(5,2) DEFAULT NULL,
  `dt_ultima_atualizacao` datetime(3) DEFAULT NULL,
  `usuario_ult_alteracao` varchar(100) DEFAULT NULL,
  `observacoes` text,
  PRIMARY KEY (`id_kr`),
  KEY `fk_kr_natureza` (`natureza_kr`),
  KEY `fk_kr_frequencia` (`tipo_frequencia_milestone`),
  KEY `fk_kr_qualidade` (`qualidade`),
  KEY `idx_kr_status_aprovacao` (`status_aprovacao`),
  KEY `idx_kr_dt_aprovacao` (`dt_aprovacao`),
  KEY `idx_kr_id_user_criador` (`id_user_criador`),
  KEY `idx_kr_id_user_aprovador` (`id_user_aprovador`),
  KEY `fk_key_results_objetivo` (`id_objetivo`),
  KEY `idx_kr_status_datas_farol` (`status`,`dt_conclusao`,`data_fim`,`farol`),
  KEY `fk_key_results_tipo_kr` (`tipo_kr`),
  CONSTRAINT `fk_key_results_objetivo` FOREIGN KEY (`id_objetivo`) REFERENCES `objetivos` (`id_objetivo`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_key_results_tipo_kr` FOREIGN KEY (`tipo_kr`) REFERENCES `dom_tipo_kr` (`id_tipo`) ON UPDATE CASCADE,
  CONSTRAINT `fk_kr_frequencia` FOREIGN KEY (`tipo_frequencia_milestone`) REFERENCES `dom_tipo_frequencia_milestone` (`id_frequencia`),
  CONSTRAINT `fk_kr_natureza` FOREIGN KEY (`natureza_kr`) REFERENCES `dom_natureza_kr` (`id_natureza`),
  CONSTRAINT `fk_kr_objetivo` FOREIGN KEY (`id_objetivo`) REFERENCES `objetivos` (`id_objetivo`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_kr_qualidade` FOREIGN KEY (`qualidade`) REFERENCES `dom_qualidade_objetivo` (`id_qualidade`),
  CONSTRAINT `fk_kr_status` FOREIGN KEY (`status`) REFERENCES `dom_status_kr` (`id_status`),
  CONSTRAINT `fk_kr_status_aprovacao` FOREIGN KEY (`status_aprovacao`) REFERENCES `dom_status_aprovacao` (`id_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Tabela `lp001_dom_cargos`
--
CREATE TABLE `lp001_dom_cargos` (
  `id_cargo` smallint(5) unsigned NOT NULL AUTO_INCREMENT,
  `nome` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ordem_hierarquia` tinyint(3) unsigned NOT NULL DEFAULT '255',
  `dt_cadastro` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_cargo`),
  UNIQUE KEY `nome` (`nome`),
  UNIQUE KEY `uq_lp001_dom_cargos__nome` (`nome`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Tabela `lp001_dom_faixa_colaboradores`
--
CREATE TABLE `lp001_dom_faixa_colaboradores` (
  `id_faixa_colab` smallint(5) unsigned NOT NULL AUTO_INCREMENT,
  `descricao` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ordem` smallint(5) unsigned NOT NULL,
  `dt_cadastro` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_faixa_colab`),
  UNIQUE KEY `descricao` (`descricao`),
  KEY `idx_colab_ordem` (`ordem`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Tabela `lp001_dom_faixa_faturamento`
--
CREATE TABLE `lp001_dom_faixa_faturamento` (
  `id_faixa_fat` smallint(5) unsigned NOT NULL AUTO_INCREMENT,
  `descricao` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ordem` smallint(5) unsigned NOT NULL,
  `dt_cadastro` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_faixa_fat`),
  UNIQUE KEY `descricao` (`descricao`),
  KEY `idx_fat_ordem` (`ordem`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Tabela `lp001_quiz`
--
CREATE TABLE `lp001_quiz` (
  `id_quiz` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `nome` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `versao_ativa` bigint(20) unsigned DEFAULT NULL,
  `status` enum('draft','active','archived') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `dt_criacao` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `dt_ultima_atualizacao` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_quiz`),
  UNIQUE KEY `uk_lp001_quiz_slug` (`slug`),
  KEY `fk_lp001_quiz_versao_ativa` (`versao_ativa`),
  CONSTRAINT `fk_lp001_quiz_versao_ativa` FOREIGN KEY (`versao_ativa`) REFERENCES `lp001_quiz_versao` (`id_versao`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Tabela `lp001_quiz_ab_test`
--
CREATE TABLE `lp001_quiz_ab_test` (
  `id_test` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_versao` bigint(20) unsigned NOT NULL,
  `nome` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descricao` text COLLATE utf8mb4_unicode_ci,
  `variantes_json` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `dt_criacao` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_test`),
  KEY `idx_lp001_ab_versao` (`id_versao`,`dt_criacao`),
  CONSTRAINT `fk_lp001_ab_versao` FOREIGN KEY (`id_versao`) REFERENCES `lp001_quiz_versao` (`id_versao`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Tabela `lp001_quiz_agendamentos`
--
CREATE TABLE `lp001_quiz_agendamentos` (
  `id_agendamento` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_lead` bigint(20) unsigned NOT NULL,
  `id_sessao` bigint(20) unsigned DEFAULT NULL,
  `origem_cta` enum('tela_resultado','email','whatsapp') COLLATE utf8mb4_unicode_ci NOT NULL,
  `dt_agendada` datetime NOT NULL,
  `status` enum('scheduled','rescheduled','cancelled','done') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'scheduled',
  `dt_log` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_agendamento`),
  KEY `fk_lp001_ag_lead` (`id_lead`),
  KEY `fk_lp001_ag_sess` (`id_sessao`),
  KEY `idx_lp001_ag_status` (`status`,`dt_agendada`),
  CONSTRAINT `fk_lp001_ag_lead` FOREIGN KEY (`id_lead`) REFERENCES `lp001_quiz_leads` (`id_lead`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_lp001_ag_sess` FOREIGN KEY (`id_sessao`) REFERENCES `lp001_quiz_sessoes` (`id_sessao`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Tabela `lp001_quiz_benchmark_rolling`
--
CREATE TABLE `lp001_quiz_benchmark_rolling` (
  `id_bench` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_versao` bigint(20) unsigned NOT NULL,
  `id_cargo` smallint(5) unsigned NOT NULL,
  `id_modelo` bigint(20) unsigned NOT NULL,
  `id_dominio` bigint(20) unsigned DEFAULT NULL,
  `janela` enum('6m','12m','all') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'all',
  `benchmark_pct` decimal(5,2) NOT NULL,
  `amostra_n` int(10) unsigned NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_bench`),
  UNIQUE KEY `uq_bench` (`id_versao`,`id_cargo`,`id_modelo`,`id_dominio`,`janela`),
  KEY `idx_bench_lookup` (`id_cargo`,`id_modelo`,`janela`),
  KEY `fk_bench_dominio` (`id_dominio`),
  CONSTRAINT `fk_bench_cargo` FOREIGN KEY (`id_cargo`) REFERENCES `lp001_dom_cargos` (`id_cargo`),
  CONSTRAINT `fk_bench_dominio` FOREIGN KEY (`id_dominio`) REFERENCES `lp001_quiz_dominios` (`id_dominio`) ON DELETE SET NULL,
  CONSTRAINT `fk_bench_versao` FOREIGN KEY (`id_versao`) REFERENCES `lp001_quiz_versao` (`id_versao`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Tabela `lp001_quiz_cargo_map`
--
CREATE TABLE `lp001_quiz_cargo_map` (
  `id_cargo` smallint(5) unsigned NOT NULL,
  `id_versao` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id_cargo`),
  KEY `fk_qcm_versao` (`id_versao`),
  CONSTRAINT `fk_qcm_cargo` FOREIGN KEY (`id_cargo`) REFERENCES `lp001_dom_cargos` (`id_cargo`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_qcm_versao` FOREIGN KEY (`id_versao`) REFERENCES `lp001_quiz_versao` (`id_versao`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Tabela `lp001_quiz_checklist_result`
--
CREATE TABLE `lp001_quiz_checklist_result` (
  `id_result` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_sessao` bigint(20) unsigned NOT NULL,
  `id_regra` bigint(20) unsigned DEFAULT NULL,
  `ordem` int(11) NOT NULL,
  `item` varchar(300) COLLATE utf8mb4_unicode_ci NOT NULL,
  `prioridade` tinyint(4) NOT NULL DEFAULT '2',
  `tag` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_result`),
  KEY `idx_chkr_sessao` (`id_sessao`),
  KEY `fk_chkr_regra` (`id_regra`),
  CONSTRAINT `fk_chkr_regra` FOREIGN KEY (`id_regra`) REFERENCES `lp001_quiz_checklist_rules` (`id_regra`) ON DELETE SET NULL,
  CONSTRAINT `fk_chkr_sessao` FOREIGN KEY (`id_sessao`) REFERENCES `lp001_quiz_sessoes` (`id_sessao`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Tabela `lp001_quiz_checklist_rules`
--
CREATE TABLE `lp001_quiz_checklist_rules` (
  `id_regra` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_versao` bigint(20) unsigned DEFAULT NULL,
  `id_cargo` smallint(5) unsigned DEFAULT NULL,
  `id_modelo` bigint(20) unsigned DEFAULT NULL,
  `id_dominio` bigint(20) unsigned DEFAULT NULL,
  `condicao` enum('lt','le','between','bottom_n') COLLATE utf8mb4_unicode_ci NOT NULL,
  `threshold_num` decimal(6,2) DEFAULT NULL,
  `threshold_max` decimal(6,2) DEFAULT NULL,
  `check_item` varchar(300) COLLATE utf8mb4_unicode_ci NOT NULL,
  `check_owner_sugerido` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `prazo_sugerido_dias` smallint(6) DEFAULT NULL,
  `tag` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `prioridade` tinyint(4) NOT NULL DEFAULT '2',
  `max_sugeridos` tinyint(4) DEFAULT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT '1',
  `versao` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_regra`),
  KEY `idx_chk_alvos` (`id_versao`,`id_cargo`,`id_modelo`,`id_dominio`,`ativo`),
  KEY `fk_chk_cargo` (`id_cargo`),
  KEY `fk_chk_dominio` (`id_dominio`),
  CONSTRAINT `fk_chk_cargo` FOREIGN KEY (`id_cargo`) REFERENCES `lp001_dom_cargos` (`id_cargo`) ON DELETE SET NULL,
  CONSTRAINT `fk_chk_dominio` FOREIGN KEY (`id_dominio`) REFERENCES `lp001_quiz_dominios` (`id_dominio`) ON DELETE SET NULL,
  CONSTRAINT `fk_chk_versao` FOREIGN KEY (`id_versao`) REFERENCES `lp001_quiz_versao` (`id_versao`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Tabela `lp001_quiz_domain_weights_overrides`
--
CREATE TABLE `lp001_quiz_domain_weights_overrides` (
  `id_override` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_versao` bigint(20) unsigned NOT NULL,
  `id_dominio` bigint(20) unsigned NOT NULL,
  `id_cargo` smallint(5) unsigned NOT NULL,
  `peso_base` decimal(5,4) NOT NULL DEFAULT '1.0000',
  `peso_extra` decimal(5,4) NOT NULL DEFAULT '0.0000',
  `obs` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dt_criacao` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_override`),
  KEY `idx_ovr_versao` (`id_versao`),
  KEY `idx_ovr_dominio` (`id_dominio`),
  KEY `idx_ovr_cargo` (`id_cargo`),
  CONSTRAINT `fk_ovr_cargo` FOREIGN KEY (`id_cargo`) REFERENCES `lp001_dom_cargos` (`id_cargo`),
  CONSTRAINT `fk_ovr_dominio` FOREIGN KEY (`id_dominio`) REFERENCES `lp001_quiz_dominios` (`id_dominio`),
  CONSTRAINT `fk_ovr_versao` FOREIGN KEY (`id_versao`) REFERENCES `lp001_quiz_versao` (`id_versao`)
) ENGINE=InnoDB AUTO_INCREMENT=127 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Tabela `lp001_quiz_dominios`
--
CREATE TABLE `lp001_quiz_dominios` (
  `id_dominio` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_versao` bigint(20) unsigned NOT NULL,
  `nome` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `peso` decimal(5,4) NOT NULL DEFAULT '0.2500',
  `ordem` smallint(5) unsigned NOT NULL,
  PRIMARY KEY (`id_dominio`),
  UNIQUE KEY `uk_lp001_qd_nome` (`id_versao`,`nome`),
  KEY `idx_lp001_qd_ordem` (`id_versao`,`ordem`),
  CONSTRAINT `fk_lp001_qd_versao` FOREIGN KEY (`id_versao`) REFERENCES `lp001_quiz_versao` (`id_versao`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=140 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Tabela `lp001_quiz_email_log`
--
CREATE TABLE `lp001_quiz_email_log` (
  `id_email` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_lead` bigint(20) unsigned NOT NULL,
  `id_sessao` bigint(20) unsigned DEFAULT NULL,
  `tipo` enum('relatorio_imediato','followup_d2','followup_d7') COLLATE utf8mb4_unicode_ci NOT NULL,
  `status_envio` enum('queued','sent','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
  `provider` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provider_msg_id` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `erro_msg` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dt_envio` datetime DEFAULT NULL,
  `dt_log` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_email`),
  KEY `fk_lp001_eml_lead` (`id_lead`),
  KEY `fk_lp001_eml_sess` (`id_sessao`),
  KEY `idx_lp001_eml_status` (`status_envio`,`dt_log`),
  KEY `idx_lp001_eml_tipo` (`tipo`,`dt_log`),
  CONSTRAINT `fk_lp001_eml_lead` FOREIGN KEY (`id_lead`) REFERENCES `lp001_quiz_leads` (`id_lead`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_lp001_eml_sess` FOREIGN KEY (`id_sessao`) REFERENCES `lp001_quiz_sessoes` (`id_sessao`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Tabela `lp001_quiz_leads`
--
CREATE TABLE `lp001_quiz_leads` (
  `id_lead` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `nome` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_validado` tinyint(1) NOT NULL DEFAULT '0',
  `email_validado_dt` datetime DEFAULT NULL,
  `empresa` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_cargo` smallint(5) unsigned DEFAULT NULL,
  `id_faixa_fat` smallint(5) unsigned DEFAULT NULL,
  `id_faixa_colab` smallint(5) unsigned DEFAULT NULL,
  `telefone_whatsapp_e164` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `whatsapp_optin` tinyint(1) NOT NULL DEFAULT '0',
  `whatsapp_optin_dt` datetime DEFAULT NULL,
  `consent_termos` tinyint(1) NOT NULL DEFAULT '1',
  `consent_marketing` tinyint(1) NOT NULL DEFAULT '0',
  `dt_consent` datetime DEFAULT NULL,
  `origem` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `utm_source` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `utm_medium` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `utm_campaign` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `utm_content` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `utm_term` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dt_cadastro` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `dt_update` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_lead`),
  UNIQUE KEY `uk_lp001_lead_email` (`email`),
  KEY `fk_lp001_lead_cargo` (`id_cargo`),
  KEY `fk_lp001_lead_fat` (`id_faixa_fat`),
  KEY `fk_lp001_lead_colab` (`id_faixa_colab`),
  KEY `idx_lp001_lead_whatsapp` (`telefone_whatsapp_e164`),
  KEY `idx_lp001_lead_utm` (`utm_source`,`utm_medium`,`utm_campaign`),
  CONSTRAINT `fk_lp001_lead_cargo` FOREIGN KEY (`id_cargo`) REFERENCES `lp001_dom_cargos` (`id_cargo`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_lp001_lead_colab` FOREIGN KEY (`id_faixa_colab`) REFERENCES `lp001_dom_faixa_colaboradores` (`id_faixa_colab`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_lp001_lead_fat` FOREIGN KEY (`id_faixa_fat`) REFERENCES `lp001_dom_faixa_faturamento` (`id_faixa_fat`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Tabela `lp001_quiz_opcoes`
--
CREATE TABLE `lp001_quiz_opcoes` (
  `id_opcao` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_pergunta` bigint(20) unsigned NOT NULL,
  `ordem` smallint(5) unsigned NOT NULL,
  `texto` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `explicacao` text COLLATE utf8mb4_unicode_ci,
  `score` smallint(6) NOT NULL DEFAULT '0',
  `categoria_resposta` enum('correta','quase_certa','razoavel','menos_correta') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'razoavel',
  PRIMARY KEY (`id_opcao`),
  UNIQUE KEY `uk_lp001_qo_ordem` (`id_pergunta`,`ordem`),
  UNIQUE KEY `uq_opcao_pergunta_ordem` (`id_pergunta`,`ordem`),
  CONSTRAINT `fk_lp001_qo_pergunta` FOREIGN KEY (`id_pergunta`) REFERENCES `lp001_quiz_perguntas` (`id_pergunta`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3169 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Tabela `lp001_quiz_perguntas`
--
CREATE TABLE `lp001_quiz_perguntas` (
  `id_pergunta` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_versao` bigint(20) unsigned NOT NULL,
  `id_dominio` bigint(20) unsigned NOT NULL,
  `ordem` smallint(5) unsigned NOT NULL,
  `texto` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `glossario_json` json DEFAULT NULL,
  `tipo` enum('single_choice') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'single_choice',
  `branch_key` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id_pergunta`),
  UNIQUE KEY `uk_lp001_qp_ordem` (`id_versao`,`ordem`),
  KEY `idx_lp001_qp_dom` (`id_dominio`,`ordem`),
  KEY `idx_lp001_p_vdom_branch_texto` (`id_versao`,`id_dominio`,`branch_key`,`texto`(191)),
  CONSTRAINT `fk_lp001_qp_dominio` FOREIGN KEY (`id_dominio`) REFERENCES `lp001_quiz_dominios` (`id_dominio`) ON UPDATE CASCADE,
  CONSTRAINT `fk_lp001_qp_versao` FOREIGN KEY (`id_versao`) REFERENCES `lp001_quiz_versao` (`id_versao`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=835 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Tabela `lp001_quiz_recommendation_rules`
--
CREATE TABLE `lp001_quiz_recommendation_rules` (
  `id_regra` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_versao` bigint(20) unsigned DEFAULT NULL,
  `id_cargo` smallint(5) unsigned DEFAULT NULL,
  `id_modelo` bigint(20) unsigned DEFAULT NULL,
  `id_dominio` bigint(20) unsigned DEFAULT NULL,
  `condicao` enum('lt','le','between','bottom_n') COLLATE utf8mb4_unicode_ci NOT NULL,
  `threshold_num` decimal(6,2) DEFAULT NULL,
  `threshold_max` decimal(6,2) DEFAULT NULL,
  `titulo` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `recomendacao_md` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `prioridade` tinyint(4) NOT NULL DEFAULT '2',
  `ativo` tinyint(1) NOT NULL DEFAULT '1',
  `versao` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_regra`),
  KEY `idx_rec_alvos` (`id_versao`,`id_cargo`,`id_modelo`,`id_dominio`,`ativo`),
  KEY `fk_rec_cargo` (`id_cargo`),
  KEY `fk_rec_dominio` (`id_dominio`),
  CONSTRAINT `fk_rec_cargo` FOREIGN KEY (`id_cargo`) REFERENCES `lp001_dom_cargos` (`id_cargo`) ON DELETE SET NULL,
  CONSTRAINT `fk_rec_dominio` FOREIGN KEY (`id_dominio`) REFERENCES `lp001_quiz_dominios` (`id_dominio`) ON DELETE SET NULL,
  CONSTRAINT `fk_rec_versao` FOREIGN KEY (`id_versao`) REFERENCES `lp001_quiz_versao` (`id_versao`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Tabela `lp001_quiz_respostas`
--
CREATE TABLE `lp001_quiz_respostas` (
  `id_resposta` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_sessao` bigint(20) unsigned NOT NULL,
  `id_pergunta` bigint(20) unsigned NOT NULL,
  `id_opcao` bigint(20) unsigned NOT NULL,
  `ordem` smallint(5) unsigned NOT NULL,
  `score_opcao` smallint(6) NOT NULL,
  `tempo_na_tela_ms` int(10) unsigned DEFAULT NULL,
  `dt_resposta` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_resposta`),
  UNIQUE KEY `uk_lp001_resp_unica` (`id_sessao`,`id_pergunta`),
  KEY `fk_lp001_resp_opcao` (`id_opcao`),
  KEY `idx_lp001_resp_sess` (`id_sessao`),
  KEY `idx_lp001_resp_perg` (`id_pergunta`),
  CONSTRAINT `fk_lp001_resp_opcao` FOREIGN KEY (`id_opcao`) REFERENCES `lp001_quiz_opcoes` (`id_opcao`) ON UPDATE CASCADE,
  CONSTRAINT `fk_lp001_resp_perg` FOREIGN KEY (`id_pergunta`) REFERENCES `lp001_quiz_perguntas` (`id_pergunta`) ON UPDATE CASCADE,
  CONSTRAINT `fk_lp001_resp_sess` FOREIGN KEY (`id_sessao`) REFERENCES `lp001_quiz_sessoes` (`id_sessao`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Tabela `lp001_quiz_result_profiles`
--
CREATE TABLE `lp001_quiz_result_profiles` (
  `id_profile` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_versao` bigint(20) unsigned NOT NULL,
  `nome` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `intervalo_score_min` smallint(6) NOT NULL,
  `intervalo_score_max` smallint(6) NOT NULL,
  `resumo_executivo` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `recomendacoes_html` mediumtext COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id_profile`),
  UNIQUE KEY `uk_lp001_qrp_intervalo` (`id_versao`,`intervalo_score_min`,`intervalo_score_max`),
  CONSTRAINT `fk_lp001_qrp_versao` FOREIGN KEY (`id_versao`) REFERENCES `lp001_quiz_versao` (`id_versao`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Tabela `lp001_quiz_scores`
--
CREATE TABLE `lp001_quiz_scores` (
  `id_score` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_sessao` bigint(20) unsigned NOT NULL,
  `score_total` smallint(6) NOT NULL,
  `score_pct_bruto` decimal(5,2) DEFAULT NULL,
  `score_pct_ponderado` decimal(5,2) DEFAULT NULL,
  `maturidade_id` bigint(20) DEFAULT NULL,
  `detalhes_json` json DEFAULT NULL,
  `classificacao_global` enum('vermelho','amarelo','verde') COLLATE utf8mb4_unicode_ci NOT NULL,
  `score_por_dominio` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_profile` bigint(20) unsigned DEFAULT NULL,
  `pdf_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pdf_hash` char(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pdf_gerado_dt` datetime DEFAULT NULL,
  `dt_calculo` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id_score`),
  UNIQUE KEY `uk_lp001_score_sess` (`id_sessao`),
  KEY `fk_lp001_sc_profile` (`id_profile`),
  KEY `idx_lp001_sc_total` (`score_total`),
  KEY `idx_lp001_sc_class` (`classificacao_global`),
  KEY `idx_quiz_scores_sessao` (`id_sessao`),
  CONSTRAINT `fk_lp001_sc_profile` FOREIGN KEY (`id_profile`) REFERENCES `lp001_quiz_result_profiles` (`id_profile`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_lp001_sc_sess` FOREIGN KEY (`id_sessao`) REFERENCES `lp001_quiz_sessoes` (`id_sessao`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Tabela `lp001_quiz_scores_det`
--
CREATE TABLE `lp001_quiz_scores_det` (
  `id_score_det` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_score` bigint(20) unsigned NOT NULL,
  `id_sessao` bigint(20) unsigned NOT NULL,
  `id_dominio` bigint(20) unsigned NOT NULL,
  `media_nota` decimal(10,4) NOT NULL,
  `pct_0_100` decimal(5,2) NOT NULL,
  `perguntas_respondidas` int(10) unsigned NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_score_det`),
  UNIQUE KEY `uq_score_dom` (`id_score`,`id_dominio`),
  KEY `idx_det_sessao` (`id_sessao`),
  KEY `idx_det_dominio` (`id_dominio`),
  CONSTRAINT `fk_det_dominio` FOREIGN KEY (`id_dominio`) REFERENCES `lp001_quiz_dominios` (`id_dominio`),
  CONSTRAINT `fk_det_score` FOREIGN KEY (`id_score`) REFERENCES `lp001_quiz_scores` (`id_score`) ON DELETE CASCADE,
  CONSTRAINT `fk_det_sessao` FOREIGN KEY (`id_sessao`) REFERENCES `lp001_quiz_sessoes` (`id_sessao`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Tabela `lp001_quiz_sessoes`
--
CREATE TABLE `lp001_quiz_sessoes` (
  `id_sessao` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_versao` bigint(20) unsigned NOT NULL,
  `id_lead` bigint(20) unsigned NOT NULL,
  `session_token` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip` varbinary(16) DEFAULT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ab_variant` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('started','completed','abandoned') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'started',
  `dt_inicio` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `dt_fim` datetime DEFAULT NULL,
  PRIMARY KEY (`id_sessao`),
  UNIQUE KEY `uk_lp001_sess_token` (`session_token`),
  KEY `fk_lp001_ses_versao` (`id_versao`),
  KEY `idx_lp001_ses_lead` (`id_lead`),
  KEY `idx_lp001_ses_status` (`status`,`dt_inicio`),
  CONSTRAINT `fk_lp001_ses_lead` FOREIGN KEY (`id_lead`) REFERENCES `lp001_quiz_leads` (`id_lead`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_lp001_ses_versao` FOREIGN KEY (`id_versao`) REFERENCES `lp001_quiz_versao` (`id_versao`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Tabela `lp001_quiz_versao`
--
CREATE TABLE `lp001_quiz_versao` (
  `id_versao` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_quiz` bigint(20) unsigned NOT NULL,
  `descricao` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data_publicacao` datetime DEFAULT NULL,
  `is_ab_test` tinyint(1) NOT NULL DEFAULT '0',
  `nota_de_relevo` text COLLATE utf8mb4_unicode_ci,
  `dt_criacao` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_versao`),
  KEY `idx_lp001_qv_quiz` (`id_quiz`),
  CONSTRAINT `fk_lp001_qv_quiz` FOREIGN KEY (`id_quiz`) REFERENCES `lp001_quiz` (`id_quiz`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Tabela `lp001_quiz_whatsapp_log`
--
CREATE TABLE `lp001_quiz_whatsapp_log` (
  `id_msg` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_lead` bigint(20) unsigned NOT NULL,
  `id_sessao` bigint(20) unsigned DEFAULT NULL,
  `provider` enum('meta_cloud_api','twilio','zenvia','gupshup') COLLATE utf8mb4_unicode_ci NOT NULL,
  `template_name` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status_envio` enum('queued','sent','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
  `provider_msg_id` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_error` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status_dt` datetime DEFAULT NULL,
  `dt_log` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_msg`),
  KEY `fk_lp001_wal_lead` (`id_lead`),
  KEY `fk_lp001_wal_sess` (`id_sessao`),
  KEY `idx_lp001_wal_status` (`status_envio`,`dt_log`),
  KEY `idx_lp001_wal_provider` (`provider`,`status_dt`),
  CONSTRAINT `fk_lp001_wal_lead` FOREIGN KEY (`id_lead`) REFERENCES `lp001_quiz_leads` (`id_lead`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_lp001_wal_sess` FOREIGN KEY (`id_sessao`) REFERENCES `lp001_quiz_sessoes` (`id_sessao`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Tabela `milestones_kr`
--
CREATE TABLE `milestones_kr` (
  `id_milestone` int(11) NOT NULL AUTO_INCREMENT,
  `id_kr` varchar(50) NOT NULL,
  `num_ordem` int(11) NOT NULL,
  `data_ref` date NOT NULL,
  `valor_esperado` decimal(10,2) NOT NULL,
  `valor_esperado_min` decimal(10,2) DEFAULT NULL,
  `valor_esperado_max` decimal(10,2) DEFAULT NULL,
  `valor_real_consolidado` decimal(10,2) DEFAULT NULL,
  `dt_ultimo_apontamento` datetime DEFAULT NULL,
  `qtde_apontamentos` int(10) unsigned NOT NULL DEFAULT '0',
  `gerado_automatico` tinyint(1) DEFAULT '1',
  `editado_manual` tinyint(1) DEFAULT '0',
  `justificativa_edicao` text,
  `comentario_analise` text,
  `bloqueado_para_edicao` tinyint(1) DEFAULT '0',
  `status_aprovacao` varchar(15) DEFAULT NULL,
  `id_user_solicitante` varchar(50) DEFAULT NULL,
  `id_user_aprovador` varchar(50) DEFAULT NULL,
  `dt_aprovacao` datetime DEFAULT NULL,
  `id_company` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id_milestone`),
  UNIQUE KEY `uq_milestone_kr_ordem` (`id_kr`,`num_ordem`),
  UNIQUE KEY `uq_milestone_kr_data` (`id_kr`,`data_ref`),
  KEY `status_aprovacao` (`status_aprovacao`),
  KEY `idx_milestone_company` (`id_company`),
  KEY `idx_mkr_kr_date` (`id_kr`,`data_ref`),
  CONSTRAINT `fk_milestones_kr` FOREIGN KEY (`id_kr`) REFERENCES `key_results` (`id_kr`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `milestones_kr_ibfk_2` FOREIGN KEY (`status_aprovacao`) REFERENCES `dom_status_aprovacao` (`id_status`)
) ENGINE=InnoDB AUTO_INCREMENT=404 DEFAULT CHARSET=utf8mb4;

--
-- Tabela `notificacoes`
--
CREATE TABLE `notificacoes` (
  `id_notificacao` int(11) NOT NULL AUTO_INCREMENT,
  `id_user` int(11) NOT NULL,
  `tipo` varchar(30) NOT NULL,
  `titulo` varchar(180) NOT NULL,
  `mensagem` text NOT NULL,
  `url` varchar(255) DEFAULT NULL,
  `lida` tinyint(1) NOT NULL DEFAULT '0',
  `dt_criado` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `dt_lida` datetime DEFAULT NULL,
  `meta_json` json DEFAULT NULL,
  PRIMARY KEY (`id_notificacao`),
  KEY `idx_notif_user_lida` (`id_user`,`lida`),
  KEY `idx_notif_user_dt` (`id_user`,`dt_criado`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4;

--
-- Tabela `objetivos`
--
CREATE TABLE `objetivos` (
  `id_objetivo` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `descricao` text NOT NULL,
  `tipo` varchar(20) DEFAULT NULL,
  `pilar_bsc` varchar(30) DEFAULT NULL,
  `dono` varchar(100) NOT NULL,
  `usuario_criador` varchar(100) NOT NULL,
  `id_user_criador` int(11) DEFAULT NULL,
  `id_company` int(10) unsigned DEFAULT NULL,
  `status` varchar(20) DEFAULT 'Não Iniciado',
  `dt_criacao` date DEFAULT NULL,
  `dt_prazo` date DEFAULT NULL,
  `dt_conclusao` date DEFAULT NULL,
  `status_aprovacao` varchar(15) DEFAULT 'Pendente',
  `aprovador` varchar(100) DEFAULT NULL,
  `id_user_aprovador` int(11) DEFAULT NULL,
  `dt_aprovacao` datetime DEFAULT NULL,
  `comentarios_aprovacao` text,
  `dt_ultima_atualizacao` datetime DEFAULT NULL,
  `usuario_ult_alteracao` varchar(100) DEFAULT NULL,
  `qualidade` varchar(15) DEFAULT NULL,
  `observacoes` text,
  `tipo_ciclo` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `ciclo` varchar(50) DEFAULT NULL,
  `dt_inicio` date DEFAULT NULL,
  `justificativa_ia` text COMMENT 'Justificativa retornada pela IA na avaliação do objetivo',
  PRIMARY KEY (`id_objetivo`),
  KEY `fk_objetivos_qualidade` (`qualidade`),
  KEY `fk_objetivos_status` (`status`),
  KEY `fk_objetivos_tipo` (`tipo`),
  KEY `fk_objetivos_tipo_ciclo` (`tipo_ciclo`),
  KEY `idx_obj_status_aprovacao` (`status_aprovacao`),
  KEY `idx_obj_dt_aprovacao` (`dt_aprovacao`),
  KEY `idx_obj_id_user_criador` (`id_user_criador`),
  KEY `idx_obj_id_user_aprovador` (`id_user_aprovador`),
  KEY `idx_objetivos_company` (`id_company`),
  KEY `idx_obj_pilar_company` (`pilar_bsc`,`id_company`),
  CONSTRAINT `fk_objetivos_company` FOREIGN KEY (`id_company`) REFERENCES `company` (`id_company`) ON UPDATE CASCADE,
  CONSTRAINT `fk_objetivos_pilar` FOREIGN KEY (`pilar_bsc`) REFERENCES `dom_pilar_bsc` (`id_pilar`),
  CONSTRAINT `fk_objetivos_qualidade` FOREIGN KEY (`qualidade`) REFERENCES `dom_qualidade_objetivo` (`id_qualidade`),
  CONSTRAINT `fk_objetivos_status` FOREIGN KEY (`status`) REFERENCES `dom_status_kr` (`id_status`),
  CONSTRAINT `fk_objetivos_status_aprovacao` FOREIGN KEY (`status_aprovacao`) REFERENCES `dom_status_aprovacao` (`id_status`),
  CONSTRAINT `fk_objetivos_tipo` FOREIGN KEY (`tipo`) REFERENCES `dom_tipo_objetivo` (`id_tipo`),
  CONSTRAINT `fk_objetivos_tipo_ciclo` FOREIGN KEY (`tipo_ciclo`) REFERENCES `dom_ciclos` (`nome_ciclo`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4;

--
-- Tabela `objetivo_links`
--
CREATE TABLE `objetivo_links` (
  `id_link` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_company` int(10) unsigned NOT NULL,
  `id_src` int(10) unsigned NOT NULL,
  `id_dst` int(10) unsigned NOT NULL,
  `justificativa` text COLLATE utf8_unicode_ci NOT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT '1',
  `observacao` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `criado_por` int(11) DEFAULT NULL,
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` datetime DEFAULT NULL,
  PRIMARY KEY (`id_link`),
  UNIQUE KEY `uq_pair` (`id_company`,`id_src`,`id_dst`),
  KEY `idx_company` (`id_company`),
  KEY `idx_src` (`id_src`),
  KEY `idx_dst` (`id_dst`),
  CONSTRAINT `fk_objlink_dst` FOREIGN KEY (`id_dst`) REFERENCES `objetivos` (`id_objetivo`) ON DELETE CASCADE,
  CONSTRAINT `fk_objlink_src` FOREIGN KEY (`id_src`) REFERENCES `objetivos` (`id_objetivo`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

--
-- Tabela `okr_email_verifications`
--
CREATE TABLE `okr_email_verifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('pending','verified','expired') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `expires_at` datetime NOT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `verified_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token` (`token`),
  KEY `idx_email_created` (`email`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Tabela `okr_kr_envolvidos`
--
CREATE TABLE `okr_kr_envolvidos` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_kr` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
  `id_user` bigint(20) unsigned NOT NULL,
  `papel` varchar(30) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'owner|colab|viewer',
  `dt_incl` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_kr_user` (`id_kr`,`id_user`),
  KEY `idx_user` (`id_user`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

--
-- Tabela `orcamentos`
--
CREATE TABLE `orcamentos` (
  `id_orcamento` int(11) NOT NULL AUTO_INCREMENT,
  `id_iniciativa` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
  `valor` decimal(12,2) NOT NULL,
  `data_desembolso` date NOT NULL,
  `status_aprovacao` varchar(15) COLLATE utf8_unicode_ci DEFAULT NULL,
  `id_user_aprovador` varchar(50) COLLATE utf8_unicode_ci DEFAULT NULL,
  `dt_aprovacao` datetime DEFAULT NULL,
  `id_user_criador` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
  `dt_criacao` date NOT NULL,
  `id_user_ult_alteracao` varchar(50) COLLATE utf8_unicode_ci DEFAULT NULL,
  `dt_ultima_atualizacao` datetime DEFAULT NULL,
  `justificativa_orcamento` text COLLATE utf8_unicode_ci,
  `valor_realizado` decimal(12,2) DEFAULT NULL,
  `status_financeiro` varchar(20) COLLATE utf8_unicode_ci DEFAULT NULL,
  `codigo_orcamento` varchar(100) COLLATE utf8_unicode_ci DEFAULT NULL,
  `comentarios_aprovacao` text COLLATE utf8_unicode_ci,
  PRIMARY KEY (`id_orcamento`),
  KEY `idx_orc_status_aprovacao` (`status_aprovacao`),
  KEY `idx_orc_dt_aprovacao` (`dt_aprovacao`)
) ENGINE=InnoDB AUTO_INCREMENT=30 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

--
-- Tabela `orcamentos_detalhes`
--
CREATE TABLE `orcamentos_detalhes` (
  `id_despesa` int(11) NOT NULL AUTO_INCREMENT,
  `id_orcamento` int(11) NOT NULL,
  `valor` decimal(10,2) NOT NULL,
  `descricao` text,
  `data_pagamento` date DEFAULT NULL,
  `evidencia_pagamento` text,
  `id_user_criador` varchar(50) DEFAULT NULL,
  `dt_criacao` datetime DEFAULT NULL,
  PRIMARY KEY (`id_despesa`),
  KEY `id_orcamento` (`id_orcamento`),
  CONSTRAINT `orcamentos_detalhes_ibfk_1` FOREIGN KEY (`id_orcamento`) REFERENCES `orcamentos` (`id_orcamento`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4;

--
-- Tabela `orcamentos_envolvidos`
--
CREATE TABLE `orcamentos_envolvidos` (
  `id_orcamento` int(11) NOT NULL,
  `id_user` varchar(50) NOT NULL,
  `dt_inclusao` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_orcamento`,`id_user`),
  CONSTRAINT `orcamentos_envolvidos_ibfk_1` FOREIGN KEY (`id_orcamento`) REFERENCES `orcamentos` (`id_orcamento`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Tabela `permissoes_aprovador`
--
CREATE TABLE `permissoes_aprovador` (
  `id_permissao` int(11) NOT NULL AUTO_INCREMENT,
  `id_user` varchar(50) NOT NULL,
  `tipo_estrutura` varchar(30) NOT NULL,
  `status_aprovacao` varchar(15) NOT NULL,
  PRIMARY KEY (`id_permissao`),
  UNIQUE KEY `uq_perm_user_mod` (`id_user`,`tipo_estrutura`),
  KEY `fk_perm_aprov_status` (`status_aprovacao`),
  CONSTRAINT `fk_perm_aprov_status` FOREIGN KEY (`status_aprovacao`) REFERENCES `dom_status_aprovacao` (`id_status`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4;

--
-- Tabela `rbac_capabilities`
--
CREATE TABLE `rbac_capabilities` (
  `capability_id` int(11) NOT NULL AUTO_INCREMENT,
  `resource` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `action` enum('R','W') COLLATE utf8mb4_unicode_ci NOT NULL,
  `scope` enum('OWN','TEAM','UNIT','ORG') COLLATE utf8mb4_unicode_ci NOT NULL,
  `conditions_json` json DEFAULT NULL,
  `cap_key` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`capability_id`),
  UNIQUE KEY `cap_key` (`cap_key`),
  UNIQUE KEY `uk_rbac_capabilities_cap_key` (`cap_key`),
  UNIQUE KEY `uk_rbac_cap_key` (`cap_key`)
) ENGINE=InnoDB AUTO_INCREMENT=104 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Tabela `rbac_roles`
--
CREATE TABLE `rbac_roles` (
  `role_id` int(11) NOT NULL AUTO_INCREMENT,
  `role_key` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role_desc` text COLLATE utf8mb4_unicode_ci,
  `is_system` tinyint(1) NOT NULL DEFAULT '1',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`role_id`),
  UNIQUE KEY `role_key` (`role_key`),
  UNIQUE KEY `role_key_2` (`role_key`),
  UNIQUE KEY `uk_rbac_roles_key` (`role_key`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Tabela `rbac_role_capability`
--
CREATE TABLE `rbac_role_capability` (
  `role_id` int(11) NOT NULL,
  `capability_id` int(11) NOT NULL,
  `effect` enum('ALLOW','DENY') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ALLOW',
  PRIMARY KEY (`role_id`,`capability_id`),
  UNIQUE KEY `uk_rc_role_cap` (`role_id`,`capability_id`),
  UNIQUE KEY `uk_role_cap` (`role_id`,`capability_id`),
  KEY `fk_rrc_cap` (`capability_id`),
  CONSTRAINT `fk_rrc_cap` FOREIGN KEY (`capability_id`) REFERENCES `rbac_capabilities` (`capability_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rrc_role` FOREIGN KEY (`role_id`) REFERENCES `rbac_roles` (`role_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Tabela `rbac_user_capability`
--
CREATE TABLE `rbac_user_capability` (
  `user_id` int(11) NOT NULL,
  `capability_id` int(11) NOT NULL,
  `effect` enum('ALLOW','DENY') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ALLOW',
  `note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`user_id`,`capability_id`),
  KEY `fk_uc_cap` (`capability_id`),
  CONSTRAINT `fk_uc_cap` FOREIGN KEY (`capability_id`) REFERENCES `rbac_capabilities` (`capability_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_uc_user` FOREIGN KEY (`user_id`) REFERENCES `usuarios` (`id_user`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Tabela `rbac_user_role`
--
CREATE TABLE `rbac_user_role` (
  `user_id` int(11) NOT NULL,
  `role_id` int(11) NOT NULL,
  `valid_from` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `valid_to` datetime DEFAULT NULL,
  PRIMARY KEY (`user_id`,`role_id`),
  UNIQUE KEY `uk_user_one_role` (`user_id`),
  UNIQUE KEY `uq_user_single_role` (`user_id`),
  KEY `fk_ur_role` (`role_id`),
  CONSTRAINT `fk_ur_role` FOREIGN KEY (`role_id`) REFERENCES `rbac_roles` (`role_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ur_user` FOREIGN KEY (`user_id`) REFERENCES `usuarios` (`id_user`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Tabela `rbac_user_roles`
--
CREATE TABLE `rbac_user_roles` (
  `user_id` int(11) NOT NULL,
  `role_id` int(11) NOT NULL,
  `valid_from` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `valid_to` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`user_id`,`role_id`,`valid_from`),
  KEY `idx_role` (`role_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_valid_to` (`valid_to`),
  CONSTRAINT `fk_rbac_user_roles__role` FOREIGN KEY (`role_id`) REFERENCES `rbac_roles` (`role_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_rbac_user_roles__user` FOREIGN KEY (`user_id`) REFERENCES `usuarios` (`id_user`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Tabela `usuarios`
--
CREATE TABLE `usuarios` (
  `id_user` int(11) NOT NULL AUTO_INCREMENT,
  `primeiro_nome` varchar(100) NOT NULL,
  `ultimo_nome` varchar(100) DEFAULT NULL,
  `avatar_id` int(10) unsigned NOT NULL DEFAULT '1',
  `telefone` varchar(20) DEFAULT NULL,
  `empresa` varchar(100) DEFAULT NULL,
  `id_company` int(10) unsigned DEFAULT NULL,
  `id_departamento` int(11) DEFAULT NULL,
  `id_nivel_cargo` tinyint(3) unsigned DEFAULT NULL,
  `faixa_qtd_funcionarios` varchar(50) DEFAULT NULL,
  `email_corporativo` varchar(150) NOT NULL,
  `imagem_url` text,
  `dt_cadastro` datetime DEFAULT CURRENT_TIMESTAMP,
  `ip_criacao` varchar(45) DEFAULT NULL,
  `id_user_criador` int(11) DEFAULT NULL,
  `id_permissao` int(11) DEFAULT NULL,
  `dt_alteracao` datetime DEFAULT NULL,
  `id_user_alteracao` int(11) DEFAULT NULL,
  PRIMARY KEY (`id_user`),
  UNIQUE KEY `email_corporativo` (`email_corporativo`),
  KEY `id_user_alteracao` (`id_user_alteracao`),
  KEY `idx_usuarios_id_permissao` (`id_permissao`),
  KEY `idx_usuarios_id_company` (`id_company`),
  KEY `idx_usuarios_email` (`email_corporativo`),
  KEY `idx_usuarios_criador` (`id_user_criador`),
  KEY `fk_usuarios_avatar` (`avatar_id`),
  KEY `fk_user_dep` (`id_departamento`),
  KEY `fk_user_nivel` (`id_nivel_cargo`),
  CONSTRAINT `fk_user_dep` FOREIGN KEY (`id_departamento`) REFERENCES `dom_departamentos` (`id_departamento`),
  CONSTRAINT `fk_user_nivel` FOREIGN KEY (`id_nivel_cargo`) REFERENCES `dom_niveis_cargo` (`id_nivel`),
  CONSTRAINT `fk_usuarios_avatar` FOREIGN KEY (`avatar_id`) REFERENCES `avatars` (`id`),
  CONSTRAINT `fk_usuarios_company` FOREIGN KEY (`id_company`) REFERENCES `company` (`id_company`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_usuarios_criador_setnull` FOREIGN KEY (`id_user_criador`) REFERENCES `usuarios` (`id_user`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_usuarios_permissao` FOREIGN KEY (`id_permissao`) REFERENCES `dom_permissoes` (`id_dominio`) ON UPDATE CASCADE,
  CONSTRAINT `usuarios_ibfk_2` FOREIGN KEY (`id_user_alteracao`) REFERENCES `usuarios` (`id_user`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4;

--
-- Tabela `usuarios_credenciais`
--
CREATE TABLE `usuarios_credenciais` (
  `id_user` int(11) NOT NULL,
  `senha_hash` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  PRIMARY KEY (`id_user`),
  CONSTRAINT `usuarios_credenciais_ibfk_1` FOREIGN KEY (`id_user`) REFERENCES `usuarios` (`id_user`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

--
-- Tabela `usuarios_paginas`
--
CREATE TABLE `usuarios_paginas` (
  `id_user` int(11) NOT NULL,
  `id_pagina` varchar(80) NOT NULL,
  PRIMARY KEY (`id_user`,`id_pagina`),
  KEY `fk_up_pagina` (`id_pagina`),
  CONSTRAINT `fk_up_pagina` FOREIGN KEY (`id_pagina`) REFERENCES `dom_paginas` (`id_pagina`) ON DELETE CASCADE,
  CONSTRAINT `fk_up_user` FOREIGN KEY (`id_user`) REFERENCES `usuarios` (`id_user`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Tabela `usuarios_password_resets`
--
CREATE TABLE `usuarios_password_resets` (
  `id_reset` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token` varchar(32) NOT NULL,
  `expira_em` datetime NOT NULL,
  `selector` char(32) DEFAULT NULL,
  `verifier_hash` char(64) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `used_at` datetime DEFAULT NULL,
  `ip_request` varchar(45) DEFAULT NULL,
  `user_agent_request` varchar(255) DEFAULT NULL,
  `ip_use` varchar(45) DEFAULT NULL,
  `user_agent_use` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id_reset`),
  UNIQUE KEY `ux_selector` (`selector`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `usuarios_password_resets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `usuarios` (`id_user`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4;

--
-- Tabela `usuarios_perfis`
--
CREATE TABLE `usuarios_perfis` (
  `id_user` int(11) NOT NULL,
  `id_perfil` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
  PRIMARY KEY (`id_user`,`id_perfil`),
  CONSTRAINT `usuarios_perfis_ibfk_1` FOREIGN KEY (`id_user`) REFERENCES `usuarios` (`id_user`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

--
-- Tabela `usuarios_permissoes`
--
CREATE TABLE `usuarios_permissoes` (
  `id_user` int(11) NOT NULL,
  `id_permissao` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id_user`,`id_permissao`),
  CONSTRAINT `usuarios_permissoes_ibfk_1` FOREIGN KEY (`id_user`) REFERENCES `usuarios` (`id_user`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Tabela `usuarios_planos`
--
CREATE TABLE `usuarios_planos` (
  `id_user` int(11) NOT NULL,
  `id_plano` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
  `dt_inicio` date DEFAULT NULL,
  `dt_fim` date DEFAULT NULL,
  PRIMARY KEY (`id_user`),
  CONSTRAINT `usuarios_planos_ibfk_1` FOREIGN KEY (`id_user`) REFERENCES `usuarios` (`id_user`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

--
-- Tabela `wp_leads`
--
CREATE TABLE `wp_leads` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `full_name` varchar(200) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `email` varchar(200) DEFAULT NULL,
  `uf` char(2) DEFAULT NULL,
  `city` varchar(120) DEFAULT NULL,
  `city_ibge_id` int(10) unsigned DEFAULT NULL,
  `geo_country` varchar(100) DEFAULT NULL,
  `geo_region` varchar(100) DEFAULT NULL,
  `geo_city` varchar(120) DEFAULT NULL,
  `geo_postal` varchar(20) DEFAULT NULL,
  `geo_latitude` decimal(10,6) DEFAULT NULL,
  `geo_longitude` decimal(10,6) DEFAULT NULL,
  `geo_timezone` varchar(80) DEFAULT NULL,
  `geo_org` varchar(255) DEFAULT NULL,
  `geo_asn` varchar(50) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` text,
  `device_type` enum('desktop','mobile','tablet','other') DEFAULT 'other',
  `referrer` text,
  `landing_url` text,
  `referer_domain` varchar(255) DEFAULT NULL,
  `utm_source` varchar(120) DEFAULT NULL,
  `utm_medium` varchar(120) DEFAULT NULL,
  `utm_campaign` varchar(120) DEFAULT NULL,
  `utm_term` varchar(255) DEFAULT NULL,
  `utm_content` varchar(255) DEFAULT NULL,
  `gclid` varchar(255) DEFAULT NULL,
  `fbclid` varchar(255) DEFAULT NULL,
  `msclkid` varchar(255) DEFAULT NULL,
  `ttclid` varchar(255) DEFAULT NULL,
  `first_source` varchar(120) DEFAULT NULL,
  `first_medium` varchar(120) DEFAULT NULL,
  `first_campaign` varchar(120) DEFAULT NULL,
  `first_referrer` text,
  `browser_lang` varchar(20) DEFAULT NULL,
  `timezone` varchar(80) DEFAULT NULL,
  `screen_w` int(11) DEFAULT NULL,
  `screen_h` int(11) DEFAULT NULL,
  `fingerprint` char(64) DEFAULT NULL,
  `attribution` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_email` (`email`),
  KEY `idx_campaign` (`utm_campaign`),
  KEY `idx_fp` (`fingerprint`),
  KEY `idx_ibge` (`city_ibge_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- View `lp001_vw_leads_quality`
--
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `lp001_vw_leads_quality` AS select `l`.`id_lead` AS `id_lead`,`l`.`email` AS `email`,`l`.`nome` AS `nome`,`l`.`telefone_whatsapp_e164` AS `telefone_whatsapp_e164`,(`l`.`email` regexp '@(gmail|hotmail|yahoo|outlook|icloud)\\.(com|com\\.br)$') AS `is_free_mail`,`l`.`consent_marketing` AS `consent_marketing`,`l`.`whatsapp_optin` AS `whatsapp_optin`,`l`.`dt_cadastro` AS `dt_cadastro` from `lp001_quiz_leads` `l`;

--
-- View `lp001_vw_quiz_benchmarks`
--
CREATE ALGORITHM=UNDEFINED DEFINER=`planni40_wendrew`@`201.49.74.26` SQL SECURITY DEFINER VIEW `lp001_vw_quiz_benchmarks` AS select `s`.`id_versao` AS `id_versao`,`sd`.`id_dominio` AS `id_dominio`,avg(`sd`.`pct_0_100`) AS `mean_pct`,count(0) AS `amostra_n` from (((`lp001_quiz_scores_det` `sd` join `lp001_quiz_scores` `sc` on((`sc`.`id_score` = `sd`.`id_score`))) join `lp001_quiz_sessoes` `s` on((`s`.`id_sessao` = `sd`.`id_sessao`))) left join `lp001_quiz_dominios` `d` on((`d`.`id_dominio` = `sd`.`id_dominio`))) group by `s`.`id_versao`,`sd`.`id_dominio`;

--
-- View `lp001_vw_quiz_funnel`
--
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `lp001_vw_quiz_funnel` AS select `s`.`id_versao` AS `id_versao`,count(0) AS `sessoes_total`,sum((`s`.`status` = 'completed')) AS `sessoes_completas`,round(((sum((`s`.`status` = 'completed')) / count(0)) * 100),2) AS `taxa_conclusao_pct` from `lp001_quiz_sessoes` `s` group by `s`.`id_versao`;

--
-- View `lp001_vw_quiz_radar_sessao`
--
CREATE ALGORITHM=UNDEFINED DEFINER=`planni40_wendrew`@`201.49.74.26` SQL SECURITY DEFINER VIEW `lp001_vw_quiz_radar_sessao` AS select `sd`.`id_sessao` AS `id_sessao`,`sd`.`id_dominio` AS `id_dominio`,`d`.`nome` AS `dominio_nome`,`sd`.`pct_0_100` AS `pct_0_100`,`sd`.`perguntas_respondidas` AS `perguntas_respondidas` from (`lp001_quiz_scores_det` `sd` join `lp001_quiz_dominios` `d` on((`d`.`id_dominio` = `sd`.`id_dominio`)));

--
-- View `lp001_vw_quiz_top3_fortes`
--
CREATE ALGORITHM=UNDEFINED DEFINER=`planni40_wendrew`@`201.49.74.26` SQL SECURITY DEFINER VIEW `lp001_vw_quiz_top3_fortes` AS select `a`.`id_sessao` AS `id_sessao`,`a`.`id_dominio` AS `id_dominio`,`a`.`pct_0_100` AS `pct_0_100` from `lp001_quiz_scores_det` `a` where ((select count(0) from `lp001_quiz_scores_det` `b` where ((`b`.`id_sessao` = `a`.`id_sessao`) and (`b`.`pct_0_100` > `a`.`pct_0_100`))) < 3);

--
-- View `lp001_vw_quiz_top3_fracos`
--
CREATE ALGORITHM=UNDEFINED DEFINER=`planni40_wendrew`@`201.49.74.26` SQL SECURITY DEFINER VIEW `lp001_vw_quiz_top3_fracos` AS select `a`.`id_sessao` AS `id_sessao`,`a`.`id_dominio` AS `id_dominio`,`a`.`pct_0_100` AS `pct_0_100` from `lp001_quiz_scores_det` `a` where ((select count(0) from `lp001_quiz_scores_det` `b` where ((`b`.`id_sessao` = `a`.`id_sessao`) and (`b`.`pct_0_100` < `a`.`pct_0_100`))) < 3);

--
-- View `v_milestones_kr_normalizado`
--
CREATE ALGORITHM=UNDEFINED DEFINER=`planni40`@`localhost` SQL SECURITY DEFINER VIEW `v_milestones_kr_normalizado` AS select `m`.`id_milestone` AS `id_milestone`,`m`.`id_kr` AS `id_kr`,`m`.`num_ordem` AS `num_ordem`,`m`.`data_ref` AS `data_ref`,`m`.`valor_esperado` AS `valor_esperado`,`m`.`valor_esperado_min` AS `valor_esperado_min`,`m`.`valor_esperado_max` AS `valor_esperado_max`,`m`.`valor_real_consolidado` AS `valor_real_consolidado`,`m`.`dt_ultimo_apontamento` AS `dt_ultimo_apontamento`,`m`.`qtde_apontamentos` AS `qtde_apontamentos`,`m`.`gerado_automatico` AS `gerado_automatico`,`m`.`editado_manual` AS `editado_manual`,`m`.`justificativa_edicao` AS `justificativa_edicao`,`m`.`comentario_analise` AS `comentario_analise`,`m`.`bloqueado_para_edicao` AS `bloqueado_para_edicao`,`m`.`status_aprovacao` AS `status_aprovacao`,`m`.`id_user_solicitante` AS `id_user_solicitante`,`m`.`id_user_aprovador` AS `id_user_aprovador`,`m`.`dt_aprovacao` AS `dt_aprovacao`,`m`.`id_company` AS `id_company`,coalesce(`m`.`valor_esperado_min`,`m`.`valor_esperado`) AS `esperado_min_normalizado`,coalesce(`m`.`valor_esperado_max`,`m`.`valor_esperado`) AS `esperado_max_normalizado` from `milestones_kr` `m`;

--
-- View `v_user_access_effective`
--
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_user_access_effective` AS select `e`.`user_id` AS `user_id`,group_concat((case when (`c`.`action` = 'R') then `c`.`cap_key` end) order by `c`.`cap_key` ASC separator ', ') AS `consulta_R`,group_concat((case when (`c`.`action` = 'W') then `c`.`cap_key` end) order by `c`.`cap_key` ASC separator ', ') AS `edicao_W` from (((select `ua`.`user_id` AS `user_id`,`ua`.`capability_id` AS `capability_id` from (((select `ur`.`user_id` AS `user_id`,`rc`.`capability_id` AS `capability_id` from (`planni40_okr`.`rbac_user_role` `ur` join `planni40_okr`.`rbac_role_capability` `rc` on(((`rc`.`role_id` = `ur`.`role_id`) and (`rc`.`effect` = 'ALLOW'))))) union select `planni40_okr`.`rbac_user_capability`.`user_id` AS `user_id`,`planni40_okr`.`rbac_user_capability`.`capability_id` AS `capability_id` from `planni40_okr`.`rbac_user_capability` where (`planni40_okr`.`rbac_user_capability`.`effect` = 'ALLOW')) `ua` left join (select `ur`.`user_id` AS `user_id`,`rc`.`capability_id` AS `capability_id` from (`planni40_okr`.`rbac_user_role` `ur` join `planni40_okr`.`rbac_role_capability` `rc` on(((`rc`.`role_id` = `ur`.`role_id`) and (`rc`.`effect` = 'DENY')))) union select `planni40_okr`.`rbac_user_capability`.`user_id` AS `user_id`,`planni40_okr`.`rbac_user_capability`.`capability_id` AS `capability_id` from `planni40_okr`.`rbac_user_capability` where (`planni40_okr`.`rbac_user_capability`.`effect` = 'DENY')) `ud` on(((`ud`.`user_id` = `ua`.`user_id`) and (`ud`.`capability_id` = `ua`.`capability_id`)))) where isnull(`ud`.`capability_id`))) `e` join `planni40_okr`.`rbac_capabilities` `c` on((`c`.`capability_id` = `e`.`capability_id`))) group by `e`.`user_id`;

--
-- View `v_user_access_summary`
--
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_user_access_summary` AS select `v_user_access_effective`.`user_id` AS `user_id`,`v_user_access_effective`.`consulta_R` AS `consulta_R`,`v_user_access_effective`.`edicao_W` AS `edicao_W` from `planni40_okr`.`v_user_access_effective`;

