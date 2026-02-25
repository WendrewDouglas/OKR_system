# Landing Page – Diagnóstico Executivo & OKRs

Documentação operacional e técnica da LP: fluxo, APIs, dados, segurança, analytics e operação.

---

## 1) Visão Geral

* **Páginas**:
  `start.php` → coleta lead e abre sessão
  `run.php` → questionário e coleta de respostas
  `result.php` → consolida, exibe score/radar, gera PDF e envia WhatsApp
* **APIs ( /auth/ )**:
  `lead_start.php`, `versao_ativa.php`, `sessao_start.php`, `sessao_finalize.php`, `report_generate.php`, `whatsapp_send.php`
* **Objetivo**: captar leads qualificados, rodar diagnóstico enxuto, registrar jornada e entregar PDF/Trial (LGPD-compliant).

---

## 2) Modelo de Dados (ER ASCII)

```
+------------------+           +-------------------+
|     versoes_     | 1       * |      sessoes      |
|     quizz        |-----------| id_sessao (PK)    |
| id_versao (PK)   |           | session_token (U) |
| label            |           | id_lead (FK)      |
| is_ativa         |           | id_versao (FK)    |
| schema_json      |           | status            |
| created_at       |           | ip, user_agent    |
+------------------+           | started_at        |
                               | finalized_at      |
                               +---------+---------+
                                         |
                                         | 1
                                         |          * respostas
                                         v        +------------------+
                               +-------------------+ id_resposta (PK)
                               |     resultados    | id_sessao (FK)
                               | id_resultado (PK) | pergunta_id
                               | id_sessao (U,FK)  | valor_bruto
                               | score_total       | valor_normalizado
                               | tier              | created_at
                               | score_por_dom..   +------------------+
                               | bullets_json
                               | alavancas_json
                               | pdf_url? (opt)
                               | created_at
                               +---------+
                                         \
                                          \  * whatsapp_envios
                                           \+------------------------+
                                            | id_envio (PK)          |
                                            | id_sessao (FK)         |
                                            | id_lead (FK)           |
                                            | country_code, ddi      |
                                            | phone_e164, optin      |
                                            | provider, provider_id? |
                                            | status, error_code?    |
                                            | response_raw_json?     |
                                            | pdf_url_enviado?       |
                                            | created_at             |
                                            +------------------------+

+------------------+
|      leads       |
| id_lead (PK)     |
| email (UNQ)      |
| nome             |
| cargo            |
| consent_termos   |
| consent_marketing|
| utm_*            |
| created_at,...   |
+---------+--------+
          |
          | 1
          |      * lead_consent_history
          v    +-------------------------+
               | id_consent (PK)        |
               | id_lead (FK)           |
               | tipo ('termos'/'mkt')  |
               | valor (BOOL)           |
               | origin, ip, user_agent |
               | created_at             |
               +------------------------+

[Opcional]
+------------------+
|     reports      |
| id_report (PK)   |
| id_sessao (FK)   |
| pdf_url/key      |
| status,size?     |
| created_at       |
+------------------+
```

**Índices mínimos**:

* `leads.email` (UNQ)
* `sessoes.session_token` (UNQ), `sessoes.id_lead`, `sessoes.id_versao`
* `respostas.id_sessao`
* `resultados.id_sessao` (UNQ)
* `whatsapp_envios.id_sessao`, `whatsapp_envios.id_lead`, `whatsapp_envios.phone_e164`
* (Opcional) `UNIQUE (id_sessao, phone_e164)` para idempotência de envio

---

## 3) Diagrama de Sequência (ASCII)

```
start.php         lead_start    versao_ativa   sessao_start    run.php      sessao_finalize   report_generate   whatsapp_send
   |                   |              |             |             |                |                |                |
   |--submit lead----->|              |             |             |                |                |                |
   | 200 {id_lead} <---|              |             |             |                |                |                |
   |--GET versao------>|              |             |             |                |                |                |
   |           200 <---| {id_versao}  |             |             |                |                |                |
   |--POST sessao-------------------->|             |             |                |                |                |
   |                 200 {sid} <------|             |             |                |                |                |
   |----- redirect -> run.php?sid ---------------------------------------------------------------->                   |
                                                             (salva respostas)                                         |
                                                             ---- fim ----->                                          |
                                                                 |                                                   |
result.php?sid                                                   |                                                   |
   |--POST finalize--------------------------------------------------------------------------------->|               |
   |                              200 {score, nome, ...} <-------------------------------------------|               |
   | (render radar/CTA)                                                                                             |
   |--POST report-generate------------------------------------------------------------------------------------------>| 
   |                                                200 {pdf_url} <-------------------------------------------------|
   |--POST whatsapp_send----------------------------------------------------------------------------------------------------->|
   |                                              200 {status='sent'|'queued'} <--------------------------------------------|
   | (mostra msg OK)                                                                                                        |
```

---

## 4) Mapeamento Endpoint → Tabelas → Eventos (Analytics)

| Endpoint/API            | Tabelas afetadas                         | Eventos (front) sugeridos                             |
| ----------------------- | ---------------------------------------- | ----------------------------------------------------- |
| `lead_start.php`        | `leads` (upsert), `lead_consent_history` | `lead_submitted`                                      |
| `versao_ativa.php`      | —                                        | `version_resolved`                                    |
| `sessao_start.php`      | `sessoes` (insert)                       | `session_started`                                     |
| `run.php` (salva resp.) | `respostas` (insert)                     | `question_answered` (opcional)                        |
| `sessao_finalize.php`   | `resultados` (upsert idempotente)        | `session_finalized`                                   |
| `report_generate.php`   | `resultados.pdf_url` ou `reports`        | `report_generated`                                    |
| `whatsapp_send.php`     | `whatsapp_envios` (insert/update)        | `whatsapp_requested`, `whatsapp_sent/whatsapp_failed` |

**Dimensões recomendadas**: `id_versao`, `id_sessao`, `id_lead`, UTMs, `cargo`, país/DDI, `tier`, device.

---

## 5) Regras Críticas de Negócio

* **Idempotência**:
  `sessao_finalize` pode ser chamado várias vezes → sempre retorna o mesmo “snapshot” e apenas atualiza `resultados`.
  `report_generate` deve verificar se já existe PDF (reusar / atualizar).
  `whatsapp_send` deve evitar duplicatas por `(id_sessao, phone_e164)` (índice único ou checagem de última tentativa).

* **Telefone (E.164)**:
  BR com máscara no front; internacionais livres (até 25 chars). Persistir sempre **E.164**; gravar `ddi` e `country_code`.

* **Nome no result**:
  Preferir `lead_nome` do backend; fallback para `localStorage.lead_nome` (formatar “Primeira maiúscula + restante minúsculas”).

---

## 6) Variáveis de Ambiente (.env / config)

```
APP_ENV=prod
APP_URL=https://seu-dominio

# Banco
DB_HOST=...
DB_NAME=...
DB_USER=...
DB_PASS=...

# PDFs (armazenamento)
PDF_STORAGE_DRIVER=local|s3|gcs
PDF_STORAGE_PATH=/var/app/pdfs
PDF_SIGNED_URL_TTL_SECONDS=3600

# WhatsApp Provider
WA_PROVIDER=meta|twilio|zapi|...
WA_API_KEY=...
WA_API_URL=...
WA_SENDER_ID=...

# CORS/Segurança (se necessário)
ALLOWED_ORIGINS=https://seu-dominio
RATE_LIMIT_WHATS_PER_HOUR=3
```

---

## 7) Boas Práticas de Segurança & LGPD

* **Sessões**: tokens opacos, não previsíveis, checados em todas as chamadas.
* **Consent**: histórico em `lead_consent_history` (quem, quando, IP, user_agent, tipo, valor).
* **Dados sensíveis**: telefone em E.164; considerar `phone_hash` (SHA-256 + salt) para BI/análises agregadas.
* **PDFs**: URLs assinadas e com expiração; não deixar público indefinidamente.
* **Rate-limit**: especialmente em `sessao_start` e `whatsapp_send`.
* **Logs**: sem registrar payloads sensíveis em texto puro (anonimizar quando possível).
* **Retenção**: política de expurgo/anonimização após X meses (definir base legal/propósito).

---

## 8) Observabilidade & Auditoria

* **Logs** (server): `endpoint`, `status_code`, `id_sessao`, `id_lead`, latência, mensagens de erro.
* **`audit_log`** (opcional): `entity`, `entity_id`, `action`, `payload_json`, `created_at`.
* **Métricas**: contadores e taxas por status (iniciada, finalizada, pdf_gerado, whats_sent), tempo médio por etapa.

---

## 9) Checklists

**QA funcional**

* Start: validações de campos, avisa email não corporativo (sem bloquear).
* Run: persistência de respostas, refresh/retomada.
* Result: finalize idempotente; geração de PDF única; WhatsApp (sucesso/falha/retry).
* DDI: BR máscara; internacionais livres; storage E.164.

**Prod readiness**

* HTTPS forçado, HSTS.
* Variáveis de ambiente configuradas (DB, WA provider, storage PDF).
* Backups DB + PDFs.
* Cron/Jobs: limpeza de sessões antigas, rotação de logs, expurgo de URLs vencidas.
* Rate-limit e monitoramento ativo.

---

## 10) Roadmap Rápido

* **Reenvio controlado** com cooldown e contador por sessão.
* **A/B test** do CTA (cópias/botões) com métricas de conversão até o envio do WhatsApp.
* **Painel interno** (funil + heatmap por domínio/cargo/segmento).
* **Webhooks do provider** para status assíncrono (entregue/lido).

---

## 11) Glossário de Campos Importantes

* `session_token`: identificador opaco da jornada; nunca exponha IDs internos.
* `score_por_dominio_json`: `{ "Estratégia": 62, "Execução": 48, ... }`.
* `bullets_json`: array curto de insights; pode conter `%` para colorir no front.
* `alavancas_json`: 2–3 ações de impacto rápido (90 dias).
* `tier`: `verde|amarelo|vermelho` (regra definida no consolidado).
* `phone_e164`: formato `+55DDDN...` (padrão internacional).

---

## 12) Eventos de Analytics (nomes sugeridos)

* `lead_submitted` (start.php)
* `version_resolved`
* `session_started`
* `question_answered` (run.php; opcional, batelado)
* `session_finalized`
* `report_generated`
* `whatsapp_requested`
* `whatsapp_sent` / `whatsapp_failed`

**Atributos comuns**: `id_lead`, `id_sessao`, `id_versao`, UTMs, `cargo`, `country_code/ddi`, `tier`, device.

