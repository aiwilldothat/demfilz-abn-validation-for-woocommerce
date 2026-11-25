<?php
/**
 * Plugin Name:       Demfilz ABN Validation for WooCommerce
 * Plugin URI:        https://demfilz.com/abn-validation-for-woocommerce
 * Description:       Adds an ABN field to the WooCommerce checkout, validates it, and looks the business up on the Australian Business Register.
 * Version:           1.0.2
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Demfilz
 * Author URI:        https://demfilz.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       demfilz-abn-validation-for-woocommerce
 * WC requires at least: 9.9
 * WC tested up to:   11.3
 *
 * @package DemfilzAbnValidation
 */

defined( 'ABSPATH' ) || exit;

define( 'DABN_VERSION', '1.0.2' );
define( 'DABN_FILE', __FILE__ );
define( 'DABN_PATH', plugin_dir_path( __FILE__ ) );
define( 'DABN_URL', plugin_dir_url( __FILE__ ) );
define( 'DABN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Declare compatibility with High-Performance Order Storage and the cart and checkout blocks.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', DABN_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', DABN_FILE, true );
		}
	}
);

/**
 * Row under this plugin on the Plugins screen while WooCommerce is not active.
 *
 * Printed by the after_plugin_row_{file} action, so it appears next to this plugin only.
 *
 * @return void
 */
function dabn_requires_row() {
	global $wp_list_table;
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	$cols = is_object( $wp_list_table ) && method_exists( $wp_list_table, 'get_column_count' ) ? (int) $wp_list_table->get_column_count() : 4;
	echo '<tr class="plugin-update-tr active"><td colspan="' . esc_attr( $cols ) . '" class="plugin-update colspanchange">';
	echo '<div class="update-message notice inline notice-error notice-alt"><p>';
	echo esc_html__( 'Demfilz ABN Validation for WooCommerce requires WooCommerce to be installed and active.', 'demfilz-abn-validation-for-woocommerce' );
	echo '</p></div></td></tr>';
}

/**
 * Boot the plugin once all plugins are loaded, guarding for WooCommerce.
 *
 * @return void
 */
function dabn_boot() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		// The message sits under this plugin's own row on the Plugins screen, like a core update row.
		add_action( 'after_plugin_row_' . DABN_BASENAME, 'dabn_requires_row' );
		return;
	}

	require_once DABN_PATH . 'includes/class-dabn-abn.php';
	require_once DABN_PATH . 'includes/class-dabn-settings.php';
	require_once DABN_PATH . 'includes/class-dabn-abr.php';
	require_once DABN_PATH . 'includes/class-dabn-verify.php';
	require_once DABN_PATH . 'includes/class-dabn-orders.php';
	require_once DABN_PATH . 'includes/class-dabn-checkout.php';
	require_once DABN_PATH . 'includes/class-dabn-ajax.php';
	require_once DABN_PATH . 'includes/class-dabn-display.php';
	require_once DABN_PATH . 'includes/class-dabn-privacy.php';
	require_once DABN_PATH . 'includes/class-dabn-trade-accounts.php';
	require_once DABN_PATH . 'includes/class-dabn-admin.php';
	require_once DABN_PATH . 'includes/class-dabn-plugin.php';
	require_once DABN_PATH . 'includes/dabn-functions.php';

	DABN_Plugin::instance();
}
add_action( 'plugins_loaded', 'dabn_boot' );
