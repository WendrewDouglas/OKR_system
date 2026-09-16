<?php
// views/agenda.php — Agenda geral (calendário unificado de prazos).
// Fase 1: grade do mês + painel do dia. Filtros e demais visões vêm nas próximas.
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
// Este servidor está com serialize_precision=17: sem isso um progresso de 43,4
// vira "43.39999999999999857891..." dentro do JSON da página. -1 é o valor
// recomendado pelo PHP (usa a menor representação que faz roundtrip).
ini_set('serialize_precision', '-1');

session_start();

require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/helpers/nome_format.php';
require_once __DIR__ . '/../auth/functions.php';
require_once __DIR__ . '/../auth/acl.php';
require_once __DIR__ . '/../auth/helpers/agenda_events.php';

gate_page_by_path($_SERVER['SCRIPT_NAME'] ?? '');

if (empty($_SESSION['user_id'])) {
  header('Location: /OKR_system/views/login.php');
  exit;
}

// A rota limpa (/OKR_system/agenda) passa pelo index.php, então o
// gate_page_by_path acima procura por '/OKR_system/index.php' e não acha nada
// em dom_paginas. Enforça aqui, depois do redirect de login (antes dele, o
// visitante anônimo receberia o modal de permissão em vez da tela de login).
require_cap('R:objetivo@ORG');

// Conexão
try {
  $pdo = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
  );
} catch (PDOException $e) {
  http_response_code(500);
  die('Erro ao conectar ao banco.');
}

$currentUserId = (int)$_SESSION['user_id'];

// Empresa do usuário: a agenda é sempre da própria company.
$companyId = (int)($_SESSION['id_company'] ?? $_SESSION['company_id'] ?? 0);
if ($companyId <= 0) {
  $stC = $pdo->prepare("SELECT id_company FROM usuarios WHERE id_user = :uid LIMIT 1");
  $stC->execute([':uid' => $currentUserId]);
  $companyId = (int)$stC->fetchColumn();
  if ($companyId > 0) { $_SESSION['id_company'] = $companyId; }
}
if ($companyId <= 0) {
  header('Location: /OKR_system/organizacao');
  exit;
}

$dados = agenda_build_events($pdo, $companyId);
// Quem está olhando: alimenta o preset "Meus prazos".
$dados['eu'] = $currentUserId;

// Eventos da empresa: quem edita a organização também cadastra evento
// (admin_master entra pelo bypass do has_cap; gestor_master pela capability).
$podeGerenciarEventos = has_cap('M:company@ORG');
$dados['pode_gerenciar_eventos'] = $podeGerenciarEventos;

// Lista de usuários só para o formulário (não vai para quem não gerencia).
$usuariosEmpresa = [];
if ($podeGerenciarEventos) {
  $stU = $pdo->prepare("SELECT id_user, primeiro_nome, ultimo_nome FROM usuarios
                         WHERE id_company = :cid AND ativo = 1 ORDER BY primeiro_nome, ultimo_nome");
  $stU->execute([':cid' => $companyId]);
  foreach ($stU as $u) {
    $usuariosEmpresa[] = ['id' => (int)$u['id_user'],
                          'nome' => nome_exibicao((string)$u['primeiro_nome'], (string)($u['ultimo_nome'] ?? ''))];
  }
}
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrfAgenda = (string)$_SESSION['csrf_token'];

// Versão do asset pelo mtime do arquivo. Sem isso o navegador reaproveita o
// agenda.js em cache e o roda contra o payload novo: o tipo 'evento' não existe
// no TIPOS antigo e derruba o render inteiro da Agenda de quem já visitou a tela.
$assetV = static function (string $rel): string {
  $abs = __DIR__ . '/../' . ltrim($rel, '/');
  return '/OKR_system/' . ltrim($rel, '/') . '?v=' . (is_file($abs) ? (string)filemtime($abs) : '0');
};

$totalEventos = count($dados['eventos']);
$totalPessoas = count($dados['pessoas']);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Agenda – OKR System</title>

  <link rel="stylesheet" href="/OKR_system/assets/css/base.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/components.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/layout.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/theme.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous"/>
  <link rel="stylesheet" href="/OKR_system/assets/company_theme.php?cid=<?= $companyId ?>">
  <link rel="stylesheet" href="<?= htmlspecialchars($assetV('assets/css/pages/agenda.css'), ENT_QUOTES, 'UTF-8') ?>">
</head>
<body>
  <?php include __DIR__ . '/partials/sidebar.php'; ?>
  <div class="content">
    <?php include __DIR__ . '/partials/header.php'; ?>

    <main class="ag-page">

      <div class="ag-head">
        <div>
          <h1><i class="fa-regular fa-calendar-days"></i>Agenda</h1>
          <div class="ag-sub">
            <?= (int)$totalEventos ?> prazos da empresa, de <?= (int)$totalPessoas ?> responsáveis
          </div>
        </div>
        <div class="ag-nav">
          <div class="ag-visoes" role="tablist" id="agVisoes">
            <button type="button" role="tab" data-visao="mes" class="on">Mês</button>
            <button type="button" role="tab" data-visao="semana">Semana</button>
            <button type="button" role="tab" data-visao="lista">Lista</button>
            <button type="button" role="tab" data-visao="ciclo">Ciclo</button>
          </div>
          <button type="button" id="agPrev" aria-label="Período anterior"><i class="fa-solid fa-chevron-left"></i></button>
          <div class="ag-periodo" id="agPeriodo">—</div>
          <button type="button" id="agNext" aria-label="Próximo período"><i class="fa-solid fa-chevron-right"></i></button>
          <button type="button" id="agHoje" class="ag-hoje-btn">Hoje</button>
        </div>
      </div>

      <div class="ag-barra">
        <div class="ag-filtros" id="agFiltros"></div>
        <div class="ag-presets">
          <label class="ag-busca">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" id="agBusca" placeholder="Buscar prazo, KR ou pessoa…" aria-label="Buscar">
          </label>
          <button type="button" class="ag-preset" id="agPendencias">
            <i class="fa-solid fa-triangle-exclamation"></i> Só pendências
          </button>
          <?php if (!empty($dados['pessoas'][$currentUserId])): ?>
          <button type="button" class="ag-preset" id="agMeus">
            <i class="fa-solid fa-user"></i> Meus prazos
          </button>
          <?php endif; ?>
          <?php if ($podeGerenciarEventos): ?>
          <button type="button" class="ag-preset agev-novo" id="agevNovo">
            <i class="fa-solid fa-plus"></i> Novo evento
          </button>
          <?php endif; ?>
        </div>
      </div>

      <div class="ag-ativos" id="agAtivos"></div>

      <div class="ag-resumo" id="agResumo"></div>

      <div class="ag-main" id="agMain">
      <div class="ag-cal">

      <div class="ag-legenda">
        <span class="item"><i class="fa-solid fa-bullseye"></i>Objetivo</span>
        <span class="item"><i class="fa-solid fa-crosshairs"></i>Key Result</span>
        <span class="item"><i class="fa-solid fa-list-check"></i>Iniciativa</span>
        <span class="item"><i class="fa-solid fa-circle"></i>Marco</span>
        <span class="item"><i class="fa-solid fa-flag"></i>Início</span>
        <span class="item"><i class="fa-solid fa-calendar-day"></i>Evento</span>
        <span class="sep"></span>
        <span class="item"><span class="dot" style="background:var(--ag-vencido)"></span>Vencido</span>
        <span class="item"><span class="dot" style="background:var(--ag-hoje)"></span>Hoje</span>
        <span class="item"><span class="dot" style="background:var(--ag-proximo)"></span>7 dias</span>
        <span class="item"><span class="dot" style="background:var(--ag-futuro)"></span>Futuro</span>
        <span class="item"><span class="dot" style="background:var(--ag-concluido)"></span>Concluído</span>
        <span class="item"><span class="dot" style="background:var(--ag-neutro)"></span>Cancelado / pausado</span>
      </div>

      <div class="ag-grid" id="agGrid" role="grid" aria-label="Calendário de prazos"></div>

      </div><!-- /ag-cal -->

      <aside class="ag-rail" id="agDia" aria-live="polite"></aside>
      </div><!-- /ag-main -->

    </main>

    <?php include __DIR__ . '/partials/chat.php'; ?>
  </div>

  <!-- Eventos da empresa: fundo + painel de detalhe (todos) e de cadastro (gestores) -->
  <div class="agev-fundo" id="agevFundo"></div>

  <aside class="agev-drawer" id="agevDetalhe" aria-hidden="true">
    <header>
      <h3 id="agevDetTitulo">Evento</h3>
      <button type="button" class="ag-preset" data-agev-fechar style="margin-left:auto">Fechar</button>
    </header>
    <div class="agev-body">
      <dl class="agev-det" id="agevDetCorpo"></dl>
    </div>
    <?php if ($podeGerenciarEventos): ?>
    <div class="agev-acoes">
      <button type="button" class="ag-preset" id="agevCancelarOc">Cancelar esta data</button>
      <button type="button" class="ag-preset" id="agevEditar">Editar série</button>
      <button type="button" class="ag-preset" id="agevExcluir">Excluir série</button>
    </div>
    <?php endif; ?>
  </aside>

  <?php if ($podeGerenciarEventos): ?>
  <aside class="agev-drawer" id="agevForm" aria-hidden="true">
    <header>
      <h3 id="agevFormTitulo">Novo evento</h3>
      <button type="button" class="ag-preset" data-agev-fechar style="margin-left:auto">Fechar</button>
    </header>
    <div class="agev-body">
      <input type="hidden" id="agevId" value="0">

      <div class="agev-campo">
        <label for="agevTitulo">Título</label>
        <input type="text" id="agevTitulo" maxlength="180" placeholder="Ex.: Reunião de resultados">
      </div>

      <div class="agev-campo">
        <label for="agevDescricao">Descrição</label>
        <textarea id="agevDescricao" placeholder="Pauta, objetivo do encontro, material necessário…"></textarea>
      </div>

      <div class="agev-campo">
        <label for="agevLocal">Local</label>
        <input type="text" id="agevLocal" maxlength="180" placeholder="Sala, link da chamada…">
      </div>

      <div class="agev-linha">
        <div class="agev-campo">
          <label for="agevData">Data</label>
          <input type="date" id="agevData">
        </div>
        <div class="agev-campo">
          <label for="agevHoraIni">Início</label>
          <input type="time" id="agevHoraIni">
        </div>
        <div class="agev-campo">
          <label for="agevHoraFim">Término</label>
          <input type="time" id="agevHoraFim">
        </div>
      </div>

      <div class="agev-campo">
        <label for="agevRec">Repetição</label>
        <select id="agevRec">
          <option value="nenhuma">Não se repete</option>
          <option value="semanal">Semanal</option>
          <option value="quinzenal">Quinzenal</option>
          <option value="mensal">Mensal</option>
        </select>
      </div>

      <div class="agev-campo" id="agevBoxDias" hidden>
        <label>Dias da semana</label>
        <div class="agev-dias">
          <label><input type="checkbox" class="agev-dia" value="0"> Dom</label>
          <label><input type="checkbox" class="agev-dia" value="1"> Seg</label>
          <label><input type="checkbox" class="agev-dia" value="2"> Ter</label>
          <label><input type="checkbox" class="agev-dia" value="3"> Qua</label>
          <label><input type="checkbox" class="agev-dia" value="4"> Qui</label>
          <label><input type="checkbox" class="agev-dia" value="5"> Sex</label>
          <label><input type="checkbox" class="agev-dia" value="6"> Sáb</label>
        </div>
        <div class="agev-hint">Sem marcar nada, repete no mesmo dia da semana da data escolhida.</div>
      </div>

      <div class="agev-campo" id="agevBoxMensal" hidden>
        <label for="agevRegraMes">Como repetir no mês</label>
        <select id="agevRegraMes">
          <option value="dia">No mesmo dia do mês</option>
          <option value="semana">Na mesma posição (ex.: 2ª terça)</option>
        </select>
      </div>

      <div class="agev-campo" id="agevBoxFim" hidden>
        <label for="agevDataFim">Repetir até (opcional)</label>
        <input type="date" id="agevDataFim">
      </div>

      <div class="agev-campo">
        <label>Participantes</label>
        <div class="agev-pessoas" id="agevPessoas">
          <?php foreach ($usuariosEmpresa as $u): ?>
          <label><input type="checkbox" class="agev-pessoa" value="<?= (int)$u['id'] ?>"> <?= htmlspecialchars($u['nome'], ENT_QUOTES, 'UTF-8') ?></label>
          <?php endforeach; ?>
        </div>
        <div class="agev-hint">O evento aparece na Agenda de todos, e em Minhas Tarefas de quem é participante.</div>
      </div>
    </div>
    <div class="agev-acoes">
      <button type="button" class="ag-preset" data-agev-fechar>Cancelar</button>
      <button type="button" class="ag-preset agev-novo" id="agevSalvar">Salvar evento</button>
    </div>
  </aside>
  <?php endif; ?>

  <script>
    window.AGENDA = <?= json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    window.AGEV_CSRF = <?= json_encode($csrfAgenda, JSON_UNESCAPED_SLASHES) ?>;
  </script>
  <script src="<?= htmlspecialchars($assetV('assets/js/agenda.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
  <script src="<?= htmlspecialchars($assetV('assets/js/agenda_eventos.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>
