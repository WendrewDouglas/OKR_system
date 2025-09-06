<?php
// auth/mailer.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap_logging.php';


function send_email(string $to, string $subject, string $html, string $textAlt = ''): bool {
  // Se PHPMailer estiver instalado (Composer)
  if (class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
    try {
      $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
      // SMTP (preencha suas credenciais)
      $mail->isSMTP();
      $mail->Host       = 'smtp.seudominio.com'; // EX: smtp.hostgator.com
      $mail->SMTPAuth   = true;
      $mail->Username   = 'no-reply@seudominio.com';
      $mail->Password   = 'SUA_SENHA';
      $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
      $mail->Port       = 587;

      $mail->setFrom('no-reply@seudominio.com', 'OKR System');
      $mail->addAddress($to);
      $mail->isHTML(true);
      $mail->Subject = $subject;
      $mail->Body    = $html;
      $mail->AltBody = $textAlt ?: strip_tags($html);
      $mail->send();
      return true;
    } catch (\Throwable $e) {
      error_log('Mailer error: '.$e->getMessage());
      return false;
    }
  }

  // Fallback: mail() nativo (requer servidor configurado)
  $headers  = "MIME-Version: 1.0\r\n";
  $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
  $headers .= "From: OKR System <no-reply@seudominio.com>\r\n";
  return @mail($to, '=?UTF-8?B?'.base64_encode($subject).'?=', $html, $headers);
}
