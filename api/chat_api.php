<?php
// api/chat_api.php
try {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(0);

    // Autoload
    $autoloadPath = __DIR__ . '/../vendor/autoload.php';  // OKR_system/vendor/autoload.php
    if (!file_exists($autoloadPath)) {
        throw new Exception('Arquivo vendor/autoload.php não encontrado. Execute composer install.');
    }
    require $autoloadPath;

    // Carrega .env da raiz do OKR_system
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();

    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type');

    // Carrega variáveis de ambiente do .env (um nível acima de auth)
    $envFile = dirname(__DIR__) . '/.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            list($name, $value) = explode('=', $line, 2);
            $name  = trim($name);
            $value = trim($value, " \t\n\r\0\x0B'\"");
            putenv("{$name}={$value}");
        }
    }
    $apiKey = getenv('OPENAI_API_KEY');
    if (!$apiKey) {
        http_response_code(500);
        echo json_encode(['error' => 'Chave de API da OpenAI não encontrada']);
        exit;
    }


    $systemPrompt = getenv('CHAT_SYSTEM_PROMPT') ?: 
    'Você é um assistente útil especialista em OKRs. 
    Sabe diferenciar muito bem:
    1 - objetivos (não é mensuravel nem precisa especificar prazo em seu texto,
        o ciclo dado a ele define o prazo posteriormente. Entretanto precisa ser algo
        muito importante, algo que realmente impacte o futuro da empresa.
        De preferencia alinhado diretamente a missão e visão da empresa. 
        Não deve ser confundido com key results e muito menos com iniciativas ou tarefas)
    2 - key results (é algo que precisa ser alcançado,  precisa ser SMART e não pode ser uma tarefa, mas sim uma meta relevante)
    3 - iniciativas (iniciativas são tarefas a serem executadas para alcançar os key results, não traduzem valor mas favorecem
        o atingimento do key result).
        Responda de forma curta, breve e direta como se estivesse em um chat. pode incluir emojis.';
    if (!$apiKey) {
        throw new Exception('OPENAI_API_KEY não configurada.');
    }

    // Lê entrada JSON
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (!is_array($input) || empty($input['message'])) {
        throw new Exception('Mensagem inválida ou vazia no request.');
    }
    $message = trim($input['message']);

    // Prepara payload para OpenAI
    $payload = [
        'model' => 'gpt-4o-mini',
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $message]
        ],
        'max_tokens'  => 150,
        'temperature' => 0.4,
    ];

    // Executa cURL
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload)
    ]);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        throw new Exception('cURL Error: ' . $curlErr);
    }
    if ($status < 200 || $status >= 300) {
        throw new Exception("OpenAI retornou status $status: $response");
    }

    // Decodifica resposta
    $resp = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Resposta OpenAI JSON inválido: ' . json_last_error_msg());
    }

    // Extrai e imprime
    $replyText = $resp['choices'][0]['message']['content'] ?? 'Sem resposta.';
    echo json_encode(['reply' => $replyText]);

} catch (Exception $e) {
    error_log('Chat API Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['reply' => 'Erro interno no servidor.', 'error' => $e->getMessage()]);
}
