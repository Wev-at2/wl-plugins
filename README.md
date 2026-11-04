# WL Plugins

WordPress plugins by [Wisionary Lab](https://wisionarylab.com/).

| Plugin | What it does |
| --- | --- |
| [WL Login Guard](wl-login-guard/) | Moves the login to a custom address, hides wp-login.php and /wp-admin from logged-out visitors, blocks XML-RPC and hides usernames. |
| [WL Cookie Banner](wl-cookie-banner/) | Cookie notice with accept, reject and per-category choice. Works with the WP Consent API and Google Consent Mode v2. |
| [WL Updater](wl-updater/) | Keeps the plugins above up to date from this repository, through the normal WordPress update screen. |

Code and texts are in English, with a bundled Brazilian Portuguese translation.

## Install

Download the zip from [dist/](dist/) and upload it in Plugins > Add New > Upload Plugin.

Install WL Updater too and the other plugins get their updates from this repository. Each package is checked against the SHA-256 in [manifest.json](manifest.json) before it is installed.

## Release a new version

1. Bump `Version` in the plugin header and `Stable tag` in readme.txt, and add the changelog entry.
2. `python -I i18n.py <slug>` updates the translation files and fails if a text has no pt_BR translation.
3. `python -I build.py <slug>` writes `dist/<slug>-<version>.zip` and updates `manifest.json`.
4. Commit and push. Sites with WL Updater see the update within 6 hours, or with Dashboard > Updates > Check again. GitHub's CDN can take about 5 minutes to serve the new manifest.

A published zip never changes. `build.py` refuses to rebuild a version that is already in the manifest with different content: bump the version instead.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
