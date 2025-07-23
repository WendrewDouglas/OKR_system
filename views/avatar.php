<?php
// views/avatar.php
session_start();
require_once __DIR__ . '/../auth/config.php';

// só quem estiver logado pode ver
if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 403 Forbidden');
    exit;
}
$id_user = $_SESSION['user_id'];

// conecta
try {
    $dsn = "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    header('HTTP/1.1 500 Internal Server Error');
    exit;
}

// busca o Data-URI
$stmt = $pdo->prepare("SELECT imagem_url FROM usuarios WHERE id_user = :id");
$stmt->execute([':id' => $id_user]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

// se tiver Data-URI válido, decodifica e envia como binário
if (!empty($row['imagem_url'])
    && preg_match('#^data:(image/(?:png|jpeg));base64,(.+)$#', $row['imagem_url'], $m)
) {
    $mime = $m[1];
    $data = base64_decode($m[2]);
    header("Content-Type: {$mime}");
    echo $data;
    exit;
}

// caso contrário, devolve um arquivo de fallback
$fallback = __DIR__ . '/../assets/img/user-avatar.jpeg';
if (is_file($fallback)) {
    header('Content-Type: image/jpeg');
    readfile($fallback);
}
exit;
