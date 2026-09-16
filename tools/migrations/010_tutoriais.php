<?php
declare(strict_types=1);

/**
 * Migration 010 — Catálogo de vídeos tutoriais (tela views/tutoriais.php)
 * 2026-09-16
 *
 * A tela lê esta tabela, então publicar um vídeo novo é inserir uma linha aqui
 * e subir o arquivo para uploads/tutoriais/ — sem tocar em código.
 *
 * Os arquivos NÃO entram no git (uploads/ é ignorado) e são servidos
 * estaticamente pelo Apache, que já responde a byte-range (é o que permite
 * arrastar a barra do player e começar a assistir antes do download terminar).
 *
 * Idempotente: cria a tabela se faltar e faz upsert dos tutoriais pelo slug.
 *
 * Uso (CLI):
 *   php tools/migrations/010_tutoriais.php
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

$pdo->exec("
    CREATE TABLE IF NOT EXISTS `tutoriais` (
      `id_tutorial` INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `slug`        VARCHAR(80)  NOT NULL,
      `titulo`      VARCHAR(160) NOT NULL,
      `descricao`   TEXT NULL,
      `trilha`      VARCHAR(60)  NOT NULL DEFAULT 'Geral',
      `ordem`       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
      `arquivo`     VARCHAR(255) NOT NULL COMMENT 'nome do arquivo em uploads/tutoriais/',
      `poster`      VARCHAR(255) NULL,
      `legenda`     VARCHAR(255) NULL COMMENT 'WebVTT para acessibilidade',
      `duracao_seg` INT UNSIGNED NULL,
      `publicado`   TINYINT(1) NOT NULL DEFAULT 1,
      `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at`  DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id_tutorial`),
      UNIQUE KEY `uq_tutorial_slug` (`slug`),
      KEY `idx_tutorial_ordem` (`publicado`, `ordem`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// [slug, título, descrição, trilha, ordem, duração em segundos]
$tutoriais = [
    ['01-login', 'Como entrar no sistema',
     'Do site da PlanningBI até o seu painel: onde clicar, o que preencher e o que fazer se esquecer a senha.',
     'Primeiros passos', 1, 76],
    ['02-meus-okrs', 'Meus OKRs: a cascata da estratégia',
     'Objetivos, resultados-chave e iniciativas em um só lugar, com filtro por status e a alternância entre toda a empresa e o que é seu.',
     'Visões da estratégia', 2, 168],
    ['06-agenda', 'Agenda de prazos',
     'O calendário com todos os prazos da empresa, com filtros em cascata, busca e as visões de mês, semana, lista e ciclo.',
     'Visões da estratégia', 3, 211],
    ['03-detalhe-objetivo', 'Detalhe do objetivo',
     'A tela onde o acompanhamento acontece: cabeçalho do objetivo, cartões de resumo, card do KR e as cinco abas.',
     'Acompanhamento no dia a dia', 4, 229],
    ['04-apontamento', 'Registrando um apontamento',
     'Como informar o resultado do período em um KR, com justificativa e evidência, e o que muda na tela depois de salvar.',
     'Acompanhamento no dia a dia', 5, 162],
    ['05-iniciativas', 'Gerenciando iniciativas',
     'Criar iniciativa com orçamento, alterar status, lançar despesa, reordenar e usar as visões de lista e Kanban.',
     'Acompanhamento no dia a dia', 6, 232],
];

$st = $pdo->prepare("
    INSERT INTO `tutoriais` (slug, titulo, descricao, trilha, ordem, arquivo, poster, legenda, duracao_seg, publicado)
    VALUES (:slug, :titulo, :descricao, :trilha, :ordem, :arquivo, :poster, :legenda, :dur, 1)
    ON DUPLICATE KEY UPDATE
      titulo = VALUES(titulo), descricao = VALUES(descricao), trilha = VALUES(trilha),
      ordem = VALUES(ordem), arquivo = VALUES(arquivo), poster = VALUES(poster),
      legenda = VALUES(legenda), duracao_seg = VALUES(duracao_seg), publicado = 1
");

foreach ($tutoriais as [$slug, $titulo, $descricao, $trilha, $ordem, $dur]) {
    $st->execute([
        ':slug' => $slug, ':titulo' => $titulo, ':descricao' => $descricao,
        ':trilha' => $trilha, ':ordem' => $ordem,
        ':arquivo' => $slug . '.mp4', ':poster' => $slug . '.jpg', ':legenda' => $slug . '.vtt',
        ':dur' => $dur,
    ]);
}

echo "tutoriais: tabela pronta, ".count($tutoriais)." cadastros.\n";
foreach ($pdo->query("SELECT ordem, slug, trilha, duracao_seg FROM tutoriais ORDER BY ordem") as $r) {
    printf("  %d %-22s %-28s %ds\n", $r['ordem'], $r['slug'], $r['trilha'], $r['duracao_seg']);
}
