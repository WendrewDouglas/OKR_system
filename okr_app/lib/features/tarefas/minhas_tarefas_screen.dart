import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../core/models/models.dart';
import '../../core/repositories/repositories.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/haptics.dart';
import '../../core/utils/animations.dart';
import '../shared/widgets/loading_shimmer.dart';
import '../shared/widgets/empty_state.dart';
import '../shared/widgets/error_retry.dart';
import '../shared/widgets/status_badge.dart';
import '../shared/widgets/app_header.dart';

/// Consome a camada tipada: TarefaRepository → DTO MinhasTarefas.
final _minhasTarefasProvider = FutureProvider.autoDispose<MinhasTarefas>(
  (ref) => ref.read(tarefaRepositoryProvider).minhas(),
);

String _fmtDate(DateTime? d) =>
    d == null ? '' : '${d.day.toString().padLeft(2, '0')}/${d.month.toString().padLeft(2, '0')}/${d.year}';

class MinhasTarefasScreen extends ConsumerWidget {
  const MinhasTarefasScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final tarefas = ref.watch(_minhasTarefasProvider);

    return Scaffold(
      appBar: const AppHeader(),
      body: tarefas.when(
        loading: () => const LoadingShimmer(),
        error: (e, _) => ErrorRetry(
          message: 'Erro ao carregar tarefas',
          onRetry: () => ref.invalidate(_minhasTarefasProvider),
        ),
        data: (data) {
          final iniciativas = data.iniciativas;
          final krs = data.krs;
          final hasData = iniciativas.isNotEmpty || krs.isNotEmpty;

          if (!hasData) {
            return const EmptyState(
              icon: Icons.task_alt,
              title: 'Nenhuma tarefa',
              subtitle: 'Você não tem iniciativas ou KRs atribuídos.',
            );
          }

          return RefreshIndicator(
            color: AppColors.gold,
            backgroundColor: AppColors.bgCard,
            onRefresh: () async {
              AppHaptics.medium();
              ref.invalidate(_minhasTarefasProvider);
            },
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
  final List<TarefaIniciativa> iniciativas;
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
        final prazo = _fmtDate(ini.dtPrazo);

        return StaggeredFadeSlide(
          index: i,
          child: Card(
            margin: const EdgeInsets.only(bottom: 10),
            child: ListTile(
              title: Text(ini.descricao, maxLines: 2, overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w600)),
              subtitle: Padding(
                padding: const EdgeInsets.only(top: 6),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    if (ini.krDescricao.isNotEmpty)
                      Padding(
                        padding: const EdgeInsets.only(bottom: 6),
                        child: Text('KR: ${ini.krDescricao}', maxLines: 1, overflow: TextOverflow.ellipsis,
                            style: const TextStyle(fontSize: 11, color: AppColors.textMuted)),
                      ),
                    Row(
                      children: [
                        StatusBadge(label: ini.status),
                        if (prazo.isNotEmpty) ...[
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
              onTap: () {
                AppHaptics.light();
                context.push('/iniciativas/${ini.idIniciativa}');
              },
            ),
          ),
        );
      },
    );
  }
}

class _KrsList extends StatelessWidget {
  final List<TarefaKr> krs;
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
        final pct = kr.progressoPct;

        return StaggeredFadeSlide(
          index: i,
          child: Card(
            margin: const EdgeInsets.only(bottom: 10),
            child: ListTile(
              leading: _farolDot(kr.farol),
              title: Text(kr.descricao, maxLines: 2, overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w600)),
              subtitle: Padding(
                padding: const EdgeInsets.only(top: 6),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    if (kr.objetivoDescricao.isNotEmpty)
                      Padding(
                        padding: const EdgeInsets.only(bottom: 6),
                        child: Text('Obj: ${kr.objetivoDescricao}', maxLines: 1, overflow: TextOverflow.ellipsis,
                            style: const TextStyle(fontSize: 11, color: AppColors.textMuted)),
                      ),
                    Row(
                      children: [
                        Expanded(
                          child: ClipRRect(
                            borderRadius: BorderRadius.circular(3),
                            child: LinearProgressIndicator(
                              value: pct / 100,
                              backgroundColor: AppColors.borderDefault,
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
              onTap: () {
                AppHaptics.light();
                context.push('/krs/${kr.idKr}');
              },
            ),
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
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        color: color,
        boxShadow: [BoxShadow(color: color.withValues(alpha: 0.4), blurRadius: 4)],
      ),
    );
  }
}
