<?php
declare(strict_types=1);

/**
 * Preferências dos avisos de pendência (tabela usuarios_notif_pref, migração 012).
 * Separado de notif_lembretes.php para a API de usuários não carregar a agenda
 * e o push só para ler e gravar quatro chaves.
 */

const NOTIF_PREF_CHAVES = ['lembrete_marco', 'lembrete_iniciativa', 'relatorio_atrasos', 'resumo_semanal'];

function notif_prefs_padrao(): array {
  return array_fill_keys(NOTIF_PREF_CHAVES, false);
}

/** @return array<int, array<string,bool>> preferências por id_user (sem linha = tudo false) */
function notif_prefs_carregar(PDO $pdo, array $ids): array {
  $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
  $out = [];
  foreach ($ids as $id) $out[$id] = notif_prefs_padrao();
  if (!$ids) return $out;
  $in = implode(',', array_fill(0, count($ids), '?'));
  $st = $pdo->prepare("SELECT * FROM usuarios_notif_pref WHERE id_user IN ($in)");
  $st->execute($ids);
  foreach ($st as $r) {
    $p = [];
    foreach (NOTIF_PREF_CHAVES as $k) $p[$k] = (int)$r[$k] === 1;
    $out[(int)$r['id_user']] = $p;
  }
  return $out;
}

/** Grava só as chaves informadas; as demais ficam como estavam. */
function notif_prefs_salvar(PDO $pdo, int $idUser, array $prefs, ?int $por): array {
  $atual = notif_prefs_carregar($pdo, [$idUser])[$idUser];
  foreach (NOTIF_PREF_CHAVES as $k) {
    if (array_key_exists($k, $prefs)) $atual[$k] = (bool)$prefs[$k];
  }
  $st = $pdo->prepare("
    INSERT INTO usuarios_notif_pref
      (id_user, lembrete_marco, lembrete_iniciativa, relatorio_atrasos, resumo_semanal, updated_by)
    VALUES (?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
      lembrete_marco = VALUES(lembrete_marco),
      lembrete_iniciativa = VALUES(lembrete_iniciativa),
      relatorio_atrasos = VALUES(relatorio_atrasos),
      resumo_semanal = VALUES(resumo_semanal),
      updated_by = VALUES(updated_by)
  ");
  $st->execute([
    $idUser,
    (int)$atual['lembrete_marco'], (int)$atual['lembrete_iniciativa'],
    (int)$atual['relatorio_atrasos'], (int)$atual['resumo_semanal'],
    $por,
  ]);
  return $atual;
}

/** Link de descadastro assinado: não exige login e não pode ser forjado para outro usuário. */
function notif_optout_token(int $idUser): string {
  return substr(hash_hmac('sha256', 'notif-optout|' . $idUser, APP_TOKEN_PEPPER), 0, 40);
}
