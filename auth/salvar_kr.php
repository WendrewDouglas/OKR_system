<?php
// auth/salvar_kr.php

// ===== DEV ONLY: exibe erros na tela (remova em produção)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 1) Sessão
session_start();

// 2) Autoload e logger
$logger = require dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

// 2.1) Carrega variáveis do .env (um nível acima de /auth) e remove aspas
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $parts = explode('=', $line, 2);
        $name  = trim($parts[0] ?? '');
        $value = trim($parts[1] ?? '');
        $value = trim($value, "\"'"); // remove aspas simples/duplas
        if ($name !== '') {
            putenv("$name=$value");
            $_ENV[$name]    = $value;
            $_SERVER[$name] = $value;
        }
    }
}

// 3) Header JSON
header('Content-Type: application/json; charset=utf-8');

// 4) Autenticação
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autorizado']);
    exit;
}

// 5) Apenas POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
    exit;
}

// 6) CSRF
if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Token CSRF inválido']);
    exit;
}

// ======== Helpers ========

function calcularDatasCiclo(string $tipo, array $d): array {
    $ini = $fim = '';
    switch ($tipo) {
        case 'anual':
            if (!empty($d['ciclo_anual_ano'])) {
                $ano = (int)$d['ciclo_anual_ano'];
                $ini = sprintf('%04d-01-01', $ano);
                $fim = sprintf('%04d-12-31', $ano);
            }
            break;
        case 'semestral':
            if (preg_match('/^S([12])\/(\d{4})$/', $d['ciclo_semestral'] ?? '', $m)) {
                $ano = $m[2];
                if ($m[1] === '1') { $ini = "$ano-01-01"; $fim = "$ano-06-30"; }
                else               { $ini = "$ano-07-01"; $fim = "$ano-12-31"; }
            }
            break;
        case 'trimestral':
            if (preg_match('/^Q([1-4])\/(\d{4})$/', $d['ciclo_trimestral'] ?? '', $m)) {
                $map = [
                    '1'=>['01-01','03-31'],
                    '2'=>['04-01','06-30'],
                    '3'=>['07-01','09-30'],
                    '4'=>['10-01','12-31'],
                ];
                $ini = "{$m[2]}-{$map[$m[1]][0]}";
                $fim = "{$m[2]}-{$map[$m[1]][1]}";
            }
            break;
        case 'bimestral':
            // valor no front: "MM-MM-YYYY" (ex.: 01-02-2026)
            if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $d['ciclo_bimestral'] ?? '', $m)) {
                $ini = "{$m[3]}-{$m[1]}-01";
                $fim = date('Y-m-t', strtotime("{$m[3]}-{$m[2]}-01"));
            }
            break;
        case 'mensal':
            if (!empty($d['ciclo_mensal_mes']) && !empty($d['ciclo_mensal_ano'])) {
                $mes = (int)$d['ciclo_mensal_mes'];
                $ano = (int)$d['ciclo_mensal_ano'];
                $ini = sprintf('%04d-%02d-01', $ano, $mes);
                $fim = date('Y-m-t', strtotime("$ano-$mes-01"));
            }
            break;
        case 'personalizado':
            // inputs no front: YYYY-MM (month)
            if (!empty($d['ciclo_pers_inicio']) && !empty($d['ciclo_pers_fim'])) {
                $ini = $d['ciclo_pers_inicio'].'-01';
                $fim = date('Y-m-t', strtotime($d['ciclo_pers_fim'].'-01'));
            }
            break;
    }
    return [$ini, $fim];
}

/** Validação por modo: 'evaluate' | 'save' */
function validarObrigatorios(string $modo, array $post): array {
    $errors = [];

    // Sempre necessários
    if (empty($post['id_objetivo']))              $errors[] = ['field' => 'id_objetivo', 'message' => 'Objetivo associado é obrigatório'];
    if (empty(trim($post['descricao'] ?? '')))    $errors[] = ['field' => 'descricao', 'message' => 'Descrição do KR é obrigatória'];

    // Ciclo + detalhe
    $tipo_ciclo = trim($post['ciclo_tipo'] ?? '');
    if ($tipo_ciclo === '') {
        $errors[] = ['field' => 'ciclo_tipo', 'message' => 'Tipo de ciclo é obrigatório'];
    } else {
        switch ($tipo_ciclo) {
            case 'anual':
                if (empty($post['ciclo_anual_ano'])) $errors[] = ['field' => 'ciclo_anual_ano', 'message' => 'Ano do ciclo anual é obrigatório'];
                break;
            case 'semestral':
                if (empty($post['ciclo_semestral'])) $errors[] = ['field' => 'ciclo_semestral', 'message' => 'Semestre do ciclo é obrigatório'];
                break;
            case 'trimestral':
                if (empty($post['ciclo_trimestral'])) $errors[] = ['field' => 'ciclo_trimestral', 'message' => 'Trimestre do ciclo é obrigatório'];
                break;
            case 'bimestral':
                if (empty($post['ciclo_bimestral'])) $errors[] = ['field' => 'ciclo_bimestral', 'message' => 'Bimestre do ciclo é obrigatório'];
                break;
            case 'mensal':
                if (empty($post['ciclo_mensal_mes'])) $errors[] = ['field' => 'ciclo_mensal_mes', 'message' => 'Mês do ciclo é obrigatório'];
                if (empty($post['ciclo_mensal_ano'])) $errors[] = ['field' => 'ciclo_mensal_ano', 'message' => 'Ano do ciclo é obrigatório'];
                break;
            case 'personalizado':
                if (empty($post['ciclo_pers_inicio'])) $errors[] = ['field' => 'ciclo_pers_inicio', 'message' => 'Início do ciclo é obrigatório'];
                if (empty($post['ciclo_pers_fim']))    $errors[] = ['field' => 'ciclo_pers_fim', 'message' => 'Fim do ciclo é obrigatório'];
                break;
        }
    }

    // baseline / meta
    if (!isset($post['baseline']) || $post['baseline'] === '' || !is_numeric($post['baseline']))
        $errors[] = ['field' => 'baseline', 'message' => 'Baseline é obrigatória'];
    if (!isset($post['meta']) || $post['meta'] === '' || !is_numeric($post['meta']))
        $errors[] = ['field' => 'meta', 'message' => 'Meta é obrigatória'];

    // Para SALVAR, exigir frequência
    if ($modo === 'save') {
        if (empty($post['tipo_frequencia_milestone']))
            $errors[] = ['field' => 'tipo_frequencia_milestone', 'message' => 'Frequência de apontamento é obrigatória para salvar'];
    }

    // Datas derivadas
    list($ini, $fim) = calcularDatasCiclo($tipo_ciclo, $post);
    if ($tipo_ciclo !== '' && ($ini === '' || $fim === '')) {
        $errors[] = ['field' => 'ciclo_tipo', 'message' => 'Não foi possível derivar o período do ciclo selecionado'];
    }

    return $errors;
}

// MAPA nota->qualidade (ids da dom_qualidade_objetivo)
function mapScoreToQualidade(?int $score): ?string {
    if ($score === null) return null;
    if     ($score <= 2) return 'péssimo';
    elseif ($score <= 4) return 'ruim';
    elseif ($score <= 6) return 'moderado';
    elseif ($score <= 8) return 'bom';
    else                 return 'ótimo'; // 9-10
}

function avaliarKR_viaOpenAI(string $apiKey, array $dados, $logger): array {
    $system = "Você é um especialista em OKR. Responda ESTRITAMENTE em JSON válido, no formato: {\"score\": <inteiro de 0 a 10>, \"justification\": \"texto (até 150 caracteres)\"}. Sem texto extra.";
    $user   = json_encode($dados, JSON_UNESCAPED_UNICODE);

    $payload = [
        'model' => 'gpt-4o-mini',
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $user]
        ],
        'temperature' => 0.2,
        'max_tokens'  => 200,
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST            => true,
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_HTTPHEADER      => [
            'Content-Type: application/json',
            "Authorization: Bearer $apiKey"
        ],
        CURLOPT_POSTFIELDS      => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT         => 30,
    ]);

    $res  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        $logger->error("OpenAI cURL error", ['error' => $err]);
        return ['score' => 0, 'justification' => 'Falha ao conectar na IA.'];
    }

    $json = json_decode($res, true);
    if (!is_array($json) || empty($json['choices'][0]['message']['content'])) {
        $logger->error("OpenAI resposta inválida", ['http_code' => $code, 'body' => $res]);
        return ['score' => 0, 'justification' => 'Resposta inválida da IA.'];
    }

    $content = $json['choices'][0]['message']['content'];
    $parsed  = json_decode($content, true);

    if (json_last_error() !== JSON_ERROR_NONE || !isset($parsed['score'], $parsed['justification'])) {
        $logger->warning("OpenAI conteúdo não-JSON estrito", ['content' => $content]);
        return ['score' => 0, 'justification' => 'Formato inesperado da IA.'];
    }

    $score = (int)$parsed['score'];
    $just  = trim((string)$parsed['justification']);
    if ($score < 0) $score = 0;
    if ($score > 10) $score = 10;
    if (mb_strlen($just) > 150) $just = mb_substr($just, 0, 150);

    return ['score' => $score, 'justification' => $just];
}

// ======== Leitura comum (campos) ========

$id_objetivo      = filter_input(INPUT_POST, 'id_objetivo', FILTER_VALIDATE_INT);
$descricao        = trim($_POST['descricao'] ?? '');
$tipo_kr          = ($_POST['tipo_kr'] ?? '') !== '' ? (string)$_POST['tipo_kr'] : null;         // DB: varchar(20)
$natureza_kr      = ($_POST['natureza_kr'] ?? '') !== '' ? (string)$_POST['natureza_kr'] : null; // DB: varchar(20)
$status           = ($_POST['status'] ?? '') !== '' ? (string)$_POST['status'] : null;           // DB: varchar(20)
$tipo_frequencia  = ($_POST['tipo_frequencia_milestone'] ?? '') !== '' ? (string)$_POST['tipo_frequencia_milestone'] : null; // DB: varchar(20)

$baseline         = filter_input(INPUT_POST, 'baseline', FILTER_VALIDATE_FLOAT);
$meta             = filter_input(INPUT_POST, 'meta', FILTER_VALIDATE_FLOAT);
$unidade_medida   = trim($_POST['unidade_medida'] ?? '');
$direcao_metrica  = trim($_POST['direcao_metrica'] ?? '');
$margem_confianca = ($_POST['margem_confianca'] ?? '') !== '' ? (float)$_POST['margem_confianca'] : null;

$tipo_ciclo       = trim($_POST['ciclo_tipo'] ?? '');
$responsavel      = ($_POST['responsavel'] ?? '') !== '' ? (string)$_POST['responsavel'] : null; // DB: varchar(100)
$observacoes      = trim($_POST['observacoes'] ?? '');
$usuario_criador  = (string)($_SESSION['user_id']); // DB: varchar(100)

// Detalhe de ciclo (opcional)
$ciclo_detalhe = '';
switch ($tipo_ciclo) {
    case 'anual':        $ciclo_detalhe = $_POST['ciclo_anual_ano'] ?? ''; break;
    case 'semestral':    $ciclo_detalhe = $_POST['ciclo_semestral'] ?? ''; break;
    case 'trimestral':   $ciclo_detalhe = $_POST['ciclo_trimestral'] ?? ''; break;
    case 'bimestral':    $ciclo_detalhe = $_POST['ciclo_bimestral'] ?? ''; break;
    case 'mensal':
        if (!empty($_POST['ciclo_mensal_mes']) && !empty($_POST['ciclo_mensal_ano'])) {
            $ciclo_detalhe = $_POST['ciclo_mensal_mes'] . '/' . $_POST['ciclo_mensal_ano'];
        }
        break;
    case 'personalizado':
        if (!empty($_POST['ciclo_pers_inicio']) && !empty($_POST['ciclo_pers_fim'])) {
            $ciclo_detalhe = $_POST['ciclo_pers_inicio'] . ' a ' . $_POST['ciclo_pers_fim'];
        }
        break;
}

// Datas do ciclo
list($data_inicio, $data_fim) = calcularDatasCiclo($tipo_ciclo, $_POST);

// ======== Fluxo A: Apenas avaliação (evaluate=1) ========
if (isset($_POST['evaluate']) && $_POST['evaluate'] === '1') {
    $errors = validarObrigatorios('evaluate', $_POST);
    if (!empty($errors)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'errors' => $errors]);
        exit;
    }

    // Lê API key
    $apiKey = getenv('OPENAI_API_KEY')
           ?: ($_ENV['OPENAI_API_KEY'] ?? '')
           ?: ($_SERVER['OPENAI_API_KEY'] ?? '');
    $apiKey = trim($apiKey, "\"'");

    if (!$apiKey) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'API key não configurada']);
        exit;
    }

    $dadosParaIA = [
        'descricao'        => $descricao,
        'baseline'         => $baseline,
        'meta'             => $meta,
        'unidade_medida'   => $unidade_medida,
        'direcao_metrica'  => $direcao_metrica,
        'margem_confianca' => $margem_confianca,
        'data_inicio'      => $data_inicio,
        'data_fim'         => $data_fim,
        'tipo_kr'          => $tipo_kr,
        'natureza_kr'      => $natureza_kr,
        'status'           => $status,
        'frequencia'       => $tipo_frequencia,
        'responsavel'      => $responsavel
    ];

    $avaliacao = avaliarKR_viaOpenAI($apiKey, $dadosParaIA, $logger);

    echo json_encode([
        'score'         => $avaliacao['score'],
        'justification' => $avaliacao['justification']
    ]);
    exit;
}

// ======== Fluxo B: Salvar no banco ========
$errors = validarObrigatorios('save', $_POST);
if (!empty($errors)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'errors' => $errors]);
    exit;
}

// Conexão PDO
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    $logger->error("Conexão falhou: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno de conexão']);
    exit;
}

// Metadados
date_default_timezone_set('America/Sao_Paulo');
$dt_criacao            = date('Y-m-d');
$dt_ultima_atualizacao = date('Y-m-d H:i:s');
$usuario_ult_alteracao = (string)$_SESSION['user_id'];

// ===== Gerar id_kr e inserir com segurança (transação) =====
try {
    $pdo->beginTransaction();

    // Pega o último sequencial do KR para o objetivo com lock (evita corrida)
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(key_result_num), 0) AS maxnum FROM key_results WHERE id_objetivo = ? FOR UPDATE");
    $stmt->execute([$id_objetivo]);
    $maxnum = (int)$stmt->fetchColumn();
    $key_result_num = $maxnum + 1;

    // id_kr formato NNN-OO (OO com 2 dígitos se < 100; caso contrário, sem truncar)
    $objFmt = ((int)$id_objetivo < 100)
        ? str_pad((string)$id_objetivo, 2, '0', STR_PAD_LEFT)
        : (string)$id_objetivo;
    $id_kr = sprintf('%03d-%s', $key_result_num, $objFmt);

    // Pega score/justificativa vindos do front (se avaliou) e deriva qualidade
    $score_ia         = isset($_POST['score_ia']) && $_POST['score_ia'] !== '' ? (int)$_POST['score_ia'] : null;
    $justificativa_ia = isset($_POST['justificativa_ia']) ? trim($_POST['justificativa_ia']) : null;
    $qualidade        = mapScoreToQualidade($score_ia); // pode ser null

    // INSERT (inclui qualidade)
    $sql = "INSERT INTO key_results (
                id_kr, id_objetivo, key_result_num, descricao, usuario_criador, dt_criacao,
                tipo_kr, natureza_kr, status, status_aprovacao, tipo_frequencia_milestone,
                baseline, meta, unidade_medida, direcao_metrica, margem_confianca,
                data_inicio, data_fim, responsavel, observacoes,
                dt_ultima_atualizacao, usuario_ult_alteracao,
                qualidade
                -- , score_ia, justificativa_ia
            ) VALUES (
                :id_kr, :id_objetivo, :key_result_num, :descricao, :usuario_criador, :dt_criacao,
                :tipo_kr, :natureza_kr, :status, 'pendente', :tipo_frequencia_milestone,
                :baseline, :meta, :unidade_medida, :direcao_metrica, :margem_confianca,
                :data_inicio, :data_fim, :responsavel, :observacoes,
                :dt_ultima_atualizacao, :usuario_ult_alteracao,
                :qualidade
                -- , :score_ia, :justificativa_ia
            )";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id_kr'                     => $id_kr,
        ':id_objetivo'               => $id_objetivo,
        ':key_result_num'            => $key_result_num,
        ':descricao'                 => $descricao,
        ':usuario_criador'           => $usuario_criador,
        ':dt_criacao'                => $dt_criacao,
        ':tipo_kr'                   => $tipo_kr,
        ':natureza_kr'               => $natureza_kr,
        ':status'                    => $status, // DB tem default 'Não Iniciado' se vier null
        ':tipo_frequencia_milestone' => $tipo_frequencia,
        ':baseline'                  => $baseline,
        ':meta'                      => $meta,
        ':unidade_medida'            => $unidade_medida ?: null,
        ':direcao_metrica'           => $direcao_metrica ?: null,
        ':margem_confianca'          => $margem_confianca,
        ':data_inicio'               => $data_inicio,
        ':data_fim'                  => $data_fim,
        ':responsavel'               => $responsavel,
        ':observacoes'               => $observacoes ?: null,
        ':dt_ultima_atualizacao'     => $dt_ultima_atualizacao,
        ':usuario_ult_alteracao'     => $usuario_ult_alteracao,
        ':qualidade'                 => $qualidade,
        // ':score_ia'               => $score_ia,
        // ':justificativa_ia'       => $justificativa_ia,
    ]);

    $pdo->commit();

    echo json_encode(['success' => true, 'id_kr' => $id_kr, 'key_result_num' => $key_result_num]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $logger->error("Erro ao salvar KR", ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Falha ao salvar KR']);
}
