<?php
/**
 * Diagnóstico: simula delete_company_cascade passo a passo (DRY RUN + real)
 * Acesso: tools/diag_delete.php?token=HEALTH_CHECK_TOKEN&uid=15&mode=dry|real
 */
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/../auth/config.php';

$token = $_GET['token'] ?? '';
$expected = defined('HEALTH_CHECK_TOKEN') ? HEALTH_CHECK_TOKEN : (getenv('HEALTH_CHECK_TOKEN') ?: '');
if (!$expected || !hash_equals($expected, $token)) { http_response_code(403); die("Forbidden\n"); }

$uid  = (int)($_GET['uid'] ?? 0);
$mode = $_GET['mode'] ?? 'dry'; // dry = só diagnostica, real = executa de verdade

if ($uid <= 0) die("uid required\n");

try {
  $pdo = new PDO(
    'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
  );
} catch (Throwable $e) { die("DB connect fail: ".$e->getMessage()."\n"); }

// Buscar user
$st = $pdo->prepare("SELECT id_user, id_company, primeiro_nome, email_corporativo FROM usuarios WHERE id_user=?");
$st->execute([$uid]);
$user = $st->fetch();
if (!$user) die("User $uid not found\n");

$companyId = $user['id_company'] ? (int)$user['id_company'] : null;
echo "=== DIAGNOSTICO DELETE ===\n";
echo "User: #{$user['id_user']} {$user['primeiro_nome']} ({$user['email_corporativo']})\n";
echo "Company: " . ($companyId ?? 'NULL') . "\n";
echo "Mode: $mode\n\n";

// Count users in company
if ($companyId) {
  $st = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE id_company = ?");
  $st->execute([$companyId]);
  $cnt = (int)$st->fetchColumn();
  echo "Users in company $companyId: $cnt\n";
  $scenario = $cnt <= 1 ? 'solo' : 'reassign';
} else {
  $scenario = 'reassign';
}
echo "Scenario: $scenario\n\n";

function tableExists(PDO $pdo, string $t): bool {
  $s = $pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
  $s->execute([$t]); return (bool)$s->fetchColumn();
}
function colExists(PDO $pdo, string $t, string $c): bool {
  $s = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $s->execute([$t,$c]); return (bool)$s->fetchColumn();
}

// Solo scenario: simulate each step
if ($scenario === 'solo' && $companyId) {
  $steps = [];

  // Step 1-3: orcamentos chain
  $steps[] = ['label'=>'orcamentos_detalhes (via joins)', 'sql'=>"
    DELETE od FROM orcamentos_detalhes od
    JOIN orcamentos o ON o.id_orcamento = od.id_orcamento
    JOIN iniciativas i ON i.id_iniciativa = o.id_iniciativa
    JOIN key_results k ON k.id_kr = i.id_kr
    JOIN objetivos ob ON ob.id_objetivo = k.id_objetivo
    WHERE ob.id_company = ?
  ", 'params'=>[$companyId], 'check'=>'orcamentos_detalhes'];

  $steps[] = ['label'=>'orcamentos_envolvidos (via joins)', 'sql'=>"
    DELETE oe FROM orcamentos_envolvidos oe
    JOIN orcamentos o ON o.id_orcamento = oe.id_orcamento
    JOIN iniciativas i ON i.id_iniciativa = o.id_iniciativa
    JOIN key_results k ON k.id_kr = i.id_kr
    JOIN objetivos ob ON ob.id_objetivo = k.id_objetivo
    WHERE ob.id_company = ?
  ", 'params'=>[$companyId], 'check'=>'orcamentos_envolvidos'];

  $steps[] = ['label'=>'orcamentos (via joins)', 'sql'=>"
    DELETE o FROM orcamentos o
    JOIN iniciativas i ON i.id_iniciativa = o.id_iniciativa
    JOIN key_results k ON k.id_kr = i.id_kr
    JOIN objetivos ob ON ob.id_objetivo = k.id_objetivo
    WHERE ob.id_company = ?
  ", 'params'=>[$companyId], 'check'=>'orcamentos'];

  // Step 4
  $steps[] = ['label'=>'apontamentos_status_iniciativas', 'sql'=>"
    DELETE a FROM apontamentos_status_iniciativas a
    JOIN iniciativas i ON i.id_iniciativa = a.id_iniciativa
    JOIN key_results k ON k.id_kr = i.id_kr
    JOIN objetivos ob ON ob.id_objetivo = k.id_objetivo
    WHERE ob.id_company = ?
  ", 'params'=>[$companyId], 'check'=>'apontamentos_status_iniciativas'];

  // Step 5
  $steps[] = ['label'=>'iniciativas_envolvidos', 'sql'=>"
    DELETE ie FROM iniciativas_envolvidos ie
    JOIN iniciativas i ON i.id_iniciativa = ie.id_iniciativa
    JOIN key_results k ON k.id_kr = i.id_kr
    JOIN objetivos ob ON ob.id_objetivo = k.id_objetivo
    WHERE ob.id_company = ?
  ", 'params'=>[$companyId], 'check'=>'iniciativas_envolvidos'];

  // Step 6
  $steps[] = ['label'=>'iniciativas', 'sql'=>"
    DELETE i FROM iniciativas i
    JOIN key_results k ON k.id_kr = i.id_kr
    JOIN objetivos ob ON ob.id_objetivo = k.id_objetivo
    WHERE ob.id_company = ?
  ", 'params'=>[$companyId], 'check'=>'iniciativas'];

  // Step 7
  $steps[] = ['label'=>'apontamentos_kr', 'sql'=>"
    DELETE a FROM apontamentos_kr a
    JOIN key_results k ON k.id_kr = a.id_kr
    JOIN objetivos ob ON ob.id_objetivo = k.id_objetivo
    WHERE ob.id_company = ?
  ", 'params'=>[$companyId], 'check'=>'apontamentos_kr'];

  // Step 8
  $steps[] = ['label'=>'milestones_kr', 'sql'=>"
    DELETE m FROM milestones_kr m
    JOIN key_results k ON k.id_kr = m.id_kr
    JOIN objetivos ob ON ob.id_objetivo = k.id_objetivo
    WHERE ob.id_company = ?
  ", 'params'=>[$companyId], 'check'=>'milestones_kr'];

  // Step 9
  $steps[] = ['label'=>'okr_kr_envolvidos', 'sql'=>"
    DELETE ke FROM okr_kr_envolvidos ke
    JOIN key_results k ON k.id_kr = ke.id_kr
    JOIN objetivos ob ON ob.id_objetivo = k.id_objetivo
    WHERE ob.id_company = ?
  ", 'params'=>[$companyId], 'check'=>'okr_kr_envolvidos'];

  // Step 10: kr_comentarios
  foreach (['kr_comentarios', 'comentarios_kr'] as $tbl) {
    $steps[] = ['label'=>"$tbl", 'sql'=>"
      DELETE c FROM `$tbl` c
      JOIN key_results k ON k.id_kr = c.id_kr
      JOIN objetivos ob ON ob.id_objetivo = k.id_objetivo
      WHERE ob.id_company = ?
    ", 'params'=>[$companyId], 'check'=>$tbl, 'needCol'=>'id_kr'];
  }

  // Step 11: key_results
  $steps[] = ['label'=>'key_results', 'sql'=>"
    DELETE k FROM key_results k
    JOIN objetivos ob ON ob.id_objetivo = k.id_objetivo
    WHERE ob.id_company = ?
  ", 'params'=>[$companyId], 'check'=>'key_results'];

  // Step 12: objetivo_links (id_company or id_src/id_dst)
  if (tableExists($pdo, 'objetivo_links') && colExists($pdo, 'objetivo_links', 'id_company')) {
    $steps[] = ['label'=>'objetivo_links (by id_company)', 'sql'=>"DELETE FROM objetivo_links WHERE id_company = ?", 'params'=>[$companyId], 'check'=>'objetivo_links'];
  } elseif (tableExists($pdo, 'objetivo_links')) {
    $steps[] = ['label'=>'objetivo_links (by id_src)', 'sql'=>"
      DELETE ol FROM objetivo_links ol
      JOIN objetivos ob ON ob.id_objetivo = ol.id_src
      WHERE ob.id_company = ?
    ", 'params'=>[$companyId], 'check'=>'objetivo_links'];
    $steps[] = ['label'=>'objetivo_links (by id_dst)', 'sql'=>"
      DELETE ol FROM objetivo_links ol
      JOIN objetivos ob ON ob.id_objetivo = ol.id_dst
      WHERE ob.id_company = ?
    ", 'params'=>[$companyId], 'check'=>'objetivo_links'];
  }

  // Step 13: objetivos
  $steps[] = ['label'=>'objetivos', 'sql'=>"DELETE FROM objetivos WHERE id_company = ?", 'params'=>[$companyId], 'check'=>'objetivos'];

  // Step 14: dom tables
  $steps[] = ['label'=>'dom_cargos', 'sql'=>"DELETE FROM dom_cargos WHERE id_company = ?", 'params'=>[$companyId], 'check'=>'dom_cargos'];
  $steps[] = ['label'=>'dom_departamentos', 'sql'=>"DELETE FROM dom_departamentos WHERE id_company = ?", 'params'=>[$companyId], 'check'=>'dom_departamentos'];

  // Step 15: approval tables
  foreach (['fluxo_aprovacoes','aprovacao_movimentos','permissoes_aprovador','aprovadores'] as $tbl) {
    $steps[] = ['label'=>$tbl, 'sql'=>"DELETE FROM `$tbl` WHERE id_user = ?", 'params'=>[$uid], 'check'=>$tbl, 'needCol'=>'id_user'];
  }

  // Step 16: notificacoes, chat_conversas
  $steps[] = ['label'=>'notificacoes', 'sql'=>"DELETE FROM notificacoes WHERE id_user = ?", 'params'=>[$uid], 'check'=>'notificacoes'];
  $steps[] = ['label'=>'chat_conversas', 'sql'=>"DELETE FROM chat_conversas WHERE id_user = ?", 'params'=>[$uid], 'check'=>'chat_conversas'];

  // Step 17: company_style
  $steps[] = ['label'=>'company_style', 'sql'=>"DELETE FROM company_style WHERE id_company = ?", 'params'=>[$companyId], 'check'=>'company_style'];

  // Step 18: RBAC + legacy
  foreach (['rbac_user_capability','rbac_user_role','usuarios_permissoes','usuarios_paginas','usuarios_planos','usuarios_credenciais','usuarios_password_resets','usuarios_perfis'] as $tbl) {
    $col = in_array($tbl, ['rbac_user_capability','rbac_user_role']) ? 'user_id' :
           (in_array($tbl, ['usuarios_password_resets']) ? 'user_id' : 'id_user');
    $steps[] = ['label'=>$tbl, 'sql'=>"DELETE FROM `$tbl` WHERE `$col` = ?", 'params'=>[$uid], 'check'=>$tbl];
  }

  // Step 19: nullify RESTRICT FKs + delete user
  $setCols = [];
  foreach (['id_departamento','id_nivel_cargo','id_permissao'] as $c) {
    if (colExists($pdo, 'usuarios', $c)) $setCols[] = "`$c` = NULL";
  }
  if (colExists($pdo, 'usuarios', 'avatar_id')) $setCols[] = "`avatar_id` = 1";
  if ($setCols) {
    $steps[] = ['label'=>'usuarios unlink restrict FKs', 'sql'=>"UPDATE usuarios SET ".implode(', ',$setCols)." WHERE id_user = ?", 'params'=>[$uid], 'check'=>null];
  }
  $steps[] = ['label'=>'DELETE usuarios', 'sql'=>"DELETE FROM usuarios WHERE id_user = ?", 'params'=>[$uid], 'check'=>null];

  // Step 20: company
  $steps[] = ['label'=>'DELETE company', 'sql'=>"DELETE FROM company WHERE id_company = ?", 'params'=>[$companyId], 'check'=>null];

  // Execute steps
  if ($mode === 'real') {
    $pdo->beginTransaction();
  }

  $stepNum = 0;
  foreach ($steps as $step) {
    $stepNum++;
    $tbl = $step['check'] ?? null;
    $needCol = $step['needCol'] ?? null;

    // Skip if table doesn't exist
    if ($tbl && !tableExists($pdo, $tbl)) {
      echo "  [{$stepNum}] SKIP {$step['label']} — table not found\n";
      continue;
    }
    if ($needCol && $tbl && !colExists($pdo, $tbl, $needCol)) {
      echo "  [{$stepNum}] SKIP {$step['label']} — column '$needCol' not found\n";
      continue;
    }

    if ($mode === 'dry') {
      // DRY RUN: use EXPLAIN or just try in a savepoint
      $pdo->beginTransaction();
      try {
        $st = $pdo->prepare($step['sql']);
        $st->execute($step['params']);
        $affected = $st->rowCount();
        $pdo->rollBack();
        echo "  [{$stepNum}] OK    {$step['label']} — would affect $affected rows\n";
      } catch (Throwable $e) {
        $pdo->rollBack();
        echo "  [{$stepNum}] FAIL  {$step['label']} — {$e->getMessage()}\n";
      }
    } else {
      // REAL: execute inside the transaction
      try {
        $st = $pdo->prepare($step['sql']);
        $st->execute($step['params']);
        $affected = $st->rowCount();
        echo "  [{$stepNum}] DONE  {$step['label']} — $affected rows\n";
      } catch (Throwable $e) {
        echo "  [{$stepNum}] FAIL  {$step['label']} — {$e->getMessage()}\n";
        echo "\nROLLBACK!\n";
        $pdo->rollBack();
        die("Aborted at step $stepNum\n");
      }
    }
  }

  if ($mode === 'real') {
    $pdo->commit();
    echo "\nCOMMIT OK — user $uid and company $companyId deleted.\n";
  } else {
    echo "\nDRY RUN complete. No changes made.\n";
  }
} else {
  echo "Reassign scenario — use the UI for this.\n";
}
