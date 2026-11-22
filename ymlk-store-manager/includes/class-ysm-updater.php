<?php
/**
 * Automatic updates from the YMLK Store Manager GitHub repository.
 *
 * WordPress checks for plugin updates about twice a day. When it does, this reads a small update.json
 * (version + download link) from the repository and, if there is a newer version, shows it on the
 * Plugins page like any other update, so WordPress's "Enable auto-updates" works too.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class YSM_Updater {

	const CACHE = 'ysm_update_info';

	public static function init() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'clear' ), 10, 0 );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );
		add_action( 'admin_init', array( __CLASS__, 'manual_check' ) );
	}

	private static function basename() {
		return plugin_basename( YSM_FILE );
	}

	/** Fetches update.json (cached for 6 hours so it never slows the admin down). */
	public static function info( $force = false ) {
		$info = $force ? false : get_site_transient( self::CACHE );
		if ( false !== $info ) {
			return $info ? $info : null;
		}
		$res  = wp_remote_get( YSM_UPDATE_JSON, array( 'timeout' => 8, 'headers' => array( 'Accept' => 'application/json' ) ) );
		$data = null;
		if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
			$json = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( is_array( $json ) && ! empty( $json['version'] ) && ! empty( $json['download_url'] ) ) {
				$data = $json;
			}
		}
		set_site_transient( self::CACHE, $data ? $data : 0, $data ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
		return $data;
	}

	public static function check( $transient ) {
		if ( ! is_object( $transient ) ) {
			$transient = new stdClass();
		}
		$info = self::info();
		if ( ! $info ) {
			return $transient;
		}
		$item = (object) array(
			'id'           => 'ymlk-store-manager',
			'slug'         => YSM_SLUG,
			'plugin'       => self::basename(),
			'new_version'  => $info['version'],
			'url'          => isset( $info['homepage'] ) ? $info['homepage'] : 'https://ymlktechnologies.com',
			'package'      => $info['download_url'],
			'requires'     => isset( $info['requires'] ) ? $info['requires'] : '',
			'tested'       => isset( $info['tested'] ) ? $info['tested'] : '',
			'requires_php' => isset( $info['requires_php'] ) ? $info['requires_php'] : '',
			'icons'        => array(),
		);
		if ( version_compare( $info['version'], YSM_VERSION, '>' ) ) {
			$transient->response[ self::basename() ] = $item;
		} else {
			$transient->no_update[ self::basename() ] = $item; // lets the auto-update toggle show
		}
		return $transient;
	}

	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || YSM_SLUG !== $args->slug ) {
			return $result;
		}
		$info = self::info();
		if ( ! $info ) {
			return $result;
		}
		return (object) array(
			'name'          => 'YMLK Store Manager',
			'slug'          => YSM_SLUG,
			'version'       => $info['version'],
			'author'        => '<a href="https://ymlktechnologies.com">YMLK Technologies</a>',
			'homepage'      => 'https://ymlktechnologies.com',
			'requires'      => isset( $info['requires'] ) ? $info['requires'] : '',
			'tested'        => isset( $info['tested'] ) ? $info['tested'] : '',
			'requires_php'  => isset( $info['requires_php'] ) ? $info['requires_php'] : '',
			'download_link' => $info['download_url'],
			'last_updated'  => isset( $info['released'] ) ? $info['released'] : '',
			'sections'      => array(
				'description' => 'A simple store-management app for WooCommerce, by YMLK Technologies.',
				'changelog'   => isset( $info['changelog'] ) ? wp_kses_post( $info['changelog'] ) : '',
			),
		);
	}

	public static function clear() {
		delete_site_transient( self::CACHE );
	}

	public static function row_meta( $links, $file ) {
		if ( $file === self::basename() && current_user_can( 'update_plugins' ) ) {
			$links[] = '<a href="' . esc_url( wp_nonce_url( admin_url( 'plugins.php?ysm_check_update=1' ), 'ysm_check_update' ) ) . '">Check for updates</a>';
		}
		return $links;
	}

	public static function manual_check() {
		if ( empty( $_GET['ysm_check_update'] ) || ! current_user_can( 'update_plugins' ) ) { // phpcs:ignore
			return;
		}
		check_admin_referer( 'ysm_check_update' );
		self::info( true );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		wp_safe_redirect( admin_url( 'plugins.php' ) );
		exit;
	}
}
