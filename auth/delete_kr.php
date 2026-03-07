<?php
// /auth/delete_kr.php
declare(strict_types=1);

/**
 * Endpoint dedicado para EXCLUSÃO PERMANENTE de um Key Result (KR).
 *
 * - Requer POST + CSRF token válido.
 * - Verifica sessão/autorização.
 * - Apaga, em ordem:
 *   1) Despesas (orcamentos_detalhes) e orçamentos das iniciativas do KR
 *   2) Iniciativas do KR
 *      - Importante: para a tabela apontamentos_status_iniciativas,
 *        deve existir uma FK fk_apont_status_iniciativa com
 *        ON DELETE CASCADE para evitar erro 1451.
 *   3) Milestones do KR
 *   4) Apontamentos do KR
 *   5) Comentários do KR
 *   6) O próprio KR
 *   7) Renumera os demais KRs do mesmo objetivo (key_result_num = 1..N)
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
 * @param string    $msg
 * @param Throwable $e   (opcional)
 * @param array     $ctx (opcional) contexto adicional
 */
function log_delete_kr_error(string $msg, ?Throwable $e = null, array $ctx = []): void
{
    $logFile = __DIR__ . '/error_log';
    $line    = '[' . date('Y-m-d H:i:s') . '] [delete_kr] ' . $msg;

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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

$id_kr = $_POST['id_kr'] ?? '';
$id_kr = is_string($id_kr) ? trim($id_kr) : '';

if ($id_kr === '') {
    echo json_encode([
        'success' => false,
        'error'   => 'id_kr inválido',
    ]);
    exit;
}

/* ===================== ACL / PERMISSÕES ===================== */

/**
 * Gate pela tabela dom_paginas (se estiver configurado) + capability explícita.
 * Você pode ajustar o capability abaixo conforme sua matriz de permissões.
 */
try {
    gate_page_by_path($_SERVER['SCRIPT_NAME'] ?? '');
} catch (Throwable $e) {
    // se der erro aqui, registra e segue com um 403
    log_delete_kr_error('Falha em gate_page_by_path', $e, [
        'script' => $_SERVER['SCRIPT_NAME'] ?? '',
        'id_kr'  => $id_kr,
    ]);
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error'   => 'Acesso negado',
    ]);
    exit;
}

try {
    // Capability de escrita em KR/Objetivo (ajuste se você tiver algo mais específico)
    require_cap('W:objetivo@ORG', ['id_kr' => $id_kr]);
} catch (Throwable $e) {
    log_delete_kr_error('Permissão insuficiente para excluir KR', $e, [
        'user_id' => $_SESSION['user_id'] ?? null,
        'id_kr'   => $id_kr,
    ]);
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error'   => 'Permissão insuficiente para excluir KR',
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
    log_delete_kr_error('Erro de conexão com o banco de dados', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Erro de conexão',
    ]);
    exit;
}

/* ===================== HELPERS (MESMOS DO detalhe_okr.php) ===================== */

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
$findKrIdCol = static function (PDO $pdo, string $table): ?string {
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
    return null;
};

/* ===================== EXCLUSÃO DO KR ===================== */

try {
    $pdo->beginTransaction();

    // 0) Descobre o id_objetivo do KR (para renumeração ao final)
    $st = $pdo->prepare("SELECT `id_objetivo` FROM `key_results` WHERE `id_kr` = :id LIMIT 1");
    $st->execute(['id' => $id_kr]);
    $id_objetivo = (int)($st->fetchColumn() ?: 0);

    if ($id_objetivo === 0) {
        // KR não encontrado ou sem objetivo associado
        throw new RuntimeException('KR não encontrado ou sem id_objetivo');
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
            $krCol = $colExists($pdo, $t, 'id_kr') ? 'id_kr' : null;
        }

        if ($krCol) {
            $stDelMs = $pdo->prepare("DELETE FROM `$t` WHERE `$krCol` = :id");
            $stDelMs->execute(['id' => $id_kr]);
        }
    }

    // 3) Apontamentos
    foreach (['apontamentos_kr', 'apontamentos'] as $t) {
        if (!$tableExists($pdo, $t)) {
            continue;
        }

        $krCol = $findKrIdCol($pdo, $t);
        if (!$krCol) {
            $krCol = $colExists($pdo, $t, 'id_kr') ? 'id_kr' : null;
        }

        if ($krCol) {
            $stDelAp = $pdo->prepare("DELETE FROM `$t` WHERE `$krCol` = :id");
            $stDelAp->execute(['id' => $id_kr]);
        }
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
        throw new RuntimeException('Nenhum registro de KR apagado (KR inexistente?)');
    }

    // 6) Renumeração de key_result_num dentro do mesmo objetivo
    if ($id_objetivo > 0 && $colExists($pdo, 'key_results', 'key_result_num')) {
        $stIds = $pdo->prepare("
            SELECT `id_kr`
            FROM `key_results`
            WHERE `id_objetivo` = :obj
            ORDER BY `key_result_num` ASC, `id_kr` ASC
        ");
        $stIds->execute(['obj' => $id_objetivo]);
        $ids = $stIds->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($ids)) {
            $upd = $pdo->prepare("
                UPDATE `key_results`
                SET `key_result_num` = :n
                WHERE `id_kr` = :id
            ");
            $n = 1;
            foreach ($ids as $kid) {
                $upd->execute([
                    'n'  => $n++,
                    'id' => $kid,
                ]);
            }
        }
    }

    $pdo->commit();

    echo json_encode([
        'success'     => true,
        'id_kr'       => $id_kr,
        'id_objetivo' => $id_objetivo,
        'message'     => 'KR excluído com sucesso',
    ]);
    exit;
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    log_delete_kr_error('Falha ao excluir KR', $e, [
        'id_kr'       => $id_kr,
        'user_id'     => $_SESSION['user_id'] ?? null,
        'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
    ]);

    echo json_encode([
        'success' => false,
        'error'   => 'Falha ao excluir KR. Tente novamente ou contate o administrador.',
    ]);
    exit;
}
