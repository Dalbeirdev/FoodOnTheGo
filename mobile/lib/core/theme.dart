import 'package:flutter/material.dart';

/// Approved FoodOnTheGo brand: navy text, orange→red accent, light premium UI, Outfit type.
class Brand {
  static const navy = Color(0xFF101827);
  static const orange = Color(0xFFFF6A00);
  static const red = Color(0xFFFF2538);
  static const orangeDeep = Color(0xFFF24E1E);
  static const grey = Color(0xFF667085);
  static const greyLight = Color(0xFF98A2B3);
  static const line = Color(0xFFE6E8EE);
  static const bg = Color(0xFFF6F7FA);
  static const green = Color(0xFF1D8F3F);
  static const greenBg = Color(0xFFE6F7EA);
  static const peach = Color(0xFFFFF3EA);
  static const peachDeep = Color(0xFFFFE4D1);
  static const blueBg = Color(0xFFEAF2FF);
  static const blue = Color(0xFF2563EB);
  static const purpleBg = Color(0xFFEFE9FF);
  static const purple = Color(0xFF6F3CE6);
  static const amberBg = Color(0xFFFFF0E0);
  static const amber = Color(0xFFF08A00);
  static const star = Color(0xFFF5A623);
  static const font = 'Outfit';

  static const gradient = LinearGradient(colors: [orange, red], begin: Alignment.centerLeft, end: Alignment.centerRight);
  static const gradientDiag = LinearGradient(colors: [orange, red], begin: Alignment.topLeft, end: Alignment.bottomRight);
  static const heroGradient = LinearGradient(colors: [Color(0xFFFFF1E4), Color(0xFFF6F7FA)], begin: Alignment.topCenter, end: Alignment.bottomCenter);

  static ThemeData theme() {
    final base = ThemeData(useMaterial3: true, colorSchemeSeed: orange, brightness: Brightness.light, fontFamily: font);
    return base.copyWith(
      scaffoldBackgroundColor: bg,
      textTheme: base.textTheme.apply(bodyColor: navy, displayColor: navy, fontFamily: font),
      dividerColor: line,
      appBarTheme: const AppBarTheme(
        backgroundColor: Colors.white,
        foregroundColor: navy,
        elevation: 0,
        scrolledUnderElevation: 0.5,
        centerTitle: false,
        titleTextStyle: TextStyle(fontFamily: font, fontSize: 18, fontWeight: FontWeight.w700, color: navy),
      ),
      cardTheme: CardThemeData(color: Colors.white, elevation: 0, margin: EdgeInsets.zero, shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18), side: const BorderSide(color: line))),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: Colors.white,
        hintStyle: const TextStyle(color: greyLight, fontWeight: FontWeight.w400),
        labelStyle: const TextStyle(color: grey),
        border: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: line)),
        enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: line)),
        focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: orangeDeep, width: 1.5)),
        disabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: line)),
        contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
      ),
      chipTheme: base.chipTheme.copyWith(shape: const StadiumBorder(), side: BorderSide.none, backgroundColor: Colors.white, labelStyle: const TextStyle(fontFamily: font, fontWeight: FontWeight.w600, color: navy)),
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: Colors.white,
        surfaceTintColor: Colors.transparent,
        indicatorColor: peach,
        height: 66,
        elevation: 0,
        labelTextStyle: WidgetStateProperty.resolveWith((s) => TextStyle(fontFamily: font, fontSize: 12, fontWeight: s.contains(WidgetState.selected) ? FontWeight.w700 : FontWeight.w500, color: s.contains(WidgetState.selected) ? orangeDeep : grey)),
        iconTheme: WidgetStateProperty.resolveWith((s) => IconThemeData(color: s.contains(WidgetState.selected) ? orangeDeep : grey, size: 24)),
      ),
      snackBarTheme: const SnackBarThemeData(backgroundColor: navy, contentTextStyle: TextStyle(fontFamily: font, color: Colors.white), behavior: SnackBarBehavior.floating),
    );
  }
}

/// Simple responsive helpers so phones and tablets both look intentional.
class Layout {
  static const maxWidth = 840.0;
  static double width(BuildContext c) => MediaQuery.sizeOf(c).width;
  static bool isWide(BuildContext c) => width(c) >= 700;
  static int columns(BuildContext c, {int narrow = 1, int medium = 2, int wide = 3}) {
    final w = width(c);
    return w >= 1000 ? wide : (w >= 620 ? medium : narrow);
  }
}

/// Primary gradient button used across the app.
class BrandButton extends StatelessWidget {
  const BrandButton({super.key, required this.label, this.onPressed, this.icon, this.expand = true, this.height = 50, this.trailingIcon});
  final String label;
  final VoidCallback? onPressed;
  final IconData? icon;
  final IconData? trailingIcon;
  final bool expand;
  final double height;

  @override
  Widget build(BuildContext context) {
    final enabled = onPressed != null;
    final child = Ink(
      decoration: BoxDecoration(
        gradient: enabled ? Brand.gradient : null,
        color: enabled ? null : const Color(0xFFFFC9A8),
        borderRadius: BorderRadius.circular(14),
        boxShadow: enabled ? [BoxShadow(color: Brand.orange.withValues(alpha: .28), blurRadius: 14, offset: const Offset(0, 6))] : null,
      ),
      child: Container(
        height: height,
        padding: const EdgeInsets.symmetric(horizontal: 22),
        alignment: Alignment.center,
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          if (icon != null) ...[Icon(icon, color: Colors.white, size: 20), const SizedBox(width: 8)],
          Flexible(child: Text(label, overflow: TextOverflow.ellipsis, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700, fontSize: 16))),
          if (trailingIcon != null) ...[const SizedBox(width: 8), Icon(trailingIcon, color: Colors.white, size: 20)],
        ]),
      ),
    );
    return Material(
      color: Colors.transparent,
      child: InkWell(onTap: onPressed, borderRadius: BorderRadius.circular(14), child: expand ? SizedBox(width: double.infinity, child: child) : child),
    );
  }
}

/// Secondary (outlined) button matching the web design.
class OutlineButton extends StatelessWidget {
  const OutlineButton({super.key, required this.label, this.onPressed, this.icon, this.color = Brand.navy, this.borderColor = Brand.line, this.expand = true, this.height = 50});
  final String label;
  final VoidCallback? onPressed;
  final IconData? icon;
  final Color color, borderColor;
  final bool expand;
  final double height;
  @override
  Widget build(BuildContext context) => OutlinedButton.icon(
        onPressed: onPressed,
        icon: icon == null ? const SizedBox.shrink() : Icon(icon, size: 19, color: color == Brand.navy ? Brand.orangeDeep : color),
        label: Text(label, overflow: TextOverflow.ellipsis, style: TextStyle(fontWeight: FontWeight.w700, fontSize: 15, color: color)),
        style: OutlinedButton.styleFrom(
          minimumSize: Size(expand ? double.infinity : 0, height),
          backgroundColor: Colors.white,
          side: BorderSide(color: borderColor, width: 1.2),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
          padding: const EdgeInsets.symmetric(horizontal: 18),
        ),
      );
}

class Pill extends StatelessWidget {
  const Pill(this.text, {super.key, this.color = Brand.green, this.bg = Brand.greenBg, this.icon, this.small = false});
  final String text;
  final Color color;
  final Color bg;
  final IconData? icon;
  final bool small;
  @override
  Widget build(BuildContext context) => Container(
        padding: EdgeInsets.symmetric(horizontal: small ? 8 : 11, vertical: small ? 3 : 5),
        decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(999)),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          if (icon != null) ...[Icon(icon, size: small ? 12 : 14, color: color), const SizedBox(width: 4)],
          Text(text, style: TextStyle(color: color, fontSize: small ? 11 : 12.5, fontWeight: FontWeight.w700)),
        ]),
      );
}

/// Text painted with the brand gradient ("On Your Route").
class GradientText extends StatelessWidget {
  const GradientText(this.text, {super.key, required this.style, this.align = TextAlign.start});
  final String text;
  final TextStyle style;
  final TextAlign align;
  @override
  Widget build(BuildContext context) => ShaderMask(
        blendMode: BlendMode.srcIn,
        shaderCallback: (b) => Brand.gradient.createShader(Rect.fromLTWH(0, 0, b.width, b.height)),
        child: Text(text, textAlign: align, style: style.copyWith(color: Colors.white)),
      );
}
