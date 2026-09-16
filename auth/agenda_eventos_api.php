<?php
// auth/agenda_eventos_api.php — CRUD dos eventos da empresa na Agenda.
// Quem cria/edita/exclui: admin_master (bypass do has_cap) e gestor_master da
// empresa, via capability M:company@ORG — a mesma de quem edita a organização.
// Leitura da agenda é de todo mundo e não passa por aqui.
declare(strict_types=1);
date_default_timezone_set('America/Sao_Paulo');

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/acl.php';

function agev_exit(int $code, array $payload): void {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

if (empty($_SESSION['user_id'])) agev_exit(401, ['success' => false, 'error' => 'Não autenticado.']);
$MEU_ID = (int)$_SESSION['user_id'];

try {
  $pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
  );
} catch (PDOException $e) {
  error_log('agenda_eventos_api conexão: ' . $e->getMessage());
  agev_exit(500, ['success' => false, 'error' => 'Falha ao conectar.']);
}

// Empresa da sessão (a agenda é sempre da própria empresa)
$companyId = (int)($_SESSION['id_company'] ?? $_SESSION['company_id'] ?? 0);
if ($companyId <= 0) {
  $st = $pdo->prepare('SELECT id_company FROM usuarios WHERE id_user = ? LIMIT 1');
  $st->execute([$MEU_ID]);
  $companyId = (int)$st->fetchColumn();
}
if ($companyId <= 0) agev_exit(422, ['success' => false, 'error' => 'Usuário sem empresa.']);

// has_cap já dá bypass para admin_master. Não usamos require_cap porque ele
// responde HTML (modal) quando o Accept não é JSON, e o fetch desta tela manda */*.
if (!has_cap('M:company@ORG')) {
  agev_exit(403, ['success' => false, 'error' => 'Sem permissão para gerenciar eventos da agenda.']);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $csrf = (string)($_POST['csrf_token'] ?? '');
  if ($csrf === '' || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $csrf)) {
    agev_exit(403, ['success' => false, 'error' => 'Token CSRF inválido.']);
  }
}

/* ---------- helpers de validação ---------- */

$soData = static function (?string $v): ?string {
  $v = trim((string)$v);
  return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
};
$soHora = static function (?string $v): ?string {
  $v = trim((string)$v);
  if ($v === '') return null;
  return preg_match('/^\d{2}:\d{2}$/', $v) ? $v . ':00' : null;
};
$diasSemana = static function (?string $v): ?string {
  $ds = array_values(array_unique(array_filter(
    array_map('intval', explode(',', (string)$v)),
    static fn($d) => $d >= 0 && $d <= 6
  )));
  sort($ds);
  return $ds ? implode(',', $ds) : null;
};

/** O evento pertence à empresa da sessão? admin_master enxerga todas. */
$doMeuCompany = static function (PDO $pdo, int $idEvento, int $companyId): bool {
  $st = $pdo->prepare('SELECT id_company FROM agenda_eventos WHERE id_evento = ?');
  $st->execute([$idEvento]);
  $c = $st->fetchColumn();
  if ($c === false) return false;
  return has_cap('M:company@SYS') || (int)$c === $companyId;
};

$acao = strtolower(trim((string)($_POST['acao'] ?? $_GET['acao'] ?? 'listar')));

/* ---------- listar (para a tela de gestão) ---------- */
if ($acao === 'listar') {
  $st = $pdo->prepare("
    SELECT e.*, (SELECT COUNT(*) FROM agenda_evento_pessoas p WHERE p.id_evento = e.id_evento) AS n_pessoas
      FROM agenda_eventos e
     WHERE e.id_company = :cid AND e.ativo = 1
     ORDER BY e.data_inicio DESC, e.id_evento DESC
  ");
  $st->execute([':cid' => $companyId]);
  agev_exit(200, ['success' => true, 'eventos' => $st->fetchAll()]);
}

/* ---------- criar / atualizar ---------- */
if ($acao === 'salvar') {
  $id       = (int)($_POST['id_evento'] ?? 0);
  $titulo   = trim((string)($_POST['titulo'] ?? ''));
  $descricao= trim((string)($_POST['descricao'] ?? ''));
  $local    = trim((string)($_POST['local'] ?? ''));
  $dataIni  = $soData($_POST['data_inicio'] ?? '');
  $horaIni  = $soHora($_POST['hora_inicio'] ?? '');
  $horaFim  = $soHora($_POST['hora_fim'] ?? '');
  $rec      = strtolower(trim((string)($_POST['recorrencia'] ?? 'nenhuma')));
  $dias     = $diasSemana($_POST['dias_semana'] ?? '');
  $regraMes = in_array(($_POST['regra_mensal'] ?? ''), ['dia', 'semana'], true) ? $_POST['regra_mensal'] : 'dia';
  $dataFim  = $soData($_POST['data_fim'] ?? '');
  $pessoas  = json_decode((string)($_POST['pessoas_json'] ?? '[]'), true);
  $organiza = (int)($_POST['id_organizador'] ?? $MEU_ID);

  if ($titulo === '')  agev_exit(422, ['success' => false, 'error' => 'Informe o título do evento.']);
  if (!$dataIni)       agev_exit(422, ['success' => false, 'error' => 'Informe a data do evento.']);
  if (!in_array($rec, ['nenhuma', 'semanal', 'quinzenal', 'mensal'], true)) {
    agev_exit(422, ['success' => false, 'error' => 'Recorrência inválida.']);
  }
  if ($horaIni && $horaFim && $horaFim < $horaIni) {
    agev_exit(422, ['success' => false, 'error' => 'A hora de término é anterior à de início.']);
  }
  if ($dataFim && $dataFim < $dataIni) {
    agev_exit(422, ['success' => false, 'error' => 'O fim da recorrência é anterior à data inicial.']);
  }
  if ($rec === 'nenhuma') { $dias = null; $dataFim = null; }

  try {
    $pdo->beginTransaction();

    if ($id > 0) {
      if (!$doMeuCompany($pdo, $id, $companyId)) {
        $pdo->rollBack();
        agev_exit(403, ['success' => false, 'error' => 'Evento de outra empresa.']);
      }
      $pdo->prepare("
        UPDATE agenda_eventos
           SET titulo = :t, descricao = :d, `local` = :l, data_inicio = :di, hora_inicio = :hi,
               hora_fim = :hf, recorrencia = :r, dias_semana = :ds, regra_mensal = :rm, data_fim = :df
         WHERE id_evento = :id
      ")->execute([
        ':t' => $titulo, ':d' => ($descricao !== '' ? $descricao : null), ':l' => ($local !== '' ? $local : null),
        ':di' => $dataIni, ':hi' => $horaIni, ':hf' => $horaFim, ':r' => $rec,
        ':ds' => $dias, ':rm' => ($rec === 'mensal' ? $regraMes : null), ':df' => $dataFim, ':id' => $id,
      ]);
    } else {
      $pdo->prepare("
        INSERT INTO agenda_eventos (id_company, titulo, descricao, `local`, data_inicio, hora_inicio, hora_fim,
                                    recorrencia, dias_semana, regra_mensal, data_fim, id_user_criador)
        VALUES (:cid, :t, :d, :l, :di, :hi, :hf, :r, :ds, :rm, :df, :uid)
      ")->execute([
        ':cid' => $companyId, ':t' => $titulo, ':d' => ($descricao !== '' ? $descricao : null),
        ':l' => ($local !== '' ? $local : null), ':di' => $dataIni, ':hi' => $horaIni, ':hf' => $horaFim,
        ':r' => $rec, ':ds' => $dias, ':rm' => ($rec === 'mensal' ? $regraMes : null),
        ':df' => $dataFim, ':uid' => $MEU_ID,
      ]);
      $id = (int)$pdo->lastInsertId();
    }

    // Participantes: o organizador entra sempre; os demais são da mesma empresa.
    $pdo->prepare('DELETE FROM agenda_evento_pessoas WHERE id_evento = ?')->execute([$id]);
    $ins = $pdo->prepare('INSERT IGNORE INTO agenda_evento_pessoas (id_evento, id_user, papel) VALUES (?, ?, ?)');
    $ins->execute([$id, $organiza, 'organizador']);

    $lista = is_array($pessoas) ? array_unique(array_map('intval', $pessoas)) : [];
    if ($lista) {
      $inP = implode(',', array_fill(0, count($lista), '?'));
      $stV = $pdo->prepare("SELECT id_user FROM usuarios WHERE id_user IN ($inP) AND id_company = ?");
      $stV->execute([...$lista, $companyId]);
      foreach ($stV->fetchAll(PDO::FETCH_COLUMN) as $uid) {
        if ((int)$uid === $organiza) continue;
        $ins->execute([$id, (int)$uid, 'participante']);
      }
    }

    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('agenda_eventos_api salvar: ' . $e->getMessage());
    agev_exit(500, ['success' => false, 'error' => 'Falha ao salvar o evento.']);
  }

  agev_exit(200, ['success' => true, 'id_evento' => $id]);
}

/* ---------- excluir a série inteira (baixa lógica) ---------- */
if ($acao === 'excluir') {
  $id = (int)($_POST['id_evento'] ?? 0);
  if ($id <= 0 || !$doMeuCompany($pdo, $id, $companyId)) {
    agev_exit(403, ['success' => false, 'error' => 'Evento inválido.']);
  }
  $pdo->prepare('UPDATE agenda_eventos SET ativo = 0 WHERE id_evento = ?')->execute([$id]);
  agev_exit(200, ['success' => true]);
}

/* ---------- cancelar ou remarcar UMA ocorrência ---------- */
if ($acao === 'ocorrencia') {
  $id      = (int)($_POST['id_evento'] ?? 0);
  $dataRef = $soData($_POST['data_ref'] ?? '');
  $tipo    = strtolower(trim((string)($_POST['tipo'] ?? '')));   // cancelar | remarcar | restaurar
  $novaD   = $soData($_POST['nova_data'] ?? '');
  $novaH   = $soHora($_POST['nova_hora'] ?? '');
  $motivo  = trim((string)($_POST['motivo'] ?? ''));

  if ($id <= 0 || !$dataRef || !$doMeuCompany($pdo, $id, $companyId)) {
    agev_exit(422, ['success' => false, 'error' => 'Ocorrência inválida.']);
  }

  if ($tipo === 'restaurar') {
    $pdo->prepare('DELETE FROM agenda_evento_excecoes WHERE id_evento = ? AND data_ref = ?')->execute([$id, $dataRef]);
    agev_exit(200, ['success' => true]);
  }
  if (!in_array($tipo, ['cancelar', 'remarcar'], true)) {
    agev_exit(422, ['success' => false, 'error' => 'Ação inválida para a ocorrência.']);
  }
  if ($tipo === 'remarcar' && !$novaD && !$novaH) {
    agev_exit(422, ['success' => false, 'error' => 'Informe a nova data ou a nova hora.']);
  }

  $pdo->prepare("
    INSERT INTO agenda_evento_excecoes (id_evento, data_ref, acao, nova_data, nova_hora, motivo, id_user)
    VALUES (:id, :dr, :ac, :nd, :nh, :mo, :uid)
    ON DUPLICATE KEY UPDATE acao = VALUES(acao), nova_data = VALUES(nova_data),
                            nova_hora = VALUES(nova_hora), motivo = VALUES(motivo), id_user = VALUES(id_user)
  ")->execute([
    ':id' => $id, ':dr' => $dataRef, ':ac' => ($tipo === 'cancelar' ? 'cancelada' : 'remarcada'),
    ':nd' => $novaD, ':nh' => $novaH, ':mo' => ($motivo !== '' ? $motivo : null), ':uid' => $MEU_ID,
  ]);
  agev_exit(200, ['success' => true]);
}

agev_exit(400, ['success' => false, 'error' => 'Ação desconhecida.']);
