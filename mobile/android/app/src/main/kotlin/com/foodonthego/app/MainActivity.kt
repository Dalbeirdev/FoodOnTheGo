package com.foodonthego.app

import android.app.NotificationChannel
import android.app.NotificationManager
import android.content.Context
import android.os.Build
import androidx.core.app.NotificationManagerCompat
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

/**
 * Module 33: the Android notification channels (stable ids — Android keeps a channel's user settings forever) and the
 * OS permission state, exposed to Dart over a method channel. Nothing here sends anything.
 */
class MainActivity : FlutterActivity() {
    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, "foodonthego/notifications").setMethodCallHandler { call, result ->
            when (call.method) {
                "createChannels" -> { createChannels(); result.success(true) }
                "permissionState" -> result.success(if (NotificationManagerCompat.from(this).areNotificationsEnabled()) "GRANTED" else "DENIED")
                else -> result.notImplemented()
            }
        }
    }

    private fun createChannels() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val manager = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        val channels = listOf(
            Triple("orders", "Orders", NotificationManager.IMPORTANCE_DEFAULT),
            Triple("pickup", "Pickup", NotificationManager.IMPORTANCE_HIGH),
            Triple("account", "Account & security", NotificationManager.IMPORTANCE_HIGH),
            Triple("offers", "Offers", NotificationManager.IMPORTANCE_LOW),
        )
        for ((id, name, importance) in channels) {
            val channel = NotificationChannel(id, name, importance)
            channel.description = when (id) {
                "orders" -> "Order confirmed, being prepared, completed"
                "pickup" -> "Your order is ready for pickup"
                "account" -> "Sign-in and account changes"
                else -> "Offers and news — only with your consent"
            }
            manager.createNotificationChannel(channel)
        }
    }
}
