import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../core/network/api_client.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/haptics.dart';
import '../shared/widgets/loading_shimmer.dart';
import '../shared/widgets/farol_indicator.dart';
import '../shared/widgets/status_badge.dart';
import '../shared/widgets/progress_chart.dart';
import 'apontamento_sheet.dart';

final krDetailProvider = FutureProvider.autoDispose
    .family<Map<String, dynamic>, String>((ref, idKr) async {
  final api = ref.read(apiClientProvider);
  final res = await api.dio.get('/krs/$idKr');
  return res.data as Map<String, dynamic>;
});

final iniciativasForKrProvider = FutureProvider.autoDispose
    .family<List<Map<String, dynamic>>, String>((ref, idKr) async {
  final api = ref.read(apiClientProvider);
  final res = await api.dio.get('/krs/$idKr/iniciativas');
  return ((res.data['iniciativas'] as List?) ?? []).cast<Map<String, dynamic>>();
});

class KrDetailScreen extends ConsumerWidget {
  final String idKr;
  const KrDetailScreen({super.key, required this.idKr});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final detail = ref.watch(krDetailProvider(idKr));

    return Scaffold(
      appBar: AppBar(
        title: const Text('Key Result'),
        actions: [
          IconButton(
            icon: const Icon(Icons.edit_outlined),
            onPressed: () {
              AppHaptics.light();
              context.push('/krs/$idKr/editar');
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
              const Text('Erro ao carregar KR', style: TextStyle(color: AppColors.red)),
              const SizedBox(height: 8),
              TextButton.icon(
                icon: const Icon(Icons.refresh, size: 18),
                label: const Text('Tentar novamente'),
                onPressed: () {
                  AppHaptics.light();
                  ref.invalidate(krDetailProvider(idKr));
                },
              ),
            ],
          ),
        ),
        data: (data) {
          final kr = data['kr'] as Map<String, dynamic>? ?? {};
          final milestones = ((data['milestones'] as List?) ?? []).cast<Map<String, dynamic>>();
          final chart = data['chart'] as Map<String, dynamic>? ?? {};
          final agg = data['agregados'] as Map<String, dynamic>? ?? {};
          final orc = agg['orcamento'] as Map<String, dynamic>?;

          return RefreshIndicator(
            color: AppColors.gold,
            backgroundColor: AppColors.bgCard,
            onRefresh: () async {
              AppHaptics.medium();
              ref.invalidate(krDetailProvider(idKr));
              ref.invalidate(iniciativasForKrProvider(idKr));
            },
            child: ListView(
              padding: const EdgeInsets.all(16),
              children: [
                _KrInfoCard(kr: kr),
                const SizedBox(height: 16),

                // Chart
                Card(
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text('Evolução', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
                        const SizedBox(height: 4),
                        Row(children: [
                          _LegendDot(color: AppColors.textMuted, label: 'Meta'),
                          const SizedBox(width: 16),
                          _LegendDot(color: AppColors.gold, label: 'Real'),
                        ]),
                        const SizedBox(height: 12),
                        ProgressChart(
                          labels: ((chart['labels'] as List?) ?? []).cast<String>(),
                          esperado: _toDoubleList(chart['esperado']),
                          real: _toDoubleList(chart['real']),
                          min: _toDoubleList(chart['min']),
                          max: _toDoubleList(chart['max']),
                          unidade: kr['unidade_medida'] as String?,
                        ),
                      ],
                    ),
                  ),
                ),
                const SizedBox(height: 16),

                _MilestonesSection(milestones: milestones, idKr: idKr, kr: kr, ref: ref),
                const SizedBox(height: 16),

                // Aggregates row
                Row(children: [
                  Expanded(
                    child: _AggCard(
                      icon: Icons.rocket_launch,
                      label: 'Iniciativas',
                      value: '${agg['iniciativas'] ?? 0}',
                      onTap: () {
                        AppHaptics.light();
                        context.push('/krs/$idKr/iniciativas');
                      },
                    ),
                  ),
                  const SizedBox(width: 12),
                  if (orc != null)
                    Expanded(
                      child: _AggCard(
                        icon: Icons.account_balance_wallet,
                        label: 'Orçamento',
                        value: 'R\$ ${_formatNum(orc['aprovado'])}',
                        subtitle: 'Saldo: R\$ ${_formatNum(orc['saldo'])}',
                      ),
                    ),
                ]),
                const SizedBox(height: 16),

                _IniciativasPreview(idKr: idKr, ref: ref),
                const SizedBox(height: 24),

                _ActionButtons(idKr: idKr, kr: kr, ref: ref),
              ],
            ),
          );
        },
      ),
      floatingActionButton: FloatingActionButton.extended(
        icon: const Icon(Icons.add_chart),
        label: const Text('Registrar Progresso'),
        onPressed: () async {
          AppHaptics.medium();
          final saved = await showApontamentoSheet(context, idKr);
          if (saved == true) {
            ref.invalidate(krDetailProvider(idKr));
          }
        },
      ),
    );
  }

  List<double?> _toDoubleList(dynamic list) {
    if (list is! List) return [];
    return list.map((e) => e != null ? (e as num).toDouble() : null).toList();
  }

  String _formatNum(dynamic v) {
    if (v == null) return '0';
    final d = (v as num).toDouble();
    if (d == d.roundToDouble()) return d.toStringAsFixed(0);
    return d.toStringAsFixed(2);
  }
}

class _KrInfoCard extends StatelessWidget {
  final Map<String, dynamic> kr;
  const _KrInfoCard({required this.kr});

  @override
  Widget build(BuildContext context) {
    final farol = kr['farol'] as String? ?? '';
    final resp = kr['responsavel'] as Map<String, dynamic>?;
    final base = (kr['baseline'] as num?)?.toDouble() ?? 0;
    final meta = (kr['meta'] as num?)?.toDouble() ?? 0;
    final unidade = kr['unidade_medida'] ?? '';

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(children: [
              FarolIndicator(farol: farol, size: 14),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  kr['descricao'] ?? '',
                  style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16),
                ),
              ),
            ]),
            const SizedBox(height: 12),
            Wrap(spacing: 8, runSpacing: 6, children: [
              StatusBadge(label: kr['status'] ?? ''),
              if (kr['natureza_kr'] != null && kr['natureza_kr'] != '')
                StatusBadge(label: kr['natureza_kr'], color: AppColors.blue),
              if (kr['tipo_kr'] != null && kr['tipo_kr'] != '')
                StatusBadge(label: kr['tipo_kr'], color: AppColors.accent),
            ]),
            const SizedBox(height: 12),
            Row(children: [
              _InfoChip(icon: Icons.trending_up, text: '$base → $meta $unidade'),
              const Spacer(),
              if (resp != null)
                _InfoChip(icon: Icons.person_outline, text: resp['nome'] ?? ''),
            ]),
            if (kr['data_inicio'] != null || kr['data_fim'] != null) ...[
              const SizedBox(height: 8),
              _InfoChip(
                icon: Icons.calendar_today,
                text: '${kr['data_inicio'] ?? '?'} → ${kr['data_fim'] ?? '?'}',
              ),
            ],
            if (kr['obj_descricao'] != null) ...[
              const SizedBox(height: 8),
              Text(
                'Objetivo: ${kr['obj_descricao']}',
                style: const TextStyle(color: AppColors.textMuted, fontSize: 12),
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _InfoChip extends StatelessWidget {
  final IconData icon;
  final String text;
  const _InfoChip({required this.icon, required this.text});

  @override
  Widget build(BuildContext context) {
    return Row(mainAxisSize: MainAxisSize.min, children: [
      Icon(icon, size: 14, color: AppColors.textMuted),
      const SizedBox(width: 4),
      Text(text, style: const TextStyle(color: AppColors.textMuted, fontSize: 12)),
    ]);
  }
}

class _LegendDot extends StatelessWidget {
  final Color color;
  final String label;
  const _LegendDot({required this.color, required this.label});

  @override
  Widget build(BuildContext context) {
    return Row(mainAxisSize: MainAxisSize.min, children: [
      Container(width: 10, height: 10, decoration: BoxDecoration(color: color, shape: BoxShape.circle)),
      const SizedBox(width: 4),
      Text(label, style: const TextStyle(fontSize: 11, color: AppColors.textMuted)),
    ]);
  }
}

class _MilestonesSection extends StatelessWidget {
  final List<Map<String, dynamic>> milestones;
  final String idKr;
  final Map<String, dynamic> kr;
  final WidgetRef ref;
  const _MilestonesSection({required this.milestones, required this.idKr, required this.kr, required this.ref});

  @override
  Widget build(BuildContext context) {
    if (milestones.isEmpty) return const SizedBox.shrink();

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(children: [
              const Text('Milestones', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
              const Spacer(),
              Text('${milestones.length} períodos', style: const TextStyle(color: AppColors.textMuted, fontSize: 12)),
            ]),
            const SizedBox(height: 12),
            ...milestones.map((m) {
              final dataRef = m['data_ref'] ?? '';
              final esperado = m['valor_esperado'];
              final real = m['valor_real'];
              final bloqueado = m['bloqueado'] == true;
              final hasReal = real != null;

              return Container(
                padding: const EdgeInsets.symmetric(vertical: 10, horizontal: 12),
                decoration: BoxDecoration(
                  border: Border(bottom: BorderSide(color: AppColors.borderDefault.withValues(alpha: 0.5))),
                ),
                child: Row(children: [
                  Container(
                    width: 8, height: 8,
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      color: hasReal
                          ? AppColors.green
                          : bloqueado
                              ? AppColors.textMuted
                              : AppColors.borderDefault,
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(_formatDate(dataRef), style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w500)),
                        Text(
                          'Meta: ${esperado ?? '-'} ${kr['unidade_medida'] ?? ''}',
                          style: const TextStyle(fontSize: 11, color: AppColors.textMuted),
                        ),
                      ],
                    ),
                  ),
                  if (hasReal)
                    Text(
                      '$real',
                      style: const TextStyle(fontWeight: FontWeight.w700, color: AppColors.gold, fontSize: 14),
                    )
                  else if (!bloqueado)
                    const Icon(Icons.edit_note, size: 18, color: AppColors.textMuted),
                ]),
              );
            }),
          ],
        ),
      ),
    );
  }

  String _formatDate(String d) {
    if (d.length < 7) return d;
    final parts = d.split('-');
    if (parts.length >= 2) return '${parts[1]}/${parts[0]}';
    return d;
  }
}

class _AggCard extends StatelessWidget {
  final IconData icon;
  final String label;
  final String value;
  final String? subtitle;
  final VoidCallback? onTap;
  const _AggCard({required this.icon, required this.label, required this.value, this.subtitle, this.onTap});

  @override
  Widget build(BuildContext context) {
    return Card(
      child: InkWell(
        borderRadius: BorderRadius.circular(16),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(icon, size: 20, color: AppColors.gold),
              const SizedBox(height: 8),
              Text(value, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 18)),
              Text(label, style: const TextStyle(color: AppColors.textMuted, fontSize: 12)),
              if (subtitle != null)
                Text(subtitle!, style: const TextStyle(color: AppColors.textMuted, fontSize: 11)),
            ],
          ),
        ),
      ),
    );
  }
}

class _IniciativasPreview extends StatelessWidget {
  final String idKr;
  final WidgetRef ref;
  const _IniciativasPreview({required this.idKr, required this.ref});

  @override
  Widget build(BuildContext context) {
    final inis = ref.watch(iniciativasForKrProvider(idKr));
    return inis.when(
      loading: () => const SizedBox.shrink(),
      error: (_, __) => const SizedBox.shrink(),
      data: (items) {
        if (items.isEmpty) return const SizedBox.shrink();
        final preview = items.take(3).toList();
        return Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(children: [
              const Text('Iniciativas', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
              const Spacer(),
              if (items.length > 3)
                TextButton(
                  onPressed: () {
                    AppHaptics.light();
                    context.push('/krs/$idKr/iniciativas');
                  },
                  child: Text('Ver todas (${items.length})', style: const TextStyle(color: AppColors.gold, fontSize: 12)),
                ),
            ]),
            const SizedBox(height: 8),
            ...preview.map((ini) => Card(
              margin: const EdgeInsets.only(bottom: 8),
              child: ListTile(
                dense: true,
                title: Text(ini['descricao'] ?? '', style: const TextStyle(fontSize: 13), maxLines: 1, overflow: TextOverflow.ellipsis),
                subtitle: Text(ini['status'] ?? '', style: const TextStyle(fontSize: 11, color: AppColors.textMuted)),
                trailing: const Icon(Icons.chevron_right, size: 18, color: AppColors.textMuted),
                onTap: () {
                  AppHaptics.light();
                  context.push('/iniciativas/${ini['id_iniciativa']}');
                },
              ),
            )),
          ],
        );
      },
    );
  }
}

class _ActionButtons extends StatelessWidget {
  final String idKr;
  final Map<String, dynamic> kr;
  final WidgetRef ref;
  const _ActionButtons({required this.idKr, required this.kr, required this.ref});

  @override
  Widget build(BuildContext context) {
    final status = kr['status'] as String? ?? '';
    final isCancelado = status.toLowerCase() == 'cancelado';

    return Row(children: [
      if (!isCancelado)
        Expanded(
          child: OutlinedButton.icon(
            icon: const Icon(Icons.cancel_outlined, size: 18),
            label: const Text('Cancelar KR'),
            style: OutlinedButton.styleFrom(foregroundColor: AppColors.red, side: const BorderSide(color: AppColors.red)),
            onPressed: () {
              AppHaptics.medium();
              _cancelKr(context);
            },
          ),
        ),
      if (isCancelado)
        Expanded(
          child: ElevatedButton.icon(
            icon: const Icon(Icons.play_arrow, size: 18),
            label: const Text('Reativar KR'),
            onPressed: () {
              AppHaptics.medium();
              _reactivateKr(context);
            },
          ),
        ),
    ]);
  }

  Future<void> _cancelKr(BuildContext context) async {
    final api = ref.read(apiClientProvider);
    try {
      await api.dio.post('/krs/$idKr/cancelar');
      ref.invalidate(krDetailProvider(idKr));
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('KR cancelado.')));
      }
    } catch (e) {
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Erro: $e')));
      }
    }
  }

  Future<void> _reactivateKr(BuildContext context) async {
    final api = ref.read(apiClientProvider);
    try {
      await api.dio.post('/krs/$idKr/reativar');
      ref.invalidate(krDetailProvider(idKr));
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('KR reativado.')));
      }
    } catch (e) {
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Erro: $e')));
      }
    }
  }
}
