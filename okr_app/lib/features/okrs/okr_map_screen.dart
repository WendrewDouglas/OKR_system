import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../core/network/api_client.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/haptics.dart';
import '../../core/utils/animations.dart';
import '../shared/widgets/loading_shimmer.dart';
import '../shared/widgets/error_retry.dart';
import '../shared/widgets/app_header.dart';
import '../shared/widgets/user_avatar.dart';

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

/// Progresso/esperado/farol por pilar, calculado no backend (helper kr_progress).
class PillarProgress {
  final double? progress; // 0..100 (barra)
  final double? esperado; // 0..100 (tick)
  final String farol; // verde | amarelo | vermelho | cinza
  const PillarProgress({this.progress, this.esperado, required this.farol});
}

/// Mapa: pilar canônico → PillarProgress (fonte: /dashboard/mapa-estrategico).
final mapaEstrategicoProvider =
    FutureProvider.autoDispose<Map<String, PillarProgress>>((ref) async {
  final api = ref.read(apiClientProvider);
  final res = await api.dio.get('/dashboard/mapa-estrategico');
  final pillars = ((res.data['pillars'] as List?) ?? [])
      .cast<Map<String, dynamic>>();
  final map = <String, PillarProgress>{};
  for (final p in pillars) {
    final canon = _canonicalPillar((p['pilar_nome'] as String?) ?? '');
    map[canon] = PillarProgress(
      progress: (p['progress'] as num?)?.toDouble(),
      esperado: (p['esperado'] as num?)?.toDouble(),
      farol: (p['farol'] as String?) ?? 'cinza',
    );
  }
  return map;
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
  bool _fabOpen = false;

  Future<void> _abrir(String rota) async {
    setState(() => _fabOpen = false);
    AppHaptics.light();
    // A API exige W:objetivo@ORG / W:kr@ORG; quem não tiver recebe 403 no submit.
    final created = await context.push(rota);
    if (created == true) {
      ref.invalidate(okrCascataProvider);
      ref.invalidate(mapaEstrategicoProvider);
    }
  }

  @override
  Widget build(BuildContext context) {
    final cascata = ref.watch(okrCascataProvider);
    final pillarProgress = ref.watch(mapaEstrategicoProvider).asData?.value;

    return Scaffold(
      backgroundColor: Colors.transparent,
      appBar: const AppHeader(),
      floatingActionButton: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          if (_fabOpen) ...[
            _SpeedDialAction(
              label: 'Novo Objetivo',
              icon: Icons.flag_outlined,
              onTap: () => _abrir('/okrs/novo'),
            ),
            const SizedBox(height: 12),
            _SpeedDialAction(
              label: 'Novo KR',
              icon: Icons.trending_up,
              onTap: () => _abrir('/krs/novo'),
            ),
            const SizedBox(height: 12),
          ],
          FloatingActionButton(
            onPressed: () {
              AppHaptics.medium();
              setState(() => _fabOpen = !_fabOpen);
            },
            child: AnimatedRotation(
              turns: _fabOpen ? 0.125 : 0, // "+" gira para "x" quando aberto
              duration: const Duration(milliseconds: 200),
              child: const Icon(Icons.add),
            ),
          ),
        ],
      ),
      body: Stack(
        children: [
          cascata.when(
        loading: () => const LoadingShimmer(),
        error: (e, _) => ErrorRetry(
          message: 'Erro ao carregar mapa OKR',
          onRetry: () => ref.invalidate(okrCascataProvider),
        ),
        data: (objetivos) {
          // Os 4 pilares BSC sempre aparecem, na ordem canônica, mesmo sem
          // objetivos (os vazios ficam "apagados"/inativos).
          final pillarMap = <String, List<Map<String, dynamic>>>{
            for (final p in _bscPillars) p: <Map<String, dynamic>>[],
          };
          for (final obj in objetivos) {
            final canon = _canonicalPillar((obj['pilar_bsc'] as String?) ?? '');
            pillarMap.putIfAbsent(canon, () => []).add(obj);
          }
          // 4 pilares canônicos primeiro; qualquer pilar extra/desconhecido vem depois.
          final pillars = <String>[
            ..._bscPillars,
            ...pillarMap.keys.where((k) => !_bscPillars.contains(k)),
          ];

          return RefreshIndicator(
            color: AppColors.gold,
            backgroundColor: AppColors.bgCard,
            onRefresh: () async {
              AppHaptics.medium();
              ref.invalidate(okrCascataProvider);
              ref.invalidate(mapaEstrategicoProvider);
            },
            child: ListView.builder(
              padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
              itemCount: pillars.length + 1,
              itemBuilder: (ctx, i) {
                if (i == 0) {
                  return Padding(
                    padding: const EdgeInsets.only(bottom: 16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
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
                              'Mapa BSC|OKR',
                              style: Theme.of(context)
                                  .textTheme
                                  .titleMedium
                                  ?.copyWith(fontWeight: FontWeight.w700),
                            ),
                          ],
                        ),
                        const SizedBox(height: 16),
                        _OkrStatsRow(objetivos: objetivos),
                      ],
                    ),
                  );
                }
                final pillarName = pillars[i - 1];
                final pillarObjs = pillarMap[pillarName]!;
                return StaggeredFadeSlide(
                  index: i - 1,
                  child: _PillarTile(
                    pillarName: pillarName,
                    objetivos: pillarObjs,
                    prog: pillarProgress?[pillarName],
                  ),
                );
              },
            ),
          );
        },
          ),
          // Scrim: fecha o menu ao tocar fora.
          if (_fabOpen)
            Positioned.fill(
              child: GestureDetector(
                onTap: () => setState(() => _fabOpen = false),
                child: const ColoredBox(color: Colors.black54),
              ),
            ),
        ],
      ),
    );
  }
}

/// Ação do speed-dial: rótulo + mini-FAB (usado no menu do botão "+").
class _SpeedDialAction extends StatelessWidget {
  final String label;
  final IconData icon;
  final VoidCallback onTap;
  const _SpeedDialAction({required this.label, required this.icon, required this.onTap});

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
          decoration: BoxDecoration(
            color: AppColors.bgCard,
            borderRadius: BorderRadius.circular(8),
            border: Border.all(color: AppColors.borderDefault, width: 0.5),
            boxShadow: [BoxShadow(color: Colors.black.withValues(alpha: 0.3), blurRadius: 6)],
          ),
          child: Text(label, style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600)),
        ),
        const SizedBox(width: 12),
        FloatingActionButton.small(
          heroTag: 'speeddial_$label',
          onPressed: onTap,
          child: Icon(icon),
        ),
      ],
    );
  }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/// Os 4 pilares BSC na ordem canônica de exibição.
const List<String> _bscPillars = [
  'Financeiro',
  'Clientes',
  'Processos Internos',
  'Aprendizado e Crescimento',
];

/// Normaliza o nome do pilar de um objetivo para um dos 4 pilares canônicos.
/// Mantém o nome original caso não case com nenhum (pilar extra/desconhecido).
String _canonicalPillar(String nome) {
  final n = nome.toLowerCase();
  if (n.contains('financ')) return 'Financeiro';
  if (n.contains('client')) return 'Clientes';
  if (n.contains('process')) return 'Processos Internos';
  if (n.contains('aprend') || n.contains('conhec')) {
    return 'Aprendizado e Crescimento';
  }
  return nome.isEmpty ? 'Sem pilar' : nome;
}

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

/// Status que contam como "encerrados" (não entram em risco/atraso).
bool _statusEncerrado(String? status) {
  final s = (status ?? '').toLowerCase();
  return s.contains('conclu') ||
      s.contains('complet') ||
      s.contains('finaliz') ||
      s.contains('cancel') ||
      s.contains('reprovad');
}

/// True quando o prazo já passou (mesma régua de [_deadlineColor]).
bool _isOverdue(String? deadline) {
  if (deadline == null || deadline.isEmpty) return false;
  final dt = DateTime.tryParse(deadline);
  if (dt == null) return false;
  return dt.difference(DateTime.now()).inDays < 0;
}

// ---------------------------------------------------------------------------
// Cards de resumo (Objetivos / Key Results / Iniciativas) — topo do mapa.
// Calculados a partir da própria árvore /dashboard/cascata já carregada.
// ---------------------------------------------------------------------------

class _OkrStatsRow extends StatelessWidget {
  final List<Map<String, dynamic>> objetivos;
  const _OkrStatsRow({required this.objetivos});

  @override
  Widget build(BuildContext context) {
    var totalObj = objetivos.length;
    var objRisco = 0;
    var totalKr = 0;
    var krRisco = 0;
    var totalInic = 0;
    var inicAtrasadas = 0;

    for (final obj in objetivos) {
      final objStatus = obj['status'] as String?;
      final krs =
          (obj['key_results'] as List?)?.cast<Map<String, dynamic>>() ?? [];
      var objTemKrVermelho = false;

      for (final kr in krs) {
        totalKr++;
        final farol = (kr['farol'] as String?)?.toLowerCase();
        if (farol == 'vermelho') {
          krRisco++;
          objTemKrVermelho = true;
        }
        final inics =
            (kr['iniciativas'] as List?)?.cast<Map<String, dynamic>>() ?? [];
        for (final ini in inics) {
          totalInic++;
          final iniStatus = ini['status'] as String?;
          if (!_statusEncerrado(iniStatus) &&
              _isOverdue(ini['dt_prazo'] as String?)) {
            inicAtrasadas++;
          }
        }
      }

      final emRisco =
          (objStatus ?? '').toLowerCase().contains('risco') || objTemKrVermelho;
      if (!_statusEncerrado(objStatus) && emRisco) objRisco++;
    }

    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Expanded(
            child: _StatCard(
              icon: Icons.flag_outlined,
            accent: AppColors.gold,
            total: totalObj,
            label: 'Objetivos',
            alert: objRisco,
            alertLabel: 'em risco',
            okLabel: 'sem risco',
          ),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: _StatCard(
            icon: Icons.track_changes,
            accent: AppColors.blue,
            total: totalKr,
            label: 'Key Results',
            alert: krRisco,
            alertLabel: 'em risco',
            okLabel: 'sem risco',
          ),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: _StatCard(
            icon: Icons.task_alt,
            accent: AppColors.pilarAprendizado,
            total: totalInic,
            label: 'Iniciativas',
            alert: inicAtrasadas,
            alertLabel: 'atrasadas',
            okLabel: 'no prazo',
          ),
        ),
        ],
      ),
    );
  }
}

class _StatCard extends StatelessWidget {
  final IconData icon;
  final Color accent;
  final int total;
  final String label;
  final int alert;
  final String alertLabel;
  final String okLabel;

  const _StatCard({
    required this.icon,
    required this.accent,
    required this.total,
    required this.label,
    required this.alert,
    required this.alertLabel,
    required this.okLabel,
  });

  @override
  Widget build(BuildContext context) {
    final hasAlert = alert > 0;
    final alertColor = hasAlert ? AppColors.red : AppColors.green;

    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: AppColors.bgCard,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: AppColors.borderDefault, width: 0.5),
        boxShadow: AppShadows.cardRest,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            padding: const EdgeInsets.all(7),
            decoration: BoxDecoration(
              color: accent.withValues(alpha: 0.12),
              borderRadius: BorderRadius.circular(9),
            ),
            child: Icon(icon, color: accent, size: 18),
          ),
          const SizedBox(height: 10),
          Text(
            '$total',
            style: const TextStyle(
              fontSize: 22,
              fontWeight: FontWeight.w800,
              height: 1.0,
            ),
          ),
          const SizedBox(height: 3),
          Text(
            label,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: const TextStyle(
              color: AppColors.textMuted,
              fontSize: 11,
              fontWeight: FontWeight.w500,
            ),
          ),
          const SizedBox(height: 8),
          Row(
            children: [
              Container(
                width: 6,
                height: 6,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: alertColor,
                ),
              ),
              const SizedBox(width: 5),
              Expanded(
                child: Text(
                  hasAlert ? '$alert $alertLabel' : okLabel,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    color: alertColor,
                    fontSize: 11,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

/// Avatar pequeno (com fallback para iniciais) lido de um map de pessoa
/// (dono/responsavel) do endpoint /dashboard/cascata.
Widget _personAvatar(Map<String, dynamic>? person, {double radius = 8}) {
  final nome = (person?['nome'] as String?) ?? '';
  final parts = nome.trim().split(RegExp(r'\s+'));
  final firstName = parts.isNotEmpty ? parts.first : '';
  final lastName = parts.length > 1 ? parts.sublist(1).join(' ') : '';
  return UserAvatar(
    avatarUrl: person?['avatar_url'] as String?,
    firstName: firstName,
    lastName: lastName,
    radius: radius,
  );
}

// ---------------------------------------------------------------------------
// Tile expansível reutilizável (chevron animado + expand/collapse).
// Centraliza a lógica que era triplicada em pilar/objetivo/KR.
// ---------------------------------------------------------------------------

class _ExpandableTile extends StatefulWidget {
  /// Constrói o cabeçalho; recebe o callback de toggle e a animação do chevron.
  final Widget Function(BuildContext context, VoidCallback toggle, Animation<double> chevronTurns) header;
  /// Conteúdo expansível (só é construído quando aberto).
  final WidgetBuilder children;

  const _ExpandableTile({required this.header, required this.children});

  @override
  State<_ExpandableTile> createState() => _ExpandableTileState();
}

class _ExpandableTileState extends State<_ExpandableTile>
    with SingleTickerProviderStateMixin {
  bool _expanded = false;
  late final AnimationController _chevronCtrl;
  late final Animation<double> _chevronTurns;

  @override
  void initState() {
    super.initState();
    _chevronCtrl = AnimationController(vsync: this, duration: AppDurations.normal);
    _chevronTurns = Tween<double>(begin: 0.0, end: 0.25).animate(
      CurvedAnimation(parent: _chevronCtrl, curve: AppCurves.defaultCurve),
    );
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
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        widget.header(context, _toggle, _chevronTurns),
        AnimatedSize(
          duration: AppDurations.normal,
          curve: AppCurves.defaultCurve,
          alignment: Alignment.topCenter,
          child: _expanded ? widget.children(context) : const SizedBox.shrink(),
        ),
      ],
    );
  }
}

// ---------------------------------------------------------------------------
// Pillar tile (Level 0)
// ---------------------------------------------------------------------------

Color _farolColor(String farol) {
  switch (farol) {
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

/// Barra de progresso do pilar: preenchimento na cor do farol + tick do esperado.
class _PillarProgressBar extends StatelessWidget {
  final PillarProgress prog;
  const _PillarProgressBar({required this.prog});

  @override
  Widget build(BuildContext context) {
    final pct = ((prog.progress ?? 0).clamp(0, 100)).toDouble();
    final esp = prog.esperado?.clamp(0, 100).toDouble();
    final c = _farolColor(prog.farol);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        LayoutBuilder(
          builder: (context, constraints) {
            final w = constraints.maxWidth;
            return SizedBox(
              width: w,
              height: 12,
              child: Stack(
                clipBehavior: Clip.none,
                children: [
                  // track
                  Positioned(
                    left: 0,
                    right: 0,
                    top: 2,
                    child: Container(
                      height: 8,
                      decoration: BoxDecoration(
                        color: c.withValues(alpha: 0.15),
                        borderRadius: BorderRadius.circular(4),
                      ),
                    ),
                  ),
                  // fill
                  Positioned(
                    left: 0,
                    top: 2,
                    child: Container(
                      width: w * pct / 100.0,
                      height: 8,
                      decoration: BoxDecoration(
                        color: c,
                        borderRadius: BorderRadius.circular(4),
                      ),
                    ),
                  ),
                  // tick do esperado
                  if (esp != null)
                    Positioned(
                      left: (w * esp / 100.0 - 1).clamp(0.0, w - 2.0),
                      top: 0,
                      child: Container(
                        width: 2,
                        height: 12,
                        decoration: BoxDecoration(
                          color: AppColors.text,
                          borderRadius: BorderRadius.circular(1),
                        ),
                      ),
                    ),
                ],
              ),
            );
          },
        ),
        const SizedBox(height: 6),
        Row(
          children: [
            Text(
              '${pct.toStringAsFixed(0)}%',
              style: TextStyle(color: c, fontSize: 12, fontWeight: FontWeight.w700),
            ),
            if (esp != null) ...[
              const SizedBox(width: 8),
              Text(
                'esperado ${esp.toStringAsFixed(0)}%',
                style: const TextStyle(color: AppColors.textMuted, fontSize: 11),
              ),
            ],
          ],
        ),
      ],
    );
  }
}

class _PillarTile extends StatelessWidget {
  final String pillarName;
  final List<Map<String, dynamic>> objetivos;
  final PillarProgress? prog;

  const _PillarTile({
    required this.pillarName,
    required this.objetivos,
    this.prog,
  });

  @override
  Widget build(BuildContext context) {
    final color = _pillarColor(pillarName);
    final icon = _pillarIcon(pillarName);

    // Pilar sem objetivos: card "apagado"/inativo (não expansível).
    if (objetivos.isEmpty) {
      return Padding(
        padding: const EdgeInsets.only(bottom: 12),
        child: Opacity(
          opacity: 0.42,
          child: Card(
            margin: EdgeInsets.zero,
            clipBehavior: Clip.antiAlias,
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Container(width: 4, decoration: BoxDecoration(color: color)),
                Expanded(
                  child: Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
                    child: Row(
                      children: [
                        Icon(icon, color: color, size: 22),
                        const SizedBox(width: 10),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                pillarName,
                                style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15),
                              ),
                              const SizedBox(height: 4),
                              const Text(
                                'Sem objetivos',
                                style: TextStyle(color: AppColors.textMuted, fontSize: 12),
                              ),
                            ],
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
      );
    }

    // Aggregate counts
    int totalKrs = 0;
    int totalIniciativas = 0;
    String? earliestDeadline;
    for (final obj in objetivos) {
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
        clipBehavior: Clip.antiAlias,
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Colored left border — stretches with content
            Container(width: 4, decoration: BoxDecoration(color: color)),
            Expanded(
              child: _ExpandableTile(
                header: (context, toggle, turns) => InkWell(
                  borderRadius: const BorderRadius.only(
                    topRight: Radius.circular(16),
                    bottomRight: Radius.circular(16),
                  ),
                  onTap: toggle,
                  child: Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
                          children: [
                            Icon(icon, color: color, size: 22),
                            const SizedBox(width: 10),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(
                                    pillarName,
                                    style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15),
                                  ),
                                  const SizedBox(height: 4),
                                  Text(
                                    '${objetivos.length} obj  ·  $totalKrs KRs  ·  $totalIniciativas inic.',
                                    style: const TextStyle(color: AppColors.textMuted, fontSize: 12),
                                  ),
                                ],
                              ),
                            ),
                            _deadlineDot(earliestDeadline),
                            const SizedBox(width: 8),
                            RotationTransition(
                              turns: turns,
                              child: const Icon(Icons.chevron_right, color: AppColors.textMuted, size: 22),
                            ),
                          ],
                        ),
                        if (prog?.progress != null) ...[
                          const SizedBox(height: 12),
                          _PillarProgressBar(prog: prog!),
                        ],
                      ],
                    ),
                  ),
                ),
                children: (context) => Padding(
                  padding: const EdgeInsets.only(left: 12, right: 12, bottom: 12),
                  child: Column(
                    children: [
                      Container(height: 0.5, color: AppColors.borderDefault),
                      const SizedBox(height: 8),
                      ...List.generate(
                        objetivos.length,
                        (i) => _ObjetivoTile(obj: objetivos[i]),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Objetivo tile (Level 1)
// ---------------------------------------------------------------------------

class _ObjetivoTile extends StatelessWidget {
  final Map<String, dynamic> obj;
  const _ObjetivoTile({required this.obj});

  @override
  Widget build(BuildContext context) {
    final desc = obj['descricao'] ?? '';
    final status = _statusLabel(obj['status'] as String?);
    final deadline = obj['dt_prazo'] as String?;
    final donoMap = obj['dono'] as Map<String, dynamic>?;
    final dono = (donoMap?['nome'] as String?) ?? '';
    final krs = (obj['key_results'] as List?)?.cast<Map<String, dynamic>>() ?? [];

    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      decoration: BoxDecoration(
        color: AppColors.bgSurface,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.borderDefault, width: 0.5),
      ),
      child: _ExpandableTile(
        header: (context, toggle, turns) => InkWell(
          borderRadius: BorderRadius.circular(12),
          onTap: toggle,
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
            child: Row(
              children: [
                const Icon(Icons.flag_outlined, size: 16, color: AppColors.gold),
                const SizedBox(width: 8),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        desc,
                        style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13),
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                      ),
                      const SizedBox(height: 3),
                      Row(
                        children: [
                          _deadlineDot(deadline),
                          const SizedBox(width: 4),
                          Text(status, style: const TextStyle(color: AppColors.textMuted, fontSize: 11)),
                          if (dono.isNotEmpty) ...[
                            const SizedBox(width: 8),
                            _personAvatar(donoMap, radius: 9),
                            const SizedBox(width: 4),
                            Flexible(
                              child: Text(dono,
                                  style: const TextStyle(color: AppColors.textMuted, fontSize: 11),
                                  overflow: TextOverflow.ellipsis),
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
                    context.push('/okrs/${obj['id_objetivo']}');
                  },
                  child: const Icon(Icons.open_in_new, size: 16, color: AppColors.textMuted),
                ),
                const SizedBox(width: 6),
                if (krs.isNotEmpty)
                  RotationTransition(
                    turns: turns,
                    child: const Icon(Icons.chevron_right, size: 18, color: AppColors.textMuted),
                  ),
              ],
            ),
          ),
        ),
        children: (context) => krs.isEmpty
            ? const SizedBox.shrink()
            : Padding(
                padding: const EdgeInsets.only(left: 16, right: 8, bottom: 8),
                child: Column(
                  children: [
                    Container(
                      height: 0.5,
                      margin: const EdgeInsets.only(bottom: 6),
                      color: AppColors.borderDefault,
                    ),
                    ...List.generate(krs.length, (i) => _KrTile(kr: krs[i])),
                  ],
                ),
              ),
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// KR tile (Level 2)
// ---------------------------------------------------------------------------

class _KrTile extends StatelessWidget {
  final Map<String, dynamic> kr;
  const _KrTile({required this.kr});

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
    final desc = kr['descricao'] ?? '';
    final status = _statusLabel(kr['status'] as String?);
    final farol = kr['farol'] as String?;
    final deadline = kr['data_fim'] as String?;
    final responsavelMap = kr['responsavel'] as Map<String, dynamic>?;
    final responsavel = (responsavelMap?['nome'] as String?) ?? '';
    final iniciativas = (kr['iniciativas'] as List?)?.cast<Map<String, dynamic>>() ?? [];

    return Container(
      margin: const EdgeInsets.only(bottom: 6),
      decoration: BoxDecoration(
        color: AppColors.bgCard,
        borderRadius: BorderRadius.circular(10),
        border: Border(left: BorderSide(color: _farolColor(farol), width: 3)),
      ),
      child: _ExpandableTile(
        header: (context, toggle, turns) => InkWell(
          borderRadius: BorderRadius.circular(10),
          onTap: toggle,
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
            child: Row(
              children: [
                Icon(Icons.track_changes, size: 14, color: _farolColor(farol)),
                const SizedBox(width: 6),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        desc,
                        style: const TextStyle(fontWeight: FontWeight.w500, fontSize: 12),
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                      ),
                      const SizedBox(height: 2),
                      Row(
                        children: [
                          _deadlineDot(deadline),
                          const SizedBox(width: 4),
                          Text(status, style: const TextStyle(color: AppColors.textMuted, fontSize: 10)),
                          if (responsavel.isNotEmpty) ...[
                            const SizedBox(width: 6),
                            _personAvatar(responsavelMap, radius: 8),
                            const SizedBox(width: 3),
                            Flexible(
                              child: Text(responsavel,
                                  style: const TextStyle(color: AppColors.textMuted, fontSize: 10),
                                  overflow: TextOverflow.ellipsis),
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
                    context.push('/krs/${kr['id_kr']}');
                  },
                  child: const Icon(Icons.open_in_new, size: 14, color: AppColors.textMuted),
                ),
                const SizedBox(width: 4),
                if (iniciativas.isNotEmpty)
                  RotationTransition(
                    turns: turns,
                    child: const Icon(Icons.chevron_right, size: 16, color: AppColors.textMuted),
                  ),
              ],
            ),
          ),
        ),
        children: (context) => iniciativas.isEmpty
            ? const SizedBox.shrink()
            : Padding(
                padding: const EdgeInsets.only(left: 14, right: 6, bottom: 6),
                child: Column(
                  children: [
                    Container(
                      height: 0.5,
                      margin: const EdgeInsets.only(bottom: 4),
                      color: AppColors.borderDefault,
                    ),
                    ...List.generate(
                      iniciativas.length,
                      (i) => _IniciativaTile(iniciativa: iniciativas[i]),
                    ),
                  ],
                ),
              ),
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
    final responsavelMap = iniciativa['responsavel'] as Map<String, dynamic>?;
    final responsavel = (responsavelMap?['nome'] as String?) ?? '';

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
                        _personAvatar(responsavelMap, radius: 8),
                        const SizedBox(width: 3),
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
