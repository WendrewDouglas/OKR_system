import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import '../../core/utils/haptics.dart';
import '../shared/widgets/carbon_fiber_bg.dart';
import '../shared/widgets/app_bottom_nav.dart';

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
    return CarbonFiberBackground(
      child: Scaffold(
        backgroundColor: Colors.transparent,
        body: navigationShell,
        bottomNavigationBar: AppBottomNav(
          currentIndex: navigationShell.currentIndex,
          onTap: _onTap,
        ),
      ),
    );
  }
}
