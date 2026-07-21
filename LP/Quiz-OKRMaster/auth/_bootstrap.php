<?php
declare(strict_types=1);

// Todas as APIs deste modulo respondem JSON
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// .../OKR_system/LP/Quiz-OKRMaster/auth  ->  volta 3 niveis
$root       = dirname(__DIR__, 3);
$configPath = $root . '/auth/config.php';

if (!is_file($configPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Configuracao nao encontrada'], JSON_UNESCAPED_UNICODE);
    exit;
}
require_once $configPath;

/** Instancia PDO singleton (planni40_okr) */
function pdo(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME
         . ';charset=' . (defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        return $pdo;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Falha na conexao com o banco'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function json_input(): array {
    $raw = file_get_contents('php://input');
    $j   = json_decode($raw ?: '[]', true);
    return is_array($j) ? $j : [];
}

function ok($data = []): void {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $msg, int $code = 400, array $extra = []): void {
    http_response_code($code);
    echo json_encode(array_merge(['error' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function ip_bin(): ?string {
    $bin = @inet_pton($_SERVER['REMOTE_ADDR'] ?? '');
    return ($bin === false) ? null : $bin;
}

/** Token opaco de sessao */
function novo_token(): string {
    return bin2hex(random_bytes(24)); // 48 chars
}

/**
 * Resolve a sessao pelo token. $exigirAberta bloqueia gravacao em
 * sessao ja finalizada - trava que o quiz lp001 nao possui.
 */
function sessao_por_token(PDO $pdo, string $token, bool $exigirAberta = false): array {
    if ($token === '') fail('Sessao invalida', 400);
    $st = $pdo->prepare("
        SELECT s.*, a.nome AS aluno_nome, a.email AS aluno_email
          FROM okrm_sessoes s
          JOIN okrm_alunos a ON a.id_aluno = s.id_aluno
         WHERE s.session_token = ? LIMIT 1
    ");
    $st->execute([$token]);
    $s = $st->fetch();
    if (!$s) fail('Sessao nao encontrada', 404);
    if ($exigirAberta && $s['status'] !== 'aberta') {
        fail('Esta avaliacao ja foi finalizada.', 409);
    }
    return $s;
}

/** Modulo e versao ativa correntes */
function versao_ativa(PDO $pdo, string $codigoModulo): array {
    $st = $pdo->prepare("
        SELECT m.id_modulo, m.codigo, m.titulo, m.subtitulo,
               v.id_versao, v.label
          FROM okrm_modulos m
          JOIN okrm_versao  v ON v.id_modulo = m.id_modulo AND v.is_ativa = 1
         WHERE m.codigo = ? AND m.ativo = 1
         ORDER BY v.id_versao DESC LIMIT 1
    ");
    $st->execute([$codigoModulo]);
    $v = $st->fetch();
    if (!$v) fail('Nenhuma versao ativa para o modulo ' . $codigoModulo, 404);
    return $v;
}
