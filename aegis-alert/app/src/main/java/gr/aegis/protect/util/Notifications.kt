package gr.aegis.protect.util

import android.app.NotificationChannel
import android.app.NotificationManager
import android.content.Context
import android.os.Build
import androidx.core.content.getSystemService
import gr.aegis.protect.R

object Notifications {
    const val CH_ALERTS = "alerts"
    const val CH_CHECKIN = "checkin"
    const val CH_TRACKING = "tracking"
    const val CH_STATUS = "status"

    const val ID_ALERT_SERVICE = 1001
    const val ID_ALERT_RESULT = 1002
    const val ID_CHECKIN = 1003
    const val ID_TRACKING = 1004

    fun createChannels(context: Context) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val nm = context.getSystemService<NotificationManager>() ?: return
        nm.createNotificationChannel(
            NotificationChannel(CH_ALERTS, context.getString(R.string.channel_alerts), NotificationManager.IMPORTANCE_HIGH).apply {
                setBypassDnd(true)
            }
        )
        nm.createNotificationChannel(
            NotificationChannel(CH_CHECKIN, context.getString(R.string.channel_checkin), NotificationManager.IMPORTANCE_HIGH).apply {
                setBypassDnd(true)
            }
        )
        nm.createNotificationChannel(
            NotificationChannel(CH_TRACKING, context.getString(R.string.channel_tracking), NotificationManager.IMPORTANCE_LOW)
        )
        nm.createNotificationChannel(
            NotificationChannel(CH_STATUS, context.getString(R.string.channel_status), NotificationManager.IMPORTANCE_DEFAULT)
        )
    }
}
