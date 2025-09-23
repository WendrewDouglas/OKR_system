<?php
// views/dashboard.php

// DEV ONLY (remova em produção)
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/functions.php';

// Redireciona se não estiver logado
if (!isset($_SESSION['user_id'])) {
  header('Location: /OKR_system/views/login.php');
  exit;
}

// CSRF
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// Conexão
try {
  $dsn = "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4";
  $pdo = new PDO($dsn, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
} catch (PDOException $e) {
  http_response_code(500);
  die("Erro ao conectar: ".$e->getMessage());
}

// ====== Dados da empresa ======
$userId = (int)$_SESSION['user_id'];
$company = [
  'id_company'   => null,
  'organizacao'  => null,
  'razao_social' => null,
  'cnpj'         => null,
  'missao'       => null,
  'visao'        => null,
];

try {
  $stmt = $pdo->prepare("
    SELECT c.id_company, c.organizacao, c.razao_social, c.cnpj, c.missao, c.visao
    FROM usuarios u
    LEFT JOIN company c ON c.id_company = u.id_company
    WHERE u.id_user = :uid
    LIMIT 1
  ");
  $stmt->execute([':uid' => $userId]);
  if ($row = $stmt->fetch()) $company = $row;
} catch (Throwable $th) { /* mantém default */ }

if (empty($company['id_company'])) {
  header('Location: /OKR_system/organizacao');
  exit;
}

$_SESSION['company_id'] = (int)$company['id_company'];
$companyId = (int)$company['id_company'];
$companyName = $company['organizacao'] ?: ($company['razao_social'] ?: 'Sua Empresa');
$companyHasCNPJ = !empty($company['cnpj']);

// ===== Helpers (defina ANTES de usar) =====
function pct($parte, $todo) { return $todo ? (int)round(($parte/$todo)*100) : 0; }

function first_upper_rest_lower(string $s): string {
  $s = trim($s);
  if ($s === '') return $s;
  $s = mb_strtolower($s, 'UTF-8');
  $first = mb_strtoupper(mb_substr($s, 0, 1, 'UTF-8'), 'UTF-8');
  return $first . mb_substr($s, 1, null, 'UTF-8');
}

/**
 * Normaliza rótulos de pilares para chaves canônicas:
 *   financeiro | clientes | processos | aprendizado
 * Aceita tanto id_pilar quanto descricao_exibicao (qualquer caixa/acentos).
 * NÃO faz replace por substring; usa equivalência exata pós-trim/lower/sem acentos.
 */
$normPilar = static function($s) {
  $s = trim((string)$s);
  if ($s === '') return '';

  // lower + remover acentos
  $lower = mb_strtolower($s, 'UTF-8');
  $trans = [
    'á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a',
    'ç'=>'c',
    'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
    'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
    'ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o',
    'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u'
  ];
  $norm = strtr($lower, $trans);
  $norm = preg_replace('/\s+/', ' ', $norm); // colapsa múltiplos espaços

  // mapa de equivalências exatas (após normalização)
  static $map = [
    'financeiro'                 => 'financeiro',
    'financas'                   => 'financeiro',
    'financeira'                 => 'financeiro',

    'clientes'                   => 'clientes',
    'cliente'                    => 'clientes',
    'foco no cliente'            => 'clientes',
    'mercado e clientes'         => 'clientes',

    'processos'                  => 'processos',
    'processo'                   => 'processos',
    'processos internos'         => 'processos',
    'operacoes'                  => 'processos',
    'operacoes internas'         => 'processos',
    'operacao'                   => 'processos',
    'operacoes e processos'      => 'processos',

    'aprendizado'                => 'aprendizado',
    'aprendizagem'               => 'aprendizado',
    'aprendizado e crescimento'  => 'aprendizado',
    'pessoas'                    => 'aprendizado',
  ];

  // tenta equivalência direta
  if (isset($map[$norm])) return $map[$norm];

  // tenta remover palavras extras comuns (ex.: "perspectiva clientes", etc.)
  $norm2 = preg_replace('/^(perspectiva|pilar|area|área|dimensao|dimensão)\s+/u','',$norm);
  if (isset($map[$norm2])) return $map[$norm2];

  // fallback: se já é um dos 4, fica como está; senão, devolve string simplificada
  if (in_array($norm, ['financeiro','clientes','processos','aprendizado'], true)) return $norm;

  return $norm; // deixa rastreável, mas fora do set padrão
};

$clamp = static function($v){
  if($v===null||!is_numeric($v)) return null;
  $v=(float)$v; return (int)max(0,min(100,round($v)));
};

// Paleta e ícones por pilar (chave canônica)
$PILLAR_COLORS = [
  'aprendizado'=>'#8e44ad',
  'processos'  =>'#2980b9',
  'clientes'   =>'#27ae60',
  'financeiro' =>'#f39c12'
];
$PILLAR_ICONS = [
  'aprendizado'=>'fa-solid fa-graduation-cap',
  'processos'  =>'fa-solid fa-gears',
  'clientes'   =>'fa-solid fa-users',
  'financeiro' =>'fa-solid fa-coins'
];

// ====== Endpoint AJAX: salvar missão/visão ======
if (isset($_GET['ajax']) && $_GET['ajax'] === 'vm') {
  header('Content-Type: application/json; charset=utf-8');

  if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success'=>false, 'error'=>'Não autorizado']); exit;
  }

  // CSRF
  $token = $_POST['csrf_token'] ?? '';
  if (!$token || !hash_equals($_SESSION['csrf_token'], $token)) {
    http_response_code(403);
    echo json_encode(['success'=>false, 'error'=>'Falha de segurança (CSRF). Recarregue a página.']); exit;
  }

  // Estado mínimo da empresa
  $stC = $pdo->prepare("
    SELECT c.id_company, c.cnpj, c.missao, c.visao
    FROM usuarios u
    LEFT JOIN company c ON c.id_company = u.id_company
    WHERE u.id_user = :uid
    LIMIT 1
  ");
  $stC->execute([':uid' => $userId]);
  $cRow = $stC->fetch();

  if (!$cRow || empty($cRow['id_company'])) {
    echo json_encode(['success'=>false, 'error'=>'Empresa não encontrada para este usuário.']); exit;
  }
  if (empty($cRow['cnpj'])) {
    echo json_encode([
      'success' => false,
      'requireCNPJ' => true,
      'message' => 'Você precisa cadastrar o CNPJ para incluir Missão e Visão.',
      'redirect' => '/OKR_system/organizacao'
    ]);
    exit;
  }

  $tipo  = $_POST['tipo']  ?? '';
  $valor = trim((string)($_POST['valor'] ?? ''));

  if (!in_array($tipo, ['missao','visao'], true)) {
    echo json_encode(['success'=>false, 'error'=>'Tipo inválido.']); exit;
  }
  if ($valor === '') {
    echo json_encode(['success'=>false, 'error'=>'O texto não pode ficar vazio.']); exit;
  }
  if (mb_strlen($valor) > 2000) {
    echo json_encode(['success'=>false, 'error'=>'O texto excede 2000 caracteres.']); exit;
  }

  // Evita reaproveitar o mesmo texto
  $atual = (string)($cRow[$tipo] ?? '');
  if (mb_strtolower(trim($atual)) === mb_strtolower($valor)) {
    echo json_encode(['success'=>false, 'error'=>'O novo texto precisa ser diferente do atual.']); exit;
  }

  // Normalização simples para exibição
  $normalized = mb_strtolower($valor, 'UTF-8');
  $normalized = mb_strtoupper(mb_substr($normalized, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($normalized, 1, null, 'UTF-8');

  try {
    $sql = "UPDATE company SET {$tipo} = :valor, updated_at = NOW() WHERE id_company = :id LIMIT 1";
    $stU = $pdo->prepare($sql);
    $stU->execute([':valor' => $valor, ':id' => $cRow['id_company']]);
  } catch (Throwable $th) {
    http_response_code(500);
    echo json_encode(['success'=>false, 'error'=>'Erro ao salvar.']); exit;
  }

  echo json_encode([
    'success'=>true,
    'tipo'=>$tipo,
    'html'=> nl2br(htmlspecialchars($normalized, ENT_QUOTES, 'UTF-8'))
  ]);
  exit;
}

// Totais gerais
$stTotais = $pdo->prepare("
  SELECT
    (SELECT COUNT(*) FROM objetivos o WHERE o.id_company = :cid) AS total_obj,
    (SELECT COUNT(*) FROM key_results kr JOIN objetivos o ON o.id_objetivo = kr.id_objetivo WHERE o.id_company = :cid) AS total_kr,
    (SELECT COUNT(*)
       FROM key_results kr
       JOIN objetivos o ON o.id_objetivo = kr.id_objetivo
      WHERE o.id_company = :cid
        AND (kr.dt_conclusao IS NOT NULL
             OR kr.status IN ('Concluído','Concluido','Completo','Finalizado'))) AS total_kr_done,
    (SELECT COUNT(*)
       FROM key_results kr
       JOIN objetivos o ON o.id_objetivo = kr.id_objetivo
       LEFT JOIN milestones_kr m
              ON m.id_kr = kr.id_kr
             AND m.data_ref = (
                  SELECT MAX(m2.data_ref)
                  FROM milestones_kr m2
                  WHERE m2.id_kr = kr.id_kr
                    AND m2.data_ref <= CURDATE()
             )
      WHERE o.id_company = :cid
        AND (kr.dt_conclusao IS NULL
             AND (kr.status IS NULL OR kr.status NOT IN ('Concluído','Concluido','Completo','Finalizado')))
        AND m.id_milestone IS NOT NULL
        AND (m.valor_real_consolidado IS NULL OR m.valor_real_consolidado < m.valor_esperado)
    ) AS total_kr_risk
");
$stTotais->execute([':cid' => $companyId]);
$totais = $stTotais->fetch();

// ====== Pilares BSC ======
$stPilares = $pdo->prepare("
  SELECT
    p.id_pilar,
    p.descricao_exibicao AS pilar_nome,
    COALESCE(COUNT(DISTINCT o.id_objetivo),0) AS objetivos,
    COALESCE(COUNT(kr.id_kr),0)               AS krs,
    COALESCE(SUM(CASE
      WHEN kr.dt_conclusao IS NOT NULL
        OR kr.status IN ('Concluído','Concluido','Completo','Finalizado') THEN 1
      ELSE 0 END),0) AS krs_concluidos,
    COALESCE(SUM(
      CASE
        WHEN kr.dt_conclusao IS NULL
         AND (kr.status IS NULL OR kr.status NOT IN ('Concluído','Concluido','Completo','Finalizado'))
         AND m.id_milestone IS NOT NULL
         AND (m.valor_real_consolidado IS NULL OR m.valor_real_consolidado < m.valor_esperado)
        THEN 1 ELSE 0
      END
    ),0) AS krs_risco
  FROM dom_pilar_bsc p
  LEFT JOIN objetivos o
         ON o.pilar_bsc = p.id_pilar
        AND o.id_company = :cid
  LEFT JOIN key_results kr
         ON kr.id_objetivo = o.id_objetivo
  LEFT JOIN milestones_kr m
         ON m.id_kr = kr.id_kr
        AND m.data_ref = (
             SELECT MAX(m2.data_ref)
             FROM milestones_kr m2
             WHERE m2.id_kr = kr.id_kr
               AND m2.data_ref <= CURDATE()
        )
  GROUP BY p.id_pilar, p.descricao_exibicao, p.ordem_pilar
  ORDER BY p.ordem_pilar
");
$stPilares->execute([':cid' => $companyId]);
$pilares = $stPilares->fetchAll();

// === Mapa robusto para reconhecer qualquer variação vinda de objetivos.pilar_bsc ===
// cria um lookup que aceita: id_pilar, descricao_exibicao, e suas versões normalizadas
$pilarLookup = [];
foreach ($pilares as $row) {
  $idRaw   = (string)$row['id_pilar'];              // ex.: "Processos"
  $nomeRaw = (string)$row['pilar_nome'];            // ex.: "Processos Internos"
  $canon   = $normPilar($nomeRaw ?: $idRaw);        // ex.: "processos"

  // chaves possíveis (com e sem normalização)
  foreach ([$idRaw, $nomeRaw] as $k) {
    $k1 = trim($k);
    if ($k1 !== '') $pilarLookup[$k1] = $canon;     // forma original (case-sensitive)
    $k2 = mb_strtolower($k1,'UTF-8');
    if ($k2 !== '') $pilarLookup[$k2] = $canon;     // minúscula
    // sem acentos
    $k3 = strtr($k2, ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','ç'=>'c','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u']);
    if ($k3 !== '') $pilarLookup[$k3] = $canon;
    // normalizador canônico
    $k4 = $normPilar($k1);
    if ($k4 !== '') $pilarLookup[$k4] = $canon;
  }
}

// ===================== PROGRESSO DOS PILARES =====================
$tableExists = static function(PDO $pdo, string $t): bool {
  try { $pdo->query("SHOW COLUMNS FROM `$t`"); return true; } catch(Throwable) { return false; }
};
$colExists = static function(PDO $pdo, string $t, string $c): bool {
  try { $st=$pdo->prepare("SHOW COLUMNS FROM `$t` LIKE :c"); $st->execute([':c'=>$c]); return (bool)$st->fetch(); } catch(Throwable) { return false; }
};

$pillarMedia = [];
$stObj = $pdo->prepare("SELECT id_objetivo, pilar_bsc FROM objetivos WHERE id_company = :cid");
$stObj->execute([':cid'=>$companyId]);
$objsAll = $stObj->fetchAll();

if ($objsAll) {
  $objIds = array_column($objsAll, 'id_objetivo');

  // mapeia objetivo -> chave canônica do pilar
  $objPilarKey = [];
  foreach ($objsAll as $o) {
    $raw = trim((string)($o['pilar_bsc'] ?? '')); // pode vir "Processos" ou "Processos Internos", etc.
    if ($raw === '') continue;

    // tenta todas as formas no lookup, senão normaliza direto
    $canon = $pilarLookup[$raw]
          ?? $pilarLookup[mb_strtolower($raw,'UTF-8')]
          ?? $pilarLookup[strtr(mb_strtolower($raw,'UTF-8'), ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','ç'=>'c','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u'])]
          ?? $normPilar($raw);

    $objPilarKey[(int)$o['id_objetivo']] = $canon;
  }

  $in = implode(',', array_fill(0, count($objIds), '?'));

  $selKR = function(string $c) use ($colExists, $pdo) {
    return $colExists($pdo,'key_results',$c) ? "kr.`$c`" : "NULL";
  };
  $stKR = $pdo->prepare("
    SELECT kr.id_kr, kr.id_objetivo,
           {$selKR('baseline')} AS baseline,
           {$selKR('meta')}     AS meta
    FROM key_results kr
    WHERE kr.id_objetivo IN ($in)
  ");
  $stKR->execute($objIds);
  $krsAll = $stKR->fetchAll();

  $msT=null; if($tableExists($pdo,'milestones_kr')) $msT='milestones_kr'; else if($tableExists($pdo,'milestones')) $msT='milestones';
  $apT=null; if($tableExists($pdo,'apontamentos_kr')) $apT='apontamentos_kr'; else if($tableExists($pdo,'apontamentos')) $apT='apontamentos';

  $msKr=$msDate=$msExp=$msReal=null; $apKr=$apVal=$apWhen=null;
  $findCol=function(PDO $pdo,string $table,array $opts){ foreach($opts as $c){ try{$st=$pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :c"); $st->execute([':c'=>$c]); if($st->fetch()) return $c; }catch(Throwable){} } return null; };

  if ($msT){
    $msKr   = $findCol($pdo,$msT,['id_kr','kr_id','id_key_result','key_result_id']);
    $msDate = $findCol($pdo,$msT,['data_ref','dt_prevista','data_prevista','data','dt']);
    $msExp  = $findCol($pdo,$msT,['valor_esperado','esperado','target','meta']);
    $msReal = $findCol($pdo,$msT,['valor_real_consolidado','valor_real','realizado','resultado','alcancado']);
  }
  if ($apT){
    $apKr   = $findCol($pdo,$apT,['id_kr','kr_id','id_key_result','key_result_id']);
    $apVal  = $findCol($pdo,$apT,['valor_real','valor','resultado']);
    $apWhen = $findCol($pdo,$apT,['dt_apontamento','created_at','dt_criacao','data','dt']);
  }

  $stExp    = ($msT && $msKr && $msDate && $msExp) ? $pdo->prepare("SELECT `$msExp` FROM `$msT` WHERE `$msKr`=:id AND `$msDate`<=CURDATE() ORDER BY `$msDate` DESC LIMIT 1") : null;
  $stRealMs = ($msT && $msKr && $msReal)          ? $pdo->prepare("SELECT `$msReal` FROM `$msT` WHERE `$msKr`=:id AND `$msReal` IS NOT NULL AND `$msReal`<>'' ORDER BY ".($msDate? "`$msDate` DESC":"1")." LIMIT 1") : null;
  $stRealAp = ($apT && $apKr && $apVal)           ? $pdo->prepare("SELECT `$apVal` FROM `$apT` WHERE `$apKr`=:id ORDER BY ".($apWhen? "`$apWhen` DESC":"1")." LIMIT 1") : null;

  $objVals = [];
  foreach ($krsAll as $kr) {
    $oid = (int)$kr['id_objetivo'];
    if (!isset($objVals[$oid])) $objVals[$oid] = [];

    $base = is_numeric($kr['baseline']) ? (float)$kr['baseline'] : null;
    $meta = is_numeric($kr['meta'])     ? (float)$kr['meta']     : null;

    $realNow = null;
    if ($stRealMs){ $stRealMs->execute([':id'=>$kr['id_kr']]); $realNow = $stRealMs->fetchColumn(); }
    if (($realNow===false || $realNow===null) && $stRealAp){
      $stRealAp->execute([':id'=>$kr['id_kr']]); $realNow = $stRealAp->fetchColumn();
    }

    $pctAtual = null;
    if ($base!==null && $meta!==null && $meta!=$base){
      if (is_numeric($realNow)) {
        if ($meta > $base){ // crescer
          $pctAtual = (($realNow-$base)/($meta-$base))*100.0;
        } else { // reduzir
          $pctAtual = (($base-$realNow)/($base-$meta))*100.0;
        }
        $pctAtual = $clamp($pctAtual);
      }
    }
    if ($pctAtual!==null) $objVals[$oid][] = $pctAtual;
  }

  $objPct = [];
  foreach ($objVals as $oid => $vals) {
    if (count($vals)) { $objPct[$oid] = (int)round(array_sum($vals)/count($vals)); }
  }

  $pAgg = [];
  foreach ($objPct as $oid => $pctVal) {
    $pkey = $objPilarKey[$oid] ?? '';
    if (!$pkey) continue;
    if (!isset($pAgg[$pkey])) $pAgg[$pkey] = ['sum'=>0,'cnt'=>0];
    $pAgg[$pkey]['sum'] += $pctVal;
    $pAgg[$pkey]['cnt']++;
  }
  foreach ($pAgg as $k => $acc) {
    $pillarMedia[$k] = $acc['cnt'] ? (int)round($acc['sum']/$acc['cnt']) : null;
  }
}

// Anexa 'media_pct' + chave canônica para cada pilar exibido
foreach ($pilares as &$p) {
  // use a descricao_exibicao (ex.: "Processos Internos") como fonte “humana” e normalize
  $key = $normPilar($p['pilar_nome'] ?: $p['id_pilar']);
  $p['__pkey']    = $key;
  $p['media_pct'] = $pillarMedia[$key] ?? null;
}
unset($p);

// ====== Ordem fixa: Financeiro, Clientes, Processos, Aprendizado ======
$desiredOrder = ['financeiro','clientes','processos','aprendizado'];
usort($pilares, function($a,$b) use ($desiredOrder){
  $ia = array_search($a['__pkey'] ?? '', $desiredOrder, true);
  $ib = array_search($b['__pkey'] ?? '', $desiredOrder, true);
  $ia = $ia === false ? 99 : $ia;
  $ib = $ib === false ? 99 : $ib;
  return $ia <=> $ib;
});

$mapaUrl = '/OKR_system/views/mapa_estrategico.php';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard – OKR System</title>

  <!-- CSS globais -->
  <link rel="stylesheet" href="/OKR_system/assets/css/base.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/layout.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/theme.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous"/>

  <!-- Tema por empresa -->
  <link rel="stylesheet"
        href="/OKR_system/assets/company_theme.php?cid=<?= (int)($_SESSION['company_id'] ?? 0) ?>">

  <style>
    :root{
      --bg-soft:#171b21; --card: var(--bg1, #222222); --muted:#a6adbb; --text:#eaeef6;
      --gold:var(--bg2, #F1C40F); --green:#22c55e; --blue:#60a5fa; --red:#ef4444;
      --border:#222733; --shadow:0 10px 30px rgba(0,0,0,.20);
      --mini-min-h: 64px;        /* cards menores */
      --mini-pad: 8px;
      --pillar-stripe-w: 8px;
      --card-pad: 14px;
      --gap: 8px;
      --fs-xxs: .72rem;
      --fs-xs:  .78rem;
      --fs-s:   .85rem;
      --fs-m:   .95rem;
      --pillar-stripe-h: 8px; /* altura da faixa no topo */
    }
    body { background:#fff !important; color:#111; }
    .content { background: transparent; }
    main.dashboard-container{
      padding: 20px; display: grid; grid-template-columns: 1fr; gap: 10px;
      margin-right: var(--chat-w); transition: margin-right .25s ease;
    }

    [hidden]{ display:none !important; }

    /* progresso com parte vazia branca */
    .pillar-card .progress-bar{
      background:var(--muted) !important;
      border-color: rgba(0,0,0,.15);
    }

    /* Visão/Missão */
    .vision-mission{ display: grid; grid-template-columns: 1fr 1fr; gap: var(--gap); }
    @media (max-width: 900px){ .vision-mission{ grid-template-columns: 1fr; } }
    .vm-card{
      background: linear-gradient(180deg, var(--card), #0d1117);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 12px var(--card-pad);
      box-shadow: var(--shadow);
      position: relative; overflow: hidden;
      color: var(--text);
    }
    .vm-card:before{
      content:""; position:absolute; inset:0;
      background: radial-gradient(400px 90px at 10% -10%, rgba(246,195,67,.10), transparent 40%),
                  radial-gradient(340px 140px at 110% 10%, rgba(96,165,250,.08), transparent 50%);
      pointer-events:none;
    }
    .vm-title{
      display:flex; align-items:center; gap:8px; margin-bottom:1px;
      font-weight:700; letter-spacing:.2px; font-size: var(--fs-s);
    }
    .vm-title .badge{ background: var(--gold); color:#1a1a1a; padding:4px 8px; border-radius:999px; font-size: var(--fs-xxs); font-weight:800; text-transform:uppercase; }
    .vm-text{ color:var(--muted); line-height:1.35; white-space:pre-line; text-align:left; font-size: var(--fs-xs); font-style:italic; }
    .vm-text a.vm-edit-link{ color:#eab308; text-decoration:underline dotted; }

    /* Pilares: 4 colunas */
    .pillars{
      display:grid; grid-template-columns: repeat(4, 1fr); gap: var(--gap);
    }
    @media (max-width: 640px){ .pillars{ grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 420px){ .pillars{ grid-template-columns: 1fr; } }

    .pillar-card{
      --pilar-color: var(--gold);
      background: linear-gradient(180deg, var(--card), #0d1117);
      border: none;
      border-radius: 12px;
      padding: var(--card-pad);
      box-shadow: var(--shadow);
      position:relative; overflow:hidden; color: var(--text);
      cursor: pointer; transition: transform .15s ease, border-color .15s ease, box-shadow .15s ease;
      outline: none;
    }
    .pillar-card:focus-visible{ box-shadow: 0 0 0 3px color-mix(in srgb, var(--pilar-color), #fff 70%); }
    .pillar-card:hover{ transform: translateY(-1px); }
    .pillar-card::after{
      content: "";
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: var(--pillar-stripe-h);
      /* degradezinho na horizontal usando a cor do pilar */
      background: linear-gradient(
        90deg,
        color-mix(in srgb, var(--pilar-color), #fff 10%),
        var(--pilar-color)
      );
      /* arredonda só os cantos de cima para casar com o card */
      border-top-left-radius: 12px;
      border-top-right-radius: 12px;
    }
    .pillar-header{ display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:8px; margin-top:5px; }
    .pillar-title{ display:flex; align-items:center; gap:8px; font-weight:700; font-size: var(--fs-s); }
    .pillar-title i{
      color: var(--pilar-color);
      background: color-mix(in srgb, var(--pilar-color), transparent 88%);
      width: 34px; height: 34px; border-radius: 10px;
      display:grid; place-items:center; border:1px solid color-mix(in srgb, var(--pilar-color), transparent 65%);
      font-size: .95rem;
    }

    .pillar-stats{
      display:grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; margin: 8px 0 8px; align-items: stretch;
    }
    .stat{
      display:flex; flex-direction:column; align-items:center; justify-content:center;
      min-height: var(--mini-min-h); padding: var(--mini-pad); background: #0e131a;
      border:1px solid var(--border); border-radius: 10px; text-align:center; box-sizing: border-box;
    }
    .stat .label{ font-size: var(--fs-xxs); color:var(--muted); line-height:1.1; white-space:nowrap; }
    .stat .value{ font-size: clamp(1rem, 1.9vw, 1.25rem); font-weight:800; letter-spacing:.15px; color: var(--text); }

    .progress-wrap{ margin-top:6px; }
    .progress-label{ display:flex; align-items:center; justify-content:space-between; font-size: var(--fs-xxs); color:var(--muted); margin-bottom:4px;}
    .progress-bar{ width:100%; height:8px; background:#ffffff !important; border:1px solid var(--border); border-radius:999px; overflow:hidden; }
    .progress-fill{ height:100%; width:0%; background: var(--pilar-color); border-right:1px solid rgba(255,255,255,.15); transition: width 700ms ease; }

    .risk-badge{ display:inline-flex; align-items:center; gap:6px; background: rgba(239,68,68,.12); color: #fecaca; border:1px solid rgba(239,68,68,.35);
      padding:3px 8px; border-radius:999px; font-size: var(--fs-xxs); font-weight:700; }
    .pillar-footer{ margin-top: 8px; display:flex; justify-content:center; }

    /* KPIs: 4 colunas */
    .kpi-row{ display:grid; grid-template-columns: repeat(4, 1fr); gap: var(--gap); }
    @media (max-width: 640px){ .kpi-row{ grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 420px){ .kpi-row{ grid-template-columns: 1fr; } }

    .kpi-card{
      background: linear-gradient(180deg, var(--card), #0e1319);
      border:1px solid var(--border); border-radius:12px; padding: var(--card-pad); box-shadow: var(--shadow);
      position:relative; overflow:hidden; color: var(--text);
      cursor:pointer; transition: transform .15s ease, border-color .15s ease, box-shadow .15s ease;
      min-height: 96px;
    }
    .kpi-card:hover{ transform: translateY(-1px); border-color:#2a3342; }
    .kpi-card:focus-visible{ outline: none; box-shadow: 0 0 0 3px rgba(96,165,250,.35); }
    .kpi-card .kpi-head{ display:flex; align-items:center; justify-content:space-between; margin-bottom:4px; color:var(--muted); font-size: var(--fs-xs); }
    .kpi-card .kpi-value{ font-size: clamp(1.2rem, 2.4vw, 1.6rem); color:var(--gold); font-weight:900; letter-spacing:.2px; }
    .kpi-icon{ width:34px; height:34px; border-radius:10px; display:grid; place-items:center; border:1px solid var(--border); background:rgba(96,165,250,.12); font-size:.95rem; color:#c7d2fe; }
    .kpi-card.success .kpi-icon{ color:#86efac; background:rgba(34,197,94,.12); }
    .kpi-card.danger  .kpi-icon{ color:#fca5a5; background:rgba(239,68,68,.12); }

    .card-link{ position:absolute; inset:0; z-index: 5; }
    .card-link:focus{ outline:none; }
  </style>
</head>
<body>
  <?php include __DIR__ . '/partials/sidebar.php'; ?>
  <div class="content">
    <?php include __DIR__ . '/partials/header.php'; ?>

    <main class="dashboard-container">

      <!-- Visão & Missão -->
      <section class="vision-mission">
        <!-- VISÃO -->
        <article class="vm-card" id="cardVisao">
          <div class="vm-title">
            <span class="badge">Visão</span><i class="fa-regular fa-eye"></i>
          </div>
          <p class="vm-text" id="visaoText">
            <?php if (!empty($company['visao'])): ?>
              <?= nl2br(htmlspecialchars(first_upper_rest_lower($company['visao']), ENT_QUOTES, 'UTF-8')) ?>
            <?php else: ?>
              Ser referência em excelência operacional e inovação, impulsionando crescimento sustentável e impacto positivo no mercado.<br>
              (Texto de exemplo — <a href="#" class="vm-edit-link" data-type="visao">clique aqui</a> e substitua pela visão oficial).
            <?php endif; ?>
          </p>
        </article>

        <!-- MISSÃO -->
        <article class="vm-card" id="cardMissao">
          <div class="vm-title">
            <span class="badge">Missão</span><i class="fa-solid fa-rocket"></i>
          </div>
          <p class="vm-text" id="missaoText">
            <?php if (!empty($company['missao'])): ?>
              <?= nl2br(htmlspecialchars(first_upper_rest_lower($company['missao']), ENT_QUOTES, 'UTF-8')) ?>
            <?php else: ?>
              Entregar valor contínuo aos clientes por meio de soluções simples, seguras e escaláveis, promovendo a alta performance das equipes.<br>
              (Texto de exemplo — <a href="#" class="vm-edit-link" data-type="missao">clique aqui</a> e substitua pela missão oficial).
            <?php endif; ?>
          </p>
        </article>
      </section>

      <!-- Pilares BSC -->
      <section class="pillars" aria-label="Pilares BSC">
        <?php foreach ($pilares as $p):
          $pctPilar = is_null($p['media_pct']) ? 0 : (int)$p['media_pct'];
          $pKey     = $p['__pkey'] ?: ''; // esperado: financeiro | clientes | processos | aprendizado
          $pColor   = $PILLAR_COLORS[$pKey] ?? '#a3a3a3';
          $pIcon    = $PILLAR_ICONS[$pKey]  ?? 'fa-solid fa-layer-group';
        ?>
        <div class="pillar-card" style="--pilar-color: <?= htmlspecialchars($pColor, ENT_QUOTES, 'UTF-8') ?>;"
             role="link" tabindex="0" aria-label="Abrir mapa estratégico do pilar <?= htmlspecialchars($p['pilar_nome'] ?: 'Pilar', ENT_QUOTES, 'UTF-8') ?>">
          <a class="card-link" href="<?= htmlspecialchars($mapaUrl, ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></a>

          <div class="pillar-header">
            <div class="pillar-title">
              <i class="<?= htmlspecialchars($pIcon) ?>"></i>
              <span><?= htmlspecialchars($p['pilar_nome'] ?: 'Pilar') ?></span>
            </div>
          </div>

          <div class="pillar-stats">
            <div class="stat">
              <div class="label">Objetivos</div>
              <div class="value countup" data-target="<?= (int)$p['objetivos'] ?>">0</div>
            </div>
            <div class="stat">
              <div class="label">KRs</div>
              <div class="value countup" data-target="<?= (int)$p['krs'] ?>">0</div>
            </div>
            <div class="stat">
              <div class="label">Concluídos</div>
              <div class="value countup" data-target="<?= (int)$p['krs_concluidos'] ?>">0</div>
            </div>
          </div>

          <div class="progress-wrap">
            <div class="progress-label">
              <span>Progresso do pilar</span>
              <strong><span class="progress-pct"><?= $pctPilar ?></span>%</strong>
            </div>
            <div class="progress-bar">
              <div class="progress-fill" data-final="<?= $pctPilar ?>"></div>
            </div>
          </div>

          <div class="pillar-footer">
            <span class="risk-badge" title="KRs com último milestone abaixo do esperado">
              <i class="fa-solid fa-triangle-exclamation"></i>
              <?= (int)$p['krs_risco'] ?> em risco
            </span>
          </div>
        </div>
        <?php endforeach; ?>
      </section>

      <!-- KPIs gerais -->
      <section class="kpi-row" aria-label="Indicadores gerais">
        <div class="kpi-card" role="link" tabindex="0" aria-label="Abrir mapa estratégico - Total de Objetivos">
          <a class="card-link" href="<?= htmlspecialchars($mapaUrl, ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></a>
          <div class="kpi-head"><span>Total de Objetivos</span><div class="kpi-icon"><i class="fa-solid fa-bullseye"></i></div></div>
          <div class="kpi-value countup" data-target="<?= (int)$totais['total_obj'] ?>">0</div>
        </div>
        <div class="kpi-card" role="link" tabindex="0" aria-label="Abrir mapa estratégico - Total de KRs">
          <a class="card-link" href="<?= htmlspecialchars($mapaUrl, ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></a>
          <div class="kpi-head"><span>Total de KRs</span><div class="kpi-icon"><i class="fa-solid fa-list-check"></i></div></div>
          <div class="kpi-value countup" data-target="<?= (int)$totais['total_kr'] ?>">0</div>
        </div>
        <div class="kpi-card success" role="link" tabindex="0" aria-label="Abrir mapa estratégico - KRs Concluídos">
          <a class="card-link" href="<?= htmlspecialchars($mapaUrl, ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></a>
          <div class="kpi-head"><span>KRs Concluídos</span><div class="kpi-icon"><i class="fa-solid fa-check-double"></i></div></div>
          <div class="kpi-value countup" data-target="<?= (int)$totais['total_kr_done'] ?>">0</div>
        </div>
        <div class="kpi-card danger" role="link" tabindex="0" aria-label="Abrir mapa estratégico - KRs em Risco">
          <a class="card-link" href="<?= htmlspecialchars($mapaUrl, ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></a>
          <div class="kpi-head"><span>KRs em Risco</span><div class="kpi-icon"><i class="fa-solid fa-triangle-exclamation"></i></div></div>
          <div class="kpi-value countup" data-target="<?= (int)$totais['total_kr_risk'] ?>">0</div>
        </div>
      </section>
    </main>

    <?php include __DIR__ . '/partials/chat.php'; ?>
  </div>

  <!-- Metas para JS -->
  <meta id="csrfToken" data-token="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
  <meta id="companyHasCNPJ" data-has="<?= $companyHasCNPJ ? '1':'0' ?>">
  <meta id="companyName" data-name="<?= htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') ?>">

  <!-- Modal de Edição (Missão/Visão) -->
  <div class="modal-backdrop" id="vmModalBackdrop" aria-hidden="true" hidden>
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="vmModalTitle">
      <header>
        <h3 id="vmModalTitle">Editar</h3>
        <button class="btn btn-link" id="vmClose" aria-label="Fechar">Fechar ✕</button>
      </header>
      <div class="modal-body">
        <div class="helper"><i class="fa-solid fa-building"></i> <strong id="companyHeaderName"><?= htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') ?></strong></div>
        <label for="vmTextarea" class="helper" id="vmHelper">Atualize o texto abaixo.</label>
        <textarea id="vmTextarea" class="textarea" maxlength="2000" placeholder="Digite aqui sua missão"></textarea>
        <div class="helper"><span id="vmCount">0</span>/2000</div>
      </div>
      <div class="modal-actions">
        <button class="btn" id="vmCancel">Cancelar</button>
        <button class="btn btn-primary" id="vmSave">Salvar</button>
      </div>
    </div>
  </div>

  <!-- Modal de Aviso: CNPJ necessário -->
  <div class="modal-backdrop" id="cnpjModalBackdrop" aria-hidden="true" hidden>
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="cnpjModalTitle">
      <header>
        <h3 id="cnpjModalTitle">CNPJ necessário</h3>
        <button class="btn btn-link" id="cnpjClose" aria-label="Fechar">Fechar ✕</button>
      </header>
      <div class="modal-body">
        <div class="notice">
          Para incluir <strong>Missão</strong> e <strong>Visão</strong>, é necessário cadastrar o <strong>CNPJ</strong> da empresa.
        </div>
        <p class="helper">Acesse a página <em>Organização</em> para concluir o cadastro.</p>
      </div>
      <div class="modal-actions">
        <button class="btn" id="cnpjBack">Voltar</button>
        <a class="btn btn-primary" id="cnpjGo" href="/OKR_system/organizacao">Ir para Organização</a>
      </div>
    </div>
  </div>

  <script>
    function animateCounter(el, target, duration=800){
      const start=0, t0=performance.now();
      function tick(t){
        const p=Math.min((t-t0)/duration,1);
        el.textContent = Math.floor(start+(target-start)*p).toLocaleString('pt-BR');
        if(p<1) requestAnimationFrame(tick);
      }
      requestAnimationFrame(tick);
    }
    function animateProgressBars(){
      document.querySelectorAll('.pillar-card .progress-fill').forEach(bar=>{
        const to=parseInt(bar.getAttribute('data-final')||'0',10);
        requestAnimationFrame(()=>{ bar.style.width = Math.max(0,Math.min(100,to))+'%'; });
      });
    }

    // Espaço do chat
    const CHAT_SELECTORS = ['#chatPanel', '.chat-panel', '.chat-container', '#chat', '.drawer-chat'];
    const TOGGLE_SELECTORS = ['#chatToggle', '.chat-toggle', '.btn-chat-toggle', '.chat-icon', '.chat-open'];
    function findChatEl(){ for(const s of CHAT_SELECTORS){ const el=document.querySelector(s); if(el) return el; } return null; }
    function isOpen(el){
      const style = getComputedStyle(el);
      const visible = style.display!=='none' && style.visibility!=='hidden';
      const w = el.offsetWidth;
      return (visible && w>0) || el.classList.contains('open') || el.classList.contains('show') || el.getAttribute('aria-expanded')==='true';
    }
    function updateChatWidth(){ const el = findChatEl(); const w = (el && isOpen(el)) ? el.offsetWidth : 0; document.documentElement.style.setProperty('--chat-w', (w||0)+'px'); }
    function setupChatObservers(){
      const chat = findChatEl(); if(!chat) return;
      const mo = new MutationObserver(()=>updateChatWidth());
      mo.observe(chat, { attributes:true, attributeFilter:['style','class','aria-expanded'] });
      window.addEventListener('resize', updateChatWidth);
      document.querySelectorAll(TOGGLE_SELECTORS.join(',')).forEach(btn=>btn.addEventListener('click', ()=>setTimeout(updateChatWidth, 200)));
      updateChatWidth();
    }

    document.addEventListener('DOMContentLoaded', ()=>{
      document.querySelectorAll('.countup[data-target]').forEach(el=>{
        const tgt = parseInt(el.getAttribute('data-target')||'0',10);
        animateCounter(el, tgt, 700 + Math.random()*300);
      });
      animateProgressBars();
      setupChatObservers();

      // Acessibilidade: Enter/Espaço abre links dos cards
      document.querySelectorAll('.pillar-card, .kpi-card').forEach(card=>{
        card.addEventListener('keydown', (ev)=>{
          if (ev.key === 'Enter' || ev.key === ' ') {
            ev.preventDefault();
            const a = card.querySelector('.card-link');
            if (a) window.location.href = a.href;
          }
        });
      });
    });

    // Modais (missão/visão)
    const vmModalBackdrop = document.getElementById('vmModalBackdrop');
    const vmTitle   = document.getElementById('vmModalTitle');
    const vmHelper  = document.getElementById('vmHelper');
    const vmTextarea= document.getElementById('vmTextarea');
    const vmCount   = document.getElementById('vmCount');
    const vmSaveBtn = document.getElementById('vmSave');
    const vmCancel  = document.getElementById('vmCancel');
    const vmClose   = document.getElementById('vmClose');

    const cnpjModalBackdrop = document.getElementById('cnpjModalBackdrop');
    const cnpjClose = document.getElementById('cnpjClose');
    const cnpjBack  = document.getElementById('cnpjBack');
    const cnpjGo    = document.getElementById('cnpjGo');

    const COMPANY_HAS_CNPJ = (document.getElementById('companyHasCNPJ').dataset.has === '1');
    const COMPANY_NAME = document.getElementById('companyName').dataset.name;

    let currentType = null;

    function openVMModal(type){
      currentType = type;
      vmTitle.textContent = type === 'visao' ? 'Editar Visão' : 'Editar Missão';
      vmHelper.textContent = type === 'visao'
        ? 'Defina a Visão de forma aspiracional e clara.'
        : 'Defina a Missão de forma objetiva e centrada no cliente.';
      document.getElementById('companyHeaderName').textContent = COMPANY_NAME;
      vmTextarea.value = '';
      vmTextarea.placeholder = (type === 'visao') ? 'Digite aqui sua visão' : 'Digite aqui sua missão';
      vmCount.textContent = '0';
      vmModalBackdrop.classList.add('show');
      vmModalBackdrop.removeAttribute('hidden');
      vmTextarea.focus();
    }
    function closeVMModal(){
      vmModalBackdrop.classList.remove('show');
      vmModalBackdrop.setAttribute('hidden', '');
      currentType = null; vmTextarea.value = ''; vmCount.textContent = '0';
    }
    function openCNPJModal(){
      cnpjModalBackdrop.classList.add('show');
      cnpjModalBackdrop.removeAttribute('hidden');
    }
    function closeCNPJModal(){
      cnpjModalBackdrop.classList.remove('show');
      cnpjModalBackdrop.setAttribute('hidden', '');
    }

    vmTextarea && vmTextarea.addEventListener('input', ()=>{ vmCount.textContent = vmTextarea.value.length.toString(); });
    vmCancel && vmCancel.addEventListener('click', closeVMModal);
    vmClose  && vmClose.addEventListener('click', closeVMModal);
    cnpjBack && cnpjBack.addEventListener('click', closeCNPJModal);
    cnpjClose && cnpjClose.addEventListener('click', closeCNPJModal);

    document.addEventListener('click', (e)=>{
      const a = e.target.closest('.vm-edit-link');
      if (a){
        e.preventDefault();
        if (!COMPANY_HAS_CNPJ){ openCNPJModal(); return; }
        openVMModal(a.getAttribute('data-type'));
      }
    });

    vmSaveBtn && vmSaveBtn.addEventListener('click', async ()=>{
      if (!currentType) return;
      const csrf = document.getElementById('csrfToken').dataset.token;
      const valor = vmTextarea.value.trim();

      const u = new URL(window.location.href);
      u.searchParams.set('ajax', 'vm');
      const ajaxUrl = u.toString();

      vmSaveBtn.disabled = true; vmSaveBtn.textContent = 'Salvando...';

      try{
        const params = new URLSearchParams();
        params.set('tipo', currentType);
        params.set('valor', valor);
        params.set('csrf_token', csrf);

        const res = await fetch(ajaxUrl, {
          method: 'POST',
          headers: {'Content-Type':'application/x-www-form-urlencoded'},
          body: params.toString(),
        });

        let data = null;
        try { data = await res.json(); } catch{ data = null; }
        if (!res.ok || !data) throw new Error('bad_response');

        if (!data.success){
          if (data.requireCNPJ){
            closeVMModal();
            if (data.redirect) { cnpjGo.setAttribute('href', data.redirect); }
            openCNPJModal();
          } else {
            alert(data.error || 'Erro ao salvar.');
          }
        } else {
          const html = data.html || '';
          if (data.tipo === 'visao'){
            document.getElementById('visaoText').innerHTML = html;
          } else {
            document.getElementById('missaoText').innerHTML = html;
          }
          closeVMModal();
        }
      }catch(err){
        console.error(err);
        alert('Falha na comunicação. Tente novamente.');
      } finally {
        vmSaveBtn.disabled = false; vmSaveBtn.textContent = 'Salvar';
      }
    });
  </script>
</body>
</html>
