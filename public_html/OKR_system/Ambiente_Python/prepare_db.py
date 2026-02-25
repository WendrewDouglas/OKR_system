# prepare_db.py
# -*- coding: utf-8 -*-
"""
Prepara SOMENTE a ESTRUTURA do banco para o Quiz (LP001), compatível com MySQL 5.7.
- Ajustes em lp001_quiz_scores (colunas/índice)
- Criação de tabelas: scores_det, overrides, recommendation_rules, checklist_rules, checklist_result, benchmark_rolling
- Criação de views compatíveis com 5.7 (sem window functions) e view de benchmark ADAPTATIVA
NÃO popula dados.
"""

import os, time, sys, traceback, re
from pathlib import Path
from typing import Optional, Tuple

import pymysql
from pymysql.cursors import DictCursor
from dotenv import load_dotenv

# =========================
# Conexão
# =========================
load_dotenv(Path(__file__).resolve().parent.parent / ".env")

def get_conn(retries=3, wait=2):
    last = None
    for _ in range(retries):
        try:
            return pymysql.connect(
                host=os.getenv("DB_HOST"),
                port=int(os.getenv("DB_PORT", "3306")),
                user=os.getenv("DB_USER"),
                password=os.getenv("DB_PASS"),
                database=os.getenv("DB_NAME"),
                charset=os.getenv("DB_CHARSET", "utf8mb4"),
                cursorclass=DictCursor,
                autocommit=False,
                connect_timeout=10, read_timeout=15, write_timeout=15,
            )
        except Exception as e:
            last = e
            time.sleep(wait)
    raise last

CREATE_VIEWS = True

# =========================
# Helpers
# =========================
def server_version(cur) -> str:
    cur.execute("SELECT VERSION() AS v")
    return cur.fetchone()["v"]

def parse_version_tuple(version_str: str) -> Tuple[int,int,int]:
    m = re.match(r"(\d+)\.(\d+)\.(\d+)", version_str)
    if not m: return (0,0,0)
    return tuple(int(x) for x in m.groups())

def column_exists(cur, table_name: str, column_name: str) -> bool:
    cur.execute(
        "SELECT COUNT(*) AS n FROM information_schema.columns "
        "WHERE table_schema = DATABASE() AND table_name = %s AND column_name = %s",
        (table_name, column_name)
    )
    return cur.fetchone()["n"] > 0

def columns_exist(cur, table_name: str, columns: list) -> dict:
    """
    Retorna um dict {col: True/False} indicando se cada coluna existe na tabela.
    """
    res = {}
    for col in columns:
        cur.execute(
            "SELECT COUNT(*) AS n FROM information_schema.columns "
            "WHERE table_schema = DATABASE() AND table_name=%s AND column_name=%s",
            (table_name, col)
        )
        res[col] = cur.fetchone()["n"] > 0
    return res

def table_exists(cur, table_name: str) -> bool:
    cur.execute(
        "SELECT COUNT(*) AS n FROM information_schema.tables "
        "WHERE table_schema = DATABASE() AND table_name = %s",
        (table_name,)
    )
    return cur.fetchone()["n"] > 0

def index_exists(cur, table_name: str, index_name: str) -> bool:
    cur.execute(f"SHOW INDEX FROM `{table_name}`")
    return any(r.get("Key_name") == index_name for r in cur.fetchall())

def add_column_safe(cur, table: str, col: str, coltype: str, anchor_after: Optional[str] = None):
    if anchor_after and column_exists(cur, table, anchor_after):
        sql = f"ALTER TABLE `{table}` ADD COLUMN `{col}` {coltype} AFTER `{anchor_after}`"
    else:
        sql = f"ALTER TABLE `{table}` ADD COLUMN `{col}` {coltype}"
    cur.execute(sql)

def exec_ddl(conn, sql: str, step: str):
    print(f"\n[STEP] {step}")
    try:
        with conn.cursor() as cur:
            cur.execute(sql)
        conn.commit()
        print("  ✓ OK")
    except Exception as e:
        conn.rollback()
        print(f"  ✗ ERRO em '{step}': {e}")
        show_innodb_status(conn)
        traceback.print_exc()
        raise

def try_create_view(conn, name: str, sql: str):
    print(f"\n[VIEW] Criando/atualizando view {name} …")
    try:
        with conn.cursor() as cur:
            cur.execute(sql)
        conn.commit()
        print(f"  ✓ View {name} criada/atualizada")
    except Exception as e:
        conn.rollback()
        print(f"  ⚠ Não foi possível criar a view {name}: {e}")
        show_innodb_status(conn)
        traceback.print_exc()

def show_innodb_status(conn, max_chars=4000):
    try:
        with conn.cursor() as cur:
            cur.execute("SHOW ENGINE INNODB STATUS")
            row = cur.fetchone()
            if row and 'Status' in row and row['Status']:
                print("\n[DIAGNÓSTICO INNODB]\n", (row['Status'] or '')[:max_chars])
    except Exception:
        pass

def fk_coltype_from(cur, table: str, column: str) -> str:
    cur.execute("""
        SELECT DATA_TYPE, COLUMN_TYPE
        FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name=%s AND column_name=%s
        """, (table, column))
    r = cur.fetchone()
    if not r:
        raise RuntimeError(f"Coluna referenciada não encontrada: {table}.{column}")
    coltype = r["COLUMN_TYPE"].upper()
    if "BIGINT" in coltype:
        base = "BIGINT"
    elif "SMALLINT" in coltype:
        base = "SMALLINT"
    elif "TINYINT" in coltype:
        base = "TINYINT"
    else:
        base = "INT"
    unsigned = "UNSIGNED" if "UNSIGNED" in coltype else ""
    return f"{base} {unsigned}".strip()

# =========================
# Migrações (somente DDL)
# =========================
def migrate_alter_scores(conn):
    print("\n=== Ajuste: lp001_quiz_scores (colunas/índice) — sem dados ===")
    with conn.cursor() as cur:
        if not column_exists(cur, "lp001_quiz_scores", "score_pct_bruto"):
            print("  - Adicionando coluna score_pct_bruto …")
            add_column_safe(cur, "lp001_quiz_scores", "score_pct_bruto", "DECIMAL(5,2) NULL", anchor_after="score_total")
            conn.commit()
        else:
            print("  - Coluna score_pct_bruto já existe.")

        if not column_exists(cur, "lp001_quiz_scores", "score_pct_ponderado"):
            print("  - Adicionando coluna score_pct_ponderado …")
            add_column_safe(cur, "lp001_quiz_scores", "score_pct_ponderado", "DECIMAL(5,2) NULL", anchor_after="score_pct_bruto")
            conn.commit()
        else:
            print("  - Coluna score_pct_ponderado já existe.")

        if not column_exists(cur, "lp001_quiz_scores", "maturidade_id"):
            print("  - Adicionando coluna maturidade_id …")
            add_column_safe(cur, "lp001_quiz_scores", "maturidade_id", "BIGINT NULL", anchor_after="score_pct_ponderado")
            conn.commit()
        else:
            print("  - Coluna maturidade_id já existe.")

        if not column_exists(cur, "lp001_quiz_scores", "detalhes_json"):
            print("  - Adicionando coluna detalhes_json …")
            try:
                add_column_safe(cur, "lp001_quiz_scores", "detalhes_json", "JSON NULL", anchor_after="maturidade_id")
                conn.commit()
            except Exception:
                conn.rollback()
                print("    • JSON não suportado/permitido; usando LONGTEXT …")
                add_column_safe(cur, "lp001_quiz_scores", "detalhes_json", "LONGTEXT NULL", anchor_after="maturidade_id")
                conn.commit()
        else:
            print("  - Coluna detalhes_json já existe.")

        if not column_exists(cur, "lp001_quiz_scores", "pdf_path"):
            print("  - Adicionando coluna pdf_path …")
            add_column_safe(cur, "lp001_quiz_scores", "pdf_path", "VARCHAR(255) NULL", anchor_after="detalhes_json")
            conn.commit()
        else:
            print("  - Coluna pdf_path já existe.")

        if not column_exists(cur, "lp001_quiz_scores", "updated_at"):
            print("  - Adicionando coluna updated_at …")
            anchor = "created_at" if column_exists(cur, "lp001_quiz_scores", "created_at") else None
            add_column_safe(cur, "lp001_quiz_scores", "updated_at", "DATETIME NULL", anchor_after=anchor)
            conn.commit()
        else:
            print("  - Coluna updated_at já existe.")

        if not index_exists(cur, "lp001_quiz_scores", "idx_quiz_scores_sessao"):
            print("  - Criando índice idx_quiz_scores_sessao(id_sessao) …")
            cur.execute("ALTER TABLE lp001_quiz_scores ADD INDEX idx_quiz_scores_sessao (id_sessao)")
            conn.commit()
        else:
            print("  - Índice idx_quiz_scores_sessao já existe.")

def migrate_create_scores_det(conn):
    print("\n=== Nova: lp001_quiz_scores_det (detalhe por domínio) — sem dados ===")
    sql = """
    CREATE TABLE IF NOT EXISTS lp001_quiz_scores_det (
      id_score_det          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      id_score              BIGINT UNSIGNED NOT NULL,
      id_sessao             BIGINT UNSIGNED NOT NULL,
      id_dominio            BIGINT UNSIGNED NOT NULL,
      media_nota            DECIMAL(10,4)   NOT NULL,
      pct_0_100             DECIMAL(5,2)    NOT NULL,
      perguntas_respondidas INT UNSIGNED    NOT NULL DEFAULT 0,
      created_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id_score_det),
      UNIQUE KEY uq_score_dom (id_score, id_dominio),
      KEY idx_det_sessao (id_sessao),
      KEY idx_det_dominio (id_dominio),
      CONSTRAINT fk_det_score   FOREIGN KEY (id_score)  REFERENCES lp001_quiz_scores(id_score)   ON DELETE CASCADE,
      CONSTRAINT fk_det_sessao  FOREIGN KEY (id_sessao) REFERENCES lp001_quiz_sessoes(id_sessao) ON DELETE CASCADE,
      CONSTRAINT fk_det_dominio FOREIGN KEY (id_dominio) REFERENCES lp001_quiz_dominios(id_dominio) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    """
    exec_ddl(conn, sql, "Criar lp001_quiz_scores_det")

def migrate_create_overrides(conn):
    print("\n=== Nova: lp001_quiz_domain_weights_overrides (boosts) — sem dados ===")
    with conn.cursor() as cur:
        cargo_t  = fk_coltype_from(cur, "lp001_dom_cargos",   "id_cargo")
        versao_t = fk_coltype_from(cur, "lp001_quiz_versao",  "id_versao")
        dom_t    = fk_coltype_from(cur, "lp001_quiz_dominios","id_dominio")

    sql = f"""
    CREATE TABLE IF NOT EXISTS `lp001_quiz_domain_weights_overrides` (
      `id_override` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `id_versao`   {versao_t} NOT NULL,
      `id_dominio`  {dom_t}    NOT NULL,
      `id_cargo`    {cargo_t}  NOT NULL,
      `peso_base`   DECIMAL(5,4) NOT NULL DEFAULT 1.0000,
      `peso_extra`  DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
      `obs`         VARCHAR(200) NULL,
      `dt_criacao`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id_override`),
      KEY `idx_ovr_versao` (`id_versao`),
      KEY `idx_ovr_dominio` (`id_dominio`),
      KEY `idx_ovr_cargo` (`id_cargo`),
      CONSTRAINT `fk_ovr_versao`  FOREIGN KEY (`id_versao`)  REFERENCES `lp001_quiz_versao`(`id_versao`),
      CONSTRAINT `fk_ovr_dominio` FOREIGN KEY (`id_dominio`) REFERENCES `lp001_quiz_dominios`(`id_dominio`),
      CONSTRAINT `fk_ovr_cargo`   FOREIGN KEY (`id_cargo`)   REFERENCES `lp001_dom_cargos`(`id_cargo`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    """
    exec_ddl(conn, sql, "Criar lp001_quiz_domain_weights_overrides")

def migrate_create_recommendations_and_checklist(conn):
    print("\n=== Novas: regras de recomendações & checklist — sem dados ===")
    with conn.cursor() as cur:
        cargo_t   = fk_coltype_from(cur, "lp001_dom_cargos",   "id_cargo")
        versao_t  = fk_coltype_from(cur, "lp001_quiz_versao",  "id_versao")
        dom_t     = fk_coltype_from(cur, "lp001_quiz_dominios","id_dominio")
        has_model = table_exists(cur, "lp001_quiz_modelos")
        if has_model:
            modelo_t = fk_coltype_from(cur, "lp001_quiz_modelos", "id_modelo")
        else:
            modelo_t = "BIGINT UNSIGNED"  # placeholder sem FK

    # recommendation_rules
    sql_rec = f"""
    CREATE TABLE IF NOT EXISTS lp001_quiz_recommendation_rules (
      id_regra        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      id_versao       {versao_t}  NULL,
      id_cargo        {cargo_t}   NULL,
      id_modelo       {modelo_t}  NULL,
      id_dominio      {dom_t}     NULL,
      condicao        ENUM('lt','le','between','bottom_n') NOT NULL,
      threshold_num   DECIMAL(6,2)  NULL,
      threshold_max   DECIMAL(6,2)  NULL,
      titulo          VARCHAR(200)  NOT NULL,
      recomendacao_md TEXT          NOT NULL,
      prioridade      TINYINT       NOT NULL DEFAULT 2,
      ativo           TINYINT(1)    NOT NULL DEFAULT 1,
      versao          VARCHAR(20)   NULL,
      created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id_regra),
      KEY idx_rec_alvos (id_versao, id_cargo, id_modelo, id_dominio, ativo),
      CONSTRAINT fk_rec_versao  FOREIGN KEY (id_versao)  REFERENCES lp001_quiz_versao(id_versao)     ON DELETE SET NULL,
      CONSTRAINT fk_rec_cargo   FOREIGN KEY (id_cargo)   REFERENCES lp001_dom_cargos(id_cargo)       ON DELETE SET NULL,
      {"CONSTRAINT fk_rec_modelo  FOREIGN KEY (id_modelo)  REFERENCES lp001_quiz_modelos(id_modelo)  ON DELETE SET NULL," if has_model else ""}
      CONSTRAINT fk_rec_dominio FOREIGN KEY (id_dominio) REFERENCES lp001_quiz_dominios(id_dominio)  ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    """
    exec_ddl(conn, sql_rec, "Criar lp001_quiz_recommendation_rules")

    # checklist_rules
    sql_chk = f"""
    CREATE TABLE IF NOT EXISTS lp001_quiz_checklist_rules (
      id_regra            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      id_versao           {versao_t}  NULL,
      id_cargo            {cargo_t}   NULL,
      id_modelo           {modelo_t}  NULL,
      id_dominio          {dom_t}     NULL,
      condicao            ENUM('lt','le','between','bottom_n') NOT NULL,
      threshold_num       DECIMAL(6,2)  NULL,
      threshold_max       DECIMAL(6,2)  NULL,
      check_item          VARCHAR(300)  NOT NULL,
      check_owner_sugerido VARCHAR(120) NULL,
      prazo_sugerido_dias SMALLINT NULL,
      tag                 VARCHAR(80)   NULL,
      prioridade          TINYINT       NOT NULL DEFAULT 2,
      max_sugeridos       TINYINT       NULL,
      ativo               TINYINT(1)    NOT NULL DEFAULT 1,
      versao              VARCHAR(20)   NULL,
      created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id_regra),
      KEY idx_chk_alvos (id_versao, id_cargo, id_modelo, id_dominio, ativo),
      CONSTRAINT fk_chk_versao  FOREIGN KEY (id_versao)  REFERENCES lp001_quiz_versao(id_versao)     ON DELETE SET NULL,
      CONSTRAINT fk_chk_cargo   FOREIGN KEY (id_cargo)   REFERENCES lp001_dom_cargos(id_cargo)       ON DELETE SET NULL,
      {"CONSTRAINT fk_chk_modelo  FOREIGN KEY (id_modelo)  REFERENCES lp001_quiz_modelos(id_modelo)  ON DELETE SET NULL," if has_model else ""}
      CONSTRAINT fk_chk_dominio FOREIGN KEY (id_dominio) REFERENCES lp001_quiz_dominios(id_dominio)  ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    """
    exec_ddl(conn, sql_chk, "Criar lp001_quiz_checklist_rules")

    # checklist_result
    sql_chk_res = """
    CREATE TABLE IF NOT EXISTS lp001_quiz_checklist_result (
      id_result      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      id_sessao      BIGINT UNSIGNED NOT NULL,
      id_regra       BIGINT UNSIGNED NULL,
      ordem          INT            NOT NULL,
      item           VARCHAR(300)   NOT NULL,
      prioridade     TINYINT        NOT NULL DEFAULT 2,
      tag            VARCHAR(80)    NULL,
      created_at     DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id_result),
      KEY idx_chkr_sessao (id_sessao),
      CONSTRAINT fk_chkr_sessao FOREIGN KEY (id_sessao) REFERENCES lp001_quiz_sessoes(id_sessao) ON DELETE CASCADE,
      CONSTRAINT fk_chkr_regra  FOREIGN KEY (id_regra)  REFERENCES lp001_quiz_checklist_rules(id_regra) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    """
    exec_ddl(conn, sql_chk_res, "Criar lp001_quiz_checklist_result")

def migrate_create_benchmark(conn):
    print("\n=== Nova: lp001_quiz_benchmark_rolling — sem dados ===")
    with conn.cursor() as cur:
        cargo_t   = fk_coltype_from(cur, "lp001_dom_cargos",   "id_cargo")
        versao_t  = fk_coltype_from(cur, "lp001_quiz_versao",  "id_versao")
        dom_t     = fk_coltype_from(cur, "lp001_quiz_dominios","id_dominio")
        has_model = table_exists(cur, "lp001_quiz_modelos")
        if has_model:
            modelo_t = fk_coltype_from(cur, "lp001_quiz_modelos", "id_modelo")
        else:
            modelo_t = "BIGINT UNSIGNED"

    sql = f"""
    CREATE TABLE IF NOT EXISTS lp001_quiz_benchmark_rolling (
      id_bench       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      id_versao      {versao_t}  NOT NULL,
      id_cargo       {cargo_t}   NOT NULL,
      id_modelo      {modelo_t}  NOT NULL,
      id_dominio     {dom_t}     NULL,
      janela         ENUM('6m','12m','all') NOT NULL DEFAULT 'all',
      benchmark_pct  DECIMAL(5,2)  NOT NULL,
      amostra_n      INT UNSIGNED   NOT NULL,
      updated_at     DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id_bench),
      UNIQUE KEY uq_bench (id_versao, id_cargo, id_modelo, id_dominio, janela),
      KEY idx_bench_lookup (id_cargo, id_modelo, janela),
      CONSTRAINT fk_bench_versao  FOREIGN KEY (id_versao)  REFERENCES lp001_quiz_versao(id_versao)       ON DELETE CASCADE,
      CONSTRAINT fk_bench_cargo   FOREIGN KEY (id_cargo)   REFERENCES lp001_dom_cargos(id_cargo)         ON DELETE RESTRICT,
      {"CONSTRAINT fk_bench_modelo  FOREIGN KEY (id_modelo)  REFERENCES lp001_quiz_modelos(id_modelo)    ON DELETE RESTRICT," if has_model else ""}
      CONSTRAINT fk_bench_dominio FOREIGN KEY (id_dominio) REFERENCES lp001_quiz_dominios(id_dominio)    ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    """
    exec_ddl(conn, sql, "Criar lp001_quiz_benchmark_rolling")

def migrate_create_views(conn, version_tuple):
    if not CREATE_VIEWS:
        print("\n(Views desativadas por configuração)")
        return

    print("\n=== Views ===")

    # ----- View de Benchmarks ADAPTATIVA -----
    with conn.cursor() as cur:
        cols = columns_exist(cur, "lp001_quiz_sessoes", ["id_cargo", "modelo_negocio"])
        has_cargo = cols.get("id_cargo", False)
        has_modelo = cols.get("modelo_negocio", False)

    select_parts = [
        "s.id_versao",
        "sd.id_dominio",
        "AVG(sd.pct_0_100) AS mean_pct",
        "COUNT(*) AS amostra_n"
    ]
    group_parts = ["s.id_versao", "sd.id_dominio"]

    if has_cargo:
        select_parts.insert(1, "s.id_cargo")
        group_parts.insert(1, "s.id_cargo")

    if has_modelo:
        select_parts.insert(2 if has_cargo else 1, "s.modelo_negocio")
        group_parts.insert(2 if has_cargo else 1, "s.modelo_negocio")

    vw_bench_sql = f"""
    CREATE OR REPLACE VIEW lp001_vw_quiz_benchmarks AS
    SELECT
        {", ".join(select_parts)}
    FROM lp001_quiz_scores_det sd
    JOIN lp001_quiz_scores sc   ON sc.id_score = sd.id_score
    JOIN lp001_quiz_sessoes s   ON s.id_sessao = sd.id_sessao
    LEFT JOIN lp001_quiz_dominios d ON d.id_dominio = sd.id_dominio
    GROUP BY {", ".join(group_parts)};
    """
    try_create_view(conn, "lp001_vw_quiz_benchmarks", vw_bench_sql)

    # ----- Radar por sessão -----
    vw_radar = """
    CREATE OR REPLACE VIEW lp001_vw_quiz_radar_sessao AS
    SELECT
      sd.id_sessao,
      sd.id_dominio,
      d.nome AS dominio_nome,
      sd.pct_0_100,
      sd.perguntas_respondidas
    FROM lp001_quiz_scores_det sd
    JOIN lp001_quiz_dominios d ON d.id_dominio = sd.id_dominio;
    """
    try_create_view(conn, "lp001_vw_quiz_radar_sessao", vw_radar)

    major, _, _ = version_tuple
    if major < 8:
        print("  (MySQL 5.7 detectado — criando views TOP3 compatíveis sem window functions)")

        vw_top3_fortes = """
        CREATE OR REPLACE VIEW lp001_vw_quiz_top3_fortes AS
        SELECT a.id_sessao, a.id_dominio, a.pct_0_100
        FROM lp001_quiz_scores_det a
        WHERE (
          SELECT COUNT(*) FROM lp001_quiz_scores_det b
          WHERE b.id_sessao = a.id_sessao
            AND b.pct_0_100 > a.pct_0_100
        ) < 3;
        """
        try_create_view(conn, "lp001_vw_quiz_top3_fortes", vw_top3_fortes)

        vw_top3_fracos = """
        CREATE OR REPLACE VIEW lp001_vw_quiz_top3_fracos AS
        SELECT a.id_sessao, a.id_dominio, a.pct_0_100
        FROM lp001_quiz_scores_det a
        WHERE (
          SELECT COUNT(*) FROM lp001_quiz_scores_det b
          WHERE b.id_sessao = a.id_sessao
            AND b.pct_0_100 < a.pct_0_100
        ) < 3;
        """
        try_create_view(conn, "lp001_vw_quiz_top3_fracos", vw_top3_fracos)
    else:
        print("  (MySQL 8+ suportado — podemos migrar p/ window functions se quiser)")

def main():
    print("==== PREPARO DE ESTRUTURA | QUIZ (LP001) — INÍCIO ====")
    try:
        conn = get_conn()
        with conn.cursor() as cur:
            ver = server_version(cur)
            vt = parse_version_tuple(ver)
            print(f"[INFO] Conectado. Versão do servidor: {ver}")
    except Exception as e:
        print(f"[FATAL] Falha de conexão: {e}")
        traceback.print_exc()
        sys.exit(1)

    try:
        migrate_alter_scores(conn)
        migrate_create_scores_det(conn)
        migrate_create_overrides(conn)
        migrate_create_recommendations_and_checklist(conn)
        migrate_create_benchmark(conn)
        migrate_create_views(conn, vt)

        print("\n==== PREPARO DE ESTRUTURA | CONCLUÍDO COM SUCESSO ====")
        print("• Tabelas/colunas/índices/views criadas/ajustadas (somente DDL).")
        print("• Próximo passo: popular overrides/regras e implementar o cálculo pós-sessão.")
    except Exception as e:
        print("\n==== PREPARO DE ESTRUTURA | FALHOU ====")
        print(f"Motivo: {e}")
        traceback.print_exc()
        try:
            conn.rollback()
        except:
            pass
        sys.exit(2)
    finally:
        try:
            conn.close()
        except:
            pass

if __name__ == "__main__":
    main()
