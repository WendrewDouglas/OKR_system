<?php
// /OKR_system/LP/Quizz-01/auth/cargos_list.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

try {
    // <<< CAMINHO CORRIGIDO: sobe 3 níveis e entra em /auth/config.php
    require_once __DIR__ . '/../../../auth/config.php';

    // Monta DSN usando constantes do config
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        DB_HOST,
        DB_NAME,
        defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4'
    );

    // Use as mesmas opções do config se existirem; senão, opções seguras padrão.
    $pdoOptions = $options ?? [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    // Garante collation se veio definido no config
    if (defined('DB_COLLATION') && (!isset($pdoOptions[PDO::MYSQL_ATTR_INIT_COMMAND]))) {
        $pdoOptions[PDO::MYSQL_ATTR_INIT_COMMAND] = sprintf('SET NAMES %s COLLATE %s', DB_CHARSET, DB_COLLATION);
    }

    // Sanidade rápida (ajuda quando APP_DEBUG=true)
    if (APP_DEBUG === true && (!DB_HOST || !DB_NAME)) {
        echo json_encode([
            'ok' => false,
            'error' => 'Variáveis de DB não definidas',
            'debug' => [
                'DB_HOST' => DB_HOST,
                'DB_NAME' => DB_NAME,
                'ENV_PATH' => dirname(__DIR__, 3) . '/.env',
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo = new PDO($dsn, DB_USER, DB_PASS, $pdoOptions);

    // Consulta simples
    $sql = 'SELECT id_cargo, nome FROM lp001_dom_cargos ORDER BY ordem_hierarquia, nome';
    $stmt = $pdo->query($sql);
    $data = $stmt->fetchAll();

    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[cargos_list] ' . $e->getMessage());

    http_response_code(500);
    $payload = ['ok' => false, 'error' => 'Falha ao listar cargos'];

    
    // Debug detalhado quando APP_DEBUG=true
    if (defined('APP_DEBUG') && APP_DEBUG === true) {
        $payload['debug'] = [
            'type'    => get_class($e),
            'message' => $e->getMessage(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),     
            // Útil para problemas de conexão / env
            'dsn'     => isset($dsn) ? $dsn : null,
            'DB_HOST' => defined('DB_HOST') ? DB_HOST : null,
            'DB_NAME' => defined('DB_NAME') ? DB_NAME : null,
            'env_loaded' => [
                'APP_ENV'   => defined('APP_ENV') ? APP_ENV : null,
                'APP_DEBUG' => defined('APP_DEBUG') ? APP_DEBUG : null,
            ],
        ];
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
}
