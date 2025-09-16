<?php
// views/detalhe_okr.php – layout com iconografia no padrão do home.php

/* ===================== MODO AJAX (ENDPOINTS) ===================== */
if (isset($_GET['ajax'])) {
  ini_set('display_errors', 1);
  ini_set('display_startup_errors', 1);
  error_reporting(E_ALL);

  session_start();
  require_once __DIR__ . '/../auth/config.php';
  require_once __DIR__ . '/../auth/functions.php';

  header('Content-Type: application/json; charset=utf-8');

  if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autorizado']);
    exit;
  }

  try {
    $pdo = new PDO(
      "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
      DB_USER,
      DB_PASS,
      [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      ]
    );
  } catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro de conexão']);
    exit;
  }

  // Helpers
  $g = static function(array $row, string $k, $d = null) {
    return array_key_exists($k, $row) ? $row[$k] : $d;
  };
  $tableExists = static function(PDO $pdo, string $table): bool {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    try { $pdo->query("SHOW COLUMNS FROM `$table`"); return $cache[$table] = true; }
    catch (Throwable $e) { return $cache[$table] = false; }
  };
  $colExists = static function(PDO $pdo, string $table, string $col): bool {
    static $cache = [];
    $key = "$table.$col";
    if (isset($cache[$key])) return $cache[$key];
    try {
      $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :c");
      $st->execute(['c'=>$col]);
      $cache[$key] = (bool)$st->fetch();
      return $cache[$key];
    } catch (Throwable $e){ return $cache[$key] = false; }
  };
  // Helper: limita percentuais entre 0% e 100%
  $clampPct = static function($v){
    if ($v === null) return null;
    $v = (float)$v;
    if (!is_finite($v)) return null;
    return (int)round(max(0.0, min(100.0, $v)));
  };
  $findKrUserIdCol = static function(PDO $pdo): ?string {
    try { $st = $pdo->query("SHOW COLUMNS FROM `key_results`"); }
    catch (Throwable $e) { return null; }
    $cols = $st->fetchAll();
    if (!$cols) return null;
    $prefer = [
      'id_user_responsavel','id_responsavel','responsavel_id',
      'id_responsavel_kr','id_usuario_responsavel','owner_id',
      'id_owner','id_dono_responsavel','id_dono'
    ];
    $names = array_column($cols,'Field');
    $types = array_column($cols,'Type','Field');
    foreach ($prefer as $p) {
      if (in_array($p,$names,true) && stripos($types[$p]??'', 'int')!==false) return $p;
    }
    foreach ($names as $n) {
      if (preg_match('/respons|owner|dono/i',$n) && preg_match('/(^id_|_id$)/i',$n) && stripos($types[$n]??'','int')!==false) {
        return $n;
      }
    }
    return null;
  };
  $getUserNameById = static function(PDO $pdo, $id): ?string {
    static $cache = [];
    $id = (int)$id;
    if ($id<=0) return null;
    if (isset($cache[$id])) return $cache[$id];
    $st = $pdo->prepare("SELECT `primeiro_nome` FROM `usuarios` WHERE `id_user` = :id LIMIT 1");
    $st->execute(['id'=>$id]);
    $name = $st->fetchColumn();
    $cache[$id] = $name ?: null;
    return $cache[$id];
  };
  // Adiciona comentário ao KR (melhor esforço)
  $addKrComment = static function(PDO $pdo, string $id_kr, int $id_user, string $texto): void {
    $texto = trim($texto);
    if ($texto === '') return;
    foreach (['kr_comentarios','comentarios_kr'] as $t) {
      try { $pdo->query("SHOW COLUMNS FROM `$t`"); } catch (Throwable $e) { continue; }
      $idKrCol = null;
      foreach (['id_kr','kr_id'] as $c) if ($pdo->query("SHOW COLUMNS FROM `$t` LIKE '$c'")->fetch()) { $idKrCol = $c; break; }
      $userCol = null;
      foreach (['id_user','id_usuario','usuario_id','user_id'] as $c) if ($pdo->query("SHOW COLUMNS FROM `$t` LIKE '$c'")->fetch()) { $userCol = $c; break; }
      $textCol = null;
      foreach (['texto','comentario','mensagem','descricao'] as $c) if ($pdo->query("SHOW COLUMNS FROM `$t` LIKE '$c'")->fetch()) { $textCol = $c; break; }
      $dateCol = null;
      foreach (['dt_criacao','created_at','data','dt'] as $c) if ($pdo->query("SHOW COLUMNS FROM `$t` LIKE '$c'")->fetch()) { $dateCol = $c; break; }
      if (!$idKrCol || !$textCol) continue;
      $cols = [$idKrCol=>$id_kr, $textCol=>$texto];
      if ($userCol) $cols[$userCol] = $id_user;
      $fields = implode(',', array_map(fn($k)=>"`$k`", array_keys($cols)));
      $marks  = implode(',', array_map(fn($k)=>":$k", array_keys($cols)));
      $sql = "INSERT INTO `$t` ($fields" . ($dateCol? ", `$dateCol`":"") . ") VALUES ($marks" . ($dateCol? ", NOW()":"") . ")";
      $st = $pdo->prepare($sql);
      $st->execute($cols);
      return;
    }
    // Fallback: concatenar em campo de texto do KR, se existir
    foreach (['observacoes','comentarios'] as $c) {
      try { $col = $pdo->query("SHOW COLUMNS FROM `key_results` LIKE '$c'")->fetch(); if (!$col) continue; }
      catch (Throwable $e) { continue; }
      $st = $pdo->prepare("UPDATE `key_results` SET `$c` = CONCAT(COALESCE(`$c`,''), :sep, :t) WHERE `id_kr`=:id");
      $sep = (PHP_EOL . '[' . date('Y-m-d H:i') . '] ');
      $st->execute(['sep'=>$sep, 't'=>$texto, 'id'=>$id_kr]);
      return;
    }
  };
  // Descobre o nome da coluna que referencia o KR em uma tabela
  $findKrIdCol = static function(PDO $pdo, string $table): ?string {
    try { $cols = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC); }
    catch(Throwable $e){ return null; }
    foreach ($cols as $c) {
      $f = strtolower($c['Field']);
      if (in_array($f, ['id_kr','kr_id','id_key_result','key_result_id'], true)) return $c['Field'];
    }
    return null;
  };

  $action = $_GET['ajax'];

  /* ---------- LISTAR STATUS DE INICIATIVA (NOVO) ---------- */
  if ($action === 'list_status_iniciativa') {
    try {
      // tenta variações comuns primeiro
      $candidatas = ['dom_status_iniciativa','dom_status_iniciativas','dom_status_ini','dom_status_kr'];
      $tabela = null;
      foreach ($candidatas as $t) {
        try { $pdo->query("SHOW COLUMNS FROM `$t`"); $tabela = $t; break; } catch(Throwable $e){}
      }
      if (!$tabela) { echo json_encode(['success'=>false,'error'=>'Tabela de status de iniciativa não encontrada']); exit; }

      $cols = $pdo->query("SHOW COLUMNS FROM `$tabela`")->fetchAll(PDO::FETCH_ASSOC);
      $names = array_column($cols,'Field');
      $idCol    = in_array('id_status',$names,true) ? 'id_status' : (in_array('id',$names,true) ? 'id' : $names[0]);
      $labelCol = in_array('descricao_exibicao',$names,true) ? 'descricao_exibicao'
                : (in_array('descricao',$names,true) ? 'descricao'
                : (in_array('nome',$names,true) ? 'nome' : ($names[1] ?? $names[0])));

      $sql  = "SELECT `$idCol` AS id, `$labelCol` AS label FROM `$tabela` ORDER BY `$labelCol`";
      $rows = $pdo->query($sql)->fetchAll();

      echo json_encode(['success'=>true,'items'=>$rows]);
      exit;
    } catch (Throwable $e) {
      echo json_encode(['success'=>false,'error'=>'Falha ao listar status de iniciativa']);
      exit;
    }
  }


  /* ---------- ATUALIZAR STATUS DE INICIATIVA (obs obrigatória) ---------- */
  if ($action === 'update_iniciativa_status') {
    if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
      http_response_code(403);
      echo json_encode(['success'=>false,'error'=>'Token CSRF inválido']);
      exit;
    }

    $id_ini = $_POST['id_iniciativa'] ?? '';
    $novo   = trim((string)($_POST['novo_status'] ?? ''));
    $obs    = trim((string)($_POST['observacao'] ?? ''));

    if (!$id_ini || $novo==='') { echo json_encode(['success'=>false,'error'=>'Dados inválidos']); exit; }
    if ($obs==='')              { echo json_encode(['success'=>false,'error'=>'Observação é obrigatória.']); exit; }

    try {
      $pdo->beginTransaction();

      // Monta UPDATE de forma resiliente às colunas
      $sets  = ["status = :s"];
      $binds = [':s'=>$novo, ':id'=>$id_ini];

      // observacoes
      $hasObs = $colExists($pdo,'iniciativas','observacoes');
      if ($hasObs) {
        $sep   = PHP_EOL.'['.date('Y-m-d H:i').'] ';
        $usr   = (int)$_SESSION['user_id'];
        $linha = "Status alterado para \"{$novo}\" por usuário {$usr}. Obs: {$obs}";
        $sets[] = "observacoes = CONCAT(COALESCE(observacoes,''), :sep, :linha)";
        $binds[':sep']   = $sep;
        $binds[':linha'] = $linha;
      }

      // auditoria (se existirem)
      if ($colExists($pdo,'iniciativas','id_user_ult_alteracao')) {
        $sets[] = "id_user_ult_alteracao = :u";
        $binds[':u'] = (int)$_SESSION['user_id'];
      }
      if ($colExists($pdo,'iniciativas','dt_ultima_atualizacao')) {
        $sets[] = "dt_ultima_atualizacao = NOW()";
      }

      $sql = "UPDATE iniciativas SET ".implode(', ', $sets)." WHERE id_iniciativa = :id";
      $st  = $pdo->prepare($sql);
      $st->execute($binds);

      $pdo->commit();
      echo json_encode(['success'=>true]);
      exit;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      echo json_encode(['success'=>false,'error'=>'Falha ao alterar status']);
      exit;
    }
  }



  /* ---------- LISTAR RESPONSÁVEIS DA MESMA COMPANY ---------- */
  if ($action === 'list_responsaveis_company') {
    try {
      $userId = (int)$_SESSION['user_id'];

      // verifica existência da coluna id_company
      $stCol = $pdo->prepare("SHOW COLUMNS FROM `usuarios` LIKE 'id_company'");
      $stCol->execute();
      $hasCompany = (bool)$stCol->fetch();

      $companyVal = null;
      if ($hasCompany) {
        $st = $pdo->prepare("SELECT `id_company` FROM `usuarios` WHERE `id_user`=:u LIMIT 1");
        $st->execute(['u'=>$userId]);
        $companyVal = $st->fetchColumn();
      }

      if ($hasCompany && $companyVal !== null && $companyVal !== '') {
        // Lista todos da MESMA company
        $sql = "SELECT `id_user`, `primeiro_nome`, `ultimo_nome`
                FROM `usuarios`
                WHERE `id_company` = :c
                ORDER BY `primeiro_nome`, `ultimo_nome`";
        $st = $pdo->prepare($sql);
        $st->execute(['c'=>$companyVal]);
      } else {
        // Fallback seguro: apenas o usuário logado (evita vazar outras companies)
        $st = $pdo->prepare("SELECT `id_user`, `primeiro_nome`, `ultimo_nome` FROM `usuarios` WHERE `id_user`=:u LIMIT 1");
        $st->execute(['u'=>$userId]);
      }

      $rows = $st->fetchAll() ?: [];
      echo json_encode(['success'=>true,'items'=>$rows]);
      exit;
    } catch (Throwable $e) {
      echo json_encode(['success'=>false,'error'=>'Falha ao listar responsáveis']);
      exit;
    }
  }

  /* ---------- LISTA DE KRs (robusto a colunas ausentes) ---------- */
  if ($action === 'load_krs') {
    $id_objetivo = isset($_GET['id_objetivo']) ? (int)$_GET['id_objetivo'] : 0;
    if ($id_objetivo <= 0) { echo json_encode(['success'=>false,'error'=>'id_objetivo inválido']); exit; }

    $krUserIdCol = $findKrUserIdCol($pdo);
    $hasRespText = $colExists($pdo,'key_results','responsavel');

    $c = fn($name) => $colExists($pdo,'key_results',$name) ? "kr.`$name`" : "NULL";

    // prazo_final = COALESCE(das colunas que existirem)
    $prazoCandidates = ['dt_novo_prazo','data_fim','dt_prazo','data_limite','dt_limite','prazo','deadline'];
    $prazoParts = [];
    foreach ($prazoCandidates as $pc) if ($colExists($pdo,'key_results',$pc)) $prazoParts[] = "kr.`$pc`";
    $prazoExpr = $prazoParts ? ("COALESCE(" . implode(',', $prazoParts) . ")") : "NULL";

    $select = "
      SELECT
        kr.`id_kr`,
        {$c('key_result_num')} AS key_result_num,
        {$c('descricao')} AS descricao,
        {$c('farol')} AS farol,
        {$c('status')} AS status,
        {$c('tipo_frequencia_milestone')} AS tipo_frequencia_milestone,
        {$c('baseline')} AS baseline,
        {$c('meta')} AS meta,
        {$c('unidade_medida')} AS unidade_medida,
        {$c('direcao_metrica')} AS direcao_metrica,
        {$c('data_fim')} AS data_fim,
        {$c('dt_novo_prazo')} AS dt_novo_prazo,
        {$c('margem_confianca')} AS margem_tolerancia,
        $prazoExpr AS prazo_final
    ";
    $join = "";
    if ($krUserIdCol) {
      $select .= ", kr.`$krUserIdCol` AS kr_user_id, u.`primeiro_nome` AS responsavel_nome ";
      $join    = " LEFT JOIN `usuarios` u ON u.`id_user` = kr.`$krUserIdCol` ";
    }
    if ($hasRespText) $select .= ", kr.`responsavel` AS responsavel_text";

    $orderParts = [];
    if ($colExists($pdo,'key_results','key_result_num')) $orderParts[] = "kr.`key_result_num` ASC";
    if ($colExists($pdo,'key_results','dt_ultima_atualizacao')) $orderParts[] = "kr.`dt_ultima_atualizacao` DESC";
    $orderSql = $orderParts ? " ORDER BY ".implode(', ',$orderParts) : "";

    $sql = $select . " FROM `key_results` kr $join WHERE kr.`id_objetivo` = :id $orderSql ";

    try {
      $st = $pdo->prepare($sql);
      $st->execute(['id'=>$id_objetivo]);
    } catch (Throwable $e) {
      echo json_encode(['success'=>false,'error'=>'Falha ao consultar KRs']);
      exit;
    }

    $rows = $st->fetchAll();

    /* === ADD: detectar milestones + apontamentos para progresso === */
    $msTable = null;
    foreach (['milestones_kr','milestones'] as $t) {
      try { $pdo->query("SHOW COLUMNS FROM `$t`"); $msTable=$t; break; } catch(Throwable $e){}
    }
    $msCols = $msTable ? $pdo->query("SHOW COLUMNS FROM `$msTable`")->fetchAll(PDO::FETCH_ASSOC) : [];
    $hasMs  = function($n) use($msCols){ foreach($msCols as $c){ if (strcasecmp($c['Field'],$n)===0) return true; } return false; };

    $msKr   = $msTable ? ($hasMs('id_kr') ? 'id_kr' : ($findKrIdCol($pdo,$msTable) ?: null)) : null;
    $msDate = $msTable ? ($hasMs('data_ref') ? 'data_ref' : ($hasMs('dt_prevista') ? 'dt_prevista' : ($hasMs('data_prevista') ? 'data_prevista' : null))) : null;
    $msExp  = $msTable ? ($hasMs('valor_esperado') ? 'valor_esperado' : ($hasMs('esperado') ? 'esperado' : null)) : null;
    $msReal = $msTable ? ($hasMs('valor_real') ? 'valor_real' : ($hasMs('realizado') ? 'realizado' : ($hasMs('valor_real_consolidado') ? 'valor_real_consolidado' : null))) : null;

        // Colunas extras do milestone p/ a nova lógica
    $msId  = $msTable ? ($hasMs('id_milestone') ? 'id_milestone' : ($hasMs('id_ms') ? 'id_ms' : ($hasMs('id') ? 'id' : null))) : null;
    $msMin = $msTable ? ($hasMs('valor_esperado_min') ? 'valor_esperado_min' : ($hasMs('esperado_min') ? 'esperado_min' : null)) : null;
    $msMax = $msTable ? ($hasMs('valor_esperado_max') ? 'valor_esperado_max' : ($hasMs('esperado_max') ? 'esperado_max' : null)) : null;
    $msCnt = $msTable ? ($hasMs('qtde_apontamentos') ? 'qtde_apontamentos' : null) : null;

    // SELECT do milestone exatamente no dia D (YYYY-MM-DD) e do passado mais próximo
    $selBase = "SELECT `$msDate` AS data_ref, `$msExp` AS E, "
              .($msMin? "`$msMin` AS E_min,":"NULL AS E_min,")
              .($msMax? "`$msMax` AS E_max,":"NULL AS E_max,")
              .($msReal? "`$msReal` AS R,":"NULL AS R,")
              .($msCnt? "`$msCnt` AS cnt,":"NULL AS cnt,")
              .($msId ? "`$msId` AS id_ms":"NULL AS id_ms")
              ." FROM `$msTable` WHERE `$msKr`=:id ";

    $stRefHoje = ($msTable && $msKr && $msDate && $msExp)
      ? $pdo->prepare($selBase . " AND `$msDate` = :d ORDER BY `$msDate` DESC LIMIT 1")
      : null;

    $stRefPast = ($msTable && $msKr && $msDate && $msExp)
      ? $pdo->prepare($selBase . " AND `$msDate` < :d ORDER BY `$msDate` DESC LIMIT 1")
      : null;

    // Se existir tabela de apontamentos, deixar pronto um contador por MS ou por data_ref
    $stApCntByMs  = null;
    $stApCntByRef = null;
    if ($apTable && $apKr) {
      if ($apMs) {
        $stApCntByMs  = $pdo->prepare("SELECT COUNT(*) FROM `$apTable` WHERE `$apKr`=:kr AND `$apMs`=:ms");
      } elseif ($apRef) {
        $stApCntByRef = $pdo->prepare("SELECT COUNT(*) FROM `$apTable` WHERE `$apKr`=:kr AND `$apRef`=:d");
      }
    }

    // helper: checar se há apontamento no MS (R não-nulo, ou cnt>0, ou há linhas em apontamentos)
    $hasApont = function(array $msRow, string $krId) use($stApCntByMs,$stApCntByRef){
      $r   = $msRow['R']   ?? null;
      $cnt = (int)($msRow['cnt'] ?? 0);
      if ($r !== null && $r !== '' ) return true;
      if ($cnt > 0) return true;
      if ($stApCntByMs && !empty($msRow['id_ms'])) {
        $stApCntByMs->execute(['kr'=>$krId, 'ms'=>$msRow['id_ms']]);
        return ((int)$stApCntByMs->fetchColumn()) > 0;
      }
      if ($stApCntByRef && !empty($msRow['data_ref'])) {
        $stApCntByRef->execute(['kr'=>$krId, 'd'=>$msRow['data_ref']]);
        return ((int)$stApCntByRef->fetchColumn()) > 0;
      }
      return false;
    };

    // helper: dividir com proteção (den==0 vira "muito ruim")
    $rel = function($num, $den){
      $den = (float)$den;
      if (!is_finite($den) || abs($den) < 1e-12) return 1e9; // enorme => pinta vermelho
      return $num / $den;
    };

    // Apontamentos (fallback quando não houver coluna "real" no milestone)
    $apTable = null; $apKr=null; $apVal=null; $apWhen=null;
    foreach (['apontamentos_kr','apontamentos'] as $t) {
      try { $pdo->query("SHOW COLUMNS FROM `$t`"); $apTable=$t; break; } catch(Throwable $e){}
    }
    $getColAp = function(string $table, array $cands) use($pdo){
      foreach($cands as $c){ try{ $st=$pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :c"); $st->execute(['c'=>$c]); if($st->fetch()) return $c; }catch(Throwable $e){} }
      return null;
    };
    if ($apTable){
      $apKr   = $getColAp($apTable, ['id_kr','kr_id','id_key_result','key_result_id']);
      $apVal  = $getColAp($apTable, ['valor_real','valor']);
      $apWhen = $getColAp($apTable, ['dt_apontamento','created_at','dt_criacao','data']);
    }

    // Statements preparadas para performance
    $stExp = ($msTable && $msKr && $msDate && $msExp)
      ? $pdo->prepare("SELECT `$msExp` FROM `$msTable` WHERE `$msKr`=:id AND `$msDate`<=CURDATE() ORDER BY `$msDate` DESC LIMIT 1")
      : null;

    $stRealMs = ($msTable && $msKr && $msReal)
      ? $pdo->prepare("SELECT `$msReal` FROM `$msTable` WHERE `$msKr`=:id AND `$msReal` IS NOT NULL AND `$msReal`<>'' ".
                      ($msDate? "ORDER BY `$msDate` DESC ":"ORDER BY 1 LIMIT 1")." LIMIT 1")
      : null;

    $stRealAp = ($apTable && $apKr && $apVal)
      ? $pdo->prepare("SELECT `$apVal` FROM `$apTable` WHERE `$apKr`=:id ".
                      ($apWhen? "ORDER BY `$apWhen` DESC ":"")." LIMIT 1")
      : null;
    $out = [];
    foreach ($rows as $r) {
      $nome = $r['responsavel_nome'] ?? null;
      if (!$nome && isset($r['responsavel_text'])) {
        $txt = trim((string)$r['responsavel_text']);
        if ($txt !== '') $nome = ctype_digit($txt) ? ($getUserNameById($pdo,(int)$txt) ?: $txt) : $txt;
      }
      if (!$nome && !empty($r['kr_user_id'] ?? null)) $nome = $getUserNameById($pdo, (int)$r['kr_user_id']);

            // === ADD: calcular progresso atual (%) e status (verde/vermelho) ===
      $expNow  = null;   // valor esperado até hoje
      $realNow = null;   // último apontado
      if ($stExp)    { $stExp->execute(['id'=>$r['id_kr']]);  $expNow  = $stExp->fetchColumn(); }
      if ($stRealMs) { $stRealMs->execute(['id'=>$r['id_kr']]); $realNow = $stRealMs->fetchColumn(); }
      if ($realNow===false || $realNow===null) {
        if ($stRealAp) { $stRealAp->execute(['id'=>$r['id_kr']]); $realNow = $stRealAp->fetchColumn(); }
      }
      $expNow  = is_numeric($expNow)  ? (float)$expNow  : null;
      $realNow = is_numeric($realNow) ? (float)$realNow : null;

      $base = is_numeric($r['baseline']) ? (float)$r['baseline'] : null;
      $meta = is_numeric($r['meta'])     ? (float)$r['meta']     : null;

      $pctAtual = null; $pctEsper = null; $ok = null;
      if ($base !== null && $meta !== null && $meta != $base) {
        // direção inferida por baseline->meta
        $upTrend = $meta > $base;

        if ($upTrend) {
          if ($realNow!==null) $pctAtual = (($realNow - $base)/($meta - $base))*100;
          if ($expNow !==null) $pctEsper = (($expNow  - $base)/($meta - $base))*100;
          if ($realNow!==null && $expNow!==null) $ok = ($realNow >= $expNow);
        } else {
          if ($realNow!==null) $pctAtual = (($base - $realNow)/($base - $meta))*100;
          if ($expNow !==null) $pctEsper = (($base - $expNow )/($base - $meta))*100;
          if ($realNow!==null && $expNow!==null) $ok = ($realNow <= $expNow);
        }

        // Ajuste se houver "entre"/faixa na direção da métrica
        $dir = strtolower((string)$r['direcao_metrica']);
        if ($dir && preg_match('/entre|range|faixa/i',$dir) && $realNow!==null) {
          $lo = min($base,$meta); $hi = max($base,$meta);
          $ok = ($realNow >= $lo && $realNow <= $hi);
          // percentuais permanecem com a mesma fórmula baseada em base→meta
        }
      }
      // Garante as regras:
      // 1) acima da meta => no máximo 100%
      // 2) aquém da baseline => no mínimo 0%
      $pctAtual = $clampPct($pctAtual);
      $pctEsper = $clampPct($pctEsper);

      
      // === NOVO: Farol de confiança do KR baseado no "MS de referência" ===
      $hoje = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');

      // Tenta pegar o MS de hoje e o passado mais próximo
      $msHoje = null; $msPast = null;
      if ($stRefHoje) { $stRefHoje->execute(['id'=>$r['id_kr'], 'd'=>$hoje]); $msHoje = $stRefHoje->fetch(PDO::FETCH_ASSOC) ?: null; }
      if ($stRefPast) { $stRefPast->execute(['id'=>$r['id_kr'], 'd'=>$hoje]); $msPast = $stRefPast->fetch(PDO::FETCH_ASSOC) ?: null; }

      $ref   = null;     // MS de referência
      $rjust = '';       // motivo/rota de seleção
      if ($msHoje) {
        if ($hasApont($msHoje, $r['id_kr'])) { $ref=$msHoje; $rjust='hoje'; }
        elseif ($msPast) { $ref=$msPast; $rjust='fallback_hoje_sem_apont_usa_passado'; }
        else { $ref=null; $rjust='sem_referencia'; }
      } else {
        if ($msPast) { $ref=$msPast; $rjust='passado'; }
        else { $ref=null; $rjust='sem_referencia'; }
      }

      $farol_auto = 'vermelho';
      $farol_calc = ['s'=>null, 'm'=>null, 'dir'=>null];

      if ($ref === null) {
        $farol_auto = 'vermelho'; // sem referência histórica
      } else {
        // curto-circuito por falta de apontamento
        if (!$hasApont($ref, $r['id_kr'])) {
          $farol_auto = 'vermelho';
        } else {
          // parâmetros do cálculo
          $E     = is_numeric($ref['E']     ?? null) ? (float)$ref['E']     : null;
          $Emin  = is_numeric($ref['E_min'] ?? null) ? (float)$ref['E_min'] : null;
          $Emax  = is_numeric($ref['E_max'] ?? null) ? (float)$ref['E_max'] : null;
          $R     = is_numeric($ref['R']     ?? null) ? (float)$ref['R']     : null;

          // margem (coluna em KR se existir); aceita 10 (10%) ou 0.10
          $m = null;
          if (isset($r['margem_tolerancia']) && is_numeric($r['margem_tolerancia'])) $m = (float)$r['margem_tolerancia'];
          elseif (isset($r['margem']) && is_numeric($r['margem'])) $m = (float)$r['margem'];
          if ($m === null || $m <= 0) $m = 0.10;
          if ($m > 1.0) $m = $m / 100.0;

          // direção
          $dirRaw = strtolower((string)($r['direcao_metrica'] ?? ''));
          $dir = 'maior'; // default
          if (preg_match('/menor/', $dirRaw)) $dir = 'menor';
          if (preg_match('/entre|interval|faixa|range/', $dirRaw)) $dir = 'intervalo';

          $s = null; // desvio relativo ruim (>=0)

          if ($R === null) {
            // Por segurança, se chegou até aqui sem R válido, trata como muito ruim
            $s = 1e9;
          } else if ($dir === 'intervalo' && $Emin !== null && $Emax !== null && $Emin <= $Emax) {
            if ($R >= $Emin && $R <= $Emax) {
              $s = 0.0;
            } elseif ($R < $Emin) {
              $s = $rel(($Emin - $R), ($Emin == 0 ? 1e-12 : $Emin));
            } else { // $R > $Emax
              $s = $rel(($R - $Emax), ($Emax == 0 ? 1e-12 : $Emax));
            }
          } else if ($dir === 'menor' && $E !== null) {
            // menor melhor: s = max(0, (R - E)/E)
            $s = max(0.0, $rel(($R - $E), ($E == 0 ? 1e-12 : $E)));
          } else if ($E !== null) {
            // maior melhor (padrão): s = max(0, (E - R)/E)
            $s = max(0.0, $rel(($E - $R), ($E == 0 ? 1e-12 : $E)));
          } else {
            $s = 1e9; // sem E válido, considera muito ruim
          }

          // mapeamento de cor (bordas inclusivas)
          if ($s <= $m + 1e-12) {
            $farol_auto = 'verde';
          } else if ($s <= 3*$m + 1e-12) {
            $farol_auto = 'amarelo';
          } else {
            $farol_auto = 'vermelho';
          }

          $farol_calc = ['s'=>$s, 'm'=>$m, 'dir'=>$dir];
        }
      }



      $out[] = [
        'id_kr' => $r['id_kr'],
        'key_result_num' => $r['key_result_num'],
        'descricao' => $r['descricao'],
        'farol' => $r['farol'],
        'status' => $r['status'],
        'tipo_frequencia_milestone' => $r['tipo_frequencia_milestone'],
        'baseline' => $r['baseline'],
        'meta' => $r['meta'],
        'unidade_medida' => $r['unidade_medida'],
        'direcao_metrica' => $r['direcao_metrica'],
        'data_fim' => $r['data_fim'],
        'dt_novo_prazo' => $r['dt_novo_prazo'],
        'prazo_final' => $r['prazo_final'],
        'responsavel' => $nome ?: '—',

        /* === ADD: progresso calculado === */
        'progress' => [
          'valor_atual'    => $realNow,
          'valor_esperado' => $expNow,
          'pct_atual'      => $pctAtual,   // inteiro (%)
          'pct_esperado'   => $pctEsper,   // inteiro (%)
          'ok'             => $ok          // true=verde, false=vermelho, null=indefinido
        ],
        'farol_auto'   => $farol_auto,    // 'verde' | 'amarelo' | 'vermelho'
        'farol_reason' => $rjust,         // explicação da referência usada
        'ref_milestone'=> [
          'data' => $ref['data_ref'] ?? null,
          'id_ms'=> $ref['id_ms'] ?? null,
          'E'    => isset($ref['E'])     ? (float)$ref['E']     : null,
          'E_min'=> isset($ref['E_min']) ? (float)$ref['E_min'] : null,
          'E_max'=> isset($ref['E_max']) ? (float)$ref['E_max'] : null,
          'R'    => isset($ref['R'])     ? (float)$ref['R']     : null,
          'tem_apontamento' => ($ref ? $hasApont($ref, $r['id_kr']) : false)
        ],
        'farol_calc'   => $farol_calc
      ];
    }
    echo json_encode(['success'=>true,'krs'=>$out]);
    exit;
  }


  /* ---------- DETALHE DO KR ---------- */
  if ($action === 'kr_detail') {
    $id_kr = $_GET['id_kr'] ?? '';
    if (!$id_kr) { echo json_encode(['success'=>false,'error'=>'id_kr inválido']); exit; }

    $hasUlt = $colExists($pdo, 'key_results', 'dt_ultima_atualizacao');
    $st = $pdo->prepare("
      SELECT id_kr, id_objetivo, key_result_num, descricao, farol, status, baseline, meta, unidade_medida, direcao_metrica, tipo_frequencia_milestone
             ".($hasUlt ? ", dt_ultima_atualizacao" : ", NULL AS dt_ultima_atualizacao")."
      FROM `key_results`
      WHERE `id_kr` = :id LIMIT 1
    ");
    $st->execute(['id'=>$id_kr]);
    $kr = $st->fetch();
    if (!$kr) { echo json_encode(['success'=>false,'error'=>'KR não encontrado']); exit; }

// milestones table
$msTable = null;
foreach (['milestones_kr','milestones'] as $t) {
  try { $pdo->query("SHOW COLUMNS FROM `$t`"); $msTable = $t; break; } catch (Throwable $e) {}
}
if (!$msTable) { echo json_encode(['success'=>false,'error'=>'Tabela de milestones não encontrada']); exit; }

// helper p/ procurar colunas em uma tabela
$getCol = function(string $table, array $cands) use($pdo){
  foreach ($cands as $c) {
    try { $st=$pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :c"); $st->execute(['c'=>$c]); if ($st->fetch()) return $c; } catch(Throwable $e){}
  }
  return null;
};

$krCol = $findKrIdCol($pdo, $msTable);
if (!$krCol) $krCol = $colExists($pdo, $msTable, 'id_kr') ? 'id_kr' : null;
if (!$krCol) { echo json_encode(['success'=>false,'error'=>'Coluna que referencia o KR nos milestones não encontrada']); exit; }

$cols = $pdo->query("SHOW COLUMNS FROM `$msTable`")->fetchAll(PDO::FETCH_ASSOC);
$has  = function($name) use($cols){ foreach($cols as $c){ if (strcasecmp($c['Field'],$name)===0) return true; } return false; };

$dateCol = $has('data_ref') ? 'data_ref'
         : ($has('dt_prevista') ? 'dt_prevista'
         : ($has('data_prevista') ? 'data_prevista' : null));

$expCol  = $has('valor_esperado') ? 'valor_esperado'
         : ($has('esperado') ? 'esperado' : null);

/* EXPANDEMOS os candidatos do "real" */
$realCol = $has('valor_real') ? 'valor_real'
         : ($has('realizado') ? 'realizado'
         : ($has('valor_real_consolidado') ? 'valor_real_consolidado'
         : ($has('valor_apontado') ? 'valor_apontado'
         : ($has('resultado') ? 'resultado' : null))));

// NOVO: limites min/max do intervalo ideal (se existirem)
$minCol = $has('valor_esperado_min') ? 'valor_esperado_min'
        : ($has('esperado_min') ? 'esperado_min' : null);

$maxCol = $has('valor_esperado_max') ? 'valor_esperado_max'
        : ($has('esperado_max') ? 'esperado_max' : null);

$evidCol = $has('dt_evidencia') ? 'dt_evidencia'
         : ($has('data_evidencia') ? 'data_evidencia'
         : ($has('dt_ultimo_apontamento') ? 'dt_ultimo_apontamento' : null));

if (!$dateCol || !$expCol) { echo json_encode(['success'=>false,'error'=>'Colunas de milestones não encontradas (data/esperado)']); exit; }

/* ——— Fallback: último apontamento ——— */
$apTable = null;
foreach (['apontamentos_kr','apontamentos'] as $t) {
  try { $pdo->query("SHOW COLUMNS FROM `$t`"); $apTable = $t; break; } catch (Throwable $e) {}
}
$apKr   = $apTable ? $getCol($apTable, ['id_kr','kr_id','id_key_result','key_result_id']) : null;
$apMs   = $apTable ? $getCol($apTable, ['id_milestone','id_ms']) : null;
$apVal  = $apTable ? $getCol($apTable, ['valor_real','valor']) : null;
$apWhen = $apTable ? $getCol($apTable, ['dt_apontamento','created_at','dt_criacao','data']) : null;
$apRef  = $apTable ? $getCol($apTable, ['data_ref','data_prevista']) : null;

/* Query base dos milestones */
$sqlMs = "SELECT ms.`$dateCol` AS data_prevista,
                 ms.`$expCol`  AS valor_esperado";

// NOVO: min/max (ou NULL se não existirem)
$sqlMs .= ($minCol ? ", ms.`$minCol` AS valor_esperado_min" : ", NULL AS valor_esperado_min");
$sqlMs .= ($maxCol ? ", ms.`$maxCol` AS valor_esperado_max" : ", NULL AS valor_esperado_max");

/* Se tivermos coluna "real" no milestone, trazemos; senão criaremos via subselect */
if ($realCol) {
  $sqlMs .= ", ms.`$realCol` AS valor_real";
} else {
  $sqlMs .= ", NULL AS valor_real";
}

if ($evidCol) $sqlMs .= ", ms.`$evidCol` AS dt_evidencia";
$sqlMs .= " FROM `$msTable` ms WHERE ms.`$krCol` = :id ORDER BY ms.`$dateCol` ASC";

$stmM = $pdo->prepare($sqlMs);
$stmM->execute(['id'=>$id_kr]);
$milestones = $stmM->fetchAll();

/* Se a coluna real não existe ou está vazia, tenta pegar o último apontamento correspondente */
if ((!$realCol || !$milestones) && $apTable && $apKr && $apVal && ($apMs || $apRef)) {
  foreach ($milestones as &$m) {
    if ($realCol && $m['valor_real'] !== null && $m['valor_real'] !== '') continue;

    if ($apMs && $has('id_milestone')) {
      // por id do milestone
      $idMsCol = $has('id_milestone') ? 'id_milestone' : ($has('id_ms') ? 'id_ms' : 'id');
      $stA = $pdo->prepare("
        SELECT `$apVal` AS v
        FROM `$apTable`
        WHERE `$apKr` = :kr AND `$apMs` = :ms
        ORDER BY ".($apWhen ? "`$apWhen` DESC" : "1")."
        LIMIT 1
      ");
      $stA->execute(['kr'=>$id_kr,'ms'=>$m[$idMsCol] ?? null]);
      $v = $stA->fetchColumn();
      if ($v !== false && $v !== null) $m['valor_real'] = (float)$v;
    } elseif ($apRef) {
      // por data de referência
      $stA = $pdo->prepare("
        SELECT `$apVal` AS v
        FROM `$apTable`
        WHERE `$apKr` = :kr AND `$apRef` = :d
        ORDER BY ".($apWhen ? "`$apWhen` DESC" : "1")."
        LIMIT 1
      ");
      $stA->execute(['kr'=>$id_kr,'d'=>$m['data_prevista']]);
      $v = $stA->fetchColumn();
      if ($v !== false && $v !== null) $m['valor_real'] = (float)$v;
    }
  }
  unset($m);
}

/* Monta as séries do gráfico (agora com min/max) */
$labels = []; $mid = []; $real = []; $minArr = []; $maxArr = [];
foreach ($milestones as $m) {
  $labels[] = $m['data_prevista'];

  $min = isset($m['valor_esperado_min']) ? $m['valor_esperado_min'] : null;
  $max = isset($m['valor_esperado_max']) ? $m['valor_esperado_max'] : null;
  $val = isset($m['valor_esperado'])     ? $m['valor_esperado']     : null;

  // Fallbacks:
  // - se min/max vierem nulos, usa o esperado como ambos
  if ($min === null && $max === null && $val !== null) { $min = $val; $max = $val; }
  // - se o esperado não vier, mas min/max vierem: média
  if ($val === null && $min !== null && $max !== null) { $val = ($min + $max) / 2; }

  $minArr[] = ($min === null ? null : (float)$min);
  $maxArr[] = ($max === null ? null : (float)$max);
  $mid[]    = ($val === null ? null : (float)$val);

  $real[] = ($m['valor_real'] === null || $m['valor_real'] === '') ? null : (float)$m['valor_real'];
}

    // agregados
    $stmI = $pdo->prepare("SELECT COUNT(*) AS t FROM `iniciativas` WHERE `id_kr`=:id");
    $stmI->execute(['id'=>$id_kr]);
    $tIni = (int)($stmI->fetch()['t'] ?? 0);

    $stmA = $pdo->prepare("
      SELECT COALESCE(SUM(o.valor),0) AS v
      FROM `orcamentos` o
      INNER JOIN `iniciativas` i ON i.`id_iniciativa`=o.`id_iniciativa`
      WHERE i.`id_kr` = :id
    ");
    $stmA->execute(['id'=>$id_kr]);
    $aprov = (float)($stmA->fetch()['v'] ?? 0);

    $stmR = $pdo->prepare("
      SELECT COALESCE(SUM(od.valor),0) AS v
      FROM `orcamentos_detalhes` od
      INNER JOIN `orcamentos` o ON o.`id_orcamento`=od.`id_orcamento`
      INNER JOIN `iniciativas` i ON i.`id_iniciativa`=o.`id_iniciativa`
      WHERE i.`id_kr` = :id
    ");
    $stmR->execute(['id'=>$id_kr]);
    $realiz = (float)($stmR->fetch()['v'] ?? 0);

    // próximo milestone
    $prox = null;
    foreach ($milestones as $m) {
      $d = $m['data_prevista'] ?? null;
      if ($d && strtotime($d) >= strtotime(date('Y-m-d'))) { $prox = $m; break; }
    }

    echo json_encode([
      'success'=>true,
      'kr'=>$kr,
      'milestones'=>$milestones,
      'chart'=>[
        'labels'=>$labels,
        'min'=>$minArr,
        'max'=>$maxArr,
        'esperado'=>$mid, // compat: mantemos "esperado" como a linha média
        'real'=>$real
      ],
      'agregados'=>[
        'iniciativas'=>$tIni,
        'orcamento'=>['aprovado'=>$aprov,'realizado'=>$realiz,'saldo'=>max(0,$aprov-$realiz)],
        'proximo_milestone'=>$prox
      ]
    ]);
    exit;
  }

  /* ---------- LISTA DE INICIATIVAS ---------- */
  if ($action === 'iniciativas_list') {
    $id_kr = $_GET['id_kr'] ?? '';
    if (!$id_kr) { echo json_encode(['success'=>false,'error'=>'id_kr inválido']); exit; }

    $stmI = $pdo->prepare("
      SELECT i.`id_iniciativa`, i.`num_iniciativa`, i.`descricao`, i.`status`, i.`dt_prazo`,
             i.`id_user_responsavel`, u.`primeiro_nome` AS responsavel_nome
      FROM `iniciativas` i
      LEFT JOIN `usuarios` u ON u.`id_user` = i.`id_user_responsavel`
      WHERE i.`id_kr`=:id
      ORDER BY i.`num_iniciativa` ASC, i.`dt_criacao` ASC
    ");
    $stmI->execute(['id'=>$id_kr]);
    $iniciativas = $stmI->fetchAll();

    $resp=[];
    foreach ($iniciativas as $ini) {
      $id_ini = $ini['id_iniciativa'];

      $stmA = $pdo->prepare("SELECT COALESCE(SUM(`valor`),0) AS aprovado, MIN(`id_orcamento`) AS id_orc FROM `orcamentos` WHERE `id_iniciativa`=:id");
      $stmA->execute(['id'=>$id_ini]);
      $oa = $stmA->fetch() ?: [];
      $aprov = (float)($oa['aprovado'] ?? 0);
      $id_orc = $oa['id_orc'] ?? null;

      if ($id_orc) {
        $stmR = $pdo->prepare("SELECT COALESCE(SUM(`valor`),0) AS realizado FROM `orcamentos_detalhes` WHERE `id_orcamento`=:o");
        $stmR->execute(['o'=>$id_orc]);
        $real = (float)($stmR->fetch()['realizado'] ?? 0);
      } else {
        $stmR2 = $pdo->prepare("
          SELECT COALESCE(SUM(od.`valor`),0) AS realizado
          FROM `orcamentos_detalhes` od
          INNER JOIN `orcamentos` o ON o.`id_orcamento`=od.`id_orcamento`
          WHERE o.`id_iniciativa`=:id
        ");
        $stmR2->execute(['id'=>$id_ini]);
        $real = (float)($stmR2->fetch()['realizado'] ?? 0);
      }

      $resp[] = [
        'id_iniciativa'=>$id_ini,
        'num_iniciativa'=>(int)$ini['num_iniciativa'],
        'descricao'=>$ini['descricao'],
        'status'=>$ini['status'],
        'dt_prazo'=>$ini['dt_prazo'],
        'responsavel'=>$ini['responsavel_nome'] ?: '—',
        'orcamento'=>[
          'aprovado'=>$aprov,'realizado'=>$real,'saldo'=>max(0,$aprov-$real),
          'id_orcamento'=>$id_orc
        ]
      ];
    }

    echo json_encode(['success'=>true,'iniciativas'=>$resp]);
    exit;
  }



    /* ---------- NOVA INICIATIVA (+ orçamento opcional) ---------- */
    if ($action === 'nova_iniciativa') {
      if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success'=>false,'error'=>'Token CSRF inválido']); exit;
      }

      $id_kr   = $_POST['id_kr'] ?? '';
      $desc    = trim((string)($_POST['descricao'] ?? ''));
      $resp    = (int)($_POST['id_user_responsavel'] ?? 0);
      $status  = trim((string)($_POST['status_iniciativa'] ?? ''));
      $dt_prazo= $_POST['dt_prazo'] ?? null;

      $inclOrc = !empty($_POST['incluir_orcamento']);
      $valorTot= (float)($_POST['valor_orcamento'] ?? 0);
      $prevArr = json_decode($_POST['desembolsos_json'] ?? '[]', true) ?: [];
      $just    = trim((string)($_POST['justificativa_orcamento'] ?? ''));

      if (!$id_kr || $desc==='') {
        echo json_encode(['success'=>false,'error'=>'Dados obrigatórios ausentes']); exit;
      }

      try {
        $pdo->beginTransaction();

        // dentro da transação:
        $st = $pdo->prepare("SELECT num_iniciativa FROM iniciativas WHERE id_kr=:k ORDER BY num_iniciativa DESC LIMIT 1 FOR UPDATE");
        $st->execute(['k'=>$id_kr]);
        $last = (int)($st->fetchColumn() ?: 0);
        $num  = $last + 1;

        // Gera id da iniciativa (varchar PK)
        $id_ini = bin2hex(random_bytes(12));

        // Insert dinâmico em iniciativas (resiliente a colunas)
        $colsI = ['id_iniciativa'=>$id_ini, 'id_kr'=>$id_kr, 'num_iniciativa'=>$num, 'descricao'=>$desc];
        if ($colExists($pdo,'iniciativas','status'))               $colsI['status'] = $status ?: 'Não Iniciado';
        if ($colExists($pdo,'iniciativas','id_user_responsavel'))  $colsI['id_user_responsavel'] = $resp ?: (int)$_SESSION['user_id'];
        if ($colExists($pdo,'iniciativas','dt_prazo') && $dt_prazo)$colsI['dt_prazo'] = $dt_prazo;
        if ($colExists($pdo,'iniciativas','dt_criacao'))           $colsI['dt_criacao'] = date('Y-m-d H:i:s');
        if ($colExists($pdo,'iniciativas','id_user_criador'))      $colsI['id_user_criador'] = (int)$_SESSION['user_id'];

        $fI = implode(',', array_keys($colsI));
        $mI = implode(',', array_map(fn($k)=>":$k", array_keys($colsI)));
        $st = $pdo->prepare("INSERT INTO iniciativas ($fI) VALUES ($mI)");
        $st->execute($colsI);

        // Orçamento opcional: 1 linha por competência em "orcamentos"
        $createdOrc = 0;
        if ($inclOrc && $tableExists($pdo,'orcamentos')) {
          $linhas = []; $sumPrev = 0.0;

          // Monta a partir do JSON (competencia yyyy-mm, valor)
          foreach ((array)$prevArr as $p) {
            $comp = preg_match('/^\d{4}-\d{2}$/', $p['competencia'] ?? '') ? $p['competencia'] : null;
            $val  = (float)($p['valor'] ?? 0);
            if ($comp && $val>0) { $linhas[] = [$comp, $val]; $sumPrev += $val; }
          }
          // Se houver total e diferença, ajusta a última parcela
          if ($linhas && $valorTot>0 && abs($sumPrev - $valorTot) > 0.01) {
            $linhas[count($linhas)-1][1] += ($valorTot - $sumPrev);
          }
          // Se não veio previsão, cria 1 parcela única
          if (!$linhas) {
            $comp = $dt_prazo ? substr($dt_prazo,0,7) : date('Y-m');
            $linhas = [[$comp, max($valorTot,0)]];
          }

          foreach ($linhas as [$comp,$val]) {
            $d   = $comp.'-01';
            $ins = ['id_iniciativa'=>$id_ini];
            if ($colExists($pdo,'orcamentos','valor'))               $ins['valor'] = $val;
            if ($colExists($pdo,'orcamentos','data_desembolso'))     $ins['data_desembolso'] = $d;
            if ($colExists($pdo,'orcamentos','status_aprovacao'))    $ins['status_aprovacao'] = 'pendente';
            if ($just && $colExists($pdo,'orcamentos','justificativa_orcamento')) $ins['justificativa_orcamento'] = $just;
            if ($colExists($pdo,'orcamentos','id_user_criador'))     $ins['id_user_criador'] = (int)$_SESSION['user_id'];
            if ($colExists($pdo,'orcamentos','dt_criacao'))          $ins['dt_criacao'] = date('Y-m-d H:i:s');

            $f = implode(',', array_keys($ins));
            $m = implode(',', array_map(fn($k)=>":$k", array_keys($ins)));
            $st = $pdo->prepare("INSERT INTO orcamentos ($f) VALUES ($m)");
            $st->execute($ins);
            $createdOrc++;
          }
        }

        $pdo->commit();
        echo json_encode(['success'=>true,'id_iniciativa'=>$id_ini,'num_iniciativa'=>$num,'orc_parcelas'=>$createdOrc]); exit;

      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success'=>false,'error'=>'Falha ao criar iniciativa: '.$e->getMessage()]); exit;
      }
    }



    /* ---------- LANÇAR DESPESA (orcamentos_detalhes) ---------- */
    if ($action === 'add_despesa') {
      if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success'=>false,'error'=>'Token CSRF inválido']); exit;
      }
      $id_orc = $_POST['id_orcamento'] ?? '';
      $valor  = (float)($_POST['valor'] ?? 0);
      $data   = $_POST['data_pagamento'] ?? '';
      $desc   = trim((string)($_POST['descricao'] ?? ''));

      if (!$id_orc || $valor<=0 || !$data) {
        echo json_encode(['success'=>false,'error'=>'Dados da despesa inválidos']); exit;
      }

      try {
        $ins = ['id_orcamento'=>$id_orc];
        if ($colExists($pdo,'orcamentos_detalhes','valor'))          $ins['valor'] = $valor;
        if ($colExists($pdo,'orcamentos_detalhes','data_pagamento')) $ins['data_pagamento'] = $data;
        if ($desc && $colExists($pdo,'orcamentos_detalhes','descricao')) $ins['descricao'] = $desc;
        if ($colExists($pdo,'orcamentos_detalhes','id_user_criador'))$ins['id_user_criador'] = (int)$_SESSION['user_id'];
        if ($colExists($pdo,'orcamentos_detalhes','dt_criacao'))     $ins['dt_criacao'] = date('Y-m-d H:i:s');

        $f = implode(',', array_keys($ins));
        $m = implode(',', array_map(fn($k)=>":$k", array_keys($ins)));
        $st = $pdo->prepare("INSERT INTO orcamentos_detalhes ($f) VALUES ($m)");
        $st->execute($ins);

        echo json_encode(['success'=>true]); exit;
      } catch (Throwable $e) {
        echo json_encode(['success'=>false,'error'=>'Falha ao lançar despesa']); exit;
      }
    }



  /* ---------- DASHBOARD DE ORÇAMENTOS (aba Orçamentos do KR) ---------- */
  if ($action === 'orc_dashboard') {
    $id_kr = $_GET['id_kr'] ?? '';
    $ano   = isset($_GET['ano']) ? (int)$_GET['ano'] : (int)date('Y');
    if (!$id_kr) { echo json_encode(['success'=>false,'error'=>'id_kr inválido']); exit; }

    try {
      // status KR
      $st = $pdo->prepare("SELECT `status` FROM `key_results` WHERE `id_kr`=:id LIMIT 1");
      $st->execute(['id'=>$id_kr]);
      $statusKr = (string)($st->fetchColumn() ?? '');
      $isCancel = stripos($statusKr, 'cancel') !== false;

      // Totais gerais
      $sqlA = "
        SELECT COALESCE(SUM(o.`valor`),0)
        FROM `orcamentos` o
        INNER JOIN `iniciativas` i ON i.`id_iniciativa`=o.`id_iniciativa`
        WHERE i.`id_kr`=:id";
      $sqlR = "
        SELECT COALESCE(SUM(od.`valor`),0)
        FROM `orcamentos_detalhes` od
        INNER JOIN `orcamentos` o ON o.`id_orcamento`=od.`id_orcamento`
        INNER JOIN `iniciativas` i ON i.`id_iniciativa`=o.`id_iniciativa`
        WHERE i.`id_kr`=:id";

      $sa = $pdo->prepare($sqlA); $sa->execute(['id'=>$id_kr]); $aprovado = (float)$sa->fetchColumn();
      $sr = $pdo->prepare($sqlR); $sr->execute(['id'=>$id_kr]); $realizado = (float)$sr->fetchColumn();
      $saldo = max(0, $aprovado - $realizado);

      // Planejado/Realizado até hoje
      $sa2 = $pdo->prepare($sqlA . " AND o.`data_desembolso` <= CURDATE()");
      $sa2->execute(['id'=>$id_kr]);
      $planAtHoje = (float)$sa2->fetchColumn();

      $sr2 = $pdo->prepare($sqlR . " AND od.`data_pagamento` <= CURDATE()");
      $sr2->execute(['id'=>$id_kr]);
      $realAtHoje = (float)$sr2->fetchColumn();

      $desvio = $realAtHoje - $planAtHoje;
      $farol = (abs($aprovado) < 0.01)
                  ? 'neutro'
                  : (abs($desvio) <= 0.05*$aprovado ? 'verde' : (abs($desvio) <= 0.15*$aprovado ? 'amarelo' : 'vermelho'));

      // Séries por mês do ano selecionado
      $bind = ['id'=>$id_kr, 'ano'=>$ano];

      $stmtPlan = $pdo->prepare("
        SELECT DATE_FORMAT(o.`data_desembolso`,'%Y-%m') AS comp, SUM(o.`valor`) AS val
        FROM `orcamentos` o
        INNER JOIN `iniciativas` i ON i.`id_iniciativa`=o.`id_iniciativa`
        WHERE i.`id_kr`=:id AND YEAR(o.`data_desembolso`)=:ano
        GROUP BY comp
      ");
      $stmtPlan->execute($bind);
      $byPlan = [];
      foreach ($stmtPlan as $r) $byPlan[$r['comp']] = (float)$r['val'];

      $stmtReal = $pdo->prepare("
        SELECT DATE_FORMAT(od.`data_pagamento`,'%Y-%m') AS comp, SUM(od.`valor`) AS val
        FROM `orcamentos_detalhes` od
        INNER JOIN `orcamentos` o ON o.`id_orcamento`=od.`id_orcamento`
        INNER JOIN `iniciativas` i ON i.`id_iniciativa`=o.`id_iniciativa`
        WHERE i.`id_kr`=:id AND YEAR(od.`data_pagamento`)=:ano
        GROUP BY comp
      ");
      $stmtReal->execute($bind);
      $byReal = [];
      foreach ($stmtReal as $r) $byReal[$r['comp']] = (float)$r['val'];

      $stmtPend = $pdo->prepare("
        SELECT DATE_FORMAT(o.`data_desembolso`,'%Y-%m') AS comp, COUNT(*) AS qtd
        FROM `orcamentos` o
        INNER JOIN `iniciativas` i ON i.`id_iniciativa`=o.`id_iniciativa`
        WHERE i.`id_kr`=:id AND YEAR(o.`data_desembolso`)=:ano
              AND COALESCE(o.`status_aprovacao`,'pendente')='pendente'
        GROUP BY comp
      ");
      $stmtPend->execute($bind);
      $byPend = [];
      foreach ($stmtPend as $r) $byPend[$r['comp']] = (int)$r['qtd'];

      $series = [];
      $planAc=0; $realAc=0;
      for($m=1;$m<=12;$m++){
        $comp = sprintf('%04d-%02d',$ano,$m);
        $p = $byPlan[$comp] ?? 0.0;
        $r = $byReal[$comp] ?? 0.0;
        $planAc += $p; $realAc += $r;
        $series[] = [
          'competencia' => $comp,
          'planejado'   => round($p,2),
          'realizado'   => round($r,2),
          'plan_acum'   => round($planAc,2),
          'real_acum'   => round($realAc,2),
          'tem_pendente'=> !empty($byPend[$comp])
        ];
      }

      // Por iniciativa (totais)
      $sqlInis = "
        SELECT i.`id_iniciativa`, i.`num_iniciativa`, i.`descricao`,
               u.`primeiro_nome` AS responsavel,
               COALESCE(a.v_aprov,0) AS aprovado,
               COALESCE(r.v_real,0) AS realizado
        FROM `iniciativas` i
        LEFT JOIN `usuarios` u ON u.`id_user` = i.`id_user_responsavel`
        LEFT JOIN (
          SELECT `id_iniciativa`, SUM(`valor`) v_aprov
          FROM `orcamentos` GROUP BY `id_iniciativa`
        ) a ON a.`id_iniciativa` = i.`id_iniciativa`
        LEFT JOIN (
          SELECT o.`id_iniciativa`, SUM(od.`valor`) v_real
          FROM `orcamentos_detalhes` od
          INNER JOIN `orcamentos` o ON o.`id_orcamento`=od.`id_orcamento`
          GROUP BY o.`id_iniciativa`
        ) r ON r.`id_iniciativa` = i.`id_iniciativa`
        WHERE i.`id_kr` = :id
        ORDER BY i.`num_iniciativa` ASC, i.`dt_criacao` ASC
      ";
      $stInis = $pdo->prepare($sqlInis);
      $stInis->execute(['id'=>$id_kr]);
      $iniItems = [];
      foreach($stInis as $row){
        $saldoI = max(0, (float)$row['aprovado'] - (float)$row['realizado']);
        $iniItems[] = [
          'id_iniciativa' => $row['id_iniciativa'],
          'num_iniciativa' => (int)$row['num_iniciativa'],
          'descricao' => $row['descricao'],
          'responsavel' => $row['responsavel'] ?: '—',
          'aprovado' => (float)$row['aprovado'],
          'realizado' => (float)$row['realizado'],
          'saldo' => $saldoI
        ];
      }

      // Pendências
      $stPend = $pdo->prepare("
        SELECT o.`id_orcamento`, o.`id_iniciativa`, o.`valor`, o.`data_desembolso`, o.`justificativa_orcamento`,
               u.`primeiro_nome` AS criador_nome
        FROM `orcamentos` o
        INNER JOIN `iniciativas` i ON i.`id_iniciativa`=o.`id_iniciativa`
        LEFT JOIN `usuarios` u ON u.`id_user` = o.`id_user_criador`
        WHERE i.`id_kr`=:id AND COALESCE(o.`status_aprovacao`,'pendente')='pendente'
        ORDER BY o.`data_desembolso` ASC, o.`id_orcamento` ASC
        LIMIT 40
      ");
      $stPend->execute(['id'=>$id_kr]);
      $pend = $stPend->fetchAll() ?: [];

      // Últimas despesas
      $stUlt = $pdo->prepare("
        SELECT od.`id_despesa`, od.`valor`, od.`data_pagamento`, od.`descricao`,
               u.`primeiro_nome` AS criador_nome
        FROM `orcamentos_detalhes` od
        INNER JOIN `orcamentos` o ON o.`id_orcamento`=od.`id_orcamento`
        INNER JOIN `iniciativas` i ON i.`id_iniciativa`=o.`id_iniciativa`
        LEFT JOIN `usuarios` u ON u.`id_user` = od.`id_user_criador`
        WHERE i.`id_kr`=:id
        ORDER BY COALESCE(od.`data_pagamento`, od.`dt_criacao`) DESC, od.`id_despesa` DESC
        LIMIT 12
      ");
      $stUlt->execute(['id'=>$id_kr]);
      $ult = $stUlt->fetchAll() ?: [];

      echo json_encode([
        'success'=>true,
        'is_cancelado'=>$isCancel,
        'totais'=>[
          'aprovado'=>$aprovado,
          'realizado'=>$realizado,
          'saldo'=>$saldo,
          'planejado_ate_hoje'=>$planAtHoje,
          'realizado_ate_hoje'=>$realAtHoje,
          'desvio'=>$desvio,
          'farol'=>$farol
        ],
        'series'=>$series,
        'por_iniciativa'=>$iniItems,
        'pendencias'=>$pend,
        'ultimas_despesas'=>$ult,
        'range'=>['inicio'=>"$ano-01",'fim'=>"$ano-12"]
      ]);
      exit;
    } catch(Throwable $e){
      error_log('orc_dashboard: '.$e->getMessage());
      echo json_encode(['success'=>false,'error'=>'Falha ao carregar dashboard de orçamentos']);
      exit;
    }
  }

  /* ---------- LISTAR STATUS DE KR ---------- */
  if ($action === 'list_status_kr') {
    try {
      $cols = $pdo->query("SHOW COLUMNS FROM `dom_status_kr`")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
      echo json_encode(['success'=>false,'error'=>'Tabela dom_status_kr não encontrada']);
      exit;
    }
    $names = array_column($cols,'Field');
    $idCol = in_array('id_status',$names,true) ? 'id_status' : (in_array('id',$names,true) ? 'id' : $names[0]);
    $labelCol = in_array('descricao_exibicao',$names,true) ? 'descricao_exibicao'
              : (in_array('descricao',$names,true) ? 'descricao'
              : (in_array('nome',$names,true) ? 'nome' : ($names[1] ?? $names[0])));

    $rows = $pdo->query("SELECT `$idCol` AS id, `$labelCol` AS label FROM `dom_status_kr`")->fetchAll();

    $onlyActive = isset($_GET['only_active']) ? (int)$_GET['only_active'] : 0;
    if ($onlyActive) {
      $norm = fn($s)=>mb_strtolower(trim((string)$s));
      $rows = array_values(array_filter($rows, fn($r)=>
        strpos($norm($r['id']), 'cancel')===false && strpos($norm($r['label']), 'cancel')===false
      ));
    }
    echo json_encode(['success'=>true,'items'=>$rows]);
    exit;
  }

  /* ---------- REATIVAR KR ---------- */
  if ($action === 'reactivate_kr') {
    if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
      http_response_code(403);
      echo json_encode(['success'=>false,'error'=>'Token CSRF inválido']);
      exit;
    }
    $id_kr = $_POST['id_kr'] ?? '';
    $statusTarget = trim($_POST['status_target'] ?? '');
    if (!$id_kr){ echo json_encode(['success'=>false,'error'=>'id_kr inválido']); exit; }

    try {
      $pdo->beginTransaction();

      $statusCol = null;
      foreach (['status','situacao','state'] as $c) {
        $st = $pdo->prepare("SHOW COLUMNS FROM `key_results` LIKE :c");
        $st->execute(['c'=>$c]);
        if ($st->fetch()) { $statusCol = $c; break; }
      }
      if (!$statusCol) { throw new RuntimeException('Coluna de status não encontrada em key_results'); }

      $cols = $pdo->query("SHOW COLUMNS FROM `dom_status_kr`")->fetchAll(PDO::FETCH_ASSOC);
      if (!$cols) throw new RuntimeException('Tabela dom_status_kr não encontrada');
      $names = array_column($cols,'Field');
      $idCol = in_array('id_status',$names,true) ? 'id_status' : (in_array('id',$names,true) ? 'id' : $names[0]);
      $labelCol = in_array('descricao_exibicao',$names,true) ? 'descricao_exibicao'
                : (in_array('descricao',$names,true) ? 'descricao'
                : (in_array('nome',$names,true) ? 'nome' : ($names[1] ?? $names[0])));
      $rows = $pdo->query("SELECT `$idCol` AS id, `$labelCol` AS label FROM `dom_status_kr`")->fetchAll();

      $norm = function($s){
        $s = mb_strtolower(trim((string)$s));
        $s2 = @iconv('UTF-8','ASCII//TRANSLIT',$s);
        if ($s2 !== false && $s2 !== null) $s = $s2;
        $s = preg_replace('/\s+/',' ',$s);
        return $s;
      };

      $targetId = null;
      if ($statusTarget !== '') {
        $needle = $norm($statusTarget);
        foreach ($rows as $r) {
          if ($norm($r['id']) === $needle || $norm($r['label']) === $needle) { $targetId = $r['id']; break; }
        }
        if ($targetId === null) throw new RuntimeException('Status informado é inválido.');
      } else {
        $pref = ['em andamento','nao iniciado','concluido','ativo','open','ongoing'];
        foreach ($pref as $p) {
          foreach ($rows as $r) {
            if ($norm($r['id']) === $p || $norm($r['label']) === $p) { $targetId = $r['id']; break 2; }
          }
        }
        if ($targetId === null) {
          foreach ($rows as $r) {
            if (strpos($norm($r['id']), 'cancel') === false && strpos($norm($r['label']), 'cancel') === false) { $targetId = $r['id']; break; }
          }
        }
      }
      if ($targetId === null) throw new RuntimeException('Não há status válido para reativar.');

      $st = $pdo->prepare("UPDATE `key_results` SET `{$statusCol}` = :v WHERE `id_kr` = :id");
      $st->execute(['v'=>$targetId, 'id'=>$id_kr]);

      $user = (int)$_SESSION['user_id'];
      $addKrComment($pdo, $id_kr, $user, "KR reativado para o status: " . $targetId);

      $pdo->commit();
      echo json_encode(['success'=>true]);
      exit;
    } catch(Throwable $e){
      if ($pdo->inTransaction()) $pdo->rollBack();
      error_log('reactivate_kr: '.$e->getMessage());
      echo json_encode(['success'=>false,'error'=>'Falha ao reativar KR: '.$e->getMessage()]);
      exit;
    }
  }

  /* ---------- CANCELAR KR (string/FK) ---------- */
  if ($action === 'cancel_kr') {
    if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
      http_response_code(403);
      echo json_encode(['success'=>false,'error'=>'Token CSRF inválido']);
      exit;
    }
    $id_kr = $_POST['id_kr'] ?? '';
    $just  = trim($_POST['justificativa'] ?? '');
    if (!$id_kr || $just===''){ echo json_encode(['success'=>false,'error'=>'Informe a justificativa.']); exit; }

    try {
      $pdo->beginTransaction();

      $statusCol = null;
      foreach (['status','situacao','state'] as $c) {
        $st = $pdo->prepare("SHOW COLUMNS FROM `key_results` LIKE :c");
        $st->execute(['c'=>$c]);
        if ($st->fetch()) { $statusCol = $c; break; }
      }
      if (!$statusCol) { throw new RuntimeException('Coluna de status não encontrada em key_results'); }

      $cancelId = null; $rows = null;
      try {
        $cols = $pdo->query("SHOW COLUMNS FROM `dom_status_kr`")->fetchAll(PDO::FETCH_ASSOC);
        if ($cols) {
          $names = array_column($cols,'Field');
          $idCol = in_array('id_status',$names,true) ? 'id_status' : (in_array('id',$names,true) ? 'id' : $names[0]);
          $labelCol = in_array('descricao_exibicao',$names,true) ? 'descricao_exibicao'
                    : (in_array('descricao',$names,true) ? 'descricao'
                    : (in_array('nome',$names,true) ? 'nome' : ($names[1] ?? $names[0])));
          $rows = $pdo->query("SELECT `$idCol` AS id, `$labelCol` AS label FROM `dom_status_kr`")->fetchAll();
          $norm = function($s){ $s = mb_strtolower(trim((string)$s)); $s2 = @iconv('UTF-8','ASCII//TRANSLIT',$s); if ($s2 !== false && $s2 !== null) $s = $s2; return preg_replace('/\s+/',' ',$s); };
          foreach ($rows as $r) { if ($norm($r['id'])==='cancelado' || $norm($r['label'])==='cancelado') { $cancelId = (string)$r['id']; break; } }
          if ($cancelId === null) {
            foreach ($rows as $r) { if (strpos($norm($r['id']), 'cancel')!==false || strpos($norm($r['label']), 'cancel')!==false) { $cancelId = (string)$r['id']; break; } }
          }
        }
      } catch (Throwable $e) { /* ignora */ }

      if ($cancelId === null) {
        $hasDom = isset($rows);
        if ($hasDom) throw new RuntimeException('Status "cancelado" não encontrado em dom_status_kr');
        $cancelId = 'Cancelado';
      }

      $st = $pdo->prepare("UPDATE `key_results` SET `{$statusCol}` = :v WHERE `id_kr` = :id");
      $st->execute(['v'=>$cancelId, 'id'=>$id_kr]);

      $user = (int)$_SESSION['user_id'];
      $addKrComment($pdo, $id_kr, $user, "KR cancelado. Justificativa: ".$just);

      $pdo->commit();
      echo json_encode(['success'=>true]);
      exit;
    } catch(Throwable $e){
      if ($pdo->inTransaction()) $pdo->rollBack();
      error_log('cancel_kr: '.$e->getMessage());
      echo json_encode(['success'=>false,'error'=>'Falha ao cancelar KR: '.$e->getMessage()]);
      exit;
    }
  }

  /* ---------- EXCLUIR KR (permanente) ---------- */
  if ($action === 'delete_kr') {
    if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
      http_response_code(403);
      echo json_encode(['success'=>false,'error'=>'Token CSRF inválido']);
      exit;
    }
    $id_kr = $_POST['id_kr'] ?? '';
    if (!$id_kr){ echo json_encode(['success'=>false,'error'=>'id_kr inválido']); exit; }

    try {
      $pdo->beginTransaction();

      // id_objetivo do KR
      $st = $pdo->prepare("SELECT `id_objetivo` FROM `key_results` WHERE `id_kr`=:id LIMIT 1");
      $st->execute(['id'=>$id_kr]);
      $id_obj = (int)($st->fetchColumn() ?: 0);

      // 1) Apaga despesas e orçamentos das iniciativas do KR
      if ($tableExists($pdo,'iniciativas')) {
        $st = $pdo->prepare("SELECT `id_iniciativa` FROM `iniciativas` WHERE `id_kr`=:id");
        $st->execute(['id'=>$id_kr]);
        $inis = $st->fetchAll(PDO::FETCH_COLUMN);

        if ($inis) {
          if ($tableExists($pdo,'orcamentos')) {
            if ($tableExists($pdo,'orcamentos_detalhes')) {
              $st = $pdo->prepare("DELETE od FROM `orcamentos_detalhes` od
                                   INNER JOIN `orcamentos` o ON o.`id_orcamento`=od.`id_orcamento`
                                   WHERE o.`id_iniciativa` IN (" . implode(',', array_fill(0,count($inis),'?')) . ")");
              $st->execute($inis);
            }
            $st = $pdo->prepare("DELETE FROM `orcamentos` WHERE `id_iniciativa` IN (" . implode(',', array_fill(0,count($inis),'?')) . ")");
            $st->execute($inis);
          }
          $st = $pdo->prepare("DELETE FROM `iniciativas` WHERE `id_iniciativa` IN (" . implode(',', array_fill(0,count($inis),'?')) . ")");
          $st->execute($inis);
        }
      }

      // 2) Milestones
      foreach (['milestones_kr','milestones'] as $t) {
        if (!$tableExists($pdo,$t)) continue;
        $krCol = $findKrIdCol($pdo,$t);
        if (!$krCol) $krCol = $colExists($pdo,$t,'id_kr') ? 'id_kr' : null;
        if ($krCol) {
          $st = $pdo->prepare("DELETE FROM `$t` WHERE `$krCol` = :id");
          $st->execute(['id'=>$id_kr]);
        }
      }

      // 3) Apontamentos
      foreach (['apontamentos_kr','apontamentos'] as $t) {
        if (!$tableExists($pdo,$t)) continue;
        $krCol = $findKrIdCol($pdo,$t);
        if (!$krCol) $krCol = $colExists($pdo,$t,'id_kr') ? 'id_kr' : null;
        if ($krCol) {
          $st = $pdo->prepare("DELETE FROM `$t` WHERE `$krCol` = :id");
          $st->execute(['id'=>$id_kr]);
        }
      }

      // 4) Comentários
      foreach (['kr_comentarios','comentarios_kr'] as $t) {
        if ($tableExists($pdo,$t) && $colExists($pdo,$t,'id_kr')) {
          $st = $pdo->prepare("DELETE FROM `$t` WHERE `id_kr` = :id");
          $st->execute(['id'=>$id_kr]);
        }
      }

      // 5) KR
      $st = $pdo->prepare("DELETE FROM `key_results` WHERE `id_kr`=:id");
      $st->execute(['id'=>$id_kr]);

      // 6) Renumeração
      if ($id_obj > 0 && $colExists($pdo,'key_results','key_result_num')) {
        $st = $pdo->prepare("SELECT `id_kr` FROM `key_results` WHERE `id_objetivo`=:obj ORDER BY `key_result_num` ASC, `id_kr` ASC");
        $st->execute(['obj'=>$id_obj]);
        $ids = $st->fetchAll(PDO::FETCH_COLUMN);
        if ($ids) {
          $upd = $pdo->prepare("UPDATE `key_results` SET `key_result_num`=:n WHERE `id_kr`=:id");
          $n = 1;
          foreach ($ids as $kid) { $upd->execute(['n'=>$n++, 'id'=>$kid]); }
        }
      }

      $pdo->commit();
      echo json_encode(['success'=>true]);
      exit;
    } catch(Throwable $e){
      $pdo->rollBack();
      echo json_encode(['success'=>false,'error'=>'Falha ao excluir KR']);
      exit;
    }
  }

  /* ---------- APONTAMENTO: DADOS DO MODAL (lista milestones do KR) ---------- */
  if ($action === 'apont_modal_data') {
    $id_kr = $_GET['id_kr'] ?? '';
    if (!$id_kr) { echo json_encode(['success'=>false,'error'=>'id_kr inválido']); exit; }

    // Info do KR (resiliente a colunas)
    $cKR = fn($name)=> $colExists($pdo,'key_results',$name) ? "`$name`" : "NULL AS `$name`";
    $stKR = $pdo->prepare("
      SELECT `id_kr`,
            {$cKR('descricao')}, {$cKR('unidade_medida')}, {$cKR('baseline')}, {$cKR('meta')}, {$cKR('direcao_metrica')}
      FROM `key_results` WHERE `id_kr`=:id LIMIT 1
    ");
    $stKR->execute(['id'=>$id_kr]);
    $kr = $stKR->fetch();
    if (!$kr) { echo json_encode(['success'=>false,'error'=>'KR não encontrado']); exit; }

    // Descobre tabela/colunas de milestones
    $msTable = null;
    foreach (['milestones_kr','milestones'] as $t) {
      try { $pdo->query("SHOW COLUMNS FROM `$t`"); $msTable=$t; break; } catch(Throwable $e){}
    }
    if (!$msTable) { echo json_encode(['success'=>false,'error'=>'Tabela de milestones não encontrada']); exit; }

    $cols = $pdo->query("SHOW COLUMNS FROM `$msTable`")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $has  = function(string $n) use($cols){ foreach($cols as $c){ if (strcasecmp($c['Field'],$n)===0) return true; } return false; };

    $krCol   = $has('id_kr') ? 'id_kr' : ($findKrIdCol($pdo,$msTable) ?: null);
    $idCol   = $has('id_milestone') ? 'id_milestone' : ($has('id_ms') ? 'id_ms' : ($has('id') ? 'id' : null));
    $dateCol = $has('data_ref') ? 'data_ref' : ($has('dt_prevista') ? 'dt_prevista' : ($has('data_prevista') ? 'data_prevista' : null));
    $expCol  = $has('valor_esperado') ? 'valor_esperado' : ($has('esperado') ? 'esperado' : null);
    $realCol = $has('valor_real') ? 'valor_real'
            : ($has('realizado') ? 'realizado'
            : ($has('valor_real_consolidado') ? 'valor_real_consolidado' : null));
    $eviCol  = $has('dt_evidencia') ? 'dt_evidencia'
            : ($has('data_evidencia') ? 'data_evidencia'
            : ($has('dt_ultimo_apontamento') ? 'dt_ultimo_apontamento' : null));

    if (!$krCol || !$dateCol || !$expCol) {
      echo json_encode(['success'=>false,'error'=>'Colunas essenciais do milestone ausentes']); exit;
    }

    $sql = "SELECT `$dateCol` AS data_prevista, `$expCol` AS valor_esperado"
        . ($realCol? ", `$realCol` AS valor_real" : ", NULL AS valor_real")
        . ($eviCol ? ", `$eviCol` AS dt_evidencia" : ", NULL AS dt_evidencia")
        . ($idCol ? ", `$idCol` AS id_ms" : ", NULL AS id_ms")
        . " FROM `$msTable` WHERE `$krCol`=:id ORDER BY `$dateCol` ASC";
    $stm = $pdo->prepare($sql);
    $stm->execute(['id'=>$id_kr]);
    $rows = $stm->fetchAll();

    // NOVO: injeta numeração 1/total
    $total = count($rows);
    foreach ($rows as $i => &$r) {
      $r['ordem_label'] = ($i+1) . '/' . $total; // ex.: "1/12"
    }
    unset($r);


    // NOVO retorno ÚNICO e completo:
    echo json_encode([
      'success' => true,
      'kr' => [
        'id_kr'           => $kr['id_kr'],
        'descricao'       => $kr['descricao'] ?? null,
        'unidade_medida'  => $kr['unidade_medida'] ?? null,
        'baseline'        => $kr['baseline'] ?? null,
        'meta'            => $kr['meta'] ?? null,
        'direcao_metrica' => $kr['direcao_metrica'] ?? null
      ],
      'milestones' => $rows
    ]);
    exit;

  }

  /* ---------- APONTAMENTO: SALVAR (multi-linhas por milestone) ---------- */
  if ($action === 'apont_save') {
    if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
      http_response_code(403);
      echo json_encode(['success'=>false,'error'=>'Token CSRF inválido']); exit;
    }
    $id_kr = $_POST['id_kr'] ?? '';
    $items = json_decode($_POST['items_json'] ?? '[]', true) ?: [];
    if (!$id_kr || !$items || !is_array($items)) {
      echo json_encode(['success'=>false,'error'=>'Dados inválidos para apontamento']); exit;
    }

    // Tabela/colunas de milestones (mesma lógica do modal)
    $msTable=null;
    foreach(['milestones_kr','milestones'] as $t){ try{$pdo->query("SHOW COLUMNS FROM `$t`"); $msTable=$t; break;}catch(Throwable $e){} }
    if(!$msTable){ echo json_encode(['success'=>false,'error'=>'Tabela de milestones não encontrada']); exit; }

    $cols = $pdo->query("SHOW COLUMNS FROM `$msTable`")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $has  = function(string $n) use($cols){ foreach($cols as $c){ if (strcasecmp($c['Field'],$n)===0) return true; } return false; };

    $krCol   = $has('id_kr') ? 'id_kr' : ($findKrIdCol($pdo,$msTable) ?: null);
    $idCol   = $has('id_milestone') ? 'id_milestone' : ($has('id_ms') ? 'id_ms' : ($has('id') ? 'id' : null));
    $dateCol = $has('data_ref') ? 'data_ref' : ($has('dt_prevista') ? 'dt_prevista' : ($has('data_prevista') ? 'data_prevista' : null));
    $realCol = $has('valor_real') ? 'valor_real'
            : ($has('realizado') ? 'realizado'
            : ($has('valor_real_consolidado') ? 'valor_real_consolidado' : null));
    $eviCol  = $has('dt_evidencia') ? 'dt_evidencia'
            : ($has('data_evidencia') ? 'data_evidencia' : null);
    $apoCol  = $has('dt_apontamento') ? 'dt_apontamento'
            : ($has('data_apontamento') ? 'data_apontamento'
            : ($has('dt_ultimo_apontamento') ? 'dt_ultimo_apontamento' : null));
    $usrCol  = $has('id_user_apontamento') ? 'id_user_apontamento'
            : ($has('id_user_ult_alteracao') ? 'id_user_ult_alteracao' : null);

    /* extras do seu schema */
    $cntCol  = $has('qtde_apontamentos') ? 'qtde_apontamentos' : null;
    $manCol  = $has('editado_manual') ? 'editado_manual' : null;
    $blkCol  = $has('bloqueado_para_edicao') ? 'bloqueado_para_edicao' : null;

    if (!$krCol || !$dateCol || !$realCol) {
      echo json_encode(['success'=>false,'error'=>'Colunas para salvar apontamento ausentes']); exit;
    }

    // Tabela de log de apontamentos (opcional)
    $apTable = null;
    foreach (['apontamentos_kr','apontamentos'] as $t) {
      try { $pdo->query("SHOW COLUMNS FROM `$t`"); $apTable=$t; break; } catch(Throwable $e){}
    }
    $getCol = function(string $table, array $cands) use($pdo){ foreach($cands as $c){ try{ $st=$pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :c"); $st->execute(['c'=>$c]); if($st->fetch()) return $c; }catch(Throwable $e){} } return null; };

    try {
      $pdo->beginTransaction();
      $userId = (int)$_SESSION['user_id'];
      $ok = 0;

      $outRows = [];

      foreach ($items as $it) {
        $id_ms   = $it['id_ms'] ?? null;
        $dataRef = $it['data_prevista'] ?? null; // yyyy-mm-dd
        $valor   = isset($it['valor_real']) ? (float)$it['valor_real'] : null;
        $evid    = trim((string)($it['dt_evidencia'] ?? ''));
        $obs     = trim((string)($it['observacao'] ?? ''));

        if ($valor === null || $valor === '' || (!$dataRef && !$id_ms)) continue;

        // Lê valor anterior para detectar overwrite
        $was = null;
        if ($idCol && $id_ms) {
          $st0 = $pdo->prepare("SELECT `$realCol` FROM `$msTable` WHERE `$idCol`=:idms LIMIT 1");
          $st0->execute(['idms'=>$id_ms]);
        } else {
          $st0 = $pdo->prepare("SELECT `$realCol` FROM `$msTable` WHERE `$krCol`=:ik AND `$dateCol`=:dr LIMIT 1");
          $st0->execute(['ik'=>$id_kr,'dr'=>$dataRef]);
        }
        $was = $st0->fetchColumn();
        $overwrite = ($was !== null && $was !== '' && (float)$was != (float)$valor);

        // WHERE para SELECT/UPDATE
        $whereParts = [];
        $whereBind  = [];
        if ($idCol && $id_ms) {
          $whereParts[] = "`$idCol` = :idms";
          $whereBind[':idms'] = $id_ms;
          $whereParts[] = "`$krCol` = :ik";
          $whereBind[':ik'] = $id_kr;
        } else {
          $whereParts[] = "`$krCol` = :ik";
          $whereBind[':ik'] = $id_kr;
          $whereParts[] = "`$dateCol` = :dr";
          $whereBind[':dr'] = $dataRef;
        }

        // Bloqueio
        if ($blkCol) {
          $stB = $pdo->prepare("SELECT `$blkCol` FROM `$msTable` WHERE ".implode(' AND ',$whereParts)." LIMIT 1");
          $stB->execute($whereBind);
          if ((int)$stB->fetchColumn() === 1) {
            throw new RuntimeException('Milestone bloqueado para edição');
          }
        }

        // UPDATE milestone
        $sets = ["`$realCol` = :vr"];
        $bind = [':vr'=>$valor] + $whereBind;

        if ($eviCol) { $sets[] = "`$eviCol` = :de"; $bind[':de'] = $evid ?: date('Y-m-d'); }
        if ($apoCol) { $sets[] = "`$apoCol` = NOW()"; }
        if ($usrCol) { $sets[] = "`$usrCol` = :uu"; $bind[':uu'] = $userId; }
        if ($cntCol) { $sets[] = "`$cntCol` = COALESCE(`$cntCol`,0) + 1"; }
        if ($manCol) { $sets[] = "`$manCol` = 1"; }

        $sqlUp = "UPDATE `$msTable` SET ".implode(', ',$sets)." WHERE ".implode(' AND ',$whereParts)." LIMIT 1";
        $st = $pdo->prepare($sqlUp);
        $st->execute($bind);

        // LOG em apontamentos_kr / apontamentos (se existir)
        if ($apTable) {
          $colsAp = $pdo->query("SHOW COLUMNS FROM `$apTable`")->fetchAll(PDO::FETCH_ASSOC) ?: [];
          $hasAp  = function($n) use($colsAp){ foreach($colsAp as $c){ if(strcasecmp($c['Field'],$n)===0) return true; } return false; };

          $krAp   = $getCol($apTable, ['id_kr','kr_id','id_key_result','key_result_id']);
          $dtRef  = $getCol($apTable, ['data_ref','data_prevista']); // sua tabela não tem, é opcional
          $vrAp   = $getCol($apTable, ['valor_real','valor']);
          $eviAp  = $getCol($apTable, ['dt_evidencia','data_evidencia']);
          $obsAp  = $getCol($apTable, ['observacao','obs','descricao','comentario','mensagem']);
          $usrAp  = $getCol($apTable, ['id_user','id_usuario','usuario_id','user_id']);
          $dtaAp  = $getCol($apTable, ['dt_apontamento','created_at','dt_criacao','data']);
          $msAp   = $getCol($apTable, ['id_milestone','id_ms']); // novo

          $ins = [];
          if ($krAp)  $ins[$krAp]  = $id_kr;
          if ($msAp && $id_ms) $ins[$msAp] = $id_ms;
          if ($dtRef) $ins[$dtRef] = $dataRef ?: ($evid ?: date('Y-m-d'));
          if ($vrAp)  $ins[$vrAp]  = $valor;
          if ($eviAp) $ins[$eviAp] = $evid ?: date('Y-m-d');
          if ($obs && $obsAp) $ins[$obsAp] = $obs;
          if ($usrAp) $ins[$usrAp] = $userId;
          if ($dtaAp) $ins[$dtaAp] = date('Y-m-d H:i:s');

          if ($ins) {
            $f = implode(',', array_map(fn($k)=>"`$k`", array_keys($ins)));
            $m = implode(',', array_map(fn($k)=>":$k", array_keys($ins)));
            $stI = $pdo->prepare("INSERT INTO `$apTable` ($f) VALUES ($m)");
            $stI->execute($ins);
          }
        }

        // Retorno atualizado para o front
        $stR = $pdo->prepare("
          SELECT `$dateCol` AS data_prevista,
                COALESCE(`$realCol`, NULL) AS valor_real,
                ".($eviCol ? "`$eviCol`" : "NULL")." AS dt_evidencia
          FROM `$msTable`
          WHERE ".implode(' AND ',$whereParts)."
          LIMIT 1
        ");
        $stR->execute($whereBind);
        $lastRow = $stR->fetch() ?: null;

        $outRows[] = [
          'id_ms'         => $id_ms,
          'data_prevista' => $lastRow['data_prevista'] ?? $dataRef,
          'valor_real'    => isset($lastRow['valor_real']) ? (float)$lastRow['valor_real'] : $valor,
          'dt_evidencia'  => $lastRow['dt_evidencia'] ?? ($evid ?: date('Y-m-d')),
          'overwrite'     => $overwrite
        ];

        $ok++;
      }


      $pdo->commit();
      echo json_encode(['success'=>true,'salvos'=>$ok,'rows'=>$outRows ?? []]);
      exit;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      echo json_encode(['success'=>false,'error'=>'Falha ao salvar apontamentos']); exit;
    }
  }

/* ---------- APONTAMENTO: UPLOAD DE EVIDÊNCIA (por milestone) ---------- */
if ($action === 'apont_file_upload') {
  if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success'=>false,'error'=>'Token CSRF inválido']); exit;
  }

  $id_kr = $_POST['id_kr'] ?? '';
  $id_ms = $_POST['id_ms'] ?? '';
  if (!$id_kr || !$id_ms || empty($_FILES['evidencia']['tmp_name'])) {
    echo json_encode(['success'=>false,'error'=>'Dados inválidos']); exit;
  }

  // Pasta base (ajuste se seu projeto usar outra raiz pública)
  $base = realpath(__DIR__ . '/../uploads');
  if (!$base) { @mkdir(__DIR__ . '/../uploads', 0775, true); $base = realpath(__DIR__ . '/../uploads'); }
  $destDir = $base . '/kr_evidencias/' . preg_replace('/[^a-zA-Z0-9_\-]/','',$id_kr) . '/' . preg_replace('/[^a-zA-Z0-9_\-]/','',$id_ms);
  if (!is_dir($destDir)) @mkdir($destDir, 0775, true);

  // Bloqueia executáveis por extensão e por MIME
  $fn   = $_FILES['evidencia']['name'] ?? 'arquivo';
  $tmp  = $_FILES['evidencia']['tmp_name'];
  $ext  = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
  $banExt = ['exe','msi','bat','cmd','sh','com','scr','jar','apk','cgi','php','phar','pl','py'];
  if (in_array($ext, $banExt,true)) { echo json_encode(['success'=>false,'error'=>'Extensão não permitida']); exit; }

  $fi = new finfo(FILEINFO_MIME_TYPE);
  $mime = $fi->file($tmp) ?: 'application/octet-stream';
  $banMime = [
    'application/x-dosexec','application/x-msdownload','application/x-ms-installer',
    'application/x-executable','application/x-sh','application/java-archive',
    'application/x-php','text/x-php','application/x-python','text/x-python',
  ];
  if (in_array($mime,$banMime,true)) { echo json_encode(['success'=>false,'error'=>'Tipo de arquivo não permitido']); exit; }

  // Move com nome único
  $safe = preg_replace('/[^a-zA-Z0-9_\-\.]/','_', $fn);
  $dest = $destDir . '/' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '_' . $safe;
  if (!move_uploaded_file($tmp, $dest)) { echo json_encode(['success'=>false,'error'=>'Falha ao salvar arquivo']); exit; }

  // URL pública
  $public = '/OKR_system/uploads/kr_evidencias/' . rawurlencode($id_kr) . '/' . rawurlencode($id_ms) . '/' . rawurlencode(basename($dest));

  echo json_encode(['success'=>true,'file'=>['name'=>basename($dest),'url'=>$public]]);
  exit;
}

/* ---------- APONTAMENTO: LISTAR EVIDÊNCIAS (por milestone) ---------- */
if ($action === 'apont_file_list') {
  $id_kr = $_GET['id_kr'] ?? '';
  $id_ms = $_GET['id_ms'] ?? '';
  if (!$id_kr || !$id_ms){ echo json_encode(['success'=>false,'error'=>'Parâmetros inválidos']); exit; }

  $base = realpath(__DIR__ . '/../uploads/kr_evidencias/' . $id_kr . '/' . $id_ms);
  $items = [];
  if ($base && is_dir($base)) {
    foreach (scandir($base) as $f) {
      if ($f==='.'||$f==='..') continue;
      $path = $base.'/'.$f;
      if (is_file($path)) {
        $items[] = [
          'name'=>$f,
          'url' => '/OKR_system/uploads/kr_evidencias/'.rawurlencode($id_kr).'/'.rawurlencode($id_ms).'/'.rawurlencode($f),
          'size'=> filesize($path)
        ];
      }
    }
  }
  echo json_encode(['success'=>true,'files'=>$items]);
  exit;
}

/* ---------- APONTAMENTO: EXCLUIR EVIDÊNCIA (com justificativa) ---------- */
if ($action === 'apont_file_delete') {
  if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success'=>false,'error'=>'Token CSRF inválido']); exit;
  }
  $id_kr = $_POST['id_kr'] ?? '';
  $id_ms = $_POST['id_ms'] ?? '';
  $name  = $_POST['name']  ?? '';
  $just  = trim($_POST['justificativa'] ?? '');
  if (!$id_kr || !$id_ms || !$name || $just===''){ echo json_encode(['success'=>false,'error'=>'Justificativa é obrigatória']); exit; }

  $dir  = realpath(__DIR__ . '/../uploads/kr_evidencias/' . $id_kr . '/' . $id_ms);
  $file = $dir ? $dir . '/' . basename($name) : null;
  if (!$dir || !is_dir($dir) || !$file || !is_file($file)) {
    echo json_encode(['success'=>false,'error'=>'Arquivo não encontrado']); exit;
  }

  // Log simples na timeline do KR (reutiliza helper, se quiser)
  try { $addKrComment($pdo, $id_kr, (int)$_SESSION['user_id'], "Evidência removida de MS {$id_ms}. Motivo: ".$just); } catch(Throwable $e){}

  if (!unlink($file)) { echo json_encode(['success'=>false,'error'=>'Não foi possível excluir']); exit; }
  echo json_encode(['success'=>true]);
  exit;
}


  // Fallback
  echo json_encode(['success'=>false,'error'=>'Ação inválida']);
  exit;
}



/* ===================== MODO PÁGINA ===================== */
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/functions.php';

if (!isset($_SESSION['user_id'])) { header('Location: /OKR_system/views/login.php'); exit; }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];

try {
  $pdo = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
    DB_USER, DB_PASS,
    [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC ]
  );
} catch (PDOException $e) {
  http_response_code(500);
  die("Erro ao conectar: ".$e->getMessage());
}

$id_objetivo = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id_objetivo<=0 && preg_match('#/detalhe_okr/([0-9]+)#', $_SERVER['REQUEST_URI'] ?? '', $m)) {
  $id_objetivo = (int)$m[1];
}
if ($id_objetivo<=0) { http_response_code(400); die('id_objetivo inválido.'); }

/* Objetivo + nome do dono (primeiro_nome) */
$g = static function(array $row, string $k, $d='—'){
  return array_key_exists($k,$row) && $row[$k]!==null && $row[$k]!=='' ? $row[$k] : $d;
};

$st = $pdo->prepare("
  SELECT o.`id_objetivo`, o.`descricao` AS nome_objetivo, o.`descricao`, o.`pilar_bsc`, o.`tipo`,
         o.`status`, o.`status_aprovacao`, o.`dono`, u.`primeiro_nome` AS dono_nome,
         o.`dt_criacao`, o.`dt_prazo`, o.`dt_conclusao`, o.`qualidade`, o.`observacoes`
  FROM `objetivos` o
  LEFT JOIN `usuarios` u ON u.`id_user` = o.`dono`
  WHERE o.`id_objetivo`=:id LIMIT 1
");
$st->execute(['id'=>$id_objetivo]);
$objetivo = $st->fetch();
if (!$objetivo) { http_response_code(404); die('Objetivo não encontrado.'); }

$stK = $pdo->prepare("
  SELECT COUNT(*) AS total_krs,
         SUM(CASE WHEN kr.`farol`='vermelho' THEN 1 ELSE 0 END) AS criticos,
         SUM(CASE WHEN kr.`farol`='amarelo' THEN 1 ELSE 0 END) AS em_risco
  FROM `key_results` kr WHERE kr.`id_objetivo`=:id
");
$stK->execute(['id'=>$id_objetivo]);
$kpi = $stK->fetch() ?: ['total_krs'=>0,'criticos'=>0,'em_risco'=>0];

$stI = $pdo->prepare("SELECT COUNT(*) AS total FROM `iniciativas` i INNER JOIN `key_results` kr ON kr.`id_kr`=i.`id_kr` WHERE kr.`id_objetivo`=:id");
$stI->execute(['id'=>$id_objetivo]);
$tIni = (int)($stI->fetch()['total'] ?? 0);

$stIC = $pdo->prepare("
  SELECT COUNT(DISTINCT i.`id_iniciativa`) AS com_orc
  FROM `iniciativas` i
  INNER JOIN `key_results` kr ON kr.`id_kr`=i.`id_kr`
  INNER JOIN `orcamentos` o ON o.`id_iniciativa`=i.`id_iniciativa`
  WHERE kr.`id_objetivo`=:id
");
$stIC->execute(['id'=>$id_objetivo]);
$comOrc = (int)($stIC->fetch()['com_orc'] ?? 0);

$stOA = $pdo->prepare("
  SELECT COALESCE(SUM(o.`valor`),0) AS v
  FROM `orcamentos` o
  INNER JOIN `iniciativas` i ON i.`id_iniciativa`=o.`id_iniciativa`
  INNER JOIN `key_results` kr ON kr.`id_kr`=i.`id_kr`
  WHERE kr.`id_objetivo`=:id
");
$stOA->execute(['id'=>$id_objetivo]);
$aprovObj = (float)($stOA->fetch()['v'] ?? 0);

$stOR = $pdo->prepare("
  SELECT COALESCE(SUM(od.`valor`),0) AS v
  FROM `orcamentos_detalhes` od
  INNER JOIN `orcamentos` o ON o.`id_orcamento`=od.`id_orcamento`
  INNER JOIN `iniciativas` i ON i.`id_iniciativa`=o.`id_iniciativa`
  INNER JOIN `key_results` kr ON kr.`id_kr`=i.`id_kr`
  WHERE kr.`id_objetivo`=:id
");
$stOR->execute(['id'=>$id_objetivo]);
$realObj = (float)($stOR->fetch()['v'] ?? 0);

$saldoObj = max(0, $aprovObj - $realObj);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Detalhe do Objetivo – OKR System</title>

  <link rel="stylesheet" href="/OKR_system/assets/css/base.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/layout.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/theme.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous"/>

  <style>
    /* Chip de progresso (verde/vermelho) */
    .meta-pill.prog-ok{
      color:#22c55e;
      background:#0c1f14;
      border-color:#1a3d2a;
    }
    .meta-pill.prog-bad{
      color:#f87171;
      background:#1a0b0e;
      border-color:#3b0d13;
    }
    .meta-pill.prog-warn{
      color:#fbbf24;
      background:#1f1a0b;
      border-color:#4a3b0a;
    }
    body{ background:#fff !important; color:#111; }
    :root{ --chat-w:0px; }
    .content{ background:transparent; }
    main.okr-detail{ padding:24px; display:grid; grid-template-columns:1fr; gap:16px; margin-right:var(--chat-w); transition:margin-right .25s ease; }
    :root{ --bg-soft:#171b21; --card: var(--bg1, #222222); --muted:#a6adbb; --text:#eaeef6; --gold:var(--bg2, #F1C40F); --green:#22c55e; --blue:#60a5fa; --red:#ef4444; --border:#222733; --shadow:0 10px 30px rgba(0,0,0,.20); }
    /* Breadcrumb */
    .crumbs{ color:#333; font-size:.9rem; display:flex; align-items:center; gap:6px; }
    .crumbs a{ color:#0c4a6e; text-decoration:none; }
    .crumbs .sep{ opacity:.5; margin:0 2px; }
    .crumbs i{ opacity:.8; }
    /* Header Objetivo */
    .obj-card{ position:relative; background:linear-gradient(180deg, var(--card), #0d1117); border:1px solid var(--border); border-radius:16px; padding:16px 44px 16px 16px; box-shadow:var(--shadow); color:var(--text); overflow:hidden; }
    .obj-title{ font-size:0.95rem; font-weight:700; margin:0 0 8px; letter-spacing:.2px; display:flex; align-items:center; gap:8px; }
    .obj-title i{ color:var(--gold); }
    .obj-meta-pills{ display:flex; flex-wrap:wrap; gap:8px; }
    .pill{ display:inline-flex; align-items:center; gap:8px; background:#0e131a; border:1px solid var(--border); color:#a6adbb; padding:6px 10px; border-radius:999px; font-size:.82rem; font-weight:700; }
    .pill i{ font-size:.9rem; opacity:.9; }
    .obj-actions{ display:flex; gap:10px; margin-top:12px; }
    .btn{ border:1px solid var(--border); background:var(--btn); color:#e5e7eb; padding:10px 14px; border-radius:12px; font-weight:600; cursor:pointer; }
    .btn:hover{ border-color:#2a3342; transform:translateY(-1px); transition:.15s; }
    .btn-primary{ background:#1f2937; }
    .btn-outline{ background:transparent; font-size: 0.7rem; }
    .btn-sm{ padding:7px 10px; font-size:.86rem; border-radius:10px; }
    .btn-gold{ background:var(--gold); border-color:var(--gold); color:#1f2937; }
    .btn-gold:hover{ filter:brightness(0.95); }
    .btn-danger{ background:#7a1020; border-color:#7a1020; color:#ffe4e6; }
    .btn-warning{ background:#8a6d00; border-color:#8a6d00; color:#fff7cc; }
    .btn-success{ background:#065f46; border-color:#065f46; color:#c7f9e5; }
    .obj-dates{ display:flex; flex-wrap:wrap; gap:8px; margin-top:10px; }
    .obj-dates .pill{ background:#0c1118; }
    .share-fab{ position:absolute; top:30px; right:30px; left:auto; background:transparent; border:none; color:var(--gold); font-size:1.1rem; padding:6px; cursor:pointer; line-height:1; }
    .share-fab:hover{ opacity:.9; transform:translateY(-1px); transition:.15s; }
    /* KPIs */
    .kpi-grid{ display:grid; grid-template-columns:repeat(4,1fr); gap:12px; }
    @media (max-width:1100px){ .kpi-grid{ grid-template-columns:repeat(2,1fr); } }
    @media (max-width:650px){ .kpi-grid{ grid-template-columns:1fr; } }
    .kpi{ background:linear-gradient(180deg, var(--card), #18190eff); border:1px solid var(--border); border-radius:14px; padding:14px; box-shadow:var(--shadow); color:#eaeef6; }
    .kpi-head{ display:flex; align-items:center; justify-content:space-between; margin-bottom:6px; color:var(--muted); font-size:.9rem; }
    .kpi-value{ font-weight:900; font-size:1.55rem; }
    .kpi-sub{ color:var(--muted); font-size:.85rem; }
    .kpi-icon{ width:40px; height:40px; border-radius:12px; display:grid; place-items:center; border:1px solid var(--border); color:#c7d2fe; background:rgba(96,165,250,.12); }
    .kpi-icon.success{ color:#86efac; background:rgba(34,197,94,.12); }
    .kpi-icon.money{ color:#fde68a; background:rgba(246,195,67,.12); }
    /* Filtros */
    .filters{ display:flex; align-items:center; gap:10px; }
    .chips{ display:flex; flex-wrap:wrap; gap:8px; }
    .chip{ background:#0e131a; border:1px solid var(--border); color:#a6adbb; padding:6px 10px; border-radius:999px; font-weight:700; font-size:.78rem; display:inline-flex; align-items:center; gap:6px; }
    .chip i{ opacity:.9; }
    /* KRs */
    .tabpane .kpi { display:flex; flex-direction:column; }
    .kr-ops-inline{ margin-top:auto; display:flex; gap:8px; flex-wrap:wrap; border-top:1px dashed #273043; padding-top:10px; }
    .kr-ops-inline .spacer{ flex:1; }
    .kr-ops-inline .btn{ padding: 6px 10px; font-size: .60rem; height: 34px; width: 90px; display: inline-flex; align-items: center; justify-content: center; gap: 6px; white-space: nowrap; box-sizing: border-box; border-radius: 10px; }
    .kr-ops-inline .btn i{ font-size: .9rem; }
    @media (max-width: 480px){ .kr-ops-inline .btn{ width: 112px; } }
    .kr-resumo-right{ display: flex; flex-direction: column; }
    .kr-ops-outside{ margin-top: 8px; display: flex; gap: 8px; justify-content: flex-end; flex-wrap: wrap; }
    .kr-ops-outside .btn{ padding: 6px 10px; font-size: .60rem; height: 34px; width: 90px; display: inline-flex; align-items: center; justify-content: center; gap: 6px; white-space: nowrap; box-sizing: border-box; border-radius: 10px; }
    .kr-ops-outside .btn i{ font-size: .9rem; }
    @media (max-width: 480px){ .kr-ops-outside .btn{ width: 112px; } }
    .kr-list{ display:flex; flex-direction:column; gap:10px; }
    .kr-card{ background:#0f1420; border:1px solid var(--border); border-radius:14px; padding:10px 12px; box-shadow:var(--shadow); color:#eaeef6; }
    .kr-head{ display:flex; justify-content:space-between; align-items:flex-start; gap:12px; }
    .kr-title{ font-weight:600; display:flex; align-items:center; gap:8px; color: #fff; font-size: 15px;}
    .meta-line{ display:flex; flex-wrap:wrap; gap:8px; margin-top:6px; }
    .meta-pill{ display:inline-flex; align-items:center; gap:6px; background:#0b0f14; border:1px solid var(--border); color:#a6adbb; padding:5px 8px; border-radius:999px; font-size:.78rem; font-weight:700; }
    .meta-pill i{ font-size:.85rem; }
    .meta-pill.white, .meta-pill.white i { color:#eee !important; }
    .kr-actions{ display:flex; gap:8px; flex-wrap:nowrap; align-items:center; }
    .kr-actions .btn.btn-sm { width: 180px; flex: 0 0 180px; white-space: nowrap; text-align:center; box-sizing:border-box; }
    @media (max-width:560px){ .kr-actions { flex-wrap: nowrap; } }
    .kr-toggle{ background:#0b0f14; border:1px solid var(--border); color:#a6adbb; width:36px; height:36px; border-radius:10px; display:grid; place-items:center; cursor:pointer; }
    .kr-toggle.gold{ border-color:var(--gold); color:var(--gold); }
    .kr-toggle i{ transition:transform .2s ease; }
    .kr-card.open .kr-toggle i{ transform:rotate(180deg); }
    .kr-card.cancelado .kr-title { color:#9aa4b2; }
    .kr-card.cancelado [data-act="apont"], .kr-card.cancelado [data-act="nova"]{ display: none !important; }
    .kr-body{ max-height:0; overflow:hidden; transition:max-height .25s ease, opacity .2s ease; opacity:0; }
    .kr-card.open .kr-body{ max-height:1400px; opacity:1; margin-top:10px; }
    /* Abas */
    .tabs{ border-bottom:1px solid var(--border); display:flex; gap:6px; }
    .tab{ background:transparent; border:1px solid var(--border); border-bottom:none; padding:8px 12px; border-radius:10px 10px 0 0; color:#a6adbb; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:8px; }
    .tab.active{ background:#0e131a; color:#eaeef6; }
    .tabpane{ display:none; padding:12px; background:#0e131a; border:1px solid #1f2a3a; border-radius:0 12px 12px 12px; }
    .tabpane.active{ display:block; }
    /* Tabelas */
    .table{ width:100%; border-collapse:collapse; }
    .table th, .table td{ border-bottom:1px dashed #1e2636; padding:8px 6px; text-align:left; font-size:.92rem; color:#a6adbb; }
    .table th{ color:#cbd5e1; white-space:nowrap; }
    .th-ico{ opacity:.85; margin-right:6px; }
    .kr-ops{ display:flex; gap:8px; flex-wrap:wrap; padding:10px; border:1px dashed #273043; border-radius:12px; background:#0b1018; margin-top:12px; }
    .kr-ops .spacer{ flex:1; }
    .kr-banner { display:flex; align-items:center; gap:8px; background:#0b1018; border:1px solid #1f2a3a; color:#eaeef6; padding:10px 12px; border-radius:10px; margin-bottom:12px; }
    .kr-banner i { color:var(--gold); }
    .kr-banner .title { font-weight:800; }
    .kr-banner .sub { color:#a6adbb; font-size:.9rem; }
    .ni-flex { display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap; }
    .ni-flex .col { flex:1 1 200px; }
    .ni-table{ width:100%; border-collapse:collapse; margin-top:8px; }
    .ni-table th,.ni-table td{ border-bottom:1px dashed #1e2636; padding:6px 8px; font-size:.92rem; color:#a6adbb; }
    .ni-table th{ color:#cbd5e1; }
    .ni-hint{ color:#9aa4b2; font-size:.85rem; margin-top:6px; }
    .ni-right{ text-align:right; }
    .ni-ok{ color:#22c55e; font-weight:800; }
    .ni-err{ color:#f87171; font-weight:800; }
    /* Drawers */
    .drawer{ position:fixed; top:0; right:-560px; width:520px; max-width:92vw; height:100%; background:#0f1420; border-left:1px solid #223047; box-shadow:-10px 0 40px rgba(0,0,0,.35); transition:right .25s ease; z-index:2000; color:#e5e7eb; display:flex; flex-direction:column; }
    .drawer.show{ right:0; }
    .drawer header{ padding:14px 16px; border-bottom:1px solid #1f2a3a; background:#0b101a; display:flex; justify-content:space-between; align-items:center; }
    .drawer .body{ padding:14px 16px; overflow:auto; }
    .drawer .actions{ padding:12px 16px; border-top:1px solid #1f2a3a; display:flex; justify-content:flex-end; gap:10px; background:#0b101a; }
    input[type="text"], input[type="date"], input[type="number"], textarea, select{ width:100%; background:#0c1118; color:#e5e7eb; border:1px solid #1f2635; border-radius:10px; padding:10px 10px; outline:none; }
    label{ display:block; margin-bottom:6px; color:#cbd5e1; font-size:.9rem; }
    /* Toast */
    .toast{ position:fixed; bottom:20px; right:20px; background:#0b7a44; color:#eafff5; padding:12px 14px; border-radius:10px; font-weight:700; box-shadow:0 10px 30px rgba(0,0,0,.25); z-index:3000; }
    .toast.error{ background:#7a1020; color:#ffe4e6; }
    /* ====== Orçamentos (aba do KR) ====== */
    .orc-topbar{ display:flex; gap:12px; align-items:center; justify-content:space-between; margin-bottom:10px; }
    .orc-topbar .lbl{ color:#a6adbb; font-size:.85rem; margin-right:6px; }
    .orc-topbar .sel-year{ background:#0c1118; color:#e5e7eb; border:1px solid #1f2635; border-radius:10px; padding:8px 10px; }
    .orc-topbar-right{ display:flex; align-items:center; gap:10px; }
    .segmented{ background:#0b1018; border:1px solid #1f2a3a; border-radius:10px; padding:4px; display:flex; gap:4px; }
    .segmented button{ background:transparent; border:none; color:#a6adbb; padding:6px 10px; border-radius:8px; font-weight:700; cursor:pointer; }
    .segmented button.active{ background:#121826; color:#eaeef6; }
    .orc-chart{ background:#0e131a; border:1px solid var(--border); border-radius:12px; padding:10px; margin-bottom:10px; }
    .orc-grid{ display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:10px; }
    @media (max-width:1100px){ .orc-grid{ grid-template-columns:repeat(3,1fr);} }
    @media (max-width:700px){ .orc-grid{ grid-template-columns:repeat(2,1fr);} }
    @media (max-width:480px){ .orc-grid{ grid-template-columns:1fr;} }
    .orc-month{ background:#0f1420; border:1px solid var(--border); border-radius:12px; padding:10px; }
    .orc-month .m-head{ display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; font-weight:800; color:#cbd5e1; }
    .orc-month .m-body{ font-size:.9rem; color:#a6adbb; display:grid; gap:4px; }
    .orc-month .badge{ font-size:.7rem; border:1px solid #705e14; color:#ffec99; background:#3b320a; padding:2px 6px; border-radius:999px; }
    .orc-card{ background:#0e131a; border:1px solid var(--border); border-radius:12px; padding:10px; margin-bottom:10px; }
    .orc-card-title{ font-weight:900; color:#eaeef6; margin-bottom:8px; display:flex; gap:8px; align-items:center; }
    /* ===== Modal de Apontamento: limitar altura da tabela ===== */
    #modalApont .table-wrap{
      /* o JS ajusta o max-height dinamicamente para 8 linhas */
      overflow-y: auto;      /* garante a barra de rolagem vertical quando exceder */
      overflow-x: hidden;    /* evita barra horizontal; a tabela já cuida do layout */
    }

    /* (opcional) fallback elegante se o JS não rodar por algum motivo */
    @media (min-height: 500px){
      #modalApont .table-wrap.fallback-cap {
        max-height: 50vh;    /* não fica gigante; ainda assim mostra bastante conteúdo */
      }
    }
    .orc-two-cols{ display:grid; grid-template-columns:2fr 1fr; gap:10px; }
    @media (max-width:1000px){ .orc-two-cols{ grid-template-columns:1fr; } }
    .orc-list .item{ border:1px dashed #1f2a3a; border-radius:10px; padding:8px; margin-bottom:8px; color:#a6adbb; }
    .orc-list .item strong{ color:#eaeef6; }
    .orc-list .empty{ color:#9aa4b2; font-style:italic; }
    #btnSalvarIni {
      background: #fff;  /* ou var(--gold) */
      color: #000 !important;
    }
    #btnSalvarIni i {
      color: #000 !important;
    }
    /* MODAL */
    .modal{ position:fixed; inset:0; background:rgba(0,0,0,.5); display:none; align-items:center; justify-content:center; z-index:3000; }
    .modal.show{ display:flex; }
    .modal-card{ width:1100px; max-width:95vw; background:#0f1420; border:1px solid var(--border); border-radius:16px; box-shadow:var(--shadow); color:#eaeef6; }
    .modal-head{ display:flex; align-items:center; justify-content:space-between; padding:12px 16px; border-bottom:1px solid #1f2a3a; background:#0b101a; }
    .modal-body{ padding:14px 16px; }
    .modal-actions{ padding:12px 16px; border-top:1px solid #1f2a3a; display:flex; justify-content:flex-end; gap:10px; background:#0b101a; }
    .modal.mini .modal-card{ width:520px; max-width:95vw; }
    .modal.mini textarea{ width:100%; background:#0c1118; color:#e5e7eb; border:1px solid #1f2635; border-radius:10px; padding:10px; }

  </style>
</head>
<body>
  <?php include __DIR__ . '/partials/sidebar.php'; ?>
  <div class="content">
    <?php include __DIR__ . '/partials/header.php'; ?>

    <main class="okr-detail">
      <!-- Breadcrumb com ícones -->
      <div class="crumbs">
        <i class="fa-solid fa-route"></i>
        <a href="/OKR_system/dashboard"><i class="fa-solid fa-house"></i> Dashboard</a>
        <span class="sep">/</span>
        <a href="/OKR_system/meus_okrs"><i class="fa-solid fa-bullseye"></i> Meus OKRs</a>
        <span class="sep">/</span>
        <span><i class="fa-solid fa-circle-info"></i> Detalhe do Objetivo</span>
      </div>

      <!-- HEADER -->
      <section class="obj-card">
        <button class="share-fab" aria-label="Compartilhar" title="Compartilhar" onclick="navigator.clipboard.writeText(location.href)">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="20" cy="4" r="3"></circle>
            <circle cx="4" cy="12" r="3"></circle>
            <circle cx="20" cy="20" r="3"></circle>
            <path d="M7 12 L17 6"></path>
            <path d="M7 12 L17 18"></path>
          </svg>
        </button>

        <h1 class="obj-title">
          <i class="fa-solid fa-bullseye"></i>
          <?= htmlspecialchars($g($objetivo,'nome_objetivo',$g($objetivo,'descricao','Objetivo'))) ?>
        </h1>

        <div class="obj-meta-pills">
          <span class="pill" title="Pilar BSC"><i class="fa-solid fa-layer-group"></i><?= htmlspecialchars($g($objetivo,'pilar_bsc')) ?></span>
          <span class="pill" title="Tipo do objetivo"><i class="fa-solid fa-tag"></i><?= htmlspecialchars($g($objetivo,'tipo')) ?></span>
          <span class="pill" title="Dono (responsável)"><i class="fa-solid fa-user-tie"></i><?= htmlspecialchars($g($objetivo,'dono_nome',$g($objetivo,'dono'))) ?></span>
          <span class="pill" title="Status"><i class="fa-solid fa-clipboard-check"></i><?= htmlspecialchars($g($objetivo,'status')) ?></span>
          <span class="pill" title="Aprovação"><i class="fa-regular fa-circle-check"></i><?= htmlspecialchars($g($objetivo,'status_aprovacao')) ?></span>
        </div>

        <div class="obj-actions">
          <a class="btn btn-outline" href="/OKR_system/views/objetivos_editar.php?id=<?= (int)$objetivo['id_objetivo'] ?>"><i class="fa-regular fa-pen-to-square"></i>&nbsp;Editar</a>
          <a class="btn btn-outline" href="/OKR_system/views/novo_key_result.php?id_objetivo=<?= (int)$objetivo['id_objetivo'] ?>"><i class="fa-solid fa-plus"></i>&nbsp;Novo KR</a>
          <button class="btn btn-outline" onclick="window.print()"><i class="fa-regular fa-file-lines"></i>&nbsp;Exportar</button>
        </div>

        <div class="obj-dates">
          <span class="pill" title="Data de criação"><i class="fa-regular fa-calendar-plus"></i><?= htmlspecialchars($g($objetivo,'dt_criacao')) ?></span>
          <span class="pill" title="Prazo"><i class="fa-regular fa-calendar-days"></i><?= htmlspecialchars($g($objetivo,'dt_prazo')) ?></span>

          <!-- TROCA AQUI: Farol do objetivo -->
          <span class="pill meta-pill white" id="objFarolPill" title="Farol do objetivo">
            <i class="fa-solid fa-traffic-light"></i>
            <span id="objFarolLabel">—</span>
          </span>

          <span class="pill" title="Conclusão"><i class="fa-solid fa-flag-checkered"></i><?= htmlspecialchars($g($objetivo,'dt_conclusao')) ?></span>
        </div>
      </section>

      <!-- KPIs -->
      <section class="kpi-grid">
        <div class="kpi">
          <div class="kpi-head"><span>KRs</span><div class="kpi-icon"><i class="fa-solid fa-list-check"></i></div></div>
          <div class="kpi-value" id="kpiTotalKrs"><?= (int)$kpi['total_krs'] ?></div>
          <div class="kpi-sub">Críticos: <strong id="kpiCriticos"><?= (int)$kpi['criticos'] ?></strong> · Em risco: <strong id="kpiRisco"><?= (int)$kpi['em_risco'] ?></strong></div>
        </div>
        <div class="kpi">
          <div class="kpi-head"><span>Iniciativas</span><div class="kpi-icon"><i class="fa-solid fa-diagram-project"></i></div></div>
          <div class="kpi-value"><?= (int)$tIni ?></div>
          <div class="kpi-sub">Com orçamento: <strong><?= (int)$comOrc ?></strong></div>
        </div>
        <div class="kpi">
          <div class="kpi-head"><span>Orçamento aprovado</span><div class="kpi-icon money"><i class="fa-solid fa-coins"></i></div></div>
          <div class="kpi-value">R$ <?= number_format($aprovObj,2,',','.') ?></div>
        </div>
        <div class="kpi">
          <div class="kpi-head"><span>Realizado / Saldo</span><div class="kpi-icon money"><i class="fa-solid fa-wallet"></i></div></div>
          <div class="kpi-value">R$ <?= number_format($realObj,2,',','.') ?> <span style="opacity:.7">/</span> R$ <?= number_format($saldoObj,2,',','.') ?></div>
        </div>
      </section>

      <!-- Lista de KRs -->
      <section id="krContainer" class="kr-list"></section>

      <?php include __DIR__ . '/partials/chat.php'; ?>
    </main>
  </div>

  <!-- Drawer: Nova Iniciativa -->
  <aside id="drawerNovaIni" class="drawer" aria-hidden="true">
    <header>
      <h3 style="margin:0;font-size:1rem"><i class="fa-solid fa-rocket me-1"></i> Nova iniciativa</h3>
      <button class="btn btn-outline" type="button" onclick="toggleDrawer('#drawerNovaIni',false)">Fechar ✕</button>
    </header>
    <div class="body">
      <!-- BANNER: nome/descrição do KR -->
      <div class="kr-banner">
        <i class="fa-solid fa-flag"></i>
        <div>
          <div class="title" id="ni_kr_titulo">KR —</div>
          <div class="sub" id="ni_kr_sub">Carregando...</div>
        </div>
      </div>

      <form id="formNovaIniciativa">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="id_kr" id="ni_id_kr">
        <input type="hidden" name="desembolsos_json" id="ni_prev_json">

        <div class="mb-2">
          <label><i class="fa-regular fa-rectangle-list"></i> Descrição</label>
          <textarea name="descricao" rows="3" required></textarea>
        </div>

        <div class="mb-2">
          <label><i class="fa-regular fa-user"></i> Responsável</label>
          <!-- lista só usuários da mesma company -->
          <select name="id_user_responsavel" id="ni_resp" required>
            <option value="">Carregando...</option>
          </select>
        </div>

        <div class="mb-2">
          <label><i class="fa-regular fa-calendar-days"></i> Prazo</label>
          <input type="date" name="dt_prazo">
        </div>

        <div class="mb-2">
          <label><i class="fa-solid fa-clipboard-check"></i> Status da iniciativa</label>
          <select name="status_iniciativa" id="ni_status" required>
            <option value="">Carregando...</option>
          </select>
          <div class="ni-hint">Sugestão: <em>Não Iniciado</em> ou <em>Em Andamento</em>.</div>
        </div>

        <hr style="border-color:#1f2a3a; opacity:.6; margin:12px 0">

        <div class="mb-2" style="display:flex; align-items:center; gap:8px;">
          <input id="ni_sw_orc" type="checkbox" name="incluir_orcamento" value="1" style="width:18px;height:18px;">
          <label for="ni_sw_orc" style="margin:0;"><i class="fa-solid fa-coins"></i> Incluir orçamento nesta iniciativa</label>
        </div>

        <div id="ni_orc_group" style="display:none;">
          <div class="mb-2">
            <label><i class="fa-solid fa-sack-dollar"></i> Valor aprovado (total)</label>
            <input type="number" step="0.01" name="valor_orcamento" id="ni_valor_total" placeholder="0,00">
          </div>

          <!-- PREVISÃO DE DESEMBOLSO -->
          <div class="mb-2">
            <label><i class="fa-regular fa-calendar"></i> Previsão de desembolso</label>
            <div class="ni-flex">
              <div class="col">
                <label style="display:block; color:#a6adbb; font-size:.85rem; margin-bottom:4px;">Competência (mês/ano)</label>
                <input type="month" id="ni_prev_comp">
              </div>
              <div class="col">
                <label style="display:block; color:#a6adbb; font-size:.85rem; margin-bottom:4px;">Valor</label>
                <input type="number" step="0.01" id="ni_prev_valor" placeholder="0,00">
              </div>
              <button class="btn btn-outline" type="button" id="ni_prev_add">
                <i class="fa-solid fa-plus"></i> Adicionar
              </button>
            </div>

            <table class="ni-table">
              <thead>
              <tr><th>Competência</th><th class="ni-right">Valor</th><th style="width:1px"></th></tr>
              </thead>
              <tbody id="ni_prev_tbody">
                <tr><td colspan="3" style="color:#9aa4b2">Nenhuma parcela adicionada.</td></tr>
              </tbody>
            </table>

            <div class="ni-hint">
              Soma das parcelas: <span id="ni_prev_sum">R$ 0,00</span>
              &nbsp;|&nbsp; Total: <span id="ni_prev_total">R$ 0,00</span>
              &nbsp;→&nbsp; <span id="ni_prev_check" class="ni-err">inconsistente</span>
            </div>
          </div>

          <div class="mb-2">
            <label><i class="fa-regular fa-comment-dots"></i> Justificativa</label>
            <input type="text" name="justificativa_orcamento" placeholder="Motivo do orçamento">
          </div>

          <div class="mb-1" style="color:#9aa4b2; font-size:.85rem;">
            <i class="fa-solid fa-hourglass-half"></i> Status de aprovação inicia como <em>pendente</em>.
          </div>
        </div>
      </form>
    </div>
    <div class="actions">
      <button class="btn btn-outline" type="button" onclick="toggleDrawer('#drawerNovaIni',false)">Cancelar</button>
      <button class="btn btn-primary" type="button" id="btnSalvarIni"><i class="fa-regular fa-floppy-disk"></i> Salvar</button>
    </div>
  </aside>

  <!-- Drawer: Lançar Despesa -->
  <aside id="drawerDespesa" class="drawer" aria-hidden="true">
    <header>
      <h3 style="margin:0;font-size:1rem"><i class="fa-solid fa-file-invoice-dollar"></i> Lançar despesa</h3>
      <button class="btn btn-outline" type="button" onclick="toggleDrawer('#drawerDespesa',false)">Fechar ✕</button>
    </header>
    <div class="body">
      <form id="formDespesa">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="id_orcamento" id="desp_id_orcamento">
        <div class="mb-2"><label><i class="fa-solid fa-money-bill-wave"></i> Valor</label><input type="number" step="0.01" name="valor" required></div>
        <div class="mb-2"><label><i class="fa-regular fa-calendar-check"></i> Data de pagamento</label><input type="date" name="data_pagamento" required></div>
        <div class="mb-2"><label><i class="fa-regular fa-note-sticky"></i> Descrição</label><input type="text" name="descricao" placeholder="Ex.: parcela, serviço, etc."></div>
      </form>
    </div>
    <div class="actions">
      <button class="btn btn-outline" type="button" onclick="toggleDrawer('#drawerDespesa',false)">Cancelar</button>
      <button class="btn btn-primary" type="button" id="btnSalvarDesp"><i class="fa-regular fa-floppy-disk"></i> Lançar</button>
    </div>
  </aside>


  <!-- MODAL: Apontamento -->
  <div id="modalApont" class="modal" aria-hidden="true">
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="apont_title">
      <header class="modal-head">
        <h3 id="apont_title"><i class="fa-regular fa-pen-to-square"></i> Apontamento do KR</h3>
        <button class="btn btn-outline" type="button" onclick="showApontModal(false)">Fechar ✕</button>
      </header>
      <div class="modal-body">
        <div class="kr-banner" style="margin-bottom:10px;">
          <i class="fa-solid fa-flag"></i>
          <div>
            <div class="title" id="ap_kr_titulo">KR —</div>
            <div class="sub" id="ap_kr_sub">Carregando...</div>
          </div>
        </div>

        <div class="chip" style="margin-bottom:10px;">
          <i class="fa-solid fa-gauge"></i><span id="ap_kr_base">Baseline: —</span>
          &nbsp;|&nbsp;<i class="fa-solid fa-scale-balanced"></i><span id="ap_kr_meta">Meta: —</span>
          &nbsp;|&nbsp;<i class="fa-solid fa-ruler-horizontal"></i><span id="ap_kr_um">Unidade: —</span>
        </div>

        <input type="hidden" id="ap_id_kr">
        <input type="hidden" id="ap_items_json">
        <div class="table-wrap" style="margin-top:8px;">
          <table class="table">
            <thead>
              <tr>
                <th>Milestone</th>
                <th>Data</th>
                <th>Esperado</th>
                <th style="width:140px">Apontado</th>
                <th style="width:24%">Justificativa</th>
                <th style="white-space:nowrap">Evidência</th>
                <th style="white-space:nowrap">Ver anexo</th>
                <th style="white-space:nowrap">Salvar</th>
              </tr>
            </thead>
            <tbody id="ap_rows">
              <tr><td colspan="8" style="color:#9aa4b2">Carregando...</td></tr>
            </tbody>
          </table>
        </div>
        <!-- input único para upload (acionado por linha) -->
        <input type="file" id="ap_file_input" style="display:none"
              accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,image/*" />
      </div>
      <div class="modal-actions">
        <button class="btn btn-outline" type="button" onclick="showApontModal(false)">Fechar</button>
      </div>
    </div>
  </div>

  <!-- MINI-MODAL: justificativa obrigatória -->
  <div id="modalJust" class="modal mini" aria-hidden="true">
    <div class="modal-card mini">
      <header class="modal-head"><h3>Informe a justificativa</h3></header>
      <div class="modal-body">
        <textarea id="just_text" rows="4" placeholder="Explique o motivo..."></textarea>
      </div>
      <div class="modal-actions">
        <button class="btn btn-outline" type="button" onclick="showJust(false)">Cancelar</button>
        <button class="btn btn-primary" type="button" id="just_confirm">Confirmar</button>
      </div>
    </div>
  </div>


  <!-- Drawer: Alterar Status da Iniciativa -->
<aside id="drawerIniStatus" class="drawer" aria-hidden="true">
  <header>
    <h3 style="margin:0;font-size:1rem">
      <i class="fa-solid fa-arrows-rotate"></i> Alterar status da iniciativa
    </h3>
    <button class="btn btn-outline" type="button" onclick="toggleDrawer('#drawerIniStatus',false)">Fechar ✕</button>
  </header>
  <div class="body">
    <form id="formIniStatus">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="id_iniciativa" id="inis_id_iniciativa">
      <div class="mb-2">
        <label><i class="fa-solid fa-clipboard-check"></i> Novo status</label>
        <select name="novo_status" id="inis_status" required>
          <option value="">Carregando...</option>
        </select>
      </div>
      <div class="mb-2">
        <label><i class="fa-regular fa-note-sticky"></i> Observação (obrigatória)</label>
        <textarea name="observacao" id="inis_obs" rows="3" required placeholder="Explique o motivo da mudança..."></textarea>
      </div>
    </form>
  </div>
  <div class="actions">
    <button class="btn btn-outline" type="button" onclick="toggleDrawer('#drawerIniStatus',false)">Cancelar</button>
    <button class="btn btn-primary" type="button" id="btnSalvarIniStatus">
      <i class="fa-regular fa-floppy-disk"></i> Salvar
    </button>
  </div>
</aside>


  <!-- Drawer: Reativar KR -->
  <aside id="drawerReativar" class="drawer" aria-hidden="true">
    <header>
      <h3 style="margin:0;font-size:1rem"><i class="fa-solid fa-rotate-left"></i> Reativar KR</h3>
      <button class="btn btn-outline" type="button" onclick="toggleDrawer('#drawerReativar',false)">Fechar ✕</button>
    </header>
    <div class="body">
      <form id="formReativar">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="id_kr" id="react_id_kr">
        <div class="mb-2">
          <label><i class="fa-solid fa-clipboard-check"></i> Status ao reativar</label>
          <select id="react_status" name="status_target" required></select>
        </div>
        <div class="mb-1" style="color:#9aa4b2; font-size:.85rem;">
          Sugestões: <em>Não Iniciado</em>, <em>Em Andamento</em> ou <em>Concluído</em>.
        </div>
      </form>
    </div>
    <div class="actions">
      <button class="btn btn-outline" type="button" onclick="toggleDrawer('#drawerReativar',false)">Cancelar</button>
      <button class="btn btn-success" type="button" id="btnReativarKrSave"><i class="fa-solid fa-rotate-left"></i> Reativar</button>
    </div>
  </aside>

  <!-- Chart.js -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <script>
    Chart.defaults.font.family = 'Inter, system-ui, -apple-system, Segoe UI, Roboto, sans-serif';
    Chart.defaults.color = '#a6adbb';
  </script>
  <script>
    function showApontModal(show=true){ $('#modalApont')?.classList.toggle('show', show); }
    function showJust(show=true){ $('#modalJust')?.classList.toggle('show', show); }
  </script>
  <script>
    // ================== Utils ==================
    const currentUserId = <?= (int)$_SESSION['user_id'] ?>;
    const csrfToken     = "<?= htmlspecialchars($csrf) ?>";
    const idObjetivo    = <?= (int)$id_objetivo ?>;
    const SCRIPT        = "<?= $_SERVER['SCRIPT_NAME'] ?>";

    const $  = (s,p=document)=>p.querySelector(s);
    const $$ = (s,p=document)=>Array.from(p.querySelectorAll(s));

    function fmtNum(x){ if(x===null||x===undefined||isNaN(x)) return '—'; return Number(x).toLocaleString('pt-BR',{maximumFractionDigits:2}); }
    function fmtBRL(x){ return (Number(x)||0).toLocaleString('pt-BR',{style:'currency',currency:'BRL'}); }
    function escapeHtml(s){ return (s??'').toString().replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;'); }
    function truncate(s,n){ if(!s)return''; return s.length>n?s.slice(0,n-1)+'…':s; }
    function toast(msg, ok=true){ const t=document.createElement('div'); t.className='toast'+(ok?'':' error'); t.textContent=msg; document.body.appendChild(t); setTimeout(()=>t.remove(),3000); }
    // Limita a área visível da tabela do modal de apontamento a N linhas (default 8)
    function capApontRows(maxRows = 8){
      const wrap  = document.querySelector('#modalApont .table-wrap');
      const table = document.querySelector('#modalApont .table');
      const tbody = document.getElementById('ap_rows');
      const thead = table ? table.querySelector('thead') : null;

      if (!wrap || !tbody) return;

      // Pega linhas visíveis (ignora a de "Carregando..." se ainda estiver)
      const rows = Array.from(tbody.querySelectorAll('tr')).filter(r => r.offsetParent !== null);
      if (!rows.length){
        // Sem linhas reais ainda: aplica um fallback suave e sai
        wrap.classList.add('fallback-cap');
        return;
      } else {
        wrap.classList.remove('fallback-cap');
      }

      // Escolhe uma linha de amostra para medir a altura real
      let sample = rows.find(r => r.querySelector('td')) || rows[0];
      const rowH    = sample.getBoundingClientRect().height || 56; // fallback ~56px
      const headerH = thead ? (thead.getBoundingClientRect().height || 44) : 44;
      const visible = Math.min(maxRows, rows.length);

      // Altura máxima = cabeçalho + (altura de 8 linhas) + uma folguinha de 8px
      const maxPx = Math.ceil(headerH + (rowH * visible) + 8);

      wrap.style.maxHeight = maxPx + 'px';
      // Só mostra barra quando realmente exceder
      wrap.style.overflowY = rows.length > maxRows ? 'auto' : 'visible';
    }

    // Recalcula ao redimensionar a janela (mantém robusto)
    window.addEventListener('resize', () => capApontRows(8));
    function toggleDrawer(sel, show=true){ const el=$(sel); if(!el) return; if(show){ el.classList.add('show'); } else { el.classList.remove('show'); } }
    function badgeFarol(v){
      v = (v||'').toLowerCase();
      if (v.includes('vermelho')) return `<i class="fa-solid fa-circle" style="color:#ef4444"></i> Vermelho`;
      if (v.includes('amarelo'))  return `<i class="fa-solid fa-circle" style="color:#f6c343"></i> Amarelo`;
      if (v.includes('verde'))    return `<i class="fa-solid fa-circle" style="color:#22c55e"></i> Verde`;
      return `<i class="fa-regular fa-circle" style="color:#6b7280"></i> —`;
    }
    function respLabel(kr){ return kr.responsavel ?? '—'; }
    function onlyDate(s){ if(!s) return ''; const str=String(s).trim(); return str.includes(' ')?str.split(' ')[0]:str; }
    function toDDMMYYYY(raw, sep='/'){
      const s = onlyDate(raw); if(!s) return '';
      let y,m,d;
      let m1 = s.match(/^(\d{4})-(\d{2})-(\d{2})$/);
      if (m1){ y=m1[1]; m=m1[2]; d=m1[3]; return [d,m,y].join(sep); }
      let m2 = s.match(/^(\d{2})[\/-](\d{2})[\/-](\d{4})$/);
      if (m2){ d=m2[1]; m=m2[2]; y=m2[3]; return [d,m,y].join(sep); }
      const dt = new Date(s);
      if(!isNaN(dt)){ d = String(dt.getDate()).padStart(2,'0'); m = String(dt.getMonth()+1).padStart(2,'0'); y = String(dt.getFullYear()); return [d,m,y].join(sep); }
      return s;
    }
    function prazoLabel(kr){
      const raw = kr.prazo_final || kr.dt_novo_prazo || kr.data_fim || kr.dt_prazo || kr.data_limite || kr.dt_limite;
      const fmt = toDDMMYYYY(raw, '/');
      return fmt || '—';
    }

    // ====== Nova Iniciativa (previsões) ======
    let previsoes = [];
    function renderPrev(){
      const tb = $('#ni_prev_tbody');
      if (!tb) return;
      tb.innerHTML = '';
      if (!previsoes.length){
        tb.innerHTML = '<tr><td colspan="3" style="color:#9aa4b2">Nenhuma parcela adicionada.</td></tr>';
        updatePrevTotals();
        return;
      }
      previsoes.forEach((p, idx)=>{
        tb.insertAdjacentHTML('beforeend', `
          <tr>
            <td>${escapeHtml(p.competenciaLabel)}</td>
            <td class="ni-right">${fmtBRL(p.valor)}</td>
            <td class="ni-right">
              <button type="button" class="btn btn-outline btn-sm" data-prev-del="${idx}">
                <i class="fa-regular fa-trash-can"></i>
              </button>
            </td>
          </tr>
        `);
      });
      updatePrevTotals();
    }
    function updatePrevTotals(){
      const sum = previsoes.reduce((a,b)=> a + (Number(b.valor)||0), 0);
      const total = Number($('#ni_valor_total')?.value || 0);
      $('#ni_prev_sum').textContent   = sum.toLocaleString('pt-BR',{style:'currency',currency:'BRL'});
      $('#ni_prev_total').textContent = total.toLocaleString('pt-BR',{style:'currency',currency:'BRL'});
      const ok = Math.abs(sum - total) < 0.005;
      $('#ni_prev_check').textContent = ok ? 'ok' : 'inconsistente';
      $('#ni_prev_check').className   = ok ? 'ni-ok' : 'ni-err';
      const json = previsoes.map(p=>({competencia:p.competencia, valor: Number(p.valor)}));
      $('#ni_prev_json').value = JSON.stringify(json);
    }
    $('#ni_prev_add')?.addEventListener('click', ()=>{
      const comp = $('#ni_prev_comp').value; // yyyy-mm
      const val  = Number($('#ni_prev_valor').value || 0);
      if (!comp) { toast('Informe a competência (mês/ano).', false); return; }
      if (!val || val <= 0){ toast('Informe um valor válido.', false); return; }
      const [y,m] = comp.split('-');
      const label = `${m}/${y}`;
      const i = previsoes.findIndex(p=>p.competencia === comp);
      if (i >= 0) previsoes[i].valor = Number(previsoes[i].valor||0) + val;
      else previsoes.push({competencia: comp, competenciaLabel: label, valor: val});
      $('#ni_prev_valor').value = '';
      renderPrev();
    });
    document.addEventListener('click', (e)=>{
      const del = e.target.closest('button[data-prev-del]');
      if (!del) return;
      const idx = Number(del.getAttribute('data-prev-del'));
      previsoes.splice(idx,1);
      renderPrev();
    });
    $('#ni_valor_total')?.addEventListener('input', updatePrevTotals);

    // Toggle do bloco de orçamento
    $('#ni_sw_orc')?.addEventListener('change', (e)=>{
      $('#ni_orc_group').style.display = e.target.checked ? 'block' : 'none';
    });

    // Salvar Nova Iniciativa (+ orçamento opcional)
    $('#btnSalvarIni')?.addEventListener('click', async ()=>{
      const form = $('#formNovaIniciativa');
      const fd   = new FormData(form);
      const idKR = $('#ni_id_kr')?.value;

      if (!idKR) { toast('KR inválido.', false); return; }

      if ($('#ni_sw_orc')?.checked) {
        const total = Number($('#ni_valor_total')?.value || 0);
        const soma  = previsoes.reduce((a,b)=> a + (Number(b.valor)||0), 0);
        if (total <= 0) { toast('Informe o Valor aprovado (total).', false); return; }
        // updatePrevTotals() já preenche o JSON; só alerta se estiver diferente
        if (Math.abs(soma - total) > 0.01) {
          toast('Soma das parcelas ≠ total. Ajustei a última parcela ao salvar.', true);
        }
      } else {
        fd.delete('desembolsos_json');
        fd.delete('valor_orcamento');
        fd.delete('justificativa_orcamento');
      }

      const res  = await fetch(`${SCRIPT}?ajax=nova_iniciativa`, { method:'POST', body:fd });
      const data = await res.json();
      if (!data.success) { toast(data.error || 'Falha ao salvar', false); return; }

      toast('Iniciativa criada com sucesso!');
      toggleDrawer('#drawerNovaIni', false);

      // Recarregar listas/painéis do KR aberto
      const open = document.querySelector('.kr-card.open') || document.querySelector(`.kr-card[data-id="${idKR}"]`);
      if (open) {
        await loadIniciativas(idKR);
        await loadKrDetail(idKR);
        const selAno = document.getElementById(`orc_ano_${idKR}`);
        await loadOrcDashboard(idKR, selAno?.value);
      } else {
        await loadKRs();
      }
    });

    // Lançar despesa
    $('#btnSalvarDesp')?.addEventListener('click', async ()=>{
      const fd  = new FormData($('#formDespesa'));
      const res = await fetch(`${SCRIPT}?ajax=add_despesa`, { method:'POST', body:fd });
      const data= await res.json();
      if (!data.success) { toast(data.error || 'Falha ao lançar despesa', false); return; }

      toast('Despesa lançada!');
      toggleDrawer('#drawerDespesa', false);

      const open = document.querySelector('.kr-card.open');
      if (open) {
        const id = open.getAttribute('data-id');
        const selAno = document.getElementById(`orc_ano_${id}`);
        await loadOrcDashboard(id, selAno?.value);
        await loadIniciativas(id);
        await loadKrDetail(id);
      }
    });


    // ====== KRs ======
    async function openNovaIniciativaDrawer(id){
      // reset
      $('#formNovaIniciativa')?.reset();
      $('#ni_orc_group').style.display='none';
      previsoes = [];
      renderPrev();
      $('#ni_id_kr').value = id;

      // 1) banner com nome do KR
      try {
        const res  = await fetch(`${SCRIPT}?ajax=kr_detail&id_kr=${encodeURIComponent(id)}`);
        const data = await res.json();
        if (data.success){
          const num = data.kr.key_result_num ? `KR ${data.kr.key_result_num}` : 'KR';
          $('#ni_kr_titulo').textContent = num;
          $('#ni_kr_sub').textContent    = (data.kr.descricao || '—');
        }
      } catch(e){ /* silencioso */ }

      // 2) responsáveis (mesma company)
      try{
        const res  = await fetch(`${SCRIPT}?ajax=list_responsaveis_company`);
        const data = await res.json();
        const sel  = $('#ni_resp');
        sel.innerHTML = '';
        (data.items||[]).forEach(u=>{
          const opt = document.createElement('option');
          opt.value = u.id_user;
          const ln  = (u.ultimo_nome || '').trim();
          opt.textContent = ln ? `${u.primeiro_nome} ${ln}` : (u.primeiro_nome || u.id_user);
          sel.appendChild(opt);
        });
        if (sel.options.length) {
          sel.value = String(currentUserId);
          if (sel.value !== String(currentUserId)) sel.selectedIndex = 0;
        } else {
          sel.innerHTML = '<option value="">— sem usuários —</option>';
        }
      } catch(e){
        $('#ni_resp').innerHTML = '<option value="">Falha ao carregar</option>';
      }

      // 3) status (carrega de dom_status_kr via list_status_iniciativa)
      try {
        const res = await fetch(`${SCRIPT}?ajax=list_status_iniciativa`);
        const data = await res.json();

        const sel = document.getElementById('ni_status');
        sel.innerHTML = '';

        (data.items || []).forEach(s => {
          const opt = document.createElement('option');
          opt.value = s.id;
          opt.textContent = s.label || s.id;
          sel.appendChild(opt);
        });

        // Seleção padrão
        const preferidos = ['nao iniciado', 'não iniciado', 'em andamento'];
        const idx = (data.items || []).findIndex(it =>
          preferidos.includes(String(it.id || '').toLowerCase()) ||
          preferidos.includes(String(it.label || '').toLowerCase())
        );
        if (idx >= 0 && sel.options[idx]) sel.selectedIndex = idx;

        if (!sel.options.length) {
          sel.innerHTML = '<option value="" disabled selected>Nenhum status disponível</option>';
        }
      } catch (e) {
        const sel = document.getElementById('ni_status');
        // placeholder silencioso (não quebra o layout nem o JS)
        sel.innerHTML = '<option value="" disabled selected>—</option>';
      }

      updatePrevTotals();
      toggleDrawer('#drawerNovaIni', true);
    }


        // ====== Novo Apontamento ======
    async function openApontModal(id){
    // reset
    $('#ap_id_kr').value = id;
    $('#ap_rows').innerHTML = '<tr><td colspan="8" style="color:#9aa4b2">Carregando...</td></tr>';

    // banner + dados
    const res  = await fetch(`${SCRIPT}?ajax=apont_modal_data&id_kr=${encodeURIComponent(id)}`);
    const data = await res.json();
    if(!data.success){ toast(data.error||'Falha ao carregar', false); return; }

    $('#ap_kr_titulo').textContent = 'KR';
    $('#ap_kr_sub').textContent    = data.kr?.descricao || '—';
    $('#ap_kr_meta').textContent   = 'Meta: ' + (data.kr?.meta ?? '—');
    $('#ap_kr_base').textContent   = 'Baseline: ' + (data.kr?.baseline ?? '—');
    $('#ap_kr_um').textContent     = 'Unidade: ' + (data.kr?.unidade_medida ?? '—');

    const tb = $('#ap_rows'); tb.innerHTML='';
    capApontRows(8);
    const total = (data.milestones||[]).length || 0;

    (data.milestones||[]).forEach((m, i)=>{
      const dp   = toDDMMYYYY(m.data_prevista,'/');
      const esp  = Number(m.valor_esperado||0);
      const rea  = (m.valor_real===null||m.valor_real===undefined) ? null : Number(m.valor_real);
      const idMs = (m.id_ms ?? '');
      const ordem = m.ordem_label || ((i+1)+'/'+total);

      tb.insertAdjacentHTML('beforeend', `
        <tr data-idms="${idMs}" data-dref="${m.data_prevista}" data-esp="${esp}" data-has-real="${rea!==null}">
          <td><strong>${ordem}</strong></td>
          <td>${dp}</td>
          <td>${fmtNum(esp)}</td>
          <td><input type="number" step="0.0001" class="ap-real" value="${rea===null?'':rea}" placeholder="0,00"></td>
          <td><input type="text" class="ap-just" placeholder="Justificativa (obrigatória se sobrescrever)"></td>
          <td>
            <button class="btn btn-outline btn-sm ap-add" type="button"><i class="fa-regular fa-paperclip"></i> Anexar</button>
            <button class="btn btn-outline btn-sm ap-del" type="button" style="display:none"><i class="fa-regular fa-trash-can"></i> Excluir</button>
          </td>
          <td><button class="btn btn-outline btn-sm ap-list" type="button"><i class="fa-regular fa-eye"></i> Ver</button></td>
          <td><button class="btn btn-primary btn-sm ap-save" type="button"><i class="fa-regular fa-floppy-disk"></i> Salvar</button></td>
        </tr>
      `);

      // Estado inicial dos botões de evidência (verifica se já existe arquivo)
      setTimeout(async ()=>{
        const f = await fetch(`${SCRIPT}?ajax=apont_file_list&id_kr=${encodeURIComponent(id)}&id_ms=${encodeURIComponent(idMs)}`);
        const j = await f.json();
        const tr = tb.querySelector(`tr[data-idms="${CSS.escape(idMs)}"]`);
        if (tr && j.success && (j.files||[]).length){
          tr.querySelector('.ap-add').style.display = 'none';
          tr.querySelector('.ap-del').style.display = '';
        }
      }, 0);
    });

      showApontModal(true);
    }


    // Mini-modal de justificativa: fluxo controlado
    let _just_cb = null; // callback após confirmar
    $('#just_confirm')?.addEventListener('click', ()=>{
      const t = $('#just_text').value.trim();
      if (!t){ toast('Justificativa é obrigatória.', false); return; }
      const cb = _just_cb; _just_cb = null;
      showJust(false);
      $('#just_text').value='';
      if (cb) cb(t);
    });

    // Handler único por delegação
    document.addEventListener('click', async (e)=>{
      const tr = e.target.closest('#ap_rows tr'); if (!tr) return;
      const id_kr = $('#ap_id_kr').value;
      const id_ms = tr.getAttribute('data-idms') || '';
      const dref  = tr.getAttribute('data-dref') || null;
      const esp   = Number(tr.getAttribute('data-esp') || 0);
      const hasRealBefore = tr.getAttribute('data-has-real') === 'true';

      // SALVAR
      if (e.target.closest('.ap-save')) {
        const val = tr.querySelector('.ap-real')?.value;
        const justInput = tr.querySelector('.ap-just');
        let just = justInput?.value.trim() || '';

        // Se já tinha valor e está mudando => exigir justificativa
        const prev = tr.querySelector('.ap-real')?.defaultValue;
        const isOverwrite = (prev !== '' && val !== '' && Number(prev) != Number(val));
        if (isOverwrite && !just) {
          _just_cb = async (texto)=>{
            tr.querySelector('.ap-just').value = texto;
            await salvarLinhaApontamento({id_kr,id_ms,dref,valor:Number(val),just:texto, tr});
          };
          showJust(true);
          return;
        }

        await salvarLinhaApontamento({id_kr,id_ms,dref,valor:Number(val),just, tr});
        return;
      }

      // ANEXAR
      if (e.target.closest('.ap-add')) {
        const fi = $('#ap_file_input'); if (!fi) return;
        fi.onchange = async ()=>{
          if (!fi.files || !fi.files[0]) return;
          const fd = new FormData();
          fd.append('csrf_token', csrfToken);
          fd.append('id_kr', id_kr);
          fd.append('id_ms', id_ms);
          fd.append('evidencia', fi.files[0]);
          const res = await fetch(`${SCRIPT}?ajax=apont_file_upload`, {method:'POST', body:fd});
          const j = await res.json();
          if (!j.success){ toast(j.error||'Falha ao anexar', false); fi.value=''; return; }
          toast('Evidência anexada!');
          tr.querySelector('.ap-add').style.display = 'none';
          tr.querySelector('.ap-del').style.display = '';
          fi.value='';
        };
        fi.click();
        return;
      }

      // EXCLUIR EVIDÊNCIA (com justificativa obrigatória)
      if (e.target.closest('.ap-del')) {
        _just_cb = async (texto)=>{
          const fd = new FormData();
          fd.append('csrf_token', csrfToken);
          fd.append('id_kr', id_kr);
          fd.append('id_ms', id_ms);
          // se existir mais de um, você pode abrir a lista e escolher; aqui removemos o mais recente:
          const lst = await fetch(`${SCRIPT}?ajax=apont_file_list&id_kr=${encodeURIComponent(id_kr)}&id_ms=${encodeURIComponent(id_ms)}`).then(r=>r.json());
          const file = (lst.files||[]).slice(-1)[0];
          if (!file){ toast('Nenhum anexo para excluir.', false); return; }
          fd.append('name', file.name);
          fd.append('justificativa', texto);
          const res = await fetch(`${SCRIPT}?ajax=apont_file_delete`, {method:'POST', body:fd});
          const j = await res.json();
          if (!j.success){ toast(j.error||'Falha ao excluir', false); return; }
          toast('Evidência excluída!');
          tr.querySelector('.ap-add').style.display = '';
          tr.querySelector('.ap-del').style.display = 'none';
        };
        showJust(true);
        return;
      }

      // VER ANEXO(S)
      if (e.target.closest('.ap-list')) {
        const f = await fetch(`${SCRIPT}?ajax=apont_file_list&id_kr=${encodeURIComponent(id_kr)}&id_ms=${encodeURIComponent(id_ms)}`);
        const j = await f.json();
        if (!j.success){ toast(j.error||'Falha ao listar', false); return; }
        if (!(j.files||[]).length){ toast('Sem anexos para este milestone.'); return; }
        // abre em nova aba o mais recente; ou liste num mini-modal customizado
        window.open(j.files.slice(-1)[0].url, '_blank');
      }
    });

    // Função que salva 1 linha e reflete na UI imediatamente
    async function salvarLinhaApontamento({id_kr,id_ms,dref,valor,just,tr}){
      if (valor===undefined || valor===null || String(valor).trim()==='') {
        toast('Informe o valor apontado.', false); return;
      }
      const items = [{
        id_ms: id_ms || null,
        data_prevista: dref,
        valor_real: Number(valor),
        dt_evidencia: new Date().toISOString().slice(0,10),
        observacao: just || ''
      }];
      const fd = new FormData();
      fd.append('csrf_token', csrfToken);
      fd.append('id_kr', id_kr);
      fd.append('items_json', JSON.stringify(items));

      const res = await fetch(`${SCRIPT}?ajax=apont_save`, { method:'POST', body:fd });
      const j = await res.json();
      if (!j.success){ toast(j.error||'Falha ao salvar', false); return; }

      // Atualiza linha (Real atual e Δ), zera justificativa do input
      const esp  = Number(tr.getAttribute('data-esp')||0);
      const novo = Number(valor);
      tr.querySelector('.ap-real').defaultValue = String(novo);
      tr.setAttribute('data-has-real','true');
      tr.querySelector('.ap-just').value = '';
      const deltaCell = tr.querySelector('td:nth-child(3)'); // cuidado com índices se mudar colunas
      // Aqui atualizamos as colunas certas:
      tr.children[2].textContent = fmtNum(esp);    // esperado (já estava)
      // Coluna "Apontado" é o input (fica como está)
      // Nada de alterar "Esperado"
      // Dica: se você quiser mostrar "Real atual" numa coluna separada, crie e atualize aqui.

      toast('Apontamento salvo!');

      // opcional: recarregar o gráfico do KR já aberto
      const open = document.querySelector('.kr-card.open');
      const reloadId = open ? open.getAttribute('data-id') : id_kr;
      if (reloadId) { 
        await loadKrDetail(reloadId);
      }
    }

    async function loadKRs(){
      const cont = $('#krContainer');
      cont.innerHTML = `<div class="chip"><i class="fa-solid fa-circle-notch fa-spin"></i> Carregando KRs...</div>`;

      const res  = await fetch(`${SCRIPT}?ajax=load_krs&id_objetivo=${idObjetivo}`);
      const data = await res.json();

      if(!data.success){
        cont.innerHTML = `<div class="chip" style="background:#5b1b1b;color:#ffe4e6;border-color:#7a1020"><i class="fa-solid fa-triangle-exclamation"></i> Erro ao carregar</div>`;
        return;
      }

      // Acumuladores para o progresso do OBJETIVO (média dos KRs)
      let sumAtual = 0, sumEsper = 0, n = 0;

      const toNum = v => (v === null || v === undefined || v === '' || isNaN(v)) ? null : Number(v);

      cont.innerHTML = '';
      data.krs.forEach(kr=>{
        const id = kr.id_kr;
        const isCancel = (kr.status || '').toLowerCase().includes('cancel');

        // Progresso do KR
        const pctAtualNum = toNum(kr?.progress?.pct_atual);
        const pctEsperNum = toNum(kr?.progress?.pct_esperado);
        const okFlag = (kr?.progress?.ok === true) ? true : ((kr?.progress?.ok === false) ? false : null);

        if (pctAtualNum !== null && pctEsperNum !== null) {
          sumAtual += pctAtualNum;
          sumEsper += pctEsperNum;
          n++;
        }

        const pctLabel  = pctAtualNum !== null ? `${pctAtualNum}%` : '—';
        const expLabel  = pctEsperNum !== null ? `${pctEsperNum}%` : '—';
        const progCls   = okFlag === null ? 'white' : (okFlag ? 'prog-ok' : 'prog-bad');

        // === NOVO: farol baseado na lógica do backend (farol_auto) com fallback ===
        const farolAuto      = (kr.farol_auto || kr.farol || 'neutro').toLowerCase();
        const farolAutoCls   = farolAuto === 'verde'   ? 'prog-ok'
                              : farolAuto === 'amarelo'? 'prog-warn'
                              : farolAuto === 'vermelho'? 'prog-bad'
                              : 'white';
        const farolAutoLabel = farolAuto === 'verde'   ? 'No trilho'
                              : farolAuto === 'amarelo'? 'Atenção'
                              : farolAuto === 'vermelho'? 'Crítico'
                              : '—';

        // tooltip do farol (mostra MS de referência, s e m se existirem)
        const sVal   = kr?.farol_calc?.s;
        const mVal   = kr?.farol_calc?.m;
        const sTxt   = (typeof sVal === 'number' && isFinite(sVal)) ? sVal.toFixed(3) : '—';
        const mTxt   = (typeof mVal === 'number' && isFinite(mVal)) ? mVal.toFixed(3) : '—';
        const refDt  = kr?.ref_milestone?.data || '—';
        const refAp  = kr?.ref_milestone?.tem_apontamento ? 'com apontamento' : 'sem apontamento';
        const farolTitle = `Ref: ${refDt} · ${refAp} · s=${sTxt} · m=${mTxt}`;

        cont.insertAdjacentHTML('beforeend', `
          <article class="kr-card${isCancel ? ' cancelado' : ''}" data-id="${id}">
            <div class="kr-head">
              <div>
                <div class="kr-title"><i class="fa-solid fa-flag"></i> KR${kr.key_result_num ? ' ' + kr.key_result_num : ''}: ${escapeHtml(truncate(kr.descricao||'', 160))}</div>
                <div class="meta-line">
                  <!-- PROGRESSO EM PRIMEIRO LUGAR -->
                  <span class="meta-pill ${progCls}" title="Esperado: ${expLabel} · Atual: ${pctLabel}">
                    <i class="fa-solid fa-chart-line"></i> Progresso: ${pctLabel}
                  </span>

                  <!-- === TROCA: farol dinâmico (novo) em vez do badge antigo === -->
                  <span class="meta-pill ${farolAutoCls}" title="${escapeHtml(farolTitle)}">
                    <i class="fa-solid fa-traffic-light"></i> ${farolAutoLabel}
                  </span>

                  <span class="meta-pill" title="Status"><i class="fa-solid fa-clipboard-check"></i>${escapeHtml(kr.status||'—')}</span>
                  <span class="meta-pill" title="Responsável do KR"><i class="fa-regular fa-user"></i>${escapeHtml(respLabel(kr))}</span>
                  <span class="meta-pill white" title="Data limite"><i class="fa-regular fa-calendar-days"></i>${escapeHtml(prazoLabel(kr))}</span>
                  <span class="meta-pill" title="Baseline"><i class="fa-solid fa-gauge"></i>${fmtNum(kr.baseline)} ${escapeHtml(kr.unidade_medida||'')}</span>
                  <span class="meta-pill" title="Meta"><i class="fa-solid fa-bullseye"></i>${fmtNum(kr.meta)} ${escapeHtml(kr.unidade_medida||'')}</span>
                  <span class="meta-pill" title="Frequência de apontamento"><i class="fa-solid fa-clock-rotate-left"></i>${escapeHtml(kr.tipo_frequencia_milestone||'—')}</span>
                </div>
              </div>
              <div class="kr-actions">
                <button class="btn btn-gold btn-sm" data-act="apont" data-id="${id}"><i class="fa-regular fa-pen-to-square"></i> Novo apontamento</button>
                <button class="btn btn-outline btn-sm" data-act="nova" data-id="${id}"><i class="fa-solid fa-screwdriver-wrench"></i> Incluir iniciativa</button>
                <button class="kr-toggle gold" title="Expandir" data-act="toggle" data-id="${id}">
                  <i class="fa-solid fa-chevron-down"></i>
                </button>
              </div>
            </div>
            <div class="kr-body">
              ${renderTabs(id)}
            </div>
          </article>
        `);
      });
      async function loadKRs(){
      const cont = $('#krContainer');
      cont.innerHTML = `<div class="chip"><i class="fa-solid fa-circle-notch fa-spin"></i> Carregando KRs...</div>`;

      const res  = await fetch(`${SCRIPT}?ajax=load_krs&id_objetivo=${idObjetivo}`);
      const data = await res.json();

      if(!data.success){
        cont.innerHTML = `<div class="chip" style="background:#5b1b1b;color:#ffe4e6;border-color:#7a1020"><i class="fa-solid fa-triangle-exclamation"></i> Erro ao carregar</div>`;
        return;
      }

      // Acumuladores para o progresso do OBJETIVO (média dos KRs)
      let sumAtual = 0, sumEsper = 0, n = 0;

      const toNum = v => (v === null || v === undefined || v === '' || isNaN(v)) ? null : Number(v);

      cont.innerHTML = '';
      data.krs.forEach(kr=>{
        const id = kr.id_kr;
        const isCancel = (kr.status || '').toLowerCase().includes('cancel');

        // Progresso do KR
        const pctAtualNum = toNum(kr?.progress?.pct_atual);
        const pctEsperNum = toNum(kr?.progress?.pct_esperado);
        const okFlag = (kr?.progress?.ok === true) ? true : ((kr?.progress?.ok === false) ? false : null);

        if (pctAtualNum !== null && pctEsperNum !== null) {
          sumAtual += pctAtualNum;
          sumEsper += pctEsperNum;
          n++;
        }

        const pctLabel  = pctAtualNum !== null ? `${pctAtualNum}%` : '—';
        const expLabel  = pctEsperNum !== null ? `${pctEsperNum}%` : '—';
        const progCls   = okFlag === null ? 'white' : (okFlag ? 'prog-ok' : 'prog-bad');

        cont.insertAdjacentHTML('beforeend', `
          <article class="kr-card${isCancel ? ' cancelado' : ''}" data-id="${id}">
            <div class="kr-head">
              <div>
                <div class="kr-title"><i class="fa-solid fa-flag"></i> KR${kr.key_result_num ? ' ' + kr.key_result_num : ''}: ${escapeHtml(truncate(kr.descricao||'', 160))}</div>
                <div class="meta-line">
                  <!-- PROGRESSO EM PRIMEIRO LUGAR -->
                  <span class="meta-pill ${progCls}" title="Esperado: ${expLabel} · Atual: ${pctLabel}">
                    <i class="fa-solid fa-chart-line"></i> Progresso: ${pctLabel}
                  </span>

                  <span class="meta-pill" title="Status"><i class="fa-solid fa-clipboard-check"></i>${escapeHtml(kr.status||'—')}</span>
                  <span class="meta-pill" title="Responsável do KR"><i class="fa-regular fa-user"></i>${escapeHtml(respLabel(kr))}</span>
                  <span class="meta-pill" title="Farol">${badgeFarol(kr.farol)}</span>
                  <span class="meta-pill white" title="Data limite"><i class="fa-regular fa-calendar-days"></i>${escapeHtml(prazoLabel(kr))}</span>
                  <span class="meta-pill" title="Meta"><i class="fa-solid fa-bullseye"></i>${fmtNum(kr.meta)} ${escapeHtml(kr.unidade_medida||'')}</span>
                  <span class="meta-pill" title="Baseline"><i class="fa-solid fa-gauge"></i>${fmtNum(kr.baseline)} ${escapeHtml(kr.unidade_medida||'')}</span>
                  <span class="meta-pill" title="Frequência de apontamento"><i class="fa-solid fa-clock-rotate-left"></i>${escapeHtml(kr.tipo_frequencia_milestone||'—')}</span>
                </div>
              </div>
              <div class="kr-actions">
                <button class="btn btn-gold btn-sm" data-act="apont" data-id="${id}"><i class="fa-regular fa-pen-to-square"></i> Novo apontamento</button>
                <button class="btn btn-outline btn-sm" data-act="nova" data-id="${id}"><i class="fa-solid fa-screwdriver-wrench"></i> Incluir iniciativa</button>
                <button class="kr-toggle gold" title="Expandir" data-act="toggle" data-id="${id}">
                  <i class="fa-solid fa-chevron-down"></i>
                </button>
              </div>
            </div>
            <div class="kr-body">
              ${renderTabs(id)}
            </div>
          </article>
        `);
      });

      // ====== CHIP DE PROGRESSO DO OBJETIVO (média dos KRs) ======
      const objPctAtual = n > 0 ? Math.round(sumAtual / n) : null;
      const objPctEsper = n > 0 ? Math.round(sumEsper / n) : null;
      const objOk       = (objPctAtual !== null && objPctEsper !== null) ? (objPctAtual >= objPctEsper) : null;

      const objPctLabel = objPctAtual !== null ? `${objPctAtual}%` : '—';
      const objExpLabel = objPctEsper !== null ? `${objPctEsper}%` : '—';
      const objCls      = objOk === null ? 'white' : (objOk ? 'prog-ok' : 'prog-bad');

      const metaBar = document.querySelector('.obj-meta-pills');
      if (metaBar) {
        // Atualiza se já existir; senão insere como primeiro chip
        const existing = document.querySelector('#objProgChip');
        const chipHTML = `
          <span class="meta-pill ${objCls}" id="objProgChip" title="Esperado: ${objExpLabel} · Atual: ${objPctLabel}">
            <i class="fa-solid fa-chart-line"></i> Progresso do objetivo: ${objPctLabel}
          </span>
        `;
        if (existing) {
          existing.classList.remove('prog-ok','prog-bad','white');
          existing.classList.add(objCls);
          existing.title = `Esperado: ${objExpLabel} · Atual: ${objPctLabel}`;
          existing.innerHTML = `<i class="fa-solid fa-chart-line"></i> Progresso do objetivo: ${objPctLabel}`;
          // garante que fique em primeiro
          if (metaBar.firstElementChild !== existing) metaBar.insertBefore(existing, metaBar.firstChild);
        } else {
          metaBar.insertAdjacentHTML('afterbegin', chipHTML);
        }
      }
    }
  }


    function renderTabs(id){
      return `
        <div class="tabs" data-tabs="${id}">
          <button class="tab active" data-tab="resumo-${id}"><i class="fa-solid fa-chart-line"></i> Resumo</button>
          <button class="tab" data-tab="ms-${id}"><i class="fa-solid fa-flag-checkered"></i> Milestones & Apontamentos</button>
          <button class="tab" data-tab="ini-${id}"><i class="fa-solid fa-diagram-project"></i> Iniciativas</button>
          <button class="tab" data-tab="orc-${id}"><i class="fa-solid fa-coins"></i> Orçamento</button>
          <button class="tab" data-tab="log-${id}"><i class="fa-regular fa-comments"></i> Log & Discussões</button>
        </div>
        <div class="tabpane active" id="resumo-${id}">
          <div style="display:grid;grid-template-columns:2fr 1fr;gap:12px;align-items:stretch;">
            <div style="background:#0e131a;border:1px solid var(--border);border-radius:12px; padding:10px;">
              <canvas id="scurve_${id}" height="260"></canvas>
            </div>
            <div class="kr-resumo-right">
              <div class="kpi">
                <div class="kpi-head"><span>Iniciativas do KR</span><div class="kpi-icon"><i class="fa-solid fa-rocket"></i></div></div>
                <div class="kpi-value" id="kpi_ini_${id}">—</div>
                <div class="kpi-head" style="margin-top:10px"><span>Orçamento do KR</span><div class="kpi-icon money"><i class="fa-solid fa-coins"></i></div></div>
                <div class="kpi-sub"><strong>Aprovado:</strong> <span id="orc_aprov_${id}">—</span></div>
                <div class="kpi-sub"><strong>Realizado:</strong> <span id="orc_real_${id}">—</span></div>
                <div class="kpi-sub"><strong>Saldo:</strong> <span id="orc_saldo_${id}">—</span></div>
                <hr style="border-color:#1f2a3a; margin:10px 0; opacity:.7">
                <div class="kpi-head"><span>Próximo milestone</span><div class="kpi-icon success"><i class="fa-regular fa-calendar-check"></i></div></div>
                <div class="kpi-sub" id="prox_ms_${id}">—</div>
              </div>
              <div class="kr-ops-outside" id="ops_${id}"></div>
            </div>
          </div>
        </div>

        <div class="tabpane" id="ms-${id}">
          <table class="table">
            <thead>
              <tr>
                <th><i class="th-ico fa-regular fa-calendar-days"></i>Data</th>
                <th><i class="th-ico fa-solid fa-bullseye"></i>Esperado</th>
                <th><i class="th-ico fa-solid fa-chart-line"></i>Realizado</th>
                <th><i class="th-ico fa-solid fa-plus-minus"></i>Δ</th>
                <th><i class="th-ico fa-regular fa-file-lines"></i>Data evidência</th>
              </tr>
            </thead>
            <tbody id="tb_ms_${id}"><tr><td colspan="5">Carregando...</td></tr></tbody>
          </table>
        </div>

        <div class="tabpane" id="ini-${id}">
          <div style="display:flex; justify-content:flex-end; margin-bottom:8px;">
            <button class="btn btn-outline"><i class="fa-solid fa-table-columns"></i> Ver Kanban</button>
          </div>
          <table class="table">
            <thead>
              <tr>
                <th><i class="th-ico fa-solid fa-hashtag"></i>#</th>
                <th><i class="th-ico fa-regular fa-rectangle-list"></i>Descrição</th>
                <th><i class="th-ico fa-regular fa-user"></i>Responsável</th>
                <th><i class="th-ico fa-solid fa-clipboard-check"></i>Status</th>
                <th><i class="th-ico fa-regular fa-calendar-days"></i>Prazo</th>
                <th style="text-align:right"><i class="th-ico fa-solid fa-sack-dollar"></i>Aprovado</th>
                <th style="text-align:right"><i class="th-ico fa-solid fa-wallet"></i>Realizado</th>
                <th style="text-align:right"><i class="th-ico fa-solid fa-scale-balanced"></i>Saldo</th>
                <th style="text-align:right"><i class="th-ico fa-solid fa-wrench"></i>Ações</th>
              </tr>
            </thead>
            <tbody id="tb_ini_${id}"><tr><td colspan="9">Carregando...</td></tr></tbody>
          </table>
        </div>

        <div class="tabpane" id="orc-${id}">
          <div class="orc-topbar">
            <div class="chips">
              <span class="chip"><i class="fa-solid fa-sack-dollar"></i> Aprovado: <strong id="orc2_aprov_${id}" style="margin-left:6px">—</strong></span>
              <span class="chip"><i class="fa-solid fa-wallet"></i> Realizado: <strong id="orc2_real_${id}" style="margin-left:6px">—</strong></span>
              <span class="chip"><i class="fa-solid fa-scale-balanced"></i> Saldo: <strong id="orc2_saldo_${id}" style="margin-left:6px">—</strong></span>
              <span class="chip" id="orc_badge_${id}" title="Desvio vs planejado até hoje"><i class="fa-solid fa-traffic-light"></i> <strong>—</strong></span>
            </div>
            <div class="orc-topbar-right">
              <label for="orc_ano_${id}" class="lbl">Ano</label>
              <select id="orc_ano_${id}" class="sel-year"></select>
              <div class="segmented" data-scope="${id}">
                <button type="button" data-mode="mensal" class="active">Mensal</button>
                <button type="button" data-mode="acum">Acumulado</button>
              </div>
            </div>
          </div>
          <div class="orc-chart">
            <canvas id="orc_chart_${id}" height="230"></canvas>
          </div>
          <div class="orc-grid" id="orc_grid_${id}"></div>
          <div class="orc-card">
            <div class="orc-card-title"><i class="fa-solid fa-diagram-project"></i> Execução por iniciativa</div>
            <div class="table-wrap">
              <table class="table">
                <thead><tr><th>#</th><th>Iniciativa</th><th>Responsável</th><th style="text-align:right">Aprovado</th><th style="text-align:right">Realizado</th><th style="text-align:right">Saldo</th></tr></thead>
                <tbody id="orc_tab_inis_${id}">
                  <tr><td colspan="6" style="color:#9aa4b2">Carregando...</td></tr>
                </tbody>
              </table>
            </div>
          </div>
          <div class="orc-two-cols">
            <div class="orc-card">
              <div class="orc-card-title"><i class="fa-regular fa-circle-question"></i> Pendências de aprovação</div>
              <div id="orc_pend_${id}" class="orc-list"></div>
            </div>
            <div class="orc-card">
              <div class="orc-card-title"><i class="fa-regular fa-clock"></i> Últimas despesas</div>
              <div id="orc_ult_${id}" class="orc-list"></div>
            </div>
          </div>
        </div>

        <div class="tabpane" id="log-${id}">
          <div class="chip"><i class="fa-regular fa-comments"></i> Conecte aqui seu feed/timeline.</div>
        </div>
      `;
    }

    // Delegação de eventos
    document.addEventListener('click', async (e)=>{
      const btnT = e.target.closest('[data-act="toggle"]');
      if (btnT){
        const card = e.target.closest('.kr-card');
        const id   = btnT.getAttribute('data-id');
        const willOpen = !card.classList.contains('open');
        if (willOpen){
          card.classList.add('open');
          await loadKrDetail(id);
          await loadIniciativas(id);
        } else {
          card.classList.remove('open');
        }
        return;
      }

      const tabBtn = e.target.closest('.tab');
      if (tabBtn){
        const target = tabBtn.getAttribute('data-tab');
        const wrap   = tabBtn.parentElement;
        $$('.tab', wrap).forEach(b=>b.classList.remove('active'));
        tabBtn.classList.add('active');
        const body = wrap.parentElement;
        $$('.tabpane', body).forEach(p=>p.classList.remove('active'));
        const pane = $('#'+target, body);
        pane.classList.add('active');

        if (target.startsWith('orc-')){
          const id  = target.replace('orc-','');
          const selAno = document.getElementById(`orc_ano_${id}`);
          const ano = selAno?.value;
          loadOrcDashboard(id, ano);
        }
        return;
      }

      const btnNova = e.target.closest('[data-act="nova"]');
      if (btnNova){
        const id = btnNova.getAttribute('data-id');
        await openNovaIniciativaDrawer(id);
        return;
      }

      const btnAp = e.target.closest('[data-act="apont"]');
      if (btnAp){
        const id = btnAp.getAttribute('data-id');
        await openApontModal(id);
        return;
      }

      const btnDesp = e.target.closest('button[data-act="despesa"]');
      if (btnDesp){
        $('#desp_id_orcamento').value = btnDesp.getAttribute('data-id');
        toggleDrawer('#drawerDespesa', true);
        return;
      }

      // Abrir drawer "Alterar status da iniciativa"
      const btnIniStatus = e.target.closest('button[data-act="ini-status"]');
      if (btnIniStatus) {
        const idIni = btnIniStatus.getAttribute('data-id');
        await openIniStatusDrawer(idIni);
        return;
      }


      async function openIniStatusDrawer(idIni){
      // Preenche id
      $('#inis_id_iniciativa').value = idIni;
      // Limpa form
      $('#formIniStatus')?.reset();

      // Carrega lista de status
      const sel = $('#inis_status');
      sel.innerHTML = '<option value="">Carregando...</option>';
      try{
        const res = await fetch(`${SCRIPT}?ajax=list_status_iniciativa`);
        const data = await res.json();
        if (!data.success) throw new Error(data.error||'Falha ao listar status');
        sel.innerHTML = '';
        (data.items||[]).forEach(s=>{
          const opt = document.createElement('option');
          opt.value = s.id;
          opt.textContent = s.label || s.id;
          sel.appendChild(opt);
        });
        // Sugestão de default
        const pref = ['Em Andamento','Não Iniciado'];
        const idx  = (data.items||[]).findIndex(it => 
          pref.map(p=>p.toLowerCase()).includes((it.id||'').toLowerCase()) ||
          pref.map(p=>p.toLowerCase()).includes((it.label||'').toLowerCase())
        );
        if (idx >= 0) sel.selectedIndex = idx;
      }catch(err){
        sel.innerHTML = '<option value="">Falha ao carregar</option>';
        toast(err.message, false);
      }

      toggleDrawer('#drawerIniStatus', true);
    }

    $('#btnSalvarIniStatus')?.addEventListener('click', async ()=>{
      const fd  = new FormData($('#formIniStatus'));
      const obs = (fd.get('observacao')||'').toString().trim();
      if (!obs) { toast('Observação é obrigatória.', false); return; }

      const res = await fetch(`${SCRIPT}?ajax=update_iniciativa_status`, { method:'POST', body:fd });
      const data = await res.json();
      if (!data.success) { toast(data.error||'Falha ao alterar status', false); return; }

      toast('Status da iniciativa atualizado!');
      toggleDrawer('#drawerIniStatus', false);

      // Recarrega a lista da aba Iniciativas do KR aberto
      const open = document.querySelector('.kr-card.open');
      if (open){
        const id = open.getAttribute('data-id');
        await loadIniciativas(id);
      }
    });


      // Cancelar KR
      const btnCancel = e.target.closest('button[data-act="cancel-kr"]');
      if (btnCancel){
        const id = btnCancel.getAttribute('data-id');
        const justificativa = prompt('Confirme a justificativa do cancelamento do KR:');
        if (!justificativa || justificativa.trim().length < 3) { toast('Cancelamento abortado: informe uma justificativa.', false); return; }
        const fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('id_kr', id);
        fd.append('justificativa', justificativa.trim());
        const res  = await fetch(`${SCRIPT}?ajax=cancel_kr`, { method:'POST', body:fd });
        const data = await res.json();
        if (!data.success){ toast(data.error||'Falha ao cancelar KR', false); return; }
        toast('KR cancelado com sucesso.');
        await loadKrDetail(id);
        await loadIniciativas(id);
        return;
      }

      // Reativar KR
      const btnReativar = e.target.closest('button[data-act="reactivate-kr"]');
      if (btnReativar){
        const id = btnReativar.getAttribute('data-id');
        await openReactivateDrawer(id);
        return;
      }

      // Excluir KR
      const btnExcluir = e.target.closest('button[data-act="delete-kr"]');
      if (btnExcluir){
        const id = btnExcluir.getAttribute('data-id');
        const msg = '⚠️ ATENÇÃO!\n\nVocê pode CANCELAR este KR em vez de EXCLUIR.\n\n'
          + 'Excluir é permanente e removerá milestones, apontamentos, iniciativas e orçamentos ligados a este KR.\n'
          + 'Esta ação NÃO poderá ser desfeita.\n\nDeseja EXCLUIR mesmo assim?';
        if (!confirm(msg)) return;
        const fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('id_kr', id);
        const res  = await fetch(`${SCRIPT}?ajax=delete_kr`, { method:'POST', body:fd });
        const data = await res.json();
        if (!data.success){ toast(data.error||'Falha ao excluir KR', false); return; }
        toast('KR excluído definitivamente.');
        await loadKRs();
        return;
      }
    });

    async function openReactivateDrawer(id){
      $('#react_id_kr').value = id;
      const sel = $('#react_status');
      sel.innerHTML = '<option>Carregando...</option>';
      try{
        const res  = await fetch(`${SCRIPT}?ajax=list_status_kr&only_active=1`);
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Falha ao listar status');
        sel.innerHTML = '';
        (data.items||[]).forEach(it=>{
          const opt = document.createElement('option');
          opt.value = it.id;
          opt.textContent = it.label || it.id;
          sel.appendChild(opt);
        });
        const pref = ['em andamento','nao iniciado'];
        const idx = (data.items||[]).findIndex(it =>
          pref.includes((it.id||'').toLowerCase()) || pref.includes((it.label||'').toLowerCase())
        );
        if (idx >= 0) sel.selectedIndex = idx;
      } catch(err){
        sel.innerHTML = '';
        toast(err.message, false);
      }
      toggleDrawer('#drawerReativar', true);
    }

    $('#btnReativarKrSave')?.addEventListener('click', async ()=>{
      const id     = $('#react_id_kr').value;
      const status = $('#react_status').value;
      const fd = new FormData();
      fd.append('csrf_token', csrfToken);
      fd.append('id_kr', id);
      fd.append('status_target', status);
      const res  = await fetch(`${SCRIPT}?ajax=reactivate_kr`, { method:'POST', body: fd });
      const data = await res.json();
      if (!data.success){ toast(data.error || 'Falha ao reativar KR', false); return; }
      toast('KR reativado com sucesso.');
      toggleDrawer('#drawerReativar', false);
      await loadKrDetail(id);
      await loadIniciativas(id);
      await loadKRs();
    });

    // Detalhe KR
    const charts    = {};
    const orcCharts = {};

    async function loadKrDetail(id){
      const res  = await fetch(`${SCRIPT}?ajax=kr_detail&id_kr=${encodeURIComponent(id)}`);
      const data = await res.json();
      if(!data.success){ toast('Erro ao carregar KR', false); return; }

      // Gráfico (Esperado)
      // === Curva-S / Faixa ideal ===
      const ctx = document.getElementById(`scurve_${id}`);
      if (ctx) {
        if (charts[id]) charts[id].destroy();

        // Base
        const labels   = (data.chart.labels || []).map(d => toDDMMYYYY(d,'/'));
        const realData = (data.chart.real   || []).map(v => v ?? null);
        const unidade  = data.kr?.unidade_medida ? ' ' + data.kr.unidade_medida : '';

        // Intervalo ideal: detectar pelo campo DIREÇÃO (é o que o backend manda)
        const dirText = (data.kr?.direcao_metrica || '').toLowerCase();
        const saysInterval = /intervalo|entre|faixa/.test(dirText);

        // Séries min/max vindas do backend
        const minArrRaw = data.chart?.min ?? null;
        const maxArrRaw = data.chart?.max ?? null;

        const toNumOrNull = v => (v===null||v===undefined||v==='') ? null : Number(v);
        const expandTo = (src, len) => {
          if (!Array.isArray(src)) return Array(len).fill(null);
          const arr = src.map(toNumOrNull);
          if (arr.length === len) return arr;
          if (arr.length === 1)   return Array(len).fill(arr[0]);
          if (arr.length < len)   return arr.concat(Array(len - arr.length).fill(arr[arr.length-1]));
          return arr.slice(0, len);
        };

        // Só ativa o modo faixa se o KR disser que é intervalo E existir algum ponto válido em min/max
        const hasRangeData = Array.isArray(minArrRaw) && Array.isArray(maxArrRaw) &&
          (minArrRaw.some(v => v!=null && v!=='') || maxArrRaw.some(v => v!=null && v!==''));
        const useRange = saysInterval && hasRangeData;

        let minArr = useRange ? expandTo(minArrRaw, labels.length) : null;
        let maxArr = useRange ? expandTo(maxArrRaw, labels.length) : null;

        if (useRange) {
          // garante min <= max por ponto
          for (let i=0;i<labels.length;i++){
            const a=minArr[i], b=maxArr[i];
            if (a!=null && b!=null && a>b) { minArr[i]=b; maxArr[i]=a; }
          }
        }

        // Datasets: em intervalo ideal mostramos Min e Max (e Realizado). NÃO mostramos "Esperado" (que é a média).
        const datasets = useRange ? [
          {
            label: 'Mínimo ideal',
            data: minArr,
            borderColor: '#60a5fa',
            pointBackgroundColor: '#60a5fa',
            pointBorderColor: '#60a5fa',
            borderWidth: 2,
            pointRadius: 2,
            tension: 0.35,
            fill: false,
            borderDash: [4,4],
            spanGaps: true
          },
          {
            label: 'Máximo ideal',
            data: maxArr,
            borderColor: '#60a5fa',
            pointBackgroundColor: '#60a5fa',
            pointBorderColor: '#60a5fa',
            borderWidth: 2,
            pointRadius: 2,
            tension: 0.35,
            fill: '-1', // preenche ENTRE Máximo e Mínimo
            backgroundColor: 'rgba(96,165,250,0.12)',
            spanGaps: true
          },
          {
            label: 'Realizado',
            data: realData,
            borderColor: '#f6c343',
            backgroundColor: 'rgba(246,195,67,0.12)',
            pointBackgroundColor: '#f6c343',
            pointBorderColor: '#f6c343',
            borderWidth: 2,
            pointRadius: 3,
            tension: 0.35,
            fill: false,
            spanGaps: true
          }
        ] : [
          // modo padrão (não-intervalo): Esperado + Realizado
          {
            label: 'Esperado',
            data: data.chart.esperado || [],
            borderColor: '#e4eaf0ff',
            backgroundColor: 'rgba(246,195,67,0.12)',
            pointBackgroundColor: '#e4eaf0ff',
            pointBorderColor: '#e4eaf0ff',
            borderWidth: 2,
            pointRadius: 3,
            tension: 0.35,
            fill: false,
            borderDash: [6,4]
          },
          {
            label: 'Realizado',
            data: realData,
            borderColor: '#f6c343',
            backgroundColor: 'rgba(96,165,250,0.12)',
            pointBackgroundColor: '#f6c343',
            pointBorderColor: '#f6c343',
            borderWidth: 2,
            pointRadius: 3,
            tension: 0.35,
            fill: false,
            spanGaps: true
          }
        ];

        // Eixo Y: auto-ajuste quando for faixa
        let yOpts = {
          beginAtZero: true,
          ticks: { color: '#a6adbb', callback: v => fmtNum(v) },
          grid:  { color: 'rgba(255,255,255,0.06)' }
        };
        if (useRange) {
          const vals = [...minArr, ...maxArr, ...realData].filter(v => Number.isFinite(v));
          if (vals.length) {
            const lo = Math.min(...vals), hi = Math.max(...vals);
            const pad = (hi - lo) * 0.05 || 1;
            yOpts = {
              ticks: { color:'#a6adbb', callback: v => fmtNum(v) },
              grid:  { color:'rgba(255,255,255,0.06)' },
              suggestedMin: lo - pad,
              suggestedMax: hi + pad
            };
          }
        }

        const titleText = useRange ? 'Curva "S" (faixa ideal)' : 'Curva "S" de progresso';

        charts[id] = new Chart(ctx, {
          type: 'line',
          data: { labels, datasets },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
              legend: { display: true },
              title: { display: true, text: titleText, color: '#eaeef6', font: { weight: 'bold', size: 14 } },
              tooltip: {
                callbacks: {
                  title: (items)=> items.length ? `Milestone: ${items[0].label}` : '',
                  label: (item)=> `${item.dataset.label}: ${fmtNum(item.parsed.y)}${unidade}`,
                  afterBody(items){
                    if (useRange) {
                      const min = items.find(i => i.dataset.label === 'Mínimo ideal')?.parsed?.y;
                      const max = items.find(i => i.dataset.label === 'Máximo ideal')?.parsed?.y;
                      const r   = items.find(i => i.dataset.label === 'Realizado')?.parsed?.y;
                      if (min == null || max == null || r == null) return '';
                      if (r >= min && r <= max) return 'Dentro do ideal';
                      const diff = r < min ? (min - r) : (r - max);
                      return `Fora do ideal por ${fmtNum(diff)}${unidade}`;
                    }
                    if (items.length < 2) return '';
                    const e = items.find(i => i.dataset.label === 'Esperado')?.parsed?.y;
                    const r = items.find(i => i.dataset.label === 'Realizado')?.parsed?.y;
                    if (e == null || r == null) return '';
                    return `Δ: ${fmtNum(r - e)}${unidade}`;
                  }
                }
              }
            },
            scales: {
              x: { ticks: { color: '#a6adbb' }, grid: { color: 'rgba(255,255,255,0.06)' } },
              y: yOpts
            }
          }
        });
      }

      // KPIs e agregados
      setText(`kpi_ini_${id}`,     data.agregados.iniciativas ?? '—');
      setText(`orc_aprov_${id}`,   fmtBRL(data.agregados.orcamento.aprovado ?? 0));
      setText(`orc_real_${id}`,    fmtBRL(data.agregados.orcamento.realizado ?? 0));
      setText(`orc_saldo_${id}`,   fmtBRL(data.agregados.orcamento.saldo ?? 0));
      setText(`orc2_aprov_${id}`,  fmtBRL(data.agregados.orcamento.aprovado ?? 0));
      setText(`orc2_real_${id}`,   fmtBRL(data.agregados.orcamento.realizado ?? 0));
      setText(`orc2_saldo_${id}`,  fmtBRL(data.agregados.orcamento.saldo ?? 0));

      const p = data.agregados.proximo_milestone;
      if(p && p.data_prevista){
        const delta = (p.valor_real ?? null) !== null ? (p.valor_real - (p.valor_esperado ?? 0)) : null;
        const deltaTxt = delta !== null ? (` (Δ ${fmtNum(delta)})`) : '';
        setText(`prox_ms_${id}`, `${toDDMMYYYY(p.data_prevista,'/')} • Esperado: ${fmtNum(p.valor_esperado)} • Realizado: ${p.valor_real!==null?fmtNum(p.valor_real):'—'}${deltaTxt}`);
      } else {
        setText(`prox_ms_${id}`, '—');
      }

      // Milestones (lista)
      const tb = document.getElementById(`tb_ms_${id}`);
      if(tb){
        tb.innerHTML = '';
        const arr = data.milestones || [];
        if(!arr.length){
          tb.innerHTML = `<tr><td colspan="5" style="color:#9aa4b2">Sem milestones cadastrados.</td></tr>`;
        } else {
          arr.forEach(m=>{
            const delta = (m.valor_real ?? null) !== null ? (m.valor_real - (m.valor_esperado ?? 0)) : null;
            tb.insertAdjacentHTML('beforeend', `
              <tr>
                <td>${escapeHtml(toDDMMYYYY(m.data_prevista,'/'))}</td>
                <td>${fmtNum(m.valor_esperado)}</td>
                <td>${m.valor_real!==null?fmtNum(m.valor_real):'—'}</td>
                <td>${delta!==null?fmtNum(delta):'—'}</td>
                <td>${escapeHtml(m.dt_evidencia ? toDDMMYYYY(m.dt_evidencia,'/') : '—')}</td>
              </tr>
            `);
          });
        }
      }

      // AÇÕES DO KR (aba Resumo)
      const ops = document.getElementById(`ops_${id}`);
      if (ops){
        const status    = (data.kr.status || '').toLowerCase();
        const cancelado = status.includes('cancel');
        ops.innerHTML = `
          ${cancelado ? '' : `<a class="btn btn-outline" href="/OKR_system/views/editar_key_result.php?id_kr=${encodeURIComponent(id)}"><i class="fa-regular fa-pen-to-square"></i> Editar KR</a>`}
          ${cancelado ? `<button class="btn btn-success" data-act="reactivate-kr" data-id="${id}"><i class="fa-solid fa-rotate-left"></i> Reativar KR</button>`
                      : `<button class="btn btn-warning" data-act="cancel-kr" data-id="${id}"><i class="fa-regular fa-circle-xmark"></i> Cancelar KR</button>`}
          <span class="spacer"></span>
          <button class="btn btn-danger" data-act="delete-kr" data-id="${id}"><i class="fa-regular fa-trash-can"></i> Excluir KR</button>
        `;
      }
    }

    function setText(id, txt){ const el=document.getElementById(id); if(el) el.textContent=txt; }

    // Iniciativas
    async function loadIniciativas(id){
      const res  = await fetch(`${SCRIPT}?ajax=iniciativas_list&id_kr=${encodeURIComponent(id)}`);
      const data = await res.json();
      const tb   = document.getElementById(`tb_ini_${id}`);
      if(!tb) return;
      tb.innerHTML = '';
      if(!data.success || !data.iniciativas?.length){
        tb.innerHTML = `<tr><td colspan="9" style="color:#9aa4b2">Sem iniciativas.</td></tr>`;
        return;
      }
      data.iniciativas.forEach(ini=>{
      // Ícone de saquinho de dinheiro (lançar despesa) se houver orçamento
        const moneyBtn = ini.orcamento?.id_orcamento
          ? `<button class="btn btn-outline btn-sm" title="Lançar despesa"
              data-act="despesa" data-id="${ini.orcamento.id_orcamento}">
              <i class="fa-solid fa-sack-dollar"></i>
            </button>`
          : `<button class="btn btn-outline btn-sm" title="Sem orçamento vinculado" disabled>
              <i class="fa-solid fa-sack-dollar"></i>
            </button>`;

        // Ícone para alterar status da iniciativa (sempre habilitado)
        const statusBtn = `<button class="btn btn-outline btn-sm" title="Alterar status"
                            data-act="ini-status" data-id="${ini.id_iniciativa}">
                            <i class="fa-solid fa-arrows-rotate"></i>
                          </button>`;

        const actions = `<div style="display:flex; gap:6px; justify-content:flex-end;">
                          ${moneyBtn}
                          ${statusBtn}
                        </div>`;

        tb.insertAdjacentHTML('beforeend', `
          <tr>
            <td>${ini.num_iniciativa}</td>
            <td style="color:#d1d5db">${escapeHtml(ini.descricao||'')}</td>
            <td>${escapeHtml(ini.responsavel||'—')}</td>
            <td>${escapeHtml(ini.status||'—')}</td>
            <td>${escapeHtml(ini.dt_prazo ? toDDMMYYYY(ini.dt_prazo,'/') : '—')}</td>
            <td style="text-align:right">${fmtBRL(ini.orcamento?.aprovado||0)}</td>
            <td style="text-align:right">${fmtBRL(ini.orcamento?.realizado||0)}</td>
            <td style="text-align:right">${fmtBRL(ini.orcamento?.saldo||0)}</td>
            <td style="text-align:right">${actions}</td>
          </tr>
        `);
      });
    }

    function mesLabel(ym){
      const [y,m] = (ym||'').split('-');
      const nomes = ['JAN','FEV','MAR','ABR','MAI','JUN','JUL','AGO','SET','OUT','NOV','DEZ'];
      const mi = Math.max(1, Math.min(12, parseInt(m||'1',10)));
      return `${nomes[mi-1]}/${y}`;
    }
    function farolBadge(f){
      if (f==='verde')   return `<span class="chip"><i class="fa-solid fa-circle" style="color:#22c55e"></i> ok</span>`;
      if (f==='amarelo') return `<span class="chip"><i class="fa-solid fa-circle" style="color:#f6c343"></i> atenção</span>`;
      if (f==='vermelho')return `<span class="chip"><i class="fa-solid fa-circle" style="color:#ef4444"></i> alerta</span>`;
      return `<span class="chip"><i class="fa-regular fa-circle"></i> —</span>`;
    }

    async function loadOrcDashboard(id, ano){
      try{
        // ano default do select
        const selAno = document.getElementById(`orc_ano_${id}`);
        const anoNow = new Date().getFullYear();
        if (selAno && !selAno.options.length){
          [anoNow-1, anoNow, anoNow+1].forEach(a=>{
            const o=document.createElement('option'); o.value=a; o.textContent=a; selAno.appendChild(o);
          });
          selAno.value = String(ano || anoNow);
          selAno.addEventListener('change', ()=> loadOrcDashboard(id, selAno.value));
        }
        const useAno = selAno ? selAno.value : (ano || anoNow);

        const res  = await fetch(`${SCRIPT}?ajax=orc_dashboard&id_kr=${encodeURIComponent(id)}&ano=${encodeURIComponent(useAno)}`);
        const data = await res.json();
        if(!data.success) throw new Error(data.error || 'Falha ao carregar orçamentos');

        setText(`orc2_aprov_${id}`, fmtBRL(data.totais.aprovado||0));
        setText(`orc2_real_${id}`,  fmtBRL(data.totais.realizado||0));
        setText(`orc2_saldo_${id}`, fmtBRL(data.totais.saldo||0));

        const badge = document.getElementById(`orc_badge_${id}`);
        if (badge) badge.innerHTML = `<i class="fa-solid fa-traffic-light"></i> ${farolBadge(data.totais.farol)}`;

        // Chart
        const chartEl   = document.getElementById(`orc_chart_${id}`);
        const labels    = (data.series||[]).map(s=>mesLabel(s.competencia));
        const mensalPlan= (data.series||[]).map(s=>s.planejado||0);
        const mensalReal= (data.series||[]).map(s=>s.realizado||0);
        const acumPlan  = (data.series||[]).map(s=>s.plan_acum||0);
        const acumReal  = (data.series||[]).map(s=>s.real_acum||0);

        if (orcCharts[id]) { try{ orcCharts[id].destroy(); }catch(e){} }

        orcCharts[id] = new Chart(chartEl, {
          type: 'bar', // tipo base; datasets podem virar 'line' no toggle
          data: {
            labels,
            datasets: [
              { label:'Planejado', data: mensalPlan, backgroundColor:'rgba(246,195,67,0.35)', borderColor:'#f6c343', borderWidth:1, type: 'bar' },
              { label:'Realizado', data: mensalReal, backgroundColor:'rgba(96,165,250,0.35)', borderColor:'#60a5fa', borderWidth:1, type: 'bar' }
            ]
          },
          options: {
            responsive:true,
            maintainAspectRatio:false,
            interaction: { mode:'index', intersect:false },
            animation: { duration: 200 },
            plugins:{
              legend:{ labels:{ color:'#cbd5e1' } },
              tooltip:{
                callbacks:{
                  label: (item)=> `${item.dataset.label}: ${fmtBRL(item.parsed.y)}`
                }
              }
            },
            scales:{
              x:{ ticks:{ color:'#a6adbb', maxRotation: 0 }, grid:{ color:'rgba(255,255,255,.06)' } },
              y:{
                beginAtZero:true,
                ticks:{ color:'#a6adbb', callback: (v)=> fmtBRL(v) },
                grid:{ color:'rgba(255,255,255,.06)' }
              }
            }
          }
        });
        // toggle mensal/acum
        const seg = document.querySelector(`.segmented[data-scope="${id}"]`);
        if (seg && !seg.dataset.bound){
          seg.dataset.bound = '1';

          const setMode = (mode) => {
            const isAcum = mode === 'acum';

            // troca os dados
            orcCharts[id].data.datasets[0].data = isAcum ? acumPlan : mensalPlan;
            orcCharts[id].data.datasets[1].data = isAcum ? acumReal : mensalReal;

            // troca o “tipo” dos datasets (mais estável do que mudar chart.config.type)
            orcCharts[id].data.datasets.forEach(ds => {
              ds.type = isAcum ? 'line' : 'bar';
              ds.borderWidth = isAcum ? 2 : 1;
              // opcional: remove fill quando vira linha
              ds.fill = !isAcum;
            });

            orcCharts[id].update();
          };

          seg.addEventListener('click', (e)=>{
            const b = e.target.closest('button[data-mode]'); if(!b) return;
            seg.querySelectorAll('button').forEach(x=>x.classList.remove('active'));
            b.classList.add('active');
            setMode(b.getAttribute('data-mode'));
          });
        }

        // Grade mensal
        const grid = document.getElementById(`orc_grid_${id}`);
        if (grid){
          grid.innerHTML='';
          (data.series||[]).forEach(s=>{
            grid.insertAdjacentHTML('beforeend', `
              <div class="orc-month">
                <div class="m-head">
                  <span>${mesLabel(s.competencia)}</span>
                  ${s.tem_pendente ? '<span class="badge">pendências</span>' : ''}
                </div>
                <div class="m-body">
                  <div>Planejado: <strong>${fmtBRL(s.planejado||0)}</strong></div>
                  <div>Realizado: <strong>${fmtBRL(s.realizado||0)}</strong></div>
                  <div>Δ mês: <strong>${fmtBRL((s.realizado||0)-(s.planejado||0))}</strong></div>
                </div>
              </div>
            `);
          });
        }

        // Tabela por iniciativa
        const tb = document.getElementById(`orc_tab_inis_${id}`);
        if (tb){
          tb.innerHTML = '';
          const arr = data.por_iniciativa || [];
          if (!arr.length){
            tb.innerHTML = `<tr><td colspan="6" class="empty">Sem iniciativas com orçamento.</td></tr>`;
          } else {
            arr.forEach(it=>{
              tb.insertAdjacentHTML('beforeend', `
                <tr>
                  <td>${it.num_iniciativa||''}</td>
                  <td style="color:#d1d5db">${escapeHtml(it.descricao||'')}</td>
                  <td>${escapeHtml(it.responsavel||'—')}</td>
                  <td style="text-align:right">${fmtBRL(it.aprovado||0)}</td>
                  <td style="text-align:right">${fmtBRL(it.realizado||0)}</td>
                  <td style="text-align:right">${fmtBRL(it.saldo||0)}</td>
                </tr>
              `);
            });
          }
        }

        // Pendências
        const pend = document.getElementById(`orc_pend_${id}`);
        if (pend){
          pend.innerHTML='';
          const arr = data.pendencias || [];
          if (!arr.length){
            pend.innerHTML = `<div class="empty">Nenhuma parcela pendente.</div>`;
          } else {
            arr.forEach(p=>{
              pend.insertAdjacentHTML('beforeend', `
                <div class="item">
                  <div><strong>${mesLabel((p.data_desembolso||'').slice(0,7))}</strong> · ${fmtBRL(p.valor||0)}</div>
                  <div>${escapeHtml(p.justificativa_orcamento||'—')}</div>
                  <div style="opacity:.8">Criado por: ${escapeHtml(p.criador_nome||'—')}</div>
                </div>
              `);
            });
          }
        }

        // Últimas despesas
        const ult = document.getElementById(`orc_ult_${id}`);
        if (ult){
          ult.innerHTML='';
          const arr = data.ultimas_despesas || [];
          if (!arr.length){
            ult.innerHTML = `<div class="empty">Nada lançado ainda.</div>`;
          } else {
            arr.forEach(d=>{
              ult.insertAdjacentHTML('beforeend', `
                <div class="item">
                  <div><strong>${toDDMMYYYY(d.data_pagamento,'/')}</strong> · ${fmtBRL(d.valor||0)}</div>
                  <div>${escapeHtml(d.descricao||'—')}</div>
                  <div style="opacity:.8">Por: ${escapeHtml(d.criador_nome||'—')}</div>
                </div>
              `);
            });
          }
        }
      } catch(err){
        toast(err.message || 'Erro ao carregar a aba Orçamentos', false);
      }
    }

    // Forms
    $('#ni_sw_orc')?.addEventListener('change', e=> $('#ni_orc_group').style.display = e.target.checked ? 'block':'none');

    $('#btnSalvarIni')?.addEventListener('click', async ()=>{
      const fd = new FormData($('#formNovaIniciativa'));
      const incl = $('#ni_sw_orc')?.checked;
      if (incl){
        const total = Number($('#ni_valor_total').value || 0);
        if (!total || total <= 0) { toast('Informe o valor total do orçamento.', false); return; }
        if (!previsoes.length){ toast('Adicione ao menos uma competência na previsão de desembolso.', false); return; }
        const soma = previsoes.reduce((a,b)=> a + (Number(b.valor)||0), 0);
        if (Math.abs(soma - total) > 0.005){ toast('A soma das competências deve ser igual ao total.', false); return; }
      }
      const res  = await fetch('/OKR_system/auth/salvar_iniciativas.php', { method:'POST', body:fd });
      let data = {};
      try { data = await res.json(); } catch(e){}
      if (!data.success){ toast(data.error||'Erro ao salvar', false); return; }
      toast('Iniciativa criada com sucesso!');
      toggleDrawer('#drawerNovaIni', false);
      await loadIniciativas($('#ni_id_kr').value);
      const open = document.querySelector('.kr-card.open');
      if (open){ const id=open.getAttribute('data-id'); await loadKrDetail(id); }
      await loadKRs();
    });

    $('#btnSalvarDesp')?.addEventListener('click', async ()=>{
      const fd  = new FormData($('#formDespesa'));
      const res = await fetch(`${SCRIPT}?ajax=create_despesa`, { method:'POST', body:fd });
      const data= await res.json();
      if(!data.success){ toast(data.error||'Erro ao lançar', false); return; }
      toast('Despesa lançada com sucesso!');
      toggleDrawer('#drawerDespesa', false);
      const open = document.querySelector('.kr-card.open');
      if(open){ const id=open.getAttribute('data-id'); await loadKrDetail(id); await loadIniciativas(id); }
      $('#formDespesa').reset();
    });

    // Filtros
    $('#btnClearFilters')?.addEventListener('click', ()=> $('#chipsFilters').innerHTML='');

    // Ajuste com chat lateral
    const CHAT_SELECTORS  = ['#chatPanel','.chat-panel','.chat-container','#chat','.drawer-chat'];
    const TOGGLE_SELECTORS= ['#chatToggle','.chat-toggle','.btn-chat-toggle','.chat-icon','.chat-open'];
    function findChatEl(){ for(const s of CHAT_SELECTORS){ const el=document.querySelector(s); if(el) return el; } return null; }
    function isOpen(el){ const st=getComputedStyle(el); const vis=st.display!=='none'&&st.visibility!=='hidden'; const w=el.offsetWidth; return (vis&&w>0)||el.classList.contains('open')||el.classList.contains('show'); }
    function updateChatWidth(){ const el=findChatEl(); const w=(el && isOpen(el))?el.offsetWidth:0; document.documentElement.style.setProperty('--chat-w',(w||0)+'px'); }
    function setupChatObservers(){
      const chat=findChatEl(); if(!chat) return;
      const mo=new MutationObserver(()=>updateChatWidth());
      mo.observe(chat,{attributes:true,attributeFilter:['style','class','aria-expanded']});
      window.addEventListener('resize',updateChatWidth);
      TOGGLE_SELECTORS.forEach(s=>document.querySelectorAll(s).forEach(btn=>btn.addEventListener('click',()=>setTimeout(updateChatWidth,200))));
      updateChatWidth();
    }

    document.addEventListener('DOMContentLoaded', ()=>{
      loadKRs();
      setupChatObservers();
      const moBody = new MutationObserver(()=>{
        if(findChatEl()){ setupChatObservers(); moBody.disconnect(); }
      });
      moBody.observe(document.body,{childList:true,subtree:true});
    });

    // ID do objetivo disponível no PHP
      const OBJ_ID = <?= (int)$id_objetivo ?>;

      // Calcula médias e injeta/atualiza o chip no header do objetivo
      function injectObjectiveProgressChip(krs) {
        let sumAtual = 0, sumEsper = 0, nAtual = 0, nEsper = 0;

        (krs || []).forEach(kr => {
          const pa = kr?.progress?.pct_atual;
          const pe = kr?.progress?.pct_esperado;
          if (Number.isFinite(pa)) { sumAtual += Math.max(0, Math.min(100, pa)); nAtual++; }
          if (Number.isFinite(pe)) { sumEsper += Math.max(0, Math.min(100, pe)); nEsper++; }
        });

        const criticos = resp.krs.filter(k => k.farol_auto === 'vermelho').length;
        const risco    = resp.krs.filter(k => k.farol_auto === 'amarelo').length;

        const elTot  = document.getElementById('kpiTotalKrs');
        const elCri  = document.getElementById('kpiCriticos');
        const elRis  = document.getElementById('kpiRisco');

        // === Farol do objetivo: vermelho se existir KR vermelho; senão amarelo se existir KR amarelo; senão verde
        const objPill = document.getElementById('objFarolPill');
        const objLbl  = document.getElementById('objFarolLabel');

        if (objPill && objLbl) {
          // remove estados anteriores
          objPill.classList.remove('prog-ok','prog-warn','prog-bad','white');

          let cls = 'prog-ok';
          let txt = 'No trilho';
          if (criticos > 0) { cls = 'prog-bad';  txt = 'Crítico'; }
          else if (risco > 0) { cls = 'prog-warn'; txt = 'Atenção'; }

          objPill.classList.add(cls);
          objLbl.textContent = txt;
          objPill.title = `Farol do objetivo — KRs vermelhos: ${criticos}, amarelos: ${risco}`;
        }

        if (elTot) elTot.textContent = resp.krs.length;
        if (elCri) elCri.textContent = criticos;
        if (elRis) elRis.textContent = risco;

        const objPctAtual = nAtual ? Math.round(sumAtual / nAtual) : null;
        const objPctEsper = nEsper ? Math.round(sumEsper / nEsper) : null;
        const objOk = (objPctAtual !== null && objPctEsper !== null)
          ? (objPctAtual >= objPctEsper)
          : null;

        const metaBar = document.querySelector('.obj-meta-pills');
        if (!metaBar) return;

        const id = 'objProgChip';
        const cls = objOk === null ? 'white' : (objOk ? 'prog-ok' : 'prog-bad');
        const lblAtual = (objPctAtual === null) ? '—' : (objPctAtual + '%');
        const lblEsper = (objPctEsper === null) ? '—' : (objPctEsper + '%');
        const title    = `Esperado: ${lblEsper} · Atual: ${lblAtual}`;

        const html = `
          <span class="meta-pill ${cls}" id="${id}" title="${title}">
            <i class="fa-solid fa-chart-line"></i> Progresso do objetivo: ${lblAtual}
          </span>
        `;

        // Atualiza se já existir; senão, insere como PRIMEIRO chip do header
        const existing = document.getElementById(id);
        if (existing) {
          existing.classList.remove('prog-ok','prog-bad','white');
          existing.classList.add(cls);
          existing.title = title;
          existing.innerHTML = `<i class="fa-solid fa-chart-line"></i> Progresso do objetivo: ${lblAtual}`;
          if (metaBar.firstElementChild !== existing) metaBar.insertBefore(existing, metaBar.firstChild);
        } else {
          metaBar.insertAdjacentHTML('afterbegin', html);
        }
      }

      // Busca os KRs e aciona a função acima
      async function refreshObjectiveProgressFromKRs() {
        try {
          const url = `/OKR_system/views/detalhe_okr.php?ajax=load_krs&id_objetivo=${OBJ_ID}`;
          const resp = await fetch(url, { headers: { 'Accept': 'application/json' } });
          const data = await resp.json();
          if (data?.success && Array.isArray(data.krs)) {
            injectObjectiveProgressChip(data.krs);
          }
        } catch (e) {
          console.error('Falha ao atualizar chip de progresso do objetivo:', e);
        }
      }

      // Garante que o header já existe no DOM
      document.addEventListener('DOMContentLoaded', refreshObjectiveProgressFromKRs);
    </script>
    <script>
    // Recarrega a página sempre que QUALQUER modal (.modal) for fechado (perder a classe .show)
    (function () {
      function watchModals(root = document) {
        const modals = root.querySelectorAll('.modal');
        if (!modals.length) return;

        let reloading = false;
        const obs = new MutationObserver(muts => {
          if (reloading) return;
          for (const m of muts) {
            if (m.type !== 'attributes' || m.attributeName !== 'class') continue;
            const el = m.target;
            const hadShow = (m.oldValue || '').split(/\s+/).includes('show');
            const hasShow = el.classList.contains('show');
            // Foi de aberto (com .show) para fechado (sem .show)? Recarrega.
            if (hadShow && !hasShow) {
              reloading = true;
              setTimeout(() => location.reload(), 30); // pequeno delay para terminar animações/POSTs
              break;
            }
          }
        });

        modals.forEach(el => {
          obs.observe(el, { attributes: true, attributeFilter: ['class'], attributeOldValue: true });
        });
      }

      // Observa os modais já presentes
      watchModals();

      // (Opcional) Se você cria modais dinamicamente, observa novas inserções
      new MutationObserver(muList => {
        for (const mu of muList) {
          mu.addedNodes?.forEach(n => {
            if (n.nodeType === 1 && n.matches?.('.modal')) {
              watchModals(document);
            }
          });
        }
      }).observe(document.body, { childList: true, subtree: true });
    })();
    </script>

</body>
</html>