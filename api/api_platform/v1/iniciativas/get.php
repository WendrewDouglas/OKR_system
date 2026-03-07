<?php
declare(strict_types=1);

/**
 * GET /iniciativas/:id
 */

$auth = api_require_auth();
$cid  = (int)($auth['cid'] ?? 0);
$id   = api_param('id');
$pdo  = api_db();

$st = $pdo->prepare("
  SELECT i.*, o.id_company,
         u.primeiro_nome AS resp_nome, u.ultimo_nome AS resp_sobrenome,
         uc.primeiro_nome AS criador_nome
    FROM iniciativas i
    JOIN key_results kr ON kr.id_kr = i.id_kr
    JOIN objetivos o ON o.id_objetivo = kr.id_objetivo
    LEFT JOIN usuarios u ON u.id_user = i.id_user_responsavel
    LEFT JOIN usuarios uc ON uc.id_user = i.id_user_criador
   WHERE i.id_iniciativa = ?
");
$st->execute([$id]);
$ini = $st->fetch();

if (!$ini || (int)$ini['id_company'] !== $cid) {
  api_error('E_NOT_FOUND', 'Iniciativa não encontrada.', 404);
}

// Envolvidos
$stE = $pdo->prepare("
  SELECT ie.id_user, u.primeiro_nome, u.ultimo_nome
    FROM iniciativas_envolvidos ie
    JOIN usuarios u ON u.id_user = ie.id_user
   WHERE ie.id_iniciativa = ?
");
$stE->execute([$id]);
$envolvidos = array_map(fn($e) => [
  'id_user' => (int)$e['id_user'],
  'nome'    => trim($e['primeiro_nome'] . ' ' . ($e['ultimo_nome'] ?? '')),
], $stE->fetchAll());

// Budget
$stO = $pdo->prepare("
  SELECT id_orcamento, valor, data_desembolso, status_aprovacao, justificativa_orcamento
    FROM orcamentos WHERE id_iniciativa = ? ORDER BY data_desembolso
");
$stO->execute([$id]);
$orcamentos = $stO->fetchAll();

api_json([
  'ok'        => true,
  'iniciativa' => [
    'id_iniciativa'      => $ini['id_iniciativa'],
    'id_kr'              => $ini['id_kr'],
    'num_iniciativa'     => (int)$ini['num_iniciativa'],
    'descricao'          => $ini['descricao'],
    'status'             => $ini['status'],
    'dt_prazo'           => $ini['dt_prazo'],
    'dt_criacao'         => $ini['dt_criacao'],
    'observacoes'        => $ini['observacoes'] ?? '',
    'responsavel'        => $ini['id_user_responsavel'] ? [
      'id_user' => (int)$ini['id_user_responsavel'],
      'nome'    => trim(($ini['resp_nome'] ?? '') . ' ' . ($ini['resp_sobrenome'] ?? '')),
    ] : null,
    'criador'            => $ini['criador_nome'] ?? '',
    'envolvidos'         => $envolvidos,
    'orcamentos'         => array_map(fn($o) => [
      'id_orcamento'          => (int)$o['id_orcamento'],
      'valor'                 => (float)$o['valor'],
      'data_desembolso'       => $o['data_desembolso'],
      'status_aprovacao'      => $o['status_aprovacao'],
      'justificativa'         => $o['justificativa_orcamento'],
    ], $orcamentos),
  ],
]);
