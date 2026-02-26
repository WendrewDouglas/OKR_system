import 'package:flutter/material.dart';
import '../../../core/theme/app_theme.dart';

class FarolIndicator extends StatelessWidget {
  final String farol;
  final double size;

  const FarolIndicator({super.key, required this.farol, this.size = 12});

  Color get color {
    switch (farol.toLowerCase()) {
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
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        color: color,
        shape: BoxShape.circle,
        boxShadow: [BoxShadow(color: color.withValues(alpha: 0.4), blurRadius: 4)],
      ),
    );
  }
}
