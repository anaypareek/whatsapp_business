package com.fineoutput.wabusiness.admin

import android.app.Application
import android.util.Log
import com.google.firebase.FirebaseApp
import com.google.firebase.FirebaseOptions
import org.json.JSONObject

/**
 * The APK ships without google-services.json. The Flutter side downloads the Firebase client options from
 * GET /api/mobile/config and caches them in shared_preferences ("flutter.fcm_options_v2"). Initialising the
 * default FirebaseApp here, at process start, lets FCM deliver pushes even when the app was killed.
 */
class MainApplication : Application() {
    override fun onCreate() {
        super.onCreate()
        try {
            if (FirebaseApp.getApps(this).isNotEmpty()) return
            val raw = getSharedPreferences("FlutterSharedPreferences", MODE_PRIVATE).getString("flutter.fcm_options_v2", null) ?: return
            val j = JSONObject(raw)
            val b = FirebaseOptions.Builder()
                .setApiKey(j.getString("apiKey"))
                .setApplicationId(j.getString("appId"))
                .setProjectId(j.getString("projectId"))
            j.optString("messagingSenderId").takeIf { it.isNotEmpty() }?.let { b.setGcmSenderId(it) }
            j.optString("storageBucket").takeIf { it.isNotEmpty() && it != "null" }?.let { b.setStorageBucket(it) }
            FirebaseApp.initializeApp(this, b.build())
        } catch (e: Exception) {
            Log.w("WABusiness", "Firebase not initialised from cached options: ${e.message}")
        }
    }
}
