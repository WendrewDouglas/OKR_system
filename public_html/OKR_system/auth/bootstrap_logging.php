<?php
// auth/bootstrap_logging.php
declare(strict_types=1);

// Caminho do log (certifique-se de que /auth tem permissão de escrita do usuário do PHP)
const APP_LOG_FILE = __DIR__ . '/error_log';

// Garante log de erros do PHP aqui
ini_set('log_errors', '1');
ini_set('error_log', APP_LOG_FILE);
// Em produção, esconda erros na tela:
ini_set('display_errors', '0');

function app_log(string $message, array $context = []): void {
    // Evita acumular dados muito grandes
    foreach ($context as $k => $v) {
        if (is_string($v) && strlen($v) > 5000) {
            $context[$k] = substr($v, 0, 5000) . '…[trunc]';
        }
    }
    $line = sprintf(
        "[%s] %s %s\n",
        date('c'),
        $message,
        $context ? json_encode($context, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : ''
    );
    error_log($line);
}

// Trata warnings/notices como log estruturado
set_error_handler(function(int $severity, string $message, string $file, int $line): bool {
    app_log('PHP_ERROR', ['severity'=>$severity, 'message'=>$message, 'file'=>$file, 'line'=>$line]);
    // Deixe o PHP continuar o fluxo padrão apenas para fatal
    return true;
});

// Exceptions não capturadas
set_exception_handler(function(Throwable $e): void {
    app_log('UNCAUGHT_EXCEPTION', [
        'type'=> get_class($e),
        'message'=> $e->getMessage(),
        'file'=> $e->getFile(),
        'line'=> $e->getLine(),
        'trace'=> $e->getTraceAsString()
    ]);
});

// Fatal no shutdown
register_shutdown_function(function(): void {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        app_log('FATAL_SHUTDOWN', $err);
    }
});

// Util: mascarar e-mail no log
function mask_email(string $email): string {
    if (!str_contains($email, '@')) return $email;
    [$u, $d] = explode('@', $email, 2);
    $u = mb_substr($u, 0, 1) . str_repeat('*', max(0, mb_strlen($u)-1));
    return $u . '@' . $d;
}
