<?php
// Recupera o caminho solicitado
$path = isset($_GET['path']) ? $_GET['path'] : '';

// Remove barras e caracteres indesejados
$path = trim($path, '/');
$path = basename($path); // segurança

// Define caminho do arquivo correspondente em views/
$targetFile = __DIR__ . '/views/' . $path . '.php';

if (file_exists($targetFile)) {
    require $targetFile;
} else {
    http_response_code(404);
    echo "<h1>404 - Página não encontrada</h1>";
}
?>
