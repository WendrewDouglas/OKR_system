import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../core/network/api_client.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/haptics.dart';
import '../../core/utils/animations.dart';
import '../shared/widgets/loading_shimmer.dart';
import '../shared/widgets/empty_state.dart';
import '../shared/widgets/app_header.dart';

// ── Data model ──────────────────────────────────────────────────────────────

class _PersonItem {
  final String type; // 'objetivo', 'kr', 'iniciativa'
  final String descricao;
  final String status;
  final String? pilarBsc;

  const _PersonItem({
    required this.type,
    required this.descricao,
    required this.status,
    this.pilarBsc,
  });
}

class _PersonSummary {
  final int idUser;
  final String nome;
  final int objetivosCount;
  final int krsCount;
  final int iniciativasCount;
  final double progressPercent;
  final List<_PersonItem> items;

  const _PersonSummary({
    required this.idUser,
    required this.nome,
    required this.objetivosCount,
    required this.krsCount,
    required this.iniciativasCount,
    required this.progressPercent,
    required this.items,
  });

  int get totalItems => objetivosCount + krsCount + iniciativasCount;
}

// ── Provider ────────────────────────────────────────────────────────────────

final responsaveisProvider = FutureProvider.autoDispose<List<_PersonSummary>>((ref) async {
  final api = ref.read(apiClientProvider);
  final res = await api.dio.get('/dashboard/cascata');
  final objetivos = ((res.data['objetivos'] as List?) ?? []).cast<Map<String, dynamic>>();

  // Group by person (id_user → collected data)
  final Map<int, _PersonAccumulator> people = {};

  for (final obj in objetivos) {
    final dono = obj['dono'] as Map<String, dynamic>?;
    if (dono != null) {
      final id = dono['id_user'] as int;
      final nome = (dono['nome'] as String?) ?? '';
      people.putIfAbsent(id, () => _PersonAccumulator(id: id, nome: nome));
      people[id]!.objetivos.add(_PersonItem(
        type: 'objetivo',
        descricao: obj['descricao'] ?? '',
        status: obj['status'] ?? '',
        pilarBsc: obj['pilar_bsc'],
      ));
    }

    final krs = ((obj['key_results'] as List?) ?? []).cast<Map<String, dynamic>>();
    for (final kr in krs) {
      final resp = kr['responsavel'] as Map<String, dynamic>?;
      if (resp != null) {
        final id = resp['id_user'] as int;
        final nome = (resp['nome'] as String?) ?? '';
        people.putIfAbsent(id, () => _PersonAccumulator(id: id, nome: nome));
        people[id]!.krs.add(_PersonItem(
          type: 'kr',
          descricao: kr['descricao'] ?? '',
          status: kr['status'] ?? '',
        ));
      }

      final inis = ((kr['iniciativas'] as List?) ?? []).cast<Map<String, dynamic>>();
      for (final ini in inis) {
        final resp = ini['responsavel'] as Map<String, dynamic>?;
        if (resp != null) {
          final id = resp['id_user'] as int;
          final nome = (resp['nome'] as String?) ?? '';
          people.putIfAbsent(id, () => _PersonAccumulator(id: id, nome: nome));
          people[id]!.iniciativas.add(_PersonItem(
            type: 'iniciativa',
            descricao: ini['descricao'] ?? '',
            status: ini['status'] ?? '',
          ));
        }
      }
    }
  }

  // Convert to summaries, sorted by total items descending
  final summaries = people.values.map((p) {
    final allItems = [...p.objetivos, ...p.krs, ...p.iniciativas];
    final doneCount = allItems.where((i) => _isDone(i.status)).length;
    final progress = allItems.isNotEmpty ? (doneCount / allItems.length * 100) : 0.0;

    return _PersonSummary(
      idUser: p.id,
      nome: p.nome,
      objetivosCount: p.objetivos.length,
      krsCount: p.krs.length,
      iniciativasCount: p.iniciativas.length,
      progressPercent: progress,
      items: allItems,
    );
  }).toList();

  summaries.sort((a, b) => b.totalItems.compareTo(a.totalItems));
  return summaries;
});

class _PersonAccumulator {
  final int id;
  final String nome;
  final List<_PersonItem> objetivos = [];
  final List<_PersonItem> krs = [];
  final List<_PersonItem> iniciativas = [];

  _PersonAccumulator({required this.id, required this.nome});
}

bool _isDone(String status) {
  final s = status.toLowerCase();
  return s.contains('conclu') || s.contains('done') || s.contains('finaliz');
}

// ── Screen ──────────────────────────────────────────────────────────────────

class ResponsaveisScreen extends ConsumerWidget {
  const ResponsaveisScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final data = ref.watch(responsaveisProvider);

    return Scaffold(
      backgroundColor: Colors.transparent,
      appBar: const AppHeader(),
      body: data.when(
        loading: () => const LoadingShimmer(),
        error: (e, _) => Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.error_outline, color: AppColors.red, size: 48),
              const SizedBox(height: 12),
              const Text(
                'Erro ao carregar responsaveis',
                style: TextStyle(color: AppColors.red, fontSize: 16),
              ),
              const SizedBox(height: 8),
              TextButton.icon(
                icon: const Icon(Icons.refresh, size: 18),
                label: const Text('Tentar novamente'),
                onPressed: () {
                  AppHaptics.light();
                  ref.invalidate(responsaveisProvider);
                },
              ),
            ],
          ),
        ),
        data: (people) => RefreshIndicator(
          color: AppColors.gold,
          backgroundColor: AppColors.bgCard,
          onRefresh: () async {
            AppHaptics.medium();
            ref.invalidate(responsaveisProvider);
          },
          child: people.isEmpty
              ? const EmptyState(
                  icon: Icons.people_outline,
                  title: 'Nenhum responsavel encontrado',
                  subtitle: 'Atribua responsaveis aos seus OKRs e iniciativas.',
                )
              : ListView.builder(
                  padding: const EdgeInsets.all(16),
                  itemCount: people.length + 1, // +1 for header
                  itemBuilder: (ctx, i) {
                    if (i == 0) {
                      return StaggeredFadeSlide(
                        index: 0,
                        child: Padding(
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
                                'Responsaveis',
                                style: Theme.of(context).textTheme.titleMedium?.copyWith(
                                  fontWeight: FontWeight.w700,
                                ),
                              ),
                              const Spacer(),
                              Container(
                                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                                decoration: BoxDecoration(
                                  color: AppColors.gold.withValues(alpha: 0.15),
                                  borderRadius: BorderRadius.circular(12),
                                ),
                                child: Text(
                                  '${people.length} pessoas',
                                  style: const TextStyle(
                                    color: AppColors.gold,
                                    fontSize: 12,
                                    fontWeight: FontWeight.w600,
                                  ),
                                ),
                              ),
                            ],
                          ),
                        ),
                      );
                    }
                    return StaggeredFadeSlide(
                      index: i,
                      child: _PersonCard(person: people[i - 1]),
                    );
                  },
                ),
        ),
      ),
    );
  }
}

// ── Person card ─────────────────────────────────────────────────────────────

class _PersonCard extends StatefulWidget {
  final _PersonSummary person;
  const _PersonCard({required this.person});

  @override
  State<_PersonCard> createState() => _PersonCardState();
}

class _PersonCardState extends State<_PersonCard>
    with SingleTickerProviderStateMixin {
  bool _expanded = false;
  late final AnimationController _expandCtrl;
  late final Animation<double> _expandAnim;

  @override
  void initState() {
    super.initState();
    _expandCtrl = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 300),
    );
    _expandAnim = CurvedAnimation(
      parent: _expandCtrl,
      curve: Curves.easeOutCubic,
    );
  }

  @override
  void dispose() {
    _expandCtrl.dispose();
    super.dispose();
  }

  void _toggle() {
    AppHaptics.selection();
    setState(() {
      _expanded = !_expanded;
      if (_expanded) {
        _expandCtrl.forward();
      } else {
        _expandCtrl.reverse();
      }
    });
  }

  Color _progressColor(double pct) {
    if (pct >= 70) return AppColors.green;
    if (pct >= 40) return AppColors.warn;
    return AppColors.red;
  }

  String _initials(String name) {
    final parts = name.trim().split(RegExp(r'\s+'));
    if (parts.isEmpty || parts.first.isEmpty) return '?';
    if (parts.length == 1) return parts.first[0].toUpperCase();
    return '${parts.first[0]}${parts.last[0]}'.toUpperCase();
  }

  Color _avatarColor(int id) {
    const palette = [
      Color(0xFF60A5FA), // blue
      Color(0xFFA78BFA), // purple
      Color(0xFF22C55E), // green
      Color(0xFFF59E0B), // amber
      Color(0xFFEF4444), // red
      Color(0xFF06B6D4), // cyan
      Color(0xFFF472B6), // pink
      Color(0xFFFBBF24), // yellow
    ];
    return palette[id % palette.length];
  }

  @override
  Widget build(BuildContext context) {
    final p = widget.person;
    final pct = p.progressPercent;
    final color = _avatarColor(p.idUser);

    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: InkWell(
        borderRadius: BorderRadius.circular(16),
        onTap: _toggle,
        child: Column(
          children: [
            // Main row
            Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                children: [
                  Row(
                    children: [
                      // Avatar circle
                      Container(
                        width: 44,
                        height: 44,
                        decoration: BoxDecoration(
                          shape: BoxShape.circle,
                          color: color.withValues(alpha: 0.15),
                          border: Border.all(color: color.withValues(alpha: 0.4), width: 1.5),
                        ),
                        alignment: Alignment.center,
                        child: Text(
                          _initials(p.nome),
                          style: TextStyle(
                            color: color,
                            fontWeight: FontWeight.w700,
                            fontSize: 16,
                          ),
                        ),
                      ),
                      const SizedBox(width: 12),
                      // Name and counts
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              p.nome,
                              style: const TextStyle(
                                fontWeight: FontWeight.w600,
                                fontSize: 14,
                              ),
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                            ),
                            const SizedBox(height: 6),
                            Wrap(
                              spacing: 6,
                              runSpacing: 4,
                              children: [
                                if (p.objetivosCount > 0)
                                  _CountBadge(
                                    count: p.objetivosCount,
                                    label: 'OBJ',
                                    color: AppColors.gold,
                                  ),
                                if (p.krsCount > 0)
                                  _CountBadge(
                                    count: p.krsCount,
                                    label: 'KR',
                                    color: AppColors.blue,
                                  ),
                                if (p.iniciativasCount > 0)
                                  _CountBadge(
                                    count: p.iniciativasCount,
                                    label: 'INI',
                                    color: AppColors.pilarAprendizado,
                                  ),
                              ],
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(width: 8),
                      // Progress circle
                      SizedBox(
                        width: 44,
                        height: 44,
                        child: Stack(
                          alignment: Alignment.center,
                          children: [
                            SizedBox(
                              width: 44,
                              height: 44,
                              child: CircularProgressIndicator(
                                value: pct / 100,
                                strokeWidth: 3.5,
                                backgroundColor: AppColors.borderDefault,
                                valueColor: AlwaysStoppedAnimation(_progressColor(pct)),
                              ),
                            ),
                            Text(
                              '${pct.round()}%',
                              style: TextStyle(
                                fontSize: 11,
                                fontWeight: FontWeight.w700,
                                color: _progressColor(pct),
                              ),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                  // Expand indicator
                  const SizedBox(height: 4),
                  AnimatedRotation(
                    turns: _expanded ? 0.5 : 0,
                    duration: const Duration(milliseconds: 300),
                    child: const Icon(
                      Icons.keyboard_arrow_down,
                      size: 20,
                      color: AppColors.textMuted,
                    ),
                  ),
                ],
              ),
            ),
            // Expandable details
            SizeTransition(
              sizeFactor: _expandAnim,
              child: _ExpandedDetails(person: p),
            ),
          ],
        ),
      ),
    );
  }
}

// ── Count badge ─────────────────────────────────────────────────────────────

class _CountBadge extends StatelessWidget {
  final int count;
  final String label;
  final Color color;

  const _CountBadge({
    required this.count,
    required this.label,
    required this.color,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Text(
        '$count $label',
        style: TextStyle(
          color: color,
          fontSize: 11,
          fontWeight: FontWeight.w600,
        ),
      ),
    );
  }
}

// ── Expanded details ────────────────────────────────────────────────────────

class _ExpandedDetails extends StatelessWidget {
  final _PersonSummary person;
  const _ExpandedDetails({required this.person});

  @override
  Widget build(BuildContext context) {
    final objs = person.items.where((i) => i.type == 'objetivo').toList();
    final krs = person.items.where((i) => i.type == 'kr').toList();
    final inis = person.items.where((i) => i.type == 'iniciativa').toList();

    return Container(
      decoration: const BoxDecoration(
        border: Border(top: BorderSide(color: AppColors.borderDefault, width: 0.5)),
      ),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (objs.isNotEmpty) ...[
              _SectionTitle(label: 'Objetivos', color: AppColors.gold, count: objs.length),
              const SizedBox(height: 6),
              ...objs.map((item) => _ItemTile(item: item, accent: AppColors.gold)),
              const SizedBox(height: 12),
            ],
            if (krs.isNotEmpty) ...[
              _SectionTitle(label: 'Key Results', color: AppColors.blue, count: krs.length),
              const SizedBox(height: 6),
              ...krs.map((item) => _ItemTile(item: item, accent: AppColors.blue)),
              const SizedBox(height: 12),
            ],
            if (inis.isNotEmpty) ...[
              _SectionTitle(label: 'Iniciativas', color: AppColors.pilarAprendizado, count: inis.length),
              const SizedBox(height: 6),
              ...inis.map((item) => _ItemTile(item: item, accent: AppColors.pilarAprendizado)),
            ],
          ],
        ),
      ),
    );
  }
}

// ── Section title ───────────────────────────────────────────────────────────

class _SectionTitle extends StatelessWidget {
  final String label;
  final Color color;
  final int count;

  const _SectionTitle({
    required this.label,
    required this.color,
    required this.count,
  });

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Container(
          width: 3,
          height: 14,
          decoration: BoxDecoration(
            color: color,
            borderRadius: BorderRadius.circular(2),
          ),
        ),
        const SizedBox(width: 6),
        Text(
          label,
          style: TextStyle(
            fontSize: 12,
            fontWeight: FontWeight.w700,
            color: color,
          ),
        ),
        const SizedBox(width: 6),
        Text(
          '($count)',
          style: TextStyle(
            fontSize: 11,
            color: color.withValues(alpha: 0.6),
          ),
        ),
      ],
    );
  }
}

// ── Item tile ───────────────────────────────────────────────────────────────

class _ItemTile extends StatelessWidget {
  final _PersonItem item;
  final Color accent;

  const _ItemTile({required this.item, required this.accent});

  Color _statusColor(String status) {
    final s = status.toLowerCase();
    if (s.contains('conclu') || s.contains('done') || s.contains('finaliz')) {
      return AppColors.green;
    }
    if (s.contains('andamento') || s.contains('progress')) return AppColors.blue;
    if (s.contains('risco') || s.contains('atras')) return AppColors.red;
    if (s.contains('paus') || s.contains('cancel')) return AppColors.textMuted;
    return AppColors.warn;
  }

  IconData _typeIcon(String type) {
    switch (type) {
      case 'objetivo':
        return Icons.flag_rounded;
      case 'kr':
        return Icons.track_changes;
      case 'iniciativa':
        return Icons.rocket_launch_outlined;
      default:
        return Icons.circle;
    }
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 6),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsets.only(top: 3),
            child: Icon(_typeIcon(item.type), size: 14, color: accent.withValues(alpha: 0.6)),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              item.descricao,
              style: const TextStyle(fontSize: 12, color: AppColors.text),
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
            ),
          ),
          const SizedBox(width: 8),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
            decoration: BoxDecoration(
              color: _statusColor(item.status).withValues(alpha: 0.12),
              borderRadius: BorderRadius.circular(6),
            ),
            child: Text(
              item.status,
              style: TextStyle(
                fontSize: 10,
                fontWeight: FontWeight.w600,
                color: _statusColor(item.status),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
