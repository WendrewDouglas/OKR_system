import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../auth/auth_provider.dart';
import '../../features/auth/login_screen.dart';
import '../../features/auth/forgot_password_screen.dart';
import '../../features/shell/app_shell.dart';
import '../../features/dashboard/dashboard_screen.dart';
import '../../features/okrs/okr_list_screen.dart';
import '../../features/okrs/okr_detail_screen.dart';
import '../../features/okrs/objetivo_form_screen.dart';
import '../../features/okrs/kr_detail_screen.dart';
import '../../features/okrs/kr_form_screen.dart';
import '../../features/iniciativas/iniciativa_list_screen.dart';
import '../../features/iniciativas/iniciativa_form_screen.dart';
import '../../features/iniciativas/iniciativa_detail_screen.dart';
import '../../features/tarefas/minhas_tarefas_screen.dart';
import '../../features/orcamento/orcamento_screen.dart';
import '../../features/menu/menu_screen.dart';
import '../../features/aprovacoes/aprovacao_list_screen.dart';
import '../../features/notificacoes/notificacoes_screen.dart';
import '../../features/profile/profile_screen.dart';
import '../../features/profile/edit_profile_screen.dart';
import '../../features/profile/change_password_screen.dart';

/// Notifies GoRouter to re-evaluate redirects when auth changes
class AuthChangeNotifier extends ChangeNotifier {
  AuthStatus _status = AuthStatus.unknown;
  AuthStatus get status => _status;

  void update(AuthStatus newStatus) {
    if (_status != newStatus) {
      _status = newStatus;
      notifyListeners();
    }
  }
}

final authChangeNotifierProvider = Provider<AuthChangeNotifier>((ref) {
  final notifier = AuthChangeNotifier();
  ref.listen<AuthState>(authProvider, (_, next) {
    notifier.update(next.status);
  });
  return notifier;
});

final routerProvider = Provider<GoRouter>((ref) {
  final authNotifier = ref.read(authChangeNotifierProvider);

  // Also trigger initial read so listener is active
  ref.watch(authProvider);

  return GoRouter(
    initialLocation: '/dashboard',
    refreshListenable: authNotifier,
    redirect: (context, state) {
      final authStatus = authNotifier.status;
      final loc = state.matchedLocation;
      final publicRoutes = ['/login', '/forgot-password'];

      if (authStatus == AuthStatus.unknown) return null;
      if (authStatus != AuthStatus.authenticated && !publicRoutes.contains(loc)) {
        return '/login';
      }
      if (authStatus == AuthStatus.authenticated && publicRoutes.contains(loc)) {
        return '/dashboard';
      }
      return null;
    },
    routes: [
      // Public routes
      GoRoute(path: '/login', builder: (_, __) => const LoginScreen()),
      GoRoute(path: '/forgot-password', builder: (_, __) => const ForgotPasswordScreen()),

      // Shell routes (bottom nav)
      ShellRoute(
        builder: (_, __, child) => AppShell(child: child),
        routes: [
          // === Bottom nav tabs ===
          GoRoute(path: '/dashboard', pageBuilder: (_, __) => const NoTransitionPage(child: DashboardScreen())),
          GoRoute(path: '/okrs', pageBuilder: (_, __) => const NoTransitionPage(child: OkrListScreen())),
          GoRoute(path: '/tarefas', pageBuilder: (_, __) => const NoTransitionPage(child: MinhasTarefasScreen())),
          GoRoute(path: '/orcamento', pageBuilder: (_, __) => const NoTransitionPage(child: OrcamentoScreen())),
          GoRoute(path: '/menu', pageBuilder: (_, __) => const NoTransitionPage(child: MenuScreen())),

          // === Menu sub-pages ===
          GoRoute(path: '/aprovacoes', builder: (_, __) => const AprovacaoListScreen()),
          GoRoute(path: '/notificacoes', builder: (_, __) => const NotificacoesScreen()),
          GoRoute(path: '/perfil', builder: (_, __) => const ProfileScreen()),

          // === OKR routes ===
          GoRoute(path: '/okrs/novo', builder: (_, __) => const ObjetivoFormScreen()),
          GoRoute(path: '/okrs/:id', builder: (_, state) => OkrDetailScreen(idObjetivo: state.pathParameters['id']!)),
          GoRoute(path: '/okrs/:id/editar', builder: (_, state) => ObjetivoFormScreen(idObjetivo: state.pathParameters['id']!)),
          GoRoute(path: '/okrs/:idObj/krs/novo', builder: (_, state) => KrFormScreen(idObjetivo: state.pathParameters['idObj']!)),

          // KR routes
          GoRoute(path: '/krs/:id', builder: (_, state) => KrDetailScreen(idKr: state.pathParameters['id']!)),
          GoRoute(path: '/krs/:id/editar', builder: (_, state) => KrFormScreen(idKr: state.pathParameters['id']!)),

          // Iniciativas routes
          GoRoute(path: '/krs/:idKr/iniciativas', builder: (_, state) => IniciativaListScreen(idKr: state.pathParameters['idKr']!)),
          GoRoute(path: '/krs/:idKr/iniciativas/nova', builder: (_, state) => IniciativaFormScreen(idKr: state.pathParameters['idKr']!)),
          GoRoute(path: '/iniciativas/:id', builder: (_, state) => IniciativaDetailScreen(idIniciativa: state.pathParameters['id']!)),
          GoRoute(path: '/iniciativas/:id/editar', builder: (_, state) => IniciativaFormScreen(idIniciativa: state.pathParameters['id']!)),

          // Profile routes
          GoRoute(path: '/perfil/editar', builder: (_, __) => const EditProfileScreen()),
          GoRoute(path: '/perfil/senha', builder: (_, __) => const ChangePasswordScreen()),
        ],
      ),
    ],
  );
});
