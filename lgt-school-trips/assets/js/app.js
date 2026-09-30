/**
 * LGT School Trips – single-page app shared by the school portal and the admin screen.
 * No build step, no dependencies.
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
	function fmtDate(d) {
		if (!d) { return ''; }
		var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(d);
		return m ? m[3] + '/' + m[2] + '/' + m[1] : d;
	}
	function fmtDateTime(d) {
		if (!d) { return ''; }
		var m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(d);
		return m ? m[3] + '/' + m[2] + '/' + m[1] + ' ' + m[4] + ':' + m[5] : d;
	}
	function ageAt(birth, at) {
		if (!birth) { return null; }
		var b = new Date(birth), a = at ? new Date(at) : new Date();
		if (isNaN(b) || isNaN(a)) { return null; }
		var age = a.getFullYear() - b.getFullYear();
		var m = a.getMonth() - b.getMonth();
		if (m < 0 || (m === 0 && a.getDate() < b.getDate())) { age--; }
		return age;
	}
	function paxType(birth, at) {
		var a = ageAt(birth, at);
		if (a === null) { return ''; }
		return a < 2 ? 'INF' : (a < 12 ? 'CHD' : 'ADT');
	}
	function paxTitle(gender, birth, at) {
		var a = ageAt(birth, at);
		if (gender === 'M') { return a !== null && a < 12 ? 'MSTR' : 'MR'; }
		if (gender === 'F') { return a !== null && a < 12 ? 'MISS' : 'MS'; }
		return '';
	}
	function natSort(a, b) {
		return String(a).localeCompare(String(b), 'el', { numeric: true, sensitivity: 'base' });
	}

	/* ELOT 743 transliteration (mirror of the PHP implementation, for live preview). */
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
			return s.toUpperCase().replace(/[^A-Z0-9 \-']/g, '').replace(/\s+/g, ' ').trim();
		}
		function toLatin(s) {
			s = (s || '').trim();
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

	/* Guess gender from a Greek first name ending (for imports; user reviews). */
	function guessGender(first) {
		var w = (first || '').trim().toLowerCase().split(/[\s-]+/)[0] || '';
		if (!/[Ͱ-Ͽ]/.test(w)) { return ''; }
		try { w = w.normalize('NFD').replace(/[̀-ͯ]/g, ''); } catch (e) { /* ignore */ }
		if (/(ος|ης|ας|ους|ις|ευς|ων|ωρ|ηλ|ιμ)$/.test(w)) { return 'M'; }
		if (/(α|η|ω|ου|ις)$/.test(w)) { return 'F'; }
		return '';
	}

	var toastBox;
	function toast(msg, type) {
		if (!toastBox) {
			toastBox = document.createElement('div');
			toastBox.className = 'lgt-toasts';
			document.body.appendChild(toastBox);
		}
		var el = document.createElement('div');
		el.className = 'lgt-toast' + (type ? ' lgt-toast-' + type : '');
		el.textContent = msg;
		toastBox.appendChild(el);
		setTimeout(function () { el.remove(); }, 3800);
	}

	/* ------------------------------------------------------------------ */
	/* State & API                                                        */
	/* ------------------------------------------------------------------ */

	var S = {
		loaded: false, trip: null, participants: [], rooms: [], assignments: [], issues: {}, summary: {}, config: {},
		tab: (location.hash || '').replace('#', '') || 'participants', search: '', classFilter: '', showCancelled: false,
		selected: {}, busy: false, activity: null
	};

	function isAdmin() { return S.config.mode === 'admin'; }
	function readonly() { return !!S.config.readonly; }

	function api(method, path, body) {
		var headers = { 'Content-Type': 'application/json' };
		if (CFG.nonce) { headers['X-WP-Nonce'] = CFG.nonce; }
		if (CFG.token) { headers['X-LGT-Token'] = CFG.token; }
		setBusy(true);
		return fetch(CFG.restUrl + 'trips/' + CFG.tripId + path, {
			method: method, headers: headers, credentials: 'same-origin', body: body ? JSON.stringify(body) : undefined
		}).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (data) {
				if (!r.ok) {
					var msg = (data && data.message) || ('Σφάλμα ' + r.status);
					if (r.status === 423) { S.config.readonly = true; render(); }
					throw new Error(msg);
				}
				return data;
			});
		}).finally(function () { setBusy(false); });
	}
	function setBusy(b) {
		S.busy = b;
		root.classList.toggle('lgt-busy', b);
	}
	function applyState(data) {
		if (!data) { return; }
		if (data.trip) { S.trip = data.trip; }
		if (data.participants) { S.participants = data.participants; }
		if (data.rooms) { S.rooms = data.rooms; }
		if (data.assignments) { S.assignments = data.assignments; }
		if (data.issues) { S.issues = data.issues || {}; }
		if (data.summary) { S.summary = data.summary; }
		if (data.config) { S.config = data.config; }
	}
	function call(method, path, body, okMsg) {
		return api(method, path, body).then(function (data) {
			applyState(data);
			render();
			if (okMsg) { toast(okMsg, 'ok'); }
			return data;
		}).catch(function (e) { toast(e.message, 'err'); throw e; });
	}

	/* Derived helpers */
	function active() { return S.participants.filter(function (p) { return p.status === 'active'; }); }
	function byId(id) { for (var i = 0; i < S.participants.length; i++) { if (S.participants[i].id === id) { return S.participants[i]; } } return null; }
	function roomsOf(kind) { return S.rooms.filter(function (r) { return r.kind === kind; }); }
	function assignmentsOf(kind) { return S.assignments.filter(function (a) { return a.kind === kind; }); }
	function membersOf(roomId) {
		return S.assignments.filter(function (a) { return a.room_id === roomId; }).map(function (a) { return byId(a.participant_id); }).filter(function (p) { return p && p.status === 'active'; });
	}
	function roomOf(kind, pid) {
		var as = S.assignments;
		for (var i = 0; i < as.length; i++) { if (as[i].kind === kind && as[i].participant_id === pid) { return as[i].room_id; } }
		return 0;
	}
	function unassigned(kind) {
		return active().filter(function (p) { return !roomOf(kind, p.id); });
	}
	function typesOf(kind) { return kind === 'cabin' ? (S.trip.cabin_types || []) : (S.trip.room_types || []); }
	function typeLabel(kind, code) {
		var t = typesOf(kind).filter(function (x) { return x.code === code; })[0];
		return t ? t.code + ' · ' + t.label : code;
	}
	function classes() {
		var set = {};
		S.participants.forEach(function (p) { if (p.class_name) { set[p.class_name] = 1; } });
		return Object.keys(set).sort(natSort);
	}
	function fullName(p) { return (p.last_name + ' ' + p.first_name).trim(); }
	function latName(p) { return (p.last_name_lat + ' ' + p.first_name_lat).trim(); }
	function ptypeLabel(t) { return t === 'teacher' ? 'Καθηγ.' : (t === 'escort' ? 'Συνοδός' : 'Μαθ.'); }
	function kindLabel(kind, plural) { return kind === 'cabin' ? (plural ? 'Καμπίνες' : 'Καμπίνα') : (plural ? 'Δωμάτια' : 'Δωμάτιο'); }
	function issueOf(p) { return S.issues && S.issues[p.id] ? S.issues[p.id] : null; }
	function missingLabels(p) {
		var iss = issueOf(p);
		if (!iss) { return []; }
		var labels = S.config.field_labels || {};
		return iss.missing.map(function (f) { return labels[f] || f; }).concat(iss.warnings || []);
	}
	function dlUrl(format, list) {
		var u = CFG.downloadUrl;
		u += (u.indexOf('?') >= 0 ? '&' : '?') + (isAdmin() ? 'format=' : 'lgt_dl=') + format + '&list=' + list;
		return u;
	}

	/* ------------------------------------------------------------------ */
	/* Rendering                                                          */
	/* ------------------------------------------------------------------ */

	function render() {
		if (!S.loaded) { return; }
		var t = S.trip, sm = S.summary || {};
		var html = '';
		html += '<div class="lgt-head"><div>';
		html += '<h1 class="lgt-head-title">' + h(t.title) + '</h1>';
		html += '<p class="lgt-head-sub">' + h(t.school_name) + (t.destination ? ' · ' + h(t.destination) : '') + (t.departure_date ? ' · ' + fmtDate(t.departure_date) + (t.return_date ? ' – ' + fmtDate(t.return_date) : '') : '') + '</p>';
		html += '<div class="lgt-head-meta">';
		if (t.has_hotel) { html += '<span class="lgt-badge lgt-badge-soft">🏨 ' + h(t.hotel_name || 'Ξενοδοχείο') + '</span>'; }
		if (t.has_ferry) { html += '<span class="lgt-badge lgt-badge-soft">⛴ ' + h(t.ferry_company || 'Ακτοπλοϊκό') + '</span>'; }
		if (t.has_flight) { html += '<span class="lgt-badge lgt-badge-soft">✈ ' + h(t.airline || 'Αεροπορικό') + '</span>'; }
		html += '<span class="lgt-badge ' + (t.status === 'open' ? 'lgt-badge-ok' : 'lgt-badge-warn') + '">' + (t.status === 'open' ? 'Ανοιχτή καταχώρηση' : (t.status === 'closed' ? 'Κλειστή' : h(t.status))) + '</span>';
		html += '</div></div>';
		html += '<div class="lgt-stats">';
		html += '<div class="lgt-stat"><b>' + (sm.total || 0) + '</b><span>Άτομα</span></div>';
		html += '<div class="lgt-stat"><b>' + (sm.students || 0) + '</b><span>Μαθητές</span></div>';
		html += '<div class="lgt-stat"><b>' + (sm.staff || 0) + '</b><span>Συνοδοί</span></div>';
		html += '<div class="lgt-stat"><b><span style="color:var(--lgt-male)">' + (sm.male || 0) + '</span> / <span style="color:var(--lgt-female)">' + (sm.female || 0) + '</span></b><span>Αγόρια / Κορίτσια</span></div>';
		html += '<div class="lgt-stat ' + (sm.issue_count ? 'lgt-stat-err' : 'lgt-stat-ok') + '"><b>' + (sm.issue_count || 0) + '</b><span>Ελλείψεις</span></div>';
		html += '</div></div>';

		if (readonly()) {
			html += '<div class="lgt-readonly-banner">🔒 Η καταχώρηση έχει κλείσει από το γραφείο. Μπορείτε να δείτε και να κατεβάσετε τις λίστες, όχι όμως να κάνετε αλλαγές. Για αλλαγές επικοινωνήστε μαζί μας' + (S.config.company_phone ? ' στο ' + h(S.config.company_phone) : '') + '.</div>';
		}
		if (!isAdmin() && !readonly()) { html += stepsHtml(); }
		if (t.notes_school && S.tab === 'participants') {
			html += '<div class="lgt-notes-box"><strong>Μήνυμα από το γραφείο:</strong>\n' + h(t.notes_school) + '</div>';
		}

		/* Tabs */
		var tabs = [['participants', '👥 Συμμετέχοντες', sm.total || 0]];
		if (t.has_hotel) { tabs.push(['hotel', '🏨 Δωμάτια', sm.hotel_unassigned ? { n: sm.hotel_unassigned, cls: 'lgt-badge-warn' } : null]); }
		if (t.has_ferry) { tabs.push(['cabin', '⛴ Καμπίνες', sm.cabin_unassigned ? { n: sm.cabin_unassigned, cls: 'lgt-badge-warn' } : null]); }
		if (t.has_ferry) { tabs.push(['ferry', '📋 Λίστα πλοίου', null]); }
		if (t.has_flight) { tabs.push(['flight', '✈ Αεροπορικό', null]); }
		tabs.push(['summary', '✅ Σύνοψη & Υποβολή', sm.issue_count ? { n: sm.issue_count, cls: 'lgt-badge-err' } : null]);
		if (!tabs.some(function (x) { return x[0] === S.tab; })) { S.tab = 'participants'; }
		html += '<div class="lgt-tabs">';
		tabs.forEach(function (tb) {
			var badge = '';
			if (tb[2] !== null && tb[2] !== undefined) {
				if (typeof tb[2] === 'object') { badge = '<span class="lgt-badge ' + tb[2].cls + '">' + tb[2].n + '</span>'; }
				else { badge = '<span class="lgt-badge">' + tb[2] + '</span>'; }
			}
			html += '<button class="lgt-tab' + (S.tab === tb[0] ? ' active' : '') + '" data-tab="' + tb[0] + '">' + tb[1] + badge + '</button>';
		});
		html += '</div>';

		switch (S.tab) {
			case 'hotel': html += viewRooms('hotel'); break;
			case 'cabin': html += viewRooms('cabin'); break;
			case 'ferry': html += viewManifest('ferry'); break;
			case 'flight': html += viewManifest('flight'); break;
			case 'summary': html += viewSummary(); break;
			default: html += viewParticipants();
		}
		var focusInfo = null, ae = document.activeElement;
		if (ae && root.contains(ae) && ae.getAttribute('data-f')) {
			focusInfo = { pid: ae.getAttribute('data-pid'), f: ae.getAttribute('data-f'), s: ae.selectionStart, e: ae.selectionEnd };
		}
		var scrollY = window.scrollY;
		root.innerHTML = html;
		bindDnD();
		if (focusInfo) {
			var pid = focusMap[focusInfo.pid] || focusInfo.pid;
			var el = root.querySelector('[data-pid="' + pid + '"][data-f="' + focusInfo.f + '"]');
			if (el) { el.focus(); try { if (el.setSelectionRange && focusInfo.s !== null) { el.setSelectionRange(focusInfo.s, focusInfo.e); } } catch (err) { /* ignore */ } }
		}
		window.scrollTo(0, scrollY);
	}

	/* 3-step guide for schools */
	function stepsHtml() {
		var t = S.trip, sm = S.summary;
		var steps = [];
		steps.push({ tab: 'participants', title: '1. Περάστε τα ονόματα', done: sm.total > 0 && !sm.issue_count, partial: sm.total > 0, hint: sm.total ? (sm.issue_count ? sm.issue_count + ' άτομα με ελλείψεις' : sm.total + ' άτομα, όλα πλήρη') : 'μαθητές & συνοδοί (επικόλληση από Excel ή ένα-ένα)' });
		if (t.has_hotel) { steps.push({ tab: 'hotel', title: '2. Δωμάτια', done: sm.hotel_rooms > 0 && !sm.hotel_unassigned, partial: sm.hotel_rooms > 0, hint: sm.hotel_rooms ? (sm.hotel_unassigned ? sm.hotel_unassigned + ' χωρίς δωμάτιο' : 'όλοι τοποθετημένοι') : 'ποιος μένει με ποιον (υπάρχει αυτόματη κατανομή)' }); }
		if (t.has_ferry) { steps.push({ tab: 'cabin', title: (t.has_hotel ? '3' : '2') + '. Καμπίνες', done: sm.cabin_rooms > 0 && !sm.cabin_unassigned, partial: sm.cabin_rooms > 0, hint: sm.cabin_rooms ? (sm.cabin_unassigned ? sm.cabin_unassigned + ' χωρίς καμπίνα' : 'όλοι τοποθετημένοι') : 'μπορούν να αντιγραφούν από τα δωμάτια' }); }
		steps.push({ tab: 'summary', title: steps.length + 1 + '. Υποβολή στο γραφείο', done: !!t.submitted_at && !(t.data_updated_at && t.data_updated_at > t.submitted_at), partial: !!t.submitted_at, hint: t.submitted_at ? 'υποβλήθηκε ' + fmtDateTime(t.submitted_at) : 'όταν τελειώσετε, ένα κλικ' });
		var html = '<div class="lgt-steps">';
		steps.forEach(function (st) {
			html += '<button class="lgt-step' + (st.done ? ' lgt-step-done' : (st.partial ? ' lgt-step-partial' : '')) + (S.tab === st.tab ? ' lgt-step-active' : '') + '" data-tab="' + st.tab + '"><span class="lgt-step-icon">' + (st.done ? '✓' : (st.partial ? '…' : '')) + '</span><span><b>' + h(st.title) + '</b><br><small>' + h(st.hint) + '</small></span></button>';
		});
		return html + '</div>';
	}

	/* ---------------- Participants (spreadsheet-style grid) ---------------- */

	var blankSeq = 0, blankRows = [], draft = {}, latManual = {}, focusMap = {};
	function ensureBlankRows(min) { while (blankRows.length < min) { blankRows.push('n' + (++blankSeq)); } }
	function isTemp(pid) { return String(pid).charAt(0) === 'n'; }

	function gridColumns() {
		var t = S.trip, cols = [
			{ f: 'last_name', label: 'Επώνυμο', w: 150 },
			{ f: 'first_name', label: 'Όνομα', w: 130 },
			{ f: 'last_name_lat', label: 'Επώνυμο λατινικά', w: 150, lat: true },
			{ f: 'first_name_lat', label: 'Όνομα λατινικά', w: 130, lat: true },
			{ f: 'gender', label: 'Φύλο', w: 90, type: 'gender' },
			{ f: 'class_name', label: 'Τμήμα', w: 70 },
			{ f: 'birth_date', label: 'Ημ. γέννησης', w: 110, type: 'date' }
		];
		if (t.has_ferry || t.has_flight) {
			cols.push({ f: 'nationality', label: 'Εθν.', w: 60 });
			cols.push({ f: 'doc_type', label: 'Έγγραφο', w: 110, type: 'doc_type' });
			cols.push({ f: 'doc_number', label: 'Αρ. εγγράφου', w: 120 });
		}
		if (t.has_flight) { cols.push({ f: 'doc_expiry', label: 'Λήξη εγγράφου', w: 110, type: 'date' }); }
		cols.push({ f: 'ptype', label: 'Ιδιότητα', w: 110, type: 'ptype' });
		return cols;
	}
	/* Columns that a pasted block maps onto, in order (Latin columns are auto-filled). */
	function pasteColumns() { return gridColumns().filter(function (c) { return !c.lat && c.f !== 'ptype'; }).map(function (c) { return c.f; }); }

	function cellHtml(col, v, pid, miss, ro) {
		var req = (S.trip.required_fields || []).indexOf(col.f) >= 0;
		var cls = 'lgt-cell' + (miss.indexOf(col.f) >= 0 ? ' lgt-cell-missing' : '') + (col.lat ? ' lgt-lat' : '');
		var attrs = ' data-pid="' + pid + '" data-f="' + col.f + '"' + (ro ? ' disabled' : '') + ' title="' + h(col.label) + (req ? ' (υποχρεωτικό)' : '') + '"';
		var opt = function (val, label) { return '<option value="' + val + '"' + (v === val ? ' selected' : '') + '>' + label + '</option>'; };
		switch (col.type) {
			case 'gender': return '<select class="' + cls + '"' + attrs + '>' + opt('', '—') + opt('M', 'Αγόρι') + opt('F', 'Κορίτσι') + '</select>';
			case 'doc_type': return '<select class="' + cls + '"' + attrs + '>' + opt('', '—') + opt('ID', 'Ταυτότητα') + opt('PASSPORT', 'Διαβατήριο') + '</select>';
			case 'ptype': return '<select class="' + cls + '"' + attrs + '>' + opt('student', 'Μαθητής/τρια') + opt('teacher', 'Καθηγητής/τρια') + opt('escort', 'Συνοδός') + '</select>';
			case 'date': return '<input type="text" class="' + cls + '" inputmode="numeric" maxlength="10" placeholder="ηη/μμ/εεεε" value="' + h(fmtDate(v)) + '"' + attrs + ' autocomplete="off">';
			default: return '<input type="text" class="' + cls + '" value="' + h(v) + '"' + attrs + (col.lat ? ' spellcheck="false" placeholder="αυτόματα"' : '') + (col.f === 'nationality' ? ' placeholder="GR"' : '') + ' autocomplete="off">';
		}
	}

	function viewParticipants() {
		var t = S.trip, ro = readonly(), cols = gridColumns();
		var html = '<div class="lgt-toolbar">';
		if (!ro) {
			html += '<button class="lgt-btn lgt-btn-primary" data-act="import">📋 Επικόλληση από Excel</button>';
			html += '<button class="lgt-btn" data-act="moreRows">+ 10 γραμμές</button>';
		}
		html += '<span class="lgt-spacer"></span>';
		html += '<input type="search" class="lgt-input" data-role="search" placeholder="Αναζήτηση…" value="' + h(S.search) + '" style="width:170px">';
		var cls = classes();
		if (cls.length) {
			html += '<select class="lgt-select" data-role="classFilter"><option value="">Όλα τα τμήματα</option>' + cls.map(function (c) { return '<option value="' + h(c) + '"' + (S.classFilter === c ? ' selected' : '') + '>' + h(c) + '</option>'; }).join('') + '</select>';
		}
		if (S.summary.cancelled) { html += '<label class="lgt-small"><input type="checkbox" data-role="showCancelled"' + (S.showCancelled ? ' checked' : '') + '> Ακυρωμένοι (' + S.summary.cancelled + ')</label>'; }
		html += '</div>';
		if (!ro) {
			html += '<div class="lgt-alert lgt-alert-info lgt-small">✍️ Γράψτε <b>κατευθείαν μέσα στον πίνακα</b>, όπως στο Excel. Κάθε γραμμή αποθηκεύεται μόνη της. Τα λατινικά συμπληρώνονται αυτόματα (ΕΛΟΤ 743, όπως στην ταυτότητα) – διορθώστε τα μόνο αν διαφέρουν από το έγγραφο. Έχετε ήδη λίστα; Επικολλήστε την (Ctrl+V) στο πρώτο κενό «Επώνυμο» ή πατήστε «Επικόλληση από Excel».</div>';
		}
		var list = S.participants.filter(function (p) {
			if (!S.showCancelled && p.status !== 'active') { return false; }
			if (S.classFilter && p.class_name !== S.classFilter) { return false; }
			if (S.search) { var q = S.search.toLowerCase(); return (fullName(p) + ' ' + latName(p) + ' ' + p.class_name + ' ' + p.doc_number).toLowerCase().indexOf(q) >= 0; }
			return true;
		});
		html += '<div class="lgt-grid-wrap"><table class="lgt-grid"><thead><tr><th class="lgt-grid-num">#</th>';
		cols.forEach(function (c) { html += '<th style="min-width:' + c.w + 'px">' + h(c.label) + ((t.required_fields || []).indexOf(c.f) >= 0 ? ' <span class="lgt-req">*</span>' : '') + '</th>'; });
		if (t.has_hotel) { html += '<th>Δωμ.</th>'; }
		if (t.has_ferry) { html += '<th>Καμπ.</th>'; }
		html += '<th class="lgt-grid-status"></th></tr></thead><tbody>';
		list.forEach(function (p, i) {
			var iss = issueOf(p), miss = iss ? iss.missing : [];
			var rowCls = p.status !== 'active' ? 'lgt-row-cancelled' : (iss ? 'lgt-row-issue' : '');
			html += '<tr class="' + rowCls + '" data-row="' + p.id + '"><td class="lgt-grid-num">' + (i + 1) + '</td>';
			cols.forEach(function (c) { html += '<td>' + cellHtml(c, p[c.f] || '', p.id, miss, ro || p.status !== 'active') + '</td>'; });
			if (t.has_hotel) { var hr = roomOf('hotel', p.id); html += '<td class="lgt-grid-ro">' + (hr ? h(roomLabel(hr)) : '—') + '</td>'; }
			if (t.has_ferry) { var cr = roomOf('cabin', p.id); html += '<td class="lgt-grid-ro">' + (cr ? h(roomLabel(cr)) : '—') + '</td>'; }
			html += '<td class="lgt-grid-status lgt-actions">' + (p.status !== 'active' ? '<span class="lgt-badge">Ακυρ.</span>' : (iss ? '<span class="lgt-badge lgt-badge-err" title="' + h(missingLabels(p).join(', ')) + '">' + missingLabels(p).length + ' ⚠</span>' : '<span class="lgt-badge lgt-badge-ok" title="Πλήρη στοιχεία">✓</span>'));
			html += ' <button class="lgt-btn-icon" data-act="edit" data-pid="' + p.id + '" title="Περισσότερα (τηλέφωνο, σημειώσεις, ακύρωση)">⋯</button>' + (ro ? '' : '<button class="lgt-btn-icon" data-act="delete" data-pid="' + p.id + '" title="Διαγραφή">🗑</button>') + '</td></tr>';
		});
		if (!ro && !S.search && !S.classFilter) {
			ensureBlankRows(S.participants.length ? 5 : 15);
			blankRows.forEach(function (tid, j) {
				var d = draft[tid] || {};
				html += '<tr class="lgt-row-blank" data-row="' + tid + '"><td class="lgt-grid-num">' + (list.length + j + 1) + '</td>';
				cols.forEach(function (c) { var v = d[c.f]; if (v === undefined) { v = c.f === 'nationality' ? (S.config.default_nationality || 'GR') : (c.f === 'ptype' ? 'student' : (c.f === 'class_name' ? (S.classFilter || d.class_name || '') : '')); } html += '<td>' + cellHtml(c, v, tid, [], false) + '</td>'; });
				if (t.has_hotel) { html += '<td></td>'; }
				if (t.has_ferry) { html += '<td></td>'; }
				html += '<td class="lgt-grid-status"><span class="lgt-muted lgt-small" data-status="' + tid + '"></span></td></tr>';
			});
		}
		html += '</tbody></table></div>';
		if (!list.length && ro) { html += '<div class="lgt-empty">Δεν υπάρχουν συμμετέχοντες.</div>'; }
		return html;
	}

	function roomLabel(roomId) {
		var r = S.rooms.filter(function (x) { return x.id === roomId; })[0];
		return r ? r.label : '';
	}

	function rowValues(pid) {
		var d = {};
		root.querySelectorAll('[data-pid="' + pid + '"][data-f]').forEach(function (i) { d[i.getAttribute('data-f')] = i.value.trim(); });
		return d;
	}
	function setRowStatus(pid, txt) {
		var el = root.querySelector('[data-status="' + pid + '"]');
		if (el) { el.textContent = txt; }
	}
	function formatDateTyping(v) {
		var digits = String(v || '').replace(/\D/g, '').slice(0, 8), parts = [];
		if (digits.length > 0) { parts.push(digits.slice(0, 2)); }
		if (digits.length > 2) { parts.push(digits.slice(2, 4)); }
		if (digits.length > 4) { parts.push(digits.slice(4, 8)); }
		return parts.join('/');
	}

	function saveCell(el) {
		var pid = el.getAttribute('data-pid'), f = el.getAttribute('data-f');
		var d = rowValues(pid);
		if (isTemp(pid)) {
			draft[pid] = d;
			if (!d.last_name || !d.first_name) { return; }
			if (!d.gender) { d.gender = guessGender(d.first_name); }
			d.lat_manual = latManual[pid] ? 1 : 0;
			setRowStatus(pid, 'αποθήκευση…');
			api('POST', '/participants', d).then(function (res) {
				blankRows = blankRows.filter(function (x) { return x !== pid; });
				delete draft[pid];
				focusMap[pid] = res.created_id;
				applyState(res);
				render();
			}).catch(function (e) { toast(e.message, 'err'); setRowStatus(pid, '⚠ ' + e.message); });
			return;
		}
		var p = byId(parseInt(pid, 10));
		if (!p) { return; }
		var patch = {};
		patch[f] = d[f];
		if (f === 'last_name' || f === 'first_name') {
			if (!p.lat_manual && !latManual[pid]) { patch.last_name_lat = d.last_name_lat; patch.first_name_lat = d.first_name_lat; patch.lat_manual = 0; }
		}
		if (f === 'last_name_lat' || f === 'first_name_lat') { patch.lat_manual = 1; latManual[pid] = true; }
		var same = (col => { var cur = p[f] || ''; if (col && col.type === 'date') { cur = fmtDate(cur); } return cur === d[f]; })(gridColumns().filter(function (c) { return c.f === f; })[0]);
		if (same && !(f === 'last_name_lat' || f === 'first_name_lat')) { return; }
		if ((f === 'last_name' || f === 'first_name') && !d[f]) { toast('Το ' + (f === 'last_name' ? 'επώνυμο' : 'όνομα') + ' δεν μπορεί να είναι κενό.', 'err'); el.value = p[f]; return; }
		api('PUT', '/participants/' + pid, patch).then(function (res) { applyState(res); render(); }).catch(function (e) { toast(e.message, 'err'); });
	}

	/* Paste a block of cells (from Excel) into the grid → bulk import. */
	function gridPaste(el, text) {
		var pid = el.getAttribute('data-pid'), f = el.getAttribute('data-f');
		var parsed = parseClipboard(text);
		if (!parsed.rows.length) { return false; }
		var multi = parsed.rows.length > 1 || parsed.rows[0].length > 1;
		if (!multi) { return false; }
		var order = pasteColumns();
		var startIdx = Math.max(0, order.indexOf(f === 'last_name_lat' || f === 'first_name_lat' ? 'last_name' : f));
		var rows = parsed.rows.slice(parsed.header ? 1 : 0), headers = parsed.header ? parsed.rows[0] : [];
		var ncol = Math.max.apply(null, rows.map(function (r) { return r.length; }).concat([1]));
		var mapping = [];
		for (var c = 0; c < ncol; c++) {
			var g = parsed.header ? guessField(headers[c]) : (order[startIdx + c] || '');
			if (c === 0 && !parsed.header && rows.filter(function (r) { return /\s/.test(r[0] || ''); }).length > rows.length / 2 && (ncol === 1 || !rows.some(function (r) { return r[1] && /[Ͱ-Ͽ]/.test(r[1]) && !/^(Α|Κ|M|F|Θ)$/i.test(r[1]); }))) { g = 'full_name'; }
			mapping.push(g);
		}
		var defClass = rowValues(pid).class_name || S.classFilter || '';
		var out = [];
		rows.forEach(function (r) {
			var d = { class_name: defClass, ptype: 'student' };
			mapping.forEach(function (fld, i) {
				var v = (r[i] || '').trim();
				if (!fld || !v) { return; }
				if (fld === 'full_name') { var parts = v.split(/\s+/); d.last_name = parts[0]; d.first_name = parts.slice(1).join(' '); return; }
				if (fld === 'gender') { var g2 = v.toUpperCase(); d.gender = /^(M|A|Α|ΑΓ|ΑΡ|ΑΝΔ|MALE|BOY)/.test(g2) ? 'M' : (/^(F|Θ|Κ|Γ|FEM|GIRL|W)/.test(g2) ? 'F' : ''); return; }
				if (fld === 'doc_type') { d.doc_type = /διαβ|pass/i.test(v) ? 'PASSPORT' : 'ID'; return; }
				d[fld] = v;
			});
			if (!d.first_name && d.last_name && /\s/.test(d.last_name)) { var np = d.last_name.split(/\s+/); d.last_name = np[0]; d.first_name = np.slice(1).join(' '); }
			if (!d.gender) { d.gender = guessGender(d.first_name); }
			if (d.last_name && d.first_name) { out.push(d); }
		});
		if (!out.length) { toast('Δεν αναγνωρίστηκαν ονόματα στην επικόλληση.', 'err'); return true; }
		if (isTemp(pid)) { blankRows = blankRows.filter(function (x) { return x !== pid; }); delete draft[pid]; }
		call('POST', '/participants/import', { rows: out }).then(function (res) {
			toast('Προστέθηκαν ' + res.added + ' άτομα' + (res.skipped ? ', παραλείφθηκαν ' + res.skipped + ' (κενά ή διπλά)' : '') + '.', 'ok');
		}).catch(function () { /* toast */ });
		return true;
	}

	/* ---------------- Rooms / cabins (column sheet, like the paper rooming list) ---------------- */

	function viewRooms(kind) {
		var t = S.trip, ro = readonly(), rooms = roomsOf(kind), types = typesOf(kind).slice();
		types.sort(function (a, b) { return a.capacity - b.capacity; });
		var other = kind === 'hotel' ? 'cabin' : 'hotel';
		var otherHas = kind === 'hotel' ? t.has_ferry : t.has_hotel;
		var un = unassigned(kind);
		var word = kindLabel(kind), words = kindLabel(kind, true);
		var html = '';
		var notes = kind === 'hotel' ? t.hotel_notes : t.ferry_notes;
		if (notes) { html += '<div class="lgt-notes-box">' + h(notes) + '</div>'; }
		html += '<div class="lgt-toolbar">';
		if (!ro) {
			html += '<button class="lgt-btn lgt-btn-primary" data-act="auto" data-kind="' + kind + '">✨ Αυτόματη κατανομή</button>';
			if (otherHas && roomsOf(other).length) { html += '<button class="lgt-btn" data-act="copy" data-kind="' + kind + '">⇄ Ίδιες παρέες με ' + kindLabel(other, true).toLowerCase() + '</button>'; }
			if (rooms.length) { html += '<button class="lgt-btn lgt-btn-danger lgt-btn-sm" data-act="clear" data-kind="' + kind + '">Καθαρισμός</button>'; }
		}
		html += '<span class="lgt-spacer"></span>';
		html += '<a class="lgt-btn lgt-btn-sm" href="' + h(dlUrl('xlsx', kind === 'hotel' ? 'rooming' : 'cabins')) + '">⬇ Excel</a><a class="lgt-btn lgt-btn-sm" href="' + h(dlUrl('pdf', kind === 'hotel' ? 'rooming' : 'cabins')) + '">⬇ PDF</a>';
		html += '</div>';
		if (!ro && !rooms.length && active().length) {
			html += '<div class="lgt-alert lgt-alert-info lgt-small">Πατήστε <b>«Αυτόματη κατανομή»</b> για να μπουν όλοι σε ' + words.toLowerCase() + ' ανά φύλο και τμήμα, ή προσθέστε ' + words.toLowerCase() + ' στις στήλες και επιλέξτε ονόματα σε κάθε θέση. Μπορείτε επίσης να σύρετε ονόματα από ' + word.toLowerCase() + ' σε ' + word.toLowerCase() + '.</div>';
		}
		/* Totals like the paper sheet */
		html += '<div class="lgt-totals">';
		html += '<div class="lgt-total"><span>Σύνολο ' + words.toLowerCase() + '</span><b>' + rooms.length + '</b></div>';
		types.forEach(function (ty) { var n = rooms.filter(function (r) { return r.type_code === ty.code; }).length; html += '<div class="lgt-total"><span>' + h(ty.label) + '</span><b>' + n + '</b></div>'; });
		html += '<div class="lgt-total ' + (un.length ? 'lgt-total-warn' : 'lgt-total-ok') + '"><span>Χωρίς ' + word.toLowerCase() + '</span><b>' + un.length + '</b></div>';
		html += '</div>';

		/* Unassigned strip */
		html += '<div class="lgt-pool' + (un.length ? '' : ' lgt-pool-empty') + '" data-drop="0" data-kind="' + kind + '">';
		if (un.length) {
			html += '<div class="lgt-pool-title">Χωρίς ' + word.toLowerCase() + ' (' + un.length + ') – σύρετε σε μια θέση ή επιλέξτε το όνομα μέσα στο ' + word.toLowerCase() + '</div><div class="lgt-pool-list">';
			un.sort(function (a, b) { return natSort((a.ptype === 'student' ? '1' : '0') + a.class_name + a.gender + fullName(a), (b.ptype === 'student' ? '1' : '0') + b.class_name + b.gender + fullName(b)); }).forEach(function (p) { html += chipHtml(p, kind, false); });
			html += '</div>';
		} else {
			html += '<div class="lgt-pool-title">' + (active().length ? '🎉 Όλοι έχουν ' + word.toLowerCase() + '. Σύρετε ένα όνομα εδώ για να το αφαιρέσετε.' : 'Δεν υπάρχουν ακόμη συμμετέχοντες – συμπληρώστε πρώτα τα ονόματα.') + '</div>';
		}
		html += '</div>';

		/* Columns per type */
		var known = {};
		types.forEach(function (ty) { known[ty.code] = 1; });
		var extra = rooms.filter(function (r) { return !known[r.type_code]; });
		html += '<div class="lgt-sheet">';
		types.forEach(function (ty) {
			var list = rooms.filter(function (r) { return r.type_code === ty.code; });
			html += '<div class="lgt-col"><div class="lgt-col-title">' + h(ty.label) + '<small>' + h(ty.code) + ' · ' + ty.capacity + ' άτομα · ' + list.length + ' ' + words.toLowerCase() + '</small></div>';
			list.forEach(function (r) { html += roomBoxHtml(r, kind, un, ro); });
			if (!ro) { html += '<button class="lgt-btn lgt-btn-sm lgt-col-add" data-act="addRoom" data-kind="' + kind + '" data-type="' + h(ty.code) + '">+ ' + word + '</button>'; }
			html += '</div>';
		});
		if (extra.length) {
			html += '<div class="lgt-col"><div class="lgt-col-title">Άλλα<small>' + extra.length + '</small></div>';
			extra.forEach(function (r) { html += roomBoxHtml(r, kind, un, ro); });
			html += '</div>';
		}
		html += '</div>';
		return html;
	}

	function chipHtml(p, kind, inRoom) {
		var ro = readonly();
		return '<div class="lgt-chip-person' + (p.ptype !== 'student' ? ' lgt-chip-staff' : '') + '" draggable="' + (ro ? 'false' : 'true') + '" data-pid="' + p.id + '" data-kind="' + kind + '" title="' + h(latName(p)) + '">'
			+ '<span class="lgt-sex lgt-sex-' + (p.gender || 'U') + '">' + (p.gender === 'M' ? 'Α' : (p.gender === 'F' ? 'Κ' : '?')) + '</span>'
			+ '<span class="lgt-chip-name">' + h(fullName(p)) + '</span>'
			+ (p.class_name ? '<span class="lgt-chip-cls">' + h(p.class_name) + '</span>' : '') + (p.ptype !== 'student' ? '<span class="lgt-chip-cls">' + ptypeLabel(p.ptype) + '</span>' : '')
			+ (inRoom && !ro ? '<button class="lgt-chip-x" data-act="unassign" data-pid="' + p.id + '" data-kind="' + kind + '" title="Αφαίρεση">✕</button>' : '')
			+ '</div>';
	}

	function roomBoxHtml(r, kind, un, ro) {
		var mem = membersOf(r.id), n = mem.length, slots = Math.max(r.capacity, n);
		var genders = {};
		mem.forEach(function (p) { if (p.gender) { genders[p.gender] = 1; } });
		var mixed = Object.keys(genders).length > 1;
		var html = '<div class="lgt-room' + (mixed ? ' lgt-room-mixed' : '') + (n > r.capacity ? ' lgt-room-over' : '') + '" data-drop="' + r.id + '" data-kind="' + kind + '">';
		html += '<div class="lgt-room-head"><span class="lgt-room-label" data-act="renameRoom" data-rid="' + r.id + '">' + kindLabel(kind) + ' ' + h(r.label) + '</span><span class="lgt-room-cap ' + (n > r.capacity ? 'lgt-over' : (n === r.capacity ? 'lgt-full' : '')) + '">' + n + '/' + r.capacity + '</span>' + (ro ? '' : '<button class="lgt-btn-icon lgt-room-del" data-act="deleteRoom" data-rid="' + r.id + '" title="Διαγραφή ' + kindLabel(kind).toLowerCase() + '">🗑</button>') + '</div>';
		if (mixed) { html += '<div class="lgt-room-notes">⚠ Μικτό φύλο</div>'; }
		if (n > r.capacity) { html += '<div class="lgt-room-notes">⚠ Περισσότερα άτομα από τις θέσεις</div>'; }
		for (var i = 0; i < slots; i++) {
			if (mem[i]) { html += '<div class="lgt-slot lgt-slot-filled">' + chipHtml(mem[i], kind, true) + '</div>'; }
			else if (ro) { html += '<div class="lgt-slot lgt-slot-empty"><span class="lgt-muted">κενή θέση</span></div>'; }
			else { html += '<div class="lgt-slot lgt-slot-empty">' + slotSelectHtml(r, kind, un) + '</div>'; }
		}
		html += '</div>';
		return html;
	}

	function slotSelectHtml(r, kind, un) {
		var groups = {};
		un.forEach(function (p) {
			var key = p.ptype === 'student' ? ((p.class_name || 'Χωρίς τμήμα') + ' · ' + (p.gender === 'M' ? 'Αγόρια' : (p.gender === 'F' ? 'Κορίτσια' : 'Χωρίς φύλο'))) : 'Συνοδοί';
			(groups[key] = groups[key] || []).push(p);
		});
		var html = '<select class="lgt-slot-pick" data-act="slotPick" data-rid="' + r.id + '" data-kind="' + kind + '"><option value="">' + (un.length ? '— επιλέξτε όνομα —' : '— κενή θέση —') + '</option>';
		Object.keys(groups).sort(natSort).forEach(function (k) {
			html += '<optgroup label="' + h(k) + '">' + groups[k].map(function (p) { return '<option value="' + p.id + '">' + h(fullName(p)) + '</option>'; }).join('') + '</optgroup>';
		});
		return html + '</select>';
	}

	/* ---------------- Manifests ---------------- */

	function viewManifest(kind) {
		var t = S.trip, list = active().slice();
		list.sort(function (a, b) {
			if (a.ptype !== b.ptype) { return a.ptype === 'student' ? 1 : -1; }
			return natSort(latName(a), latName(b));
		});
		var html = '';
		var notes = kind === 'flight' ? t.flight_notes : t.ferry_notes;
		html += '<div class="lgt-alert lgt-alert-info">' + (kind === 'flight'
			? '✈ <strong>Αεροπορικό:</strong> οι αεροπορικές εταιρείες απαιτούν το ονοματεπώνυμο <em>ακριβώς όπως αναγράφεται στο διαβατήριο/ταυτότητα</em> (λατινικά), ημερομηνία γέννησης, φύλο, εθνικότητα, τύπο και αριθμό εγγράφου και ημερομηνία λήξης. Ο τύπος επιβάτη (ADT/CHD/INF) υπολογίζεται αυτόματα με βάση την ηλικία την ημέρα αναχώρησης.'
			: '⛴ <strong>Ακτοπλοϊκό:</strong> για τη λίστα επιβατών του πλοίου απαιτούνται ονοματεπώνυμο (λατινικά), φύλο, ημερομηνία γέννησης, εθνικότητα' + ((S.trip.required_fields || []).indexOf('doc_number') >= 0 ? ' και αριθμός ταυτότητας/διαβατηρίου' : '') + '.') + (notes ? '<br><br>' + h(notes) : '') + '</div>';
		var incomplete = list.filter(function (p) { return issueOf(p) && issueOf(p).missing.length; }).length;
		html += '<div class="lgt-toolbar"><span class="lgt-badge ' + (incomplete ? 'lgt-badge-err' : 'lgt-badge-ok') + '">' + (incomplete ? incomplete + ' άτομα με ελλιπή στοιχεία' : 'Όλα τα στοιχεία πλήρη') + '</span><span class="lgt-spacer"></span>';
		html += '<a class="lgt-btn lgt-btn-sm" href="' + h(dlUrl('xlsx', kind)) + '">⬇ Excel</a><a class="lgt-btn lgt-btn-sm" href="' + h(dlUrl('pdf', kind)) + '">⬇ PDF</a></div>';
		html += '<div class="lgt-table-wrap"><table class="lgt-table"><thead><tr><th>#</th>';
		if (kind === 'flight') { html += '<th>Title</th>'; }
		html += '<th>Surname</th><th>Name</th><th>Sex</th><th>Date of birth</th>';
		if (kind === 'flight') { html += '<th>PAX</th>'; }
		html += '<th>Nationality</th><th>Doc</th><th>Doc No</th>';
		if (kind === 'flight') { html += '<th>Expiry</th>'; }
		if (kind === 'ferry') { html += '<th>Cabin</th>'; }
		html += '<th></th></tr></thead><tbody>';
		list.forEach(function (p, i) {
			var iss = issueOf(p), miss = iss ? iss.missing : [];
			var m = function (f, v) { return '<td class="' + (miss.indexOf(f) >= 0 ? 'lgt-missing' : '') + '">' + (v ? h(v) : 'λείπει') + '</td>'; };
			html += '<tr class="' + (iss ? 'lgt-row-issue' : '') + '"><td class="lgt-muted">' + (i + 1) + '</td>';
			if (kind === 'flight') { html += '<td>' + paxTitle(p.gender, p.birth_date, t.departure_date) + '</td>'; }
			html += '<td class="lgt-lat"><b>' + h(p.last_name_lat) + '</b></td><td class="lgt-lat">' + h(p.first_name_lat) + '</td>';
			html += m('gender', p.gender) + m('birth_date', fmtDate(p.birth_date));
			if (kind === 'flight') { html += '<td>' + paxType(p.birth_date, t.departure_date) + '</td>'; }
			html += m('nationality', p.nationality) + m('doc_type', p.doc_type) + m('doc_number', p.doc_number);
			if (kind === 'flight') { html += m('doc_expiry', fmtDate(p.doc_expiry)); }
			if (kind === 'ferry') { var cr = roomOf('cabin', p.id); html += '<td>' + (cr ? h(roomLabel(cr)) : 'DECK') + '</td>'; }
			html += '<td class="lgt-actions"><button class="lgt-btn-icon" data-act="edit" data-pid="' + p.id + '">' + (readonly() ? '👁' : '✏️') + '</button></td></tr>';
		});
		html += '</tbody></table></div>';
		return html;
	}

	/* ---------------- Summary ---------------- */

	function viewSummary() {
		var t = S.trip, sm = S.summary, ro = readonly();
		var checks = [];
		checks.push({ ok: sm.total > 0, level: 'err', text: sm.total ? sm.total + ' συμμετέχοντες (' + sm.students + ' μαθητές, ' + sm.staff + ' συνοδοί)' : 'Δεν έχουν καταχωρηθεί συμμετέχοντες' });
		checks.push({ ok: sm.staff > 0, level: 'warn', text: sm.staff ? sm.staff + ' συνοδοί/καθηγητές' : 'Δεν έχουν δηλωθεί συνοδοί καθηγητές' });
		checks.push({ ok: !sm.issue_count, level: 'err', text: sm.issue_count ? sm.issue_count + ' άτομα με ελλιπή ή προβληματικά στοιχεία' : 'Όλα τα στοιχεία είναι πλήρη' });
		if (t.has_hotel) { checks.push({ ok: !sm.hotel_unassigned && sm.hotel_rooms > 0, level: 'err', text: sm.hotel_rooms ? (sm.hotel_unassigned ? sm.hotel_unassigned + ' άτομα χωρίς δωμάτιο' : 'Όλοι έχουν δωμάτιο (' + sm.hotel_rooms + ' δωμάτια)') : 'Δεν έχει γίνει κατανομή σε δωμάτια' }); }
		if (t.has_ferry) { checks.push({ ok: !sm.cabin_unassigned && sm.cabin_rooms > 0, level: 'warn', text: sm.cabin_rooms ? (sm.cabin_unassigned ? sm.cabin_unassigned + ' άτομα χωρίς καμπίνα (θέση καταστρώματος)' : 'Όλοι έχουν καμπίνα (' + sm.cabin_rooms + ' καμπίνες)') : 'Δεν έχει γίνει κατανομή σε καμπίνες' }); }
		if (sm.overfilled && sm.overfilled.length) { checks.push({ ok: false, level: 'err', text: sm.overfilled.length + ' δωμάτια/καμπίνες με περισσότερα άτομα από τη χωρητικότητα' }); }
		var mixed = S.rooms.filter(function (r) { var g = {}; membersOf(r.id).forEach(function (p) { if (p.gender) { g[p.gender] = 1; } }); return Object.keys(g).length > 1; }).length;
		if (mixed) { checks.push({ ok: false, level: 'warn', text: mixed + ' δωμάτια/καμπίνες με μικτό φύλο' }); }
		var blocking = checks.some(function (c) { return !c.ok && c.level === 'err'; });

		var html = '<div class="lgt-two-col"><div>';
		html += '<div class="lgt-card"><h2>Έλεγχος πληρότητας</h2><ul class="lgt-checks">';
		checks.forEach(function (c) { html += '<li><span class="lgt-check-icon ' + (c.ok ? 'lgt-check-ok' : 'lgt-check-' + c.level) + '">' + (c.ok ? '✓' : '!') + '</span><span>' + h(c.text) + '</span></li>'; });
		html += '</ul>';
		if (sm.issue_count) {
			html += '<h3>Ελλείψεις ανά άτομο</h3><ul class="lgt-small" style="margin:0;padding-left:18px">';
			active().forEach(function (p) { if (issueOf(p)) { html += '<li><a href="#" data-act="edit" data-pid="' + p.id + '">' + h(fullName(p)) + '</a>: ' + h(missingLabels(p).join(', ')) + '</li>'; } });
			html += '</ul>';
		}
		html += '</div>';

		html += '<div class="lgt-card"><h2>Λήψη αρχείων</h2><div class="lgt-dl-grid">';
		html += '<a class="lgt-btn" href="' + h(dlUrl('xlsx', 'all')) + '">⬇ Excel (όλες οι λίστες)</a><a class="lgt-btn" href="' + h(dlUrl('pdf', 'all')) + '">⬇ PDF (όλες οι λίστες)</a>';
		if (t.has_hotel) { html += '<a class="lgt-btn lgt-btn-sm" href="' + h(dlUrl('pdf', 'rooming')) + '">Rooming list PDF</a>'; }
		if (t.has_ferry) { html += '<a class="lgt-btn lgt-btn-sm" href="' + h(dlUrl('pdf', 'cabins')) + '">Καμπίνες PDF</a><a class="lgt-btn lgt-btn-sm" href="' + h(dlUrl('xlsx', 'ferry')) + '">Manifest πλοίου Excel</a>'; }
		if (t.has_flight) { html += '<a class="lgt-btn lgt-btn-sm" href="' + h(dlUrl('xlsx', 'flight')) + '">Λίστα αεροπορικού Excel</a>'; }
		if (isAdmin()) { html += '<a class="lgt-btn lgt-btn-sm" href="' + h(dlUrl('xlsx', 'full')) + '">Πλήρης λίστα (ελληνικά) Excel</a>'; }
		html += '</div></div></div><div>';

		/* Submit */
		html += '<div class="lgt-card"><h2>' + (isAdmin() ? 'Αποστολή λιστών στο γραφείο' : 'Υποβολή στο γραφείο') + '</h2>';
		if (t.submitted_at) { html += '<p class="lgt-muted lgt-small">Τελευταία υποβολή: ' + fmtDateTime(t.submitted_at) + (t.data_updated_at && t.data_updated_at > t.submitted_at ? ' · <span class="lgt-badge lgt-badge-warn">υπάρχουν νεότερες αλλαγές</span>' : '') + '</p>'; }
		if (ro) {
			html += '<p>Η καταχώρηση έχει κλείσει. Για αλλαγές επικοινωνήστε με το γραφείο.</p>';
		} else {
			html += '<p class="lgt-small">' + (isAdmin() ? 'Στέλνει τα τρέχοντα Excel/PDF στα email του γραφείου.' : 'Με την υποβολή, το γραφείο μας λαμβάνει αυτόματα τις λίστες (Excel & PDF) και εσείς επιβεβαίωση στο ' + h(t.school_email || 'email σας') + '. Μπορείτε να ξανα-υποβάλετε όσες φορές θέλετε μετά από αλλαγές.') + '</p>';
			if (blocking && !isAdmin()) { html += '<div class="lgt-alert lgt-alert-warn">Υπάρχουν εκκρεμότητες (κόκκινα σημεία). Μπορείτε να υποβάλετε και τώρα, αλλά παρακαλούμε συμπληρώστε τα στοιχεία που λείπουν το συντομότερο.</div>'; }
			html += '<textarea class="lgt-textarea" data-role="submitMsg" rows="3" style="width:100%" placeholder="Προαιρετικό μήνυμα προς το γραφείο (π.χ. αλλαγές, ειδικές ανάγκες, διατροφή…)"></textarea>';
			html += '<p style="margin-top:10px"><button class="lgt-btn lgt-btn-accent" data-act="submit">📨 ' + (isAdmin() ? 'Αποστολή τώρα' : 'Υποβολή στο γραφείο') + '</button></p>';
		}
		html += '</div>';

		/* Contact */
		html += '<div class="lgt-card"><h2>Στοιχεία επικοινωνίας σχολείου</h2>';
		html += '<div class="lgt-form-row"><div class="lgt-field"><label>Υπεύθυνος</label><input type="text" data-field="contact_name" value="' + h(t.contact_name) + '"' + (ro ? ' disabled' : '') + '></div>';
		html += '<div class="lgt-field"><label>Τηλέφωνο</label><input type="text" data-field="school_phone" value="' + h(t.school_phone) + '"' + (ro ? ' disabled' : '') + '></div>';
		html += '<div class="lgt-field"><label>Email</label><input type="email" data-field="school_email" value="' + h(t.school_email) + '"' + (ro ? ' disabled' : '') + '></div></div>';
		if (!ro) { html += '<button class="lgt-btn lgt-btn-sm" data-act="saveContact">Αποθήκευση</button>'; }
		html += '</div>';

		/* Admin box */
		if (isAdmin()) {
			html += '<div class="lgt-card"><h2>Σύνδεσμος σχολείου</h2>';
			if (t.portal_url) {
				html += '<div class="lgt-link-box"><input type="text" readonly value="' + h(t.portal_url) + '" data-role="portalUrl"><button class="lgt-btn lgt-btn-sm" data-act="copyLink">Αντιγραφή</button><a class="lgt-btn lgt-btn-sm" href="' + h(t.portal_url) + '" target="_blank" rel="noopener">Άνοιγμα</a></div>';
				if (t.access_code) { html += '<p class="lgt-small">Κωδικός πρόσβασης: <b>' + h(t.access_code) + '</b></p>'; }
				html += '<p class="lgt-small lgt-muted">Ο σύνδεσμος δεν λήγει ποτέ αυτόματα. Τον κλείνετε εσείς όποτε θέλετε.</p>';
				html += '<div class="lgt-toolbar">';
				if (t.status === 'open') { html += '<button class="lgt-btn lgt-btn-danger" data-act="link" data-link="close">🔒 Κλείσιμο καταχώρησης</button>'; }
				else { html += '<button class="lgt-btn lgt-btn-primary" data-act="link" data-link="open">🔓 Άνοιγμα καταχώρησης</button>'; }
				html += '<button class="lgt-btn" data-act="link" data-link="send_link"' + (t.school_email ? '' : ' disabled title="Δεν υπάρχει email σχολείου"') + '>✉ Αποστολή συνδέσμου στο σχολείο</button>';
				html += '<button class="lgt-btn lgt-btn-sm" data-act="link" data-link="regenerate" title="Ακυρώνει τον παλιό σύνδεσμο">↻ Νέος σύνδεσμος</button>';
				html += '</div>';
			} else {
				html += '<p>Δεν έχει δημιουργηθεί σύνδεσμος. Με τη δημιουργία, η καταχώρηση ανοίγει για το σχολείο.</p><button class="lgt-btn lgt-btn-primary" data-act="link" data-link="generate">🔗 Δημιουργία συνδέσμου</button>';
			}
			html += '<p style="margin-top:12px"><a href="' + h(CFG.adminUrl + '&tab=details') + '">Επεξεργασία στοιχείων εκδρομής →</a> · <a href="' + h(CFG.adminUrl + '&tab=history') + '">Ιστορικό →</a></p>';
			html += '</div>';
		}
		html += '</div></div>';
		return html;
	}

	/* ------------------------------------------------------------------ */
	/* Modals                                                             */
	/* ------------------------------------------------------------------ */

	function openModal(html, opts) {
		closeModal();
		var bg = document.createElement('div');
		bg.className = 'lgt-modal-bg';
		bg.innerHTML = '<div class="lgt-modal' + (opts && opts.large ? ' lgt-modal-lg' : '') + '">' + html + '</div>';
		document.body.appendChild(bg);
		bg.addEventListener('mousedown', function (e) { if (e.target === bg) { closeModal(); } });
		document.addEventListener('keydown', escClose);
		S.modal = bg;
		var first = bg.querySelector('input:not([type=hidden]),select,textarea');
		if (first) { setTimeout(function () { first.focus(); }, 30); }
		return bg;
	}
	function escClose(e) { if (e.key === 'Escape') { closeModal(); } }
	function closeModal() {
		if (S.modal) { S.modal.remove(); S.modal = null; }
		document.removeEventListener('keydown', escClose);
	}

	function participantForm(p) {
		var t = S.trip, ro = readonly(), isNew = !p;
		p = p || { ptype: 'student', status: 'active', last_name: '', first_name: '', last_name_lat: '', first_name_lat: '', lat_manual: 0, gender: '', birth_date: '', class_name: S.classFilter || '', nationality: S.config.default_nationality || 'GR', doc_type: t.has_flight ? 'ID' : '', doc_number: '', doc_expiry: '', phone: '', notes: '' };
		var req = t.required_fields || [];
		var iss = p.id ? issueOf(p) : null, miss = iss ? iss.missing : [];
		var needDocs = t.has_flight || t.has_ferry;
		function f(key, label, input, hint) {
			return '<div class="lgt-field' + (miss.indexOf(key) >= 0 ? ' lgt-field-missing' : '') + '"><label>' + label + (req.indexOf(key) >= 0 ? ' <span class="lgt-req">*</span>' : '') + '</label>' + input + (hint ? '<div class="lgt-hint">' + hint + '</div>' : '') + '</div>';
		}
		var dis = ro ? ' disabled' : '';
		var html = '<div class="lgt-modal-head"><h3>' + (isNew ? 'Προσθήκη ατόμου' : (ro ? 'Στοιχεία' : 'Επεξεργασία') + ' – ' + h(fullName(p))) + '</h3><button class="lgt-btn-icon" data-act="closeModal">✕</button></div>';
		html += '<div class="lgt-modal-body"><form id="lgt-pform">';
		html += '<div class="lgt-form-row">';
		html += '<div class="lgt-field"><label>Ιδιότητα</label><div class="lgt-seg" data-seg="ptype"><button type="button" data-v="student" class="' + (p.ptype === 'student' ? 'active' : '') + '"' + dis + '>Μαθητής/τρια</button><button type="button" data-v="teacher" class="' + (p.ptype === 'teacher' ? 'active' : '') + '"' + dis + '>Καθηγητής/τρια</button><button type="button" data-v="escort" class="' + (p.ptype === 'escort' ? 'active' : '') + '"' + dis + '>Συνοδός</button></div><input type="hidden" name="ptype" value="' + h(p.ptype) + '"></div>';
		html += '<div class="lgt-field"><label>Φύλο <span class="lgt-req">*</span></label><div class="lgt-seg" data-seg="gender"><button type="button" data-v="M" class="' + (p.gender === 'M' ? 'active' : '') + '"' + dis + '>Αγόρι / Άνδρας</button><button type="button" data-v="F" class="' + (p.gender === 'F' ? 'active' : '') + '"' + dis + '>Κορίτσι / Γυναίκα</button></div><input type="hidden" name="gender" value="' + h(p.gender) + '"></div>';
		html += f('class_name', 'Τμήμα', '<input type="text" name="class_name" list="lgt-classes" value="' + h(p.class_name) + '" placeholder="π.χ. Γ1"' + dis + '><datalist id="lgt-classes">' + classes().map(function (c) { return '<option value="' + h(c) + '">'; }).join('') + '</datalist>');
		html += '</div><div class="lgt-form-row">';
		html += f('last_name', 'Επώνυμο', '<input type="text" name="last_name" required value="' + h(p.last_name) + '" autocomplete="off"' + dis + '>');
		html += f('first_name', 'Όνομα', '<input type="text" name="first_name" required value="' + h(p.first_name) + '" autocomplete="off"' + dis + '>');
		html += '</div><div class="lgt-form-row">';
		html += f('last_name_lat', 'Επώνυμο λατινικά', '<input type="text" name="last_name_lat" class="lgt-lat" value="' + h(p.last_name_lat) + '" style="text-transform:uppercase"' + dis + '>', 'Αυτόματα (ΕΛΟΤ 743). Διορθώστε αν διαφέρει από το έγγραφο.');
		html += f('first_name_lat', 'Όνομα λατινικά', '<input type="text" name="first_name_lat" class="lgt-lat" value="' + h(p.first_name_lat) + '" style="text-transform:uppercase"' + dis + '>', '<label style="text-transform:none;font-weight:normal;display:inline"><input type="checkbox" name="lat_manual" value="1"' + (p.lat_manual ? ' checked' : '') + dis + '> Κλείδωμα (μην αλλάζει αυτόματα)</label>');
		html += '</div>';
		if (needDocs) {
			html += '<div class="lgt-form-row">';
			html += f('birth_date', 'Ημ. γέννησης', '<input type="date" name="birth_date" value="' + h(p.birth_date || '') + '"' + dis + '>');
			html += f('nationality', 'Εθνικότητα', '<input type="text" name="nationality" value="' + h(p.nationality) + '" placeholder="GR" style="text-transform:uppercase"' + dis + '>', 'Κωδικός χώρας, π.χ. GR');
			html += f('doc_type', 'Έγγραφο', '<select name="doc_type"' + dis + '><option value="">—</option><option value="ID"' + (p.doc_type === 'ID' ? ' selected' : '') + '>Ταυτότητα</option><option value="PASSPORT"' + (p.doc_type === 'PASSPORT' ? ' selected' : '') + '>Διαβατήριο</option></select>');
			html += f('doc_number', 'Αριθμός εγγράφου', '<input type="text" name="doc_number" value="' + h(p.doc_number) + '" style="text-transform:uppercase" placeholder="π.χ. ΑΚ123456"' + dis + '>');
			html += f('doc_expiry', 'Λήξη εγγράφου', '<input type="date" name="doc_expiry" value="' + h(p.doc_expiry || '') + '"' + dis + '>', t.has_flight ? 'Απαραίτητο για αεροπορικά' : '');
			html += '</div>';
		} else {
			html += '<details style="margin-bottom:12px"><summary class="lgt-small lgt-muted" style="cursor:pointer">Περισσότερα στοιχεία (ημ. γέννησης, έγγραφα)</summary><div class="lgt-form-row" style="margin-top:10px">';
			html += f('birth_date', 'Ημ. γέννησης', '<input type="date" name="birth_date" value="' + h(p.birth_date || '') + '"' + dis + '>');
			html += f('nationality', 'Εθνικότητα', '<input type="text" name="nationality" value="' + h(p.nationality) + '"' + dis + '>');
			html += f('doc_type', 'Έγγραφο', '<select name="doc_type"' + dis + '><option value="">—</option><option value="ID"' + (p.doc_type === 'ID' ? ' selected' : '') + '>Ταυτότητα</option><option value="PASSPORT"' + (p.doc_type === 'PASSPORT' ? ' selected' : '') + '>Διαβατήριο</option></select>');
			html += f('doc_number', 'Αριθμός εγγράφου', '<input type="text" name="doc_number" value="' + h(p.doc_number) + '"' + dis + '>');
			html += f('doc_expiry', 'Λήξη εγγράφου', '<input type="date" name="doc_expiry" value="' + h(p.doc_expiry || '') + '"' + dis + '>');
			html += '</div></details>';
		}
		html += '<div class="lgt-form-row">';
		html += f('phone', 'Τηλέφωνο (γονέα/συνοδού)', '<input type="text" name="phone" value="' + h(p.phone) + '"' + dis + '>');
		html += f('notes', 'Σημειώσεις', '<input type="text" name="notes" value="' + h(p.notes) + '" placeholder="διατροφή, φάρμακα, ειδικές ανάγκες…"' + dis + '>');
		if (!isNew) { html += f('status', 'Κατάσταση', '<select name="status"' + dis + '><option value="active"' + (p.status === 'active' ? ' selected' : '') + '>Ενεργός/ή</option><option value="cancelled"' + (p.status === 'cancelled' ? ' selected' : '') + '>Ακυρώθηκε (δεν συμμετέχει)</option></select>'); }
		html += '</div>';
		if (iss && iss.warnings && iss.warnings.length) { html += '<div class="lgt-alert lgt-alert-warn">' + iss.warnings.map(h).join('<br>') + '</div>'; }
		html += '</form></div>';
		html += '<div class="lgt-modal-foot">';
		if (!ro) {
			if (isNew) { html += '<label class="lgt-left lgt-small"><input type="checkbox" id="lgt-keep-open" checked> Συνέχεια με επόμενο άτομο</label>'; }
			html += '<button class="lgt-btn" data-act="closeModal">Άκυρο</button><button class="lgt-btn lgt-btn-primary" data-act="saveParticipant" data-pid="' + (p.id || 0) + '">' + (isNew ? 'Προσθήκη' : 'Αποθήκευση') + '</button>';
		} else { html += '<button class="lgt-btn" data-act="closeModal">Κλείσιμο</button>'; }
		html += '</div>';
		var bg = openModal(html);
		var form = bg.querySelector('#lgt-pform');
		/* Live transliteration */
		function syncLat() {
			if (form.lat_manual.checked) { return; }
			form.last_name_lat.value = TR.toLatin(form.last_name.value);
			form.first_name_lat.value = TR.toLatin(form.first_name.value);
		}
		form.last_name.addEventListener('input', syncLat);
		form.first_name.addEventListener('input', syncLat);
		form.first_name.addEventListener('blur', function () {
			if (!form.gender.value) {
				var g = guessGender(form.first_name.value);
				if (g) { form.gender.value = g; bg.querySelectorAll('[data-seg=gender] button').forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-v') === g); }); }
			}
		});
		['last_name_lat', 'first_name_lat'].forEach(function (n) { form[n].addEventListener('input', function () { form.lat_manual.checked = true; }); });
		form.addEventListener('submit', function (e) { e.preventDefault(); saveParticipant(p.id || 0); });
	}

	function readParticipantForm() {
		var form = document.getElementById('lgt-pform');
		if (!form) { return null; }
		var d = {};
		['ptype', 'gender', 'class_name', 'last_name', 'first_name', 'last_name_lat', 'first_name_lat', 'birth_date', 'nationality', 'doc_type', 'doc_number', 'doc_expiry', 'phone', 'notes', 'status'].forEach(function (k) {
			if (form[k]) { d[k] = form[k].value.trim(); }
		});
		d.lat_manual = form.lat_manual && form.lat_manual.checked ? 1 : 0;
		return d;
	}

	function saveParticipant(pid) {
		var d = readParticipantForm();
		if (!d) { return; }
		if (!d.last_name || !d.first_name) { toast('Συμπληρώστε επώνυμο και όνομα.', 'err'); return; }
		var keep = document.getElementById('lgt-keep-open');
		var keepOpen = keep && keep.checked;
		var lastClass = d.class_name, lastType = d.ptype;
		var req = pid ? call('PUT', '/participants/' + pid, d, 'Αποθηκεύτηκε') : call('POST', '/participants', d, 'Προστέθηκε: ' + d.last_name + ' ' + d.first_name);
		req.then(function () {
			closeModal();
			if (!pid && keepOpen) {
				participantForm(null);
				var form = document.getElementById('lgt-pform');
				if (form) {
					form.class_name.value = lastClass;
					form.ptype.value = lastType;
					document.querySelectorAll('[data-seg=ptype] button').forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-v') === lastType); });
				}
			}
		}).catch(function () { /* toast shown */ });
	}

	/* ---------------- Import wizard ---------------- */

	var IMPORT_FIELDS = [
		['', '— αγνόηση —'], ['last_name', 'Επώνυμο'], ['first_name', 'Όνομα'], ['full_name', 'Ονοματεπώνυμο (Επώνυμο Όνομα)'], ['full_name_rev', 'Ονοματεπώνυμο (Όνομα Επώνυμο)'],
		['gender', 'Φύλο'], ['class_name', 'Τμήμα'], ['birth_date', 'Ημ. γέννησης'], ['nationality', 'Εθνικότητα'], ['doc_type', 'Τύπος εγγράφου'], ['doc_number', 'Αρ. εγγράφου'], ['doc_expiry', 'Λήξη εγγράφου'],
		['last_name_lat', 'Επώνυμο λατινικά'], ['first_name_lat', 'Όνομα λατινικά'], ['phone', 'Τηλέφωνο'], ['notes', 'Σημειώσεις'], ['ptype', 'Ιδιότητα (μαθητής/καθηγητής)']
	];
	function guessField(header) {
		var s = (header || '').toLowerCase().trim();
		if (!s) { return ''; }
		var rules = [
			[/επώνυμο|επωνυμο|surname|last\s*name|lastname/, 'last_name'], [/^όνομα$|^ονομα$|first\s*name|firstname|^name$/, 'first_name'], [/ονοματεπ|full\s*name/, 'full_name'],
			[/φύλο|φυλο|gender|sex/, 'gender'], [/τμήμα|τμημα|τάξη|ταξη|class/, 'class_name'], [/γένν|γενν|birth|dob|ημ\.?\s*γ/, 'birth_date'],
			[/εθνικ|nation|υπηκο/, 'nationality'], [/λήξη|ληξη|expir/, 'doc_expiry'], [/τύπος|τυπος|doc\s*type/, 'doc_type'], [/αδτ|α\.δ\.τ|ταυτ|διαβατ|passport|document|doc|αριθ/, 'doc_number'],
			[/τηλ|phone|κινητ/, 'phone'], [/σημει|notes|παρατ/, 'notes'], [/ιδιότ|ιδιοτ|ρόλος|ρολος|type/, 'ptype']
		];
		for (var i = 0; i < rules.length; i++) { if (rules[i][0].test(s)) { return rules[i][1]; } }
		return '';
	}
	function parseClipboard(text) {
		var lines = text.replace(/\r/g, '').split('\n').filter(function (l) { return l.trim() !== ''; });
		if (!lines.length) { return { rows: [], header: false }; }
		var sep = lines[0].indexOf('\t') >= 0 ? '\t' : (lines[0].indexOf(';') >= 0 ? ';' : (lines[0].indexOf(',') >= 0 ? ',' : null));
		var rows = lines.map(function (l) { return sep ? l.split(sep).map(function (c) { return c.trim().replace(/^"|"$/g, ''); }) : [l.trim()]; });
		/* Header detection: first row has no digits and matches a known label. */
		var header = rows[0].some(function (c) { return guessField(c) !== ''; }) && !rows[0].some(function (c) { return /\d{2}/.test(c); });
		return { rows: rows, header: header };
	}
	function importWizard() {
		var html = '<div class="lgt-modal-head"><h3>Μαζική εισαγωγή από Excel</h3><button class="lgt-btn-icon" data-act="closeModal">✕</button></div>';
		html += '<div class="lgt-modal-body">';
		html += '<p class="lgt-small">Αντιγράψτε τις γραμμές από το Excel (με ή χωρίς επικεφαλίδες) και επικολλήστε εδώ. Οι στήλες αναγνωρίζονται αυτόματα, μπορείτε να τις διορθώσετε στην προεπισκόπηση. Αν υπάρχει μία στήλη «Ονοματεπώνυμο», χωρίζεται αυτόματα σε Επώνυμο / Όνομα.</p>';
		html += '<textarea class="lgt-textarea-mono" id="lgt-import-text" placeholder="Επώνυμο\tΌνομα\tΦύλο\tΤμήμα\tΗμ. γέννησης\nΠαπαδόπουλος\tΓιώργος\tΑ\tΓ1\t12/05/2008\n…"></textarea>';
		html += '<div class="lgt-toolbar" style="margin-top:8px"><button class="lgt-btn" data-act="importPreview">Προεπισκόπηση →</button>';
		html += '<label class="lgt-small"><input type="checkbox" id="lgt-import-header"> Η 1η γραμμή είναι επικεφαλίδα</label>';
		html += '<label class="lgt-small">Προεπιλ. τμήμα <input type="text" id="lgt-import-class" class="lgt-input" style="width:70px" value="' + h(S.classFilter) + '"></label>';
		html += '<label class="lgt-small"><input type="checkbox" id="lgt-import-guess" checked> Εκτίμηση φύλου από το όνομα όπου λείπει</label></div>';
		html += '<div id="lgt-import-preview"></div>';
		html += '</div><div class="lgt-modal-foot"><span class="lgt-left lgt-small lgt-muted" id="lgt-import-status"></span><button class="lgt-btn" data-act="closeModal">Άκυρο</button><button class="lgt-btn lgt-btn-primary" data-act="importRun" disabled id="lgt-import-run">Εισαγωγή</button></div>';
		var bg = openModal(html, { large: true });
		var ta = bg.querySelector('#lgt-import-text');
		ta.addEventListener('paste', function () { setTimeout(importPreview, 50); });
	}
	var importParsed = null;
	function importPreview() {
		var bg = S.modal; if (!bg) { return; }
		var text = bg.querySelector('#lgt-import-text').value;
		var parsed = parseClipboard(text);
		var hdrCb = bg.querySelector('#lgt-import-header');
		if (!importParsed || importParsed.text !== text) { hdrCb.checked = parsed.header; }
		var hasHeader = hdrCb.checked;
		var rows = parsed.rows.slice(hasHeader ? 1 : 0);
		var headers = hasHeader ? parsed.rows[0] : [];
		var ncol = Math.max.apply(null, parsed.rows.map(function (r) { return r.length; }).concat([1]));
		var mapping = [];
		for (var c = 0; c < ncol; c++) {
			var g = hasHeader ? guessField(headers[c]) : '';
			if (!g && !hasHeader) {
				/* Positional defaults: Επώνυμο, Όνομα, Φύλο, Τμήμα, Ημ. γέννησης, Εθν., Αρ. εγγράφου */
				g = ['last_name', 'first_name', 'gender', 'class_name', 'birth_date', 'nationality', 'doc_number'][c] || '';
				/* Heuristic: if first column contains a space in most rows → full name. */
				if (c === 0 && rows.filter(function (r) { return /\s/.test(r[0] || ''); }).length > rows.length / 2) { g = 'full_name'; }
			}
			mapping.push(g);
		}
		if (importParsed && importParsed.text === text && importParsed.mapping.length === ncol) { mapping = importParsed.mapping; }
		importParsed = { text: text, rows: rows, mapping: mapping, hasHeader: hasHeader };
		var html = '<div class="lgt-import-preview"><table class="lgt-table"><thead><tr>';
		for (c = 0; c < ncol; c++) {
			html += '<th><select data-col="' + c + '" class="lgt-import-map">' + IMPORT_FIELDS.map(function (f) { return '<option value="' + f[0] + '"' + (mapping[c] === f[0] ? ' selected' : '') + '>' + h(f[1]) + '</option>'; }).join('') + '</select>' + (hasHeader ? '<div class="lgt-small lgt-muted">' + h(headers[c] || '') + '</div>' : '') + '</th>';
		}
		html += '</tr></thead><tbody>';
		rows.slice(0, 15).forEach(function (r) { html += '<tr>' + Array.apply(null, Array(ncol)).map(function (_, i) { return '<td>' + h(r[i] || '') + '</td>'; }).join('') + '</tr>'; });
		if (rows.length > 15) { html += '<tr><td colspan="' + ncol + '" class="lgt-muted">… και ' + (rows.length - 15) + ' ακόμη γραμμές</td></tr>'; }
		html += '</tbody></table></div>';
		bg.querySelector('#lgt-import-preview').innerHTML = html;
		bg.querySelector('#lgt-import-status').textContent = rows.length + ' γραμμές';
		bg.querySelector('#lgt-import-run').disabled = !rows.length;
	}
	function importRun() {
		var bg = S.modal; if (!bg || !importParsed) { return; }
		var mapping = [];
		bg.querySelectorAll('.lgt-import-map').forEach(function (sel) { mapping[parseInt(sel.getAttribute('data-col'), 10)] = sel.value; });
		if (mapping.indexOf('last_name') < 0 && mapping.indexOf('full_name') < 0 && mapping.indexOf('full_name_rev') < 0) { toast('Ορίστε τη στήλη Επώνυμο ή Ονοματεπώνυμο.', 'err'); return; }
		var defClass = bg.querySelector('#lgt-import-class').value.trim();
		var guess = bg.querySelector('#lgt-import-guess').checked;
		var out = [];
		importParsed.rows.forEach(function (r) {
			var d = { class_name: defClass, ptype: 'student' };
			mapping.forEach(function (f, i) {
				var v = (r[i] || '').trim();
				if (!f || !v) { return; }
				if (f === 'full_name' || f === 'full_name_rev') {
					var parts = v.split(/\s+/);
					if (parts.length === 1) { d.last_name = parts[0]; return; }
					if (f === 'full_name') { d.last_name = parts[0]; d.first_name = parts.slice(1).join(' '); }
					else { d.first_name = parts.slice(0, -1).join(' '); d.last_name = parts[parts.length - 1]; }
					return;
				}
				if (f === 'ptype') {
					var lv = v.toLowerCase();
					d.ptype = /καθ|teach|prof|εκπ/.test(lv) ? 'teacher' : (/συνοδ|escort|γον/.test(lv) ? 'escort' : 'student');
					return;
				}
				if (f === 'gender') {
					var g = v.toUpperCase();
					d.gender = /^(M|A|Α|ΑΓ|ΑΡ|ΑΝΔ|MALE|BOY)/.test(g) ? 'M' : (/^(F|Θ|Κ|Γ|FEM|GIRL|W)/.test(g) ? 'F' : '');
					return;
				}
				if (f === 'doc_type') { d.doc_type = /διαβ|pass/i.test(v) ? 'PASSPORT' : 'ID'; return; }
				d[f] = v;
			});
			if (!d.first_name && d.last_name && /\s/.test(d.last_name)) { var np2 = d.last_name.split(/\s+/); d.last_name = np2[0]; d.first_name = np2.slice(1).join(' '); }
			if (!d.gender && guess) { d.gender = guessGender(d.first_name); }
			if (d.last_name && d.first_name) { out.push(d); }
		});
		if (!out.length) { toast('Δεν βρέθηκαν έγκυρες γραμμές.', 'err'); return; }
		call('POST', '/participants/import', { rows: out }).then(function (res) {
			closeModal();
			toast('Προστέθηκαν ' + res.added + ' άτομα' + (res.skipped ? ', παραλείφθηκαν ' + res.skipped + ' (κενά ή διπλά)' : '') + '.', 'ok');
		}).catch(function () { /* toast */ });
	}

	/* ---------------- Auto allocate dialog ---------------- */

	function autoDialog(kind) {
		var types = typesOf(kind), existing = roomsOf(kind).length;
		var html = '<div class="lgt-modal-head"><h3>✨ Αυτόματη κατανομή σε ' + kindLabel(kind, true).toLowerCase() + '</h3><button class="lgt-btn-icon" data-act="closeModal">✕</button></div><div class="lgt-modal-body">';
		html += '<p class="lgt-small">Η κατανομή γίνεται ανά <b>φύλο</b> και <b>τμήμα</b>, με προτίμηση στα μεγαλύτερα ' + kindLabel(kind, true).toLowerCase() + ', χωρίς να μένει κανείς μόνος όπου γίνεται. Οι συνοδοί τοποθετούνται χωριστά (μονόκλινα/δίκλινα). Μετά μπορείτε να σύρετε ονόματα για διορθώσεις.</p>';
		html += '<div class="lgt-field"><label>Τύποι που θα χρησιμοποιηθούν</label>' + types.map(function (x) { return '<label style="display:block;text-transform:none;font-weight:normal"><input type="checkbox" name="type" value="' + h(x.code) + '" checked> ' + h(x.code) + ' – ' + h(x.label) + ' (' + x.capacity + ' άτομα)</label>'; }).join('') + '</div>';
		html += '<p><label><input type="checkbox" id="lgt-auto-class" checked> Κράτηση τμημάτων μαζί</label></p>';
		if (existing) { html += '<p><label><input type="checkbox" id="lgt-auto-only"> Μόνο για όσους δεν έχουν ' + kindLabel(kind).toLowerCase() + ' (διατήρηση υπαρχόντων)</label></p>'; }
		html += '</div><div class="lgt-modal-foot"><button class="lgt-btn" data-act="closeModal">Άκυρο</button><button class="lgt-btn lgt-btn-primary" data-act="autoRun" data-kind="' + kind + '">Κατανομή</button></div>';
		openModal(html);
	}
	function autoRun(kind) {
		var bg = S.modal; if (!bg) { return; }
		var codes = Array.prototype.map.call(bg.querySelectorAll('input[name=type]:checked'), function (c) { return c.value; });
		if (!codes.length) { toast('Επιλέξτε τουλάχιστον έναν τύπο.', 'err'); return; }
		var only = bg.querySelector('#lgt-auto-only');
		call('POST', '/rooms/auto', { kind: kind, type_codes: codes, by_class: bg.querySelector('#lgt-auto-class').checked, only_unassigned: !!(only && only.checked) }).then(function (res) {
			closeModal();
			toast('Δημιουργήθηκαν ' + res.created_rooms + ' ' + kindLabel(kind, true).toLowerCase() + '.', 'ok');
		}).catch(function () { /* toast */ });
	}

	/* ------------------------------------------------------------------ */
	/* Drag & drop                                                        */
	/* ------------------------------------------------------------------ */

	var dragPid = null;
	function bindDnD() {
		if (readonly()) { return; }
		root.querySelectorAll('.lgt-chip-person[draggable=true]').forEach(function (el) {
			el.addEventListener('dragstart', function (e) {
				dragPid = parseInt(el.getAttribute('data-pid'), 10);
				el.classList.add('lgt-dragging');
				try { e.dataTransfer.setData('text/plain', String(dragPid)); } catch (err) { /* ignore */ }
				e.dataTransfer.effectAllowed = 'move';
			});
			el.addEventListener('dragend', function () { el.classList.remove('lgt-dragging'); dragPid = null; });
		});
		root.querySelectorAll('[data-drop]').forEach(function (zone) {
			zone.addEventListener('dragover', function (e) { e.preventDefault(); zone.classList.add('lgt-drop-hover'); e.dataTransfer.dropEffect = 'move'; });
			zone.addEventListener('dragleave', function () { zone.classList.remove('lgt-drop-hover'); });
			zone.addEventListener('drop', function (e) {
				e.preventDefault();
				e.stopPropagation();
				zone.classList.remove('lgt-drop-hover');
				var pid = dragPid || parseInt(e.dataTransfer.getData('text/plain'), 10);
				if (!pid) { return; }
				var roomId = parseInt(zone.getAttribute('data-drop'), 10), kind = zone.getAttribute('data-kind');
				if (roomId && roomOf(kind, pid) === roomId) { return; }
				if (roomId && !confirmOverfill(roomId, 1)) { return; }
				call('POST', '/rooms/assign', { kind: kind, room_id: roomId, participant_ids: [pid] });
			});
		});
	}
	function confirmOverfill(roomId, adding) {
		var r = S.rooms.filter(function (x) { return x.id === roomId; })[0];
		if (!r) { return true; }
		var n = membersOf(r.id).length + adding;
		if (n > r.capacity) { return window.confirm('Το ' + kindLabel(r.kind).toLowerCase() + ' ' + r.label + ' έχει χωρητικότητα ' + r.capacity + ' και θα έχει ' + n + ' άτομα. Συνέχεια;'); }
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Events                                                             */
	/* ------------------------------------------------------------------ */

	document.addEventListener('click', function (e) {
		var tabBtn = e.target.closest('.lgt-tab, .lgt-step');
		if (tabBtn && root.contains(tabBtn)) { S.tab = tabBtn.getAttribute('data-tab'); S.selected = {}; location.hash = S.tab; render(); return; }
		var seg = e.target.closest('.lgt-seg button');
		if (seg && S.modal && S.modal.contains(seg)) {
			var wrap = seg.parentNode, name = wrap.getAttribute('data-seg');
			wrap.querySelectorAll('button').forEach(function (b) { b.classList.remove('active'); });
			seg.classList.add('active');
			var input = S.modal.querySelector('input[name=' + name + ']');
			if (input) { input.value = seg.getAttribute('data-v'); }
			return;
		}
		var btn = e.target.closest('[data-act]');
		if (!btn) { return; }
		if (!root.contains(btn) && !(S.modal && S.modal.contains(btn))) { return; }
		var act = btn.getAttribute('data-act'), pid = parseInt(btn.getAttribute('data-pid') || '0', 10), rid = parseInt(btn.getAttribute('data-rid') || '0', 10), kind = btn.getAttribute('data-kind');
		if (btn.tagName === 'A') { e.preventDefault(); }
		switch (act) {
			case 'add': participantForm(null); break;
			case 'edit': participantForm(byId(pid)); break;
			case 'delete':
				var p = byId(pid);
				if (p && window.confirm('Διαγραφή: ' + fullName(p) + ';\n\nΑν απλώς δεν συμμετέχει πια, προτιμήστε «Ακυρώθηκε» στην επεξεργασία.')) { call('DELETE', '/participants/' + pid, null, 'Διαγράφηκε'); }
				break;
			case 'saveParticipant': saveParticipant(pid); break;
			case 'closeModal': closeModal(); break;
			case 'import': importWizard(); break;
			case 'importPreview': importPreview(); break;
			case 'importRun': importRun(); break;
			case 'addRoom':
				call('POST', '/rooms', { kind: kind, type_code: btn.getAttribute('data-type') || '', count: 1 });
				break;
			case 'moreRows':
				ensureBlankRows(blankRows.length + 10);
				render();
				break;
			case 'auto': autoDialog(kind); break;
			case 'autoRun': autoRun(kind); break;
			case 'copy':
				var from = kind === 'hotel' ? 'cabin' : 'hotel';
				if (roomsOf(kind).length && !window.confirm('Η υπάρχουσα κατανομή σε ' + kindLabel(kind, true).toLowerCase() + ' θα αντικατασταθεί από τις παρέες των ' + kindLabel(from, true).toLowerCase() + '. Συνέχεια;')) { break; }
				call('POST', '/rooms/copy', { from: from, to: kind }).then(function (res) { toast('Δημιουργήθηκαν ' + res.created_rooms + ' ' + kindLabel(kind, true).toLowerCase() + ' με τις ίδιες παρέες.', 'ok'); }).catch(function () { /* toast */ });
				break;
			case 'clear':
				if (window.confirm('Διαγραφή όλων των ' + kindLabel(kind, true).toLowerCase() + ' και της κατανομής;')) { call('POST', '/rooms/clear', { kind: kind }); }
				break;
			case 'deleteRoom':
				var rm = S.rooms.filter(function (x) { return x.id === rid; })[0];
				if (rm && (!membersOf(rid).length || window.confirm('Διαγραφή ' + kindLabel(rm.kind).toLowerCase() + ' ' + rm.label + '; Τα άτομα θα μείνουν χωρίς ' + kindLabel(rm.kind).toLowerCase() + '.'))) { call('DELETE', '/rooms/' + rid); }
				break;
			case 'renameRoom':
				if (readonly()) { break; }
				var r0 = S.rooms.filter(function (x) { return x.id === rid; })[0];
				var nl = window.prompt('Αριθμός / όνομα ' + kindLabel(r0.kind).toLowerCase() + ':', r0.label);
				if (nl !== null && nl.trim() !== '' && nl !== r0.label) { call('PUT', '/rooms/' + rid, { label: nl.trim() }); }
				break;
			case 'unassign': call('POST', '/rooms/assign', { kind: kind, room_id: 0, participant_ids: [pid] }); break;
			case 'submit':
				var msgEl = root.querySelector('[data-role=submitMsg]');
				if (!window.confirm(isAdmin() ? 'Αποστολή των λιστών στα email του γραφείου;' : 'Υποβολή της λίστας στο γραφείο;')) { break; }
				call('POST', isAdmin() ? '/send' : '/submit', { message: msgEl ? msgEl.value : '' }).then(function () { toast(isAdmin() ? 'Οι λίστες στάλθηκαν.' : 'Η λίστα υποβλήθηκε. Θα λάβετε επιβεβαίωση με email.', 'ok'); }).catch(function () { /* toast */ });
				break;
			case 'saveContact':
				var d = {};
				root.querySelectorAll('[data-field]').forEach(function (i) { d[i.getAttribute('data-field')] = i.value.trim(); });
				call('PATCH', '', d, 'Αποθηκεύτηκε');
				break;
			case 'link':
				var la = btn.getAttribute('data-link');
				if (la === 'regenerate' && !window.confirm('Ο παλιός σύνδεσμος θα πάψει να λειτουργεί. Συνέχεια;')) { break; }
				if (la === 'close' && !window.confirm('Κλείσιμο καταχώρησης; Το σχολείο θα βλέπει τις λίστες αλλά δεν θα μπορεί να κάνει αλλαγές.')) { break; }
				var lm = '';
				if (la === 'send_link') { lm = window.prompt('Προαιρετικό μήνυμα προς το σχολείο:', '') || ''; }
				call('POST', '/link', { action: la, message: lm }).then(function (res) {
					if (la === 'send_link') { toast(res.sent ? 'Ο σύνδεσμος στάλθηκε στο ' + S.trip.school_email : 'Αποτυχία αποστολής', res.sent ? 'ok' : 'err'); }
					else { toast('OK', 'ok'); }
				}).catch(function () { /* toast */ });
				break;
			case 'copyLink':
				var inp = root.querySelector('[data-role=portalUrl]');
				if (inp) { inp.select(); try { navigator.clipboard.writeText(inp.value); toast('Αντιγράφηκε', 'ok'); } catch (err) { document.execCommand('copy'); } }
				break;
		}
	});

	document.addEventListener('change', function (e) {
		var el = e.target;
		if (!root.contains(el)) { return; }
		var role = el.getAttribute('data-role');
		if (role === 'classFilter') { S.classFilter = el.value; render(); return; }
		if (role === 'showCancelled') { S.showCancelled = el.checked; render(); return; }
		if (el.getAttribute('data-act') === 'slotPick') {
			var pid = parseInt(el.value, 10);
			if (pid) { call('POST', '/rooms/assign', { kind: el.getAttribute('data-kind'), room_id: parseInt(el.getAttribute('data-rid'), 10), participant_ids: [pid] }); }
			return;
		}
		if (el.getAttribute('data-f') && el.getAttribute('data-pid')) { saveCell(el); }
	});
	var searchTimer;
	document.addEventListener('input', function (e) {
		var el = e.target;
		if (!root.contains(el)) { return; }
		if (el.getAttribute('data-role') === 'search') {
			clearTimeout(searchTimer);
			searchTimer = setTimeout(function () {
				S.search = el.value;
				render();
				var s = root.querySelector('[data-role=search]');
				if (s) { s.focus(); s.setSelectionRange(s.value.length, s.value.length); }
			}, 250);
			return;
		}
		var f = el.getAttribute('data-f'), pid = el.getAttribute('data-pid');
		if (!f || !pid) { return; }
		if (f === 'birth_date' || f === 'doc_expiry') {
			var fv = formatDateTyping(el.value);
			if (fv !== el.value) { el.value = fv; }
			return;
		}
		if (f === 'last_name' || f === 'first_name') {
			var p = isTemp(pid) ? null : byId(parseInt(pid, 10));
			if (latManual[pid] || (p && p.lat_manual)) { return; }
			var target = root.querySelector('[data-pid="' + pid + '"][data-f="' + f + '_lat"]');
			if (target) { target.value = TR.toLatin(el.value); }
			return;
		}
		if (f === 'last_name_lat' || f === 'first_name_lat') { latManual[pid] = true; el.value = el.value.toUpperCase(); }
	});
	document.addEventListener('paste', function (e) {
		var el = e.target;
		if (!root.contains(el) || !el.getAttribute('data-f') || readonly()) { return; }
		var text = (e.clipboardData || window.clipboardData).getData('text');
		if (text && gridPaste(el, text)) { e.preventDefault(); }
	});
	/* Enter in a grid cell moves down like a spreadsheet. */
	document.addEventListener('keydown', function (e) {
		var el = e.target;
		if (e.key !== 'Enter' || !root.contains(el) || !el.getAttribute('data-f') || el.tagName !== 'INPUT') { return; }
		e.preventDefault();
		var row = el.closest('tr'), next = row && row.nextElementSibling;
		if (next) { var cell = next.querySelector('[data-f="' + el.getAttribute('data-f') + '"]'); if (cell) { cell.focus(); return; } }
		el.blur();
	});

	/* ------------------------------------------------------------------ */
	/* Boot                                                               */
	/* ------------------------------------------------------------------ */

	api('GET', '/bootstrap').then(function (data) {
		applyState(data);
		S.loaded = true;
		render();
	}).catch(function (e) {
		root.innerHTML = '<div class="lgt-card"><div class="lgt-alert lgt-alert-error">' + h(e.message) + '</div></div>';
	});
})();
