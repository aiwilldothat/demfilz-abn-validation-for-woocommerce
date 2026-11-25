<?php
/**
 * Wording of check results, the ABN line in the billing address and the admin email line.
 *
 * @package DemfilzAbnValidation
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shows the ABN and its check outside the checkout.
 *
 * WooCommerce itself prints the field's value on order pages, in emails, on the order screen and in
 * My Account; this class adds what WooCommerce does not know about.
 */
class DABN_Display {

	/**
	 * Orders whose ABN was already printed on this page, by order id.
	 *
	 * @var array
	 */
	private $printed = array();

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'woocommerce_email_order_meta', array( $this, 'admin_email_line' ), 20, 3 );

		// WooCommerce prints the checkout block's field only for orders placed through the block. Orders from
		// the classic checkout, the admin or the REST API get the same lines from here.
		add_action( 'woocommerce_email_customer_details', array( $this, 'email_abn' ), 31, 3 );
		add_action( 'woocommerce_order_details_after_customer_details', array( $this, 'order_details_abn' ), 11 );
		add_action( 'woocommerce_thankyou', array( $this, 'thankyou_abn' ), 20 );

		if ( DABN_Settings::on( 'address_line' ) ) {
			add_filter( 'woocommerce_order_formatted_billing_address', array( $this, 'address_value' ), 10, 2 );
			add_filter( 'woocommerce_formatted_address_replacements', array( $this, 'address_replacement' ), 10, 2 );
			add_filter( 'woocommerce_localisation_address_formats', array( $this, 'address_formats' ) );
		}
	}

	/**
	 * A register date (Y-m-d) in the site's date format.
	 *
	 * @param string $ymd Date.
	 * @return string
	 */
	public static function date_text( $ymd ) {
		if ( ! is_string( $ymd ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $ymd ) ) {
			return '';
		}
		$timestamp = strtotime( $ymd . ' 12:00:00 UTC' );
		return false === $timestamp ? '' : (string) wp_date( (string) get_option( 'date_format', 'j F Y' ), $timestamp, new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Short label of a result.
	 *
	 * @param string $result active, cancelled, not_found, unavailable, unchecked or invalid.
	 * @return string
	 */
	public static function result_label( $result ) {
		switch ( $result ) {
			case 'active':
				return __( 'Active', 'demfilz-abn-validation-for-woocommerce' );
			case 'cancelled':
				return __( 'Cancelled', 'demfilz-abn-validation-for-woocommerce' );
			case 'not_found':
				return __( 'Not on the register', 'demfilz-abn-validation-for-woocommerce' );
			case 'invalid':
				return __( 'Not a valid ABN', 'demfilz-abn-validation-for-woocommerce' );
			case 'unchecked':
				return __( 'Valid format, not looked up', 'demfilz-abn-validation-for-woocommerce' );
			default:
				return __( 'Not checked', 'demfilz-abn-validation-for-woocommerce' );
		}
	}

	/**
	 * "Active since 1 November 1999".
	 *
	 * @param array $record Register record or stored check.
	 * @return string
	 */
	public static function status_text( $record ) {
		$label = self::result_label( isset( $record['result'] ) ? (string) $record['result'] : '' );
		$since = self::date_text( isset( $record['since'] ) ? (string) $record['since'] : '' );
		if ( '' === $since || ! in_array( $record['result'], array( 'active', 'cancelled' ), true ) ) {
			return $label;
		}
		/* translators: 1: status, for example Active. 2: date. */
		return sprintf( __( '%1$s since %2$s', 'demfilz-abn-validation-for-woocommerce' ), $label, $since );
	}

	/**
	 * "Registered since 1 July 2000" or "Not registered".
	 *
	 * @param array $record Register record or stored check.
	 * @return string
	 */
	public static function gst_text( $record ) {
		$gst = isset( $record['gst'] ) ? (string) $record['gst'] : '';
		if ( '' === $gst ) {
			return __( 'Not registered', 'demfilz-abn-validation-for-woocommerce' );
		}
		$date = self::date_text( $gst );
		/* translators: %s: date. */
		return '' !== $date ? sprintf( __( 'Registered since %s', 'demfilz-abn-validation-for-woocommerce' ), $date ) : __( 'Registered', 'demfilz-abn-validation-for-woocommerce' );
	}

	/**
	 * Why an ABN could not be checked, from the note kept with the record.
	 *
	 * @param string $note Error code stored when the check was made.
	 * @return string
	 */
	public static function note_text( $note ) {
		switch ( $note ) {
			case 'dabn_bad_guid':
				return __( 'The Australian Business Register did not accept the store\'s GUID.', 'demfilz-abn-validation-for-woocommerce' );
			case 'dabn_rate_limited':
				return __( 'Too many lookups were made in that hour.', 'demfilz-abn-validation-for-woocommerce' );
			case 'dabn_no_guid':
				return __( 'No GUID was saved.', 'demfilz-abn-validation-for-woocommerce' );
			default:
				return __( 'The Australian Business Register could not be reached.', 'demfilz-abn-validation-for-woocommerce' );
		}
	}

	/**
	 * One line about a check: "Active, ACME PTY LTD, registered for GST".
	 *
	 * @param array $record Stored check.
	 * @return string
	 */
	public static function summary( $record ) {
		$result = isset( $record['result'] ) ? (string) $record['result'] : '';
		$parts  = array( self::result_label( $result ) );
		if ( in_array( $result, array( 'active', 'cancelled' ), true ) ) {
			$name = DABN_ABR::display_name( $record );
			if ( '' !== $name ) {
				$parts[] = $name;
			}
			if ( 'active' === $result ) {
				$parts[] = '' !== (string) $record['gst'] ? __( 'registered for GST', 'demfilz-abn-validation-for-woocommerce' ) : __( 'not registered for GST', 'demfilz-abn-validation-for-woocommerce' );
			}
		} elseif ( 'unavailable' === $result ) {
			$parts[] = self::note_text( isset( $record['note'] ) ? (string) $record['note'] : '' );
		}
		return implode( ', ', $parts );
	}

	/**
	 * Admin emails: say what the register answered for the order's ABN.
	 *
	 * @param WC_Order $order         Order.
	 * @param bool     $sent_to_admin Whether the email goes to the store.
	 * @param bool     $plain_text    Whether the email is plain text.
	 * @return void
	 */
	public function admin_email_line( $order, $sent_to_admin = false, $plain_text = false ) {
		if ( ! $sent_to_admin || ! $order instanceof WC_Order ) {
			return;
		}
		$check = DABN_Orders::get_check( $order );
		if ( null === $check ) {
			return;
		}
		$label = __( 'ABN check', 'demfilz-abn-validation-for-woocommerce' );
		$text  = DABN_ABN::format( $check['abn'] ) . ': ' . self::summary( $check );
		if ( $plain_text ) {
			// Escaped the way WooCommerce escapes its own plain text emails.
			echo "\n" . esc_html( $label . ': ' . $text ) . "\n";
			return;
		}
		echo '<p><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $text ) . '</p>';
	}

	/**
	 * The ABN of an order that WooCommerce will not print by itself, formatted; empty otherwise.
	 *
	 * WooCommerce prints the field for orders created through the Store API (the checkout block). With
	 * the ABN in the billing address it is printed there instead, for every order.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private static function abn_to_print( $order ) {
		if ( ! $order instanceof WC_Order || DABN_Settings::on( 'address_line' ) || 'store-api' === $order->get_created_via() ) {
			return '';
		}
		$abn = DABN_Orders::get_abn( $order );
		return DABN_ABN::is_valid( $abn ) ? DABN_ABN::format( $abn ) : '';
	}

	/**
	 * Order emails: the ABN, in the same place and shape WooCommerce uses for checkout block orders.
	 *
	 * @param WC_Order $order         Order.
	 * @param bool     $sent_to_admin Whether the email goes to the store.
	 * @param bool     $plain_text    Whether the email is plain text.
	 * @return void
	 */
	public function email_abn( $order, $sent_to_admin = false, $plain_text = false ) {
		$abn = self::abn_to_print( $order );
		if ( '' === $abn ) {
			return;
		}
		$heading = __( 'Additional information', 'demfilz-abn-validation-for-woocommerce' );
		$label   = DABN_Settings::label();
		if ( $plain_text ) {
			echo "\n" . esc_html( wc_strtoupper( $heading ) ) . "\n\n";
			echo esc_html( $label . ': ' . $abn ) . "\n";
			return;
		}
		echo '<h2>' . esc_html( $heading ) . '</h2>';
		echo '<ul class="additional-fields" style="margin-bottom: 40px;"><li><strong>' . esc_html( $label ) . '</strong>: ' . esc_html( $abn ) . '</li></ul>';
	}

	/**
	 * Order received and View order pages (classic templates): the ABN under the customer details.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public function order_details_abn( $order ) {
		$abn = self::abn_to_print( $order );
		if ( '' === $abn ) {
			return;
		}
		$this->printed[ $order->get_id() ] = true;
		echo '<section class="wc-block-order-confirmation-additional-fields-wrapper dabn-order-details">';
		echo '<h2>' . esc_html__( 'Additional information', 'demfilz-abn-validation-for-woocommerce' ) . '</h2>';
		echo '<dl class="wc-block-components-additional-fields-list"><dt>' . esc_html( DABN_Settings::label() ) . '</dt><dd>' . esc_html( $abn ) . '</dd></dl>';
		echo '</section>';
	}

	/**
	 * Order received page built from blocks (block themes): the classic order details template is not
	 * used there, so the ABN is printed from the thank-you hook instead.
	 *
	 * On the classic template the order details have already printed it by the time this runs. Like
	 * WooCommerce's customer details, it is shown only to the customer the order belongs to.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public function thankyou_abn( $order_id ) {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 || isset( $this->printed[ $order_id ] ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( $order instanceof WC_Order && (int) $order->get_user_id() === (int) get_current_user_id() ) {
			$this->order_details_abn( $order );
		}
	}

	/**
	 * Add the ABN to the parts of an order's billing address.
	 *
	 * @param array    $address Address parts.
	 * @param WC_Order $order   Order.
	 * @return array
	 */
	public function address_value( $address, $order ) {
		if ( ! is_array( $address ) || ! $order instanceof WC_Order ) {
			return $address;
		}
		$abn = DABN_Orders::get_abn( $order );
		if ( DABN_ABN::is_valid( $abn ) ) {
			/* translators: %s: ABN, for example 51 824 753 556. */
			$address['dabn_abn'] = sprintf( __( 'ABN %s', 'demfilz-abn-validation-for-woocommerce' ), DABN_ABN::format( $abn ) );
		}
		return $address;
	}

	/**
	 * Teach WooCommerce's address formatter the {dabn_abn} placeholder.
	 *
	 * @param array $replacements Placeholder => value.
	 * @param array $args         Address parts.
	 * @return array
	 */
	public function address_replacement( $replacements, $args ) {
		$replacements['{dabn_abn}'] = isset( $args['dabn_abn'] ) ? (string) $args['dabn_abn'] : '';
		return $replacements;
	}

	/**
	 * Put the placeholder on its own line at the end of every country's address format. Addresses
	 * without an ABN lose the empty line again when WooCommerce formats them.
	 *
	 * @param array $formats Country code => format.
	 * @return array
	 */
	public function address_formats( $formats ) {
		if ( ! is_array( $formats ) ) {
			return $formats;
		}
		foreach ( $formats as $country => $format ) {
			if ( is_string( $format ) && false === strpos( $format, '{dabn_abn}' ) ) {
				$formats[ $country ] = $format . "\n{dabn_abn}";
			}
		}
		return $formats;
	}
}
