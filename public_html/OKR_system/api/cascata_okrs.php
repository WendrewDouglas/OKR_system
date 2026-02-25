<?php
/**
 * api/cascata_okrs.php — Endpoint AJAX para a cascata de OKRs.
 *
 * Retorna a árvore completa: Objetivos → KRs → Iniciativas → Orçamentos
 * para o usuário logado (ou toda a company, conforme parâmetros).
 *
 * GET ?scope=company     → todos os objetivos da company do usuário (PADRÃO)
 * GET ?scope=meus        → objetivos onde o usuário é dono, responsável KR ou envolvido em iniciativa
 * GET ?id_objetivo=N     → apenas um objetivo específico (com filhos)
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/functions.php';
require_once __DIR__ . '/../auth/acl.php';
require_once __DIR__ . '/../auth/helpers/iniciativa_envolvidos.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autorizado']);
    exit;
}

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro de conexão']);
    exit;
}

$userId    = (int)$_SESSION['user_id'];
$companyId = (int)($_SESSION['id_company'] ?? $_SESSION['company_id'] ?? 0);
$scope     = $_GET['scope'] ?? 'company';  // padrão = toda a empresa
$filtroObj = isset($_GET['id_objetivo']) ? (int)$_GET['id_objetivo'] : 0;

// Se company_id não está na sessão, buscar do banco
if ($companyId <= 0) {
    $stCid = $pdo->prepare("SELECT id_company FROM usuarios WHERE id_user = ? LIMIT 1");
    $stCid->execute([$userId]);
    $companyId = (int)($stCid->fetchColumn() ?: 0);
}

// ──── 1) Buscar Objetivos ────
$objWhere = "WHERE 1=1";
$objParams = [];

if ($filtroObj > 0) {
    $objWhere .= " AND o.id_objetivo = :obj_id";
    $objParams['obj_id'] = $filtroObj;
} elseif ($scope === 'meus') {
    // Objetivos onde o user participa: dono, responsável de KR, ou envolvido em iniciativa
    $objWhere .= " AND o.id_company = :cid AND o.id_objetivo IN (
        SELECT id_objetivo FROM objetivos WHERE dono = :uid1
        UNION
        SELECT id_objetivo FROM key_results WHERE responsavel = :uid2
        UNION
        SELECT kr.id_objetivo
        FROM iniciativas_envolvidos ie
        INNER JOIN iniciativas i ON i.id_iniciativa = ie.id_iniciativa
        INNER JOIN key_results kr ON kr.id_kr = i.id_kr
        WHERE ie.id_user = :uid3
    )";
    $objParams['cid'] = $companyId;
    $objParams['uid1'] = $userId;
    $objParams['uid2'] = $userId;
    $objParams['uid3'] = $userId;
} else {
    // company (padrão) — todos da empresa
    $objWhere .= " AND o.id_company = :cid";
    $objParams['cid'] = $companyId;
}

$stObj = $pdo->prepare("
    SELECT o.id_objetivo, o.descricao, o.tipo, o.pilar_bsc, o.status, o.status_aprovacao,
           o.tipo_ciclo, o.ciclo, o.dt_prazo, o.dt_inicio, o.dono,
           u.primeiro_nome AS dono_nome, u.ultimo_nome AS dono_sobrenome,
           a.filename AS dono_avatar
    FROM objetivos o
    LEFT JOIN usuarios u ON u.id_user = o.dono
    LEFT JOIN avatars  a ON a.id = u.avatar_id
    $objWhere
    ORDER BY o.dt_prazo ASC
");
$stObj->execute($objParams);
$objetivos = $stObj->fetchAll();

if (empty($objetivos)) {
    echo json_encode(['success' => true, 'objetivos' => []]);
    exit;
}

$objIds = array_column($objetivos, 'id_objetivo');

// ──── 2) Buscar KRs de todos os objetivos ────
$phObj = implode(',', array_fill(0, count($objIds), '?'));
$stKr = $pdo->prepare("
    SELECT kr.id_kr, kr.id_objetivo, kr.key_result_num, kr.descricao, kr.status,
           kr.baseline, kr.meta, kr.unidade_medida, kr.data_fim,
           kr.responsavel AS responsavel_id,
           u.primeiro_nome AS resp_nome, u.ultimo_nome AS resp_sobrenome,
           av.filename AS resp_avatar
    FROM key_results kr
    LEFT JOIN usuarios u  ON u.id_user = kr.responsavel
    LEFT JOIN avatars  av ON av.id = u.avatar_id
    WHERE kr.id_objetivo IN ($phObj)
    ORDER BY kr.key_result_num ASC
");
$stKr->execute($objIds);
$allKrs = $stKr->fetchAll();

$krsByObj = [];
$krIds = [];
foreach ($allKrs as $kr) {
    $krsByObj[$kr['id_objetivo']][] = $kr;
    $krIds[] = $kr['id_kr'];
}

// ──── 3) Buscar Iniciativas de todos os KRs ────
$inisByKr = [];
$allIniIds = [];
if ($krIds) {
    $phKr = implode(',', array_fill(0, count($krIds), '?'));
    $stIni = $pdo->prepare("
        SELECT i.id_iniciativa, i.id_kr, i.num_iniciativa, i.descricao, i.status,
               i.dt_prazo, i.id_user_responsavel,
               u.primeiro_nome AS resp_nome, u.ultimo_nome AS resp_sobrenome,
               av.filename AS resp_avatar
        FROM iniciativas i
        LEFT JOIN usuarios u  ON u.id_user = i.id_user_responsavel
        LEFT JOIN avatars  av ON av.id = u.avatar_id
        WHERE i.id_kr IN ($phKr)
        ORDER BY i.num_iniciativa ASC, i.dt_criacao ASC
    ");
    $stIni->execute($krIds);
    foreach ($stIni->fetchAll() as $ini) {
        $inisByKr[$ini['id_kr']][] = $ini;
        $allIniIds[] = $ini['id_iniciativa'];
    }
}

// ──── 4) Buscar envolvidos de todas as iniciativas ────
$envolvMap = [];
if ($allIniIds) {
    $phIni = implode(',', array_fill(0, count($allIniIds), '?'));
    $stEnv = $pdo->prepare("
        SELECT ie.id_iniciativa, ie.id_user,
               CONCAT(u.primeiro_nome, ' ', COALESCE(u.ultimo_nome,'')) AS nome,
               u.primeiro_nome, u.ultimo_nome,
               av.filename AS avatar
        FROM iniciativas_envolvidos ie
        INNER JOIN usuarios u  ON u.id_user = ie.id_user
        LEFT JOIN  avatars  av ON av.id = u.avatar_id
        WHERE ie.id_iniciativa IN ($phIni)
        ORDER BY ie.id_user ASC
    ");
    $stEnv->execute($allIniIds);
    foreach ($stEnv->fetchAll() as $row) {
        $envolvMap[$row['id_iniciativa']][] = [
            'id_user'   => (int)$row['id_user'],
            'nome'      => trim($row['nome']),
            'primeiro_nome' => $row['primeiro_nome'],
            'ultimo_nome'   => $row['ultimo_nome'],
            'avatar'    => $row['avatar'],
        ];
    }
}

// ──── 5) Buscar Orçamentos de todas as iniciativas ────
$orcByIni = [];
if ($allIniIds) {
    $stOrc = $pdo->prepare("
        SELECT o.id_orcamento, o.id_iniciativa, o.valor, o.valor_realizado,
               o.data_desembolso, o.status_aprovacao, o.status_financeiro,
               o.codigo_orcamento,
               COALESCE(d.total_despesas, 0) AS total_despesas
        FROM orcamentos o
        LEFT JOIN (
            SELECT id_orcamento, SUM(valor) AS total_despesas
            FROM orcamentos_detalhes
            GROUP BY id_orcamento
        ) d ON d.id_orcamento = o.id_orcamento
        WHERE o.id_iniciativa IN ($phIni)
        ORDER BY o.data_desembolso ASC
    ");
    $stOrc->execute($allIniIds);
    foreach ($stOrc->fetchAll() as $orc) {
        $orcByIni[$orc['id_iniciativa']][] = $orc;
    }
}

// ──── 6) Montar árvore ────
$avatarBase = '/OKR_system/assets/img/avatars/default_avatar/';
$defaultAvatar = $avatarBase . 'default.png';

$mkAvatar = function(?string $fn) use ($avatarBase, $defaultAvatar) {
    if ($fn && preg_match('/^[a-z0-9_.-]+\.png$/i', $fn)) {
        return $avatarBase . $fn;
    }
    return $defaultAvatar;
};

$mkInitials = function(?string $primeiro, ?string $ultimo) {
    $p = mb_strtoupper(mb_substr(trim((string)$primeiro), 0, 1));
    $u = mb_strtoupper(mb_substr(trim((string)$ultimo), 0, 1));
    return $p . $u ?: '?';
};

// Helper: monta um person-object a partir de dados crus (para deduplicar sócios)
$mkPerson = function(int $id, ?string $primeiro, ?string $ultimo, ?string $avatarFn) use ($mkAvatar, $mkInitials) {
    return [
        'id_user'  => $id,
        'nome'     => trim(($primeiro ?? '') . ' ' . ($ultimo ?? '')),
        'initials' => $mkInitials($primeiro, $ultimo),
        'avatar'   => $mkAvatar($avatarFn),
    ];
};

$tree = [];
foreach ($objetivos as $obj) {
    $oid = $obj['id_objetivo'];
    $krsOut = [];
    $objSocios = []; // id_user => person (todos que participam dentro do objetivo)

    foreach ($krsByObj[$oid] ?? [] as $kr) {
        $kid = $kr['id_kr'];
        $inisOut = [];
        $krSocios = []; // id_user => person (todos que participam dentro do KR)

        // O responsável do KR é sócio do objetivo
        $krRespId = (int)($kr['responsavel_id'] ?? 0);
        if ($krRespId > 0) {
            $p = $mkPerson($krRespId, $kr['resp_nome'], $kr['resp_sobrenome'], $kr['resp_avatar']);
            $objSocios[$krRespId] = $p;
        }

        foreach ($inisByKr[$kid] ?? [] as $ini) {
            $iid = $ini['id_iniciativa'];

            $envolvidos = [];
            foreach ($envolvMap[$iid] ?? [] as $env) {
                $person = [
                    'id_user'  => $env['id_user'],
                    'nome'     => $env['nome'],
                    'initials' => $mkInitials($env['primeiro_nome'], $env['ultimo_nome']),
                    'avatar'   => $mkAvatar($env['avatar']),
                ];
                $envolvidos[] = $person;
                // Cada envolvido é sócio do KR e do objetivo
                $krSocios[$env['id_user']] = $person;
                $objSocios[$env['id_user']] = $person;
            }

            // Responsável principal da iniciativa também é sócio
            $iniRespId = (int)($ini['id_user_responsavel'] ?? 0);
            if ($iniRespId > 0 && !isset($krSocios[$iniRespId])) {
                $p = $mkPerson($iniRespId, $ini['resp_nome'], $ini['resp_sobrenome'], $ini['resp_avatar']);
                $krSocios[$iniRespId] = $p;
                $objSocios[$iniRespId] = $p;
            }

            // Totalizar orçamento da iniciativa
            $orcs = $orcByIni[$iid] ?? [];
            $orcAprovado = 0; $orcRealizado = 0;
            $orcItems = [];
            foreach ($orcs as $orc) {
                $val = (float)$orc['valor'];
                $desp = (float)$orc['total_despesas'];
                $orcAprovado += $val;
                $orcRealizado += $desp;
                $orcItems[] = [
                    'id_orcamento'     => (int)$orc['id_orcamento'],
                    'valor'            => $val,
                    'total_despesas'   => $desp,
                    'data_desembolso'  => $orc['data_desembolso'],
                    'status_aprovacao' => $orc['status_aprovacao'],
                    'status_financeiro'=> $orc['status_financeiro'],
                    'codigo'           => $orc['codigo_orcamento'],
                ];
            }

            $inisOut[] = [
                'id_iniciativa'  => $iid,
                'num'            => (int)$ini['num_iniciativa'],
                'descricao'      => $ini['descricao'],
                'status'         => $ini['status'],
                'dt_prazo'       => $ini['dt_prazo'],
                'responsavel'    => [
                    'id_user'  => $iniRespId,
                    'nome'     => trim(($ini['resp_nome'] ?? '') . ' ' . ($ini['resp_sobrenome'] ?? '')),
                    'initials' => $mkInitials($ini['resp_nome'], $ini['resp_sobrenome']),
                    'avatar'   => $mkAvatar($ini['resp_avatar']),
                ],
                'envolvidos'     => $envolvidos,
                'orcamento'      => [
                    'aprovado'  => $orcAprovado,
                    'realizado' => $orcRealizado,
                    'saldo'     => max(0, $orcAprovado - $orcRealizado),
                    'items'     => $orcItems,
                ],
            ];
        }

        $krsOut[] = [
            'id_kr'       => $kid,
            'num'         => (int)$kr['key_result_num'],
            'descricao'   => $kr['descricao'],
            'status'      => $kr['status'],
            'baseline'    => $kr['baseline'],
            'meta'        => $kr['meta'],
            'unidade'     => $kr['unidade_medida'],
            'data_fim'    => $kr['data_fim'],
            'responsavel' => [
                'id_user'  => $krRespId,
                'nome'     => trim(($kr['resp_nome'] ?? '') . ' ' . ($kr['resp_sobrenome'] ?? '')),
                'initials' => $mkInitials($kr['resp_nome'], $kr['resp_sobrenome']),
                'avatar'   => $mkAvatar($kr['resp_avatar']),
            ],
            'socios'      => array_values($krSocios),
            'iniciativas' => $inisOut,
        ];
    }

    // Dono do objetivo também é "sócio"
    $donoId = (int)$obj['dono'];
    if ($donoId > 0) {
        $objSocios[$donoId] = $mkPerson($donoId, $obj['dono_nome'], $obj['dono_sobrenome'], $obj['dono_avatar']);
    }

    $tree[] = [
        'id_objetivo'     => (int)$oid,
        'descricao'       => $obj['descricao'],
        'tipo'            => $obj['tipo'],
        'pilar_bsc'       => $obj['pilar_bsc'],
        'status'          => $obj['status'],
        'status_aprovacao'=> $obj['status_aprovacao'],
        'tipo_ciclo'      => $obj['tipo_ciclo'],
        'ciclo'           => $obj['ciclo'],
        'dt_prazo'        => $obj['dt_prazo'],
        'dt_inicio'       => $obj['dt_inicio'],
        'dono'            => [
            'id_user'  => $donoId,
            'nome'     => trim(($obj['dono_nome'] ?? '') . ' ' . ($obj['dono_sobrenome'] ?? '')),
            'initials' => $mkInitials($obj['dono_nome'], $obj['dono_sobrenome']),
            'avatar'   => $mkAvatar($obj['dono_avatar']),
        ],
        'socios'          => array_values($objSocios),
        'key_results'     => $krsOut,
    ];
}

echo json_encode(['success' => true, 'objetivos' => $tree, 'user_id' => $userId]);
