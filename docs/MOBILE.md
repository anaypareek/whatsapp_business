# WA Business Fineoutput: Android app

Flutter app in `mobile/`. It is for **client users only** and covers WhatsApp-style chat on the client's own numbers.

- **Package / applicationId:** `com.fineoutput.wabusiness`. This is final. The Firebase Android app must use exactly this id.
- **Release APK:** `release/wa-business-fineoutput.apk`. Signed with `secrets/wa-business-release.jks` (gitignored; **back it up**, because updates must be signed with the same key).
- **API base:** `https://whatsappwork.fineoutput.com`, compiled in. For local testing, override it with `--dart-define=API_BASE=http://10.0.2.2:8020`.

## Features
- Login with the client-portal email and password.
  - Returns a Sanctum token named `mobile:Android app`.
  - The token is stored in `flutter_secure_storage` (Android Keystore).
  - Logout revokes the token and removes the push device.
  - Any 401 drops back to the login screen.
- Conversation list:
  - number switcher (All numbers / one number, with unread counts per number);
  - last message preview with ticks, WhatsApp-style time, unread badge;
  - search by name, number or message text;
  - pull to refresh.
- Thread:
  - bubbles, ✓ sent / ✓✓ delivered / blue ✓✓ read / 🕒 pending / ⚠ failed;
  - date separators (Today / Yesterday / weekday / date) and infinite scroll back (50 per page);
  - send text, a photo (gallery or camera) or any document (with caption);
  - incoming images are shown inline (tap for zoom); documents, audio and video download and open in another app.
- Media comes from `GET /api/client/chat/media/{id}` with the bearer token.
  - The first request downloads incoming media from Meta once and stores it in `storage/app/public/media-in/`. Later requests are served from disk.
- Sends go through the existing client send path, so the 24-hour-window rules are unchanged.
  - A failed send shows "Not sent: …", with a hint to use an approved template from the web portal.

## API used by the app

| Method | Path | Notes |
|---|---|---|
| POST | `/api/client/auth/login` | `{email,password,device_name}` |
| GET | `/api/client/chat/sync?since=CURSOR` | The poll. `If-None-Match` → **304** |
| GET | `/api/client/chat/conversations` | snapshot + `cursor` + numbers |
| GET | `/api/client/chat/thread?phone_number_id&contact&before_id&limit` | ascending page, `has_more`; marks inbound read |
| POST | `/api/client/chat/read` | mark a conversation read |
| POST | `/api/client/chat/send` | multipart `phone_number_id,to,text,file` |
| GET | `/api/client/chat/media/{message}` | scoped to the client's own numbers |
| POST / DELETE | `/api/client/devices` | FCM token register / remove |
| GET | `/api/mobile/config` | public; Firebase client options (if uploaded) |

## Load design (shared HostGator)

### The sync endpoint
- The cursor is `maxId.maxUpdatedUnix.rowsInThatSecond` over the client's messages.
  - It is computed with index-only queries: the PK plus the new `messages(phone_number_id, updated_at)` index.
  - It is cached **5 s** per client (`chat:ver:{clientId}`). The number list is cached 60 s.
- Every `Message` save (webhook inbound, status update, send) **busts** the owning client's cursor cache. So changes are visible on the next poll, without waiting for the TTL.
- No change → `{changed:false}`, or **304** when the app sends `If-None-Match`. A no-change poll costs:
  - the Sanctum token lookup and the client row;
  - one cache read (≤1 cheap aggregate every 5 s per client when the cache is cold);
  - **zero message-table row reads** (asserted in tests).
- Change → only the changed rows (`id > sinceId OR updated_at >= sinceTs`, max 200) plus summaries of just the affected conversations.
  - A larger gap returns `reset:true` and the app reloads the snapshot.

### Throttles (per token)
- `chat-sync`: 30/min.
- `chat` (other chat calls): 90/min.
- Both return `429` with `error_code: RATE_LIMITED`.

### App polling (foreground only)

| State | Interval |
|---|---|
| Thread open, user active (last touch < 45 s) | **4 s** |
| Thread open, idle 45 s – 2 min | 8 s |
| Thread open, idle 2 – 5 min | 15 s |
| Thread open, idle > 5 min | 30 s |
| Conversation list | **20 s** (30 s after 5 min idle) |
| App in background / screen locked (`paused`, `hidden`, `detached`) | **no requests** (timer cancelled via `WidgetsBindingObserver`) |
| Error | 5 s, 10 s, 20 s … max 120 s |
| 429 | ≥ 30 s or `Retry-After` |

- Every delay gets **±15 % jitter**, so devices don't line up.
- Resuming the app does one immediate sync.
- Typing, scrolling, sending or a push arriving resets the "active" clock (or syncs at once).

### Load math

Worst realistic case: 50 clients with the app **open in the foreground at the same moment**, 10 of them actively chatting in a thread and 40 on the list or idle.
- Active: 10 × 15 req/min = 150 req/min.
- List: 40 × 3 req/min = 120 req/min.
- Total ≈ **270 req/min ≈ 4.5 req/s**, nearly all 304 / no-change.
  - Each costs roughly 2–3 indexed primary-key lookups and ~10–20 ms of PHP.
  - That is about 5–10 % of one CPU core, and no message scans.

Per device:
- Hard cap 30 sync/min from the server throttle.
- Real use 3–15/min, only while the screen is on and the app is in front.
- A phone left in a pocket makes **zero** requests.

Compare with "every phone polls every 4 s, always": 50 × 15 = 750 req/min, 24×7. The adaptive design cuts the steady state by roughly 3–10×, and to zero overnight.

Background delivery uses **FCM push** (below) rather than polling.

## Push notifications (FCM)
- **Server.** An inbound webhook message calls `SendIncomingMessagePush::dispatchAfterResponse`. Meta gets its 200 first, and no queue worker is needed.
  - The message goes to every device of the owning client through the **FCM HTTP v1** API.
  - OAuth token: an RS256 JWT signed with the service account, exchanged at Google, cached for 55 minutes.
  - Message details:
    - notification title = contact name (or +number), body = preview (`📷 Photo`, text…);
    - `android.collapse_key` and `notification.tag` = `chat_{pn}_{contact}`, so a conversation shows one notification that updates;
    - channel `chat_messages`, high priority.
  - Tokens answering `UNREGISTERED` / `NOT_FOUND` are deleted.
  - Not configured → no-op, with one warning in the log per hour. Other failures go to **Error Logs**.
- **App, no google-services.json in the APK.** Firebase is started at runtime with options from `GET /api/mobile/config`. The admin uploads those options in Settings.
  - The options are cached in shared preferences.
  - `MainApplication.kt` initialises the default `FirebaseApp` from that cache at process start. That way pushes still arrive when the app was killed.
  - The background handler also starts from the cache.
  - If the server has no config yet, push stays off and the app works with polling. On a later start it picks the config up automatically. **No rebuild is needed.**
  - After login:
    - ask for notification permission (Android 13+);
    - register the token with `POST /client/devices`;
    - re-register on token refresh.
  - On logout: `DELETE /client/devices` and delete the FCM token.
  - Push arrives while the app is open:
    - if that thread is on screen → just sync immediately (no notification);
    - otherwise → local notification plus sync.
  - Tapping a notification opens that thread, whether the app was cold or warm.
- Setup: see [FIREBASE-SETUP.md](FIREBASE-SETUP.md).

## Build

```bash
cd mobile
# signing: android/key.properties (gitignored) -> storeFile=/abs/path/secrets/wa-business-release.jks, storePassword, keyAlias, keyPassword
flutter build apk --release --target-platform android-arm,android-arm64 --split-debug-info=build/symbols --obfuscate
cp build/app/outputs/flutter-apk/app-release.apk ../release/wa-business-fineoutput.apk
```

- Version: bump `version:` in `pubspec.yaml` (for example `1.0.1+2`) before each new build so phones accept the update.
- Keystore passwords: `secrets/android-key.properties`. Never commit them. If this keystore is lost, existing installs can't be updated.

## Tests
- `flutter test`: poller intervals and jitter, store sync (304, deltas, read marking), optimistic send and failure, 429 handling, plus screenshot (golden) tests.
  - Write the screenshots to `/screenshots/mobile-*.png` with `flutter test --update-goldens test/screenshots_test.dart`.
- `LIVE_API=http://127.0.0.1:8020 LIVE_EMAIL=… LIVE_PASSWORD=… flutter test test/live_api_test.dart`: end to end against a local Laravel with mock Meta.
- Laravel: `tests/Feature/MobileChatTest.php` (scoping, paging, the sync no-change path with zero message queries, 304, deltas, throttle, devices, push settings, FCM via `Http::fake`).
