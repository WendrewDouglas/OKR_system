# OKR System — Arquitetura, Regras de Negócio e Roadmap

> Documento mestre de referência. Cobre o objetivo do produto, o modelo de domínio,
> as camadas de acesso/permissão (RBAC) e a estratégia de evolução para **web + mobile**.
> Fonte de verdade derivada do código (`auth/acl.php`, `api/api_platform/v1/`, `okr_app/`,
> `Ambiente_Python/documents/schema.sql`). Última auditoria: 2026-06-15.
>
> Companheiros: [`PLANO_DE_TESTES.md`](PLANO_DE_TESTES.md) (sequências de teste) e
> as auditorias detalhadas que originaram este documento.

---

## 1. Objetivo do sistema

Plataforma **SaaS multi-empresa (multi-tenant)** de gestão estratégica por **OKRs**
(Objectives & Key Results), com camada financeira (orçamento de iniciativas), fluxo de
**aprovações**, **notificações/push**, e um módulo **CRM** em construção.

Cada empresa (`company`) é um tenant isolado. Usuários pertencem a uma empresa
(`usuarios.id_company`) e atuam sobre os OKRs dessa empresa conforme seu papel e
permissões. Há um papel de super-administração (`admin_master`) que transcende o tenant.

**Personas-alvo:**
- **Colaborador** (`user_colab`) — executa: aponta progresso nos KRs em que está envolvido, vê tarefas, recebe notificações.
- **Gestor** (`gestor_master`) — gere OKRs da empresa, aprova, acompanha cascata e relatórios.
- **Administrador da empresa** (`user_admin`) — administra usuários, estilo/identidade, configurações da empresa.
- **Super-admin** (`admin_master`) — opera múltiplas empresas, campanhas push globais, saúde do sistema.

---

## 2. Modelo de domínio

### 2.1 Hierarquia OKR (núcleo)

```
company (tenant)
  └── usuarios (id_company)
        └── objetivos (id_company, dono)            ← Objetivo estratégico (pilar BSC)
              └── key_results (id_objetivo)          ← KR mensurável (baseline → meta)
                    ├── milestones_kr (id_kr)        ← marcos temporais (série de datas)
                    ├── apontamentos_kr (id_kr)      ← evidências de progresso (dt_evidencia)
                    └── iniciativas (id_kr)          ← ações para atingir o KR
                          └── orcamentos (id_iniciativa)
                                └── despesas          ← desembolsos
```

**Regras de negócio centrais:**
- **Objetivo** pertence a um **pilar BSC** (`pilar_bsc`) e a um **ciclo** (`ciclo_tipo`:
  anual/semestral/trimestral/bimestral/mensal/personalizado). O ciclo determina a série
  de datas dos milestones (ver `tests/Unit/Cycle/`).
- **KR** tem `baseline`, `meta`, `direcao_metrica` (maior/menor melhor) e gera
  automaticamente uma **série de milestones** na criação (transação atômica com
  `FOR UPDATE` para numeração sequencial — `krs/create.php`). O progresso (`pct_atual`)
  e o **farol** (verde/amarelo/vermelho) derivam de baseline/meta/apontamentos.
- **Apontamento** é a evidência de avanço; só pode ser feito por quem está **envolvido no KR**
  (`okr_kr_envolvidos`) quando o usuário é colaborador.
- **Iniciativa** carrega `status` e pode ter **orçamento** com `status_aprovacao`.
- **Aprovação** é um fluxo transversal: objetivos, KRs, iniciativas e orçamentos podem
  exigir decisão de um **aprovador habilitado** (`aprovadores`, `permissoes_aprovador`).

### 2.2 Cálculo de ciclo e qualidade (lógica testada)
- Geração de série de datas por tipo de ciclo (`CalcDatas*Test`).
- Inferência de natureza do KR (`InferirNaturezaSlug`).
- Mapeamento de score → qualidade (`MapScoreToQualidade`).
- Validação de campos obrigatórios do KR (`ValidarObrigatorios`).

### 2.3 Módulos transversais
- **Dashboard / Mapa estratégico**: agregações de cascata (4 níveis), summary, progresso.
- **Notificações + Push (FCM)**: campanhas, segmentos, devices, eventos, preferências.
- **CRM** (novo, 21 tabelas): contas, contatos (canais/cargos), conversas/mensagens,
  oportunidades + pipeline, campanhas, atividades, tarefas, tags/segmentos, lead scoring,
  e **consent/LGPD** (`crm_consent_events`). Ainda sem API nem UI.

---

## 3. Camadas de acesso e permissão (RBAC)

O modelo RBAC é a espinha dorsal de segurança. Implementado **duas vezes**:
- **Web** (com sessão): `auth/acl.php` → `has_cap()`, `require_cap()`, `gate_page_by_path()`.
- **API** (stateless, JWT): `api/api_platform/v1/_middleware.php` → `api_has_cap()`, `api_require_cap()`.

Ambos compartilham a mesma semântica. **Devem permanecer em paridade** — divergência entre
eles é fonte de bug de segurança.

### 3.1 Vocabulário de capability

Formato: **`ACTION:resource@SCOPE`** (ex.: `W:kr@ORG`).

| Eixo | Valores | Semântica |
|---|---|---|
| **ACTION** | `R` (ler), `W` (escrever, cobre R), `M` (gerir, cobre tudo) | `action_matches()` |
| **SCOPE** | `OWN`, `TEAM`, `UNIT`, `ORG`, `SYS` (cobre todos) | `scope_covers()` |
| **resource** | `objetivo`, `kr`, `milestone`, `apontamento`, `iniciativa`, `orcamento`, `aprovacao`, `relatorio`, `user`, `company`, `config_okrs`, `config_notify` | recurso de domínio ou administrativo |

### 3.2 Papéis (roles)

| role_key | Papel | Comportamento |
|---|---|---|
| `admin_master` | Super-admin | **Bypass total** (`has_cap` retorna true antes de checar caps). Opera multi-empresa e push global. |
| `gestor_master` | Gestor de OKR | Caps de gestão de OKR/aprovação da empresa. |
| `user_admin` | Admin da empresa | Caps administrativas (usuários, company, estilo). Recebe todas as caps no seed (migração 002). |
| `user_colab` | Colaborador | Caps de leitura + **regra especial**: só `W:apontamento@ORG` se estiver em `okr_kr_envolvidos` do KR. |

### 3.3 Resolução de permissão (ordem de avaliação)

`has_cap('ACTION:resource@SCOPE', $ctx)`:
1. Sessão/token válido? Senão `false`.
2. `admin_master` → `true` (bypass).
3. Coleta caps via **role** (`rbac_role_capability`) + **overrides de usuário** (`rbac_user_capability`).
4. **Merge com DENY > ALLOW** — um DENY de usuário bloqueia mesmo cap concedida por role.
5. Encontra uma cap concedida que cubra ação **e** recurso **e** escopo.
6. **Tenant check**: para `@ORG` em recursos de dados, resolve a empresa do recurso
   (`resolve_resource_company`) e exige `== id_company` do usuário. Recursos administrativos
   (`relatorio`, `user`, `company`, `config_okrs`, `config_notify`) usam a própria empresa da sessão.
7. **Regra do colaborador**: `user_colab` + `W:apontamento` exige vínculo em `okr_kr_envolvidos`.

> ⚠️ O tenant check só roda quando há **contexto** (`$ctx` com id do recurso). Gates de
> página passam sem contexto — o isolamento real precisa ser garantido **em cada ação**.

### 3.4 Gate por página (web)
`dom_paginas.requires_cap` mapeia cada path → capability mínima. `gate_page_by_path()`
no topo das views aplica `require_cap()`. `can_open_path()` esconde itens de menu sem permissão.

### 3.5 View de auditoria
`v_user_access_effective` consolida, por usuário, as caps efetivas de **consulta (R)** e
**edição (W)** após aplicar ALLOW/DENY — útil para telas de administração e para testes.

---

## 4. Arquitetura técnica (3 superfícies)

| Superfície | Stack | Auth | Estado |
|---|---|---|---|
| **Web** | PHP (views server-side) + `auth/*` endpoints | Sessão PHP (`$_SESSION`) | Produto principal, completo |
| **API** | `api_platform/v1` (front-controller + RBAC) | JWT-lite HMAC-SHA256 (stateless) | 78 endpoints; bem arquitetada; consumida pelo app |
| **Mobile/Desktop** | Flutter (Riverpod, Dio, go_router) | Bearer token (secure storage) | MVP; consome a API; ~40% dos endpoints |

**Princípio de evolução:** a **API é o contrato único** entre o backend e o app. Toda
funcionalidade nova de produto deve nascer (ou ser exposta) na API, com RBAC aplicado,
e então consumida por web e mobile. Evitar lógica de negócio na UI (web ou Flutter).

---

## 5. Matriz de cobertura (web × API × mobile)

| Domínio | Web | API `v1` | Mobile | Lacuna |
|---|:--:|:--:|:--:|---|
| Auth (login/reset/refresh) | ✅ | ✅ | ✅ | — |
| Objetivos (CRUD) | ✅ | ✅ | ✅ | — |
| Key Results (CRUD) | ✅ | ✅ | ✅ | delete real de KR no app |
| Milestones / Apontamentos | ✅ | ⚠️ | ✅ | **bug: `apontamentos/list` quebrado** |
| Iniciativas (CRUD) | ✅ | ⚠️ | ✅ | **sem RBAC na API** |
| Orçamento | ✅ | ✅ | ⚠️ leitura | CRUD no app; delete na API |
| Aprovações | ✅ | ⚠️ | ⚠️ | **IDOR cross-tenant na API**; UX no app |
| Notificações / Push | ✅ | ✅ | ⚠️ | badge + deep-link no app |
| Dashboard / Mapa | ✅ | ✅ | ⚠️ | dashboard dedicado no app |
| Usuários + RBAC | ✅ | ⚠️ | ❌ | **furos de tenant/escalonamento na API**; ausente no app |
| Company + estilo | ✅ | ✅ | ❌ | ausente no app |
| Missão/Visão | ✅ | ⚠️ parcial | ❌ | sem endpoint dedicado |
| Gestão multi-empresa (admin_master) | ✅ | ❌ | ❌ | **API ausente** |
| Relatórios / PDF | ✅ | ❌ | ❌ | **API ausente** |
| Matriz de prioridade | ✅ | ❌ | ❌ | **API ausente** |
| System health | ✅ | ❌ | ❌ | **API ausente** |
| CRM | ⚠️ schema | ❌ | ❌ | **full-stack ausente** |

Legenda: ✅ completo · ⚠️ parcial/com ressalva · ❌ ausente.

---

## 6. Achados de segurança consolidados

> Vários afetam o **backend compartilhado** (web e API), não só o app. Tratar como
> correção de produção independente. Cada item tem teste de regressão correspondente
> em [`PLANO_DE_TESTES.md`](PLANO_DE_TESTES.md) (§ Regressão de segurança).

### 🔴 Crítico
| ID | Achado | Local |
|---|---|---|
| SEC-01 | `GET /krs/:id/apontamentos` retorna 500 — coluna inexistente `data_ref` (correto: `dt_evidencia`) | `apontamentos/list.php:27` |
| SEC-02 | Escalonamento de privilégio: `role_key` do body sem whitelist permite criar `admin_master` | `usuarios/create.php:25` |
| SEC-03 | Quebra de isolamento entre empresas: admin de A altera role/permissões/dados de usuário de B | `usuarios/{role,save_permissions,update,pre_delete,get_permissions}.php` |
| SEC-04 | Iniciativas sem RBAC: qualquer colaborador cria/edita/exclui/muda status | `iniciativas/{create,update,delete,update_status}.php` |

### 🟠 Alto
| ID | Achado | Local |
|---|---|---|
| SEC-05 | IDOR em aprovações: decide item de qualquer empresa por `id_ref` sem checar tenant | `aprovacoes/decidir.php:40-65` |
| SEC-06 | `orcamentos/create` sem RBAC (inconsistente com update/add_despesa) | `orcamentos/create.php` |
| SEC-07 | `usuarios/list` sem RBAC — vaza nome/e-mail/telefone de todos | `usuarios/list.php:9` |
| SEC-08 | `krs/cancel` exige justificativa e a descarta (auditoria falsa) + query com colunas inexistentes (`id`/`descricao`); mesmo bug em `krs/reactivate` | `krs/cancel.php`, `krs/reactivate.php` |
| SEC-09 | Enums de status gravados sem validação (`api_enum`) | `objetivos/update`, `krs/update`, `iniciativas/update_status` |
| SEC-10 | Token FCM logado sem `kDebugMode` (vazamento de credencial) | `okr_app/.../push_service.dart:88,182` |
| SEC-11 | Refresh de token sem mutex/anti-loop | `okr_app/.../api_client.dart:64-92` |

### 🟡 Médio
- SEC-12 `register.php` aberto sem rate-limit/CAPTCHA (criação em massa de tenants).
- SEC-13 `company/style_update` aceita `logo_base64` sem limite de tamanho.
- SEC-14 Reset de senha não revoga tokens JWT já emitidos (stateless sem `pwd_changed_at`).
- SEC-15 Envelope de resposta inconsistente entre endpoints (impacta camada de DTOs do app).

---

## 7. Roadmap consolidado (web + API + mobile)

> Princípio: **API saneada e padronizada primeiro**; web e mobile consomem.
> Detalhe e estimativas no histórico de planejamento; aqui fica a sequência canônica.

| Fase | Frente | Entrega |
|---|---|---|
| **F0 — Blindagem** | App + API | Críticos SEC-01..04, SEC-10/11. App: dart-define, `unregisterDevice` no logout, lock em ações. |
| **F1 — Fundação app** | Mobile | Camada de DTOs (`json_serializable`) + repositórios; testes de auth/refresh; extrair widgets duplicados; `StatefulShellRoute`. |
| **F1.5 — Saneamento API** | Backend | SEC-05..09; **padronizar envelope** `{ok,data,pagination}`; RBAC consistente; N+1. |
| **F2 — Paridade pronta** | Mobile | Blocos A (operacional) + B (usuários) + D (estratégia) — backend já existe. |
| **F3 — Backend novo** | Backend | Relatórios/PDF, matriz de prioridade, gestão multi-empresa, system health, criar/disparar campanha push. |
| **F4 — Paridade avançada** | Mobile | Consumir F3 conforme sai. |
| **F5 — CRM** | Full-stack | API CRM + UI web + mobile, com RBAC e LGPD (consent). |
| **Contínuo** | Todas | Acessibilidade, i18n (se aplicável), CI (lint+test+build), telemetria. |

---

## 8. Estratégia de qualidade

A garantia de que "tudo que for construído funcione" está em
[`PLANO_DE_TESTES.md`](PLANO_DE_TESTES.md), organizada em pirâmide:

1. **Unitários** (lógica de negócio pura): ciclos, KR, RBAC primitives — já existem, expandir.
2. **RBAC/Permissões** (matriz por papel × recurso × escopo × tenant) — núcleo de segurança.
3. **Contrato de API** (cada endpoint: auth, RBAC, validação, isolamento, shape).
4. **Integração** (fluxos ponta a ponta: ciclo de vida de um OKR).
5. **Regressão de segurança** (um teste por achado SEC-xx).
6. **Mobile** (unit de repositórios/DTOs, widget, golden, fluxo).

**Definição de Pronto (DoD)** para qualquer feature nova:
- [ ] Endpoint na API com RBAC aplicado e isolamento de tenant.
- [ ] Envelope de resposta padronizado.
- [ ] Teste de contrato (sucesso + 401 + 403 + 422 + cross-tenant negado).
- [ ] Teste de regra de negócio (unitário).
- [ ] Consumido por web e/ou mobile sem lógica de negócio na UI.
- [ ] Para mobile: DTO tipado + teste de repositório.

---

## 9. Revisão de arquiteto de solução (a fazer)

Após documentação e testes prontos, avaliar formalmente:
- **Cobertura de necessidade**: a matriz §5 atende todas as personas (§1) em web e mobile?
- **Consistência de regra**: web e API aplicam exatamente o mesmo RBAC? (risco de divergência)
- **Escalabilidade multi-tenant**: o isolamento por `id_company` resiste a todos os caminhos?
- **CRM**: o modelo de 21 tabelas se integra ao tenant e ao RBAC existentes?
- **Mobile como produto**: paridade total faz sentido (admin pesado em mobile tem ROI baixo)?

Resultado dessa revisão está em § 10.

---

## 10. Revisão de arquiteto de solução (2026-06-16)

**Pergunta central:** o sistema atende *completamente* clientes e usuários, em web e mobile?
**Veredito:** o **web atende hoje**; o **mobile ainda não**, e há um risco estrutural a decidir antes de escalar.

### 10.1 Cobertura por persona
| Persona | Web | Mobile | Atende completamente? |
|---|:--:|:--:|---|
| Colaborador | ✅ | 🟡 ~85% | Quase — falta corrigir SEC-01 e polir notificações. Persona mais perto de pronto em mobile. |
| Gestor | ✅ | 🟡 ~60% | Operacional sim; falta o analítico (relatórios, matriz) que é o trabalho-fim do gestor. |
| Admin da empresa | ✅ | ❌ | Mobile não tem gestão de usuários/empresa/estilo. |
| Super-admin | ✅ | ❌ | Multi-empresa e system health nem existem na API. |

### 10.2 Consistência de regra (maior risco silencioso)
O RBAC está implementado **duas vezes** (`acl.php` web e `_middleware.php` API) e **já diverge**:
o web aplica tenant check, mas vários endpoints da API não chamam o RBAC (SEC-03/04/05). A mesma
ação tem regra diferente conforme a porta de entrada. **Recomendação:** convergir para uma única
fonte de autorização (API como ponto único, ou módulo RBAC compartilhado). SEQ-RBAC-06 (paridade
web↔API) deve virar gate de CI.

### 10.3 Integridade multi-tenant
Design correto (`id_company` + `resolve_resource_company`), enforcement com furos (SEC-03/05).
O isolamento é tão forte quanto o handler mais fraco. Testes cross-tenant 100% verdes são
**requisito** antes de múltiplos clientes, não desejável.

### 10.4 CRM
Modelagem madura (inclui LGPD/consent), mas sem API nem UI. É um produto dentro do produto;
tratar como fase própria (F5), nascendo API-first + RBAC + tenant + LGPD — sem repetir os débitos do OKR.

### 10.5 Mobile — questionar "paridade total"
Decisão registrada é paridade total. Ressalva de arquiteto: admin pesado em mobile tem ROI baixo.
Manter paridade total como norte, mas **validar no marco pós-Bloco B** se super-admin/admin pesado
precisam de app, ou se "paridade do que é usado em mobilidade" entrega o mesmo valor com menos custo.

### 10.6 Resposta final
Atenderá completamente **desde que** três condições sejam requisito (não backlog):
- **(a)** fechar SEC-01..09 com testes de regressão verdes;
- **(b)** garantir paridade do RBAC entre web e API;
- **(c)** construir a camada de DTOs/repositórios do app antes de escalar telas.

Com isso, web serve todas as personas e mobile alcança paridade de forma sustentável. Sem isso,
dívida e risco de segurança escalam proporcionalmente a cada novo cliente.

> **Execução iniciada em 2026-06-16** pela Fase 0 (correções críticas SEC-01..09). Progresso
> rastreado no working tree; deploy/commit sob aval do dono.

---

## 11. Revisão de paridade RBAC web↔API (F1.5, 2026-06-16)

Comparação linha a linha de `has_cap` (`auth/acl.php`) e `api_has_cap` (`_middleware.php`).
A **lógica de ação/escopo/merge é equivalente** (M⇒W⇒R; SYS cobre tudo; DENY > ALLOW;
bypass de `admin_master`; regra do colaborador para `apontamento`). Divergências encontradas:

| ID | Divergência | Impacto | Recomendação |
|---|---|---|---|
| **PARITY-01** | **Resolução de tenant difere.** Web deriva a empresa do recurso pelo **dono** (`objetivos.dono → usuarios.id_company`); API usa **`objetivos.id_company`** direto. Se a empresa do dono divergir da do objetivo (dono trocou de empresa, etc.), as duas dão decisões diferentes. | Autorização inconsistente entre web e API no mesmo recurso. | Convergir para `objetivos.id_company` (direto e correto) também no `acl.php`. **Muda comportamento de authz → validar antes.** |
| **PARITY-02** | `resolve_resource_company` da **API não tem os casos `milestone` e `aprovacao`** que o web tem. | Latente: uma cap `…:milestone@ORG`/`…:aprovacao@ORG` com ctx na API resolveria `null` → nega. Hoje milestones entram sob caps de `kr`; baixo impacto. | Adicionar os casos faltantes na API para paridade. |
| **PARITY-03** | **Bug compartilhado (web e API):** ambos ignoram `rbac_role_capability.effect` (tratam todo vínculo de role como ALLOW), divergindo da view canônica `v_user_access_effective`, que honra `effect='DENY'` em caps de role. | Um DENY no nível de role **não é aplicado** por `has_cap`/`api_has_cap`. Afeta os dois igualmente. | Filtrar/honrar `effect` em ambos; alinhar com a view. |

**Conclusão:** a condição (b) da §10.6 ("paridade do RBAC") foi endereçada.

### 11.1 Status (corrigido em 2026-06-16, working tree)
- **PARITY-01 ✅** — `acl.php` (web) convergido para resolver tenant por `objetivos.id_company` (joins), igual à API. Removidos os joins via `usuarios.dono`.
- **PARITY-02 ✅** — adicionados os casos `milestone` e `aprovacao` ao resolver da API (`_middleware.php`); corrigida a coluna `id_ms`→`id_milestone` no web.
- **PARITY-03 ✅** — `has_cap` e `api_has_cap` agora honram `rbac_role_capability.effect`: um DENY no nível de role bloqueia (antes era ignorado).

> ⚠️ **Muda decisões de autorização.** Validar num banco de teste antes de commitar/deploy.
> Cobertura: `tests/Unit/Acl/RoleCapEffectTest.php` (PARITY-03, in-process) + os testes
> cross-tenant HTTP de `ApiSecurityRegressionTest` (tenant). PARITY-01/02 dependem de fixtures
> com empresa do dono ≠ empresa do objetivo para exercício completo.

---

## 12. Envelope de resposta padrão (F1, aditivo — 2026-06-16)

A API converge para um envelope único, **de forma aditiva** (mantém chaves legadas para
não quebrar app Flutter atual nem `views/admin_push.php`).

**Forma padrão:**
```json
{ "ok": true, "data": <payload>, "pagination": { "page", "per_page", "total", "pages" } }
```
- `data` = payload principal (lista, objeto ou item). `pagination` só em listas paginadas.
- **Chaves legadas continuam presentes** (ex.: `apontamentos`, `krs`, `items`, `page`…).

**Helpers** (`_core.php`): `api_ok($data, $legacy = [], $pagination = null)` e
`api_ok_paginated($result, $extraLegacy = [])`.

**Regra para novos endpoints:** usar `api_ok`/`api_ok_paginated` desde o início; novos
consumidores (DTOs do app) leem `data`/`pagination`. Chaves legadas serão removidas só
quando nenhum consumidor depender delas (fase futura, com versionamento se preciso).

### 12.1 Rollout — checklist
- ✅ **Listas operacionais (feito):** `objetivos/list`, `krs/list`, `iniciativas/list`,
  `apontamentos/list`, `orcamentos/list`, `tarefas/minhas`, `aprovacoes/list`,
  `notificacoes/list`, `usuarios/list`.
- ⏳ **GETs (single):** `objetivos/get`, `krs/get`, `iniciativas/get`, `auth/me`, `company/me`,
  `orcamentos/resumo`, `orcamentos/dashboard`, `krs/milestones`, `dashboard/*`, `dominios/*`.
- ⏳ **Mutações:** create/update/delete/decidir (envolver `id_*`/`message` em `data`).
- ⏳ **Push:** `push/campaigns_list` (hoje sem `ok:true` — corrigir junto).

> Aplicar o restante incrementalmente conforme a camada de DTOs (passos 2–3) consome cada
> endpoint, para validar `data`/`pagination` end-to-end a cada migração.
