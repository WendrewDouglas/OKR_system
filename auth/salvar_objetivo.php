<?php
// auth/salvar_objetivo.php

// DEV ONLY: exibe erros na tela (remova em produção)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 1) Inicia sessão antes de qualquer saída
session_start();

// 2) Autoload e logger
$logger = require dirname(__DIR__) . '/bootstrap.php';

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

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

// Garante que vamos enviar JSON puro
header('Content-Type: application/json');

// Autenticação
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autorizado']);
    exit;
}

// Apenas POST
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

// CAPTURA DOS CAMPOS DO FORMULÁRIO
$descricao        = trim($_POST['nome_objetivo']    ?? '');
$tipo_id          =                $_POST['tipo_objetivo'] ?? '';
$pilar_id         =                $_POST['pilar_bsc']      ?? '';
$responsavel      = trim($_POST['responsavel']      ?? '');
$observacoes      = trim($_POST['observacoes']      ?? '');
$tipo_ciclo       =                $_POST['ciclo_tipo']     ?? '';
$usuario_criador  = $_SESSION['user_id'];
$evaluate         =                $_POST['evaluate']       ?? '0';
$justificativa_ia = trim($_POST['justificativa_ia'] ?? '');

// Determina o campo detalhado do ciclo
$ciclo_detalhe = '';
switch ($tipo_ciclo) {
    case 'anual':
        $ciclo_detalhe = $_POST['ciclo_anual_ano'] ?? '';
        break;
    case 'semestral':
        $ciclo_detalhe = $_POST['ciclo_semestral'] ?? '';
        break;
    case 'trimestral':
        $ciclo_detalhe = $_POST['ciclo_trimestral'] ?? '';
        break;
    case 'bimestral':
        $ciclo_detalhe = $_POST['ciclo_bimestral'] ?? '';
        break;
    case 'mensal':
        $mes = $_POST['ciclo_mensal_mes'] ?? '';
        $ano = $_POST['ciclo_mensal_ano'] ?? '';
        if ($mes && $ano) {
            $ciclo_detalhe = "$mes/$ano";
        }
        break;
    case 'personalizado':
        $ini = $_POST['ciclo_pers_inicio'] ?? '';
        $fim = $_POST['ciclo_pers_fim']     ?? '';
        if ($ini && $fim) {
            $ciclo_detalhe = "$ini a $fim";
        }
        break;
}

// ----------- CÁLCULO DE dt_inicio E dt_prazo BASEADO NO CICLO -----------
function calcularDatasCiclo(string $tipo_ciclo, array $dados): array {
    $dt_inicio = '';
    $dt_prazo  = '';

    switch ($tipo_ciclo) {
        case 'anual':
            $ano = $dados['ciclo_anual_ano'] ?? '';
            if ($ano) {
                $dt_inicio = "$ano-01-01";
                $dt_prazo  = "$ano-12-31";
            }
            break;

        case 'semestral':
            if (preg_match('/S([12])\/(\d{4})/', $dados['ciclo_semestral'] ?? '', $m)) {
                if ($m[1] === '1') {
                    $dt_inicio = "{$m[2]}-01-01";
                    $dt_prazo  = "{$m[2]}-06-30";
                } else {
                    $dt_inicio = "{$m[2]}-07-01";
                    $dt_prazo  = "{$m[2]}-12-31";
                }
            }
            break;

        case 'trimestral':
            if (preg_match('/Q([1-4])\/(\d{4})/', $dados['ciclo_trimestral'] ?? '', $m)) {
                $map = [
                    1 => ['01-01','03-31'],
                    2 => ['04-01','06-30'],
                    3 => ['07-01','09-30'],
                    4 => ['10-01','12-31'],
                ];
                $dt_inicio = "{$m[2]}-{$map[$m[1]][0]}";
                $dt_prazo  = "{$m[2]}-{$map[$m[1]][1]}";
            }
            break;

        case 'bimestral':
            if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $dados['ciclo_bimestral'] ?? '', $m)) {
                $dt_inicio = sprintf('%04d-%02d-01', $m[3], $m[1]);
                $dt_prazo  = date('Y-m-t', strtotime("{$m[3]}-{$m[2]}-01"));
            }
            break;

        case 'mensal':
            $mes = $dados['ciclo_mensal_mes'] ?? '';
            $ano = $dados['ciclo_mensal_ano'] ?? '';
            if ($mes && $ano) {
                $dt_inicio = sprintf('%04d-%02d-01', $ano, $mes);
                $dt_prazo  = date('Y-m-t', strtotime("$ano-$mes-01"));
            }
            break;

        case 'personalizado':
            $ini = $dados['ciclo_pers_inicio'] ?? '';
            $fim = $dados['ciclo_pers_fim']     ?? '';
            if ($ini && $fim) {
                $dt_inicio = $ini . '-01';
                $dt_prazo  = date('Y-m-t', strtotime("$fim-01"));
            }
            break;
    }

    return [$dt_inicio, $dt_prazo];
}

list($dt_inicio, $dt_prazo) = calcularDatasCiclo($tipo_ciclo, $_POST);

// Validação básica dos campos obrigatórios
if (
    $descricao === '' ||
    $tipo_id === '' ||
    $pilar_id === '' ||
    $responsavel === '' ||
    $tipo_ciclo === '' ||
    $ciclo_detalhe === '' ||
    $dt_inicio === '' ||
    $dt_prazo === ''
) {
    http_response_code(422);
    echo json_encode(['error' => 'Campos obrigatórios não preenchidos']);
    exit;
}

// Conecta ao banco
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    $logPath = dirname(__DIR__) . '/error_log';
    error_log(date('[Y-m-d H:i:s] ') . $e->getMessage() . "\n", 3, $logPath);
    http_response_code(500);
    echo json_encode(['error' => 'Erro de conexão: ' . $e->getMessage()]);
    exit;
}

// Busca descrições de tipo e pilar para avaliação IA
$stmt = $pdo->prepare("SELECT descricao_exibicao FROM dom_tipo_objetivo WHERE id_tipo = ?");
$stmt->execute([$tipo_id]);
$tipo_label = $stmt->fetchColumn() ?: $tipo_id;

$stmt = $pdo->prepare("SELECT descricao_exibicao FROM dom_pilar_bsc WHERE id_pilar = ?");
$stmt->execute([$pilar_id]);
$pilar_label = $stmt->fetchColumn() ?: $pilar_id;

// Busca nome do responsável
$stmt = $pdo->prepare("SELECT primeiro_nome, ultimo_nome FROM usuarios WHERE id_user = ?");
$stmt->execute([$responsavel]);
$resp_row = $stmt->fetch();
$responsavel_nome = $resp_row
    ? $resp_row['primeiro_nome'] . ' ' . $resp_row['ultimo_nome']
    : $responsavel;

// Função de avaliação com logging
function evaluateObjective($apiKey, $descricao, $tipo, $pilar, $responsavel, $dt_inicio, $dt_prazo): array {
    global $logger;

    $system = "Você é um avaliador de objetivos estratégicos especialista em OKR.
1 - claro e inspirador
2 - pilar BSC adequado
3 - prazo do ciclo adequado
4 - tipo de objetivo condizente
5 - sem prazo na descrição (definido pelo ciclo)
6 - sem métricas (apenas KRs definem)
7 - inspirador e impactante.
Retorne SOMENTE JSON: {\"score\":0-10, \"justification\":\"texto curto\"}.";
    $user    = "Objetivo: {$descricao}\nTipo: {$tipo}\nPilar BSC: {$pilar}\nResponsável: {$responsavel}\nPeríodo: {$dt_inicio} até {$dt_prazo}";

    $payload = json_encode([
        'model'       => 'gpt-4',
        'messages'    => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $user],
        ],
        'max_tokens'  => 150,
        'temperature' => 0.4,
    ]);

    $logger->debug('OPENAI ▶ request', [
        'endpoint' => 'chat/completions',
        'payload'  => json_decode($payload, true),
    ]);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            "Authorization: Bearer {$apiKey}",
        ],
        CURLOPT_POSTFIELDS     => $payload,
    ]);
    $result   = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    $logger->debug('OPENAI ◀ response', [
        'http_code' => $httpCode,
        'curl_err'  => $curlErr ?: null,
        'body'      => json_decode($result, true),
    ]);

    $resp    = json_decode($result, true);
    $content = $resp['choices'][0]['message']['content'] ?? '';
    $json    = json_decode($content, true);
    if (json_last_error() === JSON_ERROR_NONE && isset($json['score'], $json['justification'])) {
        return [
            'score'         => intval($json['score']),
            'justification' => trim($json['justification']),
        ];
    }

    $logger->warning('OPENAI parse error', [
        'error'   => json_last_error_msg(),
        'content' => $content,
    ]);

    return ['score' => 0, 'justification' => 'Resposta inválida da IA.'];
}

// Se for só avaliação
if ($evaluate === '1') {
    $eval = evaluateObjective(
        $apiKey, $descricao, $tipo_label, $pilar_label,
        $responsavel_nome, $dt_inicio, $dt_prazo
    );
    echo json_encode([
        'score'         => $eval['score'],
        'justification' => $eval['justification'],
    ]);
    exit;
}

// Avaliação IA antes de salvar
$eval  = evaluateObjective(
    $apiKey, $descricao, $tipo_label, $pilar_label,
    $responsavel_nome, $dt_inicio, $dt_prazo
);
$score = $eval['score'];

function mapScoreToQualidadeId(int $score): string {
    if ($score >= 9) return 'ótimo';
    if ($score >= 7) return 'bom';
    if ($score >= 5) return 'moderado';
    if ($score >= 3) return 'ruim';
    return 'péssimo';
}
$id_qualidade = mapScoreToQualidadeId($score);

// Inserção no banco
try {
    $stmt = $pdo->prepare("
        INSERT INTO objetivos
            (
                descricao, tipo, pilar_bsc, dono, usuario_criador,
                status, dt_criacao, dt_prazo, dt_inicio,
                status_aprovacao, qualidade, observacoes,
                tipo_ciclo, ciclo, justificativa_ia
            )
        VALUES
            (
                :descricao, :tipo, :pilar, :dono, :usuario_criador,
                :status,   :dt_criacao, :dt_prazo, :dt_inicio,
                :status_aprovacao, :qualidade, :observacoes,
                :tipo_ciclo,       :ciclo,      :justificativa_ia
            )
    ");
    $stmt->execute([
        ':descricao'         => $descricao,
        ':tipo'              => $tipo_id,
        ':pilar'             => $pilar_id,
        ':dono'              => $responsavel,
        ':usuario_criador'   => $usuario_criador,
        ':status'            => 'nao iniciado',
        ':dt_criacao'        => date('Y-m-d H:i:s'),
        ':dt_prazo'          => $dt_prazo,
        ':dt_inicio'         => $dt_inicio,
        ':status_aprovacao'  => 'pendente',
        ':qualidade'         => $id_qualidade,
        ':observacoes'       => $observacoes,
        ':tipo_ciclo'        => $tipo_ciclo,
        ':ciclo'             => $ciclo_detalhe,
        ':justificativa_ia'  => $justificativa_ia,
    ]);
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    $logPath = dirname(__DIR__) . '/error_log';
    $errText = date('[Y-m-d H:i:s] ') . $e->getMessage() . "\n";
    $errText .= "Dados enviados: " . json_encode([
        'descricao'       => $descricao,
        'tipo'            => $tipo_id,
        'pilar'           => $pilar_id,
        'dono'            => $responsavel,
        'usuario_criador' => $usuario_criador,
        'status'          => 'nao iniciado',
        'dt_criacao'      => date('Y-m-d H:i:s'),
        'dt_prazo'        => $dt_prazo,
        'dt_inicio'       => $dt_inicio,
        'status_aprovacao'=> 'pendente',
        'qualidade'       => $id_qualidade,
        'observacoes'     => $observacoes,
        'tipo_ciclo'      => $tipo_ciclo,
        'ciclo'           => $ciclo_detalhe,
        'justificativa_ia'=> $justificativa_ia,
    ]) . "\n";
    error_log($errText, 3, $logPath);

    http_response_code(500);
    echo json_encode(['error' => 'Falha ao salvar: ' . $e->getMessage()]);
}
