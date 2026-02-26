import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../core/network/api_client.dart';
import '../../core/theme/app_theme.dart';
import '../shared/widgets/loading_shimmer.dart';

Future<bool?> showApontamentoSheet(BuildContext context, String idKr) {
  return showModalBottomSheet<bool>(
    context: context,
    isScrollControlled: true,
    backgroundColor: AppColors.bgCard,
    shape: const RoundedRectangleBorder(
      borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
    ),
    builder: (_) => DraggableScrollableSheet(
      initialChildSize: 0.75,
      minChildSize: 0.4,
      maxChildSize: 0.95,
      expand: false,
      builder: (ctx, scrollCtrl) => _ApontamentoSheetContent(
        idKr: idKr,
        scrollController: scrollCtrl,
      ),
    ),
  );
}

class _ApontamentoSheetContent extends ConsumerStatefulWidget {
  final String idKr;
  final ScrollController scrollController;
  const _ApontamentoSheetContent({required this.idKr, required this.scrollController});

  @override
  ConsumerState<_ApontamentoSheetContent> createState() => _ApontamentoSheetContentState();
}

class _ApontamentoSheetContentState extends ConsumerState<_ApontamentoSheetContent> {
  Map<String, dynamic>? _modalData;
  bool _isLoading = true;
  bool _isSaving = false;
  String? _error;
  final Map<int, TextEditingController> _controllers = {};

  @override
  void initState() {
    super.initState();
    _loadData();
  }

  @override
  void dispose() {
    for (final c in _controllers.values) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _loadData() async {
    try {
      final api = ref.read(apiClientProvider);
      final res = await api.dio.get('/krs/${widget.idKr}/apontamentos/modal-data');
      setState(() {
        _modalData = res.data;
        _isLoading = false;
      });
    } catch (e) {
      setState(() {
        _error = '$e';
        _isLoading = false;
      });
    }
  }

  TextEditingController _controllerFor(int idMilestone, double? currentVal) {
    return _controllers.putIfAbsent(idMilestone, () {
      return TextEditingController(text: currentVal != null ? currentVal.toString() : '');
    });
  }

  Future<void> _save() async {
    final items = <Map<String, dynamic>>[];
    for (final entry in _controllers.entries) {
      final text = entry.value.text.trim();
      if (text.isEmpty) continue;
      final val = double.tryParse(text);
      if (val == null) continue;
      items.add({'id_milestone': entry.key, 'valor_real': val});
    }

    if (items.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Preencha pelo menos um valor.')),
      );
      return;
    }

    setState(() => _isSaving = true);
    try {
      final api = ref.read(apiClientProvider);
      await api.dio.post('/krs/${widget.idKr}/apontamentos', data: {'items': items});
      if (mounted) {
        Navigator.of(context).pop(true);
      }
    } catch (e) {
      setState(() => _isSaving = false);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Erro: $e')));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_isLoading) {
      return const Padding(padding: EdgeInsets.all(24), child: LoadingShimmer());
    }
    if (_error != null) {
      return Center(child: Text('Erro: $_error', style: const TextStyle(color: AppColors.red)));
    }

    final kr = _modalData!['kr'] as Map<String, dynamic>;
    final milestones = ((_modalData!['milestones'] as List?) ?? []).cast<Map<String, dynamic>>();
    final unidade = kr['unidade_medida'] ?? '';

    return Column(children: [
      // Handle
      Container(
        margin: const EdgeInsets.only(top: 12, bottom: 8),
        width: 40, height: 4,
        decoration: BoxDecoration(color: AppColors.border, borderRadius: BorderRadius.circular(2)),
      ),
      // Header
      Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('Registrar Progresso', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 18)),
            const SizedBox(height: 4),
            Text(kr['descricao'] ?? '', style: const TextStyle(color: AppColors.textMuted, fontSize: 13), maxLines: 2),
            const SizedBox(height: 4),
            Text('${kr['baseline']} → ${kr['meta']} $unidade', style: const TextStyle(color: AppColors.textMuted, fontSize: 12)),
          ],
        ),
      ),
      const Divider(),
      // Milestones list
      Expanded(
        child: ListView.builder(
          controller: widget.scrollController,
          padding: const EdgeInsets.all(16),
          itemCount: milestones.length,
          itemBuilder: (ctx, i) {
            final m = milestones[i];
            final idMs = m['id_milestone'] as int;
            final dataRef = m['data_ref'] ?? '';
            final esperado = m['valor_esperado'];
            final currentVal = m['valor_real'] as double?;
            final bloqueado = m['bloqueado'] == true;
            final ordemLabel = m['ordem_label'] ?? '';
            final ctrl = _controllerFor(idMs, currentVal);

            return Container(
              margin: const EdgeInsets.only(bottom: 12),
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: AppColors.bgSurface,
                borderRadius: BorderRadius.circular(12),
                border: Border.all(
                  color: currentVal != null ? AppColors.green.withValues(alpha: 0.3) : AppColors.border,
                ),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(children: [
                    Text(ordemLabel, style: const TextStyle(fontSize: 11, color: AppColors.textMuted, fontWeight: FontWeight.w600)),
                    const SizedBox(width: 8),
                    Text(_formatDate(dataRef), style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14)),
                    const Spacer(),
                    if (esperado != null)
                      Text('Meta: $esperado $unidade', style: const TextStyle(fontSize: 11, color: AppColors.textMuted)),
                  ]),
                  const SizedBox(height: 10),
                  TextField(
                    controller: ctrl,
                    keyboardType: const TextInputType.numberWithOptions(decimal: true),
                    enabled: !bloqueado,
                    decoration: InputDecoration(
                      hintText: bloqueado ? 'Bloqueado' : 'Valor real',
                      suffixText: unidade,
                      isDense: true,
                      contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                    ),
                  ),
                ],
              ),
            );
          },
        ),
      ),
      // Save button
      Padding(
        padding: const EdgeInsets.all(16),
        child: SizedBox(
          width: double.infinity,
          height: 50,
          child: ElevatedButton(
            onPressed: _isSaving ? null : _save,
            child: _isSaving
                ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.5, color: AppColors.bgSoft))
                : const Text('Salvar Apontamentos'),
          ),
        ),
      ),
    ]);
  }

  String _formatDate(String d) {
    if (d.length < 7) return d;
    final parts = d.split('-');
    if (parts.length >= 2) return '${parts[1]}/${parts[0]}';
    return d;
  }
}
