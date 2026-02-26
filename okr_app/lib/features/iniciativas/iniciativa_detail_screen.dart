import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../core/network/api_client.dart';
import '../../core/theme/app_theme.dart';
import '../shared/widgets/loading_shimmer.dart';
import '../shared/widgets/status_badge.dart';
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
              final result = await context.push('/iniciativas/$idIniciativa/editar');
              if (result == true) ref.invalidate(iniciativaDetailProvider(idIniciativa));
            },
          ),
          IconButton(
            icon: const Icon(Icons.delete_outline, color: AppColors.red),
            onPressed: () => _delete(context, ref),
          ),
        ],
      ),
      body: detail.when(
        loading: () => const LoadingShimmer(),
        error: (e, _) => Center(child: Text('Erro: $e', style: const TextStyle(color: AppColors.red))),
        data: (data) {
          final ini = data['iniciativa'] as Map<String, dynamic>? ?? {};
          final envolvidos = ((ini['envolvidos'] as List?) ?? []).cast<Map<String, dynamic>>();
          final orcs = ((ini['orcamentos'] as List?) ?? []).cast<Map<String, dynamic>>();

          return RefreshIndicator(
            onRefresh: () async => ref.invalidate(iniciativaDetailProvider(idIniciativa)),
            child: ListView(
              padding: const EdgeInsets.all(16),
              children: [
                // Main card
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

                // Envolvidos
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

                // Orçamentos
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

                // Status change buttons
                _StatusChanger(idIniciativa: idIniciativa, currentStatus: ini['status'] ?? '', ref: ref),
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
      final api = ref.read(apiClientProvider);
      await api.dio.delete('/iniciativas/$idIniciativa');
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Iniciativa excluída.')));
        context.pop();
      }
    } catch (e) {
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Erro: $e')));
      }
    }
  }
}

class _StatusChanger extends StatelessWidget {
  final String idIniciativa;
  final String currentStatus;
  final WidgetRef ref;
  const _StatusChanger({required this.idIniciativa, required this.currentStatus, required this.ref});

  @override
  Widget build(BuildContext context) {
    final statuses = ['Não Iniciado', 'Em Andamento', 'Concluído', 'Cancelado']
        .where((s) => s != currentStatus)
        .toList();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Text('Alterar Status', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
        const SizedBox(height: 8),
        Wrap(
          spacing: 8,
          runSpacing: 6,
          children: statuses.map((s) => OutlinedButton(
            onPressed: () => _changeStatus(context, s),
            child: Text(s, style: const TextStyle(fontSize: 13)),
          )).toList(),
        ),
      ],
    );
  }

  Future<void> _changeStatus(BuildContext context, String newStatus) async {
    final obsCtrl = TextEditingController();
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: AppColors.bgCard,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: Text('Mudar para "$newStatus"'),
        content: TextField(
          controller: obsCtrl,
          maxLines: 3,
          decoration: const InputDecoration(hintText: 'Observação (obrigatória)'),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.of(ctx).pop(false), child: const Text('Cancelar')),
          ElevatedButton(onPressed: () => Navigator.of(ctx).pop(true), child: const Text('Confirmar')),
        ],
      ),
    );

    if (confirmed != true) return;
    if (obsCtrl.text.trim().isEmpty) {
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Observação obrigatória.')));
      }
      return;
    }

    try {
      final api = ref.read(apiClientProvider);
      await api.dio.put('/iniciativas/$idIniciativa/status', data: {
        'status': newStatus,
        'observacao': obsCtrl.text.trim(),
      });
      ref.invalidate(iniciativaDetailProvider(idIniciativa));
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Status alterado para $newStatus.')));
      }
    } catch (e) {
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Erro: $e')));
      }
    }
  }
}
