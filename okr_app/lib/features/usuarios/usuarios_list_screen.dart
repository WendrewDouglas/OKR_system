import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../core/models/models.dart';
import '../../core/repositories/repositories.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/haptics.dart';
import '../../core/utils/animations.dart';
import '../shared/widgets/loading_shimmer.dart';
import '../shared/widgets/empty_state.dart';
import '../shared/widgets/error_retry.dart';
import '../shared/widgets/status_badge.dart';
import '../shared/widgets/confirm_dialog.dart';
import '../shared/widgets/app_scaffold.dart';

/// Rótulos amigáveis dos papéis RBAC.
const Map<String, String> kRoleLabels = {
  'user_colab': 'Colaborador',
  'gestor_master': 'Gestor',
  'user_admin': 'Admin',
  'admin_master': 'Super Admin',
};

String roleLabel(String key) => kRoleLabels[key] ?? (key.isEmpty ? '—' : key);

final usuariosProvider = FutureProvider.autoDispose<Paged<Usuario>>(
  (ref) => ref.read(usuarioRepositoryProvider).list(perPage: 100),
);

class UsuariosListScreen extends ConsumerWidget {
  const UsuariosListScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final usuarios = ref.watch(usuariosProvider);

    return AppScaffold(
      title: 'Usuários',
      body: usuarios.when(
        loading: () => const LoadingShimmer(),
        error: (e, _) => ErrorRetry(
          message: 'Erro ao carregar usuários',
          onRetry: () => ref.invalidate(usuariosProvider),
        ),
        data: (paged) {
          final items = paged.items;
          if (items.isEmpty) {
            return const EmptyState(
              icon: Icons.people_outline,
              title: 'Nenhum usuário',
              subtitle: 'Cadastre o primeiro usuário da empresa.',
            );
          }
          return RefreshIndicator(
            color: AppColors.gold,
            backgroundColor: AppColors.bgCard,
            onRefresh: () async {
              AppHaptics.medium();
              ref.invalidate(usuariosProvider);
            },
            child: ListView.builder(
              padding: const EdgeInsets.all(16),
              itemCount: items.length,
              itemBuilder: (_, i) => StaggeredFadeSlide(
                index: i,
                child: _UsuarioCard(usuario: items[i]),
              ),
            ),
          );
        },
      ),
      floatingActionButton: FloatingActionButton(
        onPressed: () async {
          AppHaptics.medium();
          final result = await context.push('/usuarios/novo');
          if (result == true) ref.invalidate(usuariosProvider);
        },
        child: const Icon(Icons.person_add_alt_1),
      ),
    );
  }
}

class _UsuarioCard extends ConsumerWidget {
  final Usuario usuario;
  const _UsuarioCard({required this.usuario});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Card(
      margin: const EdgeInsets.only(bottom: 10),
      child: ListTile(
        leading: CircleAvatar(
          backgroundColor: AppColors.gold.withValues(alpha: 0.2),
          child: Text(
            usuario.iniciais.isNotEmpty ? usuario.iniciais : '?',
            style: const TextStyle(color: AppColors.gold, fontWeight: FontWeight.w700, fontSize: 13),
          ),
        ),
        title: Text(usuario.nomeCompleto, maxLines: 1, overflow: TextOverflow.ellipsis,
            style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14)),
        subtitle: Padding(
          padding: const EdgeInsets.only(top: 4),
          child: Row(
            children: [
              if (usuario.email.isNotEmpty)
                Flexible(
                  child: Text(usuario.email, maxLines: 1, overflow: TextOverflow.ellipsis,
                      style: const TextStyle(fontSize: 11, color: AppColors.textMuted)),
                ),
              if (usuario.roleKey.isNotEmpty) ...[
                const SizedBox(width: 8),
                StatusBadge(label: roleLabel(usuario.roleKey), color: AppColors.blue),
              ],
            ],
          ),
        ),
        trailing: PopupMenuButton<String>(
          icon: const Icon(Icons.more_vert, color: AppColors.textMuted, size: 20),
          onSelected: (v) async {
            if (v == 'editar') {
              AppHaptics.light();
              final result = await context.push('/usuarios/${usuario.idUser}/editar');
              if (result == true) ref.invalidate(usuariosProvider);
            } else if (v == 'excluir') {
              await _delete(context, ref);
            }
          },
          itemBuilder: (_) => const [
            PopupMenuItem(value: 'editar', child: Text('Editar')),
            PopupMenuItem(value: 'excluir', child: Text('Excluir', style: TextStyle(color: AppColors.red))),
          ],
        ),
        onTap: () async {
          AppHaptics.light();
          final result = await context.push('/usuarios/${usuario.idUser}/editar');
          if (result == true) ref.invalidate(usuariosProvider);
        },
      ),
    );
  }

  Future<void> _delete(BuildContext context, WidgetRef ref) async {
    AppHaptics.heavy();
    final ok = await showConfirmDialog(
      context,
      title: 'Excluir usuário',
      message: 'Remover ${usuario.nomeCompleto}? Esta ação não pode ser desfeita.',
      confirmLabel: 'Excluir',
      isDanger: true,
    );
    if (!ok) return;
    try {
      await ref.read(usuarioRepositoryProvider).delete(usuario.idUser);
      ref.invalidate(usuariosProvider);
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Usuário excluído.')));
      }
    } catch (e) {
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(apiErrorMessage(e))));
      }
    }
  }
}
