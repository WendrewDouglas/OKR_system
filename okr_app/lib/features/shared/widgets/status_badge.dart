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
    final c = _color;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: [c.withValues(alpha: 0.08), c.withValues(alpha: 0.16)],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.circular(6),
        border: Border.all(color: c.withValues(alpha: 0.3)),
        boxShadow: [
          BoxShadow(color: c.withValues(alpha: 0.15), blurRadius: 6),
        ],
      ),
      child: Text(
        label,
        style: TextStyle(color: c, fontSize: 11, fontWeight: FontWeight.w600),
      ),
    );
  }
}
