package gr.aegis.protect.ui

import android.Manifest
import android.content.Intent
import android.os.Bundle
import android.provider.ContactsContract
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.Toast
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AlertDialog
import androidx.fragment.app.Fragment
import androidx.recyclerview.widget.LinearLayoutManager
import androidx.recyclerview.widget.RecyclerView
import gr.aegis.protect.R
import gr.aegis.protect.data.AlertRecord
import gr.aegis.protect.data.ContactStore
import gr.aegis.protect.data.EmergencyContact
import gr.aegis.protect.data.HistoryStore
import gr.aegis.protect.data.Prefs
import gr.aegis.protect.data.Profile
import gr.aegis.protect.data.Recipient
import gr.aegis.protect.data.SmsStatus
import gr.aegis.protect.databinding.DialogContactBinding
import gr.aegis.protect.databinding.FragmentContactsBinding
import gr.aegis.protect.databinding.ItemContactBinding
import gr.aegis.protect.location.LocationFinder
import gr.aegis.protect.sms.MessageBuilder
import gr.aegis.protect.sms.SmsSender
import gr.aegis.protect.util.Perms
import java.util.UUID

class ContactsFragment : Fragment() {

    private var _b: FragmentContactsBinding? = null
    private val b get() = _b!!
    private val items = mutableListOf<EmergencyContact>()
    private lateinit var adapter: ContactAdapter
    private var dialogBinding: DialogContactBinding? = null
    private var testFinder: LocationFinder? = null

    private val pickContact = registerForActivityResult(ActivityResultContracts.StartActivityForResult()) { result ->
        val uri = result.data?.data ?: return@registerForActivityResult
        val ctx = context ?: return@registerForActivityResult
        try {
            ctx.contentResolver.query(
                uri,
                arrayOf(ContactsContract.CommonDataKinds.Phone.NUMBER, ContactsContract.CommonDataKinds.Phone.DISPLAY_NAME),
                null, null, null
            )?.use { c ->
                if (c.moveToFirst()) {
                    val number = c.getString(0) ?: ""
                    val name = c.getString(1) ?: ""
                    dialogBinding?.edtName?.setText(name)
                    dialogBinding?.edtPhone?.setText(number)
                }
            }
        } catch (e: Exception) {
            Toast.makeText(ctx, e.message ?: "error", Toast.LENGTH_SHORT).show()
        }
    }

    private val smsPermission = registerForActivityResult(ActivityResultContracts.RequestPermission()) { }

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View {
        _b = FragmentContactsBinding.inflate(inflater, container, false)
        return b.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        b.txtDesc.text = getString(R.string.contacts_desc, ContactStore.MAX_CONTACTS)
        adapter = ContactAdapter(items, ::confirmDelete, ::confirmTest)
        b.recycler.layoutManager = LinearLayoutManager(requireContext())
        b.recycler.adapter = adapter
        b.fabAdd.setOnClickListener { showAddDialog() }
        reload()
    }

    override fun onDestroyView() {
        testFinder?.cancel()
        testFinder = null
        _b = null
        super.onDestroyView()
    }

    private fun reload() {
        items.clear()
        items.addAll(ContactStore.load(requireContext()))
        adapter.notifyDataSetChanged()
        b.txtEmpty.visibility = if (items.isEmpty()) View.VISIBLE else View.GONE
    }

    private fun showAddDialog() {
        val ctx = requireContext()
        if (items.size >= ContactStore.MAX_CONTACTS) {
            Toast.makeText(ctx, R.string.contacts_max, Toast.LENGTH_SHORT).show()
            return
        }
        val db = DialogContactBinding.inflate(layoutInflater)
        dialogBinding = db
        db.btnPick.setOnClickListener {
            try {
                pickContact.launch(Intent(Intent.ACTION_PICK, ContactsContract.CommonDataKinds.Phone.CONTENT_URI))
            } catch (e: Exception) {
                Toast.makeText(ctx, e.message ?: "error", Toast.LENGTH_SHORT).show()
            }
        }
        val dialog = AlertDialog.Builder(ctx)
            .setTitle(R.string.contacts_add)
            .setView(db.root)
            .setPositiveButton(R.string.contacts_save, null)
            .setNegativeButton(R.string.contacts_cancel, null)
            .create()
        dialog.setOnShowListener {
            dialog.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener {
                val name = db.edtName.text?.toString()?.trim().orEmpty()
                val phone = db.edtPhone.text?.toString()?.trim().orEmpty()
                if (name.isEmpty() || !ContactStore.isValidPhone(phone)) {
                    Toast.makeText(ctx, R.string.contacts_invalid, Toast.LENGTH_SHORT).show()
                    return@setOnClickListener
                }
                ContactStore.add(ctx, EmergencyContact(name = name, phone = ContactStore.normalizePhone(phone)))
                reload()
                dialog.dismiss()
            }
        }
        dialog.setOnDismissListener { dialogBinding = null }
        dialog.show()
    }

    private fun confirmDelete(contact: EmergencyContact) {
        AlertDialog.Builder(requireContext())
            .setMessage(getString(R.string.contacts_delete_confirm, contact.name))
            .setPositiveButton(R.string.delete) { _, _ ->
                ContactStore.remove(requireContext(), contact.id)
                reload()
            }
            .setNegativeButton(R.string.cancel, null)
            .show()
    }

    private fun confirmTest(contact: EmergencyContact) {
        val ctx = requireContext()
        if (!Perms.sms(ctx)) {
            smsPermission.launch(Manifest.permission.SEND_SMS)
            return
        }
        AlertDialog.Builder(ctx)
            .setTitle(R.string.contacts_test_sms)
            .setMessage(getString(R.string.contacts_test_confirm, contact.name))
            .setPositiveButton(R.string.yes) { _, _ -> sendTest(contact) }
            .setNegativeButton(R.string.cancel, null)
            .show()
    }

    private fun sendTest(contact: EmergencyContact) {
        val ctx = requireContext().applicationContext
        testFinder?.cancel()
        testFinder = LocationFinder(ctx).also { f ->
            f.start(15_000L) { result ->
                val text = ctx.getString(R.string.sms_test_prefix) + " " +
                    MessageBuilder.buildSafe(ctx, Profile.load(ctx), result.location, result.stale)
                val phone = ContactStore.normalizePhone(contact.phone)
                val rc = Recipient(phone, contact.name)
                val record = AlertRecord(
                    id = UUID.randomUUID().toString(), time = System.currentTimeMillis(), typeCode = "SAFE",
                    source = "test", lat = result.location?.latitude, lon = result.location?.longitude,
                    accuracy = result.location?.accuracy, stale = result.stale, message = text,
                    recipients = mutableListOf(rc)
                )
                val o = SmsSender.send(ctx, record.id, phone, text)
                if (o.started) rc.totalParts = o.parts else { rc.status = SmsStatus.FAILED; rc.error = o.error }
                HistoryStore.add(ctx, record)
                if (isAdded) Toast.makeText(ctx, getString(R.string.contacts_test_sent, contact.name), Toast.LENGTH_LONG).show()
            }
        }
    }

    private class ContactAdapter(
        private val items: List<EmergencyContact>,
        private val onDelete: (EmergencyContact) -> Unit,
        private val onTest: (EmergencyContact) -> Unit
    ) : RecyclerView.Adapter<ContactAdapter.VH>() {

        class VH(val b: ItemContactBinding) : RecyclerView.ViewHolder(b.root)

        override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): VH =
            VH(ItemContactBinding.inflate(LayoutInflater.from(parent.context), parent, false))

        override fun getItemCount(): Int = items.size

        override fun onBindViewHolder(holder: VH, position: Int) {
            val c = items[position]
            holder.b.txtName.text = c.name
            holder.b.txtPhone.text = c.phone
            holder.b.btnDelete.setOnClickListener { onDelete(c) }
            holder.b.btnTest.setOnClickListener { onTest(c) }
        }
    }
}
