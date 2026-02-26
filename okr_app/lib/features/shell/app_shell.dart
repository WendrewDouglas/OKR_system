import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import '../../core/theme/app_theme.dart';

class AppShell extends StatelessWidget {
  final Widget child;
  const AppShell({super.key, required this.child});

  int _currentIndex(BuildContext context) {
    final location = GoRouterState.of(context).matchedLocation;
    if (location.startsWith('/dashboard')) return 0;
    if (location.startsWith('/okrs')) return 1;
    if (location.startsWith('/aprovacoes')) return 2;
    if (location.startsWith('/notificacoes')) return 3;
    if (location.startsWith('/perfil')) return 4;
    return 0;
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: child,
      bottomNavigationBar: NavigationBar(
        selectedIndex: _currentIndex(context),
        onDestinationSelected: (i) {
          switch (i) {
            case 0: context.go('/dashboard');
            case 1: context.go('/okrs');
            case 2: context.go('/aprovacoes');
            case 3: context.go('/notificacoes');
            case 4: context.go('/perfil');
          }
        },
        backgroundColor: AppColors.bgCard,
        indicatorColor: AppColors.gold.withValues(alpha: 0.15),
        destinations: const [
          NavigationDestination(icon: Icon(Icons.dashboard_outlined), selectedIcon: Icon(Icons.dashboard, color: AppColors.gold), label: 'Dashboard'),
          NavigationDestination(icon: Icon(Icons.flag_outlined), selectedIcon: Icon(Icons.flag, color: AppColors.gold), label: 'OKRs'),
          NavigationDestination(icon: Icon(Icons.check_circle_outline), selectedIcon: Icon(Icons.check_circle, color: AppColors.gold), label: 'Aprovações'),
          NavigationDestination(icon: Icon(Icons.notifications_outlined), selectedIcon: Icon(Icons.notifications, color: AppColors.gold), label: 'Alertas'),
          NavigationDestination(icon: Icon(Icons.person_outline), selectedIcon: Icon(Icons.person, color: AppColors.gold), label: 'Perfil'),
        ],
      ),
    );
  }
}
