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
