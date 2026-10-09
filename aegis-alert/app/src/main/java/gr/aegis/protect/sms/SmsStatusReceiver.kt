package gr.aegis.protect.sms

import android.app.Activity
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.telephony.SmsManager
import gr.aegis.protect.data.HistoryStore
import gr.aegis.protect.data.SmsStatus

/** Receives the per-part sent/delivered reports and updates the history record. */
class SmsStatusReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        val recordId = intent.getStringExtra(SmsSender.EXTRA_RECORD) ?: return
        val phone = intent.getStringExtra(SmsSender.EXTRA_PHONE) ?: return
        val total = intent.getIntExtra(SmsSender.EXTRA_TOTAL, 1)
        val code = resultCode

        HistoryStore.update(context, recordId) { record ->
            val rc = record.recipients.firstOrNull { it.phone == phone } ?: return@update
            if (rc.totalParts == 0) rc.totalParts = total
            when (intent.action) {
                SmsSender.ACTION_SENT -> {
                    if (code == Activity.RESULT_OK) {
                        rc.sentParts += 1
                        if (rc.status == SmsStatus.PENDING && rc.sentParts >= rc.totalParts) rc.status = SmsStatus.SENT
                    } else {
                        rc.status = SmsStatus.FAILED
                        rc.error = errorText(code)
                    }
                }
                SmsSender.ACTION_DELIVERED -> {
                    if (code == Activity.RESULT_OK) {
                        rc.deliveredParts += 1
                        if (rc.status != SmsStatus.FAILED && rc.deliveredParts >= rc.totalParts) rc.status = SmsStatus.DELIVERED
                    }
                }
            }
        }
    }

    private fun errorText(code: Int): String = when (code) {
        SmsManager.RESULT_ERROR_GENERIC_FAILURE -> "generic failure"
        SmsManager.RESULT_ERROR_NO_SERVICE -> "no service"
        SmsManager.RESULT_ERROR_NULL_PDU -> "null PDU"
        SmsManager.RESULT_ERROR_RADIO_OFF -> "radio off"
        else -> "error $code"
    }
}
