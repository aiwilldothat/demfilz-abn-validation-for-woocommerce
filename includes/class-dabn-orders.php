<?php
/**
 * The ABN on orders and customers.
 *
 * @package DemfilzAbnValidation
 */

defined( 'ABSPATH' ) || exit;

/**
 * Keeps one ABN per order whichever checkout it came from, and the check record beside it.
 *
 * The ABN is stored the way WooCommerce stores a billing field called billing_abn: order meta
 * _billing_abn and customer meta billing_abn, eleven digits. The checkout block also keeps its own
 * copy (_wc_other/dabn/abn, formatted), which WooCommerce prints on order pages and in emails.
 */
class DABN_Orders {

	/**
	 * Id of the field in the checkout block.
	 */
	const FIELD_ID = 'dabn/abn';

	/**
	 * Order meta holding the ABN.
	 */
	const ORDER_KEY = '_billing_abn';

	/**
	 * Customer meta holding the ABN.
	 */
	const USER_KEY = 'billing_abn';

	/**
	 * Order meta holding the check record.
	 */
	const CHECK_KEY = '_dabn_check';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'woocommerce_set_additional_field_value', array( $this, 'mirror_block_value' ), 10, 4 );
		add_filter( 'woocommerce_get_default_value_for_' . self::FIELD_ID, array( $this, 'default_block_value' ), 10, 3 );

		add_action( 'woocommerce_checkout_order_processed', array( $this, 'after_classic_checkout' ), 10, 3 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'block_request' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'after_block_checkout' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'after_admin_save' ), 60 );
		add_action( 'woocommerce_rest_insert_shop_order_object', array( $this, 'after_rest_insert' ) );

		// Order search (admin list and REST API), with both order storage modes.
		add_filter( 'woocommerce_order_table_search_query_meta_keys', array( $this, 'search_keys' ) );
		add_filter( 'woocommerce_shop_order_search_fields', array( $this, 'search_keys' ) );

		// Customer accounts: keep the checkout block's copy in step with the billing meta, whoever writes it.
		add_filter( 'sanitize_user_meta_' . self::USER_KEY, array( $this, 'sanitize_user_abn' ) );
		add_action( 'added_user_meta', array( $this, 'user_abn_saved' ), 10, 4 );
		add_action( 'updated_user_meta', array( $this, 'user_abn_saved' ), 10, 4 );
		add_action( 'deleted_user_meta', array( $this, 'user_abn_deleted' ), 10, 3 );
	}

	/**
	 * Store a customer's ABN as eleven digits however it was typed (profile screen, imports).
	 *
	 * @param mixed $value Value being saved.
	 * @return mixed
	 */
	public function sanitize_user_abn( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$digits = DABN_ABN::digits( $value );
		return 11 === strlen( $digits ) ? $digits : sanitize_text_field( (string) $value );
	}

	/**
	 * A customer's ABN was saved: update the copy WooCommerce shows in My Account and the checkout block.
	 *
	 * @param int    $meta_id    Meta row id.
	 * @param int    $user_id    User id.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Value saved.
	 * @return void
	 */
	public function user_abn_saved( $meta_id, $user_id, $meta_key, $meta_value ) {
		if ( self::USER_KEY !== $meta_key ) {
			return;
		}
		$abn  = DABN_ABN::digits( $meta_value );
		$want = '' === $abn ? '' : DABN_ABN::format( $abn );
		$have = (string) get_user_meta( $user_id, self::block_key(), true );
		if ( $want === $have ) {
			return;
		}
		if ( '' === $want ) {
			delete_user_meta( $user_id, self::block_key() );
		} else {
			update_user_meta( $user_id, self::block_key(), $want );
		}
	}

	/**
	 * A customer's ABN was deleted: drop the copy too.
	 *
	 * @param array  $meta_ids Meta row ids.
	 * @param int    $user_id  User id.
	 * @param string $meta_key Meta key.
	 * @return void
	 */
	public function user_abn_deleted( $meta_ids, $user_id, $meta_key ) {
		if ( self::USER_KEY === $meta_key && '' !== (string) get_user_meta( $user_id, self::block_key(), true ) ) {
			delete_user_meta( $user_id, self::block_key() );
		}
	}

	/**
	 * Meta key of the checkout block's copy.
	 *
	 * @return string
	 */
	public static function block_key() {
		return '_wc_other/' . self::FIELD_ID;
	}

	/**
	 * The ABN of an order.
	 *
	 * @param WC_Order $order Order.
	 * @return string Digits (eleven for a valid ABN), or empty.
	 */
	public static function get_abn( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return '';
		}
		$abn = DABN_ABN::digits( $order->get_meta( self::ORDER_KEY, true ) );
		if ( '' === $abn ) {
			$abn = DABN_ABN::digits( $order->get_meta( self::block_key(), true ) );
		}
		return $abn;
	}

	/**
	 * The ABN saved on a customer account.
	 *
	 * @param int $user_id User id.
	 * @return string Digits, or empty.
	 */
	public static function get_customer_abn( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return '';
		}
		$abn = DABN_ABN::digits( get_user_meta( $user_id, self::USER_KEY, true ) );
		if ( '' === $abn ) {
			// WooCommerce's copy can hold a value that was never checked (see block_request()).
			$copy = DABN_ABN::digits( get_user_meta( $user_id, self::block_key(), true ) );
			$abn  = DABN_ABN::is_valid( $copy ) ? $copy : '';
		}
		return $abn;
	}

	/**
	 * The check record of an order, when it belongs to the order and to its current ABN.
	 *
	 * A record copied from another order (a subscription renewal, a duplicated order) is not this
	 * order's check, however alike the ABN.
	 *
	 * @param WC_Order $order Order.
	 * @return array|null
	 */
	public static function get_check( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return null;
		}
		$check = $order->get_meta( self::CHECK_KEY, true );
		if ( ! is_array( $check ) || ! isset( $check['abn'], $check['result'] ) || self::get_abn( $order ) !== $check['abn'] ) {
			return null;
		}
		if ( isset( $check['order'] ) && (int) $check['order'] !== (int) $order->get_id() ) {
			return null;
		}
		return array_merge( DABN_Verify::record( $check['abn'], $check['result'] ), $check );
	}

	/**
	 * The checkout block saved its field: keep WooCommerce's billing meta in step.
	 *
	 * @param string  $key       Field id.
	 * @param mixed   $value     Value being saved.
	 * @param string  $group     Field group.
	 * @param WC_Data $wc_object Order or customer.
	 * @return void
	 */
	public function mirror_block_value( $key, $value, $group, $wc_object ) {
		if ( self::FIELD_ID !== $key ) {
			return;
		}
		$abn = DABN_ABN::digits( $value );

		if ( $wc_object instanceof WC_Order ) {
			if ( '' === $abn ) {
				$wc_object->delete_meta_data( self::ORDER_KEY );
			} else {
				$wc_object->update_meta_data( self::ORDER_KEY, $abn );
			}
			return;
		}

		if ( ! $wc_object instanceof WC_Customer ) {
			return;
		}

		// The checkout block empties a field it has hidden and posts the empty value. For a customer
		// who is ordering to an address the field does not apply to, that is not "I have no ABN":
		// the ABN saved on their account stays.
		$country = (string) $wc_object->get_billing_country();
		if ( '' !== $country && ! DABN_Settings::applies_to_country( $country ) ) {
			return;
		}
		if ( '' === $abn ) {
			$wc_object->delete_meta_data( self::USER_KEY );
		} elseif ( DABN_ABN::is_valid( $abn ) ) {
			// Only a well-formed ABN reaches the account. Anything else is still in WooCommerce's own
			// copy, where the field's validation deals with it.
			$wc_object->update_meta_data( self::USER_KEY, $abn );
		}
	}

	/**
	 * Give the checkout block's field the ABN of orders and customers that came through the classic checkout.
	 *
	 * @param mixed   $value     Null unless another filter answered.
	 * @param string  $group     Field group.
	 * @param WC_Data $wc_object Order or customer.
	 * @return mixed
	 */
	public function default_block_value( $value, $group, $wc_object ) {
		if ( null !== $value && '' !== $value ) {
			return $value;
		}
		$abn = '';
		if ( $wc_object instanceof WC_Order ) {
			$abn = DABN_ABN::digits( $wc_object->get_meta( self::ORDER_KEY, true ) );
		} elseif ( $wc_object instanceof WC_Customer ) {
			$abn = DABN_ABN::digits( $wc_object->get_meta( self::USER_KEY, true ) );
		}
		return '' !== $abn ? DABN_ABN::format( $abn ) : $value;
	}

	/**
	 * Classic checkout placed an order (its ABN was validated by DABN_Checkout::classic_validate()).
	 *
	 * @param int      $order_id Order id.
	 * @param array    $posted   Posted data.
	 * @param WC_Order $order    Order.
	 * @return void
	 */
	public function after_classic_checkout( $order_id, $posted = array(), $order = null ) {
		if ( ! DABN_Settings::field_on() ) {
			return;
		}
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( $order instanceof WC_Order ) {
			self::sync( $order, false, 'order' );
		}
	}

	/**
	 * Checkout block: the order was updated from a request. This is the last word on its ABN.
	 *
	 * WooCommerce validates the field only when the request carries it and the field is not hidden.
	 * A value saved earlier (while the field was hidden, or by a request made by hand) is copied
	 * onto the order unchecked, so the order's final ABN is checked here, before anything is paid.
	 *
	 * @throws Exception When the order is being placed with an ABN the store does not accept, or without one it requires.
	 *
	 * @param WC_Order        $order   Draft order.
	 * @param WP_REST_Request $request Request.
	 * @return void
	 */
	public function block_request( $order, $request = null ) {
		if ( ! $order instanceof WC_Order || ! DABN_Settings::field_on() ) {
			return;
		}
		$country = (string) $order->get_billing_country();

		// The field is hidden for this billing country: nothing is kept, checked or required.
		if ( '' !== $country && ! DABN_Settings::applies_to_country( $country ) ) {
			self::forget( $order );
			return;
		}

		// While the shopper is still filling in the form (PUT, PATCH) nothing is refused.
		if ( ! $request instanceof WP_REST_Request || 'POST' !== $request->get_method() ) {
			return;
		}

		$raw = trim( (string) $order->get_meta( self::block_key(), true ) );
		if ( '' === $raw ) {
			$raw = trim( (string) $order->get_meta( self::ORDER_KEY, true ) );
		}

		if ( '' === $raw ) {
			if ( DABN_Settings::is_required_for( $country, $order->get_billing_company() ) ) {
				self::refuse( 'dabn_abn_required', DABN_Checkout::required_message() );
			}
			return;
		}

		$check = DABN_Verify::check( $raw, array( 'purpose' => 'order' ) );
		if ( ! $check['ok'] ) {
			self::forget( $order );
			self::refuse( 'dabn_invalid_abn', $check['message'] );
		}
	}

	/**
	 * Stop a checkout block order with a message for the shopper.
	 *
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException The Store API's own error, which it turns into a 400 response.
	 * @throws Exception When that class is missing.
	 *
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @return void
	 */
	private static function refuse( $code, $message ) {
		if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( esc_html( $code ), esc_html( $message ), 400 );
		}
		throw new Exception( esc_html( $message ) );
	}

	/**
	 * Checkout block placed an order: tidy the ABN and keep the register's answer with it.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public function after_block_checkout( $order ) {
		if ( ! $order instanceof WC_Order || ! DABN_Settings::field_on() ) {
			return;
		}
		if ( ! DABN_Settings::applies_to_country( $order->get_billing_country() ) ) {
			self::forget( $order );
			return;
		}
		self::sync( $order, false, 'order' );
	}

	/**
	 * The order was saved in the admin (WooCommerce has stored the billing fields by now).
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public function after_admin_save( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order instanceof WC_Order ) {
			self::sync( $order );
		}
	}

	/**
	 * An order was created or updated through the REST API (an ERP, an app, an import).
	 *
	 * @param WC_Data $order Order.
	 * @return void
	 */
	public function after_rest_insert( $order ) {
		if ( $order instanceof WC_Order && '' !== trim( (string) $order->get_meta( self::ORDER_KEY, true ) . (string) $order->get_meta( self::block_key(), true ) ) ) {
			self::sync( $order, false, 'order' );
		}
	}

	/**
	 * Remove the ABN from an order that must not carry it, and an unusable one from the shopper.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	private static function forget( $order ) {
		$had = '' !== (string) $order->get_meta( self::ORDER_KEY, true ) || '' !== (string) $order->get_meta( self::block_key(), true ) || '' !== (string) $order->get_meta( self::CHECK_KEY, true );
		if ( $had ) {
			$order->delete_meta_data( self::ORDER_KEY );
			$order->delete_meta_data( self::block_key() );
			$order->delete_meta_data( self::CHECK_KEY );
			$order->save();
		}

		// The shopper's session (and, when logged in, their account) may hold the same unchecked value.
		// A well-formed ABN stays: it was saved through a validated field, or will be checked when used.
		$customer = function_exists( 'WC' ) && WC()->customer instanceof WC_Customer ? WC()->customer : null;
		if ( $customer ) {
			$copy = trim( (string) $customer->get_meta( self::block_key(), true ) );
			if ( '' !== $copy && ! DABN_ABN::is_valid( $copy ) ) {
				$customer->delete_meta_data( self::block_key() );
				if ( ! DABN_ABN::is_valid( $customer->get_meta( self::USER_KEY, true ) ) ) {
					$customer->delete_meta_data( self::USER_KEY );
				}
				$customer->save();
			}
		}
	}

	/**
	 * Let the order search find orders by ABN, typed with or without spaces.
	 *
	 * @param array $keys Meta keys searched.
	 * @return array
	 */
	public function search_keys( $keys ) {
		if ( ! is_array( $keys ) ) {
			return $keys;
		}
		$keys[] = self::ORDER_KEY;
		$keys[] = self::block_key();
		return array_values( array_unique( $keys ) );
	}

	/**
	 * Tidy the ABN of an order and make sure its check record belongs to it.
	 *
	 * @param WC_Order $order   Order.
	 * @param bool     $fresh   Ask the register again even when a record exists.
	 * @param string   $purpose "order" when an order is being placed (see DABN_ABR::lookup()).
	 * @return array|null The check record, or null when the order has no ABN.
	 */
	public static function sync( $order, $fresh = false, $purpose = 'lookup' ) {
		if ( ! $order instanceof WC_Order ) {
			return null;
		}
		$changed = false;
		$stored  = (string) $order->get_meta( self::ORDER_KEY, true );
		$block   = (string) $order->get_meta( self::block_key(), true );
		$abn     = DABN_ABN::digits( $stored );

		if ( '' === $abn && '' !== trim( $stored ) ) {
			// Text without a single digit (typed on the order screen, sent by an import) is neither an
			// ABN nor "no ABN". The ABN the order had stays, and a note says why.
			$abn   = DABN_ABN::digits( $block );
			$typed = mb_substr( sanitize_text_field( $stored ), 0, 40 );
			$order->add_order_note(
				'' !== $abn
					/* translators: %s: what was typed in the ABN field. */
					? sprintf( __( 'ABN not changed: "%s" is not a number.', 'demfilz-abn-validation-for-woocommerce' ), $typed )
					/* translators: %s: what was typed in the ABN field. */
					: sprintf( __( 'ABN not saved: "%s" is not a number.', 'demfilz-abn-validation-for-woocommerce' ), $typed )
			);
		} elseif ( '' === $abn && '' !== DABN_ABN::digits( $block ) ) {
			if ( $order->meta_exists( self::ORDER_KEY ) ) {
				// The ABN was emptied on purpose (the order screen saved an empty field): drop the copy too.
				$order->delete_meta_data( self::block_key() );
				$block   = '';
				$changed = true;
			} else {
				// An order that only ever had the checkout block's copy (placed while this plugin was off).
				$abn = DABN_ABN::digits( $block );
			}
		}

		if ( $stored !== $abn || ( '' === $abn && $order->meta_exists( self::ORDER_KEY ) ) ) {
			if ( '' === $abn ) {
				$order->delete_meta_data( self::ORDER_KEY );
			} else {
				$order->update_meta_data( self::ORDER_KEY, $abn );
			}
			$changed = true;
		}

		// WooCommerce prints its copy as it is (order screen, emails, My Account), so every order with an
		// ABN gets one, formatted. It also lets the order search match an ABN typed with spaces.
		if ( '' !== $abn && DABN_ABN::format( $abn ) !== $block ) {
			$order->update_meta_data( self::block_key(), DABN_ABN::format( $abn ) );
			$changed = true;
		}

		$check  = $order->get_meta( self::CHECK_KEY, true );
		$record = null;
		$mine   = is_array( $check ) && isset( $check['abn'] ) && $check['abn'] === $abn && ( ! isset( $check['order'] ) || (int) $check['order'] === (int) $order->get_id() );
		if ( '' === $abn ) {
			if ( ! empty( $check ) ) {
				$order->delete_meta_data( self::CHECK_KEY );
				$changed = true;
			}
		} elseif ( $fresh || ! $mine ) {
			$result = DABN_Verify::check(
				$abn,
				array(
					'fresh'   => $fresh,
					'purpose' => $purpose,
				)
			);
			if ( is_array( $result['record'] ) ) {
				$record          = $result['record'];
				$record['order'] = (int) $order->get_id();
				$order->update_meta_data( self::CHECK_KEY, $record );
				$changed = true;
			}
		} else {
			$record = $check;
		}

		if ( $changed ) {
			$order->save();
		}

		/**
		 * The ABN of an order was tidied and checked.
		 *
		 * @param WC_Order   $order  Order.
		 * @param string     $abn    Eleven digits, or empty when the order has no ABN.
		 * @param array|null $record Check record: abn, result, name, type, code, names, gst, since, state,
		 *                           postcode, acn, checked, note, order.
		 */
		do_action( 'dabn_order_synced', $order, $abn, $record );

		return $record;
	}
}
