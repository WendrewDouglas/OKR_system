<?php
declare(strict_types=1);

/**
 * Eventos da empresa na Agenda — leitura e expansão da recorrência.
 *
 * Guardamos a REGRA (agenda_eventos) e expandimos as ocorrências aqui, dentro de
 * uma janela limitada. Exceções (ocorrência cancelada ou remarcada) ficam em
 * agenda_evento_excecoes e são aplicadas depois da expansão.
 *
 * Isolado de agenda_events.php para o motor de prazos continuar sendo só isso:
 * prazos. Quem junta as duas fontes é agenda_build_events(), e ele só faz isso
 * quando o chamador pede (Minhas Tarefas, por exemplo, pede só os eventos em que
 * a pessoa é participante).
 */

require_once __DIR__ . '/nome_format.php';
require_once __DIR__ . '/../avatar_helpers.php';

if (!defined('AGEV_JANELA_MESES_ATRAS')) {
  // Janela de expansão: o payload da Agenda vai inline, então precisa de limite.
  // 12 meses atrás cobre o histórico visível; 18 à frente cobre o ciclo anual + folga.
  define('AGEV_JANELA_MESES_ATRAS', 12);
  define('AGEV_JANELA_MESES_FRENTE', 18);
}

if (!function_exists('agev_janela')) {
  /** @return array{0:string,1:string} [de, ate] em Y-m-d */
  function agev_janela(string $hoje): array {
    return [
      date('Y-m-01', strtotime($hoje . ' -' . AGEV_JANELA_MESES_ATRAS . ' months')),
      date('Y-m-t',  strtotime($hoje . ' +' . AGEV_JANELA_MESES_FRENTE . ' months')),
    ];
  }
}

if (!function_exists('agev_expandir')) {
  /**
   * Datas geradas pela regra do evento dentro de [de, ate].
   *
   * @param array $ev linha de agenda_eventos
   * @return list<string> datas Y-m-d, em ordem
   */
  function agev_expandir(array $ev, string $de, string $ate): array {
    $inicio = (string)$ev['data_inicio'];
    $limite = !empty($ev['data_fim']) && $ev['data_fim'] < $ate ? (string)$ev['data_fim'] : $ate;
    if ($inicio > $limite) return [];

    $rec = (string)($ev['recorrencia'] ?? 'nenhuma');
    if ($rec === 'nenhuma' || $rec === '') {
      return ($inicio >= $de && $inicio <= $limite) ? [$inicio] : [];
    }

    // Trava de segurança: uma regra ruim não pode gerar lista infinita.
    $MAX = 400;
    $datas = [];

    if ($rec === 'semanal' || $rec === 'quinzenal') {
      $passoSemanas = ($rec === 'quinzenal') ? 2 : 1;
      $dias = array_values(array_filter(
        array_map('intval', explode(',', (string)($ev['dias_semana'] ?? ''))),
        static fn($d) => $d >= 0 && $d <= 6
      ));
      if (!$dias) $dias = [(int)date('w', strtotime($inicio))];
      sort($dias);

      // Âncora: domingo da semana em que a série começa.
      $semana0 = strtotime('sunday this week', strtotime($inicio));
      if (date('w', strtotime($inicio)) === '0') $semana0 = strtotime($inicio);
      else $semana0 = strtotime('-' . (int)date('w', strtotime($inicio)) . ' days', strtotime($inicio));

      for ($s = 0; count($datas) < $MAX; $s += $passoSemanas) {
        $baseSemana = strtotime("+{$s} weeks", $semana0);
        if (date('Y-m-d', $baseSemana) > $limite) break;
        foreach ($dias as $d) {
          $data = date('Y-m-d', strtotime("+{$d} days", $baseSemana));
          if ($data < $inicio || $data > $limite) continue;
          if ($data >= $de) $datas[] = $data;
        }
        if ($s > 520) break; // ~10 anos de semanas: nunca deveria chegar aqui
      }
    } elseif ($rec === 'mensal') {
      $regra   = (string)($ev['regra_mensal'] ?? 'dia');
      $diaMes  = (int)date('j', strtotime($inicio));
      $diaSem  = (int)date('w', strtotime($inicio));
      $ordinal = (int)ceil($diaMes / 7);   // 1ª, 2ª, 3ª... ocorrência daquele dia da semana

      for ($m = 0; count($datas) < $MAX && $m <= 240; $m++) {
        $ref = strtotime(date('Y-m-01', strtotime($inicio)) . " +{$m} months");
        $ano = (int)date('Y', $ref);
        $mes = (int)date('n', $ref);
        if (date('Y-m-d', $ref) > $limite) break;

        if ($regra === 'semana') {
          // "mesma posição no mês": ex. 2ª terça-feira.
          $primeiro = strtotime(sprintf('%04d-%02d-01', $ano, $mes));
          $desloc   = ($diaSem - (int)date('w', $primeiro) + 7) % 7;
          $dia      = 1 + $desloc + ($ordinal - 1) * 7;
          if ($dia > (int)date('t', $primeiro)) continue; // mês sem essa 5ª ocorrência
          $data = sprintf('%04d-%02d-%02d', $ano, $mes, $dia);
        } else {
          // "mesmo dia do mês": mês curto simplesmente não tem dia 31.
          if ($diaMes > (int)date('t', $ref)) continue;
          $data = sprintf('%04d-%02d-%02d', $ano, $mes, $diaMes);
        }

        if ($data < $inicio || $data > $limite) continue;
        if ($data >= $de) $datas[] = $data;
      }
    }

    sort($datas);
    return $datas;
  }
}

if (!function_exists('agev_carregar')) {
  /**
   * Catálogo de eventos da empresa + ocorrências já expandidas na janela.
   *
   * @return array{catalogo: array<int,array>, ocorrencias: list<array>}
   */
  function agev_carregar(PDO $pdo, int $companyId, string $hoje): array {
    [$de, $ate] = agev_janela($hoje);

    $catalogo = [];
    $ocorrencias = [];

    try {
      $st = $pdo->prepare("
        SELECT id_evento, titulo, descricao, `local`, data_inicio, hora_inicio, hora_fim,
               recorrencia, dias_semana, regra_mensal, data_fim, id_user_criador
          FROM agenda_eventos
         WHERE id_company = :cid AND ativo = 1
         ORDER BY data_inicio, id_evento
      ");
      $st->execute([':cid' => $companyId]);
      $eventos = $st->fetchAll();
    } catch (Throwable $e) {
      // Tabela ainda não migrada: a Agenda continua funcionando só com prazos.
      error_log('agev_carregar: ' . $e->getMessage());
      return ['catalogo' => [], 'ocorrencias' => []];
    }

    if (!$eventos) return ['catalogo' => [], 'ocorrencias' => []];

    $ids = array_column($eventos, 'id_evento');
    $in  = implode(',', array_fill(0, count($ids), '?'));

    // participantes
    $pessoasPorEvento = [];
    $stP = $pdo->prepare("SELECT id_evento, id_user, papel FROM agenda_evento_pessoas WHERE id_evento IN ($in)");
    $stP->execute($ids);
    foreach ($stP as $r) {
      $pessoasPorEvento[(int)$r['id_evento']][] = [
        'id'    => (int)$r['id_user'],
        // A Agenda já sabe pintar 'responsavel' e 'corresponsavel': o organizador
        // entra como responsável para reusar filtro, avatar e o preset "Meus prazos".
        'papel' => ($r['papel'] === 'organizador') ? 'responsavel' : 'corresponsavel',
      ];
    }

    // exceções
    $excecoes = [];
    $stX = $pdo->prepare("SELECT id_evento, data_ref, acao, nova_data, nova_hora FROM agenda_evento_excecoes WHERE id_evento IN ($in)");
    $stX->execute($ids);
    foreach ($stX as $r) {
      $excecoes[(int)$r['id_evento']][(string)$r['data_ref']] = $r;
    }

    foreach ($eventos as $ev) {
      $id = (int)$ev['id_evento'];
      $catalogo[$id] = [
        'id'          => $id,
        'titulo'      => (string)$ev['titulo'],
        'descricao'   => (string)($ev['descricao'] ?? ''),
        'local'       => (string)($ev['local'] ?? ''),
        'hora_inicio' => $ev['hora_inicio'] ? substr((string)$ev['hora_inicio'], 0, 5) : null,
        'hora_fim'    => $ev['hora_fim'] ? substr((string)$ev['hora_fim'], 0, 5) : null,
        'recorrencia' => (string)$ev['recorrencia'],
        'data_inicio' => (string)$ev['data_inicio'],
        'data_fim'    => $ev['data_fim'] ? (string)$ev['data_fim'] : null,
        'dias_semana' => (string)($ev['dias_semana'] ?? ''),
        'regra_mensal'=> (string)($ev['regra_mensal'] ?? ''),
        'pessoas'     => $pessoasPorEvento[$id] ?? [],
      ];

      foreach (agev_expandir($ev, $de, $ate) as $data) {
        $hora = $catalogo[$id]['hora_inicio'];
        $x = $excecoes[$id][$data] ?? null;
        if ($x) {
          if ($x['acao'] === 'cancelada') {
            $ocorrencias[] = ['id_evento' => $id, 'data' => $data, 'hora' => $hora, 'cancelada' => true, 'data_ref' => $data];
            continue;
          }
          if ($x['acao'] === 'remarcada') {
            $data = $x['nova_data'] ? (string)$x['nova_data'] : $data;
            $hora = $x['nova_hora'] ? substr((string)$x['nova_hora'], 0, 5) : $hora;
          }
        }
        $ocorrencias[] = ['id_evento' => $id, 'data' => $data, 'hora' => $hora, 'cancelada' => false,
                          'data_ref' => $x ? (string)$x['data_ref'] : $data];
      }
    }

    return ['catalogo' => $catalogo, 'ocorrencias' => $ocorrencias];
  }
}
