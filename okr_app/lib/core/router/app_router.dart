import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../auth/auth_provider.dart';
import '../utils/animations.dart';
import '../../features/auth/login_screen.dart';
import '../../features/auth/forgot_password_screen.dart';
import '../../features/auth/reset_password_screen.dart';
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
      final uri = state.uri;
      final loc = state.matchedLocation;

      // Deep link: web URL → app route
      if (uri.path.contains('password_reset.php')) {
        final selector = uri.queryParameters['selector'] ?? '';
        final verifier = uri.queryParameters['verifier'] ?? '';
        return Uri(path: '/reset-password', queryParameters: {
          'selector': selector,
          'verifier': verifier,
        }).toString();
      }

      final publicRoutes = ['/login', '/forgot-password', '/reset-password'];

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
      // Public routes — fade transitions
      GoRoute(
        path: '/login',
        pageBuilder: (_, __) => fadeTransitionPage(child: const LoginScreen()),
      ),
      GoRoute(
        path: '/forgot-password',
        pageBuilder: (_, __) => fadeTransitionPage(child: const ForgotPasswordScreen()),
      ),
      GoRoute(
        path: '/reset-password',
        pageBuilder: (_, state) {
          final selector = state.uri.queryParameters['selector'] ?? '';
          final verifier = state.uri.queryParameters['verifier'] ?? '';
          return fadeTransitionPage(
            child: ResetPasswordScreen(selector: selector, verifier: verifier),
          );
        },
      ),

      // Shell routes (bottom nav)
      ShellRoute(
        builder: (_, __, child) => AppShell(child: child),
        routes: [
          // === Bottom nav tabs — instant (no transition) ===
          GoRoute(path: '/dashboard', pageBuilder: (_, __) => const NoTransitionPage(child: DashboardScreen())),
          GoRoute(path: '/okrs', pageBuilder: (_, __) => const NoTransitionPage(child: OkrListScreen())),
          GoRoute(path: '/tarefas', pageBuilder: (_, __) => const NoTransitionPage(child: MinhasTarefasScreen())),
          GoRoute(path: '/orcamento', pageBuilder: (_, __) => const NoTransitionPage(child: OrcamentoScreen())),
          GoRoute(path: '/menu', pageBuilder: (_, __) => const NoTransitionPage(child: MenuScreen())),

          // === Menu sub-pages — slide up ===
          GoRoute(
            path: '/aprovacoes',
            pageBuilder: (_, __) => slideUpTransitionPage(child: const AprovacaoListScreen()),
          ),
          GoRoute(
            path: '/notificacoes',
            pageBuilder: (_, __) => slideUpTransitionPage(child: const NotificacoesScreen()),
          ),
          GoRoute(
            path: '/perfil',
            pageBuilder: (_, __) => slideUpTransitionPage(child: const ProfileScreen()),
          ),

          // === OKR routes — slide up for detail/forms ===
          GoRoute(
            path: '/okrs/novo',
            pageBuilder: (_, __) => slideUpTransitionPage(child: const ObjetivoFormScreen()),
          ),
          GoRoute(
            path: '/okrs/:id',
            pageBuilder: (_, state) => slideUpTransitionPage(
              child: OkrDetailScreen(idObjetivo: state.pathParameters['id']!),
            ),
          ),
          GoRoute(
            path: '/okrs/:id/editar',
            pageBuilder: (_, state) => slideUpTransitionPage(
              child: ObjetivoFormScreen(idObjetivo: state.pathParameters['id']!),
            ),
          ),
          GoRoute(
            path: '/okrs/:idObj/krs/novo',
            pageBuilder: (_, state) => slideUpTransitionPage(
              child: KrFormScreen(idObjetivo: state.pathParameters['idObj']!),
            ),
          ),

          // KR routes
          GoRoute(
            path: '/krs/:id',
            pageBuilder: (_, state) => slideUpTransitionPage(
              child: KrDetailScreen(idKr: state.pathParameters['id']!),
            ),
          ),
          GoRoute(
            path: '/krs/:id/editar',
            pageBuilder: (_, state) => slideUpTransitionPage(
              child: KrFormScreen(idKr: state.pathParameters['id']!),
            ),
          ),

          // Iniciativas routes
          GoRoute(
            path: '/krs/:idKr/iniciativas',
            pageBuilder: (_, state) => slideUpTransitionPage(
              child: IniciativaListScreen(idKr: state.pathParameters['idKr']!),
            ),
          ),
          GoRoute(
            path: '/krs/:idKr/iniciativas/nova',
            pageBuilder: (_, state) => slideUpTransitionPage(
              child: IniciativaFormScreen(idKr: state.pathParameters['idKr']!),
            ),
          ),
          GoRoute(
            path: '/iniciativas/:id',
            pageBuilder: (_, state) => slideUpTransitionPage(
              child: IniciativaDetailScreen(idIniciativa: state.pathParameters['id']!),
            ),
          ),
          GoRoute(
            path: '/iniciativas/:id/editar',
            pageBuilder: (_, state) => slideUpTransitionPage(
              child: IniciativaFormScreen(idIniciativa: state.pathParameters['id']!),
            ),
          ),

          // Profile routes
          GoRoute(
            path: '/perfil/editar',
            pageBuilder: (_, __) => slideUpTransitionPage(child: const EditProfileScreen()),
          ),
          GoRoute(
            path: '/perfil/senha',
            pageBuilder: (_, __) => slideUpTransitionPage(child: const ChangePasswordScreen()),
          ),
        ],
      ),
    ],
  );
});
