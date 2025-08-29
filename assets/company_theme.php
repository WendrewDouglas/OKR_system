<?php
// OKR_system/assets/company_theme.php
declare(strict_types=1);

// ======== HEADERS (antes de qualquer saída) ========
header('Content-Type: text/css; charset=utf-8');
// cache privado por usuário; pode anular com ?nocache=1
if (isset($_GET['nocache'])) {
  header('Cache-Control: private, no-store, max-age=0');
} else {
  header('Cache-Control: private, max-age=300, must-revalidate');
}
header('Vary: Cookie'); // evita cache cruzado entre usuários

require_once __DIR__ . '/../auth/config.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$debug = []; // será impresso como comentário CSS no final

// ======== 1) Identidade da company ========
$companyId = $_SESSION['company_id'] ?? 1;
$debug[] = "company_id={$companyId} (session)";

// ======== 2) Defaults seguros ========
$bg1 = '#222222';
$bg2 = '#F1C40F';
$updatedAt = gmdate('c');

// ======== 3) Buscar tema no DB com try/catch ========
try {
  $pdo = new PDO(
    "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
  );

  $sql = "SELECT bg1_hex, bg2_hex, logo_base64, COALESCE(updated_at, NOW()) AS updated_at
          FROM company_style
          WHERE id_company = :cid
          ORDER BY id_style DESC
          LIMIT 1";
  $st = $pdo->prepare($sql);
  $st->execute([':cid' => $companyId]);
  if ($row = $st->fetch()) {
    $sanitize = function($hex, $fb) {
      if (!is_string($hex)) return $fb;
      $hex = trim($hex);
      return preg_match('/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $hex) ? strtoupper($hex) : $fb;
    };
    $bg1 = $sanitize($row['bg1_hex'] ?? '', $bg1);
    $bg2 = $sanitize($row['bg2_hex'] ?? '', $bg2);
    $updatedAt = $row['updated_at'] ?? $updatedAt;
    $debug[] = "db: ok (bg1={$bg1}, bg2={$bg2}, updated_at={$updatedAt})";
  } else {
    $debug[] = "db: sem registro para a company; usando defaults";
  }
} catch (Throwable $e) {
  // Nunca quebra o CSS; só registra no comentário
  $debug[] = "db_error: ".substr($e->getMessage(), 0, 180);
}

// ======== 4) Cálculo de contraste e hovers ========
$yiq = function(string $hex) {
  $h = ltrim($hex, '#'); if (strlen($h) === 3) { $h = preg_replace('/(.)/', '$1$1', $h); }
  $r = hexdec(substr($h,0,2)); $g = hexdec(substr($h,2,2)); $b = hexdec(substr($h,4,2));
  return (($r*299)+($g*587)+($b*114))/1000;
};
$onBg1 = $yiq($bg1) >= 128 ? '#111111' : '#FFFFFF';
$onBg2 = '#111111'; // textos geralmente escuros sobre tons claros/dourado

$shade = function(string $hex, float $p) {
  $h = ltrim($hex,'#'); if (strlen($h)===3) { $h=preg_replace('/(.)/','$1$1',$h); }
  $mix = function($c, $p){ return max(0, min(255, (int)round($c + (255 - $c) * $p))); };
  $r = $mix(hexdec(substr($h,0,2)), $p);
  $g = $mix(hexdec(substr($h,2,2)), $p);
  $b = $mix(hexdec(substr($h,4,2)), $p);
  return sprintf('#%02X%02X%02X', $r, $g, $b);
};
$bg1Hover = $shade($bg1, 0.06);   // clareia 6%
$bg2Hover = $shade($bg2, -0.06);  // escurece 6%

// ======== 5) Versão/ETag e 304 ========
$ver = substr(sha1($bg1.$bg2.$updatedAt.$companyId), 0, 12);
$lastMod = gmdate('D, d M Y H:i:s', strtotime($updatedAt)).' GMT';
header('ETag: "'.$ver.'"');
header('Last-Modified: '.$lastMod);
if (isset($_SERVER['HTTP_IF_NONE_MATCH']) &&
    trim($_SERVER['HTTP_IF_NONE_MATCH']) === '"'.$ver.'"') {
  header('HTTP/1.1 304 Not Modified');
  exit;
}

?>
:root{
  /* Variáveis do tema (com fallbacks de segurança) */
  --bg1: <?= $bg1 ?>;
  --bg1-contrast: <?= $onBg1 ?>;
  --bg1-hover: <?= $bg1Hover ?>;

  --bg2: <?= $bg2 ?>;
  --bg2-contrast: <?= $onBg2 ?>;
  --bg2-hover: <?= $bg2Hover ?>;

  /* Bootstrap */
  --bs-primary: var(--bg2);
  --bs-link-color: var(--bg2);
  --bs-link-hover-color: var(--bg2-hover);
  --bs-dark: var(--bg1);
}

/* Utilitários */
.bg-bg1{ background-color: var(--bg1) !important; color: var(--bg1-contrast) !important; }
.bg-bg2{ background-color: var(--bg2) !important; color: var(--bg2-contrast) !important; }
.text-bg1{ color: var(--bg1) !important; }
.text-bg2{ color: var(--bg2) !important; }
.border-bg1{ border-color: var(--bg1) !important; }
.border-bg2{ border-color: var(--bg2) !important; }

/* Sidebar & header (links herdando as variáveis) */
.sidebar{ background: var(--bg1); color: var(--bg1-contrast); }
.sidebar a{ color: var(--bg2); }
.sidebar a:hover{ color: var(--bg2-hover); }

/* Botões e destaques */
.btn-primary{ background-color: var(--bg2); border-color: var(--bg2); color: var(--bg2-contrast); }
.btn-primary:hover{ background-color: var(--bg2-hover); border-color: var(--bg2-hover); }

/* Badges / Progress */
.badge-warning, .badge-gold{ background-color: var(--bg2) !important; color: var(--bg2-contrast) !important; }
.progress-bar{ background-color: var(--bg2) !important; }

/* Ex.: alvo visual */
.objetivo-selecionado{ outline:2px solid var(--bg2); box-shadow:0 0 0 3px color-mix(in srgb, var(--bg2) 25%, transparent); }

/* DEBUG
<?= "/* ".implode(" | ", $debug)." */" . PHP_EOL ?>
*/
