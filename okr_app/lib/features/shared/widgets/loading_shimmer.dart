import 'package:flutter/material.dart';
import 'package:shimmer/shimmer.dart';
import '../../../core/theme/app_theme.dart';

class LoadingShimmer extends StatelessWidget {
  final int count;
  const LoadingShimmer({super.key, this.count = 5});

  static const _heights = [80.0, 60.0, 80.0, 100.0, 60.0];

  @override
  Widget build(BuildContext context) {
    return Shimmer.fromColors(
      baseColor: AppColors.bgCard,
      highlightColor: const Color(0xFF2A2520),
      child: ListView.builder(
        padding: const EdgeInsets.all(16),
        itemCount: count,
        itemBuilder: (_, i) {
          final h = _heights[i % _heights.length];
          return Container(
            height: h,
            margin: const EdgeInsets.only(bottom: 12),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(16),
            ),
            child: Padding(
              padding: const EdgeInsets.all(14),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Container(
                    width: 120,
                    height: 10,
                    decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(4)),
                  ),
                  const SizedBox(height: 10),
                  Container(
                    width: double.infinity,
                    height: 8,
                    decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(4)),
                  ),
                  if (h > 70) ...[
                    const SizedBox(height: 8),
                    Container(
                      width: 200,
                      height: 8,
                      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(4)),
                    ),
                  ],
                ],
              ),
            ),
          );
        },
      ),
    );
  }
}
