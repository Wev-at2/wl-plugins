/* WL Cookie Banner 1.1.0 | Wisionary Lab */
(function () {
	'use strict';

	var cfg = window.WLCB_CONFIG || {};
	var COOKIE = cfg.cookie || 'wl_cookie_consent';
	var REV = cfg.rev || 1;
	var DAYS = cfg.days || 180;
	var CATS = cfg.categories || ['s'];

	var banner = document.getElementById('wl-cookie-banner');
	var reopen = document.getElementById('wl-cookie-reopen');
	if (!banner) return;

	var prefs = banner.querySelector('#wlcb-prefs');
	var btnCustomize = banner.querySelector('[data-wlcb="customize"]');
	var btnSave = banner.querySelector('[data-wlcb="save"]');
	var btnReject = banner.querySelector('[data-wlcb="reject"]');
	var btnAccept = banner.querySelector('[data-wlcb="accept"]');
	var lastFocus = null;

	/* ---------- Cookie ---------- */

	function read() {
		var m = document.cookie.match(new RegExp('(?:^|; )' + COOKIE + '=([^;]*)'));
		if (!m) return null;
		try {
			var c = JSON.parse(decodeURIComponent(m[1]));
			return c && c.r === REV ? c : null;
		} catch (e) {
			return null;
		}
	}

	function write(c) {
		var value = encodeURIComponent(JSON.stringify(c));
		var secure = location.protocol === 'https:' ? '; Secure' : '';
		document.cookie = COOKIE + '=' + value + '; Max-Age=' + DAYS * 86400 + '; Path=/; SameSite=Lax' + secure;
	}

	/* Deletes Google Analytics cookies when the visitor withdraws permission. */
	function dropAnalyticsCookies() {
		var host = location.hostname.split('.');
		var domains = [''];
		for (var i = 0; i < host.length - 1; i++) {
			domains.push('; Domain=.' + host.slice(i).join('.'));
		}
		document.cookie.split('; ').forEach(function (pair) {
			var name = pair.split('=')[0];
			if (/^(_ga|_gid|_gat|_gcl_)/.test(name)) {
				domains.forEach(function (d) {
					document.cookie = name + '=; Max-Age=0; Path=/' + d;
				});
			}
		});
	}

	/* ---------- Apply the choice ---------- */

	function apply(c) {
		var g = function (v) { return v ? 'granted' : 'denied'; };

		if (typeof window.gtag === 'function') {
			window.gtag('consent', 'update', {
				analytics_storage: g(c.s),
				ad_storage: g(c.m),
				ad_user_data: g(c.m),
				ad_personalization: g(c.m),
				personalization_storage: g(c.p)
			});
		}

		// WP Consent API: Site Kit and other plugins listen to this change.
		if (typeof window.wp_set_consent === 'function') {
			var a = function (v) { return v ? 'allow' : 'deny'; };
			window.wp_set_consent('functional', 'allow');
			window.wp_set_consent('statistics', a(c.s));
			window.wp_set_consent('statistics-anonymous', a(c.s));
			window.wp_set_consent('marketing', a(c.m));
			window.wp_set_consent('preferences', a(c.p));
		}

		if (!c.s) dropAnalyticsCookies();

		document.dispatchEvent(new CustomEvent('wl:cookie-consent', { detail: c }));
	}

	function choose(values) {
		var c = { r: REV, s: 0, m: 0, p: 0, t: Date.now() };
		CATS.forEach(function (k) { c[k] = values[k] ? 1 : 0; });
		write(c);
		apply(c);
		hide();
	}

	function all(v) {
		var out = {};
		CATS.forEach(function (k) { out[k] = v; });
		return out;
	}

	/* ---------- Interface ---------- */

	function setPrefs(open) {
		prefs.hidden = !open;
		btnSave.hidden = !open;
		btnCustomize.hidden = open;
		btnCustomize.setAttribute('aria-expanded', open ? 'true' : 'false');
		if (open) {
			var current = read() || {};
			prefs.querySelectorAll('[data-cat]').forEach(function (input) {
				input.checked = !!current[input.getAttribute('data-cat')];
			});
			var first = prefs.querySelector('[data-cat]') || btnSave;
			first.focus();
		}
	}

	function show(withPrefs) {
		lastFocus = document.activeElement;
		banner.hidden = false;
		if (reopen) reopen.hidden = true;
		setPrefs(!!withPrefs);
		requestAnimationFrame(function () { banner.classList.add('is-open'); });
	}

	function hide() {
		banner.classList.remove('is-open');
		banner.hidden = true;
		setPrefs(false);
		if (reopen && cfg.reopen) reopen.hidden = false;
		if (lastFocus && lastFocus !== document.body && document.contains(lastFocus)) {
			lastFocus.focus();
		}
	}

	btnAccept.addEventListener('click', function () { choose(all(true)); });
	btnReject.addEventListener('click', function () { choose(all(false)); });
	btnCustomize.addEventListener('click', function () { setPrefs(true); });
	btnSave.addEventListener('click', function () {
		var values = {};
		prefs.querySelectorAll('[data-cat]').forEach(function (input) {
			values[input.getAttribute('data-cat')] = input.checked;
		});
		choose(values);
	});

	banner.addEventListener('keydown', function (e) {
		if (e.key === 'Escape' && !prefs.hidden) {
			setPrefs(false);
			btnCustomize.focus();
		}
	});

	if (reopen) {
		reopen.addEventListener('click', function () { show(true); });
	}

	document.addEventListener('click', function (e) {
		var t = e.target.closest && e.target.closest('[data-wl-cookies], a[href="#wl-cookies"]');
		if (!t) return;
		e.preventDefault();
		show(true);
	});

	window.WLCookies = {
		open: function () { show(true); },
		get: read
	};

	/* ---------- Start ---------- */

	var saved = read();
	if (saved) {
		// The "denied" default was already updated in <head>; this tells the WP Consent API.
		apply(saved);
		if (reopen && cfg.reopen) reopen.hidden = false;
	} else {
		show(false);
	}
})();
