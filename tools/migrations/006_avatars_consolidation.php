<?php
declare(strict_types=1);

/**
 * Migration 006 — Consolidação do sistema de avatares (Fase 1)
 * 2026-06-18
 *
 * Estende a tabela `avatars` para ser o CATÁLOGO ÚNICO de avatares
 * (padrões da galeria + uploads dos usuários), sem alterar nenhuma tela.
 *
 * Colunas adicionadas (todas aditivas / não destrutivas):
 *   - kind          enum('default','custom')  -> origem do avatar
 *   - owner_user_id int(11) NULL              -> dono (NULL para padrões da galeria)
 *   - path          varchar(255) NULL         -> caminho relativo a assets/img/avatars/
 *                                                (ex.: 'default_avatar/user1.png', 'gallery/av_042.svg',
 *                                                 'custom/123/<hash>_256.webp')
 *   - format        enum('svg','png','jpg','jpeg','webp')
 *   - tags          JSON NULL                 -> filtros do picker (etnia, óculos, barba, etc.)
 *   - updated_at    timestamp
 *
 * Backfill: para as linhas legadas (galeria atual em default_avatar/), preenche
 * `path` = CONCAT('default_avatar/', filename) para que o resolvedor único
 * (auth/avatar_helpers.php) já funcione com o novo esquema.
 *
 * Idempotente: pode rodar várias vezes com segurança.
 *
 * Uso (CLI, na raiz do projeto ou em qualquer lugar):
 *   php tools/migrations/006_avatars_consolidation.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$projectRoot = dirname(__DIR__, 2);
$config = $projectRoot . '/auth/config.php';
if (!is_file($config)) {
    fwrite(STDERR, "Config não encontrado: {$config}\n");
    exit(1);
}
require $config;

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (Throwable $e) {
    fwrite(STDERR, "Falha ao conectar: " . $e->getMessage() . "\n");
    exit(1);
}

/* ===== Helpers de introspecção (idempotência) ===== */
function colExists(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $st->execute([$table, $col]);
    return (bool) $st->fetchColumn();
}
function idxExists(PDO $pdo, string $table, string $idx): bool {
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?"
    );
    $st->execute([$table, $idx]);
    return (bool) $st->fetchColumn();
}
function fkExists(PDO $pdo, string $table, string $fk): bool {
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
            AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    $st->execute([$table, $fk]);
    return (bool) $st->fetchColumn();
}

$changes = [];

/* ===== 0. PK auto-incremento ===== */
// O catálogo cresce com os uploads dos usuários; garante AUTO_INCREMENT no id.
$idExtra = (string) $pdo->query(
    "SELECT EXTRA FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'avatars' AND COLUMN_NAME = 'id'"
)->fetchColumn();
if (stripos($idExtra, 'auto_increment') === false) {
    // O id é referenciado pela FK usuarios.avatar_id; desliga a checagem só para
    // o MODIFY (o tipo não muda, então a integridade do FK é preservada).
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    try {
        $pdo->exec("ALTER TABLE `avatars` MODIFY `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT");
        $changes[] = '+auto_increment(id)';
    } finally {
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    }
}

/* ===== 1. Colunas ===== */
if (!colExists($pdo, 'avatars', 'kind')) {
    $pdo->exec("ALTER TABLE `avatars`
        ADD COLUMN `kind` ENUM('default','custom') NOT NULL DEFAULT 'default' AFTER `id`");
    $changes[] = '+kind';
}
if (!colExists($pdo, 'avatars', 'owner_user_id')) {
    $pdo->exec("ALTER TABLE `avatars`
        ADD COLUMN `owner_user_id` INT(11) NULL DEFAULT NULL AFTER `kind`");
    $changes[] = '+owner_user_id';
}
if (!colExists($pdo, 'avatars', 'path')) {
    $pdo->exec("ALTER TABLE `avatars`
        ADD COLUMN `path` VARCHAR(255) NULL DEFAULT NULL AFTER `filename`");
    $changes[] = '+path';
}
if (!colExists($pdo, 'avatars', 'format')) {
    $pdo->exec("ALTER TABLE `avatars`
        ADD COLUMN `format` ENUM('svg','png','jpg','jpeg','webp') NOT NULL DEFAULT 'png' AFTER `path`");
    $changes[] = '+format';
}
if (!colExists($pdo, 'avatars', 'tags')) {
    $pdo->exec("ALTER TABLE `avatars`
        ADD COLUMN `tags` JSON NULL DEFAULT NULL AFTER `gender`");
    $changes[] = '+tags';
}
if (!colExists($pdo, 'avatars', 'updated_at')) {
    $pdo->exec("ALTER TABLE `avatars`
        ADD COLUMN `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`");
    $changes[] = '+updated_at';
}

/* ===== 2. Backfill da galeria legada ===== */
$n = $pdo->exec(
    "UPDATE `avatars`
        SET `path` = CONCAT('default_avatar/', `filename`)
      WHERE (`path` IS NULL OR `path` = '')
        AND `filename` IS NOT NULL AND `filename` <> ''"
);
if ($n > 0) {
    $changes[] = "backfill path x{$n}";
}
// format coerente com a extensão (galeria legada é toda PNG)
$pdo->exec("UPDATE `avatars` SET `format` = 'svg'  WHERE `path` LIKE '%.svg'");
$pdo->exec("UPDATE `avatars` SET `format` = 'webp' WHERE `path` LIKE '%.webp'");
$pdo->exec("UPDATE `avatars` SET `format` = 'jpg'  WHERE `path` LIKE '%.jpg' OR `path` LIKE '%.jpeg'");
$pdo->exec("UPDATE `avatars` SET `format` = 'png'  WHERE `path` LIKE '%.png'");

/* ===== 3. Índices ===== */
if (!idxExists($pdo, 'avatars', 'idx_avatars_kind_active')) {
    $pdo->exec("ALTER TABLE `avatars` ADD INDEX `idx_avatars_kind_active` (`kind`, `active`)");
    $changes[] = '+idx kind_active';
}
if (!idxExists($pdo, 'avatars', 'idx_avatars_owner')) {
    $pdo->exec("ALTER TABLE `avatars` ADD INDEX `idx_avatars_owner` (`owner_user_id`)");
    $changes[] = '+idx owner';
}
if (!idxExists($pdo, 'avatars', 'idx_avatars_gender_active')) {
    $pdo->exec("ALTER TABLE `avatars` ADD INDEX `idx_avatars_gender_active` (`gender`, `active`)");
    $changes[] = '+idx gender_active';
}

/* ===== 4. FK owner_user_id -> usuarios(id_user) ===== */
if (!fkExists($pdo, 'avatars', 'fk_avatars_owner')) {
    try {
        $pdo->exec(
            "ALTER TABLE `avatars`
               ADD CONSTRAINT `fk_avatars_owner`
               FOREIGN KEY (`owner_user_id`) REFERENCES `usuarios`(`id_user`)
               ON DELETE CASCADE ON UPDATE CASCADE"
        );
        $changes[] = '+fk owner';
    } catch (Throwable $e) {
        // Não aborta a migration: a relação também é garantida na camada de app.
        fwrite(STDERR, "AVISO: FK fk_avatars_owner não criada: " . $e->getMessage() . "\n");
    }
}

/* ===== Relatório ===== */
echo "AVATARS_MIGRATION_OK changes=[" . implode(', ', $changes ?: ['nenhuma (já aplicada)']) . "]\n";
echo "Estrutura final de `avatars`:\n";
foreach ($pdo->query("SHOW COLUMNS FROM `avatars`") as $c) {
    echo "  " . str_pad($c['Field'], 16) . " | " . $c['Type']
       . ($c['Null'] === 'NO' ? ' NOT NULL' : '')
       . ($c['Default'] !== null ? " DEFAULT '" . $c['Default'] . "'" : '')
       . "\n";
}
