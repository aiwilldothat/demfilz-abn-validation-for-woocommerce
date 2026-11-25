<?php
/**
 * Decides whether an ABN is accepted.
 *
 * @package DemfilzAbnValidation
 */

defined( 'ABSPATH' ) || exit;

/**
 * Applies the store's policy to an ABN and builds the record kept on the order.
 */
class DABN_Verify {

	/**
	 * Check an ABN against the store's policy.
	 *
	 * @param mixed $raw  What was typed.
	 * @param array $args {
	 *     Optional.
	 *
	 *     @type bool   $fresh   Ask the register again instead of using the cache.
	 *     @type string $purpose "order" when an order is being placed or saved (see DABN_ABR::lookup()).
	 * }
	 * @return array {
	 *     @type bool       $ok      Whether the ABN may be saved.
	 *     @type string     $abn     Eleven digits, or empty.
	 *     @type string     $code    empty, invalid, unchecked, active, no_gst, cancelled, not_found or unavailable.
	 *     @type string     $message Sentence for the shopper (empty when there is nothing to say).
	 *     @type array|null $record  Record to keep on the order.
	 * }
	 */
	public static function check( $raw, $args = array() ) {
		$text = is_scalar( $raw ) ? trim( (string) $raw ) : '';
		$abn  = DABN_ABN::digits( $text );

		if ( '' === $text ) {
			return self::answer( true, '', 'empty', '', null, $raw );
		}

		if ( ! DABN_ABN::is_valid( $abn ) ) {
			return self::answer(
				false,
				11 === strlen( $abn ) ? $abn : '',
				'invalid',
				__( 'That is not a valid ABN. Please check the 11 digits.', 'demfilz-abn-validation-for-woocommerce' ),
				self::record( $abn, 'invalid' ),
				$raw
			);
		}

		$mode = DABN_Settings::verify_mode();
		if ( 'format' === $mode ) {
			return self::answer( true, $abn, 'unchecked', '', self::record( $abn, 'unchecked' ), $raw );
		}

		$result = DABN_ABR::lookup(
			$abn,
			array(
				'fresh'   => ! empty( $args['fresh'] ),
				'purpose' => isset( $args['purpose'] ) && 'order' === $args['purpose'] ? 'order' : 'lookup',
			)
		);

		if ( is_wp_error( $result ) ) {
			// The register is down, the GUID was rejected or the hourly ceiling was reached: never block a sale for that.
			return self::answer(
				true,
				$abn,
				'unavailable',
				__( 'We could not check this ABN right now. You can still place your order.', 'demfilz-abn-validation-for-woocommerce' ),
				self::record( $abn, 'unavailable', array(), $result->get_error_code() ),
				$raw
			);
		}

		$strict = 'active' === $mode;
		$name   = DABN_ABR::display_name( $result );

		if ( 'not_found' === $result['result'] ) {
			return self::answer(
				! $strict,
				$abn,
				'not_found',
				__( 'We could not find this ABN on the Australian Business Register. Please check the number.', 'demfilz-abn-validation-for-woocommerce' ),
				self::record( $abn, 'not_found', $result ),
				$raw
			);
		}

		if ( 'cancelled' === $result['result'] ) {
			return self::answer(
				! $strict,
				$abn,
				'cancelled',
				__( 'This ABN is cancelled on the Australian Business Register. Please check the number.', 'demfilz-abn-validation-for-woocommerce' ),
				self::record( $abn, 'cancelled', $result ),
				$raw
			);
		}

		$has_gst = '' !== $result['gst'];
		if ( DABN_Settings::on( 'require_gst' ) && ! $has_gst ) {
			return self::answer(
				! $strict,
				$abn,
				'no_gst',
				__( 'This ABN is not registered for GST.', 'demfilz-abn-validation-for-woocommerce' ),
				self::record( $abn, 'active', $result ),
				$raw
			);
		}

		// The name is what the register holds for this ABN. It does not prove who is typing it.
		if ( '' !== $name ) {
			$message = $has_gst
				/* translators: %s: business name from the register. */
				? sprintf( __( '%s. Active ABN, registered for GST.', 'demfilz-abn-validation-for-woocommerce' ), $name )
				/* translators: %s: business name from the register. */
				: sprintf( __( '%s. Active ABN, not registered for GST.', 'demfilz-abn-validation-for-woocommerce' ), $name );
		} else {
			$message = $has_gst
				? __( 'Active ABN, registered for GST.', 'demfilz-abn-validation-for-woocommerce' )
				: __( 'Active ABN, not registered for GST.', 'demfilz-abn-validation-for-woocommerce' );
		}

		return self::answer( true, $abn, 'active', $message, self::record( $abn, 'active', $result ), $raw );
	}

	/**
	 * Build the record that is kept on an order.
	 *
	 * @param string $abn    Eleven digits (or whatever digits were given, for an invalid ABN).
	 * @param string $result invalid, unchecked, unavailable, not_found, cancelled or active.
	 * @param array  $data   Register record, when there is one.
	 * @param string $note   Reason code when the ABN could not be checked.
	 * @return array
	 */
	public static function record( $abn, $result, $data = array(), $note = '' ) {
		return array(
			'abn'      => (string) $abn,
			'result'   => (string) $result,
			'name'     => isset( $data['name'] ) ? (string) $data['name'] : '',
			'type'     => isset( $data['type'] ) ? (string) $data['type'] : '',
			'code'     => isset( $data['code'] ) ? (string) $data['code'] : '',
			'names'    => isset( $data['names'] ) && is_array( $data['names'] ) ? array_values( array_map( 'strval', $data['names'] ) ) : array(),
			'gst'      => isset( $data['gst'] ) ? (string) $data['gst'] : '',
			'since'    => isset( $data['since'] ) ? (string) $data['since'] : '',
			'state'    => isset( $data['state'] ) ? (string) $data['state'] : '',
			'postcode' => isset( $data['postcode'] ) ? (string) $data['postcode'] : '',
			'acn'      => isset( $data['acn'] ) ? (string) $data['acn'] : '',
			'checked'  => isset( $data['checked'] ) ? (int) $data['checked'] : time(),
			'note'     => (string) $note,
		);
	}

	/**
	 * Assemble the answer and let other code adjust it.
	 *
	 * @param bool       $ok      Whether the ABN may be saved.
	 * @param string     $abn     Eleven digits or empty.
	 * @param string     $code    Outcome code.
	 * @param string     $message Sentence for the shopper.
	 * @param array|null $record  Record for the order.
	 * @param mixed      $raw     What was typed.
	 * @return array
	 */
	private static function answer( $ok, $abn, $code, $message, $record, $raw ) {
		$check = array(
			'ok'      => (bool) $ok,
			'abn'     => (string) $abn,
			'code'    => (string) $code,
			'message' => (string) $message,
			'record'  => $record,
		);

		/**
		 * The outcome of checking an ABN.
		 *
		 * @param array $check ok (bool), abn, code, message, record.
		 * @param mixed $raw   What was typed.
		 */
		$filtered = apply_filters( 'dabn_check', $check, $raw );
		return is_array( $filtered ) && isset( $filtered['ok'], $filtered['code'] ) ? array_merge( $check, $filtered ) : $check;
	}
}
