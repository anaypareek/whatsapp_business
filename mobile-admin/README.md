# WA Fineoutput Master (Android)

Staff/admin companion app (package `com.fineoutput.wabusiness.admin`). Same codebase as `mobile/` with `AppConfig.isAdmin = true`:
admin login (`/api/auth/login`), chats across ALL numbers (`/api/admin/chat/*`) with a number filter showing the owning client,
replies with media via `POST /api/messages/send`, polling + push (`/api/admin/devices`), number/template request approve/reject,
and the client dues badge/page (`/api/billing/summary`).

Build: `flutter build apk --release --split-per-abi --target-platform android-arm64` (signing: `android/key.properties`, gitignored, keystore in /secrets).
Screenshots: `flutter test --update-goldens test/screenshots_test.dart` writes screenshots/106-110.
