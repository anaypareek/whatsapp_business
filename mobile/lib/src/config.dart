/// Build-time configuration. Override for local testing:
///   flutter run --dart-define=API_BASE=http://10.0.2.2:8020
class AppConfig {
  static const appName = 'WA Business Fineoutput';
  static const packageName = 'com.fineoutput.wabusiness';
  static const apiBase = String.fromEnvironment('API_BASE', defaultValue: 'https://whatsappwork.fineoutput.com');
  static const version = '1.0.0';
}
