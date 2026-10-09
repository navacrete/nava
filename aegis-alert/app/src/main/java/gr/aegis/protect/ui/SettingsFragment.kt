package gr.aegis.protect.ui

import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.provider.Settings
import android.text.InputType
import androidx.appcompat.app.AlertDialog
import androidx.preference.EditTextPreference
import androidx.preference.Preference
import androidx.preference.PreferenceFragmentCompat
import gr.aegis.protect.R
import gr.aegis.protect.data.Prefs

class SettingsFragment : PreferenceFragmentCompat() {

    override fun onCreatePreferences(savedInstanceState: Bundle?, rootKey: String?) {
        setPreferencesFromResource(R.xml.preferences, rootKey)

        findPreference<EditTextPreference>(Prefs.KEY_TEST_NUMBER)?.setOnBindEditTextListener { edit ->
            edit.inputType = InputType.TYPE_CLASS_PHONE
        }

        findPreference<Preference>("battery")?.setOnPreferenceClickListener {
            val ctx = requireContext()
            try {
                @Suppress("BatteryLife")
                startActivity(Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS, Uri.parse("package:${ctx.packageName}")))
            } catch (e: Exception) {
                try { startActivity(Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS)) } catch (ignored: Exception) { }
            }
            true
        }

        findPreference<Preference>("app_info")?.setOnPreferenceClickListener {
            val ctx = requireContext()
            try {
                startActivity(Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.parse("package:${ctx.packageName}")))
            } catch (ignored: Exception) { }
            true
        }

        findPreference<Preference>("about")?.let { about ->
            val version = try {
                requireContext().packageManager.getPackageInfo(requireContext().packageName, 0).versionName ?: ""
            } catch (e: Exception) { "" }
            about.summary = getString(R.string.settings_about_sum, version)
            about.setOnPreferenceClickListener {
                AlertDialog.Builder(requireContext())
                    .setTitle(R.string.app_name)
                    .setMessage(R.string.settings_about_text)
                    .setPositiveButton(R.string.ok, null)
                    .show()
                true
            }
        }
    }
}
