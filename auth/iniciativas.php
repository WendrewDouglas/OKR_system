<?php
declare(strict_types=1);

/**
 * Handle da ação "nova_iniciativa" (inserção de iniciativa + orçamento opcional).
 * 
 * É chamado a partir de views/detalhe_okr.php em modo AJAX.
 * 
 * Parâmetros:
 *  - PDO $pdo                Conexão já aberta
 *  - callable $tableExists   Closure utilitário definido em detalhe_okr.php
 *  - callable $colExists     Closure utilitário definido em detalhe_okr.php
 */
function handle_nova_iniciativa(PDO $pdo, callable $tableExists, callable $colExists): void
{
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/helpers/iniciativa_envolvidos.php';

    if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success'=>false,'error'=>'Token CSRF inválido']);
        exit;
    }

    $id_kr    = $_POST['id_kr'] ?? '';
    $desc     = trim((string)($_POST['descricao'] ?? ''));
    $resp     = (int)($_POST['id_user_responsavel'] ?? 0);
    $status   = trim((string)($_POST['status_iniciativa'] ?? ''));
    $dt_prazo = $_POST['dt_prazo'] ?? null;

    // Múltiplos responsáveis (JSON array de id_user)
    $respJsonRaw = $_POST['responsaveis_json'] ?? '';
    $respIds = $respJsonRaw ? json_decode($respJsonRaw, true) : [];
    if (!is_array($respIds)) $respIds = [];
    $respIds = array_values(array_unique(array_filter(array_map('intval', $respIds), fn($v)=>$v>0)));
    if ($respIds) {
        $resp = $respIds[0];
    }

    $inclOrc  = !empty($_POST['incluir_orcamento']);
    $valorTot = (float)($_POST['valor_orcamento'] ?? 0);
    $prevArr  = json_decode($_POST['desembolsos_json'] ?? '[]', true) ?: [];
    $just     = trim((string)($_POST['justificativa_orcamento'] ?? ''));

    if (!$id_kr || $desc === '') {
        echo json_encode(['success'=>false,'error'=>'Dados obrigatórios ausentes']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // ============================================================
        // PROTEÇÃO ANTI-DUPLICAÇÃO (mesmo KR + descrição, etc.)
        // ============================================================
        $chkSql = "SELECT id_iniciativa FROM iniciativas WHERE id_kr = :k AND descricao = :d";
        $chkParams = [
            ':k' => $id_kr,
            ':d' => $desc,
        ];

        // se existir coluna id_user_responsavel, usamos no filtro
        if ($colExists($pdo, 'iniciativas', 'id_user_responsavel')) {
            $chkSql .= " AND id_user_responsavel = :r";
            $chkParams[':r'] = $resp ?: (int)($_SESSION['user_id'] ?? 0);
        }

        // se tiver dt_prazo informado e coluna existir, também filtramos
        if ($dt_prazo && $colExists($pdo, 'iniciativas', 'dt_prazo')) {
            $chkSql .= " AND dt_prazo = :p";
            $chkParams[':p'] = $dt_prazo;
        }

        // se a tabela tiver dt_criacao, limitamos aos últimos 30 segundos
        if ($colExists($pdo, 'iniciativas', 'dt_criacao')) {
            $chkSql .= " AND dt_criacao >= (NOW() - INTERVAL 30 SECOND)";
        }

        $chkSql .= " ORDER BY id_iniciativa DESC LIMIT 1";

        $stChk = $pdo->prepare($chkSql);
        $stChk->execute($chkParams);
        $dup = $stChk->fetch(PDO::FETCH_ASSOC);

        if ($dup) {
            // Já existe uma iniciativa idêntica recém-criada → evita duplicar
            $pdo->rollBack();

            echo json_encode([
                'success'        => true,
                'id_iniciativa'  => $dup['id_iniciativa'],
                'duplicado'      => true,
                'msg'            => 'Iniciativa idêntica já criada há poucos segundos. Evitando duplicação.',
            ]);
            exit;
        }
        // ============================================================
        // FIM DA PROTEÇÃO ANTI-DUPLICAÇÃO
        // ============================================================

        // dentro da transação:
        $st = $pdo->prepare("
            SELECT num_iniciativa
            FROM iniciativas
            WHERE id_kr = :k
            ORDER BY num_iniciativa DESC
            LIMIT 1
            FOR UPDATE
        ");
        $st->execute(['k'=>$id_kr]);
        $last = (int)($st->fetchColumn() ?: 0);
        $num  = $last + 1;

        // Gera id da iniciativa (varchar PK)
        $id_ini = bin2hex(random_bytes(12));

        // Insert dinâmico em iniciativas (resiliente a colunas)
        $colsI = [
            'id_iniciativa'   => $id_ini,
            'id_kr'           => $id_kr,
            'num_iniciativa'  => $num,
            'descricao'       => $desc
        ];

        if ($colExists($pdo, 'iniciativas', 'status')) {
            $colsI['status'] = $status ?: 'Não Iniciado';
        }
        if ($colExists($pdo, 'iniciativas', 'id_user_responsavel')) {
            $colsI['id_user_responsavel'] = $resp ?: (int)($_SESSION['user_id'] ?? 0);
        }
        if ($colExists($pdo, 'iniciativas', 'dt_prazo') && $dt_prazo) {
            $colsI['dt_prazo'] = $dt_prazo;
        }
        if ($colExists($pdo, 'iniciativas', 'dt_criacao')) {
            $colsI['dt_criacao'] = date('Y-m-d H:i:s');
        }
        if ($colExists($pdo, 'iniciativas', 'id_user_criador')) {
            $colsI['id_user_criador'] = (int)($_SESSION['user_id'] ?? 0);
        }

        $fI = implode(',', array_keys($colsI));
        $mI = implode(',', array_map(fn($k)=>":$k", array_keys($colsI)));

        $st = $pdo->prepare("INSERT INTO iniciativas ($fI) VALUES ($mI)");
        $st->execute($colsI);

        // Sync envolvidos na junction table
        $syncIds = $respIds ?: [$resp ?: (int)($_SESSION['user_id'] ?? 0)];
        sync_iniciativa_envolvidos($pdo, $id_ini, $syncIds);

        // Orçamento opcional: 1 linha por competência em "orcamentos"
        $createdOrc = 0;
        if ($inclOrc && $tableExists($pdo, 'orcamentos')) {
            $linhas  = [];
            $sumPrev = 0.0;

            // Monta a partir do JSON (competencia yyyy-mm, valor)
            foreach ((array)$prevArr as $p) {
                $comp = preg_match('/^\d{4}-\d{2}$/', $p['competencia'] ?? '') ? $p['competencia'] : null;
                $val  = (float)($p['valor'] ?? 0);
                if ($comp && $val > 0) {
                    $linhas[] = [$comp, $val];
                    $sumPrev += $val;
                }
            }

            // Se houver total e diferença, ajusta a última parcela
            if ($linhas && $valorTot > 0 && abs($sumPrev - $valorTot) > 0.01) {
                $linhas[count($linhas)-1][1] += ($valorTot - $sumPrev);
            }

            // Se não veio previsão, cria 1 parcela única
            if (!$linhas) {
                $comp   = $dt_prazo ? substr($dt_prazo, 0, 7) : date('Y-m');
                $linhas = [[$comp, max($valorTot, 0)]];
            }

            foreach ($linhas as [$comp, $val]) {
                $d   = $comp . '-01';
                $ins = ['id_iniciativa' => $id_ini];

                if ($colExists($pdo, 'orcamentos', 'valor')) {
                    $ins['valor'] = $val;
                }
                if ($colExists($pdo, 'orcamentos', 'data_desembolso')) {
                    $ins['data_desembolso'] = $d;
                }
                if ($colExists($pdo, 'orcamentos', 'status_aprovacao')) {
                    $ins['status_aprovacao'] = 'pendente';
                }
                if ($just && $colExists($pdo, 'orcamentos', 'justificativa_orcamento')) {
                    $ins['justificativa_orcamento'] = $just;
                }
                if ($colExists($pdo, 'orcamentos', 'id_user_criador')) {
                    $ins['id_user_criador'] = (int)($_SESSION['user_id'] ?? 0);
                }
                if ($colExists($pdo, 'orcamentos', 'dt_criacao')) {
                    $ins['dt_criacao'] = date('Y-m-d H:i:s');
                }

                $f = implode(',', array_keys($ins));
                $m = implode(',', array_map(fn($k)=>":$k", array_keys($ins)));

                $st = $pdo->prepare("INSERT INTO orcamentos ($f) VALUES ($m)");
                $st->execute($ins);
                $createdOrc++;
            }
        }

        $pdo->commit();

        echo json_encode([
            'success'       => true,
            'id_iniciativa' => $id_ini,
            'num_iniciativa'=> $num,
            'orc_parcelas'  => $createdOrc
        ]);
        exit;
    } catch (Throwable $e) {
        // Trata especificamente o SIGNAL 45000 / 1644 da trigger de duplicidade
        if ($e instanceof \PDOException) {
            // Log leve para debug (opcional; pode remover depois que estabilizar)
            error_log('[nova_iniciativa] PDOException: '
                . 'code=' . $e->getCode()
                . ' | errorInfo=' . json_encode($e->errorInfo ?? [])
                . ' | msg=' . $e->getMessage()
            );

            $errInfo   = $e->errorInfo ?? [];
            // errorInfo[0] = SQLSTATE, errorInfo[1] = código numérico (1644), errorInfo[2] = msg
            $sqlState  = $errInfo[0] ?? $e->getCode();
            $driverErr = $errInfo[1] ?? null;

            // Qualquer SIGNAL 45000 (SQLSTATE) + erro 1644 tratamos como duplicidade
            if ($sqlState === '45000' && (string)$driverErr === '1644') {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                echo json_encode([
                    'success'   => true,
                    'duplicada' => true,
                    'msg'       => 'Iniciativa já existia. Nenhum registro novo foi criado, mas o estado está consistente.'
                ]);
                exit;
            }
        }

        // Qualquer outro erro continua sendo erro de verdade
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        echo json_encode([
            'success' => false,
            'error'   => 'Falha ao criar iniciativa: ' . $e->getMessage(),
        ]);
        exit;
    }

}
