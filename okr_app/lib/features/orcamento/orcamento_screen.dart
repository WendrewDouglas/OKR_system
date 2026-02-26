import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../core/network/api_client.dart';
import '../../core/theme/app_theme.dart';
import '../shared/widgets/loading_shimmer.dart';
import '../shared/widgets/empty_state.dart';
import '../shared/widgets/app_header.dart';

final _orcamentoProvider = FutureProvider.autoDispose<List<Map<String, dynamic>>>((ref) async {
  final api = ref.read(apiClientProvider);
  final res = await api.dio.get('/orcamentos');
  return (res.data['orcamentos'] as List?)?.cast<Map<String, dynamic>>() ?? [];
});

class OrcamentoScreen extends ConsumerWidget {
  const OrcamentoScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final orcamentos = ref.watch(_orcamentoProvider);

    return Scaffold(
      appBar: const AppHeader(),
      body: orcamentos.when(
        loading: () => const LoadingShimmer(),
        error: (e, _) => Center(child: Text('Erro: $e', style: const TextStyle(color: AppColors.red))),
        data: (items) {
          if (items.isEmpty) {
            return const EmptyState(
              icon: Icons.account_balance_wallet_outlined,
              title: 'Sem orçamentos',
              subtitle: 'Nenhum orçamento registrado ainda.',
            );
          }

          // Aggregate totals
          double totalPlanejado = 0;
          double totalRealizado = 0;
          for (final o in items) {
            totalPlanejado += (o['valor_planejado'] as num?)?.toDouble() ?? 0;
            totalRealizado += (o['valor_realizado'] as num?)?.toDouble() ?? 0;
          }
          final pctUsado = totalPlanejado > 0 ? (totalRealizado / totalPlanejado * 100) : 0.0;

          return RefreshIndicator(
            onRefresh: () async => ref.invalidate(_orcamentoProvider),
            child: ListView(
              padding: const EdgeInsets.all(16),
              children: [
                // Summary card
                Card(
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text('Resumo Orçamentário',
                            style: TextStyle(fontSize: 16, fontWeight: FontWeight.w700)),
                        const SizedBox(height: 16),
                        Row(
                          children: [
                            Expanded(child: _SummaryTile(
                              label: 'Planejado',
                              value: _currency(totalPlanejado),
                              color: AppColors.blue,
                            )),
                            const SizedBox(width: 12),
                            Expanded(child: _SummaryTile(
                              label: 'Realizado',
                              value: _currency(totalRealizado),
                              color: pctUsado > 100 ? AppColors.red : AppColors.green,
                            )),
                          ],
                        ),
                        const SizedBox(height: 12),
                        ClipRRect(
                          borderRadius: BorderRadius.circular(4),
                          child: LinearProgressIndicator(
                            value: (pctUsado / 100).clamp(0, 1),
                            backgroundColor: AppColors.border,
                            valueColor: AlwaysStoppedAnimation(
                              pctUsado > 100 ? AppColors.red : pctUsado > 80 ? AppColors.warn : AppColors.green,
                            ),
                            minHeight: 8,
                          ),
                        ),
                        const SizedBox(height: 6),
                        Align(
                          alignment: Alignment.centerRight,
                          child: Text('${pctUsado.toStringAsFixed(1)}% utilizado',
                              style: const TextStyle(fontSize: 12, color: AppColors.textMuted)),
                        ),
                      ],
                    ),
                  ),
                ),
                const SizedBox(height: 20),
                Text('Detalhamento', style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
                const SizedBox(height: 12),
                ...items.map((o) => _OrcamentoCard(orcamento: o)),
              ],
            ),
          );
        },
      ),
    );
  }

  String _currency(double value) {
    final formatted = value.toStringAsFixed(2).replaceAll('.', ',');
    // Simple thousand separator
    final parts = formatted.split(',');
    final intPart = parts[0].replaceAllMapped(
      RegExp(r'(\d)(?=(\d{3})+$)'),
      (m) => '${m[1]}.',
    );
    return 'R\$ $intPart,${parts[1]}';
  }
}

class _SummaryTile extends StatelessWidget {
  final String label;
  final String value;
  final Color color;
  const _SummaryTile({required this.label, required this.value, required this.color});

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: const TextStyle(fontSize: 12, color: AppColors.textMuted)),
        const SizedBox(height: 4),
        Text(value, style: TextStyle(fontSize: 16, fontWeight: FontWeight.w700, color: color)),
      ],
    );
  }
}

class _OrcamentoCard extends StatelessWidget {
  final Map<String, dynamic> orcamento;
  const _OrcamentoCard({required this.orcamento});

  @override
  Widget build(BuildContext context) {
    final descricao = orcamento['descricao'] ?? orcamento['iniciativa_descricao'] ?? 'Sem descrição';
    final planejado = (orcamento['valor_planejado'] as num?)?.toDouble() ?? 0;
    final realizado = (orcamento['valor_realizado'] as num?)?.toDouble() ?? 0;
    final pct = planejado > 0 ? (realizado / planejado * 100) : 0.0;

    return Card(
      margin: const EdgeInsets.only(bottom: 10),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(descricao.toString(), maxLines: 2, overflow: TextOverflow.ellipsis,
                style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600)),
            const SizedBox(height: 10),
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text('Plan: R\$ ${planejado.toStringAsFixed(2)}',
                    style: const TextStyle(fontSize: 12, color: AppColors.textMuted)),
                Text('Real: R\$ ${realizado.toStringAsFixed(2)}',
                    style: TextStyle(fontSize: 12, color: pct > 100 ? AppColors.red : AppColors.green)),
              ],
            ),
            const SizedBox(height: 6),
            ClipRRect(
              borderRadius: BorderRadius.circular(3),
              child: LinearProgressIndicator(
                value: (pct / 100).clamp(0, 1),
                backgroundColor: AppColors.border,
                valueColor: AlwaysStoppedAnimation(pct > 100 ? AppColors.red : pct > 80 ? AppColors.warn : AppColors.green),
                minHeight: 4,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
