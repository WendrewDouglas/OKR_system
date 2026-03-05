import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

class AppColors {
  // Carbon fiber depth layers
  static const bgDeep = Color(0xFF0D1117);
  static const bgSoft = Color(0xFF171B21);
  static const bgCard = Color(0xFF1C2128);
  static const bgSurface = Color(0xFF21262D);
  static const bgElevated = Color(0xFF2D333B);

  // Borders
  static const borderSubtle = Color(0xFF1C2128);
  static const borderDefault = Color(0xFF30363D);
  static const borderMuted = Color(0xFF484F58);
  static const border = Color(0xFF222733); // legacy alias

  // Text
  static const text = Color(0xFFEAEEF6);
  static const textMuted = Color(0xFFA6ADBB);

  // Gold palette
  static const gold = Color(0xFFF1C40F);
  static const goldLight = Color(0xFFF5D142);
  static const goldDark = Color(0xFFD4A80C);
  static const goldSubtle = Color(0xFF3D2E06);

  // Semantic
  static const green = Color(0xFF22C55E);
  static const blue = Color(0xFF60A5FA);
  static const red = Color(0xFFEF4444);
  static const accent = Color(0xFF0C4A6E);
  static const warn = Color(0xFFF59E0B);

  // Pillar colors (BSC)
  static const pilarFinanceiro = Color(0xFFF59E0B);
  static const pilarCliente = Color(0xFF22C55E);
  static const pilarProcessos = Color(0xFF60A5FA);
  static const pilarAprendizado = Color(0xFFA78BFA);

  // Gradients
  static const goldGradient = LinearGradient(
    colors: [goldLight, gold, goldDark],
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
  );

  static const goldShimmer = LinearGradient(
    colors: [goldDark, gold, goldLight, gold],
    stops: [0.0, 0.35, 0.65, 1.0],
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
  );
}

class AppShadows {
  static const cardRest = [
    BoxShadow(color: Color(0x33000000), blurRadius: 8, offset: Offset(0, 2)),
  ];
  static const cardHover = [
    BoxShadow(color: Color(0x55000000), blurRadius: 16, offset: Offset(0, 6)),
  ];
  static final goldGlow = [
    BoxShadow(color: AppColors.gold.withValues(alpha: 0.25), blurRadius: 12, spreadRadius: 1),
  ];
}

class AppSpacing {
  static const double xs = 4;
  static const double sm = 8;
  static const double md = 12;
  static const double lg = 16;
  static const double xl = 24;
  static const double xxl = 32;
}

class AppTheme {
  static ThemeData get dark {
    return ThemeData(
      brightness: Brightness.dark,
      scaffoldBackgroundColor: AppColors.bgSoft,
      colorScheme: const ColorScheme.dark(
        primary: AppColors.gold,
        secondary: AppColors.blue,
        surface: AppColors.bgCard,
        error: AppColors.red,
        onPrimary: AppColors.bgDeep,
        onSecondary: AppColors.text,
        onSurface: AppColors.text,
        onError: AppColors.text,
      ),
      textTheme: GoogleFonts.interTextTheme(ThemeData.dark().textTheme).copyWith(
        headlineLarge: GoogleFonts.inter(fontWeight: FontWeight.w800, color: AppColors.text),
        headlineMedium: GoogleFonts.inter(fontWeight: FontWeight.w800, color: AppColors.text),
        headlineSmall: GoogleFonts.inter(fontWeight: FontWeight.w700, color: AppColors.text),
        titleLarge: GoogleFonts.inter(fontWeight: FontWeight.w700, color: AppColors.text),
        titleMedium: GoogleFonts.inter(fontWeight: FontWeight.w600, color: AppColors.text),
        titleSmall: GoogleFonts.inter(fontWeight: FontWeight.w600, color: AppColors.text),
        bodyLarge: GoogleFonts.inter(fontWeight: FontWeight.w400, color: AppColors.text),
        bodyMedium: GoogleFonts.inter(fontWeight: FontWeight.w400, color: AppColors.text),
        bodySmall: GoogleFonts.inter(fontWeight: FontWeight.w400, color: AppColors.textMuted),
        labelLarge: GoogleFonts.inter(fontWeight: FontWeight.w600, color: AppColors.text),
        labelMedium: GoogleFonts.inter(fontWeight: FontWeight.w500, color: AppColors.textMuted),
        labelSmall: GoogleFonts.inter(fontWeight: FontWeight.w500, color: AppColors.textMuted),
      ),
      cardTheme: CardThemeData(
        color: AppColors.bgCard,
        elevation: 2,
        shadowColor: Colors.black,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(16),
          side: const BorderSide(color: AppColors.borderDefault, width: 0.5),
        ),
      ),
      appBarTheme: const AppBarTheme(
        backgroundColor: AppColors.bgSoft,
        elevation: 0,
        centerTitle: false,
        titleTextStyle: TextStyle(
          color: AppColors.text,
          fontSize: 20,
          fontWeight: FontWeight.w700,
        ),
        iconTheme: IconThemeData(color: AppColors.text),
      ),
      bottomNavigationBarTheme: const BottomNavigationBarThemeData(
        backgroundColor: AppColors.bgCard,
        selectedItemColor: AppColors.gold,
        unselectedItemColor: AppColors.textMuted,
        type: BottomNavigationBarType.fixed,
        elevation: 8,
      ),
      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          backgroundColor: AppColors.gold,
          foregroundColor: AppColors.bgDeep,
          elevation: 2,
          shadowColor: AppColors.goldDark,
          padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 14),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          textStyle: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: AppColors.text,
          side: const BorderSide(color: AppColors.borderDefault),
          padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 14),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
        ),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: AppColors.bgDeep,
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: const BorderSide(color: AppColors.borderDefault),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: const BorderSide(color: AppColors.borderDefault),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: const BorderSide(color: AppColors.gold, width: 2),
        ),
        errorBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: const BorderSide(color: AppColors.red),
        ),
        focusedErrorBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: const BorderSide(color: AppColors.red, width: 2),
        ),
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
        hintStyle: const TextStyle(color: AppColors.textMuted),
        labelStyle: const TextStyle(color: AppColors.textMuted),
        floatingLabelStyle: const TextStyle(color: AppColors.gold),
      ),
      snackBarTheme: SnackBarThemeData(
        backgroundColor: AppColors.bgElevated,
        contentTextStyle: const TextStyle(color: AppColors.text),
        actionTextColor: AppColors.gold,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
        behavior: SnackBarBehavior.floating,
      ),
      dialogTheme: DialogThemeData(
        backgroundColor: AppColors.bgCard,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(16),
          side: const BorderSide(color: AppColors.borderDefault, width: 0.5),
        ),
      ),
      floatingActionButtonTheme: const FloatingActionButtonThemeData(
        backgroundColor: AppColors.gold,
        foregroundColor: AppColors.bgDeep,
        elevation: 6,
      ),
      bottomSheetTheme: const BottomSheetThemeData(
        backgroundColor: AppColors.bgCard,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
        ),
        showDragHandle: false,
      ),
      dividerTheme: const DividerThemeData(color: AppColors.borderDefault, thickness: 1),
      chipTheme: ChipThemeData(
        backgroundColor: AppColors.bgSurface,
        labelStyle: const TextStyle(color: AppColors.text, fontSize: 13),
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(20),
          side: const BorderSide(color: AppColors.borderDefault),
        ),
      ),
    );
  }
}
