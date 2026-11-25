<?php
/**
 * AJAX lookups: the checkout's live check and the admin lookup tool.
 *
 * @package DemfilzAbnValidation
 */

defined( 'ABSPATH' ) || exit;

/**
 * Answers lookups without ever sending the store's GUID to the browser.
 */
class DABN_Ajax {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_ajax_dabn_lookup', array( $this, 'checkout_lookup' ) );
		add_action( 'wp_ajax_nopriv_dabn_lookup', array( $this, 'checkout_lookup' ) );
		add_action( 'wp_ajax_dabn_admin_lookup', array( $this, 'admin_lookup' ) );
	}

	/**
	 * Checkout: check one ABN for a shopper (guests included).
	 *
	 * Read only. It returns the outcome of the store's policy and, for an active ABN, the business name
	 * from the public register. Lookups are cached per ABN and capped per IP address and per hour.
	 *
	 * @return void
	 */
	public function checkout_lookup() {
		check_ajax_referer( 'woocommerce-dabn-lookup', 'nonce' );

		if ( ! DABN_Settings::field_on() ) {
			wp_send_json_error( array( 'code' => 'off' ), 404 );
		}

		$raw   = isset( $_POST['abn'] ) ? sanitize_text_field( wp_unslash( $_POST['abn'] ) ) : '';
		$check = DABN_Verify::check( mb_substr( $raw, 0, 40 ) );

		$answer = array(
			'ok'      => $check['ok'],
			'code'    => $check['code'],
			'message' => $check['message'],
			'abn'     => '' !== $check['abn'] ? DABN_ABN::format( $check['abn'] ) : '',
			'name'    => '',
		);
		if ( in_array( $check['code'], array( 'active', 'no_gst' ), true ) && is_array( $check['record'] ) ) {
			$answer['name'] = DABN_ABR::display_name( $check['record'] );
		}

		wp_send_json_success( $answer );
	}

	/**
	 * Admin lookup tool: an ABN, an ACN or a name.
	 *
	 * @return void
	 */
	public function admin_lookup() {
		check_ajax_referer( 'dabn_admin_lookup', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'demfilz-abn-validation-for-woocommerce' ) ), 403 );
		}

		$query = isset( $_POST['q'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['q'] ) ) ) : '';
		$query = mb_substr( $query, 0, 200 );
		// A number pasted with its label ("ABN 51 824 753 556", "ACN: 004 085 616") is still a number.
		$query  = trim( (string) preg_replace( '/^(?:abn|acn)\b[\s:#.]*(?=\d)/i', '', $query ) );
		$digits = DABN_ABN::digits( $query );
		// Digits, spaces and separators only: a number. Anything with letters is a name.
		$is_number = '' !== $digits && 1 === preg_match( '/^[0-9\s.\-]+$/', $query );

		if ( '' === $query ) {
			wp_send_json_error( array( 'message' => __( 'Type an ABN, an ACN or a business name.', 'demfilz-abn-validation-for-woocommerce' ) ) );
		}

		if ( $is_number && 11 === strlen( $digits ) ) {
			if ( ! DABN_ABN::is_valid( $digits ) ) {
				wp_send_json_error( array( 'message' => __( 'That is not a valid ABN: the check digits do not add up.', 'demfilz-abn-validation-for-woocommerce' ) ) );
			}
			$result = DABN_ABR::lookup( $digits, array( 'fresh' => true ) );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error(
					array(
						'message' => self::admin_error( $result ),
						'note'    => 'dabn_no_guid' === $result->get_error_code() ? __( 'The format is valid: this is a well-formed ABN.', 'demfilz-abn-validation-for-woocommerce' ) : '',
					)
				);
			}
			wp_send_json_success(
				array(
					'kind'   => 'record',
					'record' => self::admin_record( $result ),
				)
			);
		}

		if ( $is_number && 9 === strlen( $digits ) ) {
			if ( ! DABN_ABN::is_valid_acn( $digits ) ) {
				wp_send_json_error( array( 'message' => __( 'That is not a valid ACN: the check digit does not add up.', 'demfilz-abn-validation-for-woocommerce' ) ) );
			}
			$result = DABN_ABR::lookup_acn( $digits );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => self::admin_error( $result ) ) );
			}
			wp_send_json_success(
				array(
					'kind'   => 'record',
					'record' => self::admin_record( $result ),
				)
			);
		}

		if ( $is_number ) {
			wp_send_json_error( array( 'message' => __( 'An ABN has 11 digits and an ACN has 9.', 'demfilz-abn-validation-for-woocommerce' ) ) );
		}

		$names = DABN_ABR::search_names( $query, 10 );
		if ( is_wp_error( $names ) ) {
			wp_send_json_error( array( 'message' => self::admin_error( $names ) ) );
		}
		$rows = array();
		foreach ( $names as $row ) {
			$rows[] = array(
				'abn'      => DABN_ABN::format( $row['abn'] ),
				'name'     => $row['name'],
				'score'    => $row['score'],
				'place'    => trim( $row['state'] . ' ' . $row['postcode'] ),
				'validAbn' => DABN_ABN::is_valid( $row['abn'] ),
			);
		}
		wp_send_json_success(
			array(
				'kind'  => 'names',
				'names' => $rows,
			)
		);
	}

	/**
	 * A register record as the lookup tool shows it: label and value pairs, already worded.
	 *
	 * @param array $result Register record.
	 * @return array
	 */
	private static function admin_record( $result ) {
		$rows = array();
		if ( 'not_found' === $result['result'] ) {
			return array(
				'result' => 'not_found',
				'title'  => __( 'Not on the Australian Business Register', 'demfilz-abn-validation-for-woocommerce' ),
				'abn'    => DABN_ABN::format( $result['abn'] ),
				'url'    => '',
				'rows'   => $rows,
			);
		}

		$rows[] = array( __( 'ABN', 'demfilz-abn-validation-for-woocommerce' ), DABN_ABN::format( $result['abn'] ) );
		$rows[] = array( __( 'Status', 'demfilz-abn-validation-for-woocommerce' ), DABN_Display::status_text( $result ) );
		if ( '' !== $result['name'] ) {
			$rows[] = array( __( 'Entity name', 'demfilz-abn-validation-for-woocommerce' ), $result['name'] );
		}
		if ( '' !== $result['type'] ) {
			$rows[] = array( __( 'Entity type', 'demfilz-abn-validation-for-woocommerce' ), $result['type'] );
		}
		$rows[] = array( __( 'GST', 'demfilz-abn-validation-for-woocommerce' ), DABN_Display::gst_text( $result ) );
		if ( '' !== trim( $result['state'] . $result['postcode'] ) ) {
			$rows[] = array( __( 'Main business location', 'demfilz-abn-validation-for-woocommerce' ), trim( $result['state'] . ' ' . $result['postcode'] ) );
		}
		if ( ! empty( $result['names'] ) ) {
			$rows[] = array( __( 'Business names', 'demfilz-abn-validation-for-woocommerce' ), implode( ', ', $result['names'] ) );
		}
		if ( '' !== $result['acn'] ) {
			$rows[] = array( __( 'ACN', 'demfilz-abn-validation-for-woocommerce' ), DABN_ABN::format_acn( $result['acn'] ) );
		}

		return array(
			'result' => $result['result'],
			'title'  => DABN_ABR::display_name( $result ),
			'abn'    => DABN_ABN::format( $result['abn'] ),
			'url'    => DABN_ABR::record_url( $result['abn'] ),
			'rows'   => $rows,
		);
	}

	/**
	 * Why a lookup failed, in words for the shop manager.
	 *
	 * @param WP_Error $error Error from the register client.
	 * @return string
	 */
	public static function admin_error( $error ) {
		switch ( $error->get_error_code() ) {
			case 'dabn_no_guid':
				return __( 'No ABN Lookup GUID is saved yet. Add your GUID in the Settings section to look ABNs up on the register.', 'demfilz-abn-validation-for-woocommerce' );
			case 'dabn_bad_guid':
				$message = $error->get_error_message();
				/* translators: %s: message from the Australian Business Register. */
				return '' !== $message ? sprintf( __( 'The Australian Business Register did not accept your GUID: %s', 'demfilz-abn-validation-for-woocommerce' ), $message ) : __( 'The Australian Business Register did not accept your GUID.', 'demfilz-abn-validation-for-woocommerce' );
			case 'dabn_rate_limited':
				return __( 'Too many lookups in the last hour. Please try again later.', 'demfilz-abn-validation-for-woocommerce' );
			case 'dabn_invalid':
				return $error->get_error_message();
			default:
				$message = $error->get_error_message();
				return '' !== $message ? $message : __( 'The Australian Business Register could not be reached. Please try again in a minute.', 'demfilz-abn-validation-for-woocommerce' );
		}
	}
}
