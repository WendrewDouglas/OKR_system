import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import '../../../core/utils/haptics.dart';
import 'carbon_fiber_bg.dart';
import 'app_header.dart';
import 'app_bottom_nav.dart';

/// Scaffold padrão das páginas full-screen (empilhadas sobre as abas).
///
/// Garante o **cabeçalho de marca** (com botão voltar e título/ações da página)
/// e a **barra inferior** de navegação em todas as páginas — paridade visual
/// com as abas. Tocar numa aba leva para ela, descartando a página atual.
class AppScaffold extends StatelessWidget {
  final Widget body;
  final String? title;
  final List<Widget>? actions;
  final Widget? floatingActionButton;
  final FloatingActionButtonLocation? floatingActionButtonLocation;
  final bool showBack;
  final bool resizeToAvoidBottomInset;

  const AppScaffold({
    super.key,
    required this.body,
    this.title,
    this.actions,
    this.floatingActionButton,
    this.floatingActionButtonLocation,
    this.showBack = true,
    this.resizeToAvoidBottomInset = true,
  });

  @override
  Widget build(BuildContext context) {
    return CarbonFiberBackground(
      child: Scaffold(
        backgroundColor: Colors.transparent,
        resizeToAvoidBottomInset: resizeToAvoidBottomInset,
        appBar: AppHeader(showBack: showBack, title: title, actions: actions),
        body: body,
        floatingActionButton: floatingActionButton,
        floatingActionButtonLocation: floatingActionButtonLocation,
        bottomNavigationBar: AppBottomNav(
          currentIndex: null,
          onTap: (i) {
            AppHaptics.selection();
            context.go(AppBottomNav.routes[i]);
          },
        ),
      ),
    );
  }
}
