# tratamento_quiz.py
# -*- coding: utf-8 -*-
"""
Carga de CONTEÚDO do Quiz (LP001) a partir do questionario.csv, com:
- --dry-run: apenas valida CSV e mostra contagens (sem gravar)
- --purge: limpa conteúdo do quiz lp001 ANTES de recarregar (somente conteúdo)
- --reset-versao V: cria nova versão (ex.: v2) e carrega nela (sem apagar versões antigas)

Fluxo:
  1) Lê/normaliza CSV (aliases, trims, coerção de 'Nota')
  2) Garante Quiz e Versão alvo (v1 por padrão ou --reset-versao)
  3) Cria/atualiza Domínios (categorias), Cargos e vínculo versão×cargo (1:1)
  4) Insere/atualiza Perguntas (com ordem única por versão) e Opções
  5) Cria/atualiza overrides de pesos por cargo×domínio
  6) Executa checagens SQL finais e gera um relatório .md com TODO o log + resultados

⚠ Purge remove apenas CONTEÚDO do quiz-alvo (não runtime, não dom_cargos).
Compatível com MySQL 5.7+.
"""

# =========================
# Imports
# =========================
import os, sys, time, json, traceback, argparse, io, datetime
from pathlib import Path
from typing import Dict, List, Tuple

import pandas as pd
import numpy as np
import pymysql
from pymysql.cursors import DictCursor
from pymysql import err as mysql_err
from dotenv import load_dotenv

# =========================
# CONFIG — VARIÁVEIS CENTRAIS
# =========================
CONFIG = {
    # ---- Pastas/arquivos ----
    "BASE_DIR": os.path.abspath(os.path.dirname(__file__)),
    "AUX_DIR_NAME": "arquivos_auxiliares",
    "SRC_FILENAME": "questionario.csv",
    "DOCS_DIR_NAME": "saida_quiz",  # <--- salva o report aqui

    # ---- CSV leitura robusta ----
    "CSV_ENCODINGS_TRY": ["utf-8-sig", "utf-8", "latin-1", "cp1252"],
    "CSV_SEPARATORS_TRY": [None, ";", ",", "\t", "|"],  # None = autodetect (engine='python')
    "CSV_ENGINE": "python",
    "CSV_QUOTECHAR": '"',
    "CSV_ESCAPECHAR": "\\",
    "CSV_ON_BAD_LINES": "warn",  # 'warn' | 'skip' | 'error'

    # ---- Colunas obrigatórias e aliases ----
    "REQUIRED_COLS": [
        "Posição", "Categoria", "Modelo de Negócio", "Tema",
        "Questão", "Alternativas", "Nota", "Gabarito"
    ],
    "COLUMN_ALIASES": {
        "Posição": ["Posicao", "POSIÇÃO", "POSICAO"],
        "Categoria": ["CATEGORIA"],
        "Modelo de Negócio": ["Modelo de Negocio", "ModeloNegocio", "MODELO DE NEGÓCIO"],
        "Tema": ["TEMA"],
        "Questão": ["Questao", "QUESTÃO", "QUESTAO"],
        "Alternativas": ["ALTERNATIVAS", "Alternativa"],
        "Nota": ["NOTA", "Score", "Pontuacao", "Pontuação"],
        "Gabarito": ["Explicacao", "Explicação", "Feedback", "GABARITO"],
    },

    # ---- Normalização ----
    "NORMALIZE_STRIP_NEWLINES": True,
    "NORMALIZE_TRIM": True,

    # ---- Quiz / Versão ----
    "QUIZ_NAME": "Diagnóstico de Maturidade — PlanningBI",
    "QUIZ_SLUG": "lp001",
    "DEFAULT_VERSAO": "v1",

    # ---- Mapeamento categoria_resposta por Nota ----
    "MAP_CATEGORIA_RESPOSTA": {10: "correta", 6: "quase_certa", 2: "razoavel", 0: "menos_correta"},

    # ---- Boosts por cargo×categoria ----
    "CATEGORY_BOOSTS_DEFAULT": {
        "C-LEVEL": {"Estratégia & Conselho": 20},
        "Diretor": {"Estratégia & Portfólio": 20, "Cadência de Execução": 10},
        "Gerente": {"Estratégia & Portfólio": 10, "Cadência de Execução": 20},
        "Coordenador": {"Dados & Orçamento": 20, "Cadência de Execução": 10},
        "Especialista": {"Dados & Orçamento": 20},
        "Analista": {"Dados & Orçamento": 20}
    },

    # ---- Prints ----
    "PRINT_Q_AMOSTRA": 2,
}

# =========================
# Helpers de caminho / normalização
# =========================
def _pjoin(*a) -> str:
    return os.path.join(*a)

def get_paths() -> Tuple[str, str, str, str]:
    base = CONFIG["BASE_DIR"]
    aux = _pjoin(base, CONFIG["AUX_DIR_NAME"])
    src = _pjoin(aux, CONFIG["SRC_FILENAME"])
    docs = _pjoin(base, CONFIG["DOCS_DIR_NAME"])
    return base, aux, src, docs

def _norm_posicao(s: str) -> str:
    s = (s or "").strip().lower()
    mapa = {
        "c-level": "C-LEVEL", "c level": "C-LEVEL", "clevel": "C-LEVEL",
        "diretor": "Diretor", "gerente": "Gerente", "coordenador": "Coordenador",
        "especialista": "Especialista", "analista": "Analista",
    }
    return mapa.get(s, s.title())

# =========================
# Conexão
# =========================
def get_conn(retries=3, wait=2):
    load_dotenv(Path(__file__).resolve().parent.parent / ".env")
    last = None
    for _ in range(retries):
        try:
            conn = pymysql.connect(
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
            return conn
        except Exception as e:
            last = e
            time.sleep(wait)
    raise last

def server_version(cur) -> str:
    cur.execute("SELECT DATABASE() AS db, VERSION() AS v")
    r = cur.fetchone()
    print(f"CONEXÃO COM BANCO - OK | db={r['db']} | ver={r['v']}")
    return r["v"]

# =========================
# CSV: leitura/normalização
# =========================
def try_read_csv(path: str) -> Tuple[pd.DataFrame, str, str]:
    print(f"\n[1/9] Leitura do CSV: {path}")
    last = None
    for enc in CONFIG["CSV_ENCODINGS_TRY"]:
        for sep in CONFIG["CSV_SEPARATORS_TRY"]:
            try:
                print(f"  - Tentando encoding='{enc}', sep={'auto' if sep is None else repr(sep)} …")
                df = pd.read_csv(
                    path,
                    sep=sep,
                    encoding=enc,
                    engine=CONFIG["CSV_ENGINE"],
                    quotechar=CONFIG["CSV_QUOTECHAR"],
                    escapechar=CONFIG["CSV_ESCAPECHAR"],
                    on_bad_lines=CONFIG["CSV_ON_BAD_LINES"],
                )
                if df.shape[1] >= 5:
                    df.columns = [str(c).strip() for c in df.columns]
                    print(f"    ✓ Lido com shape={df.shape}")
                    return df, enc, (sep if sep is not None else "auto")
                else:
                    print("    ! Poucas colunas detectadas; tentando outra combinação…")
            except Exception as e:
                last = e
                print(f"    ✗ Falhou ({type(e).__name__}): {e}")
    raise RuntimeError(f"Falha ao ler CSV. Último erro: {last}")

def apply_column_aliases(df: pd.DataFrame) -> pd.DataFrame:
    req = CONFIG["REQUIRED_COLS"]
    aliases = CONFIG["COLUMN_ALIASES"]
    ren, missing = {}, []
    for must in req:
        if must in df.columns:
            continue
        found = None
        for alt in aliases.get(must, []):
            if alt in df.columns:
                found = alt
                break
        if found:
            ren[found] = must
        else:
            missing.append(must)
    if ren:
        print(f"[2/9] Renomeando colunas (aliases): {ren}")
        df = df.rename(columns=ren)
    if missing:
        print("  ✗ ERRO: Colunas obrigatórias ausentes:", missing)
        print("    → Colunas encontradas:", list(df.columns))
        raise ValueError(f"Colunas obrigatórias ausentes: {missing}")
    print("[2/9] Colunas validadas.")
    return df

def normalize_df(df: pd.DataFrame) -> pd.DataFrame:
    print("[3/9] Normalizações (trim, quebras de linha, Nota)")
    df = df.fillna("")
    text_cols = ["Posição","Categoria","Modelo de Negócio","Tema","Questão","Alternativas","Gabarito"]
    if CONFIG["NORMALIZE_STRIP_NEWLINES"] or CONFIG["NORMALIZE_TRIM"]:
        for c in text_cols:
            s = df[c].astype(str)
            if CONFIG["NORMALIZE_STRIP_NEWLINES"]:
                s = s.str.replace("\r"," ",regex=False).str.replace("\n"," ",regex=False)
            if CONFIG["NORMALIZE_TRIM"]:
                s = s.str.strip()
            df[c] = s
    df["Nota"] = pd.to_numeric(df["Nota"], errors="coerce").fillna(0.0).astype(int)
    df["Posição"] = df["Posição"].map(_norm_posicao)
    print(f"  - Total linhas: {len(df)} | Notas únicas: {sorted(df['Nota'].unique().tolist())}")
    return df

def print_samples(df: pd.DataFrame):
    print("[4/9] Inventário rápido e amostras")
    try:
        print("  - Posições:", ", ".join(sorted(map(str, df['Posição'].unique()))))
        print("  - Categorias:", ", ".join(sorted(map(str, df['Categoria'].unique()))))
        print("  - Modelos:", ", ".join(sorted(map(str, df['Modelo de Negócio'].unique()))))
        k = CONFIG["PRINT_Q_AMOSTRA"]
        print(f"  - Primeiras {k} perguntas (amostra com alternativas):")
        tmp = df.copy()
        tmp["id_p"] = (tmp["Posição"]+"|"+tmp["Modelo de Negócio"]+"|"+tmp["Categoria"]+"|"+tmp["Tema"]+"|"+tmp["Questão"]).astype("category").cat.codes
        for pid in tmp["id_p"].drop_duplicates().head(k):
            bloco = tmp[tmp["id_p"] == pid][["Posição","Categoria","Tema","Questão","Alternativas","Nota"]]
            print(bloco.to_string(index=False))
            print("-"*80)
    except Exception as e:
        print("  (Aviso) Não consegui imprimir amostras:", e)

# =========================
# DB helpers (fetch/insert/update)
# =========================
def _fetchone(cur, sql, params=()):
    cur.execute(sql, params)
    return cur.fetchone()

def _fetchall(cur, sql, params=()):
    cur.execute(sql, params)
    return cur.fetchall()

def _insert(cur, sql, params=()):
    cur.execute(sql, params)
    return cur.lastrowid

# ---- helpers de ordem única por versão ----
def get_max_ordem(cur, id_versao: int) -> int:
    r = _fetchone(cur, "SELECT COALESCE(MAX(ordem),0) AS m FROM lp001_quiz_perguntas WHERE id_versao=%s", (id_versao,))
    return int(r["m"] or 0)

def is_ordem_taken(cur, id_versao: int, ordem: int, exclude_id: int = None) -> bool:
    if exclude_id:
        r = _fetchone(cur,
            "SELECT 1 FROM lp001_quiz_perguntas WHERE id_versao=%s AND ordem=%s AND id_pergunta<>%s LIMIT 1",
            (id_versao, ordem, exclude_id)
        )
    else:
        r = _fetchone(cur,
            "SELECT 1 FROM lp001_quiz_perguntas WHERE id_versao=%s AND ordem=%s LIMIT 1",
            (id_versao, ordem)
        )
    return r is not None

def next_free_ordem(cur, id_versao: int) -> int:
    return get_max_ordem(cur, id_versao) + 1

# =========================
# Upserts de cadastro
# =========================
def ensure_quiz(cur, slug: str, name: str) -> int:
    q = _fetchone(cur, "SELECT id_quiz FROM lp001_quiz WHERE slug=%s", (slug,))
    if not q:
        print("  - Criando quiz …")
        return _insert(cur,
            "INSERT INTO lp001_quiz (nome, slug, status, dt_criacao) VALUES (%s,%s,'ativo',NOW())",
            (name, slug)
        )
    print(f"  - Quiz existente id={q['id_quiz']}")
    return q["id_quiz"]

def ensure_version(cur, id_quiz: int, versao_label: str) -> int:
    v = _fetchone(cur,
        "SELECT id_versao FROM lp001_quiz_versao WHERE id_quiz=%s AND descricao=%s",
        (id_quiz, versao_label)
    )
    if not v:
        print(f"  - Criando versão '{versao_label}' …")
        return _insert(cur,
            "INSERT INTO lp001_quiz_versao (id_quiz, descricao, is_ab_test, data_publicacao, dt_criacao) "
            "VALUES (%s,%s,0,NOW(),NOW())",
            (id_quiz, versao_label)
        )
    print(f"  - Versão existente id={v['id_versao']}")
    return v["id_versao"]

def ensure_domains(cur, id_versao: int, categorias: List[str]) -> Dict[str,int]:
    uniq = sorted(set([c.strip() for c in categorias if str(c).strip()]))
    n = max(1, len(uniq))
    peso = round(1.0 / n, 4)
    id_by_cat = {}
    print(f"  - Domínios a garantir: {len(uniq)} (peso_base={peso})")
    for i, cat in enumerate(uniq, start=1):
        row = _fetchone(cur,
            "SELECT id_dominio FROM lp001_quiz_dominios WHERE id_versao=%s AND nome=%s",
            (id_versao, cat)
        )
        if row:
            id_by_cat[cat] = row["id_dominio"]
            cur.execute("UPDATE lp001_quiz_dominios SET peso=%s, ordem=%s WHERE id_dominio=%s",
                        (peso, i, id_by_cat[cat]))
        else:
            id_d = _insert(cur,
                "INSERT INTO lp001_quiz_dominios (id_versao, nome, peso, ordem) VALUES (%s,%s,%s,%s)",
                (id_versao, cat, peso, i)
            )
            id_by_cat[cat] = id_d
    print("  - Domínios prontos.")
    return id_by_cat

def ensure_cargos_and_map(cur, id_versao: int, cargos: List[str]) -> Dict[str,int]:
    """
    Política 1:1 por cargo em lp001_quiz_cargo_map (PRIMARY KEY = id_cargo).
    - Se já existir um vínculo para o cargo, atualiza para a versão alvo.
    - Se não existir, insere.
    """
    uniq = sorted(set([_norm_posicao(c) for c in cargos]))
    id_by_cargo = {}
    print(f"  - Cargos a garantir: {', '.join(uniq)}")
    for cargo in uniq:
        r = _fetchone(cur, "SELECT id_cargo FROM lp001_dom_cargos WHERE nome=%s", (cargo,))
        if not r:
            id_c = _insert(cur,
                "INSERT INTO lp001_dom_cargos (nome, ordem_hierarquia, dt_cadastro) VALUES (%s,%s,NOW())",
                (cargo, 0)
            )
        else:
            id_c = r["id_cargo"]
        id_by_cargo[cargo] = id_c

        rmap = _fetchone(cur,
            "SELECT id_cargo, id_versao FROM lp001_quiz_cargo_map WHERE id_cargo=%s",
            (id_c,)
        )
        if not rmap:
            _insert(cur,
                "INSERT INTO lp001_quiz_cargo_map (id_cargo, id_versao) VALUES (%s,%s)",
                (id_c, id_versao)
            )
            print(f"    • Vinculado cargo '{cargo}' (id={id_c}) à versão {id_versao}.")
        else:
            if int(rmap["id_versao"]) != int(id_versao):
                cur.execute(
                    "UPDATE lp001_quiz_cargo_map SET id_versao=%s WHERE id_cargo=%s",
                    (id_versao, id_c)
                )
                print(f"    • Cargo '{cargo}' (id={id_c}) já tinha vínculo (versão {rmap['id_versao']}); atualizado para {id_versao}.")
            else:
                print(f"    • Cargo '{cargo}' (id={id_c}) já vinculado à versão alvo ({id_versao}).")
    print("  - Cargos e vínculo versão×cargo (1:1) prontos.")
    return id_by_cargo

def upsert_pergunta(cur, id_versao:int, id_dominio:int, ordem:int,
                    texto:str, tema:str, modelo:str, cargo:str) -> int:
    """
    Garante unicidade por (id_versao, id_dominio, branch_key, texto).
    Mantém 'ordem' única por versão; se colisão, realoca para MAX(ordem)+1.
    """
    # Monta branch_key ANTES de usar e respeita VARCHAR(60)
    _cargo  = (cargo or "").strip()
    _modelo = (modelo or "").strip()
    branch_key = f"cargo={_cargo}|modelo={_modelo}"
    if len(branch_key) > 60:
        branch_key = branch_key[:60]

    glossario_json = json.dumps({"tema": tema, "modelo": _modelo}, ensure_ascii=False)

    # Busca considerando branch_key (alinha com o índice id_versao,id_dominio,branch_key,texto(191))
    row = _fetchone(cur,
        "SELECT id_pergunta, ordem FROM lp001_quiz_perguntas "
        "WHERE id_versao=%s AND id_dominio=%s AND branch_key=%s AND texto=%s",
        (id_versao, id_dominio, branch_key, texto)
    )

    if row:
        idp = int(row["id_pergunta"])
        target_ordem = int(ordem)
        if is_ordem_taken(cur, id_versao, target_ordem, exclude_id=idp):
            target_ordem = next_free_ordem(cur, id_versao)
        cur.execute(
            "UPDATE lp001_quiz_perguntas "
            "SET ordem=%s, tipo='single_choice', branch_key=%s, glossario_json=%s "
            "WHERE id_pergunta=%s",
            (target_ordem, branch_key, glossario_json, idp)
        )
        return idp

    # Não achou: inserir
    target_ordem = int(ordem)
    if is_ordem_taken(cur, id_versao, target_ordem):
        target_ordem = next_free_ordem(cur, id_versao)

    return _insert(cur,
        "INSERT INTO lp001_quiz_perguntas "
        "(id_versao, id_dominio, ordem, texto, glossario_json, tipo, branch_key) "
        "VALUES (%s,%s,%s,%s,%s,'single_choice',%s)",
        (id_versao, id_dominio, target_ordem, texto, glossario_json, branch_key)
    )

def upsert_opcao(cur, id_pergunta:int, ordem:int, texto:str, explicacao:str, nota:int):
    """
    Atualiza/insere opção da pergunta. Não usa coluna 'versao' (inexistente na tabela).
    """
    cat = CONFIG["MAP_CATEGORIA_RESPOSTA"].get(int(nota), "razoavel")
    row = _fetchone(cur,
        "SELECT id_opcao FROM lp001_quiz_opcoes WHERE id_pergunta=%s AND ordem=%s",
        (id_pergunta, ordem)
    )
    if row:
        cur.execute(
            "UPDATE lp001_quiz_opcoes SET texto=%s, explicacao=%s, score=%s, categoria_resposta=%s "
            "WHERE id_opcao=%s",
            (texto, explicacao, nota, cat, row["id_opcao"])
        )
    else:
        _insert(cur,
            "INSERT INTO lp001_quiz_opcoes (id_pergunta, ordem, texto, explicacao, score, categoria_resposta) "
            "VALUES (%s,%s,%s,%s,%s,%s)",
            (id_pergunta, ordem, texto, explicacao, nota, cat)
        )

def upsert_domain_weights_overrides(
    cur,
    id_versao: int,
    id_by_dom: Dict[str, int],
    id_by_cargo: Dict[str, int],
    boosts: Dict[str, Dict[str, float]],
    peso_base: float
) -> int:
    """
    Insere/atualiza overrides de pesos por cargo×domínio.
    Logs detalhados; retorna quantidade de inserts novos.
    """
    inseridos = 0
    total_updates = 0
    print("  - Aplicando overrides de pesos (cargo×domínio)…")
    if not boosts:
        print("    • Nenhum boost configurado (CATEGORY_BOOSTS_DEFAULT vazio).")
        return 0

    for cargo, catmap in boosts.items():
        id_cargo = id_by_cargo.get(cargo)
        if not id_cargo:
            print(f"    • AVISO: cargo '{cargo}' não encontrado no mapa; pulando.")
            continue
        for categoria, boost_pct in catmap.items():
            id_dom = id_by_dom.get(categoria)
            if not id_dom:
                print(f"    • AVISO: categoria '{categoria}' não pertence à versão; pulando.")
                continue

            row = _fetchone(
                cur,
                "SELECT id_override FROM lp001_quiz_domain_weights_overrides "
                "WHERE id_versao=%s AND id_dominio=%s AND id_cargo=%s",
                (id_versao, id_dom, id_cargo),
            )
            if row:
                cur.execute(
                    "UPDATE lp001_quiz_domain_weights_overrides "
                    "SET peso_base=%s, peso_extra=%s, obs=%s "
                    "WHERE id_override=%s",
                    (peso_base, boost_pct, "peso default + boost por cargo", row["id_override"]),
                )
                total_updates += cur.rowcount
            else:
                _insert(
                    cur,
                    "INSERT INTO lp001_quiz_domain_weights_overrides "
                    "(id_versao, id_dominio, id_cargo, peso_base, peso_extra, obs, dt_criacao) "
                    "VALUES (%s,%s,%s,%s,%s,%s,NOW())",
                    (id_versao, id_dom, id_cargo, peso_base, boost_pct, "peso default + boost por cargo"),
                )
                inseridos += 1

    print(f"  ✓ Overrides: inseridos={inseridos} | atualizados={total_updates}")
    return inseridos

# =========================
# PURGE (conteúdo do quiz alvo) — com LOGS DETALHADOS
# =========================
def _count_by_versions(cur, table: str, where: str, ids: List[int]) -> int:
    if not ids:
        return 0
    sql = f"SELECT COUNT(*) AS n FROM {table} WHERE {where} IN (" + ",".join(["%s"]*len(ids)) + ")"
    cur.execute(sql, ids)
    return int(cur.fetchone()["n"])

def _count_options_from_perguntas(cur, ids_perg: List[int]) -> int:
    if not ids_perg:
        return 0
    sql = "SELECT COUNT(*) AS n FROM lp001_quiz_opcoes WHERE id_pergunta IN (" + ",".join(["%s"]*len(ids_perg)) + ")"
    cur.execute(sql, ids_perg)
    return int(cur.fetchone()["n"])

def _safe_exec(cur, sql: str, params: Tuple = ()):
    """Executa SQL e retorna (ok:bool, rowcount:int, err:Exception|None)."""
    try:
        cur.execute(sql, params)
        return True, cur.rowcount, None
    except Exception as e:
        return False, -1, e

def _log_sql_error(prefix: str, e: Exception):
    if isinstance(e, mysql_err.OperationalError):
        code = e.args[0] if e.args else None
        msg  = e.args[1] if len(e.args) > 1 else str(e)
        print(f"  ✗ {prefix} | OperationalError {code}: {msg}")
        if code == 1142:
            print("    → Possível falta de permissão para DELETE/TRUNCATE nesta tabela/DB/usuário.")
        if code in (1451, 1452):
            print("    → Falha de FK (1451/1452). Verifique ordem de deleção e FKs.")
    elif isinstance(e, mysql_err.ProgrammingError):
        code = e.args[0] if e.args else None
        msg  = e.args[1] if len(e.args) > 1 else str(e)
        print(f"  ✗ {prefix} | ProgrammingError {code}: {msg}")
    else:
        print(f"  ✗ {prefix} | {type(e).__name__}: {e}")

def purge_content_for_quiz(conn, id_quiz: int, dry_run: bool):
    print("\n[ PURGE ] Limpando conteúdo anterior do quiz (somente conteúdo; sem runtime)")
    try:
        with conn.cursor() as cur:
            versoes = _fetchall(cur, "SELECT id_versao, descricao FROM lp001_quiz_versao WHERE id_quiz=%s", (id_quiz,))
            if not versoes:
                print("  - Nenhuma versão encontrada para este quiz. Nada a limpar.")
                return

            ids_versao = [v["id_versao"] for v in versoes]
            labels     = [v["descricao"] for v in versoes]
            print(f"  - Versões encontradas: {', '.join([f'{d}(id={i})' for i,d in zip(ids_versao, labels)])}")

            # Coletar perguntas dessas versões
            cur.execute(
                "SELECT id_pergunta FROM lp001_quiz_perguntas WHERE id_versao IN (" +
                ",".join(["%s"]*len(ids_versao)) + ")", ids_versao
            )
            ids_perg = [r["id_pergunta"] for r in cur.fetchall()]

            # Contagens BEFORE
            cnt = {
                "overrides": _count_by_versions(cur, "lp001_quiz_domain_weights_overrides", "id_versao", ids_versao),
                "dominios":  _count_by_versions(cur, "lp001_quiz_dominios", "id_versao", ids_versao),
                "cargo_map": _count_by_versions(cur, "lp001_quiz_cargo_map", "id_versao", ids_versao),
                "perguntas": len(ids_perg),
                "opcoes":    _count_options_from_perguntas(cur, ids_perg),
            }
            # Regras opcionais
            try:
                cnt["recom_rules"] = _count_by_versions(cur, "lp001_quiz_recommendation_rules", "id_versao", ids_versao)
            except Exception:
                cnt["recom_rules"] = None
            try:
                cnt["check_rules"] = _count_by_versions(cur, "lp001_quiz_checklist_rules", "id_versao", ids_versao)
            except Exception:
                cnt["check_rules"] = None

            print("  - Contagens BEFORE (por conjunto de versões):")
            print(f"      overrides: {cnt['overrides']}")
            print(f"      cargo_map: {cnt['cargo_map']}")
            print(f"      dominios : {cnt['dominios']}")
            print(f"      perguntas: {cnt['perguntas']}")
            print(f"      opcoes   : {cnt['opcoes']}")
            print(f"      recom_rules: {cnt['recom_rules'] if cnt['recom_rules'] is not None else '(tabela ausente)'}")
            print(f"      check_rules: {cnt['check_rules'] if cnt['check_rules'] is not None else '(tabela ausente)'}")

            if dry_run:
                print("  (dry-run) Não executando DELETEs. SQLs que seriam executados em ordem:")
                print("    SET FOREIGN_KEY_CHECKS=0;")
                print("    DELETE FROM lp001_quiz_domain_weights_overrides WHERE id_versao IN (...);")
                if ids_perg:
                    print("    DELETE FROM lp001_quiz_opcoes WHERE id_pergunta IN (...);")
                    print("    DELETE FROM lp001_quiz_perguntas WHERE id_pergunta IN (...);")
                print("    DELETE FROM lp001_quiz_cargo_map WHERE id_versao IN (...);")
                print("    DELETE FROM lp001_quiz_dominios WHERE id_versao IN (...);")
                print("    DELETE FROM lp001_quiz_recommendation_rules WHERE id_versao IN (...); (se existir)")
                print("    DELETE FROM lp001_quiz_checklist_rules WHERE id_versao IN (...); (se existir)")
                print("    SET FOREIGN_KEY_CHECKS=1;")
                return

            # Desativar FKs (sessão)
            ok, _, err = _safe_exec(cur, "SET FOREIGN_KEY_CHECKS=0")
            if ok:
                print("  • FOREIGN_KEY_CHECKS=0 (ok)")
            else:
                _log_sql_error("SET FOREIGN_KEY_CHECKS=0", err)

            # Deletar overrides
            ok, rc, err = _safe_exec(
                cur,
                "DELETE FROM lp001_quiz_domain_weights_overrides WHERE id_versao IN (" +
                ",".join(["%s"]*len(ids_versao)) + ")",
                tuple(ids_versao)
            )
            if ok:
                print(f"  ✓ DELETE overrides | afetadas={rc}")
            else:
                _log_sql_error("DELETE overrides", err)

            # Opções → Perguntas
            if ids_perg:
                ok, rc, err = _safe_exec(
                    cur,
                    "DELETE FROM lp001_quiz_opcoes WHERE id_pergunta IN (" +
                    ",".join(["%s"]*len(ids_perg)) + ")",
                    tuple(ids_perg)
                )
                if ok:
                    print(f"  ✓ DELETE opcoes    | afetadas={rc}")
                else:
                    _log_sql_error("DELETE opcoes", err)

                ok, rc, err = _safe_exec(
                    cur,
                    "DELETE FROM lp001_quiz_perguntas WHERE id_pergunta IN (" +
                    ",".join(["%s"]*len(ids_perg)) + ")",
                    tuple(ids_perg)
                )
                if ok:
                    print(f"  ✓ DELETE perguntas | afetadas={rc}")
                else:
                    _log_sql_error("DELETE perguntas", err)
            else:
                print("  - Nenhuma pergunta para remover.")

            # cargo_map
            ok, rc, err = _safe_exec(
                cur,
                "DELETE FROM lp001_quiz_cargo_map WHERE id_versao IN (" +
                ",".join(["%s"]*len(ids_versao)) + ")",
                tuple(ids_versao)
            )
            if ok:
                print(f"  ✓ DELETE cargo_map | afetadas={rc}")
            else:
                _log_sql_error("DELETE cargo_map", err)

            # dominios
            ok, rc, err = _safe_exec(
                cur,
                "DELETE FROM lp001_quiz_dominios WHERE id_versao IN (" +
                ",".join(["%s"]*len(ids_versao)) + ")",
                tuple(ids_versao)
            )
            if ok:
                print(f"  ✓ DELETE dominios  | afetadas={rc}")
            else:
                _log_sql_error("DELETE dominios", err)

            # Regras opcionais
            try:
                ok, rc, err = _safe_exec(
                    cur,
                    "DELETE FROM lp001_quiz_recommendation_rules WHERE id_versao IN (" +
                    ",".join(["%s"]*len(ids_versao)) + ")",
                    tuple(ids_versao)
                )
                if ok:
                    print(f"  ✓ DELETE recom_rules | afetadas={rc}")
                else:
                    _log_sql_error("DELETE recom_rules", err)
            except Exception as e:
                print("  (Aviso) lp001_quiz_recommendation_rules ausente ou sem permissão; ignorado.")
                _log_sql_error("DELETE recom_rules", e)

            try:
                ok, rc, err = _safe_exec(
                    cur,
                    "DELETE FROM lp001_quiz_checklist_rules WHERE id_versao IN (" +
                    ",".join(["%s"]*len(ids_versao)) + ")",
                    tuple(ids_versao)
                )
                if ok:
                    print(f"  ✓ DELETE check_rules | afetadas={rc}")
                else:
                    _log_sql_error("DELETE check_rules", err)
            except Exception as e:
                print("  (Aviso) lp001_quiz_checklist_rules ausente ou sem permissão; ignorado.")
                _log_sql_error("DELETE check_rules", e)

            # Reativar FKs
            ok, _, err = _safe_exec(cur, "SET FOREIGN_KEY_CHECKS=1")
            if ok:
                print("  • FOREIGN_KEY_CHECKS=1 (ok)")
            else:
                _log_sql_error("SET FOREIGN_KEY_CHECKS=1", err)

            # Contagens AFTER
            cur.execute(
                "SELECT id_pergunta FROM lp001_quiz_perguntas WHERE id_versao IN (" +
                ",".join(["%s"]*len(ids_versao)) + ")", ids_versao
            )
            ids_perg_after = [r["id_pergunta"] for r in cur.fetchall()]
            aft = {
                "overrides": _count_by_versions(cur, "lp001_quiz_domain_weights_overrides", "id_versao", ids_versao),
                "dominios":  _count_by_versions(cur, "lp001_quiz_dominios", "id_versao", ids_versao),
                "cargo_map": _count_by_versions(cur, "lp001_quiz_cargo_map", "id_versao", ids_versao),
                "perguntas": len(ids_perg_after),
                "opcoes":    _count_options_from_perguntas(cur, ids_perg_after),
            }
            print("  - Contagens AFTER:")
            print(f"      overrides: {aft['overrides']}")
            print(f"      cargo_map: {aft['cargo_map']}")
            print(f"      dominios : {aft['dominios']}")
            print(f"      perguntas: {aft['perguntas']}")
            print(f"      opcoes   : {aft['opcoes']}")
            print("  ✓ Purge concluído (conteúdo).")

    except Exception as e:
        print(f"  ✗ ERRO no purge: {e}")
        traceback.print_exc()
        raise

# =========================
# Checagens finais (SQLs do relatório)
# =========================
SQL_BLOCKS = {
    "versoes_quiz": """
-- Versões do quiz
SELECT q.id_quiz, q.slug, v.id_versao, v.descricao
FROM lp001_quiz q
JOIN lp001_quiz_versao v ON v.id_quiz = q.id_quiz
WHERE q.slug='lp001';
""".strip(),
    "dominios_perguntas_opcoes": """
-- Domínios / Perguntas / Opções
SELECT COUNT(*) AS dominios FROM lp001_quiz_dominios WHERE id_versao=%s;
SELECT COUNT(*) AS perguntas FROM lp001_quiz_perguntas WHERE id_versao=%s;
SELECT COUNT(*) AS opcoes FROM lp001_quiz_opcoes WHERE id_pergunta IN
  (SELECT id_pergunta FROM lp001_quiz_perguntas WHERE id_versao=%s);
""".strip(),
    "overrides_cargo_map": """
-- Overrides e vínculo cargo→versão
SELECT COUNT(*) AS overrides FROM lp001_quiz_domain_weights_overrides WHERE id_versao=%s;
SELECT c.nome AS cargo, m.id_versao
FROM lp001_quiz_cargo_map m
JOIN lp001_dom_cargos c ON c.id_cargo=m.id_cargo
WHERE m.id_versao=%s
ORDER BY cargo;
""".strip(),
}

def run_final_checks_and_print(cur, id_versao: int) -> str:
    """
    Executa as checagens finais, imprime no stdout e também
    retorna uma string formatada com os RESULTADOS para ser incluída no relatório.
    """
    out_lines = []
    out_lines.append("### Resultados das verificações (após a carga)\n")

    print("\n[CHECKS FINAIS] SQLs de validação pós-carga")

    # Versões do quiz (por slug)
    print("\n-- Versões do quiz (slug='lp001'):")
    cur.execute("""
        SELECT q.id_quiz, q.slug, v.id_versao, v.descricao
        FROM lp001_quiz q
        JOIN lp001_quiz_versao v ON v.id_quiz = q.id_quiz
        WHERE q.slug='lp001'
        ORDER BY v.id_versao DESC
    """)
    rows = cur.fetchall()
    out_lines.append("**Versões do quiz (slug='lp001')**")
    if rows:
        for r in rows:
            line = f"- id_quiz={r['id_quiz']} | slug={r['slug']} | id_versao={r['id_versao']} | descricao={r['descricao']}"
            print(" ", line)
            out_lines.append(line)
    else:
        print("  (nenhum registro)")
        out_lines.append("(nenhum registro)")
    out_lines.append("")

    # Domínios / Perguntas / Opções (por versão)
    print(f"\n-- Contagens por versão (id_versao={id_versao}):")
    out_lines.append(f"**Contagens por versão (id_versao={id_versao})**")
    for label, sql in [
        ("dominios", "SELECT COUNT(*) AS n FROM lp001_quiz_dominios WHERE id_versao=%s"),
        ("perguntas", "SELECT COUNT(*) AS n FROM lp001_quiz_perguntas WHERE id_versao=%s"),
        ("opcoes", "SELECT COUNT(*) AS n FROM lp001_quiz_opcoes WHERE id_pergunta IN (SELECT id_pergunta FROM lp001_quiz_perguntas WHERE id_versao=%s)"),
    ]:
        cur.execute(sql, (id_versao,))
        n = cur.fetchone()["n"]
        print(f"  {label:10s}: {n}")
        out_lines.append(f"- {label}: {n}")
    out_lines.append("")

    # Overrides / cargo_map (por versão)
    print(f"\n-- Overrides e vínculo cargo→versão (id_versao={id_versao}):")
    cur.execute("SELECT COUNT(*) AS n FROM lp001_quiz_domain_weights_overrides WHERE id_versao=%s", (id_versao,))
    n_over = cur.fetchone()["n"]
    print(f"  overrides : {n_over}")
    out_lines.append(f"**Overrides e vínculo cargo→versão (id_versao={id_versao})**")
    out_lines.append(f"- overrides: {n_over}")

    cur.execute("""
        SELECT c.nome AS cargo, m.id_versao
        FROM lp001_quiz_cargo_map m
        JOIN lp001_dom_cargos c ON c.id_cargo=m.id_cargo
        WHERE m.id_versao=%s
        ORDER BY cargo
    """, (id_versao,))
    rows = cur.fetchall()
    if rows:
        for r in rows:
            line = f"- cargo={r['cargo']} | id_versao={r['id_versao']}"
            print(" ", line)
            out_lines.append(line)
    else:
        print("  (nenhum vínculo cargo→versão)")
        out_lines.append("(nenhum vínculo cargo→versão)")

    # Também imprime o bloco de SQL para documentação no relatório
    print("\n-- Bloco SQL utilizado nas checagens (para documentação):\n")
    print(SQL_BLOCKS["versoes_quiz"])
    print()
    print(SQL_BLOCKS["dominios_perguntas_opcoes"] % (id_versao, id_versao, id_versao))
    print()
    print(SQL_BLOCKS["overrides_cargo_map"] % (id_versao, id_versao))

    return "\n".join(out_lines).strip()

# =========================
# Util: salvar relatório Markdown com todo o log + resultados de checks
# =========================
def save_markdown_report(docs_dir: str, slug: str, versao_label: str, id_versao: int,
                         full_log_text: str, checks_results_text: str = ""):
    os.makedirs(docs_dir, exist_ok=True)
    ts = datetime.datetime.now().strftime("%Y%m%d_%H%M%S")
    fname = f"{slug.upper()}_carga_report_{ts}.md"
    fpath = _pjoin(docs_dir, fname)

    header = []
    header.append(f"# Relatório de Carga do Quiz ({slug.upper()})")
    header.append("")
    header.append(f"- **Data/Hora**: {datetime.datetime.now().isoformat(sep=' ', timespec='seconds')}")
    header.append(f"- **Versão alvo**: {versao_label} (id_versao={id_versao})")
    header.append(f"- **Arquivo**: {fname}")
    header.append("")
    header.append("## SQLs de verificação utilizados")
    header.append("")
    header.append("```sql")
    header.append(SQL_BLOCKS["versoes_quiz"])
    header.append("")
    header.append("-- Os três abaixo recebem id_versao como parâmetro")
    header.append(SQL_BLOCKS["dominios_perguntas_opcoes"] % ("{id_versao}", "{id_versao}", "{id_versao}"))
    header.append("")
    header.append(SQL_BLOCKS["overrides_cargo_map"] % ("{id_versao}", "{id_versao}"))
    header.append("```")

    # Resultados executados após a carga
    if checks_results_text:
        header.append("")
        header.append("## Resultados das verificações (executadas após a carga)")
        header.append("")
        header.append(checks_results_text)

    header.append("")
    header.append("## Log completo da execução")
    header.append("")
    header.append("```text")
    header.append(full_log_text.strip())
    header.append("```")
    content = "\n".join(header).replace("{id_versao}", str(id_versao))

    with open(fpath, "w", encoding="utf-8") as f:
        f.write(content)
    return fpath

# =========================
# Execução principal
# =========================
def main():
    parser = argparse.ArgumentParser(description="Carga de conteúdo do Quiz LP001")
    parser.add_argument("--dry-run", action="store_true", help="Apenas valida CSV e imprime contagens (não grava).")
    parser.add_argument("--purge", action="store_true", help="Limpa conteúdo do quiz lp001 ANTES de recarregar.")
    parser.add_argument("--reset-versao", dest="reset_versao", default=None,
                        help="Cria nova versão (ex.: v2) e carrega nela (sem apagar versões antigas).")
    args = parser.parse_args()

    # ===== Tee de stdout para capturar todo o log =====
    orig_stdout = sys.stdout
    buffer = io.StringIO()
    class _Tee(io.TextIOBase):
        def write(self, s):
            orig_stdout.write(s)
            buffer.write(s)
            return len(s)
        def flush(self):
            orig_stdout.flush()
            buffer.flush()
    sys.stdout = _Tee()

    versao_label = None
    id_versao = None
    report_path = None
    checks_results_text = ""

    try:
        print("==== CARGA DE CONTEÚDO | QUIZ (LP001) — INÍCIO ====")
        BASE, AUX, SRC, DOCS = get_paths()
        print(f"[PATH] BASE={BASE}\n       AUX={AUX}\n       SRC={SRC}\n       DOCS={DOCS}")
        print(f"[FLAGS] dry_run={args.dry_run} | purge={args.purge} | reset_versao={args.reset_versao or '-'}")

        # 0) Conexão
        try:
            conn = get_conn()
            with conn.cursor() as cur:
                server_version(cur)
        except Exception as e:
            print(f"[FATAL] Falha de conexão: {e}")
            traceback.print_exc()
            sys.stdout.flush()
            save_markdown_report(DOCS, CONFIG["QUIZ_SLUG"], "-", -1, buffer.getvalue(), "")
            sys.exit(1)

        # 1..4) CSV
        try:
            df_raw, enc, sep = try_read_csv(SRC)
            print(f"[INFO] CSV OK | encoding={enc} | sep={sep} | shape={df_raw.shape}")
            df_norm = apply_column_aliases(df_raw)
            df_norm = normalize_df(df_norm)
            print_samples(df_norm)
        except Exception as e:
            print(f"[FATAL] Erro ao processar CSV: {e}")
            traceback.print_exc()
            try: conn.close()
            except: pass
            sys.stdout.flush()
            save_markdown_report(DOCS, CONFIG["QUIZ_SLUG"], "-", -1, buffer.getvalue(), "")
            sys.exit(2)

        # 5) Preparar bases agregadas
        print("\n[5/9] Preparando perguntas únicas e ordem das opções")
        key_cols = ["Posição", "Categoria", "Modelo de Negócio", "Tema", "Questão"]
        dfx = df_norm.copy()

        # Perguntas únicas preservando a ordem de aparição no CSV
        perguntas = dfx[key_cols].drop_duplicates(keep="first").reset_index(drop=True)
        dfx["ordem_opcao"] = dfx.groupby(key_cols).cumcount() + 1
        print(f"  - Perguntas únicas: {len(perguntas)} (esperado ~60)")
        print(f"  - Total de linhas de alternativas: {len(dfx)} (esperado ~240)")

        # 6) Gravando no banco
        print("\n[6/9] Gravando no banco (quiz, versão, domínios, cargos, perguntas, opções, overrides)")
        try:
            with conn.cursor() as cur:
                # Quiz
                id_quiz = ensure_quiz(cur, CONFIG["QUIZ_SLUG"], CONFIG["QUIZ_NAME"])
                print(f"  ✓ Quiz id={id_quiz}")

                # Purge se solicitado
                if args.purge:
                    if args.dry_run:
                        print("  (dry-run) Purge solicitado, mas NÃO será executado em dry-run.")
                    else:
                        purge_content_for_quiz(conn, id_quiz, dry_run=False)
                        conn.commit()

                # Versão alvo
                versao_label = args.reset_versao.strip() if args.reset_versao else CONFIG["DEFAULT_VERSAO"]
                id_versao = ensure_version(cur, id_quiz, versao_label)
                print(f"  ✓ Versão alvo: '{versao_label}' (id={id_versao})")

                # Encerrar cedo no dry-run
                if args.dry_run:
                    print("\n[SUCESSO] Dry-run concluído. Nada foi gravado.")
                    try: conn.close()
                    except: pass
                    sys.stdout.flush()
                    report_path = save_markdown_report(DOCS, CONFIG["QUIZ_SLUG"], versao_label, id_versao, buffer.getvalue(), "")
                    print(f"\n[RELATÓRIO] Salvo em: {report_path}")
                    print("\n==== CARGA (dry-run) | CONCLUÍDO ====")
                    return

                # Domínios
                categorias = perguntas["Categoria"].tolist()
                id_by_dom = ensure_domains(cur, id_versao, categorias)

                # Cargos + map
                cargos = perguntas["Posição"].tolist()
                id_by_cargo = ensure_cargos_and_map(cur, id_versao, cargos)

                # ---- Ordem única por versão: começamos de MAX(ordem)+1 ----
                base_ordem = get_max_ordem(cur, id_versao)
                print(f"  - Ordem inicial (MAX atual da versão {id_versao}): {base_ordem}")

                # Perguntas
                p_map, inserted_p = {}, 0
                for i, r in perguntas.iterrows():
                    cargo = r["Posição"]; cat = r["Categoria"]; modelo = r["Modelo de Negócio"]; tema = r["Tema"]
                    texto = r["Questão"]
                    id_dom = id_by_dom.get(cat)
                    desired_ordem = base_ordem + i + 1  # sequência contínua global
                    idp = upsert_pergunta(cur, id_versao, id_dom, desired_ordem, texto, tema, modelo, cargo)
                    p_map[(cargo, modelo, cat, tema, texto)] = idp
                    inserted_p += 1
                print(f"  ✓ Perguntas inseridas/atualizadas: {inserted_p}")

                # Opções
                inserted_o = 0
                for _, r in dfx.iterrows():
                    key = (r["Posição"], r["Modelo de Negócio"], r["Categoria"], r["Tema"], r["Questão"])
                    idp = p_map[key]
                    upsert_opcao(cur, idp, int(r["ordem_opcao"]), r["Alternativas"], r["Gabarito"], int(r["Nota"]))
                    inserted_o += 1
                print(f"  ✓ Opções inseridas/atualizadas: {inserted_o}")

                # Overrides de peso
                n_dom = max(1, len(id_by_dom))
                peso_base = round(1.0 / n_dom, 4)
                inseridos_boost = upsert_domain_weights_overrides(
                    cur, id_versao, id_by_dom, id_by_cargo, CONFIG["CATEGORY_BOOSTS_DEFAULT"], peso_base
                )
                print(f"  ✓ Overrides de pesos inseridos/atualizados: {inseridos_boost}")

            conn.commit()
            print("\n[SUCESSO] Commit realizado.")
        except Exception as e:
            print("\n[ERRO] Falha na gravação — executando rollback.")
            try:
                conn.rollback()
            except:
                pass
            print(f"Tipo: {type(e).__name__} | Detalhe: {e}")
            traceback.print_exc()
            try:
                with conn.cursor() as cur:
                    cur.execute("SHOW ENGINE INNODB STATUS")
                    row = cur.fetchone()
                    if row and 'Status' in row and row['Status']:
                        print("\n[DIAGNÓSTICO INNODB]\n", (row['Status'] or '')[:3500])
            except Exception:
                pass
            try:
                conn.close()
            except:
                pass
            sys.stdout.flush()
            save_markdown_report(DOCS, CONFIG["QUIZ_SLUG"], versao_label or "-", id_versao or -1, buffer.getvalue(), "")
            sys.exit(3)

        # 7) Resumo
        print("\n[7/9] Resumo:")
        print(f"  • Quiz: {CONFIG['QUIZ_SLUG']} | Versão: {versao_label}")
        print(f"  • Perguntas (únicas): {inserted_p}")
        print(f"  • Opções (total): {inserted_o}")
        print(f"  • Overrides aplicados: {inseridos_boost} (peso_base={peso_base})")

        # 7.1) Checagens finais (incluídas no log e relatório)
        try:
            with conn.cursor() as cur:
                checks_results_text = run_final_checks_and_print(cur, id_versao)
        except Exception as e:
            print(f"[AVISO] Falha ao executar checks finais: {e}")

        # 8) Próximos passos
        print("\n[8/9] Próximos passos:")
        print("  - Implementar runtime (sessões, respostas, cálculo e pdf/logs) no backend.")
        print("  - (Opcional) Popular lp001_quiz_result_profiles, recommendation_rules e checklist_rules.")

        try:
            conn.close()
        except:
            pass
        print("\n[9/9] FIM")
        print("==== CARGA DE CONTEÚDO | CONCLUÍDO ====")

        # Salvar relatório
        sys.stdout.flush()
        report_path = save_markdown_report(DOCS, CONFIG["QUIZ_SLUG"], versao_label, id_versao,
                                           buffer.getvalue(), checks_results_text)
        print(f"\n[RELATÓRIO] Salvo em: {report_path}")

    finally:
        # Restaura stdout
        sys.stdout = orig_stdout

if __name__ == "__main__":
    main()
