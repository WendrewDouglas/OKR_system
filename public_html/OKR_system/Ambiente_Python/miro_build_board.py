# miro_build_board.py
# Solução robusta contra "outside of parent boundaries":
# - Cria frames (v2)
# - Cria stickies em POSIÇÃO ABSOLUTA (no canvas), sem 'parent', alinhadas ao centro do frame
# - Grade 3x3 compacta por frame (visual correto, sem boundary)

import os
import json
import time
import requests
from typing import Dict, Any, Tuple

API_BASE = "https://api.miro.com/v2"

# ========= CONFIG =========
BOARD_ID = "uXjVJu5sJj0="      # <-- cole o ID do seu board
TOKENS_PATH = "tokens.json"     # gerado pelo oauth_get_token.py
REQUEST_PAUSE = 0.06            # pausa leve anti rate-limit
# ==========================

def load_access_token() -> str:
    env = os.getenv("MIRO_ACCESS_TOKEN")
    if env:
        return env.strip()
    if not os.path.exists(TOKENS_PATH):
        raise FileNotFoundError(f"{TOKENS_PATH} não encontrado. Rode oauth_get_token.py.")
    with open(TOKENS_PATH, "r", encoding="utf-8") as f:
        data = json.load(f)
    tok = data.get("access_token")
    if not tok:
        raise ValueError("access_token não encontrado em tokens.json.")
    return tok.strip()

def headers(token: str) -> Dict[str, str]:
    return {"Authorization": f"Bearer {token}",
            "Content-Type": "application/json",
            "Accept": "application/json"}

def miropost(token: str, path: str, payload: Dict[str, Any]) -> Dict[str, Any]:
    url = f"{API_BASE}{path}"
    r = requests.post(url, headers=headers(token), json=payload)
    if r.status_code >= 400:
        print("\n[MIRO DEBUG] POST", url)
        try:
            print("[MIRO DEBUG] Payload:", json.dumps(payload, ensure_ascii=False))
        except Exception:
            print("[MIRO DEBUG] Payload(raw):", payload)
        print("[MIRO DEBUG] Status:", r.status_code)
        print("[MIRO DEBUG] Body  :", r.text, "\n")
    r.raise_for_status()
    return r.json()

def create_frame(token: str, board_id: str, title: str,
                 x: float, y: float, w: float, h: float) -> str:
    payload = {
        "data": {"title": title},
        "position": {"x": x, "y": y, "origin": "center"},
        "geometry": {"width": w, "height": h}
    }
    res = miropost(token, f"/boards/{board_id}/frames", payload)
    return res["id"]

def create_sticky_canvas(token: str, board_id: str, content: str,
                         abs_x: float, abs_y: float, color: str) -> str:
    """
    Cria sticky NOTA no canvas (sem parent), em coordenadas ABSOLUTAS.
    """
    payload = {
        "data": {"content": content, "shape": "square"},  # 'square' | 'rectangle'
        "style": {
            # Cores válidas (v2): gray, light_yellow, yellow, orange, red,
            # light_green, green, dark_green, cyan, light_pink, pink,
            # violet, light_blue, blue, dark_blue, black
            "fillColor": color
        },
        "position": {"x": abs_x, "y": abs_y, "origin": "center"}
    }
    res = miropost(token, f"/boards/{board_id}/sticky_notes", payload)
    return res["id"]

# -------- conteúdo (8 alavancas) --------
COLOR = {
    "Problema":   "red",
    "Alavanca":   "blue",
    "Métrica":    "green",
    "Dono":       "violet",
    "Passos/SOP": "yellow",
    "Rito":       "cyan",
    "Risco":      "gray",
    "Contenção":  "orange",
    "Status":     "light_yellow"
}

ALAVANCAS = [
    {
        "titulo": "1) NF D+1 com evidência",
        "itens": {
            "Problema":   "NF sai tarde/sem prova; cliente diz 'não recebi'",
            "Alavanca":   "Confirmar NF D+1 com evidência (print portal/AR)",
            "Métrica":    "% NF confirmada D+1 ≥ 90%; DSO ↓",
            "Dono":       "Eliana (AR/AP)",
            "Passos/SOP": "D0 até 20h → emitir D0/D+1 → confirmar D+1 com evidência",
            "Rito":       "D+1 (10 min): NFs confirmadas; pagamentos 5 dias; caixa T+28",
            "Risco":      "D0 incompleto; portal instável; contato não atende",
            "Contenção":  "Checklist D0; contato B; e-mail com AR automático",
            "Status":     "⬜ Não iniciado"
        }
    },
    {
        "titulo": "2) Cartão do Job T-1",
        "itens": {
            "Problema":   "Exigência de portal/treinamento aparece na véspera",
            "Alavanca":   "Cartão T-1 até 18h (portal, pedido/OS, janela, NR/EPIs, hotel)",
            "Métrica":    "% Prontos T-1 ≥ 95%; OTS ≥ 90%",
            "Dono":       "Cícera → entrega a Hugo",
            "Passos/SOP": "Preparar cartão; validar pendências; marcar 'vermelho' com dono",
            "Rito":       "Daily 15’ (Cícera+Hugo) focado em T-1/T0",
            "Risco":      "Portal travado; treinamento vencido; janela não confirmada",
            "Contenção":  "Playbooks por portal; calendário de treinamentos; confirmação ativa",
            "Status":     "⬜ Não iniciado"
        }
    },
    {
        "titulo": "3) Pacote D0 até 20h",
        "itens": {
            "Problema":   "Fotos/medições/aceite faltando; NF atrasa",
            "Alavanca":   "Checklist saída/retorno + deadline 20h",
            "Métrica":    "% D0 completos ≥ 95%",
            "Dono":       "Hugo (Execução)",
            "Passos/SOP": "Checklist app/WhatsApp; conferência 18–20h; marcar 'pendente'",
            "Rito":       "Daily: pendências da véspera",
            "Risco":      "Fotos ruins; aceite sem assinatura",
            "Contenção":  "Modelo de foto; aceite digital; janela backup de upload",
            "Status":     "🟨 Em progresso"
        }
    },
    {
        "titulo": "4) Inbox única AP + DDA",
        "itens": {
            "Problema":   "Documentos dispersos; risco de multa/juros",
            "Alavanca":   "E-mail único do financeiro + DDA habilitado",
            "Métrica":    "Docs ≤ 24h ≥ 95%; Fora do prazo ≤ 2%",
            "Dono":       "Eliana",
            "Passos/SOP": "Lançar ≤24h; classificar lote 10/20; programar e baixar no dia",
            "Rito":       "Seg–sex 8h20 checagem + fech. dos lotes (5º útil / dia 15)",
            "Risco":      "Fornecedor envia por WhatsApp; DDA desativado",
            "Contenção":  "Mensagem automática com e-mail correto; ativar DDA em todos bancos",
            "Status":     "🟩 Ativo"
        }
    },
    {
        "titulo": "5) Agenda com status + causas",
        "itens": {
            "Problema":   "Agenda invisível; choque de recurso",
            "Alavanca":   "Previsto/Confirmado/Reprogramado + causa obrigatória",
            "Métrica":    "Reprogramações internas ↓; OTS ↑",
            "Dono":       "Hugo",
            "Passos/SOP": "Marcar status; capturar causa; relatório semanal",
            "Rito":       "Daily 15’; review semanal de causas",
            "Risco":      "Time não preenche; causa genérica",
            "Contenção":  "Campo obrigatório; lista padronizada; alerta automático",
            "Status":     "🟨 Em progresso"
        }
    },
    {
        "titulo": "6) Preventiva + kits + 5S",
        "itens": {
            "Problema":   "Corretiva domina; avarias e custo alto",
            "Alavanca":   "Calendário de preventiva; kits por tipo; 5S quinzenal",
            "Métrica":    "% Preventiva 60–70%; MTBF↑/MTTR↓; TCO/receita ↓",
            "Dono":       "Zé (PCM)",
            "Passos/SOP": "Planejar preventiva ligada à agenda; checklist kits; auditoria 5S",
            "Rito":       "Review quinzenal Top 10 MTBF/MTTR",
            "Risco":      "Fura preventiva por pico; kit incompleto",
            "Contenção":  "Aval obrigatório para reprogramar; estoque mínimo do kit",
            "Status":     "⬜ Não iniciado"
        }
    },
    {
        "titulo": "7) Matriz de alçadas",
        "itens": {
            "Problema":   "Tudo sobe para sócio; aprovações travam",
            "Alavanca":   "Limites por valor/tipo (pagamento, desconto, exceção)",
            "Métrica":    "Decisões por rito ≥ 90%",
            "Dono":       "Andrea / Nico",
            "Passos/SOP": "Publicar alçadas; registrar exceções; revisão mensal",
            "Rito":       "WBR 15’ para exceções",
            "Risco":      "Bypass por WhatsApp; regra confusa",
            "Contenção":  "Quadro público; checklist de aprovação; log de exceções",
            "Status":     "⬜ Não iniciado"
        }
    },
    {
        "titulo": "8) Cash plan T+8 + D+1 (10’)",
        "itens": {
            "Problema":   "Susto no caixa; difícil prever",
            "Alavanca":   "Previsão 8 semanas + revisão D+1",
            "Métrica":    "Desvio T+8 ≤ 10%",
            "Dono":       "Andrea",
            "Passos/SOP": "Sexta: fechar T+8; D+1: NFs confirmadas e pagamentos 5 dias",
            "Rito":       "D+1 8h20–8h30 (fixo)",
            "Risco":      "Falta de dados; reunião cancelada",
            "Contenção":  "BI v1 financeiro; dono substituto; pauta mínima",
            "Status":     "🟨 Em progresso"
        }
    }
]

# ---- layout dos frames no canvas (absoluto) ----
FRAME_W, FRAME_H = 1400.0, 900.0
START_X, START_Y = 0.0, 0.0
DX_FRAME, DY_FRAME = 1600.0, 1100.0  # distância entre frames

# ---- grade 3x3 "compacta" ao redor do centro do frame ----
# offsets RELATIVOS ao centro do frame (para converter em ABSOLUTO: soma com fx/fy)
# Usamos uma malha compacta para caber com folga
OFF_X = 300.0
OFF_Y = 200.0
GRID_OFFSETS = {
    (0,0): (-OFF_X, -OFF_Y), (1,0): (0.0, -OFF_Y), (2,0): ( OFF_X, -OFF_Y),
    (0,1): (-OFF_X,  0.0   ), (1,1): (0.0,  0.0   ), (2,1): ( OFF_X,  0.0   ),
    (0,2): (-OFF_X,  OFF_Y), (1,2): (0.0,  OFF_Y), (2,2): ( OFF_X,  OFF_Y),
}
CELLS = [
    ("Problema",   0, 0),
    ("Alavanca",   1, 0),
    ("Métrica",    2, 0),
    ("Dono",       0, 1),
    ("Passos/SOP", 1, 1),
    ("Rito",       2, 1),
    ("Risco",      0, 2),
    ("Contenção",  1, 2),
    ("Status",     2, 2),
]

def main():
    token = load_access_token()

    for idx, alav in enumerate(ALAVANCAS):
        # centro ABSOLUTO do frame no canvas
        col = idx % 2
        row = idx // 2
        fx = START_X + col * DX_FRAME
        fy = START_Y + row * DY_FRAME

        # cria o frame
        create_frame(token, BOARD_ID, alav["titulo"], fx, fy, FRAME_W, FRAME_H)

        # cria as 9 stickies como ITENS NO CANVAS (sem parent),
        # posicionadas ao redor do centro do frame
        for key, cx, cy in CELLS:
            txt = f"{key}: {alav['itens'][key]}"
            offx, offy = GRID_OFFSETS[(cx, cy)]
            abs_x = fx + offx
            abs_y = fy + offy
            color = COLOR.get(key, "light_yellow")
            create_sticky_canvas(token, BOARD_ID, txt, abs_x, abs_y, color)
            time.sleep(REQUEST_PAUSE)

        time.sleep(REQUEST_PAUSE)

    print("✅ Concluído: 8 frames + stickies posicionadas no canvas, alinhadas aos frames (sem boundary).")

if __name__ == "__main__":
    try:
        main()
    except Exception as e:
        print("\n[ERRO] Execução interrompida:", repr(e))
        print("Dicas:")
        print("- BOARD_ID ok e token com 'boards:write'.")
        print("- Sem 'parent' nas stickies, não deve ocorrer boundary.")
        print("- Persistindo algo, mande o bloco [MIRO DEBUG] para ajustarmos offsets.\n")
        raise
