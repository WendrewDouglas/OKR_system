<?php
// OKR_system/includes/logger.php
declare(strict_types=1);

define('OKR_LOG_DIR', __DIR__ . '/../logs');

function okr_init_logger(): void {
    if (!is_dir(OKR_LOG_DIR)) {
        @mkdir(OKR_LOG_DIR, 0750, true);
    }
    // Protege contra acesso web (Apache)
    $ht = OKR_LOG_DIR . '/.htaccess';
    if (!file_exists($ht)) {
        @file_put_contents($ht, "Require all denied\n");
    }
}

function okr_mask(array $data): array {
    $sensitive = ['senha','password','pass','token','csrf','authorization','secret','api_key'];
    $masked = [];
    foreach ($data as $k => $v) {
        $lk = mb_strtolower((string)$k);
        $masked[$k] = in_array($lk, $sensitive, true) ? '***' : (is_array($v) ? okr_mask($v) : $v);
    }
    return $masked;
}

function okr_log_path(): string {
    $page = pathinfo($_SERVER['SCRIPT_NAME'] ?? 'cli', PATHINFO_FILENAME);
    $date = date('Ymd');
    return OKR_LOG_DIR . "/{$page}-{$date}.log";
}

/** Retorna um event_id curto para você exibir ao usuário em erros */
function okr_event_id(): string {
    try { return bin2hex(random_bytes(4)); } catch (\Throwable $e) { return (string)mt_rand(); }
}

/**
 * Níveis sugeridos: DEBUG, INFO, NOTICE, WARNING, ERROR, CRITICAL
 * $context será serializado em JSON (não logamos segredos)
 */
function okr_log(string $level, string $message, array $context = []): string {
    okr_init_logger();
    $eid = $context['event_id'] ?? okr_event_id();
    $base = [
        'event_id' => $eid,
        'ts'       => date('c'),
        'page'     => pathinfo($_SERVER['SCRIPT_NAME'] ?? 'cli', PATHINFO_BASENAME),
        'user_id'  => $_SESSION['user_id'] ?? null,
        'method'   => $_SERVER['REQUEST_METHOD'] ?? null,
        'uri'      => $_SERVER['REQUEST_URI'] ?? null,
        'ip'       => $_SERVER['REMOTE_ADDR'] ?? null,
    ];
    if (!empty($_POST)) { $base['post'] = okr_mask($_POST); }
    if (!empty($_GET))  { $base['get']  = $_GET; }

    $payload = array_merge($base, $context);
    $line = sprintf(
        "%s\t%s\t%s\t%s\n",
        $base['ts'],
        strtoupper($level),
        $message,
        json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
    );

    @file_put_contents(okr_log_path(), $line, FILE_APPEND | LOCK_EX);
    return $eid;
}

// Captura erros/ exceções fatais também
set_error_handler(function($severity,$message,$file,$line){
    okr_log('ERROR', 'PHP_ERROR', ['severity'=>$severity,'message'=>$message,'file'=>$file,'line'=>$line]);
});
set_exception_handler(function(Throwable $e){
    okr_log('CRITICAL', 'UNCAUGHT_EXCEPTION', [
        'message'=>$e->getMessage(),
        'code'=>$e->getCode(),
        'file'=>$e->getFile(),
        'line'=>$e->getLine(),
    ]);
    http_response_code(500);
});
register_shutdown_function(function(){
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR], true)) {
        okr_log('CRITICAL', 'FATAL_SHUTDOWN', $e);
    }
});
