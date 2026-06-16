import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../models/models.dart';
import '../network/api_client.dart';
import 'repository.dart';

class UsuarioRepository extends BaseRepository {
  UsuarioRepository(super.dio);

  Future<Paged<Usuario>> list({int page = 1, int perPage = 20, String? q}) async {
    final res = await dio.get('/usuarios', queryParameters: {
      'page': page,
      'per_page': perPage,
      if (q != null && q.isNotEmpty) 'q': q,
    });
    final b = bodyOf(res);
    final items = asModelList(envelopeData(b, legacyKeys: ['items']), Usuario.fromJson);
    return Paged(items, envelopePagination(b));
  }

  Future<Usuario> get(int id) async {
    final res = await dio.get('/usuarios/$id');
    final data = envelopeData(bodyOf(res), legacyKeys: ['user']);
    return Usuario.fromJson(asMap(data));
  }

  Future<void> create(Map<String, dynamic> payload) async {
    ensureOk(await dio.post('/usuarios', data: payload));
  }

  Future<void> update(int id, Map<String, dynamic> payload) async {
    ensureOk(await dio.put('/usuarios/$id', data: payload));
  }

  Future<void> setRole(int id, String roleKey) async {
    ensureOk(await dio.put('/usuarios/$id/role', data: {'role_key': roleKey}));
  }

  Future<void> delete(int id) async {
    ensureOk(await dio.delete('/usuarios/$id'));
  }
}

final usuarioRepositoryProvider = Provider<UsuarioRepository>(
  (ref) => UsuarioRepository(ref.read(apiClientProvider).dio),
);
