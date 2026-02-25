<?php
require __DIR__ . '/config.php';

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        $options
    );
    echo 'Conexão bem-sucedida ao banco de dados!';
} catch (PDOException $e) {
    echo 'Erro ao conectar: ' . $e->getMessage();
}
