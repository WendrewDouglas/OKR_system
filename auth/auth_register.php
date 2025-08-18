<?php
// auth/auth_register.php
session_start();

// Função de redirecionamento com mensagem
function redirectWithError($msg) {
    $_SESSION['error_message'] = $msg;
    header('Location: /OKR_system/views/cadastro_site.php');
    exit;
}
function redirectWithSuccess($msg) {
    $_SESSION['success_message'] = $msg;
    header('Location: /OKR_system/views/cadastro_site.php');
    exit;
}
function storeOldInputs() {
    $_SESSION['old_inputs'] = [
        'primeiro_nome'          => $_POST['primeiro_nome']          ?? '',
        'ultimo_nome'            => $_POST['ultimo_nome']            ?? '',
        'email_corporativo'      => $_POST['email_corporativo']      ?? '',
        'telefone'               => $_POST['telefone']               ?? '',
        'empresa'                => $_POST['empresa']                ?? '',
        'faixa_qtd_funcionarios' => $_POST['faixa_qtd_funcionarios'] ?? '',
        // NÃO armazene senha nem senha_confirm
    ];
}

// ==================== PADRÕES DE ESTILO DA EMPRESA ====================
const DEFAULT_BG1 = '#222222'; // escuro
const DEFAULT_BG2 = '#f1c40f'; // claro
const DEFAULT_LOGO_URL = 'https://planningbi.com.br/wp-content/uploads/2025/07/logo-horizontal.jpg';
const DEFAULT_LOGO_MAX_BYTES = 2_097_152; // 2MB

function downloadLogoToDataUri(string $url, int $maxBytes = DEFAULT_LOGO_MAX_BYTES): ?string {
    $raw = null; $mime = null;

    // Tenta cURL
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 4,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'OKRSystem/1.0',
            CURLOPT_HEADER         => true,
        ]);
        $resp = curl_exec($ch);
        if ($resp !== false) {
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $headersRaw = substr($resp, 0, $headerSize);
            $body       = substr($resp, $headerSize);
            if ($body !== false) {
                if (strlen($body) <= $maxBytes) {
                    $raw = $body;
                    // Tenta mime do header
                    if (preg_match('/^Content-Type:\s*([^\r\n;]+)/im', $headersRaw, $m)) {
                        $mime = trim($m[1]);
                    }
                }
            }
        }
        curl_close($ch);
    }

    // Fallback file_get_contents (se permitido)
    if ($raw === null && ini_get('allow_url_fopen')) {
        $ctx = stream_context_create([
            'http' => ['timeout' => 15, 'follow_location' => 1, 'header' => "User-Agent: OKRSystem/1.0\r\n"]
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw !== false) {
            if (strlen($raw) > $maxBytes) $raw = null;
            // tenta extrair Content-Type do wrapper
            if (isset($http_response_header) && is_array($http_response_header)) {
                foreach ($http_response_header as $h) {
                    if (stripos($h, 'Content-Type:') === 0) {
                        $mime = trim(explode(':', $h, 2)[1]);
                        $mime = explode(';', $mime)[0];
                        break;
                    }
                }
            }
        } else {
            $raw = null;
        }
    }

    if ($raw === null) {
        error_log('auth_register: falha ao baixar logo padrão em '.DEFAULT_LOGO_URL);
        return null;
    }

    // Detecta MIME pelo conteúdo se necessário
    if (!$mime && function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
            $det = finfo_buffer($fi, $raw);
            if ($det) $mime = $det;
            finfo_close($fi);
        }
    }
    // Fallback final pelo sufixo do URL
    if (!$mime) $mime = 'image/jpeg';

    // Aceita apenas formatos comuns de imagem
    $allowed = ['image/jpeg','image/jpg','image/png','image/webp','image/svg+xml'];
    if (!in_array(strtolower($mime), $allowed, true)) {
        // força jpeg se extensão aparenta
        if (preg_match('/\.(jpe?g)(\?|$)/i', $url)) $mime = 'image/jpeg';
        else if (preg_match('/\.(png)(\?|$)/i', $url)) $mime = 'image/png';
        else if (preg_match('/\.(svg)(\?|$)/i', $url)) $mime = 'image/svg+xml';
        else if (preg_match('/\.(webp)(\?|$)/i', $url)) $mime = 'image/webp';
        else {
            error_log('auth_register: MIME desconhecido para logo padrão: '.$mime);
            return null;
        }
    }

    $b64 = base64_encode($raw);
    return 'data:'.$mime.';base64,'.$b64;
}

/**
 * Garante 1 registro em company_style para a empresa informada.
 * Se não existir, cria com cores padrão e logo padrão em base64.
 */
function ensureDefaultCompanyStyle(PDO $pdo, int $idCompany, ?int $createdBy = null): void {
    $st = $pdo->prepare('SELECT id_style FROM company_style WHERE id_company = :c LIMIT 1');
    $st->execute([':c' => $idCompany]);
    if ($st->fetch()) return;

    $logoDataUri = downloadLogoToDataUri(DEFAULT_LOGO_URL);
    $sql = 'INSERT INTO company_style (id_company, bg1_hex, bg2_hex, logo_base64, okr_master_user_id, created_by)
            VALUES (:c, :bg1, :bg2, :logo, NULL, :cb)';
    $pdo->prepare($sql)->execute([
        ':c'   => $idCompany,
        ':bg1' => DEFAULT_BG1,
        ':bg2' => DEFAULT_BG2,
        ':logo'=> $logoDataUri, // pode ser null se download falhar
        ':cb'  => $createdBy,
    ]);
}

// 1) CSRF
if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    storeOldInputs();
    redirectWithError('Requisição inválida (CSRF).');
}

// 2) Honeypot
if (!empty($_POST['website'])) { http_response_code(400); exit; }

// 3) HTTPS obrigatório
if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] !== 'on') {
    storeOldInputs();
    redirectWithError('Conexão insegura. Use HTTPS.');
}

// 4) Rate-limit simples
if (!isset($_SESSION['reg_attempts'])) $_SESSION['reg_attempts'] = 0;
if ($_SESSION['reg_attempts']++ >= 20) {
    storeOldInputs();
    redirectWithError('Muitas tentativas. Aguarde alguns minutos.');
}

// 5) Sanitização e validação
$primeiro = trim($_POST['primeiro_nome'] ?? '');
$ultimo   = trim($_POST['ultimo_nome']    ?? '');
$email    = trim($_POST['email_corporativo'] ?? '');
$telefone = trim($_POST['telefone']       ?? '');
$empresa  = trim($_POST['empresa']        ?? '');
$faixa    = trim($_POST['faixa_qtd_funcionarios'] ?? '');
$senha    = $_POST['senha']               ?? '';
$senha_cf = $_POST['senha_confirm']       ?? '';

if ($primeiro === '') { storeOldInputs(); redirectWithError('Primeiro nome é obrigatório.'); }
if ($empresa  === '') { storeOldInputs(); redirectWithError('O nome da organização é obrigatório.'); }

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    storeOldInputs();
    redirectWithError('E-mail inválido.');
}
[, $dominio] = explode('@', $email);
if (!checkdnsrr($dominio, 'MX')) {
    storeOldInputs();
    redirectWithError('Domínio de e-mail não existe.');
}

$senhaRegex = '/(?=^.{8,}$)(?=.*\d)(?=.*[A-Z])(?=.*[a-z])(?=.*\W).*$/';
if (!preg_match($senhaRegex, $senha)) {
    storeOldInputs();
    redirectWithError('Senha não atende aos requisitos mínimos.');
}
if ($senha !== $senha_cf) {
    storeOldInputs();
    redirectWithError('Senhas não conferem.');
}
if ($telefone !== '' && !preg_match('/^\(\d{2}\)\s?\d{4,5}-\d{4}$/', $telefone)) {
    storeOldInputs();
    redirectWithError('Formato de telefone inválido.');
}

// 6) Conexão ao banco via PDO
require_once __DIR__ . '/config.php';
try {
    $dsn = "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    error_log($e->getMessage());
    storeOldInputs();
    redirectWithError('Erro ao conectar ao banco.');
}

// 7) Checagem de unicidade de e-mail
$stmt = $pdo->prepare('SELECT 1 FROM usuarios WHERE email_corporativo = :email');
$stmt->execute(['email' => $email]);
if ($stmt->fetch()) {
    storeOldInputs();
    redirectWithError('E-mail já cadastrado.');
}

// 8) Inserção atômica (com vínculo à company e estilo padrão)
try {
    $pdo->beginTransaction();

    // 8.0) Encontrar ou criar a company pela organização informada (case-insensitive simples)
    $empresaNormalizada = preg_replace('/\s+/', ' ', mb_strtolower($empresa, 'UTF-8'));
    $findCompany = $pdo->prepare('SELECT id_company, organizacao FROM company WHERE LOWER(organizacao) = :org LIMIT 1');
    $findCompany->execute(['org' => $empresaNormalizada]);
    $company = $findCompany->fetch();

    if ($company) {
        $idCompany = (int)$company['id_company'];
        // Garante estilo padrão se ainda não houver
        ensureDefaultCompanyStyle($pdo, $idCompany, null);
    } else {
        // Cria a empresa mínima
        $insertCompany = $pdo->prepare('INSERT INTO company (organizacao, created_at) VALUES (:org, NOW())');
        $insertCompany->execute(['org' => $empresa]);
        $idCompany = (int)$pdo->lastInsertId();

        // Cria estilo padrão imediatamente
        ensureDefaultCompanyStyle($pdo, $idCompany, null);
    }

    // 8.1) usuarios  (agora com id_company)
    $sql1 = 'INSERT INTO usuarios
      (primeiro_nome, ultimo_nome, telefone, empresa, faixa_qtd_funcionarios,
       email_corporativo, dt_cadastro, ip_criacao, id_user_criador, id_permissao,
       id_company)
     VALUES
      (:primeiro, :ultimo, :telefone, :empresa, :faixa,
       :email, NOW(), :ip, :criador, :perm,
       :id_company)';
    $stmt1 = $pdo->prepare($sql1);
    $stmt1->execute([
        'primeiro'   => $primeiro,
        'ultimo'     => $ultimo ?: null,
        'telefone'   => $telefone ?: null,
        'empresa'    => $empresa, // mantém por compatibilidade (pode remover após migração completa)
        'faixa'      => $faixa ?: null,
        'email'      => $email,
        'ip'         => $_SERVER['REMOTE_ADDR'] ?? null,
        'criador'    => $_SESSION['user_id'] ?? null,
        'perm'       => 2,
        'id_company' => $idCompany,
    ]);
    $newId = (int)$pdo->lastInsertId();

    // 8.2) usuarios_credenciais
    $hash = password_hash($senha, PASSWORD_ARGON2ID);
    $stmt2 = $pdo->prepare('INSERT INTO usuarios_credenciais (id_user, senha_hash) VALUES (:id, :hash)');
    $stmt2->execute(['id' => $newId, 'hash' => $hash]);

    // 8.3) usuarios_permissoes
    $stmt3 = $pdo->prepare('INSERT INTO usuarios_permissoes (id_user, id_permissao) VALUES (:id, :perm)');
    $stmt3->execute(['id' => $newId, 'perm' => 'user_admin']);

    $pdo->commit();
    unset($_SESSION['old_inputs']);
    redirectWithSuccess('Cadastro realizado com sucesso! Faça login para começar.');
} catch (Exception $e) {
    $pdo->rollBack();
    error_log($e->getMessage());
    storeOldInputs();
    redirectWithError('Falha no cadastro. Tente novamente mais tarde.');
}
