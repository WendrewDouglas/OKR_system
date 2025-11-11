#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
Importa LP Quizz-01/documents/questionario.csv para as tabelas:
 - lp001_quiz_dominios
 - lp001_quiz_perguntas
 - lp001_quiz_opcoes

Uso:
  Servidor (recomendado):
    $ cd ~/public_html/OKR_system/Ambiente_Python
    $ python3 -m venv .venv && source .venv/bin/activate
    $ pip install pymysql python-dotenv
    $ python import_questionario.py --versao 4 \
        --csv "../LP/Quizz-01/documents/questionario.csv"

  PC (via túnel SSH local 3307→remoto 3306):
    PS> ssh -N -L 3307:127.0.0.1:3306 usuario@seu_servidor
    PS> .venv\Scripts\activate
    PS> pip install pymysql python-dotenv
    PS> python import_questionario.py --versao 4 `
         --csv "C:\Meus_Projetos\OKRsystem\public_html\OKR_system\LP\Quizz-01\documents\questionario.csv" `
         --host 127.0.0.1 --port 3307
"""

import argparse
import csv
import os
from pathlib import Path
from typing import Optional

import pymysql
from dotenv import load_dotenv

# =========================
# 1) CARREGA .env igual ao PHP
# =========================
BASE_DIR = Path(__file__).resolve().parent                          # .../OKR_system/Ambiente_Python
PROJECT_ROOT = BASE_DIR.parent                                      # .../OKR_system
ENV_PATH = PROJECT_ROOT / ".env"
load_dotenv(ENV_PATH)  # se existir, carrega; não estoura erro

# =========================
# 2) CONFIG PADRÃO (podem ser sobrescritos via args)
# =========================
DEFAULTS = {
    "DB_HOST": os.getenv("DB_HOST", "localhost"),
    "DB_PORT": int(os.getenv("DB_PORT", "3306")),
    "DB_USER": os.getenv("DB_USER", "root"),
    "DB_PASS": os.getenv("DB_PASS", ""),
    "DB_NAME": os.getenv("DB_NAME", "planni40_okr"),
    "DB_CHARSET": os.getenv("DB_CHARSET", "utf8mb4"),
    "DB_COLLATION": os.getenv("DB_COLLATION", "utf8mb4_unicode_ci"),
    "QUIZ_VERSAO_ID": os.getenv("QUIZ_VERSAO_ID", ""),
}

# Caminho padrão do CSV se não for passado por --csv
CSV_DEFAULT = (PROJECT_ROOT / "LP" / "Quizz-01" / "documents" / "questionario.csv").resolve()

# Cabeçalhos esperados (flexível)
HEADER_MAP = {
    "dominio": ["dominio", "domínio", "categoria", "area", "área"],
    "ordem": ["ordem", "ordem_pergunta", "n", "numero", "número"],
    "contexto": ["contexto", "enunciado", "intro"],
    "pergunta": ["pergunta", "texto", "questao", "questão"],
    "A": ["a", "alt_a", "alternativa_a"],
    "B": ["b", "alt_b", "alternativa_b"],
    "C": ["c", "alt_c", "alternativa_c"],
    "D": ["d", "alt_d", "alternativa_d"],
    "correta": ["correta", "gabarito", "letra_correta", "resp"],
    "correta_texto": ["correta_texto", "gabarito_texto", "resposta_correta_texto"],
}

def norm(s: Optional[str]) -> str:
    return (s or "").strip()

def detect_delimiter(file_path: Path) -> str:
    with open(file_path, "r", encoding="utf-8-sig", newline="") as f:
        sample = f.read(4096)
    try:
        dialect = csv.Sniffer().sniff(sample, delimiters=",;|\t")
        return dialect.delimiter
    except Exception:
        return ";" if sample.count(";") > sample.count(",") else ","

def map_headers(header_row):
    lower = [h.strip().lower() for h in header_row]
    mapping = {}
    for canon, aliases in HEADER_MAP.items():
        for a in aliases:
            if a in lower:
                mapping[canon] = lower.index(a)
                break
    missing = [k for k in ["dominio","ordem","pergunta","A","B","C","D","correta"] if k not in mapping]
    if missing:
        raise ValueError(f"CSV sem colunas obrigatórias: faltando {missing}. Cabeçalho lido: {lower}")
    return mapping

def get_conn(cfg):
    conn = pymysql.connect(
        host=cfg["DB_HOST"],
        port=cfg["DB_PORT"],
        user=cfg["DB_USER"],
        password=cfg["DB_PASS"],
        database=cfg["DB_NAME"],
        charset=cfg["DB_CHARSET"],
        cursorclass=pymysql.cursors.DictCursor,
        autocommit=False,
    )
    # Garante collation igual ao PHP (PDO init command)
    with conn.cursor() as cur:
        cur.execute(f"SET NAMES {cfg['DB_CHARSET']} COLLATE {cfg['DB_COLLATION']}")
    return conn

def ensure_versao_exists(cur, versao_id: int):
    cur.execute("SELECT id_versao FROM lp001_quiz_versao WHERE id_versao=%s", (versao_id,))
    if not cur.fetchone():
        raise ValueError(f"id_versao={versao_id} não existe em lp001_quiz_versao.")

def get_or_create_dominio(cur, versao_id: int, nome: str, cache: dict):
    cur.execute("""
        SELECT id_dominio FROM lp001_quiz_dominios
         WHERE id_versao=%s AND nome=%s
    """, (versao_id, nome))
    row = cur.fetchone()
    if row:
        return row["id_dominio"]

    if "max_ordem" not in cache:
        cur.execute("SELECT COALESCE(MAX(ordem),0) AS maxo FROM lp001_quiz_dominios WHERE id_versao=%s", (versao_id,))
        cache["max_ordem"] = cur.fetchone()["maxo"] or 0
    cache["max_ordem"] += 1
    ordem = cache["max_ordem"]

    cur.execute("""
        INSERT INTO lp001_quiz_dominios (id_versao, nome, peso, ordem)
        VALUES (%s, %s, %s, %s)
    """, (versao_id, nome, 0.0, ordem))
    return cur.lastrowid

def upsert_pergunta(cur, versao_id: int, id_dominio: int, ordem: int, texto: str):
    cur.execute("""
        SELECT id_pergunta FROM lp001_quiz_perguntas
         WHERE id_versao=%s AND id_dominio=%s AND ordem=%s
    """, (versao_id, id_dominio, ordem))
    ex = cur.fetchone()
    if ex:
        cur.execute("UPDATE lp001_quiz_perguntas SET texto=%s WHERE id_pergunta=%s", (texto, ex["id_pergunta"]))
        return ex["id_pergunta"], True
    cur.execute("""
        INSERT INTO lp001_quiz_perguntas (id_versao, id_dominio, ordem, texto)
        VALUES (%s, %s, %s, %s)
    """, (versao_id, id_dominio, ordem, texto))
    return cur.lastrowid, False

def reset_opcoes(cur, id_pergunta: int):
    cur.execute("DELETE FROM lp001_quiz_opcoes WHERE id_pergunta=%s", (id_pergunta,))

def insert_opcao(cur, id_pergunta: int, letra: str, texto: str, ordem: int, correta: bool):
    """
    Atenção: sua tabela NÃO tem colunas 'letra' e 'is_correta'.
    Vamos gravar em 'texto', 'ordem' e 'categoria_resposta' (enum).
    Mapeamento:
      correta      -> 'correta'
      quase_certa  -> 'quase_certa'
      demais       -> 'razoavel' (pode ajustar lógica aqui se quiser granular)
    """
    categoria = "correta" if correta else "razoavel"
    cur.execute("""
        INSERT INTO lp001_quiz_opcoes (id_pergunta, ordem, texto, categoria_resposta)
        VALUES (%s, %s, %s, %s)
    """, (id_pergunta, ordem, texto, categoria))

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--host", default=DEFAULTS["DB_HOST"])
    ap.add_argument("--port", type=int, default=DEFAULTS["DB_PORT"])
    ap.add_argument("--user", default=os.getenv("DB_USER", DEFAULTS["DB_USER"]))
    ap.add_argument("--password", default=os.getenv("DB_PASS", DEFAULTS["DB_PASS"]))
    ap.add_argument("--database", default=DEFAULTS["DB_NAME"])
    ap.add_argument("--charset", default=DEFAULTS["DB_CHARSET"])
    ap.add_argument("--collation", default=DEFAULTS["DB_COLLATION"])
    ap.add_argument("--versao", type=int, default=int(DEFAULTS["QUIZ_VERSAO_ID"] or 0))
    ap.add_argument("--csv", default=str(CSV_DEFAULT))
    args = ap.parse_args()

    if not args.versao:
        raise SystemExit("Informe --versao N ou defina QUIZ_VERSAO_ID no .env/ambiente.")
    csv_path = Path(args.csv)
    if not csv_path.exists():
        raise SystemExit(f"CSV não encontrado: {csv_path}")

    delim = detect_delimiter(csv_path)
    print(f"[INFO] CSV: {csv_path} | delim: '{delim}' | versao={args.versao}")

    conn = get_conn({
        "DB_HOST": args.host, "DB_PORT": args.port,
        "DB_USER": args.user, "DB_PASS": args.password,
        "DB_NAME": args.database,
        "DB_CHARSET": args.charset, "DB_COLLATION": args.collation,
    })

    try:
        with conn.cursor() as cur, open(csv_path, "r", encoding="utf-8-sig", newline="") as fh:
            ensure_versao_exists(cur, args.versao)

            reader = csv.reader(fh, delimiter=delim)
            header = next(reader, None)
            if not header:
                raise ValueError("CSV vazio (sem cabeçalho).")
            hmap = map_headers(header)

            dominio_cache = {}
            inserted = updated = 0
            linha = 1

            for row in reader:
                linha += 1
                if not any(row):  # linha vazia
                    continue

                dominio = norm(row[hmap["dominio"]])
                ordem_s = norm(row[hmap["ordem"]])
                pergunta = norm(row[hmap["pergunta"]])
                if not (dominio and ordem_s and pergunta):
                    print(f"[WARN] Linha {linha} ignorada (faltou dominio/ordem/pergunta).")
                    continue
                try:
                    ordem = int(float(ordem_s))
                except Exception:
                    raise ValueError(f"Linha {linha}: 'ordem' inválida: {ordem_s}")

                contexto = norm(row[hmap["contexto"]]) if "contexto" in hmap else ""
                texto_final = f"{contexto}\n\n{pergunta}" if contexto else pergunta

                alts = {
                    "A": norm(row[hmap["A"]]),
                    "B": norm(row[hmap["B"]]),
                    "C": norm(row[hmap["C"]]),
                    "D": norm(row[hmap["D"]]),
                }

                letra_corr = ""
                if "correta" in hmap:
                    letra_corr = norm(row[hmap["correta"]]).upper()
                    if letra_corr.startswith("ALTERNATIVA "):
                        letra_corr = letra_corr.replace("ALTERNATIVA ", "").strip()
                    if letra_corr not in {"A","B","C","D"}:
                        letra_corr = ""
                correta_txt = norm(row[hmap["correta_texto"]]) if "correta_texto" in hmap else ""
                if not letra_corr and correta_txt:
                    for L, t in alts.items():
                        if t and t.lower() == correta_txt.lower():
                            letra_corr = L
                            break

                id_dom = get_or_create_dominio(cur, args.versao, dominio, dominio_cache)
                id_pergunta, was_update = upsert_pergunta(cur, args.versao, id_dom, ordem, texto_final)
                updated += 1 if was_update else 0
                inserted += 0 if was_update else 1

                reset_opcoes(cur, id_pergunta)
                for idx, L in enumerate(["A","B","C","D"], start=1):
                    txt = alts[L]
                    if not txt:
                        continue
                    is_ok = (L == letra_corr)
                    insert_opcao(cur, id_pergunta, L, txt, idx, is_ok)

            conn.commit()
            print(f"[OK] Perguntas novas: {inserted} | atualizadas: {updated}")

    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()

if __name__ == "__main__":
    main()
