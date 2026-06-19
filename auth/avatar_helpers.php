<?php
declare(strict_types=1);

/**
 * auth/avatar_helpers.php
 * ------------------------------------------------------------------
 * Camada ÚNICA de resolução e renderização de avatares (Fase 1).
 *
 * Objetivo: substituir, nas próximas fases, todas as montagens de URL de
 * avatar espalhadas pelas telas (header, perfil, usuarios, relatorios,
 * cascata, minhas_tarefas, admin_companies, chat...) por estas funções.
 *
 * Esta fase apenas DISPONIBILIZA as funções — nenhuma tela é alterada ainda.
 *
 * Compatível com:
 *   - esquema NOVO  : avatars.path (relativo a assets/img/avatars/) + format
 *   - esquema LEGADO: avatars.filename (em assets/img/avatars/default_avatar/)
 *
 * Depende da migration 006 (coluna `path`/`format` em `avatars`).
 * ------------------------------------------------------------------
 */

if (!defined('AVATAR_WEB_BASE')) {
    // Base pública de todos os avatares.
    define('AVATAR_WEB_BASE', '/OKR_system/assets/img/avatars/');
}
if (!defined('AVATAR_DEFAULT_PATH')) {
    // Caminho (relativo a AVATAR_WEB_BASE) do avatar padrão/neutro.
    define('AVATAR_DEFAULT_PATH', 'default_avatar/default.png');
}
if (!defined('AVATAR_CSS_HREF')) {
    define('AVATAR_CSS_HREF', '/OKR_system/assets/css/avatar.css');
}

/**
 * Conexão PDO preguiçosa e reaproveitada (caso a chamadora não passe a sua).
 */
function avatar_pdo(): ?PDO
{
    static $pdo = null;
    static $tried = false;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if ($tried) {
        return null;
    }
    $tried = true;

    if (!defined('DB_HOST')) {
        $cfg = __DIR__ . '/config.php';
        if (is_file($cfg)) {
            require_once $cfg;
        }
    }
    if (!defined('DB_HOST')) {
        return null;
    }

    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    } catch (Throwable $e) {
        $pdo = null;
    }
    return $pdo;
}

/**
 * Gera as iniciais (1 a 2 letras maiúsculas) a partir de nome/sobrenome.
 */
function avatar_initials(string $first, string $last = ''): string
{
    $first = trim($first);
    $last  = trim($last);

    if ($first === '' && $last === '') {
        return '?';
    }

    $a = $first !== '' ? mb_substr($first, 0, 1, 'UTF-8') : '';
    $b = $last !== ''  ? mb_substr($last, 0, 1, 'UTF-8')  : '';

    // Sem sobrenome explícito: tenta o último token do nome completo.
    if ($b === '' && $first !== '') {
        $parts = preg_split('/\s+/', $first) ?: [];
        if (count($parts) > 1) {
            $b = mb_substr((string) end($parts), 0, 1, 'UTF-8');
        }
    }

    $ini = mb_strtoupper($a . $b, 'UTF-8');
    return $ini !== '' ? $ini : '?';
}

/**
 * Resolve a URL pública a partir de uma linha já carregada da tabela `avatars`
 * (ou de um JOIN). Aceita tanto os aliases prefixados (avatar_path/avatar_filename)
 * quanto os nomes crus (path/filename).
 */
function avatar_url_from_row(?array $row): string
{
    if ($row) {
        $path = trim((string) ($row['avatar_path'] ?? $row['path'] ?? ''));
        if ($path !== '') {
            return AVATAR_WEB_BASE . ltrim($path, '/');
        }
        $fn = trim((string) ($row['avatar_filename'] ?? $row['filename'] ?? ''));
        if ($fn !== '') {
            return AVATAR_WEB_BASE . 'default_avatar/' . $fn;
        }
    }
    return AVATAR_WEB_BASE . AVATAR_DEFAULT_PATH;
}

/**
 * Deriva a URL do thumbnail. Uploads custom geram <hash>_256.webp e <hash>_64.webp;
 * para os demais (galeria), o thumbnail é a própria imagem (SVG/PNG já é leve).
 */
function avatar_thumb_from_row(?array $row): string
{
    $url = avatar_url_from_row($row);
    if (preg_match('/_256\.webp$/', $url)) {
        return preg_replace('/_256\.webp$/', '_64.webp', $url);
    }
    return $url;
}

/**
 * Indica se o avatar resolvido é o padrão/neutro (útil para decidir
 * entre mostrar a imagem ou as iniciais).
 */
function avatar_is_default(?array $row): bool
{
    $url = avatar_url_from_row($row);
    return str_ends_with($url, '/' . AVATAR_DEFAULT_PATH)
        || str_ends_with($url, '/default_avatar/default.png');
}

/**
 * Resolve o avatar de um usuário pelo id, com cache em sessão
 * (invalida automaticamente ao trocar de usuário).
 *
 * @return array{url:string,url_thumb:string,initials:string,is_default:bool}
 */
function avatar_resolve(int $userId, ?PDO $pdo = null): array
{
    $sessionActive = (session_status() === PHP_SESSION_ACTIVE);

    if (
        $sessionActive
        && isset($_SESSION['avatar_cache'], $_SESSION['avatar_cache_uid'])
        && (int) $_SESSION['avatar_cache_uid'] === $userId
        && is_array($_SESSION['avatar_cache'])
    ) {
        return $_SESSION['avatar_cache'];
    }

    $row = null;
    try {
        $pdo = $pdo ?: avatar_pdo();
        if ($pdo && $userId > 0) {
            $st = $pdo->prepare(
                "SELECT a.filename AS avatar_filename,
                        a.path     AS avatar_path,
                        a.format   AS avatar_format,
                        u.primeiro_nome,
                        u.ultimo_nome
                   FROM usuarios u
              LEFT JOIN avatars a ON a.id = u.avatar_id
                  WHERE u.id_user = :id
                  LIMIT 1"
            );
            $st->execute([':id' => $userId]);
            $row = $st->fetch() ?: null;
        }
    } catch (Throwable $e) {
        $row = null; // fallback para o padrão
    }

    $data = [
        'url'        => avatar_url_from_row($row),
        'url_thumb'  => avatar_thumb_from_row($row),
        'initials'   => avatar_initials(
            (string) ($row['primeiro_nome'] ?? ''),
            (string) ($row['ultimo_nome'] ?? '')
        ),
        'is_default' => avatar_is_default($row),
    ];

    if ($sessionActive) {
        $_SESSION['avatar_cache']     = $data;
        $_SESSION['avatar_cache_uid'] = $userId;
    }

    return $data;
}

/**
 * Invalida o cache de avatar em sessão (chamar após trocar/subir avatar).
 */
function avatar_cache_flush(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        unset($_SESSION['avatar_cache'], $_SESSION['avatar_cache_uid']);
    }
}

/**
 * Renderiza o componente de avatar (markup ÚNICO do sistema).
 * Sempre inclui um fallback de iniciais que aparece se a imagem falhar.
 *
 * @param array $data Retorno de avatar_resolve() (ou array com url/url_thumb/initials).
 * @param int   $size Tamanho em pixels (lado do quadrado).
 * @param array $opts ['alt'=>string,'class'=>string,'lazy'=>bool,'thumb'=>bool]
 */
function render_avatar(array $data, int $size = 40, array $opts = []): string
{
    $useThumb = (bool) ($opts['thumb'] ?? false);
    $url      = (string) ($useThumb
        ? ($data['url_thumb'] ?? $data['url'] ?? '')
        : ($data['url'] ?? ''));
    $initials = (string) ($data['initials'] ?? '?');
    $alt      = (string) ($opts['alt'] ?? 'Avatar');
    $extra    = trim((string) ($opts['class'] ?? ''));
    $lazy     = ($opts['lazy'] ?? true) ? ' loading="lazy" decoding="async"' : '';
    $sz       = max(16, $size);

    $eAlt = htmlspecialchars($alt, ENT_QUOTES, 'UTF-8');
    $eIni = htmlspecialchars($initials, ENT_QUOTES, 'UTF-8');
    $cls  = 'avatar' . ($extra !== '' ? ' ' . htmlspecialchars($extra, ENT_QUOTES, 'UTF-8') : '');
    $styleSize = '--avatar-size:' . $sz . 'px';

    if ($url === '') {
        return '<span class="' . $cls . '" style="' . $styleSize . '">'
             . '<span class="avatar__initials">' . $eIni . '</span>'
             . '</span>';
    }

    $eUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

    return '<span class="' . $cls . '" style="' . $styleSize . '">'
         . '<img src="' . $eUrl . '" alt="' . $eAlt . '"' . $lazy
         . ' onerror="this.style.display=&quot;none&quot;;this.nextElementSibling.style.display=&quot;flex&quot;;">'
         . '<span class="avatar__initials" style="display:none">' . $eIni . '</span>'
         . '</span>';
}

/**
 * Tag <link> do CSS do componente (incluir uma vez por página na Fase 4).
 */
function avatar_css_link(): string
{
    return '<link rel="stylesheet" href="' . AVATAR_CSS_HREF . '">';
}
