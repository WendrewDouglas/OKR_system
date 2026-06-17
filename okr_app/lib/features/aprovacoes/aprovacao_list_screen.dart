import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../core/models/models.dart';
import '../../core/repositories/repositories.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/haptics.dart';
import '../../core/utils/animations.dart';
import '../shared/widgets/loading_shimmer.dart';
import '../shared/widgets/error_retry.dart';
import '../shared/widgets/confirm_dialog.dart';
import '../shared/widgets/app_scaffold.dart';

final aprovacoesProvider = FutureProvider.autoDispose<AprovacoesData>(
  (ref) => ref.read(aprovacaoRepositoryProvider).list(),
);

class AprovacaoListScreen extends ConsumerWidget {
  const AprovacaoListScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final data = ref.watch(aprovacoesProvider);

    return AppScaffold(
      title: 'Aprovações',
      body: data.when(
        loading: () => const LoadingShimmer(),
        error: (e, _) => ErrorRetry(
          message: 'Erro ao carregar aprovações',
          onRetry: () => ref.invalidate(aprovacoesProvider),
        ),
        data: (ap) {
          final paraAprovar = ap.paraAprovar;
          final minhas = ap.minhasPendentes;

          return RefreshIndicator(
            color: AppColors.gold,
            backgroundColor: AppColors.bgCard,
            onRefresh: () async {
              AppHaptics.medium();
              ref.invalidate(aprovacoesProvider);
            },
            child: ListView(
              padding: const EdgeInsets.all(16),
              children: [
                Row(children: [
                  _StatChip(label: 'Pendentes', value: ap.pendentes, color: AppColors.warn),
                  const SizedBox(width: 8),
                  _StatChip(label: 'Reprovados', value: ap.reprovados, color: AppColors.red),
                ]),
                const SizedBox(height: 20),
                if (paraAprovar.isNotEmpty) ...[
                  Text('Para aprovar', style: Theme.of(context).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
                  const SizedBox(height: 8),
                  ...List.generate(paraAprovar.length, (i) => StaggeredFadeSlide(
                    index: i,
                    child: _AprovacaoCard(item: paraAprovar[i], isAction: true),
                  )),
                ],
                if (minhas.isNotEmpty) ...[
                  const SizedBox(height: 20),
                  Text('Minhas pendências', style: Theme.of(context).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
                  const SizedBox(height: 8),
                  ...List.generate(minhas.length, (i) => StaggeredFadeSlide(
                    index: paraAprovar.length + i,
                    child: _AprovacaoCard(item: minhas[i], isAction: false),
                  )),
                ],
                if (paraAprovar.isEmpty && minhas.isEmpty)
                  const Center(child: Padding(
                    padding: EdgeInsets.only(top: 60),
                    child: Text('Nenhuma aprovação pendente', style: TextStyle(color: AppColors.textMuted)),
                  )),
              ],
            ),
          );
        },
      ),
    );
  }
}

class _StatChip extends StatelessWidget {
  final String label;
  final int value;
  final Color color;
  const _StatChip({required this.label, required this.value, required this.color});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.1),
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: color.withValues(alpha: 0.3)),
        boxShadow: [BoxShadow(color: color.withValues(alpha: 0.1), blurRadius: 6)],
      ),
      child: Text('$value $label', style: TextStyle(color: color, fontWeight: FontWeight.w600, fontSize: 13)),
    );
  }
}

class _AprovacaoCard extends ConsumerStatefulWidget {
  final AprovacaoItem item;
  final bool isAction;
  const _AprovacaoCard({required this.item, required this.isAction});

  @override
  ConsumerState<_AprovacaoCard> createState() => _AprovacaoCardState();
}

class _AprovacaoCardState extends ConsumerState<_AprovacaoCard> {
  bool _busy = false;

  @override
  Widget build(BuildContext context) {
    final item = widget.item;
    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(children: [
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                decoration: BoxDecoration(color: AppColors.blue.withValues(alpha: 0.15), borderRadius: BorderRadius.circular(4)),
                child: Text(item.modulo, style: const TextStyle(fontSize: 10, color: AppColors.blue, fontWeight: FontWeight.w600)),
              ),
              const Spacer(),
              Text(item.statusAprovacao, style: const TextStyle(fontSize: 11, color: AppColors.textMuted)),
            ]),
            const SizedBox(height: 8),
            Text(item.descricao, style: const TextStyle(fontSize: 13), maxLines: 2, overflow: TextOverflow.ellipsis),
            if (widget.isAction) ...[
              const SizedBox(height: 10),
              if (_busy)
                const Align(
                  alignment: Alignment.centerRight,
                  child: Padding(
                    padding: EdgeInsets.all(4),
                    child: SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: AppColors.gold)),
                  ),
                )
              else
                Row(mainAxisAlignment: MainAxisAlignment.end, children: [
                  TextButton(
                    onPressed: _reject,
                    child: const Text('Rejeitar', style: TextStyle(color: AppColors.red)),
                  ),
                  const SizedBox(width: 8),
                  ElevatedButton(
                    onPressed: _approve,
                    child: const Text('Aprovar'),
                  ),
                ]),
            ],
          ],
        ),
      ),
    );
  }

  Future<void> _approve() async {
    AppHaptics.light();
    final ok = await showConfirmDialog(
      context,
      title: 'Aprovar',
      message: 'Confirmar a aprovação deste item?',
      confirmLabel: 'Aprovar',
    );
    if (!ok) return;
    await _run('aprovado', '');
  }

  Future<void> _reject() async {
    AppHaptics.light();
    final reason = await _promptRejectReason();
    if (reason == null) return; // cancelado ou vazio
    await _run('reprovado', reason);
  }

  Future<void> _run(String decisao, String comentarios) async {
    if (_busy) return; // trava: evita duplo-toque / requisições concorrentes
    setState(() => _busy = true);
    try {
      await ref.read(aprovacaoRepositoryProvider).decidir(
            modulo: widget.item.modulo,
            idRef: widget.item.idRef,
            decisao: decisao,
            comentarios: comentarios,
          );
      AppHaptics.success();
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(decisao == 'aprovado' ? 'Item aprovado.' : 'Item reprovado.')),
        );
      }
      ref.invalidate(aprovacoesProvider); // recarrega a lista
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(apiErrorMessage(e))));
        setState(() => _busy = false); // reabilita em caso de erro
      }
    }
  }

  Future<String?> _promptRejectReason() async {
    final ctrl = TextEditingController();
    final reason = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: AppColors.bgCard,
        title: const Text('Motivo da rejeição'),
        content: TextField(
          controller: ctrl,
          autofocus: true,
          minLines: 2,
          maxLines: 4,
          decoration: const InputDecoration(hintText: 'Descreva o motivo (obrigatório)'),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('Cancelar', style: TextStyle(color: AppColors.textMuted)),
          ),
          ElevatedButton(
            style: ElevatedButton.styleFrom(backgroundColor: AppColors.red, foregroundColor: Colors.white),
            onPressed: () {
              final t = ctrl.text.trim();
              if (t.isEmpty) return; // motivo é obrigatório
              Navigator.pop(ctx, t);
            },
            child: const Text('Rejeitar'),
          ),
        ],
      ),
    );
    ctrl.dispose();
    return reason;
  }
}
