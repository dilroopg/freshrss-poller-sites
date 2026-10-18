/**
 * pol-ler Sites — feed page badge
 *
 * Adds one line to the feed header block (the one that shows the last update),
 * explaining whether pol-ler is handling this feed and which template built it.
 *
 * Pure DOM: no core files are touched, so this survives FreshRSS upgrades and
 * works on any install. The data arrives from the extension's js_vars hook.
 */
(function () {
	'use strict';

	function map() {
		// js_vars data is merged under context.extensions by FreshRSS
		// (app/views/helpers/javascript_vars.phtml), not directly under context.
		var ctx = window.context || {};
		var ext = ctx.extensions || {};
		return ext.pollerSites || {};
	}

	function build(info) {
		var wrap = document.createElement('span');
		wrap.className = 'poller-sites-badge';
		wrap.style.display = 'inline-block';
		wrap.style.marginTop = '4px';

		var dot = document.createElement('span');
		dot.textContent = '⟳ ';
		dot.setAttribute('aria-hidden', 'true');

		var text = document.createElement('span');
		if (info.role === 'twin') {
			text.textContent = 'pol-ler — built from template ' + info.template +
				' (' + info.domain + ')';
		} else {
			text.textContent = 'pol-ler — ' + info.domain + ' template ' + info.template +
				', twin follows automatically';
		}

		wrap.appendChild(dot);
		wrap.appendChild(text);
		return wrap;
	}

	function decorate() {
		var block = document.getElementById('feed_update');
		if (!block) { return; }
		if (block.querySelector('.poller-sites-badge')) { return; }

		var feedId = block.getAttribute('data-feed-id');
		if (!feedId) { return; }

		var info = map()[feedId];
		if (!info) { return; }

		// Place it inside the status paragraph that carries "last update", so it
		// reads as part of that block rather than floating somewhere unrelated.
		var host = block.querySelector('.alert-success, .alert-error, .alert-warn');
		if (!host) { return; }

		var badge = build(info);
		if (host.tagName === 'P') {
			host.appendChild(document.createElement('br'));
			host.appendChild(badge);
		} else {
			host.appendChild(badge);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', decorate);
	} else {
		decorate();
	}

	// The feed slider loads by AJAX, so re-check after FreshRSS swaps content in.
	window.addEventListener('freshrss:load', decorate);
	document.addEventListener('freshrss:load', decorate);
})();
