<?php
declare(strict_types=1);

/**
 * GET /dashboard/summary
 * Requer Bearer token.
 */

$auth = api_require_auth();
$cid  = (int)($auth['cid'] ?? 0);

if ($cid <= 0) {
  api_error('E_AUTH', 'Company inválida no token.', 401);
}

$pdo = api_db();

/**
 * Status considerados "concluídos" (fallback por texto).
 * (Depois podemos trocar para dom_status_kr se quiser.)
 */
$DONE_STATUS_SQL = "('Concluído','Concluido','Completo','Finalizado')";

/** ===================== TOTAIS ===================== */
/**
 * IMPORTANTE:
 * - usamos "?" porque PDO com emulate_prepares=false pode falhar com :cid repetido
 * - portanto passamos $cid 4 vezes no execute
 */
$sqlTotals = <<<SQL
SELECT
  (SELECT COUNT(*)
     FROM objetivos o
    WHERE o.id_company = ?
  ) AS total_obj,

  (SELECT COUNT(*)
     FROM key_results kr
     JOIN objetivos o ON o.id_objetivo = kr.id_objetivo
    WHERE o.id_company = ?
  ) AS total_kr,

  (SELECT COUNT(*)
     FROM key_results kr
     JOIN objetivos o ON o.id_objetivo = kr.id_objetivo
    WHERE o.id_company = ?
      AND (
        kr.dt_conclusao IS NOT NULL
        OR kr.status IN $DONE_STATUS_SQL
      )
  ) AS total_kr_done,

  (SELECT COUNT(*)
     FROM key_results kr
     JOIN objetivos o ON o.id_objetivo = kr.id_objetivo
     LEFT JOIN milestones_kr m
       ON m.id_kr = kr.id_kr
      AND m.data_ref = (
        SELECT MAX(m2.data_ref)
          FROM milestones_kr m2
         WHERE m2.id_kr = kr.id_kr
           AND m2.data_ref <= CURDATE()
      )
    WHERE o.id_company = ?
      AND kr.dt_conclusao IS NULL
      AND (kr.status IS NULL OR kr.status NOT IN $DONE_STATUS_SQL)
      AND m.id_milestone IS NOT NULL
      AND m.valor_real_consolidado IS NOT NULL
      AND (
        (
          COALESCE(UPPER(kr.direcao_metrica),'MAIOR_MELHOR') IN ('MAIOR_MELHOR','MAIOR')
          AND m.valor_esperado IS NOT NULL
          AND m.valor_real_consolidado < m.valor_esperado
        )
        OR
        (
          COALESCE(UPPER(kr.direcao_metrica),'MAIOR_MELHOR') IN ('MENOR_MELHOR','MENOR')
          AND m.valor_esperado IS NOT NULL
          AND m.valor_real_consolidado > m.valor_esperado
        )
        OR
        (
          COALESCE(UPPER(kr.direcao_metrica),'MAIOR_MELHOR') IN ('INTERVALO_IDEAL','INTERVALO')
          AND m.valor_esperado_min IS NOT NULL
          AND m.valor_esperado_max IS NOT NULL
          AND (
            m.valor_real_consolidado < m.valor_esperado_min
            OR m.valor_real_consolidado > m.valor_esperado_max
          )
        )
      )
  ) AS total_kr_risk
SQL;

$st = $pdo->prepare($sqlTotals);
$st->execute([$cid, $cid, $cid, $cid]);
$tot = $st->fetch() ?: ['total_obj'=>0,'total_kr'=>0,'total_kr_done'=>0,'total_kr_risk'=>0];

/** ===================== PILARES ===================== */
/**
 * Aqui o cid aparece uma vez só, mas mantemos "?" por padrão.
 */
$sqlPilares = <<<SQL
SELECT
  p.id_pilar,
  p.descricao_exibicao AS pilar_nome,
  COUNT(DISTINCT o.id_objetivo) AS objetivos,
  COUNT(DISTINCT kr.id_kr) AS krs,

  SUM(CASE
        WHEN kr.id_kr IS NULL THEN 0
        WHEN (kr.dt_conclusao IS NOT NULL OR kr.status IN $DONE_STATUS_SQL) THEN 1
        ELSE 0
      END) AS krs_concluidos,

  SUM(CASE
        WHEN kr.id_kr IS NULL THEN 0
        WHEN kr.dt_conclusao IS NULL
         AND (kr.status IS NULL OR kr.status NOT IN $DONE_STATUS_SQL)
         AND m.id_milestone IS NOT NULL
         AND m.valor_real_consolidado IS NOT NULL
         AND (
           (
             COALESCE(UPPER(kr.direcao_metrica),'MAIOR_MELHOR') IN ('MAIOR_MELHOR','MAIOR')
             AND m.valor_esperado IS NOT NULL
             AND m.valor_real_consolidado < m.valor_esperado
           )
           OR
           (
             COALESCE(UPPER(kr.direcao_metrica),'MAIOR_MELHOR') IN ('MENOR_MELHOR','MENOR')
             AND m.valor_esperado IS NOT NULL
             AND m.valor_real_consolidado > m.valor_esperado
           )
           OR
           (
             COALESCE(UPPER(kr.direcao_metrica),'MAIOR_MELHOR') IN ('INTERVALO_IDEAL','INTERVALO')
             AND m.valor_esperado_min IS NOT NULL
             AND m.valor_esperado_max IS NOT NULL
             AND (
               m.valor_real_consolidado < m.valor_esperado_min
               OR m.valor_real_consolidado > m.valor_esperado_max
             )
           )
         )
        THEN 1
        ELSE 0
      END) AS krs_risco
FROM dom_pilar_bsc p
LEFT JOIN objetivos o
  ON o.pilar_bsc = p.id_pilar
 AND o.id_company = ?
LEFT JOIN key_results kr
  ON kr.id_objetivo = o.id_objetivo
LEFT JOIN milestones_kr m
  ON m.id_kr = kr.id_kr
 AND m.data_ref = (
    SELECT MAX(m2.data_ref)
      FROM milestones_kr m2
     WHERE m2.id_kr = kr.id_kr
       AND m2.data_ref <= CURDATE()
 )
GROUP BY p.id_pilar, p.descricao_exibicao, p.ordem_pilar
ORDER BY p.ordem_pilar ASC, p.id_pilar ASC
SQL;

$st = $pdo->prepare($sqlPilares);
$st->execute([$cid]);
$pilares = $st->fetchAll() ?: [];

api_json([
  'ok' => true,
  'totals' => [
    'objetivos'      => (int)$tot['total_obj'],
    'krs'            => (int)$tot['total_kr'],
    'krs_concluidos' => (int)$tot['total_kr_done'],
    'krs_risco'      => (int)$tot['total_kr_risk'],
  ],
  'pilares' => array_map(static function(array $r): array {
    return [
      'id_pilar'       => is_numeric($r['id_pilar']) ? (int)$r['id_pilar'] : (string)$r['id_pilar'],
      'pilar_nome'     => (string)$r['pilar_nome'],
      'objetivos'      => (int)$r['objetivos'],
      'krs'            => (int)$r['krs'],
      'krs_concluidos' => (int)$r['krs_concluidos'],
      'krs_risco'      => (int)$r['krs_risco'],
      'media_pct'      => null, // Etapa 2
    ];
  }, $pilares),
  'time' => date('c'),
]);
