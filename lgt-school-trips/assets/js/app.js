/**
 * LGT School Trips – the two LeGrand forms (names form + rooming list), online.
 * Shared by the school page and the admin view. No dependencies.
 */
(function () {
	'use strict';

	var CFG = window.LGT_APP || {};
	var root = document.getElementById('lgt-app');
	if (!root || !CFG.tripId) { return; }

	/* ------------------------------------------------------------------ */
	/* Utilities                                                          */
	/* ------------------------------------------------------------------ */

	function h(s) {
		return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}
	function fmtDateTime(d) {
		if (!d) { return ''; }
		var m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(d);
		return m ? m[3] + '/' + m[2] + '/' + m[1] + ' ' + m[4] + ':' + m[5] : d;
	}
	function fmtDate(d) {
		if (!d) { return ''; }
		var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(d);
		return m ? m[3] + '/' + m[2] + '/' + m[1] : d;
	}

	/* ELOT 743 transliteration (mirror of the PHP implementation). */
	var TR = (function () {
		var map = { 'α': 'a', 'β': 'v', 'γ': 'g', 'δ': 'd', 'ε': 'e', 'ζ': 'z', 'η': 'i', 'θ': 'th', 'ι': 'i', 'κ': 'k', 'λ': 'l', 'μ': 'm', 'ν': 'n', 'ξ': 'x', 'ο': 'o', 'π': 'p', 'ρ': 'r', 'σ': 's', 'ς': 's', 'τ': 't', 'υ': 'y', 'φ': 'f', 'χ': 'ch', 'ψ': 'ps', 'ω': 'o' };
		var acc = { 'ά': 'α', 'έ': 'ε', 'ή': 'η', 'ί': 'ι', 'ό': 'ο', 'ύ': 'υ', 'ώ': 'ω', 'ϊ': 'ι', 'ϋ': 'υ', 'ΐ': 'ι', 'ΰ': 'υ', 'ς': 'σ' };
		var dia = { 'ϊ': 1, 'ϋ': 1, 'ΐ': 1, 'ΰ': 1 };
		var vowels = 'αεηιουω', voiced = 'βγδζλμνρ';
		function base(c) { return acc[c] || c; }
		function word(w) {
			var ch = Array.from(w.toLowerCase()), out = '';
			for (var i = 0; i < ch.length; i++) {
				var c = ch[i], b = base(c), nc = ch[i + 1] || '', next = nc ? base(nc) : '', ndia = !!dia[nc];
				if ((b === 'α' || b === 'ε' || b === 'η') && next === 'υ' && !ndia) {
					var after = ch[i + 2] ? base(ch[i + 2]) : '';
					var v = after !== '' && (vowels.indexOf(after) >= 0 || voiced.indexOf(after) >= 0);
					out += map[b] + (v ? 'v' : 'f'); i++; continue;
				}
				if (b === 'ο' && next === 'υ' && !ndia) { out += 'ou'; i++; continue; }
				if (b === 'γ') {
					if (next === 'γ') { out += 'ng'; i++; continue; }
					if (next === 'κ') { out += 'gk'; i++; continue; }
					if (next === 'ξ') { out += 'nx'; i++; continue; }
					if (next === 'χ') { out += 'nch'; i++; continue; }
				}
				if (b === 'μ' && next === 'π') { out += (i === 0 ? 'b' : 'mp'); i++; continue; }
				if (b === 'ν' && next === 'τ') { out += 'nt'; i++; continue; }
				out += map[b] !== undefined ? map[b] : c;
			}
			return out;
		}
		function clean(s) {
			try { s = s.normalize('NFD').replace(/[̀-ͯ]/g, ''); } catch (e) { /* ignore */ }
			return s.toUpperCase().replace(/[^A-Z0-9 \-']/g, '').replace(/\s+/g, ' ');
		}
		function toLatin(s) {
			s = s || '';
			if (!s) { return ''; }
			if (!/[Ͱ-Ͽἀ-῿]/.test(s)) { return clean(s); }
			var parts = s.split(/(\s+|-|'|’)/), out = '';
			parts.forEach(function (p) {
				if (!p) { return; }
				if (/^\s+$/.test(p)) { out += ' '; }
				else if (p === '-') { out += '-'; }
				else if (p === "'" || p === '’') { out += "'"; }
				else { out += word(p); }
			});
			return clean(out);
		}
		return { toLatin: toLatin };
	})();

	function formatDateTyping(v) {
		var digits = String(v || '').replace(/\D/g, '').slice(0, 8), parts = [];
		if (digits.length > 0) { parts.push(digits.slice(0, 2)); }
		if (digits.length > 2) { parts.push(digits.slice(2, 4)); }
		if (digits.length > 4) { parts.push(digits.slice(4, 8)); }
		return parts.join('/');
	}
	function normalizePastedDate(c) {
		var m = /^(\d{1,2})[\/\.\-](\d{1,2})[\/\.\-](\d{2,4})$/.exec(c.trim());
		if (m) {
			var y = m[3].length === 2 ? ((+m[3] > (new Date().getFullYear() % 100) + 1 ? '19' : '20') + m[3]) : m[3];
			return formatDateTyping(('0' + m[1]).slice(-2) + ('0' + m[2]).slice(-2) + y);
		}
		var iso = /^(\d{4})-(\d{2})-(\d{2})/.exec(c.trim());
		if (iso) { return iso[3] + '/' + iso[2] + '/' + iso[1]; }
		return formatDateTyping(c);
	}
	function isValidDate(v) {
		var m = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(String(v || '').trim());
		if (!m) { return false; }
		var d = +m[1], mo = +m[2], y = +m[3], dt = new Date(y, mo - 1, d);
		return y >= 1900 && dt.getFullYear() === y && dt.getMonth() === mo - 1 && dt.getDate() === d && dt <= new Date();
	}

	var toastBox;
	function toast(msg, type) {
		if (!toastBox) { toastBox = document.createElement('div'); toastBox.className = 'lgt-toasts'; document.body.appendChild(toastBox); }
		var el = document.createElement('div');
		el.className = 'lgt-toast' + (type ? ' lgt-toast-' + type : '');
		el.textContent = msg;
		toastBox.appendChild(el);
		setTimeout(function () { el.remove(); }, 4000);
	}

	/* ------------------------------------------------------------------ */
	/* State & API                                                        */
	/* ------------------------------------------------------------------ */

	var S = { loaded: false, trip: null, form: [], rooming: null, cabins: null, summary: {}, config: {}, dirty: false, saving: false, savedAt: null, saveError: false };
	var DEFAULT_ROWS = 40;
	var COLS = { hotel: { 1: 'Μονόκλινα', 2: 'Δίκλινα', 3: 'Τρίκλινα', 4: 'Τετράκλινα' }, cabin: { 1: 'Μονόκλινες', 2: 'Δίκλινες', 3: 'Τρίκλινες', 4: 'Τετράκλινες' } };

	function isAdmin() { return S.config.mode === 'admin'; }
	function readonly() { return !!S.config.readonly; }

	function api(method, path, body) {
		var headers = { 'Content-Type': 'application/json' };
		if (CFG.nonce) { headers['X-WP-Nonce'] = CFG.nonce; }
		if (CFG.token) { headers['X-LGT-Token'] = CFG.token; }
		return fetch(CFG.restUrl + 'trips/' + CFG.tripId + path, { method: method, headers: headers, credentials: 'same-origin', body: body ? JSON.stringify(body) : undefined })
			.then(function (r) {
				return r.json().catch(function () { return {}; }).then(function (data) {
					if (!r.ok) {
						if (r.status === 423) { S.config.readonly = true; render(); }
						throw new Error((data && data.message) || ('Σφάλμα ' + r.status));
					}
					return data;
				});
			});
	}
	function applyState(d) {
		if (d.trip) { S.trip = d.trip; }
		if (d.form) { S.form = d.form; }
		if (d.rooming) { S.rooming = d.rooming; }
		if ('cabins' in d) { S.cabins = d.cabins; }
		if (d.summary) { S.summary = d.summary; }
		if (d.config) { S.config = d.config; }
	}
	function payload() { return { form: S.form, rooming: S.rooming, cabins: S.cabins }; }

	/* Autosave: debounced after any change. */
	var saveTimer = null;
	function markDirty() {
		if (readonly()) { return; }
		S.dirty = true;
		renderStatus();
		clearTimeout(saveTimer);
		saveTimer = setTimeout(save, 900);
	}
	function save() {
		if (!S.dirty || S.saving || readonly()) { return Promise.resolve(); }
		S.saving = true; S.dirty = false; renderStatus();
		return api('PUT', '/data', payload()).then(function (res) {
			S.saving = false; S.saveError = false; S.savedAt = new Date();
			if (res.summary) { S.summary = res.summary; }
			renderStatus(); renderTotals('hotel'); renderTotals('cabin');
		}).catch(function (e) {
			S.saving = false; S.saveError = true; S.dirty = true; renderStatus();
			toast('Η αποθήκευση απέτυχε: ' + e.message, 'err');
		});
	}
	window.addEventListener('beforeunload', function (e) { if (S.dirty || S.saving) { e.preventDefault(); e.returnValue = ''; } });

	/* ------------------------------------------------------------------ */
	/* Rendering                                                          */
	/* ------------------------------------------------------------------ */

	function render() {
		if (!S.loaded) { return; }
		var t = S.trip, ro = readonly();
		var html = '<main class="lgt-container">';
		html += '<section class="lgt-hero"><h1>' + h(t.school_name) + '</h1><p class="lgt-subtitle">' + h(t.title) + (t.destination ? ' · ' + h(t.destination) : '') + (t.departure_date ? ' · ' + fmtDate(t.departure_date) + (t.return_date ? ' – ' + fmtDate(t.return_date) : '') : '') + '</p>';
		if (ro) { html += '<div class="lgt-banner lgt-banner-warn">🔒 Η φόρμα έχει κλείσει από το γραφείο. Μπορείτε να τη δείτε αλλά όχι να κάνετε αλλαγές. Για αλλαγές επικοινωνήστε μαζί μας' + (S.config.company && S.config.company.phone ? ' στο ' + h(S.config.company.phone) : '') + '.</div>'; }
		else if (t.submitted_at) { html += '<div class="lgt-banner lgt-banner-ok">✅ Η φόρμα υποβλήθηκε στις ' + fmtDateTime(t.submitted_at) + '. Αν κάνετε αλλαγές, πατήστε ξανά «Υποβολή».</div>'; }
		if (t.notes_school) { html += '<div class="lgt-banner lgt-banner-info"><strong>Μήνυμα από το γραφείο:</strong><br>' + h(t.notes_school).replace(/\n/g, '<br>') + '</div>'; }
		html += '</section>';

		if (isAdmin()) { html += adminBar(); }

		/* 1. Names form */
		html += '<section class="lgt-block" id="lgt-form-block"><div class="lgt-block-head"><h2>1. Φόρμα ονομάτων</h2><p class="lgt-subtitle">Συμπληρώστε Επώνυμο, Όνομα (λατινικά, όπως στην ταυτότητα) και ημερομηνία γέννησης. Αν γράψετε ελληνικά, μετατρέπονται αυτόματα.</p></div>';
		if (!ro) { html += '<div class="lgt-actions"><button type="button" class="lgt-btn lgt-secondary" data-act="addRow">+ Προσθήκη γραμμής</button><button type="button" class="lgt-btn lgt-secondary" data-act="addRows">+ 10 γραμμές</button><span class="lgt-count" id="lgt-form-count"></span></div>'; }
		html += '<div class="lgt-table-wrap"><table class="lgt-table"><thead><tr><th class="lgt-num">#</th><th>Επώνυμο</th><th>Όνομα</th><th>Ημερομηνία Γέννησης</th>' + (ro ? '' : '<th class="lgt-act">Ενέργεια</th>') + '</tr></thead><tbody id="lgt-form-rows"></tbody></table></div>';
		html += '</section>';

		/* 2. Rooming list */
		if (t.has_hotel && S.rooming) { html += roomingSection('hotel', S.rooming, '2. Rooming List', 'Κάθε πλαίσιο είναι ένα δωμάτιο. Γράψτε τα ονόματα των μαθητών που θα μείνουν μαζί. Τα δωμάτια που μένουν κενά δεν μετράνε.'); }
		if (t.has_ferry && S.cabins) { html += roomingSection('cabin', S.cabins, (t.has_hotel ? '3' : '2') + '. Καμπίνες πλοίου', 'Κάθε πλαίσιο είναι μία καμπίνα. Γράψτε ποιοι θα μείνουν μαζί.'); }

		/* Submit bar */
		html += '<section class="lgt-submit" id="lgt-submit">';
		if (ro) {
			html += '<div class="lgt-submit-row"><span class="lgt-status-text">Η φόρμα είναι κλειστή.</span><span class="lgt-spacer"></span><a class="lgt-btn lgt-secondary" href="' + h(dlUrl('pdf')) + '">Λήψη PDF</a></div>';
		} else {
			html += '<div class="lgt-submit-row"><span class="lgt-status-text" id="lgt-save-status"></span><span class="lgt-spacer"></span><button type="button" class="lgt-btn lgt-secondary" data-act="print">Εκτύπωση</button><a class="lgt-btn lgt-secondary" href="' + h(dlUrl('xlsx')) + '">Excel</a><a class="lgt-btn lgt-secondary" href="' + h(dlUrl('pdf')) + '">PDF</a><button type="button" class="lgt-btn lgt-primary lgt-big" data-act="submit">' + (isAdmin() ? '📨 Αποστολή αρχείων στο γραφείο' : '📨 Υποβολή στο ' + h((S.config.company && S.config.company.name) || 'γραφείο')) + '</button></div>';
			html += '<p class="lgt-hint">' + (isAdmin() ? 'Στέλνει τα τρέχοντα Excel/PDF στα email του γραφείου.' : 'Η φόρμα αποθηκεύεται αυτόματα όσο γράφετε. Με την «Υποβολή» το γραφείο λαμβάνει τη λίστα σας σε Excel και PDF και εσείς επιβεβαίωση με email.') + '</p>';
		}
		html += '</section>';
		html += '<p class="lgt-credit">Created by Ioannis Fanourakis</p>';
		html += '</main>';
		root.innerHTML = html;
		renderFormRows();
		renderColumns('hotel'); renderColumns('cabin');
		renderStatus();
	}

	function dlUrl(format) {
		var u = CFG.downloadUrl;
		return u + (u.indexOf('?') >= 0 ? '&' : '?') + (isAdmin() ? 'format=' : 'lgt_dl=') + format + '&list=all';
	}

	function adminBar() {
		var t = S.trip, html = '<section class="lgt-adminbar">';
		html += '<div class="lgt-adminbar-row"><strong>Σύνδεσμος σχολείου:</strong> ';
		if (t.portal_url) {
			html += '<input type="text" readonly value="' + h(t.portal_url) + '" data-role="portalUrl"><button type="button" class="lgt-btn lgt-secondary lgt-sm" data-act="copyLink">Αντιγραφή</button><a class="lgt-btn lgt-secondary lgt-sm" href="' + h(t.portal_url) + '" target="_blank" rel="noopener">Άνοιγμα</a>';
			html += t.status === 'open' ? '<button type="button" class="lgt-btn lgt-secondary lgt-sm" data-act="link" data-link="close">🔒 Κλείσιμο φόρμας</button>' : '<button type="button" class="lgt-btn lgt-primary lgt-sm" data-act="link" data-link="open">🔓 Άνοιγμα φόρμας</button>';
			html += '<button type="button" class="lgt-btn lgt-secondary lgt-sm" data-act="link" data-link="send_link"' + (t.school_email ? '' : ' disabled') + '>✉ Αποστολή συνδέσμου</button>';
			html += '<button type="button" class="lgt-btn lgt-secondary lgt-sm" data-act="link" data-link="regenerate" title="Ο παλιός σύνδεσμος παύει να ισχύει">↻ Νέος</button>';
		} else {
			html += '<button type="button" class="lgt-btn lgt-primary lgt-sm" data-act="link" data-link="generate">🔗 Δημιουργία συνδέσμου</button>';
		}
		html += '<span class="lgt-badge lgt-badge-' + (t.status === 'open' ? 'ok' : 'warn') + '">' + (t.status === 'open' ? 'Ανοιχτή' : (t.status === 'closed' ? 'Κλειστή' : h(t.status))) + '</span>';
		if (t.access_code) { html += '<span class="lgt-muted">Κωδικός: <b>' + h(t.access_code) + '</b></span>'; }
		html += '</div><p class="lgt-hint">' + (t.submitted_at ? 'Τελευταία υποβολή από το σχολείο: ' + fmtDateTime(t.submitted_at) + '.' : 'Το σχολείο δεν έχει υποβάλει ακόμη.') + ' Ό,τι αλλάζετε εδώ αποθηκεύεται και το βλέπει και το σχολείο. <a href="' + h(CFG.adminUrl + '&tab=details') + '">Στοιχεία εκδρομής</a> · <a href="' + h(CFG.adminUrl + '&tab=history') + '">Ιστορικό</a></p></section>';
		return html;
	}

	/* ---------------- Names form ---------------- */

	function ensureRows() {
		while (S.form.length < (readonly() ? 0 : DEFAULT_ROWS)) { S.form.push({ lastName: '', firstName: '', birthDate: '' }); }
		if (!S.form.length) { S.form.push({ lastName: '', firstName: '', birthDate: '' }); }
	}
	function renderFormRows() {
		var tb = document.getElementById('lgt-form-rows');
		if (!tb) { return; }
		var ro = readonly();
		ensureRows();
		var rows = ro ? S.form.filter(function (r) { return r.lastName || r.firstName || r.birthDate; }) : S.form;
		var html = '';
		rows.forEach(function (r, i) {
			var badDate = r.birthDate && !isValidDate(r.birthDate);
			html += '<tr data-row="' + i + '"><td class="lgt-num">' + (i + 1) + '</td>';
			html += '<td><input type="text" class="lgt-latin" data-f="lastName" data-row="' + i + '" value="' + h(r.lastName) + '" placeholder="Επώνυμο" autocomplete="off"' + (ro ? ' disabled' : '') + '></td>';
			html += '<td><input type="text" class="lgt-latin" data-f="firstName" data-row="' + i + '" value="' + h(r.firstName) + '" placeholder="Όνομα" autocomplete="off"' + (ro ? ' disabled' : '') + '></td>';
			html += '<td><input type="text" class="' + (badDate ? 'lgt-invalid' : '') + '" data-f="birthDate" data-row="' + i + '" value="' + h(r.birthDate) + '" placeholder="ηη/μμ/εεεε" inputmode="numeric" maxlength="10" autocomplete="off"' + (ro ? ' disabled' : '') + '></td>';
			if (!ro) { html += '<td class="lgt-act"><button type="button" class="lgt-remove" data-act="removeRow" data-row="' + i + '">Διαγραφή</button></td>'; }
			html += '</tr>';
		});
		if (!rows.length) { html += '<tr><td colspan="5" class="lgt-muted" style="text-align:center;padding:20px">Δεν έχουν συμπληρωθεί ονόματα.</td></tr>'; }
		tb.innerHTML = html;
		renderFormCount();
	}
	function renderFormCount() {
		var el = document.getElementById('lgt-form-count');
		if (!el) { return; }
		var n = S.form.filter(function (r) { return r.lastName || r.firstName; }).length;
		el.textContent = n ? n + ' ονόματα' : '';
	}

	/* ---------------- Rooming list ---------------- */

	function sheet(kind) { return kind === 'cabin' ? S.cabins : S.rooming; }
	function roomWord(kind) { return kind === 'cabin' ? 'Καμπίνα' : 'Δωμάτιο'; }

	function roomingSection(kind, data, title, subtitle) {
		var ro = readonly(), m = data.meta;
		var html = '<section class="lgt-block" id="lgt-' + kind + '-block"><div class="lgt-block-head"><h2>' + h(title) + '</h2><p class="lgt-subtitle">' + h(subtitle) + '</p></div>';
		if (!ro) {
			var hasSingles = data.columns.some(function (c) { return c.type === 1; });
			html += '<div class="lgt-actions"><button type="button" class="lgt-btn lgt-secondary" data-act="toggleSingles" data-kind="' + kind + '">' + (hasSingles ? 'Αφαίρεση ' + COLS[kind][1] : 'Προσθήκη ' + COLS[kind][1]) + '</button><button type="button" class="lgt-btn lgt-secondary" data-act="fillFromForm" data-kind="' + kind + '" title="Γεμίζει τα κενά με τα ονόματα της φόρμας, με τη σειρά">Συμπλήρωση από τη φόρμα</button><button type="button" class="lgt-btn lgt-secondary lgt-danger" data-act="clearSheet" data-kind="' + kind + '">Καθαρισμός</button></div>';
		}
		html += '<div class="lgt-meta">';
		html += '<div class="lgt-field"><label>Γκρουπ / Σχολείο</label><input type="text" data-meta="groupName" data-kind="' + kind + '" value="' + h(m.groupName) + '"' + (ro ? ' disabled' : '') + '></div>';
		html += '<div class="lgt-field"><label>' + (kind === 'cabin' ? 'Πλοίο / Εταιρεία' : 'Ξενοδοχείο') + '</label><input type="text" data-meta="hotelName" data-kind="' + kind + '" value="' + h(m.hotelName) + '"' + (ro ? ' disabled' : '') + '></div>';
		html += '<div class="lgt-field"><label>' + (kind === 'cabin' ? 'Ημερομηνία' : 'Άφιξη') + '</label><input type="text" data-meta="arrivalDate" data-kind="' + kind + '" value="' + h(m.arrivalDate) + '" placeholder="ηη/μμ/εεεε"' + (ro ? ' disabled' : '') + '></div>';
		html += '<div class="lgt-field"><label>' + (kind === 'cabin' ? 'Επιστροφή' : 'Αναχώρηση') + '</label><input type="text" data-meta="departureDate" data-kind="' + kind + '" value="' + h(m.departureDate) + '" placeholder="ηη/μμ/εεεε"' + (ro ? ' disabled' : '') + '></div>';
		html += '</div>';
		html += '<div class="lgt-totals" id="lgt-totals-' + kind + '"></div>';
		html += '<div class="lgt-sheet"><div class="lgt-columns" id="lgt-columns-' + kind + '"></div></div>';
		html += '<datalist id="lgt-names-' + kind + '"></datalist>';
		html += '</section>';
		return html;
	}

	function namesList() {
		return S.form.filter(function (r) { return r.lastName || r.firstName; }).map(function (r) { return TR.toLatin(r.lastName + ' ' + r.firstName).trim(); });
	}

	function renderColumns(kind) {
		var el = document.getElementById('lgt-columns-' + kind), data = sheet(kind);
		if (!el || !data) { return; }
		var ro = readonly(), word = roomWord(kind);
		var html = '';
		data.columns.forEach(function (col, ci) {
			var rooms = ro ? col.rooms.filter(function (r) { return r.some(Boolean); }) : col.rooms;
			html += '<article class="lgt-column"><div class="lgt-column-head"><p class="lgt-column-title">' + h(col.label) + '</p>';
			if (!ro) { html += '<div class="lgt-column-actions"><button type="button" class="lgt-mini" data-act="addRoom" data-kind="' + kind + '" data-col="' + ci + '">+ ' + word + '</button><button type="button" class="lgt-mini" data-act="removeRoom" data-kind="' + kind + '" data-col="' + ci + '"' + (col.rooms.length <= 1 ? ' disabled' : '') + '>− ' + word + '</button></div>'; }
			html += '</div><div class="lgt-slot-stack">';
			if (ro && !rooms.length) { html += '<p class="lgt-muted" style="text-align:center">—</p>'; }
			col.rooms.forEach(function (room, ri) {
				if (ro && !room.some(Boolean)) { return; }
				html += '<section class="lgt-slot"><div class="lgt-slot-label"><span>' + word + ' ' + (ri + 1) + '</span>' + (!ro && col.rooms.length > 1 ? '<button type="button" class="lgt-room-remove" data-act="removeSpecific" data-kind="' + kind + '" data-col="' + ci + '" data-room="' + ri + '">Αφαίρεση</button>' : '') + '</div><div class="lgt-name-grid">';
				room.forEach(function (name, gi) {
					html += '<input type="text" class="lgt-latin" list="lgt-names-' + kind + '" data-guest="' + kind + '-' + ci + '-' + ri + '-' + gi + '" placeholder="Όνομα ' + (gi + 1) + '" value="' + h(name) + '" autocomplete="off"' + (ro ? ' disabled' : '') + '>';
				});
				html += '</div></section>';
			});
			html += '</div></article>';
		});
		el.innerHTML = html;
		renderTotals(kind);
		renderNames(kind);
	}
	function renderNames(kind) {
		var dl = document.getElementById('lgt-names-' + kind);
		if (dl) { dl.innerHTML = namesList().map(function (n) { return '<option value="' + h(n) + '">'; }).join(''); }
	}
	function renderTotals(kind) {
		var el = document.getElementById('lgt-totals-' + kind), data = sheet(kind);
		if (!el || !data) { return; }
		var total = 0, names = 0, html = '';
		var per = data.columns.map(function (col) {
			var n = col.rooms.filter(function (r) { return r.some(Boolean); }).length;
			col.rooms.forEach(function (r) { names += r.filter(Boolean).length; });
			total += n;
			return { label: col.label, n: n };
		});
		html += '<div class="lgt-total-card"><span class="lgt-total-label">Σύνολο ' + (kind === 'cabin' ? 'καμπινών' : 'δωματίων') + '</span><span class="lgt-total-value">' + total + '</span></div>';
		per.forEach(function (p) { html += '<div class="lgt-total-card"><span class="lgt-total-label">' + h(p.label) + '</span><span class="lgt-total-value">' + p.n + '</span></div>'; });
		var formNames = namesList().length;
		html += '<div class="lgt-total-card' + (formNames && names < formNames ? ' lgt-total-warn' : '') + '"><span class="lgt-total-label">Ονόματα σε ' + (kind === 'cabin' ? 'καμπίνες' : 'δωμάτια') + '</span><span class="lgt-total-value">' + names + (formNames ? ' / ' + formNames : '') + '</span></div>';
		el.innerHTML = html;
	}

	function renderStatus() {
		var el = document.getElementById('lgt-save-status');
		if (!el) { return; }
		if (S.saving) { el.textContent = 'Αποθήκευση…'; el.className = 'lgt-status-text'; }
		else if (S.saveError) { el.textContent = '⚠ Δεν αποθηκεύτηκε – ελέγξτε τη σύνδεση'; el.className = 'lgt-status-text lgt-status-err'; }
		else if (S.dirty) { el.textContent = 'Αλλαγές…'; el.className = 'lgt-status-text'; }
		else if (S.savedAt) { el.textContent = '✓ Αποθηκεύτηκε ' + S.savedAt.toLocaleTimeString('el-GR', { hour: '2-digit', minute: '2-digit' }); el.className = 'lgt-status-text lgt-status-ok'; }
		else { el.textContent = 'Αποθηκεύεται αυτόματα'; el.className = 'lgt-status-text'; }
	}

	/* ------------------------------------------------------------------ */
	/* Actions                                                            */
	/* ------------------------------------------------------------------ */

	function addRoom(kind, ci) { var col = sheet(kind).columns[ci]; col.rooms.push(Array.apply(null, Array(col.type)).map(function () { return ''; })); renderColumns(kind); markDirty(); }
	function removeRoom(kind, ci, ri) {
		var col = sheet(kind).columns[ci];
		if (col.rooms.length <= 1) { return; }
		var idx = ri === undefined ? col.rooms.length - 1 : ri;
		if (col.rooms[idx].some(Boolean) && !window.confirm('Το ' + roomWord(kind).toLowerCase() + ' έχει ονόματα. Αφαίρεση;')) { return; }
		col.rooms.splice(idx, 1); renderColumns(kind); markDirty();
	}
	function toggleSingles(kind) {
		var data = sheet(kind), idx = -1;
		data.columns.forEach(function (c, i) { if (c.type === 1) { idx = i; } });
		if (idx >= 0) {
			if (data.columns[idx].rooms.some(function (r) { return r.some(Boolean); }) && !window.confirm('Τα ' + COLS[kind][1].toLowerCase() + ' έχουν ονόματα. Αφαίρεση;')) { return; }
			data.columns.splice(idx, 1);
		} else {
			data.columns.unshift({ type: 1, label: COLS[kind][1], rooms: [[''], [''], [''], ['']] });
		}
		renderColumns(kind); markDirty();
	}
	function clearSheet(kind) {
		if (!window.confirm('Να καθαριστούν όλα τα ονόματα από ' + (kind === 'cabin' ? 'τις καμπίνες' : 'τα δωμάτια') + ';')) { return; }
		sheet(kind).columns.forEach(function (col) { col.rooms = col.rooms.map(function (r) { return r.map(function () { return ''; }); }); });
		renderColumns(kind); markDirty();
	}
	/* Put the names of the form, in order, into the empty beds (helps a school that already listed everyone). */
	function fillFromForm(kind) {
		var data = sheet(kind), placed = {};
		data.columns.forEach(function (col) { col.rooms.forEach(function (r) { r.forEach(function (n) { if (n) { placed[n] = true; } }); }); });
		var queue = namesList().filter(function (n) { return !placed[n]; });
		if (!queue.length) { toast('Όλα τα ονόματα της φόρμας είναι ήδη τοποθετημένα.', 'ok'); return; }
		if (!window.confirm('Θα συμπληρωθούν ' + queue.length + ' ονόματα στις κενές θέσεις, με τη σειρά της φόρμας (ξεκινώντας από τα μεγαλύτερα ' + (kind === 'cabin' ? 'καμπίνες' : 'δωμάτια') + '). Μετά μπορείτε να τα αλλάξετε. Συνέχεια;')) { return; }
		var cols = data.columns.slice().sort(function (a, b) { return b.type - a.type; });
		cols.forEach(function (col) {
			col.rooms.forEach(function (room) {
				if (room.some(Boolean)) { return; }
				for (var i = 0; i < room.length && queue.length; i++) { room[i] = queue.shift(); }
			});
			while (queue.length >= col.type && col.type > 1) { var r = []; for (var j = 0; j < col.type; j++) { r.push(queue.shift()); } col.rooms.push(r); }
		});
		if (queue.length) { toast('Έμειναν ' + queue.length + ' ονόματα χωρίς θέση – προσθέστε δωμάτια.', 'err'); }
		renderColumns(kind); markDirty();
	}

	function validateForSubmit() {
		var problems = [];
		var rows = S.form.filter(function (r) { return r.lastName || r.firstName || r.birthDate; });
		if (!rows.length) { problems.push('Δεν έχετε συμπληρώσει ονόματα.'); }
		var bad = rows.filter(function (r) { return r.birthDate && !isValidDate(r.birthDate); }).length;
		if (bad) { problems.push(bad + ' ημερομηνίες γέννησης δεν είναι σωστές (μορφή ηη/μμ/εεεε).'); }
		var half = rows.filter(function (r) { return !r.lastName || !r.firstName; }).length;
		if (half) { problems.push(half + ' γραμμές έχουν μόνο επώνυμο ή μόνο όνομα.'); }
		return problems;
	}

	function submit() {
		var problems = validateForSubmit();
		if (problems.length && !isAdmin()) {
			if (!window.confirm('Προσοχή:\n• ' + problems.join('\n• ') + '\n\nΝα γίνει η υποβολή έτσι;')) { return; }
		} else if (!window.confirm(isAdmin() ? 'Αποστολή Excel/PDF στα email του γραφείου;' : 'Υποβολή της φόρμας στο γραφείο;')) { return; }
		var btn = root.querySelector('[data-act=submit]');
		if (btn) { btn.disabled = true; btn.textContent = 'Αποστολή…'; }
		clearTimeout(saveTimer);
		S.dirty = false;
		var body = payload();
		api('POST', isAdmin() ? '/send' : '/submit', isAdmin() ? {} : body).then(function (res) {
			applyState(res);
			S.savedAt = new Date();
			render();
			toast(isAdmin() ? 'Τα αρχεία στάλθηκαν.' : 'Η φόρμα υποβλήθηκε. Θα λάβετε επιβεβαίωση με email.', 'ok');
			window.scrollTo({ top: 0, behavior: 'smooth' });
		}).catch(function (e) {
			toast(e.message, 'err');
			if (btn) { btn.disabled = false; btn.textContent = '📨 Υποβολή'; }
		});
	}

	/* ------------------------------------------------------------------ */
	/* Events                                                             */
	/* ------------------------------------------------------------------ */

	root.addEventListener('input', function (e) {
		var el = e.target, f = el.getAttribute('data-f');
		if (f) {
			var i = parseInt(el.getAttribute('data-row'), 10);
			if (f === 'birthDate') {
				var fv = formatDateTyping(el.value);
				if (fv !== el.value) { el.value = fv; }
				el.classList.toggle('lgt-invalid', !!fv && fv.length === 10 && !isValidDate(fv));
				S.form[i].birthDate = fv;
			} else {
				S.form[i][f] = el.value.trim();
			}
			renderFormCount();
			markDirty();
			return;
		}
		var g = el.getAttribute('data-guest');
		if (g) {
			var p = g.split('-'), kind = p[0];
			sheet(kind).columns[+p[1]].rooms[+p[2]][+p[3]] = el.value.trim();
			renderTotals(kind);
			markDirty();
			return;
		}
		var meta = el.getAttribute('data-meta');
		if (meta) {
			var k = el.getAttribute('data-kind');
			if (meta === 'arrivalDate' || meta === 'departureDate') { var dv = formatDateTyping(el.value); if (dv !== el.value) { el.value = dv; } }
			sheet(k).meta[meta] = el.value.trim();
			markDirty();
		}
	});

	/* Names are converted to Latin capitals when leaving the field (so digraphs like ου / μπ convert correctly). */
	root.addEventListener('change', function (e) {
		var el = e.target, f = el.getAttribute('data-f'), g = el.getAttribute('data-guest');
		if (f && f !== 'birthDate') {
			var nv = TR.toLatin(el.value).trim();
			el.value = nv;
			S.form[parseInt(el.getAttribute('data-row'), 10)][f] = nv;
			renderNames('hotel'); renderNames('cabin');
			markDirty();
		} else if (g) {
			var p = g.split('-'), val = TR.toLatin(el.value).trim();
			el.value = val;
			sheet(p[0]).columns[+p[1]].rooms[+p[2]][+p[3]] = val;
			renderTotals(p[0]);
			markDirty();
		}
	});

	/* Enter moves down a row; paste of several lines fills several rows. */
	root.addEventListener('keydown', function (e) {
		var el = e.target;
		if (e.key !== 'Enter' || !el.getAttribute('data-f')) { return; }
		e.preventDefault();
		var i = parseInt(el.getAttribute('data-row'), 10), f = el.getAttribute('data-f');
		if (i === S.form.length - 1) { S.form.push({ lastName: '', firstName: '', birthDate: '' }); renderFormRows(); }
		var next = root.querySelector('[data-f="' + f + '"][data-row="' + (i + 1) + '"]');
		if (next) { next.focus(); }
	});
	root.addEventListener('paste', function (e) {
		var el = e.target, f = el.getAttribute('data-f');
		if (!f || readonly()) { return; }
		var text = (e.clipboardData || window.clipboardData).getData('text');
		if (!text || (text.indexOf('\n') < 0 && text.indexOf('\t') < 0)) { return; }
		e.preventDefault();
		var start = parseInt(el.getAttribute('data-row'), 10);
		var lines = text.replace(/\r/g, '').split('\n').filter(function (l) { return l.trim() !== ''; });
		var fields = ['lastName', 'firstName', 'birthDate'], startF = fields.indexOf(f);
		lines.forEach(function (line, li) {
			var cells = line.split(/\t|;/).map(function (c) { return c.trim(); });
			if (cells.length === 1 && startF === 0 && /\s/.test(cells[0])) { var parts = cells[0].split(/\s+/); cells = [parts[0], parts.slice(1).join(' ')]; }
			var ri = start + li;
			while (S.form.length <= ri) { S.form.push({ lastName: '', firstName: '', birthDate: '' }); }
			cells.forEach(function (c, ci) {
				var fld = fields[startF + ci];
				if (!fld) { return; }
				S.form[ri][fld] = fld === 'birthDate' ? normalizePastedDate(c) : TR.toLatin(c).trim();
			});
		});
		renderFormRows(); renderNames('hotel'); renderNames('cabin');
		markDirty();
		toast('Επικολλήθηκαν ' + lines.length + ' γραμμές.', 'ok');
	});

	root.addEventListener('click', function (e) {
		var btn = e.target.closest('[data-act]');
		if (!btn) { return; }
		var act = btn.getAttribute('data-act'), kind = btn.getAttribute('data-kind'), ci = parseInt(btn.getAttribute('data-col') || '-1', 10);
		switch (act) {
			case 'addRow': S.form.push({ lastName: '', firstName: '', birthDate: '' }); renderFormRows(); root.querySelector('[data-f=lastName][data-row="' + (S.form.length - 1) + '"]').focus(); break;
			case 'addRows': for (var i = 0; i < 10; i++) { S.form.push({ lastName: '', firstName: '', birthDate: '' }); } renderFormRows(); break;
			case 'removeRow':
				var ri = parseInt(btn.getAttribute('data-row'), 10);
				if (S.form.length > 1) { S.form.splice(ri, 1); } else { S.form[0] = { lastName: '', firstName: '', birthDate: '' }; }
				renderFormRows(); renderNames('hotel'); renderNames('cabin'); markDirty();
				break;
			case 'addRoom': addRoom(kind, ci); break;
			case 'removeRoom': removeRoom(kind, ci); break;
			case 'removeSpecific': removeRoom(kind, ci, parseInt(btn.getAttribute('data-room'), 10)); break;
			case 'toggleSingles': toggleSingles(kind); break;
			case 'clearSheet': clearSheet(kind); break;
			case 'fillFromForm': fillFromForm(kind); break;
			case 'print': window.print(); break;
			case 'submit': save().then(submit); break;
			case 'copyLink':
				var inp = root.querySelector('[data-role=portalUrl]');
				if (inp) { inp.select(); try { navigator.clipboard.writeText(inp.value); toast('Αντιγράφηκε', 'ok'); } catch (err) { document.execCommand('copy'); } }
				break;
			case 'link':
				var la = btn.getAttribute('data-link');
				if (la === 'regenerate' && !window.confirm('Ο παλιός σύνδεσμος θα πάψει να λειτουργεί. Συνέχεια;')) { break; }
				if (la === 'close' && !window.confirm('Κλείσιμο φόρμας; Το σχολείο θα τη βλέπει αλλά δεν θα μπορεί να κάνει αλλαγές.')) { break; }
				var lm = la === 'send_link' ? (window.prompt('Προαιρετικό μήνυμα προς το σχολείο:', '') || '') : '';
				api('POST', '/link', { action: la, message: lm }).then(function (res) {
					applyState(res); render();
					toast(la === 'send_link' ? (res.sent ? 'Ο σύνδεσμος στάλθηκε στο ' + S.trip.school_email : 'Αποτυχία αποστολής') : 'OK', la === 'send_link' && !res.sent ? 'err' : 'ok');
				}).catch(function (err) { toast(err.message, 'err'); });
				break;
		}
	});

	/* ------------------------------------------------------------------ */
	/* Boot                                                               */
	/* ------------------------------------------------------------------ */

	api('GET', '/bootstrap').then(function (data) {
		applyState(data);
		S.loaded = true;
		render();
	}).catch(function (e) {
		root.innerHTML = '<div class="lgt-container"><div class="lgt-banner lgt-banner-warn">' + h(e.message) + '</div></div>';
	});
})();
