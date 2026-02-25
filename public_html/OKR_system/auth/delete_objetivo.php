<?php
// /OKR_system/auth/delete_objetivo.php
declare(strict_types=1);

/**
 * Endpoint dedicado para EXCLUSÃO PERMANENTE de um Objetivo.
 *
 * - Requer POST + CSRF token válido.
 * - Verifica sessão/autorização.
 * - Apaga, em ordem:
 *   1) Para cada KR do objetivo:
 *      1.1) Despesas (orcamentos_detalhes) e orçamentos das iniciativas do KR
 *      1.2) Iniciativas do KR
 *      1.3) Milestones do KR
 *      1.4) Apontamentos do KR
 *      1.5) Comentários do KR
 *      1.6) O próprio KR
 *   2) O próprio objetivo
 *
 * Em caso de erro:
 *   - Faz rollback, se houver transação aberta
 *   - Registra o erro em /auth/error_log
 *   - Retorna JSON com success=false
 */

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/acl.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * Log de erros específico deste endpoint.
 *
 * @param string         $msg
 * @param Throwable|null $e   (opcional)
 * @param array          $ctx (opcional) contexto adicional
 */
function log_delete_objetivo_error(string $msg, ?Throwable $e = null, array $ctx = []): void
{
    $logFile = __DIR__ . '/error_log';
    $line    = '[' . date('Y-m-d H:i:s') . '] [delete_objetivo] ' . $msg;

    if (!empty($ctx)) {
        $line .= ' | ctx=' . json_encode($ctx, JSON_UNESCAPED_UNICODE);
    }

    if ($e !== null) {
        $line .= ' | ex=' . get_class($e) . ': ' . $e->getMessage() .
                 ' @ ' . $e->getFile() . ':' . $e->getLine();
    }

    $line .= PHP_EOL;

    // best-effort: não queremos derrubar a resposta por falha de log
    try {
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    } catch (Throwable $e2) {
        // silencia
    }
}

/* ===================== VALIDACÕES BÁSICAS ===================== */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error'   => 'Método não permitido. Use POST.',
    ]);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error'   => 'Não autorizado',
    ]);
    exit;
}

if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error'   => 'Token CSRF inválido',
    ]);
    exit;
}

$id_objetivo = $_POST['id_objetivo'] ?? null;
$id_objetivo = is_numeric($id_objetivo) ? (int)$id_objetivo : 0;

if ($id_objetivo <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'id_objetivo inválido',
    ]);
    exit;
}

/* ===================== ACL / PERMISSÕES ===================== */

try {
    gate_page_by_path($_SERVER['SCRIPT_NAME'] ?? '');
} catch (Throwable $e) {
    log_delete_objetivo_error('Falha em gate_page_by_path', $e, [
        'script'      => $_SERVER['SCRIPT_NAME'] ?? '',
        'id_objetivo' => $id_objetivo,
    ]);
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error'   => 'Acesso negado',
    ]);
    exit;
}

try {
    // Capability de escrita em Objetivo/KR (ajuste se precisar de algo mais específico)
    require_cap('W:objetivo@ORG', ['id_objetivo' => $id_objetivo]);
} catch (Throwable $e) {
    log_delete_objetivo_error('Permissão insuficiente para excluir objetivo', $e, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'id_objetivo' => $id_objetivo,
    ]);
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error'   => 'Permissão insuficiente para excluir objetivo',
    ]);
    exit;
}

/* ===================== CONEXÃO PDO ===================== */

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    log_delete_objetivo_error('Erro de conexão com o banco de dados', $e, [
        'id_objetivo' => $id_objetivo,
    ]);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Erro de conexão',
    ]);
    exit;
}

/* ===================== HELPERS (MESMOS DO delete_kr.php) ===================== */

$tableExists = static function (PDO $pdo, string $table): bool {
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    try {
        $pdo->query("SHOW COLUMNS FROM `$table`");
        return $cache[$table] = true;
    } catch (Throwable $e) {
        return $cache[$table] = false;
    }
};

$colExists = static function (PDO $pdo, string $table, string $col): bool {
    static $cache = [];
    $key = "$table.$col";
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :c");
        $st->execute(['c' => $col]);
        $cache[$key] = (bool)$st->fetch();
        return $cache[$key];
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
};

/**
 * Descobre o nome da coluna que referencia o KR em uma tabela.
 */
$findKrIdCol = static function (PDO $pdo, string $table) use ($colExists): ?string {
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return null;
    }
    foreach ($cols as $c) {
        $f = strtolower($c['Field']);
        if (in_array($f, ['id_kr', 'kr_id', 'id_key_result', 'key_result_id'], true)) {
            return $c['Field'];
        }
    }
    // fallback leve
    if ($colExists($pdo, $table, 'id_kr')) {
        return 'id_kr';
    }
    return null;
};

/**
 * Exclui um KR e seus dependentes, reaproveitando a mesma lógica do delete_kr.php,
 * porém sem transação própria (quem chama controla) e sem renumeração (não necessária
 * quando estamos deletando o objetivo inteiro).
 *
 * @param PDO      $pdo
 * @param string   $id_kr
 * @param callable $tableExists
 * @param callable $colExists
 * @param callable $findKrIdCol
 */
function delete_kr_cascade(PDO $pdo, string $id_kr, callable $tableExists, callable $colExists, callable $findKrIdCol): void
{
    if ($id_kr === '') {
        throw new RuntimeException('id_kr inválido ao tentar excluir dentro de delete_objetivo');
    }

    // 1) Apaga despesas e orçamentos das iniciativas do KR
    if ($tableExists($pdo, 'iniciativas')) {
        $st = $pdo->prepare("SELECT `id_iniciativa` FROM `iniciativas` WHERE `id_kr` = :id");
        $st->execute(['id' => $id_kr]);
        $inis = $st->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($inis)) {
            // Orçamentos + detalhes
            if ($tableExists($pdo, 'orcamentos')) {
                if ($tableExists($pdo, 'orcamentos_detalhes')) {
                    $placeholders = implode(',', array_fill(0, count($inis), '?'));
                    $sql          = "DELETE od FROM `orcamentos_detalhes` od
                                     INNER JOIN `orcamentos` o ON o.`id_orcamento` = od.`id_orcamento`
                                     WHERE o.`id_iniciativa` IN ($placeholders)";
                    $stDelDet     = $pdo->prepare($sql);
                    $stDelDet->execute($inis);
                }

                $placeholders = implode(',', array_fill(0, count($inis), '?'));
                $sql          = "DELETE FROM `orcamentos`
                                 WHERE `id_iniciativa` IN ($placeholders)";
                $stDelOrc     = $pdo->prepare($sql);
                $stDelOrc->execute($inis);
            }

            // Iniciativas
            $placeholders = implode(',', array_fill(0, count($inis), '?'));
            $sql          = "DELETE FROM `iniciativas`
                             WHERE `id_iniciativa` IN ($placeholders)";
            $stDelIni     = $pdo->prepare($sql);
            $stDelIni->execute($inis);
        }
    }

    // 2) Milestones
    foreach (['milestones_kr', 'milestones'] as $t) {
        if (!$tableExists($pdo, $t)) {
            continue;
        }

        $krCol = $findKrIdCol($pdo, $t);
        if (!$krCol) {
            continue;
        }

        $stDelMs = $pdo->prepare("DELETE FROM `$t` WHERE `$krCol` = :id");
        $stDelMs->execute(['id' => $id_kr]);
    }

    // 3) Apontamentos
    foreach (['apontamentos_kr', 'apontamentos'] as $t) {
        if (!$tableExists($pdo, $t)) {
            continue;
        }

        $krCol = $findKrIdCol($pdo, $t);
        if (!$krCol) {
            continue;
        }

        $stDelAp = $pdo->prepare("DELETE FROM `$t` WHERE `$krCol` = :id");
        $stDelAp->execute(['id' => $id_kr]);
    }

    // 4) Comentários
    foreach (['kr_comentarios', 'comentarios_kr'] as $t) {
        if ($tableExists($pdo, $t) && $colExists($pdo, $t, 'id_kr')) {
            $stDelCom = $pdo->prepare("DELETE FROM `$t` WHERE `id_kr` = :id");
            $stDelCom->execute(['id' => $id_kr]);
        }
    }

    // 5) O KR em si
    $stDelKr = $pdo->prepare("DELETE FROM `key_results` WHERE `id_kr` = :id");
    $stDelKr->execute(['id' => $id_kr]);

    if ($stDelKr->rowCount() === 0) {
        // Nada apagado – provavelmente o KR já não existe
        throw new RuntimeException('Nenhum registro de KR apagado (KR inexistente?) ao excluir objetivo');
    }
}

/* ===================== EXCLUSÃO DO OBJETIVO + KRs ===================== */

$krsDoObjetivo = [];
$dadosObjetivo = null;

try {
    $pdo->beginTransaction();

    // 0) Confirma que o objetivo existe e pega dados básicos
    $st = $pdo->prepare("
        SELECT `id_objetivo`, `descricao`
        FROM `objetivos`
        WHERE `id_objetivo` = :id
        LIMIT 1
    ");
    $st->execute(['id' => $id_objetivo]);
    $dadosObjetivo = $st->fetch();

    if (!$dadosObjetivo) {
        throw new RuntimeException('Objetivo não encontrado');
    }

    // 1) Lista todos os KRs vinculados ao objetivo
    $stK = $pdo->prepare("
        SELECT `id_kr`
        FROM `key_results`
        WHERE `id_objetivo` = :id
    ");
    $stK->execute(['id' => $id_objetivo]);
    $krsDoObjetivo = $stK->fetchAll(PDO::FETCH_COLUMN) ?: [];

    // 2) Para cada KR, aplica a mesma cascata do delete_kr.php
    foreach ($krsDoObjetivo as $id_kr) {
        $id_kr = is_string($id_kr) ? trim($id_kr) : (string)$id_kr;

        if ($id_kr === '') {
            // registra em log e segue, mas isso não deveria ocorrer
            log_delete_objetivo_error('id_kr vazio/zero ignorado ao excluir objetivo', null, [
                'id_objetivo' => $id_objetivo,
                'id_kr'       => $id_kr,
            ]);
            continue;
        }

        delete_kr_cascade($pdo, $id_kr, $tableExists, $colExists, $findKrIdCol);
    }

    // 3) Exclusão de tabelas auxiliares ligadas diretamente ao objetivo (se existirem)
    //    Se você tiver, por exemplo, objetivos_comentarios, objetivos_tags, etc., pode incluir aqui:
    //    if ($tableExists($pdo, 'objetivos_comentarios')) { ... }

    // 4) Exclui o objetivo em si
    $stDelObj = $pdo->prepare("DELETE FROM `objetivos` WHERE `id_objetivo` = :id");
    $stDelObj->execute(['id' => $id_objetivo]);

    if ($stDelObj->rowCount() === 0) {
        throw new RuntimeException('Objetivo não foi removido (rowCount = 0). Pode já ter sido excluído.');
    }

    $pdo->commit();

    echo json_encode([
        'success'       => true,
        'id_objetivo'   => $id_objetivo,
        'descricao'     => $dadosObjetivo['descricao'] ?? null,
        'krs_excluidos' => $krsDoObjetivo,
        'message'       => 'Objetivo e KRs excluídos com sucesso',
    ]);
    exit;
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    log_delete_objetivo_error('Falha ao excluir objetivo', $e, [
        'id_objetivo'   => $id_objetivo,
        'user_id'       => $_SESSION['user_id'] ?? null,
        'krsDoObjetivo' => $krsDoObjetivo,
        'descricao'     => $dadosObjetivo['descricao'] ?? null,
        'request_uri'   => $_SERVER['REQUEST_URI'] ?? '',
    ]);

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Falha ao excluir objetivo. Tente novamente ou contate o administrador.',
    ]);
    exit;
}
