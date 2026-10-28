import 'package:flutter/material.dart';
import 'config/theme.dart';
import 'screens/dashboard_screen.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(const CliniqoApp());
}

class CliniqoApp extends StatelessWidget {
  const CliniqoApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Cliniqo Medical',
      debugShowCheckedModeBanner: false,
      theme: CliniqoTheme.lightTheme,
      home: const DashboardScreen(),
    );
  }
}
