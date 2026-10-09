package gr.aegis.protect.sms

import android.content.Context
import android.location.Location
import gr.aegis.protect.R
import gr.aegis.protect.data.Profile
import gr.aegis.protect.model.EmergencyType
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

/** Builds the SMS texts. Coordinates always use a dot decimal separator so map links work. */
object MessageBuilder {

    fun mapsLink(lat: Double, lon: Double): String =
        String.format(Locale.US, "https://maps.google.com/?q=%.5f,%.5f", lat, lon)

    fun coords(lat: Double, lon: Double): String =
        String.format(Locale.US, "%.5f,%.5f", lat, lon)

    fun ageMinutes(loc: Location): Int =
        ((System.currentTimeMillis() - loc.time) / 60_000L).toInt().coerceAtLeast(0)

    fun timeNow(): String = SimpleDateFormat("HH:mm", Locale.getDefault()).format(Date())

    fun locationBlock(context: Context, loc: Location?, stale: Boolean): String {
        if (loc == null) return context.getString(R.string.sms_location_unknown)
        val where = coords(loc.latitude, loc.longitude) + " " + mapsLink(loc.latitude, loc.longitude)
        return if (stale) {
            context.getString(R.string.sms_location_stale, ageMinutes(loc), where)
        } else {
            context.getString(R.string.sms_location, where, loc.accuracy.toInt().coerceAtLeast(1))
        }
    }

    /** Compact message for 112: what, who, where, when. */
    fun build112(
        context: Context,
        type: EmergencyType,
        profile: Profile,
        loc: Location?,
        stale: Boolean,
        note: String?,
        includeName: Boolean
    ): String {
        val sb = StringBuilder()
        sb.append(context.getString(R.string.sms_112_prefix)).append(": ")
        sb.append(context.getString(type.smsRes)).append(". ")
        if (includeName && profile.name.isNotBlank()) {
            sb.append(context.getString(R.string.sms_name, profile.name.trim())).append(". ")
        }
        sb.append(locationBlock(context, loc, stale)).append(". ")
        sb.append(context.getString(R.string.sms_time, timeNow()))
        if (!note.isNullOrBlank()) sb.append(". ").append(context.getString(R.string.sms_note, note.trim()))
        return sb.toString()
    }

    /** Full message for personal contacts, optionally with medical details. */
    fun buildContacts(
        context: Context,
        type: EmergencyType,
        profile: Profile,
        loc: Location?,
        stale: Boolean,
        note: String?,
        includeMedical: Boolean,
        sent112: Boolean
    ): String {
        val sb = StringBuilder()
        sb.append(context.getString(R.string.sms_contacts_header, profile.displayName(context))).append(": ")
        sb.append(context.getString(type.smsRes)).append(". ")
        sb.append(locationBlock(context, loc, stale)).append(". ")
        sb.append(context.getString(R.string.sms_time, timeNow())).append(". ")
        if (!note.isNullOrBlank()) sb.append(context.getString(R.string.sms_note, note.trim())).append(". ")
        if (includeMedical && profile.hasMedical()) {
            sb.append(context.getString(R.string.sms_medical, profile.medicalSummary(context))).append(". ")
        }
        if (sent112) sb.append(context.getString(R.string.sms_contacts_112_sent))
        return sb.toString().trim()
    }

    fun buildSafe(context: Context, profile: Profile, loc: Location?, stale: Boolean): String =
        context.getString(
            R.string.sms_safe,
            profile.displayName(context),
            locationBlock(context, loc, stale),
            context.getString(R.string.sms_time, timeNow())
        )

    fun buildTracking(context: Context, profile: Profile, loc: Location?, stale: Boolean, intervalMin: Int): String =
        context.getString(
            R.string.tracking_sms,
            profile.displayName(context),
            locationBlock(context, loc, stale) + ", " + context.getString(R.string.sms_time, timeNow()),
            intervalMin
        )
}
