<?php
declare(strict_types=1);

/**
 * Migration 011 — Eventos da empresa na Agenda
 * 2026-09-16
 *
 * Até aqui a Agenda só mostrava prazos DERIVADOS (objetivo, KR, marco, iniciativa).
 * Estas tabelas permitem que um administrador (admin_master ou gestor_master da
 * empresa) cadastre compromissos próprios — reuniões, ritos, treinamentos — com
 * hora, descrição, participantes e recorrência.
 *
 * Desenho: guarda-se a REGRA da recorrência, não uma linha por ocorrência.
 * A expansão acontece na leitura (auth/helpers/agenda_eventos.php), dentro de uma
 * janela limitada. Materializar ocorrências incharia o banco e transformaria
 * qualquer correção de série em migração de dados. A exceção é a tabela de
 * exceções, que guarda só o que foge da regra (ocorrência cancelada ou remarcada).
 *
 * Idempotente.
 *
 * Uso (CLI):
 *   php tools/migrations/011_agenda_eventos.php
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

// company.id_company é INT UNSIGNED e usuarios.id_user é INT (assinado):
// os tipos abaixo acompanham isso, senão a FK é recusada.
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `agenda_eventos` (
      `id_evento`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `id_company`      INT UNSIGNED NOT NULL,
      `titulo`          VARCHAR(160) NOT NULL,
      `descricao`       TEXT NULL,
      `local`           VARCHAR(255) NULL COMMENT 'sala, endereço ou link da reunião',
      `data_inicio`     DATE NOT NULL,
      `hora_inicio`     TIME NULL COMMENT 'NULL = dia inteiro',
      `hora_fim`        TIME NULL,
      `recorrencia`     VARCHAR(12) NOT NULL DEFAULT 'nenhuma' COMMENT 'nenhuma|semanal|quinzenal|mensal',
      `dias_semana`     VARCHAR(20) NULL COMMENT 'CSV 0=dom..6=sab, para semanal/quinzenal',
      `regra_mensal`    VARCHAR(10) NULL COMMENT 'dia (mesmo dia do mês) | semana (mesma semana e dia, ex. 2a terça)',
      `data_fim`        DATE NULL COMMENT 'limite da recorrência; NULL = sem fim',
      `ativo`           TINYINT(1) NOT NULL DEFAULT 1,
      `id_user_criador` INT NOT NULL,
      `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at`      DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id_evento`),
      KEY `idx_agev_company` (`id_company`, `ativo`, `data_inicio`),
      CONSTRAINT `fk_agev_company` FOREIGN KEY (`id_company`) REFERENCES `company` (`id_company`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS `agenda_evento_pessoas` (
      `id_evento` INT UNSIGNED NOT NULL,
      `id_user`   INT NOT NULL,
      `papel`     VARCHAR(14) NOT NULL DEFAULT 'participante' COMMENT 'organizador|participante',
      PRIMARY KEY (`id_evento`, `id_user`),
      KEY `idx_agevp_user` (`id_user`),
      CONSTRAINT `fk_agevp_evento` FOREIGN KEY (`id_evento`) REFERENCES `agenda_eventos` (`id_evento`) ON DELETE CASCADE,
      CONSTRAINT `fk_agevp_user`   FOREIGN KEY (`id_user`)   REFERENCES `usuarios` (`id_user`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Uma ocorrência que foge da regra: desmarcada ou movida de data/hora.
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `agenda_evento_excecoes` (
      `id_evento`  INT UNSIGNED NOT NULL,
      `data_ref`   DATE NOT NULL COMMENT 'data ORIGINAL da ocorrência, gerada pela regra',
      `acao`       VARCHAR(10) NOT NULL COMMENT 'cancelada|remarcada',
      `nova_data`  DATE NULL,
      `nova_hora`  TIME NULL,
      `motivo`     VARCHAR(255) NULL,
      `id_user`    INT NULL,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id_evento`, `data_ref`),
      CONSTRAINT `fk_agevx_evento` FOREIGN KEY (`id_evento`) REFERENCES `agenda_eventos` (`id_evento`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

foreach (['agenda_eventos', 'agenda_evento_pessoas', 'agenda_evento_excecoes'] as $t) {
    $n = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    echo "  $t: ok ($n linhas)\n";
}
echo "Eventos da agenda: estrutura pronta.\n";
