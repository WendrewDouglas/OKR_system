<?php
declare(strict_types=1);

/**
 * auth_login.php — Login com reCAPTCHA v3 (opcional) + SQL compatível com o schema original
 * Esperado:
 *   usuarios(id_user, primeiro_nome, email_corporativo[, ativo])
 *   usuarios_credenciais(id_user, senha_hash)
 * Adaptativo:
 *   - Se não houver email_corporativo, usa email.
 *   - Se não houver ativo, assume 1 (ativo).
 *   - Se o hash tiver outro nome (password_hash/hash_senha), detecta e usa.
 *
 * Front-end: carregar https://www.google.com/recaptcha/api.js?render=SITE_KEY
 * e enviar recaptcha_token (ou g-recaptcha-response) no POST.
 */

define('LOGIN_DEBUG', true); // mude para false depois de estabilizar

/* =================== LOG em auth/error_log (com fallback) =================== */
define('AUTH_LOG', __DIR__ . '/error_log');
@ini_set('log_errors', '1');
if (!is_file(AUTH_LOG)) { @touch(AUTH_LOG); @chmod(AUTH_LOG, 0664); }
@ini_set('error_log', is_writable(AUTH_LOG) ? AUTH_LOG : ini_get('error_log'));

function alog(string $code, string $msg, array $ctx = []): void {
  $ctx['_ip']  = $_SERVER['REMOTE_ADDR']     ?? '';
  $ctx['_uri'] = $_SERVER['REQUEST_URI']     ?? '';
  error_log(sprintf('%s [%s] %s | %s', date('c'), $code, $msg, json_encode($ctx, JSON_UNESCAPED_UNICODE)));
}

/* =================== Sessão =================== */
@ini_set('session.use_strict_mode', '1');
@ini_set('session.cookie_httponly', '1');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') { @ini_set('session.cookie_secure', '1'); }
if (session_status() === PHP_SESSION_NONE) { session_start(); }

/* =================== Helpers =================== */
function back_with_error(string $msg = 'Credenciais inválidas.', string $code = null): void {
  if ($code) alog($code, $msg);
  if (LOGIN_DEBUG && $code) $msg .= " [{$code}]";
  $_SESSION['error_message'] = $msg;
  header('Location: /OKR_system/views/login.php', true, 302);
  exit;
}
function envget(string $k, string $default = ''): string {
  $v = getenv($k);
  if ($v !== false && $v !== '') return $v;
  if (isset($_ENV[$k]) && $_ENV[$k] !== '') return (string)$_ENV[$k];
  return $default;
}
function verify_recaptcha_v3(string $secret, string $token, string $expectedAction, float $minScore): bool {
  if ($secret === '' || $token === '') return false;
  $post = http_build_query(['secret'=>$secret,'response'=>$token,'remoteip'=>$_SERVER['REMOTE_ADDR'] ?? '']);
  $resp = false;
  if (function_exists('curl_init')) {
    $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
    curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$post, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>8]);
    $resp = curl_exec($ch);
    if ($resp === false) alog('RECAPTCHA_CURL', curl_error($ch));
    curl_close($ch);
  } else {
    $ctx = stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/x-www-form-urlencoded\r\n",'content'=>$post,'timeout'=>8]]);
    $resp = @file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, $ctx);
    if ($resp === false) alog('RECAPTCHA_FOPEN', 'siteverify failed');
  }
  if ($resp === false) return false;
  $data = json_decode($resp, true);
  if (!($data['success'] ?? false)) return false;
  if (!empty($expectedAction) && (($data['action'] ?? '') !== $expectedAction)) return false;
  return (float)($data['score'] ?? 0.0) >= (float)$minScore;
}

/* =================== Apenas POST =================== */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') back_with_error('Método inválido.', 'BAD_METHOD');

/* =================== Entrada =================== */
$email    = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
$password = isset($_POST['password']) ? trim((string)$_POST['password']) : '';
if (!$email || $password === '') back_with_error('E-mail ou senha inválidos.', 'E_INPUT');

/* =================== reCAPTCHA (opcional via .env) =================== */
$captchaProvider = strtolower(envget('CAPTCHA_PROVIDER', ''));
$captchaSiteKey  = envget('CAPTCHA_SITE_KEY', '');
$captchaSecret   = envget('CAPTCHA_SECRET', '');
$minScore        = (float)envget('RECAPTCHA_MIN_SCORE', '0.5');
$captchaToken    = (string)($_POST['recaptcha_token'] ?? $_POST['g-recaptcha-response'] ?? '');
if ($captchaProvider === 'recaptcha' && $captchaSiteKey !== '' && $captchaSecret !== '') {
  if (!verify_recaptcha_v3($captchaSecret, $captchaToken, 'login', $minScore)) {
    back_with_error('Falha na validação de segurança. Tente novamente.', 'E_RECAPTCHA');
  }
}

/* =================== DB =================== */
require __DIR__ . '/config.php';
$pdoOptions = isset($options) && is_array($options) ? $options : [
  PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
  $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS, $pdoOptions);
} catch (Throwable $e) {
  alog('E_DB_CONN', $e->getMessage(), ['host'=>DB_HOST,'db'=>DB_NAME]);
  back_with_error('Indisponível no momento. Tente novamente em instantes.', 'E_DB_CONN');
}

/* =================== Detecção LEVE de colunas =================== */
function colExists(PDO $pdo, string $table, string $col): bool {
  try {
    $st = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE :c");
    $st->execute([':c'=>$col]);
    return (bool)$st->fetchColumn();
  } catch (Throwable $e) {
    alog('DIAG_COL', $e->getMessage(), ['table'=>$table,'col'=>$col]);
    return false;
  }
}
function pick(array $candidates, callable $existsCb): ?string {
  foreach ($candidates as $c) if ($existsCb($c)) return $c;
  return null;
}

$emailCol = pick(['email_corporativo','email'], fn($c)=>colExists($pdo,'usuarios',$c)) ?? 'email_corporativo';
$idCol    = pick(['id_user','user_id','id'],    fn($c)=>colExists($pdo,'usuarios',$c)) ?? 'id_user';
$nameCol  = pick(['primeiro_nome','nome'],      fn($c)=>colExists($pdo,'usuarios',$c)) ?? 'primeiro_nome';
$actCol   = pick(['ativo','is_active','status'],fn($c)=>colExists($pdo,'usuarios',$c)); // opcional

$hashColsCred = array_values(array_filter(['senha_hash','password_hash','hash_senha'], fn($c)=>colExists($pdo,'usuarios_credenciais',$c)));
$hashColCred  = $hashColsCred[0] ?? 'senha_hash'; // preferido
$hasCredTable = true;
try {
  $chk = $pdo->query("SHOW TABLES LIKE 'usuarios_credenciais'")->fetchColumn();
  $hasCredTable = (bool)$chk;
} catch (Throwable $e) {
  $hasCredTable = true; // assume presente como no seu código original
}

alog('DIAG', 'schema', [
  'emailCol'=>$emailCol, 'idCol'=>$idCol, 'nameCol'=>$nameCol, 'actCol'=>$actCol ?: '(none)',
  'credTable'=>$hasCredTable, 'hashColCred'=>$hashColCred
]);

/* =================== Consulta (LEFT JOIN, nomes originais, com fallback leve) =================== */
try {
  $select = "
    SELECT
      u.`{$idCol}`            AS id,
      u.`{$nameCol}`          AS nome,
      u.`{$emailCol}`         AS email,
      ".($actCol ? "u.`{$actCol}` AS ativo," : "1 AS ativo,")."
      ".($hasCredTable ? "c.`{$hashColCred}` AS password_hash" : "NULL AS password_hash")."
    FROM `usuarios` u
    ".($hasCredTable ? "LEFT JOIN `usuarios_credenciais` c ON u.`{$idCol}` = c.`id_user`" : "")."
    WHERE u.`{$emailCol}` = :email
    LIMIT 1
  ";
  $stmt = $pdo->prepare($select);
  $stmt->execute([':email'=>$email]);
  $user = $stmt->fetch();
} catch (Throwable $e) {
  alog('E_DB_QUERY', $e->getMessage(), ['sql'=>'users+creds']);
  back_with_error('Indisponível no momento. Tente novamente em instantes.', 'E_DB_QUERY');
}

if (!$user || empty($user['password_hash']) || !password_verify($password, (string)$user['password_hash'])) {
  back_with_error('E-mail ou senha incorretos.', 'E_AUTH');
}
if (!(int)($user['ativo'] ?? 1)) {
  back_with_error('Conta inativa. Contate o administrador.', 'E_INACTIVE');
}

/* =================== Sessão =================== */
session_regenerate_id(true);
$_SESSION['user_id']    = (string)$user['id'];
$_SESSION['user_name']  = (string)$user['nome'];
$_SESSION['user_email'] = (string)$user['email'];

/* Assinatura opcional */
$pepper = envget('APP_TOKEN_PEPPER', '');
if ($pepper !== '') {
  $_SESSION['session_sig'] = hash_hmac('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '').'|'.($_SERVER['REMOTE_ADDR'] ?? ''), $pepper);
}

/* (Opcional) último login — silencioso se falhar */
try {
  $pdo->prepare("UPDATE `usuarios` SET dt_ultimo_login = NOW() WHERE `{$idCol}` = :id LIMIT 1")
      ->execute([':id'=>$user['id']]);
} catch (Throwable $e) {
  alog('WARN_LAST_LOGIN', $e->getMessage(), ['user_id'=>$user['id']]);
}

/* =================== Redirect =================== */
header('Location: https://planningbi.com.br/OKR_system/dashboard', true, 302);
exit;
