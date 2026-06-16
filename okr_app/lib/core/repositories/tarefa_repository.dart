import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../models/models.dart';
import '../network/api_client.dart';
import 'repository.dart';

class TarefaRepository extends BaseRepository {
  TarefaRepository(super.dio);

  Future<MinhasTarefas> minhas() async {
    final res = await dio.get('/minhas-tarefas');
    final b = bodyOf(res);
    // `data` é um objeto {krs, iniciativas, totals}; fallback ao corpo (legado).
    return MinhasTarefas.fromJson(asMap(envelopeData(b) ?? b));
  }
}

final tarefaRepositoryProvider = Provider<TarefaRepository>(
  (ref) => TarefaRepository(ref.read(apiClientProvider).dio),
);
