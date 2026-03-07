import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:image_picker/image_picker.dart';
import '../../core/auth/auth_provider.dart';
import '../../core/network/api_client.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/haptics.dart';
import '../shared/widgets/user_avatar.dart';

class EditProfileScreen extends ConsumerStatefulWidget {
  const EditProfileScreen({super.key});

  @override
  ConsumerState<EditProfileScreen> createState() => _EditProfileScreenState();
}

class _EditProfileScreenState extends ConsumerState<EditProfileScreen> {
  final _formKey = GlobalKey<FormState>();
  final _nomeCtrl = TextEditingController();
  final _sobrenomeCtrl = TextEditingController();
  final _telefoneCtrl = TextEditingController();
  bool _isLoading = false;
  bool _isUploadingAvatar = false;

  @override
  void initState() {
    super.initState();
    final user = ref.read(authProvider).user ?? {};
    _nomeCtrl.text = user['primeiro_nome'] ?? '';
    _sobrenomeCtrl.text = user['ultimo_nome'] ?? '';
    _telefoneCtrl.text = user['telefone'] ?? '';
  }

  @override
  void dispose() {
    _nomeCtrl.dispose();
    _sobrenomeCtrl.dispose();
    _telefoneCtrl.dispose();
    super.dispose();
  }

  Future<void> _pickAndUploadAvatar() async {
    final picker = ImagePicker();
    final picked = await picker.pickImage(source: ImageSource.gallery, maxWidth: 500, maxHeight: 500, imageQuality: 85);
    if (picked == null) return;

    AppHaptics.medium();
    setState(() => _isUploadingAvatar = true);
    try {
      final api = ref.read(apiClientProvider);
      final formData = FormData.fromMap({
        'avatar': await MultipartFile.fromFile(picked.path, filename: 'avatar.png'),
      });
      await api.dio.post('/auth/avatar',
        data: formData,
        options: Options(contentType: 'multipart/form-data'),
      );
      await ref.read(authProvider.notifier).refreshUser();
      AppHaptics.success();
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Avatar atualizado!')));
      }
    } catch (e) {
      AppHaptics.error();
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Erro ao atualizar avatar.')));
      }
    } finally {
      if (mounted) setState(() => _isUploadingAvatar = false);
    }
  }

  Future<void> _removeAvatar() async {
    AppHaptics.medium();
    setState(() => _isUploadingAvatar = true);
    try {
      final api = ref.read(apiClientProvider);
      await api.dio.delete('/auth/avatar');
      await ref.read(authProvider.notifier).refreshUser();
      AppHaptics.success();
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Avatar removido.')));
      }
    } catch (e) {
      AppHaptics.error();
    } finally {
      if (mounted) setState(() => _isUploadingAvatar = false);
    }
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    AppHaptics.medium();
    setState(() => _isLoading = true);
    try {
      final api = ref.read(apiClientProvider);
      await api.dio.put('/auth/me', data: {
        'primeiro_nome': _nomeCtrl.text.trim(),
        'ultimo_nome': _sobrenomeCtrl.text.trim(),
        'telefone': _telefoneCtrl.text.trim(),
      });
      await ref.read(authProvider.notifier).refreshUser();
      AppHaptics.success();
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Perfil atualizado!')));
        context.pop();
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
    final auth = ref.watch(authProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Editar Perfil')),
      body: Form(
        key: _formKey,
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            // Avatar section
            Center(
              child: Stack(
                children: [
                  _isUploadingAvatar
                      ? const CircleAvatar(
                          radius: 48,
                          backgroundColor: AppColors.bgCard,
                          child: SizedBox(width: 28, height: 28, child: CircularProgressIndicator(strokeWidth: 2.5, color: AppColors.gold)),
                        )
                      : UserAvatar(
                          avatarUrl: auth.avatarUrl,
                          firstName: auth.userName,
                          lastName: (auth.user?['ultimo_nome'] as String?) ?? '',
                          radius: 48,
                          showGoldRing: true,
                        ),
                  Positioned(
                    bottom: 0,
                    right: 0,
                    child: GestureDetector(
                      onTap: _isUploadingAvatar ? null : _pickAndUploadAvatar,
                      child: Container(
                        padding: const EdgeInsets.all(6),
                        decoration: BoxDecoration(
                          color: AppColors.gold,
                          shape: BoxShape.circle,
                          border: Border.all(color: AppColors.bgSoft, width: 2),
                        ),
                        child: const Icon(Icons.camera_alt, size: 16, color: AppColors.bgDeep),
                      ),
                    ),
                  ),
                ],
              ),
            ),
            if (auth.avatarUrl != null && auth.avatarUrl!.isNotEmpty)
              Center(
                child: TextButton(
                  onPressed: _isUploadingAvatar ? null : _removeAvatar,
                  child: const Text('Remover foto', style: TextStyle(color: AppColors.red, fontSize: 13)),
                ),
              ),
            const SizedBox(height: 24),

            TextFormField(
              controller: _nomeCtrl,
              decoration: const InputDecoration(labelText: 'Primeiro Nome *'),
              validator: (v) => (v == null || v.trim().isEmpty) ? 'Obrigatório' : null,
            ),
            const SizedBox(height: 16),
            TextFormField(
              controller: _sobrenomeCtrl,
              decoration: const InputDecoration(labelText: 'Último Nome'),
            ),
            const SizedBox(height: 16),
            TextFormField(
              controller: _telefoneCtrl,
              keyboardType: TextInputType.phone,
              decoration: const InputDecoration(labelText: 'Telefone'),
            ),
            const SizedBox(height: 32),
            SizedBox(
              width: double.infinity,
              height: 50,
              child: ElevatedButton(
                onPressed: _isLoading ? null : _submit,
                child: _isLoading
                    ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.5, color: AppColors.bgDeep))
                    : const Text('Salvar'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
