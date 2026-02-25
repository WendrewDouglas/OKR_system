<?php
declare(strict_types=1);

function objetivo_find(PDO $pdo, int $id): ?array {
  $st = $pdo->prepare("SELECT * FROM objetivos WHERE id_objetivo = :id LIMIT 1");
  $st->execute([':id' => $id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}