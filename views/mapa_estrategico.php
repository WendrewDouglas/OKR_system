<?php
// views/mapa_estrategico.php — Mapa Estratégico com ligações entre objetivos (SVG overlay, CRUD AJAX, Neon + Modal UX)
// -------------------------------------------------------------------------------------------------------------------------------
declare(strict_types=1);
ini_set('display_errors','0'); ini_set('display_startup_errors','0'); error_reporting(0);

session_start();
require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/functions.php';

if (!isset($_SESSION['user_id'])) { header('Location: /OKR_system/views/login.php'); exit; }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf_token'];

// Helpers
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function slug($s){
  $s = mb_strtolower(trim((string)$s),'UTF-8');
  $s = @iconv('UTF-8','ASCII//TRANSLIT',$s) ?: $s;
  $s = preg_replace('/[^a-z0-9]+/',' ', $s);
  return trim(preg_replace('/\s+/',' ', $s));
}
function normalizeText($s){
  $s = (string)$s;
  if ($s==='') return '';
  return mb_strtoupper(mb_substr($s,0,1),'UTF-8').mb_strtolower(mb_substr($s,1),'UTF-8');
}
function table_exists(PDO $pdo, string $table): bool {
  try{ $st=$pdo->prepare("SHOW TABLES LIKE :t"); $st->execute([':t'=>$table]); return (bool)$st->fetchColumn(); }
  catch(Throwable){ return false; }
}
function cols(PDO $pdo, string $table): array {
  try{ $st=$pdo->query("SHOW COLUMNS FROM `$table`"); $out=[]; foreach($st as $r){ $out[]=$r['Field']; } return $out; }
  catch(Throwable){ return []; }
}
function hascol(array $list, string $name): bool {
  foreach($list as $c){ if (strcasecmp($c,$name)===0) return true; }
  return false;
}
/** Cor do texto para bom contraste sobre o chip colorido */
function pill_text_color(string $hex): string {
  $hex = ltrim($hex, '#');
  if (strlen($hex)===3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
  $r = hexdec(substr($hex,0,2)); $g = hexdec(substr($hex,2,2)); $b = hexdec(substr($hex,4,2));
  $lum = (0.2126*$r + 0.7152*$g + 0.0722*$b);
  return ($lum > 160) ? '#111' : '#fff';
}

function hex_to_rgb(string $hex): array {
  $h = ltrim($hex,'#');
  if (strlen($h)===3) $h = $h[0].$h[0].$h[1].$h[1].$h[2].$h[2];
  return [hexdec(substr($h,0,2)), hexdec(substr($h,2,2)), hexdec(substr($h,4,2))];
}
function rgba(string $hex, float $alpha): string {
  [$r,$g,$b] = hex_to_rgb($hex);
  $a = max(0,min(1,$alpha));
  return "rgba($r,$g,$b,$a)";
}


// Conexão
$pdo = null;
try{
  $pdo = new PDO(
    "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
    DB_USER, DB_PASS ?? '',
    [ PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC ]
  );
}catch(Throwable){ /* silencioso */ }

// ===== Empresa do usuário (obrigatória) =====
$userId = (int)($_SESSION['user_id'] ?? 0);
$companyId = null;
try{
  $st = $pdo->prepare("
    SELECT c.id_company
    FROM usuarios u
    LEFT JOIN company c ON c.id_company = u.id_company
    WHERE u.id_user = :uid
    LIMIT 1
  ");
  $st->execute([':uid'=>$userId]);
  $row = $st->fetch();
  if ($row && !empty($row['id_company'])) $companyId = (int)$row['id_company'];
}catch(Throwable){ /* noop */ }
if (!$companyId) { header('Location: /OKR_system/organizacao'); exit; }
$_SESSION['company_id'] = $companyId;

/* ===================== Endpoints AJAX de ligações (antes de qualquer saída) ===================== */
function json_out($arr){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($arr); exit; }

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'])) {
  if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    json_out(['ok'=>false,'msg'=>'CSRF inválido']);
  }
  if (!$pdo) json_out(['ok'=>false,'msg'=>'Sem conexão']);
  $action = $_POST['action'];

  try {
    if ($action==='create_link') {
      $src = (int)($_POST['src'] ?? 0);
      $dst = (int)($_POST['dst'] ?? 0);
      // novo: justificativa obrigatória, limitada
      $just = trim((string)($_POST['justificativa'] ?? ''));
      $just = mb_substr($just, 0, 2000, 'UTF-8'); // limite de segurança

      if ($src<=0 || $dst<=0 || $src===$dst) json_out(['ok'=>false,'msg'=>'Par inválido']);
      if ($just==='') json_out(['ok'=>false,'msg'=>'Informe a justificativa da ligação']);

      // valida se ambos objetivos pertencem à empresa
      $chk = $pdo->prepare("SELECT COUNT(*) FROM objetivos WHERE id_objetivo IN (:s,:d) AND id_company=:c");
      $chk->execute([':s'=>$src,':d'=>$dst,':c'=>$companyId]);
      if ((int)$chk->fetchColumn()!==2) json_out(['ok'=>false,'msg'=>'Objetivo fora da empresa']);

      $pdo->beginTransaction();

      // já existe? se inativo, reativa E atualiza a justificativa
      $sel = $pdo->prepare("SELECT id_link, ativo FROM objetivo_links WHERE id_company=:c AND id_src=:s AND id_dst=:d LIMIT 1");
      $sel->execute([':c'=>$companyId,':s'=>$src,':d'=>$dst]);
      $row = $sel->fetch();

      if ($row) {
        if ((int)$row['ativo']===0) {
          $upd = $pdo->prepare("
            UPDATE objetivo_links
              SET ativo=1, justificativa=:j, atualizado_em=NOW()
            WHERE id_link=:id
          ");
          $upd->execute([':j'=>$just, ':id'=>$row['id_link']]);
          $pdo->commit();
          json_out(['ok'=>true,'id_link'=>(int)$row['id_link'],'reactivated'=>true,'justificativa'=>$just]);
        } else {
          $pdo->rollBack();
          json_out(['ok'=>false,'msg'=>'Ligação já existe']);
        }
      } else {
        $ins = $pdo->prepare("
          INSERT INTO objetivo_links (id_company,id_src,id_dst,justificativa,ativo,criado_por)
          VALUES (:c,:s,:d,:j,1,:u)
        ");
        $ins->execute([':c'=>$companyId,':s'=>$src,':d'=>$dst,':j'=>$just,':u'=>$userId]);
        $id = (int)$pdo->lastInsertId();
        $pdo->commit();
        json_out(['ok'=>true,'id_link'=>$id,'justificativa'=>$just]);
      }
    }

    if ($action==='toggle_active') {
      $id = (int)($_POST['id_link'] ?? 0);
      $to = (int)($_POST['ativo'] ?? 0) ? 1 : 0;
      $upd = $pdo->prepare("UPDATE objetivo_links SET ativo=:a, atualizado_em=NOW() WHERE id_link=:id AND id_company=:c");
      $upd->execute([':a'=>$to, ':id'=>$id, ':c'=>$companyId]);
      json_out(['ok'=>true]);
    }

    if ($action==='delete_link') {
      $id = (int)($_POST['id_link'] ?? 0);
      $del = $pdo->prepare("DELETE FROM objetivo_links WHERE id_link=:id AND id_company=:c");
      $del->execute([':id'=>$id, ':c'=>$companyId]);
      json_out(['ok'=>true]);
    }

    json_out(['ok'=>false,'msg'=>'Ação desconhecida']);
  } catch(Throwable $e){
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_out(['ok'=>false,'msg'=>'Erro: '.$e->getMessage()]);
  }
}

/* ============ INJETAR O TEMA (com ?cid=) — somente após possíveis saídas JSON acima ============ */
if (!defined('PB_THEME_LINK_EMITTED')) {
  define('PB_THEME_LINK_EMITTED', true);
  $cid = (int)$_SESSION['company_id'];
  $noc = isset($_GET['nocache']) ? '&nocache=1' : '';
  echo '<link rel="stylesheet" href="/OKR_system/assets/company_theme.php?cid='.$cid.$noc.'">';
}

/* ======================= Dados base ======================= */

// Usuários (somente da mesma company) — id -> primeiro_nome
$usuarios=[];
if ($pdo && table_exists($pdo,'usuarios')){
  $st = $pdo->prepare("SELECT id_user, primeiro_nome FROM usuarios WHERE id_company = :cid");
  $st->execute([':cid'=>$companyId]);
  foreach($st as $r){
    $usuarios[(string)$r['id_user']] = $r['primeiro_nome'] ?: $r['id_user'];
  }
}

// Pilares (domínio — não depende de empresa)
$pilares=[];
if ($pdo && table_exists($pdo,'dom_pilar_bsc')){
  $c=cols($pdo,'dom_pilar_bsc');
  $labelCol = hascol($c,'descricao_exibicao')?'descricao_exibicao':(hascol($c,'descricao')?'descricao':'id_pilar');
  $ordCol   = hascol($c,'ordem_pilar')?'ordem_pilar':(hascol($c,'ordem')?'ordem':null);
  $sql="SELECT id_pilar, `$labelCol` AS titulo FROM dom_pilar_bsc".($ordCol?" ORDER BY `$ordCol`":"");
  $colorMap = [
    'financeiro' => '#f39c12',
    'cliente'    => '#27ae60', 'clientes' => '#27ae60',
    'processos'  => '#2980b9', 'processos internos'=>'#2980b9',
    'aprendizado' => '#8e44ad', 'aprendizado e crescimento'=>'#8e44ad'
  ];
  foreach($pdo->query($sql) as $row){
    $key = slug($row['id_pilar']);
    $pilares[$key] = [
      'titulo' => $row['titulo'],
      'cor'    => $colorMap[$key] ?? '#6c757d'
    ];
  }
}

// Objetivos (somente da company)
$objetivos=[];
if ($pdo && table_exists($pdo,'objetivos')){
  $st = $pdo->prepare("
    SELECT o.id_objetivo, o.descricao, o.pilar_bsc, o.tipo, o.dono, o.status, o.dt_prazo, o.qualidade,
           COALESCE(o.status_aprovacao,'Pendente') AS status_aprovacao, o.dt_criacao
    FROM objetivos o
    WHERE o.id_company = :cid
    ORDER BY o.dt_criacao DESC
  ");
  $st->execute([':cid'=>$companyId]);
  foreach($st as $r){
    $r['pilar'] = slug($r['pilar_bsc']);
    $objetivos[] = $r;
  }
}

// KRs (somente KRs de objetivos da company)
$krs=[];           // id_kr => ['id_objetivo'=>..,'status'=>..]
$krPorObj=[];      // id_objetivo => [id_kr,...]
if ($pdo && table_exists($pdo,'key_results')){
  $st = $pdo->prepare("
    SELECT kr.id_kr, kr.id_objetivo, kr.status
    FROM key_results kr
    JOIN objetivos o ON o.id_objetivo = kr.id_objetivo
    WHERE o.id_company = :cid
  ");
  $st->execute([':cid'=>$companyId]);
  foreach($st as $r){
    $krs[$r['id_kr']] = ['id_objetivo'=>$r['id_objetivo'], 'status'=>mb_strtolower((string)$r['status'])];
    $krPorObj[$r['id_objetivo']][] = $r['id_kr'];
  }
}

// Status KR agregado -> status do objetivo (lógica simples)
function statusObjetivoAgregado(int $id_obj, array $krPorObj, array $krs): string {
  $lista = $krPorObj[$id_obj] ?? [];
  if (!$lista) return 'não iniciado';
  $tot = count($lista);
  $con = 0; $and = 0;
  foreach($lista as $idkr){
    $s = $krs[$idkr]['status'] ?? '';
    if ($s==='concluido') $con++;
    if ($s==='em andamento' || $s==='andamento') $and++;
  }
  if ($tot>0 && $con===$tot) return 'concluído';
  if ($and>0 || $con>0) return 'em andamento';
  return 'não iniciado';
}

/* ======================= Métricas ======================= */
$metrics=[]; // id_objetivo => ['qtd_kr'=>int,'progresso'=>float,'krs_sem_apont_mes'=>int]

$msTable=null; foreach(['milestones_kr','milestones'] as $t) if($pdo && table_exists($pdo,$t)) { $msTable=$t; break; }
if ($pdo && $msTable){
  $mc = cols($pdo,$msTable);
  $COL_EXP = null; foreach(['valor_esperado','esperado','target','meta'] as $c) if(hascol($mc,$c)){$COL_EXP=$c; break;}
  $COL_REAL = null;
  foreach (['valor_real_consolidado','valor_real','realizado','resultado','alcancado'] as $c) {
    if (hascol($mc,$c)) { $COL_REAL=$c; break; }
  }
  $COL_ORD = null;
  foreach (['data_ref','num_ordem','dt_prevista','data_prevista','data','dt','competencia'] as $c) {
    if (hascol($mc,$c)) { $COL_ORD=$c; break; }
  }


  if ($COL_EXP && $COL_ORD){
    $ordAsc  = "`$COL_ORD` ASC";
    $ordDesc = "`$COL_ORD` DESC";
    $EXP = "`$COL_EXP`";
    $REAL= $COL_REAL ? "`$COL_REAL`" : "NULL";

    $SUB_BASE = "(SELECT $EXP FROM `$msTable` mmb WHERE mmb.id_kr=kr.id_kr ORDER BY $ordAsc  LIMIT 1)";
    $SUB_META = "(SELECT $EXP FROM `$msTable` mme WHERE mme.id_kr=kr.id_kr ORDER BY $ordDesc LIMIT 1)";
    $SUB_REAL = "(SELECT $REAL FROM `$msTable` mmu WHERE mmu.id_kr=kr.id_kr AND $REAL IS NOT NULL ORDER BY $ordDesc LIMIT 1)";

    // Só KRs da company
    // status a desconsiderar no progresso
    $EXC = " AND (kr.status IS NULL OR LOWER(kr.status) NOT IN (
      'não iniciado','nao iniciado','nao-iniciado','não-iniciado',
      'cancelado','cancelada','cancelled'
    ))";

    $sqlCountKR = "
      SELECT kr.id_objetivo, COUNT(*) AS qtd_kr
      FROM key_results kr
      JOIN objetivos o ON o.id_objetivo = kr.id_objetivo
      WHERE o.id_company = :cid
      GROUP BY kr.id_objetivo
    ";

    $sqlProgKR  = "
      SELECT kr.id_objetivo,
        CASE WHEN (($SUB_META) - ($SUB_BASE)) <> 0 THEN
          LEAST(100, GREATEST(0,
            ROUND( ( (COALESCE(($SUB_REAL), ($SUB_BASE)) - ($SUB_BASE)) / (($SUB_META) - ($SUB_BASE)) ) * 100, 1)
          ))
        ELSE 0 END AS progresso_kr
      FROM key_results kr
      JOIN objetivos o ON o.id_objetivo = kr.id_objetivo
      WHERE o.id_company = :cid
      $EXC
    ";

    $sqlSum = "
      SELECT o.id_objetivo,
            COALESCE(c.qtd_kr,0) AS qtd_kr,
            avgp.progresso        AS progresso,         -- pode ser NULL quando todos os KRs foram desconsiderados
            COALESCE(avgp.qtd_considerados,0) AS qtd_considerados
      FROM objetivos o
      LEFT JOIN ($sqlCountKR) c ON c.id_objetivo=o.id_objetivo
      LEFT JOIN (
        SELECT id_objetivo,
              AVG(progresso_kr) AS progresso,
              COUNT(*)          AS qtd_considerados
        FROM ($sqlProgKR) t
        GROUP BY id_objetivo
      ) avgp ON avgp.id_objetivo=o.id_objetivo
      WHERE o.id_company = :cid
    ";
    $st = $pdo->prepare($sqlSum);
    $st->execute([':cid'=>$companyId]);
    foreach($st as $row){
      $metrics[$row['id_objetivo']] = [
        'qtd_kr'           => (int)$row['qtd_kr'],
        'progresso'        => isset($row['progresso']) ? (float)$row['progresso'] : null, // deixa NULL quando não há KR considerado
        'krs_sem_apont_mes'=> 0,
        'qtd_considerados' => (int)$row['qtd_considerados'],
      ];
    }
  } else {
    // Sem milestones utilizáveis: ao menos conta KR
    foreach($krPorObj as $ido=>$arr){
      $metrics[$ido] = ['qtd_kr'=>count($arr),'progresso'=>0.0,'krs_sem_apont_mes'=>0];
    }
  }
}

/* ======================= Faróis (KR e Objetivo) ======================= */
$farolKR = [];          // id_kr => cinza|verde|amarelo|vermelho
$farolObj = [];         // id_objetivo => cinza|verde|amarelo|vermelho

if ($pdo && $msTable){
  $mc = cols($pdo, $msTable);
  $COL_EXP     = null; foreach(['valor_esperado','esperado','target','meta'] as $c) if(hascol($mc,$c)){$COL_EXP=$c;break;}
  $COL_REAL = null;
  foreach (['valor_real_consolidado','valor_real','realizado','resultado','alcancado'] as $c) {
    if (hascol($mc,$c)) { $COL_REAL=$c; break; }
  }
  $COL_ORD = null;
  foreach (['data_ref','num_ordem','dt_prevista','data_prevista','data','dt','competencia'] as $c) {
    if (hascol($mc,$c)) { $COL_ORD=$c; break; }
  }
  $COL_EXP_MIN = null; foreach(['valor_esperado_min','esperado_min','minimo'] as $c) if(hascol($mc,$c)){$COL_EXP_MIN=$c;break;}
  $COL_EXP_MAX = null; foreach(['valor_esperado_max','esperado_max','maximo'] as $c) if(hascol($mc,$c)){$COL_EXP_MAX=$c;break;}

  $kc = cols($pdo,'key_results');
  $COL_DIR = null; foreach(['direcao_metrica','direcao','direction'] as $c) if(in_array($c,$kc,true)){$COL_DIR=$c;break;}

  if ($COL_EXP && $COL_ORD){
    $ordDesc = "`$COL_ORD` DESC";
    $EXP     = "`$COL_EXP`";
    $REAL    = $COL_REAL ? "`$COL_REAL`" : "NULL";
    $MIN     = $COL_EXP_MIN ? "`$COL_EXP_MIN`" : "NULL";
    $MAX     = $COL_EXP_MAX ? "`$COL_EXP_MAX`" : "NULL";
    $LAST_WH = "mm.id_kr=kr.id_kr AND mm.`$COL_ORD`<=CURDATE()";

    $SUB_ESP = "(SELECT $EXP  FROM `$msTable` mm WHERE $LAST_WH ORDER BY $ordDesc LIMIT 1)";
    $SUB_REAL= "(SELECT $REAL FROM `$msTable` mm WHERE $LAST_WH ORDER BY $ordDesc LIMIT 1)";
    $SUB_MIN = "(SELECT $MIN  FROM `$msTable` mm WHERE $LAST_WH ORDER BY $ordDesc LIMIT 1)";
    $SUB_MAX = "(SELECT $MAX  FROM `$msTable` mm WHERE $LAST_WH ORDER BY $ordDesc LIMIT 1)";

    $sql = "
      SELECT kr.id_kr, kr.id_objetivo,
             ".($COL_DIR ? "LOWER(kr.`$COL_DIR`)" : "NULL")." AS dir,
             $SUB_REAL AS v_real, $SUB_ESP AS v_esp,
             $SUB_MIN  AS v_min,  $SUB_MAX AS v_max
      FROM key_results kr
      JOIN objetivos o ON o.id_objetivo=kr.id_objetivo
      WHERE o.id_company=:cid
    ";
    $st = $pdo->prepare($sql);
    $st->execute([':cid'=>$companyId]);

    while($r = $st->fetch()){
      $idkr = (string)$r['id_kr']; $idobj = (int)$r['id_objetivo'];
      $dir  = $r['dir'] ?: 'maior'; // fallback
      $real = is_null($r['v_real']) ? null : (float)$r['v_real'];
      $esp  = is_null($r['v_esp'])  ? null : (float)$r['v_esp'];
      $vmin = is_null($r['v_min'])  ? null : (float)$r['v_min'];
      $vmax = is_null($r['v_max'])  ? null : (float)$r['v_max'];

      // Sem milestone <= hoje
      if (is_null($esp) && is_null($vmin) && is_null($vmax)){
        $farol = 'cinza';
      } else if (is_null($real)){
        // Sem apontamento até o último milestone válido => crítico
        $farol = 'vermelho';
      } else if (!is_null($vmin) && !is_null($vmax)){
        // Intervalo ideal
        if ($real >= $vmin && $real <= $vmax) $farol = 'verde';
        else {
          $margem = max(0.0, ($vmax - $vmin) * 0.05); // 5% de tolerância = amarelo
          if ($real >= ($vmin-$margem) && $real <= ($vmax+$margem)) $farol='amarelo';
          else $farol='vermelho';
        }
      } else {
        // Direção maior/menor (fallback = maior)
        if ($dir==='menor'){
          if ($real <= $esp) $farol='verde';
          else if ($real <= $esp*1.10) $farol='amarelo';
          else $farol='vermelho';
        } else {
          if ($real >= $esp) $farol='verde';
          else if ($real >= $esp*0.90) $farol='amarelo';
          else $farol='vermelho';
        }
      }

      $farolKR[$idkr] = $farol;

      // Agrega no objetivo
      $curr = $farolObj[$idobj] ?? null;
      if ($curr === 'vermelho') { /* mantém vermelho */ }
      else if ($farol === 'vermelho') $farolObj[$idobj] = 'vermelho';
      else if ($farol === 'amarelo')  $farolObj[$idobj] = ($curr==='vermelho'?'vermelho':'amarelo');
      else if ($farol === 'verde')    $farolObj[$idobj] = ($curr?:'verde');
      else if (!$curr)                $farolObj[$idobj] = 'cinza';
    }

    // Objetivos sem KRs => cinza
    // Objetivos sem agregação calculada => cinza
    foreach ($objetivos as $o){
      $ido=(int)$o['id_objetivo'];
      if (!isset($farolObj[$ido])) {
        $farolObj[$ido] = 'cinza';
      }
    }
  }
}


// ====== Carregar ligações desta empresa ======
$links = [];
if ($pdo && table_exists($pdo,'objetivo_links')) {
  $st = $pdo->prepare("SELECT id_link, id_src, id_dst, ativo, justificativa FROM objetivo_links WHERE id_company=:c");
  $st->execute([':c'=>$companyId]);
  foreach($st as $r){
    $links[] = [
      'id_link'=>(int)$r['id_link'],
      'src'    =>(int)$r['id_src'],
      'dst'    =>(int)$r['id_dst'],
      'ativo'  =>(int)$r['ativo'],
      'justificativa' => (string)$r['justificativa'],
    ];
  }
}

// Mapa id -> titulo (para mostrar nos modais)
$objTitles = [];
foreach($objetivos as $o){ $objTitles[(int)$o['id_objetivo']] = normalizeText($o['descricao']); }

// Agregados para header
$totalObj = count($objetivos);
$totalKR  = 0; foreach($metrics as $m){ $totalKR += (int)($m['qtd_kr']??0); }
$totalPil = count($pilares);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Mapa Estratégico — OKR System</title>

  <link rel="stylesheet" href="/OKR_system/assets/css/base.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/layout.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/theme.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous"/>

  <style>
    :root{
      --bg-soft:#171b21; --card:var(--bg1, #222222); --muted:#a6adbb; --text:#eaeef6;
      --gold:var(--bg2, #F1C40F); --green:#22c55e; --blue:#60a5fa; --red:#ef4444;
      --border:#222733; --shadow:0 10px 30px rgba(0,0,0,.20); --btn:#0e131a;
      --accent:#0c4a6e; --chat-w:0px;
    }
    body{ background:#fff !important; color:#111; }
    .content{ background:transparent; }
    main.mapa{ padding:24px; display:grid; grid-template-columns:1fr; gap:16px; margin-right:var(--chat-w); transition:margin-right .25s ease; position:relative; }
    .crumbs{ color:#333; font-size:.9rem; display:flex; align-items:center; gap:6px; }
    .crumbs a{ color:var(--accent); text-decoration:none; }
    .crumbs .sep{ opacity:.5; margin:0 2px; }
    .crumbs i{ opacity:.8; }

    .head-card{ background:linear-gradient(180deg, var(--card), #0d1117); border:1px solid var(--border); border-radius:16px; padding:16px; box-shadow:var(--shadow); color:var(--text); position:relative; overflow:hidden; }
    .head-top{ display:flex; align-items:flex-start; justify-content:space-between; gap:12px; flex-wrap:wrap; }
    .head-title{ margin:0; font-size:1.35rem; font-weight:900; letter-spacing:.2px; display:flex; align-items:center; gap:8px; }
    .head-title i{ color:var(--gold); }
    .head-meta{ margin-top:10px; display:flex; gap:10px; flex-wrap:wrap; }

    .pill{ display:inline-flex; align-items:center; gap:8px; background:#0e131a; border:1px solid var(--border); color: var(--muted); padding:6px 10px; border-radius:999px; font-size:.82rem; font-weight:700; }
    .pill i{ font-size:.9rem; opacity:.9; }
    .btn{ border:1px solid var(--border); background:var(--btn); color:#e5e7eb; padding:8px 12px; border-radius:10px; font-weight:800; cursor:pointer; }
    .btn:hover{ transform:translateY(-1px); transition:.15s; }
    .btn-gold{ background:var(--gold); color:#111; border:1px solid rgba(246,195,67,.9); padding:10px 16px; border-radius:12px; font-weight:900; white-space:nowrap; box-shadow:0 6px 20px rgba(246,195,67,.22); }
    .btn-gold:hover{ filter:brightness(.96); transform:translateY(-1px); box-shadow:0 10px 28px rgba(246,195,67,.28); }

    /* ====== Seções do pilar ====== */
    .pilar-row{
      display:grid;
      grid-template-columns: minmax(180px, 240px) 1fr;
      gap:14px;
      align-items:start;
    }
    @media (max-width: 980px){ .pilar-row{ grid-template-columns: 1fr; } }

    :root { --pillar-gap: 35px; }
    section.pilar + section.pilar { margin-top: var(--pillar-gap); }
    main.mapa { gap: 16px; }
    @media (min-width: 1400px){ :root { --pillar-gap: 36px; } }

    .pilar-info{ padding:0; }
    .pilar-info-card{
      background:linear-gradient(180deg, var(--card), #0e1319);
      border:1px solid var(--border);
      border-radius:12px;
      padding:10px;
      box-shadow:var(--shadow);
      color:var(--text);
    }
    .pilar-info-card .pilar-title{
      display:flex; align-items:flex-start; gap:6px;
      font-weight:800; font-size:.92rem; line-height:1.2; color:var(--text);
      white-space:normal; word-break:break-word;
    }
    .pilar-info-card .pilar-title i{ flex:0 0 auto; }
    .pilar-info-card .pilar-badges{ display:flex; gap:6px; align-items:center; flex-wrap:wrap; margin-top:8px; }
    .pilar-info-card .pill{ font-size:.78rem; padding:5px 8px; }
    .pilar-info-card .progress-pill{ font-weight:900; }

    .pilar-wrap{
      background:linear-gradient(180deg, var(--card), #0e1319);
      border:1px solid var(--border);
      border-radius:16px;
      padding:14px;
      box-shadow:var(--shadow);
      color:var(--text);
      position:relative;
    }

    #pillarsContainer { position:relative; }

    .cards-grid{ display:grid; grid-template-columns: repeat(3, minmax(210px,1fr)); gap:12px; }
    @media (min-width: 1400px){ .cards-grid{ grid-template-columns: repeat(4, minmax(200px,1fr)); } }
    @media (max-width: 1200px){ .cards-grid{ grid-template-columns: repeat(2, minmax(200px,1fr)); } }
    @media (max-width: 640px){ .cards-grid{ grid-template-columns: 1fr; } }

    .card{
      background:linear-gradient(180deg, var(--card), #0e1319);
      border:1px solid #7e7e7eff;
      border-radius:14px;
      padding:8px;
      box-shadow:var(--shadow);
      color:#eaeef6;
      position:relative;
      display:grid;
      gap:6px;
      grid-template-rows:auto auto 1fr;
      overflow:visible;        /* não corta o conteúdo interno */
      max-height:none;         /* altura livre para o título crescer */
      grid-template-rows:auto auto auto; /* filas naturais (evita "1fr" esticar) */
      transition:transform .2s ease, box-shadow .2s ease, max-height .25s ease;
    }
    .card:hover{
      transform:translateY(-3px);
      box-shadow:0 12px 28px rgba(0,0,0,.35);
      /* sem max-height aqui */
    }
    .title{
      font-weight:600; letter-spacing:.05px; line-height:1.25;
      font-size:.70rem;
      display:block;                /* sem webkit-box */
      white-space:normal;
      overflow:visible;             /* não esconde o texto */
    }

    .progress{
      position: relative;
      height:6px;
      background: var(--track, rgba(15,23,42,.18));
      border:1px solid var(--track-border, rgba(31,42,68,.35));
      border-radius:5px;
      overflow:hidden;
    }
    .progress .bar{
      position: relative;
      height:100%;
      background: var(--bar, #60a5fa);
      transition: width .35s ease;
      z-index: 1;
    }

    /* ===== Linha abaixo da barra: chips dono + farol ===== */
    .progress-meta{
      display:flex;
      align-items:center;
      gap:8px;
      margin-top:6px;
      flex-wrap:nowrap;       /* mantém na mesma linha */
      white-space:nowrap;
    }
    .progress-meta .badge.owner{
      flex:1 1 auto;          /* ocupa o espaço disponível */
      min-width:0;
      overflow:hidden;
      text-overflow:ellipsis; /* reticências se precisar */
    }
    /* O chip de farol usa as classes de cor já existentes (b-green, b-warn, b-red, b-gray) */
    .badge.farol{ flex:0 0 auto; }

    .more{ display:grid; gap:6px; opacity:.0; max-height:0; transition:max-height .25s ease, opacity .25s ease; }
    .card:hover .more{ opacity:1; max-height:220px; }

    .row{ display:flex; justify-content:space-between; font-size:.8rem; color:#cbd5e1; }
    .badges{ display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
    .badge{ font-size:.72rem; border:1px solid var(--border); padding:3px 6px; border-radius:999px; color:#c9d4e5; }
    .b-type{ border:1px dashed #334155; color:#cbd5e1; }
    .b-green{ background:rgba(34,197,94,.12); border-color:#14532d; color:#a7f3d0; }
    .b-blue{ background:rgba(59,130,246,.12); border-color:#1e3a8a; color:#bfdbfe; }
    .b-gray{ background:rgba(148,163,184,.15); border-color:#334155; color:#cbd5e1; }
    .b-warn{ background:rgba(250,204,21,.16); border-color:#705e14; color:#ffec99; }

    .meta{ font-size:.82rem; color:#cbd5e1; display:grid; gap:2px; }
    .link{ position:absolute; inset:0; text-decoration:none; color:inherit; }

    /* ====== Overlay de ligações (NEON) ====== */
    #linksLayerWrap { position:absolute; inset:0; pointer-events:none; z-index: 9; }
    #linksLayer { position:absolute; inset:0; width:100%; height:100%; overflow:visible; }
    .link-path{
      fill:none;
      stroke-width:1.5;
      stroke-linecap:round;
      stroke-linejoin:round;
      vector-effect:non-scaling-stroke;
      filter:url(#neonGlow);
      stroke-opacity:1;
      mix-blend-mode:normal;
      pointer-events:auto;
    }
    .link-path.inactive{
      stroke-dasharray:6 6;
      opacity:.85;
      filter:url(#neonGlowSoft);
    }
    .link-handle { pointer-events:auto; cursor:pointer; fill:#0e131a; stroke:#888; stroke-width:1.5; }
    .link-handle:hover{ stroke:#fff; filter:url(#neonGlowSoft); }

    body.has-link-mode .card .link{ pointer-events:none; }
    .card.link-src { outline:2px dashed #60a5fa; outline-offset:2px; }
    .card.link-dst { outline:2px dashed #22c55e; outline-offset:2px; }

    .head-actions{ display:flex; flex-direction:column; align-items:flex-end; gap:6px; }
    .link-hint{ display:none; color:#cbd5e1; font-size:.9rem; line-height:1.3; max-width:420px; text-align:right; }

    .just-box{
      white-space:pre-wrap;
      background:#0c1118;
      border:1px dashed #1f2635;
      padding:8px;
      border-radius:10px;
      color:#cbd5e1;
    }


    /* ====== Modal ====== */
    .modal-backdrop{
      position:fixed; inset:0; background:rgba(0,0,0,.55); display:none;
      align-items:center; justify-content:center; z-index:60;
    }
    .modal{
      background:linear-gradient(180deg, var(--card), #0f141c);
      border:1px solid #1f2a44; color:#eaeef6; border-radius:14px; box-shadow:0 24px 60px rgba(0,0,0,.45);
      width:min(520px, 92vw); padding:14px; display:grid; gap:10px;
    }
    .modal-header{ display:flex; align-items:center; justify-content:space-between; gap:10px; }
    .modal-title{ font-weight:900; font-size:1.05rem; display:flex; align-items:center; gap:8px; }
    .modal-body{ font-size:.95rem; color:#d1d5db; line-height:1.45; }
    .modal-actions{ display:flex; align-items:center; justify-content:flex-end; gap:8px; margin-top:6px; }
    .btn-subtle{ background:#0c1118; border:1px solid #1f2635; color:#c9d4e5; padding:8px 12px; border-radius:10px; font-weight:700; cursor:pointer; }
    .btn-primary{ background:#3b82f6; border:1px solid #1e40af; color:#fff; padding:9px 14px; border-radius:10px; font-weight:800; cursor:pointer; }
    .btn-danger{ background:#ef4444; border:1px solid #7f1d1d; color:#fff; padding:9px 14px; border-radius:10px; font-weight:800; cursor:pointer; }
    .btn-subtle:hover, .btn-primary:hover, .btn-danger:hover{ transform:translateY(-1px); }

    /* Toast */
    .toast{
      position:fixed; right:18px; bottom:18px; background:#0c1118; color:#eaeef6; border:1px solid #1f2635;
      border-radius:12px; padding:10px 12px; font-weight:700; box-shadow:var(--shadow); display:none; z-index:70;
    }
    .toast.ok{ border-color:#14532d; background:rgba(34,197,94,.12); color:#a7f3d0; }
    .toast.err{ border-color:#7f1d1d; background:rgba(239,68,68,.12); color:#fecaca; }

    .b-red{ background:rgba(239,68,68,.12); border-color:#7f1d1d; color:#fecaca; }
  </style>
</head>
<body>
  <?php include __DIR__ . '/partials/sidebar.php'; ?>
  <div class="content">
    <?php include __DIR__ . '/partials/header.php'; ?>

    <main class="mapa">
      <!-- Breadcrumb -->
      <div class="crumbs">
        <i class="fa-solid fa-route"></i>
        <a href="/OKR_system/dashboard"><i class="fa-solid fa-house"></i> Dashboard</a>
        <span class="sep">/</span>
        <span><i class="fa-solid fa-sitemap"></i> Mapa Estratégico</span>
      </div>

      <!-- Cabeçalho -->
      <section class="head-card">
        <div class="head-top">
          <h1 class="head-title"><i class="fa-solid fa-sitemap"></i> Mapa Estratégico</h1>

        <!-- AÇÕES DO CABEÇALHO -->
          <div class="head-actions">
            <button id="btnLinkMode" class="btn">
              <i class="fa-solid fa-share-nodes"></i> Ligar objetivos
            </button>
            <div id="linkHint" class="link-hint">
              Clique no objetivo de <strong>origem</strong> e depois no objetivo de <strong>destino</strong>.
            </div>
          </div>
        </div>
        <div class="head-meta">
          <span class="pill"><i class="fa-solid fa-layer-group"></i> Perspectivas: <?= (int)$totalPil ?></span>
          <span class="pill"><i class="fa-solid fa-bullseye"></i> Objetivos: <?= (int)$totalObj ?></span>
          <span class="pill"><i class="fa-solid fa-list-check"></i> Key Results: <?= (int)$totalKR ?></span>
        </div>
      </section>

      <?php
      $iconMap = [
        'financeiro' => 'fa-solid fa-coins',
        'cliente' => 'fa-solid fa-users',
        'clientes' => 'fa-solid fa-users',
        'processos' => 'fa-solid fa-gears',
        'processos internos' => 'fa-solid fa-gears',
        'aprendizado' => 'fa-solid fa-graduation-cap',
        'aprendizado e crescimento' => 'fa-solid fa-graduation-cap',
      ];
      ?>

      <div id="pillarsContainer">
        <?php
        foreach ($pilares as $pillKey => $info):
          $objs = array_values(array_filter($objetivos, fn($o)=> slug($o['pilar']) === $pillKey));
          $acc = 0; $n = 0;
          foreach ($objs as $o) {
            $m = $metrics[$o['id_objetivo']] ?? null;
            if (!$m) continue;
            // só considera objetivos que tenham pelo menos 1 KR considerado
            if (!empty($m['qtd_considerados']) && $m['progresso'] !== null) {
              $acc += (float)$m['progresso'];
              $n++;
            }
          }
          $pilarProg = $n ? round($acc / $n, 1) : 0.0;

          $iconClass = $iconMap[$pillKey] ?? 'fa-solid fa-layer-group';
          $chipFg = pill_text_color($info['cor']);
        ?>
        <section class="pilar">
          <div class="pilar-row">
            <aside class="pilar-info" aria-label="Pilar <?= h($info['titulo']) ?>">
              <div class="pilar-info-card" style="border-left:6px solid <?= h($info['cor']) ?>;">
                <div class="pilar-title">
                  <i class="<?= h($iconClass) ?>" style="color: <?= h($info['cor']) ?>;"></i>
                  <span><?= h(mb_strtolower($info['titulo'],'UTF-8')) ?></span>
                </div>
                <div class="pilar-badges">
                  <span class="pill progress-pill"
                        style="background: <?= h($info['cor']) ?>; border-color: <?= h($info['cor']) ?>; color: <?= h($chipFg) ?>;">
                    <?= number_format($pilarProg,1,',','.') ?>%
                  </span>
                </div>
                <div class="pilar-badges">
                  <span class="pill"><i class="fa-solid fa-bullseye"></i> Objetivos: <?= count($objs) ?></span>
                </div>
              </div>
            </aside>

            <div class="pilar-wrap" style="border-left:6px solid <?= h($info['cor']) ?>;">
              <div class="cards-grid" data-cards="<?= h($pillKey) ?>">
                <?php if (!$objs): ?>
                  <div class="pill" style="grid-column:1 / -1"><i class="fa-regular fa-folder-open"></i> Nenhum objetivo neste pilar.</div>
                <?php else: foreach($objs as $obj):
                  $m = $metrics[$obj['id_objetivo']] ?? ['qtd_kr'=>0,'progresso'=>0,'krs_sem_apont_mes'=>0];
                  $prog = min(100, (float)$m['progresso']);
                  $status = statusObjetivoAgregado((int)$obj['id_objetivo'], $krPorObj, $krs);
                  $statusBadge = $status==='concluído' ? 'b-green' : ($status==='em andamento' ? 'b-blue' : 'b-gray');
                  $detailUrl = "/OKR_system/views/detalhe_okr.php?id=" . urlencode((string)$obj['id_objetivo']);
                  $dono = $usuarios[(string)$obj['dono']] ?? $obj['dono'];

                  $farol = $farolObj[(int)$obj['id_objetivo']] ?? 'cinza';
                  $farolBadge = match($farol){
                    'vermelho' => 'b-red',
                    'amarelo'  => 'b-warn',
                    'verde'    => 'b-green',
                    default    => 'b-gray'
                  };
                   $farolTextMap = [
                    'vermelho' => 'Crítico',
                    'amarelo'  => 'Atenção',
                    'verde'    => 'No trilho',
                    'cinza'    => '-',
                  ];
                  $farolText = $farolTextMap[$farol] ?? '-';
                ?>
                <article
                  id="obj-<?= (int)$obj['id_objetivo'] ?>"
                  class="card"
                  data-obj-id="<?= (int)$obj['id_objetivo'] ?>"
                  data-pilar-color="<?= h($info['cor']) ?>"
                  data-text="<?= h(mb_strtolower($obj['descricao'],'UTF-8')) ?>"
                  style="--bar: <?= h($info['cor']) ?>; --track: <?= h(rgba($info['cor'], .18)) ?>; --track-border: <?= h(rgba($info['cor'], .35)) ?>;">
                  <div class="title"><?= h(normalizeText($obj['descricao'])) ?></div>

                  <div class="progress" title="Progresso do objetivo" style="--pct: <?= (float)$prog ?>%;">
                    <div class="bar" style="width: <?= (float)$prog ?>%"></div>
                  </div>

                  <!-- Chips na mesma linha: Dono + Farol -->
                  <div class="progress-meta">
                    <span class="badge owner"><i class="fa-regular fa-user"></i> <?= h($dono) ?></span>
                    <span class="badge prog-chip">
                      <i class="fa-solid fa-gauge"></i>
                      Prog: <strong class="prog-val"><?= number_format((float)$prog,1,',','.') ?>%</strong>
                    </span>
                    <span class="badge farol <?= $farolBadge ?>"><i class="fa-regular fa-lightbulb"></i> Farol: <?= h(ucfirst($farolText)) ?></span>
                  </div>

                  <div class="more">
                    <div class="badges">
                      <span class="badge b-gray"><i class="fa-solid fa-list-check"></i> KR: <strong><?= (int)$m['qtd_kr'] ?></strong></span>
                      <?php if(!empty($obj['tipo'])): ?><span class="badge b-type"><?= h(normalizeText($obj['tipo'])) ?></span><?php endif; ?>
                      <?php if(!empty($obj['dt_prazo'])):
                        try{ $prazo=(new DateTime($obj['dt_prazo']))->format('d/m/Y'); }catch(Throwable){ $prazo='Data inválida'; }
                        ?><span class="badge b-gray"><i class="fa-regular fa-calendar"></i> <?= h($prazo) ?></span><?php endif; ?>
                    </div>
                  </div>

                  <a class="link" href="<?= h($detailUrl) ?>" title="Abrir objetivo"></a>
                </article>
                <?php endforeach; endif; ?>
              </div>
            </div>
          </div>
        </section>
        <?php endforeach; ?>

        <div id="linksLayerWrap" aria-hidden="true">
          <svg id="linksLayer"></svg>
        </div>
      </div>

      <?php include __DIR__ . '/partials/chat.php'; ?>
    </main>
  </div>

  <!-- ===== Modal reutilizável ===== -->
  <div id="modalBackdrop" class="modal-backdrop" role="dialog" aria-modal="true" aria-hidden="true">
    <div class="modal" role="document">
      <div class="modal-header">
        <div class="modal-title" id="modalTitle"><i class="fa-solid fa-circle-info"></i> Ação</div>
        <button id="modalClose" class="btn-subtle" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <div class="modal-body" id="modalBody">...</div>
      <div class="modal-actions" id="modalActions"></div>
    </div>
  </div>

  <!-- Toast -->
  <div id="toast" class="toast" role="status"></div>

<script>
  function esc(s){ const d=document.createElement('div'); d.textContent=String(s||''); return d.innerHTML; }

  // ===== Busca de objetivos por texto
  const q = document.getElementById('q');
  if (q){
    q.addEventListener('input', ()=>{
      const term = (q.value||'').toLowerCase().trim();
      document.querySelectorAll('.cards-grid .card').forEach(card=>{
        const txt = (card.getAttribute('data-text')||'').toLowerCase();
        card.style.display = term && !txt.includes(term) ? 'none' : '';
      });
      setTimeout(drawLinks, 60);
    });
  }

  // ===== Ajuste com chat lateral
  const CHAT_SELECTORS=['#chatPanel','.chat-panel','.chat-container','#chat','.drawer-chat'];
  const TOGGLE_SELECTORS=['#chatToggle','.chat-toggle','.btn-chat-toggle','.chat-icon','.chat-open'];
  function findChatEl(){ for(const s of CHAT_SELECTORS){ const el=document.querySelector(s); if(el) return el; } return null; }
  function isOpen(el){ const st=getComputedStyle(el); const vis=st.display!=='none'&&st.visibility!=='hidden'; const w=el.offsetWidth; return (vis&&w>0)||el.classList.contains('open')||el.classList.contains('show'); }
  function updateChatWidth(){ const el=findChatEl(); const w=(el && isOpen(el))?el.offsetWidth:0; document.documentElement.style.setProperty('--chat-w',(w||0)+'px'); }
  function setupChatObservers(){
    const chat=findChatEl(); if(!chat) return;
    const mo=new MutationObserver(()=>{ updateChatWidth(); drawLinks(); });
    mo.observe(chat,{attributes:true,attributeFilter:['style','class','aria-expanded']});
    window.addEventListener('resize',()=>{ updateChatWidth(); drawLinks(); });
    TOGGLE_SELECTORS.forEach(s=>document.querySelectorAll(s).forEach(btn=>btn.addEventListener('click',()=>setTimeout(()=>{ updateChatWidth(); drawLinks(); },200))));
    updateChatWidth();
  }
  document.addEventListener('DOMContentLoaded', ()=>{ setupChatObservers(); });

  // ===== Dados do backend
  window.__links = <?= json_encode($links, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
  window.__objTitles = <?= json_encode($objTitles, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;

  // ===== Overlay / desenho de ligações (NEON)
  const pillarsContainer = document.getElementById('pillarsContainer');
  const svg = document.getElementById('linksLayer');

  function ensureMarkers(){
    if (svg.querySelector('#neonGlow')) return;
    const defs = document.createElementNS('http://www.w3.org/2000/svg','defs');
    defs.innerHTML = `
      <filter id="neonGlow" filterUnits="userSpaceOnUse" x="-50%" y="-50%" width="200%" height="200%" color-interpolation-filters="sRGB">
        <feGaussianBlur in="SourceGraphic" stdDeviation="2.5" result="b1"/>
        <feGaussianBlur in="SourceGraphic" stdDeviation="5"   result="b2"/>
        <feMerge>
          <feMergeNode in="b2"/>
          <feMergeNode in="b1"/>
          <feMergeNode in="SourceGraphic"/>
        </feMerge>
      </filter>
      <filter id="neonGlowSoft" filterUnits="userSpaceOnUse" x="-50%" y="-50%" width="200%" height="200%" color-interpolation-filters="sRGB">
        <feGaussianBlur in="SourceGraphic" stdDeviation="2" result="b"/>
        <feMerge>
          <feMergeNode in="b"/>
          <feMergeNode in="SourceGraphic"/>
        </feMerge>
      </filter>
    `;
    svg.appendChild(defs);
  }

  function ensureDefs(){
    let d = svg.querySelector('defs');
    if(!d){ d = document.createElementNS(svg.namespaceURI,'defs'); svg.appendChild(d); }
    return d;
  }

  function getMarkerForColor(color){
    const safe = String(color).replace(/[^a-zA-Z0-9]/g,'_');
    const id = 'arrowHead_' + safe;
    let m = svg.querySelector('#'+id);
    if (!m){
      const defs = ensureDefs();
      m = document.createElementNS(svg.namespaceURI,'marker');
      m.setAttribute('id', id);
      m.setAttribute('viewBox','0 0 10 10');
      m.setAttribute('refX','10');
      m.setAttribute('refY','5');
      m.setAttribute('markerWidth','9');
      m.setAttribute('markerHeight','9');
      m.setAttribute('orient','auto-start-reverse');

      const p = document.createElementNS(svg.namespaceURI,'path');
      p.setAttribute('d','M0 0 L10 5 L0 10 Z');
      p.setAttribute('fill', color);
      p.setAttribute('stroke', color);
      p.setAttribute('filter','url(#neonGlow)');

      m.appendChild(p);
      defs.appendChild(m);
    }
    return 'url(#'+id+')';
  }

  function syncLayerSize(){
    const r = pillarsContainer.getBoundingClientRect();
    svg.setAttribute('width', r.width);
    svg.setAttribute('height', pillarsContainer.scrollHeight);
    svg.style.width = r.width+'px';
    svg.style.height = pillarsContainer.scrollHeight+'px';
  }

  function getOffset(el){
    const base = pillarsContainer.getBoundingClientRect();
    const r = el.getBoundingClientRect();
    const top = (r.top - base.top) + pillarsContainer.scrollTop;
    const left = (r.left - base.left) + pillarsContainer.scrollLeft;
    return { top, left, width:r.width, height:r.height };
  }

  function anchorPoint(el, where='center'){
    const rect = getOffset(el);
    const cx = rect.left + rect.width/2;
    const cy = rect.top  + rect.height/2;
    if (where === 'top')    return { x: cx, y: rect.top - 2 };
    if (where === 'bottom') return { x: cx, y: rect.top + rect.height + 2 };
    if (where === 'left')   return { x: rect.left, y: cy };
    if (where === 'right')  return { x: rect.left + rect.width, y: cy };
    return { x: cx, y: cy };
  }

  function bezierAnchored(p1, p2, a1, a2){
    const vy = p2.y - p1.y;
    const dy = Math.max(40, Math.abs(vy) * 0.35);
    const c1 = { x: p1.x, y: (a1==='top') ? (p1.y - dy) : (p1.y + dy) };
    const c2 = { x: p2.x, y: (a2==='top') ? (p2.y - dy) : (p2.y + dy) };
    return `M ${p1.x},${p1.y} C ${c1.x},${c1.y} ${c2.x},${c2.y} ${p2.x},${p2.y}`;
  }

  function samePillar(cardA, cardB){
    const gA = cardA.closest('.cards-grid');
    const gB = cardB.closest('.cards-grid');
    if (!gA || !gB) return false;
    return gA === gB || (gA.dataset.cards && gA.dataset.cards === gB.dataset.cards);
  }

  function drawLinks(){
    syncLayerSize();
    ensureMarkers();
    Array.from(svg.querySelectorAll('.link-path, .link-handle')).forEach(n=>n.remove());

    const getCard = (id)=> document.getElementById('obj-'+id);

    (window.__links||[]).forEach(link=>{
      const srcEl = getCard(link.src);
      const dstEl = getCard(link.dst);
      if (!srcEl || !dstEl) return;
      if (srcEl.style.display==='none' || dstEl.style.display==='none') return;

      let aStart = 'top', aEnd = 'top';
      if (!samePillar(srcEl, dstEl)){
        const r1 = getOffset(srcEl);
        const r2 = getOffset(dstEl);
        const srcIsAbove = r1.top <= r2.top;
        if (srcIsAbove){
          aStart = 'bottom'; aEnd = 'top';
        } else {
          aStart = 'top'; aEnd = 'bottom';
        }
      }

      const p1 = anchorPoint(srcEl, aStart);
      const p2 = anchorPoint(dstEl, aEnd);
      const d  = bezierAnchored(p1, p2, aStart, aEnd);

      const color = srcEl.getAttribute('data-pilar-color') || '#60a5fa';

      const path = document.createElementNS('http://www.w3.org/2000/svg','path');
      path.setAttribute('d', d);
      path.setAttribute('class', 'link-path' + (link.ativo ? '' : ' inactive'));
      path.setAttribute('stroke', color);
      path.setAttribute('marker-end', getMarkerForColor(color));
      path.dataset.linkId = String(link.id_link);
      path.dataset.ativo  = String(link.ativo ?? 1);
      path.addEventListener('click', (ev)=>{ ev.stopPropagation(); showLinkActions(link); });
      svg.appendChild(path);

      const mid = { x:(p1.x+p2.x)/2, y:(p1.y+p2.y)/2 };
      const handle = document.createElementNS('http://www.w3.org/2000/svg','circle');
      handle.setAttribute('cx', mid.x);
      handle.setAttribute('cy', mid.y);
      handle.setAttribute('r', 7);
      handle.setAttribute('class','link-handle');
      handle.dataset.linkId = link.id_link;
      handle.addEventListener('click', (ev)=>{ ev.stopPropagation(); showLinkActions(link); });
      svg.appendChild(handle);
    });
  }

  // ===== Modal & Toast
  const $backdrop = document.getElementById('modalBackdrop');
  const $modalTitle = document.getElementById('modalTitle');
  const $modalBody  = document.getElementById('modalBody');
  const $modalActions = document.getElementById('modalActions');
  const $modalClose = document.getElementById('modalClose');
  const $toast = document.getElementById('toast');

  function openModal({title, html, actions=[]}){
    $modalTitle.innerHTML = `<i class="fa-solid fa-circle-info"></i> ${title || 'Mensagem'}`;
    $modalBody.innerHTML = html || '';
    $modalActions.innerHTML = '';
    actions.forEach(a=>{
      const btn = document.createElement('button');
      btn.className = a.className || 'btn-subtle';
      btn.textContent = a.label || 'OK';
      btn.addEventListener('click', ()=>{ a.onClick && a.onClick(); });
      $modalActions.appendChild(btn);
    });
    $backdrop.style.display = 'flex';
    $backdrop.setAttribute('aria-hidden','false');
  }
  function closeModal(){
    $backdrop.style.display = 'none';
    $backdrop.setAttribute('aria-hidden','true');
    $modalActions.innerHTML = '';
  }
  $modalClose.addEventListener('click', closeModal);
  $backdrop.addEventListener('click', (e)=>{ if(e.target === $backdrop) closeModal(); });

  function toast(msg, ok=true){
    $toast.textContent = msg;
    $toast.className = 'toast ' + (ok ? 'ok' : 'err');
    $toast.style.display = 'block';
    setTimeout(()=>{ $toast.style.display = 'none'; }, 2200);
  }

  // ===== CRUD helpers
  function postForm(data){
    return fetch(location.href, {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams(data)
    }).then(r=>r.json());
  }

  function toggleLink(id, ativo){
    postForm({action:'toggle_active', id_link:id, ativo: ativo?1:0, csrf_token:'<?= $csrf ?>'})
      .then(res=>{
        if (!res.ok) { toast(res.msg||'Falha ao atualizar ligação', false); return; }
        const it = (window.__links||[]).find(x=>x.id_link===id); if (it){ it.ativo = ativo?1:0; }
        drawLinks();
        toast(ativo ? 'Ligação reativada' : 'Ligação inativada', true);
      }).catch(()=>toast('Erro ao atualizar ligação', false))
      .finally(closeModal);
  }

  function deleteLink(id){
    postForm({action:'delete_link', id_link:id, csrf_token:'<?= $csrf ?>'})
      .then(res=>{
        if (!res.ok) { toast(res.msg||'Falha ao excluir ligação', false); return; }
        window.__links = (window.__links||[]).filter(x=>x.id_link!==id);
        drawLinks();
        toast('Ligação excluída', true);
      }).catch(()=>toast('Erro ao excluir ligação', false))
      .finally(closeModal);
  }

  function showLinkActions(link){
    const srcName = (window.__objTitles||{})[link.src] || ('#'+link.src);
    const dstName = (window.__objTitles||{})[link.dst] || ('#'+link.dst);
    const statusLabel = link.ativo ? '<span style="color:#a7f3d0">Ativa</span>' : '<span style="color:#fecaca">Inativa</span>';
    const jus = esc(link.justificativa || '');

    openModal({
      title: 'Gerenciar ligação',
      html: `
        <div style="display:grid; gap:10px;">
          <div><strong>Origem:</strong> ${esc(srcName)}</div>
          <div><strong>Destino:</strong> ${esc(dstName)}</div>
          <div><strong>Status:</strong> ${statusLabel}</div>
          <div><strong>Justificativa:</strong></div>
          <div class="just-box">${jus || '<em style="color:#94a3b8">—</em>'}</div>
          <div style="margin-top:4px; font-size:.9rem; color:#94a3b8;">
            Você pode ativar/inativar ou excluir permanentemente esta ligação.
          </div>
        </div>
      `,
      actions: [
        link.ativo
          ? { label:'Inativar', className:'btn-subtle', onClick:()=>toggleLink(link.id_link, false) }
          : { label:'Reativar', className:'btn-primary', onClick:()=>toggleLink(link.id_link, true) },
        { label:'Excluir', className:'btn-danger', onClick:()=>confirmDelete(link) },
        { label:'Fechar', className:'btn-subtle', onClick:()=>closeModal() },
      ]
    });
  }

  function confirmDelete(link){
    const srcName = (window.__objTitles||{})[link.src] || ('#'+link.src);
    const dstName = (window.__objTitles||{})[link.dst] || ('#'+link.dst);
    openModal({
      title: 'Confirmar exclusão',
      html: `
        <div style="display:grid; gap:8px;">
          <div>Você está prestes a <strong>excluir</strong> a ligação:</div>
          <div style="padding:8px; border:1px dashed #334155; border-radius:10px;">
            <div><strong>Origem:</strong> ${srcName}</div>
            <div><strong>Destino:</strong> ${dstName}</div>
          </div>
          <div style="margin-top:4px; color:#fca5a5;">Esta ação não pode ser desfeita.</div>
        </div>
      `,
      actions: [
        { label:'Excluir definitivamente', className:'btn-danger', onClick:()=>deleteLink(link.id_link) },
        { label:'Cancelar', className:'btn-subtle', onClick:()=>closeModal() },
      ]
    });
  }

  // ===== Modo ligação
  let linkMode = false;
  let srcSel = null;

  const btn = document.getElementById('btnLinkMode');
  const hint = document.getElementById('linkHint');

  function setLinkMode(on){
    linkMode = !!on;
    document.body.classList.toggle('has-link-mode', linkMode);
    btn.classList.toggle('btn-gold', linkMode);
    hint.style.display = linkMode ? 'block' : 'none';
    document.querySelectorAll('.card.link-src,.card.link-dst').forEach(el=>el.classList.remove('link-src','link-dst'));
    srcSel = null;
  }
  btn.addEventListener('click', ()=> setLinkMode(!linkMode));

  function onCardClick(ev){
    if (!linkMode) return;
    ev.preventDefault();
    ev.stopPropagation();

    const card = ev.currentTarget;
    const id = parseInt(card.getAttribute('data-obj-id'),10);
    if (!srcSel){
      srcSel = id;
      card.classList.add('link-src');
    } else {
      const dst = id;
      if (dst===srcSel) return;
      card.classList.add('link-dst');

      // Abre modal pedindo justificativa
      const srcName = (window.__objTitles||{})[srcSel] || ('#'+srcSel);
      const dstName = (window.__objTitles||{})[dst]   || ('#'+dst);

      openModal({
        title: 'Criar ligação',
        html: `
          <div style="display:grid; gap:8px;">
            <div><strong>Origem:</strong> ${esc(srcName)}</div>
            <div><strong>Destino:</strong> ${esc(dstName)}</div>
            <label style="margin-top:6px; font-weight:700;">Justificativa (obrigatória)</label>
            <textarea id="linkJustInput" rows="4" style="width:100%; resize:vertical; padding:8px; border-radius:8px; border:1px solid #1f2635; background:#0c1118; color:#eaeef6;"></textarea>
            <div id="linkJustCount" style="text-align:right; font-size:.85rem; color:#94a3b8;">0 / 2000</div>
          </div>
        `,
        actions: [
          { label:'Cancelar', className:'btn-subtle', onClick:()=>{ closeModal(); setLinkMode(false); } },
          { label:'Criar ligação', className:'btn-primary', onClick:()=>{
              const ta = document.getElementById('linkJustInput');
              const raw = (ta?.value||'').trim();
              if (!raw){ ta?.focus(); return toast('Informe a justificativa', false); }
              const just = raw.length>2000 ? raw.slice(0,2000) : raw;

              postForm({action:'create_link', src:srcSel, dst:dst, justificativa:just, csrf_token:'<?= $csrf ?>'})
                .then(res=>{
                  if (!res.ok) { toast(res.msg||'Falha ao criar ligação', false); }
                  else {
                    // atualiza (se já existia inativa) ou inclui
                    const existing = (window.__links||[]).find(x=>x.src===srcSel && x.dst===dst);
                    if (existing){
                      existing.ativo = 1;
                      existing.id_link = res.id_link || existing.id_link;
                      existing.justificativa = res.justificativa ?? just;
                    } else {
                      (window.__links|| (window.__links=[])).push({
                        id_link: res.id_link,
                        src: srcSel,
                        dst: dst,
                        ativo: 1,
                        justificativa: res.justificativa ?? just
                      });
                    }
                    drawLinks();
                    toast('Ligação criada', true);
                  }
                  closeModal();
                  setLinkMode(false);
                })
                .catch(()=>{ toast('Erro ao criar ligação', false); closeModal(); setLinkMode(false); });
            } }
        ]
      });

      // contador de caracteres
      const ta = document.getElementById('linkJustInput');
      const cnt = document.getElementById('linkJustCount');
      if (ta && cnt){
        const upd=()=>{ cnt.textContent = `${ta.value.length} / 2000`; }
        ta.addEventListener('input', upd); upd();
        ta.focus();
      }
    }
  }

  function bindCardClicks(){
    document.querySelectorAll('.cards-grid .card').forEach(card=>{
      card.removeEventListener('click', onCardClick);
      card.addEventListener('click', onCardClick);
    });
  }

  const pillars = document.getElementById('pillarsContainer');
  const ro = new ResizeObserver(()=>drawLinks());
  ro.observe(pillars);
  window.addEventListener('resize', drawLinks);
  window.addEventListener('scroll', drawLinks, {passive:true});

  function normalizeNoAccents(s){
    return (s||'').normalize('NFD').replace(/[\u0300-\u036f]/g, '');
  }

  function isNotStartedKR(kr){
    const s = normalizeNoAccents(String(kr?.status || '').toLowerCase());
    // cobre “não iniciado”, “nao-iniciado”, “planejado(a)”, “not started)”
    return s.includes('nao iniciado') || s.includes('nao-iniciado') ||
          s.includes('planejad') || s.includes('not started');
  }

  // === mesmo cálculo da barra, mas garantindo que KR "não iniciado" seja ignorado ===
  function computeObjectiveProgress(krs){
    let sA = 0, nA = 0;
    for (const kr of (krs || [])) {
      if (isNotStartedKR(kr)) continue;                 // <-- ignora
      const pa = kr?.progress?.pct_atual;
      if (Number.isFinite(pa)) {
        sA += Math.max(0, Math.min(100, pa));
        nA++;
      }
    }
    const pctA = nA ? Math.round(sA / nA) : null;
    return { pctA };
  }

  function setCardProgressChip(card, pct){
    const el = card.querySelector('.prog-chip .prog-val');
    if (!el) return;
    el.textContent = (pct==null)
      ? '—'
      : (pct.toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + '%');
  }

  function setCardProgress(card, pct){
    const bar = card.querySelector('.progress .bar');
    const a = pct == null ? NaN : Math.max(0, Math.min(100, Number(pct)));
    if (bar) bar.style.width = (Number.isFinite(a) ? a : 0) + '%';

    // guarda o progresso correto no card (para agregação do pilar)
    if (Number.isFinite(a)) {
      card.dataset.prog = a.toFixed(1);   // ex.: "73.2"
    } else {
      delete card.dataset.prog;           // sem KR considerado
    }

    // reprocessa apenas o pilar deste card
    recomputePillarForCard(card);
  }

  function recomputePillar(section){
    const cards = section.querySelectorAll('.cards-grid .card');
    let sum = 0, n = 0;
    cards.forEach(card => {
      const v = parseFloat(card.dataset.prog);
      if (Number.isFinite(v)) { sum += v; n++; }   // só conta objetivos com progresso válido
    });

    const pill = section.querySelector('.pilar-info-card .progress-pill');
    if (pill){
      const pct = n ? (sum / n) : 0;
      pill.textContent = pct.toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + '%';
    }
  }

  function recomputePillarForCard(card){
    const section = card.closest('section.pilar');
    if (section) recomputePillar(section);
  }

  function recomputeAllPillars(){
    document.querySelectorAll('section.pilar').forEach(recomputePillar);
  }


  function setCardFarol(card, farol){
    // badge: <span class="badge farol b-..."> Farol: ...</span>
    const badge = card.querySelector('.badge.farol');
    if (!badge) return;

    // normaliza
    const f = String(farol||'cinza').toLowerCase();
    let cls='b-gray', txt='-';
    if (f==='verde'){ cls='b-green'; txt='No trilho'; }
    else if (f==='amarelo'){ cls='b-warn'; txt='Atenção'; }
    else if (f==='vermelho'){ cls='b-red'; txt='Crítico'; }

    badge.classList.remove('b-green','b-warn','b-red','b-gray');
    badge.classList.add(cls);
    // mantém o ícone, troca apenas o texto após ele
    const icon = badge.querySelector('i')?.outerHTML || '';
    badge.innerHTML = icon + ' Farol: ' + txt;
  }

  async function refreshCardFromAjax(card){
    const id = card.getAttribute('data-obj-id');
    if (!id) return;
    try{
      const url  = `/OKR_system/views/detalhe_okr.php?ajax=load_krs&id_objetivo=${encodeURIComponent(id)}`;
      const resp = await fetch(url, { headers:{'Accept':'application/json'} });
      const data = await resp.json();
      if (data?.success && Array.isArray(data.krs)) {
        const prog = computeObjectiveProgress(data.krs);
        setCardProgress(card, prog.pctA);      // barra
        setCardProgressChip(card, prog.pctA);  // chip "Prog:"
        setCardFarol(card, data.obj_farol);    // farol
      }
    } catch(e){
      console.error('Falha ao carregar dados do objetivo', id, e);
    }
  }


  document.addEventListener('DOMContentLoaded', ()=>{
    bindCardClicks();

    // alinha cálculo com "Meus OKRs"
    document.querySelectorAll('.cards-grid .card').forEach(card=>{
      refreshCardFromAjax(card);
    });

    // passada de segurança (caso algumas respostas AJAX demorem)
      setTimeout(recomputeAllPillars, 600);
  });
</script>
</body>
</html>
