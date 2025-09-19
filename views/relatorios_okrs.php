<?php
// views/relatorios_okrs.php — One-page executivo de OKRs (BSC + Ranking + Objetivos + Orçamento)
// Farol e progresso finais dos cartões ainda podem ser refinados pelo detalhe_okr.php,
// mas agora o backend deste relatório calcula: (1) farol dos KRs por milestone
// (2) contagem por farol (Verde/Amarelo/Vermelho) por Objetivo, KPI e Ranking.
//
// REGRA DE FAROL POR MILESTONE (America/Sao_Paulo):
// 1) Escolha do milestone de referência (comparando apenas a DATA):
// - Se existir milestone com data_ref = hoje e COM apontamento (valor_real_consolidado != NULL OU qtde_apontamentos>0) => usar esse.
// - Caso contrário => usar o passado mais próximo (maior data_ref < hoje).
// - Se não existir nenhum passado:
//   a) Se existir milestone no futuro (>= hoje) => farol CINZA (não iniciado / sem apontamento).
//   b) Se não houver nenhum milestone => farol VERMELHO (sem referência histórica).
// 2) Curto-circuito: se o milestone escolhido estiver SEM apontamento => farol VERMELHO.
// 3) Cálculo do desvio relativo ruim s (E, [E_min,E_max], R, margem m; direção: maior/menor/intervalo):
// - Maior melhor : s = max(0,(E - R)/E)
// - Menor melhor : s = max(0,(R - E)/E)
// - Intervalo : se R∈[Emin,Emax] => s=0; abaixo: s=(Emin-R)/Emin; acima: s=(R-Emax)/Emax
// - Proteções: denominadores tratados com epsilon.
// - Cores: s<=m => VERDE; m<s<=3m => AMARELO; s>3m => VERMELHO (bordas inclusivas: s=m verde; s=3m amarelo).
//
// Observação: KRs "não iniciado" ou "cancelado" continuam desconsiderados para KPI/contagens.
// Margem m: tenta ler do KR (margem/tolerancia/tolerancia_pct). Se ausente, usa 0.10.

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
  }catch(Throwable){
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>'Falha na conexão']);
    exit;
  }

  $tableExists = static function(PDO $pdo, string $t): bool {
    try{ $pdo->query("SHOW COLUMNS FROM $t"); return true; }catch(Throwable){ return false; }
  };
  $colExists = static function(PDO $pdo, string $t, string $c): bool {
    try{ $st=$pdo->prepare("SHOW COLUMNS FROM $t LIKE :c"); $st->execute([':c'=>$c]); return (bool)$st->fetch(); }catch(Throwable){ return false; }
  };

  $clamp = static function(?float $v): ?int {
    if($v===null||!is_finite($v)) return null;
    return (int)max(0, min(100, round($v)));
  };
  $noacc = static function(string $s): string {
    $s = iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$s);
    return mb_strtolower(trim(preg_replace('/\s+/',' ',$s) ?? ''),'UTF-8');
  };

  $normP = static function($s) use ($noacc){
    $s = mb_strtolower(trim($noacc((string)$s)),'UTF-8');
    $s = str_replace(
      ['processos internos','cliente','clientes e mercado','cliente e mercado','mercado e clientes','clientes/mercado','clientes-mercado'],
      ['processos','clientes','clientes','clientes','clientes','clientes','clientes'],
      $s
    );
    // Colapsa por “contém” para as quatro chaves canônicas
    if (strpos($s,'finance') !== false) return 'financeiro';
    if (strpos($s,'client')  !== false || strpos($s,'mercad') !== false) return 'clientes';
    if (strpos($s,'process') !== false) return 'processos';
    if (strpos($s,'aprend')  !== false || strpos($s,'pesso') !== false || strpos($s,'gente') !== false) return 'aprendizado';
    return $s ?: '—';
  };


  // KRs a desconsiderar (igual ao mapa)
  $isKRDesconsiderado = static function(?string $status) use ($noacc): bool {
    if($status===null || $status==='') return false;
    $st = $noacc($status);
    $notStarted = ['nao iniciado','não iniciado','nao-iniciado','não-iniciado','not started','planejado','to do','todo','backlog','draft'];
    $cancelled = ['cancelado','cancelada','cancelled','canceled','abortado','abortada'];
    foreach($notStarted as $n){ if($st===$n) return true; }
    foreach($cancelled as $c){ if($st===$c) return true; }
    if (strpos($st,'cancel')!==false) return true;
    if (strpos($st,'nao inicia')!==false || strpos($st,'não inicia')!==false) return true;
    if (strpos($st,'not start')!==false) return true;
    return false;
  };

  $PILLAR_ORDER = ['aprendizado','processos','clientes','financeiro'];
  $PILLAR_COLORS = ['aprendizado'=>'#8e44ad','processos'=>'#2980b9','clientes'=>'#27ae60','financeiro'=>'#f39c12'];

  if ($_GET['ajax']==='report') {
    $payload = json_decode(file_get_contents('php://input'), true) ?: [];
    $dtIni = $payload['dt_inicio'] ?? '';
    $dtFim = $payload['dt_fim'] ?? '';
    $pilarF = trim((string)($payload['pilar'] ?? ''));
    $statusF= trim((string)($payload['status'] ?? ''));
    $texto = trim((string)($payload['q'] ?? ''));
    $donoF = $payload['dono'] ?? null;

    try{
      $userId=(int)$_SESSION['user_id'];
      $idCompany=null;
      $st=$pdo->prepare("SELECT id_company FROM usuarios WHERE id_user=:u LIMIT 1");
      $st->execute([':u'=>$userId]);
      $idCompany=$st->fetchColumn();
      if ($idCompany === false || $idCompany === null || $idCompany === '') { $idCompany = null; } else { $idCompany = (int)$idCompany; }

      $now = new DateTimeImmutable('now');
      if (!$dtIni) $dtIni = $now->format('Y-01-01');
      if (!$dtFim) $dtFim = $now->format('Y-12-31');

      $parts=[]; $bind=[];
      if ($idCompany !== null){ $parts[]="o.id_company=:c"; $bind[':c']=$idCompany; }
      if ($dtIni){ $parts[]="o.dt_criacao>=:di"; $bind[':di']=$dtIni; }
      if ($dtFim){ $parts[]="o.dt_criacao<=:df"; $bind[':df']=$dtFim; }
      if ($pilarF!==''){
        $pilarCanon = $normP($pilarF); // 'clientes', 'processos', etc.
        // Igualdade após “normalizar” também no SQL (sem acento) e singular→plural básico
        $parts[] = "LOWER(REPLACE(REPLACE(o.pilar_bsc,'processos internos','processos'),'cliente','clientes')) = LOWER(:p)";
        $bind[':p'] = $pilarCanon;
      }
      if ($statusF!==''){ $parts[]="o.status=:s"; $bind[':s']=$statusF; }
      if ($texto!==''){ $parts[]="(o.descricao LIKE :q OR o.observacoes LIKE :q)"; $bind[':q']="%$texto%"; }
      if ($donoF!==null && $donoF!==''){
        if(ctype_digit((string)$donoF)){ $parts[]="o.dono=:dn"; $bind[':dn']=(int)$donoF; }
        else { $parts[]="u.primeiro_nome LIKE :dnn"; $bind[':dnn']="%$donoF%"; }
      }
      $where = $parts ? ("WHERE ".implode(' AND ',$parts)) : "";

      $stO=$pdo->prepare("
        SELECT o.id_objetivo, o.descricao AS nome, o.pilar_bsc, o.status, o.dono,
               u.primeiro_nome AS dono_nome, u.ultimo_nome AS dono_sobrenome, o.dt_prazo
        FROM objetivos o
        LEFT JOIN usuarios u ON u.id_user=o.dono
        $where
        ORDER BY o.pilar_bsc, o.descricao
        LIMIT 32
      ");
      $stO->execute($bind);
      $objs=$stO->fetchAll();

      if(!$objs){
        $pilares=[];
        foreach($PILLAR_ORDER as $p){
          $pilares[]=['pilar'=>$p,'media'=>null,'krs'=>0,'krs_criticos'=>0,'count_obj'=>0,'color'=>$PILLAR_COLORS[$p]];
        }
        echo json_encode([
          'success'=>true,
          'items'=>[],
          'kpi'=>['objetivos'=>0,'media'=>null,'krs'=>0,'krs_criticos'=>0],
          'pilares'=>$pilares,
          'rank'=>[],
          'budget'=>['aprovado'=>0,'realizado'=>0,'saldo'=>0,'series_acc'=>[],'series_acc_plan'=>[]]
        ]);
        exit;
      }

      

      $objIds=array_column($objs,'id_objetivo');
      $in=implode(',',array_fill(0,count($objIds),'?'));

      $findCol=function(PDO $pdo,string $table,array $opts){
        foreach($opts as $c){
          try{$st=$pdo->prepare("SHOW COLUMNS FROM $table LIKE :c"); $st->execute([':c'=>$c]); if($st->fetch()) return $c;}catch(Throwable){}
        }
        return null;
      };

      // ===== KR base + campos dinâmicos =====
      $selKRCols = fn($n)=> $colExists($pdo,'key_results',$n) ? "kr.$n" : "NULL";
      $prazoParts=[];
      foreach(['dt_novo_prazo','data_fim','dt_prazo','data_limite','dt_limite','prazo','deadline'] as $pc){
        if($colExists($pdo,'key_results',$pc)) $prazoParts[]="kr.$pc";
      }
      $prazoExpr=$prazoParts? "COALESCE(".implode(',',$prazoParts).")":"NULL";
      $krStatusCol = $findCol($pdo,'key_results',['status','situacao','state','situacao_kr','status_kr']);
      $krMarginCol = $findCol($pdo,'key_results',['margem','tolerancia','tolerancia_pct','tolerancia_percent','desvio_tol']);
      $stKR=$pdo->prepare("
        SELECT
          kr.id_kr,
          kr.id_objetivo,
          COALESCE(kr.descricao,'') AS label,
          {$selKRCols('baseline')} AS baseline,
          {$selKRCols('meta')}     AS meta,
          {$selKRCols('direcao_metrica')} AS direcao_metrica,
          ".($krMarginCol ? "kr.$krMarginCol AS margem_kr" : "NULL AS margem_kr").",
          $prazoExpr AS prazo_final,
          ".($krStatusCol ? "kr.$krStatusCol AS status_kr" : "NULL AS status_kr")
        ." FROM key_results kr
        WHERE kr.id_objetivo IN ($in)
      ");
      $stKR->execute($objIds);
      $krs=$stKR->fetchAll();

      // ===== Milestones (ref, esperado, realizado, qtd de apontamentos) =====
      $msT = $tableExists($pdo,'milestones_kr') ? 'milestones_kr' : ($tableExists($pdo,'milestones')?'milestones':null);
      $msKr=$msDate=$msExp=$msReal=$msApCt=$msExpMin=$msExpMax=null;
      if($msT){
        $msKr = $findCol($pdo,$msT,['id_kr','kr_id','id_key_result','key_result_id']);
        $msDate = $findCol($pdo,$msT,['data_ref','dt_prevista','data_prevista','data','dt','competencia']);
        $msExp = $findCol($pdo,$msT,['valor_esperado','esperado','target','meta']);
        $msReal = $findCol($pdo,$msT,['valor_real_consolidado','valor_real','realizado','resultado','alcancado']);
        $msApCt = $findCol($pdo,$msT,['qtde_apontamentos','qtd_apontamentos','apontamentos','count_apontamentos','qt_apontamentos','qt_apont']);
        $msExpMin= $findCol($pdo,$msT,['valor_esperado_min','esperado_min','meta_min','target_min','faixa_min']);
        $msExpMax= $findCol($pdo,$msT,['valor_esperado_max','esperado_max','meta_max','target_max','faixa_max']);
      }

      $tz = new DateTimeZone('America/Sao_Paulo');
      $today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');

      $selectMs = function() use($msDate,$msReal,$msApCt,$msExp,$msExpMin,$msExpMax){
        $f = [];
        $f[] = $msDate ? "$msDate AS d" : "NULL AS d";
        $f[] = $msReal ? "$msReal AS r" : "NULL AS r";
        $f[] = $msApCt ? "$msApCt AS ap" : "NULL AS ap";
        $f[] = $msExp ? "$msExp AS e" : "NULL AS e";
        $f[] = $msExpMin ? "$msExpMin AS e_min" : "NULL AS e_min";
        $f[] = $msExpMax ? "$msExpMax AS e_max" : "NULL AS e_max";
        return implode(',', $f);
      };

      $stMsTodayWithApp = ($msT && $msKr && $msDate) ? $pdo->prepare("
        SELECT ".$selectMs()."
        FROM $msT
        WHERE $msKr=:id AND DATE($msDate)=:d
          AND (
              ".($msReal ? "$msReal IS NOT NULL AND $msReal<>''" : "0")."
              OR ".($msApCt ? "$msApCt>0" : "0")."
          )
        ORDER BY $msDate DESC LIMIT 1
      ") : null;

      $stMsLatestBefore = ($msT && $msKr && $msDate) ? $pdo->prepare("
        SELECT ".$selectMs()."
        FROM $msT
        WHERE $msKr=:id AND DATE($msDate)<:d
        ORDER BY $msDate DESC LIMIT 1
      ") : null;

      $stMsEarliestAfter = ($msT && $msKr && $msDate) ? $pdo->prepare("
        SELECT ".$selectMs()."
        FROM $msT
        WHERE $msKr=:id AND DATE($msDate) >= :d
        ORDER BY $msDate ASC LIMIT 1
      ") : null;

      // Utilidades numéricas
      $safeNum = static function($v){
        if ($v===null) return null;
        if (is_numeric($v)) return (float)$v;
        $s = str_replace(['.',','], ['','.'], preg_replace('/[^\d,.\-]+/','',$v));
        return is_numeric($s) ? (float)$s : null;
      };
      $parseMargin = static function($v) use ($safeNum){
        if($v===null || $v==='') return 0.10;
        if (is_string($v) && strpos($v,'%')!==false) {
          $n = $safeNum(str_replace('%','',$v));
          if($n===null) return 0.10;
          return max(0.0, min(1.0, $n/100.0));
        }
        $n = $safeNum($v);
        if($n===null) return 0.10;
        if($n>1.0) $n = $n/100.0;
        return max(0.0, min(1.0, $n));
      };
      $normDir = static function($s) use ($noacc){
        $s = $noacc((string)$s);
        if (strpos($s,'interval')!==false || strpos($s,'entre')!==false || strpos($s,'faixa')!==false) return 'intervalo';
        if (strpos($s,'menor')!==false || strpos($s,'baix')!==false || strpos($s,'redu')!==false || strpos($s,'down')!==false) return 'menor';
        return 'maior';
      };
      $colorFromS = static function(float $s, float $m): string {
        if ($s <= $m + 1e-12) return 'verde';
        if ($s <= 3*$m + 1e-12) return 'amarelo';
        return 'vermelho';
      };

      // ===== Acumuladores =====
      $byObj=[];       // objetivos
      $pAgg=[];        // agregados por pilar
      $pCount=[];      // contagem de objetivos por pilar

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

          '__sum'=>0.0,'__cnt'=>0,        // [FIX] acumuladores de % do objetivo (média dos KRs válidos)
          'pct'=>null,

          'kr_total'=>0,
          'kr_counts'=>['verde'=>0,'amarelo'=>0,'vermelho'=>0,'cinza'=>0],
        ];
        $pCount[$p] = ($pCount[$p] ?? 0) + 1;
        if(!isset($pAgg[$p])) $pAgg[$p]=['obj_sum'=>0.0,'obj_cnt'=>0,'krs'=>0,'krs_crit'=>0];
      }

      $totalKrs=0;
      $totalKrsCrit=0;

      // [FIX] para média geral (KPI "Média de progresso")
      $gSumObj=0.0; $gCntObj=0;

      // ===== Processa cada KR =====
      $eps = 1e-9;

      // [FIX] função de progresso do KR (0–100) com fallback
      $computeKrProgress = function(array $krRow, ?array $ref) use ($safeNum, $normDir, $eps): ?float {
        if(!$ref) return null;
        $R = $safeNum($ref['r'] ?? null);
        if ($R === null) return null;

        $base = $safeNum($krRow['baseline'] ?? null);
        $meta = $safeNum($krRow['meta'] ?? null);

        $E    = $safeNum($ref['e'] ?? null);
        $Emin = $safeNum($ref['e_min'] ?? null);
        $Emax = $safeNum($ref['e_max'] ?? null);

        $dir  = $normDir($krRow['direcao_metrica'] ?? '');

        // 1) Com baseline & meta
        if ($base !== null && $meta !== null && abs($meta - $base) > $eps) {
          if ($dir === 'menor') {
            $den = ($base - $meta);
            if ($den > $eps) {
              $pct = (($base - $R) / $den) * 100.0;
              return max(0.0, min(100.0, $pct));
            }
          } else { // maior (default)
            $den = ($meta - $base);
            if ($den > $eps) {
              $pct = (($R - $base) / $den) * 100.0;
              return max(0.0, min(100.0, $pct));
            }
          }
          // se não deu para usar baseline/meta, cai no fallback por esperado
        }

        // 2) Fallback por esperado (E / faixa)
        if ($dir === 'intervalo' && $Emin !== null && $Emax !== null) {
          if ($R >= $Emin - 1e-12 && $R <= $Emax + 1e-12) {
            return 100.0;
          } elseif ($R < $Emin) {
            $den = max($Emin, $eps);
            return max(0.0, min(100.0, 100.0 * ($R / $den)));
          } else { // R > Emax
            $den = max($R, $eps); // aproximação: quão perto está do teto
            return max(0.0, min(100.0, 100.0 * (max($Emax,$eps) / $den)));
          }
        } elseif ($E !== null) {
          if ($dir === 'menor') {
            if ($R <= 0) return 100.0;
            return max(0.0, min(100.0, 100.0 * ($E / max($R,$eps))));
          } else { // maior
            return max(0.0, min(100.0, 100.0 * ($R / max($E,$eps))));
          }
        }

        return null;
      };

      foreach($krs as $r){
        $oid=(int)$r['id_objetivo'];
        if(!isset($byObj[$oid])) continue;

        $krStatus = $r['status_kr'] ?? null;
        if ($isKRDesconsiderado(is_string($krStatus)?$krStatus:null)) {
          continue;
        }

        // Busca milestone de referência
        $ref = null;
        if($stMsTodayWithApp){
          $stMsTodayWithApp->execute([':id'=>$r['id_kr'], ':d'=>$today]);
          $ref = $stMsTodayWithApp->fetch() ?: null;
        }
        if(!$ref && $stMsLatestBefore){
          $stMsLatestBefore->execute([':id'=>$r['id_kr'], ':d'=>$today]);
          $ref = $stMsLatestBefore->fetch() ?: null;
        }

        $farol = 'vermelho';

        if(!$ref){
          // Sem ref passada: se houver futuro => cinza; senão vermelho
          if ($stMsEarliestAfter) {
            $stMsEarliestAfter->execute([':id'=>$r['id_kr'], ':d'=>$today]);
            $futureFirst = $stMsEarliestAfter->fetch() ?: null;
            $farol = $futureFirst ? 'cinza' : 'vermelho';
          } else {
            $farol = 'vermelho';
          }
        } else {
          $R = $safeNum($ref['r'] ?? null);
          $ap = $safeNum($ref['ap'] ?? null);
          $temApont = ( $R !== null || ($ap !== null && $ap > 0) );

          if(!$temApont){
            $farol = 'vermelho';
          } else {
            $dir = $normDir($r['direcao_metrica'] ?? '');
            $m   = $parseMargin($r['margem_kr'] ?? null);

            $E    = $safeNum($ref['e'] ?? null);
            $Emin = $safeNum($ref['e_min'] ?? null);
            $Emax = $safeNum($ref['e_max'] ?? null);

            $s = 0.0;
            if ($dir === 'intervalo' && $Emin !== null && $Emax !== null) {
              if ($R !== null && $R >= $Emin - 1e-12 && $R <= $Emax + 1e-12) {
                $s = 0.0;
              } elseif ($R !== null && $R < $Emin) {
                $den = max(abs($Emin), $eps);
                $s = max(0.0, ($Emin - $R) / $den);
              } elseif ($R !== null && $R > $Emax) {
                $den = max(abs($Emax), $eps);
                $s = max(0.0, ($R - $Emax) / $den);
              } else { $s = 1.0; }
            } else {
              if ($E === null) { $s = 1.0; }
              else {
                $den = max(abs($E), $eps);
                if ($dir === 'menor') { $s = ($R === null) ? 1.0 : max(0.0, ($R - $E) / $den); }
                else { $s = ($R === null) ? 1.0 : max(0.0, ($E - $R) / $den); }
              }
            }
            $farol = $colorFromS((float)$s, (float)$m);
          }
        }

        // Contagens por farol
        $byObj[$oid]['kr_counts'][$farol] = ($byObj[$oid]['kr_counts'][$farol] ?? 0) + 1;
        if($farol==='vermelho') $totalKrsCrit++;
        $totalKrs++;
        $byObj[$oid]['kr_total']++;

        $p = $byObj[$oid]['pilar'];
        $pAgg[$p]['krs']++;
        if($farol==='vermelho') $pAgg[$p]['krs_crit']++;

        // [FIX] Progresso do KR (0–100) para média do objetivo/pilares/ranking/KPI
        $krPct = $computeKrProgress($r, $ref);
        if ($krPct !== null) {
          $byObj[$oid]['__sum'] += max(0.0, min(100.0, (float)$krPct));
          $byObj[$oid]['__cnt'] += 1;
        }
      }

      // ===== Consolida objetivos e pilares (agora com pct calculado) =====
      foreach($byObj as &$o){
        $o['pct'] = ($o['__cnt']>0) ? (int)round($o['__sum']/$o['__cnt']) : null;
        if ($o['pct'] !== null) {
          $pk = $o['pilar'];
          $pAgg[$pk]['obj_sum'] += $o['pct'];
          $pAgg[$pk]['obj_cnt']++;

          // [FIX] acumula para KPI "Média de progresso"
          $gSumObj += $o['pct'];
          $gCntObj++;
        }
        unset($o['__sum'],$o['__cnt']);
      }
      unset($o);

      // ===== KPIs =====
      $kpi = [
        'objetivos'=>count($byObj),
        'media' => null,                   // será calculado pelos pilares
        'krs' => $totalKrs,
        'krs_criticos' => $totalKrsCrit
      ];

      // ===== Pilares (média de % dos objetivos do pilar) =====
      $pilares=[];
      foreach($PILLAR_ORDER as $p){
        $media = ($pAgg[$p]['obj_cnt']??0)>0 ? (int)round($pAgg[$p]['obj_sum']/$pAgg[$p]['obj_cnt']) : null;
        $pilares[]=[
          'pilar'=>$p,
          'media'=>$media,
          'krs'=> (int)($pAgg[$p]['krs']??0),
          'krs_criticos'=> (int)($pAgg[$p]['krs_crit']??0),
          'count_obj'=> (int)($pCount[$p] ?? 0),
          'color'=>$PILLAR_COLORS[$p]
        ];
      }

      // === KPI 'Média de progresso' = média aritmética das médias dos pilares (apenas os que existem)
      $vals = [];
      foreach ($pilares as $pi) {
          if ($pi['media'] !== null) {
              $vals[] = (float) $pi['media']; // já é 0–100 inteiro
          }
      }
      $kpi['media'] = $vals ? (int) round(array_sum($vals) / count($vals)) : null;


      // ===== Ranking por dono (média dos objetivos do dono) =====
      $rankMap=[];
      foreach($byObj as $o){
        $id=$o['dono_id'] ?: 0;
        $nm=$o['dono'] ?: '—';
        if(!isset($rankMap[$id])) $rankMap[$id]=['id'=>$id,'nome'=>$nm,'sum'=>0,'cnt'=>0,'obj_count'=>0,'kr_count'=>0,'kr_critico'=>0];
        $rankMap[$id]['obj_count']++;
        $rankMap[$id]['kr_count'] += (int)$o['kr_total'];
        $rankMap[$id]['kr_critico'] += (int)($o['kr_counts']['vermelho'] ?? 0);
        if($o['pct']!==null){
          $rankMap[$id]['sum']+=$o['pct'];
          $rankMap[$id]['cnt']++;
        }
      }
      $rank = array_values(array_map(function($r){
        $r['media']=$r['cnt']?(int)round($r['sum']/$r['cnt']):0;
        unset($r['sum'],$r['cnt']);
        return $r;
      }, $rankMap));
      usort($rank, fn($a,$b)=> ($b['media']<=>$a['media']) ?: ($b['kr_critico']<=>$a['kr_critico']));

      // ===== Ordenação dos objetivos e fatia =====
      $items = array_values($byObj);
      $order = array_flip(['financeiro','clientes','processos','aprendizado']);
      usort($items, function($a,$b) use ($order){
        $ia = $order[$a['pilar']] ?? 999;
        $ib = $order[$b['pilar']] ?? 999;
        return $ia <=> $ib ?: strnatcasecmp($a['nome'],$b['nome']);
      });
      $items = array_slice($items,0,16);

      // ===== Budget =====
      $budget = ['aprovado'=>0,'realizado'=>0,'saldo'=>0,'series_acc'=>[],'series_acc_plan'=>[]];
      if ($tableExists($pdo,'orcamentos')) {
        $dateStart = $dtIni;
        $dateEnd = $dtFim;
        $companyFilter = ($idCompany !== null) ? " AND obj.id_company=:cid" : "";
        $bindB = [':dini'=>$dateStart, ':dfim'=>$dateEnd];
        if ($idCompany !== null) { $bindB[':cid'] = $idCompany; }

        $stA=$pdo->prepare("
          SELECT COALESCE(SUM(o.valor),0)
          FROM orcamentos o
          LEFT JOIN iniciativas i ON i.id_iniciativa=o.id_iniciativa
          LEFT JOIN key_results kr ON kr.id_kr=i.id_kr
          LEFT JOIN objetivos obj ON obj.id_objetivo=kr.id_objetivo
          WHERE o.data_desembolso BETWEEN :dini AND :dfim{$companyFilter}
        ");
        $stA->execute($bindB);
        $budget['aprovado']=(float)$stA->fetchColumn();

        $stR=$pdo->prepare("
          SELECT COALESCE(SUM(od.valor),0)
          FROM orcamentos_detalhes od
          LEFT JOIN orcamentos o ON o.id_orcamento=od.id_orcamento
          LEFT JOIN iniciativas i ON i.id_iniciativa=o.id_iniciativa
          LEFT JOIN key_results kr ON kr.id_kr=i.id_kr
          LEFT JOIN objetivos obj ON obj.id_objetivo=kr.id_objetivo
          WHERE od.data_pagamento BETWEEN :dini AND :dfim{$companyFilter}
        ");
        $stR->execute($bindB);
        $budget['realizado']=(float)$stR->fetchColumn();

        $stPlan=$pdo->prepare("
          SELECT DATE_FORMAT(o.data_desembolso,'%Y-%m') AS comp, SUM(o.valor) AS v
          FROM orcamentos o
          LEFT JOIN iniciativas i ON i.id_iniciativa=o.id_iniciativa
          LEFT JOIN key_results kr ON kr.id_kr=i.id_kr
          LEFT JOIN objetivos obj ON obj.id_objetivo=kr.id_objetivo
          WHERE o.data_desembolso BETWEEN :dini AND :dfim{$companyFilter}
          GROUP BY comp ORDER BY comp
        ");
        $stPlan->execute($bindB);
        $mapP=[];
        foreach($stPlan as $r){ $mapP[$r['comp']] = (float)$r['v']; }

        $stReal=$pdo->prepare("
          SELECT DATE_FORMAT(od.data_pagamento,'%Y-%m') AS comp, SUM(od.valor) AS v
          FROM orcamentos_detalhes od
          LEFT JOIN orcamentos o ON o.id_orcamento=od.id_orcamento
          LEFT JOIN iniciativas i ON i.id_iniciativa=o.id_iniciativa
          LEFT JOIN key_results kr ON kr.id_kr=i.id_kr
          LEFT JOIN objetivos obj ON obj.id_objetivo=kr.id_objetivo
          WHERE od.data_pagamento BETWEEN :dini AND :dfim{$companyFilter}
          GROUP BY comp ORDER BY comp
        ");
        $stReal->execute($bindB);
        $mapR=[];
        foreach($stReal as $r){ $mapR[$r['comp']] = (float)$r['v']; }

        $seriesP=[]; $seriesR=[];
        $d=new DateTimeImmutable($dateStart);
        $end=new DateTimeImmutable($dateEnd);
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
        $budget['series_acc'] = $seriesR;
        $budget['saldo'] = max(0, $budget['aprovado'] - $budget['realizado']);
      }

      echo json_encode(['success'=>true,'items'=>$items,'kpi'=>$kpi,'pilares'=>$pilares,'rank'=>$rank,'budget'=>$budget]);
      exit;

    }catch(Throwable $e){
      error_log('relatorios_okrs/report: '.$e->getMessage());
      echo json_encode(['success'=>false,'error'=>'Falha ao montar relatório']);
      exit;
    }
  }

  echo json_encode(['success'=>false,'error'=>'Ação inválida']);
  exit;
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
  $st->execute([':u'=>(int)$_SESSION['user_id']]);
  $exportUserName=$st->fetchColumn() ?: $exportUserName;
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
.sidebar{ background:linear-gradient(180deg,#0f1420,#0a0f16) !important; color:#eaeef6 !important; border-right:1px solid var(--border) !important; }
.sidebar a{ color:#cbd5e1 !important; }
.sidebar .active, .sidebar .current{ color:#fff !important; }
.header{ background:#fff !important; color:#eaeef6 !important; }
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
.kpi-desc{ font-size:.62rem; font-weight:600; color:#cbd5e1; display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; overflow:hidden; line-height:1.1; max-height: calc(1.1em * 3); }
.kpi-desc:hover{ -webkit-line-clamp:unset; max-height:none; }
.kpi-counts{ display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.kpi-counts .big{ font-size:1.24rem; font-weight:900; }
.kpi-progress{ height:6px; background:#0b1018; border:1px solid #223047; border-radius:999px; overflow:hidden; margin-top:4px; }
.kpi-progress > span{ display:block; height:100%; background:#22c55e; width:0%; transition:width .7s ease; }
.row-analytics{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:8px;
  align-items:stretch;       /* <- estica os dois cards igualmente na tela */
}
.bsc-wrap{ background:#0f1420; border:1px solid var(--border); border-radius:8px; padding:8px; color:#eaeef6; }
.bsc-head{ display:flex; align-items:center; justify-content:space-between; margin-bottom:6px; font-size:.8rem; }
.bsc-chart{ display:grid; grid-template-columns:38px 1fr; gap:8px; align-items:end; height:200px; position:relative; }
.bsc-y{ display:flex; flex-direction:column; justify-content:space-between; align-items:flex-end; height:100%; padding:2px 0; font-size:.68rem; color:#94a3b8; }
.bsc-plot{ position:relative; height:100%; }
.bsc-grid{ position:absolute; inset:0 0 22px 0; background: linear-gradient(to top, rgba(255,255,255,.08) 0 1px, transparent 1px) 0 0/100% 25%, linear-gradient(to top, rgba(255,255,255,.04) 0 1px, transparent 1px) 0 0/100% 5%; pointer-events:none; border-radius:8px 8px 0 0; }
.bsc-cols{ position:absolute; inset:0 0 22px 0; display:flex; align-items:flex-end; justify-content:space-around; gap:8px; padding:0 6px; }
.bsc-col{ flex:1; display:flex; flex-direction:column; align-items:center; justify-content:flex-end; min-width:46px; height:100%; }
.bsc-bar{ width:30px; min-width:26px; height:0%; background: var(--col,#60a5fa); border:1px solid rgba(255,255,255,.18); border-bottom-color: rgba(255,255,255,.28); border-radius:8px 8px 6px 6px; transition:height .9s cubic-bezier(.2,.7,.2,1); position:relative; }
.bsc-bar::after{ content: attr(data-val) '%'; position:absolute; left:50%; transform:translate(-50%,6px); top:-18px; background:#0c1118; border:1px solid #1f2a44; border-radius:6px; padding:1px 4px; font-size:.62rem; font-weight:900; color:#eaeef6; opacity:0; transition:opacity .4s ease, transform .4s ease; pointer-events:none; }
.bsc-bar.show::after{ opacity:1; transform:translate(-50%,0); }
.bsc-labels{ position:absolute; left:0; right:0; bottom:0; height:22px; display:flex; align-items:center; justify-content:space-around; gap:8px; padding:0 6px; }
.bsc-label{ font-size:.7rem; color:#cbd5e1; text-align:center; width:58px; line-height:1.05; }
.bsc-sub{ font-size:.62rem; color:#94a3b8; }
.rank{ background:#0e131a; border:1px solid var(--border); border-radius:8px; padding:8px; color:#eaeef6; display:flex;
  flex-direction:column;
  overflow:hidden; /* evita que force crescimento do grid */
  min-height:0;
  height:100%;}
#rank_list{
  flex:1 1 auto;
  overflow:auto;   /* rolagem quando tiver muitos donos */
  min-height:0;
}
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
.obj-grid{
  display:flex;            /* linhas empilhadas */
  flex-direction:column;
  gap:8px;
}
.obj-row{
  display:grid;            /* cada linha vira um grid próprio */
  grid-template-columns:repeat(var(--cols,4), 1fr); /* n colunas na linha */
  gap:8px;
}
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
.dot.g{ background:#22c55e; }
.dot.y{ background:#f59e0b; }
.dot.r{ background:#ef4444; }
.dot.c{ background:#9ca3af; }
.obj-more{ display:none; font-size:.66rem; color:#cbd5e1; gap:4px; }
.obj-card:hover .obj-more{ display:grid; }
.kr-line{ display:flex; align-items:flex-start; gap:6px; }
.kr-line .kr-dot{ width:7px; height:7px; border-radius:50%; display:inline-block; margin-top: 2px; }
.kr-dot.g{ background:#22c55e; }
.kr-dot.y{ background:#f59e0b; }
.kr-dot.r{ background:#ef4444; }
.kr-dot.c{ background:#9ca3af; }
.kr-line .kr-txt{ flex: 1 1 auto; min-width: 0; white-space: normal; word-break: break-word; overflow: hidden; }
.kr-line .kr-val{ flex: 0 0 auto; white-space: nowrap; margin-left:6px; }
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
@media print{
  @page{ margin:10mm; }
  *{ -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
  .sidebar, .header, .crumbs, .share-fab, .no-print, .filters-block, #chatPanel, .chat-panel, .chat-container, #chat, .drawer-chat, .chat-fab, .chat-bubble, .chat-avatar, .chat-widget, .chat-toggle, [data-chat], [class*="chat"], [id*="chat"] { display:none !important; }
  body{ background:#fff !important; }
  .content{ margin:0 !important; }
  main.report{ padding:0 !important; }
  .row-analytics{ display:grid; grid-template-columns:1fr 1fr; gap:8px; align-items:stretch; }
  .kpi-grid{ grid-template-columns: repeat(4, 1fr) !important; }
  .rep-card .kpi-value{ font-size:.82rem !important; }
  .rep-card .kpi-head{ font-size:.66rem !important; }
  .obj-grid{ grid-template-columns: repeat(4, 1fr) !important; }
  .tiny-dots .dot{ border-color:transparent !important; }
  .bmini-grid{ gap:6px !important; }
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

    <!-- Filtros -->
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
        <div class="kpi-head"><span>Média de progresso</span><i class="fa-solid fa-gauge-high"></i></div>
        <div class="kpi-value" id="kpi_media">—%</div>
        <div class="kpi-progress" id="kpi_media_bar"><span></span></div>
      </div>
      <div class="kpi"><div class="kpi-head"><span>Objetivo líder</span><i class="fa-solid fa-trophy"></i></div><div class="kpi-value" id="kpi_lider">—</div></div>
      <div class="kpi"><div class="kpi-head"><span>Pior objetivo</span><i class="fa-regular fa-face-frown"></i></div><div class="kpi-value" id="kpi_pior">—</div></div>
    </div>
  </section>

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
/* =================== Helpers / Constantes =================== */
const $ = (s,p)=> (p||document).querySelector(s);
const PILLAR_COLORS = { 'aprendizado':'#8e44ad', 'processos':'#2980b9', 'clientes':'#27ae60', 'financeiro':'#f39c12' };
const PILLAR_ORDER = ['aprendizado','processos','clientes','financeiro'];
const PILLAR_ORDER_REVERSED = ['financeiro','clientes','processos','aprendizado'];
const PILLAR_ICONS = { 'aprendizado':'fa-solid fa-graduation-cap', 'processos' :'fa-solid fa-gears', 'clientes' :'fa-solid fa-users', 'financeiro' :'fa-solid fa-coins' };
const FAROL_COLORS = { verde:'#22c55e', amarelo:'#f59e0b', vermelho:'#ef4444', cinza:'#9ca3af' };

function pillarColor(key){ return PILLAR_COLORS[String(key).toLowerCase()] || '#60a5fa'; }
function pillarIcon(key){ return PILLAR_ICONS[String(key).toLowerCase()] || 'fa-solid fa-layer-group'; }
function strnatcasecmp(a,b){ return (a||'').toString().localeCompare((b||'').toString(),'pt-BR',{numeric:true,sensitivity:'base'}); }
function esc(s){ if(s===null||s===undefined) return ''; return String(s).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'","&#039;"); }
function labelPillar(k){ switch(String(k).toLowerCase()){ case 'aprendizado': return 'Aprendizado'; case 'processos': return 'Processos'; case 'clientes': return 'Clientes'; case 'financeiro': return 'Financeiro'; default: return k||'—'; } }
function contrastText(hex){ if(!hex) return '#fff'; const c=hex.replace('#',''); const r=parseInt(c.substring(0,2),16); const g=parseInt(c.substring(2,4),16); const b=parseInt(c.substring(4,6),16); const yiq=((r*299)+(g*587)+(b*114))/1000; return yiq >= 200 ? '#0b0f14' : '#ffffff'; }
function avatarHTML(userId){
  const PNG=`/OKR_system/assets/img/avatars/${userId}.png`;
  const JPG=`/OKR_system/assets/img/avatars/${userId}.jpg`;
  const JPEG=`/OKR_system/assets/img/avatars/${userId}.jpeg`;
  return `<img src="${PNG}" onerror="this.onerror=null; this.src='${JPG}'; this.onerror=function(){this.src='${JPEG}';};" alt="">`;
}

// ==== helpers para detalhe (mantidos) ====
function normalizeNoAccents(s){ return (s||'').normalize('NFD').replace(/[\u0300-\u036f]/g, ''); }
function isNotStartedKR(kr){
  const s = normalizeNoAccents(String(kr?.status || '').toLowerCase());
  return s.includes('nao iniciado') || s.includes('nao-iniciado') || s.includes('planejad') || s.includes('not started');
}
function computeObjectiveProgress(krs){
  let sA = 0, nA = 0;
  for (const kr of (krs || [])) {
    if (isNotStartedKR(kr)) continue;
    const pa = kr?.progress?.pct_atual;
    if (Number.isFinite(pa)) { sA += Math.max(0, Math.min(100, pa)); nA++; }
  }
  const pctA = nA ? Math.round(sA / nA) : null;
  return { pctA };
}

// UI helpers
function styleObjNumberChip(el, farol){
  const key = String(farol||'cinza').toLowerCase();
  const col = FAROL_COLORS[key] || FAROL_COLORS.cinza;
  el.style.background = col; el.style.borderColor = col; el.style.color = contrastText(col);
}
function setObjCardProgress(card, pct){
  const bar = card.querySelector('.obj-prog > span');
  const label = card.querySelector('.obj-foot > span strong');
  const v = (pct==null || isNaN(pct)) ? null : Math.max(0, Math.min(100, Number(pct)));
  if (bar) bar.style.width = (v==null ? '0%' : (v + '%'));
  if (label) label.textContent = (v==null ? '—' : (v + '%'));

  // [NOVO] persistir no DOM e reagendar recálculo do BSC
  card.dataset.pct = (v==null ? '' : String(v));
  scheduleRecalcBSC();
}

function setObjCardFarol(card, farol){
  const chip = card.querySelector('.obj-badge');
  styleObjNumberChip(chip, farol);
}

function syncRankHeight(){
  const rank = document.getElementById('rank_box');
  const list = document.getElementById('rank_list');
  if (!rank || !list) return;

  const head = rank.querySelector('.head');
  const styles = getComputedStyle(rank);
  const pad = (parseFloat(styles.paddingTop||0) + parseFloat(styles.paddingBottom||0)) || 0;
  const headH = head ? head.getBoundingClientRect().height : 0;

  // como o .rank agora tem height:100% via CSS, a conta usa a própria altura dele
  const h = Math.max(0, rank.clientHeight - headH - pad);
  list.style.maxHeight = h + 'px';
  list.style.overflow = 'auto';
}
window.addEventListener('resize', ()=> requestAnimationFrame(syncRankHeight));


// [NOVO] Acumuladores globais para KPI/Ranking (alimentados do backend)
let __krFarolByObj = new Map(); // objId -> {verde, amarelo, vermelho}
let __krCritByOwner = new Map(); // donoId -> qtd KRs vermelhos
let __rankData = [];
let __rankPosMap = new Map();
let __rankOrderDesc = true;

function recomputeAggregates(){
  let totalRed = 0;
  const ownerRed = new Map();
  __krFarolByObj.forEach((c, objId)=>{
    const red = Number(c?.vermelho||0);
    totalRed += red;
    const owner = (window.__ownerByObj && window.__ownerByObj.get(String(objId))) || 0;
    ownerRed.set(owner, (ownerRed.get(owner)||0) + red);
  });
  __krCritByOwner = ownerRed;

  if(Array.isArray(__rankData) && __rankData.length){
    __rankData = __rankData.map(r=>{
      const id = r.id ?? r.dono_id ?? 0;
      return { ...r, kr_critico: ownerRed.get(id) || r.kr_critico || 0 };
    });
    drawRanking();
  }
}

function renderKPIs(itemsOrdered, data){
  const objN = data.kpi?.objetivos ?? 0;
  const krN = data.kpi?.krs ?? 0;
  const krCritN = data.kpi?.krs_criticos ?? 0;

  $('#kpi_obj').innerHTML = `
    <div class="kpi-counts">
      <span class="kpi-big" id="kpi_obj_n">${objN}</span>
      <span class="chip-mini"><i class="fa-solid fa-list-check"></i> <span id="kpi_krs_total">${krN}</span> KRs</span>
      <span class="chip-mini chip-danger" title="KRs críticos (vermelho)">
        <i class="fa-solid fa-triangle-exclamation"></i> <span id="kpi_krs_crit">${krCritN}</span>
      </span>
    </div>`;

  const mediaPct = (data.kpi?.media ?? null);
  const mediaTxt = (mediaPct===null || isNaN(mediaPct)) ? '—' : `${mediaPct}%`;
  $('#kpi_media').innerHTML = `<span class="kpi-big">${mediaTxt}</span>`;
  const bar = $('#kpi_media_bar > span');
  if (bar){
    const v = (mediaPct===null || isNaN(mediaPct)) ? 0 : Math.max(0, Math.min(100, parseInt(mediaPct,10) || 0));
    bar.style.width = '0%';
    requestAnimationFrame(()=> setTimeout(()=> { bar.style.width = v + '%'; }, 20));
  }

  // líder/pior serão obtidos do array itemsOrdered (pct já vem do backend)
  const valid = (itemsOrdered||[]).filter(it => it.pct !== null && it.pct !== undefined);
  if (valid.length){
    const best = [...valid].sort((a,b)=> b.pct - a.pct)[0];
    const worst = [...valid].sort((a,b)=> a.pct - b.pct)[0];
    const bestIdx = (window.__objIndexMap && window.__objIndexMap.get(best.id)) || 1;
    const worstIdx = (window.__objIndexMap && window.__objIndexMap.get(worst.id)) || 1;
    const objNoB = String(bestIdx).padStart(2,'0');
    const objNoW = String(worstIdx).padStart(2,'0');
    const pKeyB = String(best.pilar||'').toLowerCase();
    const pKeyW = String(worst.pilar||'').toLowerCase();
    const iconB = PILLAR_ICONS[pKeyB] || 'fa-solid fa-layer-group';
    const iconW = PILLAR_ICONS[pKeyW] || 'fa-solid fa-layer-group';
    const colorB = PILLAR_COLORS[pKeyB] || '#60a5fa';
    const colorW = PILLAR_COLORS[pKeyW] || '#60a5fa';

    $('#kpi_lider').innerHTML = `
      <div class="kpi-obj">
        <i class="${iconB}" style="color:${colorB}"></i>
        <span class="obj-no">OBJ ${objNoB}</span>
        <span style="margin-left:auto; font-weight:900">${best.pct}%</span>
      </div>
      <div class="kpi-desc" title="${esc(best.nome||'—')}">${esc(best.nome||'—')}</div>`;

    $('#kpi_pior').innerHTML = `
      <div class="kpi-obj">
        <i class="${iconW}" style="color:${colorW}"></i>
        <span class="obj-no">OBJ ${objNoW}</span>
        <span style="margin-left:auto; font-weight:900">${worst.pct}%</span>
      </div>
      <div class="kpi-desc" title="${esc(worst.nome||'—')}">${esc(worst.nome||'—')}</div>`;
  } else {
    $('#kpi_lider').textContent = '—';
    $('#kpi_pior').textContent = '—';
  }
}

function renderBSC(pilares){
  const by = {};
  (pilares||[]).forEach(p=>{ if(!p) return; by[(p.pilar||'').toLowerCase()] = p; });

  const cols = $('#bsc_cols'); cols.innerHTML='';
  const labels = $('#bsc_labels'); labels.innerHTML='';

  PILLAR_ORDER.forEach(key=>{
    const p = by[key] || { pilar:key, media:null, count_obj:0, color:PILLAR_COLORS[key] };
    const empty = (p.media===null || isNaN(p.media));
    const val = empty ? 0 : Math.max(0, Math.min(100, parseInt(p.media,10)));
    const col = document.createElement('div');
    col.className='bsc-col';
    col.setAttribute('data-pilar', key);
    col.innerHTML =
      `<div class="bsc-bar ${empty?'empty':''}"
            id="bsc_bar_${key}"
            style="--col:${p.color || PILLAR_COLORS[key]};"
            data-val="${empty?'—':val}"></div>`;
    cols.appendChild(col);

    const lab = document.createElement('div');
    lab.className='bsc-label';
    lab.setAttribute('data-pilar', key);
    lab.id = `bsc_lab_${key}`;
    lab.innerHTML = `<div>${labelPillar(key)}</div><div class="bsc-sub">${(p.count_obj||0)}-Objs</div>`;
    labels.appendChild(lab);

    requestAnimationFrame(()=>{
      const bar=col.querySelector('.bsc-bar');
      setTimeout(()=>{ bar.style.height = (empty ? '0%' : (val + '%')); bar.classList.add('show'); }, 20);
    });
  });
}

// [NOVO] Recalcular BSC a partir dos cards de objetivos
let __bscRecalcTimer = null;
function scheduleRecalcBSC(){
  clearTimeout(__bscRecalcTimer);
  __bscRecalcTimer = setTimeout(recomputeBSCFromCards, 60);
}

function recomputeBSCFromCards(){
  const sums = {aprendizado:0, processos:0, clientes:0, financeiro:0};
  const cnts = {aprendizado:0, processos:0, clientes:0, financeiro:0};

  document.querySelectorAll('.obj-card').forEach(card=>{
    const pk = (card.dataset.pilar||'').toLowerCase();
    const pct = Number(card.dataset.pct);
    if (Number.isFinite(pct) && pk in sums) {
      sums[pk] += pct;
      cnts[pk] += 1;
    }
  });

  const avgs = {};
  PILLAR_ORDER.forEach(k=>{
    avgs[k] = cnts[k] ? Math.round(sums[k]/cnts[k]) : null; // <<< null quando não houver objetivos
  });
  avgs.__cnts = cnts; // carregar cnts junto

  applyBSCValues(avgs);
}

function applyBSCValues(avgs){
  PILLAR_ORDER.forEach(key=>{
    const bar = document.getElementById(`bsc_bar_${key}`) ||
                document.querySelector(`.bsc-bar[data-pilar="${key}"]`);
    if (!bar) return;
    const v = Math.max(0, Math.min(100, Number(avgs[key]||0)));
    // Se o pilar não teve objetivos na recontagem, trate como null para o KPI
    const isNull = !(Number.isFinite(Number(avgs[key])) && Number(avgs[key]) > 0) && (avgs.__cnts && (avgs.__cnts[key]||0)===0);
    bar.setAttribute('data-val', isNull ? '—' : v);
    bar.style.height = (isNull ? '0' : v) + '%';
    bar.classList.add('show');
  });

  // === NOVO: recalcular KPI a partir dos pilares já aplicados (ignorando os '—')
  const vals = PILLAR_ORDER.map(k=>{
    const bar = document.getElementById(`bsc_bar_${k}`) ||
                document.querySelector(`.bsc-bar[data-pilar="${k}"]`);
    const dv = bar ? bar.getAttribute('data-val') : null;
    const n  = dv == null || dv === '—' ? NaN : Number(dv);
    return Number.isFinite(n) ? n : null;
  }).filter(v => v !== null);

  const mean = vals.length ? Math.round(vals.reduce((a,b)=>a+b,0)/vals.length) : null;
  const kpiEl = $('#kpi_media');
  const barEl = $('#kpi_media_bar > span');
  if (kpiEl) kpiEl.innerHTML = (mean==null ? '—%' : `<span class="kpi-big">${mean}%</span>`);
  if (barEl)  barEl.style.width = (mean==null ? '0' : mean) + '%';
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
    const firstLast = nome.trim().split(/\s+/).filter(Boolean).reduce((acc, part, i, arr) => (i === 0 ? [part] : (i === arr.length - 1 ? [acc[0], part] : acc)), ['—']).join(' ');

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
      <div class="val" title="${esc(nome)}">${media}%</div>`;
    box.appendChild(row);
  });
  requestAnimationFrame(()=> syncRankHeight());
}

function renderObjetivos(items){
  const grid = $('#obj_grid'); grid.innerHTML='';
  if(!window.__ownerByObj) window.__ownerByObj = new Map();
  window.__ownerByObj.clear();

  (items||[]).forEach((o, i)=>{
    const idx = (window.__objIndexMap && window.__objIndexMap.get(o.id)) || (i+1);
    const objNo = String(idx).padStart(2,'0');
    const pKey = String(o.pilar||'').toLowerCase();
    const color = pillarColor(pKey);
    const icon = pillarIcon(pKey);
    const donoFirst = (o.dono||'—').toString().trim().split(/\s+/)[0] || '—';
    const counts = (o.kr_counts || {verde:0,amarelo:0,vermelho:0});
    const card = document.createElement('div');
    card.className='obj-card';
    card.setAttribute('data-obj-id', String(o.id));
    card.setAttribute('data-dono-id', String(o.dono_id || 0));
    card.style.borderLeft = `6px solid ${color}`;
    card.dataset.pilar = pKey;
    if (o.pct != null) {
      card.dataset.pct = String(Math.max(0, Math.min(100, o.pct)));
    }

    window.__ownerByObj.set(String(o.id), Number(o.dono_id || 0));

    card.innerHTML = `
      <span class="obj-badge" title="Farol do objetivo">OBJ ${objNo}</span>
      <div class="obj-title"><i class="${icon}" style="color:${color}"></i> ${esc(o.nome||'Objetivo')}</div>
      <div class="obj-meta">
        <span class="pill"><i class="fa-regular fa-user"></i> ${esc(donoFirst)}</span>
        <span class="pill"><i class="fa-regular fa-calendar-days"></i> ${esc(o.prazo||'—')}</span>
      </div>
      <div class="obj-prog"><span style="width:0%"></span></div>
      <div class="obj-foot">
        <span><strong>${(o.pct==null?'—':(o.pct+'%'))}</strong></span>
        <span class="tiny-dots" title="KRs: 🟢 No trilho | 🟡 Atenção | 🔴 Crítico | ⚪ Sem apontamento">
          <span class="dot g"></span> ${counts.verde||0}
          <span class="dot y" style="margin-left:6px"></span> ${counts.amarelo||0}
          <span class="dot r" style="margin-left:6px"></span> ${counts.vermelho||0}
          <span class="dot c" style="margin-left:6px"></span> ${counts.cinza||0}
        </span>
      </div>
      <div class="obj-more"></div>`;

    styleObjNumberChip(card.querySelector('.obj-badge'), 'cinza');
    const pctForBar = (o.pct==null ? 0 : Math.max(0,Math.min(100, Number(o.pct))));
    const barEl = card.querySelector('.obj-prog > span');
    if (barEl) { requestAnimationFrame(()=> setTimeout(()=> { barEl.style.width = pctForBar + '%'; }, 20)); }
    grid.appendChild(card);

    // semear agregados por objetivo (para KPI/Ranking já de cara)....

    <div class="kpi-grid">
      <div class="kpi">
        <div class="kpi-head"><span>Objetivos</span><i class="fa-regular fa-flag"></i></div>
        <div class="kpi-value" id="kpi_obj">—</div>
      </div>

      <div class="kpi">
        <div class="kpi-head"><span>Média de progresso</span><i class="fa-solid fa-gauge-high"></i></div>
        <div class="kpi-value" id="kpi_media">—%</div>
        <div class="kpi-progress" id="kpi_media_bar"><span></span></div>
      </div>

      <div class="kpi">
        <div class="kpi-head"><span>Objetivo líder</span><i class="fa-solid fa-trophy"></i></div>
        <div class="kpi-value" id="kpi_lider">—</div>
      </div>

      <div class="kpi">
        <div class="kpi-head"><span>Pior objetivo</span><i class="fa-regular fa-face-frown"></i></div>
        <div class="kpi-value" id="kpi_pior">—</div>
      </div>
    </div>
  </section>

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
        <div class="spark-wrap"><svg id="b_spark" class="spark" viewBox="0 0 300 120" preserveAspectRatio="none"></svg></div>
      </div>
    </div>
  </section>

  <div id="printFooter" data-user="<?= htmlspecialchars($exportUserName, ENT_QUOTES, 'UTF-8') ?>"></div>
  <?php include __DIR__ . '/partials/chat.php'; ?>
</main>
</div>

<script>
/* =================== Helpers / Constantes =================== */
const $ = (s,p)=> (p||document).querySelector(s);
const PILLAR_COLORS = {
  'aprendizado':'#8e44ad',
  'processos':'#2980b9',
  'clientes':'#27ae60',
  'financeiro':'#f39c12'
};
const PILLAR_ORDER = ['aprendizado','processos','clientes','financeiro'];
const PILLAR_ORDER_REVERSED = ['financeiro','clientes','processos','aprendizado'];
const PILLAR_ICONS = {
  'aprendizado':'fa-solid fa-graduation-cap',
  'processos' :'fa-solid fa-gears',
  'clientes'  :'fa-solid fa-users',
  'financeiro':'fa-solid fa-coins'
};
const FAROL_COLORS = { verde:'#22c55e', amarelo:'#f59e0b', vermelho:'#ef4444', cinza:'#9ca3af' };

function pillarColor(key){ return PILLAR_COLORS[String(key).toLowerCase()] || '#60a5fa'; }
function pillarIcon(key){ return PILLAR_ICONS[String(key).toLowerCase()] || 'fa-solid fa-layer-group'; }
function strnatcasecmp(a,b){ return (a||'').toString().localeCompare((b||'').toString(),'pt-BR',{numeric:true,sensitivity:'base'}); }
function esc(s){
  if(s===null||s===undefined) return '';
  return String(s).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'","&#039;");
}
function labelPillar(k){
  switch(String(k).toLowerCase()){
    case 'aprendizado': return 'Aprendizado';
    case 'processos'  : return 'Processos';
    case 'clientes'   : return 'Clientes';
    case 'financeiro' : return 'Financeiro';
    default: return k||'—';
  }
}
function contrastText(hex){
  if(!hex) return '#fff';
  const c=hex.replace('#','');
  const r=parseInt(c.substring(0,2),16);
  const g=parseInt(c.substring(2,4),16);
  const b=parseInt(c.substring(4,6),16);
  const yiq=((r*299)+(g*587)+(b*114))/1000;
  return yiq >= 200 ? '#0b0f14' : '#ffffff';
}
function avatarHTML(userId){
  const PNG=`/OKR_system/assets/img/avatars/${userId}.png`;
  const JPG=`/OKR_system/assets/img/avatars/${userId}.jpg`;
  const JPEG=`/OKR_system/assets/img/avatars/${userId}.jpeg`;
  return `<img src="${PNG}" onerror="this.onerror=null; this.src='${JPG}'; this.onerror=function(){this.src='${JPEG}';};" alt="">`;
}

/* ==== helpers para detalhe (mantidos) ==== */
function normalizeNoAccents(s){ return (s||'').normalize('NFD').replace(/[\u0300-\u036f]/g, ''); }
function isNotStartedKR(kr){
  const s = normalizeNoAccents(String(kr?.status || '').toLowerCase());
  return s.includes('nao iniciado') || s.includes('nao-iniciado') || s.includes('planejad') || s.includes('not started');
}
function computeObjectiveProgress(krs){
  let sA = 0, nA = 0;
  for (const kr of (krs || [])) {
    if (isNotStartedKR(kr)) continue;
    const pa = kr?.progress?.pct_atual;
    if (Number.isFinite(pa)) { sA += Math.max(0, Math.min(100, pa)); nA++; }
  }
  const pctA = nA ? Math.round(sA / nA) : null;
  return { pctA };
}

/* UI helpers */
function styleObjNumberChip(el, farol){
  if(!el) return;
  const key = String(farol||'cinza').toLowerCase();
  const col = FAROL_COLORS[key] || FAROL_COLORS.cinza;
  el.style.background = col;
  el.style.borderColor = col;
  el.style.color = contrastText(col);
}
function setObjCardProgress(card, pct){
  const bar = card.querySelector('.obj-prog > span');
  const label = card.querySelector('.obj-foot > span strong');
  const v = (pct==null || isNaN(pct)) ? null : Math.max(0, Math.min(100, Number(pct)));
  if (bar) bar.style.width = (v==null ? '0%' : (v + '%'));
  if (label) label.textContent = (v==null ? '—' : (v + '%'));

  // [NOVO] persistir no DOM e reagendar recálculo do BSC
  card.dataset.pct = (v==null ? '' : String(v));
  scheduleRecalcBSC();
}

function setObjCardFarol(card, farol){
  const chip = card.querySelector('.obj-badge');
  styleObjNumberChip(chip, farol);
}

/* [NOVO] Acumuladores globais para KPI/Ranking (semeados pelo backend) */
let __krFarolByObj = new Map();   // objId -> {verde, amarelo, vermelho, cinza}
let __krCritByOwner = new Map();  // donoId -> qtd KRs vermelhos
let __rankData = [];
let __rankPosMap = new Map();
let __rankOrderDesc = true;

function recomputeAggregates(){
  let totalRed = 0;
  const ownerRed = new Map();
  __krFarolByObj.forEach((c, objId)=>{
    const red = Number(c?.vermelho||0);
    totalRed += red;
    const owner = (window.__ownerByObj && window.__ownerByObj.get(String(objId))) || 0;
    ownerRed.set(owner, (ownerRed.get(owner)||0) + red);
  });
  __krCritByOwner = ownerRed;
  const n = document.getElementById('kpi_krs_crit');
  if(n) n.textContent = String(totalRed);

  if(Array.isArray(__rankData) && __rankData.length){
    __rankData = __rankData.map(r=>{
      const id = r.id ?? r.dono_id ?? 0;
      return { ...r, kr_critico: ownerRed.get(id) || r.kr_critico || 0 };
    });
    drawRanking();
  }
}

/* =========== Renderizadores (corrigidos) =========== */
function renderKPIs(itemsOrdered, data){
  const objN   = data.kpi?.objetivos ?? 0;
  const krN    = data.kpi?.krs ?? 0;
  const krCrit = data.kpi?.krs_criticos ?? 0;

  $('#kpi_obj').innerHTML =
    `<div class="kpi-counts">
       <span class="kpi-big" id="kpi_obj_n">${objN}</span>
       <span class="chip-mini"><i class="fa-solid fa-list-check"></i> <span id="kpi_krs_total">${krN}</span> KRs</span>
       <span class="chip-mini chip-danger" title="KRs críticos (vermelho)">
         <i class="fa-solid fa-triangle-exclamation"></i> <span id="kpi_krs_crit">${krCrit}</span>
       </span>
     </div>`;

  const mediaPct = (data.kpi?.media ?? null);
  const mediaTxt = (mediaPct===null || isNaN(mediaPct)) ? '—' : `${mediaPct}%`;
  $('#kpi_media').innerHTML = `<span class="kpi-big">${mediaTxt}</span>`;
  const bar = $('#kpi_media_bar > span');
  if (bar){
    const v = (mediaPct===null || isNaN(mediaPct)) ? 0 : Math.max(0, Math.min(100, parseInt(mediaPct,10) || 0));
    bar.style.width = '0%';
    requestAnimationFrame(()=> setTimeout(()=> { bar.style.width = v + '%'; }, 20));
  }

  // Líder / Pior objetivo (com base em pct médio do objetivo calculado no backend)
  const valid = (itemsOrdered||[]).filter(it => it.pct !== null && it.pct !== undefined);
  if (valid.length){
    const best = [...valid].sort((a,b)=> b.pct - a.pct)[0];
    const worst = [...valid].sort((a,b)=> a.pct - b.pct)[0];
    const bestIdx = (window.__objIndexMap && window.__objIndexMap.get(best.id)) || 1;
    const worstIdx = (window.__objIndexMap && window.__objIndexMap.get(worst.id)) || 1;
    const objNoB = String(bestIdx).padStart(2,'0');
    const objNoW = String(worstIdx).padStart(2,'0');

    const pKeyB = String(best.pilar||'').toLowerCase();
    const pKeyW = String(worst.pilar||'').toLowerCase();
    const iconB = PILLAR_ICONS[pKeyB] || 'fa-solid fa-layer-group';
    const iconW = PILLAR_ICONS[pKeyW] || 'fa-solid fa-layer-group';
    const colorB = PILLAR_COLORS[pKeyB] || '#60a5fa';
    const colorW = PILLAR_COLORS[pKeyW] || '#60a5fa';

    $('#kpi_lider').innerHTML =
      `<div class="kpi-obj">
         <i class="${iconB}" style="color:${colorB}"></i>
         <span class="obj-no">OBJ ${objNoB}</span>
         <span style="margin-left:auto; font-weight:900">${best.pct}%</span>
       </div>
       <div class="kpi-desc" title="${esc(best.nome||'—')}">${esc(best.nome||'—')}</div>`;

    $('#kpi_pior').innerHTML =
      `<div class="kpi-obj">
         <i class="${iconW}" style="color:${colorW}"></i>
         <span class="obj-no">OBJ ${objNoW}</span>
         <span style="margin-left:auto; font-weight:900">${worst.pct}%</span>
       </div>
       <div class="kpi-desc" title="${esc(worst.nome||'—')}">${esc(worst.nome||'—')}</div>`;
  } else {
    $('#kpi_lider').textContent = '—';
    $('#kpi_pior').textContent  = '—';
  }
}

function renderBSC(pilares){
  const by = {};
  (pilares||[]).forEach(p=>{ if(p) by[(p.pilar||'').toLowerCase()] = p; });
  const cols = $('#bsc_cols'); cols.innerHTML='';
  const labels = $('#bsc_labels'); labels.innerHTML='';

  PILLAR_ORDER.forEach(key=>{
    const p = by[key] || { pilar:key, media:null, count_obj:0, color:PILLAR_COLORS[key] };
    const empty = (p.media===null || isNaN(p.media));
    const val = empty ? 0 : Math.max(0, Math.min(100, parseInt(p.media,10)));

    const col = document.createElement('div');
    col.className='bsc-col';
    col.innerHTML =
      `<div class="bsc-bar ${empty?'empty':''}"
            id="bsc_bar_${key}"
            data-pilar="${key}"
            style="--col:${p.color || PILLAR_COLORS[key]};"
            data-val="${empty?'—':val}"></div>`;
    cols.appendChild(col);

    const lab = document.createElement('div');
    lab.className='bsc-label';
    lab.id = `bsc_lab_${key}`;
    lab.innerHTML = `<div>${labelPillar(key)}</div><div class="bsc-sub">${(p.count_obj||0)}-Objs</div>`;
    labels.appendChild(lab);

    requestAnimationFrame(()=>{
      const bar=col.querySelector('.bsc-bar');
      setTimeout(()=>{ bar.style.height = (empty ? '0%' : (val + '%')); bar.classList.add('show'); }, 20);
    });
  });
}

// [NOVO] Recalcular BSC a partir dos cards de objetivos
let __bscRecalcTimer = null;
function scheduleRecalcBSC(){
  clearTimeout(__bscRecalcTimer);
  __bscRecalcTimer = setTimeout(recomputeBSCFromCards, 60);
}

function recomputeBSCFromCards(){
  const sums = {aprendizado:0, processos:0, clientes:0, financeiro:0};
  const cnts = {aprendizado:0, processos:0, clientes:0, financeiro:0};

  document.querySelectorAll('.obj-card').forEach(card=>{
    const pk = (card.dataset.pilar||'').toLowerCase();
    const pct = Number(card.dataset.pct);
    if (Number.isFinite(pct) && pk in sums) {
      sums[pk] += pct;
      cnts[pk] += 1;
    }
  });

  const avgs = {};
  PILLAR_ORDER.forEach(k=>{
    avgs[k] = cnts[k] ? Math.round(sums[k]/cnts[k]) : null; // <<< null quando não houver objetivos
  });
  avgs.__cnts = cnts; // carregar cnts junto

  applyBSCValues(avgs);
}

function applyBSCValues(avgs){
  PILLAR_ORDER.forEach(key=>{
    const bar = document.getElementById(`bsc_bar_${key}`) ||
                document.querySelector(`.bsc-bar[data-pilar="${key}"]`);
    if (!bar) return;
    const v = Math.max(0, Math.min(100, Number(avgs[key]||0)));
    // Se o pilar não teve objetivos na recontagem, trate como null para o KPI
    const isNull = !(Number.isFinite(Number(avgs[key])) && Number(avgs[key]) > 0) && (avgs.__cnts && (avgs.__cnts[key]||0)===0);
    bar.setAttribute('data-val', isNull ? '—' : v);
    bar.style.height = (isNull ? '0' : v) + '%';
    bar.classList.add('show');
  });

  // === NOVO: recalcular KPI a partir dos pilares já aplicados (ignorando os '—')
  const vals = PILLAR_ORDER.map(k=>{
    const bar = document.getElementById(`bsc_bar_${k}`) ||
                document.querySelector(`.bsc-bar[data-pilar="${k}"]`);
    const dv = bar ? bar.getAttribute('data-val') : null;
    const n  = dv == null || dv === '—' ? NaN : Number(dv);
    return Number.isFinite(n) ? n : null;
  }).filter(v => v !== null);

  const mean = vals.length ? Math.round(vals.reduce((a,b)=>a+b,0)/vals.length) : null;
  const kpiEl = $('#kpi_media');
  const barEl = $('#kpi_media_bar > span');
  if (kpiEl) kpiEl.innerHTML = (mean==null ? '—%' : `<span class="kpi-big">${mean}%</span>`);
  if (barEl)  barEl.style.width = (mean==null ? '0' : mean) + '%';
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

    const media = r.media || 0, obj = r.obj_count || 0, krt = r.kr_count || 0, krred = r.kr_critico || 0;
    const nome = (r.nome || '—').toString();
    const parts = nome.trim().split(/\s+/).filter(Boolean);
    const firstLast = parts.length >= 2 ? `${parts[0]} ${parts[parts.length-1]}` : (parts[0] || '—');

    row.innerHTML =
      `<div class="pos" style="color:${color}" title="Posição real: ${posReal}º">${posStr}</div>
       ${avatarHTML(id)}
       <div class="line">
         <div class="name">${esc(firstLast)}</div>
         <div class="bar"><span style="width:${Math.max(0,Math.min(100,media))}%"></span></div>
         <div class="meta">
           <span>Obj: <strong>${obj}</strong></span>
           <span>KRs: <strong>${krt}</strong></span>
           <span class="crit">Críticos: <strong>${krred}</strong></span>
         </div>
       </div>
       <div class="val" title="${esc(nome)}">${media}%</div>`;
    box.appendChild(row);
  });
  requestAnimationFrame(()=> syncRankHeight());
}

function renderObjetivos(items){
  const grid = $('#obj_grid');
  grid.innerHTML='';
  if(!window.__ownerByObj) window.__ownerByObj = new Map();
  window.__ownerByObj.clear();

  const arr = items || [];
  const N = arr.length;

  // ---- distribuição equilibrada: máx. 4 por linha ----
  // nº de linhas mínimo para respeitar 4/linha
  const rows = Math.max(1, Math.ceil(N / 4));
  const base = Math.floor(N / rows);   // tamanho base de cada linha
  const extra = N % rows;              // primeiras 'extra' linhas ganham +1 item
  let ptr = 0;

  for (let r = 0; r < rows; r++){
    const size = base + (r < extra ? 1 : 0);         // itens nessa linha (<=4)
    const row = document.createElement('div');
    row.className = 'obj-row';
    row.style.setProperty('--cols', String(size));   // estica pra ocupar 100% da linha

    for (let j = 0; j < size && ptr < N; j++, ptr++){
      const o = arr[ptr];
      const idx = (window.__objIndexMap && window.__objIndexMap.get(o.id)) || (ptr+1);
      const objNo = String(idx).padStart(2,'0');
      const pKey = String(o.pilar||'').toLowerCase();
      const color = pillarColor(pKey);
      const icon  = pillarIcon(pKey);
      const donoFirst = (o.dono||'—').toString().trim().split(/\s+/)[0] || '—';
      const counts = (o.kr_counts || {verde:0,amarelo:0,vermelho:0,cinza:0});

      const card = document.createElement('div');
      card.className='obj-card';
      card.setAttribute('data-obj-id', String(o.id));
      card.setAttribute('data-dono-id', String(o.dono_id || 0));
      card.style.borderLeft = `6px solid ${color}`;
      card.dataset.pilar = pKey;
      if (o.pct != null) card.dataset.pct = String(Math.max(0, Math.min(100, o.pct)));

      window.__ownerByObj.set(String(o.id), Number(o.dono_id || 0));

      card.innerHTML =
        `<span class="obj-badge" title="Farol do objetivo">OBJ ${objNo}</span>
         <div class="obj-title"><i class="${icon}" style="color:${color}"></i> ${esc(o.nome||'Objetivo')}</div>
         <div class="obj-meta">
           <span class="pill"><i class="fa-regular fa-user"></i> ${esc(donoFirst)}</span>
           <span class="pill"><i class="fa-regular fa-calendar-days"></i> ${esc(o.prazo||'—')}</span>
         </div>
         <div class="obj-prog"><span style="width:0%"></span></div>
         <div class="obj-foot">
           <span><strong>${(o.pct==null?'—':(o.pct+'%'))}</strong></span>
           <span class="tiny-dots" title="KRs: 🟢 No trilho | 🟡 Atenção | 🔴 Crítico | ⚪ Sem apontamento">
             <span class="dot g"></span> ${counts.verde||0}
             <span class="dot y" style="margin-left:6px"></span> ${counts.amarelo||0}
             <span class="dot r" style="margin-left:6px"></span> ${counts.vermelho||0}
             <span class="dot c" style="margin-left:6px"></span> ${counts.cinza||0}
           </span>
         </div>
         <div class="obj-more"></div>`;

      // badge neutra + barra com a cor do pilar
      styleObjNumberChip(card.querySelector('.obj-badge'), 'cinza');
      const barEl = card.querySelector('.obj-prog > span');
      if (barEl) barEl.style.background = color;

      // progresso inicial vindo do backend (se houver)
      if (o.pct != null) setObjCardProgress(card, o.pct);

      // semear agregados por objetivo (para KPI/Ranking)
      __krFarolByObj.set(String(o.id), {
        verde:Number(counts.verde||0),
        amarelo:Number(counts.amarelo||0),
        vermelho:Number(counts.vermelho||0),
        cinza:Number(counts.cinza||0),
      });

      row.appendChild(card);
    }

    grid.appendChild(row);
  }
}

function updateKRListFromDetalhe(card, krs){
  if (!Array.isArray(krs)) return;
  const list = [];
  for (const kr of krs) {
    const label = kr?.descricao || kr?.label || 'KR';
    const pa = kr?.progress?.pct_atual;
    const pctTxt = (Number.isFinite(pa) ? (Math.round(Math.max(0,Math.min(100,pa)))+'%') : '—');
    list.push({label, pctTxt, order: Number.isFinite(pa)? -pa : 9999});
  }
  const moreBox = card.querySelector('.obj-more');
  const top = list.sort((a,b)=> a.order - b.order).slice(0,5);
  if (moreBox) {
    if (!top.length) { moreBox.innerHTML = ''; }
    else {
      moreBox.innerHTML = top.map(it =>
        `<div class="kr-line">
          <span class="kr-dot c"></span>
          <span class="kr-txt" title="${esc(it.label)}">${esc(it.label)}</span>
          <span class="kr-val">${it.pctTxt}</span>
        </div>`
      ).join('');
    }
  }
}

function renderBudget(b){
  const brl = (x)=> (Number(x)||0).toLocaleString('pt-BR',{style:'currency',currency:'BRL'});
  const ap = Number(b.aprovado||0), re=Number(b.realizado||0), sa=Math.max(0, Number(b.saldo||0));
  const pRe = ap>0 ? Math.round(100*re/ap) : 0;
  const pSa = ap>0 ? Math.round(100*sa/ap) : 0;
  $('#b_aprov').textContent = brl(ap);
  $('#b_real').textContent  = brl(re);
  $('#b_saldo').textContent = brl(sa);
  $('#b_real_pct').textContent  = `${pRe}%`;
  $('#b_saldo_pct').textContent = `${pSa}%`;

  const svg = $('#b_spark');
  while(svg.firstChild) svg.removeChild(svg.firstChild);

  const plan = Array.isArray(b.series_acc_plan)? b.series_acc_plan : [];
  const real = Array.isArray(b.series_acc)? b.series_acc : [];
  const N = Math.max(plan.length, real.length);
  if (!N) return;

  // viewBox responsivo
  const rect = svg.getBoundingClientRect();
  const wCss = Math.floor(rect.width) || 300;
  const hCss = Math.floor(rect.height) || 80;
  const w = Math.max(160, wCss);
  const h = Math.max(28,  hCss);
  svg.setAttribute('viewBox', `0 0 ${w} ${h}`);

  // Padding e escalas
  const padX = Math.max(16, Math.round(w * 0.08));
  const padY = Math.max(6,  Math.min(14, Math.round(h * 0.18)));
  const yBase = h - padY; // linha do zero

  // Valores acumulados (preenche faltas com 0 para manter o pareamento por mês)
  const valsPlan = Array.from({length:N}, (_,i)=> Number(plan[i]?.acc || 0));
  const valsReal = Array.from({length:N}, (_,i)=> Number(real[i]?.acc || 0));
  const maxY = Math.max(1, ...valsPlan, ...valsReal);

  const toH = (v)=> Math.round( ((v / maxY) * (h - 2*padY)) );

  // Eixo base
  const base = document.createElementNS('http://www.w3.org/2000/svg','line');
  base.setAttribute('x1', padX);
  base.setAttribute('x2', w - padX);
  base.setAttribute('y1', yBase);
  base.setAttribute('y2', yBase);
  base.setAttribute('stroke', 'rgba(255,255,255,.15)');
  base.setAttribute('stroke-width','1');
  svg.appendChild(base);

  // Dimensionamento das colunas (grupo com 2 barras paralelas)
  const plotW = (w - 2*padX);
  const groupW = plotW / N;
  const barGap  = Math.max(1, groupW * 0.06);
  const barW    = Math.max(4, Math.min(18, groupW * 0.36)); // largura de cada barra
  const offsetP = padX + (groupW - (2*barW + barGap))/2;    // centraliza par de barras dentro do grupo

  for (let i=0;i<N;i++){
    const vp = valsPlan[i];
    const vr = valsReal[i];
    const hp = toH(vp);
    const hr = toH(vr);

    const xGroup = offsetP + i*groupW;

    // Barra Planejado (esquerda)
    const rP = document.createElementNS('http://www.w3.org/2000/svg','rect');
    rP.setAttribute('x', Math.round(xGroup));
    rP.setAttribute('y', Math.round(yBase - hp));
    rP.setAttribute('width', Math.round(barW));
    rP.setAttribute('height', Math.max(0, hp));
    rP.setAttribute('fill', '#cbd5e1');   // cinza claro
    rP.setAttribute('opacity','0.65');
    svg.appendChild(rP);

    // Barra Realizado (direita)
    const rR = document.createElementNS('http://www.w3.org/2000/svg','rect');
    rR.setAttribute('x', Math.round(xGroup + barW + barGap));
    rR.setAttribute('y', Math.round(yBase - hr));
    rR.setAttribute('width', Math.round(barW));
    rR.setAttribute('height', Math.max(0, hr));
    rR.setAttribute('fill', '#f6c343');   // dourado
    svg.appendChild(rR);
  }

  // Rótulos inicial/final (mês/ano) — mantém comportamento anterior
  try {
    const firstYM = (plan[0]?.ym) || (real[0]?.ym) || '';
    const lastYM  = (plan[N-1]?.ym) || (real[N-1]?.ym) || '';
    const fmt = ym => { const [y,m] = String(ym).split('-'); return (y&&m)? `${m}/${y}` : ym||''; };
    const yLabel = Math.max(10, yBase - 6);
    const xLeft  = Math.max(2, padX - 8);
    const xRight = Math.min(w - 2, w - padX + 8);
    const fontSz = (h <= 40) ? 8 : 10;

    const makeText = (x, txt, anchor) => {
      const t = document.createElementNS('http://www.w3.org/2000/svg', 'text');
      t.setAttribute('x', String(x));
      t.setAttribute('y', String(yLabel));
      if (anchor) t.setAttribute('text-anchor', anchor);
      t.setAttribute('fill', '#cbd5e1');
      t.setAttribute('font-size', String(fontSz));
      t.setAttribute('pointer-events', 'none');
      t.textContent = txt; return t;
    };
    if (firstYM) svg.appendChild(makeText(xLeft, fmt(firstYM), 'end'));
    if (lastYM)  svg.appendChild(makeText(xRight, fmt(lastYM), 'start'));
  } catch(e){ /* silencioso */ }
}

/* === aplica a lógica do Mapa Estratégico por objetivo (somente progresso + lista) === */
async function refreshObjectiveFromDetalhe(card, id){
  try{
    const url = `/OKR_system/views/detalhe_okr.php?ajax=load_krs&id_objetivo=${encodeURIComponent(id)}`;
    const resp = await fetch(url, { headers:{'Accept':'application/json'} });
    const data = await resp.json();
    if (data?.success) {
      const prog = computeObjectiveProgress(data.krs);
      setObjCardProgress(card, prog.pctA);
      setObjCardFarol(card, data.obj_farol);
      updateKRListFromDetalhe(card, data.krs);
    }
  }catch(e){
    console.warn('Falha ao aplicar detalhe_okr no objetivo', id, e);
  }
}

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

/* ========== Filtros e carregamento ========== */
function readFilters(){
  const di = $('#f_dt_ini')?.value || '';
  const df = $('#f_dt_fim')?.value || '';
  const pil= $('#f_pilar')?.value || '';
  const st = $('#f_status')?.value || '';
  const dn = $('#f_dono')?.value || '';
  const q  = $('#f_q')?.value || '';
  return {
    dt_inicio: di, dt_fim: df,
    pilar: pil, status: st, dono: dn, q
  };
}

function orderItems(items){
  const orderIdx = k => PILLAR_ORDER_REVERSED.indexOf(String(k).toLowerCase());
  const arr=[...(items||[])];
  arr.sort((a,b)=> (orderIdx(a.pilar) - orderIdx(b.pilar)) || strnatcasecmp(a.nome||'', b.nome||''));
  return arr;
}

function carregar(filtros){
  fetch('?ajax=report', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify(filtros||{})
  })
  .then(r=>r.json())
  .then(data=>{
    if(!data.success) throw new Error(data.error||'Falha');

    const itemsOrdered = orderItems(data.items||[]);
    const idxMap = new Map();
    itemsOrdered.forEach((o,i)=> idxMap.set(o.id, i+1));
    window.__objIndexMap = idxMap;

    renderKPIs(itemsOrdered, data);
    renderBSC(data.pilares||[]);
    renderRanking(data.rank||[]);
    renderObjetivos(itemsOrdered);
    scheduleRecalcBSC();
    renderBudget(data.budget||{});

    // Semear agregados do backend (contagem por farol por objetivo/dono) e recalcular críticos
    recomputeAggregates();

    const ro = new ResizeObserver(()=> syncRankHeight());
    ro.observe(document.querySelector('.bsc-wrap'));
    ro.observe(document.getElementById('rank_box'));
    window.addEventListener('resize', ()=> syncRankHeight());

    requestAnimationFrame(()=> setTimeout(syncRankHeight, 50));

    // Aplicar detalhe por objetivo (progresso + lista)
    document.querySelectorAll('.obj-card').forEach(card=>{
      const id = card.getAttribute('data-obj-id');
      if (id) refreshObjectiveFromDetalhe(card, id);
    });
  })
  .catch(e=>{
    console.error(e);
    alert('Falha ao carregar relatório.');
  });
}

/* Botões e inicialização */
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

document.getElementById('btnAplicar')?.addEventListener('click', ()=>{
  carregar(readFilters());
});

document.getElementById('btnPrint')?.addEventListener('click', ()=>{
  finalizeChartsForPrint();
  const pf = document.getElementById('printFooter');
  if (pf) {
    const uname = pf.getAttribute('data-user') || '';
    const now = new Date();
    const d = now.toLocaleString('pt-BR');
    pf.textContent = `Gerado por ${uname} em ${d}`;
  }
  window.print();
});

/* =================== Ajuste com chat lateral =================== */
const CHAT_SELECTORS=['#chatPanel','.chat-panel','.chat-container','#chat','.drawer-chat'];
const TOGGLE_SELECTORS=['#chatToggle','.chat-toggle','.btn-chat-toggle','.chat-icon','.chat-open'];
function findChatEl(){ for(const s of CHAT_SELECTORS){ const el=document.querySelector(s); if(el) return el; } return null; }
function isOpen(el){
  const st=getComputedStyle(el);
  const vis=st.display!=='none'&&st.visibility!=='hidden';
  const w=el.offsetWidth;
  return (vis&&w>0)||el.classList.contains('open')||el.classList.contains('show');
}
function updateChatWidth(){
  const el=findChatEl();
  const w=(el && isOpen(el))?el.offsetWidth:0;
  document.documentElement.style.setProperty('--chat-w',(w||0)+'px');
}
function setupChatObservers(){
  const chat=findChatEl();
  if(!chat) return;
  const mo=new MutationObserver(()=>{ updateChatWidth(); });
  mo.observe(chat,{attributes:true,attributeFilter:['style','class','aria-expanded']});
  window.addEventListener('resize',updateChatWidth);
  TOGGLE_SELECTORS.forEach(s=>document.querySelectorAll(s).forEach(btn=>btn.addEventListener('click',()=>setTimeout(updateChatWidth,200))));
  updateChatWidth();
}

document.addEventListener('DOMContentLoaded', ()=>{
  setupChatObservers();
  carregar({}); // primeira carga com filtros vazios
});
</script>
</body>
</html>

