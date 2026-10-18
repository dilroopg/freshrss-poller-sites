/**
 * pol-ler Sites — configuration page behaviour.
 *
 * Lives in its own file rather than inline in configure.phtml because FreshRSS
 * loads that view inside the AJAX slider (configureAction nulls the layout and
 * the fragment is injected by JS). Inline <script> in an injected fragment does
 * not reliably execute, which made sites_json arrive empty and the server refuse
 * the save with "the site list could not be read".
 */
(function () {
	'use strict';

	function buildSites() {
		var table = document.getElementById('sites-table');
		if (!table || !table.tBodies[0]) { return null; }
		var out = [];
		table.tBodies[0].querySelectorAll('tr[data-i]').forEach(function (tr) {
			var d = tr.querySelector('.site-domain');
			var t = tr.querySelector('.site-template');
			if (!d || d.value.trim() === '') { return; }
			if (!t || t.value.trim() === '') { return; }
			var mode = tr.querySelector('.site-mode');
			var iv = tr.querySelector('.site-interval');
			var on = tr.querySelector('.site-enabled');
			var w = tr.querySelector('.site-watch');
			out.push({
				domain: d.value.trim(),
				template: t.value.trim(),
				mode: mode ? mode.value : 'both',
				interval: parseInt(iv && iv.value, 10) || 15,
				disabled: on ? !on.checked : false,
				watch: w ? !!w.checked : false
			});
		});
		return out;
	}

	function setHidden(form, name, value) {
		var el = form.querySelector('input[name="' + name + '"]');
		if (!el) {
			el = document.createElement('input');
			el.type = 'hidden';
			el.name = name;
			form.appendChild(el);
		}
		el.value = value;
	}

	// Delegated from document so it works whether the page loaded normally or
	// was injected into the slider.
	document.addEventListener('click', function (ev) {
		var t = ev.target;

		var del = t.closest && t.closest('#sites-table .site-del');
		if (del) {
			var tr = del.closest('tr');
			var domain = (tr.querySelector('.site-domain') || {}).value || '';
			if (domain && !window.confirm('Stop managing ' + domain + '?\n\nNothing is deleted from pol-ler or FreshRSS. Recoverable here for 10 days.\n\nSave to apply.')) {
				return;
			}
			tr.remove();
			return;
		}

		var run = t.closest && t.closest('#sites-table .site-run');
		if (run) {
			var form = document.getElementById('poller-sites-form');
			if (!form) { return; }
			setHidden(form, 'act', 'run');
			setHidden(form, 'key', run.getAttribute('data-key') || '');
			return;
		}

		if (t.id === 'site-add') {
			var tpl = document.getElementById('site-row');
			var tbody = document.getElementById('sites-table');
			if (!tpl || !tbody) { return; }
			tbody = tbody.tBodies[0];
			var next = tbody.querySelectorAll('tr[data-i]').length;
			var row = document.createElement('tr');
			row.innerHTML = tpl.innerHTML.replace('__I__', next);
			tbody.appendChild(row);
			var empty = tbody.querySelector('tr.empty');
			if (empty) { empty.remove(); }
		}
	}, true);

	// Set the payload on submit, in the capture phase so it lands before the
	// browser serialises the form.
	document.addEventListener('submit', function (ev) {
		var form = ev.target;
		if (!form || form.id !== 'poller-sites-form') { return; }
		var field = document.getElementById('sites_json');
		if (!field) { return; }
		var sites = buildSites();
		if (sites !== null) {
			field.value = JSON.stringify(sites);
		}
	}, true);
})();
