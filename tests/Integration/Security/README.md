# Testes de regressão de segurança (Fase 0)

Validam as correções SEC-01..09 aplicadas em 2026-06-16.

## Duas camadas

| Arquivo | Tipo | Precisa de servidor? | O que valida |
|---|---|:--:|---|
| `SqlSchemaContractTest.php` | DbTestCase (in-process) | Não, só banco | Colunas das queries corrigidas existem (SEC-01, SEC-08, SEC-09). Pega a classe de bug "coluna inexistente → 500". |
| `ApiSecurityRegressionTest.php` | HTTP (smoke) | Sim | Comportamento ponta a ponta: 403/404/422 corretos (SEC-02..07, SEC-09). |

## Rodar a camada in-process (rápida, sem servidor)

```bash
composer install
vendor/bin/phpunit -c tests/phpunit.xml --group security-regression --filter SqlSchemaContract
```
Requer apenas `.env` com banco de **teste** (NUNCA produção).

## Rodar a camada HTTP (ponta a ponta)

1. Suba um servidor de teste apontando para um banco de teste com o schema do projeto.
2. Garanta as fixtures abaixo (use dados que já existam no banco de teste ou crie-os):
   - Empresa A com: um `user_admin`, um `user_colab` (sem caps de escrita) e um aprovador habilitado.
   - Um KR válido da empresa A.
   - Empresa B (outro tenant) com: um usuário e um KR — para os casos cross-tenant.
3. Exporte as variáveis (exemplo PowerShell):

```powershell
$env:TEST_BASE_URL      = "http://localhost/OKR_system"
$env:TEST_ADMIN_EMAIL   = "admin@empresaA.test";   $env:TEST_ADMIN_PASS    = "..."
$env:TEST_COLAB_EMAIL   = "colab@empresaA.test";   $env:TEST_COLAB_PASS    = "..."
$env:TEST_APPROVER_EMAIL= "aprov@empresaA.test";   $env:TEST_APPROVER_PASS = "..."
$env:TEST_KR_ID         = "<id_kr_empresaA>"
$env:TEST_OTHER_USER_ID = "<id_user_empresaB>"
$env:TEST_OTHER_KR_ID   = "<id_kr_empresaB>"
```

4. Rode:
```bash
vendor/bin/phpunit -c tests/phpunit.xml --group security-regression
```

Sem as variáveis, os testes HTTP fazem **skip gracioso** (CI continua verde).
Cada teste skipa individualmente conforme a fixture que falta — configure só o que for testar.

## Mapeamento teste → achado

| Teste | SEC | Espera (pós-fix) |
|---|---|---|
| `SqlSchemaContractTest::testApontamentosListQueryColumnsExist` | SEC-01 | query executa sem erro de coluna |
| `testApontamentosListNaoRetorna500` | SEC-01 | 200 |
| `testCreateUsuarioNaoPermiteAdminMasterPorUsuarioComum` | SEC-02 | 403 |
| `testAlterarRoleDeUsuarioDeOutraEmpresaEhNegado` | SEC-03 | 403/404 |
| `testIniciativaExigeCapability` | SEC-04 | 403 |
| `testDecidirAprovacaoDeOutraEmpresaEhNegado` | SEC-05 | 403/404 |
| `testCriarOrcamentoExigeCapability` | SEC-06 | 403 |
| `testListarUsuariosExigeCapability` | SEC-07 | 403 |
| `testCancelarKrNaoRetorna500` / `SqlSchemaContract::testCancelStatusDomainQueryColumnsExist` | SEC-08 | 200 / query OK |
| `testStatusInvalidoDeKrEhRejeitado` / `SqlSchemaContract::testKrStatusValidationQueryColumnsExist` | SEC-09 (KR) | 422 / query OK |
