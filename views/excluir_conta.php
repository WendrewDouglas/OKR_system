<?php
// views/excluir_conta.php — Página PÚBLICA de solicitação de exclusão de conta.
// Atende ao requisito de "link web" do formulário Data safety (Google Play) e
// à política de exclusão de conta (App Store). Não exige login.
declare(strict_types=1);
require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/functions.php';

$sent = false;
$erro = '';
$DEST = (string) (getenv('DELETE_REQUEST_EMAIL') ?: (defined('SMTP_FROM') && SMTP_FROM ? SMTP_FROM : 'suporte@planningbi.com.br'));

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  // Honeypot anti-spam (campo oculto que humanos não preenchem)
  if (!empty($_POST['website'] ?? '')) {
    $sent = true; // finge sucesso para bots
  } else {
    $nome  = trim((string) ($_POST['nome'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    if ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $erro = 'Informe seu nome e um e-mail válido.';
    } else {
      $nomeSafe  = htmlspecialchars($nome, ENT_QUOTES, 'UTF-8');
      $emailSafe = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
      $ip = htmlspecialchars((string) ($_SERVER['REMOTE_ADDR'] ?? ''), ENT_QUOTES, 'UTF-8');
      $html = "<h3>Solicitação de exclusão de conta — OKR System</h3>"
            . "<p><b>Nome:</b> {$nomeSafe}</p>"
            . "<p><b>E-mail:</b> {$emailSafe}</p>"
            . "<p><b>Data:</b> " . date('Y-m-d H:i:s') . " | <b>IP:</b> {$ip}</p>"
            . "<p>Processe a exclusão da conta e dos dados pessoais associados.</p>";
      try {
        if (function_exists('sendTransactionalMail')) {
          @sendTransactionalMail($DEST, 'Solicitação de exclusão de conta (OKR System)', $html,
            defined('SMTP_FROM') ? SMTP_FROM : 'no-reply@planningbi.com.br',
            defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'OKR System', false);
        }
      } catch (\Throwable $e) {
        error_log('excluir_conta: ' . $e->getMessage());
      }
      error_log("[DELETE_REQUEST] nome={$nome} email={$email} ip=" . ($_SERVER['REMOTE_ADDR'] ?? ''));
      $sent = true;
    }
  }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Excluir conta — OKR System</title>
  <style>
    body{font-family:system-ui,Arial,sans-serif;background:#0d1117;color:#e6e9f2;margin:0;padding:24px;line-height:1.5}
    .card{max-width:640px;margin:0 auto;background:#161b22;border:1px solid #30363d;border-radius:14px;padding:24px}
    h1{font-size:1.4rem;margin:0 0 4px}
    h2{font-size:1.05rem;margin:20px 0 8px;color:#f6c343}
    ul{padding-left:20px} li{margin:4px 0}
    label{display:block;font-size:.85rem;color:#9aa4b2;margin:12px 0 4px}
    input{width:100%;box-sizing:border-box;background:#0d1117;border:1px solid #30363d;border-radius:8px;color:#e6e9f2;padding:10px}
    button{margin-top:16px;background:#f6c343;color:#111;border:0;border-radius:8px;padding:12px 18px;font-weight:700;cursor:pointer}
    .ok{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.35);color:#dcfce7;padding:12px;border-radius:8px;margin-top:12px}
    .err{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.35);color:#fecaca;padding:12px;border-radius:8px;margin-top:12px}
    .hp{position:absolute;left:-9999px}
    .muted{color:#9aa4b2;font-size:.85rem}
  </style>
</head>
<body>
  <div class="card">
    <h1>Excluir sua conta — OKR System</h1>
    <p class="muted">PlanningBI · Aplicativo OKR System</p>

    <h2>Opção 1 — pelo aplicativo (recomendado, imediato)</h2>
    <ul>
      <li>Abra o app OKR System e faça login.</li>
      <li>Vá em <b>Meu Perfil</b> &rarr; <b>Excluir conta</b>.</li>
      <li>Confirme digitando <b>EXCLUIR</b>. A conta e os dados pessoais são removidos na hora.</li>
    </ul>

    <h2>Opção 2 — solicitação por este formulário</h2>
    <p>Se não conseguir acessar o app, preencha abaixo. Processamos a exclusão em até 30 dias.</p>

    <?php if ($sent): ?>
      <div class="ok">Solicitação recebida. Vamos processar a exclusão da conta e dos dados pessoais associados. Você receberá uma confirmação por e-mail.</div>
    <?php else: ?>
      <?php if ($erro): ?><div class="err"><?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
      <form method="POST" autocomplete="off">
        <div class="hp"><label>Não preencha<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
        <label for="nome">Nome</label>
        <input type="text" id="nome" name="nome" required maxlength="120" value="<?= htmlspecialchars($_POST['nome'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        <label for="email">E-mail cadastrado</label>
        <input type="email" id="email" name="email" required maxlength="150" value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit">Solicitar exclusão da conta</button>
      </form>
    <?php endif; ?>

    <h2>O que é excluído</h2>
    <ul>
      <li>Dados de perfil (nome, e-mail, telefone, foto), credenciais de acesso e tokens de notificação.</li>
      <li>Notificações e vínculos de permissão (papéis) da conta.</li>
      <li>Se você for o único usuário da empresa, os dados da organização (OKRs, orçamentos) também são removidos.</li>
    </ul>
    <p class="muted">Alguns registros podem ser retidos pelo tempo exigido por obrigações legais/fiscais antes da eliminação definitiva.</p>
  </div>
</body>
</html>
