<?php
// OKR_system/assets/company_theme.php
declare(strict_types=1);

// ======== HEADERS (antes de qualquer saída) ========
header('Content-Type: text/css; charset=utf-8');
if (isset($_GET['nocache'])) {
  header('Cache-Control: private, no-store, max-age=0');
} else {
  header('Cache-Control: private, max-age=300, must-revalidate');
}
header('Vary: Cookie'); // evita cache cruzado entre usuários

require_once __DIR__ . '/../auth/config.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$debug = [];

// ======== 0) Conexão DB única (vamos reutilizar) ========
$pdo = null;
try {
  $pdo = new PDO(
    "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
  );
} catch (Throwable $e) {
  // segue sem DB; ficaremos nos defaults
  $debug[] = "db_conn_error: ".substr($e->getMessage(), 0, 120);
}

// ======== 1) Descobrir id_company (GET -> SESSION -> DB via user_id -> fallback) ========
$companyId = null;

if (isset($_GET['cid'])) {
  $companyId = (int)$_GET['cid'];
  $debug[] = "company_id={$companyId} (get)";
}

if (!$companyId && isset($_SESSION['company_id'])) {
  $companyId = (int)$_SESSION['company_id'];
  $debug[] = "company_id={$companyId} (session)";
}

if (!$companyId && isset($_SESSION['user_id']) && $pdo) {
  try {
    $stCid = $pdo->prepare("SELECT id_company FROM usuarios WHERE id_user = :uid LIMIT 1");
    $stCid->execute([':uid' => (int)$_SESSION['user_id']]);
    if ($r = $stCid->fetch()) {
      $companyId = (int)($r['id_company'] ?? 0);
      if ($companyId > 0) {
        $_SESSION['company_id'] = $companyId; // hidrata para próximas requisições
        $debug[] = "company_id={$companyId} (db_by_user)";
      }
    }
  } catch (Throwable $e) {
    $debug[] = "cid_lookup_error: ".substr($e->getMessage(), 0, 120);
  }
}

if (!$companyId) {
  $companyId = 1;
  $debug[] = "company_id={$companyId} (fallback_default)";
}

// ======== 2) Defaults seguros ========
$bg1 = '#222222';
$bg2 = '#F1C40F';
$updatedAt = gmdate('c');

// ======== 3) Buscar tema no DB ========
if ($pdo) {
  try {
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
    $debug[] = "db_error: ".substr($e->getMessage(), 0, 180);
  }
}

// ======== 4) Cálculo de contraste/hover ========
$yiq = function(string $hex) {
  $h = ltrim($hex, '#');
  if (strlen($h) === 3) { $h = preg_replace('/(.)/', '$1$1', $h); }
  $r = hexdec(substr($h,0,2));
  $g = hexdec(substr($h,2,2));
  $b = hexdec(substr($h,4,2));
  return (($r*299)+($g*587)+($b*114))/1000;
};
$onBg1 = $yiq($bg1) >= 128 ? '#111111' : '#FFFFFF';
$onBg2 = '#111111'; // textos escuros sobre dourado

$shade = function(string $hex, float $p) {
  $h = ltrim($hex,'#');
  if (strlen($h)===3) { $h=preg_replace('/(.)/','$1$1',$h); }
  $mix = function($c, $p){ return max(0, min(255, (int)round($c + (255 - $c) * $p))); };
  $r = $mix(hexdec(substr($h,0,2)), $p);
  $g = $mix(hexdec(substr($h,2,2)), $p);
  $b = $mix(hexdec(substr($h,4,2)), $p);
  return sprintf('#%02X%02X%02X', $r, $g, $b);
};
$bg1Hover = $shade($bg1, 0.06);   // clareia 6%
$bg2Hover = $shade($bg2, -0.06);  // escurece 6%

// ======== 5) ETag / Last-Modified ========
$ver = substr(sha1($bg1.$bg2.$updatedAt.$companyId), 0, 12);
$lastMod = gmdate('D, d M Y H:i:s', strtotime($updatedAt)).' GMT';
header('ETag: "'.$ver.'"');
header('Last-Modified: '.$lastMod);

// Nota: como a URL pode vir com ?cid=, o navegador já separa os caches por empresa.
// Ainda assim, o ETag traz o companyId, garantindo revalidação correta.

if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === '"'.$ver.'"') {
  header('HTTP/1.1 304 Not Modified');
  exit;
}
?>
:root{
  /* Variáveis do tema */
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

/* ============================================================
   VISUAL "CARA DO APP": fibra de carbono + brilho diagonal.
   Textura contínua atrás de header, sidebar, conteúdo e footer.
   Apenas estilos (não altera estrutura). Paleta do app Flutter:
   base #171B21 / cards #1C2128 / borda #30363D / dourado #F1C40F.
   ============================================================ */

/* Canvas único (fixo): trama de fibra de carbono + brilho diagonal suave */
body{
  background-color:#10141a !important;
  background-image:
    /* brilho diagonal sutil cobrindo a tela */
    linear-gradient(118deg, rgba(255,255,255,0) 38%, rgba(255,255,255,.045) 50%, rgba(255,255,255,0) 62%),
    /* fios da trama (duas diagonais) */
    repeating-linear-gradient(45deg,  rgba(255,255,255,.028) 0 1px, transparent 1px 3px),
    repeating-linear-gradient(-45deg, rgba(0,0,0,.30) 0 1px, transparent 1px 3px),
    /* profundidade de base */
    linear-gradient(135deg, #1b212b 0%, #12161d 60%, #0d1117 100%) !important;
  background-size: 220% 220%, 4px 4px, 4px 4px, 100% 100% !important;
  background-position: 0 0, 0 0, 0 0, 0 0 !important;
  background-repeat: no-repeat, repeat, repeat, no-repeat !important;
  background-attachment: fixed !important;
  color: var(--text, #EAEEF6);
}

/* Conteúdo transparente: a fibra aparece continuamente; cards no tom do app */
.content{
  background: transparent !important;
  color: var(--text, #EAEEF6);
  min-height: 100vh;
  --card: #1C2128;
  --border: #30363D;
}
.content > main, .content main{ background: transparent; }

/* Sidebar: painel translúcido escuro sobre a fibra + divisor sutil */
.sidebar{
  background: linear-gradient(180deg, rgba(22,27,34,.78), rgba(13,17,23,.86)) !important;
  border-right: 1px solid rgba(255,255,255,.06);
}
.sidebar-footer{ background: transparent; border-top: 1px solid rgba(255,255,255,.08); }

/* Header: escuro translúcido (fibra atrás) + linha dourada sutil embaixo */
.header{
  background: linear-gradient(180deg, rgba(22,27,34,.82), rgba(13,17,23,.72)) !important;
  box-shadow: 0 6px 18px rgba(0,0,0,.35) !important;
  border-bottom: none !important;
  position: relative;
}
.header::after{
  content:""; position:absolute; left:0; right:0; bottom:0; height:1px;
  background: linear-gradient(90deg, transparent, rgba(241,196,15,.45), transparent);
  pointer-events:none;
}
/* textos/ícones do header legíveis sobre o escuro */
.header .menu-toggle,
.header .profile span,
.header .notif-link,
.header .notif-link i{ color: var(--text, #EAEEF6) !important; }
/* respaldo claro sutil p/ a logo (que pode ser escura) ficar legível no header escuro */
.header .left .logo-link{
  background: rgba(255,255,255,.94);
  border-radius: 8px;
  padding: 3px 8px;
  line-height: 0;
}

/* Footer de página (quando houver) acompanha a fibra */
.content footer, footer.app-footer, .app-footer{ background: transparent; }

/* Utilitários */
.bg-bg1{ background-color: var(--bg1) !important; color: var(--bg1-contrast) !important; }
.bg-bg2{ background-color: var(--bg2) !important; color: var(--bg2-contrast) !important; }
.text-bg1{ color: var(--bg1) !important; }
.text-bg2{ color: var(--bg2) !important; }
.border-bg1{ border-color: var(--bg1) !important; }
.border-bg2{ border-color: var(--bg2) !important; }

/* Sidebar & header */
.sidebar{ background: var(--bg1); color: var(--bg1-contrast); }
.sidebar a{ color: var(--bg2); }
.sidebar a:hover{ color: var(--bg2-hover); }

/* Botões, badges e progress */
.btn-primary{ background-color: var(--bg2); border-color: var(--bg2); color: var(--bg2-contrast); }
.btn-primary:hover{ background-color: var(--bg2-hover); border-color: var(--bg2-hover); }
.badge-warning, .badge-gold{ background-color: var(--bg2) !important; color: var(--bg2-contrast) !important; }
.progress-bar{ background-color: var(--bg2) !important; }

/* Destaque */
.objetivo-selecionado{
  outline:2px solid var(--bg2);
  box-shadow:0 0 0 3px color-mix(in srgb, var(--bg2) 25%, transparent);
}

/* DEBUG */
/* <?= implode(" | ", $debug) ?> */
