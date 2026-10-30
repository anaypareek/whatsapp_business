/// Build-time configuration. Override for local testing:
///   flutter run --dart-define=API_BASE=http://10.0.2.2:8020
class AppConfig {
  static const appName = 'WA Business Fineoutput';
  static const packageName = 'com.fineoutput.wabusiness';
  static const apiBase = String.fromEnvironment('API_BASE', defaultValue: 'https://whatsappwork.fineoutput.com');
  static const version = '1.0.3';
  // API surface (the Master app points these at the staff/admin endpoints).
  static const isAdmin = false;
  static const loginPath = '/client/auth/login';
  static const logoutPath = '/client/auth/logout';
  static const chatBase = '/client/chat';
  static const sendPath = '/client/chat/send';
  static const devicesPath = '/client/devices';
}
