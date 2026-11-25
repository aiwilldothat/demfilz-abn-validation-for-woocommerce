<?php
/**
 * Plugin bootstrap.
 *
 * @package DemfilzAbnValidation
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin components together.
 */
class DABN_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var DABN_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Orders and customers.
	 *
	 * @var DABN_Orders
	 */
	public $orders;

	/**
	 * Checkout field.
	 *
	 * @var DABN_Checkout
	 */
	public $checkout;

	/**
	 * AJAX lookups.
	 *
	 * @var DABN_Ajax
	 */
	public $ajax;

	/**
	 * Display outside the checkout.
	 *
	 * @var DABN_Display
	 */
	public $display;

	/**
	 * Privacy.
	 *
	 * @var DABN_Privacy
	 */
	public $privacy;

	/**
	 * Link with Demfilz Trade Accounts.
	 *
	 * @var DABN_Trade_Accounts
	 */
	public $trade_accounts;

	/**
	 * Admin screens.
	 *
	 * @var DABN_Admin|null
	 */
	public $admin = null;

	/**
	 * Get the single instance.
	 *
	 * @return DABN_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register every component.
	 */
	private function __construct() {
		$this->orders         = new DABN_Orders();
		$this->checkout       = new DABN_Checkout();
		$this->ajax           = new DABN_Ajax();
		$this->display        = new DABN_Display();
		$this->privacy        = new DABN_Privacy();
		$this->trade_accounts = new DABN_Trade_Accounts();

		$this->orders->register();
		$this->checkout->register();
		$this->ajax->register();
		$this->display->register();
		$this->privacy->register();
		$this->trade_accounts->register();

		if ( is_admin() ) {
			$this->admin = new DABN_Admin();
			$this->admin->register();
		}

		$this->register_optimizer_exclusions();
	}

	/**
	 * Keep the checkout script out of JS minify/combine/delay bundles, which can keep serving an
	 * old copy for months after an update.
	 *
	 * @return void
	 */
	private function register_optimizer_exclusions() {
		// The folder the plugin is installed in, which is what the script URLs contain.
		$slug = dirname( DABN_BASENAME );
		if ( '' === $slug || '.' === $slug ) {
			$slug = 'demfilz-abn-validation';
		}
		$handles = array( 'dabn-checkout' );

		add_filter(
			'w3tc_minify_js_do_tag_minification',
			function ( $do_minify, $script_tag = '', $file = '' ) use ( $slug ) {
				if ( false !== strpos( (string) $file, $slug ) || false !== strpos( (string) $script_tag, $slug ) ) {
					return false;
				}
				return $do_minify;
			},
			10,
			3
		);
		add_filter(
			'autoptimize_filter_js_exclude',
			function ( $exclude ) use ( $slug ) {
				return rtrim( (string) $exclude, ', ' ) . ', ' . $slug;
			}
		);
		$by_path = function ( $excludes ) use ( $slug ) {
			$excludes   = is_array( $excludes ) ? $excludes : array();
			$excludes[] = $slug;
			return $excludes;
		};
		add_filter( 'litespeed_optimize_js_excludes', $by_path );
		add_filter( 'litespeed_optm_js_defer_exc', $by_path );
		add_filter( 'rocket_delay_js_exclusions', $by_path );
		// WP Rocket matches a regular expression against the script's URL path, so the plugin's own URL
		// path is taken from plugins_url() (the plugins directory can live anywhere) and quoted first.
		$rocket = function ( $excludes ) {
			$excludes   = is_array( $excludes ) ? $excludes : array();
			$path       = (string) wp_parse_url( plugins_url( '', DABN_FILE ), PHP_URL_PATH );
			$excludes[] = preg_quote( untrailingslashit( $path ), '#' ) . '/(.*)\.js';
			return $excludes;
		};
		add_filter( 'rocket_exclude_js', $rocket );
		$by_handle = function ( $existing ) use ( $handles ) {
			return array_merge( is_array( $existing ) ? $existing : array(), $handles );
		};
		add_filter( 'sgo_js_minify_exclude', $by_handle );
		add_filter( 'sgo_javascript_combine_exclude', $by_handle );
	}
}
