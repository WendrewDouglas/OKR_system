<?php
declare(strict_types=1);

// =============================================================
// Testa a autenticação SMTP de forma isolada.
//
//   php tools/smtp_probe.php                 (só autentica, não envia)
//   php tools/smtp_probe.php destino@dom.com (autentica e envia teste)
//
// Existe porque `sendTransactionalMail()` cai em silêncio no fallback
// mail() quando o SMTP falha — e o fallback quase sempre devolve
// sucesso. Ou seja: pelo retorno dela é impossível saber se o SMTP
// voltou a funcionar. Aqui a resposta é direta.
//
// Nunca imprime a senha.
// =============================================================

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/auth/config.php';

$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php não encontrado. Rode composer install.\n");
    exit(1);
}
require_once $autoload;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

$host = defined('SMTP_HOST') ? SMTP_HOST : '';
$port = defined('SMTP_PORT') ? (int) SMTP_PORT : 587;
$user = defined('SMTP_USER') ? SMTP_USER : '';
$pass = defined('SMTP_PASS') ? SMTP_PASS : '';
$from = defined('SMTP_FROM') ? SMTP_FROM : $user;

echo "Configuração\n";
printf("  host    %s:%d\n", $host === '' ? '(vazio)' : $host, $port);
printf("  usuário %s\n", $user === '' ? '(vazio)' : $user);
printf("  senha   %s\n", $pass === '' ? '(VAZIA)' : str_repeat('*', 8) . ' (' . strlen((string) $pass) . " caracteres)");
printf("  from    %s\n\n", $from);

if ($host === '' || $user === '' || $pass === '') {
    fwrite(STDERR, "Faltam SMTP_HOST / SMTP_USER / SMTP_PASS no .env.\n");
    exit(1);
}

$to = $argv[1] ?? null;

$mail = new PHPMailer(true);
$log  = [];

try {
    $mail->isSMTP();
    $mail->Host       = $host;
    $mail->Port       = $port;
    $mail->SMTPAuth   = true;
    $mail->Username   = $user;
    $mail->Password   = $pass;
    $mail->SMTPSecure = $port === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->CharSet    = 'UTF-8';
    $mail->Timeout    = 20;
    $mail->SMTPOptions = ['ssl' => ['crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT]];

    // Captura o diálogo para mostrar em que etapa parou, sem despejar
    // tudo na tela (o AUTH aparece codificado no nível 3).
    $mail->SMTPDebug   = 2;
    $mail->Debugoutput = static function (string $str) use (&$log): void {
        $log[] = rtrim($str);
    };

    if ($to === null) {
        // Só handshake + AUTH: conecta, autentica e desliga.
        $mail->smtpConnect($mail->SMTPOptions);
        $mail->smtpClose();
        echo "RESULTADO: AUTENTICAÇÃO OK\n\n";
        echo "O SMTP está funcionando. A partir de agora sendTransactionalMail()\n";
        echo "sai pelo Titan, com SPF válido e DKIM — e o roteamento local da\n";
        echo "HostGator deixa de importar, inclusive para @planningbi.com.br.\n";
        exit(0);
    }

    $mail->setFrom($from, defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'OKR System');
    $mail->addAddress($to);
    $mail->Subject = 'Teste de SMTP — ' . date('d/m/Y H:i:s');
    $mail->isHTML(true);
    $mail->Body = '<p style="font:15px/1.6 Georgia,serif">Se esta mensagem chegou, '
        . 'o envio saiu <strong>pelo Titan</strong> (não pelo fallback do servidor). '
        . 'Enviada em ' . date('d/m/Y H:i:s') . '.</p>';
    $mail->send();

    echo "RESULTADO: AUTENTICAÇÃO OK E MENSAGEM ENVIADA para {$to}\n";
    exit(0);

} catch (MailException | Throwable $e) {
    echo "RESULTADO: FALHOU\n";
    echo '  ' . $e->getMessage() . "\n\n";

    // Só as linhas que dizem algo sobre a causa.
    $uteis = array_values(array_filter($log, static function (string $l): bool {
        return (bool) preg_match('/(SERVER ->|Connection failed|SMTP ERROR|AUTH|STARTTLS|535|534|502|Invalid)/i', $l);
    }));
    if ($uteis !== []) {
        echo "Diálogo com o servidor (últimas linhas relevantes):\n";
        foreach (array_slice($uteis, -8) as $l) {
            echo '  ' . mb_strimwidth($l, 0, 150, '…') . "\n";
        }
        echo "\n";
    }

    $msg = $e->getMessage();
    if (stripos($msg, 'authenticate') !== false || stripos($msg, '535') !== false) {
        echo "Causa provável: o Titan não está permitindo acesso de aplicativo\n";
        echo "externo para esta caixa, OU a senha está incorreta.\n";
        echo "  1. Titan > 'Ative o Titan em outros aplicativos' > habilitar para\n";
        echo "     {$user}\n";
        echo "  2. Titan > 'Contas de e-mail' > confirmar que a conta existe\n";
        echo "  3. Se o Titan gerar senha de aplicativo, use ela em SMTP_PASS\n";
    } elseif (stripos($msg, 'connect') !== false || stripos($msg, 'timed out') !== false) {
        echo "Causa provável: conexão bloqueada. Teste a porta 465 (SMTPS)\n";
        echo "alterando SMTP_PORT no .env — alguns hosts bloqueiam a 587.\n";
    }
    exit(1);
}
