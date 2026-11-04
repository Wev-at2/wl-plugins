=== WL Login Guard ===
Contributors: wisionarylab
Tags: login, hide login, security, xml-rpc, user enumeration
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Moves the login to a custom address, hides wp-login.php and /wp-admin from logged-out visitors, blocks XML-RPC and hides usernames.

== Description ==

Bots look for /wp-login.php and /wp-admin on every WordPress site. WL Login Guard moves the login screen to an address you choose and shows the site's normal 404 page on the default ones.

**Login address**

* The login moves to /wl-admin by default. You can change it in Settings > General.
* /wp-admin, /wp-login.php, /wp-register.php and /wp-signup.php show the theme's 404 page to logged-out visitors.
* /login, /admin and /dashboard no longer redirect to the login screen.
* Log out, lost password and reset password links, and WordPress emails, use the new address.
* A logged-in user who opens the login address goes straight to the dashboard.
* admin-ajax.php, admin-post.php, WP-Cron and the REST API keep working.

**XML-RPC**

* xmlrpc.php accepts password attempts without going through the login screen. The plugin blocks it and removes the X-Pingback header.

**Usernames**

* The REST API user list (/wp-json/wp/v2/users) is only available to logged-in users.
* /?author=1 and author pages return a 404, and author links point to the home page.
* oEmbed data and the users sitemap no longer show usernames.
* The login screen shows the same error for an unknown user and a wrong password.

Each protection can be turned off in Settings > General.

The plugin has no settings page of its own, no ads and no external requests.

== Installation ==

1. Install and activate the plugin.
2. The notice at the top of the screen shows your new login address. Bookmark it.
3. To change the address or turn a protection off, go to Settings > General, WL Login Guard section.

If the site uses a page cache (LiteSpeed, the host's cache, Cloudflare), purge it after activation and exclude the login address from the cache.

== Frequently Asked Questions ==

= I forgot the login address. How do I get back in? =

Any one of these works:

* Rename the folder wp-content/plugins/wl-login-guard by FTP or your host's file manager. The login goes back to /wp-admin.
* Add `define( 'WL_LOGIN_GUARD_DISABLE', true );` to wp-config.php to turn the plugin off.
* Add `define( 'WL_LOGIN_SLUG', 'another-address' );` to wp-config.php to force a login address.

= Does it work with the WordPress mobile app or Jetpack? =

Both need XML-RPC. Uncheck "Block XML-RPC" in Settings > General.

= Can I use it with other plugins that hide the login? =

No. Use only one plugin that changes the login address, otherwise they redirect against each other.

= Does it work on multisite? =

It was built and tested on single sites. Multisite is not supported yet.

= What happens to password protected posts? =

They keep working. The password form still posts to wp-login.php, which accepts only that request.

== Changelog ==

= 1.2.0 =
* Code and texts in English, with a bundled Brazilian Portuguese translation.
* Safer handling of the request address and query arguments.

= 1.1.0 =
* Blocks XML-RPC.
* Hides usernames (REST API, author pages, oEmbed, sitemap and login error).
* Settings to turn each protection on and off.

= 1.0.0 =
* First version.
