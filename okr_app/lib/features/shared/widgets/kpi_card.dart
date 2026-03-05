import 'package:flutter/material.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/utils/animations.dart';

class KpiCard extends StatelessWidget {
  final String label;
  final String value;
  final IconData icon;
  final Color color;
  final int index;

  const KpiCard({
    super.key,
    required this.label,
    required this.value,
    required this.icon,
    required this.color,
    this.index = 0,
  });

  @override
  Widget build(BuildContext context) {
    final intValue = int.tryParse(value);

    return StaggeredFadeSlide(
      index: index,
      child: Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: AppColors.bgCard,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: AppColors.borderDefault, width: 0.5),
          boxShadow: AppShadows.cardRest,
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Container(
              padding: const EdgeInsets.all(6),
              decoration: BoxDecoration(
                color: color.withValues(alpha: 0.12),
                borderRadius: BorderRadius.circular(8),
                boxShadow: [
                  BoxShadow(color: color.withValues(alpha: 0.15), blurRadius: 8),
                ],
              ),
              child: Icon(icon, color: color, size: 20),
            ),
            const Spacer(),
            intValue != null
                ? TweenAnimationBuilder<int>(
                    tween: IntTween(begin: 0, end: intValue),
                    duration: AppDurations.slow,
                    curve: AppCurves.defaultCurve,
                    builder: (_, val, __) => Text(
                      '$val',
                      style: TextStyle(fontSize: 24, fontWeight: FontWeight.w800, color: color),
                    ),
                  )
                : Text(value, style: TextStyle(fontSize: 24, fontWeight: FontWeight.w800, color: color)),
            const SizedBox(height: 2),
            Text(label, style: const TextStyle(color: AppColors.textMuted, fontSize: 12)),
          ],
        ),
      ),
    );
  }
}
