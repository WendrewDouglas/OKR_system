import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../core/network/api_client.dart';
import '../../core/theme/app_theme.dart';
import '../shared/widgets/loading_shimmer.dart';
import '../shared/widgets/empty_state.dart';
import '../shared/widgets/app_header.dart';

final okrListProvider = FutureProvider.autoDispose<List<Map<String, dynamic>>>((ref) async {
  final api = ref.read(apiClientProvider);
  final res = await api.dio.get('/objetivos', queryParameters: {'per_page': 50});
  return ((res.data['items'] as List?) ?? []).cast<Map<String, dynamic>>();
});

class OkrListScreen extends ConsumerWidget {
  const OkrListScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final okrs = ref.watch(okrListProvider);

    return Scaffold(
      appBar: const AppHeader(),
      body: okrs.when(
        loading: () => const LoadingShimmer(),
        error: (e, _) => Center(child: Text('Erro: $e')),
        data: (items) => RefreshIndicator(
          onRefresh: () async => ref.invalidate(okrListProvider),
          child: items.isEmpty
              ? EmptyState(
                  icon: Icons.flag_outlined,
                  title: 'Nenhum objetivo',
                  subtitle: 'Crie seu primeiro objetivo OKR.',
                  action: ElevatedButton.icon(
                    icon: const Icon(Icons.add, size: 18),
                    label: const Text('Novo Objetivo'),
                    onPressed: () async {
                      final result = await context.push('/okrs/novo');
                      if (result == true) ref.invalidate(okrListProvider);
                    },
                  ),
                )
              : ListView.builder(
                  padding: const EdgeInsets.all(16),
                  itemCount: items.length,
                  itemBuilder: (ctx, i) => _OkrCard(obj: items[i]),
                ),
        ),
      ),
      floatingActionButton: FloatingActionButton(
        backgroundColor: AppColors.gold,
        foregroundColor: AppColors.bgSoft,
        onPressed: () async {
          final result = await context.push('/okrs/novo');
          if (result == true) ref.invalidate(okrListProvider);
        },
        child: const Icon(Icons.add),
      ),
    );
  }
}

class _OkrCard extends StatelessWidget {
  final Map<String, dynamic> obj;
  const _OkrCard({required this.obj});

  @override
  Widget build(BuildContext context) {
    final desc = obj['descricao'] ?? '';
    final status = obj['status'] ?? '';
    final pilar = obj['pilar_bsc'] ?? '';
    final qtdKrs = obj['qtd_krs'] ?? 0;
    final dono = (obj['dono'] as Map<String, dynamic>?)?['nome'] ?? '';

    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: InkWell(
        borderRadius: BorderRadius.circular(16),
        onTap: () => context.push('/okrs/${obj['id_objetivo']}'),
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                    decoration: BoxDecoration(
                      color: AppColors.gold.withValues(alpha: 0.15),
                      borderRadius: BorderRadius.circular(6),
                    ),
                    child: Text(pilar, style: const TextStyle(color: AppColors.gold, fontSize: 11, fontWeight: FontWeight.w600)),
                  ),
                  const Spacer(),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                    decoration: BoxDecoration(
                      color: AppColors.bgSurface,
                      borderRadius: BorderRadius.circular(6),
                    ),
                    child: Text(status, style: const TextStyle(fontSize: 11, color: AppColors.textMuted)),
                  ),
                ],
              ),
              const SizedBox(height: 10),
              Text(desc, style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14), maxLines: 2, overflow: TextOverflow.ellipsis),
              const SizedBox(height: 8),
              Row(
                children: [
                  const Icon(Icons.person_outline, size: 14, color: AppColors.textMuted),
                  const SizedBox(width: 4),
                  Text(dono, style: const TextStyle(color: AppColors.textMuted, fontSize: 12)),
                  const Spacer(),
                  const Icon(Icons.track_changes, size: 14, color: AppColors.textMuted),
                  const SizedBox(width: 4),
                  Text('$qtdKrs KRs', style: const TextStyle(color: AppColors.textMuted, fontSize: 12)),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}
