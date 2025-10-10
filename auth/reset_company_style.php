<?php
// auth/reset_company_style.php
// Restaura o estilo (cores + logo) para o padrão em company_style da empresa do usuário.

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ob_start();
header('Content-Type: application/json; charset=utf-8');

session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__.'/../auth/acl.php';

if (!isset($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['success'=>false,'error'=>'Não autorizado']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['success'=>false,'error'=>'Método não permitido']); exit;
}
if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
  http_response_code(403);
  echo json_encode(['success'=>false,'error'=>'CSRF inválido']); exit;
}

// ===== Defaults do reset =====
const RESET_BG1 = '#222222';
const RESET_BG2 = '#f1c40f';
const RESET_LOGO_URL = 'https://planningbi.com.br/wp-content/uploads/2025/07/logo-horizontal.jpg';
const RESET_LOGO_MAX_BYTES = 2_097_152; // 2MB

function only_digits_reset($s){ return preg_replace('/\D+/', '', (string)$s); }
function validaCNPJ_reset($cnpj) {
  $cnpj = only_digits_reset($cnpj);
  if (strlen($cnpj) != 14) return false;
  if (preg_match('/^(\\d)\\1{13}$/', $cnpj)) return false;
  $b = array_map('intval', str_split($cnpj));
  $p1=[5,4,3,2,9,8,7,6,5,4,3,2]; $p2=[6,5,4,3,2,9,8,7,6,5,4,3,2];
  $s=0; for($i=0;$i<12;$i++) $s += $b[$i]*$p1[$i]; $d1 = ($s%11<2)?0:11-$s%11;
  if ($b[12] !== $d1) return false;
  $s=0; for($i=0;$i<13;$i++) $s += $b[$i]*$p2[$i]; $d2 = ($s%11<2)?0:11-$s%11;
  return $b[13] === $d2;
}

function downloadLogoDataUri_reset(string $url, int $maxBytes = RESET_LOGO_MAX_BYTES): ?string {
  $raw = null; $mime = null;

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
      if ($body !== false && strlen($body) <= $maxBytes) {
        $raw = $body;
        if (preg_match('/^Content-Type:\s*([^\r\n;]+)/im', $headersRaw, $m)) $mime = trim($m[1]);
      }
    }
    curl_close($ch);
  }

  if ($raw === null && ini_get('allow_url_fopen')) {
    $ctx = stream_context_create([
      'http' => ['timeout'=>15, 'follow_location'=>1, 'header'=>"User-Agent: OKRSystem/1.0\r\n"]
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw !== false && strlen($raw) <= $maxBytes) {
      global $http_response_header;
      if (isset($http_response_header)) {
        foreach ($http_response_header as $h) {
          if (stripos($h, 'Content-Type:') === 0) {
            $mime = trim(explode(':', $h, 2)[1]);
            $mime = explode(';',$mime)[0];
            break;
          }
        }
      }
    } else {
      $raw = null;
    }
  }

  if ($raw === null) {
    error_log('reset_company_style: falha ao baixar logo padrão em '.RESET_LOGO_URL);
    return null;
  }

  if (!$mime && function_exists('finfo_open')) {
    $fi = finfo_open(FILEINFO_MIME_TYPE);
    if ($fi) { $mime = finfo_buffer($fi, $raw) ?: null; finfo_close($fi); }
  }
  if (!$mime) $mime = 'image/jpeg';

  $allowed = ['image/jpeg','image/jpg','image/png','image/webp','image/svg+xml'];
  if (!in_array(strtolower($mime), $allowed, true)) {
    if (preg_match('/\.(jpe?g)(\?|$)/i', $url)) $mime = 'image/jpeg';
    elseif (preg_match('/\.(png)(\?|$)/i', $url)) $mime = 'image/png';
    elseif (preg_match('/\.(svg)(\?|$)/i', $url)) $mime = 'image/svg+xml';
    elseif (preg_match('/\.(webp)(\?|$)/i', $url)) $mime = 'image/webp';
    else return null;
  }

  return 'data:'.$mime.';base64,'.base64_encode($raw);
}

try {
  $pdo = new PDO(
    "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
    DB_USER, DB_PASS,
    [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC ]
  );

  $uid = (int)$_SESSION['user_id'];
  $id_company = (int)($_POST['id_company'] ?? 0);
  if ($id_company <= 0) { http_response_code(422); echo json_encode(['success'=>false,'error'=>'Empresa inválida.']); exit; }

  // 1) Verifica se a empresa pertence ao usuário logado
  $stOwn = $pdo->prepare("
    SELECT 1
      FROM usuarios u
      JOIN company c ON c.id_company = u.id_company
     WHERE u.id_user = :uid AND c.id_company = :cid
     LIMIT 1
  ");
  $stOwn->execute([':uid'=>$uid, ':cid'=>$id_company]);
  if (!$stOwn->fetch()) {
    http_response_code(403);
    echo json_encode(['success'=>false,'error'=>'Acesso negado para esta empresa.']); exit;
  }

  // 2) Exige CNPJ válido
  $stC = $pdo->prepare("SELECT cnpj FROM company WHERE id_company = :cid");
  $stC->execute([':cid'=>$id_company]);
  $cnpj = $stC->fetchColumn();
  if (!$cnpj || !validaCNPJ_reset($cnpj)) {
    http_response_code(422);
    echo json_encode(['success'=>false,'error'=>'A empresa não possui CNPJ válido.']); exit;
  }

  // 3) Monta defaults
  $logo = downloadLogoDataUri_reset(RESET_LOGO_URL);
  // Se não conseguir baixar logo, ainda assim reseta cores (logo pode ficar null)
  $pdo->beginTransaction();

  // 4) Upsert em company_style
  $stSel = $pdo->prepare("SELECT id_style FROM company_style WHERE id_company = :cid LIMIT 1");
  $stSel->execute([':cid'=>$id_company]);
  $row = $stSel->fetch();

  if ($row) {
    $params = [
      ':bg1'=>RESET_BG1, ':bg2'=>RESET_BG2, ':upd_by'=>$uid, ':id'=>$row['id_style']
    ];
    $sql = "UPDATE company_style
               SET bg1_hex=:bg1, bg2_hex=:bg2, updated_by=:upd_by".
           ($logo ? ", logo_base64=:logo" : "")."
             WHERE id_style=:id";
    if ($logo) $params[':logo'] = $logo;
    $pdo->prepare($sql)->execute($params);
    $id_style = (int)$row['id_style'];
  } else {
    $sql = "INSERT INTO company_style
              (id_company, bg1_hex, bg2_hex, logo_base64, okr_master_user_id, created_by)
            VALUES
              (:c, :bg1, :bg2, :logo, NULL, :cb)";
    $pdo->prepare($sql)->execute([
      ':c'=>$id_company, ':bg1'=>RESET_BG1, ':bg2'=>RESET_BG2,
      ':logo'=>$logo, ':cb'=>$uid
    ]);
    $id_style = (int)$pdo->lastInsertId();
  }

  $st = $pdo->prepare("SELECT id_style, id_company, bg1_hex, bg2_hex, logo_base64 FROM company_style WHERE id_style=:id");
  $st->execute([':id'=>$id_style]);
  $record = $st->fetch();

  $pdo->commit();
  echo json_encode(['success'=>true, 'record'=>$record]);
} catch (Throwable $e) {
  if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['success'=>false,'error'=>'Erro ao resetar: '.$e->getMessage()]);
}
