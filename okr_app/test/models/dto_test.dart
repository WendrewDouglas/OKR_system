import 'package:flutter_test/flutter_test.dart';
import 'package:okr_app/core/models/models.dart';

void main() {
  group('envelope', () {
    test('envelopeData lê data e cai para chave legada', () {
      expect(envelopeData({'data': 42}), 42);
      expect(envelopeData({'krs': [1, 2]}, legacyKeys: ['krs']), [1, 2]);
      expect(envelopeData({'outro': 1}, legacyKeys: ['krs']), isNull);
    });

    test('envelopePagination lê pagination ou chaves legadas', () {
      final p1 = envelopePagination({'pagination': {'page': 2, 'per_page': 20, 'total': 41, 'pages': 3}})!;
      expect(p1.page, 2);
      expect(p1.pages, 3);
      expect(p1.hasMore, isTrue);
      final p2 = envelopePagination({'page': 3, 'total': 41, 'pages': 3})!;
      expect(p2.page, 3);
      expect(p2.hasMore, isFalse);
      expect(envelopePagination({'data': []}), isNull);
    });
  });

  group('json_utils', () {
    test('números vindos como string', () {
      expect(asInt('7'), 7);
      expect(asDouble('3,5'), 3.5); // vírgula decimal
      expect(asDoubleOrNull(null), isNull);
      expect(asBool('1'), isTrue);
      expect(asBool(0), isFalse);
    });

    test('datas em formatos do backend', () {
      expect(asDateOrNull('2026-06-16')!.year, 2026);
      expect(asDateOrNull('2026-06-16 14:30:00')!.hour, 14);
      expect(asDateOrNull(''), isNull);
    });
  });

  group('Objetivo', () {
    test('fromJson com dono e qtd_krs string', () {
      final o = Objetivo.fromJson({
        'id_objetivo': 'OBJ1',
        'descricao': 'Crescer',
        'status': 'Em Andamento',
        'status_aprovacao': 'aprovado',
        'pilar_bsc': 'financeiro',
        'qtd_krs': '3',
        'dt_prazo': '2026-12-31',
        'dono': {'id_user': 10, 'nome': 'Ana'},
      });
      expect(o.idObjetivo, 'OBJ1');
      expect(o.qtdKrs, 3);
      expect(o.dono!.nome, 'Ana');
      expect(o.dtPrazo!.month, 12);
    });

    test('fromJson sem dono não quebra', () {
      final o = Objetivo.fromJson({'id_objetivo': 'X', 'descricao': '', 'status': '', 'status_aprovacao': ''});
      expect(o.dono, isNull);
      expect(o.qtdKrs, 0);
    });
  });

  group('KeyResult', () {
    test('fromJson com baseline/meta string e progress', () {
      final kr = KeyResult.fromJson({
        'id_kr': 'KR1',
        'key_result_num': 1,
        'descricao': 'Receita',
        'status': 'Em Andamento',
        'status_aprovacao': 'aprovado',
        'baseline': '0.00',
        'meta': '100.00',
        'progress': {'valor_atual': '50', 'pct_atual': '50.0'},
      });
      expect(kr.baseline, 0);
      expect(kr.meta, 100);
      expect(kr.progress.valorAtual, 50);
      expect(kr.progress.pctAtual, 50.0);
    });
  });

  group('Iniciativa', () {
    test('fromJson com envolvidos e orcamento', () {
      final i = Iniciativa.fromJson({
        'id_iniciativa': 'INI1',
        'num_iniciativa': 2,
        'descricao': 'Campanha',
        'status': 'Não Iniciado',
        'envolvidos': [
          {'id_user': 1, 'nome': 'Bia'},
          {'id_user': 2, 'nome': 'Caio'},
        ],
        'orcamento': {'aprovado': '1000', 'realizado': '250', 'saldo': '750'},
      });
      expect(i.envolvidos.length, 2);
      expect(i.envolvidos.first.nome, 'Bia');
      expect(i.orcamento!.saldo, 750);
    });

    test('fromJson sem orcamento/envolvidos', () {
      final i = Iniciativa.fromJson({'id_iniciativa': 'X', 'num_iniciativa': 1, 'descricao': '', 'status': ''});
      expect(i.envolvidos, isEmpty);
      expect(i.orcamento, isNull);
    });
  });

  group('Apontamento', () {
    test('fromJson com dt_evidencia', () {
      final a = Apontamento.fromJson({
        'id_apontamento': 5,
        'id_milestone': 9,
        'dt_evidencia': '2026-06-01',
        'valor_real': '12.5',
        'usuario_id': '10',
      });
      expect(a.idApontamento, 5);
      expect(a.dtEvidencia!.month, 6);
      expect(a.valorReal, 12.5);
    });
  });

  group('MinhasTarefas', () {
    test('fromJson com krs e iniciativas', () {
      final t = MinhasTarefas.fromJson({
        'krs': [
          {'id_kr': 'KR1', 'descricao': 'KR', 'status': 'Em Andamento', 'progresso_pct': '40'},
        ],
        'iniciativas': [
          {'id_iniciativa': 'INI1', 'descricao': 'I', 'status': 'Não Iniciado'},
        ],
      });
      expect(t.krs.single.progressoPct, 40);
      expect(t.iniciativas.single.idIniciativa, 'INI1');
    });
  });

  group('AprovacoesData', () {
    test('fromJson com stats e listas', () {
      final ap = AprovacoesData.fromJson({
        'stats': {'pendentes': 2, 'reprovados': 1},
        'para_aprovar': [
          {'modulo': 'kr', 'id_ref': 'KR1', 'descricao': 'X', 'status_aprovacao': 'pendente', 'criador': 'Ana'},
        ],
        'minhas_pendentes': [],
      });
      expect(ap.pendentes, 2);
      expect(ap.paraAprovar.single.modulo, 'kr');
      expect(ap.minhasPendentes, isEmpty);
    });
  });
}
