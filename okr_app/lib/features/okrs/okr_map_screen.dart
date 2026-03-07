import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../core/network/api_client.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/haptics.dart';
import '../../core/utils/animations.dart';
import '../shared/widgets/loading_shimmer.dart';
import '../shared/widgets/empty_state.dart';
import '../shared/widgets/app_header.dart';

// ---------------------------------------------------------------------------
// Provider
// ---------------------------------------------------------------------------

final okrCascataProvider =
    FutureProvider.autoDispose<List<Map<String, dynamic>>>((ref) async {
  final api = ref.read(apiClientProvider);
  final res = await api.dio.get('/dashboard/cascata');
  return ((res.data['objetivos'] as List?) ?? [])
      .cast<Map<String, dynamic>>();
});

// ---------------------------------------------------------------------------
// Screen
// ---------------------------------------------------------------------------

class OkrMapScreen extends ConsumerStatefulWidget {
  const OkrMapScreen({super.key});

  @override
  ConsumerState<OkrMapScreen> createState() => _OkrMapScreenState();
}

class _OkrMapScreenState extends ConsumerState<OkrMapScreen> {
  @override
  Widget build(BuildContext context) {
    final cascata = ref.watch(okrCascataProvider);

    return Scaffold(
      backgroundColor: Colors.transparent,
      appBar: const AppHeader(),
      body: cascata.when(
        loading: () => const LoadingShimmer(),
        error: (e, _) => Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.error_outline, color: AppColors.red, size: 48),
              const SizedBox(height: 12),
              const Text(
                'Erro ao carregar mapa OKR',
                style: TextStyle(color: AppColors.red, fontSize: 16),
              ),
              const SizedBox(height: 8),
              TextButton.icon(
                icon: const Icon(Icons.refresh, size: 18),
                label: const Text('Tentar novamente'),
                onPressed: () {
                  AppHaptics.light();
                  ref.invalidate(okrCascataProvider);
                },
              ),
            ],
          ),
        ),
        data: (objetivos) {
          if (objetivos.isEmpty) {
            return const EmptyState(
              icon: Icons.account_tree_outlined,
              title: 'Nenhum OKR encontrado',
              subtitle:
                  'O mapa ficara disponivel quando houver objetivos cadastrados.',
            );
          }

          // Group objetivos by pilar_bsc
          final pillarMap = <String, List<Map<String, dynamic>>>{};
          for (final obj in objetivos) {
            final pilar = (obj['pilar_bsc'] as String?) ?? 'Sem pilar';
            pillarMap.putIfAbsent(pilar, () => []).add(obj);
          }

          // Sort pillar names consistently
          final sortedPillars = pillarMap.keys.toList()..sort();

          return RefreshIndicator(
            color: AppColors.gold,
            backgroundColor: AppColors.bgCard,
            onRefresh: () async {
              AppHaptics.medium();
              ref.invalidate(okrCascataProvider);
            },
            child: ListView.builder(
              padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
              itemCount: sortedPillars.length + 1,
              itemBuilder: (ctx, i) {
                if (i == 0) {
                  return Padding(
                    padding: const EdgeInsets.only(bottom: 16),
                    child: Row(
                      children: [
                        Container(
                          width: 3,
                          height: 20,
                          decoration: BoxDecoration(
                            gradient: AppColors.goldGradient,
                            borderRadius: BorderRadius.circular(2),
                          ),
                        ),
                        const SizedBox(width: 8),
                        Text(
                          'Mapa OKR',
                          style: Theme.of(context)
                              .textTheme
                              .titleMedium
                              ?.copyWith(fontWeight: FontWeight.w700),
                        ),
                      ],
                    ),
                  );
                }
                final pillarName = sortedPillars[i - 1];
                final pillarObjs = pillarMap[pillarName]!;
                return StaggeredFadeSlide(
                  index: i - 1,
                  child: _PillarTile(
                    pillarName: pillarName,
                    objetivos: pillarObjs,
                  ),
                );
              },
            ),
          );
        },
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

Color _pillarColor(String nome) {
  final n = nome.toLowerCase();
  if (n.contains('financ')) return AppColors.pilarFinanceiro;
  if (n.contains('client')) return AppColors.pilarCliente;
  if (n.contains('process')) return AppColors.pilarProcessos;
  if (n.contains('aprend') || n.contains('conhec')) {
    return AppColors.pilarAprendizado;
  }
  return AppColors.gold;
}

IconData _pillarIcon(String nome) {
  final n = nome.toLowerCase();
  if (n.contains('financ')) return Icons.attach_money;
  if (n.contains('client')) return Icons.people_outline;
  if (n.contains('process')) return Icons.settings_outlined;
  if (n.contains('aprend') || n.contains('conhec')) return Icons.school_outlined;
  return Icons.flag_outlined;
}

/// Returns green / yellow / red based on how many days remain until [deadline].
Color _deadlineColor(String? deadline) {
  if (deadline == null || deadline.isEmpty) return AppColors.textMuted;
  final dt = DateTime.tryParse(deadline);
  if (dt == null) return AppColors.textMuted;
  final diff = dt.difference(DateTime.now()).inDays;
  if (diff < 0) return AppColors.red;
  if (diff <= 15) return AppColors.warn;
  return AppColors.green;
}

Widget _deadlineDot(String? deadline) {
  final color = _deadlineColor(deadline);
  return Container(
    width: 8,
    height: 8,
    decoration: BoxDecoration(shape: BoxShape.circle, color: color),
  );
}

String _statusLabel(String? status) => status ?? '';

// ---------------------------------------------------------------------------
// Pillar tile (Level 0)
// ---------------------------------------------------------------------------

class _PillarTile extends StatefulWidget {
  final String pillarName;
  final List<Map<String, dynamic>> objetivos;

  const _PillarTile({required this.pillarName, required this.objetivos});

  @override
  State<_PillarTile> createState() => _PillarTileState();
}

class _PillarTileState extends State<_PillarTile>
    with SingleTickerProviderStateMixin {
  bool _expanded = false;
  late final AnimationController _chevronCtrl;
  late final Animation<double> _chevronTurns;

  @override
  void initState() {
    super.initState();
    _chevronCtrl = AnimationController(
      vsync: this,
      duration: AppDurations.normal,
    );
    _chevronTurns =
        Tween<double>(begin: 0.0, end: 0.25).animate(CurvedAnimation(
      parent: _chevronCtrl,
      curve: AppCurves.defaultCurve,
    ));
  }

  @override
  void dispose() {
    _chevronCtrl.dispose();
    super.dispose();
  }

  void _toggle() {
    AppHaptics.selection();
    setState(() => _expanded = !_expanded);
    _expanded ? _chevronCtrl.forward() : _chevronCtrl.reverse();
  }

  @override
  Widget build(BuildContext context) {
    final color = _pillarColor(widget.pillarName);
    final icon = _pillarIcon(widget.pillarName);

    // Aggregate counts
    int totalKrs = 0;
    int totalIniciativas = 0;
    String? earliestDeadline;
    for (final obj in widget.objetivos) {
      final krs =
          (obj['key_results'] as List?)?.cast<Map<String, dynamic>>() ?? [];
      totalKrs += krs.length;
      for (final kr in krs) {
        final inics =
            (kr['iniciativas'] as List?)?.cast<Map<String, dynamic>>() ?? [];
        totalIniciativas += inics.length;
      }
      final d = obj['dt_prazo'] as String?;
      if (d != null &&
          (earliestDeadline == null || d.compareTo(earliestDeadline) < 0)) {
        earliestDeadline = d;
      }
    }

    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Card(
        margin: EdgeInsets.zero,
        child: IntrinsicHeight(
          child: Row(
            children: [
              // Colored left border
              Container(
                width: 4,
                decoration: BoxDecoration(
                  color: color,
                  borderRadius: const BorderRadius.only(
                    topLeft: Radius.circular(16),
                    bottomLeft: Radius.circular(16),
                  ),
                ),
              ),
              Expanded(
                child: Column(
                  children: [
                    // Header (tappable)
                    InkWell(
                      borderRadius: const BorderRadius.only(
                        topRight: Radius.circular(16),
                        bottomRight: Radius.circular(16),
                      ),
                      onTap: _toggle,
                      child: Padding(
                        padding: const EdgeInsets.symmetric(
                            horizontal: 14, vertical: 14),
                        child: Row(
                          children: [
                            Icon(icon, color: color, size: 22),
                            const SizedBox(width: 10),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(
                                    widget.pillarName,
                                    style: const TextStyle(
                                      fontWeight: FontWeight.w700,
                                      fontSize: 15,
                                    ),
                                  ),
                                  const SizedBox(height: 4),
                                  Text(
                                    '${widget.objetivos.length} obj  ·  $totalKrs KRs  ·  $totalIniciativas inic.',
                                    style: const TextStyle(
                                      color: AppColors.textMuted,
                                      fontSize: 12,
                                    ),
                                  ),
                                ],
                              ),
                            ),
                            _deadlineDot(earliestDeadline),
                            const SizedBox(width: 8),
                            RotationTransition(
                              turns: _chevronTurns,
                              child: const Icon(
                                Icons.chevron_right,
                                color: AppColors.textMuted,
                                size: 22,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                    // Expandable children
                    AnimatedSize(
                      duration: AppDurations.normal,
                      curve: AppCurves.defaultCurve,
                      alignment: Alignment.topCenter,
                      child: _expanded
                          ? Padding(
                              padding: const EdgeInsets.only(
                                  left: 12, right: 12, bottom: 12),
                              child: Column(
                                children: [
                                  Container(
                                    height: 0.5,
                                    color: AppColors.borderDefault,
                                  ),
                                  const SizedBox(height: 8),
                                  ...List.generate(
                                    widget.objetivos.length,
                                    (i) => _ObjetivoTile(
                                        obj: widget.objetivos[i]),
                                  ),
                                ],
                              ),
                            )
                          : const SizedBox.shrink(),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Objetivo tile (Level 1)
// ---------------------------------------------------------------------------

class _ObjetivoTile extends StatefulWidget {
  final Map<String, dynamic> obj;
  const _ObjetivoTile({required this.obj});

  @override
  State<_ObjetivoTile> createState() => _ObjetivoTileState();
}

class _ObjetivoTileState extends State<_ObjetivoTile>
    with SingleTickerProviderStateMixin {
  bool _expanded = false;
  late final AnimationController _chevronCtrl;
  late final Animation<double> _chevronTurns;

  @override
  void initState() {
    super.initState();
    _chevronCtrl = AnimationController(
      vsync: this,
      duration: AppDurations.normal,
    );
    _chevronTurns =
        Tween<double>(begin: 0.0, end: 0.25).animate(CurvedAnimation(
      parent: _chevronCtrl,
      curve: AppCurves.defaultCurve,
    ));
  }

  @override
  void dispose() {
    _chevronCtrl.dispose();
    super.dispose();
  }

  void _toggle() {
    AppHaptics.selection();
    setState(() => _expanded = !_expanded);
    _expanded ? _chevronCtrl.forward() : _chevronCtrl.reverse();
  }

  @override
  Widget build(BuildContext context) {
    final desc = widget.obj['descricao'] ?? '';
    final status = _statusLabel(widget.obj['status'] as String?);
    final deadline = widget.obj['dt_prazo'] as String?;
    final dono =
        (widget.obj['dono'] as Map<String, dynamic>?)?['nome'] ?? '';
    final krs = (widget.obj['key_results'] as List?)
            ?.cast<Map<String, dynamic>>() ??
        [];

    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      decoration: BoxDecoration(
        color: AppColors.bgSurface,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.borderDefault, width: 0.5),
      ),
      child: Column(
        children: [
          // Header row
          InkWell(
            borderRadius: BorderRadius.circular(12),
            onTap: _toggle,
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
              child: Row(
                children: [
                  const Icon(Icons.flag_outlined,
                      size: 16, color: AppColors.gold),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          desc,
                          style: const TextStyle(
                            fontWeight: FontWeight.w600,
                            fontSize: 13,
                          ),
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                        ),
                        const SizedBox(height: 3),
                        Row(
                          children: [
                            _deadlineDot(deadline),
                            const SizedBox(width: 4),
                            Text(
                              status,
                              style: const TextStyle(
                                color: AppColors.textMuted,
                                fontSize: 11,
                              ),
                            ),
                            if (dono.isNotEmpty) ...[
                              const SizedBox(width: 8),
                              const Icon(Icons.person_outline,
                                  size: 12, color: AppColors.textMuted),
                              const SizedBox(width: 2),
                              Flexible(
                                child: Text(
                                  dono,
                                  style: const TextStyle(
                                    color: AppColors.textMuted,
                                    fontSize: 11,
                                  ),
                                  overflow: TextOverflow.ellipsis,
                                ),
                              ),
                            ],
                          ],
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(width: 4),
                  // Tap to navigate
                  GestureDetector(
                    onTap: () {
                      AppHaptics.light();
                      context.push('/okrs/${widget.obj['id_objetivo']}');
                    },
                    child: const Icon(Icons.open_in_new,
                        size: 16, color: AppColors.textMuted),
                  ),
                  const SizedBox(width: 6),
                  if (krs.isNotEmpty)
                    RotationTransition(
                      turns: _chevronTurns,
                      child: const Icon(Icons.chevron_right,
                          size: 18, color: AppColors.textMuted),
                    ),
                ],
              ),
            ),
          ),
          // KR children
          AnimatedSize(
            duration: AppDurations.normal,
            curve: AppCurves.defaultCurve,
            alignment: Alignment.topCenter,
            child: _expanded && krs.isNotEmpty
                ? Padding(
                    padding:
                        const EdgeInsets.only(left: 16, right: 8, bottom: 8),
                    child: Column(
                      children: [
                        Container(
                          height: 0.5,
                          margin: const EdgeInsets.only(bottom: 6),
                          color: AppColors.borderDefault,
                        ),
                        ...List.generate(
                          krs.length,
                          (i) => _KrTile(kr: krs[i]),
                        ),
                      ],
                    ),
                  )
                : const SizedBox.shrink(),
          ),
        ],
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// KR tile (Level 2)
// ---------------------------------------------------------------------------

class _KrTile extends StatefulWidget {
  final Map<String, dynamic> kr;
  const _KrTile({required this.kr});

  @override
  State<_KrTile> createState() => _KrTileState();
}

class _KrTileState extends State<_KrTile>
    with SingleTickerProviderStateMixin {
  bool _expanded = false;
  late final AnimationController _chevronCtrl;
  late final Animation<double> _chevronTurns;

  @override
  void initState() {
    super.initState();
    _chevronCtrl = AnimationController(
      vsync: this,
      duration: AppDurations.normal,
    );
    _chevronTurns =
        Tween<double>(begin: 0.0, end: 0.25).animate(CurvedAnimation(
      parent: _chevronCtrl,
      curve: AppCurves.defaultCurve,
    ));
  }

  @override
  void dispose() {
    _chevronCtrl.dispose();
    super.dispose();
  }

  void _toggle() {
    AppHaptics.selection();
    setState(() => _expanded = !_expanded);
    _expanded ? _chevronCtrl.forward() : _chevronCtrl.reverse();
  }

  Color _farolColor(String? farol) {
    switch (farol?.toLowerCase()) {
      case 'verde':
        return AppColors.green;
      case 'amarelo':
        return AppColors.warn;
      case 'vermelho':
        return AppColors.red;
      default:
        return AppColors.textMuted;
    }
  }

  @override
  Widget build(BuildContext context) {
    final desc = widget.kr['descricao'] ?? '';
    final status = _statusLabel(widget.kr['status'] as String?);
    final farol = widget.kr['farol'] as String?;
    final deadline = widget.kr['data_fim'] as String?;
    final responsavel =
        (widget.kr['responsavel'] as Map<String, dynamic>?)?['nome'] ?? '';
    final iniciativas = (widget.kr['iniciativas'] as List?)
            ?.cast<Map<String, dynamic>>() ??
        [];

    return Container(
      margin: const EdgeInsets.only(bottom: 6),
      decoration: BoxDecoration(
        color: AppColors.bgCard,
        borderRadius: BorderRadius.circular(10),
        border: Border(
          left: BorderSide(color: _farolColor(farol), width: 3),
        ),
      ),
      child: Column(
        children: [
          InkWell(
            borderRadius: BorderRadius.circular(10),
            onTap: _toggle,
            child: Padding(
              padding:
                  const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
              child: Row(
                children: [
                  Icon(Icons.track_changes,
                      size: 14, color: _farolColor(farol)),
                  const SizedBox(width: 6),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          desc,
                          style: const TextStyle(
                            fontWeight: FontWeight.w500,
                            fontSize: 12,
                          ),
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                        ),
                        const SizedBox(height: 2),
                        Row(
                          children: [
                            _deadlineDot(deadline),
                            const SizedBox(width: 4),
                            Text(
                              status,
                              style: const TextStyle(
                                color: AppColors.textMuted,
                                fontSize: 10,
                              ),
                            ),
                            if (responsavel.isNotEmpty) ...[
                              const SizedBox(width: 6),
                              const Icon(Icons.person_outline,
                                  size: 10, color: AppColors.textMuted),
                              const SizedBox(width: 2),
                              Flexible(
                                child: Text(
                                  responsavel,
                                  style: const TextStyle(
                                    color: AppColors.textMuted,
                                    fontSize: 10,
                                  ),
                                  overflow: TextOverflow.ellipsis,
                                ),
                              ),
                            ],
                          ],
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(width: 4),
                  GestureDetector(
                    onTap: () {
                      AppHaptics.light();
                      context.push('/krs/${widget.kr['id_kr']}');
                    },
                    child: const Icon(Icons.open_in_new,
                        size: 14, color: AppColors.textMuted),
                  ),
                  const SizedBox(width: 4),
                  if (iniciativas.isNotEmpty)
                    RotationTransition(
                      turns: _chevronTurns,
                      child: const Icon(Icons.chevron_right,
                          size: 16, color: AppColors.textMuted),
                    ),
                ],
              ),
            ),
          ),
          // Iniciativa children
          AnimatedSize(
            duration: AppDurations.normal,
            curve: AppCurves.defaultCurve,
            alignment: Alignment.topCenter,
            child: _expanded && iniciativas.isNotEmpty
                ? Padding(
                    padding: const EdgeInsets.only(
                        left: 14, right: 6, bottom: 6),
                    child: Column(
                      children: [
                        Container(
                          height: 0.5,
                          margin: const EdgeInsets.only(bottom: 4),
                          color: AppColors.borderDefault,
                        ),
                        ...List.generate(
                          iniciativas.length,
                          (i) =>
                              _IniciativaTile(iniciativa: iniciativas[i]),
                        ),
                      ],
                    ),
                  )
                : const SizedBox.shrink(),
          ),
        ],
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Iniciativa tile (Level 3 — leaf)
// ---------------------------------------------------------------------------

class _IniciativaTile extends StatelessWidget {
  final Map<String, dynamic> iniciativa;
  const _IniciativaTile({required this.iniciativa});

  @override
  Widget build(BuildContext context) {
    final desc = iniciativa['descricao'] ?? '';
    final status = _statusLabel(iniciativa['status'] as String?);
    final deadline = iniciativa['dt_prazo'] as String?;
    final responsavel =
        (iniciativa['responsavel'] as Map<String, dynamic>?)?['nome'] ?? '';

    return GestureDetector(
      onTap: () {
        AppHaptics.light();
        context.push('/iniciativas/${iniciativa['id_iniciativa']}');
      },
      child: Container(
        margin: const EdgeInsets.only(bottom: 4),
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 6),
        decoration: BoxDecoration(
          color: AppColors.bgDeep,
          borderRadius: BorderRadius.circular(8),
        ),
        child: Row(
          children: [
            const Icon(Icons.task_alt, size: 12, color: AppColors.textMuted),
            const SizedBox(width: 6),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    desc,
                    style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w500),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                  const SizedBox(height: 2),
                  Row(
                    children: [
                      _deadlineDot(deadline),
                      const SizedBox(width: 4),
                      Text(
                        status,
                        style: const TextStyle(
                            color: AppColors.textMuted, fontSize: 10),
                      ),
                      if (responsavel.isNotEmpty) ...[
                        const SizedBox(width: 6),
                        const Icon(Icons.person_outline,
                            size: 10, color: AppColors.textMuted),
                        const SizedBox(width: 2),
                        Flexible(
                          child: Text(
                            responsavel,
                            style: const TextStyle(
                                color: AppColors.textMuted, fontSize: 10),
                            overflow: TextOverflow.ellipsis,
                          ),
                        ),
                      ],
                    ],
                  ),
                ],
              ),
            ),
            const Icon(Icons.chevron_right,
                size: 14, color: AppColors.textMuted),
          ],
        ),
      ),
    );
  }
}
