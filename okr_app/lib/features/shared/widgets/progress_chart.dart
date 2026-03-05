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

    for (int i = 0; i < labels.length; i++) {
      if (i < esperado.length && esperado[i] != null) {
        esperadoSpots.add(FlSpot(i.toDouble(), esperado[i]!));
      }
      if (i < real.length && real[i] != null) {
        realSpots.add(FlSpot(i.toDouble(), real[i]!));
      }
    }

    return SizedBox(
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
          lineBarsData: [
            // Expected line (dashed)
            if (esperadoSpots.isNotEmpty)
              LineChartBarData(
                spots: esperadoSpots,
                isCurved: true,
                curveSmoothness: 0.2,
                color: AppColors.textMuted,
                barWidth: 2,
                dotData: const FlDotData(show: false),
                dashArray: [6, 4],
              ),
            // Actual line (solid) with gradient fill
            if (realSpots.isNotEmpty)
              LineChartBarData(
                spots: realSpots,
                isCurved: true,
                curveSmoothness: 0.2,
                color: AppColors.gold,
                barWidth: 3,
                dotData: FlDotData(
                  show: true,
                  getDotPainter: (spot, percent, bar, index) {
                    final isLast = index == realSpots.length - 1;
                    return FlDotCirclePainter(
                      radius: isLast ? 4 : 3,
                      color: AppColors.gold,
                      strokeWidth: isLast ? 2 : 1.5,
                      strokeColor: isLast ? AppColors.goldLight : AppColors.bgCard,
                    );
                  },
                ),
                belowBarData: BarAreaData(
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
              ),
          ],
          lineTouchData: LineTouchData(
            touchTooltipData: LineTouchTooltipData(
              getTooltipColor: (_) => AppColors.bgElevated,
              tooltipBorder: const BorderSide(color: AppColors.borderDefault, width: 0.5),
              getTooltipItems: (spots) => spots.map((s) {
                final isEsperado = s.barIndex == 0 && esperadoSpots.isNotEmpty;
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
    );
  }
}
