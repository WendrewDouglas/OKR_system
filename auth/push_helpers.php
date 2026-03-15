<?php
/**
 * auth/push_helpers.php
 * Helpers para o modulo de Push Notifications.
 * Audiencia, envio FCM, espelho inbox, IA.
 */
declare(strict_types=1);

if (!defined('DB_HOST')) {
  require_once __DIR__ . '/config.php';
}

/* ===================== AUDIENCIA ===================== */

/**
 * Constroi query de audiencia com base em filtros JSON.
 * Retorna [sql, params] prontos para prepared statement.
 * O SELECT retorna DISTINCT id_user dos usuarios que atendem aos filtros.
 */
function push_build_audience_query(array $filters, PDO $pdo): array {
  $where = ['1=1'];
  $params = [];
  $joins = [];
  $joinSet = []; // evita joins duplicados

  $addJoin = function(string $key, string $sql) use (&$joins, &$joinSet) {
    if (!isset($joinSet[$key])) {
      $joins[] = $sql;
      $joinSet[$key] = true;
    }
  };

  // A) Perfil de usuario
  if (!empty($filters['id_user'])) {
    $where[] = 'u.id_user = :f_uid';
    $params[':f_uid'] = (int)$filters['id_user'];
  }
  if (!empty($filters['id_company'])) {
    $where[] = 'u.id_company = :f_cid';
    $params[':f_cid'] = (int)$filters['id_company'];
  }
  if (!empty($filters['id_departamento'])) {
    $where[] = 'u.id_departamento = :f_dep';
    $params[':f_dep'] = (int)$filters['id_departamento'];
  }
  if (!empty($filters['id_nivel_cargo'])) {
    $where[] = 'u.id_nivel_cargo = :f_nivel';
    $params[':f_nivel'] = (int)$filters['id_nivel_cargo'];
  }
  if (!empty($filters['dt_cadastro_desde'])) {
    $where[] = 'u.dt_cadastro >= :f_dt_desde';
    $params[':f_dt_desde'] = $filters['dt_cadastro_desde'];
  }
  if (!empty($filters['dt_cadastro_ate'])) {
    $where[] = 'u.dt_cadastro <= :f_dt_ate';
    $params[':f_dt_ate'] = $filters['dt_cadastro_ate'];
  }
  if (isset($filters['com_telefone'])) {
    $where[] = $filters['com_telefone'] ? "u.telefone IS NOT NULL AND u.telefone != ''" : "(u.telefone IS NULL OR u.telefone = '')";
  }
  if (isset($filters['com_avatar'])) {
    $where[] = $filters['com_avatar'] ? 'u.avatar_id > 1' : 'u.avatar_id <= 1';
  }

  // B) Relacao com objetivos
  if (!empty($filters['obj_status']) || !empty($filters['obj_tipo_ciclo']) || !empty($filters['obj_pilar_bsc'])
      || !empty($filters['obj_status_aprovacao']) || !empty($filters['obj_qualidade'])
      || !empty($filters['obj_prazo_vencido']) || !empty($filters['obj_role'])) {
    $objRole = $filters['obj_role'] ?? 'dono'; // dono|criador|aprovador
    $colMap = ['dono'=>'COALESCE(o.id_user_dono, o.id_user_criador)', 'criador'=>'o.id_user_criador', 'aprovador'=>'o.id_user_aprovador'];
    $col = $colMap[$objRole] ?? $colMap['dono'];
    $addJoin('obj', "JOIN objetivos o ON $col = u.id_user");
    if (!empty($filters['obj_status'])) { $where[] = 'o.status = :f_os'; $params[':f_os'] = $filters['obj_status']; }
    if (!empty($filters['obj_tipo_ciclo'])) { $where[] = 'o.tipo_ciclo = :f_otc'; $params[':f_otc'] = $filters['obj_tipo_ciclo']; }
    if (!empty($filters['obj_pilar_bsc'])) { $where[] = 'o.pilar_bsc = :f_opb'; $params[':f_opb'] = $filters['obj_pilar_bsc']; }
    if (!empty($filters['obj_status_aprovacao'])) { $where[] = 'o.status_aprovacao = :f_osa'; $params[':f_osa'] = $filters['obj_status_aprovacao']; }
    if (!empty($filters['obj_qualidade'])) { $where[] = 'o.qualidade = :f_oq'; $params[':f_oq'] = $filters['obj_qualidade']; }
    if (!empty($filters['obj_prazo_vencido'])) { $where[] = 'o.dt_prazo < CURDATE() AND o.status != \'Concluído\''; }
  }

  // C) Relacao com KRs
  if (!empty($filters['kr_status']) || !empty($filters['kr_natureza_kr']) || !empty($filters['kr_farol'])
      || !empty($filters['kr_qualidade']) || !empty($filters['kr_status_aprovacao'])
      || !empty($filters['kr_prazo_vencido']) || !empty($filters['kr_role'])) {
    $krRole = $filters['kr_role'] ?? 'responsavel'; // responsavel|criador|envolvido
    if ($krRole === 'envolvido') {
      $addJoin('kr_env', 'JOIN okr_kr_envolvidos oke ON oke.id_user = u.id_user');
      $addJoin('kr', 'JOIN key_results k ON k.id_kr = oke.id_kr');
    } else {
      $col = $krRole === 'criador' ? 'k.id_user_criador' : 'COALESCE(k.id_user_responsavel, k.id_user_criador)';
      $addJoin('kr', "JOIN key_results k ON $col = u.id_user");
    }
    if (!empty($filters['kr_status'])) { $where[] = 'k.status = :f_ks'; $params[':f_ks'] = $filters['kr_status']; }
    if (!empty($filters['kr_natureza_kr'])) { $where[] = 'k.natureza_kr = :f_kn'; $params[':f_kn'] = $filters['kr_natureza_kr']; }
    if (!empty($filters['kr_farol'])) { $where[] = 'k.farol = :f_kf'; $params[':f_kf'] = $filters['kr_farol']; }
    if (!empty($filters['kr_qualidade'])) { $where[] = 'k.qualidade = :f_kq'; $params[':f_kq'] = $filters['kr_qualidade']; }
    if (!empty($filters['kr_status_aprovacao'])) { $where[] = 'k.status_aprovacao = :f_ksa'; $params[':f_ksa'] = $filters['kr_status_aprovacao']; }
    if (!empty($filters['kr_prazo_vencido'])) { $where[] = 'k.data_fim < CURDATE() AND k.status != \'Concluído\''; }
  }

  // D) Relacao com iniciativas
  if (!empty($filters['ini_status']) || !empty($filters['ini_status_aprovacao'])
      || !empty($filters['ini_prazo_vencido']) || !empty($filters['ini_role'])) {
    $iniRole = $filters['ini_role'] ?? 'responsavel';
    if ($iniRole === 'envolvido') {
      $addJoin('ini_env', 'JOIN iniciativas_envolvidos ie ON ie.id_user = u.id_user');
      $addJoin('ini', 'JOIN iniciativas ini ON ini.id_iniciativa = ie.id_iniciativa');
    } else {
      $col = $iniRole === 'criador' ? 'ini.id_user_criador' : 'COALESCE(ini.id_user_responsavel, ini.id_user_criador)';
      $addJoin('ini', "JOIN iniciativas ini ON $col = u.id_user");
    }
    if (!empty($filters['ini_status'])) { $where[] = 'ini.status = :f_is'; $params[':f_is'] = $filters['ini_status']; }
    if (!empty($filters['ini_status_aprovacao'])) { $where[] = 'ini.status_aprovacao = :f_isa'; $params[':f_isa'] = $filters['ini_status_aprovacao']; }
    if (!empty($filters['ini_prazo_vencido'])) { $where[] = 'ini.dt_prazo < CURDATE() AND ini.status != \'Concluído\''; }
  }

  // E) Relacao com orcamento
  if (!empty($filters['orc_status_aprovacao']) || !empty($filters['orc_status_financeiro'])) {
    $addJoin('orc', 'JOIN orcamentos orc ON orc.id_user_criador = u.id_user');
    if (!empty($filters['orc_status_aprovacao'])) { $where[] = 'orc.status_aprovacao = :f_orsa'; $params[':f_orsa'] = $filters['orc_status_aprovacao']; }
    if (!empty($filters['orc_status_financeiro'])) { $where[] = 'orc.status_financeiro = :f_orsf'; $params[':f_orsf'] = $filters['orc_status_financeiro']; }
  }

  // F) Relacao com aprovacoes pendentes
  if (!empty($filters['aprov_pendente'])) {
    $addJoin('aprov', "JOIN fluxo_aprovacoes fa ON (fa.id_user_solicitante = u.id_user OR fa.id_user_aprovador = u.id_user) AND fa.status = 'pendente'");
  }

  // G) Filtros tecnicos do app (push_devices)
  if (!empty($filters['device_platform']) || isset($filters['device_token_ativo'])
      || !empty($filters['device_app_version']) || !empty($filters['device_locale'])
      || isset($filters['device_push_enabled'])) {
    $addJoin('dev', 'JOIN push_devices pd ON pd.id_user = u.id_user AND pd.is_active = 1');
    if (!empty($filters['device_platform'])) { $where[] = 'pd.platform = :f_dp'; $params[':f_dp'] = $filters['device_platform']; }
    if (isset($filters['device_push_enabled'])) { $where[] = 'pd.notifications_enabled = :f_dpe'; $params[':f_dpe'] = $filters['device_push_enabled'] ? 1 : 0; }
    if (!empty($filters['device_app_version'])) { $where[] = 'pd.app_version = :f_dav'; $params[':f_dav'] = $filters['device_app_version']; }
    if (!empty($filters['device_locale'])) { $where[] = 'pd.locale = :f_dl'; $params[':f_dl'] = $filters['device_locale']; }
  }

  $joinSql = implode("\n", $joins);
  $whereSql = implode(' AND ', $where);

  $sql = "SELECT DISTINCT u.id_user FROM usuarios u\n{$joinSql}\nWHERE {$whereSql}";
  return [$sql, $params];
}

/**
 * Conta usuarios que atendem aos filtros.
 */
function push_count_audience(array $filters, PDO $pdo): int {
  [$innerSql, $params] = push_build_audience_query($filters, $pdo);
  $st = $pdo->prepare("SELECT COUNT(*) FROM ({$innerSql}) AS aud");
  $st->execute($params);
  return (int)$st->fetchColumn();
}

/**
 * Lista usuarios que atendem aos filtros (com dados basicos).
 */
function push_list_audience(array $filters, PDO $pdo, int $limit = 200, int $offset = 0): array {
  [$innerSql, $params] = push_build_audience_query($filters, $pdo);
  $sql = "SELECT u2.id_user, u2.primeiro_nome, u2.ultimo_nome, u2.email_corporativo, u2.id_company,
                 c.organizacao AS company_name
            FROM ({$innerSql}) AS aud
            JOIN usuarios u2 ON u2.id_user = aud.id_user
            LEFT JOIN company c ON c.id_company = u2.id_company
           ORDER BY u2.primeiro_nome
           LIMIT {$limit} OFFSET {$offset}";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

/* ===================== ENVIO FCM ===================== */

/**
 * Envia push via Firebase Cloud Messaging (HTTP v1 ou Legacy).
 * Retorna ['success'=>bool, 'message_id'=>string|null, 'error'=>string|null]
 */
function push_send_fcm(string $token, array $payload): array {
  $serverKey = (string)env('FCM_SERVER_KEY', '');
  if (!$serverKey) {
    return ['success' => false, 'message_id' => null, 'error' => 'FCM_SERVER_KEY not configured'];
  }

  $message = [
    'to' => $token,
    'notification' => [
      'title' => $payload['title'] ?? '',
      'body'  => $payload['body'] ?? '',
    ],
    'data' => $payload['data'] ?? [],
  ];

  if (!empty($payload['image'])) {
    $message['notification']['image'] = $payload['image'];
  }

  if (($payload['priority'] ?? 'normal') === 'high') {
    $message['priority'] = 'high';
  }

  $ch = curl_init('https://fcm.googleapis.com/fcm/send');
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
      'Content-Type: application/json',
      'Authorization: key=' . $serverKey,
    ],
    CURLOPT_POSTFIELDS => json_encode($message, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT    => 15,
  ]);
  $resp = curl_exec($ch);
  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $curlErr = curl_error($ch);
  curl_close($ch);

  if ($resp === false) {
    return ['success' => false, 'message_id' => null, 'error' => 'cURL error: ' . $curlErr];
  }

  $data = json_decode($resp, true);
  if ($httpCode >= 200 && $httpCode < 300 && ($data['success'] ?? 0) >= 1) {
    $msgId = $data['results'][0]['message_id'] ?? null;
    return ['success' => true, 'message_id' => $msgId, 'error' => null];
  }

  $errMsg = $data['results'][0]['error'] ?? ($data['error'] ?? "HTTP {$httpCode}");
  return ['success' => false, 'message_id' => null, 'error' => $errMsg];
}

/* ===================== ESPELHO INBOX ===================== */

/**
 * Grava notificacao na tabela notificacoes (inbox interno).
 */
function push_mirror_to_inbox(PDO $pdo, int $idUser, array $campaign): void {
  $st = $pdo->prepare("
    INSERT INTO notificacoes (id_user, tipo, titulo, mensagem, url, lida, dt_criado, meta_json)
    VALUES (:uid, 'push', :titulo, :msg, :url, 0, NOW(), :meta)
  ");
  $st->execute([
    ':uid'    => $idUser,
    ':titulo' => mb_substr($campaign['titulo'], 0, 180),
    ':msg'    => mb_substr($campaign['descricao'], 0, 5000),
    ':url'    => $campaign['route'] ?: $campaign['url_web'] ?: null,
    ':meta'   => json_encode([
      'campaign_id' => $campaign['id_campaign'],
      'category'    => $campaign['categoria'],
    ], JSON_UNESCAPED_UNICODE),
  ]);
}

/* ===================== PROCESSAMENTO DE CAMPANHA ===================== */

/**
 * Processa uma campanha: snapshot audiencia, envia push, espelha inbox.
 * Retorna stats array.
 */
function push_process_campaign(PDO $pdo, int $campaignId): array {
  $camp = $pdo->prepare("SELECT * FROM push_campaigns WHERE id_campaign = ? AND status IN ('scheduled','sending')");
  $camp->execute([$campaignId]);
  $campaign = $camp->fetch(PDO::FETCH_ASSOC);
  if (!$campaign) return ['error' => 'Campaign not found or not processable'];

  // Marca como sending
  $pdo->prepare("UPDATE push_campaigns SET status='sending', updated_at=NOW() WHERE id_campaign=?")->execute([$campaignId]);

  // Cria run
  $pdo->prepare("INSERT INTO push_campaign_runs (id_campaign, run_type, status, started_at) VALUES (?, ?, 'running', NOW())")
    ->execute([$campaignId, $campaign['is_recurring'] ? 'recurring' : ($campaign['scheduled_at'] ? 'scheduled' : 'immediate')]);
  $runId = (int)$pdo->lastInsertId();

  // Snapshot audiencia
  $filters = json_decode($campaign['filters_json'] ?: '{}', true) ?: [];
  [$audSql, $audParams] = push_build_audience_query($filters, $pdo);

  // Insere recipients com devices ativos
  $insertSql = "INSERT IGNORE INTO push_campaign_recipients (id_campaign, id_user, id_device, id_company, status_envio)
    SELECT :cid, pd.id_user, pd.id_device, pd.id_company, 'pending'
      FROM push_devices pd
      JOIN ({$audSql}) aud ON aud.id_user = pd.id_user
     WHERE pd.is_active = 1 AND pd.notifications_enabled = 1";
  $audParams[':cid'] = $campaignId;
  $pdo->prepare($insertSql)->execute($audParams);

  // Stats
  $totalTarget = (int)$pdo->query("SELECT COUNT(*) FROM push_campaign_recipients WHERE id_campaign={$campaignId}")->fetchColumn();
  $pdo->prepare("UPDATE push_campaign_runs SET total_target=? WHERE id_run=?")->execute([$totalTarget, $runId]);

  // Prepara payload
  $imageUrl = null;
  if ($campaign['image_asset_id']) {
    $ast = $pdo->prepare("SELECT public_url FROM push_assets WHERE id_asset=?");
    $ast->execute([$campaign['image_asset_id']]);
    $imageUrl = $ast->fetchColumn() ?: null;
  }
  $payload = [
    'title'    => $campaign['titulo'],
    'body'     => $campaign['descricao'],
    'image'    => $imageUrl,
    'priority' => $campaign['priority'] ?? 'normal',
    'data'     => [
      'campaign_id' => (string)$campaignId,
      'route'       => $campaign['route'] ?? '',
      'category'    => $campaign['categoria'] ?? '',
      'url_web'     => $campaign['url_web'] ?? '',
    ],
  ];

  // Processa em lotes de 100
  $canal = $campaign['canal'];
  $sent = 0; $failed = 0;

  $batch = $pdo->prepare("SELECT r.id_recipient, r.id_user, r.id_device, pd.token
    FROM push_campaign_recipients r
    JOIN push_devices pd ON pd.id_device = r.id_device
    WHERE r.id_campaign = ? AND r.status_envio = 'pending'
    LIMIT 100");

  $updRecip = $pdo->prepare("UPDATE push_campaign_recipients SET status_envio=?, provider_message_id=?, error_code=?, error_message=?, sent_at=NOW() WHERE id_recipient=?");

  do {
    $batch->execute([$campaignId]);
    $rows = $batch->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) break;

    foreach ($rows as $row) {
      // Push
      if ($canal === 'push' || $canal === 'push_inbox') {
        $result = push_send_fcm($row['token'], $payload);
        if ($result['success']) {
          $updRecip->execute(['sent', $result['message_id'], null, null, $row['id_recipient']]);
          $sent++;
        } else {
          $errCode = substr($result['error'] ?? 'unknown', 0, 60);
          $updRecip->execute(['failed', null, $errCode, $result['error'], $row['id_recipient']]);
          $failed++;
          // Desativa token invalido
          if (in_array($errCode, ['NotRegistered', 'InvalidRegistration'])) {
            $pdo->prepare("UPDATE push_devices SET is_active=0, updated_at=NOW() WHERE id_device=?")->execute([$row['id_device']]);
          }
        }
      } else {
        // Somente inbox: marca como sent
        $updRecip->execute(['sent', null, null, null, $row['id_recipient']]);
        $sent++;
      }

      // Inbox mirror
      if ($canal === 'inbox' || $canal === 'push_inbox') {
        push_mirror_to_inbox($pdo, (int)$row['id_user'], $campaign);
      }
    }
  } while (count($rows) === 100);

  // Finaliza
  $pdo->prepare("UPDATE push_campaigns SET status='sent', sent_at=NOW(), updated_at=NOW() WHERE id_campaign=?")->execute([$campaignId]);
  $pdo->prepare("UPDATE push_campaign_runs SET status='completed', total_sent=?, total_failed=?, finished_at=NOW() WHERE id_run=?")
    ->execute([$sent, $failed, $runId]);

  // Recorrencia: agendar proxima
  if ($campaign['is_recurring'] && $campaign['recurrence_rule']) {
    $next = push_calc_next_recurrence($campaign['recurrence_rule'], $campaign['scheduled_at'] ?: date('Y-m-d H:i:s'));
    if ($next) {
      $pdo->prepare("UPDATE push_campaigns SET status='scheduled', scheduled_at=?, next_recurrence_at=?, sent_at=NULL, updated_at=NOW() WHERE id_campaign=?")
        ->execute([$next, $next, $campaignId]);
      // Limpa recipients para proxima execucao
      $pdo->prepare("DELETE FROM push_campaign_recipients WHERE id_campaign=?")->execute([$campaignId]);
    }
  }

  return ['total_target' => $totalTarget, 'sent' => $sent, 'failed' => $failed, 'run_id' => $runId];
}

/**
 * Calcula proxima data de recorrencia.
 * Formato: weekly:mon,wed | monthly:15 | daily
 */
function push_calc_next_recurrence(string $rule, string $fromDate): ?string {
  $parts = explode(':', $rule, 2);
  $type = strtolower(trim($parts[0]));
  $param = trim($parts[1] ?? '');
  $from = new DateTime($fromDate);

  switch ($type) {
    case 'daily':
      $from->modify('+1 day');
      return $from->format('Y-m-d H:i:s');
    case 'weekly':
      $from->modify('+7 days');
      return $from->format('Y-m-d H:i:s');
    case 'monthly':
      $day = (int)$param ?: (int)$from->format('d');
      $from->modify('+1 month');
      $from->setDate((int)$from->format('Y'), (int)$from->format('m'), min($day, (int)$from->format('t')));
      return $from->format('Y-m-d H:i:s');
    default:
      return null;
  }
}

/* ===================== IA SUGESTOES ===================== */

/**
 * Gera sugestoes de titulo/descricao via OpenAI.
 */
function push_ai_suggest(string $prompt, array $context = []): array {
  $apiKey = (string)env('OPENAI_API_KEY', '');
  if (!$apiKey) return ['error' => 'OPENAI_API_KEY not configured'];

  $systemPrompt = "Voce e um especialista em push notifications para apps corporativos de gestao de OKRs (PlanningBI).
Gere sugestoes curtas e objetivas para notificacoes push.
Regras:
- Titulo: maximo 50 caracteres, direto, use emojis relevantes no inicio para chamar atencao
- Descricao: maximo 120 caracteres, clara, objetiva, pode incluir emojis contextuais
- Gere exatamente 4 opcoes diferentes com estilos variados (mais formal, mais casual, mais urgente, mais motivacional)
- Retorne SOMENTE um JSON array com objetos {\"titulo\":\"...\",\"descricao\":\"...\"}
- Use emojis que fiquem bonitos em push notification (ex: 🎯 🚀 ⚡ 📊 🔔 ✅ 📈 💡 🏆 ⏰ 📋)
- Considere o contexto fornecido
- O app e de gestao de OKRs (Objectives and Key Results) da marca PlanningBI";

  $userMsg = $prompt;
  if (!empty($context['categoria'])) $userMsg .= "\nCategoria: " . $context['categoria'];
  if (!empty($context['audiencia'])) $userMsg .= "\nPublico: " . $context['audiencia'];
  if (!empty($context['tom'])) $userMsg .= "\nTom desejado: " . $context['tom'];
  if (!empty($context['urgencia'])) $userMsg .= "\nUrgencia: " . $context['urgencia'];

  $ch = curl_init('https://api.openai.com/v1/chat/completions');
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
    CURLOPT_POSTFIELDS     => json_encode([
      'model'       => 'gpt-4o-mini',
      'messages'    => [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $userMsg],
      ],
      'max_tokens'  => 600,
      'temperature' => 0.7,
    ], JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT => 20,
  ]);
  $resp = curl_exec($ch);
  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($resp === false || $httpCode < 200 || $httpCode >= 300) {
    return ['error' => 'OpenAI request failed (HTTP ' . $httpCode . ')'];
  }

  $data = json_decode($resp, true);
  $content = $data['choices'][0]['message']['content'] ?? '';

  // Extrai JSON do conteudo (pode vir envolto em markdown)
  if (preg_match('/\[[\s\S]*\]/', $content, $m)) {
    $suggestions = json_decode($m[0], true);
  } else {
    $suggestions = json_decode($content, true);
  }

  return [
    'suggestions' => is_array($suggestions) ? $suggestions : [],
    'raw'         => $content,
    'tokens'      => $data['usage']['total_tokens'] ?? 0,
  ];
}
