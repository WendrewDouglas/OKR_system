<?php
declare(strict_types=1);

/**
 * Migration 009 — Tabela `kr_comentarios` (aba "Log & Discussões" do detalhe do OKR)
 * 2026-09-15
 *
 * Guarda os comentários digitados na aba e os registros automáticos de status do KR
 * (views/detalhe_okr.php → $addKrComment, que já procurava por esta tabela e, sem ela,
 * concatenava o texto em key_results.observacoes).
 *
 *   - tipo              'comentario' (digitado) | 'sistema' (gravado pelo próprio sistema)
 *   - dt_exclusao       exclusão lógica: o comentário some da tela, a linha fica para auditoria
 *
 * O id_kr herda charset/collation de key_results.id_kr, exigência da FK.
 * FK para key_results com ON UPDATE/DELETE CASCADE, igual às demais filhas do KR.
 *
 * Idempotente: pode rodar várias vezes com segurança.
 *
 * Uso (CLI):
 *   php tools/migrations/009_kr_comentarios.php
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
require_once $config;

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$existe = $pdo->query("SHOW TABLES LIKE 'kr_comentarios'")->fetchColumn();
if ($existe) {
    echo "kr_comentarios já existe. Nada a fazer.\n";
    exit(0);
}

$col = $pdo->query("
    SELECT CHARACTER_SET_NAME, COLLATION_NAME
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'key_results' AND COLUMN_NAME = 'id_kr'
")->fetch();
if (!$col) {
    fwrite(STDERR, "key_results.id_kr não encontrado.\n");
    exit(1);
}
$charset   = preg_replace('/[^a-z0-9_]/i', '', (string)$col['CHARACTER_SET_NAME']);
$collation = preg_replace('/[^a-z0-9_]/i', '', (string)$col['COLLATION_NAME']);

$pdo->exec("
    CREATE TABLE `kr_comentarios` (
      `id_comentario`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `id_kr`            VARCHAR(50) CHARACTER SET {$charset} COLLATE {$collation} NOT NULL,
      `id_user`          INT NULL,
      `tipo`             VARCHAR(20) NOT NULL DEFAULT 'comentario' COMMENT 'comentario | sistema',
      `texto`            TEXT NOT NULL,
      `dt_criacao`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `dt_exclusao`      DATETIME NULL,
      `id_user_exclusao` INT NULL,
      PRIMARY KEY (`id_comentario`),
      KEY `idx_krcom_kr_dt` (`id_kr`, `dt_criacao`),
      KEY `idx_krcom_user` (`id_user`),
      CONSTRAINT `fk_krcom_kr`   FOREIGN KEY (`id_kr`)   REFERENCES `key_results` (`id_kr`) ON DELETE CASCADE ON UPDATE CASCADE,
      CONSTRAINT `fk_krcom_user` FOREIGN KEY (`id_user`) REFERENCES `usuarios` (`id_user`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

echo "kr_comentarios criada (id_kr {$charset}/{$collation}).\n";
