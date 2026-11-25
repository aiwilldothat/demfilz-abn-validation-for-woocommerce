<?php
/**
 * Uninstall.
 *
 * ABNs on orders and customer accounts are part of the store's records (they appear on tax invoices),
 * so they are never deleted. The cached register answers and counters always go; the settings go only
 * when the store chose "Delete these settings when the plugin is deleted".
 *
 * @package DemfilzAbnValidation
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! function_exists( 'dabn_uninstall_site' ) ) {
	/**
	 * Clean up one site.
	 *
	 * @return void
	 */
	function dabn_uninstall_site() {
		global $wpdb;

		// Cached lookups, hourly counters and connection status: this plugin's own transients.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off clean-up on uninstall; transients have no delete-by-prefix API.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_dabn_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_dabn_' ) . '%'
			)
		);

		// With a persistent object cache the transients are not in the database. The ones with fixed
		// names are removed here; cached lookups (one per ABN) expire by themselves.
		foreach ( array( 'dabn_guid_bad', 'dabn_abr_down', 'dabn_rl_site', 'dabn_rl_orders' ) as $transient ) {
			delete_transient( $transient );
		}

		$settings = get_option( 'dabn_settings', array() );
		if ( is_array( $settings ) && ! empty( $settings['delete_data'] ) && 'yes' === $settings['delete_data'] ) {
			delete_option( 'dabn_settings' );
		}
	}
}

if ( is_multisite() ) {
	$dabn_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $dabn_sites as $dabn_site_id ) {
		switch_to_blog( (int) $dabn_site_id );
		dabn_uninstall_site();
		restore_current_blog();
	}
} else {
	dabn_uninstall_site();
}
