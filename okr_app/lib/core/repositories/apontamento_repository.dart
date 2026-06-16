import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../models/models.dart';
import '../network/api_client.dart';
import 'repository.dart';

class ApontamentoRepository extends BaseRepository {
  ApontamentoRepository(super.dio);

  Future<List<Apontamento>> listByKr(String idKr) async {
    final res = await dio.get('/krs/$idKr/apontamentos');
    return asModelList(
      envelopeData(bodyOf(res), legacyKeys: ['apontamentos']),
      Apontamento.fromJson,
    );
  }

  /// Cria apontamentos em lote para um KR.
  Future<void> create(String idKr, List<Map<String, dynamic>> items) async {
    ensureOk(await dio.post('/krs/$idKr/apontamentos', data: {'items': items}));
  }

  Future<void> delete(int idApontamento) async {
    ensureOk(await dio.delete('/apontamentos/$idApontamento'));
  }
}

final apontamentoRepositoryProvider = Provider<ApontamentoRepository>(
  (ref) => ApontamentoRepository(ref.read(apiClientProvider).dio),
);
