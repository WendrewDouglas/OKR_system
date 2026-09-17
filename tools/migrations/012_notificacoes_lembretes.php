<?php
declare(strict_types=1);

/**
 * Migration 012 — Lembretes e relatórios de pendência (e-mail + push)
 * 2026-09-16
 *
 * Quatro chaves por usuário, todas desligadas por padrão:
 *   - lembrete_marco       marco do KR sem apontamento: 3 dias antes e no dia
 *   - lembrete_iniciativa  iniciativa não concluída: no dia do prazo
 *   - relatorio_atrasos    itens vencidos da pessoa: terça e quinta
 *   - resumo_semanal       pendências de todos os responsáveis: segunda
 *
 * Usuário sem linha em `usuarios_notif_pref` = tudo desligado. Assim nenhum
 * usuário existente precisa de backfill.
 *
 * `notif_envio_log` impede envio duplicado quando o cron roda duas vezes no
 * mesmo dia (UNIQUE por usuário + dia + canal + chave) e serve de auditoria.
 * O disparo é feito por tools/notificacoes_okr.php.
 *
 * Idempotente.
 *
 * Uso (CLI):
 *   php tools/migrations/012_notificacoes_lembretes.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$projectRoot = dirname(__DIR__, 2);
$config = $projectRoot . '/auth/config.php';
if (!is_file($config)) {
    fwrite(STDERR, "Config não encontrado: {$config}\n");
    exit(1);
}
require_once $config;

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

// usuarios.id_user é INT (assinado): a FK acompanha o tipo.
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `usuarios_notif_pref` (
      `id_user`             INT NOT NULL,
      `lembrete_marco`      TINYINT(1) NOT NULL DEFAULT 0,
      `lembrete_iniciativa` TINYINT(1) NOT NULL DEFAULT 0,
      `relatorio_atrasos`   TINYINT(1) NOT NULL DEFAULT 0,
      `resumo_semanal`      TINYINT(1) NOT NULL DEFAULT 0,
      `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      `updated_by`          INT NULL,
      PRIMARY KEY (`id_user`),
      CONSTRAINT `fk_unp_user` FOREIGN KEY (`id_user`) REFERENCES `usuarios` (`id_user`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Sem FK para usuarios de propósito: o log precisa sobreviver à exclusão do usuário.
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `notif_envio_log` (
      `id_log`     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `id_user`    INT NOT NULL,
      `data_ref`   DATE NOT NULL COMMENT 'dia do disparo',
      `canal`      VARCHAR(10) NOT NULL COMMENT 'email|push',
      `chave`      VARCHAR(40) NOT NULL COMMENT 'pessoal | resumo:<id_company> | resumo',
      `status`     VARCHAR(16) NOT NULL COMMENT 'enviado|falha|sem_destino',
      `itens`      INT UNSIGNED NOT NULL DEFAULT 0,
      `detalhe`    VARCHAR(500) NULL,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id_log`),
      UNIQUE KEY `uq_nel_envio` (`id_user`, `data_ref`, `canal`, `chave`),
      KEY `idx_nel_data` (`data_ref`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

foreach (['usuarios_notif_pref', 'notif_envio_log'] as $t) {
    $n = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    echo "  $t: ok ($n linhas)\n";
}
echo "Lembretes de pendência: estrutura pronta.\n";
