<?php
// views/relatorios_okrs.php — One-page executivo de OKRs (BSC + Ranking + Objetivos + Orçamento)
// Revisão: corrige médias por pilar/objetivo e KPI geral considerando somente KRs iniciados (exclui "não iniciado" / "cancelado"),
// e aplica cor de farol ao chip numérico do objetivo.

declare(strict_types=1);
ini_set('display_errors',1); ini_set('display_startup_errors',1); error_reporting(E_ALL);

/* ===================== ENDPOINT AJAX ===================== */
if (isset($_GET['ajax'])) {
  session_start();
  require_once __DIR__ . '/../auth/config.php';
  require_once __DIR__ . '/../auth/functions.php';
  header('Content-Type: application/json; charset=utf-8');

  if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['success'=>false,'error'=>'Não autorizado']); exit; }

  try{
    $pdo = new PDO(
      "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
      DB_USER, DB_PASS ?? '',
      [ PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC ]
    );
  }catch(Throwable){ http_response_code(500); echo json_encode(['success'=>false,'error'=>'Falha na conexão']); exit; }

  $tableExists = static function(PDO $pdo, string $t): bool { try{ $pdo->query("SHOW COLUMNS FROM `$t`"); return true; }catch(Throwable){ return false; } };
  $colExists   = static function(PDO $pdo, string $t, string $c): bool { try{ $st=$pdo->prepare("SHOW COLUMNS FROM `$t` LIKE :c"); $st->execute([':c'=>$c]); return (bool)$st->fetch(); }catch(Throwable){ return false; } };
  $normP = static function($s){ $s=mb_strtolower(trim((string)$s),'UTF-8'); $s=str_replace(['processos internos','cliente'],['processos','clientes'],$s); return $s; };
  $clamp = static function(?float $v): ?int { if($v===null||!is_finite($v)) return null; return (int)max(0, min(100, round($v))); };
  $noacc = static function(string $s): string {
    $s = iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$s);
    return mb_strtolower(trim(preg_replace('/\s+/',' ',$s) ?? ''),'UTF-8');
  };
  $isKRDesconsiderado = static function(?string $status) use ($noacc): bool {
    if($status===null || $status==='') return false;
    $st = $noacc($status);
    // Conjuntos mais comuns para "não iniciado" e "cancelado"
    $notStarted = ['nao iniciado','não iniciado','nao-iniciado','não-iniciado','not started','planejado','to do','todo','backlog','draft'];
    $cancelled  = ['cancelado','cancelada','cancelled','canceled','abortado','abortada'];
    foreach($notStarted as $n){ if($st===$n) return true; }
    foreach($cancelled as $c){ if($st===$c) return true; }
    // Heurísticas
    if (strpos($st,'cancel')!==false) return true;
    if (strpos($st,'nao inicia')!==false || strpos($st,'não inicia')!==false) return true;
    if (strpos($st,'not start')!==false) return true;
    return false;
  };

  $PILLAR_ORDER  = ['aprendizado','processos','clientes','financeiro'];
  $PILLAR_COLORS = ['aprendizado'=>'#8e44ad','processos'=>'#2980b9','clientes'=>'#27ae60','financeiro'=>'#f39c12'];

  if ($_GET['ajax']==='report') {
    $payload = json_decode(file_get_contents('php://input'), true) ?: [];
    $dtIni = $payload['dt_inicio'] ?? '';
    $dtFim = $payload['dt_fim'] ?? '';
    $pilarF = trim((string)($payload['pilar'] ?? ''));
    $statusF= trim((string)($payload['status'] ?? ''));
    $texto  = trim((string)($payload['q'] ?? ''));
    $donoF  = $payload['dono'] ?? null;

    try{
      $userId=(int)$_SESSION['user_id']; $idCompany=null;
      $st=$pdo->prepare("SELECT id_company FROM usuarios WHERE id_user=:u LIMIT 1");
      $st->execute([':u'=>$userId]); $idCompany=$st->fetchColumn();

      $now = new DateTimeImmutable('now');
      if (!$dtIni) $dtIni = $now->format('Y-01-01');
      if (!$dtFim) $dtFim = $now->format('Y-12-31');

      $parts=[]; $bind=[];
      if ($idCompany!==''){ $parts[]="o.id_company=:c"; $bind[':c']=$idCompany; }
      if ($dtIni){ $parts[]="o.dt_criacao>=:di"; $bind[':di']=$dtIni; }
      if ($dtFim){ $parts[]="o.dt_criacao<=:df"; $bind[':df']=$dtFim; }
      if ($pilarF!==''){ $parts[]="LOWER(o.pilar_bsc)=LOWER(:p)"; $bind[':p']=$pilarF; }
      if ($statusF!==''){ $parts[]="o.status=:s"; $bind[':s']=$statusF; }
      if ($texto!==''){ $parts[]="(o.descricao LIKE :q OR o.observacoes LIKE :q)"; $bind[':q']="%$texto%"; }
      if ($donoF!==null && $donoF!==''){ if(ctype_digit((string)$donoF)){ $parts[]="o.dono=:dn"; $bind[':dn']=(int)$donoF; } else { $parts[]="u.primeiro_nome LIKE :dnn"; $bind[':dnn']="%$donoF%"; } }
      $where = $parts ? ("WHERE ".implode(' AND ',$parts)) : "";

      $stO=$pdo->prepare("
        SELECT o.id_objetivo, o.descricao AS nome, o.pilar_bsc, o.status, o.dono, u.primeiro_nome AS dono_nome, u.ultimo_nome AS dono_sobrenome, o.dt_prazo
        FROM objetivos o
        LEFT JOIN usuarios u ON u.id_user=o.dono
        $where
        ORDER BY o.pilar_bsc, o.descricao
        LIMIT 32
      ");
      $stO->execute($bind);
      $objs=$stO->fetchAll();

      if(!$objs){
        $pilares=[]; foreach($PILLAR_ORDER as $p){ $pilares[]=['pilar'=>$p,'media'=>null,'krs'=>0,'krs_criticos'=>0,'count_obj'=>0,'color'=>$PILLAR_COLORS[$p]]; }
        echo json_encode(['success'=>true,'items'=>[],'kpi'=>['objetivos'=>0,'media'=>null],'pilares'=>$pilares,'rank'=>[],'budget'=>['aprovado'=>0,'realizado'=>0,'saldo'=>0,'series_acc'=>[],'series_acc_plan'=>[]]]); exit;
      }

      $objIds=array_column($objs,'id_objetivo');
      $in=implode(',',array_fill(0,count($objIds),'?'));

      $findCol=function(PDO $pdo,string $table,array $opts){ foreach($opts as $c){ try{$st=$pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :c"); $st->execute([':c'=>$c]); if($st->fetch()) return $c; }catch(Throwable){} } return null; };

      // ===== KR base + campos dinâmicos =====
      $selKRCols = fn($n)=> $colExists($pdo,'key_results',$n) ? "kr.`$n`" : "NULL";
      $prazoParts=[]; foreach(['dt_novo_prazo','data_fim','dt_prazo','data_limite','dt_limite','prazo','deadline'] as $pc){ if($colExists($pdo,'key_results',$pc)) $prazoParts[]="kr.`$pc`"; }
      $prazoExpr=$prazoParts? "COALESCE(".implode(',',$prazoParts).")":"NULL";

      $krStatusCol = $findCol($pdo,'key_results',['status','situacao','state','situacao_kr','status_kr']);

      $stKR=$pdo->prepare("
        SELECT kr.id_kr, kr.id_objetivo,
               COALESCE(kr.descricao,'') AS label,
               {$selKRCols('baseline')} AS baseline,
               {$selKRCols('meta')}     AS meta,
               {$selKRCols('direcao_metrica')} AS direcao_metrica,
               $prazoExpr AS prazo_final,
               ".($krStatusCol ? "kr.`$krStatusCol` AS status_kr" : "NULL AS status_kr")."
        FROM key_results kr
        WHERE kr.id_objetivo IN ($in)
      ");
      $stKR->execute($objIds);
      $krs=$stKR->fetchAll();

      // ===== Milestones / Apontamentos (opcionais) =====
      $msT = $tableExists($pdo,'milestones_kr') ? 'milestones_kr' : ($tableExists($pdo,'milestones')?'milestones':null);
      $apT = $tableExists($pdo,'apontamentos_kr') ? 'apontamentos_kr' : ($tableExists($pdo,'apontamentos')?'apontamentos':null);

      $msKr=$msDate=$msExp=$msReal=$msMin=$msMax=null;
      if($msT){
        $msKr  = $findCol($pdo,$msT,['id_kr','kr_id','id_key_result','key_result_id']);
        $msDate= $findCol($pdo,$msT,['data_ref','dt_prevista','data_prevista','data','dt','competencia']);
        $msExp = $findCol($pdo,$msT,['valor_esperado','esperado','target','meta']);
        $msReal= $findCol($pdo,$msT,['valor_real_consolidado','valor_real','realizado','resultado','alcancado']);
        $msMin = $findCol($pdo,$msT,['valor_esperado_min','esperado_min','minimo']);
        $msMax = $findCol($pdo,$msT,['valor_esperado_max','esperado_max','maximo']);
      }

      $stExp   = ($msT && $msKr && $msDate && $msExp) ? $pdo->prepare("SELECT `$msExp` FROM `$msT` WHERE `$msKr`=:id AND `$msDate`<=CURDATE() ORDER BY `$msDate` DESC LIMIT 1") : null;
      $stReal  = ($msT && $msKr && $msReal)           ? $pdo->prepare("SELECT `$msReal` FROM `$msT` WHERE `$msKr`=:id AND `$msReal` IS NOT NULL AND `$msReal`<>'' ORDER BY ".($msDate? "`$msDate` DESC":"1")." LIMIT 1") : null;
      $stMin   = ($msT && $msKr && $msDate && $msMin) ? $pdo->prepare("SELECT `$msMin`  FROM `$msT` WHERE `$msKr`=:id AND `$msDate`<=CURDATE() ORDER BY `$msDate` DESC LIMIT 1") : null;
      $stMax   = ($msT && $msKr && $msDate && $msMax) ? $pdo->prepare("SELECT `$msMax`  FROM `$msT` WHERE `$msKr`=:id AND `$msDate`<=CURDATE() ORDER BY `$msDate` DESC LIMIT 1") : null;

      // ===== Acumuladores =====
      $byObj=[]; $pAgg=[]; $pCount=[];
      foreach($objs as $o){
        $p = $normP($o['pilar_bsc'] ?: '');
        $byObj[$o['id_objetivo']] = [
          'id'=>(int)$o['id_objetivo'],
          'nome'=>$o['nome'] ?: ('Objetivo '.$o['id_objetivo']),
          'pilar'=>$p ?: '—',
          'status'=>$o['status'] ?: '—',
          'dono_id'=> (int)$o['dono'],
          'dono'=> trim(($o['dono_nome']?:'').' '.($o['dono_sobrenome']?:'')) ?: ($o['dono'] ?? '—'),
          'prazo'=> $o['dt_prazo'] ?: null,
          // agregadores para objetivo
          '__sum'=>0.0,'__cnt'=>0,
          'pct'=>null,
          'krs'=>['verde'=>0,'amarelo'=>0,'vermelho'=>0],
          'kr_total'=>0,'kr_red'=>0,
          'kr_list'=>[]
        ];
        $pCount[$p] = ($pCount[$p] ?? 0) + 1;
        if(!isset($pAgg[$p])) $pAgg[$p]=['sum'=>0.0,'cnt'=>0,'krs'=>0,'krs_crit'=>0];
      }

      $totalKrs=0; $totalKrsCrit=0;
      $allKRsum=0.0; $allKRcnt=0;

      foreach($krs as $r){
        $oid=(int)$r['id_objetivo']; if(!isset($byObj[$oid])) continue;

        // ===== Esperado/Real/Min/Max no marco atual =====
        $expNow=$realNow=$vmin=$vmax=null;
        if($stExp){  $stExp->execute([':id'=>$r['id_kr']]);  $expNow = $stExp->fetchColumn(); }
        if($stReal){ $stReal->execute([':id'=>$r['id_kr']]); $realNow = $stReal->fetchColumn(); }
        if($stMin){  $stMin->execute([':id'=>$r['id_kr']]);  $vmin   = $stMin->fetchColumn(); }
        if($stMax){  $stMax->execute([':id'=>$r['id_kr']]);  $vmax   = $stMax->fetchColumn(); }

        $expNow = is_numeric($expNow) ? (float)$expNow : null;
        $realNow= is_numeric($realNow)? (float)$realNow: null;
        $vmin   = is_numeric($vmin)   ? (float)$vmin   : null;
        $vmax   = is_numeric($vmax)   ? (float)$vmax   : null;

        $base=is_numeric($r['baseline'])?(float)$r['baseline']:null;
        $meta=is_numeric($r['meta'])?(float)$r['meta']:null;

        // direção: fallback "maior"
        $dir = mb_strtolower(trim((string)$r['direcao_metrica']),'UTF-8') ?: 'maior';

        // ===== FAROL =====
        $farol='cinza';
        if ($expNow===null && $vmin===null && $vmax===null) {
          $farol = 'cinza';
        } else if ($realNow===null) {
          $farol = 'vermelho';
        } else if ($vmin!==null && $vmax!==null) {
          if ($realNow >= $vmin && $realNow <= $vmax) {
            $farol = 'verde';
          } else {
            $margem = max(0.0, ($vmax - $vmin) * 0.05);
            $farol = ($realNow >= ($vmin - $margem) && $realNow <= ($vmax + $margem)) ? 'amarelo' : 'vermelho';
          }
        } else {
          if ($dir === 'menor') {
            if ($realNow <= $expNow)          $farol = 'verde';
            else if ($realNow <= $expNow*1.10)$farol = 'amarelo';
            else                               $farol = 'vermelho';
          } else {
            if ($realNow >= $expNow)          $farol = 'verde';
            else if ($realNow >= $expNow*0.90)$farol = 'amarelo';
            else                               $farol = 'vermelho';
          }
        }

        // ===== % de avanço do KR (0–100) =====
        $pctAtual=null;
        if($base!==null && $meta!==null && $meta!=$base && is_numeric($realNow)){
          $up = $meta>$base;
          $pctAtual = $up ? (($realNow-$base)/($meta-$base))*100 : (($base-$realNow)/($base-$meta))*100;
          $pctAtual = $clamp($pctAtual);
        }

        // ===== Filtro de status (excluir "não iniciado"/"cancelado") =====
        $krStatus = $r['status_kr'] ?? null;
        $desconsiderar = $isKRDesconsiderado(is_string($krStatus)?$krStatus:null);

        // Lista exibida sempre mostra farol/pct (pct pode ser null se não aplicável)
        $byObj[$oid]['kr_list'][] = [
          'label'=>(string)($r['label']??''),
          'pct'  =>$desconsiderar? null : $pctAtual,
          'farol'=>$desconsiderar? 'cinza' : $farol
        ];

        if($desconsiderar){
          continue; // <<< NÃO entra nas médias/contagens
        }

        // Contagens globais e por objetivo/pilar
        $totalKrs++;
        if($farol==='vermelho'){ $totalKrsCrit++; }

        if($pctAtual!==null){
          $allKRsum += $pctAtual; $allKRcnt++;
          $byObj[$oid]['__sum'] += $pctAtual; $byObj[$oid]['__cnt']++;
          $p = $byObj[$oid]['pilar'];
          $pAgg[$p]['sum'] += $pctAtual; $pAgg[$p]['cnt']++;
        }

        // Farol por objetivo
        if($farol!=='cinza'){
          $byObj[$oid]['krs'][$farol]++; 
          if($farol==='vermelho') $byObj[$oid]['kr_red']++;
        }
        $byObj[$oid]['kr_total']++;
      }

      // ===== Consolida objetivos e farol do objetivo =====
      foreach($byObj as &$o){
        $o['pct'] = ($o['__cnt']>0) ? (int)round($o['__sum']/$o['__cnt']) : null;
        $o['farol_obj'] = $o['krs']['vermelho']>0 ? 'vermelho'
                        : ($o['krs']['amarelo']>0 ? 'amarelo'
                        : ($o['krs']['verde']>0   ? 'verde' : 'cinza'));
        unset($o['__sum'],$o['__cnt']);
      } unset($o);

      // ===== KPIs =====
      $kpi = [
        'objetivos'=>count($byObj),
        // Média geral = média de TODOS os KRs válidos (exclui não iniciados/cancelados)
        'media'    => ($allKRcnt>0 ? (int)round($allKRsum/$allKRcnt) : null),
        'krs'      => $totalKrs,
        'krs_criticos' => $totalKrsCrit
      ];

      // ===== Pilares (média com base em KRs válidos) =====
      $pilares=[];
      foreach($PILLAR_ORDER as $p){
        $media = ($pAgg[$p]['cnt']??0)>0 ? (int)round($pAgg[$p]['sum']/$pAgg[$p]['cnt']) : null;
        $pilares[]=[
          'pilar'=>$p,
          'media'=>$media,
          'krs'=> (int)($pAgg[$p]['cnt']??0),
          'krs_criticos'=> (int)($pAgg[$p]['krs_crit']??0),
          'count_obj'=> (int)($pCount[$p] ?? 0),
          'color'=>$PILLAR_COLORS[$p]
        ];
      }

      // ===== Ranking por dono (mantido) =====
      $rankMap=[];
      foreach($byObj as $o){
        $id=$o['dono_id'] ?: 0; $nm=$o['dono'] ?: '—';
        if(!isset($rankMap[$id])) $rankMap[$id]=['id'=>$id,'nome'=>$nm,'sum'=>0,'cnt'=>0,'obj_count'=>0,'kr_count'=>0,'kr_critico'=>0];
        $rankMap[$id]['obj_count']++;
        $rankMap[$id]['kr_count'] += (int)$o['kr_total'];
        $rankMap[$id]['kr_critico'] += (int)$o['kr_red'];
        if($o['pct']!==null){ $rankMap[$id]['sum']+=$o['pct']; $rankMap[$id]['cnt']++; }
      }
      $rank = array_values(array_map(function($r){ $r['media']=$r['cnt']?(int)round($r['sum']/$r['cnt']):0; unset($r['sum'],$r['cnt']); return $r; }, $rankMap));
      usort($rank, fn($a,$b)=>$b['media']<=>$a['media']);

      // ===== Ordenação dos objetivos (padrão reverso de pilar + nome) =====
      $items = array_values($byObj);
      $order = array_flip(['financeiro','clientes','processos','aprendizado']);
      usort($items, function($a,$b) use ($order){
        $ia = $order[$a['pilar']] ?? 999;
        $ib = $order[$b['pilar']] ?? 999;
        return $ia <=> $ib ?: strnatcasecmp($a['nome'],$b['nome']);
      });
      $items = array_slice($items,0,16);

      // ===== Budget (inalterado) =====
      $budget = ['aprovado'=>0,'realizado'=>0,'saldo'=>0,'series_acc'=>[],'series_acc_plan'=>[]];
      if ($tableExists($pdo,'orcamentos')) {
        $dateStart = $dtIni; $dateEnd = $dtFim;
        $bindB = [':dini'=>$dateStart, ':dfim'=>$dateEnd, ':cid'=>$idCompany];

        $stA=$pdo->prepare("
          SELECT COALESCE(SUM(o.valor),0)
          FROM orcamentos o
          LEFT JOIN iniciativas i ON i.id_iniciativa=o.id_iniciativa
          LEFT JOIN key_results kr ON kr.id_kr=i.id_kr
          LEFT JOIN objetivos obj ON obj.id_objetivo=kr.id_objetivo
          WHERE o.data_desembolso BETWEEN :dini AND :dfim AND obj.id_company=:cid
        "); $stA->execute($bindB); $budget['aprovado']=(float)$stA->fetchColumn();

        $stR=$pdo->prepare("
          SELECT COALESCE(SUM(od.valor),0)
          FROM orcamentos_detalhes od
          LEFT JOIN orcamentos o ON o.id_orcamento=od.id_orcamento
          LEFT JOIN iniciativas i ON i.id_iniciativa=o.id_iniciativa
          LEFT JOIN key_results kr ON kr.id_kr=i.id_kr
          LEFT JOIN objetivos obj ON obj.id_objetivo=kr.id_objetivo
          WHERE od.data_pagamento BETWEEN :dini AND :dfim AND obj.id_company=:cid
        "); $stR->execute($bindB); $budget['realizado']=(float)$stR->fetchColumn();

        $stPlan=$pdo->prepare("
          SELECT DATE_FORMAT(o.data_desembolso,'%Y-%m') AS comp, SUM(o.valor) AS v
          FROM orcamentos o
          LEFT JOIN iniciativas i ON i.id_iniciativa=o.id_iniciativa
          LEFT JOIN key_results kr ON kr.id_kr=i.id_kr
          LEFT JOIN objetivos obj ON obj.id_objetivo=kr.id_objetivo
          WHERE o.data_desembolso BETWEEN :dini AND :dfim AND obj.id_company=:cid
          GROUP BY comp ORDER BY comp
        "); $stPlan->execute($bindB);
        $mapP=[]; foreach($stPlan as $r){ $mapP[$r['comp']] = (float)$r['v']; }

        $stReal=$pdo->prepare("
          SELECT DATE_FORMAT(od.data_pagamento,'%Y-%m') AS comp, SUM(od.valor) AS v
          FROM orcamentos_detalhes od
          LEFT JOIN orcamentos o ON o.id_orcamento=od.id_orcamento
          LEFT JOIN iniciativas i ON i.id_iniciativa=o.id_iniciativa
          LEFT JOIN key_results kr ON kr.id_kr=i.id_kr
          LEFT JOIN objetivos obj ON obj.id_objetivo=kr.id_objetivo
          WHERE od.data_pagamento BETWEEN :dini AND :dfim AND obj.id_company=:cid
          GROUP BY comp ORDER BY comp
        "); $stReal->execute($bindB);
        $mapR=[]; foreach($stReal as $r){ $mapR[$r['comp']] = (float)$r['v']; }

        $seriesP=[]; $seriesR=[];
        $d=new DateTimeImmutable($dateStart); $end=new DateTimeImmutable($dateEnd);
        $accP=0.0; $accR=0.0;
        while($d <= $end){
          $ym=$d->format('Y-m');
          $accP += $mapP[$ym] ?? 0.0;
          $accR += $mapR[$ym] ?? 0.0;
          $seriesP[]=['ym'=>$ym,'acc'=>$accP];
          $seriesR[]=['ym'=>$ym,'acc'=>$accR];
          $d=$d->modify('first day of next month');
        }
        $budget['series_acc_plan'] = $seriesP;
        $budget['series_acc']      = $seriesR;

        $budget['saldo'] = max(0, $budget['aprovado'] - $budget['realizado']);
      }

      echo json_encode(['success'=>true,'items'=>$items,'kpi'=>$kpi,'pilares'=>$pilares,'rank'=>$rank,'budget'=>$budget]); exit;
    }catch(Throwable $e){ error_log('relatorios_okrs/report: '.$e->getMessage()); echo json_encode(['success'=>false,'error'=>'Falha ao montar relatório']); exit; }
  }

  echo json_encode(['success'=>false,'error'=>'Ação inválida']); exit;
}

/* ===================== MODO PÁGINA ===================== */
session_start();
require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/functions.php';
if (!isset($_SESSION['user_id'])) { header('Location: /OKR_system/views/login.php'); exit; }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];

/* Usuário corrente para rodapé de impressão */
$exportUserName = 'Usuário';
try{
  $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS ?? '', [ PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC ]);
  $st=$pdo->prepare("SELECT COALESCE(NULLIF(CONCAT(TRIM(primeiro_nome),' ',TRIM(ultimo_nome)),' '), email_corporativo, CONCAT('#',id_user)) AS nome FROM usuarios WHERE id_user=:u LIMIT 1");
  $st->execute([':u'=>(int)$_SESSION['user_id']]); $exportUserName=$st->fetchColumn() ?: $exportUserName;
}catch(Throwable){/* noop */ }
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Relatórios de OKRs — OKR System</title>

  <link rel="stylesheet" href="/OKR_system/assets/css/base.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/layout.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/theme.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous"/>

  <style>
    :root{ --chat-w:0px; --border:#222733; --gold:var(--bg2, #F1C40F); }
    body{ background:#fff !important; color:#111; }
    .content{ background:transparent; }

    /* ===== Força sidebar escura (corrige branca) ===== */
    .sidebar{ background:linear-gradient(180deg,#0f1420,#0a0f16) !important; color:#eaeef6 !important; border-right:1px solid var(--border) !important; }
    .sidebar a{ color:#cbd5e1 !important; }
    .sidebar .active, .sidebar .current{ color:#fff !important; }
    .header{ background:#fff !important; color:#eaeef6 !important; }

    /* ===== Tipografia compacta ===== */
    main.report{ font-size:12px; padding:12px; display:grid; grid-template-columns:1fr; gap:10px; margin-right:var(--chat-w); transition:margin-right .25s ease; }

    .crumbs{ color:#333; font-size:.72rem; display:flex; align-items:center; gap:6px; }
    .crumbs a{ color:#0c4a6e; text-decoration:none; }
    .crumbs .sep{ opacity:.5; margin:0 2px; }

    .rep-card{ position:relative; background:linear-gradient(180deg, #1b202a, #0d1117); border:1px solid var(--border); border-radius:10px; padding:10px 38px 10px 10px; color:#eaeef6; }
    .rep-title{ font-size:.85rem; font-weight:900; margin:0 0 6px; display:flex; gap:8px; align-items:center; }
    .rep-title i{ color:var(--gold); }
    .share-fab{ position:absolute; top:14px; right:14px; background:transparent; border:none; color:var(--gold); font-size:.95rem; padding:2px; cursor:pointer; }

    .chips{ display:flex; flex-wrap:wrap; gap:6px; }
    .chip{ background:#0e131a; border:1px solid var(--border); color:#a6adbb; padding:3px 7px; border-radius:999px; font-weight:800; font-size:.62rem; display:inline-flex; align-items:center; gap:6px; }
    .chip-mini{ font-size:.58rem; padding:2px 6px; border-radius:999px; border:1px solid #2a3342; background:#0b0f14; color:#cbd5e1; }
    .filters-block input, .filters-block select{ font-size:.68rem; }
    .btn{ border:1px solid var(--border); background:#1f2937; color:#e5e7eb; padding:7px 9px; border-radius:8px; font-weight:800; cursor:pointer; font-size:.74rem; }
    .btn:hover{ border-color:#2a3342; transform:translateY(-1px); transition:.15s; }
    .btn-outline{ background:transparent; }

    .kpi-grid{ display:grid; grid-template-columns:repeat(4,1fr); gap:8px; }
    .kpi{ background:#0f1420; border:1px solid var(--border); border-radius:8px; padding:8px; color:#eaeef6; }
    .kpi-head{ display:flex; align-items:center; justify-content:space-between; margin-bottom:3px; color:#a6adbb; font-size:.72rem; }
    .kpi-value{ font-weight:900; font-size:1.02rem; display:flex; flex-direction:column; gap:4px; }
    .kpi-value .obj-no{ font-weight:900; letter-spacing:.3px; }
    .kpi-obj{ display:flex; align-items:center; gap:6px; }
    .kpi-desc{ font-size:.62rem; font-weight:600; color:#cbd5e1; display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; overflow:hidden; line-height:1.1; max-height: calc(1.1em * 3); }
    .kpi-desc:hover{ -webkit-line-clamp:unset; max-height:none; }

    .kpi-counts{ display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .kpi-counts .big{ font-size:1.24rem; font-weight:900; }

    .kpi-progress{ height:6px; background:#0b1018; border:1px solid #223047; border-radius:999px; overflow:hidden; margin-top:4px; }
    .kpi-progress > span{ display:block; height:100%; background:#22c55e; width:0%; transition:width .7s ease; }

    .kpi-big{ font-size: 1.6rem; font-weight: 900; line-height: 1; }
    .chip-danger{ border-color:#7f1d1d !important; background:#111318 !important; color:#fca5a5 !important; }
    .chip-danger i{ color:#ef4444 !important; }

    .row-analytics{ display:grid; grid-template-columns:1fr 1fr; gap:8px; align-items:start; }

    /* ===== BSC ===== */
    .bsc-wrap{ background:#0f1420; border:1px solid var(--border); border-radius:8px; padding:8px; color:#eaeef6; }
    .bsc-head{ display:flex; align-items:center; justify-content:space-between; margin-bottom:6px; font-size:.8rem; }
    .bsc-chart{ display:grid; grid-template-columns:38px 1fr; gap:8px; align-items:end; height:200px; position:relative; }
    .bsc-y{ display:flex; flex-direction:column; justify-content:space-between; align-items:flex-end; height:100%; padding:2px 0; font-size:.68rem; color:#94a3b8; }
    .bsc-plot{ position:relative; height:100%; }
    .bsc-grid{ position:absolute; inset:0 0 22px 0; background:
        linear-gradient(to top, rgba(255,255,255,.08) 0 1px, transparent 1px) 0 0/100% 25%,
        linear-gradient(to top, rgba(255,255,255,.04) 0 1px, transparent 1px) 0 0/100% 5%; pointer-events:none; border-radius:8px 8px 0 0; }
    .bsc-cols{ position:absolute; inset:0 0 22px 0; display:flex; align-items:flex-end; justify-content:space-around; gap:8px; padding:0 6px; }
    .bsc-col{ flex:1; display:flex; flex-direction:column; align-items:center; justify-content:flex-end; min-width:46px; height:100%; }
    .bsc-bar{ width:30px; min-width:26px; height:0%; background: var(--col,#60a5fa);
      border:1px solid rgba(255,255,255,.18); border-bottom-color: rgba(255,255,255,.28); border-radius:8px 8px 6px 6px;
      transition:height .9s cubic-bezier(.2,.7,.2,1); position:relative; }
    .bsc-bar::after{ content: attr(data-val) '%'; position:absolute; left:50%; transform:translate(-50%,6px); top:-18px;
      background:#0c1118; border:1px solid #1f2a44; border-radius:6px; padding:1px 4px; font-size:.62rem; font-weight:900; color:#eaeef6;
      opacity:0; transition:opacity .4s ease, transform .4s ease; pointer-events:none; }
    .bsc-bar.show::after{ opacity:1; transform:translate(-50%,0); }
    .bsc-labels{ position:absolute; left:0; right:0; bottom:0; height:22px; display:flex; align-items:center; justify-content:space-around; gap:8px; padding:0 6px; }
    .bsc-label{ font-size:.7rem; color:#cbd5e1; text-align:center; width:58px; line-height:1.05; }
    .bsc-sub{ font-size:.62rem; color:#94a3b8; }
    .bsc-bar.empty{ background:transparent; border-style:dashed; border-color:#223047; }
    .bsc-bar.empty::after{ content:'—'; }

    /* ===== Ranking ===== */
    .rank{ background:#0e131a; border:1px solid var(--border); border-radius:8px; padding:8px; color:#eaeef6; }
    .rank .head{ display:flex; align-items:center; justify-content:space-between; margin-bottom:6px; font-size:.8rem; }
    .rank .item{ display:grid; grid-template-columns:30px 26px 1fr auto; gap:8px; align-items:center; margin:7px 0; }
    .rank img{ width:26px; height:26px; border-radius:50%; object-fit:cover; border:1px solid #2a3342; background:#111827; }
    .rank .pos{ font-weight:900; font-size:.85rem; text-align:right; }
    .rank .line{ display:grid; gap:4px; }
    .rank .name{ font-weight:800; font-size:.74rem; color:#e5e7eb; line-height:1.1; }
    .rank .meta{ font-size:.68rem; color:#a6adbb; display:flex; gap:8px; }
    .rank .meta .crit{ color:#fca5a5; }
    .rank .bar{ height:8px; background:#0b1018; border:1px solid #223047; border-radius:999px; overflow:hidden; }
    .rank .bar > span{ display:block; height:100%; background:#22c55e; width:0%; transition:width .6s ease; }
    .rank .val{ text-align:right; font-weight:900; font-size:.8rem; width:46px; }

    /* ===== Objetivos ===== */
    .obj-grid{ display:grid; grid-template-columns:repeat(4,1fr); gap:8px; }
    .obj-card{ background:#0f1420; border:1px solid var(--border); border-radius:8px; padding:8px; color:#eaeef6; min-height:118px; display:flex; flex-direction:column; gap:6px; overflow:hidden; transition:max-height .25s ease; position:relative; }
    .obj-card:hover{ max-height:320px; }
    .obj-badge{ position:absolute; top:6px; right:6px; font-size:.58rem; border:1px solid #2a3342; background:#0b0f14; color:#cbd5e1; border-radius:999px; padding:2px 6px; font-weight:900; }
    .obj-title{ font-weight:900; font-size:.78rem; line-height:1.15; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
    .obj-meta{ display:flex; gap:6px; color:#a6adbb; font-size:.62rem; flex-wrap:nowrap; overflow:hidden; }
    .obj-meta .pill{ background:#0b0f14; border:1px solid #223047; border-radius:999px; padding:1px 5px; font-weight:800; font-size:.52rem; white-space:nowrap; }
    .obj-prog{ height:6px; background:#0b1018; border:1px solid #223047; border-radius:999px; overflow:hidden; }
    .obj-prog > span{ display:block; height:100%; background:#60a5fa; width:0%; transition:width .5s ease; }
    .obj-foot{ display:flex; align-items:center; justify-content:space-between; font-size:.66rem; }
    .tiny-dots{ display:flex; gap:6px; align-items:center; }
    .tiny-dots .dot{ width:8px; height:8px; min-width:8px; border-radius:50%; display:inline-block; border:1px solid #1f2a44; }
    .dot.g{ background:#22c55e; } .dot.y{ background:#f59e0b; } .dot.r{ background:#ef4444; } .dot.c{ background:#9ca3af; }

    .obj-more{ display:none; font-size:.66rem; color:#cbd5e1; gap:4px; }
    .obj-card:hover .obj-more{ display:grid; }

    /* KR com quebra de linha e % fixo à direita */
    .kr-line{ display:flex; align-items:flex-start; gap:6px; }
    .kr-line .kr-dot{
      width:7px; height:7px; border-radius:50%; display:inline-block;
      margin-top: 2px; /* alinha com o início do texto quando quebra */
    }
    .kr-dot.g{ background:#22c55e; } .kr-dot.y{ background:#f59e0b; } .kr-dot.r{ background:#ef4444; } .kr-dot.c{ background:#9ca3af; }

    /* permite quebrar em várias linhas, sem estourar o card */
    .kr-line .kr-txt{
      flex: 1 1 auto; min-width: 0;
      white-space: normal; word-break: break-word; overflow: hidden;
    }
    /* mantém o percentual colado na direita e sem quebra */
    .kr-line .kr-val{ flex: 0 0 auto; white-space: nowrap; margin-left:6px; }
    /* ===== Orçamento ===== */
    .bmini{ background:#0f1420; border:1px solid var(--border); border-radius:8px; padding:8px; color:#eaeef6; }
    .bmini h3{ margin:0 0 6px; font-size:.85rem; display:flex; align-items:center; gap:8px; }
    .bmini-grid{ display:grid; grid-template-columns: 1fr 1fr 1fr 3fr; gap:6px; align-items:stretch; }
    .bmini .k{ background:#0b1018; border:1px solid #223047; border-radius:6px; padding:6px 8px; position:relative; height:72px; display:flex; flex-direction:column; justify-content:flex-start; gap:4px; box-sizing:border-box;}
    .bmini .k .lab{ color:#a6adbb; font-size:.60rem; margin-bottom:2px; line-height:1.1;}
    .bmini .k .val{ font-size:.78rem; font-weight:900; line-height:1.1;}
    .corner-pct{ position:absolute; right:4px; bottom:2px; font-size:.54rem; color:#a6adbb; font-weight:800; }

    .bmini .k:nth-child(4){ grid-column:4; grid-row:1; display:flex; flex-direction:column; }
    .spark-wrap{ display:flex; align-items:center; justify-content:center; height:38px; min-height:38px; overflow: hidden;}
    .spark { width:100%; height:100%; display: block;}

    /* ===== Impressão ===== */
    @media print{
      @page{ margin:10mm; }
      *{ -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }

      .sidebar, .header, .crumbs, .share-fab, .no-print, .filters-block,
      #chatPanel, .chat-panel, .chat-container, #chat, .drawer-chat,
      .chat-fab, .chat-bubble, .chat-avatar, .chat-widget, .chat-toggle,
      [data-chat], [class*="chat"], [id*="chat"] { display:none !important; }

      body{ background:#fff !important; }
      .content{ margin:0 !important; }
      main.report{ padding:0 !important; }

      .row-analytics{ grid-template-columns: 1fr 1fr !important; gap:8px !important; }
      .kpi-grid{ grid-template-columns: repeat(4, 1fr) !important; }

      .rep-card .kpi-value{ font-size:.82rem !important; }
      .rep-card .kpi-head{ font-size:.66rem !important; }

      .obj-grid{ grid-template-columns: repeat(4, 1fr) !important; }
      .tiny-dots .dot{ border-color:transparent !important; }

      .bmini-grid{ gap:6px !itant; }  /* <-- '!important' */
      .bmini .k{ padding:6px 8px !important; border-radius:6px !important; height:68px !important; }
      .bmini .k .lab{ font-size:.58rem !important; margin-bottom:2px !important; line-height:1.1 !important; }
      .bmini .k .val{ font-size:.72rem !important; line-height:1.1 !important; }
      .corner-pct{ font-size:.52rem !important; right:4px !important; bottom:2px !important; }
      .spark-wrap{ height:36px !important; }
      .spark{ height:28px !important; }

      #printFooter{ display:block !important; position:fixed; left:0; right:0; bottom:0; font-size:.68rem; color:#334155; }
    }
    #printFooter{ display:none; text-align:right; padding-top:6px; border-top:1px dashed #cbd5e1; margin-top:6px; }
  </style>
</head>
<body>
  <?php include __DIR__ . '/partials/sidebar.php'; ?>
  <div class="content">
    <?php include __DIR__ . '/partials/header.php'; ?>

    <main class="report" id="reportArea">
      <div class="crumbs">
        <i class="fa-solid fa-route"></i>
        <a href="/OKR_system/dashboard"><i class="fa-solid fa-house"></i> Dashboard</a>
        <span class="sep">/</span>
        <span><i class="fa-solid fa-chart-line"></i> Relatórios de OKRs</span>
      </div>

      <section class="rep-card">
        <button class="share-fab" title="Compartilhar" onclick="navigator.clipboard.writeText(location.href)"><i class="fa-solid fa-share-nodes"></i></button>
        <h1 class="rep-title"><i class="fa-solid fa-chart-simple"></i> Relatório executivo (one-page)</h1>

        <!-- Filtros (ocultos no PDF) -->
        <div class="chips filters-block no-print" style="margin-bottom:8px">
          <span class="chip"><i class="fa-regular fa-calendar"></i> <input id="f_dt_ini" type="date" style="background:transparent;border:none;color:#eaeef6;outline:none"></span>
          <span class="chip"><i class="fa-regular fa-calendar-check"></i> <input id="f_dt_fim" type="date" style="background:transparent;border:none;color:#eaeef6;outline:none"></span>
          <span class="chip"><i class="fa-solid fa-layer-group"></i>
            <select id="f_pilar" style="background:transparent;border:none;color:#eaeef6;outline:none">
              <option value="">Pilar (todos)</option>
              <option>Aprendizado</option><option>Processos</option><option>Clientes</option><option>Financeiro</option>
            </select>
          </span>
          <span class="chip"><i class="fa-solid fa-clipboard-check"></i>
            <select id="f_status" style="background:transparent;border:none;color:#eaeef6;outline:none">
              <option value="">Status (todos)</option>
              <option value="ativo">Ativo</option>
              <option value="concluido">Concluído</option>
              <option value="pausado">Pausado</option>
            </select>
          </span>
          <span class="chip"><i class="fa-regular fa-user"></i> <input id="f_dono" type="text" placeholder="Dono" style="background:transparent;border:none;color:#eaeef6;outline:none;width:130px"></span>
          <span class="chip"><i class="fa-solid fa-magnifying-glass"></i> <input id="f_q" type="text" placeholder="Busca" style="background:transparent;border:none;color:#eaeef6;outline:none;width:160px"></span>
          <button class="btn" id="btnAplicar"><i class="fa-solid fa-filter"></i>&nbsp;Aplicar</button>
          <button class="btn btn-outline" id="btnPrint"><i class="fa-regular fa-file-lines"></i>&nbsp;Exportar</button>
        </div>

        <!-- KPIs -->
        <div class="kpi-grid">
          <div class="kpi"><div class="kpi-head"><span>Objetivos</span><i class="fa-regular fa-flag"></i></div><div class="kpi-value" id="kpi_obj">—</div></div>
          <div class="kpi">
            <div class="kpi-head">
              <span>Média de progresso</span><i class="fa-solid fa-gauge-high"></i>
            </div>
            <div class="kpi-value" id="kpi_media">—%</div>
            <div class="kpi-progress" id="kpi_media_bar"><span></span></div>
          </div>
          <div class="kpi"><div class="kpi-head"><span>Objetivo líder</span><i class="fa-solid fa-trophy"></i></div><div class="kpi-value" id="kpi_lider">—</div></div>
          <div class="kpi"><div class="kpi-head"><span>Pior objetivo</span><i class="fa-regular fa-face-frown"></i></div><div class="kpi-value" id="kpi_pior">—</div></div>
        </div>
      </section>

      <!-- Linha: BSC (esquerda) + Ranking (direita) -->
      <section class="row-analytics">
        <div class="bsc-wrap">
          <div class="bsc-head">
            <strong><i class="fa-solid fa-layer-group"></i> Pilares BSC (0–100%)</strong>
          </div>
          <div class="bsc-chart" role="img" aria-label="Progresso por pilar BSC">
            <div class="bsc-y" aria-hidden="true">
              <span>100%</span><span>75%</span><span>50%</span><span>25%</span><span>0%</span>
            </div>
            <div class="bsc-plot">
              <div class="bsc-grid"></div>
              <div id="bsc_cols" class="bsc-cols"></div>
              <div class="bsc-labels" aria-hidden="true" id="bsc_labels"></div>
            </div>
          </div>
        </div>

        <!-- Ranking -->
        <div class="rank" id="rank_box">
          <div class="head">
            <strong><i class="fa-regular fa-user"></i> Ranking por Dono</strong>
            <div style="display:flex; align-items:center; gap:8px">
              <small id="rank_order_label" style="color:#a6adbb">melhor → pior</small>
              <button class="btn btn-outline" id="rankToggle" title="Alternar ordem (melhor↔pior)" aria-pressed="true">
                <i id="rankToggleIcon" class="fa-solid fa-arrow-down-wide-short"></i>
              </button>
            </div>
          </div>
          <div id="rank_list"></div>
        </div>
      </section>

      <!-- Objetivos -->
      <section class="obj-grid" id="obj_grid"></section>

      <!-- Resumo de orçamento -->
      <section class="bmini" id="budget_mini">
        <h3><i class="fa-solid fa-coins"></i> Resumo de orçamento</h3>
        <div class="bmini-grid">
          <!-- REPOSto: este card faltava -->
          <div class="k">
            <div class="lab">Orçado (Aprovado)</div>
            <div class="val" id="b_aprov">—</div>
          </div>

          <div class="k">
            <div class="lab">Realizado</div>
            <div class="val" id="b_real">—</div>
            <div class="corner-pct" id="b_real_pct">—%</div>
          </div>
          <div class="k">
            <div class="lab">Saldo</div>
            <div class="val" id="b_saldo">—</div>
            <div class="corner-pct" id="b_saldo_pct">—%</div>
          </div>
          <div class="k">
            <div class="lab">Evolução anual (acum.)</div>
            <div class="spark-wrap">
              <svg id="b_spark" class="spark" viewBox="0 0 300 120" preserveAspectRatio="none"></svg>
            </div>
          </div>
        </div>
      </section>
      <div id="printFooter" data-user="<?= htmlspecialchars($exportUserName, ENT_QUOTES, 'UTF-8') ?>"></div>

      <?php include __DIR__ . '/partials/chat.php'; ?>
    </main>
  </div>

<script>
  const $ = (s,p)=> (p||document).querySelector(s);

  const PILLAR_COLORS = { 'aprendizado':'#8e44ad', 'processos':'#2980b9', 'clientes':'#27ae60', 'financeiro':'#f39c12' };
  const PILLAR_ORDER  = ['aprendizado','processos','clientes','financeiro'];
  const PILLAR_ORDER_REVERSED = ['financeiro','clientes','processos','aprendizado'];
  const PILLAR_ICONS  = { 'aprendizado':'fa-solid fa-graduation-cap', 'processos':'fa-solid fa-gears', 'clientes':'fa-solid fa-users', 'financeiro':'fa-solid fa-coins' };

  const FAROL_COLORS = { verde:'#22c55e', amarelo:'#f59e0b', vermelho:'#ef4444', cinza:'#9ca3af' };

  // estado do toggle do ranking (true: melhor→pior | false: pior→melhor)
  let __rankOrderDesc = true;
  let __rankData = [];
  let __rankPosMap = new Map(); // id -> posição (1 = melhor)

  // Filtros
  $('#btnAplicar').addEventListener('click', ()=>{
    const filtros = {
      dt_inicio: $('#f_dt_ini').value || null,
      dt_fim:    $('#f_dt_fim').value || null,
      pilar:     $('#f_pilar').value || '',
      status:    $('#f_status').value || '',
      dono:      $('#f_dono').value || '',
      q:         $('#f_q').value || ''
    };
    carregar(filtros);
  });

  // Exportar (print) — garante que barras estejam “fixas” no PDF
  $('#btnPrint').addEventListener('click', ()=>{
    finalizeChartsForPrint();
    const f = $('#printFooter');
    const user = f.dataset.user || '—';
    const d=new Date(); const pad=n=>String(n).padStart(2,'0');
    const stamp = `${pad(d.getDate())}/${pad(d.getMonth()+1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
    f.textContent = `Emitido em ${stamp} por ${user}`;
    window.print();
  });

  // Toggle do ranking
  document.addEventListener('click', (e)=>{
    const btn = e.target.closest('#rankToggle');
    if(!btn) return;
    __rankOrderDesc = !__rankOrderDesc;
    const lab = $('#rank_order_label');
    const ico = $('#rankToggleIcon');
    btn.setAttribute('aria-pressed', __rankOrderDesc ? 'true' : 'false');
    if(lab) lab.textContent = __rankOrderDesc ? 'melhor → pior' : 'pior → melhor';
    if(ico) ico.className = __rankOrderDesc ? 'fa-solid fa-arrow-down-wide-short' : 'fa-solid fa-arrow-up-short-wide';
    drawRanking();
  });

  // Ajustes antes de imprimir
  window.matchMedia && window.matchMedia('print').addEventListener('change', e=>{ if(e.matches) finalizeChartsForPrint(); });
  window.addEventListener('beforeprint', finalizeChartsForPrint);

  // Primeira carga
  carregar({});

  function carregar(filtros){
    fetch('?ajax=report', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(filtros||{})})
      .then(r=>r.json())
      .then(data=>{
        if(!data.success) throw new Error(data.error||'Falha');

        // Ordena os objetivos e numera (map id -> índice)
        const itemsOrdered = orderItems(data.items||[]);
        const idxMap = new Map(); itemsOrdered.forEach((o,i)=> idxMap.set(o.id, i+1));
        window.__objIndexMap = idxMap;

        renderKPIs(itemsOrdered, data);
        renderBSC(data.pilares||[]);
        renderRanking(data.rank||[]);
        renderObjetivos(itemsOrdered);
        renderBudget(data.budget||{});
      })
      .catch(e=>{ console.error(e); alert('Falha ao carregar relatório.'); });
  }

  function orderItems(items){
    const orderIdx = k => PILLAR_ORDER_REVERSED.indexOf(String(k).toLowerCase());
    const arr=[...(items||[])];
    arr.sort((a,b)=> (orderIdx(a.pilar) - orderIdx(b.pilar)) || strnatcasecmp(a.nome||'', b.nome||''));
    return arr;
  }

  // ========= KPIs =========
  function renderKPIs(itemsOrdered, data){
    const objN     = data.kpi?.objetivos ?? 0;
    const krN      = data.kpi?.krs ?? 0;
    const krCritN  = data.kpi?.krs_criticos ?? 0;

    $('#kpi_obj').innerHTML = `
      <div class="kpi-counts">
        <span class="kpi-big" id="kpi_obj_n">${objN}</span>
        <span class="chip-mini"><i class="fa-solid fa-list-check"></i> ${krN} KRs</span>
        <span class="chip-mini chip-danger" title="KRs críticos (vermelho)">
          <i class="fa-solid fa-triangle-exclamation"></i> ${krCritN}
        </span>
      </div>
    `;

    // Média geral = média de TODOS os KRs válidos (exclui cancelados/não iniciados)
    const mediaPct = (data.kpi?.media ?? null);
    const mediaTxt = (mediaPct===null || isNaN(mediaPct)) ? '—' : `${mediaPct}%`;
    $('#kpi_media').innerHTML = `<span class="kpi-big">${mediaTxt}</span>`;

    // Barrinha animada
    const bar = $('#kpi_media_bar > span');
    if (bar){
      const v = (mediaPct===null || isNaN(mediaPct)) ? 0 : Math.max(0, Math.min(100, parseInt(mediaPct,10) || 0));
      bar.style.width = '0%';
      requestAnimationFrame(()=> setTimeout(()=> { bar.style.width = v + '%'; }, 20));
    }

    // Líder / Pior considerando somente objetivos com pct != null
    const valid = (itemsOrdered||[]).filter(it => it.pct !== null && it.pct !== undefined);
    if (valid.length){
      const best = [...valid].sort((a,b)=> b.pct - a.pct)[0];
      const worst = [...valid].sort((a,b)=> a.pct - b.pct)[0];

      // BEST
      const bestIdx = (window.__objIndexMap && window.__objIndexMap.get(best.id)) || 1;
      const objNo = String(bestIdx).padStart(2,'0');
      const pKey = String(best.pilar||'').toLowerCase();
      const icon = PILLAR_ICONS[pKey] || 'fa-solid fa-layer-group';
      const color = PILLAR_COLORS[pKey] || '#60a5fa';
      $('#kpi_lider').innerHTML = `
        <div class="kpi-obj">
          <i class="${icon}" style="color:${color}"></i>
          <span class="obj-no">OBJ ${objNo}</span>
          <span style="margin-left:auto; font-weight:900">${best.pct}%</span>
        </div>
        <div class="kpi-desc" title="${esc(best.nome||'—')}">${esc(best.nome||'—')}</div>
      `;

      // WORST
      const worstIdx = (window.__objIndexMap && window.__objIndexMap.get(worst.id)) || 1;
      const objNoW = String(worstIdx).padStart(2,'0');
      const pKeyW = String(worst.pilar||'').toLowerCase();
      const iconW = PILLAR_ICONS[pKeyW] || 'fa-solid fa-layer-group';
      const colorW= PILLAR_COLORS[pKeyW] || '#60a5fa';
      $('#kpi_pior').innerHTML = `
        <div class="kpi-obj">
          <i class="${iconW}" style="color:${colorW}"></i>
          <span class="obj-no">OBJ ${objNoW}</span>
          <span style="margin-left:auto; font-weight:900">${worst.pct}%</span>
        </div>
        <div class="kpi-desc" title="${esc(worst.nome||'—')}">${esc(worst.nome||'—')}</div>
      `;
    } else {
      $('#kpi_lider').textContent = '—';
      $('#kpi_pior').textContent  = '—';
    }
  }

  // ========= BSC (média por pilar com KRs válidos) =========
  function renderBSC(pilares){
    const by = {}; (pilares||[]).forEach(p=>{ if(!p) return; by[(p.pilar||'').toLowerCase()] = p; });

    const cols = $('#bsc_cols'); cols.innerHTML='';
    const labels = $('#bsc_labels'); labels.innerHTML='';

    PILLAR_ORDER.forEach(key=>{
      const p = by[key] || { pilar:key, media:null, count_obj:0, color:PILLAR_COLORS[key] };
      const empty = (p.media===null || isNaN(p.media));
      const val = empty ? 0 : Math.max(0, Math.min(100, parseInt(p.media,10)));

      const col = document.createElement('div'); col.className='bsc-col';
      col.innerHTML = `<div class="bsc-bar ${empty?'empty':''}" style="--col:${p.color || PILLAR_COLORS[key]};" data-val="${empty?'—':val}"></div>`;
      cols.appendChild(col);

      const lab = document.createElement('div'); lab.className='bsc-label';
      lab.innerHTML = `<div>${labelPillar(key)}</div><div class="bsc-sub">${(p.count_obj||0)}-Objs</div>`;
      labels.appendChild(lab);

      requestAnimationFrame(()=>{ const bar=col.querySelector('.bsc-bar'); setTimeout(()=>{ bar.style.height = (empty ? '0%' : (val + '%')); bar.classList.add('show'); }, 20); });
    });
  }

  // ========= Ranking =========
  function avatarHTML(userId){
    const PNG  = `/OKR_system/assets/img/avatars/${userId}.png`;
    const JPG  = `/OKR_system/assets/img/avatars/${userId}.jpg`;
    const JPEG = `/OKR_system/assets/img/avatars/${userId}.jpeg`;
    return `<img src="${PNG}" onerror="this.onerror=null; this.src='${JPG}'; this.onerror=function(){this.src='${JPEG}';};" alt="">`;
  }

  function renderRanking(lista){
    __rankData = Array.isArray(lista) ? lista.slice() : [];
    __rankPosMap = new Map(__rankData.map((r,i)=>[(r.id ?? r.dono_id ?? i), i+1]));

    const lab = $('#rank_order_label');
    const ico = $('#rankToggleIcon');
    const btn = $('#rankToggle');
    if(lab) lab.textContent = __rankOrderDesc ? 'melhor → pior' : 'pior → melhor';
    if(ico) ico.className = __rankOrderDesc ? 'fa-solid fa-arrow-down-wide-short' : 'fa-solid fa-arrow-up-short-wide';
    if(btn) btn.setAttribute('aria-pressed', __rankOrderDesc ? 'true' : 'false');

    drawRanking();
  }

  function drawRanking(){
    const box = $('#rank_list'); 
    box.innerHTML='';

    const base = (__rankData || []).slice();
    const orderedFull = __rankOrderDesc ? base : base.slice().reverse();

    const visible = orderedFull.slice(0, 10);
    const visibleTotal = visible.length;

    visible.forEach((r, idx) => {
      const row = document.createElement('div'); 
      row.className='item';

      const id = r.id ?? r.dono_id ?? 0;

      const posReal = __rankPosMap.get(id) || (idx + 1);
      const posDisplay = __rankOrderDesc ? (idx + 1) : (visibleTotal - idx);
      const posStr = posDisplay + 'º';

      const color = posReal === 1 ? '#f6c343' : posReal === 2 ? '#c0c0c0' : posReal === 3 ? '#cd7f32' : '#ffffff';

      const ava = avatarHTML(id);
      const media = r.media || 0, obj = r.obj_count || 0, krt = r.kr_count || 0, krred = r.kr_critico || 0;
      const nome = (r.nome || '—').toString();
      const firstLast = nome.trim().split(/\s+/).filter(Boolean)
        .reduce((acc, part, i, arr) => (i === 0 ? [part] : (i === arr.length - 1 ? [acc[0], part] : acc)), ['—'])
        .join(' ');

      row.innerHTML = `
        <div class="pos" style="color:${color}" title="Posição real: ${posReal}º">${posStr}</div>
        ${ava}
        <div class="line">
          <div class="name">${esc(firstLast)}</div>
          <div class="bar"><span style="width:${media}%"></span></div>
          <div class="meta">
            <span>Obj: <strong>${obj}</strong></span>
            <span>KRs: <strong>${krt}</strong></span>
            <span class="crit">Críticos: <strong>${krred}</strong></span>
          </div>
        </div>
        <div class="val" title="${esc(nome)}">${media}%</div>
      `;
      box.appendChild(row);
    });
  }

  // ========= Objetivos =========
  function pillarColor(key){ return PILLAR_COLORS[String(key).toLowerCase()] || '#60a5fa'; }
  function pillarIcon(key){ return PILLAR_ICONS[String(key).toLowerCase()] || 'fa-solid fa-layer-group'; }
  function contrastText(hex){
    // contraste simples para amarelo
    if(!hex) return '#fff';
    const c = hex.replace('#','');
    const r = parseInt(c.substring(0,2),16);
    const g = parseInt(c.substring(2,4),16);
    const b = parseInt(c.substring(4,6),16);
    const yiq = ((r*299)+(g*587)+(b*114))/1000;
    return yiq >= 200 ? '#0b0f14' : '#ffffff';
  }

  function renderObjetivos(items){
    const grid = $('#obj_grid'); grid.innerHTML='';
    (items||[]).forEach((o, i)=>{
      const idx = (window.__objIndexMap && window.__objIndexMap.get(o.id)) || (i+1);
      const objNo = String(idx).padStart(2,'0');

      const pKey = String(o.pilar||'').toLowerCase();
      const color = pillarColor(pKey);
      const icon  = pillarIcon(pKey);
      const donoFirst = (o.dono||'—').toString().trim().split(/\s+/)[0] || '—';

      const farol = (o.farol_obj || 'cinza');
      const farolCol = FAROL_COLORS[farol] || FAROL_COLORS.cinza;
      const farolTxt = contrastText(farolCol);

      const pct = (o.pct===null || o.pct===undefined) ? null : Number(o.pct);
      const pctTxt = (pct===null || isNaN(pct)) ? '—' : `${pct}%`;
      const pctWidth = (pct===null || isNaN(pct)) ? 0 : Math.max(0, Math.min(100, pct));

      const more = (o.kr_list||[]).slice(0,5).map(kr=>{
        const cls = kr.farol==='verde'?'g':(kr.farol==='amarelo'?'y':(kr.farol==='vermelho'?'r':'c'));
        const vp = (kr.pct===null || kr.pct===undefined || isNaN(kr.pct)) ? '—' : (kr.pct+'%');
        return `<div class="kr-line"><span class="kr-dot ${cls}"></span><span class="kr-txt" title="${esc(kr.label||'KR')}">${esc(kr.label||'KR')}</span><span class="kr-val">${vp}</span></div>`;
      }).join('');

      const card = document.createElement('div'); card.className='obj-card';
      card.style.borderLeft = `6px solid ${color}`;

      card.innerHTML = `
        <span class="obj-badge" style="background:${farolCol}; color:${farolTxt}; border-color:${farolCol}" title="Farol do objetivo">${'OBJ '+objNo}</span>
        <div class="obj-title"><i class="${icon}" style="color:${color}"></i> ${esc(o.nome||'Objetivo')}</div>
        <div class="obj-meta">
          <span class="pill"><i class="fa-regular fa-user"></i> ${esc(donoFirst)}</span>
          <span class="pill"><i class="fa-regular fa-calendar-days"></i> ${esc(o.prazo||'—')}</span>
        </div>
        <div class="obj-prog"><span style="background:${color}; width:${pctWidth}%"></span></div>
        <div class="obj-foot">
          <span><strong>${pctTxt}</strong></span>
          <span class="tiny-dots" title="KRs: 🟢 verde | 🟡 amarelo | 🔴 vermelho">
            <span class="dot g"></span> ${o.krs?.verde ?? 0}
            <span class="dot y" style="margin-left:6px"></span> ${o.krs?.amarelo ?? 0}
            <span class="dot r" style="margin-left:6px"></span> ${o.krs?.vermelho ?? 0}
          </span>
        </div>
        ${ more ? `<div class="obj-more">${more}</div>` : '' }
      `;
      grid.appendChild(card);
      requestAnimationFrame(()=>{ const bar=card.querySelector('.obj-prog > span'); setTimeout(()=> bar.style.width = `${pctWidth}%`, 10); });
    });
  }

  // ========= Orçamento =========
  function renderBudget(b){
    const brl = (x)=> (Number(x)||0).toLocaleString('pt-BR',{style:'currency',currency:'BRL'});
    const ap = Number(b.aprovado||0), re=Number(b.realizado||0), sa=Math.max(0, Number(b.saldo||0));
    const pRe = ap>0 ? Math.round(100*re/ap) : 0;
    const pSa = ap>0 ? Math.round(100*sa/ap) : 0;

    $('#b_aprov').textContent = brl(ap);
    $('#b_real').textContent  = brl(re);
    $('#b_saldo').textContent = brl(sa);
    $('#b_real_pct').textContent = `${pRe}%`;
    $('#b_saldo_pct').textContent= `${pSa}%`;

    // sparkline acumulado anual: orçado (branco pontilhado) x realizado (amarelo)
    const svg = $('#b_spark'); while(svg.firstChild) svg.removeChild(svg.firstChild);
    const plan = Array.isArray(b.series_acc_plan)? b.series_acc_plan : [];
    const real = Array.isArray(b.series_acc)? b.series_acc : [];
    if (!plan.length && !real.length){ return; }

    const rect = svg.getBoundingClientRect();
    const wCss = Math.floor(rect.width)  || 300;
    const hCss = Math.floor(rect.height) || 80;
    const w = Math.max(160, wCss);
    const h = Math.max(28,  hCss);
    svg.setAttribute('viewBox', `0 0 ${w} ${h}`);

    const padX = Math.max(16, Math.round(w * 0.08));
    const padY = Math.max(6,  Math.min(14, Math.round(h * 0.18)));
    const N = Math.max(plan.length, real.length);
    const dx = (w - 2*padX) / Math.max(N-1,1);

    const ysP = plan.map(p=> Number(p.acc||0));
    const ysR = real.map(p=> Number(p.acc||0));
    const maxY = Math.max(...ysP, ...ysR, 1);
    const minY = 0;

    const toX = i => Math.round(padX + i*dx);
    const toY = v => Math.round(h - padY - ( (v - minY) / (maxY - minY) ) * (h - 2*padY));

    const pointsPlan = plan.map((p,i)=> [toX(i), toY(Number(p.acc||0))]);
    const pointsReal = real.map((p,i)=> [toX(i), toY(Number(p.acc||0))]);

    const smoothQPath = (pts)=>{
      if(!pts.length) return '';
      if(pts.length===1) return `M${pts[0][0]},${pts[0][1]}`;
      let d=`M${pts[0][0]},${pts[0][1]}`;
      for(let i=1;i<pts.length;i++){
        const xc=(pts[i-1][0]+pts[i][0])/2, yc=(pts[i-1][1]+pts[i][1])/2;
        d += ` Q ${pts[i-1][0]},${pts[i-1][1]} ${xc},${yc}`;
      }
      d += ` T ${pts[pts.length-1][0]},${pts[pts.length-1][1]}`;
      return d;
    };

    // linha base
    const base = document.createElementNS('http://www.w3.org/2000/svg','line');
    base.setAttribute('x1', padX); base.setAttribute('x2', w-padX);
    base.setAttribute('y1', toY(0)); base.setAttribute('y2', toY(0));
    base.setAttribute('stroke','rgba(255,255,255,.15)'); base.setAttribute('stroke-width','1');
    svg.appendChild(base);

    // Orçado: branco pontilhado
    if(pointsPlan.length){
      const pthP = document.createElementNS('http://www.w3.org/2000/svg','path');
      pthP.setAttribute('d', smoothQPath(pointsPlan));
      pthP.setAttribute('fill','none');
      pthP.setAttribute('stroke','#ffffff');
      pthP.setAttribute('stroke-width','2');
      pthP.setAttribute('stroke-dasharray','4 3');
      pthP.setAttribute('stroke-linecap','round');
      svg.appendChild(pthP);
    }

    // Realizado: amarelo
    if(pointsReal.length){
      const pthR = document.createElementNS('http://www.w3.org/2000/svg','path');
      pthR.setAttribute('d', smoothQPath(pointsReal));
      pthR.setAttribute('fill','none');
      pthR.setAttribute('stroke','#f6c343');
      pthR.setAttribute('stroke-width','2.2');
      pthR.setAttribute('stroke-linecap','round');
      svg.appendChild(pthR);
    }

    // Rótulos início/fim (MM/AAAA)
    try {
      var headYMPlan = (Array.isArray(plan) && plan.length && plan[0] && plan[0].ym) ? String(plan[0].ym) : '';
      var headYMReal = (Array.isArray(real) && real.length && real[0] && real[0].ym) ? String(real[0].ym) : '';
      var tailYMPlan = (Array.isArray(plan) && plan.length && plan[plan.length-1] && plan[plan.length-1].ym) ? String(plan[plan.length-1].ym) : '';
      var tailYMReal = (Array.isArray(real) && real.length && real[real.length-1] && real[real.length-1].ym) ? String(real[real.length-1].ym) : '';

      function pickMinYM(a, b) { var v = []; if (a) v.push(a); if (b) v.push(b); v.sort(); return v[0] || ''; }
      function pickMaxYM(a, b) { var v = []; if (a) v.push(a); if (b) v.push(b); v.sort(); return v.length ? v[v.length - 1] : ''; }

      var firstYM = pickMinYM(headYMPlan, headYMReal);
      var lastYM  = pickMaxYM(tailYMPlan,  tailYMReal);

      function fmt(ym) {
        var parts = String(ym).split('-'); var y = parts[0], m = parts[1];
        return (y && m) ? (m + '/' + y) : (ym || '');
      }

      var yBase   = toY(0);
      var yLabel  = Math.max(10, yBase - 6);
      var xLeft   = Math.max(2, padX - 8);
      var xRight  = Math.min(w - 2, w - padX + 8);
      var fontSz  = (h <= 40) ? 8 : 10;

      function makeText(x, txt, anchor) {
        var t = document.createElementNS('http://www.w3.org/2000/svg', 'text');
        t.setAttribute('x', String(x));
        t.setAttribute('y', String(yLabel));
        if (anchor) t.setAttribute('text-anchor', anchor);
        t.setAttribute('fill', '#cbd5e1');
        t.setAttribute('font-size', String(fontSz));
        t.setAttribute('pointer-events', 'none');
        t.textContent = txt;
        return t;
      }

      if (firstYM && lastYM) {
        if (firstYM === lastYM || N === 1) {
          var xc = Math.round((toX(0) + toX(Math.max(0, N - 1))) / 2);
          svg.appendChild(makeText(xc, fmt(firstYM), 'middle'));
        } else {
          svg.appendChild(makeText(xLeft,  fmt(firstYM), 'end'));
          svg.appendChild(makeText(xRight, fmt(lastYM),  'start'));
        }
      }
    } catch (e) {
      console.warn('Erro ao desenhar rótulos do sparkline:', e);
    }
  }

  // ========= Utilitários =========
  function labelPillar(k){
    switch(String(k).toLowerCase()){
      case 'aprendizado': return 'Aprendizado';
      case 'processos': return 'Processos';
      case 'clientes': return 'Clientes';
      case 'financeiro': return 'Financeiro';
      default: return k||'—';
    }
  }
  function strnatcasecmp(a,b){ return (a||'').toString().localeCompare((b||'').toString(),'pt-BR',{numeric:true,sensitivity:'base'}); }
  function esc(s){ if(s===null||s===undefined) return ''; return String(s).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'","&#039;"); }

  function finalizeChartsForPrint(){
    document.querySelectorAll('.bsc-bar').forEach(bar=>{
      const v = bar.getAttribute('data-val');
      if(v && v!=='—'){ bar.style.height = v+'%'; bar.classList.add('show'); }
      const parentCol = bar.style.getPropertyValue('--col') || '#60a5fa';
      bar.style.background = parentCol;
    });
    document.querySelectorAll('.rank .bar > span').forEach(s=>{ const w = s.style.width || '0%'; s.style.width = w; });
    document.querySelectorAll('.obj-prog > span').forEach(s=>{ const w = s.style.width || '0%'; s.style.width = w; });
    document.querySelectorAll('.kpi-progress > span').forEach(s=>{ const w = s.style.width || '0%'; s.style.width = w; });
  }

  // ========= Ajuste de largura com chat lateral =========
  const CHAT_SELECTORS=['#chatPanel','.chat-panel','.chat-container','#chat','.drawer-chat'];
  const TOGGLE_SELECTORS=['#chatToggle','.chat-toggle','.btn-chat-toggle','.chat-icon','.chat-open'];
  function findChatEl(){ for(const s of CHAT_SELECTORS){ const el=document.querySelector(s); if(el) return el; } return null; }
  function isOpen(el){ const st=getComputedStyle(el); const vis=st.display!=='none'&&st.visibility!=='hidden'; const w=el.offsetWidth; return (vis&&w>0)||el.classList.contains('open')||el.classList.contains('show'); }
  function updateChatWidth(){ const el=findChatEl(); const w=(el && isOpen(el))?el.offsetWidth:0; document.documentElement.style.setProperty('--chat-w',(w||0)+'px'); }
  function setupChatObservers(){
    const chat=findChatEl(); if(!chat) return;
    const mo=new MutationObserver(()=>{ updateChatWidth(); });
    mo.observe(chat,{attributes:true,attributeFilter:['style','class','aria-expanded']});
    window.addEventListener('resize',updateChatWidth);
    TOGGLE_SELECTORS.forEach(s=>document.querySelectorAll(s).forEach(btn=>btn.addEventListener('click',()=>setTimeout(updateChatWidth,200))));
    updateChatWidth();
  }
  document.addEventListener('DOMContentLoaded', setupChatObservers);
</script>
</body>
</html>