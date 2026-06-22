# Runbook de Validação — branch `feature/okr-hardening-app-dtos`

Checklist único para validar todo o trabalho **F0 → F3 → F2** antes de merge/deploy.
Itens ✅ já foram executados pelo agente; ⏳ dependem do **seu** ambiente (banco de
teste / servidor / device). Desenvolvido no worktree `D:/Meus_Projetos/okr_hardening`.

## Status atual
| Camada | Status |
|---|---|
| App Flutter — `flutter analyze` | ✅ **0 issues** |
| App Flutter — `flutter test` | ✅ **23/23 verdes** |
| PHP — `php -l` (116 arquivos) | ✅ sem erros |
| PHPUnit (DB/HTTP) | ⏳ rodar no ambiente de teste |
| Device (UX/navegação) | ⏳ manual |
| Migrations / grants de produção | ⏳ aplicar |
| Dispatch FCM real | ⏳ manual |

---

## 1. App Flutter ✅ (executado, do zero, no worktree)
```bash
cd okr_app && flutter pub get && flutter analyze && flutter test
```
Cobre DTOs, repositórios (Objetivo/KR/Iniciativa/Apontamento/Tarefa/Aprovação/**Usuário**),
envelope, e widget. **23 testes verdes.**

## 2. Sintaxe PHP ✅
`php -l` em **116 arquivos** (`api/api_platform/v1` + `tests`): sem erros.

## 3. Suíte PHPUnit ⏳ (SEU banco de teste — NUNCA produção)
Pré: `composer install` (instala phpunit/require-dev) + `.env` apontando para banco de **teste** com o schema.
```bash
composer install
# Rápidos (sem servidor; precisam só do banco):
vendor/bin/phpunit -c tests/phpunit.xml --group security-regression --filter SqlSchemaContract
vendor/bin/phpunit -c tests/phpunit.xml --testsuite unit          # ACL/Cycle/KR + RoleCapEffect (PARITY-03)
# Ponta a ponta (servidor de teste + variáveis abaixo):
vendor/bin/phpunit -c tests/phpunit.xml --group security-regression   # SEC-01..09
vendor/bin/phpunit -c tests/phpunit.xml --group api-contract          # companies/health/relatorio/matriz/push/style
```
Variáveis (PowerShell) para os grupos HTTP:
```powershell
$env:TEST_BASE_URL       = "http://localhost/OKR_system"
$env:TEST_ADMIN_EMAIL    = "..."; $env:TEST_ADMIN_PASS    = "..."   # user_admin (empresa A)
$env:TEST_COLAB_EMAIL    = "..."; $env:TEST_COLAB_PASS    = "..."   # user_colab (sem caps de escrita)
$env:TEST_APPROVER_EMAIL = "..."; $env:TEST_APPROVER_PASS = "..."   # aprovador habilitado
$env:TEST_MASTER_EMAIL   = "..."; $env:TEST_MASTER_PASS   = "..."   # admin_master (companies/health/push)
$env:TEST_KR_ID          = "<id_kr_empresaA>"
$env:TEST_OBJ_ID         = "<id_objetivo_empresaA>"
$env:TEST_OTHER_USER_ID  = "<id_user_empresaB>"   # cross-tenant (SEC-03)
$env:TEST_OTHER_KR_ID    = "<id_kr_empresaB>"     # cross-tenant (SEC-05)
```
Seed/detalhes: `tests/Integration/Security/README.md`. Cada teste skipa se faltar fixture.

## 4. Migrations / grants de produção ⏳
- **Tabelas de push** (`tools/migrations/push_tables.sql`) aplicadas no ambiente de teste.
- **Conceder `R:relatorio@ORG`** (senão só admin_master gera PDF):
```sql
INSERT IGNORE INTO rbac_capabilities (cap_key, resource, action, scope)
VALUES ('R:relatorio@ORG', 'relatorio', 'R', 'ORG');
INSERT IGNORE INTO rbac_role_capability (role_id, capability_id, effect)
SELECT r.role_id, c.capability_id, 'ALLOW'
  FROM rbac_roles r JOIN rbac_capabilities c ON c.cap_key = 'R:relatorio@ORG'
 WHERE r.role_key IN ('gestor_master','user_admin');
```

## 5. Teste em device (app) ⏳ — não cobrível em CI
- **Navegação `StatefulShellRoute`**: alternar as 5 abas e voltar — estado/scroll preservado.
- **Páginas full-screen** (detalhe/forms/`/usuarios`): abrem sobre as abas; "voltar" retorna à aba.
- **`okr_map`**: expandir/recolher pilar→objetivo→KR→iniciativa (ExpandableTile) — visual idêntico.
- **Cancelar KR**: pede justificativa (alinhado ao backend SEC-08).
- **Aprovações**: lock anti-duplo-toque + diálogo de motivo na rejeição.
- **Tela de Usuários (F2)**: listar, criar (senha+papel), editar (e-mail imutável), trocar papel, excluir.
  Acesso via Menu → Gestão → Usuários. Não-admin recebe 403 (a API barra).

## 6. Endpoints novos da F3 a exercitar (manual/Postman)
- `GET /companies`, `GET/POST/PUT /companies/:id` (admin_master).
- `GET /system/health` (admin_master) → overall PASS/WARN/FAIL.
- `GET /objetivos/:id/relatorio` → PDF (precisa do grant do item 4).
- `GET /matriz-prioridade` → 4 quadrantes de iniciativas.
- `DELETE /company/style` → reset de estilo.
- `POST /push/campaigns` + `PUT /push/campaigns/:id` + `POST /push/campaigns/:id/send`
  (**envio real via FCM** — validar com credenciais FCM V1 e um device de teste).

## 7. Decisões de produção pendentes
- **PARITY-01/02/03** mudam autorização (web + API): confirmar no banco de teste antes do deploy.
- **DELETE de empresa** (tenant) — não implementado de propósito (definir política).
- **Push send** é síncrono (lotes de 100) — avaliar fila/worker para audiências grandes.
- Rollout incremental do envelope nos GETs/mutações restantes.

## 8. Histórico da branch (commits)
F0 (SEC-01..11) → F1.5/PARITY → DTOs/repos → telas+forms → StatefulShellRoute → ExpandableTile →
F3 (companies+health, PDF, reset estilo, matriz, push) → validação/cleanup → F2 (usuários).
