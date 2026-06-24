<?php
declare(strict_types=1);

/**
 * Regras de status de Key Result — compartilhado web + API.
 *
 * Status (dom_status_kr): nao iniciado | em andamento | pausado | concluido | cancelado.
 * - "nao iniciado" e "cancelado" NÃO entram na média (ver auth/helpers/kr_progress.php).
 * - "nao iniciado" só é permitido se o 1º check-in (1º milestone) ainda não chegou.
 * - Auto-promoção: KR "nao iniciado" com 1º milestone <= hoje vira "em andamento".
 * - Justificativa obrigatória para: cancelar, pausar, concluir.
 */

if (!function_exists('krs_normalize_status')) {
  /** Mapeia rótulos/variações → id_status canônico. */
  function krs_normalize_status(?string $raw): string {
    $s = strtolower(trim((string)$raw));
    $s = str_replace(['_', '-'], ' ', $s);
    $s = strtr($s, [
      'á' => 'a', 'ã' => 'a', 'â' => 'a', 'à' => 'a',
      'é' => 'e', 'ê' => 'e', 'í' => 'i',
      'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c',
    ]);
    $s = preg_replace('/\s+/', ' ', $s);
    if (strpos($s, 'cancel') !== false) return 'cancelado';
    if (strpos($s, 'conclu') !== false || strpos($s, 'finaliz') !== false) return 'concluido';
    if (strpos($s, 'pausad') !== false) return 'pausado';
    if (strpos($s, 'andament') !== false || strpos($s, 'progress') !== false) return 'em andamento';
    if (strpos($s, 'nao inici') !== false) return 'nao iniciado';
    return $s;
  }
}

if (!function_exists('krs_requer_justificativa')) {
  /** Justificativa obrigatória para cancelar/pausar/concluir. */
  function krs_requer_justificativa(string $idStatus): bool {
    return in_array($idStatus, ['cancelado', 'pausado', 'concluido'], true);
  }
}

if (!function_exists('krs_primeiro_check')) {
  /** Data (Y-m-d) do 1º milestone (primeiro check-in) ou null se não houver. */
  function krs_primeiro_check(PDO $pdo, string $idKr): ?string {
    $st = $pdo->prepare("SELECT MIN(data_ref) FROM milestones_kr WHERE id_kr = ?");
    $st->execute([$idKr]);
    $v = $st->fetchColumn();
    return ($v !== false && $v !== null && $v !== '') ? (string)$v : null;
  }
}

if (!function_exists('krs_pode_nao_iniciado')) {
  /** "nao iniciado" só é válido se o 1º check-in ainda não chegou (> hoje). */
  function krs_pode_nao_iniciado(PDO $pdo, string $idKr, ?string $today = null): bool {
    $today = $today ?: date('Y-m-d');
    $fc = krs_primeiro_check($pdo, $idKr);
    return $fc === null || $fc > $today;
  }
}

if (!function_exists('krs_auto_promover')) {
  /**
   * Promove KRs "nao iniciado" cujo 1º check-in já chegou (data_ref <= hoje) → "em andamento".
   * Escopo por empresa, idempotente. Retorna nº de KRs promovidos.
   */
  function krs_auto_promover(PDO $pdo, int $cid): int {
    $sql = "
      UPDATE key_results kr
        JOIN objetivos o ON o.id_objetivo = kr.id_objetivo
         SET kr.status = 'em andamento',
             kr.dt_ultima_atualizacao = NOW()
       WHERE o.id_company = ?
         AND LOWER(kr.status) IN ('nao iniciado', 'não iniciado')
         AND EXISTS (
           SELECT 1 FROM milestones_kr m
            WHERE m.id_kr = kr.id_kr AND m.data_ref <= CURDATE()
         )
    ";
    $st = $pdo->prepare($sql);
    $st->execute([$cid]);
    return $st->rowCount();
  }
}
