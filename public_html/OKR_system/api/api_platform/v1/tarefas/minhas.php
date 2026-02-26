<?php
declare(strict_types=1);

/**
 * GET /minhas-tarefas
 * Returns the current user's KRs (as responsável) and iniciativas (as envolvido).
 */

$ctx = api_auth_context();
$uid = $ctx['uid'];
$cid = $ctx['cid'];
$pdo = api_db();

// KRs where user is responsável
$stKrs = $pdo->prepare("
    SELECT k.id_kr, k.descricao, k.status, k.farol, k.baseline, k.meta,
           k.unidade_medida, k.direcao_metrica, k.data_inicio, k.data_fim,
           o.descricao AS objetivo_descricao,
           (SELECT m.valor_real_consolidado
              FROM milestones_kr m
             WHERE m.id_kr = k.id_kr AND m.valor_real_consolidado IS NOT NULL
             ORDER BY m.data_ref DESC LIMIT 1
           ) AS ultimo_valor
      FROM key_results k
      JOIN objetivos o ON o.id_objetivo = k.id_objetivo
     WHERE k.responsavel = ?
       AND o.id_company = ?
     ORDER BY k.data_fim ASC
");
$stKrs->execute([$uid, $cid]);
$krsRaw = $stKrs->fetchAll(PDO::FETCH_ASSOC);

$krs = [];
foreach ($krsRaw as $kr) {
    $baseline = (float)($kr['baseline'] ?? 0);
    $meta     = (float)($kr['meta'] ?? 0);
    $ultimo   = $kr['ultimo_valor'] !== null ? (float)$kr['ultimo_valor'] : null;
    $range    = abs($meta - $baseline);
    $pct      = 0.0;
    if ($range > 0 && $ultimo !== null) {
        $pct = abs($ultimo - $baseline) / $range * 100;
    }
    $krs[] = [
        'id_kr'                => $kr['id_kr'],
        'descricao'            => $kr['descricao'],
        'status'               => $kr['status'],
        'farol'                => $kr['farol'] ?? '',
        'objetivo_descricao'   => $kr['objetivo_descricao'],
        'data_inicio'          => $kr['data_inicio'],
        'data_fim'             => $kr['data_fim'],
        'progresso_pct'        => round(min($pct, 100), 1),
    ];
}

// Iniciativas where user is envolvido (or main responsável)
$stIni = $pdo->prepare("
    SELECT DISTINCT i.id_iniciativa, i.descricao, i.status, i.dt_prazo, i.dt_criacao,
           k.descricao AS kr_descricao
      FROM iniciativas i
      JOIN key_results k ON k.id_kr = i.id_kr
      JOIN objetivos o ON o.id_objetivo = k.id_objetivo
      LEFT JOIN iniciativas_envolvidos ie ON ie.id_iniciativa = i.id_iniciativa
     WHERE (i.id_user_responsavel = ? OR ie.id_user = ?)
       AND o.id_company = ?
     ORDER BY
       CASE i.status
         WHEN 'Em Andamento' THEN 1
         WHEN 'Não Iniciado' THEN 2
         WHEN 'Concluído' THEN 3
         WHEN 'Cancelado' THEN 4
         ELSE 5
       END,
       i.dt_prazo ASC
");
$stIni->execute([$uid, $uid, $cid]);
$iniciativas = $stIni->fetchAll(PDO::FETCH_ASSOC);

api_json([
    'ok'           => true,
    'krs'          => $krs,
    'iniciativas'  => $iniciativas,
    'totals'       => [
        'krs'          => count($krs),
        'iniciativas'  => count($iniciativas),
    ],
]);
