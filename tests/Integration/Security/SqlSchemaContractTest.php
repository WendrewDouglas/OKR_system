<?php
declare(strict_types=1);

namespace Tests\Integration\Security;

use PDO;
use Tests\Helpers\DbTestCase;

/**
 * Contrato de schema das queries corrigidas na Fase 0.
 *
 * Executa as MESMAS queries dos handlers corrigidos com um filtro que não casa
 * nenhuma linha. Se alguma COLUNA referenciada não existir, o PDO lança exceção
 * (PDO::ERRMODE_EXCEPTION) e o teste falha — exatamente a classe de bug do SEC-01
 * (a.data_ref) e do SEC-08 (dom_status_kr.id/descricao).
 *
 * Roda em transação + rollback (DbTestCase); não cria fixtures e não suja o banco.
 * Requer apenas conexão com o banco (mesma do HasCapTest).
 *
 * @group security-regression
 */
class SqlSchemaContractTest extends DbTestCase
{
    /** SEC-01 — apontamentos/list.php: colunas devem existir (antes referenciava a.data_ref). */
    public function testApontamentosListQueryColumnsExist(): void
    {
        $sql = "
            SELECT a.id_apontamento, a.id_milestone, a.dt_evidencia,
                   a.valor_real, a.observacao, a.justificativa, a.origem,
                   a.dt_apontamento, a.usuario_id,
                   m.data_ref AS milestone_data, m.valor_esperado
              FROM apontamentos_kr a
              LEFT JOIN milestones_kr m ON m.id_milestone = a.id_milestone
             WHERE a.id_kr = ?
             ORDER BY a.dt_apontamento DESC
        ";
        $st = $this->pdo->prepare($sql);
        $st->execute(['__no_such_kr__']);
        $rows = $st->fetchAll();
        $this->assertIsArray($rows, 'Query de apontamentos/list deve executar sem erro de coluna.');
    }

    /** SEC-08 — krs/cancel.php: colunas corretas do domínio (antes id/descricao). */
    public function testCancelStatusDomainQueryColumnsExist(): void
    {
        $st = $this->pdo->prepare(
            "SELECT id_status FROM dom_status_kr WHERE LOWER(descricao_exibicao) LIKE '%cancel%' LIMIT 1"
        );
        $st->execute();
        // Se as colunas não existissem, execute() teria lançado. Chegar aqui já valida.
        $this->assertTrue(true);
    }

    /** SEC-09 — validação de status do KR depende do domínio dom_status_kr.id_status. */
    public function testKrStatusValidationQueryColumnsExist(): void
    {
        $st = $this->pdo->prepare("SELECT 1 FROM dom_status_kr WHERE id_status = ? LIMIT 1");
        $st->execute(['__no_such_status__']);
        $this->assertSame(false, $st->fetchColumn(), 'Status inexistente não deve casar (base p/ o 422).');
    }
}
