import 'package:flutter/material.dart';
import '../../../core/theme/app_theme.dart';

/// Barra inferior de navegação padrão do app (rodapé presente em todas as
/// páginas). Widget puramente visual: o comportamento de navegação é injetado
/// via [onTap]. [currentIndex] nulo = nenhuma aba ativa (páginas full-screen).
class AppBottomNav extends StatelessWidget {
  final int? currentIndex;
  final void Function(int index) onTap;
  const AppBottomNav({super.key, required this.currentIndex, required this.onTap});

  /// Rotas das 5 abas, na mesma ordem dos branches do StatefulShellRoute.
  static const List<String> routes = [
    '/okrs',
    '/responsaveis',
    '/tarefas',
    '/orcamento',
    '/menu',
  ];

  @override
  Widget build(BuildContext context) {
    final current = currentIndex;
    return Container(
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
                onTap: () => onTap(0),
              ),
              _NavItem(
                icon: Icons.people_outline,
                activeIcon: Icons.people,
                label: 'Responsáveis',
                isActive: current == 1,
                onTap: () => onTap(1),
              ),
              // Botão central — Minhas Tarefas
              Expanded(
                child: GestureDetector(
                  onTap: () => onTap(2),
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
                onTap: () => onTap(3),
              ),
              _NavItem(
                icon: Icons.menu,
                activeIcon: Icons.menu,
                label: 'Menu',
                isActive: current == 4,
                onTap: () => onTap(4),
              ),
            ],
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
            // Indicador (ponto dourado)
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
