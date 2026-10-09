package gr.aegis.protect

import android.app.Application
import gr.aegis.protect.util.Notifications

class AegisApp : Application() {
    override fun onCreate() {
        super.onCreate()
        Notifications.createChannels(this)
    }
}
