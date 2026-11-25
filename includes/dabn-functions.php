<?php
/**
 * Functions for themes and other plugins.
 *
 * @package DemfilzAbnValidation
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether a value is a well-formed ABN (eleven digits with valid check digits).
 *
 * This does not ask the register: a well-formed ABN can still be cancelled or never issued.
 *
 * @param mixed $abn ABN in any format.
 * @return bool
 */
function dabn_is_valid_abn( $abn ) {
	return DABN_ABN::is_valid( $abn );
}

/**
 * An ABN the way it is written: 51 824 753 556.
 *
 * @param mixed $abn ABN in any format.
 * @return string
 */
function dabn_format_abn( $abn ) {
	return DABN_ABN::format( $abn );
}

/**
 * Look an ABN up on the Australian Business Register (cached; needs the store's GUID).
 *
 * @param mixed $abn ABN in any format.
 * @return array|WP_Error Record with abn, result (active, cancelled or not_found), status, since, name, type,
 *                        code, names, gst, state, postcode, acn and checked; or an error.
 */
function dabn_lookup_abn( $abn ) {
	return DABN_ABR::lookup( $abn );
}

/**
 * The ABN of an order.
 *
 * @param WC_Order|int $order Order or order id.
 * @return string Eleven digits, or empty when the order has no ABN.
 */
function dabn_get_order_abn( $order ) {
	$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
	return $order instanceof WC_Order ? DABN_Orders::get_abn( $order ) : '';
}

/**
 * What the register answered for the ABN of an order.
 *
 * @param WC_Order|int $order Order or order id.
 * @return array|null Check record (abn, result, name, type, code, names, gst, since, state, postcode, acn,
 *                    checked, note), or null when there is none.
 */
function dabn_get_order_abn_check( $order ) {
	$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
	return $order instanceof WC_Order ? DABN_Orders::get_check( $order ) : null;
}

/**
 * The ABN saved on a customer account.
 *
 * @param int $user_id User id.
 * @return string Eleven digits, or empty.
 */
function dabn_get_customer_abn( $user_id ) {
	return DABN_Orders::get_customer_abn( $user_id );
}
