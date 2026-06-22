import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:okr_app/core/repositories/repositories.dart';

/// Adapter que devolve uma resposta canônica para qualquer request (MOB-02).
class _FakeAdapter implements HttpClientAdapter {
  final int status;
  final Object body;
  _FakeAdapter(this.status, this.body);

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    return ResponseBody.fromString(
      body is String ? body as String : jsonEncode(body),
      status,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

Dio _dioWith(int status, Object body) =>
    Dio(BaseOptions(baseUrl: 'http://test'))..httpClientAdapter = _FakeAdapter(status, body);

void main() {
  test('ObjetivoRepository.list parseia data + pagination', () async {
    final dio = _dioWith(200, {
      'ok': true,
      'data': [
        {
          'id_objetivo': 'O1',
          'descricao': 'A',
          'status': 'Em Andamento',
          'status_aprovacao': 'aprovado',
          'qtd_krs': '2',
        },
      ],
      'pagination': {'page': 1, 'per_page': 20, 'total': 1, 'pages': 1},
    });
    final paged = await ObjetivoRepository(dio).list();
    expect(paged.items.single.idObjetivo, 'O1');
    expect(paged.items.single.qtdKrs, 2);
    expect(paged.pagination!.total, 1);
  });

  test('KrRepository.listByObjetivo usa fallback legado (sem data)', () async {
    final dio = _dioWith(200, {
      'ok': true,
      'krs': [
        {
          'id_kr': 'KR1',
          'key_result_num': 1,
          'descricao': 'R',
          'status': 'Em Andamento',
          'status_aprovacao': 'aprovado',
          'baseline': '0',
          'meta': '10',
        },
      ],
    });
    final krs = await KrRepository(dio).listByObjetivo('O1');
    expect(krs.single.idKr, 'KR1');
    expect(krs.single.meta, 10);
  });

  test('TarefaRepository.minhas parseia objeto data', () async {
    final dio = _dioWith(200, {
      'ok': true,
      'data': {
        'krs': [
          {'id_kr': 'KR1', 'descricao': 'x', 'status': 'Em Andamento', 'progresso_pct': '30'},
        ],
        'iniciativas': [],
        'totals': {'krs': 1, 'iniciativas': 0},
      },
    });
    final t = await TarefaRepository(dio).minhas();
    expect(t.krs.single.progressoPct, 30);
  });

  test('AprovacaoRepository.list parseia stats', () async {
    final dio = _dioWith(200, {
      'ok': true,
      'data': {
        'stats': {'pendentes': 2, 'reprovados': 0},
        'para_aprovar': [],
        'minhas_pendentes': [],
      },
    });
    final a = await AprovacaoRepository(dio).list();
    expect(a.pendentes, 2);
  });

  test('erro HTTP 500 lança DioException', () async {
    final dio = _dioWith(500, {'ok': false, 'error': 'E_SERVER', 'message': 'boom'});
    expect(() => ObjetivoRepository(dio).list(), throwsA(isA<DioException>()));
  });

  test('2xx com ok:false lança ApiException', () async {
    final dio = _dioWith(200, {'ok': false, 'message': 'Sem permissão.'});
    expect(() => ObjetivoRepository(dio).create({'descricao': 'x'}), throwsA(isA<ApiException>()));
  });
}
