package gr.aegis.protect.checkin

import android.app.AlarmManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.os.Build
import androidx.core.app.NotificationManagerCompat
import androidx.lifecycle.MutableLiveData
import gr.aegis.protect.data.Prefs
import gr.aegis.protect.util.Notifications

/** Timed check-in ("dead man's switch"): if the user does not confirm in time, an SOS is sent. */
object CheckInManager {
    private const val REQUEST_CODE = 4100

    /** Deadline in epoch millis, or 0 when inactive. */
    val deadline = MutableLiveData<Long>(0L)

    fun refresh(context: Context) {
        val d = Prefs(context).checkInDeadline
        deadline.postValue(if (d > System.currentTimeMillis()) d else 0L)
    }

    fun isActive(context: Context): Boolean = Prefs(context).checkInDeadline > System.currentTimeMillis()

    fun start(context: Context, minutes: Int) {
        val prefs = Prefs(context)
        val at = System.currentTimeMillis() + minutes * 60_000L
        prefs.checkInDeadline = at
        prefs.checkInDurationMinutes = minutes
        schedule(context, at)
        deadline.postValue(at)
    }

    /** "I'm OK": restart the same duration. */
    fun renew(context: Context) {
        val prefs = Prefs(context)
        start(context, prefs.checkInDurationMinutes.coerceAtLeast(1))
    }

    fun stop(context: Context) {
        Prefs(context).checkInDeadline = 0L
        alarmManager(context).cancel(pendingIntent(context))
        NotificationManagerCompat.from(context).cancel(Notifications.ID_CHECKIN)
        deadline.postValue(0L)
    }

    fun canScheduleExact(context: Context): Boolean =
        Build.VERSION.SDK_INT < Build.VERSION_CODES.S || alarmManager(context).canScheduleExactAlarms()

    /** Called on boot: re-arm a pending check-in, or clear one that expired while the phone was off. */
    fun restoreAfterBoot(context: Context) {
        val d = Prefs(context).checkInDeadline
        if (d <= 0L) return
        if (d > System.currentTimeMillis() + 5_000L) schedule(context, d) else Prefs(context).checkInDeadline = 0L
    }

    private fun schedule(context: Context, at: Long) {
        val am = alarmManager(context)
        val pi = pendingIntent(context)
        am.cancel(pi)
        try {
            if (canScheduleExact(context)) {
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
                    am.setExactAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, at, pi)
                } else {
                    am.setExact(AlarmManager.RTC_WAKEUP, at, pi)
                }
            } else {
                am.setAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, at, pi)
            }
        } catch (e: SecurityException) {
            am.setAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, at, pi)
        }
    }

    private fun alarmManager(context: Context) =
        context.getSystemService(Context.ALARM_SERVICE) as AlarmManager

    private fun pendingIntent(context: Context): PendingIntent =
        PendingIntent.getBroadcast(
            context, REQUEST_CODE,
            Intent(context, CheckInReceiver::class.java).setAction(CheckInReceiver.ACTION_EXPIRED),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
}
