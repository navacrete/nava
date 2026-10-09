package gr.aegis.protect.location

import android.annotation.SuppressLint
import android.content.Context
import android.location.Location
import android.location.LocationListener
import android.location.LocationManager
import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import gr.aegis.protect.util.Perms

/**
 * Obtains a position using the platform LocationManager (no Play Services dependency,
 * so it works on every Android device). Listens to GPS, network and (API 31+) fused
 * providers at once and reports the best fix as soon as it is accurate enough, or the best
 * available fix when the deadline passes. Falls back to the last known location.
 */
class LocationFinder(context: Context) {

    data class Result(val location: Location?, val stale: Boolean)

    private val appContext = context.applicationContext
    private val lm = appContext.getSystemService(Context.LOCATION_SERVICE) as LocationManager
    private val handler = Handler(Looper.getMainLooper())
    private var best: Location? = null
    private var finished = false
    private var started = false
    private var goodAccuracy = 30f
    private var callback: ((Result) -> Unit)? = null
    private val deadlineRunnable = Runnable { finish() }

    private val listener = object : LocationListener {
        override fun onLocationChanged(location: Location) = consider(location)
        override fun onProviderEnabled(provider: String) {}
        override fun onProviderDisabled(provider: String) {}
        @Deprecated("Deprecated in Java")
        override fun onStatusChanged(provider: String?, status: Int, extras: Bundle?) {}
    }

    val current: Location? get() = best
    val isFinished: Boolean get() = finished

    /** Returns true if location updates could be requested. */
    @SuppressLint("MissingPermission")
    fun start(timeoutMs: Long, goodAccuracyMeters: Float = 30f, onResult: (Result) -> Unit): Boolean {
        if (started) return true
        started = true
        goodAccuracy = goodAccuracyMeters
        callback = onResult
        handler.postDelayed(deadlineRunnable, timeoutMs)

        if (!Perms.location(appContext)) {
            // No permission: report immediately so the caller can proceed without coordinates.
            handler.post { finish() }
            return false
        }

        LocationCache.fresh(60_000L)?.let { consider(it) }
        if (finished) return true

        var any = false
        providers().forEach { p ->
            try {
                lm.requestLocationUpdates(p, 1000L, 0f, listener, Looper.getMainLooper())
                any = true
            } catch (e: Exception) {
                // Provider not available on this device; ignore.
            }
        }
        if (!any) handler.post { finish() }
        return any
    }

    /** Moves the deadline so the caller can shorten (or extend) the wait. */
    fun setDeadline(timeoutMs: Long) {
        if (finished) return
        handler.removeCallbacks(deadlineRunnable)
        handler.postDelayed(deadlineRunnable, timeoutMs)
    }

    fun cancel() {
        if (finished) return
        finished = true
        handler.removeCallbacks(deadlineRunnable)
        try { lm.removeUpdates(listener) } catch (e: Exception) { }
        callback = null
    }

    private fun providers(): List<String> {
        val wanted = mutableListOf(LocationManager.GPS_PROVIDER, LocationManager.NETWORK_PROVIDER)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) wanted += LocationManager.FUSED_PROVIDER
        val all = try { lm.allProviders } catch (e: Exception) { emptyList<String>() }
        return wanted.filter { all.contains(it) }
    }

    private fun consider(loc: Location) {
        if (finished) return
        val b = best
        val better = b == null ||
            (loc.hasAccuracy() && (!b.hasAccuracy() || loc.accuracy <= b.accuracy)) ||
            (loc.time - b.time > 10_000L && (!loc.hasAccuracy() || loc.accuracy <= b.accuracy + 15f))
        if (better) {
            best = loc
            LocationCache.update(loc)
        }
        if (loc.hasAccuracy() && loc.accuracy <= goodAccuracy) finish()
    }

    @SuppressLint("MissingPermission")
    private fun finish() {
        if (finished) return
        finished = true
        handler.removeCallbacks(deadlineRunnable)
        try { lm.removeUpdates(listener) } catch (e: Exception) { }
        val result = best?.let { Result(it, false) } ?: lastKnown()?.let { Result(it, true) } ?: Result(null, true)
        val cb = callback
        callback = null
        cb?.invoke(result)
    }

    @SuppressLint("MissingPermission")
    private fun lastKnown(): Location? {
        if (!Perms.location(appContext)) return null
        var bestLast: Location? = LocationCache.latest
        providers().forEach { p ->
            val l = try { lm.getLastKnownLocation(p) } catch (e: Exception) { null } ?: return@forEach
            val cur = bestLast
            if (cur == null || l.time > cur.time) bestLast = l
        }
        return bestLast
    }
}

/** Keeps the most recent fix in memory so a new alert can start from it instantly. */
object LocationCache {
    @Volatile var latest: Location? = null
        private set

    fun update(loc: Location) {
        val cur = latest
        if (cur == null || loc.time >= cur.time) latest = loc
    }

    fun fresh(maxAgeMs: Long): Location? {
        val l = latest ?: return null
        return if (System.currentTimeMillis() - l.time <= maxAgeMs) l else null
    }
}
