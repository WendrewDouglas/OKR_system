<?php
/**
 * OKR System — Registro de Usuário (versão robusta + avatar_id)
 * - HTTPS + CSRF + Honeypot + Rate-limit
 * - Verificação de e-mail (token em okr_email_verifications)
 * - Telefone/WhatsApp OBRIGATÓRIO
 * - Transação atômica com created_by não-nulo (self-reference)
 * - Papel SEMPRE user_admin (ajuste automático INT/VARCHAR)
 * - Fallback de logo base64 em company_style
 * - Avatar: grava avatar_id (procurando por filename na tabela avatars)
 */

declare(strict_types=1);
session_start();

require_once __DIR__ . '/bootstrap_logging.php';

$ip = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
$captchaToken = $_POST['g-recaptcha-response'] ?? $_POST['h-captcha-response'] ?? $_POST['captcha_token'] ?? null;
verifyCaptchaOrFail($captchaToken, $ip);


/* ===================================
   Helpers de redirecionamento/flash
   =================================== */
function redirectWithError(string $msg): void {
    $_SESSION['error_message'] = $msg;
    header('Location: /OKR_system/views/cadastro_site.php');
    exit;
}
function redirectWithSuccess(string $msg): void {
    $_SESSION['success_message'] = $msg;
    header('Location: /OKR_system/views/cadastro_site.php');
    exit;
}
function storeOldInputs(): void {
    $_SESSION['old_inputs'] = [
        'primeiro_nome'          => $_POST['primeiro_nome']          ?? '',
        'ultimo_nome'            => $_POST['ultimo_nome']            ?? '',
        'email_corporativo'      => $_POST['email_corporativo']      ?? '',
        'telefone'               => $_POST['telefone']               ?? '',
        'empresa'                => $_POST['empresa']                ?? '',
        'faixa_qtd_funcionarios' => $_POST['faixa_qtd_funcionarios'] ?? '',
        'avatar_file'            => $_POST['avatar_file']            ?? 'default.png',
        // Nunca armazene senha/senha_confirm
    ];
}

/* ==========================
   Constantes de estilo/segurança
   ========================== */
const DEFAULT_BG1            = '#222222';
const DEFAULT_BG2            = '#f1c40f';
const DEFAULT_LOGO_URL       = 'https://planningbi.com.br/wp-content/uploads/2025/07/logo-horizontal.jpg';
const DEFAULT_LOGO_MAX_BYTES = 2_097_152; // 2MB
const DEFAULT_SYSTEM_USER_ID = 1;

// Papel padrão (sempre user_admin)
const DEFAULT_USER_ROLE_ID   = 2;              // fallback caso slug->ID não resolva
const DEFAULT_USER_ROLE_SLUG = 'user_admin';   // slug canônico

/* PNG 1x1 transparente base64 (fallback de logo) */
const PIXEL_PNG_BASE64 = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=';

/* ==========================
   Utilitários
   ========================== */
function isHttps(): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    if (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) return true;
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') return true;
    if (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on') return true;
    return false;
}
function normalizeCompany(string $s): string {
    $s = trim($s);
    $s = preg_replace('/\s+/', ' ', $s);
    return $s;
}
function clientIp(): ?string {
    $keys = ['HTTP_CF_CONNECTING_IP','HTTP_X_REAL_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'];
    foreach ($keys as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = $_SERVER[$k];
            if ($k === 'HTTP_X_FORWARDED_FOR') {
                $parts = array_map('trim', explode(',', $ip));
                $ip = $parts[0] ?? $ip;
            }
            return $ip;
        }
    }
    return null;
}

/**
 * Baixa logo e devolve como data URI base64; senão retorna 1x1 transparente.
 */
function downloadLogoToDataUri(string $url, int $maxBytes = DEFAULT_LOGO_MAX_BYTES): string {
    $raw = null; $mime = null;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 4,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'OKRSystem/1.0',
            CURLOPT_HEADER         => true,
        ]);
        $resp = curl_exec($ch);
        if ($resp !== false) {
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $headersRaw = substr($resp, 0, $headerSize);
            $body       = substr($resp, $headerSize);
            if ($body !== false && strlen($body) <= $maxBytes) {
                $raw = $body;
                if (preg_match('/^Content-Type:\s*([^\r\n;]+)/im', $headersRaw, $m)) {
                    $mime = trim($m[1]);
                }
            }
        }
        curl_close($ch);
    }

    if ($raw === null && ini_get('allow_url_fopen')) {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 15,
                'follow_location' => 1,
                'header' => "User-Agent: OKRSystem/1.0\r\n",
            ]
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw !== false && strlen($raw) <= $maxBytes) {
            if (isset($http_response_header) && is_array($http_response_header)) {
                foreach ($http_response_header as $h) {
                    if (stripos($h, 'Content-Type:') === 0) {
                        $mime = trim(explode(':', $h, 2)[1]);
                        $mime = explode(';', $mime)[0];
                        break;
                    }
                }
            }
        } else {
            $raw = null;
        }
    }

    if ($raw === null) {
        error_log('auth_register: falha ao baixar logo padrão em ' . DEFAULT_LOGO_URL);
        return PIXEL_PNG_BASE64;
    }

    if (!$mime && function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
            $det = finfo_buffer($fi, $raw);
            if ($det) $mime = $det;
            finfo_close($fi);
        }
    }
    if (!$mime) $mime = 'image/jpeg';

    $allowed = ['image/jpeg','image/jpg','image/png','image/webp','image/svg+xml'];
    if (!in_array(strtolower($mime), $allowed, true)) {
        if (preg_match('/\.(jpe?g)(\?|$)/i', $url))        $mime = 'image/jpeg';
        elseif (preg_match('/\.(png)(\?|$)/i', $url))      $mime = 'image/png';
        elseif (preg_match('/\.(svg)(\?|$)/i', $url))      $mime = 'image/svg+xml';
        elseif (preg_match('/\.(webp)(\?|$)/i', $url))     $mime = 'image/webp';
        else {
            error_log('auth_register: MIME desconhecido para logo padrão: ' . $mime);
            return PIXEL_PNG_BASE64;
        }
    }

    return 'data:' . $mime . ';base64,' . base64_encode($raw);
}

/**
 * Garante 1 registro em company_style para a empresa informada.
 */
function ensureDefaultCompanyStyle(PDO $pdo, int $idCompany, ?int $createdBy = null): void {
    $st = $pdo->prepare('SELECT id_style FROM company_style WHERE id_company = :c LIMIT 1');
    $st->execute([':c' => $idCompany]);
    if ($st->fetch()) return;

    $logoDataUri = downloadLogoToDataUri(DEFAULT_LOGO_URL);
    $createdBy   = $createdBy ?? DEFAULT_SYSTEM_USER_ID;

    $sql = 'INSERT INTO company_style (id_company, bg1_hex, bg2_hex, logo_base64, okr_master_user_id, created_by)
            VALUES (:c, :bg1, :bg2, :logo, NULL, :cb)';
    $pdo->prepare($sql)->execute([
        ':c'    => $idCompany,
        ':bg1'  => DEFAULT_BG1,
        ':bg2'  => DEFAULT_BG2,
        ':logo' => $logoDataUri,
        ':cb'   => $createdBy,
    ]);
}

/**
 * Verifica no INFORMATION_SCHEMA se a coluna parece numérica.
 */
function columnIsNumeric(PDO $pdo, string $table, string $column): bool {
    try {
        $q = $pdo->prepare("
            SELECT DATA_TYPE
              FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = :t
               AND COLUMN_NAME  = :c
             LIMIT 1
        ");
        $q->execute([':t' => $table, ':c' => $column]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['DATA_TYPE'])) return false;
        $numeric = ['tinyint','smallint','mediumint','int','integer','bigint','decimal','numeric'];
        return in_array(strtolower($row['DATA_TYPE']), $numeric, true);
    } catch (Throwable $e) {
        error_log('auth_register: columnIsNumeric check failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Resolve id de permissão por slug/nome; se falhar, retorna default.
 */
function resolvePermissionId(PDO $pdo, string $slug): int {
    try {
        $q = $pdo->prepare("SELECT id FROM permissoes WHERE slug = :s OR nome = :s LIMIT 1");
        $q->execute([':s' => $slug]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['id'])) return (int)$row['id'];
    } catch (Throwable $e) {
        error_log('auth_register: resolvePermissionId falhou: ' . $e->getMessage());
    }
    return DEFAULT_USER_ROLE_ID;
}

/**
 * Retorna o id do avatar pelo filename; fallback para 1 (default.png).
 */
function resolveAvatarId(PDO $pdo, string $filename): int {
    $f = trim(strtolower($filename));
    if (!preg_match('/^[a-z0-9_.-]+\.png$/i', $f)) {
        $f = 'default.png';
    }
    try {
        $st = $pdo->prepare("SELECT id FROM avatars WHERE filename = :f AND active = 1 LIMIT 1");
        $st->execute([':f' => $f]);
        $id = (int)$st->fetchColumn();
        return $id > 0 ? $id : 1; // 1 = default.png
    } catch (Throwable $e) {
        error_log('auth_register: resolveAvatarId falhou: ' . $e->getMessage());
        return 1;
    }
}

/**
 * Verifica se o e-mail foi validado via token na tabela okr_email_verifications.
 */
function assertEmailVerified(PDO $pdo, string $email, string $token): void {
    $sel = $pdo->prepare("
        SELECT email, status, expires_at
          FROM okr_email_verifications
         WHERE token = :t
         ORDER BY id DESC
         LIMIT 1
    ");
    $sel->execute([':t' => $token]);
    $v = $sel->fetch(PDO::FETCH_ASSOC);

    if (!$v) {
        storeOldInputs();
        redirectWithError('Validação de e-mail não encontrada. Solicite novo código.');
    }
    if (strtolower($v['email']) !== strtolower($email)) {
        storeOldInputs();
        redirectWithError('E-mail diferente do verificado. Revise e tente novamente.');
    }
    if ($v['status'] !== 'verified') {
        storeOldInputs();
        redirectWithError('E-mail ainda não verificado. Conclua a verificação para prosseguir.');
    }
    if (strtotime($v['expires_at']) < time()) {
        storeOldInputs();
        redirectWithError('Código de verificação expirado. Solicite um novo.');
    }
}

/* ==========================
   1) CSRF
   ========================== */
if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    storeOldInputs();
    redirectWithError('Requisição inválida (CSRF).');
}

/* ==========================
   2) Honeypot
   ========================== */
if (!empty($_POST['website'])) {
    http_response_code(400);
    exit; // Bot
}

/* ==========================
   3) HTTPS obrigatório (com suporte a proxy)
   ========================== */
if (!isHttps()) {
    storeOldInputs();
    redirectWithError('Conexão insegura. Use HTTPS.');
}

/* ==========================
   4) Rate-limit simples (por sessão)
   ========================== */
if (!isset($_SESSION['reg_attempts'])) $_SESSION['reg_attempts'] = 0;
if ($_SESSION['reg_attempts']++ >= 20) {
    storeOldInputs();
    redirectWithError('Muitas tentativas. Aguarde alguns minutos.');
}

/* ==========================
   5) Sanitização e validação
   ========================== */
$primeiro = trim($_POST['primeiro_nome'] ?? '');
$ultimo   = trim($_POST['ultimo_nome']   ?? '');
$email    = trim($_POST['email_corporativo'] ?? '');
$telefone = trim($_POST['telefone'] ?? '');
$empresa  = normalizeCompany($_POST['empresa'] ?? '');
$faixa    = trim($_POST['faixa_qtd_funcionarios'] ?? '');
$senha    = $_POST['senha'] ?? '';
$senha_cf = $_POST['senha_confirm'] ?? '';

// avatar_file vem com nome do arquivo (ex.: default.png, user03.png, fem07.png)
$avatarFile = trim($_POST['avatar_file'] ?? 'default.png');
if (!preg_match('/^[a-z0-9_.-]+\.png$/i', $avatarFile)) {
    $avatarFile = 'default.png';
}

$ev_token = trim((string)($_POST['email_verify_token'] ?? ''));
$ev_flag  = trim((string)($_POST['email_verified'] ?? '0'));

if ($primeiro === '') { storeOldInputs(); redirectWithError('Primeiro nome é obrigatório.'); }
if ($empresa  === '') { storeOldInputs(); redirectWithError('O nome da organização é obrigatório.'); }
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    storeOldInputs(); redirectWithError('E-mail inválido.');
}
[, $dominio] = explode('@', $email);
if (!checkdnsrr($dominio, 'MX')) {
    storeOldInputs(); redirectWithError('Domínio de e-mail não existe.');
}
$senhaRegex = '/(?=^.{8,}$)(?=.*\d)(?=.*[A-Z])(?=.*[a-z])(?=.*\W).*$/';
if (!preg_match($senhaRegex, $senha)) {
    storeOldInputs(); redirectWithError('Senha não atende aos requisitos mínimos.');
}
if ($senha !== $senha_cf) {
    storeOldInputs(); redirectWithError('Senhas não conferem.');
}
// Telefone/WhatsApp OBRIGATÓRIO + formato
if ($telefone === '' || !preg_match('/^\(\d{2}\)\s?\d{4,5}-\d{4}$/', $telefone)) {
    storeOldInputs(); redirectWithError('Telefone/WhatsApp é obrigatório e deve estar no formato (XX) 9XXXX-XXXX.');
}

// E-mail verificado (flag + token 64 hex)
if ($ev_flag !== '1' || !preg_match('/^[a-f0-9]{64}$/', $ev_token)) {
    storeOldInputs();
    redirectWithError('Verifique seu e-mail antes de prosseguir.');
}

/* ==========================
   6) Conexão ao banco via PDO
   ========================== */
require_once __DIR__ . '/config.php';
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $options = $options ?? [];
    $options = $options + [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    error_log('auth_register: PDO connect error: ' . $e->getMessage());
    storeOldInputs();
    redirectWithError('Erro ao conectar ao banco.');
}

/* ==========================
   6.1) Resolver avatar_id a partir do filename
   ========================== */
$avatarId = resolveAvatarId($pdo, $avatarFile);

/* ==========================
   7) Unicidade de e-mail + token verificado
   ========================== */
try {
    // e-mail único
    $stmt = $pdo->prepare('SELECT 1 FROM usuarios WHERE email_corporativo = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    if ($stmt->fetch()) {
        storeOldInputs();
        redirectWithError('E-mail já cadastrado.');
    }

    // exige token verificado para este e-mail
    assertEmailVerified($pdo, $email, $ev_token);

} catch (Throwable $e) {
    error_log('auth_register: pré-validações falharam: ' . $e->getMessage());
    storeOldInputs();
    redirectWithError('Falha ao validar dados do cadastro.');
}

/* ==========================
   8) Inserção atômica com transação
   ========================== */
try {
    $pdo->beginTransaction();

    // 8.0) Encontrar/criar company por organização (case-insensitive)
    $empresaLower = mb_strtolower($empresa, 'UTF-8');
    $findCompany = $pdo->prepare('SELECT id_company, organizacao FROM company WHERE LOWER(organizacao) = :org LIMIT 1');
    $findCompany->execute([':org' => $empresaLower]);
    $company = $findCompany->fetch();

    if ($company) {
        $idCompany = (int)$company['id_company'];
    } else {
        $insertCompany = $pdo->prepare('INSERT INTO company (organizacao, created_at) VALUES (:org, NOW())');
        $insertCompany->execute([':org' => $empresa]);
        $idCompany = (int)$pdo->lastInsertId();
    }

    // 8.1) Resolver papel user_admin para ambas as tabelas (INT ou VARCHAR)
    $idPermissao       = resolvePermissionId($pdo, DEFAULT_USER_ROLE_SLUG);
    $isNumUsers        = columnIsNumeric($pdo, 'usuarios',            'id_permissao');
    $isNumUsuariosPerm = columnIsNumeric($pdo, 'usuarios_permissoes', 'id_permissao');

    $permForUsuarios     = $isNumUsers        ? $idPermissao : DEFAULT_USER_ROLE_SLUG;
    $permForUsuariosPerm = $isNumUsuariosPerm ? $idPermissao : DEFAULT_USER_ROLE_SLUG;

    // 8.2) Inserir usuário (com created_by NÃO-NULO)
    $criador = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : DEFAULT_SYSTEM_USER_ID;

    $sqlUser = 'INSERT INTO usuarios
        (primeiro_nome, ultimo_nome, telefone, empresa, faixa_qtd_funcionarios,
         email_corporativo, dt_cadastro, ip_criacao, id_user_criador, id_permissao, id_company, avatar_id)
        VALUES
        (:primeiro, :ultimo, :telefone, :empresa, :faixa,
         :email, NOW(), :ip, :criador, :perm, :id_company, :avatar_id)';

    $stmtUser = $pdo->prepare($sqlUser);
    $stmtUser->execute([
        ':primeiro'   => $primeiro,
        ':ultimo'     => $ultimo ?: null,
        ':telefone'   => $telefone ?: null,
        ':empresa'    => $empresa,
        ':faixa'      => $faixa ?: null,
        ':email'      => $email,
        ':ip'         => clientIp(),
        ':criador'    => $criador,
        ':perm'       => $permForUsuarios,
        ':id_company' => $idCompany,
        ':avatar_id'  => $avatarId,
    ]);
    $newId = (int)$pdo->lastInsertId();

    // auto-cadastro → self-reference
    if (!isset($_SESSION['user_id'])) {
        try {
            $pdo->prepare('UPDATE usuarios SET id_user_criador = :self WHERE id_user = :id')
                ->execute([':self' => $newId, ':id' => $newId]);
        } catch (Throwable $e) {
            error_log('auth_register: update self creator falhou: ' . $e->getMessage());
        }
    }

    // 8.3) Garantir estilo padrão
    ensureDefaultCompanyStyle($pdo, $idCompany, $newId);

    // 8.4) Credenciais (Argon2id -> fallback)
    $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    $hash = password_hash($senha, $algo);
    $stmtCred = $pdo->prepare('INSERT INTO usuarios_credenciais (id_user, senha_hash) VALUES (:id, :hash)');
    $stmtCred->execute([':id' => $newId, ':hash' => $hash]);

    // 8.5) Permissões (tabela de junção)
    $stmtPerm = $pdo->prepare('INSERT INTO usuarios_permissoes (id_user, id_permissao) VALUES (:id, :perm)');
    $stmtPerm->execute([':id' => $newId, ':perm' => $permForUsuariosPerm]);

    $pdo->commit();

    // 8.6) (Removido) cópia física de avatar — agora é apenas avatar_id

    // 8.7) (Opcional) marcar token verificado como usado
    try {
        $pdo->prepare("UPDATE okr_email_verifications
                          SET used_by_user_id = :uid, used_at = NOW()
                        WHERE token = :t AND status = 'verified'")
            ->execute([':uid' => $newId, ':t' => $ev_token]);
    } catch (Throwable $e) {
        error_log('auth_register: mark verification token used falhou: ' . $e->getMessage());
    }

    unset($_SESSION['old_inputs']);
    redirectWithSuccess('Cadastro realizado com sucesso! Faça login para começar.');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('auth_register: transaction error: ' . $e->getMessage());
    storeOldInputs();
    redirectWithError('Falha no cadastro. Tente novamente mais tarde.');
}
