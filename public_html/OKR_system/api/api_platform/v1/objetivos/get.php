<?php
declare(strict_types=1);

/**
 * GET /objetivos/:id
 * Retorna um objetivo com detalhes.
 */

$auth = api_require_auth();
$cid  = (int)($auth['cid'] ?? 0);
$id   = api_param('id');

$pdo = api_db();
$st = $pdo->prepare("
  SELECT o.*, u.primeiro_nome AS dono_nome, u.ultimo_nome AS dono_sobrenome,
         p.descricao_exibicao AS pilar_nome,
         (SELECT COUNT(*) FROM key_results kr WHERE kr.id_objetivo = o.id_objetivo) AS qtd_krs
    FROM objetivos o
    LEFT JOIN usuarios u ON u.id_user = o.dono
    LEFT JOIN dom_pilar_bsc p ON p.id_pilar = o.pilar_bsc
   WHERE o.id_objetivo = ? AND o.id_company = ?
");
$st->execute([$id, $cid]);
$obj = $st->fetch();

if (!$obj) {
  api_error('E_NOT_FOUND', 'Objetivo não encontrado.', 404);
}

api_json([
  'ok'       => true,
  'objetivo' => [
    'id_objetivo'      => $obj['id_objetivo'],
    'descricao'        => $obj['descricao'],
    'status'           => $obj['status'],
    'status_aprovacao' => $obj['status_aprovacao'],
    'pilar_bsc'        => $obj['pilar_bsc'],
    'pilar_nome'       => $obj['pilar_nome'],
    'tipo'             => $obj['tipo'],
    'tipo_ciclo'       => $obj['tipo_ciclo'],
    'qualidade'        => $obj['qualidade'],
    'observacoes'      => $obj['observacoes'] ?? '',
    'dt_criacao'       => $obj['dt_criacao'],
    'dt_inicio'        => $obj['dt_inicio'] ?? null,
    'dt_prazo'         => $obj['dt_prazo'],
    'dt_conclusao'     => $obj['dt_conclusao'],
    'qtd_krs'          => (int)$obj['qtd_krs'],
    'dono' => [
      'id_user' => (int)$obj['dono'],
      'nome'    => trim(($obj['dono_nome'] ?? '') . ' ' . ($obj['dono_sobrenome'] ?? '')),
    ],
  ],
]);
