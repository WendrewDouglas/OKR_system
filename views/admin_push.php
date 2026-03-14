<?php
/**
 * Envios Push — Painel Administrativo
 * Acesso restrito a admin_master.
 */
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/functions.php';
require_once __DIR__ . '/../auth/acl.php';

gate_page_by_path($_SERVER['SCRIPT_NAME'] ?? '');

if (!isset($_SESSION['user_id'])) { header('Location: /OKR_system/views/login.php'); exit; }

$_pdo = pdo_conn();
$_st = $_pdo->prepare("SELECT 1 FROM rbac_user_role ur JOIN rbac_roles r ON r.role_id=ur.role_id AND r.is_active=1 WHERE ur.user_id=:u AND r.role_key='admin_master' LIMIT 1");
$_st->execute([':u'=>(int)$_SESSION['user_id']]);
if (!$_st->fetchColumn()) { deny_with_modal('Acesso restrito a administradores do sistema.'); }

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$userId = (int)$_SESSION['user_id'];

// Dados para selects
$companies = $_pdo->query("SELECT id_company, organizacao FROM company ORDER BY organizacao")->fetchAll(PDO::FETCH_ASSOC);
$departments = $_pdo->query("SELECT id_departamento, nome, id_company FROM dom_departamentos WHERE ativo=1 ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
$niveis = $_pdo->query("SELECT id_nivel, nome FROM dom_niveis_cargo ORDER BY ordem")->fetchAll(PDO::FETCH_ASSOC);
$statusKr = $_pdo->query("SELECT id_status, descricao_exibicao FROM dom_status_kr")->fetchAll(PDO::FETCH_ASSOC);
$statusAprov = $_pdo->query("SELECT id_status, descricao_exibicao FROM dom_status_aprovacao")->fetchAll(PDO::FETCH_ASSOC);
$ciclos = $_pdo->query("SELECT nome_ciclo FROM dom_ciclos")->fetchAll(PDO::FETCH_COLUMN);
$pilares = $_pdo->query("SELECT id_pilar, descricao_exibicao FROM dom_pilar_bsc ORDER BY ordem_pilar")->fetchAll(PDO::FETCH_ASSOC);
$qualidades = $_pdo->query("SELECT id_qualidade, descricao_exibicao FROM dom_qualidade_objetivo")->fetchAll(PDO::FETCH_ASSOC);
$naturezas = $_pdo->query("SELECT id_natureza, descricao_exibicao FROM dom_natureza_kr")->fetchAll(PDO::FETCH_ASSOC);

// Campanhas existentes
$campaigns = $_pdo->query("
  SELECT pc.*, u.primeiro_nome AS criador_nome,
         (SELECT COUNT(*) FROM push_campaign_recipients r WHERE r.id_campaign=pc.id_campaign AND r.status_envio='sent') AS total_sent,
         (SELECT COUNT(*) FROM push_campaign_recipients r WHERE r.id_campaign=pc.id_campaign) AS total_recipients
    FROM push_campaigns pc
    LEFT JOIN usuarios u ON u.id_user=pc.created_by
   ORDER BY pc.created_at DESC
   LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC);

// Segmentos
$segments = $_pdo->query("SELECT * FROM push_segments ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function statusBadge(string $s): string {
  $map = [
    'draft'=>'background:rgba(148,163,184,.15);color:#94a3b8;border-color:rgba(148,163,184,.3)',
    'scheduled'=>'background:rgba(59,130,246,.15);color:#60a5fa;border-color:rgba(59,130,246,.3)',
    'sending'=>'background:rgba(245,158,11,.15);color:#fbbf24;border-color:rgba(245,158,11,.3)',
    'sent'=>'background:rgba(34,197,94,.15);color:#4ade80;border-color:rgba(34,197,94,.3)',
    'error'=>'background:rgba(239,68,68,.15);color:#f87171;border-color:rgba(239,68,68,.3)',
    'cancelled'=>'background:rgba(107,114,128,.15);color:#9ca3af;border-color:rgba(107,114,128,.3)',
  ];
  $style = $map[$s] ?? $map['draft'];
  $labels = ['draft'=>'Rascunho','scheduled'=>'Agendado','sending'=>'Enviando','sent'=>'Enviado','error'=>'Erro','cancelled'=>'Cancelado'];
  return '<span style="'.$style.';font-size:.7rem;font-weight:600;padding:2px 8px;border-radius:4px;border:1px solid">'
    .h($labels[$s] ?? $s).'</span>';
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Envios Push – Admin – OKR System</title>
<link rel="stylesheet" href="/OKR_system/assets/css/base.css">
<link rel="stylesheet" href="/OKR_system/assets/css/components.css">
<link rel="stylesheet" href="/OKR_system/assets/css/layout.css">
<link rel="stylesheet" href="/OKR_system/assets/css/theme.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.css"/>
<style>
.main-wrapper{padding:2rem 2rem 2rem 1.5rem;margin-right:var(--chat-w,0);transition:margin-right .25s ease}
@media(max-width:991px){.main-wrapper{padding:1rem}}
.ap-title{font-size:1.5rem;font-weight:800;color:var(--gold,#F1C40F);margin-bottom:.25rem}
.ap-subtitle{font-size:.85rem;color:var(--text-secondary,#aaa);margin-bottom:1.5rem}

/* Tabs */
.ap-tabs{display:flex;gap:4px;border-bottom:1px solid var(--border,#2a2f3b);margin-bottom:1.25rem;flex-wrap:wrap}
.ap-tab{padding:.6rem 1rem;font-size:.82rem;font-weight:600;color:var(--text-secondary,#aaa);cursor:pointer;border-bottom:2px solid transparent;transition:.2s}
.ap-tab:hover{color:var(--text,#eee)}
.ap-tab.active{color:var(--gold,#F1C40F);border-bottom-color:var(--gold,#F1C40F)}
.ap-panel{display:none}.ap-panel.active{display:block}

/* Cards/Form */
.ap-card{background:var(--card,#1a1f2b);border:1px solid var(--border,#2a2f3b);border-radius:12px;padding:1.25rem;margin-bottom:1rem}
.ap-card-title{font-weight:700;font-size:.95rem;color:var(--text,#eee);display:flex;align-items:center;gap:.5rem;margin-bottom:.75rem}
.ap-card-title i{color:var(--gold,#F1C40F);font-size:.85rem}
.ap-grid{display:grid;grid-template-columns:1fr 1fr;gap:.75rem}
@media(max-width:700px){.ap-grid{grid-template-columns:1fr}}
.ap-field{display:flex;flex-direction:column;gap:4px}
.ap-field.full{grid-column:1/-1}
.ap-label{font-size:.78rem;color:var(--text-secondary,#aaa);font-weight:600}
.ap-input,.ap-select,.ap-textarea{background:#0b0f14;border:1px solid var(--border,#2a2f3b);border-radius:8px;color:var(--text,#eee);padding:8px 10px;font-size:.82rem;outline:none;font-family:inherit}
.ap-input:focus,.ap-select:focus,.ap-textarea:focus{border-color:var(--gold,#F1C40F)}
.ap-textarea{resize:vertical;min-height:60px}
.ap-select{appearance:auto}
.ap-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:8px;font-weight:700;font-size:.8rem;border:1px solid var(--border,#2a2f3b);cursor:pointer;transition:.2s;background:#0e131a;color:var(--text,#eee)}
.ap-btn:hover{border-color:var(--gold,#F1C40F);color:var(--gold,#F1C40F)}
.ap-btn.primary{background:linear-gradient(90deg,var(--gold,#F1C40F),var(--green,#27ae60));color:#1a1a1a;border-color:rgba(255,255,255,.15)}
.ap-btn.danger{background:rgba(239,68,68,.15);color:#f87171;border-color:rgba(239,68,68,.3)}
.ap-btn:disabled{opacity:.5;cursor:not-allowed}
.ap-actions{display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.75rem}
.ap-status{margin-top:.5rem;padding:8px;border-radius:8px;font-size:.8rem;display:none}
.ap-status.show{display:block}
.ap-status.ok{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.2);color:#86efac}
.ap-status.err{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.2);color:#fca5a5}

/* Audience counter */
.ap-audience-count{display:flex;align-items:center;gap:.5rem;padding:.5rem .75rem;border-radius:8px;background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.2);color:#93c5fd;font-size:.82rem;font-weight:600;margin-top:.5rem}
.ap-audience-count i{font-size:.9rem}

/* Filter accordion */
.ap-filter-group{border:1px solid var(--border,#2a2f3b);border-radius:8px;margin-bottom:.5rem;overflow:hidden}
.ap-filter-header{display:flex;align-items:center;justify-content:space-between;padding:.6rem .75rem;cursor:pointer;font-size:.82rem;font-weight:600;color:var(--text,#eee)}
.ap-filter-header:hover{background:rgba(255,255,255,.03)}
.ap-filter-header i.chevron{transition:transform .2s;font-size:.7rem;color:var(--text-secondary,#aaa)}
.ap-filter-group.open .ap-filter-header i.chevron{transform:rotate(180deg)}
.ap-filter-body{display:none;padding:.5rem .75rem .75rem;border-top:1px solid var(--border,#2a2f3b)}
.ap-filter-group.open .ap-filter-body{display:block}

/* Preview phone */
.ap-preview-wrap{display:flex;gap:1.5rem;justify-content:center;flex-wrap:wrap;margin-top:.75rem}
.ap-phone{width:280px;background:#111;border-radius:28px;padding:12px;border:2px solid #333;position:relative}
.ap-phone-label{text-align:center;font-size:.7rem;color:var(--text-secondary,#aaa);margin-bottom:6px;font-weight:600}
.ap-notch{width:100px;height:20px;background:#111;border-radius:0 0 12px 12px;margin:0 auto 8px}
.ap-push-card{background:#1c1c1e;border-radius:14px;padding:10px 12px;margin:0 4px}
.ap-push-app{display:flex;align-items:center;gap:6px;font-size:.65rem;color:#8e8e93;margin-bottom:4px}
.ap-push-app-icon{width:16px;height:16px;border-radius:4px;background:linear-gradient(135deg,#F1C40F,#27ae60)}
.ap-push-title{font-weight:700;font-size:.82rem;color:#fff;margin-bottom:2px;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
.ap-push-body{font-size:.75rem;color:#aeaeb2;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical}
.ap-push-img{width:100%;border-radius:8px;margin-top:6px;max-height:140px;object-fit:cover}
.ap-push-time{font-size:.6rem;color:#636366;margin-top:4px;text-align:right}

/* Crop modal */
.ap-crop-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:30000;align-items:center;justify-content:center}
.ap-crop-overlay.show{display:flex}
.ap-crop-box{background:var(--card,#1a1f2b);border-radius:16px;padding:1.25rem;max-width:500px;width:95%;max-height:90vh;overflow:auto}
.ap-crop-box img{max-width:100%;display:block}

/* Campaign table */
.ap-table{width:100%;border-collapse:collapse;font-size:.8rem}
.ap-table th{text-align:left;padding:.5rem .6rem;color:var(--text-secondary,#aaa);font-size:.72rem;text-transform:uppercase;border-bottom:1px solid var(--border,#2a2f3b)}
.ap-table td{padding:.5rem .6rem;border-bottom:1px solid rgba(255,255,255,.04);color:var(--text,#eee)}
.ap-table tr:hover td{background:rgba(255,255,255,.02)}
.ap-table .actions{display:flex;gap:4px}
.ap-table .actions button{background:none;border:1px solid var(--border,#2a2f3b);border-radius:6px;color:var(--text-secondary,#aaa);padding:4px 8px;cursor:pointer;font-size:.72rem}
.ap-table .actions button:hover{border-color:var(--gold,#F1C40F);color:var(--gold,#F1C40F)}

/* AI section */
.ap-ai-suggestions{display:flex;flex-direction:column;gap:.5rem;margin-top:.5rem}
.ap-ai-option{background:rgba(255,255,255,.04);border:1px solid var(--border,#2a2f3b);border-radius:8px;padding:.6rem .75rem;cursor:pointer;transition:.2s}
.ap-ai-option:hover{border-color:var(--gold,#F1C40F);background:rgba(246,195,67,.05)}
.ap-ai-option .ai-title{font-weight:700;font-size:.82rem;color:var(--text,#eee)}
.ap-ai-option .ai-body{font-size:.75rem;color:var(--text-secondary,#aaa);margin-top:2px}

/* Responsive */
@media(max-width:600px){.ap-phone{width:240px}.ap-preview-wrap{gap:.75rem}}
</style>
</head>
<body>
<?php include __DIR__ . '/partials/sidebar.php'; ?>
<div class="content">
  <?php include __DIR__ . '/partials/header.php'; ?>
  <main class="main-wrapper">
    <h1 class="ap-title">Envios Push</h1>
    <p class="ap-subtitle">Crie, agende e acompanhe campanhas de push notification</p>

    <!-- Tabs -->
    <div class="ap-tabs">
      <div class="ap-tab active" data-tab="new"><i class="fas fa-plus"></i> Nova Campanha</div>
      <div class="ap-tab" data-tab="history"><i class="fas fa-clock-rotate-left"></i> Historico</div>
      <div class="ap-tab" data-tab="segments"><i class="fas fa-filter"></i> Segmentos</div>
    </div>

    <!-- ===== TAB: NOVA CAMPANHA ===== -->
    <div class="ap-panel active" id="tab-new">
      <div style="display:grid;grid-template-columns:1fr 320px;gap:1rem;align-items:start">
        <!-- Left: Form -->
        <div>
          <!-- Dados da campanha -->
          <div class="ap-card">
            <div class="ap-card-title"><i class="fas fa-bullhorn"></i> Dados da Campanha</div>
            <form id="pushForm" autocomplete="off">
              <input type="hidden" name="csrf_token" value="<?=h($csrf)?>">
              <input type="hidden" name="id_campaign" id="f_id_campaign" value="">
              <div class="ap-grid">
                <div class="ap-field full">
                  <label class="ap-label">Nome interno</label>
                  <input class="ap-input" id="f_nome" name="nome_interno" placeholder="Ex: Lembrete KRs vencidos - Mar/2026" required>
                </div>
                <div class="ap-field">
                  <label class="ap-label">Canal de envio</label>
                  <select class="ap-select" id="f_canal" name="canal">
                    <option value="push">Push somente</option>
                    <option value="inbox">Inbox interno somente</option>
                    <option value="push_inbox">Push + Inbox</option>
                  </select>
                </div>
                <div class="ap-field">
                  <label class="ap-label">Categoria</label>
                  <input class="ap-input" id="f_categoria" name="categoria" placeholder="Ex: lembrete, novidade, alerta">
                </div>
                <div class="ap-field full">
                  <label class="ap-label">Titulo <small>(max 200 chars)</small></label>
                  <input class="ap-input" id="f_titulo" name="titulo" maxlength="200" placeholder="Titulo do push" required oninput="updatePreview()">
                </div>
                <div class="ap-field full">
                  <label class="ap-label">Descricao <small>(max ~120 chars ideal)</small></label>
                  <textarea class="ap-textarea" id="f_descricao" name="descricao" maxlength="2000" placeholder="Corpo do push" required oninput="updatePreview()"></textarea>
                </div>
                <div class="ap-field">
                  <label class="ap-label">Deep link / Rota do app</label>
                  <input class="ap-input" id="f_route" name="route" placeholder="Ex: /okrs/42 ou /aprovacoes">
                </div>
                <div class="ap-field">
                  <label class="ap-label">URL web (opcional)</label>
                  <input class="ap-input" id="f_url_web" name="url_web" placeholder="https://...">
                </div>
                <div class="ap-field">
                  <label class="ap-label">Prioridade</label>
                  <select class="ap-select" name="priority">
                    <option value="normal">Normal</option>
                    <option value="high">Alta</option>
                  </select>
                </div>
                <div class="ap-field">
                  <label class="ap-label">Imagem (1:1, max 500x500)</label>
                  <input type="file" class="ap-input" id="f_image" accept="image/png,image/jpeg,image/webp" onchange="onImageSelect(this)">
                  <img id="imagePreviewThumb" style="max-width:80px;border-radius:6px;margin-top:4px;display:none">
                </div>
              </div>

              <!-- Agendamento -->
              <div class="ap-card" style="margin-top:.75rem;padding:.75rem">
                <div class="ap-card-title" style="margin-bottom:.5rem"><i class="fas fa-calendar-check"></i> Agendamento</div>
                <div class="ap-grid">
                  <div class="ap-field">
                    <label class="ap-label"><input type="checkbox" id="f_schedule" onchange="toggleSchedule()"> Agendar envio</label>
                  </div>
                  <div class="ap-field">
                    <label class="ap-label"><input type="checkbox" id="f_recurring" disabled> Recorrencia</label>
                  </div>
                  <div class="ap-field" id="scheduleFields" style="display:none">
                    <label class="ap-label">Data/Hora</label>
                    <input type="datetime-local" class="ap-input" id="f_scheduled_at" name="scheduled_at">
                  </div>
                  <div class="ap-field" id="recurFields" style="display:none">
                    <label class="ap-label">Regra</label>
                    <select class="ap-select" id="f_recurrence" name="recurrence_rule">
                      <option value="">Sem recorrencia</option>
                      <option value="daily">Diario</option>
                      <option value="weekly">Semanal</option>
                      <option value="monthly:15">Mensal (dia 15)</option>
                    </select>
                  </div>
                </div>
              </div>

              <!-- Acoes -->
              <div class="ap-actions">
                <button type="button" class="ap-btn primary" onclick="saveCampaign('draft')"><i class="fas fa-floppy-disk"></i> Salvar rascunho</button>
                <button type="button" class="ap-btn" onclick="saveCampaign('send')" style="background:rgba(59,130,246,.15);color:#60a5fa;border-color:rgba(59,130,246,.3)"><i class="fas fa-paper-plane"></i> Enviar agora</button>
                <button type="button" class="ap-btn" onclick="saveCampaign('schedule')" id="btnSchedule" disabled><i class="fas fa-clock"></i> Agendar</button>
                <button type="button" class="ap-btn" onclick="sendTest()"><i class="fas fa-vial"></i> Envio teste</button>
              </div>
              <div class="ap-status" id="formStatus"></div>
            </form>
          </div>

          <!-- Filtros de audiencia -->
          <div class="ap-card">
            <div class="ap-card-title"><i class="fas fa-users-viewfinder"></i> Filtro de Audiencia</div>

            <!-- Segmento salvo -->
            <div class="ap-field" style="margin-bottom:.5rem">
              <label class="ap-label">Segmento salvo</label>
              <select class="ap-select" id="f_segment" onchange="loadSegment(this.value)">
                <option value="">— Filtro personalizado —</option>
                <?php foreach($segments as $seg): ?>
                <option value="<?=$seg['id_segment']?>" data-filters="<?=h($seg['filters_json'])?>"><?=h($seg['nome'])?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- A) Perfil -->
            <div class="ap-filter-group">
              <div class="ap-filter-header" onclick="this.parentElement.classList.toggle('open')">
                <span><i class="fas fa-user"></i> Perfil de Usuario</span>
                <i class="fas fa-chevron-down chevron"></i>
              </div>
              <div class="ap-filter-body">
                <div class="ap-grid">
                  <div class="ap-field">
                    <label class="ap-label">Empresa</label>
                    <select class="ap-select filter-input" data-key="id_company"><option value="">Todas</option>
                    <?php foreach($companies as $c): ?><option value="<?=$c['id_company']?>"><?=h($c['organizacao'])?></option><?php endforeach; ?></select>
                  </div>
                  <div class="ap-field">
                    <label class="ap-label">Departamento</label>
                    <select class="ap-select filter-input" data-key="id_departamento"><option value="">Todos</option>
                    <?php foreach($departments as $d): ?><option value="<?=$d['id_departamento']?>"><?=h($d['nome'])?></option><?php endforeach; ?></select>
                  </div>
                  <div class="ap-field">
                    <label class="ap-label">Nivel de cargo</label>
                    <select class="ap-select filter-input" data-key="id_nivel_cargo"><option value="">Todos</option>
                    <?php foreach($niveis as $n): ?><option value="<?=$n['id_nivel']?>"><?=h($n['nome'])?></option><?php endforeach; ?></select>
                  </div>
                  <div class="ap-field">
                    <label class="ap-label">Cadastro desde</label>
                    <input type="date" class="ap-input filter-input" data-key="dt_cadastro_desde">
                  </div>
                </div>
              </div>
            </div>

            <!-- B) Objetivos -->
            <div class="ap-filter-group">
              <div class="ap-filter-header" onclick="this.parentElement.classList.toggle('open')">
                <span><i class="fas fa-bullseye"></i> Objetivos</span>
                <i class="fas fa-chevron-down chevron"></i>
              </div>
              <div class="ap-filter-body">
                <div class="ap-grid">
                  <div class="ap-field">
                    <label class="ap-label">Papel do usuario</label>
                    <select class="ap-select filter-input" data-key="obj_role"><option value="">—</option><option value="dono">Dono</option><option value="criador">Criador</option><option value="aprovador">Aprovador</option></select>
                  </div>
                  <div class="ap-field">
                    <label class="ap-label">Status</label>
                    <select class="ap-select filter-input" data-key="obj_status"><option value="">Todos</option>
                    <?php foreach($statusKr as $s): ?><option value="<?=h($s['id_status'])?>"><?=h($s['descricao_exibicao'])?></option><?php endforeach; ?></select>
                  </div>
                  <div class="ap-field">
                    <label class="ap-label">Pilar BSC</label>
                    <select class="ap-select filter-input" data-key="obj_pilar_bsc"><option value="">Todos</option>
                    <?php foreach($pilares as $p): ?><option value="<?=h($p['id_pilar'])?>"><?=h($p['descricao_exibicao'])?></option><?php endforeach; ?></select>
                  </div>
                  <div class="ap-field">
                    <label class="ap-label">Ciclo</label>
                    <select class="ap-select filter-input" data-key="obj_tipo_ciclo"><option value="">Todos</option>
                    <?php foreach($ciclos as $c): ?><option value="<?=h($c)?>"><?=h($c)?></option><?php endforeach; ?></select>
                  </div>
                  <div class="ap-field">
                    <label class="ap-label"><input type="checkbox" class="filter-input" data-key="obj_prazo_vencido" value="1"> Prazo vencido</label>
                  </div>
                </div>
              </div>
            </div>

            <!-- C) KRs -->
            <div class="ap-filter-group">
              <div class="ap-filter-header" onclick="this.parentElement.classList.toggle('open')">
                <span><i class="fas fa-chart-line"></i> Key Results</span>
                <i class="fas fa-chevron-down chevron"></i>
              </div>
              <div class="ap-filter-body">
                <div class="ap-grid">
                  <div class="ap-field">
                    <label class="ap-label">Papel</label>
                    <select class="ap-select filter-input" data-key="kr_role"><option value="">—</option><option value="responsavel">Responsavel</option><option value="criador">Criador</option><option value="envolvido">Envolvido</option></select>
                  </div>
                  <div class="ap-field">
                    <label class="ap-label">Status</label>
                    <select class="ap-select filter-input" data-key="kr_status"><option value="">Todos</option>
                    <?php foreach($statusKr as $s): ?><option value="<?=h($s['id_status'])?>"><?=h($s['descricao_exibicao'])?></option><?php endforeach; ?></select>
                  </div>
                  <div class="ap-field">
                    <label class="ap-label">Natureza</label>
                    <select class="ap-select filter-input" data-key="kr_natureza_kr"><option value="">Todas</option>
                    <?php foreach($naturezas as $n): ?><option value="<?=h($n['id_natureza'])?>"><?=h($n['descricao_exibicao'])?></option><?php endforeach; ?></select>
                  </div>
                  <div class="ap-field">
                    <label class="ap-label"><input type="checkbox" class="filter-input" data-key="kr_prazo_vencido" value="1"> Prazo vencido</label>
                  </div>
                </div>
              </div>
            </div>

            <!-- D) Iniciativas -->
            <div class="ap-filter-group">
              <div class="ap-filter-header" onclick="this.parentElement.classList.toggle('open')">
                <span><i class="fas fa-rocket"></i> Iniciativas</span>
                <i class="fas fa-chevron-down chevron"></i>
              </div>
              <div class="ap-filter-body">
                <div class="ap-grid">
                  <div class="ap-field">
                    <label class="ap-label">Papel</label>
                    <select class="ap-select filter-input" data-key="ini_role"><option value="">—</option><option value="responsavel">Responsavel</option><option value="criador">Criador</option><option value="envolvido">Envolvido</option></select>
                  </div>
                  <div class="ap-field">
                    <label class="ap-label">Status</label>
                    <select class="ap-select filter-input" data-key="ini_status"><option value="">Todos</option>
                    <?php foreach($statusKr as $s): ?><option value="<?=h($s['id_status'])?>"><?=h($s['descricao_exibicao'])?></option><?php endforeach; ?></select>
                  </div>
                </div>
              </div>
            </div>

            <!-- E) Orcamento -->
            <div class="ap-filter-group">
              <div class="ap-filter-header" onclick="this.parentElement.classList.toggle('open')">
                <span><i class="fas fa-wallet"></i> Orcamento</span>
                <i class="fas fa-chevron-down chevron"></i>
              </div>
              <div class="ap-filter-body">
                <div class="ap-grid">
                  <div class="ap-field">
                    <label class="ap-label">Status aprovacao</label>
                    <select class="ap-select filter-input" data-key="orc_status_aprovacao"><option value="">Todos</option>
                    <?php foreach($statusAprov as $s): ?><option value="<?=h($s['id_status'])?>"><?=h($s['descricao_exibicao'])?></option><?php endforeach; ?></select>
                  </div>
                </div>
              </div>
            </div>

            <!-- G) Dispositivo -->
            <div class="ap-filter-group">
              <div class="ap-filter-header" onclick="this.parentElement.classList.toggle('open')">
                <span><i class="fas fa-mobile-screen"></i> Dispositivo / App</span>
                <i class="fas fa-chevron-down chevron"></i>
              </div>
              <div class="ap-filter-body">
                <div class="ap-grid">
                  <div class="ap-field">
                    <label class="ap-label">Plataforma</label>
                    <select class="ap-select filter-input" data-key="device_platform"><option value="">Todas</option><option value="android">Android</option><option value="ios">iOS</option><option value="web">Web</option></select>
                  </div>
                </div>
              </div>
            </div>

            <!-- Audience preview -->
            <div class="ap-actions" style="margin-top:.75rem">
              <button type="button" class="ap-btn" onclick="previewAudience()"><i class="fas fa-eye"></i> Visualizar audiencia</button>
              <button type="button" class="ap-btn" onclick="saveSegment()"><i class="fas fa-bookmark"></i> Salvar segmento</button>
            </div>
            <div class="ap-audience-count" id="audienceCount" style="display:none">
              <i class="fas fa-users"></i> <span id="audienceNum">0</span> usuarios atingidos
            </div>
          </div>

          <!-- IA Sugestoes -->
          <div class="ap-card">
            <div class="ap-card-title"><i class="fas fa-wand-magic-sparkles"></i> Ajuda da IA</div>
            <div class="ap-field">
              <label class="ap-label">Descreva o objetivo do push</label>
              <textarea class="ap-textarea" id="f_ai_prompt" placeholder="Ex: Lembrar gestores que tem KRs com prazo proximo de vencer nesta semana"></textarea>
            </div>
            <div class="ap-grid" style="margin-top:.5rem">
              <div class="ap-field">
                <label class="ap-label">Tom</label>
                <select class="ap-select" id="f_ai_tom"><option value="profissional">Profissional</option><option value="motivacional">Motivacional</option><option value="urgente">Urgente</option><option value="casual">Casual</option></select>
              </div>
              <div class="ap-field">
                <label class="ap-label">Urgencia</label>
                <select class="ap-select" id="f_ai_urgencia"><option value="normal">Normal</option><option value="alta">Alta</option><option value="baixa">Baixa</option></select>
              </div>
            </div>
            <div class="ap-actions">
              <button type="button" class="ap-btn" onclick="generateAI()" id="btnAI"><i class="fas fa-sparkles"></i> Gerar sugestoes</button>
            </div>
            <div class="ap-ai-suggestions" id="aiSuggestions"></div>
          </div>
        </div>

        <!-- Right: Preview -->
        <div style="position:sticky;top:80px">
          <div class="ap-card">
            <div class="ap-card-title"><i class="fas fa-mobile-screen-button"></i> Preview</div>
            <div class="ap-preview-wrap">
              <!-- Android -->
              <div>
                <div class="ap-phone-label">Android</div>
                <div class="ap-phone">
                  <div class="ap-notch"></div>
                  <div class="ap-push-card">
                    <div class="ap-push-app"><div class="ap-push-app-icon"></div> PlanningBI &bull; agora</div>
                    <div class="ap-push-title" id="pv_title_a">Titulo do push</div>
                    <div class="ap-push-body" id="pv_body_a">Descricao do push aparece aqui...</div>
                    <img class="ap-push-img" id="pv_img_a" style="display:none">
                  </div>
                </div>
              </div>
              <!-- iOS -->
              <div>
                <div class="ap-phone-label">iPhone</div>
                <div class="ap-phone" style="border-radius:36px">
                  <div class="ap-notch" style="width:120px;height:28px;border-radius:0 0 16px 16px"></div>
                  <div class="ap-push-card" style="border-radius:18px">
                    <div class="ap-push-app"><div class="ap-push-app-icon"></div> PLANNINGBI &bull; agora</div>
                    <div class="ap-push-title" id="pv_title_i">Titulo do push</div>
                    <div class="ap-push-body" id="pv_body_i">Descricao do push aparece aqui...</div>
                    <img class="ap-push-img" id="pv_img_i" style="display:none">
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ===== TAB: HISTORICO ===== -->
    <div class="ap-panel" id="tab-history">
      <div class="ap-card">
        <div class="ap-card-title"><i class="fas fa-list"></i> Campanhas</div>
        <?php if(empty($campaigns)): ?>
          <p style="color:var(--text-secondary,#aaa);font-size:.85rem">Nenhuma campanha criada ainda.</p>
        <?php else: ?>
        <div style="overflow-x:auto">
          <table class="ap-table">
            <thead><tr>
              <th>Nome</th><th>Canal</th><th>Status</th><th>Audiencia</th><th>Enviados</th><th>Criado</th><th>Acoes</th>
            </tr></thead>
            <tbody>
            <?php foreach($campaigns as $c): ?>
            <tr>
              <td><strong><?=h($c['nome_interno'])?></strong><br><small style="color:var(--text-secondary,#aaa)"><?=h($c['titulo'])?></small></td>
              <td><?=h($c['canal'])?></td>
              <td><?=statusBadge($c['status'])?></td>
              <td><?=(int)($c['audience_estimate']??0)?></td>
              <td><?=(int)($c['total_sent']??0)?> / <?=(int)($c['total_recipients']??0)?></td>
              <td><small><?=$c['created_at']?date('d/m/y H:i',strtotime($c['created_at'])):'-'?></small></td>
              <td class="actions">
                <?php if($c['status']==='draft'):?><button onclick="editCampaign(<?=$c['id_campaign']?>)" title="Editar"><i class="fas fa-pen"></i></button><?php endif;?>
                <button onclick="duplicateCampaign(<?=$c['id_campaign']?>)" title="Duplicar"><i class="fas fa-copy"></i></button>
                <?php if($c['status']==='scheduled'):?><button onclick="cancelCampaign(<?=$c['id_campaign']?>)" title="Cancelar"><i class="fas fa-ban"></i></button><?php endif;?>
                <button onclick="viewStats(<?=$c['id_campaign']?>)" title="Stats"><i class="fas fa-chart-bar"></i></button>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ===== TAB: SEGMENTOS ===== -->
    <div class="ap-panel" id="tab-segments">
      <div class="ap-card">
        <div class="ap-card-title"><i class="fas fa-bookmark"></i> Segmentos Salvos</div>
        <?php if(empty($segments)): ?>
          <p style="color:var(--text-secondary,#aaa);font-size:.85rem">Nenhum segmento salvo.</p>
        <?php else: ?>
          <?php foreach($segments as $seg): ?>
          <div style="display:flex;align-items:center;gap:.5rem;padding:.5rem 0;border-bottom:1px solid rgba(255,255,255,.04)">
            <i class="fas fa-filter" style="color:var(--gold)"></i>
            <div style="flex:1">
              <strong style="font-size:.85rem"><?=h($seg['nome'])?></strong>
              <?php if($seg['descricao']):?><br><small style="color:var(--text-secondary,#aaa)"><?=h($seg['descricao'])?></small><?php endif;?>
            </div>
            <button class="ap-btn" onclick="loadSegmentById(<?=$seg['id_segment']?>)" style="font-size:.7rem"><i class="fas fa-arrow-right"></i> Usar</button>
            <button class="ap-btn danger" onclick="deleteSegment(<?=$seg['id_segment']?>)" style="font-size:.7rem"><i class="fas fa-trash"></i></button>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <?php include __DIR__ . '/partials/chat.php'; ?>
  </main>
</div>

<!-- Crop Modal -->
<div class="ap-crop-overlay" id="cropOverlay">
  <div class="ap-crop-box">
    <div class="ap-card-title"><i class="fas fa-crop"></i> Recortar imagem (1:1)</div>
    <img id="cropImage" style="max-width:100%">
    <div class="ap-actions" style="margin-top:.75rem">
      <button class="ap-btn primary" onclick="applyCrop()"><i class="fas fa-check"></i> Aplicar</button>
      <button class="ap-btn" onclick="cancelCrop()"><i class="fas fa-xmark"></i> Cancelar</button>
    </div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.js"></script>
<script>
const API = '/OKR_system/api/api_platform/v1';
const CSRF = '<?=h($csrf)?>';
let cropper = null;
let croppedBlob = null;

// Tabs
document.querySelectorAll('.ap-tab').forEach(tab => {
  tab.addEventListener('click', () => {
    document.querySelectorAll('.ap-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.ap-panel').forEach(p => p.classList.remove('active'));
    tab.classList.add('active');
    document.getElementById('tab-' + tab.dataset.tab).classList.add('active');
  });
});

// Preview
function updatePreview() {
  const t = document.getElementById('f_titulo').value || 'Titulo do push';
  const b = document.getElementById('f_descricao').value || 'Descricao do push aparece aqui...';
  ['a','i'].forEach(p => {
    document.getElementById('pv_title_'+p).textContent = t;
    document.getElementById('pv_body_'+p).textContent = b;
  });
}

// Image crop
function onImageSelect(input) {
  const file = input.files[0];
  if (!file) return;
  if (!['image/png','image/jpeg','image/webp'].includes(file.type)) {
    showFormStatus('Formato invalido. Use PNG, JPEG ou WebP.', 'err'); input.value = ''; return;
  }
  const reader = new FileReader();
  reader.onload = e => {
    const img = document.getElementById('cropImage');
    img.src = e.target.result;
    document.getElementById('cropOverlay').classList.add('show');
    if (cropper) cropper.destroy();
    cropper = new Cropper(img, { aspectRatio: 1, viewMode: 1, autoCropArea: 1 });
  };
  reader.readAsDataURL(file);
}
function applyCrop() {
  if (!cropper) return;
  const canvas = cropper.getCroppedCanvas({ width: 500, height: 500 });
  canvas.toBlob(blob => {
    croppedBlob = blob;
    const url = URL.createObjectURL(blob);
    const thumb = document.getElementById('imagePreviewThumb');
    thumb.src = url; thumb.style.display = 'block';
    ['a','i'].forEach(p => { const el = document.getElementById('pv_img_'+p); el.src = url; el.style.display = 'block'; });
    document.getElementById('cropOverlay').classList.remove('show');
    cropper.destroy(); cropper = null;
  }, 'image/jpeg', 0.85);
}
function cancelCrop() {
  document.getElementById('cropOverlay').classList.remove('show');
  if (cropper) { cropper.destroy(); cropper = null; }
  document.getElementById('f_image').value = '';
}

// Filters
function collectFilters() {
  const filters = {};
  document.querySelectorAll('.filter-input').forEach(el => {
    const key = el.dataset.key;
    if (el.type === 'checkbox') { if (el.checked) filters[key] = el.value || '1'; }
    else if (el.value) filters[key] = el.value;
  });
  return filters;
}

// Audience preview
async function previewAudience() {
  const filters = collectFilters();
  try {
    const r = await fetch(API+'/push/audience/preview', {
      method: 'POST', headers: {'Content-Type':'application/json','Accept':'application/json'},
      body: JSON.stringify({filters, csrf_token: CSRF})
    });
    const d = await r.json();
    if (d.ok) {
      document.getElementById('audienceCount').style.display = 'flex';
      document.getElementById('audienceNum').textContent = d.count;
    } else { showFormStatus(d.message || 'Erro', 'err'); }
  } catch(e) { showFormStatus('Erro: '+e.message, 'err'); }
}

// Save campaign
async function saveCampaign(action) {
  const form = document.getElementById('pushForm');
  const fd = new FormData(form);
  fd.set('filters_json', JSON.stringify(collectFilters()));
  fd.set('action', action);
  if (croppedBlob) fd.set('image_file', croppedBlob, 'push_image.jpg');

  try {
    const r = await fetch('/OKR_system/auth/push_save_campaign.php', { method:'POST', body:fd });
    const d = await r.json();
    if (d.success) {
      showFormStatus(d.message || 'Campanha salva!', 'ok');
      if (d.id_campaign) document.getElementById('f_id_campaign').value = d.id_campaign;
      if (action === 'send' || action === 'schedule') setTimeout(()=>location.reload(), 1500);
    } else { showFormStatus(d.error || 'Erro ao salvar', 'err'); }
  } catch(e) { showFormStatus('Erro: '+e.message, 'err'); }
}

// Send test
async function sendTest() {
  const campId = document.getElementById('f_id_campaign').value;
  if (!campId) { showFormStatus('Salve a campanha como rascunho primeiro.', 'err'); return; }
  try {
    const r = await fetch(API+'/push/campaigns/'+campId+'/send-test', {
      method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({csrf_token:CSRF})
    });
    const d = await r.json();
    showFormStatus(d.ok ? 'Envio teste realizado!' : (d.message||'Erro'), d.ok ? 'ok' : 'err');
  } catch(e) { showFormStatus('Erro: '+e.message, 'err'); }
}

// AI
async function generateAI() {
  const prompt = document.getElementById('f_ai_prompt').value.trim();
  if (!prompt) { showFormStatus('Descreva o objetivo do push para a IA.', 'err'); return; }
  const btn = document.getElementById('btnAI');
  btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Gerando...';
  try {
    const r = await fetch(API+'/push/ai/suggestions', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body:JSON.stringify({prompt, tom:document.getElementById('f_ai_tom').value, urgencia:document.getElementById('f_ai_urgencia').value, categoria:document.getElementById('f_categoria').value, csrf_token:CSRF})
    });
    const d = await r.json();
    const box = document.getElementById('aiSuggestions');
    box.innerHTML = '';
    if (d.ok && d.suggestions) {
      d.suggestions.forEach(s => {
        const div = document.createElement('div');
        div.className = 'ap-ai-option';
        div.innerHTML = '<div class="ai-title">'+esc(s.titulo)+'</div><div class="ai-body">'+esc(s.descricao)+'</div>';
        div.onclick = () => { document.getElementById('f_titulo').value = s.titulo; document.getElementById('f_descricao').value = s.descricao; updatePreview(); };
        box.appendChild(div);
      });
    } else { box.innerHTML = '<p style="color:#f87171;font-size:.8rem">'+(d.message||'Sem sugestoes')+'</p>'; }
  } catch(e) { showFormStatus('Erro IA: '+e.message, 'err'); }
  finally { btn.disabled = false; btn.innerHTML = '<i class="fas fa-sparkles"></i> Gerar sugestoes'; }
}

// Schedule toggle
function toggleSchedule() {
  const on = document.getElementById('f_schedule').checked;
  document.getElementById('scheduleFields').style.display = on ? '' : 'none';
  document.getElementById('f_recurring').disabled = !on;
  document.getElementById('btnSchedule').disabled = !on;
  document.getElementById('recurFields').style.display = on && document.getElementById('f_recurring').checked ? '' : 'none';
}
document.getElementById('f_recurring').addEventListener('change', () => {
  document.getElementById('recurFields').style.display = document.getElementById('f_recurring').checked ? '' : 'none';
});

// Segments
async function saveSegment() {
  const nome = prompt('Nome do segmento:');
  if (!nome) return;
  try {
    const r = await fetch(API+'/push/segments', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body:JSON.stringify({nome, filters_json:JSON.stringify(collectFilters()), csrf_token:CSRF})
    });
    const d = await r.json();
    if (d.ok) { showFormStatus('Segmento salvo!', 'ok'); setTimeout(()=>location.reload(),1000); }
    else showFormStatus(d.message||'Erro','err');
  } catch(e) { showFormStatus('Erro: '+e.message, 'err'); }
}
function loadSegment(id) {
  const opt = document.querySelector('#f_segment option[value="'+id+'"]');
  if (!opt || !id) return;
  const filters = JSON.parse(opt.dataset.filters || '{}');
  document.querySelectorAll('.filter-input').forEach(el => {
    const key = el.dataset.key;
    if (el.type === 'checkbox') el.checked = !!filters[key];
    else el.value = filters[key] || '';
  });
  previewAudience();
}
function loadSegmentById(id) {
  document.getElementById('f_segment').value = id;
  loadSegment(id);
  document.querySelector('.ap-tab[data-tab="new"]').click();
}
async function deleteSegment(id) {
  if (!confirm('Excluir segmento?')) return;
  await fetch(API+'/push/segments/'+id, {method:'DELETE',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf_token:CSRF})});
  location.reload();
}

// Campaign actions
async function duplicateCampaign(id) {
  const r = await fetch(API+'/push/campaigns/'+id+'/duplicate', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf_token:CSRF})});
  const d = await r.json();
  if (d.ok) { showFormStatus('Campanha duplicada!', 'ok'); setTimeout(()=>location.reload(),1000); }
}
async function cancelCampaign(id) {
  if (!confirm('Cancelar campanha agendada?')) return;
  const r = await fetch(API+'/push/campaigns/'+id+'/cancel', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf_token:CSRF})});
  const d = await r.json();
  if (d.ok) location.reload();
}
function editCampaign(id) { /* TODO: load campaign into form via API */ showFormStatus('Funcionalidade de edicao em breve.','ok'); }
function viewStats(id) { /* TODO: modal with stats */ showFormStatus('Stats em breve.','ok'); }

// Helpers
function showFormStatus(msg, type) {
  const el = document.getElementById('formStatus');
  el.className = 'ap-status show ' + (type||'');
  el.textContent = msg;
  if (type === 'ok') setTimeout(()=>{ el.classList.remove('show'); }, 3000);
}
function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
</script>
</body>
</html>
