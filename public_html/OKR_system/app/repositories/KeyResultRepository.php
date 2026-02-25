<?php
declare(strict_types=1);

function kr_by_objetivo(PDO $pdo, int $id_objetivo): array {
  $st = $pdo->prepare("SELECT * FROM key_results WHERE id_objetivo = :id ORDER BY key_result_num ASC");
  $st->execute([':id' => $id_objetivo]);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}