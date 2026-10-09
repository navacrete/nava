package gr.aegis.protect.ui

import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.ArrayAdapter
import android.widget.Toast
import androidx.core.widget.doAfterTextChanged
import androidx.fragment.app.Fragment
import gr.aegis.protect.R
import gr.aegis.protect.data.Prefs
import gr.aegis.protect.data.Profile
import gr.aegis.protect.databinding.FragmentProfileBinding
import gr.aegis.protect.location.LocationCache
import gr.aegis.protect.model.EmergencyType
import gr.aegis.protect.sms.MessageBuilder

class ProfileFragment : Fragment() {

    private var _b: FragmentProfileBinding? = null
    private val b get() = _b!!

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View {
        _b = FragmentProfileBinding.inflate(inflater, container, false)
        return b.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        val ctx = requireContext()
        val bloodTypes = resources.getStringArray(R.array.blood_types)
        b.actBlood.setAdapter(ArrayAdapter(ctx, android.R.layout.simple_list_item_1, bloodTypes))

        val p = Profile.load(ctx)
        b.edtName.setText(p.name)
        b.actBlood.setText(p.bloodType.ifBlank { "-" }, false)
        b.edtAllergies.setText(p.allergies)
        b.edtMedications.setText(p.medications)
        b.edtConditions.setText(p.conditions)
        b.edtNotes.setText(p.notes)

        listOf(b.edtName, b.edtAllergies, b.edtMedications, b.edtConditions, b.edtNotes).forEach { e ->
            e.doAfterTextChanged { renderPreview() }
        }
        b.actBlood.setOnItemClickListener { _, _, _, _ -> renderPreview() }

        b.btnSave.setOnClickListener {
            current().save(ctx)
            Toast.makeText(ctx, R.string.profile_saved, Toast.LENGTH_SHORT).show()
            renderPreview()
        }
        renderPreview()
    }

    override fun onPause() {
        // Save silently so nothing is lost when switching tabs.
        context?.let { current().save(it) }
        super.onPause()
    }

    override fun onDestroyView() {
        _b = null
        super.onDestroyView()
    }

    private fun current(): Profile = Profile(
        name = b.edtName.text?.toString()?.trim().orEmpty(),
        bloodType = b.actBlood.text?.toString()?.trim().orEmpty().let { if (it == "-") "" else it },
        allergies = b.edtAllergies.text?.toString()?.trim().orEmpty(),
        medications = b.edtMedications.text?.toString()?.trim().orEmpty(),
        conditions = b.edtConditions.text?.toString()?.trim().orEmpty(),
        notes = b.edtNotes.text?.toString()?.trim().orEmpty()
    )

    private fun renderPreview() {
        val ctx = context ?: return
        val prefs = Prefs(ctx)
        val profile = current()
        val type = EmergencyType.fromCode(prefs.lastTypeCode)
        val loc = LocationCache.latest
        b.txtPreview112.text = MessageBuilder.build112(ctx, type, profile, loc, false, null, prefs.includeName112)
        b.txtPreviewContacts.text = MessageBuilder.buildContacts(ctx, type, profile, loc, false, null, prefs.includeMedical, prefs.send112)
    }
}
