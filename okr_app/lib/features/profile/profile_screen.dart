import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../core/auth/auth_provider.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/haptics.dart';

class ProfileScreen extends ConsumerWidget {
  const ProfileScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final auth = ref.watch(authProvider);
    final user = auth.user ?? {};

    return Scaffold(
      appBar: AppBar(title: const Text('Meu Perfil')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Center(
            child: Container(
              padding: const EdgeInsets.all(3),
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                gradient: AppColors.goldGradient,
                boxShadow: [
                  BoxShadow(color: AppColors.gold.withValues(alpha: 0.2), blurRadius: 16, spreadRadius: 2),
                ],
              ),
              child: CircleAvatar(
                radius: 44,
                backgroundColor: AppColors.bgCard,
                child: Text(
                  (auth.userName.isNotEmpty ? auth.userName[0] : '?').toUpperCase(),
                  style: const TextStyle(fontSize: 32, fontWeight: FontWeight.w700, color: AppColors.gold),
                ),
              ),
            ),
          ),
          const SizedBox(height: 16),
          Center(child: Text('${user['primeiro_nome'] ?? ''} ${user['ultimo_nome'] ?? ''}',
              style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w700))),
          Center(child: Text(auth.userEmail, style: const TextStyle(color: AppColors.textMuted, fontSize: 14))),
          const SizedBox(height: 8),
          Center(child: Text(user['empresa'] ?? '', style: const TextStyle(color: AppColors.textMuted, fontSize: 13))),
          const SizedBox(height: 32),
          _ProfileTile(icon: Icons.person_outline, label: 'Editar perfil', onTap: () {
            AppHaptics.light();
            context.push('/perfil/editar');
          }),
          _ProfileTile(icon: Icons.lock_outline, label: 'Alterar senha', onTap: () {
            AppHaptics.light();
            context.push('/perfil/senha');
          }),
          const Divider(height: 32),
          _ProfileTile(
            icon: Icons.logout,
            label: 'Sair',
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

class _ProfileTile extends StatelessWidget {
  final IconData icon;
  final String label;
  final VoidCallback onTap;
  final Color? color;
  const _ProfileTile({required this.icon, required this.label, required this.onTap, this.color});

  @override
  Widget build(BuildContext context) {
    return ListTile(
      leading: Icon(icon, color: color ?? AppColors.textMuted),
      title: Text(label, style: TextStyle(color: color ?? AppColors.text)),
      trailing: const Icon(Icons.chevron_right, color: AppColors.textMuted),
      onTap: onTap,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
    );
  }
}
