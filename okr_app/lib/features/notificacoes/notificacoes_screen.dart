import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../core/network/api_client.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/haptics.dart';
import '../shared/widgets/loading_shimmer.dart';

final notificacoesProvider = FutureProvider.autoDispose<List<Map<String, dynamic>>>((ref) async {
  final api = ref.read(apiClientProvider);
  final res = await api.dio.get('/notificacoes', queryParameters: {'per_page': 50});
  return ((res.data['items'] as List?) ?? []).cast<Map<String, dynamic>>();
});

class NotificacoesScreen extends ConsumerWidget {
  const NotificacoesScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final notifs = ref.watch(notificacoesProvider);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Notificações'),
        actions: [
          TextButton(
            onPressed: () async {
              AppHaptics.medium();
              final api = ref.read(apiClientProvider);
              await api.dio.put('/notificacoes/todas/lida');
              ref.invalidate(notificacoesProvider);
            },
            child: const Text('Marcar todas', style: TextStyle(color: AppColors.gold, fontSize: 13)),
          ),
        ],
      ),
      body: notifs.when(
        loading: () => const LoadingShimmer(),
        error: (e, _) => Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.error_outline, color: AppColors.red, size: 48),
              const SizedBox(height: 12),
              const Text('Erro ao carregar notificações', style: TextStyle(color: AppColors.red)),
              const SizedBox(height: 8),
              TextButton.icon(
                icon: const Icon(Icons.refresh, size: 18),
                label: const Text('Tentar novamente'),
                onPressed: () {
                  AppHaptics.light();
                  ref.invalidate(notificacoesProvider);
                },
              ),
            ],
          ),
        ),
        data: (items) => items.isEmpty
            ? const Center(child: Text('Nenhuma notificação', style: TextStyle(color: AppColors.textMuted)))
            : RefreshIndicator(
                color: AppColors.gold,
                backgroundColor: AppColors.bgCard,
                onRefresh: () async {
                  AppHaptics.medium();
                  ref.invalidate(notificacoesProvider);
                },
                child: ListView.separated(
                  itemCount: items.length,
                  separatorBuilder: (_, __) => const Divider(height: 1),
                  itemBuilder: (ctx, i) {
                    final n = items[i];
                    final lida = n['lida'] == true;
                    return ListTile(
                      leading: Icon(
                        lida ? Icons.notifications_none : Icons.notifications,
                        color: lida ? AppColors.textMuted : AppColors.gold,
                      ),
                      title: Text(n['titulo'] ?? '', style: TextStyle(fontWeight: lida ? FontWeight.normal : FontWeight.w600, fontSize: 14)),
                      subtitle: Text(n['mensagem'] ?? '', maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 12)),
                      trailing: Text(n['dt_criado'] ?? '', style: const TextStyle(fontSize: 10, color: AppColors.textMuted)),
                      onTap: () async {
                        AppHaptics.light();
                        if (!lida) {
                          final api = ref.read(apiClientProvider);
                          await api.dio.put('/notificacoes/${n['id_notificacao']}/lida');
                          ref.invalidate(notificacoesProvider);
                        }
                      },
                    );
                  },
                ),
              ),
      ),
    );
  }
}
