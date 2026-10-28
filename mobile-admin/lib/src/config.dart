/// Build-time configuration. Override for local testing:
///   flutter run --dart-define=API_BASE=http://10.0.2.2:8020
class AppConfig {
  static const appName = 'WA Fineoutput Master';
  static const packageName = 'com.fineoutput.wabusiness.admin';
  static const apiBase = String.fromEnvironment('API_BASE', defaultValue: 'https://whatsappwork.fineoutput.com');
  static const version = '1.0.0';
  // API surface (the Master app points these at the staff/admin endpoints).
  static const isAdmin = true;
  static const loginPath = '/auth/login';
  static const logoutPath = '/auth/logout';
  static const chatBase = '/admin/chat';
  static const sendPath = '/messages/send';
  static const devicesPath = '/admin/devices';
}
