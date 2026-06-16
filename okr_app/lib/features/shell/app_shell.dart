import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/haptics.dart';
import '../shared/widgets/carbon_fiber_bg.dart';

class AppShell extends StatelessWidget {
  /// Shell com estado por aba (StatefulShellRoute.indexedStack) — preserva
  /// scroll/estado de cada aba ao alternar entre elas.
  final StatefulNavigationShell navigationShell;
  const AppShell({super.key, required this.navigationShell});

  void _onTap(int i) {
    AppHaptics.selection();
    // Tocar na aba ativa volta para a raiz dela (initialLocation).
    navigationShell.goBranch(i, initialLocation: i == navigationShell.currentIndex);
  }

  @override
  Widget build(BuildContext context) {
    final current = navigationShell.currentIndex;

    return CarbonFiberBackground(
      child: Scaffold(
        backgroundColor: Colors.transparent,
        body: navigationShell,
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
                  icon: Icons.account_tree_outlined,
                  activeIcon: Icons.account_tree,
                  label: 'OKRs',
                  isActive: current == 0,
                  onTap: () => _onTap(0),
                ),
                _NavItem(
                  icon: Icons.people_outline,
                  activeIcon: Icons.people,
                  label: 'Responsáveis',
                  isActive: current == 1,
                  onTap: () => _onTap(1),
                ),
                // Center button — Minhas Tarefas
                Expanded(
                  child: GestureDetector(
                    onTap: () => _onTap(2),
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
                  onTap: () => _onTap(3),
                ),
                _NavItem(
                  icon: Icons.menu,
                  activeIcon: Icons.menu,
                  label: 'Menu',
                  isActive: current == 4,
                  onTap: () => _onTap(4),
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
