import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../network/api_client.dart';

/// Generic domain table provider — caches dropdown data.
final domainProvider = FutureProvider.autoDispose
    .family<List<Map<String, dynamic>>, String>((ref, tabela) async {
  final api = ref.read(apiClientProvider);
  final res = await api.dio.get('/dominios/$tabela');
  return ((res.data['items'] as List?) ?? []).cast<Map<String, dynamic>>();
});

/// Company users for picker (responsáveis).
final responsaveisProvider =
    FutureProvider.autoDispose<List<Map<String, dynamic>>>((ref) async {
  final api = ref.read(apiClientProvider);
  final res = await api.dio.get('/responsaveis');
  return ((res.data['items'] as List?) ?? []).cast<Map<String, dynamic>>();
});
