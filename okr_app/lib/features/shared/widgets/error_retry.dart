import 'package:flutter/material.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/utils/haptics.dart';

/// Estado de erro reutilizável com botão "Tentar novamente".
/// Substitui o bloco Icon+Text+TextButton duplicado em ~9 telas.
class ErrorRetry extends StatelessWidget {
  final String message;
  final VoidCallback onRetry;

  const ErrorRetry({
    super.key,
    this.message = 'Erro ao carregar dados',
    required this.onRetry,
  });

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const Icon(Icons.error_outline, color: AppColors.red, size: 48),
          const SizedBox(height: 12),
          Text(
            message,
            textAlign: TextAlign.center,
            style: const TextStyle(color: AppColors.red, fontSize: 16),
          ),
          const SizedBox(height: 8),
          TextButton.icon(
            icon: const Icon(Icons.refresh, size: 18),
            label: const Text('Tentar novamente'),
            onPressed: () {
              AppHaptics.light();
              onRetry();
            },
          ),
        ],
      ),
    );
  }
}
