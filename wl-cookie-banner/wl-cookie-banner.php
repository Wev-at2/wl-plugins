<?php
/**
 * Plugin Name:       WL Cookie Banner
 * Plugin URI:        https://wisionarylab.com/
 * Description:       Cookie notice with accept, reject and per-category choice. Works with the WP Consent API and Google Consent Mode (Site Kit).
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Wisionary Lab
 * Author URI:        https://wisionarylab.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wl-cookie-banner
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

final class WL_Cookie_Banner {

	const VERSION = '1.1.0';
	const OPTION  = 'wl_cookie_banner';
	const COOKIE  = 'wl_cookie_consent';

	public static function defaults() {
		return array(
			'titulo'          => __( 'Cookies on this site', 'wl-cookie-banner' ),
			'texto'           => __( 'We use analytics cookies to understand how the site is used. They are only turned on if you allow them.', 'wl-cookie-banner' ),
			'cat_statistics'  => '1',
			'cat_marketing'   => '0',
			'cat_preferences' => '0',
			'tema'            => 'escuro',
			'cor'             => '#1847DC',
			'botao_flutuante' => '1',
			'validade_dias'   => 180,
			'revisao'         => 1,
		);
	}

	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Tells the WP Consent API that the site asks for consent first (opt-in) and that this plugin manages it.
		add_filter( 'wp_get_consent_type', array( $this, 'consent_type' ) );
		add_filter( 'wp_consent_api_registered_' . plugin_basename( __FILE__ ), '__return_true' );

		add_action( 'wp_head', array( $this, 'print_consent_default' ), 0 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_footer', array( $this, 'render' ), 5 );
		add_shortcode( 'wl_cookies', array( $this, 'shortcode' ) );

		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'action_links' ) );
	}

	/**
	 * Bundled translations. A language pack from WordPress.org always wins:
	 * the bundled file is only loaded when no pack is installed for the locale.
	 */
	public function load_textdomain() {
		$file = 'wl-cookie-banner-' . determine_locale() . '.mo';
		if ( is_textdomain_loaded( 'wl-cookie-banner' ) || file_exists( WP_LANG_DIR . '/plugins/' . $file ) ) {
			return;
		}
		$bundled = __DIR__ . '/languages/' . $file;
		if ( file_exists( $bundled ) ) {
			load_textdomain( 'wl-cookie-banner', $bundled );
		}
	}

	public function consent_type() {
		return 'optin';
	}

	private function is_front() {
		return ! is_admin() && ! wp_doing_ajax() && ! wp_is_json_request() && ! is_feed() && ! is_robots();
	}

	/** Categories shown in the banner, in order. The short key is used in the cookie. */
	private function categories() {
		$s    = self::settings();
		$list = array(
			's' => array(
				'opt'   => 'cat_statistics',
				'nome'  => __( 'Statistics', 'wl-cookie-banner' ),
				'texto' => __( 'Count visits and page views in aggregate (Google Analytics).', 'wl-cookie-banner' ),
			),
			'm' => array(
				'opt'   => 'cat_marketing',
				'nome'  => __( 'Marketing', 'wl-cookie-banner' ),
				'texto' => __( 'Measure ad campaigns and allow personalized ads.', 'wl-cookie-banner' ),
			),
			'p' => array(
				'opt'   => 'cat_preferences',
				'nome'  => __( 'Preferences', 'wl-cookie-banner' ),
				'texto' => __( 'Remember choices you made on the site, such as language or region.', 'wl-cookie-banner' ),
			),
		);
		return array_filter(
			$list,
			function ( $cat ) use ( $s ) {
				return '1' === (string) $s[ $cat['opt'] ];
			}
		);
	}

	/* ------------------------------------------------------------------ */
	/* Front end                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Google Consent Mode: everything is denied until the visitor chooses.
	 * Runs before Site Kit and any Google tag. If a choice is already saved,
	 * it is applied right away.
	 */
	public function print_consent_default() {
		if ( ! $this->is_front() ) {
			return;
		}
		$rev = (int) self::settings()['revisao'];
		?>
<script data-no-optimize="1" data-no-defer="1" data-cfasync="false" id="wl-cookie-banner-default">
window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}
gtag('consent','default',{ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied',analytics_storage:'denied',personalization_storage:'denied',functionality_storage:'granted',security_storage:'granted',wait_for_update:500});
(function(){try{var m=document.cookie.match(/(?:^|; )<?php echo esc_js( self::COOKIE ); ?>=([^;]*)/);if(!m)return;var c=JSON.parse(decodeURIComponent(m[1]));if(c.r!==<?php echo (int) $rev; ?>)return;var g=function(v){return v?'granted':'denied';};gtag('consent','update',{analytics_storage:g(c.s),ad_storage:g(c.m),ad_user_data:g(c.m),ad_personalization:g(c.m),personalization_storage:g(c.p)});}catch(e){}})();
</script>
		<?php
	}

	public function enqueue() {
		if ( ! $this->is_front() ) {
			return;
		}
		$url  = plugin_dir_url( __FILE__ ) . 'assets/';
		$deps = wp_script_is( 'wp-consent-api', 'registered' ) ? array( 'wp-consent-api' ) : array();
		$s    = self::settings();

		wp_enqueue_style( 'wl-cookie-banner', $url . 'banner.css', array(), self::VERSION );
		wp_enqueue_script( 'wl-cookie-banner', $url . 'banner.js', $deps, self::VERSION, true );

		$config = array(
			'cookie'     => self::COOKIE,
			'rev'        => (int) $s['revisao'],
			'days'       => (int) $s['validade_dias'],
			'categories' => array_keys( $this->categories() ),
			'reopen'     => '1' === (string) $s['botao_flutuante'],
		);
		wp_add_inline_script( 'wl-cookie-banner', 'window.WLCB_CONFIG=' . wp_json_encode( $config ) . ';', 'before' );
	}

	public function render() {
		if ( ! $this->is_front() ) {
			return;
		}
		$s      = self::settings();
		$policy = get_privacy_policy_url();
		$theme  = 'claro' === $s['tema'] ? 'wlcb--light' : 'wlcb--dark';
		$accent = sanitize_hex_color( $s['cor'] ) ? sanitize_hex_color( $s['cor'] ) : '#1847DC';
		?>
<div id="wl-cookie-banner" class="wlcb <?php echo esc_attr( $theme ); ?>" style="--wlcb-accent:<?php echo esc_attr( $accent ); ?>" hidden>
	<div class="wlcb__card" role="dialog" aria-modal="false" aria-labelledby="wlcb-title" aria-describedby="wlcb-text">
		<p class="wlcb__title" id="wlcb-title"><?php echo esc_html( $s['titulo'] ); ?></p>
		<p class="wlcb__text" id="wlcb-text">
			<?php echo esc_html( $s['texto'] ); ?>
			<?php if ( $policy ) : ?>
				<a class="wlcb__link" href="<?php echo esc_url( $policy ); ?>"><?php esc_html_e( 'Privacy Policy', 'wl-cookie-banner' ); ?></a>
			<?php endif; ?>
		</p>

		<div class="wlcb__prefs" id="wlcb-prefs" hidden>
			<div class="wlcb__cat">
				<span class="wlcb__cat-info">
					<strong><?php esc_html_e( 'Necessary', 'wl-cookie-banner' ); ?></strong>
					<span><?php esc_html_e( 'Keep the site working and secure. They cannot be turned off.', 'wl-cookie-banner' ); ?></span>
				</span>
				<span class="wlcb__always"><?php esc_html_e( 'Always on', 'wl-cookie-banner' ); ?></span>
			</div>
			<?php foreach ( $this->categories() as $key => $cat ) : ?>
				<label class="wlcb__cat" for="wlcb-cat-<?php echo esc_attr( $key ); ?>">
					<span class="wlcb__cat-info">
						<strong><?php echo esc_html( $cat['nome'] ); ?></strong>
						<span><?php echo esc_html( $cat['texto'] ); ?></span>
					</span>
					<input type="checkbox" class="wlcb__switch" id="wlcb-cat-<?php echo esc_attr( $key ); ?>" data-cat="<?php echo esc_attr( $key ); ?>">
				</label>
			<?php endforeach; ?>
		</div>

		<div class="wlcb__actions">
			<button type="button" class="wlcb__btn wlcb__btn--text" data-wlcb="customize" aria-expanded="false" aria-controls="wlcb-prefs"><?php esc_html_e( 'Customize', 'wl-cookie-banner' ); ?></button>
			<button type="button" class="wlcb__btn wlcb__btn--outline" data-wlcb="reject"><?php esc_html_e( 'Reject', 'wl-cookie-banner' ); ?></button>
			<button type="button" class="wlcb__btn wlcb__btn--outline" data-wlcb="save" hidden><?php esc_html_e( 'Save choices', 'wl-cookie-banner' ); ?></button>
			<button type="button" class="wlcb__btn wlcb__btn--solid" data-wlcb="accept"><?php esc_html_e( 'Accept', 'wl-cookie-banner' ); ?></button>
		</div>
	</div>
</div>
<button type="button" id="wl-cookie-reopen" class="wlcb-reopen <?php echo esc_attr( $theme ); ?>" style="--wlcb-accent:<?php echo esc_attr( $accent ); ?>" aria-label="<?php esc_attr_e( 'Cookie preferences', 'wl-cookie-banner' ); ?>" title="<?php esc_attr_e( 'Cookie preferences', 'wl-cookie-banner' ); ?>" hidden>
	<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 2a10 10 0 1 0 10 10 4 4 0 0 1-5-5 4 4 0 0 1-5-5Zm-3.5 7a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3Zm6 5a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3Zm-6 2a1 1 0 1 1 0 2 1 1 0 0 1 0-2Z"/></svg>
</button>
		<?php
	}

	/** [wl_cookies text="Cookie preferences"] prints a link that reopens the banner. */
	public function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'text'  => __( 'Cookie preferences', 'wl-cookie-banner' ),
				'texto' => '',
			),
			$atts,
			'wl_cookies'
		);
		// "texto" is the attribute name used by version 1.0.
		$label = '' !== $atts['texto'] ? $atts['texto'] : $atts['text'];
		return '<a href="#wl-cookies" data-wl-cookies>' . esc_html( $label ) . '</a>';
	}

	/* ------------------------------------------------------------------ */

	public function admin_menu() {
		add_options_page(
			__( 'Cookies', 'wl-cookie-banner' ),
			__( 'Cookies', 'wl-cookie-banner' ),
			'manage_options',
			'wl-cookie-banner',
			array( $this, 'settings_page' )
		);
	}

	public function register_settings() {
		register_setting(
			'wl_cookie_banner',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	public function sanitize( $input ) {
		$old = self::settings();
		$in  = is_array( $input ) ? $input : array();
		$d   = self::defaults();

		$out = array(
			'titulo'          => sanitize_text_field( $in['titulo'] ?? '' ),
			'texto'           => sanitize_textarea_field( $in['texto'] ?? '' ),
			'cat_statistics'  => empty( $in['cat_statistics'] ) ? '0' : '1',
			'cat_marketing'   => empty( $in['cat_marketing'] ) ? '0' : '1',
			'cat_preferences' => empty( $in['cat_preferences'] ) ? '0' : '1',
			'tema'            => ( isset( $in['tema'] ) && 'claro' === $in['tema'] ) ? 'claro' : 'escuro',
			'cor'             => sanitize_hex_color( $in['cor'] ?? '' ) ? sanitize_hex_color( $in['cor'] ) : $d['cor'],
			'botao_flutuante' => empty( $in['botao_flutuante'] ) ? '0' : '1',
			'validade_dias'   => min( 365, max( 30, absint( $in['validade_dias'] ?? $d['validade_dias'] ) ) ),
			'revisao'         => max( 1, (int) $old['revisao'] ),
		);

		if ( '' === $out['titulo'] ) {
			$out['titulo'] = $d['titulo'];
		}
		if ( '' === $out['texto'] ) {
			$out['texto'] = $d['texto'];
		}

		// The request changed? Everyone has to answer again.
		$cats_changed = $out['cat_statistics'] !== $old['cat_statistics'] || $out['cat_marketing'] !== $old['cat_marketing'] || $out['cat_preferences'] !== $old['cat_preferences'];
		if ( ! empty( $in['pedir_de_novo'] ) || $cats_changed ) {
			++$out['revisao'];
		}

		return $out;
	}

	public function settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s      = self::settings();
		$name   = self::OPTION;
		$policy = get_privacy_policy_url();
		?>
<div class="wrap">
	<h1><?php esc_html_e( 'WL Cookie Banner', 'wl-cookie-banner' ); ?></h1>

	<?php if ( ! $policy ) : ?>
		<div class="notice notice-warning"><p>
			<?php esc_html_e( 'The Privacy Policy page is not set. The banner shows without a link to it.', 'wl-cookie-banner' ); ?>
			<a href="<?php echo esc_url( admin_url( 'options-privacy.php' ) ); ?>"><?php esc_html_e( 'Set up the privacy page', 'wl-cookie-banner' ); ?></a>
		</p></div>
	<?php endif; ?>

	<?php if ( ! function_exists( 'wp_has_consent' ) ) : ?>
		<div class="notice notice-info"><p><?php esc_html_e( 'The WP Consent API plugin is not active. The banner works, but plugins such as Site Kit only respect the choice when it is installed.', 'wl-cookie-banner' ); ?></p></div>
	<?php endif; ?>

	<form method="post" action="options.php">
		<?php settings_fields( 'wl_cookie_banner' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="wlcb-titulo"><?php esc_html_e( 'Title', 'wl-cookie-banner' ); ?></label></th>
				<td><input type="text" id="wlcb-titulo" class="regular-text" name="<?php echo esc_attr( $name ); ?>[titulo]" value="<?php echo esc_attr( $s['titulo'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="wlcb-texto"><?php esc_html_e( 'Text', 'wl-cookie-banner' ); ?></label></th>
				<td><textarea id="wlcb-texto" class="large-text" rows="3" name="<?php echo esc_attr( $name ); ?>[texto]"><?php echo esc_textarea( $s['texto'] ); ?></textarea>
				<p class="description"><?php esc_html_e( 'The Privacy Policy link is added to the end of the text automatically.', 'wl-cookie-banner' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Categories', 'wl-cookie-banner' ); ?></th>
				<td><fieldset>
					<?php
					$cats = array(
						'cat_statistics'  => __( 'Statistics (Google Analytics)', 'wl-cookie-banner' ),
						'cat_marketing'   => __( 'Marketing (ads, Google Ads, Meta)', 'wl-cookie-banner' ),
						'cat_preferences' => __( 'Preferences', 'wl-cookie-banner' ),
					);
					foreach ( $cats as $key => $label ) {
						printf(
							'<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s> %4$s</label><br>',
							esc_attr( $name ),
							esc_attr( $key ),
							checked( '1', (string) $s[ $key ], false ),
							esc_html( $label )
						);
					}
					?>
					<p class="description"><?php esc_html_e( 'Only check what the site actually uses. Changing the categories shows the banner again to everyone.', 'wl-cookie-banner' ); ?></p>
				</fieldset></td>
			</tr>
			<tr>
				<th scope="row"><label for="wlcb-tema"><?php esc_html_e( 'Appearance', 'wl-cookie-banner' ); ?></label></th>
				<td>
					<select id="wlcb-tema" name="<?php echo esc_attr( $name ); ?>[tema]">
						<option value="escuro" <?php selected( $s['tema'], 'escuro' ); ?>><?php esc_html_e( 'Dark', 'wl-cookie-banner' ); ?></option>
						<option value="claro" <?php selected( $s['tema'], 'claro' ); ?>><?php esc_html_e( 'Light', 'wl-cookie-banner' ); ?></option>
					</select>
					<label for="wlcb-cor" style="margin-left:12px"><?php esc_html_e( 'Accent color', 'wl-cookie-banner' ); ?></label>
					<input type="color" id="wlcb-cor" name="<?php echo esc_attr( $name ); ?>[cor]" value="<?php echo esc_attr( $s['cor'] ); ?>">
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Button to review the choice', 'wl-cookie-banner' ); ?></th>
				<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[botao_flutuante]" value="1" <?php checked( '1', (string) $s['botao_flutuante'] ); ?>> <?php esc_html_e( 'Show a small button in the bottom left corner after the choice', 'wl-cookie-banner' ); ?></label>
				<p class="description"><?php esc_html_e( 'Without the button, add the [wl_cookies] shortcode to the footer or a link to #wl-cookies. Visitors must be able to change their mind.', 'wl-cookie-banner' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><label for="wlcb-dias"><?php esc_html_e( 'Choice lifetime', 'wl-cookie-banner' ); ?></label></th>
				<td><input type="number" id="wlcb-dias" min="30" max="365" name="<?php echo esc_attr( $name ); ?>[validade_dias]" value="<?php echo esc_attr( $s['validade_dias'] ); ?>"> <?php esc_html_e( 'days', 'wl-cookie-banner' ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Ask again', 'wl-cookie-banner' ); ?></th>
				<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[pedir_de_novo]" value="1"> <?php esc_html_e( 'Show the banner again to all visitors on save', 'wl-cookie-banner' ); ?></label>
				<p class="description">
					<?php
					/* translators: %d: current revision number */
					printf( esc_html__( 'Current revision: %d', 'wl-cookie-banner' ), (int) $s['revisao'] );
					?>
				</p></td>
			</tr>
		</table>
		<?php submit_button(); ?>
	</form>
</div>
		<?php
	}

	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=wl-cookie-banner' ) ) . '">' . esc_html__( 'Settings', 'wl-cookie-banner' ) . '</a>' );
		return $links;
	}
}

new WL_Cookie_Banner();
