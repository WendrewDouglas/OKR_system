import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../core/network/api_client.dart';
import '../../core/repositories/repositories.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/haptics.dart';
import '../../core/providers/domain_providers.dart';
import '../shared/widgets/loading_shimmer.dart';

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
  final _persInicioCtrl = TextEditingController();
  final _persFimCtrl = TextEditingController();

  String? _pilarBsc;
  String? _tipoObjetivo;
  String? _cicloTipo;
  int? _donoId;
  bool _isLoading = false;
  bool _isLoadingEdit = true;

  String? _cicloPersInicio;
  String? _cicloPersFim;

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

    AppHaptics.medium();
    setState(() => _isLoading = true);
    try {
      final body = {
        'descricao': _descricaoCtrl.text.trim(),
        'pilar_bsc': _pilarBsc,
        'tipo_objetivo': _tipoObjetivo ?? '',
        'ciclo_tipo': _cicloTipo,
        'observacoes': _observacoesCtrl.text.trim(),
        if (_donoId != null) 'dono': _donoId,
        if (_cicloPersInicio != null) 'ciclo_pers_inicio': _cicloPersInicio,
        if (_cicloPersFim != null) 'ciclo_pers_fim': _cicloPersFim,
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

  @override
  Widget build(BuildContext context) {
    final pilares = ref.watch(domainProvider('dom_pilar_bsc'));
    final tipos = ref.watch(domainProvider('dom_tipo_objetivo'));
    final ciclos = ref.watch(domainProvider('dom_ciclos'));
    final responsaveis = ref.watch(responsaveisProvider);

    return Scaffold(
      appBar: AppBar(title: Text(isEditing ? 'Editar Objetivo' : 'Novo Objetivo')),
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
                        final label = p['descricao_exibicao'] ?? p['descricao'] ?? p['pilar_bsc'] ?? '';
                        final value = p['pilar_bsc'] ?? p['id'] ?? label;
                        return DropdownMenuItem(value: value.toString(), child: Text(label.toString()));
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
                      decoration: const InputDecoration(labelText: 'Tipo de Objetivo'),
                      items: items.map((t) {
                        final label = t['descricao'] ?? t['tipo_objetivo'] ?? '';
                        final value = t['tipo_objetivo'] ?? t['id'] ?? label;
                        return DropdownMenuItem(value: value.toString(), child: Text(label.toString()));
                      }).toList(),
                      onChanged: (v) => setState(() => _tipoObjetivo = v),
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
                        final label = c['descricao'] ?? c['ciclo_tipo'] ?? '';
                        final value = c['ciclo_tipo'] ?? c['id'] ?? label;
                        return DropdownMenuItem(value: value.toString(), child: Text(label.toString()));
                      }).toList(),
                      onChanged: (v) => setState(() => _cicloTipo = v),
                      validator: (v) => v == null ? 'Obrigatório' : null,
                    ),
                  ),
                  const SizedBox(height: 16),
                  if (_cicloTipo == 'personalizado') ...[
                    Row(children: [
                      Expanded(
                        child: TextFormField(
                          decoration: const InputDecoration(labelText: 'Data Início'),
                          readOnly: true,
                          controller: _persInicioCtrl,
                          onTap: () async {
                            final dt = await showDatePicker(
                              context: context,
                              firstDate: DateTime(2020),
                              lastDate: DateTime(2030),
                            );
                            if (dt != null) {
                              final s = '${dt.year}-${dt.month.toString().padLeft(2, '0')}-${dt.day.toString().padLeft(2, '0')}';
                              setState(() => _cicloPersInicio = s);
                              _persInicioCtrl.text = s;
                            }
                          },
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: TextFormField(
                          decoration: const InputDecoration(labelText: 'Data Fim'),
                          readOnly: true,
                          controller: _persFimCtrl,
                          onTap: () async {
                            final dt = await showDatePicker(
                              context: context,
                              firstDate: DateTime(2020),
                              lastDate: DateTime(2030),
                            );
                            if (dt != null) {
                              final s = '${dt.year}-${dt.month.toString().padLeft(2, '0')}-${dt.day.toString().padLeft(2, '0')}';
                              setState(() => _cicloPersFim = s);
                              _persFimCtrl.text = s;
                            }
                          },
                        ),
                      ),
                    ]),
                    const SizedBox(height: 16),
                  ],
                  responsaveis.when(
                    loading: () => const LinearProgressIndicator(),
                    error: (_, __) => const SizedBox.shrink(),
                    data: (users) => DropdownButtonFormField<int>(
                      initialValue: _donoId,
                      decoration: const InputDecoration(labelText: 'Dono (responsável)'),
                      items: users.map((u) => DropdownMenuItem(
                        value: u['id_user'] as int,
                        child: Text(u['nome_completo'] ?? '${u['primeiro_nome']} ${u['ultimo_nome']}'),
                      )).toList(),
                      onChanged: (v) => setState(() => _donoId = v),
                    ),
                  ),
                  const SizedBox(height: 16),
                  TextFormField(
                    controller: _observacoesCtrl,
                    maxLines: 3,
                    decoration: const InputDecoration(labelText: 'Observações'),
                  ),
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
