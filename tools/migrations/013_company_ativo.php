<?php
declare(strict_types=1);

/**
 * Migration 013 — Empresa ativa
 * 2026-09-17
 *
 * `company.ativo` é ligado/desligado pelo Painel de Empresas (admin_master).
 * Hoje só governa os avisos de pendência (tools/notificacoes_okr.php):
 * empresa inativa não gera lembrete, relatório de atraso nem resumo.
 * NÃO bloqueia login nem acesso ao sistema.
 *
 * Padrão 0: empresa nova não recebe avisos até alguém ativá-la.
 * Na criação da coluna, só a FMX (1) nasce ativa.
 *
 * Idempotente: se a coluna já existe, não mexe nos valores.
 *
 * Uso (CLI):
 *   php tools/migrations/013_company_ativo.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/auth/config.php';

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$existe = static function (string $col) use ($pdo): bool {
    $st = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'company' AND COLUMN_NAME = ?");
    $st->execute([$col]);
    return (bool)$st->fetchColumn();
};

if (!$existe('ativo')) {
    $pdo->exec("ALTER TABLE `company`
      ADD COLUMN `ativo` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'empresa ativa: governa os avisos de pendência (não bloqueia acesso)',
      ADD COLUMN `ativo_alterado_em` DATETIME NULL,
      ADD COLUMN `ativo_alterado_por` INT NULL");
    $pdo->exec("UPDATE `company` SET `ativo` = 1, `ativo_alterado_em` = NOW() WHERE `id_company` = 1");
    echo "  company.ativo criada; FMX (1) ativa, demais inativas.\n";
} else {
    echo "  company.ativo já existe; valores preservados.\n";
}

foreach ($pdo->query("SELECT id_company, organizacao, ativo FROM company ORDER BY id_company") as $r) {
    printf("  [%d] %-40s %s\n", $r['id_company'], $r['organizacao'], (int)$r['ativo'] === 1 ? 'ATIVA' : 'inativa');
}
echo "Empresa ativa: estrutura pronta.\n";
