package gr.aegis.protect.data

import android.content.Context
import gr.aegis.protect.R
import org.json.JSONObject

data class Profile(
    val name: String = "",
    val bloodType: String = "",
    val allergies: String = "",
    val medications: String = "",
    val conditions: String = "",
    val notes: String = ""
) {
    fun displayName(context: Context): String =
        name.trim().ifBlank { context.getString(R.string.sms_unknown_name) }

    fun hasMedical(): Boolean =
        bloodType.isNotBlank() && bloodType != "-" || allergies.isNotBlank() ||
            medications.isNotBlank() || conditions.isNotBlank()

    /** One-line medical summary used in the SMS to contacts. */
    fun medicalSummary(context: Context): String {
        val parts = mutableListOf<String>()
        if (bloodType.isNotBlank() && bloodType != "-") parts += context.getString(R.string.sms_blood, bloodType)
        if (allergies.isNotBlank()) parts += context.getString(R.string.sms_allergies, allergies.trim())
        if (medications.isNotBlank()) parts += context.getString(R.string.sms_medications, medications.trim())
        if (conditions.isNotBlank()) parts += context.getString(R.string.sms_conditions, conditions.trim())
        return parts.joinToString(". ")
    }

    fun save(context: Context) {
        val json = JSONObject()
            .put("name", name)
            .put("bloodType", bloodType)
            .put("allergies", allergies)
            .put("medications", medications)
            .put("conditions", conditions)
            .put("notes", notes)
        context.getSharedPreferences(FILE, Context.MODE_PRIVATE)
            .edit().putString(KEY, json.toString()).apply()
    }

    companion object {
        private const val FILE = "aegis_profile"
        private const val KEY = "profile"

        fun load(context: Context): Profile {
            val raw = context.getSharedPreferences(FILE, Context.MODE_PRIVATE).getString(KEY, null)
                ?: return Profile()
            return try {
                val j = JSONObject(raw)
                Profile(
                    name = j.optString("name"),
                    bloodType = j.optString("bloodType"),
                    allergies = j.optString("allergies"),
                    medications = j.optString("medications"),
                    conditions = j.optString("conditions"),
                    notes = j.optString("notes")
                )
            } catch (e: Exception) {
                Profile()
            }
        }
    }
}
