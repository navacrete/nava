package gr.aegis.protect.model

import androidx.annotation.ColorRes
import androidx.annotation.DrawableRes
import androidx.annotation.StringRes
import gr.aegis.protect.R

enum class EmergencyType(
    val code: String,
    @StringRes val labelRes: Int,
    @StringRes val smsRes: Int,
    @DrawableRes val iconRes: Int,
    @ColorRes val colorRes: Int
) {
    GENERAL("SOS", R.string.type_general, R.string.type_general_sms, R.drawable.ic_sos, R.color.sos_red),
    MEDICAL("MED", R.string.type_medical, R.string.type_medical_sms, R.drawable.ic_medical, R.color.green),
    FIRE("FIRE", R.string.type_fire, R.string.type_fire_sms, R.drawable.ic_fire, R.color.orange),
    POLICE("POL", R.string.type_police, R.string.type_police_sms, R.drawable.ic_police, R.color.blue),
    THREAT("THR", R.string.type_threat, R.string.type_threat_sms, R.drawable.ic_threat, R.color.purple),
    ACCIDENT("ACC", R.string.type_accident, R.string.type_accident_sms, R.drawable.ic_car, R.color.amber);

    companion object {
        fun fromCode(code: String?): EmergencyType = entries.firstOrNull { it.code == code } ?: GENERAL
    }
}
