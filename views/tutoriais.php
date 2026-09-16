<?php
// views/tutoriais.php — Biblioteca de vídeos tutoriais do sistema.
// Aberta a todos os usuários logados (não exige capability): os vídeos são institucionais.
// Os arquivos ficam em uploads/tutoriais/ e são servidos estaticamente pelo Apache,
// que já responde a byte-range — é ele quem permite arrastar a barra do player.

declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/functions.php';
require_once __DIR__ . '/../auth/acl.php';

gate_page_by_path($_SERVER['SCRIPT_NAME'] ?? '');

if (!isset($_SESSION['user_id'])) {
  header('Location: /OKR_system/views/login.php');
  exit;
}

if (!defined('PB_THEME_LINK_EMITTED')) {
  define('PB_THEME_LINK_EMITTED', true);
  echo '<link rel="stylesheet" href="/OKR_system/assets/company_theme.php">';
}

$BASE_UP = '/OKR_system/uploads/tutoriais/';

$tutoriais = [];
try {
  $pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
  );
  $tutoriais = $pdo->query(
    "SELECT slug, titulo, descricao, trilha, duracao_seg, arquivo, poster, legenda
       FROM tutoriais
      WHERE publicado = 1
      ORDER BY ordem, id_tutorial"
  )->fetchAll();
} catch (Throwable $e) {
  error_log('tutoriais: ' . $e->getMessage());
}

// Agrupa por trilha preservando a ordem de exibição
$porTrilha = [];
foreach ($tutoriais as $t) {
  $porTrilha[$t['trilha'] ?: 'Geral'][] = $t;
}

function tut_duracao(?int $seg): string {
  if (!$seg) return '';
  return sprintf('%d:%02d', intdiv($seg, 60), $seg % 60);
}
function h_tut($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$slugInicial = isset($_GET['v']) ? preg_replace('/[^a-z0-9\-]/i', '', (string)$_GET['v']) : '';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Tutoriais — OKR System</title>

  <link rel="stylesheet" href="/OKR_system/assets/css/base.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/components.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/layout.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/theme.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous"/>

  <style>
    .tut-page{ padding:1.5rem 2rem 2rem; }
    .tut-head h1{ font-size:1.15rem; display:flex; align-items:center; gap:10px; margin:0 0 4px; }
    .tut-head h1 i{ color:var(--gold); }
    .tut-sub{ color:var(--muted); font-size:.86rem; margin-bottom:16px; }

    /* Player: só aparece quando um vídeo é escolhido */
    .tut-player{ display:none; margin-bottom:22px; }
    .tut-player.on{ display:block; }
    .tut-player-box{
      background:#000; border:1px solid var(--border); border-radius:14px; overflow:hidden;
      box-shadow:var(--shadow); max-width:1100px;
    }
    .tut-player video{ width:100%; display:block; aspect-ratio:16/9; background:#000; }
    .tut-player-info{ display:flex; align-items:flex-start; gap:12px; margin-top:10px; max-width:1100px; }
    .tut-player-info h2{ font-size:1rem; margin:0 0 4px; color:var(--text); }
    .tut-player-info p{ margin:0; color:var(--muted); font-size:.86rem; }
    .tut-fechar{
      margin-left:auto; flex:none; background:var(--btn); color:var(--text);
      border:1px solid var(--border); border-radius:10px; padding:7px 12px; cursor:pointer; font-size:.82rem;
    }
    .tut-fechar:hover{ border-color:var(--gold); color:var(--gold); }

    /* Barra de busca e filtros */
    .tut-barra{ display:flex; flex-wrap:wrap; align-items:center; gap:8px; margin-bottom:18px; }
    .tut-busca{
      display:inline-flex; align-items:center; gap:8px; background:var(--card);
      border:1px solid var(--border); border-radius:10px; padding:7px 12px; min-width:280px;
    }
    .tut-busca i{ color:var(--muted); font-size:.85rem; }
    .tut-busca input{ background:transparent; border:0; outline:0; color:var(--text); font-size:.88rem; width:100%; }
    .tut-chip{
      background:var(--btn); color:var(--muted); border:1px solid var(--border);
      border-radius:999px; padding:6px 14px; font-size:.8rem; font-weight:600; cursor:pointer;
    }
    .tut-chip.on{ background:var(--gold); color:#111; border-color:var(--gold); }

    /* Grade de cartões */
    .tut-trilha{ margin-bottom:26px; }
    .tut-trilha h3{
      font-size:.74rem; text-transform:uppercase; letter-spacing:.08em;
      color:var(--muted); margin:0 0 10px; font-weight:800;
    }
    .tut-grid{ display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:16px; }
    .tut-card{
      display:flex; flex-direction:column; text-align:left; padding:0; cursor:pointer;
      background:linear-gradient(180deg, var(--card), #0e1319);
      border:1px solid var(--border); border-radius:14px; overflow:hidden;
      color:var(--text); font:inherit; transition:border-color .15s ease, transform .15s ease;
    }
    .tut-card:hover, .tut-card:focus-visible{ border-color:var(--gold); transform:translateY(-2px); outline:none; }
    .tut-card.on{ border-color:var(--gold); box-shadow:0 0 0 1px var(--gold) inset; }
    .tut-thumb{ position:relative; aspect-ratio:16/9; background:#0b0f14; }
    .tut-thumb img{ width:100%; height:100%; object-fit:cover; display:block; }
    .tut-play{
      position:absolute; inset:0; display:grid; place-items:center;
      background:linear-gradient(180deg, rgba(0,0,0,.05), rgba(0,0,0,.45));
      color:#fff; font-size:2rem; opacity:.9;
    }
    .tut-dur{
      position:absolute; right:8px; bottom:8px; background:rgba(0,0,0,.75);
      color:#fff; font-size:.72rem; font-weight:700; padding:2px 7px; border-radius:6px;
    }
    .tut-card-body{ padding:12px 14px 14px; }
    .tut-card-body strong{ display:block; font-size:.92rem; margin-bottom:4px; }
    .tut-card-body span{ color:var(--muted); font-size:.8rem; line-height:1.45; }
    .tut-vazio{ color:var(--muted); font-style:italic; padding:18px 4px; }

    @media (max-width:768px){
      .tut-page{ padding:1rem; }
      .tut-busca{ min-width:0; flex:1; }
    }
  </style>
</head>
<body>
  <?php include __DIR__ . '/partials/sidebar.php'; ?>
  <div class="content">
    <?php include __DIR__ . '/partials/header.php'; ?>

    <main class="tut-page">
      <div class="tut-head">
        <h1><i class="fa-solid fa-graduation-cap"></i> Tutoriais</h1>
        <div class="tut-sub">
          <?= count($tutoriais) ?> vídeo<?= count($tutoriais) === 1 ? '' : 's' ?> curtos mostrando como usar o sistema no dia a dia.
        </div>
      </div>

      <section class="tut-player" id="tutPlayer" aria-live="polite">
        <div class="tut-player-box">
          <video id="tutVideo" controls preload="metadata" playsinline>
            <track id="tutTrack" kind="captions" srclang="pt-BR" label="Português" default>
            Seu navegador não reproduz vídeo. Baixe o arquivo para assistir.
          </video>
        </div>
        <div class="tut-player-info">
          <div>
            <h2 id="tutTitulo">—</h2>
            <p id="tutDesc"></p>
          </div>
          <button type="button" class="tut-fechar" id="tutFechar">
            <i class="fa-solid fa-xmark"></i> Fechar player
          </button>
        </div>
      </section>

      <div class="tut-barra">
        <label class="tut-busca">
          <i class="fa-solid fa-magnifying-glass"></i>
          <input type="text" id="tutBusca" placeholder="Buscar tutorial…" aria-label="Buscar tutorial">
        </label>
        <button type="button" class="tut-chip on" data-trilha="todas">Todas</button>
        <?php foreach (array_keys($porTrilha) as $trilha): ?>
          <button type="button" class="tut-chip" data-trilha="<?= h_tut($trilha) ?>"><?= h_tut($trilha) ?></button>
        <?php endforeach; ?>
      </div>

      <?php if (!$tutoriais): ?>
        <div class="tut-vazio">Nenhum tutorial publicado ainda.</div>
      <?php endif; ?>

      <?php foreach ($porTrilha as $trilha => $lista): ?>
        <section class="tut-trilha" data-trilha="<?= h_tut($trilha) ?>">
          <h3><?= h_tut($trilha) ?></h3>
          <div class="tut-grid">
            <?php foreach ($lista as $t): ?>
              <?php
                $poster  = $t['poster']  ? $BASE_UP . $t['poster']  : '';
                $legenda = $t['legenda'] ? $BASE_UP . $t['legenda'] : '';
              ?>
              <button type="button" class="tut-card"
                      data-slug="<?= h_tut($t['slug']) ?>"
                      data-src="<?= h_tut($BASE_UP . $t['arquivo']) ?>"
                      data-poster="<?= h_tut($poster) ?>"
                      data-vtt="<?= h_tut($legenda) ?>"
                      data-titulo="<?= h_tut($t['titulo']) ?>"
                      data-desc="<?= h_tut($t['descricao']) ?>"
                      aria-label="Assistir: <?= h_tut($t['titulo']) ?>">
                <div class="tut-thumb">
                  <?php if ($poster): ?><img src="<?= h_tut($poster) ?>" alt="" loading="lazy"><?php endif; ?>
                  <span class="tut-play"><i class="fa-solid fa-circle-play"></i></span>
                  <?php if ($t['duracao_seg']): ?>
                    <span class="tut-dur"><?= tut_duracao((int)$t['duracao_seg']) ?></span>
                  <?php endif; ?>
                </div>
                <div class="tut-card-body">
                  <strong><?= h_tut($t['titulo']) ?></strong>
                  <span><?= h_tut($t['descricao']) ?></span>
                </div>
              </button>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endforeach; ?>

      <?php include __DIR__ . '/partials/chat.php'; ?>
    </main>
  </div>

  <script>
  (function(){
    'use strict';
    const player  = document.getElementById('tutPlayer');
    const video   = document.getElementById('tutVideo');
    const track   = document.getElementById('tutTrack');
    const titulo  = document.getElementById('tutTitulo');
    const desc    = document.getElementById('tutDesc');
    const cards   = Array.from(document.querySelectorAll('.tut-card'));

    function abrir(card, tocar){
      cards.forEach(c => c.classList.toggle('on', c === card));
      video.pause();
      video.src   = card.dataset.src;
      video.poster = card.dataset.poster || '';
      if (card.dataset.vtt) { track.src = card.dataset.vtt; track.removeAttribute('hidden'); }
      else { track.removeAttribute('src'); }
      titulo.textContent = card.dataset.titulo;
      desc.textContent   = card.dataset.desc || '';
      player.classList.add('on');
      video.load();
      if (tocar) video.play().catch(() => {});   // navegador pode bloquear autoplay: o controle fica com o usuário
      player.scrollIntoView({ behavior: 'smooth', block: 'start' });
      // deep link: o endereço passa a apontar para o vídeo aberto
      history.replaceState(null, '', '?v=' + encodeURIComponent(card.dataset.slug));
    }

    cards.forEach(card => card.addEventListener('click', () => abrir(card, true)));

    document.getElementById('tutFechar').addEventListener('click', () => {
      video.pause();
      player.classList.remove('on');
      cards.forEach(c => c.classList.remove('on'));
      history.replaceState(null, '', location.pathname);
    });

    // Busca por título/descrição e filtro por trilha, ambos no navegador
    const busca = document.getElementById('tutBusca');
    const chips = Array.from(document.querySelectorAll('.tut-chip'));
    let trilhaAtiva = 'todas';

    function filtrar(){
      const termo = (busca.value || '').trim().toLowerCase();
      cards.forEach(card => {
        const casaTexto = !termo
          || card.dataset.titulo.toLowerCase().includes(termo)
          || (card.dataset.desc || '').toLowerCase().includes(termo);
        card.style.display = casaTexto ? '' : 'none';
      });
      document.querySelectorAll('.tut-trilha').forEach(sec => {
        const daTrilha = trilhaAtiva === 'todas' || sec.dataset.trilha === trilhaAtiva;
        const temVisivel = Array.from(sec.querySelectorAll('.tut-card')).some(c => c.style.display !== 'none');
        sec.style.display = (daTrilha && temVisivel) ? '' : 'none';
      });
    }

    busca.addEventListener('input', filtrar);
    chips.forEach(chip => chip.addEventListener('click', () => {
      chips.forEach(c => c.classList.toggle('on', c === chip));
      trilhaAtiva = chip.dataset.trilha;
      filtrar();
    }));

    // Abre direto o vídeo pedido pela URL (?v=slug)
    const inicial = <?= json_encode($slugInicial, JSON_UNESCAPED_SLASHES) ?>;
    if (inicial) {
      const alvo = cards.find(c => c.dataset.slug === inicial);
      if (alvo) abrir(alvo, false);
    }
  })();
  </script>
</body>
</html>
