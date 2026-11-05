package com.fineoutput.wabusiness

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.provider.Settings
import androidx.core.app.ActivityCompat
import androidx.core.app.NotificationManagerCompat
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

/** Native notification-permission helper: asks POST_NOTIFICATIONS directly and can open the app's notification settings. */
class MainActivity : FlutterActivity() {
    private var pending: MethodChannel.Result? = null
    private val reqCode = 7301

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, "wab/notifications").setMethodCallHandler { call, result ->
            when (call.method) {
                "enabled" -> result.success(NotificationManagerCompat.from(this).areNotificationsEnabled())
                "sdk" -> result.success(Build.VERSION.SDK_INT)
                "request" -> {
                    if (Build.VERSION.SDK_INT < 33 ||
                        checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) == PackageManager.PERMISSION_GRANTED) {
                        result.success(NotificationManagerCompat.from(this).areNotificationsEnabled())
                    } else if (pending != null) {
                        result.success(false)
                    } else {
                        pending = result
                        ActivityCompat.requestPermissions(this, arrayOf(Manifest.permission.POST_NOTIFICATIONS), reqCode)
                    }
                }
                "openSettings" -> {
                    val i = Intent(Settings.ACTION_APP_NOTIFICATION_SETTINGS)
                        .putExtra(Settings.EXTRA_APP_PACKAGE, packageName)
                        .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                    startActivity(i)
                    result.success(true)
                }
                else -> result.notImplemented()
            }
        }
    }

    override fun onRequestPermissionsResult(requestCode: Int, permissions: Array<out String>, grantResults: IntArray) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults)
        if (requestCode == reqCode) {
            pending?.success(grantResults.isNotEmpty() && grantResults[0] == PackageManager.PERMISSION_GRANTED)
            pending = null
        }
    }
}
