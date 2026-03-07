import 'package:flutter/material.dart';
import '../../../core/constants/api_constants.dart';
import '../../../core/theme/app_theme.dart';

/// Color used for initials fallback background (matches doc: #2E4DB7).
const _avatarBlue = Color(0xFF2E4DB7);

/// Reusable avatar widget with image + initials fallback.
///
/// If [avatarUrl] is provided, loads the image from the API.
/// On error or if null, falls back to a colored circle with initials.
class UserAvatar extends StatelessWidget {
  final String? avatarUrl;
  final String firstName;
  final String lastName;
  final double radius;
  final bool showGoldRing;

  const UserAvatar({
    super.key,
    this.avatarUrl,
    required this.firstName,
    required this.lastName,
    this.radius = 20,
    this.showGoldRing = false,
  });

  String get _initials {
    final f = firstName.trim();
    final l = lastName.trim();
    if (f.isEmpty && l.isEmpty) return 'X';
    if (l.isEmpty) return f[0].toUpperCase();
    return '${f[0]}${l[0]}'.toUpperCase();
  }

  @override
  Widget build(BuildContext context) {
    final avatar = _buildAvatar();
    if (!showGoldRing) return avatar;

    return Container(
      padding: const EdgeInsets.all(2.5),
      decoration: const BoxDecoration(
        shape: BoxShape.circle,
        gradient: AppColors.goldGradient,
      ),
      child: avatar,
    );
  }

  Widget _buildAvatar() {
    if (avatarUrl != null && avatarUrl!.isNotEmpty) {
      final fullUrl = _resolveUrl(avatarUrl!);
      return CircleAvatar(
        radius: radius,
        backgroundColor: _avatarBlue,
        backgroundImage: NetworkImage(fullUrl),
        onBackgroundImageError: (_, __) {},
        child: null,
      );
    }
    return _initialsAvatar();
  }

  Widget _initialsAvatar() {
    return CircleAvatar(
      radius: radius,
      backgroundColor: _avatarBlue,
      child: Text(
        _initials,
        style: TextStyle(
          fontSize: radius * 0.75,
          fontWeight: FontWeight.w600,
          color: Colors.white,
        ),
      ),
    );
  }

  String _resolveUrl(String url) {
    if (url.startsWith('http')) return url;
    // Relative URL → prepend API base (strip the /api/api_platform/v1 part)
    const base = ApiConstants.baseUrl;
    final siteBase = base.replaceAll('/api/api_platform/v1', '');
    return '$siteBase$url';
  }
}
