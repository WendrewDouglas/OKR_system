import 'package:flutter/material.dart';
import '../../../core/theme/app_theme.dart';

class FarolIndicator extends StatefulWidget {
  final String farol;
  final double size;

  const FarolIndicator({super.key, required this.farol, this.size = 12});

  @override
  State<FarolIndicator> createState() => _FarolIndicatorState();
}

class _FarolIndicatorState extends State<FarolIndicator>
    with SingleTickerProviderStateMixin {
  AnimationController? _pulseCtrl;
  Animation<double>? _pulseAnim;

  Color get color {
    switch (widget.farol.toLowerCase()) {
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

  bool get _isRed => widget.farol.toLowerCase() == 'vermelho';

  @override
  void initState() {
    super.initState();
    if (_isRed) {
      _pulseCtrl = AnimationController(
        vsync: this,
        duration: const Duration(milliseconds: 1500),
      )..repeat(reverse: true);
      _pulseAnim = Tween<double>(begin: 0.3, end: 0.7).animate(
        CurvedAnimation(parent: _pulseCtrl!, curve: Curves.easeInOut),
      );
    }
  }

  @override
  void dispose() {
    _pulseCtrl?.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final c = color;
    if (_isRed && _pulseAnim != null) {
      return AnimatedBuilder(
        animation: _pulseAnim!,
        builder: (_, __) => Container(
          width: widget.size,
          height: widget.size,
          decoration: BoxDecoration(
            color: c,
            shape: BoxShape.circle,
            boxShadow: [
              BoxShadow(color: c.withValues(alpha: _pulseAnim!.value), blurRadius: 6, spreadRadius: 1),
            ],
          ),
        ),
      );
    }
    return Container(
      width: widget.size,
      height: widget.size,
      decoration: BoxDecoration(
        color: c,
        shape: BoxShape.circle,
        boxShadow: [BoxShadow(color: c.withValues(alpha: 0.4), blurRadius: 4)],
      ),
    );
  }
}
