import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../core/models/models.dart';
import '../../core/repositories/repositories.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/haptics.dart';
import '../../core/utils/animations.dart';
import '../shared/widgets/loading_shimmer.dart';
import '../shared/widgets/status_badge.dart';
import '../shared/widgets/empty_state.dart';
import '../shared/widgets/error_retry.dart';

final iniciativasProvider = FutureProvider.autoDispose
    .family<List<Iniciativa>, String>((ref, idKr) {
  return ref.read(iniciativaRepositoryProvider).listByKr(idKr);
});

String _fmtDate(DateTime? d) =>
    d == null ? '' : '${d.day.toString().padLeft(2, '0')}/${d.month.toString().padLeft(2, '0')}/${d.year}';

class IniciativaListScreen extends ConsumerWidget {
  final String idKr;
  const IniciativaListScreen({super.key, required this.idKr});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final inis = ref.watch(iniciativasProvider(idKr));

    return Scaffold(
      appBar: AppBar(title: const Text('Iniciativas')),
      body: inis.when(
        loading: () => const LoadingShimmer(),
        error: (e, _) => ErrorRetry(
          message: 'Erro ao carregar iniciativas',
          onRetry: () => ref.invalidate(iniciativasProvider(idKr)),
        ),
        data: (items) => items.isEmpty
            ? EmptyState(
                icon: Icons.rocket_launch,
                title: 'Nenhuma iniciativa',
                subtitle: 'Crie a primeira iniciativa para este KR.',
                action: ElevatedButton.icon(
                  icon: const Icon(Icons.add, size: 18),
                  label: const Text('Nova Iniciativa'),
                  onPressed: () async {
                    AppHaptics.medium();
                    final result = await context.push('/krs/$idKr/iniciativas/nova');
                    if (result == true) ref.invalidate(iniciativasProvider(idKr));
                  },
                ),
              )
            : RefreshIndicator(
                color: AppColors.gold,
                backgroundColor: AppColors.bgCard,
                onRefresh: () async {
                  AppHaptics.medium();
                  ref.invalidate(iniciativasProvider(idKr));
                },
                child: ListView.builder(
                  padding: const EdgeInsets.all(16),
                  itemCount: items.length,
                  itemBuilder: (ctx, i) => StaggeredFadeSlide(
                    index: i,
                    child: _IniciativaCard(ini: items[i]),
                  ),
                ),
              ),
      ),
      floatingActionButton: FloatingActionButton(
        onPressed: () async {
          AppHaptics.medium();
          final result = await context.push('/krs/$idKr/iniciativas/nova');
          if (result == true) ref.invalidate(iniciativasProvider(idKr));
        },
        child: const Icon(Icons.add),
      ),
    );
  }
}

class _IniciativaCard extends StatelessWidget {
  final Iniciativa ini;
  const _IniciativaCard({required this.ini});

  @override
  Widget build(BuildContext context) {
    final resp = ini.responsavel;
    final envolvidos = ini.envolvidos;
    final orc = ini.orcamento;
    final prazo = _fmtDate(ini.dtPrazo);

    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: InkWell(
        borderRadius: BorderRadius.circular(16),
        onTap: () {
          AppHaptics.light();
          context.push('/iniciativas/${ini.idIniciativa}');
        },
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(children: [
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                  decoration: BoxDecoration(
                    color: AppColors.gold.withValues(alpha: 0.15),
                    borderRadius: BorderRadius.circular(4),
                  ),
                  child: Text('#${ini.numIniciativa}', style: const TextStyle(fontSize: 11, color: AppColors.gold, fontWeight: FontWeight.w600)),
                ),
                const SizedBox(width: 8),
                StatusBadge(label: ini.status),
                const Spacer(),
                if (prazo.isNotEmpty)
                  Text(prazo, style: const TextStyle(fontSize: 11, color: AppColors.textMuted)),
              ]),
              const SizedBox(height: 10),
              Text(ini.descricao, style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14), maxLines: 2, overflow: TextOverflow.ellipsis),
              const SizedBox(height: 10),
              Row(children: [
                if (resp != null) ...[
                  const Icon(Icons.person_outline, size: 14, color: AppColors.textMuted),
                  const SizedBox(width: 4),
                  Text(resp.nome, style: const TextStyle(color: AppColors.textMuted, fontSize: 12)),
                ],
                if (envolvidos.length > 1) ...[
                  const SizedBox(width: 6),
                  Text('+${envolvidos.length - 1}', style: const TextStyle(color: AppColors.gold, fontSize: 11, fontWeight: FontWeight.w600)),
                ],
                const Spacer(),
                if (orc != null) ...[
                  const Icon(Icons.account_balance_wallet_outlined, size: 14, color: AppColors.textMuted),
                  const SizedBox(width: 4),
                  Text('R\$ ${_fmt(orc.aprovado)}', style: const TextStyle(color: AppColors.textMuted, fontSize: 12)),
                ],
              ]),
            ],
          ),
        ),
      ),
    );
  }

  String _fmt(double d) => d.toStringAsFixed(d == d.roundToDouble() ? 0 : 2);
}
