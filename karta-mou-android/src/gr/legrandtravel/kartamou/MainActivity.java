package gr.legrandtravel.kartamou;

import android.app.Activity;
import android.app.AlertDialog;
import android.content.SharedPreferences;
import android.graphics.Color;
import android.graphics.Typeface;
import android.os.Bundle;
import android.text.InputType;
import android.util.TypedValue;
import android.view.Gravity;
import android.view.View;
import android.widget.Button;
import android.widget.EditText;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;
import android.widget.Toast;

import java.text.SimpleDateFormat;
import java.util.Date;
import java.util.Locale;
import java.util.TimeZone;

/**
 * «Η Κάρτα Μου»: two buttons (Προσέλευση / Αποχώρηση) that submit the digital work card punch
 * through eVardia exactly like its «Αποστολή από Camera» page, for ONE ΑΦΜ, protected by a PIN.
 */
public class MainActivity extends Activity {

    private static final String PREFS = "kartamou";
    private SharedPreferences prefs;
    private TextView status, result, history;
    private Button inBtn, outBtn;
    private boolean busy = false;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        prefs = getSharedPreferences(PREFS, MODE_PRIVATE);
        setContentView(buildUi());
        refreshStatus();
        if (prefs.getString("afm", "").isEmpty() || prefs.getString("username", "").isEmpty()) {
            showSettings();
        }
    }

    /* ---------------- UI ---------------- */

    private int dp(int v) {
        return (int) TypedValue.applyDimension(TypedValue.COMPLEX_UNIT_DIP, v, getResources().getDisplayMetrics());
    }

    private View buildUi() {
        ScrollView sv = new ScrollView(this);
        sv.setBackgroundColor(Color.parseColor("#1f2a44"));
        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setPadding(dp(20), dp(28), dp(20), dp(28));
        root.setGravity(Gravity.CENTER_HORIZONTAL);
        sv.addView(root);

        TextView title = new TextView(this);
        title.setText("Η Κάρτα Μου");
        title.setTextColor(Color.WHITE);
        title.setTextSize(26);
        title.setTypeface(null, Typeface.BOLD);
        title.setGravity(Gravity.CENTER);
        root.addView(title);

        status = new TextView(this);
        status.setTextColor(Color.parseColor("#c8d0e0"));
        status.setTextSize(15);
        status.setGravity(Gravity.CENTER);
        status.setPadding(0, dp(6), 0, dp(22));
        root.addView(status);

        inBtn = bigButton("ΠΡΟΣΕΛΕΥΣΗ", "#049e9c");
        inBtn.setOnClickListener(new View.OnClickListener() {
            @Override public void onClick(View v) { askPinThen("0"); }
        });
        root.addView(inBtn);

        outBtn = bigButton("ΑΠΟΧΩΡΗΣΗ", "#6a4ad6");
        outBtn.setOnClickListener(new View.OnClickListener() {
            @Override public void onClick(View v) { askPinThen("1"); }
        });
        root.addView(outBtn);

        result = new TextView(this);
        result.setTextColor(Color.WHITE);
        result.setTextSize(18);
        result.setGravity(Gravity.CENTER);
        result.setPadding(0, dp(22), 0, dp(10));
        root.addView(result);

        history = new TextView(this);
        history.setTextColor(Color.parseColor("#9aa6bf"));
        history.setTextSize(13);
        history.setGravity(Gravity.CENTER);
        root.addView(history);

        Button settings = new Button(this);
        settings.setText("Ρυθμίσεις");
        settings.setAllCaps(false);
        settings.setOnClickListener(new View.OnClickListener() {
            @Override public void onClick(View v) { showSettings(); }
        });
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(LinearLayout.LayoutParams.WRAP_CONTENT, LinearLayout.LayoutParams.WRAP_CONTENT);
        lp.topMargin = dp(28);
        settings.setLayoutParams(lp);
        root.addView(settings);

        TextView note = new TextView(this);
        note.setText("Το χτύπημα καταχωρείται με την ώρα που πατάς το κουμπί, όπως στο σκανάρισμα. Πάτα το μόνο τη στιγμή της προσέλευσης ή αποχώρησης.");
        note.setTextColor(Color.parseColor("#7f8aa6"));
        note.setTextSize(12);
        note.setGravity(Gravity.CENTER);
        note.setPadding(0, dp(18), 0, 0);
        root.addView(note);
        return sv;
    }

    private Button bigButton(String text, String color) {
        Button b = new Button(this);
        b.setText(text);
        b.setTextSize(22);
        b.setTypeface(null, Typeface.BOLD);
        b.setTextColor(Color.WHITE);
        b.setBackgroundColor(Color.parseColor(color));
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, dp(110));
        lp.bottomMargin = dp(16);
        b.setLayoutParams(lp);
        return b;
    }

    private void refreshStatus() {
        String afm = prefs.getString("afm", "");
        String name = prefs.getString("name", "");
        status.setText((name.isEmpty() ? "" : name + "\n") + (afm.isEmpty() ? "Δεν έχει οριστεί ΑΦΜ – πάτα Ρυθμίσεις" : "ΑΦΜ " + afm));
        history.setText(prefs.getString("history", ""));
        String last = prefs.getString("last_result", "");
        if (!last.isEmpty()) result.setText(last);
    }

    /* ---------------- PIN + confirm + send ---------------- */

    private void askPinThen(final String type) {
        if (busy) return;
        if (prefs.getString("afm", "").isEmpty()) { showSettings(); return; }
        final String pin = prefs.getString("pin", "");
        if (pin.isEmpty()) { confirmThen(type); return; }
        final EditText et = new EditText(this);
        et.setInputType(InputType.TYPE_CLASS_NUMBER | InputType.TYPE_NUMBER_VARIATION_PASSWORD);
        et.setHint("PIN");
        new AlertDialog.Builder(this)
                .setTitle("PIN")
                .setView(et)
                .setPositiveButton("OK", new android.content.DialogInterface.OnClickListener() {
                    @Override public void onClick(android.content.DialogInterface d, int w) {
                        if (et.getText().toString().trim().equals(pin)) confirmThen(type);
                        else Toast.makeText(MainActivity.this, "Λάθος PIN", Toast.LENGTH_SHORT).show();
                    }
                })
                .setNegativeButton("Άκυρο", null)
                .show();
    }

    private void confirmThen(final String type) {
        String what = "0".equals(type) ? "ΠΡΟΣΕΛΕΥΣΗ" : "ΑΠΟΧΩΡΗΣΗ";
        new AlertDialog.Builder(this)
                .setTitle(what + " τώρα " + now() + ";")
                .setMessage("Θα σταλεί στο eVardia / ΕΡΓΑΝΗ για ΑΦΜ " + prefs.getString("afm", "") + ".")
                .setPositiveButton("ΝΑΙ, στείλε", new android.content.DialogInterface.OnClickListener() {
                    @Override public void onClick(android.content.DialogInterface d, int w) { send(type); }
                })
                .setNegativeButton("Άκυρο", null)
                .show();
    }

    private void setBusy(boolean b) {
        busy = b;
        inBtn.setEnabled(!b);
        outBtn.setEnabled(!b);
        inBtn.setAlpha(b ? 0.5f : 1f);
        outBtn.setAlpha(b ? 0.5f : 1f);
    }

    private void send(final String type) {
        setBusy(true);
        result.setTextColor(Color.WHITE);
        result.setText("Αποστολή…");
        final String base = prefs.getString("base_url", "https://evardia.gr");
        final String user = prefs.getString("username", "");
        final String pass = prefs.getString("password", "");
        final String afm = prefs.getString("afm", "");
        final String serial = prefs.getString("serial", "36122");
        final String etaireia = prefs.getString("etaireia", "124");
        new Thread(new Runnable() {
            @Override public void run() {
                String out; boolean ok = false; String name = "";
                try {
                    EvardiaClient c = new EvardiaClient(base, user, pass);
                    String err = c.ensureLogin();
                    if (err != null) throw new Exception(err);
                    try {
                        name = c.lookupName(etaireia, afm);
                    } catch (Exception e401) {
                        if ("401".equals(e401.getMessage())) {
                            EvardiaClient.forgetSession();
                            err = c.ensureLogin();
                            if (err != null) throw new Exception(err);
                            name = c.lookupName(etaireia, afm);
                        } else {
                            throw e401;
                        }
                    }
                    if (name == null) throw new Exception("Ο εργαζόμενος δεν βρέθηκε στο σύστημα (ΑΦΜ " + afm + ").");
                    EvardiaClient.Result r = c.dhlosh(serial, afm, type, name);
                    ok = r.ok;
                    out = (ok ? "✓ " : "✗ ") + r.message + " " + name + " " + now();
                } catch (Exception e) {
                    out = "✗ " + (e.getMessage() == null ? e.toString() : e.getMessage());
                }
                final String fo = out; final boolean fok = ok; final String fname = name == null ? "" : name;
                runOnUiThread(new Runnable() {
                    @Override public void run() {
                        result.setTextColor(fok ? Color.parseColor("#7CFC9A") : Color.parseColor("#FF8A80"));
                        result.setText(fo);
                        SharedPreferences.Editor ed = prefs.edit();
                        ed.putString("last_result", fo);
                        if (!fname.isEmpty()) ed.putString("name", fname);
                        String h = prefs.getString("history", "");
                        String line = today() + " " + ("0".equals(type) ? "Προσέλευση" : "Αποχώρηση") + " " + now() + (fok ? " ✓" : " ✗");
                        String[] lines = (line + "\n" + h).split("\n");
                        StringBuilder sb = new StringBuilder();
                        for (int i = 0; i < Math.min(lines.length, 8); i++) { if (i > 0) sb.append('\n'); sb.append(lines[i]); }
                        ed.putString("history", sb.toString());
                        ed.apply();
                        refreshStatus();
                        setBusy(false);
                    }
                });
            }
        }).start();
    }

    private static String now() {
        SimpleDateFormat f = new SimpleDateFormat("HH:mm", Locale.getDefault());
        f.setTimeZone(TimeZone.getTimeZone("Europe/Athens"));
        return f.format(new Date());
    }

    private static String today() {
        SimpleDateFormat f = new SimpleDateFormat("dd/MM", Locale.getDefault());
        f.setTimeZone(TimeZone.getTimeZone("Europe/Athens"));
        return f.format(new Date());
    }

    /* ---------------- settings ---------------- */

    private void showSettings() {
        LinearLayout box = new LinearLayout(this);
        box.setOrientation(LinearLayout.VERTICAL);
        box.setPadding(dp(20), dp(10), dp(20), 0);
        final EditText user = field(box, "Όνομα χρήστη eVardia (σκάνερ)", prefs.getString("username", ""), InputType.TYPE_CLASS_TEXT);
        final EditText pass = field(box, "Κωδικός eVardia (σκάνερ)", prefs.getString("password", ""), InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_PASSWORD);
        final EditText afm = field(box, "ΑΦΜ σου (9 ψηφία)", prefs.getString("afm", ""), InputType.TYPE_CLASS_NUMBER);
        final EditText pin = field(box, "PIN εφαρμογής (προαιρετικό)", prefs.getString("pin", ""), InputType.TYPE_CLASS_NUMBER);
        final EditText serial = field(box, "Αριθμός συσκευής σκάνερ (serialNumber)", prefs.getString("serial", "36122"), InputType.TYPE_CLASS_TEXT);
        final EditText etaireia = field(box, "Κωδικός εταιρείας (etaireiaId)", prefs.getString("etaireia", "124"), InputType.TYPE_CLASS_NUMBER);
        final EditText base = field(box, "Διεύθυνση eVardia", prefs.getString("base_url", "https://evardia.gr"), InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_URI);
        ScrollView sv = new ScrollView(this);
        sv.addView(box);
        new AlertDialog.Builder(this)
                .setTitle("Ρυθμίσεις")
                .setView(sv)
                .setPositiveButton("Αποθήκευση", new android.content.DialogInterface.OnClickListener() {
                    @Override public void onClick(android.content.DialogInterface d, int w) {
                        prefs.edit()
                                .putString("username", user.getText().toString().trim())
                                .putString("password", pass.getText().toString())
                                .putString("afm", afm.getText().toString().replaceAll("\\D", ""))
                                .putString("pin", pin.getText().toString().trim())
                                .putString("serial", serial.getText().toString().trim())
                                .putString("etaireia", etaireia.getText().toString().trim())
                                .putString("base_url", base.getText().toString().trim())
                                .apply();
                        EvardiaClient.forgetSession();
                        refreshStatus();
                        Toast.makeText(MainActivity.this, "Αποθηκεύτηκαν", Toast.LENGTH_SHORT).show();
                    }
                })
                .setNegativeButton("Άκυρο", null)
                .show();
    }

    private EditText field(LinearLayout box, String label, String value, int inputType) {
        TextView tv = new TextView(this);
        tv.setText(label);
        tv.setPadding(0, dp(8), 0, 0);
        box.addView(tv);
        EditText et = new EditText(this);
        et.setInputType(inputType);
        et.setText(value);
        box.addView(et);
        return et;
    }
}
