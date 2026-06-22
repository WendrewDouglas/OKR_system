import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../../core/auth/auth_provider.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/utils/haptics.dart';
import 'user_avatar.dart';

/// Cabeçalho de marca padrão do app (presente em todas as páginas).
///
/// - Nas abas: usado sem [showBack]/[title]/[actions] (apenas a faixa de marca).
/// - Nas páginas full-screen: [showBack] exibe o botão voltar e [title]/[actions]
///   adicionam uma sub-linha com o nome da página e ações específicas.
class AppHeader extends ConsumerWidget implements PreferredSizeWidget {
  final bool showBack;
  final String? title;
  final List<Widget>? actions;

  const AppHeader({super.key, this.showBack = false, this.title, this.actions});

  bool get _hasSubBar => (title != null && title!.isNotEmpty) || (actions != null && actions!.isNotEmpty);

  static const double _subBarHeight = 44;

  @override
  Size get preferredSize => Size.fromHeight(kToolbarHeight + (_hasSubBar ? _subBarHeight : 0));

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
            padding: EdgeInsets.only(top: topPadding, left: showBack ? 4 : 16, right: 8),
            child: SizedBox(
              height: kToolbarHeight - 1,
              child: Row(
                children: [
                  if (showBack)
                    IconButton(
                      icon: const Icon(Icons.arrow_back_ios_new, color: AppColors.text, size: 20),
                      onPressed: () {
                        AppHaptics.light();
                        if (context.canPop()) {
                          context.pop();
                        } else {
                          context.go('/okrs');
                        }
                      },
                      padding: EdgeInsets.zero,
                      constraints: const BoxConstraints(minWidth: 40, minHeight: 40),
                    ),
                  // Avatar + Nome → toca para o perfil
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
                  // Sino de notificações
                  IconButton(
                    icon: const Icon(Icons.notifications_outlined, color: AppColors.text, size: 24),
                    onPressed: () {
                      AppHaptics.light();
                      context.push('/notificacoes');
                    },
                    padding: EdgeInsets.zero,
                    constraints: const BoxConstraints(minWidth: 40, minHeight: 40),
                  ),
                  // Logo PlanningBI
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
          // Divisor gradiente sutil na base
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
          // Sub-linha de página: título + ações específicas da tela
          if (_hasSubBar)
            SizedBox(
              height: _subBarHeight,
              child: Padding(
                padding: const EdgeInsets.only(left: 16, right: 4),
                child: Row(
                  children: [
                    Expanded(
                      child: Text(
                        title ?? '',
                        style: const TextStyle(
                          fontSize: 16,
                          fontWeight: FontWeight.w700,
                          color: AppColors.text,
                        ),
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                    if (actions != null) ...actions!,
                  ],
                ),
              ),
            ),
        ],
      ),
    );
  }
}
