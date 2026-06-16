import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../core/network/api_client.dart';
import '../../core/repositories/repositories.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/haptics.dart';
import '../shared/widgets/loading_shimmer.dart';
import '../shared/widgets/status_badge.dart';
import '../shared/widgets/error_retry.dart';
import '../shared/widgets/confirm_dialog.dart';

final iniciativaDetailProvider = FutureProvider.autoDispose
    .family<Map<String, dynamic>, String>((ref, id) async {
  final api = ref.read(apiClientProvider);
  final res = await api.dio.get('/iniciativas/$id');
  return res.data as Map<String, dynamic>;
});

class IniciativaDetailScreen extends ConsumerWidget {
  final String idIniciativa;
  const IniciativaDetailScreen({super.key, required this.idIniciativa});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final detail = ref.watch(iniciativaDetailProvider(idIniciativa));

    return Scaffold(
      appBar: AppBar(
        title: const Text('Iniciativa'),
        actions: [
          IconButton(
            icon: const Icon(Icons.edit_outlined),
            onPressed: () async {
              AppHaptics.light();
              final result = await context.push('/iniciativas/$idIniciativa/editar');
              if (result == true) ref.invalidate(iniciativaDetailProvider(idIniciativa));
            },
          ),
          IconButton(
            icon: const Icon(Icons.delete_outline, color: AppColors.red),
            onPressed: () {
              AppHaptics.heavy();
              _delete(context, ref);
            },
          ),
        ],
      ),
      body: detail.when(
        loading: () => const LoadingShimmer(),
        error: (e, _) => ErrorRetry(
          message: 'Erro ao carregar iniciativa',
          onRetry: () => ref.invalidate(iniciativaDetailProvider(idIniciativa)),
        ),
        data: (data) {
          final ini = data['iniciativa'] as Map<String, dynamic>? ?? {};
          final envolvidos = ((ini['envolvidos'] as List?) ?? []).cast<Map<String, dynamic>>();
          final orcs = ((ini['orcamentos'] as List?) ?? []).cast<Map<String, dynamic>>();

          return RefreshIndicator(
            color: AppColors.gold,
            backgroundColor: AppColors.bgCard,
            onRefresh: () async {
              AppHaptics.medium();
              ref.invalidate(iniciativaDetailProvider(idIniciativa));
            },
            child: ListView(
              padding: const EdgeInsets.all(16),
              children: [
                Card(
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(children: [
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                            decoration: BoxDecoration(color: AppColors.gold.withValues(alpha: 0.15), borderRadius: BorderRadius.circular(4)),
                            child: Text('#${ini['num_iniciativa'] ?? ''}', style: const TextStyle(fontSize: 11, color: AppColors.gold, fontWeight: FontWeight.w600)),
                          ),
                          const SizedBox(width: 8),
                          StatusBadge(label: ini['status'] ?? ''),
                        ]),
                        const SizedBox(height: 12),
                        Text(ini['descricao'] ?? '', style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
                        if (ini['dt_prazo'] != null) ...[
                          const SizedBox(height: 8),
                          Row(children: [
                            const Icon(Icons.calendar_today, size: 14, color: AppColors.textMuted),
                            const SizedBox(width: 6),
                            Text('Prazo: ${ini['dt_prazo']}', style: const TextStyle(color: AppColors.textMuted, fontSize: 13)),
                          ]),
                        ],
                      ],
                    ),
                  ),
                ),
                const SizedBox(height: 16),

                if (envolvidos.isNotEmpty) ...[
                  const Text('Envolvidos', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
                  const SizedBox(height: 8),
                  Wrap(
                    spacing: 8,
                    runSpacing: 6,
                    children: envolvidos.map((e) => Chip(
                      avatar: CircleAvatar(
                        backgroundColor: AppColors.gold.withValues(alpha: 0.2),
                        child: Text(
                          ((e['nome'] ?? '?') as String).isNotEmpty ? (e['nome'] as String)[0].toUpperCase() : '?',
                          style: const TextStyle(fontSize: 11, color: AppColors.gold),
                        ),
                      ),
                      label: Text(e['nome'] ?? '', style: const TextStyle(fontSize: 12)),
                    )).toList(),
                  ),
                  const SizedBox(height: 20),
                ],

                if (orcs.isNotEmpty) ...[
                  const Text('Orçamentos', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
                  const SizedBox(height: 8),
                  ...orcs.map((o) => Card(
                    margin: const EdgeInsets.only(bottom: 8),
                    child: Padding(
                      padding: const EdgeInsets.all(14),
                      child: Row(children: [
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text('R\$ ${o['valor'] ?? 0}', style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
                              if (o['data_desembolso'] != null)
                                Text(o['data_desembolso'], style: const TextStyle(color: AppColors.textMuted, fontSize: 12)),
                            ],
                          ),
                        ),
                        StatusBadge(label: o['status_aprovacao'] ?? 'pendente'),
                      ]),
                    ),
                  )),
                  const SizedBox(height: 16),
                ],

                _StatusChanger(idIniciativa: idIniciativa, currentStatus: ini['status'] ?? ''),
              ],
            ),
          );
        },
      ),
    );
  }

  Future<void> _delete(BuildContext context, WidgetRef ref) async {
    final confirmed = await showConfirmDialog(
      context,
      title: 'Excluir Iniciativa',
      message: 'Esta ação não pode ser desfeita. Deseja continuar?',
      confirmLabel: 'Excluir',
      isDanger: true,
    );
    if (!confirmed) return;

    try {
      await ref.read(iniciativaRepositoryProvider).delete(idIniciativa);
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Iniciativa excluída.')));
        context.pop();
      }
    } catch (e) {
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(apiErrorMessage(e))));
      }
    }
  }
}

class _StatusChanger extends ConsumerStatefulWidget {
  final String idIniciativa;
  final String currentStatus;
  const _StatusChanger({required this.idIniciativa, required this.currentStatus});

  @override
  ConsumerState<_StatusChanger> createState() => _StatusChangerState();
}

class _StatusChangerState extends ConsumerState<_StatusChanger> {
  bool _busy = false;

  @override
  Widget build(BuildContext context) {
    final statuses = ['Não Iniciado', 'Em Andamento', 'Concluído', 'Cancelado']
        .where((s) => s != widget.currentStatus)
        .toList();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Text('Alterar Status', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
        const SizedBox(height: 8),
        if (_busy)
          const Padding(
            padding: EdgeInsets.all(4),
            child: SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: AppColors.gold)),
          )
        else
          Wrap(
            spacing: 8,
            runSpacing: 6,
            children: statuses.map((s) => OutlinedButton(
              onPressed: () => _changeStatus(s),
              child: Text(s, style: const TextStyle(fontSize: 13)),
            )).toList(),
          ),
      ],
    );
  }

  Future<void> _changeStatus(String newStatus) async {
    AppHaptics.medium();
    final obs = await _promptObs(newStatus);
    if (obs == null) return; // cancelado ou observação vazia
    if (_busy) return;
    setState(() => _busy = true);
    try {
      await ref.read(iniciativaRepositoryProvider).updateStatus(widget.idIniciativa, newStatus, obs);
      ref.invalidate(iniciativaDetailProvider(widget.idIniciativa));
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Status alterado para $newStatus.')));
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(apiErrorMessage(e))));
        setState(() => _busy = false);
      }
    }
  }

  Future<String?> _promptObs(String newStatus) async {
    final ctrl = TextEditingController();
    final result = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: AppColors.bgCard,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: Text('Mudar para "$newStatus"'),
        content: TextField(
          controller: ctrl,
          autofocus: true,
          maxLines: 3,
          decoration: const InputDecoration(hintText: 'Observação (obrigatória)'),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(),
            child: const Text('Cancelar', style: TextStyle(color: AppColors.textMuted)),
          ),
          ElevatedButton(
            onPressed: () {
              final t = ctrl.text.trim();
              if (t.isEmpty) return; // observação é obrigatória
              Navigator.of(ctx).pop(t);
            },
            child: const Text('Confirmar'),
          ),
        ],
      ),
    );
    ctrl.dispose();
    return result;
  }
}
