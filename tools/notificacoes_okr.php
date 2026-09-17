<?php
/**
 * tools/notificacoes_okr.php
 * Disparo diário dos lembretes e relatórios de pendência (e-mail + push).
 * Regras em auth/helpers/notif_lembretes.php.
 *
 * Cron (cPanel), todo dia às 7h:
 *   0 7 * * * /usr/local/bin/php /home2/planni40/public_html/OKR_system/tools/notificacoes_okr.php >> /home2/planni40/notificacoes_okr.log 2>&1
 *
 * Opções:
 *   --dry-run          não envia nem grava nada; só lista o que sairia
 *   --date=AAAA-MM-DD  simula outro dia (segunda = resumo, terça/quinta = atrasos)
 *   --company=N        só usuários da empresa N (e, no resumo do admin, só ela)
 *   --user=N           só o usuário N
 *   --to=email         manda todos os e-mails para este endereço (teste: sem push, sem log)
 *   --no-push          não envia push nem grava na central de notificações
 *   --preview=DIR      grava o HTML de cada e-mail em DIR (funciona com --dry-run)
 *
 * Idempotente: notif_envio_log impede segundo envio no mesmo dia.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/functions.php';
require_once __DIR__ . '/../auth/helpers/notif_lembretes.php';

/* ---------- opções ---------- */
$opt = getopt('', ['dry-run', 'date:', 'company:', 'user:', 'to:', 'no-push', 'preview:']);
$dry       = isset($opt['dry-run']);
$hoje      = isset($opt['date']) ? (string)$opt['date'] : date('Y-m-d');
$soEmpresa = isset($opt['company']) ? (int)$opt['company'] : 0;
$soUser    = isset($opt['user']) ? (int)$opt['user'] : 0;
$paraTeste = isset($opt['to']) ? trim((string)$opt['to']) : '';
$semPush   = isset($opt['no-push']) || $paraTeste !== '';
$preview   = isset($opt['preview']) ? rtrim((string)$opt['preview'], '/\\') : '';
$gravaLog  = !$dry && $paraTeste === '';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hoje) || !strtotime($hoje)) {
  fwrite(STDERR, "Data inválida: $hoje\n"); exit(1);
}
if ($paraTeste !== '' && !filter_var($paraTeste, FILTER_VALIDATE_EMAIL)) {
  fwrite(STDERR, "E-mail de teste inválido: $paraTeste\n"); exit(1);
}
if ($preview !== '' && !is_dir($preview) && !@mkdir($preview, 0775, true)) {
  fwrite(STDERR, "Não consegui criar $preview\n"); exit(1);
}

/* ---------- trava contra execução simultânea ---------- */
$lock = fopen(sys_get_temp_dir() . '/okr_notificacoes.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
  fwrite(STDERR, "Outra execução em andamento.\n"); exit(1);
}

$pdo = new PDO(
  'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
  DB_USER, DB_PASS,
  [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$dow       = (int)date('N', strtotime($hoje)); // 1=seg
$segunda   = $dow === 1;
$diasSem   = [1 => 'segunda', 2 => 'terça', 3 => 'quarta', 4 => 'quinta', 5 => 'sexta', 6 => 'sábado', 7 => 'domingo'];
// Empresa demo (11) fica fora do resumo do admin. Ajustável pelo .env.
$excluir   = array_filter(array_map('intval', explode(',', (string)env('NOTIF_EXCLUIR_EMPRESAS', '11'))));

$modo = $dry ? 'DRY-RUN' : ($paraTeste !== '' ? "TESTE para $paraTeste" : 'REAL');
echo '[' . date('c') . "] notificacoes_okr $modo dia=$hoje ({$diasSem[$dow]})\n";

/* ---------- destinatários: quem tem alguma chave ligada ---------- */
$sql = "
  SELECT u.id_user, u.primeiro_nome, u.ultimo_nome, u.email_corporativo, u.id_company,
         p.lembrete_marco, p.lembrete_iniciativa, p.relatorio_atrasos, p.resumo_semanal,
         EXISTS (SELECT 1 FROM rbac_user_role ur JOIN rbac_roles r ON r.role_id = ur.role_id
                  WHERE ur.user_id = u.id_user AND r.role_key = 'admin_master' AND r.is_active = 1) AS admin_master
    FROM usuarios_notif_pref p
    JOIN usuarios u ON u.id_user = p.id_user
   WHERE u.ativo = 1
     AND (p.lembrete_marco = 1 OR p.lembrete_iniciativa = 1 OR p.relatorio_atrasos = 1 OR p.resumo_semanal = 1)
";
$par = [];
if ($soUser)    { $sql .= ' AND u.id_user = ?';    $par[] = $soUser; }
if ($soEmpresa) { $sql .= ' AND (u.id_company = ? OR (p.resumo_semanal = 1 AND EXISTS (SELECT 1 FROM rbac_user_role ur2 JOIN rbac_roles r2 ON r2.role_id = ur2.role_id WHERE ur2.user_id = u.id_user AND r2.role_key = \'admin_master\')))'; $par[] = $soEmpresa; }
$sql .= ' ORDER BY u.id_user';
$st = $pdo->prepare($sql);
$st->execute($par);
$usuarios = $st->fetchAll();

/* ---------- cache de itens por empresa ---------- */
$cache = [];
$dadosEmpresa = static function (int $cid) use (&$cache, $pdo, $hoje): array {
  if (!isset($cache[$cid])) $cache[$cid] = notif_itens_empresa($pdo, $cid, $hoje);
  return $cache[$cid];
};
$ativas = null;

$tot = ['usuarios' => count($usuarios), 'emails' => 0, 'push' => 0, 'pulados' => 0, 'falhas' => 0];

$enviarEmail = static function (int $uid, string $email, string $chave, string $assunto, string $html, int $n)
  use ($pdo, $dry, $paraTeste, $gravaLog, $preview, $hoje, &$tot): void {
  if ($gravaLog && notif_ja_enviado($pdo, $uid, $hoje, 'email', $chave)) {
    echo "    = e-mail $chave já enviado hoje\n"; $tot['pulados']++; return;
  }
  if ($preview !== '') {
    file_put_contents("$preview/{$hoje}_u{$uid}_" . str_replace(':', '-', $chave) . '.html', $html);
  }
  $destino = $paraTeste !== '' ? $paraTeste : $email;
  echo "    > e-mail [$chave] para " . mask_email($destino) . ": $assunto ($n itens)\n";
  if ($dry) return;

  if (!filter_var($destino, FILTER_VALIDATE_EMAIL)) {
    if ($gravaLog) notif_registrar($pdo, $uid, $hoje, 'email', $chave, 'sem_destino', $n, 'e-mail inválido');
    echo "      ! e-mail inválido\n"; return;
  }
  $ok = sendTransactionalMail($destino, $assunto, $html, SMTP_FROM ?: 'contato@planningbi.com.br', 'OKR System');
  if ($gravaLog) notif_registrar($pdo, $uid, $hoje, 'email', $chave, $ok ? 'enviado' : 'falha', $n);
  $ok ? $tot['emails']++ : $tot['falhas']++;
  usleep(400000); // folga para o limite de envio por hora da hospedagem
};

foreach ($usuarios as $u) {
  $uid   = (int)$u['id_user'];
  $cid   = (int)$u['id_company'];
  $admin = (int)$u['admin_master'] === 1;
  $prefs = [];
  foreach (NOTIF_PREF_CHAVES as $k) $prefs[$k] = (int)$u[$k] === 1;
  $nome  = nome_exibicao((string)$u['primeiro_nome'], (string)$u['ultimo_nome']);
  $email = (string)$u['email_corporativo'];

  echo "  - #$uid $nome (empresa $cid" . ($admin ? ', admin_master' : '') . ")\n";

  /* pendências pessoais */
  $pac = ['hoje' => [], 'em3' => [], 'atrasados' => []];
  $querPessoal = $prefs['lembrete_marco'] || $prefs['lembrete_iniciativa'] || $prefs['relatorio_atrasos'];
  if ($querPessoal && $cid > 0 && (!$soEmpresa || $soEmpresa === $cid)) {
    $pac = notif_pacote_pessoal($dadosEmpresa($cid)['itens'], $uid, $prefs, $hoje);
  }
  $nHoje = count($pac['hoje']); $nEm3 = count($pac['em3']); $nAtr = count($pac['atrasados']);
  $nPessoal = $nHoje + $nEm3 + $nAtr;

  /* resumos semanais */
  $resumos = []; // cid => [nome, grupos, pessoas, total]
  if ($segunda && $prefs['resumo_semanal']) {
    if ($admin) {
      // --company explícito vale mesmo para empresa excluída (é assim que se testa na demo).
      $ativas ??= notif_empresas_ativas($pdo, $soEmpresa ? [] : $excluir);
      $alvo = $soEmpresa ? array_intersect_key($ativas, [$soEmpresa => true]) : $ativas;
    } else {
      $nm = $pdo->prepare("SELECT COALESCE(NULLIF(organizacao,''), razao_social, CONCAT('Empresa #', id_company)) FROM company WHERE id_company = ?");
      $nm->execute([$cid]);
      $alvo = $cid > 0 ? [$cid => (string)($nm->fetchColumn() ?: "Empresa #$cid")] : [];
    }
    foreach ($alvo as $rc => $rnome) {
      $d = $dadosEmpresa((int)$rc);
      $g = notif_resumo_empresa($d['itens']);
      $resumos[(int)$rc] = [$rnome, $g, $d['pessoas'], array_sum(array_map('count', $g))];
    }
  }

  /* e-mail pessoal (para não-admin, já com o resumo da própria empresa) */
  $partes = [];
  if ($nHoje) $partes[] = notif_plural($nHoje, 'item vence hoje', 'itens vencem hoje');
  if ($nEm3)  $partes[] = notif_plural($nEm3, 'marco vence em 3 dias', 'marcos vencem em 3 dias');
  if ($nAtr)  $partes[] = notif_plural($nAtr, 'item em atraso', 'itens em atraso');
  $resumoJunto = !$admin && $resumos;

  if ($nPessoal > 0 || $resumoJunto) {
    $secoes = notif_render_pessoal($pac);
    $nItens = $nPessoal;
    if ($resumoJunto) {
      [$rnome, $g, $pes, $rt] = reset($resumos);
      $secoes .= notif_render_resumo($rnome, $g, $pes);
      $nItens += $rt;
      $assunto = "Resumo semanal de pendências: $rnome";
      $titulo  = 'Resumo semanal de pendências';
    } else {
      $assunto = 'OKR System: ' . implode(', ', $partes);
      $titulo  = 'Você tem pendências no OKR';
    }
    $saud = "Olá, {$u['primeiro_nome']}. "
      . ($partes ? 'Estes são os seus itens: ' . implode(', ', $partes) . '.' : 'Segue o resumo semanal das pendências da sua empresa.');
    $enviarEmail($uid, $email, 'pessoal', $assunto,
      notif_render_email($titulo, $saud, $secoes, $uid), $nItens);
  }

  /* resumos do admin: um e-mail por empresa */
  if ($admin) {
    foreach ($resumos as $rc => [$rnome, $g, $pes, $rt]) {
      $saud = "Olá, {$u['primeiro_nome']}. Segue o resumo semanal de $rnome: "
        . ($rt ? notif_plural($rt, 'pendência em atraso', 'pendências em atraso') . '.' : 'nenhuma pendência em atraso.');
      $enviarEmail($uid, $email, "resumo:$rc", "Resumo semanal de pendências: $rnome",
        notif_render_email('Resumo semanal de pendências', $saud, notif_render_resumo($rnome, $g, $pes), $uid), $rt);
    }
  }

  /* push + central de notificações: uma vez por dia */
  if ($semPush || ($nPessoal === 0 && !$resumos)) continue;
  if ($gravaLog && notif_ja_enviado($pdo, $uid, $hoje, 'push', 'dia')) { $tot['pulados']++; continue; }

  if ($nPessoal > 0) {
    $pTitulo = 'Pendências no OKR';
    $pCorpo  = ucfirst(implode(', ', $partes)) . '.';
    $route   = '/tarefas';
  } else {
    $totalR  = array_sum(array_column($resumos, 3));
    $pTitulo = 'Resumo semanal de pendências';
    $pCorpo  = count($resumos) > 1
      ? notif_plural($totalR, 'pendência', 'pendências') . ' em ' . count($resumos) . ' empresas. Detalhes no seu e-mail.'
      : notif_plural($totalR, 'pendência em atraso', 'pendências em atraso') . '. Detalhes no seu e-mail.';
    $route   = '/notificacoes';
  }
  echo "    > push: $pTitulo / $pCorpo\n";
  if ($dry) continue;

  notif_inbox($pdo, $uid, $pTitulo, $pCorpo, '/OKR_system/views/agenda.php',
    ['origem' => 'lembrete_okr', 'dia' => $hoje, 'hoje' => $nHoje, 'em3' => $nEm3, 'atrasados' => $nAtr]);
  $r = notif_push_usuario($pdo, $uid, $pTitulo, $pCorpo, $route);
  notif_registrar($pdo, $uid, $hoje, 'push', 'dia', $r['status'], $nPessoal, $r['detalhe']);
  echo "      push: {$r['status']} ({$r['detalhe']})\n";
  if ($r['status'] === 'enviado') $tot['push']++;
  if ($r['status'] === 'falha') $tot['falhas']++;
}

echo sprintf("[%s] fim: %d usuário(s), %d e-mail(s), %d push, %d já enviados, %d falha(s)\n",
  date('c'), $tot['usuarios'], $tot['emails'], $tot['push'], $tot['pulados'], $tot['falhas']);

flock($lock, LOCK_UN);
