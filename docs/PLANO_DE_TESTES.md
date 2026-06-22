# Plano de Testes — OKR System

> Sequências de teste para garantir que **tudo que for construído funcione corretamente**,
> em **backend (web + API)** e **mobile**. Companheiro de
> [`ARQUITETURA_E_ROADMAP.md`](ARQUITETURA_E_ROADMAP.md).
>
> Infra existente: **PHPUnit 10** (`tests/`, `tests/phpunit.xml`, `composer require-dev`).
> Convenções: `Tests\Helpers\DbTestCase` (transaction + rollback), `pdo_conn_override()`
> para injetar PDO de teste no ACL. Mobile: `flutter_test` (`okr_app/test/`).

---

## 0. Como rodar

```bash
# Backend (na raiz do projeto)
composer install
vendor/bin/phpunit -c tests/phpunit.xml                 # tudo
vendor/bin/phpunit -c tests/phpunit.xml --testsuite unit
vendor/bin/phpunit -c tests/phpunit.xml --testsuite integration
vendor/bin/phpunit -c tests/phpunit.xml --testsuite smoke

# Mobile
cd okr_app
flutter test
```

Pré-requisitos: `.env` de teste com banco isolado (NUNCA produção). Smoke usa
`TEST_BASE_URL`, `TEST_USER_EMAIL/PASS` do `phpunit.xml`.

---

## 1. Pirâmide e suítes

```
        ╱ Mobile e2e/golden (poucos) ╲
       ╱   Integração (fluxos OKR)     ╲
      ╱  Contrato de API (por endpoint) ╲
     ╱  RBAC / Permissões (matriz)       ╲
    ╱___ Unitários (lógica de negócio) ____╲   ← base larga, já existe
```

| Suíte | Diretório | Estado | Foco |
|---|---|---|---|
| Unitários de negócio | `tests/Unit/{Cycle,KR}` | ✅ existe | Ciclos, série de datas, qualidade, validação |
| RBAC primitives | `tests/Unit/Acl` | ✅ existe | parse/action/scope/has_cap |
| RBAC matriz | `tests/Unit/Acl` (expandir) | ➕ novo | papel × recurso × escopo × tenant |
| Contrato de API | `tests/Integration/Api` | ➕ novo | por grupo de endpoints |
| Integração de fluxo | `tests/Integration/Flow` | ➕ novo | ciclo de vida OKR ponta a ponta |
| Regressão segurança | `tests/Integration/Security` | ➕ novo | um teste por SEC-xx |
| Smoke | `tests/Smoke` | ✅ existe | conexão DB, login |
| Mobile | `okr_app/test` | ➕ novo | repositórios, DTOs, widgets, golden |

---

## 2. Sequências — RBAC / Permissões (núcleo de segurança)

Matriz a cobrir (cada célula = um caso de teste em `DbTestCase`):

| Papel | `R:kr@ORG` | `W:kr@ORG` | `M:objetivo@ORG` | `W:apontamento@ORG` (envolvido) | `W:apontamento@ORG` (não envolvido) | recurso de outra empresa |
|---|:--:|:--:|:--:|:--:|:--:|:--:|
| `admin_master` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ (bypass) |
| `user_admin` | ✅ | ✅ | ✅ (se tiver cap) | ✅ | ✅ | ❌ (tenant) |
| `gestor_master` | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ |
| `user_colab` | ✅ | ❌ | ❌ | ✅ | ❌ | ❌ |
| sem papel | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |

**Sequências obrigatórias:**
- SEQ-RBAC-01: `admin_master` faz bypass de qualquer cap. *(já existe: `HasCapTest::testAdminMasterBypassAll`)*
- SEQ-RBAC-02: `W` cobre `R`; `M` cobre `W` e `R`; `SYS` cobre `ORG/OWN`.
- SEQ-RBAC-03: DENY de usuário sobrepõe ALLOW de role. *(já existe: `testDenyOverrideBlocksAllow`)*
- SEQ-RBAC-04: **tenant** — usuário da empresa A recebe `false` em `has_cap('W:kr@ORG', ['id_kr'=>krDaEmpresaB])`.
- SEQ-RBAC-05: **colaborador** — `W:apontamento@ORG` só passa se houver linha em `okr_kr_envolvidos`.
- SEQ-RBAC-06: **paridade web↔API** — para o mesmo usuário/recurso, `has_cap()` (web) e
  `api_has_cap()` (API) retornam o mesmo resultado. (teste de não-divergência)

---

## 3. Sequências — Contrato de API (por endpoint)

Para **cada** endpoint, o conjunto-padrão de casos (template):

```
GIVEN um endpoint <METHOD> <path>
- 401 quando sem token / token inválido / expirado
- 403 quando autenticado mas sem a capability exigida
- 422 quando input inválido (campos obrigatórios, enums, datas)
- 404 quando recurso inexistente
- 200/201 no caminho feliz, com SHAPE de resposta estável e envelope padronizado
- CROSS-TENANT: usuário da empresa A recebe 403/404 ao tocar recurso da empresa B
- (mutações) efeito colateral correto e atômico (transação)
```

**Grupos e pontos de atenção específicos:**

### 3.1 Objetivos / KRs / Apontamentos / Iniciativas
- Criar objetivo → criar KR (gera milestones automaticamente) → apontar → ver progresso/farol.
- KR: `meta != baseline` validado; alterar datas regenera/consistência de milestones.
- Apontamento: contador `qtde_apontamentos` consistente com linhas reais (cobre SEC: dessincronia).
- Iniciativa: **exige RBAC** em create/update/delete/status (cobre SEC-04).

### 3.2 Orçamento
- create exige RBAC (cobre SEC-06); add_despesa soma corretamente; resumo/dashboard batem com despesas.
- Isolamento: orçamento de iniciativa de outra empresa → negado.

### 3.3 Aprovações
- decidir: **só item da própria empresa** (cobre SEC-05); item já decidido → conflito; aprovador não habilitado → 403.

### 3.4 Usuários + RBAC
- list/get exigem RBAC (cobre SEC-07).
- create: `role_key` fora da whitelist → rejeitado; não permite `admin_master` por usuário comum (cobre SEC-02).
- role/permissions/update/pre_delete: alvo de outra empresa → 403/404 (cobre SEC-03).

### 3.5 Notificações
- list/count/mark_read filtram por `id_user` (não vazam de outros usuários).

### 3.6 Push (admin)
- Todos os endpoints de campanha/segmento/IA exigem `admin_master`; usuário comum → 403.
- devices_register amarra device ao usuário; unregister remove.

---

## 4. Sequências — Integração de fluxo (ponta a ponta)

**FLOW-01 — Ciclo de vida completo de um OKR** (cobre o coração do produto):
```
1. admin cria empresa + usuários (gestor, colaborador)
2. gestor cria Objetivo (pilar BSC, ciclo trimestral)
3. gestor cria KR (baseline 0 → meta 100) → milestones gerados
4. gestor adiciona colaborador como envolvido no KR
5. colaborador aponta progresso (50) → pct_atual e farol atualizam
6. gestor cria Iniciativa + Orçamento (pendente)
7. aprovador aprova o orçamento → status_aprovacao = aprovado
8. dashboard/cascata reflete o progresso agregado
9. notificações foram geradas nas transições
```
Cada passo valida: persistência, RBAC do ator, isolamento de tenant, e shape de saída.

**FLOW-02 — Negativa de permissão**: colaborador tenta criar Objetivo → 403; tenta apontar
em KR onde não está envolvido → 403.

**FLOW-03 — Multi-tenant**: dois tenants paralelos; nenhuma operação de A enxerga/altera B.

---

## 5. Sequências — Regressão de segurança (um teste por achado)

> Estes testes **devem falhar hoje** (documentam o bug) e **passar após a correção**.
> Marcar com `@group security-regression`. Enquanto não corrigido, usar `markTestIncomplete`
> com referência ao ID para manter o CI verde e o débito visível.

| Teste | Achado | Asserção esperada (pós-fix) |
|---|---|---|
| `ApontamentosListTest` | SEC-01 | `GET /krs/:id/apontamentos` retorna 200 (não 500) |
| `RoleEscalationTest` | SEC-02 | create com `role_key=admin_master` por `user_admin` → rejeitado |
| `UserCrossTenantTest` | SEC-03 | role/permissions de usuário de outra empresa → 403/404 |
| `IniciativaRbacTest` | SEC-04 | colaborador sem cap não cria/edita/exclui iniciativa |
| `AprovacaoTenantTest` | SEC-05 | decidir item de outra empresa → negado |
| `OrcamentoCreateRbacTest` | SEC-06 | create sem cap → 403 |
| `UsuariosListRbacTest` | SEC-07 | list sem cap → 403 |
| `KrCancelJustificativaTest` | SEC-08 | justificativa é persistida |
| `StatusEnumValidationTest` | SEC-09 | status inválido → 422 |

Mobile (SEC-10/11): teste de unidade garantindo que o log de token só ocorre em debug e
que o interceptor de refresh serializa chamadas concorrentes (1 refresh para N 401).

---

## 6. Sequências — Mobile (Flutter)

> Depende da **Fase 1** (camada de DTOs/repositórios). Sem ela, só dá para testar widgets.

- **MOB-01 (unit/DTO)**: cada modelo `fromJson/toJson` (round-trip) com nulos e campos ausentes.
- **MOB-02 (repositório)**: mock do Dio; sucesso, 401 (dispara refresh 1x), 403, 500, timeout.
- **MOB-03 (auth)**: login guarda token em secure storage; logout limpa e chama `unregisterDevice`.
- **MOB-04 (widget)**: estados loading/erro/vazio das listas (OKRs, tarefas, aprovações).
- **MOB-05 (golden)**: `FarolIndicator`, `StatusBadge`, `KpiCard` — consistência visual.
- **MOB-06 (fluxo)**: login → lista OKRs → detalhe → apontar → ver progresso (integração com API mock).

---

## 7. CI (meta)

Pipeline ao push (cobre o objetivo de "garantir que funcione"):
```
backend:  composer install → phpunit (unit + integration + security-regression)
mobile:   flutter analyze → flutter test
gate:     bloquear merge se algum teste de segurança em regressão voltar a falhar
```

---

## 8. Cobertura — alvo por fase

| Fase | Alvo de testes |
|---|---|
| F0/F1.5 | Todos os SEC-xx com teste de regressão verde |
| F1 | DTOs e repositórios do app com unit/MOB-01..03 |
| F2+ | Cada endpoint novo entra com contrato (template §3) **antes** de ser consumido |
| F5 (CRM) | Contrato + tenant + LGPD/consent desde o primeiro endpoint |

> Regra de ouro: **nenhuma feature entra sem o conjunto-padrão de contrato (§3) e, se tiver
> regra de negócio, um teste unitário.** O DoD está em `ARQUITETURA_E_ROADMAP.md` §8.
