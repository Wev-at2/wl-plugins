=== WL Updater ===
Contributors: wisionarylab
Tags: updates, github
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Keeps Wisionary Lab plugins up to date from GitHub, through the normal WordPress update screen.

== Description ==

Internal plugin for sites built by Wisionary Lab. It is not published on WordPress.org.

* Reads manifest.json from https://github.com/Wev-at2/wl-plugins every 6 hours.
* Installed WL plugins with a newer version in the manifest show up in Dashboard > Updates and on the Plugins screen, like any other update. Auto-updates can be turned on per plugin.
* Every package is downloaded over HTTPS from GitHub and checked against the SHA-256 in the manifest before it is installed.
* "Check again" on Dashboard > Updates reloads the manifest right away.
* WL Updater updates itself the same way.

== Installation ==

1. Install the zip from the dist folder of the repository and activate it.
2. Dashboard > Updates > Check again.

To use another manifest, add `define( 'WL_UPDATER_MANIFEST', 'https://...' );` to wp-config.php.

== Changelog ==

= 1.0.0 =
* First version.
