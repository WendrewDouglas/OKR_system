<?php
declare(strict_types=1);

/**
 * GET /aprovacoes
 * Lista itens para aprovação do usuário + itens pendentes do próprio usuário.
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);
$cid  = (int)($auth['cid'] ?? 0);
$pdo  = api_db();

$isMaster = api_is_admin_master($pdo, $uid);

// Check if user is approver
$stAp = $pdo->prepare("SELECT tudo, habilitado FROM aprovadores WHERE id_user = ?");
$stAp->execute([$uid]);
$aprovador = $stAp->fetch();
$isAprovador = $aprovador && (int)$aprovador['habilitado'] === 1;
$aprovaTudo  = $aprovador && (int)$aprovador['tudo'] === 1;

// Allowed modules
$allowedModules = [];
if ($isAprovador) {
  if ($aprovaTudo || $isMaster) {
    $allowedModules = ['objetivo', 'kr', 'orcamento'];
  } else {
    $stPerm = $pdo->prepare("SELECT tipo_estrutura FROM permissoes_aprovador WHERE id_user = ?");
    $stPerm->execute([$uid]);
    $allowedModules = $stPerm->fetchAll(\PDO::FETCH_COLUMN);
  }
}

$paraAprovar = [];
$minhas      = [];

// Items pending approval (for the approver)
if (!empty($allowedModules)) {
  if (in_array('objetivo', $allowedModules)) {
    $where = $isMaster ? "1=1" : "o.id_company = ?";
    $params = $isMaster ? [] : [$cid];
    $stObj = $pdo->prepare("
      SELECT 'objetivo' AS modulo, o.id_objetivo AS id_ref, o.descricao,
             o.status_aprovacao, o.dt_criacao,
             u.primeiro_nome AS criador_nome
        FROM objetivos o
        LEFT JOIN usuarios u ON u.id_user = o.id_user_criador
       WHERE o.status_aprovacao = 'pendente' AND $where
       ORDER BY o.dt_criacao DESC
    ");
    $stObj->execute($params);
    $paraAprovar = array_merge($paraAprovar, $stObj->fetchAll());
  }

  if (in_array('kr', $allowedModules)) {
    $where = $isMaster ? "1=1" : "o.id_company = ?";
    $params = $isMaster ? [] : [$cid];
    $stKr = $pdo->prepare("
      SELECT 'kr' AS modulo, kr.id_kr AS id_ref, kr.descricao,
             kr.status_aprovacao, kr.dt_ultima_atualizacao AS dt_criacao,
             u.primeiro_nome AS criador_nome
        FROM key_results kr
        JOIN objetivos o ON o.id_objetivo = kr.id_objetivo
        LEFT JOIN usuarios u ON u.id_user = kr.id_user_criador
       WHERE kr.status_aprovacao = 'pendente' AND $where
       ORDER BY kr.dt_ultima_atualizacao DESC
    ");
    $stKr->execute($params);
    $paraAprovar = array_merge($paraAprovar, $stKr->fetchAll());
  }

  if (in_array('orcamento', $allowedModules)) {
    $where = $isMaster ? "1=1" : "o.id_company = ?";
    $params = $isMaster ? [] : [$cid];
    $stOrc = $pdo->prepare("
      SELECT 'orcamento' AS modulo, orc.id_orcamento AS id_ref,
             CONCAT('R$ ', FORMAT(orc.valor, 2, 'pt_BR'), ' - ', i.descricao) AS descricao,
             orc.status_aprovacao, orc.dt_criacao,
             u.primeiro_nome AS criador_nome
        FROM orcamentos orc
        JOIN iniciativas i ON i.id_iniciativa = orc.id_iniciativa
        JOIN key_results kr ON kr.id_kr = i.id_kr
        JOIN objetivos o ON o.id_objetivo = kr.id_objetivo
        LEFT JOIN usuarios u ON u.id_user = orc.id_user_criador
       WHERE orc.status_aprovacao = 'pendente' AND $where
       ORDER BY orc.dt_criacao DESC
    ");
    $stOrc->execute($params);
    $paraAprovar = array_merge($paraAprovar, $stOrc->fetchAll());
  }
}

// My pending items
$stMinhasObj = $pdo->prepare("
  SELECT 'objetivo' AS modulo, id_objetivo AS id_ref, descricao,
         status_aprovacao, dt_criacao
    FROM objetivos WHERE id_user_criador = ? AND status_aprovacao IN ('pendente','reprovado')
");
$stMinhasObj->execute([$uid]);
$minhas = array_merge($minhas, $stMinhasObj->fetchAll());

$stMinhasKr = $pdo->prepare("
  SELECT 'kr' AS modulo, id_kr AS id_ref, descricao,
         status_aprovacao, dt_ultima_atualizacao AS dt_criacao
    FROM key_results WHERE id_user_criador = ? AND status_aprovacao IN ('pendente','reprovado')
");
$stMinhasKr->execute([$uid]);
$minhas = array_merge($minhas, $stMinhasKr->fetchAll());

// Stats
$pendentes  = count(array_filter($paraAprovar, fn($r) => $r['status_aprovacao'] === 'pendente'));
$reprovados = count(array_filter($minhas, fn($r) => $r['status_aprovacao'] === 'reprovado'));

api_json([
  'ok'    => true,
  'stats' => [
    'pendentes'  => $pendentes,
    'reprovados' => $reprovados,
  ],
  'para_aprovar'  => array_map(fn($r) => [
    'modulo'          => $r['modulo'],
    'id_ref'          => $r['id_ref'],
    'descricao'       => $r['descricao'],
    'status_aprovacao' => $r['status_aprovacao'],
    'dt_criacao'      => $r['dt_criacao'],
    'criador'         => $r['criador_nome'] ?? '',
  ], $paraAprovar),
  'minhas_pendentes' => array_map(fn($r) => [
    'modulo'          => $r['modulo'],
    'id_ref'          => $r['id_ref'],
    'descricao'       => $r['descricao'],
    'status_aprovacao' => $r['status_aprovacao'],
    'dt_criacao'      => $r['dt_criacao'],
  ], $minhas),
]);
