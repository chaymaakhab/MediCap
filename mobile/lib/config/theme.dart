import 'package:flutter/material.dart';

class CliniqoTheme {
  static const Color brandDark = Color(0xFF005131);
  static const Color brandPrimary = Color(0xFF0E5534);
  static const Color brandContainer = Color(0xFF176B45);
  static const Color brandAccent = Color(0xFF10B981);
  static const Color brandMint = Color(0xFF88D7A8);
  static const Color brandLight = Color(0xFFE8F5E9);

  static const Color surfaceLowest = Color(0xFFFFFFFF);
  static const Color surfaceLow = Color(0xFFF2F4F2);
  static const Color surfaceContainer = Color(0xFFECEEEC);
  
  static const Color textDark = Color(0xFF191C1B);
  static const Color textMuted = Color(0xFF6F7A71);
  static const Color textSubtle = Color(0xFF94A3B8);

  static ThemeData lightTheme = ThemeData(
    useMaterial3: true,
    scaffoldBackgroundColor: surfaceLow,
    colorScheme: ColorScheme.fromSeed(
      seedColor: brandDark,
      primary: brandDark,
      secondary: brandContainer,
      surface: surfaceLowest,
      onPrimary: Colors.white,
      onSurface: textDark,
    ),
    appBarTheme: const AppBarTheme(
      backgroundColor: surfaceLowest,
      elevation: 0,
      scrolledUnderElevation: 1,
      titleTextStyle: TextStyle(
        color: textDark,
        fontSize: 16,
        fontWeight: FontWeight.bold,
      ),
      iconTheme: IconThemeData(color: textDark),
    ),
    cardTheme: CardTheme(
      color: surfaceLowest,
      elevation: 0,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(16),
        side: const BorderSide(color: Color(0xFFE2E8F0), width: 1),
      ),
    ),
  );
}
