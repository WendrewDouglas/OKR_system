import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:okr_app/core/models/models.dart';
import 'package:okr_app/core/repositories/repositories.dart';

class _FakeAdapter implements HttpClientAdapter {
  final int status;
  final Object body;
  _FakeAdapter(this.status, this.body);

  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? requestStream, Future<void>? cancelFuture) async {
    return ResponseBody.fromString(
      body is String ? body as String : jsonEncode(body),
      status,
      headers: {Headers.contentTypeHeader: [Headers.jsonContentType]},
    );
  }

  @override
  void close({bool force = false}) {}
}

Dio _dioWith(int status, Object body) =>
    Dio(BaseOptions(baseUrl: 'http://test'))..httpClientAdapter = _FakeAdapter(status, body);

void main() {
  group('Usuario DTO', () {
    test('fromJson básico', () {
      final u = Usuario.fromJson({
        'id_user': 7,
        'primeiro_nome': 'Ana',
        'ultimo_nome': 'Silva',
        'email': 'ana@x.com',
        'role_key': 'user_admin',
        'role_name': 'Admin',
        'id_company': '3',
      });
      expect(u.idUser, 7);
      expect(u.nomeCompleto, 'Ana Silva');
      expect(u.iniciais, 'AS');
      expect(u.idCompany, 3);
      expect(u.roleKey, 'user_admin');
    });
  });

  group('UsuarioRepository', () {
    test('list parseia envelope data + pagination', () async {
      final dio = _dioWith(200, {
        'ok': true,
        'data': [
          {'id_user': 1, 'primeiro_nome': 'Bia', 'email': 'b@x.com', 'role_key': 'user_colab'},
        ],
        'pagination': {'page': 1, 'per_page': 20, 'total': 1, 'pages': 1},
      });
      final paged = await UsuarioRepository(dio).list();
      expect(paged.items.single.primeiroNome, 'Bia');
      expect(paged.pagination!.total, 1);
    });

    test('get usa fallback legado (chave user)', () async {
      final dio = _dioWith(200, {
        'ok': true,
        'user': {'id_user': 9, 'primeiro_nome': 'Caio', 'email': 'c@x.com', 'role_key': 'gestor_master'},
      });
      final u = await UsuarioRepository(dio).get(9);
      expect(u.idUser, 9);
      expect(u.roleKey, 'gestor_master');
    });

    test('create sem permissão (403) lança DioException', () async {
      final dio = _dioWith(403, {'ok': false, 'message': 'Apenas administradores...'});
      expect(() => UsuarioRepository(dio).create({'primeiro_nome': 'x'}), throwsA(isA<DioException>()));
    });
  });
}
