<?php
// .../auth/versao_ativa.php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$pdo = pdo();

$slug     = isset($_GET['slug']) && $_GET['slug'] !== '' ? trim((string)$_GET['slug']) : 'lp001';
$id_cargo = isset($_GET['id_cargo']) ? (int)$_GET['id_cargo'] : 0;

try {
    // 1) Carrega quiz
    $st = $pdo->prepare("
        SELECT id_quiz,
               COALESCE(versao_ativa, 0) AS versao_ativa,
               status
          FROM lp001_quiz
         WHERE slug = ?
         LIMIT 1
    ");
    $st->execute([$slug]);
    $quiz = $st->fetch(PDO::FETCH_ASSOC);
    if (!$quiz) {
        fail('Quiz não encontrado', 404);
    }

    $id_quiz          = (int)$quiz['id_quiz'];
    $id_versao_global = (int)$quiz['versao_ativa'];

    // 2) Se não houver versao_ativa, pega a última versão cadastrada
    if ($id_versao_global <= 0) {
        $st = $pdo->prepare("
            SELECT id_versao
              FROM lp001_quiz_versao
             WHERE id_quiz = ?
             ORDER BY id_versao DESC
             LIMIT 1
        ");
        $st->execute([$id_quiz]);
        $id_versao_global = (int)$st->fetchColumn();
    }

    if ($id_versao_global <= 0) {
        fail('Nenhuma versão ativa encontrada', 404);
    }

    // 3) Versão por cargo (se houver mapa)
    $id_versao = 0;
    $cargo_nome = null;

    if ($id_cargo > 0) {
        // nome do cargo (para filtrar branch_key)
        $st = $pdo->prepare("SELECT nome FROM lp001_dom_cargos WHERE id_cargo = ? LIMIT 1");
        $st->execute([$id_cargo]);
        $cargo_nome = $st->fetchColumn() ?: null;

        // mapa cargo->versão (se existir)
        $st = $pdo->prepare("
            SELECT id_versao
              FROM lp001_quiz_cargo_map
             WHERE id_cargo = ?
             LIMIT 1
        ");
        $st->execute([$id_cargo]);
        $id_versao = (int)$st->fetchColumn();
    }

    if ($id_versao <= 0) {
        $id_versao = $id_versao_global;
    }

    // 4) Domínios
    $st = $pdo->prepare("
        SELECT id_dominio, nome, peso, ordem
          FROM lp001_quiz_dominios
         WHERE id_versao = ?
         ORDER BY ordem
    ");
    $st->execute([$id_versao]);
    $dominios = $st->fetchAll(PDO::FETCH_ASSOC);

    // 5) Perguntas (filtrando por cargo, se informado)
    $sqlPerg = "
        SELECT id_pergunta,
               id_dominio,
               ordem,
               texto,
               glossario_json,
               branch_key
          FROM lp001_quiz_perguntas
         WHERE id_versao = ?
    ";
    $paramsPerg = [$id_versao];

    if ($cargo_nome) {
        // branch_key foi montado como "cargo={cargo}|modelo={modelo}"
        $sqlPerg .= " AND branch_key LIKE ?";
        $paramsPerg[] = 'cargo=' . $cargo_nome . '|%';
    }

    $sqlPerg .= " ORDER BY ordem";
    $st = $pdo->prepare($sqlPerg);
    $st->execute($paramsPerg);
    $perguntas = $st->fetchAll(PDO::FETCH_ASSOC);

    // 6) Opções
    if ($perguntas) {
        $ids = array_map('intval', array_column($perguntas, 'id_pergunta'));
        $in  = implode(',', $ids);

        $sqlOps = "
            SELECT id_opcao,
                   id_pergunta,
                   ordem,
                   texto,
                   explicacao,
                   score
              FROM lp001_quiz_opcoes
             WHERE id_pergunta IN ($in)
             ORDER BY ordem
        ";
        $ops = $pdo->query($sqlOps)->fetchAll(PDO::FETCH_ASSOC);

        $byQ = [];
        foreach ($ops as $o) {
            $pid = (int)$o['id_pergunta'];
            if (!isset($byQ[$pid])) {
                $byQ[$pid] = [];
            }
            $byQ[$pid][] = $o;
        }

        foreach ($perguntas as &$p) {
            $pid = (int)$p['id_pergunta'];
            $p['opcoes'] = $byQ[$pid] ?? [];
        }
        unset($p);
    }

    ok([
        'id_quiz'   => $id_quiz,
        'id_versao' => $id_versao,
        'dominios'  => $dominios,
        'perguntas' => $perguntas,
    ]);

} catch (Throwable $e) {
    fail('Erro ao carregar versão ativa: ' . $e->getMessage(), 500);
}
