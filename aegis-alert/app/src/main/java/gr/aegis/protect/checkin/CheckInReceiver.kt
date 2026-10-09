package gr.aegis.protect.checkin

import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import gr.aegis.protect.R
import gr.aegis.protect.alert.AlertActivity
import gr.aegis.protect.alert.AlertService
import gr.aegis.protect.data.Prefs
import gr.aegis.protect.model.EmergencyType
import gr.aegis.protect.util.Notifications
import gr.aegis.protect.util.Perms

class CheckInReceiver : BroadcastReceiver() {
    companion object {
        const val ACTION_EXPIRED = "gr.aegis.protect.action.CHECKIN_EXPIRED"
        const val ACTION_IM_OK = "gr.aegis.protect.action.CHECKIN_OK"
    }

    override fun onReceive(context: Context, intent: Intent) {
        when (intent.action) {
            ACTION_IM_OK -> {
                AlertService.cancel(context)
                CheckInManager.stop(context)
            }
            ACTION_EXPIRED -> onExpired(context)
        }
    }

    private fun onExpired(context: Context) {
        val prefs = Prefs(context)
        if (prefs.checkInDeadline <= 0L) return
        prefs.checkInDeadline = 0L
        CheckInManager.deadline.postValue(0L)
        val grace = prefs.checkInGraceSeconds
        val type = EmergencyType.fromCode(prefs.lastTypeCode)
        val note = context.getString(R.string.checkin_missed_note)

        // The service carries the grace countdown and sends the SOS even if nobody touches the phone.
        try {
            AlertService.start(context, type, AlertService.SOURCE_CHECKIN, note, grace)
        } catch (e: Exception) { }

        // Full-screen notification so the user can cancel from the lock screen.
        val activityIntent = Intent(context, AlertActivity::class.java)
            .putExtra(AlertActivity.EXTRA_SOURCE, AlertService.SOURCE_CHECKIN)
            .putExtra(AlertActivity.EXTRA_TYPE, type.code)
            .putExtra(AlertActivity.EXTRA_NOTE, note)
            .putExtra(AlertActivity.EXTRA_COUNTDOWN, grace)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        val fullScreen = PendingIntent.getActivity(
            context, 4101, activityIntent, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
        val imOk = PendingIntent.getBroadcast(
            context, 4102,
            Intent(context, CheckInReceiver::class.java).setAction(ACTION_IM_OK),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
        val n = NotificationCompat.Builder(context, Notifications.CH_CHECKIN)
            .setSmallIcon(R.drawable.ic_timer)
            .setColor(ContextCompat.getColor(context, R.color.sos_red))
            .setContentTitle(context.getString(R.string.checkin_notif_title))
            .setContentText(context.getString(R.string.checkin_notif_text, grace))
            .setPriority(NotificationCompat.PRIORITY_MAX)
            .setCategory(NotificationCompat.CATEGORY_ALARM)
            .setContentIntent(fullScreen)
            .setFullScreenIntent(fullScreen, true)
            .addAction(R.drawable.ic_check, context.getString(R.string.notif_action_ok), imOk)
            .setAutoCancel(true)
            .setTimeoutAfter(grace * 1000L + 5_000L)
            .build()
        if (Perms.notifications(context)) {
            try { NotificationManagerCompat.from(context).notify(Notifications.ID_CHECKIN, n) } catch (e: Exception) { }
        }
    }
}
