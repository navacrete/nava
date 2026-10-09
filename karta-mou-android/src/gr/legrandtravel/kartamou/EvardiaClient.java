package gr.legrandtravel.kartamou;

import java.io.BufferedReader;
import java.io.InputStream;
import java.io.InputStreamReader;
import java.io.OutputStream;
import java.net.CookieHandler;
import java.net.CookieManager;
import java.net.CookiePolicy;
import java.net.HttpURLConnection;
import java.net.URL;
import java.net.URLEncoder;
import java.nio.charset.StandardCharsets;
import java.util.LinkedHashMap;
import java.util.Map;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

/**
 * Minimal eVardia client that does exactly what the «Αποστολή από Camera» page does:
 *  1. login with the scanner credentials (session cookie),
 *  2. GET /Ergazomenos/GetErgazomenosByAfm (name check),
 *  3. POST /Ergazomenos/Dhlosh {serialNumber, afmErgazomenou, TyposDhloshs, progr:"Camera"}.
 * No other endpoint is ever called.
 */
public class EvardiaClient {

    public static final String UA = "Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Mobile Safari/537.36";

    public static class Result {
        public boolean ok;
        public String message;
        public String name;
        public Result(boolean ok, String message, String name) { this.ok = ok; this.message = message; this.name = name; }
    }

    private final String base;
    private final String username;
    private final String password;
    private static boolean cookiesInstalled = false;

    public EvardiaClient(String base, String username, String password) {
        this.base = base.replaceAll("/+$", "");
        this.username = username;
        this.password = password;
        synchronized (EvardiaClient.class) {
            if (!cookiesInstalled) {
                CookieHandler.setDefault(new CookieManager(null, CookiePolicy.ACCEPT_ALL));
                cookiesInstalled = true;
            }
        }
    }

    public static void forgetSession() {
        CookieHandler.setDefault(new CookieManager(null, CookiePolicy.ACCEPT_ALL));
        cookiesInstalled = true;
    }

    /* ---------------- HTTP helpers ---------------- */

    private static class Resp {
        int code; String body; String location;
    }

    private Resp request(String method, String url, String body, String contentType, Map<String, String> headers, boolean follow) throws Exception {
        HttpURLConnection c = (HttpURLConnection) new URL(url).openConnection();
        c.setInstanceFollowRedirects(follow);
        c.setConnectTimeout(20000);
        c.setReadTimeout(40000);
        c.setRequestMethod(method);
        c.setRequestProperty("User-Agent", UA);
        c.setRequestProperty("Accept-Language", "el-GR,el;q=0.9");
        if (headers != null) for (Map.Entry<String, String> h : headers.entrySet()) c.setRequestProperty(h.getKey(), h.getValue());
        if (body != null) {
            c.setDoOutput(true);
            c.setRequestProperty("Content-Type", contentType);
            byte[] b = body.getBytes(StandardCharsets.UTF_8);
            c.setFixedLengthStreamingMode(b.length);
            try (OutputStream os = c.getOutputStream()) { os.write(b); }
        }
        Resp r = new Resp();
        r.code = c.getResponseCode();
        r.location = c.getHeaderField("Location");
        InputStream is = r.code >= 400 ? c.getErrorStream() : c.getInputStream();
        StringBuilder sb = new StringBuilder();
        if (is != null) {
            try (BufferedReader br = new BufferedReader(new InputStreamReader(is, StandardCharsets.UTF_8))) {
                char[] buf = new char[8192]; int n;
                while ((n = br.read(buf)) > 0) sb.append(buf, 0, n);
            }
        }
        r.body = sb.toString();
        c.disconnect();
        return r;
    }

    private static boolean looksLikeLogin(String html) {
        return html != null && Pattern.compile("<input[^>]+type=[\"']?password", Pattern.CASE_INSENSITIVE).matcher(html).find();
    }

    private String abs(String href, String pageUrl) throws Exception {
        if (href.matches("(?i)^https?://.*")) return href;
        URL p = new URL(pageUrl);
        String root = p.getProtocol() + "://" + p.getHost() + (p.getPort() > 0 ? ":" + p.getPort() : "");
        if (href.startsWith("/")) return root + href;
        String path = p.getPath() == null ? "/" : p.getPath().replaceAll("/[^/]*$", "/");
        return root + path + href;
    }

    /* ---------------- login ---------------- */

    /** Ensures a logged-in session. Returns null on success or an error message. */
    public String ensureLogin() throws Exception {
        String page = base + "/Ergazomenos/ApostolhCamera";
        Resp r = request("GET", page, null, null, null, true);
        if (r.code == 200 && !looksLikeLogin(r.body)) return null; // already logged in
        if (username.isEmpty() || password.isEmpty()) return "Λείπουν όνομα χρήστη / κωδικός eVardia (Ρυθμίσεις).";
        String html = r.body;
        // Find the form with the password field, copy hidden fields, detect field names.
        Matcher fm = Pattern.compile("(?is)<form\\b([^>]*)>(.*?)</form>").matcher(html);
        String action = page; Map<String, String> fields = new LinkedHashMap<>(); String uf = "", pf = "";
        while (fm.find()) {
            String attrs = fm.group(1), inner = fm.group(2);
            if (!looksLikeLogin(inner)) continue;
            Matcher am = Pattern.compile("(?i)action=[\"']([^\"']*)[\"']").matcher(attrs);
            if (am.find() && !am.group(1).isEmpty()) action = abs(am.group(1).replace("&amp;", "&"), page);
            Matcher im = Pattern.compile("(?is)<input\\b[^>]*>").matcher(inner);
            while (im.find()) {
                String tag = im.group();
                String name = attr(tag, "name"), type = attr(tag, "type").toLowerCase(), value = attr(tag, "value");
                if (name.isEmpty()) continue;
                if (type.equals("password")) { if (pf.isEmpty()) pf = name; continue; }
                if ((type.equals("text") || type.equals("email") || type.isEmpty()) && uf.isEmpty()) { uf = name; continue; }
                if (type.equals("submit") || type.equals("button") || type.equals("image") || type.equals("reset")) continue;
                if (type.equals("checkbox") && !tag.toLowerCase().contains("checked")) continue;
                fields.put(name, value);
            }
            break;
        }
        if (uf.isEmpty()) uf = "username";
        if (pf.isEmpty()) pf = "password";
        fields.put(uf, username);
        fields.put(pf, password);
        StringBuilder form = new StringBuilder();
        for (Map.Entry<String, String> e : fields.entrySet()) {
            if (form.length() > 0) form.append('&');
            form.append(URLEncoder.encode(e.getKey(), "UTF-8")).append('=').append(URLEncoder.encode(e.getValue(), "UTF-8"));
        }
        Map<String, String> h = new LinkedHashMap<>();
        h.put("Referer", page);
        Resp p = request("POST", action, form.toString(), "application/x-www-form-urlencoded", h, false);
        if (p.code >= 300 && p.code < 400 && p.location != null) {
            request("GET", abs(p.location, action), null, null, null, true);
        }
        Resp check = request("GET", page, null, null, null, true);
        if (check.code == 200 && !looksLikeLogin(check.body)) return null;
        return "Η σύνδεση στο eVardia απέτυχε. Ελέγξτε όνομα χρήστη/κωδικό σκάνερ.";
    }

    private static String attr(String tag, String name) {
        Matcher m = Pattern.compile("(?i)\\b" + name + "\\s*=\\s*(\"([^\"]*)\"|'([^']*)'|([^\\s>]+))").matcher(tag);
        if (!m.find()) return "";
        return m.group(2) != null ? m.group(2) : (m.group(3) != null ? m.group(3) : m.group(4));
    }

    /* ---------------- the two calls of the camera page ---------------- */

    /** GET /Ergazomenos/GetErgazomenosByAfm -> employee name, or null if not found. */
    public String lookupName(String etaireiaId, String afm) throws Exception {
        String url = base + "/Ergazomenos/GetErgazomenosByAfm?etaireiaId=" + URLEncoder.encode(etaireiaId, "UTF-8") + "&afm=" + URLEncoder.encode(afm, "UTF-8");
        Map<String, String> h = new LinkedHashMap<>();
        h.put("X-Requested-With", "XMLHttpRequest");
        h.put("Accept", "application/json, text/javascript, */*; q=0.01");
        h.put("Referer", base + "/Ergazomenos/ApostolhCamera");
        Resp r = request("GET", url, null, null, h, true);
        if (r.code == 401) throw new Exception("401");
        if (r.code != 200) return null;
        String n = jsonString(r.body, "onomateponymo");
        return n == null || n.isEmpty() ? null : n;
    }

    /** POST /Ergazomenos/Dhlosh exactly like the page. type: "0" = προσέλευση, "1" = αποχώρηση. */
    public Result dhlosh(String serialNumber, String afm, String type, String name) throws Exception {
        String json = "{\"serialNumber\":" + jsonQuote(serialNumber) + ",\"afmErgazomenou\":" + jsonQuote(afm)
                + ",\"TyposDhloshs\":" + jsonQuote(type) + ",\"progr\":\"Camera\"}";
        Map<String, String> h = new LinkedHashMap<>();
        h.put("X-Requested-With", "XMLHttpRequest");
        h.put("Accept", "application/json, text/javascript, */*; q=0.01");
        h.put("Referer", base + "/Ergazomenos/ApostolhCamera");
        h.put("Origin", base);
        Resp r = request("POST", base + "/Ergazomenos/Dhlosh", json, "application/json; charset=UTF-8", h, true);
        if (r.code == 401) return new Result(false, "Σφάλμα σύνδεσης με ΕΡΓΑΝΗ.", name);
        String msg = jsonString(r.body, "message");
        if (r.code >= 200 && r.code < 300) {
            if (msg == null || msg.isEmpty()) return new Result(false, "Παρακαλώ προσπαθήστε πάλι σε 2 λεπτά.", name);
            return new Result(true, msg, name);
        }
        return new Result(false, msg != null && !msg.isEmpty() ? msg : ("Σφάλμα HTTP " + r.code), name);
    }

    /* ---------------- tiny JSON helpers ---------------- */

    static String jsonQuote(String s) {
        StringBuilder sb = new StringBuilder("\"");
        for (char ch : s.toCharArray()) {
            if (ch == '"' || ch == '\\') sb.append('\\').append(ch);
            else if (ch < 0x20) sb.append(String.format("\\u%04x", (int) ch));
            else sb.append(ch);
        }
        return sb.append('"').toString();
    }

    static String jsonString(String json, String key) {
        if (json == null) return null;
        Matcher m = Pattern.compile("\"" + Pattern.quote(key) + "\"\\s*:\\s*(\"((?:[^\"\\\\]|\\\\.)*)\"|null)").matcher(json);
        if (!m.find()) return null;
        if (m.group(2) == null) return null;
        String v = m.group(2);
        StringBuilder out = new StringBuilder();
        for (int i = 0; i < v.length(); i++) {
            char ch = v.charAt(i);
            if (ch == '\\' && i + 1 < v.length()) {
                char n = v.charAt(++i);
                switch (n) {
                    case 'u': if (i + 4 < v.length()) { out.append((char) Integer.parseInt(v.substring(i + 1, i + 5), 16)); i += 4; } break;
                    case 'n': out.append('\n'); break;
                    case 't': out.append('\t'); break;
                    default: out.append(n);
                }
            } else out.append(ch);
        }
        return out.toString();
    }
}
