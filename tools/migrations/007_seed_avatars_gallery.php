<?php
declare(strict_types=1);

/**
 * Migration/Seeder 007 — Popula o catálogo `avatars` com a galeria SVG padrão
 * gerada offline por tools/avatar-generator/generate.mjs.
 * 2026-06-19
 *
 * Lê assets/img/avatars/gallery/manifest.json e faz UPSERT (idempotente) em
 * `avatars`, identificando cada item pelo `path` ('gallery/<arquivo>.svg').
 *
 * Cada linha inserida:
 *   kind          = 'default'
 *   owner_user_id = NULL
 *   path          = 'gallery/<file>'
 *   filename      = '<file>'           (coluna legada NOT NULL)
 *   format        = 'svg'
 *   gender        = manifest.gender    (masculino|feminino|todos)
 *   tags          = JSON manifest.tags
 *   active        = 1
 *
 * Esta etapa é ADITIVA: não altera nem desativa a galeria PNG antiga
 * (isso fica para a Fase 5 — migração/limpeza).
 *
 * Uso (CLI):
 *   php tools/migrations/007_seed_avatars_gallery.php          # aplica
 *   php tools/migrations/007_seed_avatars_gallery.php --dry    # simula
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$dry = in_array('--dry', $argv, true);

$projectRoot  = dirname(__DIR__, 2);
$config       = $projectRoot . '/auth/config.php';
$manifestPath = $projectRoot . '/assets/img/avatars/gallery/manifest.json';

if (!is_file($config)) {
    fwrite(STDERR, "Config não encontrado: {$config}\n");
    exit(1);
}
if (!is_file($manifestPath)) {
    fwrite(STDERR, "Manifest não encontrado: {$manifestPath}\n(rode tools/avatar-generator/generate.mjs antes)\n");
    exit(1);
}

require $config;

$manifest = json_decode((string) file_get_contents($manifestPath), true);
if (!is_array($manifest) || !$manifest) {
    fwrite(STDERR, "Manifest inválido ou vazio.\n");
    exit(1);
}

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

$allowedGender = ['masculino', 'feminino', 'todos'];

$selStmt = $pdo->prepare("SELECT id FROM `avatars` WHERE `path` = :path LIMIT 1");
$insStmt = $pdo->prepare(
    "INSERT INTO `avatars` (`kind`,`owner_user_id`,`filename`,`path`,`format`,`gender`,`tags`,`active`)
     VALUES ('default', NULL, :filename, :path, 'svg', :gender, :tags, 1)"
);
$updStmt = $pdo->prepare(
    "UPDATE `avatars`
        SET `kind`='default', `owner_user_id`=NULL, `filename`=:filename,
            `format`='svg', `gender`=:gender, `tags`=:tags, `active`=1
      WHERE `id`=:id"
);

$inserted = 0;
$updated  = 0;
$skipped  = 0;

if (!$dry) {
    $pdo->beginTransaction();
}

foreach ($manifest as $item) {
    $file   = (string) ($item['file'] ?? '');
    $gender = (string) ($item['gender'] ?? 'todos');
    $tags   = $item['tags'] ?? [];

    if ($file === '' || !preg_match('/^av_[mfn]_\d+\.svg$/', $file)) {
        $skipped++;
        continue;
    }
    if (!in_array($gender, $allowedGender, true)) {
        $gender = 'todos';
    }

    $path     = 'gallery/' . $file;
    $tagsJson = json_encode(array_values((array) $tags), JSON_UNESCAPED_UNICODE);

    $selStmt->execute([':path' => $path]);
    $existingId = $selStmt->fetchColumn();

    if ($dry) {
        $existingId === false ? $inserted++ : $updated++;
        continue;
    }

    if ($existingId === false) {
        $insStmt->execute([
            ':filename' => $file,
            ':path'     => $path,
            ':gender'   => $gender,
            ':tags'     => $tagsJson,
        ]);
        $inserted++;
    } else {
        $updStmt->execute([
            ':filename' => $file,
            ':gender'   => $gender,
            ':tags'     => $tagsJson,
            ':id'       => (int) $existingId,
        ]);
        $updated++;
    }
}

if (!$dry) {
    $pdo->commit();
}

$totalGallery = (int) $pdo->query(
    "SELECT COUNT(*) FROM `avatars` WHERE `kind`='default' AND `path` LIKE 'gallery/%'"
)->fetchColumn();

echo ($dry ? "[DRY-RUN] " : "") . "SEED_GALLERY_OK "
   . "inserted={$inserted} updated={$updated} skipped={$skipped} "
   . "total_gallery_svg={$totalGallery}\n";
