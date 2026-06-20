<?php
declare(strict_types=1);

/**
 * GET /matriz-prioridade
 * Matriz de Eisenhower (urgência × importância) das INICIATIVAS da empresa.
 *
 * - Importância: `peso` do KR pai >= limiar (média dos pesos no escopo).
 * - Urgência: dt_prazo vencido ou <= 15 dias.
 * Considera apenas iniciativas acionáveis (exclui Concluído/Cancelado).
 * Escopo por empresa (tenant). Read-only.
 */

$ctx = api_auth_context();
$cid = (int)$ctx['cid'];
$pdo = api_db();

if ($cid <= 0) {
  api_error('E_AUTH', 'Company inválida.', 401);
}

$st = $pdo->prepare("
  SELECT i.id_iniciativa, i.descricao, i.status, i.dt_prazo,
         i.id_user_responsavel,
         u.primeiro_nome, u.ultimo_nome,
         ar.path AS resp_avatar_path, ar.filename AS resp_avatar_filename,
         k.id_kr, k.descricao AS kr_descricao, COALESCE(k.peso, 0) AS kr_peso
    FROM iniciativas i
    JOIN key_results k ON k.id_kr = i.id_kr
    JOIN objetivos   o ON o.id_objetivo = k.id_objetivo
    LEFT JOIN usuarios u ON u.id_user = i.id_user_responsavel
    LEFT JOIN avatars ar ON ar.id = u.avatar_id
   WHERE o.id_company = ?
     AND (i.status IS NULL OR i.status NOT IN ('Concluído', 'Concluido', 'Cancelado'))
   ORDER BY i.dt_prazo IS NULL, i.dt_prazo ASC
");
$st->execute([$cid]);
$rows = $st->fetchAll();

// Limiar de importância = média dos pesos dos KRs no escopo.
$pesos = array_map(static fn($r) => (float)$r['kr_peso'], $rows);
$threshold = count($pesos) > 0 ? array_sum($pesos) / count($pesos) : 0.0;

$today = new DateTimeImmutable('today');

$quad = ['fazer' => [], 'planejar' => [], 'delegar' => [], 'revisar' => []];

foreach ($rows as $r) {
  $peso = (float)$r['kr_peso'];
  $importante = $threshold > 0 && $peso >= $threshold;

  $dias = null;
  $urgente = false;
  $prazo = $r['dt_prazo'];
  if ($prazo) {
    $p = DateTimeImmutable::createFromFormat('Y-m-d', (string)$prazo);
    if ($p) {
      $dias = (int)$today->diff($p)->format('%r%a'); // negativo = vencido
      $urgente = $dias <= 15;
    }
  }

  $item = [
    'id_iniciativa'  => $r['id_iniciativa'],
    'descricao'      => $r['descricao'],
    'status'         => $r['status'],
    'dt_prazo'       => $prazo,
    'dias_para_prazo' => $dias,
    'importante'     => $importante,
    'urgente'        => $urgente,
    'kr'             => [
      'id_kr'     => $r['id_kr'],
      'descricao' => $r['kr_descricao'],
      'peso'      => $peso,
    ],
    'responsavel'    => $r['id_user_responsavel'] ? [
      'id_user'    => (int)$r['id_user_responsavel'],
      'nome'       => trim(($r['primeiro_nome'] ?? '') . ' ' . ($r['ultimo_nome'] ?? '')),
      'avatar_url' => api_avatar_url_from_row(['path' => $r['resp_avatar_path'] ?? null, 'filename' => $r['resp_avatar_filename'] ?? null]),
    ] : null,
  ];

  // Quadrantes de Eisenhower
  if ($importante && $urgente)        $quad['fazer'][]    = $item; // Fazer já
  elseif ($importante && !$urgente)   $quad['planejar'][] = $item; // Planejar
  elseif (!$importante && $urgente)   $quad['delegar'][]  = $item; // Delegar
  else                                $quad['revisar'][]  = $item; // Revisar/eliminar
}

api_ok([
  'threshold_peso' => round($threshold, 2),
  'quadrantes'     => $quad,
  'totais'         => [
    'fazer'    => count($quad['fazer']),
    'planejar' => count($quad['planejar']),
    'delegar'  => count($quad['delegar']),
    'revisar'  => count($quad['revisar']),
    'total'    => count($rows),
  ],
]);
