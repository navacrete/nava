package gr.aegis.protect.sms

import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.os.Build
import android.telephony.SmsManager
import gr.aegis.protect.data.ContactStore

object SmsSender {
    const val ACTION_SENT = "gr.aegis.protect.SMS_SENT"
    const val ACTION_DELIVERED = "gr.aegis.protect.SMS_DELIVERED"
    const val EXTRA_RECORD = "record"
    const val EXTRA_PHONE = "phone"
    const val EXTRA_PART = "part"
    const val EXTRA_TOTAL = "total"

    private fun manager(context: Context): SmsManager =
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            context.getSystemService(SmsManager::class.java)
        } else {
            @Suppress("DEPRECATION")
            SmsManager.getDefault()
        }

    data class Outcome(val started: Boolean, val parts: Int, val error: String?)

    /**
     * Sends [text] to [phone] as a (multipart) SMS. Sent/delivered reports are routed to
     * [SmsStatusReceiver], tagged with [recordId] so the history entry can be updated.
     */
    fun send(context: Context, recordId: String, phone: String, text: String): Outcome {
        val number = ContactStore.normalizePhone(phone)
        if (number.isBlank()) return Outcome(false, 0, "empty number")
        return try {
            val sm = manager(context)
            val parts = sm.divideMessage(text)
            val sentIntents = ArrayList<PendingIntent>()
            val deliveredIntents = ArrayList<PendingIntent>()
            for (i in parts.indices) {
                sentIntents += statusIntent(context, ACTION_SENT, recordId, number, i, parts.size)
                deliveredIntents += statusIntent(context, ACTION_DELIVERED, recordId, number, i, parts.size)
            }
            sm.sendMultipartTextMessage(number, null, parts, sentIntents, deliveredIntents)
            Outcome(true, parts.size, null)
        } catch (e: Exception) {
            Outcome(false, 0, e.message ?: e.javaClass.simpleName)
        }
    }

    private fun statusIntent(
        context: Context,
        action: String,
        recordId: String,
        phone: String,
        part: Int,
        total: Int
    ): PendingIntent {
        val intent = Intent(context, SmsStatusReceiver::class.java)
            .setAction(action)
            .putExtra(EXTRA_RECORD, recordId)
            .putExtra(EXTRA_PHONE, phone)
            .putExtra(EXTRA_PART, part)
            .putExtra(EXTRA_TOTAL, total)
        val requestCode = (action + recordId + phone + part).hashCode()
        return PendingIntent.getBroadcast(
            context, requestCode, intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
    }
}
