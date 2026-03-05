import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../core/network/api_client.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/haptics.dart';
import '../shared/widgets/loading_shimmer.dart';
import '../shared/widgets/status_badge.dart';
import '../shared/widgets/farol_indicator.dart';
import '../shared/widgets/confirm_dialog.dart';

final okrDetailProvider = FutureProvider.autoDispose
    .family<Map<String, dynamic>, String>((ref, id) async {
  final api = ref.read(apiClientProvider);
  final res = await api.dio.get('/objetivos/$id');
  return res.data as Map<String, dynamic>;
});

final krsForObjProvider = FutureProvider.autoDispose
    .family<List<Map<String, dynamic>>, String>((ref, idObj) async {
  final api = ref.read(apiClientProvider);
  final res = await api.dio.get('/objetivos/$idObj/krs');
  return ((res.data['krs'] as List?) ?? []).cast<Map<String, dynamic>>();
});

class OkrDetailScreen extends ConsumerWidget {
  final String idObjetivo;
  const OkrDetailScreen({super.key, required this.idObjetivo});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final detail = ref.watch(okrDetailProvider(idObjetivo));
    final krs = ref.watch(krsForObjProvider(idObjetivo));

    return Scaffold(
      appBar: AppBar(
        title: const Text('Detalhe OKR'),
        actions: [
          IconButton(
            icon: const Icon(Icons.edit_outlined),
            onPressed: () async {
              AppHaptics.light();
              final result = await context.push('/okrs/$idObjetivo/editar');
              if (result == true) {
                ref.invalidate(okrDetailProvider(idObjetivo));
              }
            },
          ),
          IconButton(
            icon: const Icon(Icons.delete_outline, color: AppColors.red),
            onPressed: () {
              AppHaptics.heavy();
              _deleteObj(context, ref);
            },
          ),
        ],
      ),
      body: detail.when(
        loading: () => const LoadingShimmer(),
        error: (e, _) => Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.error_outline, color: AppColors.red, size: 48),
              const SizedBox(height: 12),
              const Text('Erro ao carregar objetivo', style: TextStyle(color: AppColors.red)),
              const SizedBox(height: 8),
              TextButton.icon(
                icon: const Icon(Icons.refresh, size: 18),
                label: const Text('Tentar novamente'),
                onPressed: () {
                  AppHaptics.light();
                  ref.invalidate(okrDetailProvider(idObjetivo));
                },
              ),
            ],
          ),
        ),
        data: (data) {
          final obj = data['objetivo'] as Map<String, dynamic>? ?? {};
          return RefreshIndicator(
            color: AppColors.gold,
            backgroundColor: AppColors.bgCard,
            onRefresh: () async {
              AppHaptics.medium();
              ref.invalidate(okrDetailProvider(idObjetivo));
              ref.invalidate(krsForObjProvider(idObjetivo));
            },
            child: ListView(
              padding: const EdgeInsets.all(16),
              children: [
                // Objective info card with gold top border
                Container(
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(16),
                    border: Border.all(color: AppColors.borderDefault, width: 0.5),
                    boxShadow: AppShadows.cardRest,
                  ),
                  child: Column(
                    children: [
                      // Gold gradient top accent
                      Container(
                        height: 3,
                        decoration: BoxDecoration(
                          gradient: AppColors.goldGradient,
                          borderRadius: const BorderRadius.vertical(top: Radius.circular(16)),
                        ),
                      ),
                      Container(
                        width: double.infinity,
                        padding: const EdgeInsets.all(16),
                        decoration: BoxDecoration(
                          color: AppColors.bgCard,
                          borderRadius: const BorderRadius.vertical(bottom: Radius.circular(16)),
                        ),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(obj['descricao'] ?? '', style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 17)),
                            const SizedBox(height: 12),
                            Wrap(spacing: 8, runSpacing: 6, children: [
                              if (obj['pilar_nome'] != null) StatusBadge(label: obj['pilar_nome'], color: AppColors.gold),
                              StatusBadge(label: obj['status'] ?? ''),
                              if (obj['qualidade'] != null && obj['qualidade'] != '') StatusBadge(label: obj['qualidade']),
                              if (obj['tipo_ciclo'] != null && obj['tipo_ciclo'] != '') StatusBadge(label: obj['tipo_ciclo'], color: AppColors.accent),
                            ]),
                            const SizedBox(height: 12),
                            if (obj['dono'] is Map) ...[
                              Row(children: [
                                const Icon(Icons.person_outline, size: 14, color: AppColors.textMuted),
                                const SizedBox(width: 4),
                                Text('Dono: ${(obj['dono'] as Map)['nome'] ?? ''}', style: const TextStyle(color: AppColors.textMuted, fontSize: 13)),
                              ]),
                            ],
                            if (obj['dt_inicio'] != null || obj['dt_prazo'] != null) ...[
                              const SizedBox(height: 6),
                              Row(children: [
                                const Icon(Icons.calendar_today, size: 14, color: AppColors.textMuted),
                                const SizedBox(width: 4),
                                Text('${obj['dt_inicio'] ?? '?'} → ${obj['dt_prazo'] ?? '?'}', style: const TextStyle(color: AppColors.textMuted, fontSize: 13)),
                              ]),
                            ],
                            if (obj['observacoes'] != null && (obj['observacoes'] as String).isNotEmpty) ...[
                              const SizedBox(height: 10),
                              Text(obj['observacoes'], style: const TextStyle(color: AppColors.textMuted, fontSize: 13)),
                            ],
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 20),

                // KR Section
                Row(children: [
                  Container(
                    width: 3,
                    height: 20,
                    decoration: BoxDecoration(
                      gradient: AppColors.goldGradient,
                      borderRadius: BorderRadius.circular(2),
                    ),
                  ),
                  const SizedBox(width: 8),
                  Text('Key Results', style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
                  const Spacer(),
                  TextButton.icon(
                    icon: const Icon(Icons.add, size: 18, color: AppColors.gold),
                    label: const Text('Novo KR', style: TextStyle(color: AppColors.gold, fontSize: 13)),
                    onPressed: () async {
                      AppHaptics.light();
                      final result = await context.push('/okrs/$idObjetivo/krs/novo');
                      if (result == true) {
                        ref.invalidate(krsForObjProvider(idObjetivo));
                      }
                    },
                  ),
                ]),
                const SizedBox(height: 8),
                krs.when(
                  loading: () => const LoadingShimmer(count: 3),
                  error: (e, _) => Center(
                    child: Column(
                      children: [
                        const Icon(Icons.error_outline, color: AppColors.red, size: 32),
                        const SizedBox(height: 8),
                        const Text('Erro ao carregar KRs', style: TextStyle(color: AppColors.red, fontSize: 13)),
                        TextButton(
                          onPressed: () => ref.invalidate(krsForObjProvider(idObjetivo)),
                          child: const Text('Tentar novamente'),
                        ),
                      ],
                    ),
                  ),
                  data: (krList) => krList.isEmpty
                      ? const Padding(
                          padding: EdgeInsets.all(24),
                          child: Center(child: Text('Nenhum KR cadastrado', style: TextStyle(color: AppColors.textMuted))),
                        )
                      : Column(
                          children: krList.map((kr) => _KrCard(kr: kr)).toList(),
                        ),
                ),
              ],
            ),
          );
        },
      ),
    );
  }

  Future<void> _deleteObj(BuildContext context, WidgetRef ref) async {
    final confirmed = await showConfirmDialog(
      context,
      title: 'Excluir Objetivo',
      message: 'Todos os KRs, iniciativas e apontamentos vinculados serão excluídos. Continuar?',
      confirmLabel: 'Excluir',
      isDanger: true,
    );
    if (!confirmed) return;

    try {
      final api = ref.read(apiClientProvider);
      await api.dio.delete('/objetivos/$idObjetivo');
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Objetivo excluído.')));
        context.pop();
      }
    } catch (e) {
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Erro ao excluir: $e')));
      }
    }
  }
}

class _KrCard extends StatelessWidget {
  final Map<String, dynamic> kr;
  const _KrCard({required this.kr});

  @override
  Widget build(BuildContext context) {
    final desc = kr['descricao'] ?? '';
    final base = (kr['baseline'] as num?)?.toDouble() ?? 0;
    final meta = (kr['meta'] as num?)?.toDouble() ?? 0;
    final progress = kr['progress'] as Map<String, dynamic>?;
    final pctAtual = (progress?['pct_atual'] as num?)?.toDouble();
    final farol = kr['farol'] ?? '';
    final unidade = kr['unidade_medida'] ?? '';
    final idKr = kr['id_kr'] ?? '';

    Color farolColor = AppColors.textMuted;
    if (farol == 'verde') farolColor = AppColors.green;
    if (farol == 'amarelo') farolColor = AppColors.warn;
    if (farol == 'vermelho') farolColor = AppColors.red;

    return Card(
      margin: const EdgeInsets.only(bottom: 10),
      child: InkWell(
        borderRadius: BorderRadius.circular(16),
        onTap: () {
          AppHaptics.light();
          context.push('/krs/$idKr');
        },
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(children: [
                FarolIndicator(farol: farol),
                const SizedBox(width: 8),
                Expanded(child: Text(desc, style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13), maxLines: 2)),
                const Icon(Icons.chevron_right, size: 18, color: AppColors.textMuted),
              ]),
              const SizedBox(height: 10),
              if (pctAtual != null) ...[
                ClipRRect(
                  borderRadius: BorderRadius.circular(4),
                  child: LinearProgressIndicator(
                    value: (pctAtual / 100).clamp(0, 1),
                    backgroundColor: AppColors.borderDefault,
                    valueColor: AlwaysStoppedAnimation(farolColor),
                    minHeight: 5,
                  ),
                ),
                const SizedBox(height: 6),
              ],
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Text('$base → $meta $unidade', style: const TextStyle(color: AppColors.textMuted, fontSize: 11)),
                  if (pctAtual != null)
                    Text('${pctAtual.toStringAsFixed(0)}%', style: TextStyle(fontWeight: FontWeight.w700, color: farolColor, fontSize: 13)),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}
