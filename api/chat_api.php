<?php
require __DIR__ . '/../vendor/autoload.php';  // ajuste o caminho
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
$apiKey = $_ENV['OPENAI_API_KEY'];

header('Content-Type: application/json');
$input = json_decode(file_get_contents('php://input'), true);
$message = $input['message'] ?? '';

if (!$apiKey) {
    http_response_code(500);
    echo json_encode(['reply' => 'Chave da API não configurada.']);
    exit;
}

$data = [
    'model' => 'gpt-3.5-turbo',
    'messages' => [
        ['role' => 'system', 'content' => 'Você é um assistente útil.'],
        ['role' => 'user', 'content' => $message]
    ]
];

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $apiKey,
    'Content-Type: application/json'
]);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
if (curl_errno($ch)) {
    $reply = 'Erro ao conectar à API.';
} else {
    $resp = json_decode($response, true);
    $reply = $resp['choices'][0]['message']['content'] ?? 'Sem resposta.';
}
curl_close($ch);

echo json_encode(['reply' => $reply]);
