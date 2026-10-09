package gr.aegis.protect.alert

import android.location.Location
import androidx.lifecycle.MutableLiveData
import gr.aegis.protect.model.EmergencyType

sealed class AlertState {
    object Idle : AlertState()
    data class Countdown(val secondsLeft: Int, val type: EmergencyType, val source: String) : AlertState()
    data class Locating(val type: EmergencyType, val source: String) : AlertState()
    data class Sending(val type: EmergencyType, val source: String) : AlertState()
    data class Done(
        val type: EmergencyType,
        val source: String,
        val recordId: String,
        val recipients: List<String>,
        val sentCount: Int,
        val location: Location?,
        val stale: Boolean
    ) : AlertState()
    /** No SEND_SMS permission: the messaging app is opened with everything pre-filled. */
    data class ManualSend(
        val type: EmergencyType,
        val source: String,
        val message112: String?,
        val messageContacts: String?,
        val contactPhones: List<String>,
        val location: Location?,
        val stale: Boolean
    ) : AlertState()
    data class Cancelled(val type: EmergencyType) : AlertState()
    data class Failed(val type: EmergencyType, val reason: Reason) : AlertState()

    enum class Reason { NO_SMS_PERMISSION, NO_RECIPIENTS, SEND_ERROR }

    val isActive: Boolean
        get() = this is Countdown || this is Locating || this is Sending
}

/** In-process bridge between [AlertService] and the screens observing it. */
object AlertBus {
    val state = MutableLiveData<AlertState>(AlertState.Idle)
}
