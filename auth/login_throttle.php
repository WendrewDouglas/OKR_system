<?php
declare(strict_types=1);

/**
 * auth/login_throttle.php — rate limiting de login (web + API).
 *
 * Auto-provisiona a tabela `login_attempts` (o runner de migration foi removido
 * na Fase 0). Tudo é FAIL-OPEN: se a tabela não puder ser criada/consultada, o
 * login NUNCA é bloqueado por erro de infraestrutura — apenas fica sem throttle.
 *
 * Política: janela de 15 min; bloqueia após 5 falhas para o mesmo e-mail OU 20
 * falhas para o mesmo IP. Tentativas com sucesso zeram o risco (não contam).
 */

const LOGIN_THROTTLE_WINDOW      = 900; // 15 min
const LOGIN_THROTTLE_MAX_EMAIL   = 5;
const LOGIN_THROTTLE_MAX_IP      = 20;

function login_throttle_ensure(PDO $pdo): bool {
  static $ok = null;
  if ($ok !== null) return $ok;
  try {
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS `login_attempts` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `ip` VARCHAR(45) NOT NULL,
        `email` VARCHAR(150) NOT NULL,
        `attempted_at` DATETIME NOT NULL,
        `success` TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        KEY `idx_ip_time` (`ip`, `attempted_at`),
        KEY `idx_email_time` (`email`, `attempted_at`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    return $ok = true;
  } catch (Throwable $e) {
    error_log('login_throttle ensure: ' . $e->getMessage());
    return $ok = false;
  }
}

/**
 * @return array{blocked:bool,retry_after:int}
 */
function login_throttle_check(PDO $pdo, string $ip, string $email): array {
  $none = ['blocked' => false, 'retry_after' => 0];
  if (!login_throttle_ensure($pdo)) return $none;
  try {
    $since = date('Y-m-d H:i:s', time() - LOGIN_THROTTLE_WINDOW);
    $email = mb_substr($email, 0, 150);

    $st = $pdo->prepare("SELECT COUNT(*) FROM `login_attempts` WHERE `email`=? AND `success`=0 AND `attempted_at` > ?");
    $st->execute([$email, $since]);
    $failEmail = (int)$st->fetchColumn();

    $st = $pdo->prepare("SELECT COUNT(*) FROM `login_attempts` WHERE `ip`=? AND `success`=0 AND `attempted_at` > ?");
    $st->execute([$ip, $since]);
    $failIp = (int)$st->fetchColumn();

    if ($failEmail >= LOGIN_THROTTLE_MAX_EMAIL || $failIp >= LOGIN_THROTTLE_MAX_IP) {
      return ['blocked' => true, 'retry_after' => LOGIN_THROTTLE_WINDOW];
    }
    return $none;
  } catch (Throwable $e) {
    error_log('login_throttle check: ' . $e->getMessage());
    return $none; // fail-open
  }
}

function login_throttle_record(PDO $pdo, string $ip, string $email, bool $success): void {
  if (!login_throttle_ensure($pdo)) return;
  try {
    $st = $pdo->prepare("INSERT INTO `login_attempts` (`ip`,`email`,`attempted_at`,`success`) VALUES (?,?,NOW(),?)");
    $st->execute([mb_substr($ip, 0, 45), mb_substr($email, 0, 150), $success ? 1 : 0]);

    // Limpeza probabilística de registros antigos (>24h) para não crescer sem limite.
    if (random_int(1, 25) === 1) {
      $pdo->prepare("DELETE FROM `login_attempts` WHERE `attempted_at` < ?")
          ->execute([date('Y-m-d H:i:s', time() - 86400)]);
    }
  } catch (Throwable $e) {
    error_log('login_throttle record: ' . $e->getMessage());
  }
}
