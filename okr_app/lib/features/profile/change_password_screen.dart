import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../core/network/api_client.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/haptics.dart';

class ChangePasswordScreen extends ConsumerStatefulWidget {
  const ChangePasswordScreen({super.key});

  @override
  ConsumerState<ChangePasswordScreen> createState() => _ChangePasswordScreenState();
}

class _ChangePasswordScreenState extends ConsumerState<ChangePasswordScreen> {
  final _formKey = GlobalKey<FormState>();
  final _atualCtrl = TextEditingController();
  final _novaCtrl = TextEditingController();
  final _confirmaCtrl = TextEditingController();
  bool _obscureAtual = true;
  bool _obscureNova = true;
  bool _isLoading = false;

  @override
  void dispose() {
    _atualCtrl.dispose();
    _novaCtrl.dispose();
    _confirmaCtrl.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    AppHaptics.medium();
    setState(() => _isLoading = true);
    try {
      final api = ref.read(apiClientProvider);
      final res = await api.dio.put('/auth/me', data: {
        'password_atual': _atualCtrl.text,
        'password_nova': _novaCtrl.text,
      });
      if (res.data['ok'] == true && mounted) {
        AppHaptics.success();
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Senha alterada!')));
        context.pop();
      }
    } catch (e) {
      setState(() => _isLoading = false);
      AppHaptics.error();
      if (mounted) {
        final msg = e.toString().contains('401') ? 'Senha atual incorreta' : 'Erro ao alterar senha. Tente novamente.';
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(msg)));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Alterar Senha')),
      body: Form(
        key: _formKey,
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            TextFormField(
              controller: _atualCtrl,
              obscureText: _obscureAtual,
              decoration: InputDecoration(
                labelText: 'Senha Atual *',
                suffixIcon: IconButton(
                  icon: Icon(_obscureAtual ? Icons.visibility_off : Icons.visibility),
                  onPressed: () => setState(() => _obscureAtual = !_obscureAtual),
                ),
              ),
              validator: (v) => (v == null || v.isEmpty) ? 'Obrigatório' : null,
            ),
            const SizedBox(height: 16),
            TextFormField(
              controller: _novaCtrl,
              obscureText: _obscureNova,
              decoration: InputDecoration(
                labelText: 'Nova Senha *',
                suffixIcon: IconButton(
                  icon: Icon(_obscureNova ? Icons.visibility_off : Icons.visibility),
                  onPressed: () => setState(() => _obscureNova = !_obscureNova),
                ),
              ),
              validator: (v) {
                if (v == null || v.length < 8) return 'Mínimo 8 caracteres';
                return null;
              },
            ),
            const SizedBox(height: 16),
            TextFormField(
              controller: _confirmaCtrl,
              obscureText: true,
              decoration: const InputDecoration(labelText: 'Confirmar Nova Senha *'),
              validator: (v) {
                if (v != _novaCtrl.text) return 'Senhas não conferem';
                return null;
              },
            ),
            const SizedBox(height: 32),
            SizedBox(
              width: double.infinity,
              height: 50,
              child: ElevatedButton(
                onPressed: _isLoading ? null : _submit,
                child: _isLoading
                    ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.5, color: AppColors.bgDeep))
                    : const Text('Alterar Senha'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
