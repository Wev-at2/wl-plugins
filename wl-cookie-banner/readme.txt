=== WL Cookie Banner ===
Contributors: wisionarylab
Tags: cookies, consent, gdpr, lgpd, consent mode
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight cookie notice with accept, reject and per-category choice. Works with the WP Consent API and Google Consent Mode v2.

== Description ==

A small cookie notice that asks before anything is tracked. Built for LGPD (Brazil) and GDPR style opt-in.

* Accept, Reject and Customize buttons. Reject has the same visual weight as Accept.
* Categories: Necessary (always on), Statistics, Marketing and Preferences. Show only the ones the site uses.
* Google Consent Mode v2: everything starts as denied for every visitor until they choose. The default is printed before any Google tag.
* Registers as the consent manager in the WP Consent API, so Site Kit by Google and other compatible plugins respect the choice with no extra setup.
* Withdrawing the Statistics permission deletes the Google Analytics cookies.
* A small button in the corner lets visitors review their choice. You can also use the [wl_cookies] shortcode or any link to #wl-cookies.
* Dark or light look and a custom accent color.
* Works with page cache: the banner is decided in the browser.
* Changing the categories, or checking "Ask again", shows the banner again to everyone.

No external requests, no tracking, no ads. The script and styles are small and load only on the front end.

== Installation ==

1. Install and activate the WP Consent API plugin (recommended, free).
2. Install and activate WL Cookie Banner.
3. Go to Settings > Cookies, review the text and check only the categories your site uses.
4. Publish a privacy policy and select it in Settings > Privacy. The banner links to it automatically.

== Frequently Asked Questions ==

= Do I need the WP Consent API? =

The banner works without it and still sets Google Consent Mode. Plugins that read the WP Consent API, such as Site Kit by Google, only respect the choice when it is installed.

= How do I add a link to reopen the preferences? =

Use the shortcode `[wl_cookies]`, or `[wl_cookies text="Cookie settings"]` for a custom label. Any link to `#wl-cookies` or element with the `data-wl-cookies` attribute also works.

= Does it block scripts before consent? =

It does not rewrite or block script tags. It sets Google Consent Mode and the WP Consent API, which Google tags and compatible plugins follow. Scripts that ignore both need their own integration, see the developer notes below.

= Is the banner accessible? =

It uses a labelled dialog, real buttons and checkboxes, works with the keyboard (Esc closes the preferences) and respects reduced motion.

== Privacy ==

This plugin does not send data anywhere. It stores the visitor's choice in one first party cookie, `wl_cookie_consent`, for the number of days set in the settings (180 by default). The WP Consent API stores its own `wp_consent_*` cookies.

== For developers ==

* Cookie `wl_cookie_consent`: JSON with r (revision), s (statistics), m (marketing), p (preferences) and t (timestamp).
* Event: `document.addEventListener( 'wl:cookie-consent', e => console.log( e.detail ) );`
* JavaScript: `WLCookies.open()` opens the preferences. `WLCookies.get()` returns the current choice or null.

== Changelog ==

= 1.1.0 =
* Code and texts in English, with a bundled Brazilian Portuguese translation.
* The shortcode accepts the `text` attribute. The old `texto` attribute still works.

= 1.0.0 =
* First version.
