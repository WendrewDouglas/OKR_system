<?php
declare(strict_types=1);

// Sempre responder em JSON
header('Content-Type: application/json; charset=utf-8');

// ===== Config/env (caminho robusto) =====
// Este arquivo está em: OKR_system/LP/Quizz-01/auth/lead_start.php
$root       = dirname(__DIR__, 3);               // .../OKR_system
$configPath = $root . '/auth/config.php';

if (!is_file($configPath)) {
    http_response_code(500);
    echo json_encode([
        'error'   => 'server_error',
        'message' => 'Arquivo de configuração não encontrado: ' . $configPath,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
require_once $configPath;

// ===== Helpers mínimos (iguais aos do seu bootstrap reescrito) =====
function pdo(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER') || !defined('DB_PASS')) {
        http_response_code(500);
        echo json_encode([
            'error'   => 'server_error',
            'message' => 'Constantes de conexão DB_* não definidas.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . (defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');

    $defaultOptions = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,   // mantém nativo; evitamos reutilizar placeholders
    ];
    $cfgOptions = $GLOBALS['options'] ?? [];
    if (!is_array($cfgOptions)) $cfgOptions = [];
    $options = $cfgOptions + $defaultOptions;

    return $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
}
function json_input(): array {
    $raw = file_get_contents('php://input');
    $j   = json_decode($raw ?: '[]', true);
    return is_array($j) ? $j : [];
}
function ok($data = []): void { echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function fail(string $msg, int $code = 400): void { http_response_code($code); echo json_encode(['error'=>$msg], JSON_UNESCAPED_UNICODE); exit; }

// ===== Handler =====
try {
    $in    = json_input();
    $email = trim((string)($in['email'] ?? ''));
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail('E-mail inválido', 422);
    }

    $nome   = trim((string)($in['nome']  ?? ''));
    $cargo  = trim((string)($in['cargo'] ?? ''));
    $consT  = !empty($in['consent_termos']);
    $consM  = !empty($in['consent_marketing']);

    $utm = [
        'utm_source'   => $in['utm_source']   ?? null,
        'utm_medium'   => $in['utm_medium']   ?? null,
        'utm_campaign' => $in['utm_campaign'] ?? null,
        'utm_content'  => $in['utm_content']  ?? null,
        'utm_term'     => $in['utm_term']     ?? null,
    ];

    $pdo = pdo();
    $pdo->beginTransaction();

    // Resolve/insere cargo, se vier preenchido
    $id_cargo = null;
    if ($cargo !== '') {
        $st = $pdo->prepare("SELECT id_cargo FROM lp001_dom_cargos WHERE nome = ? LIMIT 1");
        $st->execute([$cargo]);
        $id_cargo = $st->fetchColumn();
        if (!$id_cargo) {
            $pdo->prepare("INSERT INTO lp001_dom_cargos (nome) VALUES (?)")->execute([$cargo]);
            $id_cargo = (int)$pdo->lastInsertId();
        } else {
            $id_cargo = (int)$id_cargo;
        }
    }

    // Procura lead existente
    $st = $pdo->prepare("SELECT id_lead FROM lp001_quiz_leads WHERE email = ? LIMIT 1");
    $st->execute([$email]);
    $id_lead = $st->fetchColumn();
    $id_lead = $id_lead ? (int)$id_lead : null;

    if ($id_lead) {
        // UPDATE com placeholders únicos (sem reutilização)
        $sql = "
            UPDATE lp001_quiz_leads
               SET nome = COALESCE(NULLIF(:n_up, ''), nome),
                   id_cargo = :cargo_up,
                   consent_termos = :ct_up,
                   consent_marketing = :cm_up,
                   dt_consent = CASE WHEN :ct_up_dt = 1 OR :cm_up_dt = 1
                                     THEN NOW() ELSE dt_consent END,
                   utm_source   = COALESCE(:us_up, utm_source),
                   utm_medium   = COALESCE(:um_up, utm_medium),
                   utm_campaign = COALESCE(:uc_up, utm_campaign),
                   utm_content  = COALESCE(:ucont_up, utm_content),
                   utm_term     = COALESCE(:ut_up, utm_term)
             WHERE id_lead = :id_up
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':n_up'      => $nome,
            ':cargo_up'  => $id_cargo,                 // pode ser null
            ':ct_up'     => $consT ? 1 : 0,
            ':cm_up'     => $consM ? 1 : 0,
            ':ct_up_dt'  => $consT ? 1 : 0,            // placeholders únicos para CASE
            ':cm_up_dt'  => $consM ? 1 : 0,
            ':us_up'     => $utm['utm_source'],
            ':um_up'     => $utm['utm_medium'],
            ':uc_up'     => $utm['utm_campaign'],
            ':ucont_up'  => $utm['utm_content'],
            ':ut_up'     => $utm['utm_term'],
            ':id_up'     => $id_lead,
        ]);
    } else {
        // INSERT com placeholders únicos
        $sql = "
            INSERT INTO lp001_quiz_leads
                (nome, email, id_cargo,
                 consent_termos, consent_marketing, dt_consent,
                 utm_source, utm_medium, utm_campaign, utm_content, utm_term)
            VALUES
                (:n_in, :e_in, :cargo_in,
                 :ct_in, :cm_in,
                 CASE WHEN :ct_in_dt = 1 OR :cm_in_dt = 1 THEN NOW() ELSE NULL END,
                 :us_in, :um_in, :uc_in, :ucont_in, :ut_in)
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':n_in'      => $nome,
            ':e_in'      => $email,
            ':cargo_in'  => $id_cargo,                   // pode ser null
            ':ct_in'     => $consT ? 1 : 0,
            ':cm_in'     => $consM ? 1 : 0,
            ':ct_in_dt'  => $consT ? 1 : 0,
            ':cm_in_dt'  => $consM ? 1 : 0,
            ':us_in'     => $utm['utm_source'],
            ':um_in'     => $utm['utm_medium'],
            ':uc_in'     => $utm['utm_campaign'],
            ':ucont_in'  => $utm['utm_content'],
            ':ut_in'     => $utm['utm_term'],
        ]);
        $id_lead = (int)$pdo->lastInsertId();
    }

    $pdo->commit();
    ok(['id_lead' => $id_lead]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Exibe o detalhe do erro na resposta JSON (facilita debugar no front)
    http_response_code(500);
    echo json_encode([
        'error'   => 'lead_start_failed',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
