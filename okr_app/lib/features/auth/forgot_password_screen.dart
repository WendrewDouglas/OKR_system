import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../core/network/api_client.dart';
import '../../core/theme/app_theme.dart';

class ForgotPasswordScreen extends ConsumerStatefulWidget {
  const ForgotPasswordScreen({super.key});

  @override
  ConsumerState<ForgotPasswordScreen> createState() => _ForgotPasswordScreenState();
}

class _ForgotPasswordScreenState extends ConsumerState<ForgotPasswordScreen> {
  final _formKey = GlobalKey<FormState>();
  final _emailCtrl = TextEditingController();
  bool _isLoading = false;
  bool _sent = false;

  @override
  void dispose() {
    _emailCtrl.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() => _isLoading = true);
    try {
      final api = ref.read(apiClientProvider);
      await api.dio.post('/auth/forgot-password', data: {
        'email': _emailCtrl.text.trim(),
      });
      setState(() {
        _isLoading = false;
        _sent = true;
      });
    } catch (e) {
      setState(() => _isLoading = false);
      // Always show success to prevent email enumeration
      setState(() => _sent = true);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Recuperar Senha')),
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.symmetric(horizontal: 28),
            child: _sent ? _buildSuccess() : _buildForm(),
          ),
        ),
      ),
    );
  }

  Widget _buildSuccess() {
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        const Icon(Icons.mark_email_read, size: 64, color: AppColors.green),
        const SizedBox(height: 16),
        const Text('E-mail enviado!', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 20)),
        const SizedBox(height: 8),
        const Text(
          'Se o e-mail estiver cadastrado, você receberá um link para redefinir sua senha.',
          textAlign: TextAlign.center,
          style: TextStyle(color: AppColors.textMuted, fontSize: 14),
        ),
        const SizedBox(height: 24),
        SizedBox(
          width: double.infinity,
          height: 50,
          child: ElevatedButton(
            onPressed: () => context.go('/login'),
            child: const Text('Voltar ao Login'),
          ),
        ),
      ],
    );
  }

  Widget _buildForm() {
    return Form(
      key: _formKey,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const Icon(Icons.lock_reset, size: 64, color: AppColors.gold),
          const SizedBox(height: 16),
          const Text('Esqueceu sua senha?', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 20)),
          const SizedBox(height: 8),
          const Text(
            'Informe seu e-mail corporativo e enviaremos um link para redefinir.',
            textAlign: TextAlign.center,
            style: TextStyle(color: AppColors.textMuted, fontSize: 14),
          ),
          const SizedBox(height: 28),
          TextFormField(
            controller: _emailCtrl,
            keyboardType: TextInputType.emailAddress,
            decoration: const InputDecoration(
              hintText: 'E-mail corporativo',
              prefixIcon: Icon(Icons.email_outlined),
            ),
            validator: (v) => (v == null || !v.contains('@')) ? 'E-mail inválido' : null,
          ),
          const SizedBox(height: 24),
          SizedBox(
            width: double.infinity,
            height: 50,
            child: ElevatedButton(
              onPressed: _isLoading ? null : _submit,
              child: _isLoading
                  ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.5, color: AppColors.bgSoft))
                  : const Text('Enviar Link'),
            ),
          ),
          const SizedBox(height: 12),
          TextButton(
            onPressed: () => context.pop(),
            child: const Text('Voltar ao login', style: TextStyle(color: AppColors.textMuted)),
          ),
        ],
      ),
    );
  }
}
