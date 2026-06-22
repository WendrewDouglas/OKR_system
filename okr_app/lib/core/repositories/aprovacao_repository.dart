import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../models/models.dart';
import '../network/api_client.dart';
import 'repository.dart';

class AprovacaoRepository extends BaseRepository {
  AprovacaoRepository(super.dio);

  Future<AprovacoesData> list() async {
    final res = await dio.get('/aprovacoes');
    final b = bodyOf(res);
    return AprovacoesData.fromJson(asMap(envelopeData(b) ?? b));
  }

  /// Decide um item (modulo: objetivo|kr|orcamento).
  Future<void> decidir({
    required String modulo,
    required String idRef,
    required String decisao, // aprovado | reprovado
    String comentarios = '',
  }) async {
    ensureOk(await dio.post('/aprovacoes/decidir', data: {
      'modulo': modulo,
      'id_ref': idRef,
      'decisao': decisao,
      'comentarios': comentarios,
    }));
  }
}

final aprovacaoRepositoryProvider = Provider<AprovacaoRepository>(
  (ref) => AprovacaoRepository(ref.read(apiClientProvider).dio),
);
