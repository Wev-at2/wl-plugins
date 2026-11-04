<?php
/**
 * Plugin Name:       WL Updater
 * Plugin URI:        https://github.com/Wev-at2/wl-plugins
 * Description:       Keeps Wisionary Lab plugins up to date from GitHub, through the normal WordPress update screen.
 * Version:           1.0.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Wisionary Lab
 * Author URI:        https://wisionarylab.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wl-updater
 * Domain Path:       /languages
 * Update URI:        https://github.com/Wev-at2/wl-plugins
 *
 * The list of plugins and versions comes from manifest.json in the
 * repository. Define WL_UPDATER_MANIFEST in wp-config.php to use another one.
 */

defined( 'ABSPATH' ) || exit;

final class WL_Updater {

	const VERSION   = '1.0.1';
	const MANIFEST  = 'https://raw.githubusercontent.com/Wev-at2/wl-plugins/main/manifest.json';
	const CACHE     = 'wl_updater_manifest';
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/** Hosts a package may be downloaded from. */
	const HOSTS = array( 'raw.githubusercontent.com', 'github.com', 'objects.githubusercontent.com' );

	public function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_updates' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_pre_download', array( $this, 'verified_download' ), 10, 4 );
		add_action( 'load-update-core.php', array( $this, 'maybe_flush' ) );
	}

	/** Bundled translations. A language pack, if one exists, always wins. */
	public function load_textdomain() {
		$file = 'wl-updater-' . determine_locale() . '.mo';
		if ( is_textdomain_loaded( 'wl-updater' ) || file_exists( WP_LANG_DIR . '/plugins/' . $file ) ) {
			return;
		}
		$bundled = __DIR__ . '/languages/' . $file;
		if ( file_exists( $bundled ) ) {
			load_textdomain( 'wl-updater', $bundled );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Manifest                                                            */
	/* ------------------------------------------------------------------ */

	private function manifest_url() {
		return ( defined( 'WL_UPDATER_MANIFEST' ) && WL_UPDATER_MANIFEST ) ? WL_UPDATER_MANIFEST : self::MANIFEST;
	}

	/** Manifest entries keyed by plugin slug. Cached; a failed request is retried after an hour. */
	private function manifest() {
		$cached = get_site_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$data     = array();
		$response = wp_remote_get(
			$this->manifest_url(),
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$json = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $json ) ) {
				foreach ( $json as $slug => $entry ) {
					$entry = $this->clean_entry( (string) $slug, $entry );
					if ( $entry ) {
						$data[ $entry['slug'] ] = $entry;
					}
				}
			}
			set_site_transient( self::CACHE, $data, self::CACHE_TTL );
		} else {
			set_site_transient( self::CACHE, $data, HOUR_IN_SECONDS );
		}

		return $data;
	}

	/** Keeps only valid fields. Packages must be HTTPS on an allowed host and carry a SHA-256. */
	private function clean_entry( $slug, $entry ) {
		$slug = sanitize_key( $slug );
		if ( '' === $slug || ! is_array( $entry ) || empty( $entry['version'] ) || empty( $entry['package'] ) || empty( $entry['sha256'] ) ) {
			return null;
		}

		$package = esc_url_raw( (string) $entry['package'], array( 'https' ) );
		$host    = (string) wp_parse_url( $package, PHP_URL_HOST );
		$sha     = strtolower( (string) $entry['sha256'] );

		if ( '' === $package || ! in_array( $host, self::HOSTS, true ) || ! preg_match( '/^[a-f0-9]{64}$/', $sha ) ) {
			return null;
		}

		// "Tested up to: 7.1" covers 7.1.x, the same way WordPress.org reports it.
		$tested = sanitize_text_field( (string) ( $entry['tested'] ?? '' ) );
		$wp     = (string) get_bloginfo( 'version' );
		if ( '' !== $tested && 0 === strpos( $wp, $tested . '.' ) ) {
			$tested = $wp;
		}

		return array(
			'slug'         => $slug,
			'name'         => sanitize_text_field( (string) ( $entry['name'] ?? $slug ) ),
			'version'      => sanitize_text_field( (string) $entry['version'] ),
			'package'      => $package,
			'sha256'       => $sha,
			'requires'     => sanitize_text_field( (string) ( $entry['requires'] ?? '' ) ),
			'requires_php' => sanitize_text_field( (string) ( $entry['requires_php'] ?? '' ) ),
			'tested'       => $tested,
			'homepage'     => esc_url_raw( (string) ( $entry['homepage'] ?? '' ) ),
			'description'  => sanitize_textarea_field( (string) ( $entry['description'] ?? '' ) ),
		);
	}

	/** Installed plugins that the manifest knows, as plugin file => manifest entry. */
	private function managed_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$manifest = $this->manifest();
		$managed  = array();
		foreach ( get_plugins() as $file => $headers ) {
			$slug = dirname( $file );
			if ( isset( $manifest[ $slug ] ) && $slug . '/' . $slug . '.php' === $file ) {
				$managed[ $file ] = array(
					'entry'     => $manifest[ $slug ],
					'installed' => (string) $headers['Version'],
				);
			}
		}
		return $managed;
	}

	/* ------------------------------------------------------------------ */
	/* Updates                                                             */
	/* ------------------------------------------------------------------ */

	public function inject_updates( $transient ) {
		if ( ! is_object( $transient ) ) {
			$transient = new stdClass();
		}

		foreach ( $this->managed_plugins() as $file => $info ) {
			$entry = $info['entry'];
			$item  = (object) array(
				'id'           => $entry['homepage'] ? $entry['homepage'] : $file,
				'slug'         => $entry['slug'],
				'plugin'       => $file,
				'new_version'  => $entry['version'],
				'url'          => $entry['homepage'],
				'package'      => $entry['package'],
				'requires'     => $entry['requires'],
				'requires_php' => $entry['requires_php'],
				'tested'       => $entry['tested'],
				'icons'        => array(),
				'banners'      => array(),
			);

			// A newer version from another source (for example WordPress.org) is left alone.
			$current = isset( $transient->response[ $file ]->new_version ) ? (string) $transient->response[ $file ]->new_version : '';

			if ( version_compare( $entry['version'], $info['installed'], '>' ) ) {
				if ( '' === $current || version_compare( $entry['version'], $current, '>=' ) ) {
					$transient->response[ $file ] = $item;
					unset( $transient->no_update[ $file ] );
				}
			} elseif ( '' === $current ) {
				// Listed as up to date, so the auto-update toggle shows on the Plugins screen.
				$transient->no_update[ $file ] = $item;
			}
		}

		return $transient;
	}

	/** "View details" on the Plugins and Updates screens. */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) ) {
			return $result;
		}
		$manifest = $this->manifest();
		if ( ! isset( $manifest[ $args->slug ] ) ) {
			return $result;
		}
		$entry = $manifest[ $args->slug ];

		return (object) array(
			'name'          => $entry['name'],
			'slug'          => $entry['slug'],
			'version'       => $entry['version'],
			'author'        => '<a href="https://wisionarylab.com/">Wisionary Lab</a>',
			'homepage'      => $entry['homepage'],
			'requires'      => $entry['requires'],
			'requires_php'  => $entry['requires_php'],
			'tested'        => $entry['tested'],
			'download_link' => $entry['package'],
			'sections'      => array(
				'description' => wpautop( esc_html( $entry['description'] ) ),
			),
		);
	}

	/**
	 * Downloads packages listed in the manifest and checks their SHA-256
	 * before WordPress installs them. Anything else is left to WordPress.
	 */
	public function verified_download( $reply, $package, $upgrader, $hook_extra = array() ) {
		if ( false !== $reply || ! is_string( $package ) ) {
			return $reply;
		}

		$expected = '';
		foreach ( $this->manifest() as $entry ) {
			if ( $entry['package'] === $package ) {
				$expected = $entry['sha256'];
				break;
			}
		}
		if ( '' === $expected ) {
			return $reply;
		}

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( isset( $upgrader->skin ) && is_object( $upgrader->skin ) && method_exists( $upgrader->skin, 'feedback' ) ) {
			$upgrader->skin->feedback( 'downloading_package', esc_html( $package ) );
		}

		$file = download_url( $package, 300 );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		if ( ! hash_equals( $expected, (string) hash_file( 'sha256', $file ) ) ) {
			wp_delete_file( $file );
			return new WP_Error( 'wl_updater_checksum', __( 'The downloaded file does not match the checksum in the manifest. The update was cancelled.', 'wl-updater' ) );
		}

		return $file;
	}

	/** "Check again" on Dashboard > Updates also reloads the manifest. */
	public function maybe_flush() {
		if ( isset( $_GET['force-check'] ) && current_user_can( 'update_plugins' ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- Only clears a cache.
			delete_site_transient( self::CACHE );
		}
	}

	public static function deactivate() {
		delete_site_transient( self::CACHE );
	}
}

register_deactivation_hook( __FILE__, array( 'WL_Updater', 'deactivate' ) );

new WL_Updater();
