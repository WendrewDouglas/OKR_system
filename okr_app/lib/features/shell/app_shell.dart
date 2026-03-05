import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/haptics.dart';
import '../shared/widgets/carbon_fiber_bg.dart';

class AppShell extends StatelessWidget {
  final Widget child;
  const AppShell({super.key, required this.child});

  int _currentIndex(BuildContext context) {
    final location = GoRouterState.of(context).matchedLocation;
    if (location.startsWith('/dashboard')) return 0;
    if (location.startsWith('/okrs')) return 1;
    if (location.startsWith('/tarefas')) return 2;
    if (location.startsWith('/orcamento')) return 3;
    if (location.startsWith('/menu')) return 4;
    return 0;
  }

  void _onTap(BuildContext context, int i) {
    AppHaptics.selection();
    switch (i) {
      case 0: context.go('/dashboard');
      case 1: context.go('/okrs');
      case 2: context.go('/tarefas');
      case 3: context.go('/orcamento');
      case 4: context.go('/menu');
    }
  }

  @override
  Widget build(BuildContext context) {
    final current = _currentIndex(context);

    return CarbonFiberBackground(
      child: Scaffold(
        backgroundColor: Colors.transparent,
        body: child,
        bottomNavigationBar: Container(
        decoration: BoxDecoration(
          color: AppColors.bgCard,
          border: const Border(top: BorderSide(color: AppColors.borderDefault, width: 0.5)),
          boxShadow: [
            BoxShadow(color: Colors.black.withValues(alpha: 0.3), blurRadius: 12, offset: const Offset(0, -2)),
          ],
        ),
        child: SafeArea(
          child: SizedBox(
            height: 70,
            child: Row(
              children: [
                _NavItem(
                  icon: Icons.home_outlined,
                  activeIcon: Icons.home,
                  label: 'Home',
                  isActive: current == 0,
                  onTap: () => _onTap(context, 0),
                ),
                _NavItem(
                  icon: Icons.flag_outlined,
                  activeIcon: Icons.flag,
                  label: 'OKRs',
                  isActive: current == 1,
                  onTap: () => _onTap(context, 1),
                ),
                // Center button — Minhas Tarefas
                Expanded(
                  child: GestureDetector(
                    onTap: () => _onTap(context, 2),
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        AnimatedScale(
                          scale: current == 2 ? 1.0 : 0.95,
                          duration: const Duration(milliseconds: 200),
                          curve: Curves.easeOutCubic,
                          child: Container(
                            width: 46,
                            height: 46,
                            decoration: BoxDecoration(
                              gradient: AppColors.goldGradient,
                              shape: BoxShape.circle,
                              boxShadow: current == 2
                                  ? [BoxShadow(color: AppColors.gold.withValues(alpha: 0.4), blurRadius: 12, spreadRadius: 2)]
                                  : [BoxShadow(color: AppColors.gold.withValues(alpha: 0.2), blurRadius: 8)],
                            ),
                            child: const Icon(Icons.task_alt, color: AppColors.bgDeep, size: 24),
                          ),
                        ),
                        const SizedBox(height: 2),
                        Text(
                          'Tarefas',
                          style: TextStyle(
                            fontSize: 10,
                            fontWeight: current == 2 ? FontWeight.w700 : FontWeight.w500,
                            color: current == 2 ? AppColors.gold : AppColors.textMuted,
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
                _NavItem(
                  icon: Icons.account_balance_wallet_outlined,
                  activeIcon: Icons.account_balance_wallet,
                  label: 'Orçamento',
                  isActive: current == 3,
                  onTap: () => _onTap(context, 3),
                ),
                _NavItem(
                  icon: Icons.menu,
                  activeIcon: Icons.menu,
                  label: 'Menu',
                  isActive: current == 4,
                  onTap: () => _onTap(context, 4),
                ),
              ],
            ),
          ),
        ),
      ),
      ),
    );
  }
}

class _NavItem extends StatelessWidget {
  final IconData icon;
  final IconData activeIcon;
  final String label;
  final bool isActive;
  final VoidCallback onTap;

  const _NavItem({
    required this.icon,
    required this.activeIcon,
    required this.label,
    required this.isActive,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return Expanded(
      child: GestureDetector(
        onTap: onTap,
        behavior: HitTestBehavior.opaque,
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            AnimatedScale(
              scale: isActive ? 1.1 : 1.0,
              duration: const Duration(milliseconds: 200),
              curve: Curves.easeOutCubic,
              child: Icon(
                isActive ? activeIcon : icon,
                color: isActive ? AppColors.gold : AppColors.textMuted,
                size: 24,
              ),
            ),
            const SizedBox(height: 4),
            Text(
              label,
              style: TextStyle(
                fontSize: 10,
                fontWeight: isActive ? FontWeight.w700 : FontWeight.w500,
                color: isActive ? AppColors.gold : AppColors.textMuted,
              ),
            ),
            // Gold indicator dot
            AnimatedContainer(
              duration: const Duration(milliseconds: 200),
              margin: const EdgeInsets.only(top: 2),
              width: isActive ? 4 : 0,
              height: isActive ? 4 : 0,
              decoration: BoxDecoration(
                color: AppColors.gold,
                shape: BoxShape.circle,
                boxShadow: isActive
                    ? [BoxShadow(color: AppColors.gold.withValues(alpha: 0.5), blurRadius: 4)]
                    : null,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
