<?php
declare(strict_types=1);

/**
 * auth/avatars_gallery.php
 * Lista a galeria de avatares PADRÃO (catálogo único `avatars`) em JSON,
 * com filtros opcionais. Fonte de dados do novo picker (Fase 4).
 *
 * GET params:
 *   gender = masculino | feminino | todos      (opcional)
 *   tag    = barba | oculos | hijab | ...       (opcional; filtra por 1 tag)
 *   q      = busca livre em tags                (opcional)
 *
 * Resposta:
 *   { ok:true, count:N, facets:{genders:[...], tags:[...]}, avatars:[
 *       { id, url, gender, tags:[...] }, ...
 *   ] }
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=300');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/avatar_helpers.php';

$userId = $_SESSION['user_id'] ?? $_SESSION['id_user'] ?? $_SESSION['id'] ?? null;
if (!$userId) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Não autorizado']);
    exit;
}

$genderFilter = isset($_GET['gender']) ? strtolower(trim((string) $_GET['gender'])) : '';
$tagFilter    = isset($_GET['tag']) ? strtolower(trim((string) $_GET['tag'])) : '';
$q            = isset($_GET['q']) ? strtolower(trim((string) $_GET['q'])) : '';

$allowedGender = ['masculino', 'feminino', 'todos'];
if (!in_array($genderFilter, $allowedGender, true)) {
    $genderFilter = '';
}

try {
    $pdo = avatar_pdo();
    if (!$pdo) {
        throw new RuntimeException('DB indisponível');
    }

    $sql = "SELECT id, path, filename, gender, tags
              FROM `avatars`
             WHERE `kind` = 'default' AND `active` = 1
               AND `path` LIKE 'gallery/%'";
    $params = [];
    if ($genderFilter !== '') {
        // 'todos' inclui só os neutros; filtros específicos retornam seu gênero + neutros
        if ($genderFilter === 'todos') {
            $sql .= " AND `gender` = 'todos'";
        } else {
            $sql .= " AND `gender` IN (:g, 'todos')";
            $params[':g'] = $genderFilter;
        }
    }
    $sql .= " ORDER BY `gender`, `id`";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    $avatars = [];
    $tagSet  = [];
    foreach ($rows as $r) {
        $tags = json_decode((string) ($r['tags'] ?? '[]'), true);
        if (!is_array($tags)) {
            $tags = [];
        }

        // filtros por tag / busca livre (em PHP, dataset é pequeno)
        if ($tagFilter !== '' && !in_array($tagFilter, array_map('strtolower', $tags), true)) {
            continue;
        }
        if ($q !== '') {
            $hay = strtolower(implode(' ', $tags) . ' ' . (string) $r['gender']);
            if (strpos($hay, $q) === false) {
                continue;
            }
        }

        foreach ($tags as $t) {
            $tagSet[$t] = true;
        }

        $avatars[] = [
            'id'     => (int) $r['id'],
            'url'    => avatar_url_from_row($r),
            'gender' => (string) $r['gender'],
            'tags'   => array_values($tags),
        ];
    }

    ksort($tagSet);

    echo json_encode([
        'ok'     => true,
        'count'  => count($avatars),
        'facets' => [
            'genders' => $allowedGender,
            'tags'    => array_keys($tagSet),
        ],
        'avatars' => $avatars,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erro: ' . $e->getMessage()]);
}
