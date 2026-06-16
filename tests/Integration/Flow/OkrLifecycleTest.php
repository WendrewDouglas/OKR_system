<?php
declare(strict_types=1);

namespace Tests\Integration\Flow;

use PHPUnit\Framework\TestCase;
use Tests\Helpers\HttpSmokeClient;

/**
 * FLOW-01 — Ciclo de vida completo de um OKR (ponta a ponta via API).
 * Ver docs/PLANO_DE_TESTES.md §4.
 *
 * Valida o coração do produto: objetivo → KR (+milestones) → envolvido →
 * apontamento → iniciativa → orçamento → aprovação → dashboard → notificações,
 * checando em cada passo: persistência, RBAC do ator e isolamento de tenant.
 *
 * Scaffold: implementar à medida que a Fase 1.5 (saneamento da API) avança.
 * Requer ambiente de teste com usuários semente (gestor, colaborador, aprovador).
 *
 * @group flow
 */
class OkrLifecycleTest extends TestCase
{
    private HttpSmokeClient $http;

    protected function setUp(): void
    {
        $this->http = new HttpSmokeClient();
    }

    public function testCicloDeVidaCompletoDeUmOkr(): void
    {
        $this->markTestIncomplete(
            'FLOW-01: implementar a sequência de 9 passos do PLANO_DE_TESTES §4. '
            . 'Cada passo: asserir 2xx, shape de resposta e ator com permissão correta.'
        );

        // 1. gestor cria Objetivo (pilar BSC, ciclo trimestral) → 201
        // 2. gestor cria KR (baseline 0 → meta 100) → 201 + milestones gerados
        // 3. gestor adiciona colaborador como envolvido (okr_kr_envolvidos)
        // 4. colaborador aponta progresso (50) → pct_atual/farol atualizam
        // 5. gestor cria Iniciativa + Orçamento (pendente) → 201
        // 6. aprovador aprova o orçamento → status_aprovacao = aprovado
        // 7. dashboard/cascata reflete progresso agregado
        // 8. notificações geradas nas transições
        // 9. teardown: rollback / limpeza do tenant de teste
    }

    public function testColaboradorNaoCriaObjetivo(): void
    {
        $this->markTestIncomplete('FLOW-02: colaborador em POST /objetivos deve receber 403.');
    }

    public function testIsolamentoEntreTenants(): void
    {
        $this->markTestIncomplete('FLOW-03: nenhuma operação do tenant A enxerga/altera dados do tenant B.');
    }
}
