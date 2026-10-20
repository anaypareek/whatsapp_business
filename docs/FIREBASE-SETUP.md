# Push notifications: Firebase setup (one time, about 10 minutes)

The Android app is **already built**. You do **not** need to rebuild it. You create a free Firebase project, download two files, and upload them in the admin panel. Phones pick the settings up the next time the app starts.

**Android package name (must match exactly):** `com.fineoutput.wabusiness`

## 1. Create the Firebase project
1. Open <https://console.firebase.google.com> and sign in with the company Google account.
2. **Create a project** (for example "WA Business Fineoutput"). Google Analytics is not needed; you can switch it off.
3. Wait for "Your new project is ready", then click **Continue**.

## 2. Add the Android app → download `google-services.json`
1. On the project overview, click the **Android** icon ("Add app").
2. **Android package name:** `com.fineoutput.wabusiness`. Nickname: optional. SHA-1: leave empty.
3. Click **Register app**.
4. Click **Download google-services.json** and save the file.
5. Skip the remaining steps (SDK / Gradle). Click **Next → Next → Continue to console**. The app already contains the SDK.

> Already registered? Use ⚙️ **Project settings → General → Your apps → Android app** and press **google-services.json** to download it again.

## 3. Download the service-account key (lets the server send pushes)
1. ⚙️ **Project settings → Service accounts**.
2. Under "Firebase Admin SDK", click **Generate new private key**, then **Generate key**. A `…-firebase-adminsdk-….json` file downloads.
3. Keep this file private: it can send notifications on behalf of your project. Upload it only in the admin panel (step 4), then delete it from your computer.

No extra API needs enabling: "Firebase Cloud Messaging API (V1)" is on by default for new projects. If a test push reports that the API is disabled, open **Project settings → Cloud Messaging** and enable **Firebase Cloud Messaging API (V1)**.

## 4. Upload both files in the admin panel
1. Log in to the admin panel and open **Settings**.
2. Scroll to **Push Notifications (Firebase)**.
3. **1. google-services.json → Upload**. The card turns green and shows the project id.
   - If it says the package doesn't match, you uploaded a file for a different Android app. Redo step 2 with `com.fineoutput.wabusiness`.
4. **2. Service account key → Upload key (.json)**. It shows "uploaded, project X, client_email Y".
   - The key is stored **encrypted** in the database and is never shown or downloadable again.
5. The status line should now say **Push enabled**.
6. Have a client log in to the Android app once (or close and reopen the app). It then registers its phone, and the device count goes up.
7. Choose that client in **Send test push** and press the button. The phone should show "Test notification".

Done. New incoming WhatsApp messages now notify the client's phones, even when the app is closed.

### Notes
- Both files must come from the **same** Firebase project. The panel warns you if they don't.
- To change projects, upload new files (the old ones are replaced). Phones switch on their next app start.
- **Remove** (bin icon) turns push off. The app keeps working with in-app refresh.
- Advanced: instead of uploading the key, the server can read it from a file. Set `FCM_CREDENTIALS_PATH=/home/USER/fcm-service-account.json` in `api/.env`, with the file outside `public_html`. The env path takes priority.
- iPhone is not built. Only Android (`com.fineoutput.wabusiness`) is supported.
