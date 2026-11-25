<?php
/**
 * Link with Demfilz Trade Accounts for WooCommerce.
 *
 * @package DemfilzAbnValidation
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shows what the register says about the ABN on a trade account application.
 *
 * Does nothing unless Demfilz Trade Accounts for WooCommerce is active.
 */
class DABN_Trade_Accounts {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'dtaw_account_form_rows', array( $this, 'account_row' ) );
	}

	/**
	 * Add an "ABN check" row to the trade account screen.
	 *
	 * @param int $user_id Customer id.
	 * @return void
	 */
	public function account_row( $user_id ) {
		if ( ! class_exists( 'DTAW_Accounts' ) || ! is_callable( array( 'DTAW_Accounts', 'application' ) ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$application = DTAW_Accounts::application( (int) $user_id );
		$tax_id      = is_array( $application ) && isset( $application['tax_id'] ) ? trim( (string) $application['tax_id'] ) : '';
		// The field also takes other countries' tax ids; only an 11 digit number is treated as an ABN.
		if ( 11 !== strlen( DABN_ABN::digits( $tax_id ) ) ) {
			return;
		}
		$check = DABN_Verify::check( $tax_id );

		echo '<tr><th scope="row">' . esc_html__( 'ABN check', 'demfilz-abn-validation-for-woocommerce' ) . '</th><td>';
		if ( is_array( $check['record'] ) ) {
			DABN_Admin::check_panel( $check['record'], false );
		}
		echo '</td></tr>';
	}
}
