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
    $out = [];
    foreach ($rows as $r) {
      $nome = $r['responsavel_nome'] ?? null;
      if (!$nome && isset($r['responsavel_text'])) {
        $txt = trim((string)$r['responsavel_text']);
        if ($txt !== '') $nome = ctype_digit($txt) ? ($getUserNameById($pdo,(int)$txt) ?: $txt) : $txt;
      }
      if (!$nome && !empty($r['kr_user_id'] ?? null)) $nome = $getUserNameById($pdo, (int)$r['kr_user_id']);

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

    $cols = $pdo->query("SHOW COLUMNS FROM `$msTable`")->fetchAll(PDO::FETCH_ASSOC);
    $has = function($name) use($cols){ foreach($cols as $c){ if (strcasecmp($c['Field'],$name)===0) return true; } return false; };

    $dateCol = $has('data_ref') ? 'data_ref' : ($has('dt_prevista') ? 'dt_prevista' : ($has('data_prevista') ? 'data_prevista' : null));
    $expCol  = $has('valor_esperado') ? 'valor_esperado' : ($has('esperado') ? 'esperado' : null);
    $realCol = $has('valor_real') ? 'valor_real' : ($has('realizado') ? 'realizado' : null);
    $evidCol = $has('dt_evidencia') ? 'dt_evidencia' : ($has('data_evidencia') ? 'data_evidencia' : null);
    if (!$dateCol || !$expCol) { echo json_encode(['success'=>false,'error'=>'Colunas de milestones não encontradas (data/esperado)']); exit; }

    $sqlMs = "SELECT `$dateCol` AS data_prevista, `$expCol` AS valor_esperado";
    $sqlMs .= $realCol ? ", `$realCol` AS valor_real" : ", NULL AS valor_real";
    $sqlMs .= $evidCol ? ", `$evidCol` AS dt_evidencia" : ", NULL AS dt_evidencia";
    $sqlMs .= " FROM `$msTable` WHERE `id_kr` = :id ORDER BY `$dateCol` ASC";
    $stmM = $pdo->prepare($sqlMs);
    $stmM->execute(['id'=>$id_kr]);
    $milestones = $stmM->fetchAll();

    $labels=[]; $esp=[]; $real=[];
    foreach ($milestones as $m) {
      $labels[] = $m['data_prevista'];
      $esp[]    = (float)($m['valor_esperado'] ?? 0);
      $real[]   = isset($m['valor_real']) && $m['valor_real'] !== null ? (float)$m['valor_real'] : null;
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
      'chart'=>['labels'=>$labels,'esperado'=>$esp,'real'=>$real],
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
    .obj-title{ font-size:1.35rem; font-weight:900; margin:0 0 8px; letter-spacing:.2px; display:flex; align-items:center; gap:8px; }
    .obj-title i{ color:var(--gold); }
    .obj-meta-pills{ display:flex; flex-wrap:wrap; gap:8px; }
    .pill{ display:inline-flex; align-items:center; gap:8px; background:#0e131a; border:1px solid var(--border); color:#a6adbb; padding:6px 10px; border-radius:999px; font-size:.82rem; font-weight:700; }
    .pill i{ font-size:.9rem; opacity:.9; }
    .obj-actions{ display:flex; gap:10px; margin-top:12px; }
    .btn{ border:1px solid var(--border); background:var(--btn); color:#e5e7eb; padding:10px 14px; border-radius:12px; font-weight:700; cursor:pointer; }
    .btn:hover{ border-color:#2a3342; transform:translateY(-1px); transition:.15s; }
    .btn-primary{ background:#1f2937; }
    .btn-outline{ background:transparent; }
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
    .kr-title{ font-weight:800; display:flex; align-items:center; gap:8px; color: var(--gold); }
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
    .table-wrap{ overflow:auto; }
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

        <?php if ($g($objetivo,'observacoes','') !== '—'): ?>
        <div class="obj-meta-pills" style="margin-top:8px">
          <span class="pill" style="max-width:100%; white-space:normal;">
            <i class="fa-regular fa-note-sticky"></i><strong>Obs.:</strong>&nbsp;<?= nl2br(htmlspecialchars($objetivo['observacoes'])) ?>
          </span>
        </div>
        <?php endif; ?>

        <div class="obj-actions">
          <a class="btn btn-outline" href="/OKR_system/objetivos_editar.php?id=<?= (int)$objetivo['id_objetivo'] ?>"><i class="fa-regular fa-pen-to-square"></i>&nbsp;Editar</a>
          <a class="btn btn-outline" href="/OKR_system/views/novo_key_result.php?id_objetivo=<?= (int)$objetivo['id_objetivo'] ?>"><i class="fa-solid fa-plus"></i>&nbsp;Novo KR</a>
          <button class="btn btn-outline" onclick="window.print()"><i class="fa-regular fa-file-lines"></i>&nbsp;Exportar</button>
        </div>

        <div class="obj-dates">
          <span class="pill" title="Data de criação"><i class="fa-regular fa-calendar-plus"></i><?= htmlspecialchars($g($objetivo,'dt_criacao')) ?></span>
          <span class="pill" title="Prazo"><i class="fa-regular fa-calendar-days"></i><?= htmlspecialchars($g($objetivo,'dt_prazo')) ?></span>
          <span class="pill" title="Conclusão"><i class="fa-solid fa-flag-checkered"></i><?= htmlspecialchars($g($objetivo,'dt_conclusao')) ?></span>
          <span class="pill" title="Qualidade"><i class="fa-regular fa-gem"></i><?= htmlspecialchars($g($objetivo,'qualidade')) ?></span>
        </div>
      </section>

      <!-- KPIs -->
      <section class="kpi-grid">
        <div class="kpi">
          <div class="kpi-head"><span>KRs</span><div class="kpi-icon"><i class="fa-solid fa-list-check"></i></div></div>
          <div class="kpi-value"><?= (int)$kpi['total_krs'] ?></div>
          <div class="kpi-sub">Críticos: <strong><?= (int)$kpi['criticos'] ?></strong> · Em risco: <strong><?= (int)$kpi['em_risco'] ?></strong></div>
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

      <!-- Filtros -->
      <section class="filters">
        <span style="font-size:.88rem; color:#555;"><i class="fa-solid fa-filter"></i> Filtros:</span>
        <div id="chipsFilters" class="chips"></div>
        <button class="btn btn-outline" id="btnClearFilters" style="margin-left:auto"><i class="fa-solid fa-broom"></i>&nbsp;Limpar filtros</button>
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

      // 3) status (reaproveita dom_status_kr)
      try{
        const res  = await fetch(`${SCRIPT}?ajax=list_status_kr`);
        const data = await res.json();
        const sel  = $('#ni_status');
        sel.innerHTML = '';
        (data.items||[]).forEach(s=>{
          const opt = document.createElement('option');
          opt.value = s.id;
          opt.textContent = s.label || s.id;
          sel.appendChild(opt);
        });
        const pref = ['nao iniciado','em andamento'];
        const idx = (data.items||[]).findIndex(it =>
          pref.includes((it.id||'').toLowerCase()) || pref.includes((it.label||'').toLowerCase())
        );
        if (idx >= 0) sel.selectedIndex = idx;
      } catch(e){
        $('#ni_status').innerHTML = '<option value="">Falha ao carregar</option>';
      }

      updatePrevTotals();
      toggleDrawer('#drawerNovaIni', true);
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
      cont.innerHTML = '';
      data.krs.forEach(kr=>{
        const id = kr.id_kr;
        const isCancel = (kr.status || '').toLowerCase().includes('cancel');
        cont.insertAdjacentHTML('beforeend', `
          <article class="kr-card${isCancel ? ' cancelado' : ''}" data-id="${id}">
            <div class="kr-head">
              <div>
                <div class="kr-title"><i class="fa-solid fa-flag"></i> KR${kr.key_result_num ? ' ' + kr.key_result_num : ''}: ${escapeHtml(truncate(kr.descricao||'', 160))}</div>
                <div class="meta-line">
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
      if (btnAp){ toast('Conecte este botão ao fluxo de apontamentos.', false); return; }

      const btnDesp = e.target.closest('button[data-act="despesa"]');
      if (btnDesp){
        $('#desp_id_orcamento').value = btnDesp.getAttribute('data-id');
        toggleDrawer('#drawerDespesa', true);
        return;
      }

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
      const ctx = document.getElementById(`scurve_${id}`);
      if(ctx){
        if(charts[id]) charts[id].destroy();
        charts[id] = new Chart(ctx, {
          type: 'line',
          data: {
            labels: (data.chart.labels||[]).map(d => toDDMMYYYY(d,'/')),
            datasets: [{
              label: 'Esperado',
              data: data.chart.esperado || [],
              borderColor: '#f6c343',
              backgroundColor: 'rgba(246,195,67,0.12)',
              pointBackgroundColor: '#f6c343',
              pointBorderColor: '#f6c343',
              borderWidth: 2, pointRadius: 3, tension: 0.35, fill: false
            }]
          },
          options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
              legend: { display: false },
              title: { display: true, text: 'Curva "S" de progresso', color: '#eaeef6', font: { weight: 'bold', size: 14 } },
              tooltip: {
                callbacks: {
                  title: (items)=> items.length ? `Milestone: ${items[0].label}` : '',
                  label: (item)=> `Esperado: ${fmtNum(item.parsed.y)}`
                }
              }
            },
            scales: {
              x: { ticks: { color: '#a6adbb' }, grid: { color: 'rgba(255,255,255,0.06)' } },
              y: { beginAtZero: true, ticks: { color: '#a6adbb' }, grid: { color: 'rgba(255,255,255,0.06)' } }
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
        const actions = ini.orcamento?.id_orcamento
          ? `<button class="btn btn-outline btn-sm" data-act="despesa" data-id="${ini.orcamento.id_orcamento}"><i class="fa-solid fa-file-invoice-dollar"></i> Lançar despesa</button>`
          : `<span class="chip"><i class="fa-regular fa-circle"></i> Sem orçamento</span>`;
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
          type: 'bar',
          data: {
            labels,
            datasets: [
              { label:'Planejado', data: mensalPlan, backgroundColor:'rgba(246,195,67,0.35)', borderColor:'#f6c343', borderWidth:1 },
              { label:'Realizado', data: mensalReal, backgroundColor:'rgba(96,165,250,0.35)', borderColor:'#60a5fa', borderWidth:1 }
            ]
          },
          options: {
            responsive:true, maintainAspectRatio:false,
            plugins:{ legend:{ labels:{ color:'#cbd5e1' } } },
            scales:{
              x:{ ticks:{ color:'#a6adbb' }, grid:{ color:'rgba(255,255,255,.06)' } },
              y:{ ticks:{ color:'#a6adbb' }, grid:{ color:'rgba(255,255,255,.06)' }, beginAtZero:true }
            }
          }
        });

        // toggle mensal/acum
        const seg = document.querySelector(`.segmented[data-scope="${id}"]`);
        if (seg && !seg.dataset.bound){
          seg.dataset.bound = '1';
          seg.addEventListener('click', (e)=>{
            const b = e.target.closest('button[data-mode]'); if(!b) return;
            seg.querySelectorAll('button').forEach(x=>x.classList.remove('active'));
            b.classList.add('active');
            const mode = b.getAttribute('data-mode');
            const ds0 = orcCharts[id].data.datasets[0];
            const ds1 = orcCharts[id].data.datasets[1];
            if (mode==='acum'){
              orcCharts[id].config.type='line';
              ds0.data = acumPlan; ds1.data = acumReal;
            } else {
              orcCharts[id].config.type='bar';
              ds0.data = mensalPlan; ds1.data = mensalReal;
            }
            orcCharts[id].update();
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
  </script>
</body>
</html>
