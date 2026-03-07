<?php
declare(strict_types=1);

/**
 * GET /krs/:id_kr/iniciativas
 * Lista iniciativas de um KR com envolvidos e orçamento.
 */

$auth = api_require_auth();
$cid  = (int)($auth['cid'] ?? 0);
$idKr = api_param('id_kr');
$pdo  = api_db();

// Tenant
$st = $pdo->prepare("
  SELECT o.id_company FROM key_results kr
    JOIN objetivos o ON o.id_objetivo = kr.id_objetivo
   WHERE kr.id_kr = ?
");
$st->execute([$idKr]);
$co = $st->fetchColumn();
if ($co === false || (int)$co !== $cid) {
  api_error('E_NOT_FOUND', 'Key Result não encontrado.', 404);
}

$stI = $pdo->prepare("
  SELECT i.id_iniciativa, i.num_iniciativa, i.descricao, i.status,
         i.dt_prazo, i.dt_criacao, i.id_user_responsavel,
         u.primeiro_nome AS resp_nome, u.ultimo_nome AS resp_sobrenome
    FROM iniciativas i
    LEFT JOIN usuarios u ON u.id_user = i.id_user_responsavel
   WHERE i.id_kr = ?
   ORDER BY i.num_iniciativa
");
$stI->execute([$idKr]);
$inis = $stI->fetchAll();

if (empty($inis)) {
  api_json(['ok' => true, 'iniciativas' => []]);
}

// Batch fetch envolvidos
$iniIds = array_column($inis, 'id_iniciativa');
$inPh   = implode(',', array_fill(0, count($iniIds), '?'));

$stE = $pdo->prepare("
  SELECT ie.id_iniciativa, ie.id_user,
         u.primeiro_nome, u.ultimo_nome
    FROM iniciativas_envolvidos ie
    JOIN usuarios u ON u.id_user = ie.id_user
   WHERE ie.id_iniciativa IN ($inPh)
");
$stE->execute($iniIds);
$envolvidos = [];
foreach ($stE->fetchAll() as $e) {
  $envolvidos[$e['id_iniciativa']][] = [
    'id_user' => (int)$e['id_user'],
    'nome'    => trim($e['primeiro_nome'] . ' ' . ($e['ultimo_nome'] ?? '')),
  ];
}

// Batch fetch budget
$stO = $pdo->prepare("
  SELECT o.id_iniciativa, SUM(o.valor) AS aprovado,
         COALESCE((SELECT SUM(od.valor) FROM orcamentos_detalhes od WHERE od.id_orcamento = o.id_orcamento), 0) AS realizado
    FROM orcamentos o
   WHERE o.id_iniciativa IN ($inPh)
   GROUP BY o.id_iniciativa
");
$stO->execute($iniIds);
$orcs = [];
foreach ($stO->fetchAll() as $o) {
  $orcs[$o['id_iniciativa']] = [
    'aprovado'  => (float)$o['aprovado'],
    'realizado' => (float)$o['realizado'],
  ];
}

$result = array_map(function ($i) use ($envolvidos, $orcs) {
  $id   = $i['id_iniciativa'];
  $orc  = $orcs[$id] ?? null;
  return [
    'id_iniciativa'   => $id,
    'num_iniciativa'  => (int)$i['num_iniciativa'],
    'descricao'       => $i['descricao'],
    'status'          => $i['status'],
    'dt_prazo'        => $i['dt_prazo'],
    'dt_criacao'      => $i['dt_criacao'],
    'responsavel'     => $i['id_user_responsavel'] ? [
      'id_user' => (int)$i['id_user_responsavel'],
      'nome'    => trim(($i['resp_nome'] ?? '') . ' ' . ($i['resp_sobrenome'] ?? '')),
    ] : null,
    'envolvidos'      => $envolvidos[$id] ?? [],
    'orcamento'       => $orc ? [
      'aprovado'  => $orc['aprovado'],
      'realizado' => $orc['realizado'],
      'saldo'     => $orc['aprovado'] - $orc['realizado'],
    ] : null,
  ];
}, $inis);

api_json(['ok' => true, 'iniciativas' => $result]);
