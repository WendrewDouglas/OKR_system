<?php
// tools/backfill_company_style.php
declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../auth/config.php';

const DEFAULT_BG1 = '#222222';
const DEFAULT_BG2 = '#f1c40f';
const DEFAULT_LOGO_URL = 'https://planningbi.com.br/wp-content/uploads/2025/07/logo-horizontal.jpg';
const DEFAULT_LOGO_MAX_BYTES = 2097152; // 2MB

function downloadLogoToDataUri(string $url, int $maxBytes = DEFAULT_LOGO_MAX_BYTES): ?string {
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
        if (preg_match('/^Content-Type:\s*([^\r\n;]+)/im', $headersRaw, $m)) {
          $mime = trim($m[1]);
        }
      }
    }
    curl_close($ch);
  }

  if ($raw === null && ini_get('allow_url_fopen')) {
    $ctx = stream_context_create([
      'http' => ['timeout' => 15, 'follow_location' => 1, 'header' => "User-Agent: OKRSystem/1.0\r\n"]
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw !== false) {
      if (strlen($raw) > $maxBytes) $raw = null;
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

  if ($raw === null) return null;

  if (!$mime && function_exists('finfo_open')) {
    $fi = finfo_open(FILEINFO_MIME_TYPE);
    if ($fi) { $det = finfo_buffer($fi, $raw); if ($det) $mime = $det; finfo_close($fi); }
  }
  if (!$mime) $mime = 'image/jpeg';

  $allowed = ['image/jpeg','image/jpg','image/png','image/webp','image/svg+xml'];
  if (!in_array(strtolower($mime), $allowed, true)) {
    if (preg_match('/\.(jpe?g)(\?|$)/i', $url))      $mime = 'image/jpeg';
    elseif (preg_match('/\.(png)(\?|$)/i', $url))    $mime = 'image/png';
    elseif (preg_match('/\.(svg)(\?|$)/i', $url))    $mime = 'image/svg+xml';
    elseif (preg_match('/\.(webp)(\?|$)/i', $url))   $mime = 'image/webp';
    else return null;
  }

  $b64 = base64_encode($raw);
  return 'data:' . $mime . ';base64,' . $b64;
}

try {
  $pdo = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
    DB_USER,
    DB_PASS,
    [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC ]
  );

  // Baixa logo uma única vez (se falhar, seguimos sem logo para não travar)
  $logo = downloadLogoToDataUri(DEFAULT_LOGO_URL);

  $pdo->beginTransaction();

  // 1) Inserir estilos padrão nas empresas sem registro em company_style
  $ids = $pdo->query("
    SELECT c.id_company
    FROM company c
    LEFT JOIN company_style s ON s.id_company = c.id_company
    WHERE s.id_company IS NULL
  ")->fetchAll(PDO::FETCH_COLUMN);

  $ins = $pdo->prepare("
    INSERT INTO company_style (id_company, bg1_hex, bg2_hex, logo_base64, okr_master_user_id, created_by)
    VALUES (:c, :bg1, :bg2, :logo, NULL, :cb)
  ");

  $inserted = 0;
  foreach ($ids as $cid) {
    $ins->execute([
      ':c'   => (int)$cid,
      ':bg1' => DEFAULT_BG1,
      ':bg2' => DEFAULT_BG2,
      ':logo'=> $logo,          // pode ser null se o download falhar
      ':cb'  => 1               // ajuste se quiser registrar o usuário "sistema"
    ]);
    $inserted++;
  }

  // 2) Normalizar registros existentes com campos nulos/vazios
  $upd1 = $pdo->prepare("UPDATE company_style SET bg1_hex = :bg1 WHERE (bg1_hex IS NULL OR bg1_hex = '')");
  $upd1->execute([':bg1' => DEFAULT_BG1]);

  $upd2 = $pdo->prepare("UPDATE company_style SET bg2_hex = :bg2 WHERE (bg2_hex IS NULL OR bg2_hex = '')");
  $upd2->execute([':bg2' => DEFAULT_BG2]);

  if ($logo) {
    $upd3 = $pdo->prepare("UPDATE company_style SET logo_base64 = :logo WHERE (logo_base64 IS NULL OR logo_base64 = '')");
    $upd3->execute([':logo' => $logo]);
  }

  $pdo->commit();

  echo "Backfill concluído.\n";
  echo "Empresas novas com estilo criado: {$inserted}\n";
  echo "Cores/Logo padronizados onde estavam ausentes.\n";
} catch (Throwable $e) {
  if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
  fwrite(STDERR, "Erro no backfill: " . $e->getMessage() . PHP_EOL);
  exit(1);
}
