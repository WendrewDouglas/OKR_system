<?php
// /app/bootstrap/db.php
declare(strict_types=1);

/**
 * Cria PDO para MySQL.
 * Suporta constantes do config.php (DB_HOST, DB_NAME, DB_USER, DB_PASS)
 * e também variáveis de ambiente, se você usar .env.
 */
function db_pdo(): PDO {
  $host = defined('DB_HOST') ? DB_HOST : (getenv('DB_HOST') ?: 'localhost');
  $name = defined('DB_NAME') ? DB_NAME : (getenv('DB_NAME') ?: '');
  $user = defined('DB_USER') ? DB_USER : (getenv('DB_USER') ?: '');
  $pass = defined('DB_PASS') ? DB_PASS : (getenv('DB_PASS') ?: '');
  $port = getenv('DB_PORT') ?: '3306';
  $charset = getenv('DB_CHARSET') ?: 'utf8mb4';

  if ($name === '' || $user === '') {
    // Ajuda a identificar rapidamente quando config/credencial não foi carregado
    throw new RuntimeException('Credenciais do banco não definidas (DB_NAME/DB_USER). Verifique auth/config.php ou variáveis de ambiente.');
  }

  $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";
  $opt = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
  ];

  return new PDO($dsn, $user, $pass, $opt);
}