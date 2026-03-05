import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../core/network/api_client.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/haptics.dart';
import '../../core/providers/domain_providers.dart';

class IniciativaFormScreen extends ConsumerStatefulWidget {
  final String? idIniciativa;
  final String? idKr;
  const IniciativaFormScreen({super.key, this.idIniciativa, this.idKr});

  @override
  ConsumerState<IniciativaFormScreen> createState() => _IniciativaFormScreenState();
}

class _IniciativaFormScreenState extends ConsumerState<IniciativaFormScreen> {
  final _formKey = GlobalKey<FormState>();
  final _descricaoCtrl = TextEditingController();
  String _status = 'Não Iniciado';
  String? _dtPrazo;
  Set<int> _selectedResponsaveis = {};
  bool _isLoading = false;
  bool _isLoadingEdit = true;

  bool get isEditing => widget.idIniciativa != null;

  @override
  void initState() {
    super.initState();
    if (isEditing) {
      _loadIniciativa();
    } else {
      _isLoadingEdit = false;
    }
  }

  Future<void> _loadIniciativa() async {
    try {
      final api = ref.read(apiClientProvider);
      final res = await api.dio.get('/iniciativas/${widget.idIniciativa}');
      final ini = res.data['iniciativa'] as Map<String, dynamic>? ?? {};
      final envolvidos = ((ini['envolvidos'] as List?) ?? []).cast<Map<String, dynamic>>();
      setState(() {
        _descricaoCtrl.text = ini['descricao'] ?? '';
        _status = ini['status'] ?? 'Não Iniciado';
        _dtPrazo = ini['dt_prazo'] as String?;
        _selectedResponsaveis = envolvidos.map((e) => e['id_user'] as int).toSet();
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
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    AppHaptics.medium();

    setState(() => _isLoading = true);
    try {
      final api = ref.read(apiClientProvider);
      final body = {
        'descricao': _descricaoCtrl.text.trim(),
        'status': _status,
        if (_dtPrazo != null) 'dt_prazo': _dtPrazo,
        'responsaveis': _selectedResponsaveis.toList(),
        if (_selectedResponsaveis.isNotEmpty) 'id_user_responsavel': _selectedResponsaveis.first,
      };

      if (isEditing) {
        await api.dio.put('/iniciativas/${widget.idIniciativa}', data: body);
      } else {
        body['id_kr'] = widget.idKr!;
        await api.dio.post('/iniciativas', data: body);
      }

      AppHaptics.success();
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(isEditing ? 'Iniciativa atualizada!' : 'Iniciativa criada!')),
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
    final responsaveis = ref.watch(responsaveisProvider);

    return Scaffold(
      appBar: AppBar(title: Text(isEditing ? 'Editar Iniciativa' : 'Nova Iniciativa')),
      body: _isLoadingEdit
          ? const Center(child: CircularProgressIndicator())
          : Form(
              key: _formKey,
              child: ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  TextFormField(
                    controller: _descricaoCtrl,
                    maxLines: 3,
                    decoration: const InputDecoration(labelText: 'Descrição *'),
                    validator: (v) => (v == null || v.trim().isEmpty) ? 'Obrigatório' : null,
                  ),
                  const SizedBox(height: 16),
                  DropdownButtonFormField<String>(
                    initialValue: _status,
                    decoration: const InputDecoration(labelText: 'Status'),
                    items: const [
                      DropdownMenuItem(value: 'Não Iniciado', child: Text('Não Iniciado')),
                      DropdownMenuItem(value: 'Em Andamento', child: Text('Em Andamento')),
                      DropdownMenuItem(value: 'Concluído', child: Text('Concluído')),
                      DropdownMenuItem(value: 'Cancelado', child: Text('Cancelado')),
                    ],
                    onChanged: (v) => setState(() => _status = v ?? _status),
                  ),
                  const SizedBox(height: 16),
                  TextFormField(
                    readOnly: true,
                    decoration: InputDecoration(
                      labelText: 'Prazo',
                      suffixIcon: const Icon(Icons.calendar_today, size: 18),
                      hintText: _dtPrazo ?? 'Selecionar data',
                    ),
                    controller: TextEditingController(text: _dtPrazo ?? ''),
                    onTap: () async {
                      final dt = await showDatePicker(
                        context: context,
                        firstDate: DateTime(2020),
                        lastDate: DateTime(2030),
                        initialDate: DateTime.now(),
                      );
                      if (dt != null) {
                        setState(() => _dtPrazo = '${dt.year}-${dt.month.toString().padLeft(2, '0')}-${dt.day.toString().padLeft(2, '0')}');
                      }
                    },
                  ),
                  const SizedBox(height: 20),
                  const Text('Responsáveis', style: TextStyle(fontWeight: FontWeight.w600, fontSize: 14)),
                  const SizedBox(height: 8),
                  responsaveis.when(
                    loading: () => const LinearProgressIndicator(),
                    error: (_, __) => const Text('Erro ao carregar usuários'),
                    data: (users) => Wrap(
                      spacing: 8,
                      runSpacing: 6,
                      children: users.map((u) {
                        final userId = u['id_user'] as int;
                        final nome = u['nome_completo'] ?? '${u['primeiro_nome']} ${u['ultimo_nome']}';
                        final selected = _selectedResponsaveis.contains(userId);
                        return FilterChip(
                          label: Text(nome.toString()),
                          selected: selected,
                          onSelected: (v) {
                            AppHaptics.selection();
                            setState(() {
                              if (v) {
                                _selectedResponsaveis.add(userId);
                              } else {
                                _selectedResponsaveis.remove(userId);
                              }
                            });
                          },
                          selectedColor: AppColors.gold.withValues(alpha: 0.2),
                          checkmarkColor: AppColors.gold,
                        );
                      }).toList(),
                    ),
                  ),
                  const SizedBox(height: 32),
                  SizedBox(
                    width: double.infinity,
                    height: 50,
                    child: ElevatedButton(
                      onPressed: _isLoading ? null : _submit,
                      child: _isLoading
                          ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.5, color: AppColors.bgDeep))
                          : Text(isEditing ? 'Salvar Alterações' : 'Criar Iniciativa'),
                    ),
                  ),
                ],
              ),
            ),
    );
  }
}
