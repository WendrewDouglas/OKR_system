<?php
declare(strict_types=1);

/**
 * Agenda geral — monta a lista unificada de eventos com prazo.
 *
 * Fontes (todas amarradas à company via objetivos.id_company):
 *   - prazo do objetivo      objetivos.dt_prazo
 *   - início do objetivo     objetivos.dt_inicio
 *   - prazo do KR            COALESCE(key_results.dt_novo_prazo, key_results.data_fim)
 *   - marco do KR            milestones_kr.data_ref
 *   - prazo da iniciativa    iniciativas.dt_prazo
 *
 * Armadilhas tratadas aqui:
 *   - milestones_kr.id_company é varchar e está NULL na maioria das linhas:
 *     o vínculo com a empresa SEMPRE vai por milestone -> KR -> objetivo.
 *   - vários id_user_* são varchar apontando para usuarios.id_user (int): casteamos em PHP.
 *   - status é texto livre e inconsistente ('Não Iniciado' x 'nao iniciado'):
 *     tudo passa por krs_normalize_status().
 *
 * Isolado de views/agenda.php de propósito, para o app mobile poder reusar.
 *
 * O payload é NORMALIZADO: o evento carrega só ids e o que é dele (data, estado,
 * meta). Título, contexto e pessoas saem dos catálogos `objetivos`/`krs`/
 * `iniciativas`, cruzados pelo id. Sem isso a descrição de um KR se repetia nos
 * seus ~11 marcos e o JSON da FMX passava de 190 KB.
 *
 * Como resolver as pessoas de um evento:
 *   objetivo, inicio_objetivo -> objetivos[id_objetivo].pessoas
 *   kr, marco                 -> krs[id_kr].pessoas
 *   iniciativa                -> iniciativas[id_iniciativa].pessoas
 *
 * A URL também é derivável: detalhe_okr.php?id=<id_objetivo>.
 */

require_once __DIR__ . '/nome_format.php';
require_once __DIR__ . '/kr_status.php';
require_once __DIR__ . '/../avatar_helpers.php';

if (!function_exists('agenda_estado')) {
  /**
   * Estado do evento, na ordem de precedência que a UI usa para colorir.
   * O status do item ganha da data: um KR cancelado não é "vencido".
   */
  function agenda_estado(?string $data, string $statusNorm, bool $concluido, string $today): string {
    if ($statusNorm === 'cancelado') return 'cancelado';
    if ($statusNorm === 'pausado')   return 'pausado';
    if ($concluido || $statusNorm === 'concluido') return 'concluido';
    if ($data === null || $data === '') return 'sem_data';
    if ($data <  $today) return 'vencido';
    if ($data === $today) return 'hoje';
    if ($data <= date('Y-m-d', strtotime($today . ' +7 days'))) return 'proximo';
    return 'futuro';
  }
}

if (!function_exists('agenda_build_events')) {
  /**
   * @return array{
   *   eventos: list<array>,
   *   pessoas: array<int, array>,
   *   objetivos: array<int, array>,
   *   krs: array<string, array>,
   *   iniciativas: array<string, array>,
   *   hoje: string
   * }
   */
  function agenda_build_events(PDO $pdo, int $companyId, ?string $today = null): array {
    $today = $today ?: date('Y-m-d');

    $eventos     = [];
    $objetivos   = [];
    $krs         = [];
    $iniciativas = [];
    $pessoasIds  = [];

    /* ---------- catálogo de corresponsáveis ---------- */

    // Sócios do KR: só quem já aceitou o convite ('aprovado').
    // 'pendente' ainda não é corresponsável; 'rejeitado' nunca foi.
    $sociosPorKr = [];
    $stS = $pdo->prepare("
      SELECT s.id_kr, s.id_user
        FROM kr_socios s
        INNER JOIN key_results k ON k.id_kr = s.id_kr
        INNER JOIN objetivos   o ON o.id_objetivo = k.id_objetivo
       WHERE o.id_company = :cid AND s.status = 'aprovado'
    ");
    $stS->execute([':cid' => $companyId]);
    foreach ($stS as $r) {
      $uid = (int)$r['id_user'];
      $sociosPorKr[$r['id_kr']][] = $uid;
      $pessoasIds[$uid] = true;
    }

    // Envolvidos da iniciativa (multi-responsável).
    $envPorIni = [];
    $stE = $pdo->prepare("
      SELECT ie.id_iniciativa, ie.id_user
        FROM iniciativas_envolvidos ie
        INNER JOIN iniciativas i ON i.id_iniciativa = ie.id_iniciativa
        INNER JOIN key_results k ON k.id_kr = i.id_kr
        INNER JOIN objetivos   o ON o.id_objetivo = k.id_objetivo
       WHERE o.id_company = :cid
    ");
    $stE->execute([':cid' => $companyId]);
    foreach ($stE as $r) {
      $uid = (int)$r['id_user'];
      $envPorIni[$r['id_iniciativa']][] = $uid;
      $pessoasIds[$uid] = true;
    }

    /* ---------- 1/2) objetivos: prazo e início ---------- */

    $stO = $pdo->prepare("
      SELECT o.id_objetivo, o.descricao, o.dt_inicio, o.dt_prazo, o.dt_conclusao,
             o.status, o.dono, o.pilar_bsc, o.ciclo, o.tipo_ciclo
        FROM objetivos o
       WHERE o.id_company = :cid
       ORDER BY o.id_objetivo
    ");
    $stO->execute([':cid' => $companyId]);
    foreach ($stO as $o) {
      $idObj = (int)$o['id_objetivo'];
      $dono  = (int)$o['dono'];
      $desc  = trim((string)$o['descricao']);
      $sNorm = krs_normalize_status($o['status']);

      $objetivos[$idObj] = [
        'id'        => $idObj,
        'descricao' => $desc,
        'pilar'     => $o['pilar_bsc'],
        'dono'      => $dono,
        // A faixa de ciclo usa dt_inicio/dt_prazo, NUNCA o texto de `ciclo`:
        // esse campo é livre e convive com 'S2/2026', '01-02-2026' e '2026-03 a 2026-06'.
        'dt_inicio' => $o['dt_inicio'],
        'dt_prazo'  => $o['dt_prazo'],
        'ciclo'     => $o['ciclo'],
        'pessoas'   => $dono > 0 ? [['id' => $dono, 'papel' => 'responsavel']] : [],
      ];
      if ($dono > 0) $pessoasIds[$dono] = true;

      if (!empty($o['dt_prazo'])) {
        $eventos[] = [
          'id'            => 'obj:' . $idObj,
          'tipo'          => 'objetivo',
          'data'          => $o['dt_prazo'],
          'estado'        => agenda_estado($o['dt_prazo'], $sNorm, !empty($o['dt_conclusao']), $today),
          'status'        => $sNorm,
          'id_objetivo'   => $idObj,
          'id_kr'         => null,
          'id_iniciativa' => null,
          'meta'          => null,
        ];
      }
      if (!empty($o['dt_inicio'])) {
        $eventos[] = [
          'id'            => 'obji:' . $idObj,
          'tipo'          => 'inicio_objetivo',
          'data'          => $o['dt_inicio'],
          // O início não vence: ou já passou ou está por vir.
          'estado'        => $o['dt_inicio'] <= $today ? 'concluido' : 'futuro',
          'status'        => $sNorm,
          'id_objetivo'   => $idObj,
          'id_kr'         => null,
          'id_iniciativa' => null,
          'meta'          => null,
        ];
      }
    }

    /* ---------- 3) key results ---------- */

    $stK = $pdo->prepare("
      SELECT k.id_kr, k.id_objetivo, k.descricao, k.status, k.responsavel,
             k.data_inicio, k.data_fim, k.dt_novo_prazo, k.dt_conclusao,
             k.unidade_medida, k.meta, k.farol, k.key_result_num,
             o.descricao AS obj_descricao
        FROM key_results k
        INNER JOIN objetivos o ON o.id_objetivo = k.id_objetivo
       WHERE o.id_company = :cid
       ORDER BY k.id_objetivo, k.key_result_num
    ");
    $stK->execute([':cid' => $companyId]);
    foreach ($stK as $k) {
      $idKr  = (string)$k['id_kr'];
      $idObj = (int)$k['id_objetivo'];
      $resp  = (int)$k['responsavel'];
      $desc  = trim((string)$k['descricao']);
      $sNorm = krs_normalize_status($k['status']);
      $prazo = $k['dt_novo_prazo'] ?: $k['data_fim'];

      $pessoasKr = [];
      if ($resp > 0) { $pessoasKr[] = ['id' => $resp, 'papel' => 'responsavel']; $pessoasIds[$resp] = true; }
      foreach ($sociosPorKr[$idKr] ?? [] as $uid) {
        $pessoasKr[] = ['id' => $uid, 'papel' => 'corresponsavel'];
      }

      $krs[$idKr] = [
        'id'          => $idKr,
        'descricao'   => $desc,
        'id_objetivo' => $idObj,
        'num'         => (int)$k['key_result_num'],
        'responsavel' => $resp,
        'farol'       => $k['farol'],
        'unidade'     => $k['unidade_medida'],
        'meta_valor'  => $k['meta'] !== null ? (float)$k['meta'] : null,
        'pessoas'     => $pessoasKr,
      ];

      if (!empty($prazo)) {
        $eventos[] = [
          'id'            => 'kr:' . $idKr,
          'tipo'          => 'kr',
          'data'          => $prazo,
          'estado'        => agenda_estado($prazo, $sNorm, !empty($k['dt_conclusao']), $today),
          'status'        => $sNorm,
          'id_objetivo'   => $idObj,
          'id_kr'         => $idKr,
          'id_iniciativa' => null,
          'meta'          => [
            'prorrogado' => !empty($k['dt_novo_prazo']),
            'prazo_orig' => $k['data_fim'],
          ],
        ];
      }
    }

    /* ---------- 4) marcos (milestones) ---------- */

    // Sem id_company no WHERE: milestones_kr.id_company é varchar e está NULL
    // em 245 das 264 linhas. A empresa vem por KR -> objetivo.
    $stM = $pdo->prepare("
      SELECT m.id_milestone, m.id_kr, m.num_ordem, m.data_ref,
             m.valor_esperado, m.valor_real_consolidado, m.qtde_apontamentos,
             k.descricao AS kr_descricao, k.id_objetivo, k.status AS kr_status,
             k.unidade_medida, k.responsavel,
             o.descricao AS obj_descricao
        FROM milestones_kr m
        INNER JOIN key_results k ON k.id_kr = m.id_kr
        INNER JOIN objetivos   o ON o.id_objetivo = k.id_objetivo
       WHERE o.id_company = :cid
       ORDER BY m.data_ref, m.id_kr, m.num_ordem
    ");
    $stM->execute([':cid' => $companyId]);
    foreach ($stM as $m) {
      $idKr   = (string)$m['id_kr'];
      $idObj  = (int)$m['id_objetivo'];
      $sNorm  = krs_normalize_status($m['kr_status']);
      $apont  = (int)$m['qtde_apontamentos'] > 0;
      $data   = $m['data_ref'];

      $eventos[] = [
        'id'            => 'ms:' . (int)$m['id_milestone'],
        'tipo'          => 'marco',
        'data'          => $data,
        // Marco não tem status próprio: vale o apontamento, e o status do KR
        // (um marco de KR cancelado não deve aparecer como cobrança).
        'estado'        => agenda_estado($data, $sNorm, $apont, $today),
        'status'        => $sNorm,
        'id_objetivo'   => $idObj,
        'id_kr'         => $idKr,
        'id_iniciativa' => null,
        'meta'          => [
          'num_ordem'      => (int)$m['num_ordem'],
          'valor_esperado' => $m['valor_esperado'] !== null ? (float)$m['valor_esperado'] : null,
          'valor_real'     => $m['valor_real_consolidado'] !== null ? (float)$m['valor_real_consolidado'] : null,
          'apontado'       => $apont,
        ],
      ];
    }

    /* ---------- 5) iniciativas ---------- */

    $stI = $pdo->prepare("
      SELECT i.id_iniciativa, i.id_kr, i.descricao, i.dt_prazo, i.status,
             i.id_user_responsavel, i.num_iniciativa,
             k.id_objetivo, k.descricao AS kr_descricao
        FROM iniciativas i
        INNER JOIN key_results k ON k.id_kr = i.id_kr
        INNER JOIN objetivos   o ON o.id_objetivo = k.id_objetivo
       WHERE o.id_company = :cid
       ORDER BY i.id_kr, i.num_iniciativa
    ");
    $stI->execute([':cid' => $companyId]);
    foreach ($stI as $i) {
      $idIni = (string)$i['id_iniciativa'];
      $idKr  = (string)$i['id_kr'];
      $idObj = (int)$i['id_objetivo'];
      $resp  = (int)$i['id_user_responsavel'];
      $desc  = trim((string)$i['descricao']);
      $sNorm = krs_normalize_status($i['status']);

      $pessoasIni = [];
      $vistos = [];
      if ($resp > 0) {
        $pessoasIni[] = ['id' => $resp, 'papel' => 'responsavel'];
        $vistos[$resp] = true;
        $pessoasIds[$resp] = true;
      }
      foreach ($envPorIni[$idIni] ?? [] as $uid) {
        // O responsável costuma estar também em iniciativas_envolvidos:
        // não duplica, o papel principal prevalece.
        if (isset($vistos[$uid])) continue;
        $vistos[$uid] = true;
        $pessoasIni[] = ['id' => $uid, 'papel' => 'corresponsavel'];
      }

      $iniciativas[$idIni] = [
        'id'          => $idIni,
        'descricao'   => $desc,
        'id_kr'       => $idKr,
        'id_objetivo' => $idObj,
        'pessoas'     => $pessoasIni,
      ];

      if (!empty($i['dt_prazo'])) {
        $eventos[] = [
          'id'            => 'ini:' . $idIni,
          'tipo'          => 'iniciativa',
          'data'          => $i['dt_prazo'],
          'estado'        => agenda_estado($i['dt_prazo'], $sNorm, false, $today),
          'status'        => $sNorm,
          'id_objetivo'   => $idObj,
          'id_kr'         => $idKr,
          'id_iniciativa' => $idIni,
          'meta'          => ['num' => (int)$i['num_iniciativa']],
        ];
      }
    }

    /* ---------- catálogo de pessoas ---------- */

    $pessoas = [];
    if ($pessoasIds) {
      $ids = array_keys($pessoasIds);
      $in  = implode(',', array_fill(0, count($ids), '?'));
      $stU = $pdo->prepare("SELECT id_user, primeiro_nome, ultimo_nome FROM usuarios WHERE id_user IN ($in)");
      $stU->execute($ids);
      foreach ($stU as $u) {
        $uid  = (int)$u['id_user'];
        $nome = nome_exibicao((string)$u['primeiro_nome'], (string)$u['ultimo_nome']);
        $av   = avatar_resolve($uid, $pdo);
        $pessoas[$uid] = [
          'id'     => $uid,
          'nome'   => $nome !== '' ? $nome : ('Usuário ' . $uid),
          'avatar' => $av['url'] ?? '',
        ];
      }
      // Usuário referenciado que sumiu de `usuarios` não pode derrubar o filtro.
      foreach ($ids as $uid) {
        if (!isset($pessoas[$uid])) {
          $pessoas[$uid] = ['id' => $uid, 'nome' => 'Usuário ' . $uid, 'avatar' => ''];
        }
      }
    }

    usort($eventos, static function (array $a, array $b): int {
      return [$a['data'], $a['tipo'], $a['id']] <=> [$b['data'], $b['tipo'], $b['id']];
    });

    return [
      'eventos'     => $eventos,
      'pessoas'     => $pessoas,
      'objetivos'   => $objetivos,
      'krs'         => $krs,
      'iniciativas' => $iniciativas,
      'hoje'        => $today,
    ];
  }
}
