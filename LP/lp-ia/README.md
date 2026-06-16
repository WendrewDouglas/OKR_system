# LP_IA — IA Aplicada ao Dia a Dia Financeiro

Landing page **isolada** (módulo multi-landing) dentro do OKR_system.
Não altera rotas, schema do OKR nem schema do CRM.

- **Branch:** `feature/lp-ia-financeiro`
- **Pasta:** `LP/lp-ia/`
- **Schema dedicado:** `planni40_lp` (tabelas `lp_*`, escopadas por `landing_id`)
- **Slug desta landing:** `ia-financeiro`
- **URL de desenvolvimento:** `/OKR_system/LP/lp-ia/public/`
- **URL final desejada (após aprovação de DNS):** `ia-financeiro.planningbi.com.br`

## Estrutura

```
LP/lp-ia/
├── public/index.php          # página pública (todas as seções)
├── assets/css/lp-ia.css      # estilos mobile-first (identidade PlanningBI)
├── assets/js/lp-ia.js        # cupom, formulário e checkout
├── api/
│   ├── coupon_apply.php       # POST  valida cupom no servidor
│   ├── lead_submit.php        # POST  grava lead + consentimento + e-mails
│   ├── track_event.php        # POST  registra page_view
│   └── checkout_redirect.php  # GET   ?t=<token> → registra clique → PagBank
├── includes/
│   ├── bootstrap.php          # carrega /auth/config.php e os helpers
│   ├── db.php                 # lp_db() → PDO no schema planni40_lp
│   ├── helpers.php            # settings, eventos, cupom, e-mail, consentimento
│   └── security.php           # sessão, CSRF, honeypot, rate limit, captcha
└── migrations/001_lp_schema.sql
```

## 1) Criar / aplicar o schema

> Pré-requisito (HostGator): criar o banco `planni40_lp` no cPanel → MySQL
> Databases e **atribuir o usuário MySQL atual** (mesmo do OKR) a esse banco,
> com todas as permissões. O código reaproveita as credenciais do `.env`.

Aplicar a migration (idempotente — pode rodar mais de uma vez):

```bash
php tools/migrations/apply_lp_schema.php
# saída esperada: LP_SCHEMA_OK database=planni40_lp tables=7
```

Parâmetros opcionais: `php tools/migrations/apply_lp_schema.php <projectRoot> <sqlFile> <database>`

O seed já cria a landing `ia-financeiro`, as `lp_settings` padrão e o cupom
`LOPA-ENTREVISTAS` (R$ 147,00). Valor oficial: R$ 297,00.

### Variáveis de ambiente (opcionais)

Por padrão o módulo herda a conta MySQL do OKR e usa o schema `planni40_lp`.
Para sobrescrever, adicione ao `.env` (não versionado):

```
LP_DB_HOST=...
LP_DB_NAME=planni40_lp
LP_DB_USER=...
LP_DB_PASS=...
LP_NOTIFY_EMAIL=voce@planningbi.com.br   # destino da notificação interna de leads
```

reCAPTCHA: é **opcional**. Se `CAPTCHA_PROVIDER`/`CAPTCHA_SECRET` estiverem
configurados no `.env`, a validação é aplicada automaticamente; caso contrário,
a proteção fica por conta de honeypot + rate limit (a landing funciona normalmente).

## 2) Preencher os links do PagBank e demais configs (depois)

Tudo é editável **sem deploy**, direto na tabela `lp_settings`. Exemplos:

```sql
USE planni40_lp;
SET @lid = (SELECT id FROM lp_landings WHERE slug='ia-financeiro');

-- Links de pagamento (criados manualmente no PagBank)
UPDATE lp_settings SET setting_value='https://pag.ae/SEU-LINK-OFICIAL'  WHERE landing_id=@lid AND setting_key='pagbank_url_oficial';
UPDATE lp_settings SET setting_value='https://pag.ae/SEU-LINK-DESCONTO' WHERE landing_id=@lid AND setting_key='pagbank_url_desconto';

-- Dados do treinamento
UPDATE lp_settings SET setting_value='12/07/2026'        WHERE landing_id=@lid AND setting_key='training_date';
UPDATE lp_settings SET setting_value='09h00 às 13h00'    WHERE landing_id=@lid AND setting_key='training_time';
UPDATE lp_settings SET setting_value='Araçatuba/SP'      WHERE landing_id=@lid AND setting_key='training_location';

-- Vagas / botão
UPDATE lp_settings SET setting_value='Poucas vagas disponíveis' WHERE landing_id=@lid AND setting_key='spots_status_text';
UPDATE lp_settings SET setting_value='Garantir minha vaga'       WHERE landing_id=@lid AND setting_key='btn_text_oficial';

-- HABILITAR o checkout (só faça quando os links estiverem prontos!)
UPDATE lp_settings SET setting_value='1' WHERE landing_id=@lid AND setting_key='checkout_enabled';
```

Chaves disponíveis em `lp_settings`: `pagbank_url_oficial`, `pagbank_url_desconto`,
`official_price_cents`, `discount_price_cents`, `checkout_enabled`,
`btn_text_oficial`, `btn_text_desconto`, `training_date`, `training_time`,
`training_location`, `spots_total`, `spots_status_text`.

### Regra do botão de pagamento

O redirecionamento para o PagBank só ocorre se **todas** as condições forem verdadeiras:
1. lead gravado (token válido);
2. consentimento aceito;
3. `checkout_enabled = 1`;
4. link PagBank correspondente (oficial/desconto) configurado e válido.

Caso contrário, é exibida uma mensagem amigável (“pagamento ainda não disponível”)
e o evento `checkout_blocked` é registrado.

## 3) Tabelas

`lp_landings`, `lp_settings`, `lp_coupons`, `lp_leads`, `lp_consents`,
`lp_events`, `lp_rate_limits`. Todas em `planni40_lp`.

## 4) Reuso para futuras landings

Basta inserir uma nova linha em `lp_landings` (novo `slug`), criar suas
`lp_settings`/`lp_coupons` e duplicar a pasta `public/`/`assets/` com o conteúdo,
ajustando `LP_IA_SLUG` no bootstrap do novo módulo. As tabelas já são compartilhadas
e escopadas por `landing_id`.

## Segurança / LGPD

- Prepared statements em todas as queries.
- CSRF em todos os POSTs; honeypot + rate limit; reCAPTCHA opcional.
- Consentimento obrigatório, com prova imutável em `lp_consents`
  (texto exato + versão + IP + user agent + timestamp).
- Texto legal centralizado em `lp_consent_text()` / `lp_transparency_points()`
  (`includes/helpers.php`). **Revisar antes de publicar.**
- Nenhum segredo é versionado (tudo via `.env` / `lp_settings`).
