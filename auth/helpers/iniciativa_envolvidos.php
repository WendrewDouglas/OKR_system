<?php
declare(strict_types=1);

/**
 * Helpers para múltiplos responsáveis de iniciativa (junction table iniciativas_envolvidos).
 */

/**
 * Sincroniza os responsáveis de uma iniciativa.
 * DELETE + INSERT na junction e atualiza id_user_responsavel denormalizado (1º user).
 *
 * @param PDO    $pdo
 * @param string $id_iniciativa  PK varchar da iniciativa
 * @param int[]  $userIds        Array de id_user (pelo menos 1)
 */
function sync_iniciativa_envolvidos(PDO $pdo, string $id_iniciativa, array $userIds): void
{
    // Remove duplicatas e valores inválidos
    $userIds = array_values(array_unique(array_filter(
        array_map('intval', $userIds),
        fn(int $v) => $v > 0
    )));

    if (empty($userIds)) {
        return;
    }

    // DELETE existentes
    $pdo->prepare("DELETE FROM `iniciativas_envolvidos` WHERE `id_iniciativa` = ?")
        ->execute([$id_iniciativa]);

    // INSERT novos
    $st = $pdo->prepare(
        "INSERT INTO `iniciativas_envolvidos` (`id_iniciativa`, `id_user`) VALUES (?, ?)"
    );
    foreach ($userIds as $uid) {
        $st->execute([$id_iniciativa, $uid]);
    }

    // Atualiza denormalizado: id_user_responsavel = 1º da lista
    $pdo->prepare(
        "UPDATE `iniciativas` SET `id_user_responsavel` = ? WHERE `id_iniciativa` = ?"
    )->execute([$userIds[0], $id_iniciativa]);
}

/**
 * Retorna array de responsáveis de uma iniciativa.
 *
 * @return array<int, array{id_user: int, nome: string}>
 */
function get_iniciativa_envolvidos(PDO $pdo, string $id_iniciativa): array
{
    $st = $pdo->prepare("
        SELECT ie.`id_user`,
               CONCAT(u.`primeiro_nome`, ' ', COALESCE(u.`ultimo_nome`, '')) AS nome
        FROM `iniciativas_envolvidos` ie
        INNER JOIN `usuarios` u ON u.`id_user` = ie.`id_user`
        WHERE ie.`id_iniciativa` = ?
        ORDER BY ie.`id_user` ASC
    ");
    $st->execute([$id_iniciativa]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Retorna string com nomes separados por vírgula.
 *
 * @param array<int, array{nome: string}> $envolvidos
 */
function format_envolvidos_nomes(array $envolvidos): string
{
    return implode(', ', array_map(fn($e) => trim($e['nome']), $envolvidos));
}
