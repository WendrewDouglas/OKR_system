import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../models/models.dart';
import '../network/api_client.dart';
import 'repository.dart';

class ObjetivoRepository extends BaseRepository {
  ObjetivoRepository(super.dio);

  Future<Paged<Objetivo>> list({
    int page = 1,
    int perPage = 20,
    String? scope,
    String? status,
    String? pilarBsc,
    String? search,
  }) async {
    final res = await dio.get('/objetivos', queryParameters: {
      'page': page,
      'per_page': perPage,
      if (scope != null && scope.isNotEmpty) 'scope': scope,
      if (status != null && status.isNotEmpty) 'status': status,
      if (pilarBsc != null && pilarBsc.isNotEmpty) 'pilar_bsc': pilarBsc,
      if (search != null && search.isNotEmpty) 'q': search,
    });
    final b = bodyOf(res);
    final items = asModelList(envelopeData(b, legacyKeys: ['items']), Objetivo.fromJson);
    return Paged(items, envelopePagination(b));
  }

  Future<void> create(Map<String, dynamic> payload) async {
    ensureOk(await dio.post('/objetivos', data: payload));
  }

  Future<void> update(String id, Map<String, dynamic> payload) async {
    ensureOk(await dio.put('/objetivos/$id', data: payload));
  }

  Future<void> delete(String id) async {
    ensureOk(await dio.delete('/objetivos/$id'));
  }
}

final objetivoRepositoryProvider = Provider<ObjetivoRepository>(
  (ref) => ObjetivoRepository(ref.read(apiClientProvider).dio),
);
