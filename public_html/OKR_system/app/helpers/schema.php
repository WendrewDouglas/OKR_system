<?php
declare(strict_types=1);

function table_exists(PDO $pdo, string $table): bool {
  $st = $pdo->prepare("
    SELECT 1
      FROM information_schema.tables
     WHERE table_schema = DATABASE()
       AND table_name = :t
     LIMIT 1
  ");
  $st->execute([':t' => $table]);
  return (bool)$st->fetchColumn();
}

function column_exists(PDO $pdo, string $table, string $col): bool {
  $st = $pdo->prepare("
    SELECT 1
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = :t
       AND column_name = :c
     LIMIT 1
  ");
  $st->execute([':t' => $table, ':c' => $col]);
  return (bool)$st->fetchColumn();
}