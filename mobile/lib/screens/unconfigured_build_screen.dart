import 'package:flutter/material.dart';

/// Module 39 (C-03): what a PRODUCTION build shows when its configuration cannot work — no https API address, or a
/// domain still set to the development data (see AppConfig.configurationProblems). Running such a build would show a
/// customer fixture restaurants, a mock sign-in or a mock payment, so it stops here instead. Deliberately plain: no
/// network, no storage, no backend call; the list tells the person who made the build what to fix.
class UnconfiguredBuildApp extends StatelessWidget {
  const UnconfiguredBuildApp({super.key, required this.problems});
  final List<String> problems;

  @override
  Widget build(BuildContext context) => MaterialApp(
        debugShowCheckedModeBanner: false,
        title: 'FoodOnTheGo',
        home: Scaffold(
          body: SafeArea(
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisAlignment: MainAxisAlignment.center, children: [
                const Text('This build is not configured', style: TextStyle(fontSize: 22, fontWeight: FontWeight.w800)),
                const SizedBox(height: 8),
                const Text('This copy of FoodOnTheGo was built without a working configuration and cannot be used. Please install the app from the official store.', style: TextStyle(fontSize: 15)),
                const SizedBox(height: 16),
                for (final p in problems) Padding(padding: const EdgeInsets.only(bottom: 4), child: Text('• $p', style: const TextStyle(fontSize: 13, color: Color(0xFF5B6270)))),
              ]),
            ),
          ),
        ),
      );
}
