<?php
// auth/salvar_kr.php

// ===== DEV ONLY: exibe erros na tela (remova em produção)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 1) Sessão
session_start();

// ===== 0) Logging de arquivo por página =====
$__logDir = dirname(__DIR__) . '/logs';
if (!is_dir($__logDir)) { @mkdir($__logDir, 0775, true); }
$__logFile = $__logDir . '/' . basename(__FILE__, '.php') . '.log'; // ex.: salvar_kr.log
function app_log(string $level, string $message, array $ctx = []): void {
    global $__logFile;
    $ts = microtime(true);
    $dt = date('Y-m-d H:i:s', (int)$ts) . sprintf('.%03d', (int)(($ts - floor($ts))*1000));
    unset($ctx['csrf_token'], $ctx['apiKey'], $ctx['senha'], $ctx['password']);
    $line = sprintf("[%s] [%s] %s %s\n", $dt, strtoupper($level), $message, $ctx ? json_encode($ctx, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : '');
    @file_put_contents($__logFile, $line, FILE_APPEND);
}
set_error_handler(function($errno,$errstr,$errfile,$errline){
    app_log('error', "PHP Error {$errno}: {$errstr} @ {$errfile}:{$errline}");
    return false;
});
set_exception_handler(function($ex){
    app_log('error', 'Uncaught Exception', ['type'=>get_class($ex), 'msg'=>$ex->getMessage(), 'file'=>$ex->getFile(), 'line'=>$ex->getLine()]);
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success'=>false,'error'=>'Erro interno']);
    exit;
});

// 2) Autoload e logger
$logger = require dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

$MILESTONE_TABLE = 'milestones_kr';

// 2.1) .env
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        [$name,$value] = array_pad(explode('=', $line, 2), 2, '');
        $name  = trim($name);
        $value = trim(trim($value), "\"'");
        if ($name !== '') {
            putenv("$name=$value");
            $_ENV[$name]    = $value;
            $_SERVER[$name] = $value;
        }
    }
}

// 3) Header JSON
header('Content-Type: application/json; charset=utf-8');

// Log inicial
app_log('info', 'POST recebido em salvar_kr', [
    'user' => $_SESSION['user_id'] ?? null,
    'ip'   => $_SERVER['REMOTE_ADDR'] ?? null,
    'mode' => isset($_POST['evaluate']) && $_POST['evaluate']==='1' ? 'evaluate' : 'save'
]);

// 4) Autenticação
if (!isset($_SESSION['user_id'])) {
    app_log('warning', 'Requisição bloqueada', ['reason'=>'unauthorized']);
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autorizado']);
    exit;
}

// 5) Apenas POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_log('warning', 'Requisição bloqueada', ['reason'=>'method_not_allowed', 'method'=>$_SERVER['REQUEST_METHOD'] ?? null]);
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
    exit;
}

// 6) CSRF
if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    app_log('warning', 'Requisição bloqueada', ['reason'=>'csrf']);
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
            if (!empty($d['ciclo_pers_inicio']) && !empty($d['ciclo_pers_fim'])) {
                $ini = $d['ciclo_pers_inicio'].'-01';
                $fim = date('Y-m-t', strtotime($d['ciclo_pers_fim'].'-01'));
            }
            break;
    }
    return [$ini, $fim];
}

function validarObrigatorios(string $modo, array $post): array {
    $errors = [];
    if (empty($post['id_objetivo']))              $errors[] = ['field' => 'id_objetivo', 'message' => 'Objetivo associado é obrigatório'];
    if (empty(trim($post['descricao'] ?? '')))    $errors[] = ['field' => 'descricao', 'message' => 'Descrição do KR é obrigatória'];

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

    if (!isset($post['baseline']) || $post['baseline'] === '' || !is_numeric($post['baseline']))
        $errors[] = ['field' => 'baseline', 'message' => 'Baseline é obrigatória'];
    if (!isset($post['meta']) || $post['meta'] === '' || !is_numeric($post['meta']))
        $errors[] = ['field' => 'meta', 'message' => 'Meta é obrigatória'];

    if ($modo === 'save') {
        $temFreq = array_key_exists('tipo_frequencia_milestone', $post)
               && trim((string)$post['tipo_frequencia_milestone']) !== '';
        if (!$temFreq) {
            $errors[] = ['field'=>'tipo_frequencia_milestone','message'=>'Frequência de apontamento é obrigatória para salvar'];
        }
    }

    list($ini, $fim) = calcularDatasCiclo($tipo_ciclo, $post);
    if ($tipo_ciclo !== '' && ($ini === '' || $fim === '')) {
        $errors[] = ['field' => 'ciclo_tipo', 'message' => 'Não foi possível derivar o período do ciclo selecionado'];
    }
    return $errors;
}

function mapScoreToQualidade(?int $score): ?string {
    if ($score === null) return null;
    if ($score <= 2) return 'péssimo';
    if ($score <= 4) return 'ruim';
    if ($score <= 6) return 'moderado';
    if ($score <= 8) return 'bom';
    return 'ótimo';
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
        app_log('error', 'OpenAI cURL error', ['error'=>$err]);
        return ['score' => 0, 'justification' => 'Falha ao conectar na IA.'];
    }
    $json = json_decode($res, true);
    if (!is_array($json) || empty($json['choices'][0]['message']['content'])) {
        $logger->error("OpenAI resposta inválida", ['http_code' => $code, 'body' => $res]);
        app_log('error', 'OpenAI resposta inválida', ['http_code'=>$code]);
        return ['score' => 0, 'justification' => 'Resposta inválida da IA.'];
    }
    $content = $json['choices'][0]['message']['content'];
    $parsed  = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE || !isset($parsed['score'], $parsed['justification'])) {
        $logger->warning("OpenAI conteúdo não-JSON estrito", ['content' => $content]);
        app_log('warning', 'OpenAI conteúdo não-JSON estrito', []);
        return ['score' => 0, 'justification' => 'Formato inesperado da IA.'];
    }
    $score = (int)$parsed['score'];
    $just  = trim((string)$parsed['justification']);
    if ($score < 0) $score = 0;
    if ($score > 10) $score = 10;
    if (mb_strlen($just) > 150) $just = mb_substr($just, 0, 150);
    return ['score' => $score, 'justification' => $just];
}

function normalizarFrequencia(PDO $pdo, ?string $raw): string {
    $raw = strtolower(trim((string)$raw));
    $map = ['semanal','quinzenal','mensal','bimestral','trimestral','semestral','anual'];
    if (in_array($raw, $map, true)) return $raw;
    try {
        $rows = $pdo->query("SELECT id_frequencia, descricao_exibicao FROM dom_tipo_frequencia_milestone")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $id  = strtolower(trim((string)$r['id_frequencia']));
            $lbl = strtolower(trim((string)$r['descricao_exibicao']));
            if ($raw !== '' && $raw === $id) return $id;
            if (in_array($id, $map, true)) return $id;
            if (strpos($lbl, 'seman') !== false) return 'semanal';
            if (strpos($lbl, 'quinzen') !== false) return 'quinzenal';
            if (strpos($lbl, 'mens') !== false) return 'mensal';
            if (strpos($lbl, 'bimes') !== false) return 'bimestral';
            if (strpos($lbl, 'trimes') !== false) return 'trimestral';
            if (strpos($lbl, 'semes') !== false) return 'semestral';
            if (strpos($lbl, 'anual') !== false) return 'anual';
        }
    } catch (Throwable $e) {}
    return 'mensal';
}

// Garante que a frequência (incl. 'quinzenal') exista no domínio (evita erro de FK)
function ensureFrequenciaDominio(PDO $pdo, string $slug): void {
    $slug = strtolower(trim($slug));
    if ($slug === '') return;

    $st = $pdo->prepare("SELECT 1 FROM dom_tipo_frequencia_milestone WHERE id_frequencia = ? LIMIT 1");
    $st->execute([$slug]);
    if ($st->fetchColumn()) return;

    $labels = [
        'semanal'   => 'Semanal',
        'quinzenal' => 'Quinzenal (15 dias)',
        'mensal'    => 'Mensal',
        'bimestral' => 'Bimestral',
        'trimestral'=> 'Trimestral',
        'semestral' => 'Semestral',
        'anual'     => 'Anual',
    ];
    $desc = $labels[$slug] ?? ucfirst($slug);

    $ins = $pdo->prepare("
        INSERT INTO dom_tipo_frequencia_milestone (id_frequencia, descricao_exibicao)
        VALUES (?, ?)
    ");
    $ins->execute([$slug, $desc]);
    app_log('info', 'Frequência criada no domínio (auto)', ['slug'=>$slug,'descricao'=>$desc]);
}

// Natureza com suporte a Binário (binaria)
function inferirNatureza(PDO $pdo, $naturezaRaw): string {
    $val = strtolower(trim((string)$naturezaRaw));
    if ($val === 'acumulativa' || $val === 'acumulativo') return 'acumulativa';
    if ($val === 'pontual') return 'pontual';
    if ($val === 'binario' || $val === 'binária' || $val === 'binaria' || $val === 'binário') return 'binaria';

    if (ctype_digit($val)) {
        try {
            $st = $pdo->prepare("SELECT descricao_exibicao FROM dom_natureza_kr WHERE id_natureza = ? LIMIT 1");
            $st->execute([$val]);
            $desc = strtolower(trim((string)$st->fetchColumn()));
            if (strpos($desc, 'bin') !== false)       return 'binaria';
            if (strpos($desc, 'pontual') !== false)   return 'pontual';
            if (strpos($desc, 'acumula') !== false)   return 'acumulativa';
        } catch (Throwable $e) {}
    }
    return 'acumulativa';
}

function unidadeRequerInteiro(?string $u): bool {
    $u = strtolower(trim((string)$u));
    $ints = ['unid','itens','pcs','ord','proc','contratos','processos','pessoas','casos','tickets','visitas'];
    return in_array($u, $ints, true);
}

// Série de datas para milestones (espelha front)
// - semanal: +7d; quinzenal: +15d
// - demais: último dia do mês do período e sempre inclui data_fim
function gerarSerieDatas(string $data_inicio, string $data_fim, string $freq): array {
    $out = [];
    $start = new DateTime($data_inicio);
    $end   = new DateTime($data_fim);
    if ($end < $start) $end = clone $start;

    $freq = strtolower($freq);
    $pushUnique = function(array &$arr, DateTime $d) {
        $iso = $d->format('Y-m-d');
        if (empty($arr) || end($arr) !== $iso) $arr[] = $iso;
    };

    if ($freq === 'semanal' || $freq === 'quinzenal') {
        $stepDays = ($freq === 'semanal') ? 7 : 15;
        $d = (clone $start)->modify("+{$stepDays} days");
        while ($d < $end) { $pushUnique($out, $d); $d->modify("+{$stepDays} days"); }
        $pushUnique($out, $end);
    } else {
        $stepMonths = ['mensal'=>1, 'bimestral'=>2, 'trimestral'=>3, 'semestral'=>6, 'anual'=>12][$freq] ?? 1;
        $d = clone $start;
        $firstEnd = (clone $d)->modify('last day of this month');
        if ($stepMonths > 1) {
            $tmp = (clone $d)->modify('first day of this month')->modify('+'.($stepMonths-1).' months');
            $firstEnd = $tmp->modify('last day of this month');
        }
        if ($firstEnd > $end) {
            $pushUnique($out, $end);
        } else {
            $pushUnique($out, $firstEnd);
            $d = (clone $firstEnd)->modify('+'.$stepMonths.' months')->modify('last day of this month');
            while ($d < $end) { $pushUnique($out, $d); $d = $d->modify('+'.$stepMonths.' months')->modify('last day of this month'); }
            $pushUnique($out, $end);
        }
    }
    if (count($out) === 0) $out[] = $end->format('Y-m-d');
    return $out;
}

function gerarMilestonesParaKR(
    PDO $pdo,
    string $table,
    string $id_kr,
    string $data_inicio,
    string $data_fim,
    string $freqSlug,
    float $baseline,
    float $meta,
    string $naturezaSlug,
    ?string $direcao,
    ?string $unidade_medida
): int {
    $datas = gerarSerieDatas($data_inicio, $data_fim, $freqSlug);
    $N = count($datas);

    $del = $pdo->prepare("DELETE FROM {$table} WHERE id_kr = :id_kr");
    $del->execute([':id_kr' => $id_kr]);

    $ins = $pdo->prepare("
        INSERT INTO {$table} (id_kr, num_ordem, data_ref, valor_esperado, gerado_automatico, editado_manual, bloqueado_para_edicao)
        VALUES (:id_kr, :num_ordem, :data_ref, :valor_esperado, 1, 0, 0)
    ");

    $isIntUnit = unidadeRequerInteiro($unidade_medida);
    $roundFn = function($v) use ($isIntUnit) { return $isIntUnit ? (int)round($v, 0) : round($v, 2); };

    $acumulativo = ($naturezaSlug === 'acumulativa');
    $binario     = ($naturezaSlug === 'binaria' || $naturezaSlug === 'binario');
    $maiorMelhor = (strtoupper(trim((string)$direcao)) !== 'MENOR_MELHOR');

    for ($i = 1; $i <= $N; $i++) {
        $dataRef = $datas[$i-1];
        if ($binario) {
            // 0 até o penúltimo, 1 no último
            $esp = ($i === $N) ? 1 : 0;
        } elseif ($acumulativo) {
            // progressão linear
            $esp = $maiorMelhor
                ? $baseline + ($meta - $baseline) * ($i / $N)
                : $baseline - ($baseline - $meta) * ($i / $N);
        } else {
            // pontual: 0 até penúltimo, meta no último
            $esp = ($i === $N) ? $meta : 0;
        }
        $ins->execute([
            ':id_kr'          => $id_kr,
            ':num_ordem'      => $i,
            ':data_ref'       => $dataRef,
            ':valor_esperado' => $roundFn($esp),
        ]);
    }
    return $N;
}

/** Normaliza STATUS para um id válido em dom_status_kr */
function normalizarStatus(PDO $pdo, $raw): ?string {
    $val = trim((string)$raw);
    if ($val === '') $val = null;

    if ($val !== null) {
        $st = $pdo->prepare("SELECT 1 FROM dom_status_kr WHERE id_status = ? LIMIT 1");
        $st->execute([$val]);
        if ($st->fetchColumn()) return $val;

        $st = $pdo->prepare("SELECT id_status FROM dom_status_kr WHERE LOWER(descricao_exibicao) = LOWER(?) LIMIT 1");
        $st->execute([$val]);
        $id = $st->fetchColumn();
        if ($id) return $id;
    }

    $id = $pdo->query("SELECT id_status FROM dom_status_kr WHERE LOWER(id_status) IN ('nao_iniciado','nao-iniciado','naoiniciado') LIMIT 1")->fetchColumn();
    if ($id) return $id;

    $id = $pdo->query("SELECT id_status FROM dom_status_kr ORDER BY 1 LIMIT 1")->fetchColumn();
    return $id ?: null;
}

// ======== Leitura comum (campos) ========

$id_objetivo      = filter_input(INPUT_POST, 'id_objetivo', FILTER_VALIDATE_INT);
$descricao        = trim($_POST['descricao'] ?? '');
$tipo_kr          = ($_POST['tipo_kr'] ?? '') !== '' ? (string)$_POST['tipo_kr'] : null;
$natureza_kr_in   = ($_POST['natureza_kr'] ?? '') !== '' ? (string)$_POST['natureza_kr'] : null;
$status           = ($_POST['status'] ?? '') !== '' ? (string)$_POST['status'] : null;
$tipo_frequencia_in  = isset($_POST['tipo_frequencia_milestone']) ? (string)$_POST['tipo_frequencia_milestone'] : null;

$baseline         = filter_input(INPUT_POST, 'baseline', FILTER_VALIDATE_FLOAT);
$meta             = filter_input(INPUT_POST, 'meta', FILTER_VALIDATE_FLOAT);
$unidade_medida   = trim($_POST['unidade_medida'] ?? '');
$direcao_metrica  = trim($_POST['direcao_metrica'] ?? '');
$margem_confianca = ($_POST['margem_confianca'] ?? '') !== '' ? (float)$_POST['margem_confianca'] : null;

$tipo_ciclo       = trim($_POST['ciclo_tipo'] ?? '');
$responsavel      = ($_POST['responsavel'] ?? '') !== '' ? (string)$_POST['responsavel'] : null;
$observacoes      = trim($_POST['observacoes'] ?? '');
$usuario_criador  = (string)($_SESSION['user_id']);

// Detalhe de ciclo (apenas exibição)
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
app_log('info', 'Datas do ciclo derivadas', ['inicio'=>$data_inicio, 'fim'=>$data_fim, 'ciclo'=>$tipo_ciclo]);

// ======== Fluxo A: Apenas avaliação (evaluate=1) ========
if (isset($_POST['evaluate']) && $_POST['evaluate'] === '1') {
    app_log('info', 'Iniciando avaliação IA');
    $errors = validarObrigatorios('evaluate', $_POST);
    if (!empty($errors)) {
        app_log('warning', 'Validação falhou (evaluate)', ['errors'=>$errors]);
        http_response_code(422);
        echo json_encode(['success' => false, 'errors' => $errors]);
        exit;
    }

    $apiKey = getenv('OPENAI_API_KEY') ?: ($_ENV['OPENAI_API_KEY'] ?? '') ?: ($_SERVER['OPENAI_API_KEY'] ?? '');
    $apiKey = trim($apiKey, "\"'");
    if (!$apiKey) {
        app_log('error', 'API key não configurada');
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
        'natureza_kr'      => $natureza_kr_in,
        'status'           => $status,
        'frequencia'       => $tipo_frequencia_in,
        'responsavel'      => $responsavel
    ];
    $avaliacao = avaliarKR_viaOpenAI($apiKey, $dadosParaIA, $logger);
    app_log('info', 'Avaliação IA concluída', ['score'=>$avaliacao['score'] ?? null]);

    echo json_encode(['score'=>$avaliacao['score'], 'justification'=>$avaliacao['justification']]);
    exit;
}

// ======== Fluxo B: Salvar ========
app_log('info', 'Frequência recebida (raw)', ['tipo_frequencia_milestone' => $_POST['tipo_frequencia_milestone'] ?? null]);
$errors = validarObrigatorios('save', $_POST);
if (!empty($errors)) {
    app_log('warning', 'Validação falhou (save)', ['errors'=>$errors]);
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
    app_log('info', 'Conectado ao MySQL com sucesso');
} catch (PDOException $e) {
    $logger->error("Conexão falhou: " . $e->getMessage());
    app_log('error', 'Falha na conexão MySQL', ['msg'=>$e->getMessage()]);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno de conexão']);
    exit;
}

// Metadados
date_default_timezone_set('America/Sao_Paulo');
$dt_criacao            = date('Y-m-d');
$dt_ultima_atualizacao = date('Y-m-d H:i:s');
$usuario_ult_alteracao = (string)$_SESSION['user_id'];

// Normalizações
$freqSlug      = normalizarFrequencia($pdo, $tipo_frequencia_in);
$naturezaSlug  = inferirNatureza($pdo, $natureza_kr_in);
$statusNorm    = normalizarStatus($pdo, $status);

// garante a linha no domínio para não quebrar FK (inclui quinzenal)
ensureFrequenciaDominio($pdo, $freqSlug);

app_log('info', 'Normalizações', ['freq'=>$freqSlug, 'natureza'=>$naturezaSlug, 'status_raw'=>$status, 'status_norm'=>$statusNorm]);

// Coerção server-side para binário (robustez: baseline 0, meta 1)
if ($naturezaSlug === 'binaria') {
    $baseline = 0.0;
    $meta     = 1.0;
    app_log('info', 'Coerção binária aplicada', ['baseline'=>$baseline,'meta'=>$meta]);
}

// Autogerar milestones?
$autogerar = ($_POST['autogerar_milestones'] ?? '0') === '1';
app_log('info', 'Flag autogerar_milestones', ['autogerar'=>$autogerar ? 1 : 0]);

// ===== Transação =====
try {
    app_log('info', 'Iniciando transação para salvar KR');
    $pdo->beginTransaction();

    // Sequencial do KR por objetivo (lock)
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(key_result_num), 0) AS maxnum FROM key_results WHERE id_objetivo = ? FOR UPDATE");
    $stmt->execute([$id_objetivo]);
    $maxnum = (int)$stmt->fetchColumn();
    $key_result_num = $maxnum + 1;

    // id_kr NNN-OO
    $objFmt = ((int)$id_objetivo < 100) ? str_pad((string)$id_objetivo, 2, '0', STR_PAD_LEFT) : (string)$id_objetivo;
    $id_kr = sprintf('%03d-%s', $key_result_num, $objFmt);
    app_log('info', 'Gerado id_kr', ['id_kr'=>$id_kr, 'key_result_num'=>$key_result_num]);

    // IA (opcional) -> qualidade
    $score_ia         = isset($_POST['score_ia']) && $_POST['score_ia'] !== '' ? (int)$_POST['score_ia'] : null;
    $justificativa_ia = isset($_POST['justificativa_ia']) ? trim($_POST['justificativa_ia']) : null;
    $qualidade        = mapScoreToQualidade($score_ia);

    // INSERT KR
    $sql = "INSERT INTO key_results (
                id_kr, id_objetivo, key_result_num, descricao, usuario_criador, dt_criacao,
                tipo_kr, natureza_kr, status, status_aprovacao, tipo_frequencia_milestone,
                baseline, meta, unidade_medida, direcao_metrica, margem_confianca,
                data_inicio, data_fim, responsavel, observacoes,
                dt_ultima_atualizacao, usuario_ult_alteracao,
                qualidade
            ) VALUES (
                :id_kr, :id_objetivo, :key_result_num, :descricao, :usuario_criador, :dt_criacao,
                :tipo_kr, :natureza_kr, :status, 'pendente', :tipo_frequencia_milestone,
                :baseline, :meta, :unidade_medida, :direcao_metrica, :margem_confianca,
                :data_inicio, :data_fim, :responsavel, :observacoes,
                :dt_ultima_atualizacao, :usuario_ult_alteracao,
                :qualidade
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
        ':natureza_kr'               => $natureza_kr_in,          // id do domínio informado no front
        ':status'                    => $statusNorm,               // normalizado para FK
        ':tipo_frequencia_milestone' => $freqSlug,                 // slug válido ('semanal','quinzenal',...)
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
    ]);
    app_log('info', 'KR inserido', ['id_kr'=>$id_kr, 'freq'=>$freqSlug, 'inicio'=>$data_inicio, 'fim'=>$data_fim]);

    // Milestones
    $qtdeMilestones = 0;
    if ($autogerar) {
        app_log('info', 'Gerando milestones', ['id_kr'=>$id_kr]);
        $qtdeMilestones = gerarMilestonesParaKR(
            $pdo, $MILESTONE_TABLE, $id_kr,
            $data_inicio, $data_fim, $freqSlug,
            (float)$baseline, (float)$meta,
            $naturezaSlug, $direcao_metrica ?: null, $unidade_medida ?: null
        );
        app_log('info', 'Milestones gerados', ['id_kr'=>$id_kr, 'qtde'=>$qtdeMilestones]);
    }

    $pdo->commit();
    app_log('info', 'Transação concluída com sucesso', ['id_kr'=>$id_kr]);

    echo json_encode(['success' => true, 'id_kr' => $id_kr, 'key_result_num' => $key_result_num, 'milestones'=>$qtdeMilestones]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $logger->error("Erro ao salvar KR", ['error' => $e->getMessage()]);
    app_log('error', 'Erro ao salvar KR', ['exception'=>$e->getMessage()]);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Falha ao salvar KR']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $logger->error("Erro inesperado ao salvar/generar milestones", ['error' => $e->getMessage()]);
    app_log('error', 'Erro inesperado ao salvar/generar milestones', ['exception'=>$e->getMessage()]);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Falha ao gerar milestones']);
}
