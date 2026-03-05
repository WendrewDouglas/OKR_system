import 'package:flutter/material.dart';
import '../../../core/theme/app_theme.dart';

/// Custom painter that renders a carbon fiber weave pattern.
class _CarbonFiberPainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    // Base fill
    canvas.drawRect(
      Offset.zero & size,
      Paint()..color = AppColors.bgSoft,
    );

    const cellSize = 6.0;
    const gap = 1.0;
    final step = cellSize + gap;

    // Two-tone weave pattern
    final darkPaint = Paint()..color = const Color(0xFF151920);
    final lightPaint = Paint()..color = const Color(0xFF1A1F27);
    final shinePaint = Paint()..color = const Color(0x08FFFFFF);

    for (double y = 0; y < size.height; y += step * 2) {
      for (double x = 0; x < size.width; x += step * 2) {
        // Top-left cell (dark)
        canvas.drawRRect(
          RRect.fromRectAndRadius(
            Rect.fromLTWH(x, y, cellSize, cellSize),
            const Radius.circular(0.8),
          ),
          darkPaint,
        );
        // Top-right cell (light)
        canvas.drawRRect(
          RRect.fromRectAndRadius(
            Rect.fromLTWH(x + step, y, cellSize, cellSize),
            const Radius.circular(0.8),
          ),
          lightPaint,
        );
        // Bottom-left cell (light)
        canvas.drawRRect(
          RRect.fromRectAndRadius(
            Rect.fromLTWH(x, y + step, cellSize, cellSize),
            const Radius.circular(0.8),
          ),
          lightPaint,
        );
        // Bottom-right cell (dark)
        canvas.drawRRect(
          RRect.fromRectAndRadius(
            Rect.fromLTWH(x + step, y + step, cellSize, cellSize),
            const Radius.circular(0.8),
          ),
          darkPaint,
        );

        // Subtle shine on top-left of each pair
        canvas.drawRRect(
          RRect.fromRectAndRadius(
            Rect.fromLTWH(x, y, cellSize, cellSize * 0.4),
            const Radius.circular(0.8),
          ),
          shinePaint,
        );
        canvas.drawRRect(
          RRect.fromRectAndRadius(
            Rect.fromLTWH(x + step, y + step, cellSize, cellSize * 0.4),
            const Radius.circular(0.8),
          ),
          shinePaint,
        );
      }
    }
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

/// A widget that paints a carbon fiber texture behind its [child].
class CarbonFiberBackground extends StatelessWidget {
  final Widget child;
  const CarbonFiberBackground({super.key, required this.child});

  @override
  Widget build(BuildContext context) {
    return Stack(
      children: [
        Positioned.fill(
          child: RepaintBoundary(
            child: CustomPaint(
              painter: _CarbonFiberPainter(),
              isComplex: true,
              willChange: false,
            ),
          ),
        ),
        child,
      ],
    );
  }
}
