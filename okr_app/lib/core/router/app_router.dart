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
import '../../features/aprovacoes/aprovacao_list_screen.dart';
import '../../features/notificacoes/notificacoes_screen.dart';
import '../../features/profile/profile_screen.dart';
import '../../features/profile/edit_profile_screen.dart';
import '../../features/profile/change_password_screen.dart';

final _rootNavigatorKey = GlobalKey<NavigatorState>();
final _shellNavigatorKey = GlobalKey<NavigatorState>();

final routerProvider = Provider<GoRouter>((ref) {
  final authState = ref.watch(authProvider);

  return GoRouter(
    navigatorKey: _rootNavigatorKey,
    initialLocation: '/dashboard',
    redirect: (context, state) {
      final isAuth = authState.status == AuthStatus.authenticated;
      final loc = state.matchedLocation;
      final publicRoutes = ['/login', '/forgot-password'];

      if (authState.status == AuthStatus.unknown) return null;
      if (!isAuth && !publicRoutes.contains(loc)) return '/login';
      if (isAuth && publicRoutes.contains(loc)) return '/dashboard';
      return null;
    },
    routes: [
      // Public routes
      GoRoute(path: '/login', builder: (_, __) => const LoginScreen()),
      GoRoute(path: '/forgot-password', builder: (_, __) => const ForgotPasswordScreen()),

      // Shell routes (bottom nav)
      ShellRoute(
        navigatorKey: _shellNavigatorKey,
        builder: (_, __, child) => AppShell(child: child),
        routes: [
          GoRoute(
            path: '/dashboard',
            pageBuilder: (_, __) => const NoTransitionPage(child: DashboardScreen()),
          ),
          GoRoute(
            path: '/okrs',
            pageBuilder: (_, __) => const NoTransitionPage(child: OkrListScreen()),
          ),
          GoRoute(
            path: '/aprovacoes',
            pageBuilder: (_, __) => const NoTransitionPage(child: AprovacaoListScreen()),
          ),
          GoRoute(
            path: '/notificacoes',
            pageBuilder: (_, __) => const NoTransitionPage(child: NotificacoesScreen()),
          ),
          GoRoute(
            path: '/perfil',
            pageBuilder: (_, __) => const NoTransitionPage(child: ProfileScreen()),
          ),

          // OKR routes (within shell nav context)
          GoRoute(
            path: '/okrs/novo',
            builder: (_, __) => const ObjetivoFormScreen(),
          ),
          GoRoute(
            path: '/okrs/:id',
            builder: (_, state) => OkrDetailScreen(idObjetivo: state.pathParameters['id']!),
          ),
          GoRoute(
            path: '/okrs/:id/editar',
            builder: (_, state) => ObjetivoFormScreen(idObjetivo: state.pathParameters['id']!),
          ),
          GoRoute(
            path: '/okrs/:idObj/krs/novo',
            builder: (_, state) => KrFormScreen(idObjetivo: state.pathParameters['idObj']!),
          ),

          // KR routes
          GoRoute(
            path: '/krs/:id',
            builder: (_, state) => KrDetailScreen(idKr: state.pathParameters['id']!),
          ),
          GoRoute(
            path: '/krs/:id/editar',
            builder: (_, state) => KrFormScreen(idKr: state.pathParameters['id']!),
          ),

          // Iniciativas routes
          GoRoute(
            path: '/krs/:idKr/iniciativas',
            builder: (_, state) => IniciativaListScreen(idKr: state.pathParameters['idKr']!),
          ),
          GoRoute(
            path: '/krs/:idKr/iniciativas/nova',
            builder: (_, state) => IniciativaFormScreen(idKr: state.pathParameters['idKr']!),
          ),
          GoRoute(
            path: '/iniciativas/:id',
            builder: (_, state) => IniciativaDetailScreen(idIniciativa: state.pathParameters['id']!),
          ),
          GoRoute(
            path: '/iniciativas/:id/editar',
            builder: (_, state) => IniciativaFormScreen(idIniciativa: state.pathParameters['id']!),
          ),

          // Profile routes
          GoRoute(
            path: '/perfil/editar',
            builder: (_, __) => const EditProfileScreen(),
          ),
          GoRoute(
            path: '/perfil/senha',
            builder: (_, __) => const ChangePasswordScreen(),
          ),
        ],
      ),
    ],
  );
});
