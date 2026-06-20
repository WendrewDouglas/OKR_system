import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../core/network/api_client.dart';
import '../../core/repositories/repositories.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/haptics.dart';
import '../../core/providers/domain_providers.dart';
import '../shared/widgets/loading_shimmer.dart';
import '../shared/widgets/app_scaffold.dart';

class ObjetivoFormScreen extends ConsumerStatefulWidget {
  final String? idObjetivo;
  const ObjetivoFormScreen({super.key, this.idObjetivo});

  @override
  ConsumerState<ObjetivoFormScreen> createState() => _ObjetivoFormScreenState();
}

class _ObjetivoFormScreenState extends ConsumerState<ObjetivoFormScreen> {
  final _formKey = GlobalKey<FormState>();
  final _descricaoCtrl = TextEditingController();
  final _observacoesCtrl = TextEditingController();
  final _justificativaCtrl = TextEditingController();
  final _persInicioCtrl = TextEditingController();
  final _persFimCtrl = TextEditingController();

  String? _pilarBsc;
  String? _tipoObjetivo;
  String? _cicloTipo;
  int? _donoId;
  bool _isLoading = false;
  bool _isLoadingEdit = true;

  // Sub-seleções de ciclo (formatos esperados por calcularDatasCiclo no backend).
  String? _cicloAnualAno;        // "2026"
  String? _cicloSemestral;       // "S1/2026"
  String? _cicloTrimestral;      // "Q1/2026"
  String? _cicloBimestral;       // "01-02-2026"
  int? _cicloMensalMes;          // 1..12
  String? _cicloMensalAno;       // "2026"
  String? _cicloPersInicio;      // "2026-03"
  String? _cicloPersFim;         // "2026-06"

  static const List<String> _meses = [
    'Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez',
  ];
  List<int> get _anos {
    final y = DateTime.now().year;
    return [for (int a = y - 1; a <= y + 3; a++) a];
  }

  bool get isEditing => widget.idObjetivo != null;

  @override
  void initState() {
    super.initState();
    if (isEditing) {
      _loadObjective();
    } else {
      _isLoadingEdit = false;
    }
  }

  Future<void> _loadObjective() async {
    try {
      final api = ref.read(apiClientProvider);
      final res = await api.dio.get('/objetivos/${widget.idObjetivo}');
      final obj = res.data['objetivo'] as Map<String, dynamic>? ?? {};
      setState(() {
        _descricaoCtrl.text = obj['descricao'] ?? '';
        _observacoesCtrl.text = obj['observacoes'] ?? '';
        _pilarBsc = obj['pilar_bsc'] as String?;
        _tipoObjetivo = obj['tipo'] as String?;
        _cicloTipo = obj['tipo_ciclo'] as String?;
        _donoId = obj['dono'] is Map ? (obj['dono'] as Map)['id_user'] as int? : obj['dono'] as int?;
        // Pré-preenche o detalhe do ciclo personalizado a partir das datas salvas
        // (dt_inicio/dt_prazo em ISO YYYY-MM-DD), para o usuário ver o período atual.
        if (_cicloTipo == 'personalizado') {
          final di = (obj['dt_inicio'] as String?)?.trim();
          final df = (obj['dt_prazo'] as String?)?.trim();
          if (di != null && di.length >= 10) {
            _cicloPersInicio = di.substring(0, 10);
            _persInicioCtrl.text = _isoToBr(di.substring(0, 10));
          }
          if (df != null && df.length >= 10) {
            _cicloPersFim = df.substring(0, 10);
            _persFimCtrl.text = _isoToBr(df.substring(0, 10));
          }
        }
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
    _observacoesCtrl.dispose();
    _justificativaCtrl.dispose();
    _persInicioCtrl.dispose();
    _persFimCtrl.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    if (_pilarBsc == null || _cicloTipo == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Selecione pilar BSC e ciclo.')),
      );
      return;
    }
    // No cadastro, o backend calcula dt_inicio/dt_prazo a partir do detalhe do
    // ciclo (calcularDatasCiclo) — então o detalhe é obrigatório ao criar.
    if (!isEditing && !_cicloParamPreenchido()) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Preencha o detalhe do ciclo selecionado.')),
      );
      return;
    }

    AppHaptics.medium();
    setState(() => _isLoading = true);
    try {
      final body = <String, dynamic>{
        'descricao': _descricaoCtrl.text.trim(),
        'pilar_bsc': _pilarBsc,
        'tipo_objetivo': _tipoObjetivo ?? '',
        'ciclo_tipo': _cicloTipo,
        'observacoes': _observacoesCtrl.text.trim(),
        if (_donoId != null) 'dono': _donoId,
        if (isEditing) 'justificativa': _justificativaCtrl.text.trim(),
        ..._cicloParams(),
      };

      final repo = ref.read(objetivoRepositoryProvider);
      if (isEditing) {
        await repo.update(widget.idObjetivo!, body);
      } else {
        await repo.create(body);
      }

      AppHaptics.success();
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(isEditing ? 'Objetivo atualizado!' : 'Objetivo criado!')),
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

  /// Primeiro valor não-vazio dentre [keys] em um item de domínio.
  /// As tabelas dom_* têm nomes de coluna específicos (id_pilar, id_tipo,
  /// nome_ciclo, descricao_exibicao); este helper resolve com fallback.
  String _domVal(Map<String, dynamic> m, List<String> keys) {
    for (final k in keys) {
      final v = m[k];
      if (v != null && v.toString().isNotEmpty) return v.toString();
    }
    return '';
  }

  /// O detalhe do ciclo selecionado foi preenchido?
  bool _cicloParamPreenchido() {
    switch (_cicloTipo) {
      case 'anual':
        return _cicloAnualAno != null;
      case 'semestral':
        return _cicloSemestral != null;
      case 'trimestral':
        return _cicloTrimestral != null;
      case 'bimestral':
        return _cicloBimestral != null;
      case 'mensal':
        return _cicloMensalMes != null && _cicloMensalAno != null;
      case 'personalizado':
        return _cicloPersInicio != null && _cicloPersFim != null;
      default:
        return true; // tipo sem detalhe específico
    }
  }

  /// Parâmetros de ciclo no formato que o backend (calcularDatasCiclo) espera.
  Map<String, dynamic> _cicloParams() {
    switch (_cicloTipo) {
      case 'anual':
        return {if (_cicloAnualAno != null) 'ciclo_anual_ano': _cicloAnualAno};
      case 'semestral':
        return {if (_cicloSemestral != null) 'ciclo_semestral': _cicloSemestral};
      case 'trimestral':
        return {if (_cicloTrimestral != null) 'ciclo_trimestral': _cicloTrimestral};
      case 'bimestral':
        return {if (_cicloBimestral != null) 'ciclo_bimestral': _cicloBimestral};
      case 'mensal':
        return {
          if (_cicloMensalMes != null) 'ciclo_mensal_mes': _cicloMensalMes,
          if (_cicloMensalAno != null) 'ciclo_mensal_ano': _cicloMensalAno,
        };
      case 'personalizado':
        return {
          if (_cicloPersInicio != null) 'ciclo_pers_inicio': _cicloPersInicio,
          if (_cicloPersFim != null) 'ciclo_pers_fim': _cicloPersFim,
        };
      default:
        return {};
    }
  }

  void _resetCicloParams() {
    _cicloAnualAno = null;
    _cicloSemestral = null;
    _cicloTrimestral = null;
    _cicloBimestral = null;
    _cicloMensalMes = null;
    _cicloMensalAno = null;
    _cicloPersInicio = null;
    _cicloPersFim = null;
    _persInicioCtrl.clear();
    _persFimCtrl.clear();
  }

  /// Converte ISO (YYYY-MM-DD) para exibição dd/MM/aaaa.
  String _isoToBr(String iso) {
    final p = iso.split('-');
    return p.length == 3 ? '${p[2]}/${p[1]}/${p[0]}' : iso;
  }

  /// Seletor de data precisa (dia/mês/ano) — usado no ciclo Personalizado.
  /// Reporta no formato ISO (YYYY-MM-DD) esperado pelo backend e exibe dd/MM/aaaa.
  Future<void> _pickDate(TextEditingController ctrl, ValueChanged<String> onPicked) async {
    final now = DateTime.now();
    final dt = await showDatePicker(
      context: context,
      initialDate: now,
      firstDate: DateTime(now.year - 5),
      lastDate: DateTime(now.year + 6, 12, 31),
    );
    if (dt != null) {
      final iso = '${dt.year.toString().padLeft(4, '0')}-${dt.month.toString().padLeft(2, '0')}-${dt.day.toString().padLeft(2, '0')}';
      onPicked(iso);
      ctrl.text = '${dt.day.toString().padLeft(2, '0')}/${dt.month.toString().padLeft(2, '0')}/${dt.year}';
    }
  }

  /// Sub-seletor de ciclo conforme o tipo escolhido (paridade com o web).
  Widget _buildCicloParam() {
    switch (_cicloTipo) {
      case 'anual':
        return DropdownButtonFormField<String>(
          initialValue: _cicloAnualAno,
          decoration: const InputDecoration(labelText: 'Ano *'),
          items: _anos.map((a) => DropdownMenuItem(value: '$a', child: Text('$a'))).toList(),
          onChanged: (v) => setState(() => _cicloAnualAno = v),
        );
      case 'semestral':
        final opts = [for (final a in _anos) for (final s in [1, 2]) 'S$s/$a'];
        return DropdownButtonFormField<String>(
          initialValue: _cicloSemestral,
          isExpanded: true,
          decoration: const InputDecoration(labelText: 'Semestre *'),
          items: opts.map((o) => DropdownMenuItem(value: o, child: Text(o))).toList(),
          onChanged: (v) => setState(() => _cicloSemestral = v),
        );
      case 'trimestral':
        final opts = [for (final a in _anos) for (final q in [1, 2, 3, 4]) 'Q$q/$a'];
        return DropdownButtonFormField<String>(
          initialValue: _cicloTrimestral,
          isExpanded: true,
          decoration: const InputDecoration(labelText: 'Trimestre *'),
          items: opts.map((o) => DropdownMenuItem(value: o, child: Text(o))).toList(),
          onChanged: (v) => setState(() => _cicloTrimestral = v),
        );
      case 'bimestral':
        const pairs = [['01', '02'], ['03', '04'], ['05', '06'], ['07', '08'], ['09', '10'], ['11', '12']];
        final opts = <MapEntry<String, String>>[];
        for (final a in _anos) {
          for (final p in pairs) {
            final value = '${p[0]}-${p[1]}-$a';
            final label = '${_meses[int.parse(p[0]) - 1]}–${_meses[int.parse(p[1]) - 1]}/$a';
            opts.add(MapEntry(value, label));
          }
        }
        return DropdownButtonFormField<String>(
          initialValue: _cicloBimestral,
          isExpanded: true,
          decoration: const InputDecoration(labelText: 'Bimestre *'),
          items: opts.map((o) => DropdownMenuItem(value: o.key, child: Text(o.value))).toList(),
          onChanged: (v) => setState(() => _cicloBimestral = v),
        );
      case 'mensal':
        return Row(children: [
          Expanded(
            child: DropdownButtonFormField<int>(
              initialValue: _cicloMensalMes,
              isExpanded: true,
              decoration: const InputDecoration(labelText: 'Mês *'),
              items: [for (int m = 1; m <= 12; m++) DropdownMenuItem(value: m, child: Text(_meses[m - 1]))],
              onChanged: (v) => setState(() => _cicloMensalMes = v),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: DropdownButtonFormField<String>(
              initialValue: _cicloMensalAno,
              isExpanded: true,
              decoration: const InputDecoration(labelText: 'Ano *'),
              items: _anos.map((a) => DropdownMenuItem(value: '$a', child: Text('$a'))).toList(),
              onChanged: (v) => setState(() => _cicloMensalAno = v),
            ),
          ),
        ]);
      case 'personalizado':
        return Row(children: [
          Expanded(
            child: TextFormField(
              decoration: const InputDecoration(labelText: 'Data início'),
              readOnly: true,
              controller: _persInicioCtrl,
              onTap: () => _pickDate(_persInicioCtrl, (d) => setState(() => _cicloPersInicio = d)),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: TextFormField(
              decoration: const InputDecoration(labelText: 'Data fim'),
              readOnly: true,
              controller: _persFimCtrl,
              onTap: () => _pickDate(_persFimCtrl, (d) => setState(() => _cicloPersFim = d)),
            ),
          ),
        ]);
      default:
        return const SizedBox.shrink();
    }
  }

  @override
  Widget build(BuildContext context) {
    final pilares = ref.watch(domainProvider('dom_pilar_bsc'));
    final tipos = ref.watch(domainProvider('dom_tipo_objetivo'));
    final ciclos = ref.watch(domainProvider('dom_ciclos'));
    final responsaveis = ref.watch(responsaveisProvider);

    return AppScaffold(
      title: isEditing ? 'Editar Objetivo' : 'Novo Objetivo',
      body: _isLoadingEdit
          ? const LoadingShimmer()
          : Form(
              key: _formKey,
              child: ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  TextFormField(
                    controller: _descricaoCtrl,
                    maxLines: 3,
                    decoration: const InputDecoration(labelText: 'Descrição do Objetivo *'),
                    validator: (v) => (v == null || v.trim().isEmpty) ? 'Obrigatório' : null,
                  ),
                  const SizedBox(height: 16),
                  pilares.when(
                    loading: () => const LinearProgressIndicator(),
                    error: (_, __) => const Text('Erro ao carregar pilares'),
                    data: (items) => DropdownButtonFormField<String>(
                      initialValue: _pilarBsc,
                      decoration: const InputDecoration(labelText: 'Pilar BSC *'),
                      items: items.map((p) {
                        final value = _domVal(p, ['id_pilar', 'pilar_bsc', 'id']);
                        final label = _domVal(p, ['descricao_exibicao', 'descricao', 'id_pilar']);
                        return DropdownMenuItem(value: value, child: Text(label));
                      }).toList(),
                      onChanged: (v) => setState(() => _pilarBsc = v),
                      validator: (v) => v == null ? 'Obrigatório' : null,
                    ),
                  ),
                  const SizedBox(height: 16),
                  tipos.when(
                    loading: () => const SizedBox.shrink(),
                    error: (_, __) => const SizedBox.shrink(),
                    data: (items) => DropdownButtonFormField<String>(
                      initialValue: _tipoObjetivo,
                      decoration: const InputDecoration(labelText: 'Tipo de Objetivo *'),
                      items: items.map((t) {
                        final value = _domVal(t, ['id_tipo', 'tipo_objetivo', 'id']);
                        final label = _domVal(t, ['descricao_exibicao', 'descricao', 'id_tipo']);
                        return DropdownMenuItem(value: value, child: Text(label));
                      }).toList(),
                      onChanged: (v) => setState(() => _tipoObjetivo = v),
                      validator: (v) => (v == null || v.isEmpty) ? 'Obrigatório' : null,
                    ),
                  ),
                  const SizedBox(height: 16),
                  ciclos.when(
                    loading: () => const LinearProgressIndicator(),
                    error: (_, __) => const Text('Erro ao carregar ciclos'),
                    data: (items) => DropdownButtonFormField<String>(
                      initialValue: _cicloTipo,
                      decoration: const InputDecoration(labelText: 'Ciclo *'),
                      items: items.map((c) {
                        final value = _domVal(c, ['nome_ciclo', 'ciclo_tipo', 'id_ciclo']);
                        final label = _domVal(c, ['descricao', 'nome_ciclo']);
                        return DropdownMenuItem(value: value, child: Text(label));
                      }).toList(),
                      onChanged: (v) => setState(() {
                        _cicloTipo = v;
                        _resetCicloParams(); // trocar o tipo limpa o detalhe anterior
                      }),
                      validator: (v) => v == null ? 'Obrigatório' : null,
                    ),
                  ),
                  if (_cicloTipo != null) ...[
                    const SizedBox(height: 16),
                    _buildCicloParam(),
                  ],
                  const SizedBox(height: 16),
                  responsaveis.when(
                    loading: () => const LinearProgressIndicator(),
                    error: (_, __) => const SizedBox.shrink(),
                    data: (users) => DropdownButtonFormField<int>(
                      initialValue: _donoId,
                      decoration: const InputDecoration(labelText: 'Dono *'),
                      items: users.map((u) => DropdownMenuItem(
                        value: u['id_user'] as int,
                        child: Text(u['nome_completo'] ?? '${u['primeiro_nome']} ${u['ultimo_nome']}'),
                      )).toList(),
                      onChanged: (v) => setState(() => _donoId = v),
                      validator: (v) => v == null ? 'Obrigatório' : null,
                    ),
                  ),
                  const SizedBox(height: 16),
                  TextFormField(
                    controller: _observacoesCtrl,
                    maxLines: 3,
                    decoration: const InputDecoration(labelText: 'Observações'),
                  ),
                  if (isEditing) ...[
                    const SizedBox(height: 16),
                    TextFormField(
                      controller: _justificativaCtrl,
                      maxLines: 3,
                      decoration: const InputDecoration(
                        labelText: 'Justificativa da edição *',
                        helperText: 'Editar reenvia o objetivo para aprovação.',
                      ),
                      validator: (v) => (v == null || v.trim().isEmpty) ? 'Obrigatório' : null,
                    ),
                  ],
                  const SizedBox(height: 32),
                  SizedBox(
                    width: double.infinity,
                    height: 50,
                    child: ElevatedButton(
                      onPressed: _isLoading ? null : _submit,
                      child: _isLoading
                          ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.5, color: AppColors.bgDeep))
                          : Text(isEditing ? 'Salvar Alterações' : 'Criar Objetivo'),
                    ),
                  ),
                ],
              ),
            ),
    );
  }
}
