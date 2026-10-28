import 'package:flutter/material.dart';

class Brand {
  static const orange = Color(0xFFF26B1D);
  static const orangeDark = Color(0xFFD9550C);
  static const orangeSoft = Color(0xFFFFF1E7);
  static const outBubble = Color(0xFFFFE3CF);
  static const inBubble = Colors.white;
  static const chatBg = Color(0xFFF6F1EC);
  static const text = Color(0xFF1C1917);
  static const muted = Color(0xFF78716C);
  static const readBlue = Color(0xFF2E8BF0);
}

ThemeData buildTheme() {
  final scheme = ColorScheme.fromSeed(seedColor: Brand.orange, primary: Brand.orange, onPrimary: Colors.white, surface: Colors.white);
  return ThemeData(
    useMaterial3: true,
    colorScheme: scheme,
    scaffoldBackgroundColor: Colors.white,
    appBarTheme: const AppBarTheme(backgroundColor: Brand.orange, foregroundColor: Colors.white, elevation: 0, centerTitle: false,
        titleTextStyle: TextStyle(fontFamily: 'Roboto', fontSize: 19, fontWeight: FontWeight.w600, color: Colors.white)),
    floatingActionButtonTheme: const FloatingActionButtonThemeData(backgroundColor: Brand.orange, foregroundColor: Colors.white),
    inputDecorationTheme: InputDecorationTheme(
      filled: true, fillColor: const Color(0xFFFAFAF9),
      border: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: Color(0xFFE7E5E4))),
      enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: Color(0xFFE7E5E4))),
      focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: Brand.orange, width: 1.6)),
    ),
    filledButtonTheme: FilledButtonThemeData(style: FilledButton.styleFrom(backgroundColor: Brand.orange, minimumSize: const Size.fromHeight(50),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)), textStyle: const TextStyle(fontSize: 16, fontWeight: FontWeight.w600))),
  );
}
