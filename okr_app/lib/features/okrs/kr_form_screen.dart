import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../core/network/api_client.dart';
import '../../core/models/models.dart';
import '../../core/repositories/repositories.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/haptics.dart';
import '../../core/providers/domain_providers.dart';
import '../../core/utils/milestone_calc.dart';
import '../shared/widgets/loading_shimmer.dart';
import '../shared/widgets/app_scaffold.dart';
import '../shared/widgets/ciclo_selector.dart';

/// Objetivos disponíveis para vincular um novo KR (seleção no topo do form
/// quando ele é aberto sem um objetivo-pai, como no "Novo KR" do botão +).
final _objetivosParaKrProvider = FutureProvider.autoDispose<List<Objetivo>>((ref) async {
  final paged = await ref.read(objetivoRepositoryProvider).list(perPage: 200);
  return paged.items;
});

/// Entrada de um sócio no formulário (usuário + motivo do convite).
class _SocioInput {
  int? userId;
  final TextEditingController motivo = TextEditingController();
}

class KrFormScreen extends ConsumerStatefulWidget {
  final String? idKr;
  final String? idObjetivo;
  const KrFormScreen({super.key, this.idKr, this.idObjetivo});

  @override
  ConsumerState<KrFormScreen> createState() => _KrFormScreenState();
}

class _KrFormScreenState extends ConsumerState<KrFormScreen> {
  final _formKey = GlobalKey<FormState>();
  final _descricaoCtrl = TextEditingController();
  final _baselineCtrl = TextEditingController(text: '0');
  final _metaCtrl = TextEditingController();
  final _margemCtrl = TextEditingController();
  final _observacoesCtrl = TextEditingController();

  String? _direcaoMetrica = 'MAIOR_MELHOR';
  String? _naturezaKr;
  String? _tipoKr;
  String? _freqMilestone;
  String? _statusKr;
  String? _unidade;
  bool _statusDefaulted = false;
  int? _responsavelId;
  String? _selectedObjetivoId;
  bool _autoMilestones = true;
  bool _isLoading = false;
  bool _isLoadingEdit = true;

  /// Unidades curadas — paridade com o dropdown do web (views/novo_key_result.php).
  static const List<MapEntry<String, String>> _unidades = [
    MapEntry('R\$', 'Real (R\$)'),
    MapEntry('US\$', 'Dólar (US\$)'),
    MapEntry('€', 'Euro (€)'),
    MapEntry('£', 'Libra (£)'),
    MapEntry('R\$/unid', 'Custo por unidade (R\$/unid)'),
    MapEntry('%', 'Percentual (%)'),
    MapEntry('pp', 'Ponto percentual (pp)'),
    MapEntry('taxa_conversao', '% Conversão'),
    MapEntry('taxa_churn', '% Churn'),
    MapEntry('unid', 'Unidade (unid)'),
    MapEntry('itens', 'Itens'),
    MapEntry('pcs', 'Peças'),
    MapEntry('ord', 'Ordens'),
    MapEntry('proc', 'Processos'),
    MapEntry('contratos', 'Contratos'),
    MapEntry('pessoas', 'Pessoas'),
    MapEntry('tickets', 'Tickets'),
    MapEntry('visitas', 'Visitas'),
    MapEntry('h', 'Hora (h)'),
    MapEntry('d', 'Dia (d)'),
    MapEntry('sem', 'Semana (sem)'),
    MapEntry('mês', 'Mês'),
    MapEntry('tri', 'Trimestre (tri)'),
    MapEntry('a', 'Ano (a)'),
    MapEntry('pts', 'Pontos (pts)'),
    MapEntry('nps', 'NPS'),
    MapEntry('rating', 'Rating'),
    MapEntry('km', 'Quilômetro (km)'),
    MapEntry('m', 'Metro (m)'),
    MapEntry('L', 'Litro (L)'),
    MapEntry('m3', 'Metro cúbico (m³)'),
    MapEntry('kg', 'Quilograma (kg)'),
    MapEntry('g', 'Grama (g)'),
  ];

  // Ciclo + período derivado (para a prévia de milestones, idêntica ao web).
  Map<String, dynamic> _cicloParams = {};
  DateTime? _cicloInicio;
  DateTime? _cicloFim;

  // Sócios (até 3) — convites a serem criados junto com o KR.
  final List<_SocioInput> _socios = [];

  bool get isEditing => widget.idKr != null;
  /// Mostra o seletor de objetivo quando o form é aberto sem um objetivo-pai.
  bool get _precisaSelecionarObjetivo => !isEditing && widget.idObjetivo == null;
  String get _idObjetivo => widget.idObjetivo ?? _selectedObjetivoId ?? '';

  /// Primeiro valor não-vazio dentre [keys] (as tabelas dom_* têm nomes de
  /// coluna específicos: id_natureza, id_tipo, id_frequencia, nome_ciclo...).
  String _domVal(Map<String, dynamic> m, List<String> keys) {
    for (final k in keys) {
      final v = m[k];
      if (v != null && v.toString().isNotEmpty) return v.toString();
    }
    return '';
  }

  bool get _isBinario => _naturezaKr != null && MilestoneCalc.normNat(_naturezaKr!) == 'binario';
  bool get _isIntervalo => (_direcaoMetrica ?? '').toUpperCase() == 'INTERVALO_IDEAL';

  /// Prévia ao vivo dos milestones — idêntica ao que o backend gera ao salvar.
  Widget _buildMilestonePreview() {
    final freq = _freqMilestone;
    final ini = _cicloInicio;
    final fim = _cicloFim;
    final base = double.tryParse(_baselineCtrl.text.replaceAll(',', '.'));
    final meta = double.tryParse(_metaCtrl.text.replaceAll(',', '.'));
    if (freq == null || freq.isEmpty || ini == null || fim == null || base == null || meta == null) {
      return const SizedBox.shrink();
    }
    final unidade = _unidade?.trim() ?? '';
    final ms = MilestoneCalc.gerar(
      inicio: ini,
      fim: fim,
      frequencia: freq,
      baseline: base,
      meta: meta,
      natureza: _naturezaKr ?? 'acumulativo_constante',
      direcao: _direcaoMetrica,
      unidade: unidade,
    );
    if (ms.isEmpty) return const SizedBox.shrink();

    final isInt = MilestoneCalc.unidadeRequerInteiro(unidade);
    String fmt(double v) => isInt ? v.toStringAsFixed(0) : v.toStringAsFixed(2).replaceAll('.', ',');
    String dt(DateTime x) =>
        '${x.day.toString().padLeft(2, '0')}/${x.month.toString().padLeft(2, '0')}/${x.year}';
    final intervalo = ms.first.isIntervalo;

    const muted = TextStyle(fontSize: 11, color: AppColors.textMuted, fontWeight: FontWeight.w600);
    const cell = TextStyle(fontSize: 12);

    Widget linha(String a, String b, String c, String? d, {bool header = false}) {
      final st = header ? muted : cell;
      return Padding(
        padding: const EdgeInsets.symmetric(vertical: 3),
        child: Row(children: [
          SizedBox(width: 26, child: Text(a, style: st)),
          Expanded(child: Text(b, style: st)),
          SizedBox(width: 96, child: Text(c, textAlign: TextAlign.right, style: st)),
          if (d != null) SizedBox(width: 96, child: Text(d, textAlign: TextAlign.right, style: st)),
        ]),
      );
    }

    return Container(
      decoration: BoxDecoration(
        color: AppColors.bgCard,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.borderDefault, width: 0.5),
      ),
      padding: const EdgeInsets.all(12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(children: [
            const Icon(Icons.science_outlined, size: 18, color: AppColors.gold),
            const SizedBox(width: 6),
            Expanded(
              child: Text(
                intervalo ? 'Prévia de milestones (faixa ideal)' : 'Prévia de milestones',
                style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13),
              ),
            ),
            Text('${ms.length}', style: const TextStyle(color: AppColors.gold, fontWeight: FontWeight.w700)),
          ]),
          const Divider(height: 16, color: AppColors.borderDefault),
          if (intervalo)
            linha('#', 'Data ref.', 'Mín', 'Máx', header: true)
          else
            linha('#', 'Data ref.', 'Esperado', null, header: true),
          ...ms.map((m) => intervalo
              ? linha('${m.ordem}', dt(m.dataRef), fmt(m.esperadoMin!), fmt(m.esperadoMax!))
              : linha('${m.ordem}', dt(m.dataRef), fmt(m.esperado!), null)),
        ],
      ),
    );
  }

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
        final u = (kr['unidade_medida'] as String?)?.trim();
        _unidade = (u == null || u.isEmpty) ? null : u;
        _margemCtrl.text = kr['margem_confianca'] != null ? '${kr['margem_confianca']}' : '';
        _observacoesCtrl.text = kr['observacoes'] ?? '';
        _direcaoMetrica = kr['direcao_metrica'] as String?;
        _naturezaKr = kr['natureza_kr'] as String?;
        _tipoKr = kr['tipo_kr'] as String?;
        _statusKr = kr['status'] as String?;
        _freqMilestone = kr['tipo_frequencia_milestone'] as String?;
        final resp = kr['responsavel'] as Map<String, dynamic>?;
        _responsavelId = resp?['id_user'] as int?;
        _isLoadingEdit = false;
      });
    } catch (e) {
      setState(() => _isLoadingEdit = false);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(apiErrorMessage(e))));
      }
    }
  }

  @override
  void dispose() {
    _descricaoCtrl.dispose();
    _baselineCtrl.dispose();
    _metaCtrl.dispose();
    _margemCtrl.dispose();
    _observacoesCtrl.dispose();
    for (final s in _socios) {
      s.motivo.dispose();
    }
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;

    // Sócios: cada linha adicionada precisa de usuário + motivo da sociedade.
    final sociosBody = <Map<String, dynamic>>[];
    for (final s in _socios) {
      if (s.userId == null || s.motivo.text.trim().isEmpty) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Cada sócio precisa de um usuário e o motivo da sociedade.')),
        );
        return;
      }
      sociosBody.add({'id_user': s.userId, 'motivo': s.motivo.text.trim()});
    }

    AppHaptics.medium();

    setState(() => _isLoading = true);
    try {
      final body = <String, dynamic>{
        'descricao': _descricaoCtrl.text.trim(),
        'baseline': double.tryParse(_baselineCtrl.text) ?? 0,
        'meta': double.tryParse(_metaCtrl.text) ?? 0,
        'unidade_medida': _unidade?.trim() ?? '',
        'direcao_metrica': _direcaoMetrica ?? 'MAIOR_MELHOR',
        if (_naturezaKr != null) 'natureza_kr': _naturezaKr,
        if (_tipoKr != null) 'tipo_kr': _tipoKr,
        if (_statusKr != null) 'status': _statusKr,
        if (_freqMilestone != null) 'tipo_frequencia_milestone': _freqMilestone,
        if (_responsavelId != null) 'responsavel': _responsavelId,
        if (_margemCtrl.text.trim().isNotEmpty)
          'margem_confianca': double.tryParse(_margemCtrl.text.trim().replaceAll(',', '.')),
        'observacoes': _observacoesCtrl.text.trim(),
        if (sociosBody.isNotEmpty) 'socios': sociosBody,
        // ciclo_tipo + sub-parâmetros → backend calcula datas e gera milestones.
        ..._cicloParams,
        'autogerar_milestones': _autoMilestones ? 1 : 0,
      };

      final repo = ref.read(krRepositoryProvider);
      if (isEditing) {
        await repo.update(widget.idKr!, body);
      } else {
        body['id_objetivo'] = _idObjetivo;
        await repo.create(body);
      }

      AppHaptics.success();
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(isEditing ? 'KR atualizado!' : 'KR criado!')),
        );
        context.pop(true);
      }
    } catch (e) {
      setState(() => _isLoading = false);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(apiErrorMessage(e))));
      }
    }
  }

  /// Seção "Sócios (até 3)" — usuário + motivo do convite por sócio.
  Widget _buildSociosSection() {
    final users = ref.watch(responsaveisProvider).maybeWhen(
          data: (u) => u,
          orElse: () => const <Map<String, dynamic>>[],
        );
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(children: [
          const Text('Sócios (até 3)', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 14)),
          const Spacer(),
          if (_socios.length < 3)
            TextButton.icon(
              onPressed: () => setState(() => _socios.add(_SocioInput())),
              icon: const Icon(Icons.person_add_alt_1, size: 18),
              label: const Text('Adicionar'),
            ),
        ]),
        const Text('Cada sócio precisa aprovar o convite. Informe o motivo da sociedade.',
            style: TextStyle(fontSize: 11, color: AppColors.textMuted)),
        const SizedBox(height: 8),
        ...List.generate(_socios.length, (i) {
          final s = _socios[i];
          final escolhidos = _socios.where((x) => x != s).map((x) => x.userId).whereType<int>().toSet();
          final opcoes = users.where((u) {
            final id = u['id_user'] as int?;
            return id != null && id != _responsavelId && !escolhidos.contains(id);
          }).toList();
          return Card(
            margin: const EdgeInsets.only(bottom: 8),
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Column(children: [
                Row(children: [
                  Expanded(
                    child: DropdownButtonFormField<int>(
                      initialValue: s.userId,
                      isExpanded: true,
                      decoration: const InputDecoration(labelText: 'Sócio *'),
                      items: opcoes
                          .map((u) => DropdownMenuItem(
                                value: u['id_user'] as int,
                                child: Text(
                                  u['nome_completo'] ?? '${u['primeiro_nome']} ${u['ultimo_nome']}',
                                  maxLines: 1, overflow: TextOverflow.ellipsis,
                                ),
                              ))
                          .toList(),
                      onChanged: (v) => setState(() => s.userId = v),
                    ),
                  ),
                  IconButton(
                    icon: const Icon(Icons.delete_outline, color: AppColors.red),
                    onPressed: () => setState(() {
                      s.motivo.dispose();
                      _socios.removeAt(i);
                    }),
                  ),
                ]),
                const SizedBox(height: 8),
                TextFormField(
                  controller: s.motivo,
                  maxLines: 2,
                  decoration: const InputDecoration(labelText: 'Motivo da sociedade *'),
                ),
              ]),
            ),
          );
        }),
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    final naturezas = ref.watch(domainProvider('dom_natureza_kr'));
    final tiposKr = ref.watch(domainProvider('dom_tipo_kr'));
    final freqs = ref.watch(domainProvider('dom_tipo_frequencia_milestone'));
    final statusKrDom = ref.watch(domainProvider('dom_status_kr'));
    final responsaveis = ref.watch(responsaveisProvider);

    return AppScaffold(
      title: isEditing ? 'Editar Key Result' : 'Novo Key Result',
      body: _isLoadingEdit
          ? const LoadingShimmer()
          : Form(
              key: _formKey,
              child: ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  // 1º campo (como no web): vincular o KR a um objetivo existente.
                  if (_precisaSelecionarObjetivo) ...[
                    ref.watch(_objetivosParaKrProvider).when(
                      loading: () => const LinearProgressIndicator(),
                      error: (_, __) => const Text('Erro ao carregar objetivos'),
                      data: (objs) => DropdownButtonFormField<String>(
                        initialValue: _selectedObjetivoId,
                        isExpanded: true,
                        decoration: const InputDecoration(labelText: 'Objetivo *'),
                        items: objs
                            .map((o) => DropdownMenuItem(
                                  value: o.idObjetivo,
                                  child: Text(o.descricao, maxLines: 1, overflow: TextOverflow.ellipsis),
                                ))
                            .toList(),
                        onChanged: (v) => setState(() => _selectedObjetivoId = v),
                        validator: (v) => (v == null || v.isEmpty) ? 'Selecione o objetivo' : null,
                      ),
                    ),
                    const SizedBox(height: 16),
                  ],
                  TextFormField(
                    controller: _descricaoCtrl,
                    maxLines: 2,
                    decoration: const InputDecoration(labelText: 'Descrição do KR *'),
                    validator: (v) => (v == null || v.trim().isEmpty) ? 'Obrigatório' : null,
                  ),
                  const SizedBox(height: 16),
                  Row(children: [
                    Expanded(
                      child: TextFormField(
                        controller: _baselineCtrl,
                        readOnly: _isBinario,
                        keyboardType: const TextInputType.numberWithOptions(decimal: true),
                        decoration: const InputDecoration(labelText: 'Baseline *'),
                        validator: (v) => (v == null || double.tryParse(v) == null) ? 'Número' : null,
                        onChanged: (_) => setState(() {}),
                      ),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: TextFormField(
                        controller: _metaCtrl,
                        readOnly: _isBinario,
                        keyboardType: const TextInputType.numberWithOptions(decimal: true),
                        decoration: const InputDecoration(labelText: 'Meta *'),
                        validator: (v) => (v == null || double.tryParse(v) == null) ? 'Número' : null,
                        onChanged: (_) => setState(() {}),
                      ),
                    ),
                  ]),
                  const SizedBox(height: 16),
                  Row(children: [
                    Expanded(
                      child: DropdownButtonFormField<String>(
                        initialValue: _unidade,
                        isExpanded: true,
                        decoration: const InputDecoration(labelText: 'Unidade'),
                        items: [
                          // Preserva valor legado/custom que não esteja na lista curada (edição).
                          if (_unidade != null &&
                              _unidade!.isNotEmpty &&
                              !_unidades.any((u) => u.key == _unidade))
                            DropdownMenuItem(value: _unidade, child: Text(_unidade!)),
                          ..._unidades.map((u) => DropdownMenuItem(value: u.key, child: Text(u.value))),
                        ],
                        onChanged: (v) => setState(() => _unidade = v),
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
                          DropdownMenuItem(value: 'INTERVALO_IDEAL', child: Text('Intervalo ideal')),
                        ],
                        onChanged: (v) => setState(() {
                          _direcaoMetrica = v;
                          // Web: INTERVALO_IDEAL força natureza "pontual".
                          if ((v ?? '').toUpperCase() == 'INTERVALO_IDEAL') _naturezaKr = 'pontual';
                        }),
                      ),
                    ),
                  ]),
                  const SizedBox(height: 16),
                  TextFormField(
                    controller: _margemCtrl,
                    keyboardType: const TextInputType.numberWithOptions(decimal: true),
                    decoration: const InputDecoration(labelText: 'Margem de confiança (%)', hintText: 'Opcional'),
                  ),
                  const SizedBox(height: 16),
                  naturezas.when(
                    loading: () => const SizedBox.shrink(),
                    error: (_, __) => const SizedBox.shrink(),
                    data: (items) => DropdownButtonFormField<String>(
                      initialValue: _naturezaKr,
                      decoration: const InputDecoration(labelText: 'Natureza do KR'),
                      items: items.map((n) {
                        final value = _domVal(n, ['id_natureza', 'natureza_kr', 'slug']);
                        final label = _domVal(n, ['descricao_exibicao', 'descricao', 'id_natureza']);
                        return DropdownMenuItem(value: value, child: Text(label));
                      }).toList(),
                      // INTERVALO_IDEAL trava a natureza em "pontual" (igual ao web).
                      onChanged: _isIntervalo
                          ? null
                          : (v) => setState(() {
                                _naturezaKr = v;
                                if (MilestoneCalc.normNat(v ?? '') == 'binario') {
                                  _baselineCtrl.text = '0';
                                  _metaCtrl.text = '1';
                                }
                              }),
                    ),
                  ),
                  const SizedBox(height: 16),
                  tiposKr.when(
                    loading: () => const SizedBox.shrink(),
                    error: (_, __) => const SizedBox.shrink(),
                    data: (items) => DropdownButtonFormField<String>(
                      initialValue: _tipoKr,
                      decoration: const InputDecoration(labelText: 'Tipo do KR'),
                      items: items.map((t) {
                        final value = _domVal(t, ['id_tipo', 'tipo_kr', 'slug']);
                        final label = _domVal(t, ['descricao_exibicao', 'descricao', 'id_tipo']);
                        return DropdownMenuItem(value: value, child: Text(label));
                      }).toList(),
                      onChanged: (v) => setState(() => _tipoKr = v),
                    ),
                  ),
                  const SizedBox(height: 16),
                  freqs.when(
                    loading: () => const SizedBox.shrink(),
                    error: (_, __) => const SizedBox.shrink(),
                    data: (items) => DropdownButtonFormField<String>(
                      initialValue: _freqMilestone,
                      decoration: const InputDecoration(labelText: 'Frequência de apontamento *'),
                      items: items.map((f) {
                        final value = _domVal(f, ['id_frequencia', 'tipo_frequencia', 'slug']);
                        final label = _domVal(f, ['descricao_exibicao', 'descricao', 'id_frequencia']);
                        return DropdownMenuItem(value: value, child: Text(label));
                      }).toList(),
                      onChanged: (v) => setState(() => _freqMilestone = v),
                      validator: (v) => (v == null || v.isEmpty) ? 'Obrigatório' : null,
                    ),
                  ),
                  const SizedBox(height: 16),
                  if (!isEditing) ...[
                    CicloSelector(
                      onChanged: (tipo, params, ini, fim) => setState(() {
                        _cicloParams = params;
                        _cicloInicio = ini;
                        _cicloFim = fim;
                      }),
                    ),
                    const SizedBox(height: 16),
                    _buildMilestonePreview(),
                  ],
                  const SizedBox(height: 16),
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
                  statusKrDom.when(
                    loading: () => const SizedBox.shrink(),
                    error: (_, __) => const SizedBox.shrink(),
                    data: (items) {
                      // Default "Não Iniciado" na criação (espelha ensureDefaultStatus do web).
                      if (!isEditing && _statusKr == null && !_statusDefaulted && items.isNotEmpty) {
                        _statusDefaulted = true;
                        final ni = items.firstWhere(
                          (s) {
                            final v = _domVal(s, ['id_status', 'status', 'slug']).toLowerCase();
                            final l = _domVal(s, ['descricao_exibicao', 'descricao']).toLowerCase();
                            return v.contains('inicia') || l.contains('inicia');
                          },
                          orElse: () => items.first,
                        );
                        final niVal = _domVal(ni, ['id_status', 'status', 'slug']);
                        WidgetsBinding.instance.addPostFrameCallback((_) {
                          if (mounted && niVal.isNotEmpty) setState(() => _statusKr = niVal);
                        });
                      }
                      return DropdownButtonFormField<String>(
                        initialValue: _statusKr,
                        decoration: const InputDecoration(labelText: 'Status do KR'),
                        items: items.map((s) {
                          final value = _domVal(s, ['id_status', 'status', 'slug']);
                          final label = _domVal(s, ['descricao_exibicao', 'descricao', 'id_status']);
                          return DropdownMenuItem(value: value, child: Text(label));
                        }).toList(),
                        onChanged: (v) => setState(() => _statusKr = v),
                      );
                    },
                  ),
                  const SizedBox(height: 16),
                  TextFormField(
                    controller: _observacoesCtrl,
                    maxLines: 3,
                    decoration: const InputDecoration(labelText: 'Observações', hintText: 'Opcional'),
                  ),
                  const SizedBox(height: 16),
                  if (!isEditing) ...[
                    _buildSociosSection(),
                    const SizedBox(height: 16),
                  ],
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
                  SizedBox(
                    width: double.infinity,
                    height: 50,
                    child: ElevatedButton(
                      onPressed: _isLoading ? null : _submit,
                      child: _isLoading
                          ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.5, color: AppColors.bgDeep))
                          : Text(isEditing ? 'Salvar Alterações' : 'Criar Key Result'),
                    ),
                  ),
                ],
              ),
            ),
    );
  }
}
