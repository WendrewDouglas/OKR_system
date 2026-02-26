import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../core/network/api_client.dart';
import '../../core/theme/app_theme.dart';
import '../shared/widgets/loading_shimmer.dart';
import '../shared/widgets/empty_state.dart';
import '../shared/widgets/status_badge.dart';
import '../shared/widgets/app_header.dart';

final _minhasTarefasProvider = FutureProvider.autoDispose<Map<String, dynamic>>((ref) async {
  final api = ref.read(apiClientProvider);
  final res = await api.dio.get('/minhas-tarefas');
  return res.data as Map<String, dynamic>;
});

class MinhasTarefasScreen extends ConsumerWidget {
  const MinhasTarefasScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final tarefas = ref.watch(_minhasTarefasProvider);

    return Scaffold(
      appBar: const AppHeader(),
      body: tarefas.when(
        loading: () => const LoadingShimmer(),
        error: (e, _) => Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.error_outline, color: AppColors.red, size: 48),
              const SizedBox(height: 12),
              Text('Erro ao carregar tarefas', style: const TextStyle(color: AppColors.red, fontSize: 16)),
              const SizedBox(height: 8),
              TextButton(
                onPressed: () => ref.invalidate(_minhasTarefasProvider),
                child: const Text('Tentar novamente'),
              ),
            ],
          ),
        ),
        data: (data) {
          final iniciativas = (data['iniciativas'] as List?)?.cast<Map<String, dynamic>>() ?? [];
          final krs = (data['krs'] as List?)?.cast<Map<String, dynamic>>() ?? [];
          final hasData = iniciativas.isNotEmpty || krs.isNotEmpty;

          if (!hasData) {
            return const EmptyState(
              icon: Icons.task_alt,
              title: 'Nenhuma tarefa',
              subtitle: 'Você não tem iniciativas ou KRs atribuídos.',
            );
          }

          return RefreshIndicator(
            onRefresh: () async => ref.invalidate(_minhasTarefasProvider),
            child: DefaultTabController(
              length: 2,
              child: Column(
                children: [
                  Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 16),
                    child: TabBar(
                      labelColor: AppColors.gold,
                      unselectedLabelColor: AppColors.textMuted,
                      indicatorColor: AppColors.gold,
                      tabs: [
                        Tab(text: 'Iniciativas (${iniciativas.length})'),
                        Tab(text: 'Key Results (${krs.length})'),
                      ],
                    ),
                  ),
                  Expanded(
                    child: TabBarView(
                      children: [
                        _IniciativasList(iniciativas: iniciativas),
                        _KrsList(krs: krs),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          );
        },
      ),
    );
  }
}

class _IniciativasList extends StatelessWidget {
  final List<Map<String, dynamic>> iniciativas;
  const _IniciativasList({required this.iniciativas});

  @override
  Widget build(BuildContext context) {
    if (iniciativas.isEmpty) {
      return const Center(child: Text('Nenhuma iniciativa', style: TextStyle(color: AppColors.textMuted)));
    }
    return ListView.builder(
      padding: const EdgeInsets.all(16),
      itemCount: iniciativas.length,
      itemBuilder: (_, i) {
        final ini = iniciativas[i];
        final status = ini['status'] ?? '';
        final descricao = ini['descricao'] ?? '';
        final prazo = ini['dt_prazo'] as String?;
        final krDesc = ini['kr_descricao'] as String?;

        return Card(
          margin: const EdgeInsets.only(bottom: 10),
          child: ListTile(
            title: Text(descricao, maxLines: 2, overflow: TextOverflow.ellipsis,
                style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w600)),
            subtitle: Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  if (krDesc != null && krDesc.isNotEmpty)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 6),
                      child: Text('KR: $krDesc', maxLines: 1, overflow: TextOverflow.ellipsis,
                          style: const TextStyle(fontSize: 11, color: AppColors.textMuted)),
                    ),
                  Row(
                    children: [
                      StatusBadge(label: status),
                      if (prazo != null) ...[
                        const SizedBox(width: 8),
                        const Icon(Icons.calendar_today, size: 12, color: AppColors.textMuted),
                        const SizedBox(width: 4),
                        Text(prazo, style: const TextStyle(fontSize: 11, color: AppColors.textMuted)),
                      ],
                    ],
                  ),
                ],
              ),
            ),
            trailing: const Icon(Icons.chevron_right, color: AppColors.textMuted, size: 20),
            onTap: () => context.push('/iniciativas/${ini['id_iniciativa']}'),
          ),
        );
      },
    );
  }
}

class _KrsList extends StatelessWidget {
  final List<Map<String, dynamic>> krs;
  const _KrsList({required this.krs});

  @override
  Widget build(BuildContext context) {
    if (krs.isEmpty) {
      return const Center(child: Text('Nenhum KR atribuído', style: TextStyle(color: AppColors.textMuted)));
    }
    return ListView.builder(
      padding: const EdgeInsets.all(16),
      itemCount: krs.length,
      itemBuilder: (_, i) {
        final kr = krs[i];
        final descricao = kr['descricao'] ?? '';
        final pct = (kr['progresso_pct'] as num?)?.toDouble() ?? 0;
        final farol = kr['farol'] ?? '';
        final objDesc = kr['objetivo_descricao'] as String?;

        return Card(
          margin: const EdgeInsets.only(bottom: 10),
          child: ListTile(
            leading: _farolDot(farol),
            title: Text(descricao, maxLines: 2, overflow: TextOverflow.ellipsis,
                style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w600)),
            subtitle: Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  if (objDesc != null && objDesc.isNotEmpty)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 6),
                      child: Text('Obj: $objDesc', maxLines: 1, overflow: TextOverflow.ellipsis,
                          style: const TextStyle(fontSize: 11, color: AppColors.textMuted)),
                    ),
                  Row(
                    children: [
                      Expanded(
                        child: ClipRRect(
                          borderRadius: BorderRadius.circular(3),
                          child: LinearProgressIndicator(
                            value: pct / 100,
                            backgroundColor: AppColors.border,
                            valueColor: AlwaysStoppedAnimation(pct >= 70 ? AppColors.green : pct >= 40 ? AppColors.warn : AppColors.red),
                            minHeight: 5,
                          ),
                        ),
                      ),
                      const SizedBox(width: 8),
                      Text('${pct.toStringAsFixed(0)}%', style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700)),
                    ],
                  ),
                ],
              ),
            ),
            trailing: const Icon(Icons.chevron_right, color: AppColors.textMuted, size: 20),
            onTap: () => context.push('/krs/${kr['id_kr']}'),
          ),
        );
      },
    );
  }

  Widget _farolDot(String farol) {
    final color = switch (farol.toLowerCase()) {
      'verde' => AppColors.green,
      'amarelo' => AppColors.warn,
      'vermelho' => AppColors.red,
      _ => AppColors.textMuted,
    };
    return Container(
      width: 12, height: 12,
      decoration: BoxDecoration(shape: BoxShape.circle, color: color),
    );
  }
}
