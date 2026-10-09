package gr.aegis.protect.alert

import android.content.Intent
import android.os.Build
import android.os.Bundle
import android.view.View
import android.view.WindowManager
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import gr.aegis.protect.R
import gr.aegis.protect.data.Prefs
import gr.aegis.protect.databinding.ActivityAlertBinding
import gr.aegis.protect.model.EmergencyType
import gr.aegis.protect.sms.MessageBuilder
import gr.aegis.protect.util.Perms
import gr.aegis.protect.util.Phone

/**
 * Full-screen alert UI. The real work happens in [AlertService]; this screen shows the
 * countdown, lets the user cancel or send immediately, and reports the outcome.
 */
class AlertActivity : AppCompatActivity() {

    companion object {
        const val EXTRA_TYPE = AlertService.EXTRA_TYPE
        const val EXTRA_NOTE = AlertService.EXTRA_NOTE
        const val EXTRA_SOURCE = AlertService.EXTRA_SOURCE
        const val EXTRA_COUNTDOWN = AlertService.EXTRA_COUNTDOWN
        const val EXTRA_ATTACH_ONLY = "attach_only"
    }

    private lateinit var b: ActivityAlertBinding
    private lateinit var prefs: Prefs
    private var autoCalled = false

    private val permissionLauncher =
        registerForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) {
            startAlert()
        }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        b = ActivityAlertBinding.inflate(layoutInflater)
        setContentView(b.root)
        prefs = Prefs(this)
        keepScreenOn()

        b.btnCancel.setOnClickListener { AlertService.cancel(this) }
        b.btnSendNow.setOnClickListener { AlertService.sendNow(this) }
        b.btnCall.setOnClickListener { Phone.call112(this) }
        b.btnClose.setOnClickListener { finish() }
        b.btnSettings.setOnClickListener {
            androidx.appcompat.app.AlertDialog.Builder(this)
                .setTitle(R.string.restricted_title)
                .setMessage(R.string.restricted_text)
                .setPositiveButton(R.string.restricted_open) { _, _ -> Phone.openAppSettings(this) }
                .setNegativeButton(R.string.cancel, null)
                .show()
        }

        AlertBus.state.observe(this) { render(it) }

        if (savedInstanceState == null) handleIntent(intent)
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        handleIntent(intent)
    }

    private fun handleIntent(intent: Intent?) {
        val current = AlertBus.state.value
        if (current?.isActive == true || intent?.getBooleanExtra(EXTRA_ATTACH_ONLY, false) == true) {
            return // just observe the running alert
        }
        autoCalled = false
        if (Perms.sms(this) && Perms.location(this)) {
            startAlert()
        } else {
            permissionLauncher.launch(Perms.essential())
        }
    }

    private fun startAlert() {
        val type = EmergencyType.fromCode(intent?.getStringExtra(EXTRA_TYPE) ?: prefs.lastTypeCode)
        val source = intent?.getStringExtra(EXTRA_SOURCE) ?: AlertService.SOURCE_MANUAL
        val note = intent?.getStringExtra(EXTRA_NOTE)
        val countdown = intent?.getIntExtra(EXTRA_COUNTDOWN, prefs.countdownSeconds) ?: prefs.countdownSeconds
        if (!Perms.sms(this)) {
            render(AlertState.Failed(type, AlertState.Reason.NO_SMS_PERMISSION))
            return
        }
        AlertService.start(this, type, source, note, countdown)
    }

    private fun render(state: AlertState) {
        val type = when (state) {
            is AlertState.Countdown -> state.type
            is AlertState.Locating -> state.type
            is AlertState.Sending -> state.type
            is AlertState.Done -> state.type
            is AlertState.Cancelled -> state.type
            is AlertState.Failed -> state.type
            AlertState.Idle -> EmergencyType.fromCode(prefs.lastTypeCode)
        }
        b.txtType.text = getString(type.labelRes)
        b.imgType.setImageResource(type.iconRes)

        val source = when (state) {
            is AlertState.Countdown -> state.source
            is AlertState.Locating -> state.source
            is AlertState.Sending -> state.source
            is AlertState.Done -> state.source
            else -> null
        }
        b.txtSource.visibility = if (source == AlertService.SOURCE_CHECKIN) View.VISIBLE else View.GONE

        b.groupActive.visibility = View.GONE
        b.groupDone.visibility = View.GONE
        b.progress.visibility = View.GONE
        b.txtCountdown.visibility = View.GONE
        b.btnSettings.visibility = View.GONE
        b.btnSendNow.visibility = View.VISIBLE

        when (state) {
            is AlertState.Countdown -> {
                b.txtTitle.text = getString(R.string.alert_title_countdown)
                b.txtCountdown.visibility = View.VISIBLE
                b.txtCountdown.text = state.secondsLeft.toString()
                b.txtStatus.text = recipientsLine()
                b.groupActive.visibility = View.VISIBLE
            }
            is AlertState.Locating -> {
                b.txtTitle.text = getString(R.string.alert_title_locating)
                b.progress.visibility = View.VISIBLE
                b.txtStatus.text = getString(R.string.alert_status_waiting_location)
                b.groupActive.visibility = View.VISIBLE
            }
            is AlertState.Sending -> {
                b.txtTitle.text = getString(R.string.alert_title_sending)
                b.progress.visibility = View.VISIBLE
                b.txtStatus.text = recipientsLine()
                b.groupActive.visibility = View.VISIBLE
                b.btnSendNow.visibility = View.GONE
            }
            is AlertState.Done -> {
                b.txtTitle.text = getString(R.string.alert_title_done)
                val loc = state.location
                val locText = when {
                    loc == null -> getString(R.string.alert_location_none)
                    state.stale -> getString(R.string.alert_location_stale, MessageBuilder.ageMinutes(loc))
                    else -> getString(R.string.alert_location_found, loc.accuracy.toInt().coerceAtLeast(1))
                }
                b.txtStatus.text = getString(R.string.alert_recipients, state.recipients.joinToString(", ")) +
                    "\n" + locText + "\n" + getString(R.string.alert_sent_summary, state.sentCount)
                b.groupDone.visibility = View.VISIBLE
                maybeAutoCall()
            }
            is AlertState.Cancelled -> {
                b.txtTitle.text = getString(R.string.alert_title_cancelled)
                b.txtStatus.text = ""
                b.groupDone.visibility = View.VISIBLE
            }
            is AlertState.Failed -> {
                b.txtTitle.text = getString(R.string.alert_title_failed)
                b.txtStatus.text = when (state.reason) {
                    AlertState.Reason.NO_SMS_PERMISSION -> getString(R.string.alert_no_sms_permission)
                    AlertState.Reason.NO_RECIPIENTS -> getString(R.string.alert_no_recipients)
                    AlertState.Reason.SEND_ERROR -> getString(R.string.alert_title_failed)
                }
                b.btnSettings.visibility = if (state.reason == AlertState.Reason.NO_SMS_PERMISSION) View.VISIBLE else View.GONE
                b.groupDone.visibility = View.VISIBLE
            }
            AlertState.Idle -> {
                b.txtTitle.text = getString(R.string.alert_title_countdown)
                b.txtStatus.text = recipientsLine()
                b.groupDone.visibility = View.VISIBLE
            }
        }
    }

    private fun recipientsLine(): String {
        if (prefs.testModeActive) return getString(R.string.sos_test_banner, prefs.testNumber)
        return when {
            prefs.send112 && prefs.sendContacts -> getString(R.string.sos_recipients_112_on)
            prefs.send112 -> getString(R.string.sos_recipients_contacts_off)
            prefs.sendContacts -> getString(R.string.sos_recipients_112_off)
            else -> getString(R.string.sos_recipients_none)
        }
    }

    private fun maybeAutoCall() {
        if (autoCalled || !prefs.autoCall112 || prefs.testModeActive) return
        autoCalled = true
        Phone.call112(this)
    }

    private fun keepScreenOn() {
        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O_MR1) {
            setShowWhenLocked(true)
            setTurnScreenOn(true)
        } else {
            @Suppress("DEPRECATION")
            window.addFlags(
                WindowManager.LayoutParams.FLAG_SHOW_WHEN_LOCKED or WindowManager.LayoutParams.FLAG_TURN_SCREEN_ON
            )
        }
        window.statusBarColor = ContextCompat.getColor(this, R.color.sos_red_deep)
    }
}
