<?php
declare(strict_types=1);

/**
 * auth/avatar_image.php
 * ------------------------------------------------------------------
 * Biblioteca ÚNICA de processamento e persistência de avatares de upload
 * (mecanismo "custom" do catálogo consolidado).
 *
 * Pipeline: binário de imagem  ->  recorte quadrado central  ->  WebP 256/64
 *           ->  assets/img/avatars/custom/{id}/<hash>_{256,64}.webp
 *           ->  UPSERT linha kind='custom' em `avatars`
 *           ->  repontar usuarios.avatar_id
 *           ->  flush do cache de avatar em sessão
 *
 * Reutilizada por:
 *   - auth/avatar_save.php (upload + recorte do usuário)
 *   - api/avatar_ai.php    (geração por IA — migra para cá na Fase 4)
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/avatar_helpers.php';

if (!defined('AVATAR_FS_BASE')) {
    // Diretório físico base dos avatares.
    define('AVATAR_FS_BASE', dirname(__DIR__) . '/assets/img/avatars');
}
if (!defined('AVATAR_CUSTOM_QUALITY')) {
    define('AVATAR_CUSTOM_QUALITY', 82);
}

// Tamanhos gerados para cada upload (256 = exibição, 64 = thumbnail em listas).
const AVATAR_CUSTOM_SIZES = [256, 64];
const AVATAR_MAX_BYTES    = 5 * 1024 * 1024; // 5 MB
const AVATAR_MIN_DIM      = 64;              // px (lado mínimo aceitável)
const AVATAR_ALLOWED_MIME = ['image/png', 'image/jpeg', 'image/webp'];

/**
 * Valida o binário recebido (tamanho, MIME real, dimensões mínimas).
 * @return array{ok:bool,error?:string,mime?:string,width?:int,height?:int}
 */
function avatar_validate_binary(string $bin): array
{
    $len = strlen($bin);
    if ($len === 0) {
        return ['ok' => false, 'error' => 'Imagem vazia.'];
    }
    if ($len > AVATAR_MAX_BYTES) {
        return ['ok' => false, 'error' => 'Imagem muito grande (máx. 5 MB).'];
    }

    $mime = '';
    if (class_exists('finfo')) {
        $fi   = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $fi->buffer($bin);
    }
    if ($mime !== '' && !in_array($mime, AVATAR_ALLOWED_MIME, true)) {
        return ['ok' => false, 'error' => 'Formato não suportado (use PNG, JPG ou WebP).', 'mime' => $mime];
    }

    $info = @getimagesizefromstring($bin);
    if ($info === false) {
        return ['ok' => false, 'error' => 'Arquivo não é uma imagem válida.'];
    }
    [$w, $h] = $info;
    if ($w < AVATAR_MIN_DIM || $h < AVATAR_MIN_DIM) {
        return ['ok' => false, 'error' => 'Imagem muito pequena (mín. 64×64).'];
    }

    return ['ok' => true, 'mime' => $mime ?: ($info['mime'] ?? ''), 'width' => $w, 'height' => $h];
}

/**
 * Recorta o centro quadrado e reamostra para $size, achatando sobre fundo branco,
 * retornando o binário WebP.
 *
 * @throws RuntimeException
 */
function avatar_square_webp(string $bin, int $size, int $quality = AVATAR_CUSTOM_QUALITY): string
{
    if (!function_exists('imagewebp')) {
        throw new RuntimeException('Suporte a WebP indisponível no servidor.');
    }
    $src = @imagecreatefromstring($bin);
    if (!$src) {
        throw new RuntimeException('Não foi possível decodificar a imagem.');
    }

    $w    = imagesx($src);
    $h    = imagesy($src);
    $side = min($w, $h);
    $sx   = intdiv($w - $side, 2);
    $sy   = intdiv($h - $side, 2);

    $dst   = imagecreatetruecolor($size, $size);
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefilledrectangle($dst, 0, 0, $size, $size, $white);
    imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $size, $size, $side, $side);

    ob_start();
    imagewebp($dst, null, $quality);
    $out = (string) ob_get_clean();

    imagedestroy($src);
    imagedestroy($dst);

    if ($out === '') {
        throw new RuntimeException('Falha ao codificar WebP.');
    }
    return $out;
}

/**
 * Processa e persiste um avatar de upload para o usuário, atualizando o catálogo.
 *
 * @return array{ok:bool,error?:string,avatar_id?:int,url?:string,url_thumb?:string,path?:string}
 */
function avatar_store_custom(int $userId, string $bin, ?PDO $pdo = null): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'Usuário inválido.'];
    }

    $val = avatar_validate_binary($bin);
    if (!$val['ok']) {
        return $val;
    }

    $pdo = $pdo ?: avatar_pdo();
    if (!$pdo) {
        return ['ok' => false, 'error' => 'Banco indisponível.'];
    }

    // Diretório do usuário
    $userDir = AVATAR_FS_BASE . '/custom/' . $userId;
    if (!is_dir($userDir) && !@mkdir($userDir, 0775, true) && !is_dir($userDir)) {
        return ['ok' => false, 'error' => 'Não foi possível preparar o diretório do avatar.'];
    }

    // Hash de conteúdo -> cache-busting automático e imutável
    $hash = substr(sha1($bin), 0, 16);

    // Gera e grava as variantes WebP
    try {
        $written = [];
        foreach (AVATAR_CUSTOM_SIZES as $sz) {
            $webp = avatar_square_webp($bin, $sz);
            $file = $userDir . '/' . $hash . '_' . $sz . '.webp';
            if (file_put_contents($file, $webp) === false) {
                throw new RuntimeException('Falha ao gravar ' . basename($file));
            }
            $written[$sz] = $file;
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }

    // Remove variantes antigas (hashes diferentes) do mesmo usuário
    foreach (glob($userDir . '/*.webp') ?: [] as $old) {
        if (strpos(basename($old), $hash . '_') !== 0) {
            @unlink($old);
        }
    }

    $relPath  = 'custom/' . $userId . '/' . $hash . '_256.webp';
    $filename = $hash . '_256.webp';

    // UPSERT da linha custom do usuário (1 por usuário) + repontar avatar_id
    try {
        $pdo->beginTransaction();

        $sel = $pdo->prepare(
            "SELECT id FROM `avatars` WHERE `kind`='custom' AND `owner_user_id`=:uid LIMIT 1"
        );
        $sel->execute([':uid' => $userId]);
        $avatarId = $sel->fetchColumn();

        if ($avatarId === false) {
            $ins = $pdo->prepare(
                "INSERT INTO `avatars` (`kind`,`owner_user_id`,`filename`,`path`,`format`,`gender`,`active`)
                 VALUES ('custom', :uid, :fn, :path, 'webp', 'todos', 1)"
            );
            $ins->execute([':uid' => $userId, ':fn' => $filename, ':path' => $relPath]);
            $avatarId = (int) $pdo->lastInsertId();
        } else {
            $avatarId = (int) $avatarId;
            $upd = $pdo->prepare(
                "UPDATE `avatars`
                    SET `filename`=:fn, `path`=:path, `format`='webp', `active`=1
                  WHERE `id`=:id"
            );
            $upd->execute([':fn' => $filename, ':path' => $relPath, ':id' => $avatarId]);
        }

        $pdo->prepare("UPDATE `usuarios` SET `avatar_id`=:aid WHERE `id_user`=:uid")
            ->execute([':aid' => $avatarId, ':uid' => $userId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => 'Falha ao registrar avatar: ' . $e->getMessage()];
    }

    // Invalida cache em sessão se for o usuário logado
    $sessUid = $_SESSION['user_id'] ?? $_SESSION['id_user'] ?? $_SESSION['id'] ?? null;
    if ($sessUid !== null && (int) $sessUid === $userId) {
        avatar_cache_flush();
    }

    $row = ['path' => $relPath];
    return [
        'ok'        => true,
        'avatar_id' => (int) $avatarId,
        'url'       => avatar_url_from_row($row),
        'url_thumb' => avatar_thumb_from_row($row),
        'path'      => $relPath,
    ];
}
