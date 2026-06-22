<?php
declare(strict_types=1);

/**
 * Migration 008 — Migra avatares LEGADOS para o catálogo único (kind='custom').
 * 2026-06-19
 *
 * Converte para o novo sistema (WebP 256/64 + linha custom + repontar avatar_id):
 *   1) Arquivos soltos  assets/img/avatars/{id}.png|jpg|jpeg  (mecanismo B / IA antiga)
 *   2) usuarios.imagem_url em data-URI base64                 (mecanismo C / legado)
 *
 * Só age sobre usuários EXISTENTES. Arquivos {id} sem usuário correspondente
 * (órfãos de contas deletadas) são apenas listados — a limpeza (009) os remove.
 *
 * Idempotente: ao rodar de novo, store_custom regrava o mesmo hash de conteúdo
 * e não duplica linhas (UPSERT por owner_user_id).
 *
 * Uso:
 *   php tools/migrations/008_migrate_legacy_avatars.php --dry
 *   php tools/migrations/008_migrate_legacy_avatars.php
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }

$dry = in_array('--dry', $argv, true);
$root = dirname(__DIR__, 2);
require $root . '/auth/config.php';
require $root . '/auth/avatar_image.php';

$pdo = avatar_pdo();
$base = $root . '/assets/img/avatars/';

$validUsers = [];
foreach ($pdo->query("SELECT id_user FROM usuarios") as $r) { $validUsers[(int)$r['id_user']] = true; }

$migrated = 0; $skipped = 0; $orphans = [];

/* ---- 1) Arquivos {id}.ext soltos ---- */
foreach (glob($base . '*.{png,jpg,jpeg}', GLOB_BRACE) ?: [] as $path) {
    $bn = basename($path);
    if (!preg_match('/^(\d+)\.(png|jpg|jpeg)$/i', $bn, $m)) continue; // ignora avatar_IA.png etc.
    $uid = (int)$m[1];

    if (!isset($validUsers[$uid])) { $orphans[] = $bn; continue; }

    echo ($dry ? "[DRY] " : "") . "migrar arquivo {$bn} -> user {$uid}: ";
    if ($dry) { echo "ok (simulado)\n"; $migrated++; continue; }

    $res = avatar_store_custom($uid, (string)file_get_contents($path), $pdo);
    if (!empty($res['ok'])) { echo "OK avatar_id={$res['avatar_id']} {$res['path']}\n"; $migrated++; }
    else { echo "FALHA: " . ($res['error'] ?? '?') . "\n"; $skipped++; }
}

/* ---- 2) base64 imagem_url ---- */
$colExists = (bool)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='usuarios' AND COLUMN_NAME='imagem_url'"
)->fetchColumn();

if ($colExists) {
    $rows = $pdo->query("SELECT id_user, imagem_url FROM usuarios WHERE imagem_url IS NOT NULL AND imagem_url <> ''")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $uid = (int)$r['id_user'];
        $dataUrl = (string)$r['imagem_url'];
        if (!preg_match('#^data:image/(png|jpe?g|webp);base64,#i', $dataUrl)) {
            echo "base64 user {$uid}: ignorado (não é data-URI de imagem)\n"; $skipped++; continue;
        }
        $bin = (string)base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1), true);
        echo ($dry ? "[DRY] " : "") . "migrar base64 -> user {$uid}: ";
        if ($dry) { echo "ok (simulado)\n"; $migrated++; continue; }
        $res = avatar_store_custom($uid, $bin, $pdo);
        if (!empty($res['ok'])) { echo "OK avatar_id={$res['avatar_id']}\n"; $migrated++; }
        else { echo "FALHA: " . ($res['error'] ?? '?') . "\n"; $skipped++; }
    }
}

echo "\n" . ($dry ? "[DRY-RUN] " : "") . "MIGRATE_LEGACY_OK migrated={$migrated} skipped={$skipped} orphans=" . count($orphans) . "\n";
if ($orphans) echo "  órfãos (serão removidos na limpeza 009): " . implode(', ', $orphans) . "\n";
