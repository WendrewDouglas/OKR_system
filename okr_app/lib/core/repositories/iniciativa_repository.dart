import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../models/models.dart';
import '../network/api_client.dart';
import 'repository.dart';

class IniciativaRepository extends BaseRepository {
  IniciativaRepository(super.dio);

  Future<List<Iniciativa>> listByKr(String idKr) async {
    final res = await dio.get('/krs/$idKr/iniciativas');
    return asModelList(envelopeData(bodyOf(res), legacyKeys: ['iniciativas']), Iniciativa.fromJson);
  }

  Future<void> create(Map<String, dynamic> payload) async {
    ensureOk(await dio.post('/iniciativas', data: payload));
  }

  Future<void> update(String id, Map<String, dynamic> payload) async {
    ensureOk(await dio.put('/iniciativas/$id', data: payload));
  }

  Future<void> updateStatus(String id, String status, String observacao) async {
    ensureOk(await dio.put('/iniciativas/$id/status', data: {
      'status': status,
      'observacao': observacao,
    }));
  }

  Future<void> delete(String id) async {
    ensureOk(await dio.delete('/iniciativas/$id'));
  }
}

final iniciativaRepositoryProvider = Provider<IniciativaRepository>(
  (ref) => IniciativaRepository(ref.read(apiClientProvider).dio),
);
