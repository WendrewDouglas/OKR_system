<?php
/**
 * Regras de SÓCIOS do KR (compartilhado entre API e web).
 * Cada convite é uma linha em kr_socios. Regras:
 *  - até 3 sócios ativos (pendente|aprovado) por KR; rejeitados não contam;
 *  - motivo obrigatório por sócio; responsável não pode ser sócio; sem duplicar;
 *  - só o convidado decide seu convite; rejeição exige justificativa;
 *  - ao aprovar, espelha em okr_kr_envolvidos (papel 'socio') para a ACL.
 */
declare(strict_types=1);

if (!function_exists('krSociosContarAtivos')) {
    function krSociosContarAtivos(PDO $pdo, string $idKr): int
    {
        $st = $pdo->prepare("SELECT COUNT(*) FROM kr_socios WHERE id_kr = ? AND status IN ('pendente','aprovado')");
        $st->execute([$idKr]);
        return (int)$st->fetchColumn();
    }
}

if (!function_exists('krSociosValidarEInserir')) {
    /**
     * Valida e insere convites de sócio (status 'pendente').
     * @param array $socios lista de ['id_user'=>int, 'motivo'=>string]
     * @return array<int,array{id_convite:int,id_user:int}> convites criados
     * @throws InvalidArgumentException em erro de validação (mapear p/ 422)
     */
    function krSociosValidarEInserir(PDO $pdo, string $idKr, array $socios, int $idConvidou): array
    {
        if (empty($socios)) return [];

        $st = $pdo->prepare("SELECT responsavel FROM key_results WHERE id_kr = ?");
        $st->execute([$idKr]);
        $resp = $st->fetchColumn();
        $responsavel = $resp !== false && $resp !== null ? (int)$resp : 0;

        $vistos = [];
        $limpos = [];
        foreach ($socios as $s) {
            $uidS   = (int)($s['id_user'] ?? 0);
            $motivo = trim((string)($s['motivo'] ?? ''));
            if ($uidS <= 0 && $motivo === '') continue; // linha vazia (form web): ignora
            if ($uidS <= 0)                 throw new InvalidArgumentException('Selecione o usuário do sócio.');
            if ($motivo === '')             throw new InvalidArgumentException('Informe o motivo da sociedade para cada sócio.');
            if ($uidS === $responsavel)     throw new InvalidArgumentException('O responsável do KR não pode ser sócio.');
            if (isset($vistos[$uidS]))      throw new InvalidArgumentException('Sócio duplicado na lista.');
            $vistos[$uidS] = true;
            $limpos[] = ['id_user' => $uidS, 'motivo' => $motivo];
        }

        if (krSociosContarAtivos($pdo, $idKr) + count($limpos) > 3) {
            throw new InvalidArgumentException('Um KR pode ter no máximo 3 sócios.');
        }

        $stDup = $pdo->prepare("SELECT 1 FROM kr_socios WHERE id_kr = ? AND id_user = ? AND status IN ('pendente','aprovado') LIMIT 1");
        $ins   = $pdo->prepare("INSERT INTO kr_socios (id_kr, id_user, motivo, status, id_user_convidou, dt_convite) VALUES (?, ?, ?, 'pendente', ?, NOW())");
        $out = [];
        foreach ($limpos as $s) {
            $stDup->execute([$idKr, $s['id_user']]);
            if ($stDup->fetchColumn()) throw new InvalidArgumentException('Usuário já é sócio (ativo) deste KR.');
            $ins->execute([$idKr, $s['id_user'], $s['motivo'], $idConvidou]);
            $out[] = ['id_convite' => (int)$pdo->lastInsertId(), 'id_user' => $s['id_user']];
        }
        return $out;
    }
}

if (!function_exists('krSocioNotificarConvite')) {
    function krSocioNotificarConvite(PDO $pdo, string $idKr, int $idUserSocio): void
    {
        if (!function_exists('notify_inapp')) return;
        $st = $pdo->prepare("SELECT descricao FROM key_results WHERE id_kr = ?");
        $st->execute([$idKr]);
        $desc = (string)($st->fetchColumn() ?: $idKr);
        try {
            notify_inapp($pdo, $idUserSocio, 'Convite de sociedade em KR',
                'Você foi convidado como sócio do KR: ' . $desc, '/views/aprovacao.php');
        } catch (\Throwable $e) { /* notificação é best-effort */ }
    }
}

if (!function_exists('krSocioDecidir')) {
    /**
     * Aprova/rejeita um convite. Só o convidado (uid == id_user) decide.
     * @param string $decisao 'aprovado' | 'reprovado'
     * @return array contexto p/ notificação
     * @throws InvalidArgumentException justificativa ausente na rejeição
     * @throws RuntimeException 'NOT_FOUND' | 'FORBIDDEN' | 'STATE'
     */
    function krSocioDecidir(PDO $pdo, int $idConvite, int $uid, string $decisao, string $justificativa): array
    {
        $st = $pdo->prepare("SELECT * FROM kr_socios WHERE id_convite = ?");
        $st->execute([$idConvite]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row)                              throw new RuntimeException('NOT_FOUND');
        if ((int)$row['id_user'] !== $uid)      throw new RuntimeException('FORBIDDEN');
        if ($row['status'] !== 'pendente')      throw new RuntimeException('STATE');

        $rejeitado = in_array($decisao, ['reprovado', 'rejeitado'], true);
        if ($rejeitado) {
            if (trim($justificativa) === '') throw new InvalidArgumentException('Justificativa obrigatória para rejeitar a sociedade.');
            $pdo->prepare("UPDATE kr_socios SET status='rejeitado', justificativa_rejeicao=?, dt_decisao=NOW() WHERE id_convite=?")
                ->execute([$justificativa, $idConvite]);
        } else {
            $pdo->prepare("UPDATE kr_socios SET status='aprovado', dt_decisao=NOW() WHERE id_convite=?")
                ->execute([$idConvite]);
            // espelha acesso na ACL (papel 'socio')
            $pdo->prepare("INSERT IGNORE INTO okr_kr_envolvidos (id_kr, id_user, papel) VALUES (?, ?, 'socio')")
                ->execute([$row['id_kr'], $uid]);
        }

        $k = $pdo->prepare("SELECT descricao, responsavel FROM key_results WHERE id_kr = ?");
        $k->execute([$row['id_kr']]);
        $kr = $k->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'id_kr'            => $row['id_kr'],
            'id_user'          => (int)$row['id_user'],
            'id_user_convidou' => (int)$row['id_user_convidou'],
            'responsavel'      => isset($kr['responsavel']) ? (int)$kr['responsavel'] : 0,
            'kr_desc'          => (string)($kr['descricao'] ?? $row['id_kr']),
            'decisao'          => $rejeitado ? 'rejeitado' : 'aprovado',
        ];
    }
}

if (!function_exists('krSocioNotificarDecisao')) {
    function krSocioNotificarDecisao(PDO $pdo, array $ctx): void
    {
        if (!function_exists('notify_inapp')) return;
        $msg = 'Sócio ' . ($ctx['decisao'] === 'aprovado' ? 'aceitou' : 'recusou') . ' o convite no KR: ' . $ctx['kr_desc'];
        $alvos = array_unique(array_filter([$ctx['responsavel'], $ctx['id_user_convidou']]));
        foreach ($alvos as $alvo) {
            $alvo = (int)$alvo;
            if ($alvo > 0 && $alvo !== $ctx['id_user']) {
                try { notify_inapp($pdo, $alvo, 'Decisão de sociedade', $msg, '/views/detalhe_okr.php'); }
                catch (\Throwable $e) { /* best-effort */ }
            }
        }
    }
}
