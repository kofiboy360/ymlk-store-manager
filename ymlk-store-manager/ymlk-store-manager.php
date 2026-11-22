<?php
/**
 * Plugin Name: YMLK Store Manager
 * Description: A simple, fast store-management app for WooCommerce shop owners: sales overview, orders, products (with an easy options/variations builder) and inventory, all in one place.
 * Version: 1.2.1
 * Author: YMLK Technologies
 * Author URI: https://ymlktechnologies.com
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * Text Domain: ymlk-store-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'YSM_VERSION', '1.2.1' );
define( 'YSM_FILE', __FILE__ );
define( 'YSM_DIR', plugin_dir_path( __FILE__ ) );
define( 'YSM_URL', plugin_dir_url( __FILE__ ) );
define( 'YSM_SLUG', 'ymlk-store-manager' );
/* Where new versions are published (public GitHub repository). */
define( 'YSM_UPDATE_JSON', 'https://raw.githubusercontent.com/kofiboy360/ymlk-store-manager/main/update.json' );

require_once YSM_DIR . 'includes/class-ysm-rest.php';
require_once YSM_DIR . 'includes/class-ysm-updater.php';
require_once YSM_DIR . 'includes/class-ysm-more.php';

/* Declare compatibility with WooCommerce's order storage (HPOS). */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

final class YSM_Plugin {

	const CAP  = 'edit_shop_orders';
	const PAGE = 'ymlk-store-manager';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 5 );
		add_action( 'admin_menu', array( __CLASS__, 'simple_mode_menu' ), 9999 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 99, 3 );
		add_filter( 'woocommerce_login_redirect', array( __CLASS__, 'wc_login_redirect' ), 99, 2 );
		add_action( 'admin_init', array( __CLASS__, 'save_settings' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'action_links' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'simple_mode_admin_bar' ), 9999 );
		foreach ( array( 'load-post.php', 'load-post-new.php', 'load-edit.php' ) as $screen ) {
			add_action( $screen, array( __CLASS__, 'simple_mode_redirect' ) );
		}
		YSM_REST::init();
		YSM_More::init();
		YSM_Updater::init();
	}

	public static function app_url() {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	/** Simple mode is on by default; it only affects Shop Managers, never administrators. */
	public static function simple_mode_for( $user = null ) {
		$user = $user ? $user : wp_get_current_user();
		if ( ! $user || ! $user->exists() || user_can( $user, 'manage_options' ) ) {
			return false;
		}
		return '1' === get_option( 'ysm_simple_mode', '1' ) && in_array( 'shop_manager', (array) $user->roles, true );
	}

	public static function menu() {
		add_menu_page( 'Store Manager', 'Store Manager', self::CAP, self::PAGE, array( __CLASS__, 'render' ), 'dashicons-store', 2 );
		add_submenu_page( self::PAGE, 'Store Manager', 'Store Manager', self::CAP, self::PAGE, array( __CLASS__, 'render' ) );
		add_submenu_page( self::PAGE, 'Store Manager Settings', 'Settings', 'manage_options', self::PAGE . '-settings', array( __CLASS__, 'render_settings' ) );
	}

	/** Shop Managers in simple mode see only Store Manager, Dashboard and their Profile. */
	public static function simple_mode_menu() {
		if ( ! self::simple_mode_for() ) {
			return;
		}
		global $menu;
		$keep = array( self::PAGE, 'index.php', 'profile.php' );
		foreach ( (array) $menu as $item ) {
			if ( isset( $item[2] ) && ! in_array( $item[2], $keep, true ) ) {
				remove_menu_page( $item[2] );
			}
		}
		remove_submenu_page( self::PAGE, self::PAGE . '-settings' );
	}

	/** Simple mode: admin-bar links for products open Store Manager; "Edit with Elementor" is hidden. */
	public static function simple_mode_admin_bar( $bar ) {
		if ( ! self::simple_mode_for() ) {
			return;
		}
		// Elementor and theme-builder items (Edit with Elementor, templates, Theme Builder, Site Settings, Astra layouts).
		foreach ( (array) $bar->get_nodes() as $node ) {
			if ( preg_match( '/^(elementor|new-elementor|edit-custom-layout|astra-advanced-hook)/', $node->id ) ) {
				$bar->remove_node( $node->id );
			}
		}
		$id   = is_admin() ? ( isset( $_GET['post'] ) ? (int) $_GET['post'] : 0 ) : get_queried_object_id(); // phpcs:ignore
		$edit = $bar->get_node( 'edit' );
		if ( $edit && $id && 'product' === get_post_type( $id ) ) {
			$edit->href = self::app_url() . '#/products/' . $id;
			$bar->add_node( (array) $edit );
		}
		$new = $bar->get_node( 'new-product' );
		if ( $new ) {
			$new->href = self::app_url() . '#/products/new';
			$bar->add_node( (array) $new );
		}
	}

	/** Simple mode: WooCommerce's own product screens (e.g. old bookmarks) send Shop Managers to Store Manager. */
	public static function simple_mode_redirect() {
		if ( ! self::simple_mode_for() || wp_doing_ajax() || ! empty( $_POST ) ) { // phpcs:ignore
			return;
		}
		global $pagenow;
		$target = '';
		if ( 'post.php' === $pagenow && ! empty( $_GET['post'] ) ) { // phpcs:ignore
			$id = (int) $_GET['post']; // phpcs:ignore
			if ( 'product' === get_post_type( $id ) ) {
				$target = '#/products/' . $id;
			}
		} elseif ( 'post-new.php' === $pagenow && isset( $_GET['post_type'] ) && 'product' === $_GET['post_type'] ) { // phpcs:ignore
			$target = '#/products/new';
		} elseif ( 'edit.php' === $pagenow && isset( $_GET['post_type'] ) && 'product' === $_GET['post_type'] ) { // phpcs:ignore
			$target = '#/products';
		}
		if ( $target ) {
			wp_safe_redirect( self::app_url() . $target );
			exit;
		}
	}

	public static function login_redirect( $redirect_to, $requested, $user ) {
		if ( $user instanceof WP_User && self::simple_mode_for( $user ) ) {
			return self::app_url();
		}
		return $redirect_to;
	}

	public static function wc_login_redirect( $redirect, $user ) {
		if ( $user instanceof WP_User && self::simple_mode_for( $user ) ) {
			return self::app_url();
		}
		return $redirect;
	}

	public static function is_app_screen() {
		return is_admin() && isset( $_GET['page'] ) && self::PAGE === $_GET['page']; // phpcs:ignore
	}

	public static function body_class( $classes ) {
		return self::is_app_screen() ? $classes . ' ysm-fullscreen' : $classes;
	}

	public static function assets() {
		if ( ! self::is_app_screen() ) {
			return; // nothing is loaded anywhere else, and never on the public shop
		}
		wp_enqueue_media();
		$css = YSM_DIR . 'assets/app.css';
		$js  = YSM_DIR . 'assets/app.js';
		wp_enqueue_style( 'ysm-app', YSM_URL . 'assets/app.css', array(), YSM_VERSION . '.' . filemtime( $css ) );
		wp_enqueue_script( 'ysm-app', YSM_URL . 'assets/app.js', array(), YSM_VERSION . '.' . filemtime( $js ), true );

		$logo_id = get_theme_mod( 'custom_logo' );
		$country = WC()->countries ? WC()->countries->get_base_country() : '';
		$calling = ( WC()->countries && method_exists( WC()->countries, 'get_country_calling_code' ) ) ? WC()->countries->get_country_calling_code( $country ) : '';
		$user    = wp_get_current_user();
		wp_localize_script(
			'ysm-app',
			'YSM',
			array(
				'rest'       => esc_url_raw( rest_url() ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'site'       => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'siteUrl'    => home_url( '/' ),
				'logo'       => $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '',
				'currency'   => html_entity_decode( get_woocommerce_currency_symbol() ),
				'decimals'   => wc_get_price_decimals(),
				'calling'    => is_array( $calling ) ? (string) reset( $calling ) : (string) $calling,
				'user'       => $user->display_name,
				'isAdmin'    => current_user_can( 'manage_options' ),
				'wpAdmin'    => admin_url(),
				'logout'     => wp_logout_url( wp_login_url() ),
				'hpos'       => YSM_REST::hpos(),
				'lowStock'   => (int) get_option( 'woocommerce_notify_low_stock_amount', 2 ),
				'version'    => YSM_VERSION,
				'credit'     => 'https://ymlktechnologies.com',
				'canCoupons' => current_user_can( 'edit_shop_coupons' ),
				'canAlerts'  => current_user_can( 'manage_woocommerce' ),
			)
		);
	}

	public static function render() {
		echo '<div id="ysm-app" class="ysm"><div class="ysm-boot">Loading your store…</div></div>';
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::app_url() ) . '">Open</a>' );
		return $links;
	}

	public static function save_settings() {
		if ( empty( $_POST['ysm_save_settings'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'ysm_settings' );
		update_option( 'ysm_simple_mode', empty( $_POST['ysm_simple_mode'] ) ? '0' : '1' );
		update_option( 'ysm_support_whatsapp', isset( $_POST['ysm_support_whatsapp'] ) ? preg_replace( '/[^\d+ ]/', '', wp_unslash( $_POST['ysm_support_whatsapp'] ) ) : '' ); // phpcs:ignore
		add_settings_error( 'ysm', 'saved', 'Settings saved.', 'updated' );
	}

	public static function render_settings() {
		$simple = get_option( 'ysm_simple_mode', '1' );
		?>
		<div class="wrap">
			<h1>Store Manager Settings</h1>
			<?php settings_errors( 'ysm' ); ?>
			<form method="post">
				<?php wp_nonce_field( 'ysm_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Simple mode</th>
						<td>
							<label><input type="checkbox" name="ysm_simple_mode" value="1" <?php checked( $simple, '1' ); ?>> Shop Managers go straight to Store Manager after logging in and only see Store Manager, Dashboard and Profile in the menu.</label>
							<p class="description">Administrators always see the full WordPress admin.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ysm_support_whatsapp">Support WhatsApp number</label></th>
						<td>
							<input type="text" id="ysm_support_whatsapp" name="ysm_support_whatsapp" class="regular-text" value="<?php echo esc_attr( get_option( 'ysm_support_whatsapp', '' ) ); ?>" placeholder="e.g. +233 55 000 0000">
							<p class="description">Shown on the Help page so the shop owner can message you. Include the country code.</p>
						</td>
					</tr>
				</table>
				<p><button type="submit" name="ysm_save_settings" value="1" class="button button-primary">Save settings</button></p>
			</form>
			<p>Version <?php echo esc_html( YSM_VERSION ); ?> · Updates are delivered automatically from YMLK Technologies.</p>
		</div>
		<?php
	}
}

add_action( 'plugins_loaded', function () {
	if ( class_exists( 'WooCommerce' ) ) {
		YSM_Plugin::init();
	} else {
		YSM_Updater::init(); // keep receiving updates even if WooCommerce is temporarily off
	}
} );
