import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../core/network/api_client.dart';
import '../../core/theme/app_theme.dart';
import '../shared/widgets/kpi_card.dart';
import '../shared/widgets/loading_shimmer.dart';
import '../shared/widgets/app_header.dart';

final dashboardProvider = FutureProvider.autoDispose<Map<String, dynamic>>((ref) async {
  final api = ref.read(apiClientProvider);
  final res = await api.dio.get('/dashboard/summary');
  return res.data as Map<String, dynamic>;
});

class DashboardScreen extends ConsumerWidget {
  const DashboardScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final dashboard = ref.watch(dashboardProvider);

    return Scaffold(
      appBar: const AppHeader(),
      body: dashboard.when(
        loading: () => const LoadingShimmer(),
        error: (e, _) => Center(child: Text('Erro: $e', style: const TextStyle(color: AppColors.red))),
        data: (data) {
          final totals = data['totals'] as Map<String, dynamic>? ?? {};
          final pilares = (data['pilares'] as List?)?.cast<Map<String, dynamic>>() ?? [];

          return RefreshIndicator(
            onRefresh: () async => ref.invalidate(dashboardProvider),
            child: ListView(
              padding: const EdgeInsets.all(16),
              children: [
                // KPI Grid
                GridView.count(
                  crossAxisCount: 2,
                  shrinkWrap: true,
                  physics: const NeverScrollableScrollPhysics(),
                  mainAxisSpacing: 12,
                  crossAxisSpacing: 12,
                  childAspectRatio: 1.6,
                  children: [
                    KpiCard(label: 'Objetivos', value: '${totals['objetivos'] ?? 0}', icon: Icons.flag, color: AppColors.gold),
                    KpiCard(label: 'Key Results', value: '${totals['krs'] ?? 0}', icon: Icons.track_changes, color: AppColors.blue),
                    KpiCard(label: 'Concluídos', value: '${totals['krs_concluidos'] ?? 0}', icon: Icons.check_circle, color: AppColors.green),
                    KpiCard(label: 'Em Risco', value: '${totals['krs_risco'] ?? 0}', icon: Icons.warning_amber, color: AppColors.red),
                  ],
                ),
                const SizedBox(height: 24),
                Text('Pilares BSC', style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
                const SizedBox(height: 12),
                ...pilares.map((p) => _PilarCard(pilar: p)),
              ],
            ),
          );
        },
      ),
    );
  }
}

class _PilarCard extends StatelessWidget {
  final Map<String, dynamic> pilar;
  const _PilarCard({required this.pilar});

  @override
  Widget build(BuildContext context) {
    final nome = pilar['pilar_nome'] ?? '';
    final objs = pilar['objetivos'] ?? 0;
    final krs = pilar['krs'] ?? 0;
    final done = pilar['krs_concluidos'] ?? 0;
    final risk = pilar['krs_risco'] ?? 0;
    final pct = krs > 0 ? (done / krs * 100).round() : 0;

    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(nome, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
            const SizedBox(height: 12),
            ClipRRect(
              borderRadius: BorderRadius.circular(4),
              child: LinearProgressIndicator(
                value: pct / 100,
                backgroundColor: AppColors.border,
                valueColor: AlwaysStoppedAnimation(pct >= 70 ? AppColors.green : pct >= 40 ? AppColors.warn : AppColors.red),
                minHeight: 6,
              ),
            ),
            const SizedBox(height: 8),
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text('$objs obj · $krs KRs', style: const TextStyle(color: AppColors.textMuted, fontSize: 12)),
                Text('$pct%', style: TextStyle(
                  fontWeight: FontWeight.w700,
                  color: pct >= 70 ? AppColors.green : pct >= 40 ? AppColors.warn : AppColors.red,
                )),
              ],
            ),
            if (risk > 0) ...[
              const SizedBox(height: 4),
              Text('$risk KR(s) em risco', style: const TextStyle(color: AppColors.red, fontSize: 12)),
            ],
          ],
        ),
      ),
    );
  }
}
