<?php
declare(strict_types=1);

function milestones_by_krs(PDO $pdo, array $krs): array {
  $ids = [];
  foreach ($krs as $kr) if (!empty($kr['id_kr'])) $ids[] = (string)$kr['id_kr'];
  $ids = array_values(array_unique($ids));
  if (!$ids) return [];

  $in = implode(',', array_fill(0, count($ids), '?'));

  $st = $pdo->prepare("SELECT * FROM milestones_kr WHERE id_kr IN ($in) ORDER BY num_ordem ASC, data_ref ASC");
  $st->execute($ids);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $grouped = [];
  foreach ($rows as $r) {
    $key = (string)$r['id_kr'];
    $grouped[$key][] = $r;
  }
  return $grouped;
}