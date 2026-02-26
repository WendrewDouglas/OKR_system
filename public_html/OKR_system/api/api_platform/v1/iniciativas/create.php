<?php
declare(strict_types=1);

/**
 * POST /iniciativas
 * Cria uma iniciativa vinculada a um KR.
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);
$cid  = (int)($auth['cid'] ?? 0);
$pdo  = api_db();

$in = api_input();
api_require_fields($in, ['id_kr', 'descricao']);

$idKr    = api_str($in['id_kr']);
$desc    = api_str($in['descricao']);
$status  = api_str($in['status'] ?? 'Não Iniciado');
$dtPrazo = api_date_or_null($in['dt_prazo'] ?? null);
$respId  = api_int_or_null($in['id_user_responsavel'] ?? null);

// Multi-responsável
$responsaveis = [];
if (!empty($in['responsaveis'])) {
  $responsaveis = is_array($in['responsaveis']) ? $in['responsaveis'] : json_decode((string)$in['responsaveis'], true);
  if (!is_array($responsaveis)) $responsaveis = [];
  $responsaveis = array_map('intval', $responsaveis);
}
if (empty($responsaveis) && $respId) {
  $responsaveis = [$respId];
}
if (!empty($responsaveis) && !$respId) {
  $respId = $responsaveis[0];
}

// Tenant check
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

// Anti-duplicate (same KR+desc within 30s)
$stDup = $pdo->prepare("
  SELECT id_iniciativa FROM iniciativas
   WHERE id_kr = ? AND descricao = ? AND dt_criacao > DATE_SUB(NOW(), INTERVAL 30 SECOND)
   LIMIT 1
");
$stDup->execute([$idKr, $desc]);
if ($stDup->fetch()) {
  api_error('E_CONFLICT', 'Iniciativa duplicada (mesma descrição nos últimos 30s).', 409);
}

$pdo->beginTransaction();
try {
  // Sequential number
  $stN = $pdo->prepare("SELECT COALESCE(MAX(num_iniciativa), 0) + 1 FROM iniciativas WHERE id_kr = ? FOR UPDATE");
  $stN->execute([$idKr]);
  $num = (int)$stN->fetchColumn();

  $idIni = bin2hex(random_bytes(12));

  $pdo->prepare("
    INSERT INTO iniciativas
      (id_iniciativa, id_kr, num_iniciativa, descricao, status,
       id_user_responsavel, dt_prazo, dt_criacao, id_user_criador)
    VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), ?)
  ")->execute([$idIni, $idKr, $num, $desc, $status, $respId, $dtPrazo, $uid]);

  // Sync envolvidos
  if (!empty($responsaveis)) {
    $stIns = $pdo->prepare("INSERT INTO iniciativas_envolvidos (id_iniciativa, id_user, dt_inclusao) VALUES (?, ?, NOW())");
    foreach ($responsaveis as $rId) {
      $stIns->execute([$idIni, $rId]);
    }
  }

  // Budget
  $orcParcelas = 0;
  if (!empty($in['incluir_orcamento']) && !empty($in['valor_orcamento'])) {
    $valorTotal = (float)$in['valor_orcamento'];
    $justificativa = api_str($in['justificativa_orcamento'] ?? '');
    $desembolsos = $in['desembolsos'] ?? [];
    if (is_string($desembolsos)) $desembolsos = json_decode($desembolsos, true) ?: [];

    if (!empty($desembolsos)) {
      $stOrc = $pdo->prepare("
        INSERT INTO orcamentos (id_iniciativa, valor, data_desembolso, status_aprovacao,
                                justificativa_orcamento, id_user_criador, dt_criacao)
        VALUES (?, ?, ?, 'pendente', ?, ?, NOW())
      ");
      foreach ($desembolsos as $d) {
        $comp = ($d['competencia'] ?? '') . '-01';
        $val  = (float)($d['valor'] ?? 0);
        if ($val > 0) {
          $stOrc->execute([$idIni, $val, $comp, $justificativa, $uid]);
          $orcParcelas++;
        }
      }
    } elseif ($valorTotal > 0) {
      $pdo->prepare("
        INSERT INTO orcamentos (id_iniciativa, valor, status_aprovacao,
                                justificativa_orcamento, id_user_criador, dt_criacao)
        VALUES (?, ?, 'pendente', ?, ?, NOW())
      ")->execute([$idIni, $valorTotal, $justificativa, $uid]);
      $orcParcelas = 1;
    }
  }

  $pdo->commit();
} catch (\Throwable $e) {
  $pdo->rollBack();
  throw $e;
}

api_json([
  'ok'            => true,
  'id_iniciativa' => $idIni,
  'num_iniciativa' => $num,
  'orc_parcelas'  => $orcParcelas,
  'message'       => 'Iniciativa criada com sucesso.',
], 201);
