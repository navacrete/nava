package gr.aegis.protect.alert

import android.app.Notification
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.ServiceInfo
import android.location.Location
import android.os.Build
import android.os.Handler
import android.os.IBinder
import android.os.Looper
import android.os.PowerManager
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import gr.aegis.protect.R
import gr.aegis.protect.data.AlertRecord
import gr.aegis.protect.data.ContactStore
import gr.aegis.protect.data.HistoryStore
import gr.aegis.protect.data.Prefs
import gr.aegis.protect.data.Profile
import gr.aegis.protect.data.Recipient
import gr.aegis.protect.data.SmsStatus
import gr.aegis.protect.location.LocationFinder
import gr.aegis.protect.model.EmergencyType
import gr.aegis.protect.sms.MessageBuilder
import gr.aegis.protect.sms.SmsSender
import gr.aegis.protect.util.Notifications
import gr.aegis.protect.util.Perms
import gr.aegis.protect.util.Phone
import java.util.UUID

/**
 * Runs the whole alert in the foreground so it completes even if the screen turns off or the
 * user leaves the app: countdown (cancelable) -> best-effort location -> SMS to 112 / contacts.
 */
class AlertService : Service() {

    companion object {
        const val ACTION_START = "gr.aegis.protect.action.START_ALERT"
        const val ACTION_CANCEL = "gr.aegis.protect.action.CANCEL_ALERT"
        const val ACTION_SEND_NOW = "gr.aegis.protect.action.SEND_NOW"

        const val EXTRA_TYPE = "type"
        const val EXTRA_NOTE = "note"
        const val EXTRA_SOURCE = "source"
        const val EXTRA_COUNTDOWN = "countdown"

        const val SOURCE_MANUAL = "manual"
        const val SOURCE_TILE = "tile"
        const val SOURCE_SHORTCUT = "shortcut"
        const val SOURCE_CHECKIN = "checkin"

        fun startIntent(context: Context, type: EmergencyType, source: String, note: String?, countdownSeconds: Int): Intent =
            Intent(context, AlertService::class.java)
                .setAction(ACTION_START)
                .putExtra(EXTRA_TYPE, type.code)
                .putExtra(EXTRA_SOURCE, source)
                .putExtra(EXTRA_NOTE, note)
                .putExtra(EXTRA_COUNTDOWN, countdownSeconds)

        fun start(context: Context, type: EmergencyType, source: String, note: String?, countdownSeconds: Int) {
            ContextCompat.startForegroundService(context, startIntent(context, type, source, note, countdownSeconds))
        }

        fun cancel(context: Context) = command(context, ACTION_CANCEL)
        fun sendNow(context: Context) = command(context, ACTION_SEND_NOW)

        private fun command(context: Context, action: String) {
            if (AlertBus.state.value?.isActive != true) return
            try {
                context.startService(Intent(context, AlertService::class.java).setAction(action))
            } catch (e: Exception) {
                // App in background on a restricted OS version; the notification actions still work.
            }
        }
    }

    private enum class Phase { IDLE, COUNTDOWN, LOCATING, SENDING, DONE }

    private val handler = Handler(Looper.getMainLooper())
    private var phase = Phase.IDLE
    private var secondsLeft = 0
    private var type = EmergencyType.GENERAL
    private var source = SOURCE_MANUAL
    private var note: String? = null
    private var finder: LocationFinder? = null
    private var locationResult: LocationFinder.Result? = null
    private var wakeLock: PowerManager.WakeLock? = null
    private lateinit var prefs: Prefs

    private val tick = object : Runnable {
        override fun run() {
            if (phase != Phase.COUNTDOWN) return
            secondsLeft -= 1
            if (secondsLeft <= 0) {
                onCountdownFinished()
            } else {
                publishCountdown()
                if (!prefs.discreet) Phone.vibrate(this@AlertService, 120)
                handler.postDelayed(this, 1000L)
            }
        }
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onCreate() {
        super.onCreate()
        prefs = Prefs(this)
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        when (intent?.action) {
            ACTION_START -> {
                if (phase == Phase.IDLE || phase == Phase.DONE) begin(intent) else goForeground(buildNotification())
            }
            ACTION_CANCEL -> cancelAlert()
            ACTION_SEND_NOW -> sendNow()
            else -> if (phase == Phase.IDLE) stopSelf()
        }
        return START_NOT_STICKY
    }

    private fun begin(intent: Intent) {
        type = EmergencyType.fromCode(intent.getStringExtra(EXTRA_TYPE))
        source = intent.getStringExtra(EXTRA_SOURCE) ?: SOURCE_MANUAL
        note = intent.getStringExtra(EXTRA_NOTE)
        secondsLeft = intent.getIntExtra(EXTRA_COUNTDOWN, prefs.countdownSeconds).coerceIn(0, 300)
        locationResult = null
        acquireWakeLock()

        // Start looking for a fix right away so it is usually ready when the countdown ends.
        val waitMs = (secondsLeft + prefs.locationWaitSeconds) * 1000L
        finder = LocationFinder(this).also { f ->
            f.start(waitMs) { result ->
                locationResult = result
                if (phase == Phase.LOCATING) proceedToSend()
            }
        }

        if (secondsLeft > 0) {
            phase = Phase.COUNTDOWN
            publishCountdown()
            goForeground(buildNotification())
            if (!prefs.discreet) Phone.vibrate(this, 200)
            handler.postDelayed(tick, 1000L)
        } else {
            goForeground(buildNotification())
            onCountdownFinished()
        }
    }

    private fun publishCountdown() {
        AlertBus.state.postValue(AlertState.Countdown(secondsLeft, type, source))
        if (phase == Phase.COUNTDOWN) notify(buildNotification())
    }

    private fun onCountdownFinished() {
        handler.removeCallbacks(tick)
        phase = Phase.LOCATING
        AlertBus.state.postValue(AlertState.Locating(type, source))
        notify(buildNotification())
        val result = locationResult
        if (result != null || finder?.isFinished == true) {
            proceedToSend()
        } else {
            finder?.setDeadline(prefs.locationWaitSeconds * 1000L)
        }
    }

    private fun sendNow() {
        when (phase) {
            Phase.COUNTDOWN -> {
                secondsLeft = 0
                onCountdownFinished()
            }
            Phase.LOCATING -> {
                // Give the GPS only a few more seconds, then go with what we have.
                finder?.setDeadline(3000L)
            }
            else -> { }
        }
    }

    private fun cancelAlert() {
        if (phase == Phase.COUNTDOWN || phase == Phase.LOCATING) {
            handler.removeCallbacks(tick)
            finder?.cancel()
            phase = Phase.DONE
            AlertBus.state.postValue(AlertState.Cancelled(type))
            finish(removeNotification = true)
        }
    }

    private fun proceedToSend() {
        if (phase != Phase.LOCATING) return
        phase = Phase.SENDING
        AlertBus.state.postValue(AlertState.Sending(type, source))
        notify(buildNotification())

        val result = locationResult ?: LocationFinder.Result(null, true)
        val loc: Location? = result.location
        val stale = result.stale

        if (!Perms.sms(this)) {
            fail(AlertState.Reason.NO_SMS_PERMISSION)
            return
        }

        val profile = Profile.load(this)
        val recipients = mutableListOf<Recipient>()
        val messages = mutableMapOf<String, String>()
        val testMode = prefs.testModeActive
        val send112 = prefs.send112 && !testMode
        val contacts = if (prefs.sendContacts) ContactStore.load(this) else emptyList()

        val text112 = MessageBuilder.build112(this, type, profile, loc, stale, note, prefs.includeName112)
        val textContacts = MessageBuilder.buildContacts(this, type, profile, loc, stale, note, prefs.includeMedical, send112)

        if (testMode) {
            val n = ContactStore.normalizePhone(prefs.testNumber)
            recipients += Recipient(n, getString(R.string.history_source_test))
            messages[n] = getString(R.string.sms_test_prefix) + " " + text112 + "\n" +
                getString(R.string.sms_test_prefix) + " " + textContacts
        } else {
            if (send112) {
                recipients += Recipient(Phone.EMERGENCY_NUMBER, getString(R.string.recipient_112))
                messages[Phone.EMERGENCY_NUMBER] = text112
            }
            contacts.forEach { c ->
                val n = ContactStore.normalizePhone(c.phone)
                if (n.isNotBlank() && messages[n] == null) {
                    recipients += Recipient(n, c.name)
                    messages[n] = textContacts
                }
            }
        }

        if (recipients.isEmpty()) {
            fail(AlertState.Reason.NO_RECIPIENTS)
            return
        }

        val record = AlertRecord(
            id = UUID.randomUUID().toString(),
            time = System.currentTimeMillis(),
            typeCode = type.code,
            source = if (testMode) "test" else source,
            lat = loc?.latitude,
            lon = loc?.longitude,
            accuracy = loc?.accuracy,
            stale = stale,
            message = messages[recipients.first().phone] ?: "",
            recipients = recipients
        )

        var sentCount = 0
        recipients.forEach { rc ->
            val outcome = SmsSender.send(this, record.id, rc.phone, messages[rc.phone] ?: "")
            if (outcome.started) {
                rc.totalParts = outcome.parts
                sentCount += 1
            } else {
                rc.status = SmsStatus.FAILED
                rc.error = outcome.error
            }
        }
        HistoryStore.add(this, record)

        phase = Phase.DONE
        if (sentCount == 0) {
            fail(AlertState.Reason.SEND_ERROR)
            return
        }
        AlertBus.state.postValue(
            AlertState.Done(type, source, record.id, recipients.map { it.name }, sentCount, loc, stale)
        )
        showResultNotification(sentCount, recipients.joinToString(", ") { it.name })
        finish(removeNotification = true)
    }

    private fun fail(reason: AlertState.Reason) {
        phase = Phase.DONE
        AlertBus.state.postValue(AlertState.Failed(type, reason))
        val text = when (reason) {
            AlertState.Reason.NO_SMS_PERMISSION -> getString(R.string.alert_no_sms_permission)
            AlertState.Reason.NO_RECIPIENTS -> getString(R.string.alert_no_recipients)
            AlertState.Reason.SEND_ERROR -> getString(R.string.alert_title_failed)
        }
        val n = baseBuilder(Notifications.CH_ALERTS)
            .setContentTitle(getString(R.string.notif_failed_title))
            .setContentText(text)
            .setStyle(NotificationCompat.BigTextStyle().bigText(text))
            .addAction(R.drawable.ic_phone, getString(R.string.notif_action_call), Phone.dial112PendingIntent(this))
            .setAutoCancel(true)
            .build()
        safeNotify(Notifications.ID_ALERT_RESULT, n)
        finish(removeNotification = true)
    }

    private fun finish(removeNotification: Boolean) {
        handler.removeCallbacks(tick)
        finder?.cancel()
        finder = null
        releaseWakeLock()
        // Leave the final state on the bus for the activity, then reset shortly after.
        handler.postDelayed({
            val s = AlertBus.state.value
            if (s != null && !s.isActive) AlertBus.state.postValue(AlertState.Idle)
        }, 2 * 60_000L)
        if (removeNotification) stopForeground(STOP_FOREGROUND_REMOVE) else stopForeground(STOP_FOREGROUND_DETACH)
        stopSelf()
    }

    override fun onDestroy() {
        handler.removeCallbacks(tick)
        finder?.cancel()
        releaseWakeLock()
        if (phase != Phase.DONE && phase != Phase.IDLE) {
            AlertBus.state.postValue(AlertState.Idle)
        }
        super.onDestroy()
    }

    // ---- notifications ----

    private fun goForeground(notification: Notification) {
        try {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                val fgsType = if (Perms.location(this)) {
                    ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION
                } else if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE) {
                    ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE
                } else {
                    0
                }
                if (fgsType != 0) startForeground(Notifications.ID_ALERT_SERVICE, notification, fgsType)
                else startForeground(Notifications.ID_ALERT_SERVICE, notification)
            } else {
                startForeground(Notifications.ID_ALERT_SERVICE, notification)
            }
        } catch (e: Exception) {
            // Foreground start can be refused (background start restrictions). Continue anyway:
            // when started from the visible activity the work still completes.
        }
    }

    private fun notify(notification: Notification) {
        if (phase == Phase.DONE || phase == Phase.IDLE) return
        safeNotify(Notifications.ID_ALERT_SERVICE, notification)
    }

    private fun safeNotify(id: Int, notification: Notification) {
        if (!Perms.notifications(this)) return
        try { NotificationManagerCompat.from(this).notify(id, notification) } catch (e: Exception) { }
    }

    private fun baseBuilder(channel: String): NotificationCompat.Builder =
        NotificationCompat.Builder(this, channel)
            .setSmallIcon(R.drawable.ic_sos)
            .setColor(ContextCompat.getColor(this, R.color.sos_red))
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setCategory(NotificationCompat.CATEGORY_ALARM)
            .setOnlyAlertOnce(true)
            .setContentIntent(openActivityIntent())

    private fun buildNotification(): Notification {
        val b = baseBuilder(Notifications.CH_ALERTS).setOngoing(true)
        when (phase) {
            Phase.COUNTDOWN -> {
                b.setContentTitle(getString(R.string.notif_countdown_title, secondsLeft))
                    .setContentText(getString(R.string.notif_countdown_text))
                    .addAction(R.drawable.ic_close, getString(R.string.notif_action_cancel), serviceIntent(ACTION_CANCEL, 1))
                    .addAction(R.drawable.ic_send, getString(R.string.notif_action_send_now), serviceIntent(ACTION_SEND_NOW, 2))
            }
            Phase.LOCATING -> {
                b.setContentTitle(getString(R.string.notif_locating_title))
                    .setContentText(getString(type.labelRes))
                    .addAction(R.drawable.ic_close, getString(R.string.notif_action_cancel), serviceIntent(ACTION_CANCEL, 1))
                    .addAction(R.drawable.ic_send, getString(R.string.notif_action_send_now), serviceIntent(ACTION_SEND_NOW, 2))
            }
            else -> {
                b.setContentTitle(getString(R.string.notif_sending_title))
                    .setContentText(getString(type.labelRes))
            }
        }
        return b.build()
    }

    private fun showResultNotification(sentCount: Int, names: String) {
        val n = baseBuilder(Notifications.CH_STATUS)
            .setContentTitle(getString(R.string.notif_done_title))
            .setContentText(getString(R.string.notif_done_text, sentCount, names))
            .setStyle(NotificationCompat.BigTextStyle().bigText(getString(R.string.notif_done_text, sentCount, names)))
            .addAction(R.drawable.ic_phone, getString(R.string.notif_action_call), Phone.dial112PendingIntent(this))
            .setAutoCancel(true)
            .setOngoing(false)
            .build()
        safeNotify(Notifications.ID_ALERT_RESULT, n)
    }

    private fun serviceIntent(action: String, code: Int): PendingIntent =
        PendingIntent.getService(
            this, code,
            Intent(this, AlertService::class.java).setAction(action),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

    private fun openActivityIntent(): PendingIntent =
        PendingIntent.getActivity(
            this, 3,
            Intent(this, AlertActivity::class.java)
                .putExtra(EXTRA_SOURCE, source)
                .putExtra(AlertActivity.EXTRA_ATTACH_ONLY, true)
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

    // ---- wake lock ----

    private fun acquireWakeLock() {
        if (wakeLock?.isHeld == true) return
        try {
            val pm = getSystemService(Context.POWER_SERVICE) as PowerManager
            wakeLock = pm.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "aegis:alert").also {
                it.acquire(5 * 60_000L)
            }
        } catch (e: Exception) { }
    }

    private fun releaseWakeLock() {
        try { if (wakeLock?.isHeld == true) wakeLock?.release() } catch (e: Exception) { }
        wakeLock = null
    }
}
