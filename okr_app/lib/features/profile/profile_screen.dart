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

class ProfileScreen extends ConsumerStatefulWidget {
  const ProfileScreen({super.key});

  @override
  ConsumerState<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends ConsumerState<ProfileScreen> {
  bool _isUploadingAvatar = false;

  Future<void> _pickAndUploadAvatar() async {
    final picker = ImagePicker();
    final picked = await picker.pickImage(
      source: ImageSource.gallery,
      maxWidth: 500,
      maxHeight: 500,
      imageQuality: 85,
    );
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
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Avatar atualizado!')),
        );
      }
    } catch (e) {
      AppHaptics.error();
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Erro ao atualizar avatar.')),
        );
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
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Avatar removido.')),
        );
      }
    } catch (e) {
      AppHaptics.error();
    } finally {
      if (mounted) setState(() => _isUploadingAvatar = false);
    }
  }

  void _showAvatarOptions() {
    final auth = ref.read(authProvider);
    final hasAvatar = auth.avatarUrl != null && auth.avatarUrl!.isNotEmpty;

    AppHaptics.light();
    showModalBottomSheet(
      context: context,
      builder: (_) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              margin: const EdgeInsets.only(top: 12),
              width: 36,
              height: 4,
              decoration: BoxDecoration(
                color: AppColors.borderMuted,
                borderRadius: BorderRadius.circular(2),
              ),
            ),
            const SizedBox(height: 16),
            const Text('Foto de Perfil', style: TextStyle(fontSize: 16, fontWeight: FontWeight.w700)),
            const SizedBox(height: 16),
            ListTile(
              leading: const Icon(Icons.photo_library_outlined, color: AppColors.gold),
              title: const Text('Escolher da galeria'),
              onTap: () {
                Navigator.pop(context);
                _pickAndUploadAvatar();
              },
            ),
            if (hasAvatar)
              ListTile(
                leading: const Icon(Icons.delete_outline, color: AppColors.red),
                title: const Text('Remover foto', style: TextStyle(color: AppColors.red)),
                onTap: () {
                  Navigator.pop(context);
                  _removeAvatar();
                },
              ),
            const SizedBox(height: 8),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final auth = ref.watch(authProvider);
    final user = auth.user ?? {};

    return Scaffold(
      appBar: AppBar(title: const Text('Meu Perfil')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          // Avatar section
          Center(
            child: GestureDetector(
              onTap: _isUploadingAvatar ? null : _showAvatarOptions,
              child: Stack(
                children: [
                  Container(
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      boxShadow: [
                        BoxShadow(
                          color: AppColors.gold.withValues(alpha: 0.2),
                          blurRadius: 20,
                          spreadRadius: 2,
                        ),
                      ],
                    ),
                    child: _isUploadingAvatar
                        ? const CircleAvatar(
                            radius: 52,
                            backgroundColor: AppColors.bgCard,
                            child: SizedBox(
                              width: 28,
                              height: 28,
                              child: CircularProgressIndicator(
                                strokeWidth: 2.5,
                                color: AppColors.gold,
                              ),
                            ),
                          )
                        : UserAvatar(
                            avatarUrl: auth.avatarUrl,
                            firstName: auth.userName,
                            lastName: (user['ultimo_nome'] as String?) ?? '',
                            radius: 52,
                            showGoldRing: true,
                          ),
                  ),
                  Positioned(
                    bottom: 0,
                    right: 0,
                    child: Container(
                      padding: const EdgeInsets.all(7),
                      decoration: BoxDecoration(
                        color: AppColors.gold,
                        shape: BoxShape.circle,
                        border: Border.all(color: AppColors.bgSoft, width: 2.5),
                        boxShadow: [
                          BoxShadow(
                            color: AppColors.gold.withValues(alpha: 0.3),
                            blurRadius: 6,
                          ),
                        ],
                      ),
                      child: const Icon(Icons.camera_alt, size: 16, color: AppColors.bgDeep),
                    ),
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 16),

          // Name
          Center(
            child: Text(
              auth.userFullName,
              style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800),
            ),
          ),
          const SizedBox(height: 4),

          // Email
          Center(
            child: Text(
              auth.userEmail,
              style: const TextStyle(color: AppColors.textMuted, fontSize: 14),
            ),
          ),

          // Company
          if ((user['empresa'] as String?)?.isNotEmpty == true) ...[
            const SizedBox(height: 4),
            Center(
              child: Container(
                margin: const EdgeInsets.only(top: 4),
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
                decoration: BoxDecoration(
                  color: AppColors.goldSubtle.withValues(alpha: 0.4),
                  borderRadius: BorderRadius.circular(20),
                  border: Border.all(color: AppColors.gold.withValues(alpha: 0.2)),
                ),
                child: Text(
                  user['empresa'] as String,
                  style: const TextStyle(color: AppColors.gold, fontSize: 12, fontWeight: FontWeight.w600),
                ),
              ),
            ),
          ],

          const SizedBox(height: 32),

          // Info cards
          _InfoCard(
            items: [
              _InfoRow(icon: Icons.person_outline, label: 'Nome', value: auth.userFullName),
              _InfoRow(icon: Icons.email_outlined, label: 'E-mail', value: auth.userEmail),
              if ((user['telefone'] as String?)?.isNotEmpty == true)
                _InfoRow(icon: Icons.phone_outlined, label: 'Telefone', value: user['telefone'] as String),
              if (auth.userRole.isNotEmpty)
                _InfoRow(icon: Icons.badge_outlined, label: 'Função', value: auth.userRole),
            ],
          ),

          const SizedBox(height: 24),

          // Actions
          _ActionTile(
            icon: Icons.edit_outlined,
            label: 'Editar perfil',
            onTap: () {
              AppHaptics.light();
              context.push('/perfil/editar');
            },
          ),
          const SizedBox(height: 8),
          _ActionTile(
            icon: Icons.lock_outline,
            label: 'Alterar senha',
            onTap: () {
              AppHaptics.light();
              context.push('/perfil/senha');
            },
          ),
          const SizedBox(height: 24),
          _ActionTile(
            icon: Icons.logout,
            label: 'Sair da conta',
            color: AppColors.red,
            onTap: () {
              AppHaptics.heavy();
              ref.read(authProvider.notifier).logout();
            },
          ),
        ],
      ),
    );
  }
}

class _InfoCard extends StatelessWidget {
  final List<_InfoRow> items;
  const _InfoCard({required this.items});

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: AppColors.bgCard,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: AppColors.borderDefault, width: 0.5),
        boxShadow: AppShadows.cardRest,
      ),
      child: Column(
        children: [
          for (int i = 0; i < items.length; i++) ...[
            items[i],
            if (i < items.length - 1)
              const Divider(height: 1, indent: 48, color: AppColors.borderDefault),
          ],
        ],
      ),
    );
  }
}

class _InfoRow extends StatelessWidget {
  final IconData icon;
  final String label;
  final String value;
  const _InfoRow({required this.icon, required this.label, required this.value});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
      child: Row(
        children: [
          Icon(icon, size: 20, color: AppColors.textMuted),
          const SizedBox(width: 12),
          Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(label, style: const TextStyle(fontSize: 11, color: AppColors.textMuted)),
              const SizedBox(height: 2),
              Text(value, style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w500)),
            ],
          ),
        ],
      ),
    );
  }
}

class _ActionTile extends StatelessWidget {
  final IconData icon;
  final String label;
  final VoidCallback onTap;
  final Color? color;
  const _ActionTile({required this.icon, required this.label, required this.onTap, this.color});

  @override
  Widget build(BuildContext context) {
    final c = color ?? AppColors.text;
    return Material(
      color: AppColors.bgCard,
      borderRadius: BorderRadius.circular(12),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(12),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(12),
            border: Border.all(color: AppColors.borderDefault, width: 0.5),
          ),
          child: Row(
            children: [
              Icon(icon, size: 22, color: c),
              const SizedBox(width: 12),
              Expanded(child: Text(label, style: TextStyle(color: c, fontWeight: FontWeight.w500, fontSize: 15))),
              Icon(Icons.chevron_right, color: c.withValues(alpha: 0.5), size: 20),
            ],
          ),
        ),
      ),
    );
  }
}
