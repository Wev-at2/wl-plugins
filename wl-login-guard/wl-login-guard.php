<?php
/**
 * Plugin Name:       WL Login Guard
 * Plugin URI:        https://github.com/Wev-at2/wl-plugins/tree/main/wl-login-guard
 * Description:       Moves the WordPress login to a custom address (default: /wl-admin), hides wp-login.php and /wp-admin from logged-out visitors, blocks XML-RPC and hides usernames.
 * Version:           1.2.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Wisionary Lab
 * Author URI:        https://wisionarylab.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wl-login-guard
 * Domain Path:       /languages
 *
 * Emergency: define WL_LOGIN_GUARD_DISABLE as true in wp-config.php to turn
 * the plugin off, or WL_LOGIN_SLUG to force a login address.
 */

defined( 'ABSPATH' ) || exit;

final class WL_Login_Guard {

	const VERSION      = '1.2.0';
	const OPTION       = 'wl_login_guard_slug';
	const OPT_XMLRPC   = 'wl_login_guard_block_xmlrpc';
	const OPT_USERS    = 'wl_login_guard_hide_users';
	const DEFAULT_SLUG = 'wl-admin';

	/** Addresses that cannot become the login address. */
	const RESERVED = array( 'wp-admin', 'wp-login', 'wp-login.php', 'admin', 'login', 'dashboard', 'wp-content', 'wp-includes', 'wp-json', 'feed' );

	/** The request tried to open wp-login.php (or wp-register/wp-signup) directly. */
	private $blocked_login_file = false;

	public function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'plugins_loaded', array( $this, 'route_request' ), 9999 );
		add_action( 'wp_loaded', array( $this, 'handle_request' ) );

		add_filter( 'site_url', array( $this, 'filter_site_url' ), 10, 4 );
		add_filter( 'network_site_url', array( $this, 'filter_network_site_url' ), 10, 3 );
		add_filter( 'wp_redirect', array( $this, 'filter_wp_redirect' ), 10, 2 );
		add_filter( 'login_url', array( $this, 'filter_login_url' ), 10, 3 );

		// WordPress redirects /login, /admin and /dashboard to the default login, which would reveal it.
		remove_action( 'template_redirect', 'wp_redirect_admin_locations', 1000 );

		if ( self::blocks_xmlrpc() ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
			add_filter( 'xmlrpc_methods', '__return_empty_array' );
			add_filter( 'wp_headers', array( $this, 'remove_pingback_header' ) );
			remove_action( 'wp_head', 'rsd_link' );
		}

		if ( self::hides_users() ) {
			add_filter( 'rest_endpoints', array( $this, 'filter_rest_endpoints' ) );
			add_action( 'template_redirect', array( $this, 'block_author_archives' ), 1 );
			add_filter( 'author_link', array( $this, 'filter_author_link' ), 99 );
			add_filter( 'oembed_response_data', array( $this, 'filter_oembed_data' ) );
			add_filter( 'wp_sitemaps_add_provider', array( $this, 'filter_sitemap_providers' ), 10, 2 );
			add_filter( 'login_errors', array( $this, 'filter_login_errors' ) );
		}

		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'action_links' ) );
	}

	/**
	 * Bundled translations. A language pack from WordPress.org always wins:
	 * the bundled file is only loaded when no pack is installed for the locale.
	 */
	public function load_textdomain() {
		$file = 'wl-login-guard-' . determine_locale() . '.mo';
		if ( is_textdomain_loaded( 'wl-login-guard' ) || file_exists( WP_LANG_DIR . '/plugins/' . $file ) ) {
			return;
		}
		$bundled = __DIR__ . '/languages/' . $file;
		if ( file_exists( $bundled ) ) {
			load_textdomain( 'wl-login-guard', $bundled );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Login address                                                       */
	/* ------------------------------------------------------------------ */

	public static function slug() {
		if ( defined( 'WL_LOGIN_SLUG' ) && WL_LOGIN_SLUG ) {
			$slug = sanitize_title( WL_LOGIN_SLUG );
		} else {
			$slug = sanitize_title( (string) get_option( self::OPTION, self::DEFAULT_SLUG ) );
		}
		return ( '' === $slug || in_array( $slug, self::RESERVED, true ) ) ? self::DEFAULT_SLUG : $slug;
	}

	public static function blocks_xmlrpc() {
		return '1' === (string) get_option( self::OPT_XMLRPC, '1' );
	}

	public static function hides_users() {
		return '1' === (string) get_option( self::OPT_USERS, '1' );
	}

	private function uses_permalinks() {
		return (bool) get_option( 'permalink_structure' );
	}

	public function login_url( $scheme = null ) {
		$home = home_url( '/', $scheme );
		if ( $this->uses_permalinks() ) {
			return user_trailingslashit( $home . self::slug() );
		}
		return $home . '?' . self::slug();
	}

	/** Decoded path of the current request, without the trailing slash. */
	private function request_path() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		return untrailingslashit( rawurldecode( $path ) );
	}

	/* ------------------------------------------------------------------ */
	/* Routing                                                             */
	/* ------------------------------------------------------------------ */

	public function route_request() {
		global $pagenow;

		// XML-RPC accepts password attempts without going through the login screen.
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST && self::blocks_xmlrpc() ) {
			status_header( 403 );
			nocache_headers();
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'XML-RPC is disabled.';
			exit;
		}

		$path = $this->request_path();

		// Password protected post form: let WordPress handle it as usual.
		// phpcs:ignore WordPress.Security.NonceVerification -- Read only; WordPress verifies the form itself.
		if ( false !== strpos( $path, 'wp-login.php' ) && isset( $_GET['action'] ) && 'postpass' === $_GET['action'] && isset( $_POST['post_password'] ) ) {
			return;
		}

		$hits_login_file = false !== strpos( $path, 'wp-login.php' )
			|| untrailingslashit( (string) wp_parse_url( site_url( 'wp-login', 'relative' ), PHP_URL_PATH ) ) === $path;

		$hits_signup = ! is_multisite() && ( false !== strpos( $path, 'wp-register.php' ) || false !== strpos( $path, 'wp-signup.php' ) );

		if ( ( $hits_login_file || $hits_signup ) && ! is_admin() ) {
			$this->blocked_login_file = true;
			// A path that does not exist: WordPress resolves it as the theme's 404.
			$_SERVER['REQUEST_URI'] = user_trailingslashit( '/wl-404-' . wp_rand( 100000, 999999 ) );
			$pagenow                = 'index.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- Needed to route the request.
			return;
		}

		$custom_path = untrailingslashit( (string) wp_parse_url( home_url( self::slug(), 'relative' ), PHP_URL_PATH ) );
		// phpcs:ignore WordPress.Security.NonceVerification -- Read only, compared with a fixed value.
		$plain_hit = ! $this->uses_permalinks() && isset( $_GET[ self::slug() ] ) && '' === $_GET[ self::slug() ];

		if ( $path === $custom_path || $plain_hit ) {
			$pagenow = 'wp-login.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- Needed to route the request.
		}
	}

	public function handle_request() {
		global $pagenow;

		// Logged-out visitors on /wp-admin get a 404. AJAX, cron and admin-post keep working.
		if (
			is_admin()
			&& ! is_user_logged_in()
			&& ! wp_doing_ajax()
			&& ! wp_doing_cron()
			&& 'admin-post.php' !== $pagenow
			&& ! ( defined( 'WP_CLI' ) && WP_CLI )
		) {
			$this->render_404();
		}

		if ( $this->blocked_login_file ) {
			$this->render_404();
		}

		if ( 'wp-login.php' !== $pagenow ) {
			return;
		}

		// Normalize the trailing slash to avoid duplicate URLs.
		$uri      = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$raw_path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		if ( $this->uses_permalinks() && user_trailingslashit( $raw_path ) !== $raw_path ) {
			// phpcs:ignore WordPress.Security.NonceVerification -- Query arguments are only carried over to the redirect.
			$args = map_deep( wp_unslash( $_GET ), 'sanitize_text_field' );
			wp_safe_redirect( add_query_arg( rawurlencode_deep( $args ), $this->login_url() ) );
			exit;
		}

		// Logged in and no specific action: go straight to the dashboard.
		if ( is_user_logged_in() && ! isset( $_REQUEST['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- Read only.
			wp_safe_redirect( admin_url() );
			exit;
		}

		// Variables that wp-login.php expects in the global scope.
		global $error, $interim_login, $action, $user_login;

		require_once ABSPATH . 'wp-login.php';
		exit;
	}

	private function render_404() {
		global $pagenow, $wp_query;

		$pagenow = 'index.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- Needed to render the theme.
		nocache_headers();

		if ( is_admin() ) {
			// The theme does not load well in the admin context. Redirect to a 404 on the front end.
			wp_safe_redirect( home_url( user_trailingslashit( 'wl-404' ) ), 302 );
			exit;
		}

		if ( ! defined( 'WP_USE_THEMES' ) ) {
			define( 'WP_USE_THEMES', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Core constant that tells WordPress to load the theme.
		}

		wp();
		if ( $wp_query instanceof WP_Query && ! $wp_query->is_404() ) {
			$wp_query->set_404();
		}
		status_header( 404 );

		require_once ABSPATH . WPINC . '/template-loader.php';
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* XML-RPC and usernames                                               */
	/* ------------------------------------------------------------------ */

	public function remove_pingback_header( $headers ) {
		unset( $headers['X-Pingback'] );
		return $headers;
	}

	/** The REST API user list is only available to logged-in users. */
	public function filter_rest_endpoints( $endpoints ) {
		if ( is_user_logged_in() ) {
			return $endpoints;
		}
		foreach ( array_keys( $endpoints ) as $route ) {
			if ( 0 === strpos( $route, '/wp/v2/users' ) ) {
				unset( $endpoints[ $route ] );
			}
		}
		return $endpoints;
	}

	/** /?author=1 and /author/name reveal the username. They become a 404. */
	public function block_author_archives() {
		if ( ! is_author() && ! isset( $_GET['author'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- Read only.
			return;
		}
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}

	public function filter_author_link( $link ) {
		return is_admin() ? $link : home_url( '/' );
	}

	public function filter_oembed_data( $data ) {
		unset( $data['author_url'] );
		return $data;
	}

	public function filter_sitemap_providers( $provider, $name ) {
		return 'users' === $name ? false : $provider;
	}

	/** One message for both an unknown user and a wrong password. */
	public function filter_login_errors( $errors ) {
		if ( isset( $_POST['log'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- Read only.
			return '<strong>' . esc_html__( 'Error:', 'wl-login-guard' ) . '</strong> ' . esc_html__( 'incorrect username or password.', 'wl-login-guard' );
		}
		return $errors;
	}

	/* ------------------------------------------------------------------ */
	/* URL rewriting                                                       */
	/* ------------------------------------------------------------------ */

	public function filter_site_url( $url, $path, $scheme, $blog_id ) {
		return $this->rewrite_login_url( $url, $scheme );
	}

	public function filter_network_site_url( $url, $path, $scheme ) {
		return $this->rewrite_login_url( $url, $scheme );
	}

	public function filter_wp_redirect( $location, $status ) {
		return $this->rewrite_login_url( $location );
	}

	public function filter_login_url( $login_url, $redirect, $force_reauth ) {
		return $this->rewrite_login_url( $login_url );
	}

	private function rewrite_login_url( $url, $scheme = null ) {
		if ( ! is_string( $url ) || false === strpos( $url, 'wp-login.php' ) ) {
			return $url;
		}

		// The password protected post form stays on the default address so the new one is not exposed.
		if ( false !== strpos( $url, 'wp-login.php?action=postpass' ) ) {
			return $url;
		}

		if ( is_ssl() ) {
			$scheme = 'https';
		}

		$parts = explode( '?', $url, 2 );
		$new   = $this->login_url( $scheme );

		if ( isset( $parts[1] ) && '' !== $parts[1] ) {
			parse_str( $parts[1], $args );
			if ( isset( $args['login'] ) ) {
				$args['login'] = rawurlencode( $args['login'] );
			}
			$new = add_query_arg( $args, $new );
		}

		return $new;
	}

	/* ------------------------------------------------------------------ */
	/* Settings (Settings > General)                                       */
	/* ------------------------------------------------------------------ */

	public function register_settings() {
		register_setting(
			'general',
			self::OPTION,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_slug' ),
				'default'           => self::DEFAULT_SLUG,
			)
		);

		foreach ( array( self::OPT_XMLRPC, self::OPT_USERS ) as $opt ) {
			register_setting(
				'general',
				$opt,
				array(
					'type'              => 'string',
					'sanitize_callback' => array( $this, 'sanitize_checkbox' ),
					'default'           => '1',
				)
			);
		}

		add_settings_section(
			'wl_login_guard',
			'WL Login Guard',
			array( $this, 'section_intro' ),
			'general'
		);

		add_settings_field(
			self::OPTION,
			'<label for="' . esc_attr( self::OPTION ) . '">' . esc_html__( 'Login address', 'wl-login-guard' ) . '</label>',
			array( $this, 'field_slug' ),
			'general',
			'wl_login_guard'
		);

		add_settings_field(
			'wl_login_guard_protections',
			esc_html__( 'Protections', 'wl-login-guard' ),
			array( $this, 'field_protections' ),
			'general',
			'wl_login_guard'
		);
	}

	public function field_protections() {
		$rows = array(
			self::OPT_XMLRPC => __( 'Block XML-RPC (xmlrpc.php). Uncheck only if you use the WordPress mobile app or Jetpack.', 'wl-login-guard' ),
			self::OPT_USERS  => __( 'Hide usernames (REST API, author pages, login error message).', 'wl-login-guard' ),
		);
		echo '<fieldset>';
		foreach ( $rows as $opt => $label ) {
			printf(
				'<label for="%1$s"><input type="hidden" name="%1$s" value="0"><input type="checkbox" id="%1$s" name="%1$s" value="1" %2$s> %3$s</label><br>',
				esc_attr( $opt ),
				checked( '1', (string) get_option( $opt, '1' ), false ),
				esc_html( $label )
			);
		}
		echo '</fieldset>';
	}

	public function sanitize_checkbox( $value ) {
		return '1' === (string) $value ? '1' : '0';
	}

	public function section_intro() {
		echo '<p>' . esc_html__( 'Logged-out visitors who open /wp-admin or wp-login.php get the site\'s 404 page.', 'wl-login-guard' ) . '</p>';
	}

	public function field_slug() {
		$locked = defined( 'WL_LOGIN_SLUG' ) && WL_LOGIN_SLUG;
		printf(
			'<code>%1$s</code> <input type="text" id="%2$s" name="%2$s" value="%3$s" class="regular-text" %4$s> <code>/</code>',
			esc_html( trailingslashit( home_url() ) ),
			esc_attr( self::OPTION ),
			esc_attr( self::slug() ),
			disabled( $locked, true, false )
		);
		echo '<p class="description">';
		if ( $locked ) {
			esc_html_e( 'Set by the WL_LOGIN_SLUG constant in wp-config.php.', 'wl-login-guard' );
		} else {
			esc_html_e( 'Bookmark this address. Lowercase letters, numbers and hyphens.', 'wl-login-guard' );
		}
		echo '</p>';
	}

	public function sanitize_slug( $value ) {
		$slug = sanitize_title( (string) $value );

		if ( '' === $slug || in_array( $slug, self::RESERVED, true ) ) {
			add_settings_error( self::OPTION, 'wl_login_guard_reserved', __( 'This login address cannot be used. The previous one was kept.', 'wl-login-guard' ) );
			return self::slug();
		}

		if ( get_page_by_path( $slug, OBJECT, array( 'page', 'post' ) ) ) {
			add_settings_error( self::OPTION, 'wl_login_guard_conflict', __( 'A page or post already uses this address. The previous one was kept.', 'wl-login-guard' ) );
			return self::slug();
		}

		return $slug;
	}

	public function admin_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = get_current_screen();
		$show   = get_transient( 'wl_login_guard_activated' )
			|| ( $screen && 'options-general' === $screen->id && isset( $_GET['settings-updated'] ) ); // phpcs:ignore WordPress.Security.NonceVerification -- Read only.

		if ( ! $show ) {
			return;
		}

		delete_transient( 'wl_login_guard_activated' );

		printf(
			'<div class="notice notice-success is-dismissible"><p>%1$s <strong><a href="%2$s">%2$s</a></strong></p></div>',
			esc_html__( 'WL Login Guard is active. Your login address is:', 'wl-login-guard' ),
			esc_url( $this->login_url() )
		);
	}

	public function action_links( $links ) {
		$settings = '<a href="' . esc_url( admin_url( 'options-general.php#' . self::OPTION ) ) . '">' . esc_html__( 'Settings', 'wl-login-guard' ) . '</a>';
		array_unshift( $links, $settings );
		return $links;
	}

	public static function activate() {
		if ( false === get_option( self::OPTION ) ) {
			add_option( self::OPTION, self::DEFAULT_SLUG );
		}
		foreach ( array( self::OPT_XMLRPC, self::OPT_USERS ) as $opt ) {
			if ( false === get_option( $opt ) ) {
				add_option( $opt, '1' );
			}
		}
		set_transient( 'wl_login_guard_activated', 1, 60 );
	}
}

register_activation_hook( __FILE__, array( 'WL_Login_Guard', 'activate' ) );

if ( ! ( defined( 'WL_LOGIN_GUARD_DISABLE' ) && WL_LOGIN_GUARD_DISABLE ) ) {
	new WL_Login_Guard();
}
