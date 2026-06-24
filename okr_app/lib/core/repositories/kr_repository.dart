import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../models/models.dart';
import '../network/api_client.dart';
import 'repository.dart';

class KrRepository extends BaseRepository {
  KrRepository(super.dio);

  Future<List<KeyResult>> listByObjetivo(String idObjetivo) async {
    final res = await dio.get('/objetivos/$idObjetivo/krs');
    return asModelList(envelopeData(bodyOf(res), legacyKeys: ['krs']), KeyResult.fromJson);
  }

  Future<void> create(Map<String, dynamic> payload) async {
    ensureOk(await dio.post('/krs', data: payload));
  }

  Future<void> update(String id, Map<String, dynamic> payload) async {
    ensureOk(await dio.put('/krs/$id', data: payload));
  }

  Future<void> cancel(String id, String justificativa) async {
    ensureOk(await dio.post('/krs/$id/cancelar', data: {'justificativa': justificativa}));
  }

  Future<void> reactivate(String id) async {
    ensureOk(await dio.post('/krs/$id/reativar'));
  }

  /// Altera o status do KR (id_status: 'em andamento' | 'pausado' | 'concluido' |
  /// 'cancelado' | 'nao iniciado'). Justificativa obrigatória p/ cancelar/pausar/concluir.
  Future<void> updateStatus(String id, String status, {String? justificativa}) async {
    ensureOk(await dio.put('/krs/$id/status', data: {
      'status': status,
      if (justificativa != null && justificativa.trim().isNotEmpty)
        'justificativa': justificativa.trim(),
    }));
  }

  Future<void> delete(String id) async {
    ensureOk(await dio.delete('/krs/$id'));
  }
}

final krRepositoryProvider = Provider<KrRepository>(
  (ref) => KrRepository(ref.read(apiClientProvider).dio),
);
