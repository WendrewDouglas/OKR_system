<?php
// auth/salvar_objetivo.php
declare(strict_types=1);

// === DEV ONLY (remova em produção) ===
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// 1) Sessão e bootstrap
session_start();
$logger = require dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

// 2) ENV / API KEY
$envFile = dirname(__DIR__) . '/.env';
if (is_file($envFile)) {
  foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    [$k,$v] = array_pad(explode('=', $line, 2), 2, '');
    putenv(trim($k).'='.trim($v, " \t\n\r\0\x0B'\""));
  }
}
$apiKey = getenv('OPENAI_API_KEY') ?: '';

// 3) Cabeçalhos / guard rails
header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['error'=>'Não autorizado']);
  exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  http_response_code(405);
  echo json_encode(['error'=>'Método não permitido']);
  exit;
}
if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
  http_response_code(403);
  echo json_encode(['error'=>'Token CSRF inválido']);
  exit;
}

// 4) Entrada
$descricao        = trim((string)($_POST['nome_objetivo'] ?? ''));
$tipo_id          = (string)($_POST['tipo_objetivo'] ?? '');      // FK dom_tipo_objetivo.id_tipo
$pilar_id         = (string)($_POST['pilar_bsc'] ?? '');           // FK dom_pilar_bsc.id_pilar
$responsavel_raw  = trim((string)($_POST['responsavel'] ?? ''));   // CSV de IDs
$observacoes      = trim((string)($_POST['observacoes'] ?? ''));
$tipo_ciclo       = (string)($_POST['ciclo_tipo'] ?? '');          // FK dom_ciclos.nome_ciclo
$evaluate         = (string)($_POST['evaluate'] ?? '0');
$justificativa_ia = trim((string)($_POST['justificativa_ia'] ?? ''));

// 4.1) Detalhe do ciclo
$ciclo_detalhe = '';
switch ($tipo_ciclo) {
  case 'anual':
    $ciclo_detalhe = (string)($_POST['ciclo_anual_ano'] ?? '');
    break;
  case 'semestral':
    $ciclo_detalhe = (string)($_POST['ciclo_semestral'] ?? '');
    break;
  case 'trimestral':
    $ciclo_detalhe = (string)($_POST['ciclo_trimestral'] ?? '');
    break;
  case 'bimestral':
    $ciclo_detalhe = (string)($_POST['ciclo_bimestral'] ?? '');
    break;
  case 'mensal':
    $mm = (string)($_POST['ciclo_mensal_mes'] ?? '');
    $yy = (string)($_POST['ciclo_mensal_ano'] ?? '');
    if ($mm && $yy) $ciclo_detalhe = "$mm/$yy";
    break;
  case 'personalizado':
    $ini = (string)($_POST['ciclo_pers_inicio'] ?? '');
    $fim = (string)($_POST['ciclo_pers_fim'] ?? '');
    if ($ini && $fim) $ciclo_detalhe = "$ini a $fim";
    break;
}

// 4.2) Datas do ciclo
function calcularDatasCiclo(string $tipo_ciclo, array $dados): array {
  $dt_inicio = ''; $dt_prazo = '';
  switch ($tipo_ciclo) {
    case 'anual':
      $y = (int)($dados['ciclo_anual_ano'] ?? 0);
      if ($y) { $dt_inicio="$y-01-01"; $dt_prazo="$y-12-31"; }
      break;
    case 'semestral':
      if (preg_match('/^S([12])\/(\d{4})$/', (string)($dados['ciclo_semestral'] ?? ''), $m)) {
        $s=(int)$m[1]; $y=(int)$m[2];
        $dt_inicio = $s===1 ? "$y-01-01" : "$y-07-01";
        $dt_prazo  = $s===1 ? "$y-06-30" : "$y-12-31";
      }
      break;
    case 'trimestral':
      if (preg_match('/^Q([1-4])\/(\d{4})$/', (string)($dados['ciclo_trimestral'] ?? ''), $m)) {
        $q=(int)$m[1]; $y=(int)$m[2];
        $map=[1=>['01-01','03-31'],2=>['04-01','06-30'],3=>['07-01','09-30'],4=>['10-01','12-31']];
        $dt_inicio="$y-{$map[$q][0]}"; $dt_prazo="$y-{$map[$q][1]}";
      }
      break;
    case 'bimestral':
      if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', (string)($dados['ciclo_bimestral'] ?? ''), $m)) {
        $m1=(int)$m[1]; $m2=(int)$m[2]; $y=(int)$m[3];
        $dt_inicio = sprintf('%04d-%02d-01', $y, $m1);
        $dt_prazo  = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $y, $m2)));
      }
      break;
    case 'mensal':
      $mm=(int)($dados['ciclo_mensal_mes'] ?? 0);
      $yy=(int)($dados['ciclo_mensal_ano'] ?? 0);
      if ($mm && $yy) { $dt_inicio=sprintf('%04d-%02d-01',$yy,$mm); $dt_prazo=date('Y-m-t', strtotime("$yy-$mm-01")); }
      break;
    case 'personalizado':
      $ini=(string)($dados['ciclo_pers_inicio'] ?? '');
      $fim=(string)($dados['ciclo_pers_fim'] ?? '');
      if ($ini && $fim) { $dt_inicio="$ini-01"; $dt_prazo=date('Y-m-t', strtotime("$fim-01")); }
      break;
  }
  return [$dt_inicio, $dt_prazo];
}
[$dt_inicio, $dt_prazo] = calcularDatasCiclo($tipo_ciclo, $_POST);

// 4.3) Validação mínima
if ($descricao==='' || $tipo_id==='' || $pilar_id==='' || $tipo_ciclo==='' || $ciclo_detalhe==='' || $dt_inicio==='' || $dt_prazo==='') {
  http_response_code(422);
  echo json_encode(['error'=>'Campos obrigatórios não preenchidos']);
  exit;
}

// 5) DB
try {
  $pdo = new PDO(
    "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
    DB_USER, DB_PASS,
    [ PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC ]
  );
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>'Erro de conexão: '.$e->getMessage()]);
  exit;
}

// 5.1) Contexto do usuário criador
$userId = (int)($_SESSION['user_id'] ?? 0);
$st = $pdo->prepare("SELECT primeiro_nome, ultimo_nome, id_company FROM usuarios WHERE id_user=:u LIMIT 1");
$st->execute([':u'=>$userId]);
$me = $st->fetch();
if (!$me || empty($me['id_company'])) {
  http_response_code(400);
  echo json_encode(['error'=>'Usuário sem empresa vinculada']);
  exit;
}
$creatorName = trim(($me['primeiro_nome'] ?? '').' '.($me['ultimo_nome'] ?? ''));
$companyId   = (int)$me['id_company'];

// 5.2) Valida FKs dos domínios (defensivo)
$ok_tipo = $pdo->prepare("SELECT 1 FROM dom_tipo_objetivo WHERE id_tipo=:id LIMIT 1");
$ok_tipo->execute([':id'=>$tipo_id]);
$ok_pilar = $pdo->prepare("SELECT 1 FROM dom_pilar_bsc WHERE id_pilar=:id LIMIT 1");
$ok_pilar->execute([':id'=>$pilar_id]);
$ok_ciclo = $pdo->prepare("SELECT 1 FROM dom_ciclos WHERE nome_ciclo=:n LIMIT 1");
$ok_ciclo->execute([':n'=>$tipo_ciclo]);
if (!$ok_tipo->fetch() || !$ok_pilar->fetch() || !$ok_ciclo->fetch()) {
  http_response_code(422);
  echo json_encode(['error'=>'Valores de domínio inválidos (tipo/pilar/ciclo).']);
  exit;
}

// 5.3) Dono: usa primeiro ID e garante mesma empresa
$donoId = (int)explode(',', $responsavel_raw)[0];
if (!$donoId) $donoId = $userId;
$chk = $pdo->prepare("SELECT primeiro_nome, ultimo_nome FROM usuarios WHERE id_user=:id AND id_company=:c LIMIT 1");
$chk->execute([':id'=>$donoId, ':c'=>$companyId]);
$respRow = $chk->fetch();
if (!$respRow) {
  // se não pertence à mesma empresa, cai para o criador
  $donoId = $userId;
  $respRow = ['primeiro_nome'=>$me['primeiro_nome'], 'ultimo_nome'=>$me['ultimo_nome']];
}
$responsavel_nome = trim(($respRow['primeiro_nome'] ?? '').' '.($respRow['ultimo_nome'] ?? ''));

// 5.4) Labels pro prompt da IA
$lTipo = $pdo->prepare("SELECT descricao_exibicao FROM dom_tipo_objetivo WHERE id_tipo=:id");
$lTipo->execute([':id'=>$tipo_id]);
$tipo_label = (string)($lTipo->fetchColumn() ?: $tipo_id);
$lPilar = $pdo->prepare("SELECT descricao_exibicao FROM dom_pilar_bsc WHERE id_pilar=:id");
$lPilar->execute([':id'=>$pilar_id]);
$pilar_label = (string)($lPilar->fetchColumn() ?: $pilar_id);

// 6) Avaliação (com fallback)
function evaluateObjectiveSafe(string $apiKey, string $descricao, string $tipo, string $pilar, string $responsavel, string $dt_inicio, string $dt_prazo, $logger): array {
  // Fallback local (sempre retorna JSON válido)
  $fallback = function() use ($descricao, $tipo, $pilar): array {
    $score = 7;
    if (mb_strlen($descricao) < 10) $score -= 2;
    if (preg_match('/\b\d+%|\b\d{1,2}\/\d{1,2}\/\d{2,4}|\b\d{4}-\d{2}-\d{2}/u', $descricao)) $score -= 1; // evita métricas/prazos na descrição
    if ($score < 0) $score = 0; if ($score > 10) $score = 10;
    $j = "Avaliação automática: objetivo {$tipo}/{$pilar}; texto " . (mb_strlen($descricao) < 10 ? 'curto' : 'adequado') . ".";
    return ['score'=>$score, 'justification'=>$j];
  };

  $system = "Você avalia objetivos no formato OKR. Restrições: 1) clareza e inspiração; 2) pilar BSC adequado; 3) ciclo adequado; 4) tipo coerente; 5) sem prazo explícito na descrição; 6) sem métricas na descrição; 7) impacto.
Responda SOMENTE JSON: {\"score\":0-10,\"justification\":\"texto curto\"}.";
  $user   = "Objetivo: {$descricao}\nTipo: {$tipo}\nPilar BSC: {$pilar}\nResponsável: {$responsavel}\nPeríodo: {$dt_inicio} até {$dt_prazo}";

  if ($apiKey === '') {
    return $fallback();
  }

  $payload = json_encode([
    'model' => 'gpt-4', // pode trocar para outro modelo compatível
    'messages' => [
      ['role'=>'system','content'=>$system],
      ['role'=>'user','content'=>$user],
    ],
    'max_tokens' => 150,
    'temperature'=> 0.4,
  ]);

  try {
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
      CURLOPT_POST => true,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        "Authorization: Bearer {$apiKey}",
      ],
      CURLOPT_POSTFIELDS => $payload,
      CURLOPT_TIMEOUT => 20,
    ]);
    $res = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    $logger && $logger->debug('OPENAI ◀', ['http'=>$http, 'err'=>$err ?: null]);

    if ($err || $http < 200 || $http >= 300 || !$res) {
      return $fallback();
    }

    $json = json_decode($res, true);
    $content = $json['choices'][0]['message']['content'] ?? '';
    $parsed = json_decode($content, true);

    if (json_last_error() === JSON_ERROR_NONE && isset($parsed['score'], $parsed['justification'])) {
      return ['score'=>(int)$parsed['score'], 'justification'=>trim((string)$parsed['justification'])];
    }
    return $fallback();
  } catch (\Throwable $e) {
    $logger && $logger->warning('OPENAI exception', ['msg'=>$e->getMessage()]);
    return $fallback();
  }
}

// 6.1) Se for só avaliação (pré-salvar)
$eval = evaluateObjectiveSafe($apiKey, $descricao, $tipo_label, $pilar_label, $responsavel_nome, $dt_inicio, $dt_prazo, $logger);
if ($evaluate === '1') {
  echo json_encode(['score'=>$eval['score'], 'justification'=>$eval['justification']]);
  exit;
}

// 7) Qualidade
function mapScoreToQualidadeId(int $s): string {
  if ($s >= 9) return 'ótimo';
  if ($s >= 7) return 'bom';
  if ($s >= 5) return 'moderado';
  if ($s >= 3) return 'ruim';
  return 'péssimo';
}
$id_qualidade = mapScoreToQualidadeId((int)$eval['score']);
if ($justificativa_ia === '') {
  $justificativa_ia = $eval['justification'] ?? null;
}

// 8) INSERT (deixa status/status_aprovacao com DEFAULTs corretos)
try {
  $pdo->beginTransaction();

  $sql = "
    INSERT INTO objetivos (
      descricao, tipo, pilar_bsc, dono,
      usuario_criador, id_user_criador, id_company,
      dt_criacao, dt_prazo, dt_inicio,
      qualidade, observacoes,
      tipo_ciclo, ciclo, justificativa_ia
    ) VALUES (
      :descricao, :tipo, :pilar, :dono,
      :usuario_criador, :id_user_criador, :id_company,
      CURDATE(), :dt_prazo, :dt_inicio,
      :qualidade, :observacoes,
      :tipo_ciclo, :ciclo, :justificativa_ia
    )
  ";

  $st = $pdo->prepare($sql);
  $st->execute([
    ':descricao'        => $descricao,
    ':tipo'             => $tipo_id,
    ':pilar'            => $pilar_id,
    ':dono'             => (string)$donoId,   // coluna é VARCHAR; armazenamos o id do usuário
    ':usuario_criador'  => $creatorName,
    ':id_user_criador'  => $userId,
    ':id_company'       => $companyId,
    ':dt_prazo'         => $dt_prazo,
    ':dt_inicio'        => $dt_inicio,
    ':qualidade'        => $id_qualidade,
    ':observacoes'      => ($observacoes !== '' ? $observacoes : null),
    ':tipo_ciclo'       => $tipo_ciclo,
    ':ciclo'            => $ciclo_detalhe,
    ':justificativa_ia' => ($justificativa_ia !== '' ? $justificativa_ia : null),
  ]);

  $newId = (int)$pdo->lastInsertId();
  $pdo->commit();

  echo json_encode(['success'=>true, 'id_objetivo'=>$newId]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log(date('[Y-m-d H:i:s] ').$e->getMessage()."\n", 3, dirname(__DIR__).'/error_log');
  http_response_code(500);
  echo json_encode(['error'=>'Falha ao salvar: '.$e->getMessage()]);
}
