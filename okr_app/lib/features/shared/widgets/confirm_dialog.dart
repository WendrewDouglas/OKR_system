import 'package:flutter/material.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/utils/haptics.dart';

Future<bool> showConfirmDialog(
  BuildContext context, {
  required String title,
  required String message,
  String confirmLabel = 'Confirmar',
  String cancelLabel = 'Cancelar',
  bool isDanger = false,
}) async {
  AppHaptics.medium();
  final accentColor = isDanger ? AppColors.red : AppColors.gold;

  final result = await showGeneralDialog<bool>(
    context: context,
    barrierDismissible: true,
    barrierLabel: 'Dismiss',
    barrierColor: Colors.black54,
    transitionDuration: const Duration(milliseconds: 250),
    transitionBuilder: (context, animation, secondaryAnimation, child) {
      final curved = CurvedAnimation(parent: animation, curve: Curves.easeOutCubic);
      return ScaleTransition(
        scale: Tween<double>(begin: 0.9, end: 1.0).animate(curved),
        child: FadeTransition(opacity: curved, child: child),
      );
    },
    pageBuilder: (ctx, _, __) => AlertDialog(
      backgroundColor: AppColors.bgCard,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(16),
        side: const BorderSide(color: AppColors.borderDefault, width: 0.5),
      ),
      titlePadding: EdgeInsets.zero,
      title: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Gold/red accent line at top
          Container(
            height: 3,
            margin: const EdgeInsets.symmetric(horizontal: 24),
            decoration: BoxDecoration(
              gradient: LinearGradient(
                colors: [accentColor.withValues(alpha: 0.0), accentColor, accentColor.withValues(alpha: 0.0)],
              ),
              borderRadius: BorderRadius.circular(2),
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(24, 20, 24, 0),
            child: Text(title, style: const TextStyle(fontWeight: FontWeight.w700)),
          ),
        ],
      ),
      content: Text(message, style: const TextStyle(color: AppColors.textMuted)),
      actions: [
        TextButton(
          onPressed: () {
            AppHaptics.light();
            Navigator.of(ctx).pop(false);
          },
          child: Text(cancelLabel, style: const TextStyle(color: AppColors.textMuted)),
        ),
        ElevatedButton(
          onPressed: () {
            AppHaptics.medium();
            Navigator.of(ctx).pop(true);
          },
          style: isDanger
              ? ElevatedButton.styleFrom(backgroundColor: AppColors.red, foregroundColor: Colors.white)
              : null,
          child: Text(confirmLabel),
        ),
      ],
    ),
  );
  return result ?? false;
}
