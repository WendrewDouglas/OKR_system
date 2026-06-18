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

/// Rótulo amigável do módulo de aprovação.
String moduloLabel(String m) {
  switch (m) {
    case 'objetivo':
      return 'Objetivo';
    case 'kr':
      return 'KR';
    case 'orcamento':
      return 'Orçamento';
    case 'socio':
      return 'Sócio';
    default:
      return m;
  }
}

enum _Filtro { pendentes, reprovados, aprovados }

class AprovacaoListScreen extends ConsumerStatefulWidget {
  const AprovacaoListScreen({super.key});

  @override
  ConsumerState<AprovacaoListScreen> createState() => _AprovacaoListScreenState();
}

class _AprovacaoListScreenState extends ConsumerState<AprovacaoListScreen> {
  _Filtro _filtro = _Filtro.pendentes;

  bool _is(AprovacaoItem m, String s) => m.statusAprovacao.toLowerCase() == s;

  @override
  Widget build(BuildContext context) {
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
          final minhasPend = ap.minhasPendentes.where((m) => _is(m, 'pendente')).toList();
          final minhasRepr = ap.minhasPendentes.where((m) => _is(m, 'reprovado')).toList();
          final minhasAprov = ap.minhasPendentes.where((m) => _is(m, 'aprovado')).toList();

          final countPend = paraAprovar.length + minhasPend.length;
          final countRepr = minhasRepr.length;
          final countAprov = minhasAprov.length;

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
                Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: [
                    _StatChip(
                      label: 'Pendentes', value: countPend, color: AppColors.warn,
                      selected: _filtro == _Filtro.pendentes,
                      onTap: () => setState(() => _filtro = _Filtro.pendentes),
                    ),
                    _StatChip(
                      label: 'Reprovados', value: countRepr, color: AppColors.red,
                      selected: _filtro == _Filtro.reprovados,
                      onTap: () => setState(() => _filtro = _Filtro.reprovados),
                    ),
                    _StatChip(
                      label: 'Aprovados', value: countAprov, color: AppColors.green,
                      selected: _filtro == _Filtro.aprovados,
                      onTap: () => setState(() => _filtro = _Filtro.aprovados),
                    ),
                  ],
                ),
                const SizedBox(height: 20),
                ..._buildFiltro(context, paraAprovar, minhasPend, minhasRepr, minhasAprov),
              ],
            ),
          );
        },
      ),
    );
  }

  List<Widget> _buildFiltro(
    BuildContext context,
    List<AprovacaoItem> paraAprovar,
    List<AprovacaoItem> pend,
    List<AprovacaoItem> repr,
    List<AprovacaoItem> aprov,
  ) {
    switch (_filtro) {
      case _Filtro.pendentes:
        final w = <Widget>[];
        if (paraAprovar.isNotEmpty) {
          w.add(_titulo(context, 'Para aprovar'));
          for (var i = 0; i < paraAprovar.length; i++) {
            w.add(StaggeredFadeSlide(
              key: ValueKey('aprovar:${paraAprovar[i].modulo}:${paraAprovar[i].idRef}'),
              index: i,
              child: _AprovacaoCard(item: paraAprovar[i], isAction: true),
            ));
          }
        }
        if (pend.isNotEmpty) {
          if (w.isNotEmpty) w.add(const SizedBox(height: 20));
          w.add(_titulo(context, 'Minhas pendências'));
          for (var i = 0; i < pend.length; i++) {
            w.add(StaggeredFadeSlide(
              key: ValueKey('minhaP:${pend[i].modulo}:${pend[i].idRef}'),
              index: i,
              child: _AprovacaoCard(item: pend[i], isAction: false),
            ));
          }
        }
        if (w.isEmpty) w.add(_vazio('Nenhuma aprovação pendente'));
        return w;
      case _Filtro.reprovados:
        return _listaSimples(context, repr, 'Reprovados', 'Nenhum item reprovado', 'minhaR');
      case _Filtro.aprovados:
        return _listaSimples(context, aprov, 'Aprovados', 'Nenhum item aprovado', 'minhaA');
    }
  }

  List<Widget> _listaSimples(BuildContext context, List<AprovacaoItem> itens,
      String titulo, String vazioMsg, String keyPrefix) {
    if (itens.isEmpty) return [_vazio(vazioMsg)];
    final w = <Widget>[_titulo(context, titulo)];
    for (var i = 0; i < itens.length; i++) {
      w.add(StaggeredFadeSlide(
        key: ValueKey('$keyPrefix:${itens[i].modulo}:${itens[i].idRef}'),
        index: i,
        child: _AprovacaoCard(item: itens[i], isAction: false),
      ));
    }
    return w;
  }

  Widget _titulo(BuildContext context, String t) => Padding(
        padding: const EdgeInsets.only(bottom: 8),
        child: Text(t, style: Theme.of(context).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
      );

  Widget _vazio(String msg) => Center(
        child: Padding(
          padding: const EdgeInsets.only(top: 60),
          child: Text(msg, style: const TextStyle(color: AppColors.textMuted)),
        ),
      );
}

class _StatChip extends StatelessWidget {
  final String label;
  final int value;
  final Color color;
  final bool selected;
  final VoidCallback? onTap;
  const _StatChip({
    required this.label,
    required this.value,
    required this.color,
    this.selected = false,
    this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: () {
        AppHaptics.selection();
        onTap?.call();
      },
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 150),
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
        decoration: BoxDecoration(
          color: color.withValues(alpha: selected ? 0.22 : 0.08),
          borderRadius: BorderRadius.circular(10),
          border: Border.all(
            color: color.withValues(alpha: selected ? 0.9 : 0.3),
            width: selected ? 1.5 : 1,
          ),
          boxShadow: selected ? [BoxShadow(color: color.withValues(alpha: 0.18), blurRadius: 8)] : null,
        ),
        child: Text(
          '$value $label',
          style: TextStyle(
            color: color,
            fontWeight: selected ? FontWeight.w800 : FontWeight.w600,
            fontSize: 13,
          ),
        ),
      ),
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
  bool _done = false; // decidido: some imediatamente (otimista), sem esperar a recarga

  @override
  Widget build(BuildContext context) {
    if (_done) return const SizedBox.shrink();
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
                child: Text(moduloLabel(item.modulo), style: const TextStyle(fontSize: 10, color: AppColors.blue, fontWeight: FontWeight.w600)),
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
        setState(() => _done = true); // some na hora, independente da rede
      }
      ref.invalidate(aprovacoesProvider); // sincroniza contadores/lista em seguida
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
