-- =====================================================================
-- Avaliacao OKR Master - PlanningBI
-- Banco: planni40_okr   |   Prefixo: okrm_
-- Estrutura preparada para os 4 modulos do programa (BSC, OKR,
-- Hibrido, Execucao). O modulo 1 e apenas a primeira carga.
-- =====================================================================

CREATE TABLE IF NOT EXISTS okrm_modulos (
  id_modulo   INT AUTO_INCREMENT PRIMARY KEY,
  codigo      VARCHAR(20)  NOT NULL,
  titulo      VARCHAR(160) NOT NULL,
  subtitulo   VARCHAR(240) NULL,
  ordem       INT NOT NULL DEFAULT 1,
  ativo       TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_okrm_modulo_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS okrm_versao (
  id_versao   INT AUTO_INCREMENT PRIMARY KEY,
  id_modulo   INT NOT NULL,
  label       VARCHAR(60) NOT NULL,
  is_ativa    TINYINT(1) NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_okrm_versao_modulo (id_modulo, is_ativa),
  CONSTRAINT fk_okrm_versao_modulo FOREIGN KEY (id_modulo)
    REFERENCES okrm_modulos (id_modulo) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Blocos de competencia: alimentam o radar da tela de resultado
CREATE TABLE IF NOT EXISTS okrm_blocos (
  id_bloco    INT AUTO_INCREMENT PRIMARY KEY,
  id_versao   INT NOT NULL,
  nome        VARCHAR(120) NOT NULL,
  nome_curto  VARCHAR(40)  NOT NULL,
  ordem       INT NOT NULL DEFAULT 1,
  KEY ix_okrm_blocos_versao (id_versao),
  CONSTRAINT fk_okrm_blocos_versao FOREIGN KEY (id_versao)
    REFERENCES okrm_versao (id_versao) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS okrm_questoes (
  id_questao  INT AUTO_INCREMENT PRIMARY KEY,
  id_versao   INT NOT NULL,
  id_bloco    INT NOT NULL,
  ordem       INT NOT NULL,
  enunciado   TEXT NOT NULL,
  KEY ix_okrm_questoes_versao (id_versao, ordem),
  CONSTRAINT fk_okrm_questoes_versao FOREIGN KEY (id_versao)
    REFERENCES okrm_versao (id_versao) ON DELETE CASCADE,
  CONSTRAINT fk_okrm_questoes_bloco FOREIGN KEY (id_bloco)
    REFERENCES okrm_blocos (id_bloco) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- is_correta e EXPLICITO (diferente do lp001, onde a correta era
-- inferida pelo maior score). Avaliacao e binaria.
CREATE TABLE IF NOT EXISTS okrm_alternativas (
  id_alternativa INT AUTO_INCREMENT PRIMARY KEY,
  id_questao     INT NOT NULL,
  ordem          INT NOT NULL,
  texto          TEXT NOT NULL,
  is_correta     TINYINT(1) NOT NULL DEFAULT 0,
  justificativa  TEXT NOT NULL,
  KEY ix_okrm_alt_questao (id_questao, ordem),
  CONSTRAINT fk_okrm_alt_questao FOREIGN KEY (id_questao)
    REFERENCES okrm_questoes (id_questao) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS okrm_faixas (
  id_faixa    INT AUTO_INCREMENT PRIMARY KEY,
  id_versao   INT NOT NULL,
  pct_min     INT NOT NULL,
  pct_max     INT NOT NULL,
  rotulo      VARCHAR(80)  NOT NULL,
  leitura     TEXT NOT NULL,
  cor         VARCHAR(20)  NOT NULL DEFAULT 'verde',
  KEY ix_okrm_faixas_versao (id_versao),
  CONSTRAINT fk_okrm_faixas_versao FOREIGN KEY (id_versao)
    REFERENCES okrm_versao (id_versao) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS okrm_alunos (
  id_aluno          INT AUTO_INCREMENT PRIMARY KEY,
  nome              VARCHAR(160) NOT NULL,
  email             VARCHAR(190) NOT NULL,
  empresa           VARCHAR(160) NULL,
  consent_termos    TINYINT(1) NOT NULL DEFAULT 0,
  consent_marketing TINYINT(1) NOT NULL DEFAULT 0,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NULL,
  UNIQUE KEY uq_okrm_aluno_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS okrm_sessoes (
  id_sessao     INT AUTO_INCREMENT PRIMARY KEY,
  session_token CHAR(48) NOT NULL,
  id_aluno      INT NOT NULL,
  id_versao     INT NOT NULL,
  id_modulo     INT NOT NULL,
  data_aula     DATE NOT NULL,
  status        ENUM('aberta','finalizada','abandonada') NOT NULL DEFAULT 'aberta',
  dt_inicio     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  dt_fim        DATETIME NULL,
  ip            VARBINARY(16) NULL,
  user_agent    VARCHAR(255) NULL,
  UNIQUE KEY uq_okrm_sessao_token (session_token),
  KEY ix_okrm_sessao_aluno (id_aluno, id_modulo, status),
  CONSTRAINT fk_okrm_sessao_aluno FOREIGN KEY (id_aluno)
    REFERENCES okrm_alunos (id_aluno) ON DELETE CASCADE,
  CONSTRAINT fk_okrm_sessao_versao FOREIGN KEY (id_versao)
    REFERENCES okrm_versao (id_versao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- tempo_ms = tempo ate o clique em Confirmar (relogio para ANTES do
-- feedback ser renderizado, para nao contaminar a medicao)
CREATE TABLE IF NOT EXISTS okrm_respostas (
  id_resposta    INT AUTO_INCREMENT PRIMARY KEY,
  id_sessao      INT NOT NULL,
  id_questao     INT NOT NULL,
  id_alternativa INT NOT NULL,
  acertou        TINYINT(1) NOT NULL DEFAULT 0,
  tempo_ms       INT NOT NULL DEFAULT 0,
  dt_resposta    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_okrm_resp_sessao_questao (id_sessao, id_questao),
  KEY ix_okrm_resp_sessao (id_sessao),
  CONSTRAINT fk_okrm_resp_sessao FOREIGN KEY (id_sessao)
    REFERENCES okrm_sessoes (id_sessao) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS okrm_resultados (
  id_resultado    INT AUTO_INCREMENT PRIMARY KEY,
  id_sessao       INT NOT NULL,
  acertos         INT NOT NULL DEFAULT 0,
  total           INT NOT NULL DEFAULT 0,
  percentual      INT NOT NULL DEFAULT 0,
  id_faixa        INT NULL,
  score_por_bloco TEXT NULL,
  tempo_total_ms  INT NOT NULL DEFAULT 0,
  tempo_medio_ms  INT NOT NULL DEFAULT 0,
  qtd_rapidas     INT NOT NULL DEFAULT 0,
  dt_calculo      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_okrm_result_sessao (id_sessao),
  CONSTRAINT fk_okrm_result_sessao FOREIGN KEY (id_sessao)
    REFERENCES okrm_sessoes (id_sessao) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Liberacao manual de nova tentativa (regra: 1 tentativa por
-- aluno + modulo; o instrutor libera caso a caso)
CREATE TABLE IF NOT EXISTS okrm_liberacoes (
  id_liberacao INT AUTO_INCREMENT PRIMARY KEY,
  id_aluno     INT NOT NULL,
  id_modulo    INT NOT NULL,
  motivo       VARCHAR(240) NULL,
  consumida    TINYINT(1) NOT NULL DEFAULT 0,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_okrm_lib (id_aluno, id_modulo, consumida),
  CONSTRAINT fk_okrm_lib_aluno FOREIGN KEY (id_aluno)
    REFERENCES okrm_alunos (id_aluno) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
