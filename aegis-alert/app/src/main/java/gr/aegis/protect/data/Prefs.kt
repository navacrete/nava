package gr.aegis.protect.data

import android.content.Context
import android.content.SharedPreferences
import androidx.preference.PreferenceManager

/** Typed access to the settings stored by the Settings screen. */
class Prefs(context: Context) {

    val sp: SharedPreferences = PreferenceManager.getDefaultSharedPreferences(context.applicationContext)

    companion object {
        const val KEY_SEND_112 = "send_112"
        const val KEY_SEND_CONTACTS = "send_contacts"
        const val KEY_COUNTDOWN = "countdown_seconds"
        const val KEY_LOCATION_WAIT = "location_wait_seconds"
        const val KEY_INCLUDE_NAME_112 = "include_name_112"
        const val KEY_INCLUDE_MEDICAL = "include_medical_contacts"
        const val KEY_AUTO_CALL = "auto_call_112"
        const val KEY_DISCREET = "discreet_mode"
        const val KEY_CHECKIN_MINUTES = "checkin_minutes"
        const val KEY_CHECKIN_GRACE = "checkin_grace_seconds"
        const val KEY_TRACK_INTERVAL = "tracking_interval_minutes"
        const val KEY_TEST_MODE = "test_mode"
        const val KEY_TEST_NUMBER = "test_number"
        const val KEY_LAST_TYPE = "last_type"
        const val KEY_CHECKIN_DEADLINE = "checkin_deadline"
        const val KEY_CHECKIN_DURATION = "checkin_duration_minutes"
    }

    val send112: Boolean get() = sp.getBoolean(KEY_SEND_112, true)
    val sendContacts: Boolean get() = sp.getBoolean(KEY_SEND_CONTACTS, true)
    val countdownSeconds: Int get() = sp.getInt(KEY_COUNTDOWN, 5).coerceIn(1, 60)
    val locationWaitSeconds: Int get() = sp.getInt(KEY_LOCATION_WAIT, 20).coerceIn(3, 120)
    val includeName112: Boolean get() = sp.getBoolean(KEY_INCLUDE_NAME_112, true)
    val includeMedical: Boolean get() = sp.getBoolean(KEY_INCLUDE_MEDICAL, true)
    val autoCall112: Boolean get() = sp.getBoolean(KEY_AUTO_CALL, false)
    val discreet: Boolean get() = sp.getBoolean(KEY_DISCREET, false)
    val checkInMinutes: Int get() = intFromString(KEY_CHECKIN_MINUTES, 30)
    val checkInGraceSeconds: Int get() = intFromString(KEY_CHECKIN_GRACE, 60)
    val trackingIntervalMinutes: Int get() = intFromString(KEY_TRACK_INTERVAL, 5)
    val testMode: Boolean get() = sp.getBoolean(KEY_TEST_MODE, false)
    val testNumber: String get() = sp.getString(KEY_TEST_NUMBER, "")?.trim().orEmpty()

    /** Test mode is only effective when a test number has been entered. */
    val testModeActive: Boolean get() = testMode && testNumber.isNotBlank()

    var lastTypeCode: String
        get() = sp.getString(KEY_LAST_TYPE, "SOS") ?: "SOS"
        set(value) = sp.edit().putString(KEY_LAST_TYPE, value).apply()

    var checkInDeadline: Long
        get() = sp.getLong(KEY_CHECKIN_DEADLINE, 0L)
        set(value) = sp.edit().putLong(KEY_CHECKIN_DEADLINE, value).apply()

    var checkInDurationMinutes: Int
        get() = sp.getInt(KEY_CHECKIN_DURATION, 30)
        set(value) = sp.edit().putInt(KEY_CHECKIN_DURATION, value).apply()

    private fun intFromString(key: String, def: Int): Int =
        sp.getString(key, null)?.toIntOrNull() ?: def
}
