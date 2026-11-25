<?php
/**
 * Privacy: policy text, export and erasure.
 *
 * @package DemfilzAbnValidation
 */

defined( 'ABSPATH' ) || exit;

/**
 * An ABN can identify a sole trader, so it is treated as personal data: it joins WooCommerce's
 * customer and order exports and is removed with them.
 */
class DABN_Privacy {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'policy' ) );

		add_filter( 'woocommerce_privacy_export_customer_personal_data', array( $this, 'export_customer' ), 10, 2 );
		add_filter( 'woocommerce_privacy_export_order_personal_data', array( $this, 'export_order' ), 10, 2 );

		add_filter( 'woocommerce_privacy_erase_customer_personal_data_props', array( $this, 'erase_customer_props' ) );
		add_filter( 'woocommerce_privacy_erase_customer_personal_data_prop', array( $this, 'erase_customer_prop' ), 10, 3 );
		add_action( 'woocommerce_privacy_remove_order_personal_data', array( $this, 'erase_order' ) );
	}

	/**
	 * Add the suggested text to Settings > Privacy > Policy guide.
	 *
	 * @return void
	 */
	public function policy() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text  = '<p class="privacy-policy-tutorial">' . esc_html__( 'Suggested text for stores that ask for an ABN:', 'demfilz-abn-validation-for-woocommerce' ) . '</p>';
		$text .= '<p>' . esc_html__( 'If you give us an Australian Business Number (ABN) at checkout, we keep it with your order and your account so it can appear on your invoices. To confirm that it is a current ABN, we send the number to the Australian Business Register (ABN Lookup), a service of the Australian Government, and keep the business name, entity type, GST registration status, state and postcode it returns with your order. The register receives the ABN only; it does not receive your name, your address or anything about your order.', 'demfilz-abn-validation-for-woocommerce' ) . '</p>';
		wp_add_privacy_policy_content( __( 'Demfilz ABN Validation for WooCommerce', 'demfilz-abn-validation-for-woocommerce' ), wp_kses_post( $text ) );
	}

	/**
	 * Add the saved ABN to the customer data export.
	 *
	 * @param array       $data     Name and value pairs.
	 * @param WC_Customer $customer Customer.
	 * @return array
	 */
	public function export_customer( $data, $customer ) {
		if ( ! is_array( $data ) || ! $customer instanceof WC_Customer ) {
			return $data;
		}
		$abn = DABN_Orders::get_customer_abn( $customer->get_id() );
		if ( '' !== $abn ) {
			$data[] = array(
				'name'  => __( 'ABN', 'demfilz-abn-validation-for-woocommerce' ),
				'value' => DABN_ABN::format( $abn ),
			);
		}
		return $data;
	}

	/**
	 * Add the ABN and its check to the order data export.
	 *
	 * @param array    $data  Name and value pairs.
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public function export_order( $data, $order ) {
		if ( ! is_array( $data ) || ! $order instanceof WC_Order ) {
			return $data;
		}
		$abn = DABN_Orders::get_abn( $order );
		if ( '' === $abn ) {
			return $data;
		}
		$data[] = array(
			'name'  => __( 'ABN', 'demfilz-abn-validation-for-woocommerce' ),
			'value' => DABN_ABN::format( $abn ),
		);
		$check  = DABN_Orders::get_check( $order );
		if ( null !== $check ) {
			$data[] = array(
				'name'  => __( 'ABN check', 'demfilz-abn-validation-for-woocommerce' ),
				'value' => DABN_Display::summary( $check ),
			);
		}
		return $data;
	}

	/**
	 * Name the ABN among the customer details WooCommerce erases.
	 *
	 * @param array $props Property => label.
	 * @return array
	 */
	public function erase_customer_props( $props ) {
		if ( is_array( $props ) ) {
			$props['dabn_abn'] = __( 'ABN', 'demfilz-abn-validation-for-woocommerce' );
		}
		return $props;
	}

	/**
	 * Erase the ABN from a customer account. WooCommerce saves the customer afterwards.
	 *
	 * @param bool        $erased   Whether the property was erased.
	 * @param string      $prop     Property name.
	 * @param WC_Customer $customer Customer.
	 * @return bool
	 */
	public function erase_customer_prop( $erased, $prop, $customer ) {
		if ( 'dabn_abn' !== $prop || ! $customer instanceof WC_Customer ) {
			return $erased;
		}
		$had = '' !== (string) $customer->get_meta( DABN_Orders::USER_KEY, true ) || '' !== (string) $customer->get_meta( DABN_Orders::block_key(), true );
		$customer->delete_meta_data( DABN_Orders::USER_KEY );
		$customer->delete_meta_data( DABN_Orders::block_key() );
		return $had ? true : $erased;
	}

	/**
	 * Remove the ABN and its check when WooCommerce anonymises an order.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public function erase_order( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$order->delete_meta_data( DABN_Orders::ORDER_KEY );
		$order->delete_meta_data( DABN_Orders::block_key() );
		$order->delete_meta_data( DABN_Orders::CHECK_KEY );
		$order->save();
	}
}
