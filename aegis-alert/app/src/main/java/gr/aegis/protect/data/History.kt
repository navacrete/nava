package gr.aegis.protect.data

import android.content.Context
import androidx.lifecycle.MutableLiveData
import org.json.JSONArray
import org.json.JSONObject

enum class SmsStatus { PENDING, SENT, DELIVERED, FAILED }

data class Recipient(
    val phone: String,
    val name: String,
    var status: SmsStatus = SmsStatus.PENDING,
    var totalParts: Int = 0,
    var sentParts: Int = 0,
    var deliveredParts: Int = 0,
    var error: String? = null
)

data class AlertRecord(
    val id: String,
    val time: Long,
    val typeCode: String,
    val source: String,
    val lat: Double?,
    val lon: Double?,
    val accuracy: Float?,
    val stale: Boolean,
    val message: String,
    val recipients: MutableList<Recipient>
)

/** Persistent log of every alert, with per-recipient SMS delivery status. */
object HistoryStore {
    private const val FILE = "aegis_history"
    private const val KEY = "records"
    private const val MAX_RECORDS = 100

    /** Bumped whenever the log changes so screens can refresh. */
    val changed = MutableLiveData<Long>()

    @Synchronized
    fun load(context: Context): MutableList<AlertRecord> {
        val raw = context.getSharedPreferences(FILE, Context.MODE_PRIVATE).getString(KEY, null)
            ?: return mutableListOf()
        return try {
            val arr = JSONArray(raw)
            MutableList(arr.length()) { i -> fromJson(arr.getJSONObject(i)) }
        } catch (e: Exception) {
            mutableListOf()
        }
    }

    @Synchronized
    fun add(context: Context, record: AlertRecord) {
        val list = load(context)
        list.add(0, record)
        while (list.size > MAX_RECORDS) list.removeAt(list.size - 1)
        persist(context, list)
    }

    @Synchronized
    fun update(context: Context, id: String, mutate: (AlertRecord) -> Unit) {
        val list = load(context)
        val rec = list.firstOrNull { it.id == id } ?: return
        mutate(rec)
        persist(context, list)
    }

    @Synchronized
    fun clear(context: Context) {
        persist(context, emptyList())
    }

    private fun persist(context: Context, list: List<AlertRecord>) {
        val arr = JSONArray()
        list.forEach { arr.put(toJson(it)) }
        context.getSharedPreferences(FILE, Context.MODE_PRIVATE)
            .edit().putString(KEY, arr.toString()).apply()
        changed.postValue(System.currentTimeMillis())
    }

    private fun toJson(r: AlertRecord): JSONObject {
        val recipients = JSONArray()
        r.recipients.forEach { rc ->
            recipients.put(
                JSONObject()
                    .put("phone", rc.phone)
                    .put("name", rc.name)
                    .put("status", rc.status.name)
                    .put("totalParts", rc.totalParts)
                    .put("sentParts", rc.sentParts)
                    .put("deliveredParts", rc.deliveredParts)
                    .put("error", rc.error ?: JSONObject.NULL)
            )
        }
        return JSONObject()
            .put("id", r.id)
            .put("time", r.time)
            .put("typeCode", r.typeCode)
            .put("source", r.source)
            .put("lat", r.lat ?: JSONObject.NULL)
            .put("lon", r.lon ?: JSONObject.NULL)
            .put("accuracy", r.accuracy?.toDouble() ?: JSONObject.NULL)
            .put("stale", r.stale)
            .put("message", r.message)
            .put("recipients", recipients)
    }

    private fun fromJson(j: JSONObject): AlertRecord {
        val rArr = j.optJSONArray("recipients") ?: JSONArray()
        val recipients = MutableList(rArr.length()) { i ->
            val o = rArr.getJSONObject(i)
            Recipient(
                phone = o.optString("phone"),
                name = o.optString("name"),
                status = runCatching { SmsStatus.valueOf(o.optString("status", "PENDING")) }.getOrDefault(SmsStatus.PENDING),
                totalParts = o.optInt("totalParts"),
                sentParts = o.optInt("sentParts"),
                deliveredParts = o.optInt("deliveredParts"),
                error = if (o.isNull("error")) null else o.optString("error")
            )
        }
        return AlertRecord(
            id = j.optString("id"),
            time = j.optLong("time"),
            typeCode = j.optString("typeCode", "SOS"),
            source = j.optString("source", "manual"),
            lat = if (j.isNull("lat")) null else j.optDouble("lat"),
            lon = if (j.isNull("lon")) null else j.optDouble("lon"),
            accuracy = if (j.isNull("accuracy")) null else j.optDouble("accuracy").toFloat(),
            stale = j.optBoolean("stale"),
            message = j.optString("message"),
            recipients = recipients
        )
    }
}
