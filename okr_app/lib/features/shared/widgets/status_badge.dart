import 'package:flutter/material.dart';
import '../../../core/theme/app_theme.dart';

class StatusBadge extends StatelessWidget {
  final String label;
  final Color? color;

  const StatusBadge({super.key, required this.label, this.color});

  Color get _color {
    if (color != null) return color!;
    switch (label.toLowerCase()) {
      case 'concluído':
      case 'concluido':
      case 'aprovado':
        return AppColors.green;
      case 'em andamento':
      case 'em progresso':
        return AppColors.blue;
      case 'não iniciado':
      case 'nao iniciado':
      case 'pendente':
        return AppColors.textMuted;
      case 'cancelado':
      case 'reprovado':
        return AppColors.red;
      case 'em risco':
        return AppColors.warn;
      default:
        return AppColors.textMuted;
    }
  }

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        color: _color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(6),
        border: Border.all(color: _color.withValues(alpha: 0.3)),
      ),
      child: Text(
        label,
        style: TextStyle(color: _color, fontSize: 11, fontWeight: FontWeight.w600),
      ),
    );
  }
}
