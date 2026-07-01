<?php
declare(strict_types=1);

// =============================================================
// Upsert conservador do respondente na tabela `usuarios` do OKR.
//
// REGRAS DE OURO:
//  - Respondente NUNCA recebe senha (usuarios_credenciais), papel RBAC
//    (rbac_user_role) nem permissão legada (usuarios_permissoes).
//  - Ao atualizar usuário existente, só preenche campos VAZIOS — jamais
//    sobrescreve dado real (nome/telefone/company já preenchidos, imagem_url,
//    avatar_id, id_permissao, dt_cadastro, id_user_criador).
//  - id_company = 1 e empresa = 'FMX' são fixos (definidos por quem chama).
// =============================================================

require_once __DIR__ . '/security.php'; // pg_split_name, pg_normalize_whatsapp, pg_valid_email

const PG_FMX_COMPANY_ID  = 1;
const PG_FMX_COMPANY_NAME = 'FMX';
const PG_ORIGEM_CADASTRO = 'form_perspectivas';

/**
 * Faz upsert do respondente e retorna o id_user.
 *
 * @param array $data ['nome'=>..., 'email'=>..., 'whatsapp'=>...(dígitos ou '')]
 * @return int id_user
 * @throws RuntimeException em falha inesperada
 */
function pg_upsert_user(PDO $pdo, array $data): int
{
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    if (!pg_valid_email($email)) {
        throw new RuntimeException('E-mail inválido para upsert.');
    }

    [$primeiro, $ultimo] = pg_split_name((string) ($data['nome'] ?? ''));
    if ($primeiro === '') {
        throw new RuntimeException('Nome inválido para upsert.');
    }

    $telefone = pg_normalize_whatsapp((string) ($data['whatsapp'] ?? ''));
    $telefone = $telefone !== '' ? mb_substr($telefone, 0, 20) : null;
    $ip       = pg_client_ip();

    // 1) Já existe? (chave: email_corporativo, UNIQUE)
    $existing = pg_find_user_by_email($pdo, $email);
    if ($existing !== null) {
        pg_update_existing_user($pdo, $existing, $primeiro, $ultimo, $telefone);
        return (int) $existing['id_user'];
    }

    // 2) Não existe → tenta inserir. Se corrida perder para o UNIQUE, cai no update.
    try {
        return pg_insert_new_user($pdo, $email, $primeiro, $ultimo, $telefone, $ip);
    } catch (PDOException $e) {
        // 23000 = integrity constraint violation (e-mail duplicado por concorrência)
        if (($e->getCode() === '23000') || (str_contains($e->getMessage(), 'Duplicate'))) {
            $existing = pg_find_user_by_email($pdo, $email);
            if ($existing !== null) {
                pg_update_existing_user($pdo, $existing, $primeiro, $ultimo, $telefone);
                return (int) $existing['id_user'];
            }
        }
        throw $e;
    }
}

/**
 * Busca usuário por e-mail. Retorna a linha ou null.
 */
function pg_find_user_by_email(PDO $pdo, string $email): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id_user, primeiro_nome, ultimo_nome, telefone, empresa, id_company, origem_cadastro
           FROM usuarios
          WHERE email_corporativo = :email
          LIMIT 1'
    );
    $stmt->execute([':email' => $email]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/**
 * Insere respondente novo. SEM senha, SEM RBAC, SEM permissão.
 */
function pg_insert_new_user(PDO $pdo, string $email, string $primeiro, ?string $ultimo, ?string $telefone, string $ip): int
{
    $sql = 'INSERT INTO usuarios
                (primeiro_nome, ultimo_nome, email_corporativo, telefone, empresa,
                 id_company, dt_cadastro, ip_criacao, origem_cadastro)
            VALUES
                (:primeiro, :ultimo, :email, :telefone, :empresa,
                 :id_company, NOW(), :ip, :origem)';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':primeiro'   => $primeiro,
        ':ultimo'     => ($ultimo !== '' ? $ultimo : null),
        ':email'      => $email,
        ':telefone'   => $telefone,
        ':empresa'    => PG_FMX_COMPANY_NAME,
        ':id_company' => PG_FMX_COMPANY_ID,
        ':ip'         => $ip,
        ':origem'     => PG_ORIGEM_CADASTRO,
    ]);
    return (int) $pdo->lastInsertId();
}

/**
 * Atualiza usuário existente de forma CONSERVADORA: só preenche o que está vazio.
 * Nunca sobrescreve dado real nem toca em campos sensíveis.
 */
function pg_update_existing_user(PDO $pdo, array $existing, string $primeiro, ?string $ultimo, ?string $telefone): void
{
    $sets   = [];
    $params = [':id' => (int) $existing['id_user']];

    if (trim((string) ($existing['primeiro_nome'] ?? '')) === '' && $primeiro !== '') {
        $sets[] = 'primeiro_nome = :primeiro';
        $params[':primeiro'] = $primeiro;
    }
    if (trim((string) ($existing['ultimo_nome'] ?? '')) === '' && (string) $ultimo !== '') {
        $sets[] = 'ultimo_nome = :ultimo';
        $params[':ultimo'] = $ultimo;
    }
    if (trim((string) ($existing['telefone'] ?? '')) === '' && $telefone !== null && $telefone !== '') {
        $sets[] = 'telefone = :telefone';
        $params[':telefone'] = $telefone;
    }
    if ($existing['id_company'] === null) {
        $sets[] = 'id_company = :id_company';
        $params[':id_company'] = PG_FMX_COMPANY_ID;
    }
    if (trim((string) ($existing['empresa'] ?? '')) === '') {
        $sets[] = 'empresa = :empresa';
        $params[':empresa'] = PG_FMX_COMPANY_NAME;
    }
    if (trim((string) ($existing['origem_cadastro'] ?? '')) === '') {
        $sets[] = 'origem_cadastro = :origem';
        $params[':origem'] = PG_ORIGEM_CADASTRO;
    }

    if (empty($sets)) {
        return; // nada a atualizar; não mexe em dt_alteracao à toa
    }

    $sets[] = 'dt_alteracao = NOW()';
    $sql = 'UPDATE usuarios SET ' . implode(', ', $sets) . ' WHERE id_user = :id';
    $pdo->prepare($sql)->execute($params);
}
