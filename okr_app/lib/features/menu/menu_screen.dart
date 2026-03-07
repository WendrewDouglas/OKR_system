import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../core/auth/auth_provider.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/haptics.dart';
import '../shared/widgets/app_header.dart';
import '../shared/widgets/user_avatar.dart';

class MenuScreen extends ConsumerWidget {
  const MenuScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final auth = ref.watch(authProvider);

    return Scaffold(
      appBar: const AppHeader(),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          // User card with gradient background
          Container(
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(16),
              gradient: LinearGradient(
                colors: [AppColors.bgCard, AppColors.goldSubtle.withValues(alpha: 0.3)],
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
              ),
              border: Border.all(color: AppColors.borderDefault, width: 0.5),
              boxShadow: AppShadows.cardRest,
            ),
            padding: const EdgeInsets.all(16),
            child: Row(
              children: [
                // Avatar with gold gradient ring and glow
                Container(
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    boxShadow: [
                      BoxShadow(color: AppColors.gold.withValues(alpha: 0.2), blurRadius: 10),
                    ],
                  ),
                  child: UserAvatar(
                    avatarUrl: auth.avatarUrl,
                    firstName: auth.userName,
                    lastName: (auth.user?['ultimo_nome'] as String?) ?? '',
                    radius: 28,
                    showGoldRing: true,
                  ),
                ),
                const SizedBox(width: 14),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(auth.userFullName,
                          style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w700)),
                      const SizedBox(height: 2),
                      Text(auth.userEmail,
                          style: const TextStyle(fontSize: 13, color: AppColors.textMuted)),
                      if (auth.userRole.isNotEmpty) ...[
                        const SizedBox(height: 2),
                        Text(auth.userRole,
                            style: const TextStyle(fontSize: 12, color: AppColors.gold)),
                      ],
                    ],
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 20),

          _MenuSection(title: 'Gestão', items: [
            _MenuItem(icon: Icons.check_circle_outline, label: 'Aprovações', onTap: () {
              AppHaptics.light();
              context.push('/aprovacoes');
            }),
            _MenuItem(icon: Icons.notifications_outlined, label: 'Notificações', onTap: () {
              AppHaptics.light();
              context.push('/notificacoes');
            }),
          ]),
          const SizedBox(height: 16),

          _MenuSection(title: 'Conta', items: [
            _MenuItem(icon: Icons.person_outline, label: 'Meu Perfil', onTap: () {
              AppHaptics.light();
              context.push('/perfil');
            }),
            _MenuItem(icon: Icons.edit_outlined, label: 'Editar Perfil', onTap: () {
              AppHaptics.light();
              context.push('/perfil/editar');
            }),
            _MenuItem(icon: Icons.lock_outline, label: 'Alterar Senha', onTap: () {
              AppHaptics.light();
              context.push('/perfil/senha');
            }),
          ]),
          const SizedBox(height: 16),

          _MenuSection(title: '', items: [
            _MenuItem(
              icon: Icons.logout,
              label: 'Sair',
              color: AppColors.red,
              onTap: () {
                AppHaptics.heavy();
                ref.read(authProvider.notifier).logout();
              },
            ),
          ]),
        ],
      ),
    );
  }
}

class _MenuSection extends StatelessWidget {
  final String title;
  final List<_MenuItem> items;
  const _MenuSection({required this.title, required this.items});

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (title.isNotEmpty)
          Padding(
            padding: const EdgeInsets.only(left: 4, bottom: 8),
            child: Text(title, style: const TextStyle(
              fontSize: 13, fontWeight: FontWeight.w600, color: AppColors.textMuted,
            )),
          ),
        Card(
          child: Column(
            children: [
              for (int i = 0; i < items.length; i++) ...[
                items[i],
                if (i < items.length - 1)
                  const Divider(height: 1, indent: 52),
              ],
            ],
          ),
        ),
      ],
    );
  }
}

class _MenuItem extends StatelessWidget {
  final IconData icon;
  final String label;
  final VoidCallback onTap;
  final Color? color;
  const _MenuItem({required this.icon, required this.label, required this.onTap, this.color});

  @override
  Widget build(BuildContext context) {
    return ListTile(
      leading: Icon(icon, color: color ?? AppColors.textMuted, size: 22),
      title: Text(label, style: TextStyle(color: color ?? AppColors.text, fontSize: 15)),
      trailing: Icon(Icons.chevron_right, color: color ?? AppColors.textMuted, size: 20),
      onTap: onTap,
    );
  }
}
