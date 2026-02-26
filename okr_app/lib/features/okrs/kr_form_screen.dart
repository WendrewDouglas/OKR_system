import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../core/network/api_client.dart';
import '../../core/theme/app_theme.dart';
import '../../core/providers/domain_providers.dart';
import '../shared/widgets/loading_shimmer.dart';

class KrFormScreen extends ConsumerStatefulWidget {
  final String? idKr;         // null = create
  final String? idObjetivo;   // required for create
  const KrFormScreen({super.key, this.idKr, this.idObjetivo});

  @override
  ConsumerState<KrFormScreen> createState() => _KrFormScreenState();
}

class _KrFormScreenState extends ConsumerState<KrFormScreen> {
  final _formKey = GlobalKey<FormState>();
  final _descricaoCtrl = TextEditingController();
  final _baselineCtrl = TextEditingController(text: '0');
  final _metaCtrl = TextEditingController();
  final _unidadeCtrl = TextEditingController();

  String? _direcaoMetrica = 'MAIOR_MELHOR';
  String? _naturezaKr;
  String? _tipoKr;
  String? _freqMilestone;
  String? _cicloTipo;
  int? _responsavelId;
  bool _autoMilestones = true;
  bool _isLoading = false;
  bool _isLoadingEdit = true;

  bool get isEditing => widget.idKr != null;
  String get _idObjetivo => widget.idObjetivo ?? '';

  @override
  void initState() {
    super.initState();
    if (isEditing) {
      _loadKr();
    } else {
      _isLoadingEdit = false;
    }
  }

  Future<void> _loadKr() async {
    try {
      final api = ref.read(apiClientProvider);
      final res = await api.dio.get('/krs/${widget.idKr}');
      final kr = res.data['kr'] as Map<String, dynamic>? ?? {};
      setState(() {
        _descricaoCtrl.text = kr['descricao'] ?? '';
        _baselineCtrl.text = '${kr['baseline'] ?? 0}';
        _metaCtrl.text = '${kr['meta'] ?? ''}';
        _unidadeCtrl.text = kr['unidade_medida'] ?? '';
        _direcaoMetrica = kr['direcao_metrica'] as String?;
        _naturezaKr = kr['natureza_kr'] as String?;
        _tipoKr = kr['tipo_kr'] as String?;
        _freqMilestone = kr['tipo_frequencia_milestone'] as String?;
        final resp = kr['responsavel'] as Map<String, dynamic>?;
        _responsavelId = resp?['id_user'] as int?;
        _isLoadingEdit = false;
      });
    } catch (e) {
      setState(() => _isLoadingEdit = false);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Erro: $e')));
      }
    }
  }

  @override
  void dispose() {
    _descricaoCtrl.dispose();
    _baselineCtrl.dispose();
    _metaCtrl.dispose();
    _unidadeCtrl.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() => _isLoading = true);
    try {
      final api = ref.read(apiClientProvider);
      final body = {
        'descricao': _descricaoCtrl.text.trim(),
        'baseline': double.tryParse(_baselineCtrl.text) ?? 0,
        'meta': double.tryParse(_metaCtrl.text) ?? 0,
        'unidade_medida': _unidadeCtrl.text.trim(),
        'direcao_metrica': _direcaoMetrica ?? 'MAIOR_MELHOR',
        if (_naturezaKr != null) 'natureza_kr': _naturezaKr,
        if (_tipoKr != null) 'tipo_kr': _tipoKr,
        if (_freqMilestone != null) 'tipo_frequencia_milestone': _freqMilestone,
        if (_responsavelId != null) 'responsavel': _responsavelId,
        if (_cicloTipo != null) 'ciclo_tipo': _cicloTipo,
        'autogerar_milestones': _autoMilestones ? 1 : 0,
      };

      if (isEditing) {
        await api.dio.put('/krs/${widget.idKr}', data: body);
      } else {
        body['id_objetivo'] = _idObjetivo;
        await api.dio.post('/krs', data: body);
      }

      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(isEditing ? 'KR atualizado!' : 'KR criado!')),
        );
        context.pop(true);
      }
    } catch (e) {
      setState(() => _isLoading = false);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Erro: $e')));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final naturezas = ref.watch(domainProvider('dom_natureza_kr'));
    final tiposKr = ref.watch(domainProvider('dom_tipo_kr'));
    final freqs = ref.watch(domainProvider('dom_tipo_frequencia_milestone'));
    final ciclos = ref.watch(domainProvider('dom_ciclos'));
    final responsaveis = ref.watch(responsaveisProvider);

    return Scaffold(
      appBar: AppBar(title: Text(isEditing ? 'Editar Key Result' : 'Novo Key Result')),
      body: _isLoadingEdit
          ? const LoadingShimmer()
          : Form(
              key: _formKey,
              child: ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  // Descrição
                  TextFormField(
                    controller: _descricaoCtrl,
                    maxLines: 2,
                    decoration: const InputDecoration(labelText: 'Descrição do KR *'),
                    validator: (v) => (v == null || v.trim().isEmpty) ? 'Obrigatório' : null,
                  ),
                  const SizedBox(height: 16),

                  // Baseline & Meta
                  Row(children: [
                    Expanded(
                      child: TextFormField(
                        controller: _baselineCtrl,
                        keyboardType: const TextInputType.numberWithOptions(decimal: true),
                        decoration: const InputDecoration(labelText: 'Baseline *'),
                        validator: (v) => (v == null || double.tryParse(v) == null) ? 'Número' : null,
                      ),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: TextFormField(
                        controller: _metaCtrl,
                        keyboardType: const TextInputType.numberWithOptions(decimal: true),
                        decoration: const InputDecoration(labelText: 'Meta *'),
                        validator: (v) => (v == null || double.tryParse(v) == null) ? 'Número' : null,
                      ),
                    ),
                  ]),
                  const SizedBox(height: 16),

                  // Unidade & Direção
                  Row(children: [
                    Expanded(
                      child: TextFormField(
                        controller: _unidadeCtrl,
                        decoration: const InputDecoration(labelText: 'Unidade', hintText: '%, R\$, un...'),
                      ),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: DropdownButtonFormField<String>(
                        initialValue: _direcaoMetrica,
                        decoration: const InputDecoration(labelText: 'Direção'),
                        items: const [
                          DropdownMenuItem(value: 'MAIOR_MELHOR', child: Text('Maior melhor')),
                          DropdownMenuItem(value: 'MENOR_MELHOR', child: Text('Menor melhor')),
                        ],
                        onChanged: (v) => setState(() => _direcaoMetrica = v),
                      ),
                    ),
                  ]),
                  const SizedBox(height: 16),

                  // Natureza
                  naturezas.when(
                    loading: () => const SizedBox.shrink(),
                    error: (_, __) => const SizedBox.shrink(),
                    data: (items) => DropdownButtonFormField<String>(
                      initialValue: _naturezaKr,
                      decoration: const InputDecoration(labelText: 'Natureza do KR'),
                      items: items.map((n) {
                        final label = n['descricao'] ?? n['natureza_kr'] ?? '';
                        final value = n['natureza_kr'] ?? n['slug'] ?? label;
                        return DropdownMenuItem(value: value.toString(), child: Text(label.toString()));
                      }).toList(),
                      onChanged: (v) => setState(() => _naturezaKr = v),
                    ),
                  ),
                  const SizedBox(height: 16),

                  // Tipo KR
                  tiposKr.when(
                    loading: () => const SizedBox.shrink(),
                    error: (_, __) => const SizedBox.shrink(),
                    data: (items) => DropdownButtonFormField<String>(
                      initialValue: _tipoKr,
                      decoration: const InputDecoration(labelText: 'Tipo do KR'),
                      items: items.map((t) {
                        final label = t['descricao'] ?? t['tipo_kr'] ?? '';
                        final value = t['tipo_kr'] ?? t['slug'] ?? label;
                        return DropdownMenuItem(value: value.toString(), child: Text(label.toString()));
                      }).toList(),
                      onChanged: (v) => setState(() => _tipoKr = v),
                    ),
                  ),
                  const SizedBox(height: 16),

                  // Frequência Milestones
                  freqs.when(
                    loading: () => const SizedBox.shrink(),
                    error: (_, __) => const SizedBox.shrink(),
                    data: (items) => DropdownButtonFormField<String>(
                      initialValue: _freqMilestone,
                      decoration: const InputDecoration(labelText: 'Frequência Milestones'),
                      items: items.map((f) {
                        final label = f['descricao'] ?? f['tipo_frequencia'] ?? '';
                        final value = f['tipo_frequencia'] ?? f['slug'] ?? label;
                        return DropdownMenuItem(value: value.toString(), child: Text(label.toString()));
                      }).toList(),
                      onChanged: (v) => setState(() => _freqMilestone = v),
                    ),
                  ),
                  const SizedBox(height: 16),

                  // Ciclo
                  if (!isEditing)
                    ciclos.when(
                      loading: () => const SizedBox.shrink(),
                      error: (_, __) => const SizedBox.shrink(),
                      data: (items) => DropdownButtonFormField<String>(
                        initialValue: _cicloTipo,
                        decoration: const InputDecoration(labelText: 'Ciclo'),
                        items: items.map((c) {
                          final label = c['descricao'] ?? c['ciclo_tipo'] ?? '';
                          final value = c['ciclo_tipo'] ?? c['id'] ?? label;
                          return DropdownMenuItem(value: value.toString(), child: Text(label.toString()));
                        }).toList(),
                        onChanged: (v) => setState(() => _cicloTipo = v),
                      ),
                    ),
                  const SizedBox(height: 16),

                  // Responsável
                  responsaveis.when(
                    loading: () => const LinearProgressIndicator(),
                    error: (_, __) => const SizedBox.shrink(),
                    data: (users) => DropdownButtonFormField<int>(
                      initialValue: _responsavelId,
                      decoration: const InputDecoration(labelText: 'Responsável'),
                      items: users.map((u) => DropdownMenuItem(
                        value: u['id_user'] as int,
                        child: Text(u['nome_completo'] ?? '${u['primeiro_nome']} ${u['ultimo_nome']}'),
                      )).toList(),
                      onChanged: (v) => setState(() => _responsavelId = v),
                    ),
                  ),
                  const SizedBox(height: 16),

                  // Auto-generate milestones
                  if (!isEditing)
                    SwitchListTile(
                      value: _autoMilestones,
                      onChanged: (v) => setState(() => _autoMilestones = v),
                      title: const Text('Gerar milestones automaticamente', style: TextStyle(fontSize: 14)),
                      subtitle: const Text('Baseado no ciclo e frequência', style: TextStyle(fontSize: 12, color: AppColors.textMuted)),
                      activeThumbColor: AppColors.gold,
                      contentPadding: EdgeInsets.zero,
                    ),
                  const SizedBox(height: 24),

                  // Submit
                  SizedBox(
                    width: double.infinity,
                    height: 50,
                    child: ElevatedButton(
                      onPressed: _isLoading ? null : _submit,
                      child: _isLoading
                          ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.5, color: AppColors.bgSoft))
                          : Text(isEditing ? 'Salvar Alterações' : 'Criar Key Result'),
                    ),
                  ),
                ],
              ),
            ),
    );
  }
}
