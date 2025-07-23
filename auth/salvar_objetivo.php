<?php
// auth/salvar_objetivo.php

// DEV ONLY: exibe erros na tela (remova em produção)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/config.php';      // define DB_HOST, DB_NAME, DB_USER, DB_PASS
require_once __DIR__ . '/functions.php';   // se você tiver funções utilitárias

// Carrega variáveis de ambiente do .env (um nível acima de auth)
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $name  = trim($name);
        $value = trim($value, " \t\n\r\0\x0B'\"");
        putenv("{$name}={$value}");
    }
}
$apiKey = getenv('OPENAI_API_KEY');
if (!$apiKey) {
    http_response_code(500);
    echo json_encode(['error' => 'Chave de API da OpenAI não encontrada']);
    exit;
}

header('Content-Type: application/json');

// autenticação
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autorizado']);
    exit;
}

// apenas POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

// CSRF
if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(['error' => 'Token CSRF inválido']);
    exit;
}

// recebe campos
$descricao       = trim($_POST['nome_objetivo'] ?? '');
$tipo_id         = $_POST['tipo_objetivo'] ?? '';
$pilar_id        = $_POST['pilar_bsc'] ?? '';
$responsaveis    = trim($_POST['responsavel'] ?? '');
$observacoes     = trim($_POST['observacoes'] ?? '');
$dt_prazo        = $_POST['prazo_final'] ?? '';
$evaluate        = $_POST['evaluate'] ?? '0';
$usuario_criador = $_SESSION['user_id'];

// validações básicas
if ($descricao === '' || $tipo_id === '' || $pilar_id === '' || $responsaveis === '' || $dt_prazo === '') {
    http_response_code(422);
    echo json_encode(['error' => 'Campos obrigatórios não preenchidos']);
    exit;
}

// conecta ao banco
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro de conexão: ' . $e->getMessage()]);
    exit;
}

// busca descrições de tipo e pilar
$stmt = $pdo->prepare("SELECT descricao_exibicao FROM dom_tipo_objetivo WHERE id_tipo = ?");
$stmt->execute([$tipo_id]);
$tipo_label = $stmt->fetchColumn() ?: $tipo_id;

$stmt = $pdo->prepare("SELECT descricao_exibicao FROM dom_pilar_bsc WHERE id_pilar = ?");
$stmt->execute([$pilar_id]);
$pilar_label = $stmt->fetchColumn() ?: $pilar_id;

// busca nomes dos responsáveis
$resp_ids = array_filter(explode(',', $responsaveis), fn($v)=>is_numeric($v));
if (count($resp_ids) > 0) {
    $in  = str_repeat('?,', count($resp_ids) - 1) . '?';
    $stmt = $pdo->prepare("SELECT primeiro_nome, ultimo_nome FROM usuarios WHERE id_user IN ($in)");
    $stmt->execute($resp_ids);
    $names = array_map(fn($r)=>$r['primeiro_nome'].' '.$r['ultimo_nome'], $stmt->fetchAll());
    $responsaveis_str = implode(', ', $names);
} else {
    $responsaveis_str = 'N/A';
}

/**
 * Chama a OpenAI para avaliar o objetivo.
 * Retorna ['score'=>int,'justification'=>string]
 */
function evaluateObjective($apiKey, $descricao, $tipo, $pilar, $responsaveis, $prazo) {
    $system = "Você é um avaliador de objetivos estratégicos espedcialista em OKR. Avalie se o objetivo é 1 - claro e inspirador, 2 - se o pilar BSC atribuído está correto ou se outro seria mais adequado, 3 - A data de prazo final permite um prazo satisfatório para atingimento, 4 - se o tipo de objetivo corresponde à descrição do objetivo e 5 - . Retorne SOMENTE um JSON com dois campos: \"score\" (inteiro de 0 a 10) e \"justification\" (texto curto justificando a nota).";
    $user   = "Objetivo: {$descricao}\nTipo: {$tipo}\nPilar BSC: {$pilar}\nResponsável(is): {$responsaveis}\nPrazo final: {$prazo}";
    $payload = json_encode([
        'model'       => 'gpt-4',
        'messages'    => [
            ['role'=>'system','content'=>$system],
            ['role'=>'user','content'=>$user]
        ],
        'max_tokens'  => 150,
        'temperature' => 0.7,
    ]);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            "Authorization: Bearer {$apiKey}"
        ],
        CURLOPT_POSTFIELDS     => $payload,
    ]);
    $result = curl_exec($ch);
    if (curl_errno($ch)) {
        curl_close($ch);
        return ['score'=>0,'justification'=>'Erro ao contatar serviço de IA.'];
    }
    curl_close($ch);

    $resp = json_decode($result, true);
    $content = $resp['choices'][0]['message']['content'] ?? '';
    $json = json_decode($content, true);
    if (json_last_error() === JSON_ERROR_NONE && isset($json['score'], $json['justification'])) {
        return [
            'score'         => intval($json['score']),
            'justification' => trim($json['justification'])
        ];
    }
    // fallback
    return ['score'=>0,'justification'=>'Resposta inválida da IA.'];
}

// se for só avaliação
if ($evaluate === '1') {
    $eval = evaluateObjective($apiKey, $descricao, $tipo_label, $pilar_label, $responsaveis_str, $dt_prazo);
    echo json_encode([
        'score'         => $eval['score'],
        'justification' => $eval['justification']
    ]);
    exit;
}

// salvar no banco
$eval = evaluateObjective($apiKey, $descricao, $tipo_label, $pilar_label, $responsaveis_str, $dt_prazo);
$score = $eval['score'];

try {
    $stmt = $pdo->prepare("
        INSERT INTO objetivos
            (descricao, tipo, pilar_bsc, dono, usuario_criador, status, dt_criacao, dt_prazo, status_aprovacao, qualidade, observacoes)
        VALUES
            (:descricao, :tipo, :pilar, :dono, :usuario_criador, :status, :dt_criacao, :dt_prazo, :status_aprovacao, :qualidade, :observacoes)
    ");
    $stmt->execute([
        ':descricao'       => $descricao,
        ':tipo'            => $tipo_id,
        ':pilar'           => $pilar_id,
        ':dono'            => $responsaveis,
        ':usuario_criador' => $usuario_criador,
        ':status'          => 'nao iniciado',
        ':dt_criacao'      => date('Y-m-d H:i:s'),
        ':dt_prazo'        => $dt_prazo,
        ':status_aprovacao'=> 'pendente',
        ':qualidade'       => $score,
        ':observacoes'     => $observacoes,
    ]);

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Falha ao salvar: '.$e->getMessage()]);
}
