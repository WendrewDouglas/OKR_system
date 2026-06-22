import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../core/repositories/repositories.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/haptics.dart';
import '../shared/widgets/loading_shimmer.dart';
import '../shared/widgets/app_scaffold.dart';
import 'usuarios_list_screen.dart' show kRoleLabels;

class UsuarioFormScreen extends ConsumerStatefulWidget {
  final int? idUser;
  const UsuarioFormScreen({super.key, this.idUser});

  @override
  ConsumerState<UsuarioFormScreen> createState() => _UsuarioFormScreenState();
}

class _UsuarioFormScreenState extends ConsumerState<UsuarioFormScreen> {
  final _formKey = GlobalKey<FormState>();
  final _primeiroCtrl = TextEditingController();
  final _ultimoCtrl = TextEditingController();
  final _emailCtrl = TextEditingController();
  final _telefoneCtrl = TextEditingController();
  final _senhaCtrl = TextEditingController();

  String _roleInicial = '';
  String? _role;
  bool _isLoading = false;
  bool _isLoadingEdit = true;

  // Papéis atribuíveis pela tela (admin_master é concedido fora daqui).
  static const _rolesAtribuiveis = ['user_colab', 'gestor_master', 'user_admin'];

  bool get isEditing => widget.idUser != null;

  @override
  void initState() {
    super.initState();
    if (isEditing) {
      _load();
    } else {
      _isLoadingEdit = false;
    }
  }

  Future<void> _load() async {
    try {
      final u = await ref.read(usuarioRepositoryProvider).get(widget.idUser!);
      setState(() {
        _primeiroCtrl.text = u.primeiroNome;
        _ultimoCtrl.text = u.ultimoNome;
        _emailCtrl.text = u.email;
        _telefoneCtrl.text = u.telefone;
        _roleInicial = u.roleKey;
        _role = _rolesAtribuiveis.contains(u.roleKey) ? u.roleKey : null;
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
    _primeiroCtrl.dispose();
    _ultimoCtrl.dispose();
    _emailCtrl.dispose();
    _telefoneCtrl.dispose();
    _senhaCtrl.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    AppHaptics.medium();
    setState(() => _isLoading = true);
    try {
      final repo = ref.read(usuarioRepositoryProvider);
      if (isEditing) {
        await repo.update(widget.idUser!, {
          'primeiro_nome': _primeiroCtrl.text.trim(),
          'ultimo_nome': _ultimoCtrl.text.trim(),
          'telefone': _telefoneCtrl.text.trim(),
        });
        // Atualiza papel se mudou
        if (_role != null && _role != _roleInicial) {
          await repo.setRole(widget.idUser!, _role!);
        }
      } else {
        await repo.create({
          'primeiro_nome': _primeiroCtrl.text.trim(),
          'ultimo_nome': _ultimoCtrl.text.trim(),
          'email': _emailCtrl.text.trim(),
          'password': _senhaCtrl.text,
          if (_role != null) 'role_key': _role,
        });
      }
      AppHaptics.success();
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(isEditing ? 'Usuário atualizado!' : 'Usuário criado!')),
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
    return AppScaffold(
      title: isEditing ? 'Editar Usuário' : 'Novo Usuário',
      body: _isLoadingEdit
          ? const LoadingShimmer()
          : Form(
              key: _formKey,
              child: ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  TextFormField(
                    controller: _primeiroCtrl,
                    decoration: const InputDecoration(labelText: 'Nome *'),
                    validator: (v) => (v == null || v.trim().isEmpty) ? 'Obrigatório' : null,
                  ),
                  const SizedBox(height: 16),
                  TextFormField(
                    controller: _ultimoCtrl,
                    decoration: const InputDecoration(labelText: 'Sobrenome'),
                  ),
                  const SizedBox(height: 16),
                  TextFormField(
                    controller: _emailCtrl,
                    enabled: !isEditing, // e-mail não editável após criação
                    keyboardType: TextInputType.emailAddress,
                    decoration: InputDecoration(
                      labelText: 'E-mail *',
                      helperText: isEditing ? 'O e-mail não pode ser alterado.' : null,
                    ),
                    validator: (v) {
                      if (isEditing) return null;
                      final s = (v ?? '').trim();
                      if (s.isEmpty) return 'Obrigatório';
                      if (!RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$').hasMatch(s)) return 'E-mail inválido';
                      return null;
                    },
                  ),
                  const SizedBox(height: 16),
                  TextFormField(
                    controller: _telefoneCtrl,
                    keyboardType: TextInputType.phone,
                    decoration: const InputDecoration(labelText: 'Telefone'),
                  ),
                  const SizedBox(height: 16),
                  if (!isEditing) ...[
                    TextFormField(
                      controller: _senhaCtrl,
                      obscureText: true,
                      decoration: const InputDecoration(labelText: 'Senha *'),
                      validator: (v) => (v == null || v.length < 8) ? 'Mínimo 8 caracteres' : null,
                    ),
                    const SizedBox(height: 16),
                  ],
                  DropdownButtonFormField<String>(
                    initialValue: _role,
                    decoration: const InputDecoration(labelText: 'Papel'),
                    items: _rolesAtribuiveis
                        .map((r) => DropdownMenuItem(value: r, child: Text(kRoleLabels[r] ?? r)))
                        .toList(),
                    onChanged: (v) => setState(() => _role = v),
                    validator: (v) => v == null ? 'Selecione um papel' : null,
                  ),
                  const SizedBox(height: 32),
                  SizedBox(
                    width: double.infinity,
                    height: 50,
                    child: ElevatedButton(
                      onPressed: _isLoading ? null : _submit,
                      child: _isLoading
                          ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.5, color: AppColors.bgDeep))
                          : Text(isEditing ? 'Salvar Alterações' : 'Criar Usuário'),
                    ),
                  ),
                ],
              ),
            ),
    );
  }
}
