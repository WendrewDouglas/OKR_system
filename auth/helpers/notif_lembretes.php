<?php
declare(strict_types=1);

/**
 * Lembretes e relatórios de pendência (e-mail + push).
 *
 * Quatro chaves por usuário (tabela usuarios_notif_pref, migração 012), todas
 * desligadas por padrão:
 *   lembrete_marco       marco sem apontamento, 3 dias antes e no dia
 *   lembrete_iniciativa  iniciativa não concluída, no dia do prazo
 *   relatorio_atrasos    itens vencidos da pessoa, terça e quinta
 *   resumo_semanal       pendências de todos os responsáveis, segunda
 *
 * Regras de negócio:
 *   - só o responsável PRINCIPAL recebe lembrete (corresponsável não);
 *   - atraso conta a partir do dia seguinte ao vencimento (estado 'vencido'
 *     da agenda); no dia do vencimento é só lembrete;
 *   - KR cancelado/pausado/concluído e iniciativa concluída/cancelada/pausada
 *     não geram cobrança (quem decide é agenda_estado());
 *   - só empresa ativa (company.ativo, Painel de Empresas) gera aviso;
 *   - admin_master recebe o resumo de cada empresa ativa, um e-mail por
 *     empresa, mesmo que a própria empresa dele esteja inativa; os demais
 *     recebem só o da própria empresa;
 *   - um e-mail pessoal por dia, juntando tudo o que couber naquele dia.
 *
 * Os itens saem de agenda_build_events(), que já amarra marco e iniciativa à
 * empresa e já resolve responsável e status. Não há uma segunda regra aqui.
 *
 * Disparo: tools/notificacoes_okr.php (cron diário às 7h).
 */

require_once __DIR__ . '/notif_prefs.php';
require_once __DIR__ . '/agenda_events.php';
require_once __DIR__ . '/num_format.php';
require_once __DIR__ . '/nome_format.php';
require_once __DIR__ . '/../push_helpers.php';

const NOTIF_BASE_URL    = 'https://planningbi.com.br/OKR_system';
/** Itens por responsável no resumo semanal; o resto vira "+N itens". */
const NOTIF_RESUMO_MAX_POR_PESSOA = 15;

function notif_optout_url(int $idUser): string {
  return NOTIF_BASE_URL . '/auth/notif_optout.php?u=' . $idUser . '&t=' . notif_optout_token($idUser);
}

/* ======================================================================
 * Empresas e itens
 * ==================================================================== */

/**
 * Empresas ativas = company.ativo = 1 (ligado no Painel de Empresas, migração 013).
 * Só elas geram avisos. Sem a coluna (migração não aplicada), nenhuma empresa é ativa.
 * @return array<int,string> id_company => nome
 */
function notif_empresas_ativas(PDO $pdo): array {
  $st = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'company' AND COLUMN_NAME = 'ativo'");
  $st->execute();
  if (!$st->fetchColumn()) return [];

  $ativas = [];
  $q = $pdo->query("
    SELECT id_company, COALESCE(NULLIF(organizacao,''), razao_social, CONCAT('Empresa #', id_company)) AS nome
      FROM company
     WHERE ativo = 1
     ORDER BY nome
  ");
  foreach ($q as $r) $ativas[(int)$r['id_company']] = (string)$r['nome'];
  return $ativas;
}

/**
 * Marcos e iniciativas da empresa, já com responsável principal e texto pronto.
 * @return array{itens: list<array>, pessoas: array<int,array>}
 */
function notif_itens_empresa(PDO $pdo, int $companyId, string $hoje): array {
  $ag = agenda_build_events($pdo, $companyId, $hoje, false);
  $itens = [];

  foreach ($ag['eventos'] as $ev) {
    $tipo = $ev['tipo'];
    if ($tipo !== 'marco' && $tipo !== 'iniciativa') continue;
    if (!in_array($ev['estado'], ['hoje', 'proximo', 'vencido'], true)) continue;

    $kr  = $ag['krs'][$ev['id_kr']] ?? null;
    $obj = $ag['objetivos'][$ev['id_objetivo']] ?? null;
    if (!$kr) continue;

    if ($tipo === 'marco') {
      $resp   = (int)($kr['responsavel'] ?? 0);
      $meta   = $ev['meta'] ?? [];
      $titulo = 'Apontar o marco ' . (int)($meta['num_ordem'] ?? 0) . ' do KR';
      $det    = isset($meta['valor_esperado'])
        ? 'Esperado: ' . num_br($meta['valor_esperado']) . ($kr['unidade'] ? ' ' . $kr['unidade'] : '')
        : '';
    } else {
      $ini    = $ag['iniciativas'][$ev['id_iniciativa']] ?? null;
      if (!$ini) continue;
      $resp   = 0;
      foreach ($ini['pessoas'] as $p) {
        if ($p['papel'] === 'responsavel') { $resp = (int)$p['id']; break; }
      }
      $titulo = $ini['descricao'];
      $rotulos = ['nao iniciado' => 'Não iniciado', 'em andamento' => 'Em andamento'];
      $det    = 'Status atual: ' . ($rotulos[$ev['status']] ?? ($ev['status'] !== '' ? ucfirst($ev['status']) : 'sem status'));
    }

    $itens[] = [
      'tipo'      => $tipo,
      'data'      => $ev['data'],
      'estado'    => $ev['estado'],
      'dias'      => (int)round((strtotime($hoje) - strtotime($ev['data'])) / 86400),
      'titulo'    => $titulo,
      'detalhe'   => $det,
      'kr'        => $kr['descricao'],
      'objetivo'  => $obj['descricao'] ?? '',
      'resp'      => $resp,
      'url'       => NOTIF_BASE_URL . '/views/detalhe_okr.php?id=' . (int)$ev['id_objetivo']
                     . '&kr=' . rawurlencode((string)$ev['id_kr']),
    ];
  }

  return ['itens' => $itens, 'pessoas' => $ag['pessoas']];
}

/**
 * Seções do e-mail pessoal de um dia.
 * @return array{hoje: list, em3: list, atrasados: list}
 */
function notif_pacote_pessoal(array $itens, int $idUser, array $prefs, string $hoje): array {
  $em3   = date('Y-m-d', strtotime($hoje . ' +3 days'));
  $dow   = (int)date('N', strtotime($hoje)); // 1=seg .. 7=dom
  $atras = $prefs['relatorio_atrasos'] && ($dow === 2 || $dow === 4);

  $out = ['hoje' => [], 'em3' => [], 'atrasados' => []];
  foreach ($itens as $it) {
    if ($it['resp'] !== $idUser) continue;
    $ligado = $it['tipo'] === 'marco' ? $prefs['lembrete_marco'] : $prefs['lembrete_iniciativa'];

    if ($it['estado'] === 'hoje' && $ligado) {
      $out['hoje'][] = $it;
    } elseif ($it['tipo'] === 'marco' && $it['estado'] === 'proximo' && $it['data'] === $em3 && $ligado) {
      $out['em3'][] = $it;
    } elseif ($it['estado'] === 'vencido' && $atras) {
      $out['atrasados'][] = $it;
    }
  }
  usort($out['atrasados'], static fn($a, $b) => $b['dias'] <=> $a['dias']);
  return $out;
}

/**
 * Atrasos da empresa agrupados por responsável (0 = sem responsável).
 * @return array<int, list<array>>
 */
function notif_resumo_empresa(array $itens): array {
  $g = [];
  foreach ($itens as $it) {
    if ($it['estado'] !== 'vencido') continue;
    $g[$it['resp']][] = $it;
  }
  foreach ($g as &$lista) usort($lista, static fn($a, $b) => $b['dias'] <=> $a['dias']);
  unset($lista);
  uasort($g, static fn($a, $b) => count($b) <=> count($a));
  return $g;
}

/* ======================================================================
 * Renderização do e-mail
 * ==================================================================== */

function notif_h(?string $s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function notif_data_br(string $ymd): string {
  $t = strtotime($ymd);
  return $t ? date('d/m/Y', $t) : $ymd;
}

function notif_plural(int $n, string $um, string $varios): string {
  return $n . ' ' . ($n === 1 ? $um : $varios);
}

/** Linhas de itens. $comPrazo: 'atraso' mostra dias de atraso; 'data' mostra a data. */
function notif_render_itens(array $itens, string $comPrazo): string {
  $html = '';
  foreach ($itens as $it) {
    $chip = $it['tipo'] === 'marco'
      ? '<span style="display:inline-block;padding:2px 8px;border-radius:999px;background:#E0E7FF;color:#3730A3;font-size:11px;font-weight:700;">MARCO</span>'
      : '<span style="display:inline-block;padding:2px 8px;border-radius:999px;background:#DCFCE7;color:#166534;font-size:11px;font-weight:700;">INICIATIVA</span>';

    $prazo = $comPrazo === 'atraso'
      ? '<span style="color:#B91C1C;font-weight:700;">' . notif_plural($it['dias'], 'dia', 'dias') . ' de atraso</span>'
        . ' <span style="color:#6B7280;">(venceu em ' . notif_data_br($it['data']) . ')</span>'
      : '<span style="color:#374151;font-weight:700;">Prazo: ' . notif_data_br($it['data']) . '</span>';

    $html .= '<tr><td style="padding:12px 0;border-bottom:1px solid #E5E7EB;">'
      . '<div style="margin-bottom:4px;">' . $chip . '</div>'
      . '<div style="font-size:15px;font-weight:700;color:#111827;line-height:1.35;">'
      . '<a href="' . notif_h($it['url']) . '" style="color:#111827;text-decoration:none;">' . notif_h($it['titulo']) . '</a></div>'
      . '<div style="font-size:13px;color:#4B5563;margin-top:3px;line-height:1.4;">KR: ' . notif_h($it['kr']) . '</div>'
      . ($it['detalhe'] !== '' ? '<div style="font-size:13px;color:#4B5563;margin-top:2px;">' . notif_h($it['detalhe']) . '</div>' : '')
      . '<div style="font-size:13px;margin-top:4px;">' . $prazo . '</div>'
      . '<div style="margin-top:6px;"><a href="' . notif_h($it['url']) . '" style="font-size:13px;color:#B45309;font-weight:700;">'
      . ($it['tipo'] === 'marco' ? 'Abrir e apontar' : 'Abrir e atualizar o status') . '</a></div>'
      . '</td></tr>';
  }
  return $html;
}

function notif_render_secao(string $titulo, string $intro, string $cor, string $corpo): string {
  return '<tr><td style="padding:22px 28px 0;">'
    . '<div style="border-left:4px solid ' . $cor . ';padding-left:12px;">'
    . '<div style="font-size:17px;font-weight:800;color:#111827;">' . notif_h($titulo) . '</div>'
    . ($intro !== '' ? '<div style="font-size:13px;color:#6B7280;margin-top:2px;">' . notif_h($intro) . '</div>' : '')
    . '</div>'
    . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-top:6px;">' . $corpo . '</table>'
    . '</td></tr>';
}

/** Seções do e-mail pessoal. */
function notif_render_pessoal(array $pac): string {
  $html = '';
  if ($pac['hoje']) {
    $html .= notif_render_secao('Vence hoje',
      'Faça o apontamento ou atualize o status ainda hoje.', '#F59E0B',
      notif_render_itens($pac['hoje'], 'data'));
  }
  if ($pac['em3']) {
    $html .= notif_render_secao('Vence em 3 dias',
      'A data do marco está chegando. Já dá para preparar o apontamento.', '#3B82F6',
      notif_render_itens($pac['em3'], 'data'));
  }
  if ($pac['atrasados']) {
    $html .= notif_render_secao('Em atraso',
      'Estes itens já passaram do prazo e continuam pendentes.', '#DC2626',
      notif_render_itens($pac['atrasados'], 'atraso'));
  }
  return $html;
}

/** Seção do resumo semanal de uma empresa. */
function notif_render_resumo(string $empresa, array $grupos, array $pessoas): string {
  $total = 0; $marcos = 0; $inis = 0;
  foreach ($grupos as $lista) {
    foreach ($lista as $it) { $total++; $it['tipo'] === 'marco' ? $marcos++ : $inis++; }
  }

  if ($total === 0) {
    return notif_render_secao('Resumo semanal: ' . $empresa, '', '#16A34A',
      '<tr><td style="padding:12px 0;font-size:14px;color:#166534;">Nenhuma pendência em atraso. Todos os marcos e iniciativas estão em dia.</td></tr>');
  }

  $stat = static fn(string $n, string $l) =>
    '<td align="center" style="padding:10px 4px;background:#F9FAFB;border:1px solid #E5E7EB;border-radius:8px;">'
    . '<div style="font-size:22px;font-weight:800;color:#111827;">' . $n . '</div>'
    . '<div style="font-size:11px;color:#6B7280;text-transform:uppercase;letter-spacing:.04em;">' . $l . '</div></td>';

  $corpo = '<tr><td style="padding:10px 0 4px;">'
    . '<table role="presentation" width="100%" cellspacing="6" cellpadding="0"><tr>'
    . $stat((string)$total, 'Em atraso')
    . $stat((string)$marcos, 'Marcos')
    . $stat((string)$inis, 'Iniciativas')
    . $stat((string)count(array_filter(array_keys($grupos))), 'Responsáveis')
    . '</tr></table></td></tr>';

  foreach ($grupos as $uid => $lista) {
    $nome = $uid > 0 ? ($pessoas[$uid]['nome'] ?? ('Usuário ' . $uid)) : 'Sem responsável definido';
    $corpo .= '<tr><td style="padding:14px 0 2px;font-size:14px;font-weight:800;color:#111827;border-bottom:2px solid #F1C40F;">'
      . notif_h($nome) . ' <span style="font-weight:600;color:#6B7280;">(' . notif_plural(count($lista), 'item', 'itens') . ')</span></td></tr>';
    foreach (array_slice($lista, 0, NOTIF_RESUMO_MAX_POR_PESSOA) as $it) {
      $tipo = $it['tipo'] === 'marco' ? 'Marco' : 'Iniciativa';
      $corpo .= '<tr><td style="padding:7px 0;border-bottom:1px solid #F3F4F6;font-size:13px;color:#374151;line-height:1.4;">'
        . '<a href="' . notif_h($it['url']) . '" style="color:#111827;text-decoration:none;font-weight:600;">'
        . $tipo . ': ' . notif_h($it['titulo']) . '</a>'
        . '<br><span style="color:#6B7280;">KR: ' . notif_h($it['kr']) . '</span>'
        . '<br><span style="color:#B91C1C;font-weight:700;">' . notif_plural($it['dias'], 'dia', 'dias') . ' de atraso</span>'
        . ' <span style="color:#9CA3AF;">(venceu em ' . notif_data_br($it['data']) . ')</span></td></tr>';
    }
    $resto = count($lista) - NOTIF_RESUMO_MAX_POR_PESSOA;
    if ($resto > 0) {
      $corpo .= '<tr><td style="padding:6px 0;font-size:12px;color:#6B7280;">E mais ' . notif_plural($resto, 'item', 'itens') . ' na agenda.</td></tr>';
    }
  }

  return notif_render_secao('Resumo semanal: ' . $empresa,
    'Marcos sem apontamento e iniciativas vencidas, por responsável.', '#F1C40F', $corpo);
}

/** Moldura do e-mail (layout em tabela, estilo inline, compatível com Outlook/Gmail). */
function notif_render_email(string $titulo, string $saudacao, string $secoes, int $idUser): string {
  $agenda = NOTIF_BASE_URL . '/views/agenda.php';
  return '<!DOCTYPE html><html lang="pt-br"><head><meta charset="utf-8">'
    . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>' . notif_h($titulo) . '</title></head>'
    . '<body style="margin:0;padding:0;background:#F3F4F6;font-family:Segoe UI,Arial,Helvetica,sans-serif;">'
    . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#F3F4F6;"><tr><td align="center" style="padding:24px 12px;">'
    . '<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;width:100%;background:#FFFFFF;border-radius:14px;overflow:hidden;">'
    . '<tr><td style="background:#0B1020;padding:22px 28px;">'
    . '<div style="font-size:12px;font-weight:800;letter-spacing:.12em;color:#F1C40F;">OKR SYSTEM · PLANNINGBI</div>'
    . '<div style="font-size:21px;font-weight:800;color:#FFFFFF;margin-top:6px;line-height:1.3;">' . notif_h($titulo) . '</div>'
    . '</td></tr>'
    . '<tr><td style="padding:22px 28px 0;font-size:15px;color:#374151;line-height:1.5;">' . notif_h($saudacao) . '</td></tr>'
    . $secoes
    . '<tr><td align="center" style="padding:26px 28px 8px;">'
    . '<a href="' . notif_h($agenda) . '" style="display:inline-block;background:#F1C40F;color:#111827;font-weight:800;font-size:14px;text-decoration:none;padding:12px 22px;border-radius:10px;">Ver agenda completa</a>'
    . '</td></tr>'
    . '<tr><td style="padding:18px 28px 24px;font-size:12px;color:#9CA3AF;line-height:1.5;border-top:1px solid #F3F4F6;">'
    . 'Você recebe este e-mail porque os avisos de pendência foram ativados para o seu usuário no OKR System. '
    . '<a href="' . notif_h(notif_optout_url($idUser)) . '" style="color:#6B7280;">Deixar de receber</a>.'
    . '</td></tr>'
    . '</table></td></tr></table></body></html>';
}

/* ======================================================================
 * Push, inbox e log
 * ==================================================================== */

/** @return array{status:string, detalhe:string} */
function notif_push_usuario(PDO $pdo, int $idUser, string $titulo, string $corpo, string $route): array {
  $st = $pdo->prepare("SELECT id_device, token FROM push_devices
                        WHERE id_user = ? AND is_active = 1 AND notifications_enabled = 1");
  $st->execute([$idUser]);
  $devs = $st->fetchAll(PDO::FETCH_ASSOC);
  if (!$devs) return ['status' => 'sem_destino', 'detalhe' => 'nenhum aparelho com push ativo'];

  $ok = 0; $erros = [];
  foreach ($devs as $d) {
    $r = push_send_fcm((string)$d['token'], [
      'title'    => $titulo,
      'body'     => $corpo,
      'priority' => 'high',
      'data'     => ['route' => $route, 'category' => 'lembrete_okr'],
    ]);
    if ($r['success']) { $ok++; continue; }
    $erros[] = (string)$r['error'];
    if (preg_match('/NotRegistered|InvalidRegistration|UNREGISTERED/i', (string)$r['error'])) {
      $pdo->prepare("UPDATE push_devices SET is_active = 0, updated_at = NOW() WHERE id_device = ?")
          ->execute([$d['id_device']]);
    }
  }
  return $ok > 0
    ? ['status' => 'enviado', 'detalhe' => "$ok de " . count($devs) . ' aparelho(s)']
    : ['status' => 'falha', 'detalhe' => mb_substr(implode(' | ', $erros), 0, 480)];
}

/** Espelho na central de notificações (web e app), independe de ter aparelho. */
function notif_inbox(PDO $pdo, int $idUser, string $titulo, string $mensagem, string $url, array $meta): void {
  $pdo->prepare("
    INSERT INTO notificacoes (id_user, tipo, titulo, mensagem, url, lida, dt_criado, meta_json)
    VALUES (?, 'lembrete', ?, ?, ?, 0, NOW(), ?)
  ")->execute([
    $idUser, mb_substr($titulo, 0, 180), mb_substr($mensagem, 0, 5000),
    mb_substr($url, 0, 255), json_encode($meta, JSON_UNESCAPED_UNICODE),
  ]);
}

/** Já saiu hoje? 'falha' não conta: uma nova rodada tenta de novo. */
function notif_ja_enviado(PDO $pdo, int $idUser, string $dia, string $canal, string $chave): bool {
  $st = $pdo->prepare("SELECT status FROM notif_envio_log
                        WHERE id_user = ? AND data_ref = ? AND canal = ? AND chave = ?");
  $st->execute([$idUser, $dia, $canal, $chave]);
  $s = $st->fetchColumn();
  return $s === 'enviado' || $s === 'sem_destino';
}

function notif_registrar(PDO $pdo, int $idUser, string $dia, string $canal, string $chave,
                         string $status, int $itens, string $detalhe = ''): void {
  $pdo->prepare("
    INSERT INTO notif_envio_log (id_user, data_ref, canal, chave, status, itens, detalhe)
    VALUES (?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE status = VALUES(status), itens = VALUES(itens), detalhe = VALUES(detalhe)
  ")->execute([$idUser, $dia, $canal, $chave, $status, $itens, mb_substr($detalhe, 0, 500)]);
}
