package gr.aegis.protect.ui

import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.appcompat.app.AlertDialog
import androidx.core.content.ContextCompat
import androidx.fragment.app.Fragment
import androidx.recyclerview.widget.LinearLayoutManager
import androidx.recyclerview.widget.RecyclerView
import gr.aegis.protect.R
import gr.aegis.protect.data.AlertRecord
import gr.aegis.protect.data.HistoryStore
import gr.aegis.protect.data.SmsStatus
import gr.aegis.protect.databinding.FragmentHistoryBinding
import gr.aegis.protect.databinding.ItemHistoryBinding
import gr.aegis.protect.model.EmergencyType
import gr.aegis.protect.sms.MessageBuilder
import java.text.DateFormat
import java.util.Date

class HistoryFragment : Fragment() {

    private var _b: FragmentHistoryBinding? = null
    private val b get() = _b!!
    private val items = mutableListOf<AlertRecord>()
    private lateinit var adapter: HistoryAdapter

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View {
        _b = FragmentHistoryBinding.inflate(inflater, container, false)
        return b.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        adapter = HistoryAdapter(items)
        b.recycler.layoutManager = LinearLayoutManager(requireContext())
        b.recycler.adapter = adapter
        b.btnClear.setOnClickListener {
            AlertDialog.Builder(requireContext())
                .setMessage(R.string.history_clear_confirm)
                .setPositiveButton(R.string.delete) { _, _ -> HistoryStore.clear(requireContext()) }
                .setNegativeButton(R.string.cancel, null)
                .show()
        }
        HistoryStore.changed.observe(viewLifecycleOwner) { reload() }
        reload()
    }

    override fun onDestroyView() {
        _b = null
        super.onDestroyView()
    }

    private fun reload() {
        val bb = _b ?: return
        items.clear()
        items.addAll(HistoryStore.load(requireContext()))
        adapter.notifyDataSetChanged()
        bb.txtEmpty.visibility = if (items.isEmpty()) View.VISIBLE else View.GONE
    }

    private class HistoryAdapter(private val items: List<AlertRecord>) : RecyclerView.Adapter<HistoryAdapter.VH>() {

        class VH(val b: ItemHistoryBinding) : RecyclerView.ViewHolder(b.root)

        override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): VH =
            VH(ItemHistoryBinding.inflate(LayoutInflater.from(parent.context), parent, false))

        override fun getItemCount(): Int = items.size

        override fun onBindViewHolder(holder: VH, position: Int) {
            val ctx = holder.b.root.context
            val r = items[position]
            val typeLabel: String
            val icon: Int
            val color: Int
            when (r.typeCode) {
                "SAFE" -> { typeLabel = ctx.getString(R.string.sos_im_safe); icon = R.drawable.ic_check; color = R.color.green }
                "TRACK" -> { typeLabel = ctx.getString(R.string.tracking_title); icon = R.drawable.ic_track; color = R.color.gold }
                else -> {
                    val t = EmergencyType.fromCode(r.typeCode)
                    typeLabel = ctx.getString(t.labelRes); icon = t.iconRes; color = t.colorRes
                }
            }
            val sourceLabel = when (r.source) {
                "checkin" -> R.string.history_source_checkin
                "tile" -> R.string.history_source_tile
                "shortcut" -> R.string.history_source_shortcut
                "safe" -> R.string.history_source_safe
                "tracking" -> R.string.history_source_tracking
                "test" -> R.string.history_source_test
                else -> R.string.history_source_manual
            }
            holder.b.txtType.text = typeLabel + " · " + ctx.getString(sourceLabel)
            holder.b.imgType.setImageResource(icon)
            holder.b.imgType.setColorFilter(ContextCompat.getColor(ctx, color))
            holder.b.txtTime.text = DateFormat.getDateTimeInstance(DateFormat.MEDIUM, DateFormat.SHORT).format(Date(r.time))

            val lat = r.lat
            val lon = r.lon
            if (lat != null && lon != null) {
                val acc = r.accuracy?.toInt()?.coerceAtLeast(1)
                holder.b.txtLocation.text = MessageBuilder.coords(lat, lon) +
                    (if (acc != null) " (±${acc}m)" else "") +
                    (if (r.stale) " · " + ctx.getString(R.string.history_stale) else "")
                holder.b.btnMap.visibility = View.VISIBLE
                holder.b.btnMap.setOnClickListener {
                    val intent = Intent(Intent.ACTION_VIEW, Uri.parse(MessageBuilder.mapsLink(lat, lon)))
                    try { ctx.startActivity(intent) } catch (e: Exception) { }
                }
            } else {
                holder.b.txtLocation.text = ctx.getString(R.string.history_no_location)
                holder.b.btnMap.visibility = View.GONE
            }

            holder.b.txtRecipients.text = r.recipients.joinToString("\n") { rc ->
                val status = when (rc.status) {
                    SmsStatus.PENDING -> ctx.getString(R.string.history_status_pending)
                    SmsStatus.SENT -> ctx.getString(R.string.history_status_sent)
                    SmsStatus.DELIVERED -> ctx.getString(R.string.history_status_delivered)
                    SmsStatus.FAILED -> ctx.getString(R.string.history_status_failed) + (rc.error?.let { " ($it)" } ?: "")
                    SmsStatus.MANUAL -> ctx.getString(R.string.history_status_manual)
                }
                "${rc.name} (${rc.phone}): $status"
            }
        }
    }
}
