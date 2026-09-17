<?php
/**
 * auth/notif_optout.php
 * Descadastro dos lembretes de pendência (link do rodapé do e-mail).
 *
 * GET mostra a confirmação; só o POST desliga. Assim um antivírus ou
 * pré-visualizador que "clica" nos links do e-mail não descadastra ninguém.
 * O token é HMAC do id com APP_TOKEN_PEPPER: não exige login e não serve
 * para outro usuário.
 */
declare(strict_types=1);

ini_set('display_errors', '0');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/notif_prefs.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex');

$uid = (int)($_REQUEST['u'] ?? 0);
$tok = (string)($_REQUEST['t'] ?? '');
$valido = $uid > 0 && $tok !== '' && hash_equals(notif_optout_token($uid), $tok);

$estado = 'confirmar';
if (!$valido) {
  $estado = 'invalido';
} elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  try {
    $pdo = new PDO(
      'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
      DB_USER, DB_PASS,
      [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    notif_prefs_salvar($pdo, $uid, notif_prefs_padrao(), $uid);
    $estado = 'feito';
  } catch (Throwable $e) {
    error_log('notif_optout: ' . $e->getMessage());
    $estado = 'erro';
  }
}

$msg = [
  'confirmar' => ['Deixar de receber avisos?', 'Você vai parar de receber os lembretes de apontamento, os relatórios de atraso e o resumo semanal do OKR System, por e-mail e no aplicativo.'],
  'feito'     => ['Pronto, avisos desligados', 'Você não vai mais receber lembretes nem relatórios de pendência. Para voltar a receber, peça ao administrador do OKR System da sua empresa.'],
  'invalido'  => ['Link inválido', 'Este link de descadastro não é válido. Use o link do e-mail mais recente ou fale com o administrador do OKR System.'],
  'erro'      => ['Não foi possível concluir', 'Tente de novo em alguns minutos. Se continuar, fale com o administrador do OKR System.'],
][$estado];
$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Avisos do OKR System</title>
  <style>
    body{ margin:0; min-height:100vh; display:grid; place-items:center; background:#0B1020; font-family:Segoe UI,Arial,Helvetica,sans-serif; padding:16px; box-sizing:border-box; }
    .card{ max-width:440px; width:100%; background:#fff; border-radius:16px; padding:28px; box-shadow:0 20px 50px rgba(0,0,0,.35); }
    .brand{ font-size:12px; font-weight:800; letter-spacing:.12em; color:#B7950B; }
    h1{ font-size:21px; color:#111827; margin:8px 0 10px; }
    p{ color:#4B5563; line-height:1.5; font-size:15px; margin:0 0 20px; }
    button{ background:#F1C40F; color:#111827; border:0; border-radius:10px; padding:12px 20px; font-weight:800; font-size:15px; cursor:pointer; width:100%; }
    button:hover{ filter:brightness(.95); }
  </style>
</head>
<body>
  <main class="card">
    <div class="brand">OKR SYSTEM · PLANNINGBI</div>
    <h1><?= $h($msg[0]) ?></h1>
    <p><?= $h($msg[1]) ?></p>
    <?php if ($estado === 'confirmar'): ?>
      <form method="post">
        <input type="hidden" name="u" value="<?= $uid ?>">
        <input type="hidden" name="t" value="<?= $h($tok) ?>">
        <button type="submit">Sim, deixar de receber</button>
      </form>
    <?php endif; ?>
  </main>
</body>
</html>
