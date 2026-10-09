package gr.aegis.protect.data

import android.content.Context
import org.json.JSONArray
import org.json.JSONObject
import java.util.UUID

data class EmergencyContact(
    val id: String = UUID.randomUUID().toString(),
    val name: String,
    val phone: String
)

object ContactStore {
    const val MAX_CONTACTS = 5
    private const val FILE = "aegis_contacts"
    private const val KEY = "contacts"

    fun load(context: Context): MutableList<EmergencyContact> {
        val raw = context.getSharedPreferences(FILE, Context.MODE_PRIVATE).getString(KEY, null)
            ?: return mutableListOf()
        return try {
            val arr = JSONArray(raw)
            MutableList(arr.length()) { i ->
                val o = arr.getJSONObject(i)
                EmergencyContact(
                    id = o.optString("id", UUID.randomUUID().toString()),
                    name = o.optString("name"),
                    phone = o.optString("phone")
                )
            }
        } catch (e: Exception) {
            mutableListOf()
        }
    }

    fun save(context: Context, list: List<EmergencyContact>) {
        val arr = JSONArray()
        list.forEach { c ->
            arr.put(JSONObject().put("id", c.id).put("name", c.name).put("phone", c.phone))
        }
        context.getSharedPreferences(FILE, Context.MODE_PRIVATE)
            .edit().putString(KEY, arr.toString()).apply()
    }

    fun add(context: Context, contact: EmergencyContact): Boolean {
        val list = load(context)
        if (list.size >= MAX_CONTACTS) return false
        list += contact
        save(context, list)
        return true
    }

    fun remove(context: Context, id: String) {
        val list = load(context)
        list.removeAll { it.id == id }
        save(context, list)
    }

    /** Keeps digits and a leading plus sign so SmsManager always receives a dialable number. */
    fun normalizePhone(raw: String): String {
        val trimmed = raw.trim()
        val sb = StringBuilder()
        trimmed.forEachIndexed { i, c ->
            if (c.isDigit() || (c == '+' && i == 0)) sb.append(c)
        }
        return sb.toString()
    }

    fun isValidPhone(phone: String): Boolean {
        val n = normalizePhone(phone)
        val digits = n.count { it.isDigit() }
        return digits in 3..15
    }
}
