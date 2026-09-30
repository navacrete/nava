/* Admin helpers: copy link, confirm dialogs, types editor rows, media picker. */
(function () {
	'use strict';
	document.addEventListener('click', function (e) {
		var copy = e.target.closest('.lgt-copy');
		if (copy) {
			e.preventDefault();
			var txt = copy.getAttribute('data-copy');
			if (navigator.clipboard) {
				navigator.clipboard.writeText(txt).then(function () {
					var old = copy.textContent;
					copy.textContent = '✓';
					setTimeout(function () { copy.textContent = old; }, 1200);
				});
			} else {
				window.prompt('Αντιγραφή συνδέσμου:', txt);
			}
			return;
		}
		var confirmEl = e.target.closest('.lgt-confirm');
		if (confirmEl && !window.confirm(confirmEl.getAttribute('data-confirm') || 'Είστε σίγουροι;')) {
			e.preventDefault();
			return;
		}
		var rm = e.target.closest('.lgt-row-remove');
		if (rm) {
			e.preventDefault();
			rm.closest('tr').remove();
			return;
		}
		var add = e.target.closest('.lgt-row-add');
		if (add) {
			e.preventDefault();
			var editor = add.closest('.lgt-types-editor');
			var name = editor.getAttribute('data-name');
			var tbody = editor.querySelector('tbody');
			var idx = Date.now() % 100000;
			var tr = document.createElement('tr');
			tr.innerHTML =
				'<td><input type="text" name="' + name + '[' + idx + '][code]" class="small-text" style="width:70px" placeholder="DBL"></td>' +
				'<td><input type="text" name="' + name + '[' + idx + '][label]" placeholder="Περιγραφή"></td>' +
				'<td><input type="number" min="1" max="12" name="' + name + '[' + idx + '][capacity]" value="2" class="small-text"></td>' +
				'<td><input type="number" min="0" name="' + name + '[' + idx + '][quota]" class="small-text" placeholder="—"></td>' +
				'<td><button type="button" class="button-link lgt-row-remove" title="Αφαίρεση">✕</button></td>';
			tbody.appendChild(tr);
			return;
		}
		if (e.target.id === 'lgt-logo-pick' && window.wp && wp.media) {
			e.preventDefault();
			var frame = wp.media({ title: 'Επιλογή λογοτύπου (JPG)', multiple: false, library: { type: 'image/jpeg' } });
			frame.on('select', function () {
				var att = frame.state().get('selection').first().toJSON();
				document.getElementById('lgt-logo').value = att.url;
			});
			frame.open();
		}
	});
})();
