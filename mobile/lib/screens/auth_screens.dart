import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../auth/phone.dart';
import '../core/theme.dart';
import '../state/auth_state.dart';
import '../widgets/common.dart';

/// Shared header for the three sign-in steps.
class _AuthHeader extends StatelessWidget {
  const _AuthHeader({required this.step, required this.title, required this.sub});
  final int step;
  final String title;
  final Widget sub;
  @override
  Widget build(BuildContext context) => Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Center(child: Image.asset('assets/brand/logo.png', height: 40)),
        const SizedBox(height: 18),
        Wrap(spacing: 6, runSpacing: 6, children: [
          for (final (i, label) in ['Mobile number', 'Verify code', 'Your details'].indexed)
            Container(
              padding: const EdgeInsets.fromLTRB(4, 4, 10, 4),
              decoration: BoxDecoration(color: i + 1 == step ? Brand.peach : const Color(0xFFF2F3F6), borderRadius: BorderRadius.circular(999)),
              child: Row(mainAxisSize: MainAxisSize.min, children: [
                Container(width: 22, height: 22, decoration: BoxDecoration(shape: BoxShape.circle, gradient: i + 1 == step ? Brand.gradient : null, color: i + 1 < step ? Brand.green : (i + 1 == step ? null : const Color(0xFFDFE2E8))), alignment: Alignment.center, child: i + 1 < step ? const Icon(Icons.check, size: 13, color: Colors.white) : Text('${i + 1}', style: TextStyle(fontSize: 11, fontWeight: FontWeight.w800, color: i + 1 == step ? Colors.white : Brand.navy))),
                const SizedBox(width: 6),
                Text(label, style: TextStyle(fontSize: 12, fontWeight: i + 1 == step ? FontWeight.w700 : FontWeight.w500, color: i + 1 == step ? Brand.orangeDeep : Brand.grey)),
              ]),
            ),
        ]),
        const SizedBox(height: 18),
        Text(title, style: const TextStyle(fontSize: 26, fontWeight: FontWeight.w800)),
        const SizedBox(height: 4),
        DefaultTextStyle(style: const TextStyle(color: Brand.grey, fontSize: 14.5, fontFamily: Brand.font), child: sub),
        const SizedBox(height: 18),
      ]);
}

Widget _errorBox(String? message) => message == null ? const SizedBox.shrink() : Padding(padding: const EdgeInsets.only(bottom: 12), child: InfoBox(icon: Icons.error_outline, color: Brand.red, bg: const Color(0xFFFFE9E9), child: Text(message, style: const TextStyle(color: Color(0xFF9A1D17), fontSize: 13.5))));

const _devNote = InfoBox(icon: Icons.science_outlined, color: Brand.amber, bg: Brand.amberBg, child: Text('LOCAL preview — no SMS is sent. Development code: 123456. Test numbers: 98765 43210 = existing customer, 99999 00000 = send failure, 99999 00001 = no network.', style: TextStyle(fontSize: 12.5, color: Color(0xFF7C3D00), fontWeight: FontWeight.w600)));

String _friendly(Object e) => e is AuthException ? e.message : 'Something went wrong. Please try again.';

/// Step 1: mobile number.
class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key, this.reason});
  final String? reason;
  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final phone = TextEditingController();
  Country country = countries.first;
  String? error;
  bool busy = false;

  Future<void> _submit() async {
    final invalid = validatePhone(country, phone.text);
    if (invalid != null) return setState(() => error = invalid);
    setState(() { error = null; busy = true; });
    try {
      await context.read<AuthState>().requestOtp(toE164(country, phone.text)!);
      if (mounted) context.push('/verify-otp');
    } catch (e) {
      setState(() => error = _friendly(e));
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthState>();
    final expired = widget.reason == 'expired' || auth.status == AuthStatus.sessionExpired;
    return Scaffold(
      appBar: const BrandAppBar(title: 'Sign in', showCart: false),
      body: PageBody(children: [
        Card(
          child: Padding(
            padding: const EdgeInsets.all(20),
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              const _AuthHeader(step: 1, title: 'Sign in with your mobile', sub: Text("We'll text you a one-time code. No password needed.")),
              if (expired) _errorBox('Your session has expired. Please sign in again to continue.'),
              if (widget.reason == 'logged-out') const Padding(padding: EdgeInsets.only(bottom: 12), child: InfoBox(icon: Icons.check_circle_outline, color: Brand.green, bg: Brand.greenBg, child: Text('You have been signed out.', style: TextStyle(color: Brand.green, fontWeight: FontWeight.w600)))),
              LabeledField(
                'Mobile Number',
                required: true,
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Row(children: [
                    Semantics(
                      label: 'Country: ${country.name} ${country.dial}',
                      child: Container(height: 50, padding: const EdgeInsets.symmetric(horizontal: 12), decoration: BoxDecoration(color: Colors.white, border: Border.all(color: Brand.line), borderRadius: BorderRadius.circular(12)), child: Row(children: [Text(country.flag), const SizedBox(width: 6), Text(country.dial, style: const TextStyle(fontWeight: FontWeight.w700)), if (countries.length > 1) const Icon(Icons.arrow_drop_down, size: 20)])),
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: TextField(
                        controller: phone,
                        keyboardType: TextInputType.phone,
                        textInputAction: TextInputAction.done,
                        autofillHints: const [AutofillHints.telephoneNumberNational],
                        inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[\d\s\-+]')), LengthLimitingTextInputFormatter(16)],
                        onChanged: (_) => error == null ? null : setState(() => error = null),
                        onSubmitted: (_) => _submit(),
                        decoration: InputDecoration(hintText: country.example, errorText: error, prefixIcon: const Icon(Icons.phone_android_outlined, color: Brand.grey)),
                      ),
                    ),
                  ]),
                  if (error == null) Padding(padding: const EdgeInsets.only(top: 6), child: Text('${country.name} (${country.dial}) · ${country.nationalLength} digits', style: const TextStyle(color: Brand.grey, fontSize: 12))),
                ]),
              ),
              BrandButton(label: busy ? 'Sending code…' : 'Send OTP', icon: busy ? null : Icons.sms_outlined, onPressed: busy ? null : _submit),
              const SizedBox(height: 14),
              const Text('By continuing you agree to our Terms of Service and Privacy Policy.', textAlign: TextAlign.center, style: TextStyle(color: Brand.grey, fontSize: 12)),
            ]),
          ),
        ),
        const SizedBox(height: 14),
        _devNote,
      ]),
    );
  }
}

/// Step 2: six-box OTP with paste, auto-advance, countdown, resend, attempts.
class VerifyOtpScreen extends StatefulWidget {
  const VerifyOtpScreen({super.key});
  @override
  State<VerifyOtpScreen> createState() => _VerifyOtpScreenState();
}

class _VerifyOtpScreenState extends State<VerifyOtpScreen> {
  static const length = 6;
  final controllers = List.generate(length, (_) => TextEditingController());
  final nodes = List.generate(length, (_) => FocusNode());
  Timer? ticker;
  DateTime now = DateTime.now();
  String? error;
  String? notice;
  bool busy = false;
  bool locked = false;

  @override
  void initState() {
    super.initState();
    ticker = Timer.periodic(const Duration(seconds: 1), (_) => setState(() => now = DateTime.now()));
    WidgetsBinding.instance.addPostFrameCallback((_) => nodes.first.requestFocus());
  }

  @override
  void dispose() {
    ticker?.cancel();
    for (final c in controllers) {
      c.dispose();
    }
    for (final n in nodes) {
      n.dispose();
    }
    super.dispose();
  }

  String get code => controllers.map((c) => c.text).join();

  void _fill(String text, {int from = 0}) {
    final clean = text.replaceAll(RegExp(r'\D'), '');
    var i = from;
    for (final ch in clean.split('')) {
      if (i >= length) break;
      controllers[i].text = ch;
      i++;
    }
    nodes[(i).clamp(0, length - 1)].requestFocus();
    if (error != null) setState(() => error = null);
  }

  void _clear() {
    for (final c in controllers) {
      c.clear();
    }
    nodes.first.requestFocus();
  }

  Future<void> _verify() async {
    final auth = context.read<AuthState>();
    final otp = auth.otp;
    if (otp != null && now.isAfter(otp.expiresAt)) return setState(() { error = 'Your code has expired. Request a new one.'; locked = true; });
    if (code.length < length) return setState(() => error = 'Enter the $length-digit code');
    setState(() { busy = true; error = null; notice = null; });
    try {
      final result = await auth.verifyOtp(code);
      if (!mounted) return;
      if (result is Authenticated) {
        final dest = auth.takeReturnTo();
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Welcome back, ${result.user.name}!')));
        context.go(dest);
      } else {
        context.pushReplacement('/account-setup');
      }
    } on AuthException catch (e) {
      setState(() {
        error = e.message;
        locked = e.code == AuthErrorCode.expiredOtp || e.code == AuthErrorCode.tooManyAttempts;
      });
      if (e.code == AuthErrorCode.invalidOtp) _clear();
    } catch (e) {
      setState(() => error = _friendly(e));
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> _resend() async {
    setState(() { busy = true; error = null; notice = null; });
    try {
      final otp = await context.read<AuthState>().resendOtp();
      _clear();
      setState(() { locked = false; notice = 'A new code was sent to ${maskPhone(otp.phone)}.'; });
    } catch (e) {
      setState(() => error = _friendly(e));
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  String _fmt(Duration d) { final s = d.isNegative ? 0 : d.inSeconds; return '${s ~/ 60}:${(s % 60).toString().padLeft(2, '0')}'; }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthState>();
    final phone = auth.pendingPhone;
    if (phone == null) {
      return Scaffold(appBar: const BrandAppBar(title: 'Verify', showCart: false), body: PageBody(children: [EmptyState(icon: Icons.sms_outlined, title: 'Start with your mobile number', sub: 'We need to send you a code first.', actionLabel: 'Go to sign in', onAction: () => context.go('/login'))]));
    }
    final otp = auth.otp;
    final expiresIn = otp == null ? Duration.zero : otp.expiresAt.difference(now);
    final resendIn = otp == null ? Duration.zero : otp.resendAfter.difference(now);
    final expired = locked || (otp != null && expiresIn.isNegative);
    return Scaffold(
      appBar: const BrandAppBar(title: 'Verify code', showCart: false),
      body: PageBody(children: [
        Card(
          child: Padding(
            padding: const EdgeInsets.all(20),
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              _AuthHeader(step: 2, title: 'Enter the code', sub: Wrap(crossAxisAlignment: WrapCrossAlignment.center, children: [
                Text('We sent a 6-digit code to ${maskPhone(phone)}. '),
                InkWell(onTap: () { auth.changePhone(); context.pop(); }, child: const Text('Change number', style: TextStyle(color: Brand.orangeDeep, fontWeight: FontWeight.w700, decoration: TextDecoration.underline))),
              ])),
              Semantics(
                label: 'One-time code, 6 digits',
                child: Row(children: [
                  for (var i = 0; i < length; i++) ...[
                    if (i > 0) const SizedBox(width: 8),
                    Expanded(
                      child: Semantics(
                        label: 'Digit ${i + 1} of $length',
                        child: TextField(
                          controller: controllers[i],
                          focusNode: nodes[i],
                          enabled: !busy && !expired,
                          keyboardType: TextInputType.number,
                          textAlign: TextAlign.center,
                          autofillHints: i == 0 ? const [AutofillHints.oneTimeCode] : null,
                          style: const TextStyle(fontSize: 24, fontWeight: FontWeight.w800),
                          inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                          decoration: InputDecoration(contentPadding: const EdgeInsets.symmetric(vertical: 16), counterText: '', enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide(color: error != null ? Brand.red : Brand.line, width: 1.5))),
                          onChanged: (v) {
                            if (v.length > 1) {
                              controllers[i].text = v[0];
                              _fill(v.substring(1), from: i + 1);
                              return;
                            }
                            if (v.isNotEmpty) {
                              if (i < length - 1) nodes[i + 1].requestFocus();
                            } else if (i > 0) {
                              nodes[i - 1].requestFocus();
                            }
                            if (error != null) setState(() => error = null);
                            if (code.length == length) _verify();
                          },
                        ),
                      ),
                    ),
                  ],
                ]),
              ),
              const SizedBox(height: 10),
              Row(children: [
                Expanded(child: Text(expired ? 'Code expired' : 'Code expires in ${_fmt(expiresIn)}', style: TextStyle(fontSize: 13, color: expired ? const Color(0xFF9A1D17) : Brand.grey, fontWeight: expired ? FontWeight.w700 : FontWeight.w400))),
                TextButton.icon(onPressed: busy ? null : () async { final data = await Clipboard.getData('text/plain'); if (data?.text != null) _fill(data!.text!); }, icon: const Icon(Icons.content_paste, size: 16), label: const Text('Paste')),
              ]),
              _errorBox(error),
              if (notice != null) Padding(padding: const EdgeInsets.only(bottom: 12), child: InfoBox(icon: Icons.check_circle_outline, color: Brand.green, bg: Brand.greenBg, child: Text(notice!, style: const TextStyle(color: Brand.green, fontWeight: FontWeight.w600, fontSize: 13)))),
              BrandButton(label: busy ? 'Verifying…' : 'Verify', onPressed: busy || expired ? null : _verify),
              const SizedBox(height: 14),
              Center(
                child: resendIn.isNegative || expired
                    ? TextButton(onPressed: busy ? null : _resend, child: const Text('Resend OTP', style: TextStyle(color: Brand.orangeDeep, fontWeight: FontWeight.w700)))
                    : Text("Didn't get it? Resend in ${_fmt(resendIn)}", style: const TextStyle(color: Brand.grey, fontSize: 13.5)),
              ),
            ]),
          ),
        ),
        const SizedBox(height: 14),
        _devNote,
      ]),
    );
  }
}

/// Step 3 (new customers): essential details only.
class AccountSetupScreen extends StatefulWidget {
  const AccountSetupScreen({super.key});
  @override
  State<AccountSetupScreen> createState() => _AccountSetupScreenState();
}

class _AccountSetupScreenState extends State<AccountSetupScreen> {
  final name = TextEditingController();
  final email = TextEditingController();
  bool agree = false;
  bool busy = false;
  final errors = <String, String>{};

  Future<void> _submit() async {
    final auth = context.read<AuthState>();
    errors.clear();
    if (name.text.trim().length < 2) errors['name'] = 'Enter your full name';
    if (email.text.trim().isNotEmpty && !RegExp(r'^[^\s@]+@[^\s@]+\.[^\s@]+$').hasMatch(email.text.trim())) errors['email'] = 'Enter a valid email address or leave it empty';
    if (!agree) errors['agree'] = 'Please accept the Terms and Privacy Policy to continue';
    if (errors.isNotEmpty) return setState(() {});
    setState(() => busy = true);
    try {
      final user = await auth.completeSetup(name: name.text.trim(), email: email.text.trim().isEmpty ? null : email.text.trim(), acceptTerms: agree);
      if (!mounted) return;
      final dest = auth.takeReturnTo();
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Welcome, ${user.name}! Your account is ready.')));
      context.go(dest);
    } on AuthException catch (e) {
      if (e.code == AuthErrorCode.expiredOtp && mounted) {
        context.go('/login?reason=expired');
      } else {
        setState(() => errors['form'] = e.message);
      }
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthState>();
    final phone = auth.pendingPhone;
    if (auth.setupToken == null || phone == null) {
      return Scaffold(appBar: const BrandAppBar(title: 'Your details', showCart: false), body: PageBody(children: [EmptyState(icon: Icons.lock_clock_outlined, title: 'Verification needed', sub: 'Verify your mobile number first.', actionLabel: 'Go to sign in', onAction: () => context.go('/login'))]));
    }
    return Scaffold(
      appBar: const BrandAppBar(title: 'Your details', showCart: false),
      body: PageBody(children: [
        Card(
          child: Padding(
            padding: const EdgeInsets.all(20),
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              _AuthHeader(step: 3, title: 'Welcome! Tell us your name', sub: Text('Your number ${formatPhone(phone)} is verified. Just a couple of details to finish.')),
              LabeledField('Full Name', required: true, child: TextField(controller: name, autofocus: true, textCapitalization: TextCapitalization.words, autofillHints: const [AutofillHints.name], decoration: InputDecoration(hintText: 'Your name', errorText: errors['name'], prefixIcon: const Icon(Icons.person_outline, color: Brand.grey)))),
              LabeledField('Email (optional, for receipts)', child: TextField(controller: email, keyboardType: TextInputType.emailAddress, autofillHints: const [AutofillHints.email], decoration: InputDecoration(hintText: 'you@example.com', errorText: errors['email'], prefixIcon: const Icon(Icons.mail_outline, color: Brand.grey)))),
              CheckboxListTile(value: agree, activeColor: Brand.orangeDeep, contentPadding: EdgeInsets.zero, controlAffinity: ListTileControlAffinity.leading, title: const Text('I agree to the Terms of Service and Privacy Policy', style: TextStyle(fontSize: 13.5)), subtitle: errors['agree'] == null ? null : Text(errors['agree']!, style: const TextStyle(color: Brand.red, fontSize: 12)), onChanged: (v) => setState(() { agree = v ?? false; errors.remove('agree'); })),
              const SizedBox(height: 8),
              _errorBox(errors['form']),
              BrandButton(label: busy ? 'Creating your account…' : 'Continue', trailingIcon: busy ? null : Icons.arrow_forward, onPressed: busy ? null : _submit),
              const SizedBox(height: 12),
              const Text('Date of birth, addresses and payment details can be added later from your profile.', textAlign: TextAlign.center, style: TextStyle(color: Brand.grey, fontSize: 12)),
            ]),
          ),
        ),
      ]),
    );
  }
}

/// Logout with confirmation; returns to the sign-in screen with a notice.
Future<void> confirmLogout(BuildContext context) async {
  final ok = await showDialog<bool>(
    context: context,
    builder: (ctx) => AlertDialog(
      title: const Text('Sign out of FoodOnTheGo?'),
      content: const Text("You'll need to verify your mobile number again to see your orders and profile."),
      actions: [TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')), FilledButton(style: FilledButton.styleFrom(backgroundColor: Brand.orangeDeep), onPressed: () => Navigator.pop(ctx, true), child: const Text('Sign Out'))],
    ),
  );
  if (ok != true || !context.mounted) return;
  await context.read<AuthState>().logout();
  if (context.mounted) context.go('/login?reason=logged-out');
}
