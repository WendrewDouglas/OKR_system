<?php
/**
 * Envia o e-mail de recuperação de senha com link contendo o token.
 *
 * @param string $to    E-mail do usuário
 * @param string $token Token gerado para o reset
 */
function sendPasswordResetEmail(string $to, string $token): bool
{
    // Endereço de quem manda (deve ser um remetente autorizado no seu servidor)
    $from = 'no-reply@planningbi.com.br';
    $subject = 'Recuperação de senha – OKR System';

    // Monta o link; crie a página password_reset.php que recebe o token
    $link = 'https://planningbi.com.br/OKR_system/views/password_reset.php?token=' . urlencode($token);

    // Corpo do e-mail em HTML
    $message = "
    <html>
    <head>
      <title>Recuperação de Senha</title>
    </head>
    <body>
      <p>Olá,</p>
      <p>Clicando no link abaixo você poderá redefinir sua senha do OKR System:</p>
      <p><a href=\"{$link}\">Redefinir minha senha</a></p>
      <p>Este link expira em 1 hora.</p>
      <p>Se não foi você quem solicitou, ignore esta mensagem.</p>
      <hr>
      <p>PlanningBI &ndash; OKR System</p>
    </body>
    </html>
    ";

    // Cabeçalhos
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: OKR System <{$from}>\r\n";

    return mail($to, $subject, $message, $headers);
}
