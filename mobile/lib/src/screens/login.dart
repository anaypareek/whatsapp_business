import 'package:flutter/material.dart';
import '../api.dart';
import '../config.dart';
import '../session.dart';
import '../theme.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key, required this.session, required this.onLoggedIn});
  final Session session; final Future<void> Function() onLoggedIn;
  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _email = TextEditingController(), _pass = TextEditingController();
  final _form = GlobalKey<FormState>();
  bool _busy = false, _hide = true;
  String? _error;

  Future<void> _submit() async {
    if (!_form.currentState!.validate()) return;
    setState(() { _busy = true; _error = null; });
    try {
      await widget.session.login(_email.text, _pass.text);
      await widget.onLoggedIn();
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    } catch (e) {
      setState(() => _error = 'Could not sign in. Please try again.');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: Form(
              key: _form,
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                Center(child: Container(width: 84, height: 84, decoration: BoxDecoration(color: Brand.orange, borderRadius: BorderRadius.circular(24),
                    boxShadow: [BoxShadow(color: Brand.orange.withValues(alpha: .35), blurRadius: 24, offset: const Offset(0, 10))]),
                    child: const Icon(Icons.chat_rounded, color: Colors.white, size: 44))),
                const SizedBox(height: 22),
                const Text(AppConfig.appName, textAlign: TextAlign.center, style: TextStyle(fontSize: 24, fontWeight: FontWeight.w800, color: Brand.text)),
                const SizedBox(height: 6),
                const Text('Sign in with your client portal account', textAlign: TextAlign.center, style: TextStyle(color: Brand.muted)),
                const SizedBox(height: 32),
                TextFormField(
                  key: const Key('email'), controller: _email, keyboardType: TextInputType.emailAddress, autofillHints: const [AutofillHints.email],
                  textInputAction: TextInputAction.next,
                  decoration: const InputDecoration(labelText: 'Email', prefixIcon: Icon(Icons.mail_outline)),
                  validator: (v) => (v == null || !v.contains('@')) ? 'Enter your email' : null,
                ),
                const SizedBox(height: 14),
                TextFormField(
                  key: const Key('password'), controller: _pass, obscureText: _hide, autofillHints: const [AutofillHints.password],
                  onFieldSubmitted: (_) => _submit(),
                  decoration: InputDecoration(labelText: 'Password', prefixIcon: const Icon(Icons.lock_outline),
                      suffixIcon: IconButton(icon: Icon(_hide ? Icons.visibility_outlined : Icons.visibility_off_outlined), onPressed: () => setState(() => _hide = !_hide))),
                  validator: (v) => (v == null || v.isEmpty) ? 'Enter your password' : null,
                ),
                if (_error != null) Padding(
                  padding: const EdgeInsets.only(top: 14),
                  child: Container(padding: const EdgeInsets.all(12), decoration: BoxDecoration(color: Colors.red.shade50, borderRadius: BorderRadius.circular(10)),
                      child: Row(children: [Icon(Icons.error_outline, color: Colors.red.shade700, size: 20), const SizedBox(width: 8),
                        Expanded(child: Text(_error!, style: TextStyle(color: Colors.red.shade800)))])),
                ),
                const SizedBox(height: 22),
                FilledButton(key: const Key('signin'), onPressed: _busy ? null : _submit,
                    child: _busy ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.4, color: Colors.white)) : const Text('Sign in')),
                const SizedBox(height: 28),
                const Text('Fineoutput Technologies', textAlign: TextAlign.center, style: TextStyle(color: Brand.muted, fontSize: 12)),
              ]),
            ),
          ),
        ),
      ),
    );
  }
}
