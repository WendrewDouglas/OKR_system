<?php
declare(strict_types=1);
ini_set('log_errors','1');
ini_set('error_log', __DIR__ . '/error_log');
header('Content-Type: text/plain; charset=utf-8');

// Autoload
$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) { $autoload = __DIR__ . '/vendor/autoload.php'; }
if (!is_file($autoload)) { http_response_code(500); echo "autoload não encontrado\n"; exit; }
require_once $autoload;

use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/config.php';

function logdbg($msg){ error_log('[MAIL_PROBE] ' . $msg); }

// Para evitar exception interromper tentativas, use exceptions = false
function makeMailer(): PHPMailer {
  $m = new PHPMailer(false);
  $m->SMTPDebug   = 3;
  $m->Debugoutput = static function ($str, $level) { error_log("[SMTP][$level] $str"); };
  $m->isSMTP();
  $m->Host       = SMTP_HOST;
  $m->SMTPAuth   = true;
  $m->Username   = SMTP_USER;
  $m->Password   = trim((string)SMTP_PASS);
  $m->CharSet    = 'UTF-8';
  $m->Timeout    = 20;
  $m->setFrom(SMTP_USER, 'OKR System'); // igual ao usuário
  $m->addAddress('seu-email-de-teste@exemplo.com');
  $m->Subject = 'Probe Titan SMTP';
  $m->Body    = 'Teste SMTP Titan OKR System';
  $m->AltBody = $m->Body;
  return $m;
}

$combos = [
  ['port'=>587, 'secure'=>PHPMailer::ENCRYPTION_STARTTLS, 'auth'=>'LOGIN'],
  ['port'=>587, 'secure'=>PHPMailer::ENCRYPTION_STARTTLS, 'auth'=>'PLAIN'],
  ['port'=>465, 'secure'=>PHPMailer::ENCRYPTION_SMTPS,   'auth'=>'LOGIN'],
  ['port'=>465, 'secure'=>PHPMailer::ENCRYPTION_SMTPS,   'auth'=>'PLAIN'],
];

$lastErr = '';
foreach ($combos as $c) {
  $m = makeMailer();
  $m->Port       = (int)$c['port'];
  $m->SMTPSecure = $c['secure'];
  $m->AuthType   = $c['auth'];

  logdbg("Tentando porta {$m->Port}, secure={$m->SMTPSecure}, auth={$m->AuthType}");
  if ($m->send()) {
    echo "OK: enviado via porta {$m->Port}, auth={$m->AuthType}\n";
    exit;
  }
  $lastErr = $m->ErrorInfo ?: 'Erro desconhecido';
  logdbg("Falhou nessa combinação: " . $lastErr);
}

http_response_code(500);
echo "Falhou em todas as combinações. Último erro: {$lastErr}\n";
