<?php
declare(strict_types=1);

/**
 * OKR_system/api/api_platform/v1/auth/login.php
 * POST /auth/login
 * Body JSON: { "email": "...", "senha": "...", "captcha_token": "..." (opcional) }
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../../../auth/functions.php'; // carrega config.php + env() + constants + verifyCaptchaOrFail()

function api_json(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function b64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function jwt_hs256_encode(array $payload, string $secret): string {
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    $h = b64url_encode(json_encode($header, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $p = b64url_encode(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $sig = hash_hmac('sha256', $h . '.' . $p, $secret, true);
    $s = b64url_encode($sig);
    return $h . '.' . $p . '.' . $s;
}

function get_json_body(): array {
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    if (is_array($data)) return $data;
    // fallback form-data / x-www-form-urlencoded
    return is_array($_POST) ? $_POST : [];
}

function pdo_conn(): PDO {
    global $options; // vem do auth/config.php
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    return new PDO($dsn, DB_USER, DB_PASS, $options);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    api_json(405, ['ok' => false, 'error' => 'METHOD_NOT_ALLOWED']);
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$body = get_json_body();

$email = trim((string)($body['email'] ?? $body['usuario'] ?? ''));
$senha = (string)($body['senha'] ?? $body['password'] ?? '');
$captchaToken = $body['captcha_token'] ?? $body['captcha'] ?? null;

if ($email === '' || $senha === '') {
    api_json(400, ['ok' => false, 'error' => 'VALIDATION', 'message' => 'Informe email e senha.']);
}

// CAPTCHA (respeita config.php: CAPTCHA_PROVIDER=off|recaptcha|hcaptcha)
try {
    verifyCaptchaOrFail(is_string($captchaToken) ? $captchaToken : null, $ip);
} catch (Throwable $e) {
    api_json(403, ['ok' => false, 'error' => 'CAPTCHA', 'message' => $e->getMessage()]);
}

try {
    $pdo = pdo_conn();

    // Busca usuário + hash
    $sql = "
        SELECT
            u.id_user,
            u.primeiro_nome,
            u.ultimo_nome,
            u.email_corporativo,
            u.id_company,
            u.avatar_id,
            u.imagem_url,
            c.senha_hash
        FROM usuarios u
        INNER JOIN usuarios_credenciais c ON c.id_user = u.id_user
        WHERE LOWER(u.email_corporativo) = LOWER(:email)
        LIMIT 1
    ";
    $st = $pdo->prepare($sql);
    $st->execute([':email' => $email]);
    $u = $st->fetch(PDO::FETCH_ASSOC);

    if (!$u) {
        api_json(401, ['ok' => false, 'error' => 'INVALID_CREDENTIALS', 'message' => 'Credenciais inválidas.']);
    }

    if (!password_verify($senha, (string)$u['senha_hash'])) {
        api_json(401, ['ok' => false, 'error' => 'INVALID_CREDENTIALS', 'message' => 'Credenciais inválidas.']);
    }

    // Roles (opcional, mas útil)
    $roles = [];
    $st = $pdo->prepare("
        SELECT r.role_key
        FROM rbac_user_role ur
        INNER JOIN rbac_roles r ON r.role_id = ur.role_id
        WHERE ur.user_id = :uid
        ORDER BY r.role_key
    ");
    $st->execute([':uid' => (int)$u['id_user']]);
    $roles = array_values(array_filter(array_map(
        fn($x) => (string)$x['role_key'],
        $st->fetchAll(PDO::FETCH_ASSOC) ?: []
    )));

    // Permissões efetivas (view)
    $perms = ['consulta_R' => '', 'edicao_W' => ''];
    $st = $pdo->prepare("SELECT consulta_R, edicao_W FROM v_user_access_effective WHERE user_id = :uid LIMIT 1");
    $st->execute([':uid' => (int)$u['id_user']]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if ($p) $perms = [
        'consulta_R' => (string)($p['consulta_R'] ?? ''),
        'edicao_W'   => (string)($p['edicao_W'] ?? ''),
    ];

    // Empresa + estilo (cores)
    $company = null;
    $st = $pdo->prepare("SELECT id_company, organizacao, razao_social, cnpj FROM company WHERE id_company = :cid LIMIT 1");
    $st->execute([':cid' => (int)$u['id_company']]);
    $company = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    $style = null;
    $st = $pdo->prepare("SELECT bg1_hex, bg2_hex FROM company_style WHERE id_company = :cid LIMIT 1");
    $st->execute([':cid' => (int)$u['id_company']]);
    $style = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    // Token (JWT HS256)
    $ttl = (int)(getenv('API_JWT_TTL') ?: 43200); // 12h default
    $now = time();
    $secret = (string)(getenv('API_JWT_SECRET') ?: (defined('APP_TOKEN_PEPPER') ? APP_TOKEN_PEPPER : 'CHANGE_ME'));

    $fullName = trim(((string)$u['primeiro_nome']) . ' ' . ((string)$u['ultimo_nome']));
    $payload = [
        'iss' => 'planningbi-okr',
        'aud' => 'planningbi-okr-app',
        'sub' => (int)$u['id_user'],
        'cid' => (int)$u['id_company'],
        'email' => (string)$u['email_corporativo'],
        'name' => $fullName,
        'roles' => $roles,
        'iat' => $now,
        'exp' => $now + max(300, $ttl),
        'ver' => 1,
    ];

    $token = jwt_hs256_encode($payload, $secret);

    api_json(200, [
        'ok' => true,
        'token_type' => 'Bearer',
        'access_token' => $token,
        'expires_in' => (int)($payload['exp'] - $payload['iat']),
        'user' => [
            'id_user' => (int)$u['id_user'],
            'primeiro_nome' => (string)$u['primeiro_nome'],
            'ultimo_nome' => (string)$u['ultimo_nome'],
            'nome' => $fullName,
            'email' => (string)$u['email_corporativo'],
            'id_company' => (int)$u['id_company'],
            'avatar_id' => $u['avatar_id'] !== null ? (int)$u['avatar_id'] : null,
            'imagem_url' => $u['imagem_url'] ? (string)$u['imagem_url'] : null,
            'roles' => $roles,
        ],
        'company' => $company,
        'style' => $style,
        'permissions' => $perms,
    ]);

} catch (Throwable $e) {
    api_json(500, [
        'ok' => false,
        'error' => 'SERVER_ERROR',
        'message' => 'Erro interno no login.',
        'detail' => (APP_DEBUG ?? false) ? $e->getMessage() : null,
    ]);
}
