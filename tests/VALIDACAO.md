# Runbook de Validação — branch `feature/okr-hardening-app-dtos`

Checklist para validar o trabalho F0→F3 antes de merge/deploy. Itens marcados ✅
já foram executados pelo agente; os demais dependem do **seu** ambiente (banco de
teste / device / servidor).

## 1. App Flutter ✅ (executado no worktree, do zero)
```
cd okr_app && flutter pub get && flutter analyze && flutter test
```
- `flutter analyze`: **0 issues**.
- `flutter test`: **19/19 verdes** (DTOs, repositórios, widget).

## 2. Sintaxe PHP ✅
- `php -l` em **84 handlers** de `api/api_platform/v1` + testes: sem erros.

## 3. Suíte PHPUnit ⏳ (rodar no SEU ambiente de teste — NUNCA produção)
Pré: `composer install` (instala phpunit, que é require-dev e não está no vendor de deploy)
e um `.env` apontando para um **banco de teste** com o schema do projeto.

```bash
composer install
vendor/bin/phpunit -c tests/phpunit.xml --group security-regression --filter SqlSchemaContract  # rápido, sem servidor
vendor/bin/phpunit -c tests/phpunit.xml --testsuite unit                                          # ACL/Cycle/KR + RoleCapEffect
# Contratos HTTP (precisam de servidor de teste + variáveis abaixo):
vendor/bin/phpunit -c tests/phpunit.xml --group security-regression
vendor/bin/phpunit -c tests/phpunit.xml --group api-contract
```

Variáveis (PowerShell) para os testes HTTP:
```powershell
$env:TEST_BASE_URL       = "http://localhost/OKR_system"
$env:TEST_ADMIN_EMAIL    = "..."; $env:TEST_ADMIN_PASS    = "..."   # user_admin (empresa A)
$env:TEST_COLAB_EMAIL    = "..."; $env:TEST_COLAB_PASS    = "..."   # user_colab (sem caps de escrita)
$env:TEST_APPROVER_EMAIL = "..."; $env:TEST_APPROVER_PASS = "..."   # aprovador habilitado
$env:TEST_MASTER_EMAIL   = "..."; $env:TEST_MASTER_PASS   = "..."   # admin_master
$env:TEST_KR_ID          = "<id_kr_empresaA>"
$env:TEST_OBJ_ID         = "<id_objetivo_empresaA>"
$env:TEST_OTHER_USER_ID  = "<id_user_empresaB>"   # cross-tenant
$env:TEST_OTHER_KR_ID    = "<id_kr_empresaB>"     # cross-tenant
```
Ver `tests/Integration/Security/README.md` para o seed.

## 4. Conceder capability de relatório ⏳ (senão só admin_master gera PDF)
O `GET /objetivos/:id/relatorio` exige `R:relatorio@ORG`. Rodar no banco:
```sql
INSERT IGNORE INTO rbac_capabilities (cap_key, resource, action, scope)
VALUES ('R:relatorio@ORG', 'relatorio', 'R', 'ORG');

INSERT IGNORE INTO rbac_role_capability (role_id, capability_id, effect)
SELECT r.role_id, c.capability_id, 'ALLOW'
  FROM rbac_roles r
  JOIN rbac_capabilities c ON c.cap_key = 'R:relatorio@ORG'
 WHERE r.role_key IN ('gestor_master','user_admin');
```
(Idempotente. `admin_master` já gera via bypass.)

## 5. Teste em device (app) ⏳ — não cobrível em CI
- **Navegação `StatefulShellRoute`**: trocar entre as 5 abas e voltar — estado/scroll preservado.
- **Páginas full-screen**: detalhe/forms abrem sobre as abas (slide up) e o "voltar" retorna à aba.
- **`okr_map` (ExpandableTile)**: expandir/recolher pilar→objetivo→KR→iniciativa; visual idêntico.
- **Cancelar KR**: agora pede justificativa (alinhado ao backend SEC-08).
- **Aprovações**: lock anti-duplo-toque + diálogo de motivo na rejeição.

## 6. Decisões de produção pendentes
- **PARITY-01/02/03** mudam autorização (web + API): validar no banco de teste antes do deploy.
- Política de **DELETE de empresa** (tenant) — ainda não implementada de propósito.
- Padronização de envelope nos GETs/mutações restantes (rollout incremental).
