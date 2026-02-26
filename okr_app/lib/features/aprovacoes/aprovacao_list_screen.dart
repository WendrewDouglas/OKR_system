import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../core/network/api_client.dart';
import '../../core/theme/app_theme.dart';
import '../shared/widgets/loading_shimmer.dart';

final aprovacoesProvider = FutureProvider.autoDispose<Map<String, dynamic>>((ref) async {
  final api = ref.read(apiClientProvider);
  final res = await api.dio.get('/aprovacoes');
  return res.data as Map<String, dynamic>;
});

class AprovacaoListScreen extends ConsumerWidget {
  const AprovacaoListScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final data = ref.watch(aprovacoesProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Aprovações')),
      body: data.when(
        loading: () => const LoadingShimmer(),
        error: (e, _) => Center(child: Text('Erro: $e')),
        data: (json) {
          final stats = json['stats'] as Map<String, dynamic>? ?? {};
          final paraAprovar = ((json['para_aprovar'] as List?) ?? []).cast<Map<String, dynamic>>();
          final minhas = ((json['minhas_pendentes'] as List?) ?? []).cast<Map<String, dynamic>>();

          return RefreshIndicator(
            onRefresh: () async => ref.invalidate(aprovacoesProvider),
            child: ListView(
              padding: const EdgeInsets.all(16),
              children: [
                Row(children: [
                  _StatChip(label: 'Pendentes', value: stats['pendentes'] ?? 0, color: AppColors.warn),
                  const SizedBox(width: 8),
                  _StatChip(label: 'Reprovados', value: stats['reprovados'] ?? 0, color: AppColors.red),
                ]),
                const SizedBox(height: 20),
                if (paraAprovar.isNotEmpty) ...[
                  Text('Para aprovar', style: Theme.of(context).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
                  const SizedBox(height: 8),
                  ...paraAprovar.map((item) => _AprovacaoCard(item: item, isAction: true, ref: ref)),
                ],
                if (minhas.isNotEmpty) ...[
                  const SizedBox(height: 20),
                  Text('Minhas pendências', style: Theme.of(context).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
                  const SizedBox(height: 8),
                  ...minhas.map((item) => _AprovacaoCard(item: item, isAction: false, ref: ref)),
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
      ),
      child: Text('$value $label', style: TextStyle(color: color, fontWeight: FontWeight.w600, fontSize: 13)),
    );
  }
}

class _AprovacaoCard extends StatelessWidget {
  final Map<String, dynamic> item;
  final bool isAction;
  final WidgetRef ref;
  const _AprovacaoCard({required this.item, required this.isAction, required this.ref});

  @override
  Widget build(BuildContext context) {
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
                child: Text(item['modulo'] ?? '', style: const TextStyle(fontSize: 10, color: AppColors.blue, fontWeight: FontWeight.w600)),
              ),
              const Spacer(),
              Text(item['status_aprovacao'] ?? '', style: const TextStyle(fontSize: 11, color: AppColors.textMuted)),
            ]),
            const SizedBox(height: 8),
            Text(item['descricao'] ?? '', style: const TextStyle(fontSize: 13), maxLines: 2, overflow: TextOverflow.ellipsis),
            if (isAction) ...[
              const SizedBox(height: 10),
              Row(mainAxisAlignment: MainAxisAlignment.end, children: [
                TextButton(
                  onPressed: () => _decide(context, 'reprovado'),
                  child: const Text('Rejeitar', style: TextStyle(color: AppColors.red)),
                ),
                const SizedBox(width: 8),
                ElevatedButton(
                  onPressed: () => _decide(context, 'aprovado'),
                  child: const Text('Aprovar'),
                ),
              ]),
            ],
          ],
        ),
      ),
    );
  }

  Future<void> _decide(BuildContext context, String decisao) async {
    final api = ref.read(apiClientProvider);
    try {
      await api.dio.post('/aprovacoes/decidir', data: {
        'modulo': item['modulo'],
        'id_ref': '${item['id_ref']}',
        'decisao': decisao,
        'comentarios': decisao == 'reprovado' ? 'Rejeitado via app' : '',
      });
      ref.invalidate(aprovacoesProvider);
    } catch (e) {
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Erro: $e')));
      }
    }
  }
}
