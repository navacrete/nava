package gr.aegis.protect.ui

import android.Manifest
import android.content.Context
import android.content.Intent
import android.location.LocationManager
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.os.PowerManager
import android.provider.Settings
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.Toast
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AlertDialog
import androidx.core.content.ContextCompat
import androidx.fragment.app.Fragment
import gr.aegis.protect.R
import gr.aegis.protect.alert.AlertActivity
import gr.aegis.protect.alert.AlertService
import gr.aegis.protect.checkin.CheckInManager
import gr.aegis.protect.data.AlertRecord
import gr.aegis.protect.data.ContactStore
import gr.aegis.protect.data.HistoryStore
import gr.aegis.protect.data.Prefs
import gr.aegis.protect.data.Profile
import gr.aegis.protect.data.Recipient
import gr.aegis.protect.data.SmsStatus
import gr.aegis.protect.databinding.FragmentSosBinding
import gr.aegis.protect.location.LocationFinder
import gr.aegis.protect.model.EmergencyType
import gr.aegis.protect.sms.MessageBuilder
import gr.aegis.protect.sms.SmsSender
import gr.aegis.protect.tracking.TrackingService
import gr.aegis.protect.util.Perms
import gr.aegis.protect.util.Phone
import java.util.UUID

class SosFragment : Fragment() {

    private var _b: FragmentSosBinding? = null
    private val b get() = _b!!
    private lateinit var prefs: Prefs
    private val handler = Handler(Looper.getMainLooper())
    private var safeFinder: LocationFinder? = null

    private val permissionLauncher =
        registerForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { refreshReadiness() }

    private val backgroundLocationLauncher =
        registerForActivityResult(ActivityResultContracts.RequestPermission()) { }

    private val checkInTicker = object : Runnable {
        override fun run() {
            renderCheckIn()
            if (CheckInManager.isActive(requireContext())) handler.postDelayed(this, 1000L)
        }
    }

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View {
        _b = FragmentSosBinding.inflate(inflater, container, false)
        prefs = Prefs(requireContext())
        return b.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)

        // readiness actions
        b.btnSms.setOnClickListener { permissionLauncher.launch(arrayOf(Manifest.permission.SEND_SMS)) }
        b.btnLocation.setOnClickListener {
            permissionLauncher.launch(arrayOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION))
        }
        b.btnGps.setOnClickListener { Phone.openLocationSettings(requireContext()) }
        b.btnNotif.setOnClickListener {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
                permissionLauncher.launch(arrayOf(Manifest.permission.POST_NOTIFICATIONS))
            } else {
                Phone.openAppSettings(requireContext())
            }
        }
        b.btnContacts.setOnClickListener {
            (activity as? MainActivity)?.findViewById<com.google.android.material.bottomnavigation.BottomNavigationView>(R.id.bottomNav)
                ?.selectedItemId = R.id.nav_contacts
        }
        b.btnBattery.setOnClickListener { requestIgnoreBattery() }

        // type selection
        selectTypeChip(EmergencyType.fromCode(prefs.lastTypeCode))
        b.chipTypes.setOnCheckedStateChangeListener { _, checkedIds ->
            val id = checkedIds.firstOrNull() ?: return@setOnCheckedStateChangeListener
            prefs.lastTypeCode = typeForChip(id).code
        }

        // SOS
        b.btnSos.setOnClickListener { launchAlert(prefs.countdownSeconds) }
        b.btnSos.setOnLongClickListener { launchAlert(0); true }
        b.btnCall112.setOnClickListener { Phone.call112(requireContext()) }
        b.btnSafe.setOnClickListener { confirmSafe() }

        // check-in
        selectDurationChip(prefs.checkInMinutes)
        b.btnCheckinStart.setOnClickListener { startCheckIn() }
        b.btnCheckinOk.setOnClickListener {
            CheckInManager.renew(requireContext())
            Toast.makeText(requireContext(), getString(R.string.checkin_started, prefs.checkInDurationMinutes), Toast.LENGTH_SHORT).show()
            renderCheckIn()
            handler.removeCallbacks(checkInTicker); handler.post(checkInTicker)
        }
        b.btnCheckinStop.setOnClickListener {
            CheckInManager.stop(requireContext())
            renderCheckIn()
        }

        // tracking
        b.btnTrackingToggle.setOnClickListener { toggleTracking() }
        TrackingService.status.observe(viewLifecycleOwner) { renderTracking(it) }
        CheckInManager.deadline.observe(viewLifecycleOwner) { renderCheckIn() }

        if (!Perms.allEssentialGranted(requireContext())) {
            permissionLauncher.launch(Perms.essential())
        }
    }

    override fun onResume() {
        super.onResume()
        refreshReadiness()
        renderTestBanner()
        renderRecipients()
        CheckInManager.refresh(requireContext())
        handler.removeCallbacks(checkInTicker)
        handler.post(checkInTicker)
    }

    override fun onPause() {
        super.onPause()
        handler.removeCallbacks(checkInTicker)
    }

    override fun onDestroyView() {
        safeFinder?.cancel()
        safeFinder = null
        handler.removeCallbacksAndMessages(null)
        _b = null
        super.onDestroyView()
    }

    // ---- SOS ----

    private fun selectedType(): EmergencyType = typeForChip(b.chipTypes.checkedChipId)

    private fun typeForChip(id: Int): EmergencyType = when (id) {
        R.id.chipMedical -> EmergencyType.MEDICAL
        R.id.chipFire -> EmergencyType.FIRE
        R.id.chipPolice -> EmergencyType.POLICE
        R.id.chipThreat -> EmergencyType.THREAT
        R.id.chipAccident -> EmergencyType.ACCIDENT
        else -> EmergencyType.GENERAL
    }

    private fun selectTypeChip(type: EmergencyType) {
        val id = when (type) {
            EmergencyType.MEDICAL -> R.id.chipMedical
            EmergencyType.FIRE -> R.id.chipFire
            EmergencyType.POLICE -> R.id.chipPolice
            EmergencyType.THREAT -> R.id.chipThreat
            EmergencyType.ACCIDENT -> R.id.chipAccident
            EmergencyType.GENERAL -> R.id.chipGeneral
        }
        b.chipTypes.check(id)
    }

    private fun launchAlert(countdown: Int) {
        val type = selectedType()
        prefs.lastTypeCode = type.code
        val intent = Intent(requireContext(), AlertActivity::class.java)
            .putExtra(AlertActivity.EXTRA_TYPE, type.code)
            .putExtra(AlertActivity.EXTRA_NOTE, b.edtNote.text?.toString()?.trim())
            .putExtra(AlertActivity.EXTRA_SOURCE, AlertService.SOURCE_MANUAL)
            .putExtra(AlertActivity.EXTRA_COUNTDOWN, countdown)
        startActivity(intent)
    }

    // ---- readiness ----

    private fun refreshReadiness() {
        val ctx = context ?: return
        val smsOk = Perms.sms(ctx)
        val locOk = Perms.location(ctx)
        val gpsOk = isGpsEnabled(ctx)
        val notifOk = Perms.notifications(ctx)
        val contacts = ContactStore.load(ctx).size
        val contactsOk = contacts > 0
        val batteryOk = isIgnoringBattery(ctx)

        setRow(b.dotSms, b.btnSms, smsOk)
        setRow(b.dotLocation, b.btnLocation, locOk)
        setRow(b.dotGps, b.btnGps, gpsOk)
        setRow(b.dotNotif, b.btnNotif, notifOk)
        setRow(b.dotContacts, b.btnContacts, contactsOk)
        setRow(b.dotBattery, b.btnBattery, batteryOk, warnOnly = true)
        b.txtContacts.text = if (contactsOk) getString(R.string.sos_contacts_count, contacts) else getString(R.string.sos_no_contacts)

        val ready = smsOk && locOk && gpsOk && notifOk
        b.chipStatus.text = getString(if (ready) R.string.sos_header_status_ready else R.string.sos_header_status_setup)
        b.chipStatus.setChipIconResource(if (ready) R.drawable.ic_check else R.drawable.ic_warning)
        b.chipStatus.setChipIconTintResource(if (ready) R.color.green else R.color.amber)
    }

    private fun setRow(dot: View, btn: View, ok: Boolean, warnOnly: Boolean = false) {
        val color = when {
            ok -> R.color.green
            warnOnly -> R.color.amber
            else -> R.color.sos_red
        }
        dot.background.mutate().setTint(ContextCompat.getColor(requireContext(), color))
        btn.visibility = if (ok) View.INVISIBLE else View.VISIBLE
    }

    private fun isGpsEnabled(ctx: Context): Boolean {
        val lm = ctx.getSystemService(Context.LOCATION_SERVICE) as LocationManager
        return try {
            lm.isProviderEnabled(LocationManager.GPS_PROVIDER) || lm.isProviderEnabled(LocationManager.NETWORK_PROVIDER)
        } catch (e: Exception) { false }
    }

    private fun isIgnoringBattery(ctx: Context): Boolean {
        val pm = ctx.getSystemService(Context.POWER_SERVICE) as PowerManager
        return pm.isIgnoringBatteryOptimizations(ctx.packageName)
    }

    private fun requestIgnoreBattery() {
        val ctx = requireContext()
        try {
            @Suppress("BatteryLife")
            val intent = Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS, Uri.parse("package:${ctx.packageName}"))
            startActivity(intent)
        } catch (e: Exception) {
            try { startActivity(Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS)) } catch (ignored: Exception) { }
        }
    }

    private fun renderTestBanner() {
        if (prefs.testModeActive) {
            b.txtTestBanner.visibility = View.VISIBLE
            b.txtTestBanner.text = getString(R.string.sos_test_banner, prefs.testNumber)
        } else {
            b.txtTestBanner.visibility = View.GONE
        }
    }

    private fun renderRecipients() {
        b.txtRecipients.text = when {
            prefs.testModeActive -> getString(R.string.history_source_test) + ": " + prefs.testNumber
            prefs.send112 && prefs.sendContacts -> getString(R.string.sos_recipients_112_on)
            prefs.send112 -> getString(R.string.sos_recipients_contacts_off)
            prefs.sendContacts -> getString(R.string.sos_recipients_112_off)
            else -> getString(R.string.sos_recipients_none)
        }
    }

    // ---- "I'm safe" ----

    private fun confirmSafe() {
        val ctx = requireContext()
        if (!prefs.testModeActive && ContactStore.load(ctx).isEmpty()) {
            Toast.makeText(ctx, R.string.sos_im_safe_no_contacts, Toast.LENGTH_SHORT).show()
            return
        }
        if (!Perms.sms(ctx)) {
            permissionLauncher.launch(arrayOf(Manifest.permission.SEND_SMS))
            return
        }
        AlertDialog.Builder(ctx)
            .setTitle(R.string.sos_im_safe)
            .setMessage(R.string.sos_im_safe)
            .setPositiveButton(R.string.yes) { _, _ -> sendSafe() }
            .setNegativeButton(R.string.cancel, null)
            .show()
    }

    private fun sendSafe() {
        val ctx = requireContext().applicationContext
        b.btnSafe.isEnabled = false
        safeFinder?.cancel()
        safeFinder = LocationFinder(ctx).also { f ->
            f.start(15_000L) { result ->
                val p = Prefs(ctx)
                val text = MessageBuilder.buildSafe(ctx, Profile.load(ctx), result.location, result.stale)
                val raw = if (p.testModeActive) {
                    listOf(Recipient(ContactStore.normalizePhone(p.testNumber), ctx.getString(R.string.history_source_test)))
                } else {
                    ContactStore.load(ctx).map { Recipient(ContactStore.normalizePhone(it.phone), it.name) }
                }
                val targets = raw.filter { it.phone.isNotBlank() }.distinctBy { it.phone }
                val record = AlertRecord(
                    id = UUID.randomUUID().toString(), time = System.currentTimeMillis(), typeCode = "SAFE",
                    source = "safe", lat = result.location?.latitude, lon = result.location?.longitude,
                    accuracy = result.location?.accuracy, stale = result.stale, message = text,
                    recipients = targets.toMutableList()
                )
                var sent = 0
                targets.forEach { rc ->
                    val o = SmsSender.send(ctx, record.id, rc.phone, text)
                    if (o.started) { rc.totalParts = o.parts; sent++ } else { rc.status = SmsStatus.FAILED; rc.error = o.error }
                }
                HistoryStore.add(ctx, record)
                _b?.btnSafe?.isEnabled = true
                if (isAdded) Toast.makeText(ctx, getString(R.string.sos_im_safe_sent, sent), Toast.LENGTH_LONG).show()
            }
        }
    }

    // ---- check-in ----

    private fun selectedMinutes(): Int = when (b.chipCheckinDuration.checkedChipId) {
        R.id.chipMin15 -> 15
        R.id.chipMin60 -> 60
        R.id.chipMin120 -> 120
        else -> 30
    }

    private fun selectDurationChip(minutes: Int) {
        b.chipCheckinDuration.check(
            when (minutes) {
                15 -> R.id.chipMin15
                60 -> R.id.chipMin60
                120 -> R.id.chipMin120
                else -> R.id.chipMin30
            }
        )
    }

    private fun startCheckIn() {
        val ctx = requireContext()
        if (!Perms.sms(ctx) || !Perms.location(ctx)) {
            permissionLauncher.launch(Perms.essential())
            return
        }
        val minutes = selectedMinutes()
        CheckInManager.start(ctx, minutes)
        Toast.makeText(ctx, getString(R.string.checkin_started, minutes), Toast.LENGTH_SHORT).show()
        if (!CheckInManager.canScheduleExact(ctx)) {
            Toast.makeText(ctx, R.string.checkin_exact_alarm_needed, Toast.LENGTH_LONG).show()
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q && !Perms.backgroundLocation(ctx)) {
            Toast.makeText(ctx, R.string.permission_background_location, Toast.LENGTH_LONG).show()
            backgroundLocationLauncher.launch(Manifest.permission.ACCESS_BACKGROUND_LOCATION)
        }
        renderCheckIn()
        handler.removeCallbacks(checkInTicker)
        handler.post(checkInTicker)
    }

    private fun renderCheckIn() {
        val bb = _b ?: return
        val ctx = context ?: return
        val deadline = Prefs(ctx).checkInDeadline
        val remaining = deadline - System.currentTimeMillis()
        val active = remaining > 0
        bb.txtCheckinStatus.text = if (active) {
            val totalSec = remaining / 1000
            val text = String.format(java.util.Locale.getDefault(), "%02d:%02d:%02d", totalSec / 3600, (totalSec % 3600) / 60, totalSec % 60)
            getString(R.string.checkin_remaining, text)
        } else {
            getString(R.string.checkin_inactive)
        }
        bb.btnCheckinStart.visibility = if (active) View.GONE else View.VISIBLE
        bb.btnCheckinOk.visibility = if (active) View.VISIBLE else View.GONE
        bb.btnCheckinStop.visibility = if (active) View.VISIBLE else View.GONE
        bb.chipCheckinDuration.visibility = if (active) View.GONE else View.VISIBLE
    }

    // ---- tracking ----

    private fun toggleTracking() {
        val ctx = requireContext()
        val running = TrackingService.status.value?.running == true
        if (running) {
            TrackingService.stop(ctx)
            return
        }
        if (!prefs.testModeActive && ContactStore.load(ctx).isEmpty()) {
            Toast.makeText(ctx, R.string.sos_im_safe_no_contacts, Toast.LENGTH_SHORT).show()
            return
        }
        if (!Perms.sms(ctx) || !Perms.location(ctx)) {
            permissionLauncher.launch(Perms.essential())
            return
        }
        TrackingService.start(ctx)
    }

    private fun renderTracking(status: TrackingService.Status) {
        val bb = _b ?: return
        if (status.running) {
            bb.txtTrackingStatus.text = getString(R.string.tracking_active, status.intervalMinutes, status.sent)
            bb.btnTrackingToggle.text = getString(R.string.tracking_stop)
        } else {
            bb.txtTrackingStatus.text = getString(R.string.tracking_inactive)
            bb.btnTrackingToggle.text = getString(R.string.tracking_start)
        }
    }
}
