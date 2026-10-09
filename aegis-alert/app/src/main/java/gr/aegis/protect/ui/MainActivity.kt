package gr.aegis.protect.ui

import android.os.Bundle
import androidx.appcompat.app.AppCompatActivity
import androidx.fragment.app.Fragment
import androidx.fragment.app.commit
import gr.aegis.protect.R
import gr.aegis.protect.databinding.ActivityMainBinding

class MainActivity : AppCompatActivity() {

    private lateinit var b: ActivityMainBinding

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        b = ActivityMainBinding.inflate(layoutInflater)
        setContentView(b.root)

        b.bottomNav.setOnItemSelectedListener { item ->
            val tag = when (item.itemId) {
                R.id.nav_contacts -> "contacts"
                R.id.nav_profile -> "profile"
                R.id.nav_history -> "history"
                R.id.nav_settings -> "settings"
                else -> "sos"
            }
            show(tag)
            true
        }
        if (savedInstanceState == null) {
            b.bottomNav.selectedItemId = R.id.nav_sos
        }
    }

    private fun show(tag: String) {
        val existing = supportFragmentManager.findFragmentByTag(tag)
        val fragment: Fragment = existing ?: when (tag) {
            "contacts" -> ContactsFragment()
            "profile" -> ProfileFragment()
            "history" -> HistoryFragment()
            "settings" -> SettingsFragment()
            else -> SosFragment()
        }
        supportFragmentManager.commit {
            setReorderingAllowed(true)
            replace(R.id.container, fragment, tag)
        }
    }
}
