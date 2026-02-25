<?php
// api/avatar_ai.php — Gera avatar (OpenAI Images ou fallback) e salva
declare(strict_types=1);

try {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(0);

    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type');

    session_start();
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success'=>false,'error'=>'Não autenticado']); exit;
    }

    require_once __DIR__ . '/../auth/config.php';

    // Autoload + .env
    $autoloadPath = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoloadPath)) throw new Exception('vendor/autoload.php não encontrado. Execute composer install.');
    require $autoloadPath;

    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();

    // Fallback manual .env (compatível com outros endpoints)
    $envFile = dirname(__DIR__) . '/.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $name  = trim($parts[0]);
                $value = trim($parts[1], " \t\n\r\0\x0B'\"");
                putenv("$name=$value");
            }
        }
    }

    $apiKey   = getenv('OPENAI_API_KEY');
    $provider = strtolower((string)(getenv('AVATAR_PROVIDER') ?: 'openai'));   // openai | dicebear
    $fallback = strtolower((string)(getenv('AVATAR_FALLBACK') ?: 'dicebear')); // dicebear | none

    $model   = getenv('OPENAI_IMAGES_MODEL') ?: 'gpt-image-1';
    $envSize = strtolower((string)(getenv('OPENAI_IMAGES_SIZE') ?: '1024x1024'));
    $target  = (int)(getenv('AVATAR_TARGET_PX') ?: 512); // 0 = não redimensiona

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success'=>false,'error'=>'Use POST']); exit;
    }

    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success'=>false,'error'=>'CSRF inválido']); exit;
    }

    // -------- Inputs --------
    $idUser = (int)($_POST['id_user'] ?? 0);
    $prompt = trim((string)($_POST['prompt'] ?? ''));
    $postSize = isset($_POST['size']) ? strtolower((string)$_POST['size']) : null;
    $mode = strtolower((string)($_POST['mode'] ?? 'save')); // preview | save | commit
    $previewToken = preg_replace('/[^a-zA-Z0-9_\-]/','', (string)($_POST['preview_token'] ?? ''));

    if ($idUser <= 0) { http_response_code(422); echo json_encode(['success'=>false,'error'=>'id_user inválido']); exit; }
    if ($mode !== 'commit' && $prompt === '') { http_response_code(422); echo json_encode(['success'=>false,'error'=>'Prompt vazio']); exit; }

    // Permissões: master pode gerar p/ qualquer; não-master só p/ si
    $pdo = new PDO(
        "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
    );
    $MEU_ID = (int)$_SESSION['user_id'];
    $st = $pdo->prepare("SELECT tudo, habilitado FROM aprovadores WHERE id_user=? LIMIT 1");
    $st->execute([$MEU_ID]);
    $ap = $st->fetch();
    $IS_MASTER = $ap && (int)$ap['habilitado']===1 && (int)$ap['tudo']===1;
    if (!$IS_MASTER && $MEU_ID !== $idUser) {
        http_response_code(403);
        echo json_encode(['success'=>false,'error'=>'Sem permissão para gerar avatar para outro usuário']); exit;
    }

    // -------- Regras de tamanho e prompt final --------
    $allowedSizes = ['1024x1024','1024x1536','1536x1024','auto'];
    $size = $postSize ?: $envSize;
    if (!in_array($size, $allowedSizes, true)) $size = '1024x1024';

    // Prompt base (o "prompt" recebido já contém os atributos coletados no formulário)
    $finalPrompt =
        "Criar avatar realista (foto estilo profissional), busto (peito para cima), enquadramento de perfil para LinkedIn, fundo branco/neutro, PNG. ".
        "Características: ".$prompt.". Iluminação suave, foco nítido, sem texto ou marca d'água.";

    // -------- Helpers de arquivo --------
    $savePng = function(int $uid, string $pngBytes, int $targetPx) : string {
        $dir = realpath(__DIR__.'/../assets/img/avatars');
        if (!$dir) { @mkdir(__DIR__.'/../assets/img/avatars', 0775, true); $dir = realpath(__DIR__.'/../assets/img/avatars'); }
        if (!$dir) throw new Exception('Não foi possível preparar o diretório de avatares.');

        // opcional: redimensiona/centraliza quadrado
        if ($targetPx > 0 && function_exists('imagecreatefromstring')) {
            $src = @imagecreatefromstring($pngBytes);
            if ($src) {
                $w = imagesx($src); $h = imagesy($src);
                $side = min($w, $h);
                $sx = (int)(($w - $side)/2);
                $sy = (int)(($h - $side)/2);

                $dst = imagecreatetruecolor($targetPx, $targetPx);
                imagesavealpha($dst, true);
                $trans = imagecolorallocatealpha($dst, 0, 0, 0, 127);
                imagefill($dst, 0, 0, $trans);

                imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $targetPx, $targetPx, $side, $side);

                ob_start(); imagepng($dst, null, 9); $pngBytes = ob_get_clean() ?: $pngBytes;
                imagedestroy($src); imagedestroy($dst);
            }
        }

        // remove variações antigas e salva
        foreach (['png','jpg','jpeg'] as $e) {
            $p = $dir.DIRECTORY_SEPARATOR.$uid.'.'.$e;
            if (file_exists($p)) @unlink($p);
        }
        $dest = $dir.DIRECTORY_SEPARATOR.$uid.'.png';
        if (file_put_contents($dest, $pngBytes) === false) throw new Exception('Falha ao gravar o arquivo PNG.');
        return '/OKR_system/assets/img/avatars/'.$uid.'.png';
    };

    $savePngTmp = function(int $uid, string $pngBytes, int $targetPx) : array {
        $dir = realpath(__DIR__.'/../assets/img/avatars/tmp');
        if (!$dir) { @mkdir(__DIR__.'/../assets/img/avatars/tmp', 0775, true); $dir = realpath(__DIR__.'/../assets/img/avatars/tmp'); }
        if (!$dir) throw new Exception('Não foi possível preparar o diretório de previews.');

        if ($targetPx > 0 && function_exists('imagecreatefromstring')) {
            $src = @imagecreatefromstring($pngBytes);
            if ($src) {
                $w = imagesx($src); $h = imagesy($src);
                $side = min($w, $h);
                $sx = (int)(($w - $side)/2); $sy = (int)(($h - $side)/2);
                $dst = imagecreatetruecolor($targetPx, $targetPx);
                imagesavealpha($dst, true);
                $trans = imagecolorallocatealpha($dst, 0, 0, 0, 127);
                imagefill($dst, 0, 0, $trans);
                imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $targetPx, $targetPx, $side, $side);
                ob_start(); imagepng($dst, null, 9); $pngBytes = ob_get_clean() ?: $pngBytes;
                imagedestroy($src); imagedestroy($dst);
            }
        }

        $token = bin2hex(random_bytes(8));
        $destFs = $dir.DIRECTORY_SEPARATOR.$uid.'_'.$token.'.png';
        if (file_put_contents($destFs, $pngBytes) === false) throw new Exception('Falha ao gravar preview PNG.');
        $destWeb = '/OKR_system/assets/img/avatars/tmp/'.$uid.'_'.$token.'.png';
        return ['path'=>$destWeb, 'token'=>$token];
    };

    $commitPreview = function(int $uid, string $token) : string {
        $dirTmp = realpath(__DIR__.'/../assets/img/avatars/tmp');
        $dirFin = realpath(__DIR__.'/../assets/img/avatars');
        if (!$dirTmp) { @mkdir(__DIR__.'/../assets/img/avatars/tmp', 0775, true); $dirTmp = realpath(__DIR__.'/../assets/img/avatars/tmp'); }
        if (!$dirFin) { @mkdir(__DIR__.'/../assets/img/avatars', 0775, true); $dirFin = realpath(__DIR__.'/../assets/img/avatars'); }
        if (!$dirTmp || !$dirFin) throw new Exception('Diretórios de avatar não encontrados.');

        $src = $dirTmp.DIRECTORY_SEPARATOR.$uid.'_'.$token.'.png';
        if (!file_exists($src)) throw new Exception('Preview não encontrado para commit.');

        // apaga anteriores
        foreach (['png','jpg','jpeg'] as $e) {
            $p = $dirFin.DIRECTORY_SEPARATOR.$uid.'.'.$e;
            if (file_exists($p)) @unlink($p);
        }
        $dst = $dirFin.DIRECTORY_SEPARATOR.$uid.'.png';
        if (!@rename($src, $dst)) {
            if (!@copy($src, $dst)) throw new Exception('Falha ao mover preview para definitivo.');
            @unlink($src);
        }
        return '/OKR_system/assets/img/avatars/'.$uid.'.png';
    };

    // -------- Commit direto do preview --------
    if ($mode === 'commit') {
        if ($previewToken === '') { http_response_code(422); echo json_encode(['success'=>false,'error'=>'preview_token obrigatório']); exit; }
        $path = $commitPreview($idUser, $previewToken);
        echo json_encode(['success'=>true,'path'=>$path,'source'=>'commit','mode'=>'commit']); exit;
    }

    // -------- Geração (preview/save) --------
    $pngBytes = null;
    $source   = null;

    // wrappers de geração
    $genOpenAI = function(string $sz) use ($apiKey, $model, $finalPrompt): array {
        $payload = ['model'=>$model, 'prompt'=>$finalPrompt, 'size'=>$sz, 'n'=>1];
        $ch = curl_init('https://api.openai.com/v1/images/generations');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer '.$apiKey
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 120,
        ]);
        $res    = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);
        return [$status, $err, $res];
    };

    $genDiceBear = function(string $seed, int $px = 1024): string {
        $url = 'https://api.dicebear.com/9.x/adventurer/png?size='.$px.'&radius=40&backgroundColor=ffffff&seed='.urlencode($seed);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 45,
        ]);
        $png    = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);
        if ($err) throw new Exception('Falha no fallback DiceBear: '.$err);
        if ($status < 200 || $status >= 300 || !$png) throw new Exception('Fallback DiceBear HTTP '.$status);
        return $png;
    };

    if ($provider === 'dicebear') {
        $seed = substr(sha1($prompt.'|'.$idUser), 0, 16);
        $pngBytes = $genDiceBear( $seed, 1024 );
        $source   = 'dicebear';
    } else {
        if (!$apiKey) throw new Exception('OPENAI_API_KEY ausente em .env');

        // 1ª tentativa: tamanho escolhido (ou "auto")
        [$status, $err, $response] = $genOpenAI($size);
        if ($err) throw new Exception('cURL Error: '.$err);

        $retryToAuto = false;
        if ($status === 400 && $response) {
            $p = json_decode($response, true);
            $msg = $p['error']['message'] ?? '';
            $param = $p['error']['param'] ?? '';
            if (stripos($msg, 'Invalid value') !== false && $param === 'size' && $size !== 'auto') {
                $retryToAuto = true;
            }
        }
        if ($retryToAuto) {
            [$status, $err, $response] = $genOpenAI('auto');
        }

        $orgNotVerified = false;
        if ($status === 403 && $response) {
            $p = json_decode($response, true);
            $msg = $p['error']['message'] ?? '';
            if (stripos($msg, 'organization must be verified') !== false) {
                $orgNotVerified = true;
            }
        }

        if ($orgNotVerified) {
            if ($fallback === 'dicebear') {
                $seed = substr(sha1($prompt.'|'.$idUser), 0, 16);
                $pngBytes = $genDiceBear($seed, 1024);
                $source = 'dicebear';
            } else {
                http_response_code(403);
                echo json_encode([
                    'success'=>false,
                    'code'=>'ORG_NOT_VERIFIED',
                    'error'=>'A organização precisa ser verificada para usar gpt-image-1. Ajuste no painel da OpenAI ou habilite fallback.'
                ]);
                exit;
            }
        } else {
            if ($status < 200 || $status >= 300) {
                throw new Exception("OpenAI (images) HTTP $status: $response");
            }
            $resp = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) throw new Exception('Resposta JSON inválida: '.json_last_error_msg());

            $b64 = $resp['data'][0]['b64_json'] ?? null;
            $url = $resp['data'][0]['url']      ?? null;

            if ($b64) {
                $pngBytes = base64_decode($b64, true);
                if ($pngBytes === false) throw new Exception('Falha ao decodificar b64_json.');
            } elseif ($url) {
                $ch2 = curl_init($url);
                curl_setopt_array($ch2, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT        => 120,
                ]);
                $pngBytes = curl_exec($ch2);
                $http2    = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
                $err2     = curl_error($ch2);
                curl_close($ch2);
                if ($err2) throw new Exception('Falha ao baixar imagem: '.$err2);
                if ($http2 < 200 || $http2 >= 300 || !$pngBytes) throw new Exception('Download de imagem retornou HTTP '.$http2);
            } else {
                throw new Exception('Resposta sem b64_json e sem url.');
            }
            $source = 'openai';
        }
    }

    // ------ PREVIEW/SAVE ------
    if ($mode === 'preview') {
        $res = $savePngTmp($idUser, $pngBytes, $target);
        echo json_encode(['success'=>true,'path'=>$res['path'],'preview_token'=>$res['token'],'source'=>$source,'mode'=>'preview']); exit;
    }

    // modo padrão: salva definitivo (sem preview)
    $path = $savePng($idUser, $pngBytes, $target);
    echo json_encode(['success'=>true,'path'=>$path,'source'=>$source,'mode'=>'save']); exit;

} catch (Exception $e) {
    error_log('Avatar AI Error: '.$e->getMessage());
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
