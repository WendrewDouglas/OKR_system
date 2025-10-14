<?php
declare(strict_types=1);

/**
 * Login + reCAPTCHA (v2 invisível / v3 clássico / Enterprise REST)
 * - Carrega .env/config ANTES de ler variáveis.
 * - Bloqueia quando provider ativo mas token ausente ou inválido.
 * - Compatível com seu schema (auto-descoberta de colunas).
 */

define('LOGIN_DEBUG', true); // mude para false após estabilizar

/* ===== Sessão endurecida ===== */
@ini_set('session.use_strict_mode', '1');
@ini_set('session.cookie_httponly', '1');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') { @ini_set('session.cookie_secure', '1'); }
if (session_status() === PHP_SESSION_NONE) { session_start(); }

/* ===== Carrega .env/config ANTES de ler provider/chaves ===== */
require __DIR__ . '/config.php'; // ESSENCIAL

/* ===== Log centralizado ===== */
define('AUTH_LOG', __DIR__ . '/error_log');
@ini_set('log_errors', '1');
if (!is_file(AUTH_LOG)) { @touch(AUTH_LOG); @chmod(AUTH_LOG, 0664); }
@ini_set('error_log', is_writable(AUTH_LOG) ? AUTH_LOG : ini_get('error_log'));

function alog(string $code, string $msg, array $ctx = []): void {
  $ctx['_ip']  = $_SERVER['REMOTE_ADDR']      ?? '';
  $ctx['_ua']  = $_SERVER['HTTP_USER_AGENT']  ?? '';
  $ctx['_uri'] = $_SERVER['REQUEST_URI']      ?? '';
  error_log(sprintf('%s [%s] %s | %s', date('c'), $code, $msg, json_encode($ctx, JSON_UNESCAPED_UNICODE)));
}

/* ===== Helpers ===== */
function envget(string $k, string $default = ''): string {
  $v = getenv($k);
  if ($v !== false && $v !== '') return $v;
  if (isset($_ENV[$k]) && $_ENV[$k] !== '') return (string)$_ENV[$k];
  return $default;
}
function back_with_error(string $msg, ?string $code = null, array $ctx = []): never {
  if ($code) alog($code, $msg, $ctx);
  if (LOGIN_DEBUG && $code) $msg .= " [{$code}]";
  $_SESSION['error_message'] = $msg;
  header('Location: /OKR_system/views/login.php', true, 302);
  exit;
}

/* ===== Verificadores reCAPTCHA ===== */

/**
 * Enterprise (REST)
 */
function verify_recaptcha_enterprise(
  string $projectId, string $apiKey, string $siteKey,
  string $token, string $expectedAction, float $minScore
): array {
  if ($projectId === '' || $apiKey === '' || $siteKey === '' || $token === '') {
    return ['ok'=>false, 'reason'=>'MISSING_PARAMS'];
  }
  $url = 'https://recaptchaenterprise.googleapis.com/v1/projects/' .
         rawurlencode($projectId) . '/assessments?key=' . rawurlencode($apiKey);

  $payload = ['event'=>[
    'siteKey'        => $siteKey,
    'token'          => $token,
    'expectedAction' => $expectedAction,
  ]];
  $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

  $resp = false; $err = null;
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_POST           => true,
      CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
      CURLOPT_POSTFIELDS     => $body,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT        => 10,
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) $err = curl_error($ch);
    curl_close($ch);
  } else {
    $ctx = stream_context_create(['http'=>[
      'method'  => 'POST',
      'header'  => "Content-Type: application/json\r\n",
      'content' => $body,
      'timeout' => 10,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) $err = 'file_get_contents failed';
  }

  if ($resp === false) return ['ok'=>false, 'reason'=>'HTTP_FAIL', 'debug'=>$err];

  $data = json_decode($resp, true);
  if (!is_array($data))                  return ['ok'=>false, 'reason'=>'JSON_PARSE', 'debug'=>substr((string)$resp,0,200)];
  if (isset($data['error']))            return ['ok'=>false, 'reason'=>'API_ERROR',  'debug'=>$data['error']];

  $props = $data['tokenProperties'] ?? [];
  if (empty($props['valid'])) {
    return ['ok'=>false, 'reason'=>'TOKEN_INVALID', 'invalidReason'=>$props['invalidReason'] ?? 'UNKNOWN', 'raw'=>$data];
  }

  $action = (string)($props['action'] ?? '');
  if ($expectedAction !== '' && $action !== $expectedAction) {
    return ['ok'=>false, 'reason'=>'ACTION_MISMATCH', 'raw'=>$data];
  }

  $score   = (float)($data['riskAnalysis']['score']   ?? 0.0);
  $reasons = (array)($data['riskAnalysis']['reasons'] ?? []);
  return ['ok'=>($score >= $minScore), 'score'=>$score, 'reasons'=>$reasons, 'raw'=>$data];
}

/**
 * Clássico (compatível com v2 invisível e v3)
 * - v2: retorna apenas success/hostname/challenge_ts -> aprovamos se success=true
 * - v3: tem success/score/(action) -> aplicamos minScore e (action) opcional
 */
function verify_recaptcha_classic(string $secret, string $token, string $expectedAction, float $minScore): bool {
  if ($secret === '' || $token === '') return false;

  $post = http_build_query([
    'secret'   => $secret,
    'response' => $token,
    'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
  ]);

  $resp = false;
  if (function_exists('curl_init')) {
    $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
    curl_setopt_array($ch, [
      CURLOPT_POST           => true,
      CURLOPT_POSTFIELDS     => $post,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT        => 10,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
  } else {
    $ctx = stream_context_create([
      'http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => $post,
        'timeout' => 10,
      ]
    ]);
    $resp = @file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, $ctx);
  }
  if ($resp === false) return false;

  $data = json_decode($resp, true);

  // v2 invisível: apenas success (sem score/action)
  if (isset($data['success']) && $data['success'] === true && !isset($data['score'])) {
    return true;
  }

  // v3: exige success, compara action (se veio) e score
  if (!($data['success'] ?? false)) return false;
  if (isset($data['action']) && $expectedAction !== '' && $data['action'] !== $expectedAction) {
    return false;
  }
  $score = (float)($data['score'] ?? 0.0);
  return $score >= $minScore;
}

/* ===== Método ===== */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  back_with_error('Método inválido.', 'BAD_METHOD');
}

/* ===== Entrada ===== */
$email    = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
$password = isset($_POST['password']) ? trim((string)$_POST['password']) : '';
if (!$email || $password === '') {
  back_with_error('E-mail ou senha inválidos.', 'E_INPUT', ['email_hash'=>sha1((string)$email)]);
}

/* ===== reCAPTCHA ===== */
$providerRaw = envget('CAPTCHA_PROVIDER', 'off'); // pode vir com comentários no .env
$provider    = strtolower(trim(preg_split('/\s*#/', (string)$providerRaw, 2)[0])); // normaliza
$siteKey     = envget('CAPTCHA_SITE_KEY', '');
$secret      = envget('CAPTCHA_SECRET', '');
$project     = envget('RECAPTCHA_ENT_PROJECT', '');
$apiKey      = envget('RECAPTCHA_ENT_API_KEY', '');
$minScore    = (float)envget('RECAPTCHA_MIN_SCORE', '0.5');
$token       = (string)($_POST['recaptcha_token'] ?? $_POST['g-recaptcha-response'] ?? '');
$action      = (string)($_POST['recaptcha_action'] ?? 'login');

alog('RCFG', 'captcha cfg', ['provider'=>$provider, 'hasSiteKey'=>($siteKey!=='')]);

if ($provider === 'recaptcha_enterprise') {
  if ($token === '') {
    back_with_error('Falha na validação de segurança. Tente novamente.', 'E_RECAPTCHA_NO_TOKEN');
  }
  $res = verify_recaptcha_enterprise($project, $apiKey, $siteKey, $token, $action, $minScore);
  if (!$res['ok']) {
    alog('E_RECAPTCHA_ENT', 'falha enterprise', ['reason'=>$res['reason'] ?? 'unknown', 'debug'=>$res['debug'] ?? null]);
    back_with_error('Falha na validação de segurança. Tente novamente.', 'E_RECAPTCHA_ENT');
  }
  alog('RECAPTCHA_ENT_OK', 'score ok', ['score'=>$res['score'] ?? null, 'reasons'=>$res['reasons'] ?? []]);

} elseif ($provider === 'recaptcha') {
  if ($token === '' || !verify_recaptcha_classic($secret, $token, $action, $minScore)) {
    back_with_error('Falha na validação de segurança. Tente novamente.', 'E_RECAPTCHA');
  }
  alog('RECAPTCHA_OK', 'clássico ok', []);

} else {
  // Se quiser obrigar reCAPTCHA sempre, troque para back_with_error aqui.
  alog('RECAPTCHA_SKIP', 'provider off/indefinido', []);
}

/* ===== DB ===== */
$pdoOptions = isset($options) && is_array($options) ? $options : [
  PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES   => false,
];
try {
  $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS, $pdoOptions);
} catch (Throwable $e) {
  alog('E_DB_CONN', $e->getMessage(), ['host'=>DB_HOST, 'db'=>DB_NAME]);
  back_with_error('Indisponível no momento. Tente novamente em instantes.', 'E_DB_CONN');
}

/* ===== Descoberta leve de colunas ===== */
function colExists(PDO $pdo, string $table, string $col): bool {
  try {
    $like = $pdo->quote($col); // evita 1064
    $sql  = "SHOW COLUMNS FROM `{$table}` LIKE {$like}";
    return (bool)$pdo->query($sql)->fetchColumn();
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
$hashColCred  = $hashColsCred[0] ?? 'senha_hash';
$hasCredTable = (bool)$pdo->query("SHOW TABLES LIKE 'usuarios_credenciais'")->fetchColumn();

/* ===== Query ===== */
try {
  $sql = "
    SELECT
      u.`{$idCol}`    AS id,
      u.`{$nameCol}`  AS nome,
      u.`{$emailCol}` AS email,
      ".($actCol ? "u.`{$actCol}` AS ativo," : "1 AS ativo,")."
      ".($hasCredTable ? "c.`{$hashColCred}` AS password_hash" : "NULL AS password_hash")."
    FROM `usuarios` u
    ".($hasCredTable ? "LEFT JOIN `usuarios_credenciais` c ON u.`{$idCol}` = c.`id_user`" : "")."
    WHERE u.`{$emailCol}` = :email
    LIMIT 1
  ";
  $st = $pdo->prepare($sql);
  $st->execute([':email'=>$email]);
  $user = $st->fetch();
} catch (Throwable $e) {
  alog('E_DB_QUERY', $e->getMessage(), ['sql'=>'users+creds']);
  back_with_error('Indisponível no momento. Tente novamente em instantes.', 'E_DB_QUERY');
}

/* ===== Autenticação ===== */
if (!$user || empty($user['password_hash']) || !password_verify($password, (string)$user['password_hash'])) {
  back_with_error('E-mail ou senha incorretos.', 'E_AUTH');
}
if (!(int)($user['ativo'] ?? 1)) {
  back_with_error('Conta inativa. Contate o administrador.', 'E_INACTIVE');
}

/* ===== Sessão ===== */
session_regenerate_id(true);
$_SESSION['user_id']    = (string)$user['id'];
$_SESSION['user_name']  = (string)$user['nome'];
$_SESSION['user_email'] = (string)$user['email'];

$pepper = envget('APP_TOKEN_PEPPER', '');
if ($pepper !== '') {
  $_SESSION['session_sig'] = hash_hmac('sha256',
    ($_SERVER['HTTP_USER_AGENT'] ?? '').'|'.($_SERVER['REMOTE_ADDR'] ?? ''), $pepper);
}

/* ===== Último login (se existir a coluna) ===== */
if (colExists($pdo, 'usuarios', 'dt_ultimo_login')) {
  try {
    $pdo->prepare("UPDATE `usuarios` SET dt_ultimo_login = NOW() WHERE `{$idCol}` = :id LIMIT 1")
        ->execute([':id'=>$user['id']]);
  } catch (Throwable $e) {
    alog('WARN_LAST_LOGIN', $e->getMessage(), ['user_id'=>$user['id']]);
  }
}

/* ===== Redirect ===== */
header('Location: https://planningbi.com.br/OKR_system/dashboard', true, 302);
exit;
