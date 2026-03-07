import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../../core/auth/auth_provider.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/utils/haptics.dart';
import 'user_avatar.dart';

class AppHeader extends ConsumerWidget implements PreferredSizeWidget {
  const AppHeader({super.key});

  @override
  Size get preferredSize => const Size.fromHeight(kToolbarHeight);

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final auth = ref.watch(authProvider);
    final topPadding = MediaQuery.of(context).padding.top;

    return Container(
      color: AppColors.bgSoft,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Padding(
            padding: EdgeInsets.only(top: topPadding, left: 16, right: 8),
            child: SizedBox(
              height: kToolbarHeight - 1,
              child: Row(
                children: [
                  // Avatar + Name → tap to profile
                  Expanded(
                    child: GestureDetector(
                      onTap: () {
                        AppHaptics.light();
                        context.push('/perfil');
                      },
                      behavior: HitTestBehavior.opaque,
                      child: Row(
                        children: [
                          UserAvatar(
                            avatarUrl: auth.avatarUrl,
                            firstName: auth.userName,
                            lastName: (auth.user?['ultimo_nome'] as String?) ?? '',
                            radius: 18,
                            showGoldRing: true,
                          ),
                          const SizedBox(width: 10),
                          Expanded(
                            child: Column(
                              mainAxisAlignment: MainAxisAlignment.center,
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  auth.userFullName.isNotEmpty ? auth.userFullName : 'OKR System',
                                  style: const TextStyle(
                                    fontSize: 15,
                                    fontWeight: FontWeight.w700,
                                    color: AppColors.text,
                                  ),
                                  overflow: TextOverflow.ellipsis,
                                ),
                                if (auth.userRole.isNotEmpty)
                                  Text(
                                    auth.userRole,
                                    style: const TextStyle(fontSize: 11, color: AppColors.textMuted),
                                    overflow: TextOverflow.ellipsis,
                                  ),
                              ],
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                  // Notification bell
                  IconButton(
                    icon: const Icon(Icons.notifications_outlined, color: AppColors.text, size: 24),
                    onPressed: () {
                      AppHaptics.light();
                      context.push('/notificacoes');
                    },
                    padding: EdgeInsets.zero,
                    constraints: const BoxConstraints(minWidth: 40, minHeight: 40),
                  ),
                  // PlanningBI logo
                  Padding(
                    padding: const EdgeInsets.only(right: 8),
                    child: Image.asset(
                      'assets/images/favicon-128.png',
                      width: 30,
                      height: 30,
                      errorBuilder: (_, __, ___) => const Icon(Icons.analytics, color: AppColors.gold, size: 28),
                    ),
                  ),
                ],
              ),
            ),
          ),
          // Subtle gradient divider at base
          Container(
            height: 1,
            decoration: BoxDecoration(
              gradient: LinearGradient(
                colors: [
                  Colors.transparent,
                  AppColors.gold.withValues(alpha: 0.15),
                  Colors.transparent,
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}
