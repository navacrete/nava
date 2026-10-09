package gr.aegis.protect.tracking

import android.app.Notification
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.ServiceInfo
import android.os.Build
import android.os.Handler
import android.os.IBinder
import android.os.Looper
import android.os.PowerManager
import androidx.core.app.NotificationCompat
import androidx.core.content.ContextCompat
import androidx.lifecycle.MutableLiveData
import gr.aegis.protect.R
import gr.aegis.protect.data.AlertRecord
import gr.aegis.protect.data.ContactStore
import gr.aegis.protect.data.HistoryStore
import gr.aegis.protect.data.Prefs
import gr.aegis.protect.data.Profile
import gr.aegis.protect.data.Recipient
import gr.aegis.protect.data.SmsStatus
import gr.aegis.protect.location.LocationFinder
import gr.aegis.protect.sms.MessageBuilder
import gr.aegis.protect.sms.SmsSender
import gr.aegis.protect.ui.MainActivity
import gr.aegis.protect.util.Notifications
import gr.aegis.protect.util.Perms
import java.util.UUID

/** Periodically texts the user's position to their contacts (never to 112). */
class TrackingService : Service() {

    data class Status(val running: Boolean, val intervalMinutes: Int, val sent: Int)

    companion object {
        const val ACTION_START = "gr.aegis.protect.action.TRACK_START"
        const val ACTION_STOP = "gr.aegis.protect.action.TRACK_STOP"

        val status = MutableLiveData(Status(false, 0, 0))

        fun start(context: Context) {
            ContextCompat.startForegroundService(
                context, Intent(context, TrackingService::class.java).setAction(ACTION_START)
            )
        }

        fun stop(context: Context) {
            try {
                context.startService(Intent(context, TrackingService::class.java).setAction(ACTION_STOP))
            } catch (e: Exception) { }
        }
    }

    private val handler = Handler(Looper.getMainLooper())
    private var running = false
    private var sentCount = 0
    private var intervalMinutes = 5
    private var finder: LocationFinder? = null
    private var wakeLock: PowerManager.WakeLock? = null

    private val cycle = Runnable { runCycle() }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        when (intent?.action) {
            ACTION_START -> if (!running) begin()
            ACTION_STOP -> stopTracking()
            else -> if (!running) stopSelf()
        }
        return START_STICKY
    }

    private fun begin() {
        running = true
        sentCount = 0
        intervalMinutes = Prefs(this).trackingIntervalMinutes.coerceIn(1, 120)
        goForeground(buildNotification())
        publish()
        runCycle()
    }

    private fun runCycle() {
        if (!running) return
        acquireWakeLock(90_000L)
        finder?.cancel()
        finder = LocationFinder(this).also { f ->
            f.start(60_000L, 25f) { result ->
                if (!running) return@start
                sendUpdate(result)
                releaseWakeLock()
                handler.postDelayed(cycle, intervalMinutes * 60_000L)
            }
        }
    }

    private fun sendUpdate(result: LocationFinder.Result) {
        if (!Perms.sms(this)) return
        val prefs = Prefs(this)
        val profile = Profile.load(this)
        val text = MessageBuilder.buildTracking(this, profile, result.location, result.stale, intervalMinutes)
        val raw: List<Recipient> = if (prefs.testModeActive) {
            listOf(Recipient(ContactStore.normalizePhone(prefs.testNumber), getString(R.string.history_source_test)))
        } else {
            ContactStore.load(this).map { Recipient(ContactStore.normalizePhone(it.phone), it.name) }
        }
        val targets = raw.filter { it.phone.isNotBlank() }.distinctBy { it.phone }
        if (targets.isEmpty()) return

        val record = AlertRecord(
            id = UUID.randomUUID().toString(),
            time = System.currentTimeMillis(),
            typeCode = "TRACK",
            source = "tracking",
            lat = result.location?.latitude,
            lon = result.location?.longitude,
            accuracy = result.location?.accuracy,
            stale = result.stale,
            message = text,
            recipients = targets.toMutableList()
        )
        targets.forEach { rc ->
            val outcome = SmsSender.send(this, record.id, rc.phone, text)
            if (outcome.started) rc.totalParts = outcome.parts else { rc.status = SmsStatus.FAILED; rc.error = outcome.error }
        }
        HistoryStore.add(this, record)
        sentCount += 1
        publish()
        try { startForeground(Notifications.ID_TRACKING, buildNotification()) } catch (e: Exception) { }
    }

    private fun stopTracking() {
        running = false
        handler.removeCallbacks(cycle)
        finder?.cancel()
        finder = null
        releaseWakeLock()
        publish()
        stopForeground(STOP_FOREGROUND_REMOVE)
        stopSelf()
    }

    override fun onDestroy() {
        running = false
        handler.removeCallbacks(cycle)
        finder?.cancel()
        releaseWakeLock()
        publish()
        super.onDestroy()
    }

    private fun publish() {
        status.postValue(Status(running, intervalMinutes, sentCount))
    }

    private fun goForeground(notification: Notification) {
        try {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                startForeground(Notifications.ID_TRACKING, notification, ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION)
            } else {
                startForeground(Notifications.ID_TRACKING, notification)
            }
        } catch (e: Exception) {
            running = false
            publish()
            stopSelf()
        }
    }

    private fun buildNotification(): Notification {
        val stop = PendingIntent.getService(
            this, 5, Intent(this, TrackingService::class.java).setAction(ACTION_STOP),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
        val open = PendingIntent.getActivity(
            this, 6, Intent(this, MainActivity::class.java),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
        return NotificationCompat.Builder(this, Notifications.CH_TRACKING)
            .setSmallIcon(R.drawable.ic_track)
            .setColor(ContextCompat.getColor(this, R.color.gold))
            .setContentTitle(getString(R.string.tracking_notif_title))
            .setContentText(getString(R.string.tracking_notif_text, intervalMinutes, sentCount))
            .setOngoing(true)
            .setOnlyAlertOnce(true)
            .setContentIntent(open)
            .addAction(R.drawable.ic_close, getString(R.string.notif_action_stop), stop)
            .build()
    }

    private fun acquireWakeLock(ms: Long) {
        try {
            if (wakeLock == null) {
                val pm = getSystemService(Context.POWER_SERVICE) as PowerManager
                wakeLock = pm.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "aegis:tracking")
            }
            if (wakeLock?.isHeld != true) wakeLock?.acquire(ms)
        } catch (e: Exception) { }
    }

    private fun releaseWakeLock() {
        try { if (wakeLock?.isHeld == true) wakeLock?.release() } catch (e: Exception) { }
    }
}
