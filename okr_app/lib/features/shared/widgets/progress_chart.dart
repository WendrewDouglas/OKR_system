import 'package:flutter/material.dart';
import 'package:fl_chart/fl_chart.dart';
import '../../../core/theme/app_theme.dart';

class ProgressChart extends StatelessWidget {
  final List<String> labels;
  final List<double?> esperado;
  final List<double?> real;
  final List<double?> min;
  final List<double?> max;
  final String? unidade;

  const ProgressChart({
    super.key,
    required this.labels,
    required this.esperado,
    required this.real,
    this.min = const [],
    this.max = const [],
    this.unidade,
  });

  bool get _hasBand =>
      min.any((e) => e != null) && max.any((e) => e != null);

  @override
  Widget build(BuildContext context) {
    if (labels.isEmpty) {
      return const SizedBox(
        height: 200,
        child: Center(child: Text('Sem dados de milestones', style: TextStyle(color: AppColors.textMuted))),
      );
    }

    final esperadoSpots = <FlSpot>[];
    final realSpots = <FlSpot>[];
    final minSpots = <FlSpot>[];
    final maxSpots = <FlSpot>[];

    for (int i = 0; i < labels.length; i++) {
      if (i < esperado.length && esperado[i] != null) esperadoSpots.add(FlSpot(i.toDouble(), esperado[i]!));
      if (i < real.length && real[i] != null) realSpots.add(FlSpot(i.toDouble(), real[i]!));
      if (i < min.length && min[i] != null) minSpots.add(FlSpot(i.toDouble(), min[i]!));
      if (i < max.length && max[i] != null) maxSpots.add(FlSpot(i.toDouble(), max[i]!));
    }

    // Modo "faixa ideal" (INTERVALO_IDEAL): mostra limite mín/máx + área sombreada.
    final band = _hasBand && minSpots.isNotEmpty && maxSpots.isNotEmpty;

    bool insideBand(double x, double y) {
      final i = x.toInt();
      final lo = (i >= 0 && i < min.length) ? min[i] : null;
      final hi = (i >= 0 && i < max.length) ? max[i] : null;
      return lo != null && hi != null && y >= lo && y <= hi;
    }

    // Linha do realizado (apontamentos) — pontos coloridos por dentro/fora no modo faixa.
    final realBar = LineChartBarData(
      spots: realSpots,
      isCurved: !band,
      curveSmoothness: 0.2,
      color: AppColors.gold,
      barWidth: 3,
      dotData: FlDotData(
        show: true,
        getDotPainter: (spot, percent, bar, index) {
          if (band) {
            final inside = insideBand(spot.x, spot.y);
            return FlDotCirclePainter(
              radius: 4,
              color: inside ? AppColors.green : AppColors.red,
              strokeWidth: 1.5,
              strokeColor: AppColors.bgCard,
            );
          }
          final isLast = index == realSpots.length - 1;
          return FlDotCirclePainter(
            radius: isLast ? 4 : 3,
            color: AppColors.gold,
            strokeWidth: isLast ? 2 : 1.5,
            strokeColor: isLast ? AppColors.goldLight : AppColors.bgCard,
          );
        },
      ),
      belowBarData: band
          ? BarAreaData(show: false)
          : BarAreaData(
              show: true,
              gradient: LinearGradient(
                begin: Alignment.topCenter,
                end: Alignment.bottomCenter,
                colors: [
                  AppColors.gold.withValues(alpha: 0.15),
                  AppColors.gold.withValues(alpha: 0.0),
                ],
              ),
            ),
    );

    // Monta as barras + a área entre min/max (betweenBarsData).
    final bars = <LineChartBarData>[];
    final between = <BetweenBarsData>[];
    int realIndex;

    if (band) {
      final maxBar = LineChartBarData(
        spots: maxSpots,
        isCurved: false,
        color: AppColors.green.withValues(alpha: 0.5),
        barWidth: 1.5,
        dotData: const FlDotData(show: false),
      );
      final minBar = LineChartBarData(
        spots: minSpots,
        isCurved: false,
        color: AppColors.green.withValues(alpha: 0.5),
        barWidth: 1.5,
        dotData: const FlDotData(show: false),
      );
      bars.add(maxBar); // 0
      bars.add(minBar); // 1
      bars.add(realBar); // 2
      realIndex = 2;
      between.add(BetweenBarsData(
        fromIndex: 0,
        toIndex: 1,
        color: AppColors.green.withValues(alpha: 0.10),
      ));
    } else {
      if (esperadoSpots.isNotEmpty) {
        bars.add(LineChartBarData(
          spots: esperadoSpots,
          isCurved: true,
          curveSmoothness: 0.2,
          color: AppColors.textMuted,
          barWidth: 2,
          dotData: const FlDotData(show: false),
          dashArray: [6, 4],
        ));
      }
      bars.add(realBar);
      realIndex = bars.length - 1;
    }

    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        SizedBox(
          height: 220,
          child: LineChart(
            LineChartData(
              gridData: FlGridData(
                show: true,
                drawVerticalLine: false,
                getDrawingHorizontalLine: (_) => FlLine(color: AppColors.borderDefault, strokeWidth: 0.5),
              ),
              titlesData: FlTitlesData(
                topTitles: const AxisTitles(sideTitles: SideTitles(showTitles: false)),
                rightTitles: const AxisTitles(sideTitles: SideTitles(showTitles: false)),
                bottomTitles: AxisTitles(
                  sideTitles: SideTitles(
                    showTitles: true,
                    reservedSize: 30,
                    interval: labels.length > 6 ? (labels.length / 4).ceilToDouble() : 1,
                    getTitlesWidget: (value, meta) {
                      final idx = value.toInt();
                      if (idx < 0 || idx >= labels.length) return const SizedBox.shrink();
                      final parts = labels[idx].split('-');
                      final label = parts.length >= 2 ? '${parts[1]}/${parts[0].substring(2)}' : labels[idx];
                      return Padding(
                        padding: const EdgeInsets.only(top: 6),
                        child: Text(label, style: const TextStyle(fontSize: 9, color: AppColors.textMuted)),
                      );
                    },
                  ),
                ),
                leftTitles: AxisTitles(
                  sideTitles: SideTitles(
                    showTitles: true,
                    reservedSize: 44,
                    getTitlesWidget: (value, meta) {
                      return Text(
                        value.toStringAsFixed(value == value.roundToDouble() ? 0 : 1),
                        style: const TextStyle(fontSize: 10, color: AppColors.textMuted),
                      );
                    },
                  ),
                ),
              ),
              borderData: FlBorderData(show: false),
              lineBarsData: bars,
              betweenBarsData: between,
              lineTouchData: LineTouchData(
                touchTooltipData: LineTouchTooltipData(
                  getTooltipColor: (_) => AppColors.bgElevated,
                  tooltipBorder: const BorderSide(color: AppColors.borderDefault, width: 0.5),
                  getTooltipItems: (spots) => spots.map((s) {
                    if (band) {
                      // Só o realizado tem tooltip no modo faixa.
                      if (s.barIndex != realIndex) return null;
                      final inside = insideBand(s.x, s.y);
                      return LineTooltipItem(
                        'Real: ${s.y.toStringAsFixed(1)}${unidade != null ? " $unidade" : ""} (${inside ? "dentro" : "fora"})',
                        TextStyle(
                          color: inside ? AppColors.green : AppColors.red,
                          fontSize: 12,
                          fontWeight: FontWeight.w600,
                        ),
                      );
                    }
                    final isEsperado = esperadoSpots.isNotEmpty && s.barIndex == 0;
                    return LineTooltipItem(
                      '${isEsperado ? "Meta" : "Real"}: ${s.y.toStringAsFixed(1)}${unidade != null ? " $unidade" : ""}',
                      TextStyle(
                        color: isEsperado ? AppColors.textMuted : AppColors.gold,
                        fontSize: 12,
                        fontWeight: FontWeight.w600,
                      ),
                    );
                  }).toList(),
                ),
              ),
            ),
          ),
        ),
        const SizedBox(height: 8),
        _Legend(band: band),
      ],
    );
  }
}

class _Legend extends StatelessWidget {
  final bool band;
  const _Legend({required this.band});

  @override
  Widget build(BuildContext context) {
    final items = band
        ? const [
            _LegendItem(color: AppColors.green, label: 'Faixa ideal', swatch: _Swatch.area),
            _LegendItem(color: AppColors.green, label: 'Dentro', swatch: _Swatch.dot),
            _LegendItem(color: AppColors.red, label: 'Fora', swatch: _Swatch.dot),
            _LegendItem(color: AppColors.gold, label: 'Realizado', swatch: _Swatch.line),
          ]
        : const [
            _LegendItem(color: AppColors.textMuted, label: 'Esperado', swatch: _Swatch.dash),
            _LegendItem(color: AppColors.gold, label: 'Realizado', swatch: _Swatch.line),
          ];
    return Wrap(
      alignment: WrapAlignment.center,
      spacing: 14,
      runSpacing: 4,
      children: items,
    );
  }
}

enum _Swatch { area, dot, line, dash }

class _LegendItem extends StatelessWidget {
  final Color color;
  final String label;
  final _Swatch swatch;
  const _LegendItem({required this.color, required this.label, required this.swatch});

  @override
  Widget build(BuildContext context) {
    Widget mark;
    switch (swatch) {
      case _Swatch.area:
        mark = Container(
          width: 14,
          height: 10,
          decoration: BoxDecoration(
            color: color.withValues(alpha: 0.15),
            border: Border.all(color: color.withValues(alpha: 0.5)),
            borderRadius: BorderRadius.circular(2),
          ),
        );
        break;
      case _Swatch.dot:
        mark = Container(
          width: 9,
          height: 9,
          decoration: BoxDecoration(shape: BoxShape.circle, color: color),
        );
        break;
      case _Swatch.line:
        mark = Container(width: 14, height: 3, color: color);
        break;
      case _Swatch.dash:
        mark = Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(width: 5, height: 2, color: color),
            const SizedBox(width: 2),
            Container(width: 5, height: 2, color: color),
          ],
        );
        break;
    }
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        mark,
        const SizedBox(width: 5),
        Text(label, style: const TextStyle(fontSize: 10, color: AppColors.textMuted)),
      ],
    );
  }
}
