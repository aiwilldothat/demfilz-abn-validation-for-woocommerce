<?php
/**
 * The ABN field in both checkouts.
 *
 * @package DemfilzAbnValidation
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the field with the checkout block and adds it to the classic checkout form.
 */
class DABN_Checkout {

	/**
	 * Name of the field in the classic checkout.
	 */
	const CLASSIC_KEY = 'billing_abn';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		// Checkout block (also My Account > Account details, the order screen, emails: WooCommerce prints the field there).
		add_action( 'woocommerce_init', array( $this, 'register_block_field' ) );

		// Classic checkout.
		add_filter( 'woocommerce_checkout_fields', array( $this, 'classic_field' ) );
		add_filter( 'woocommerce_form_field_args', array( $this, 'classic_field_marker' ), 10, 2 );
		add_filter( 'woocommerce_checkout_get_value', array( $this, 'classic_value' ), 10, 2 );
		add_filter( 'woocommerce_checkout_posted_data', array( $this, 'classic_posted_data' ) );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'classic_validate' ), 10, 2 );

		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
	}

	/**
	 * Register the field with the checkout block.
	 *
	 * @return void
	 */
	public function register_block_field() {
		if ( ! DABN_Settings::field_on() || ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}

		$label = DABN_Settings::label();
		$args  = array(
			'id'                         => DABN_Orders::FIELD_ID,
			'label'                      => $label,
			/* translators: %s: field label, for example ABN. */
			'optionalLabel'              => sprintf( __( '%s (optional)', 'demfilz-abn-validation-for-woocommerce' ), $label ),
			'location'                   => 'contact',
			'type'                       => 'text',
			// "Required" is a rule (set below) or nothing: DABN_Orders refuses an order without an ABN
			// when one is required, whatever this WooCommerce version does with the rule.
			'required'                   => false,
			'attributes'                 => array(
				'maxLength'    => 14,
				'autocomplete' => 'off',
				'data-dabn'    => 'abn',
			),
			// With the ABN in the billing address, the separate line would say it twice.
			'show_in_order_confirmation' => ! DABN_Settings::on( 'address_line' ),
			'sanitize_callback'          => array( $this, 'block_sanitize' ),
			'validate_callback'          => array( $this, 'block_validate' ),
		);

		// Rules need a WooCommerce version that evaluates them. Without it the field is shown to everyone
		// as optional, and DABN_Orders applies the country and "required" settings when the order is placed.
		if ( class_exists( '\Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFieldsSchema\Validation' ) ) {
			$au_only = DABN_Settings::australia_only();

			// Australian billing addresses only: WooCommerce hides the field, and skips it, for other
			// countries. The rule holds only once a country other than Australia is set. While the address
			// is still loading (no country yet) the field stays, otherwise WooCommerce would empty a saved ABN.
			if ( $au_only ) {
				$args['hidden'] = self::billing_rule(
					array(
						'country' => array(
							'type' => 'string',
							'not'  => array(
								'enum' => array( 'AU', '' ),
							),
						),
					)
				);
			}

			// Required only where the field applies: a customer with no billing country yet (a new
			// account) must still be able to save their account details without an ABN.
			$needs = array();
			if ( DABN_Settings::field_required() || DABN_Settings::field_required_with_company() ) {
				$needs['country'] = $au_only
					? array( 'const' => 'AU' )
					: array(
						'type'      => 'string',
						'minLength' => 1,
					);
			}
			if ( DABN_Settings::field_required_with_company() ) {
				$needs['company'] = array(
					'type'    => 'string',
					'pattern' => '\S',
				);
			}
			if ( $needs ) {
				$args['required'] = self::billing_rule( $needs );
			}
		}

		/**
		 * Arguments of the ABN field in the checkout block.
		 *
		 * @param array $args See woocommerce_register_additional_checkout_field().
		 */
		$args = (array) apply_filters( 'dabn_block_field', $args );

		woocommerce_register_additional_checkout_field( $args );
	}

	/**
	 * A checkout block rule that holds when the billing address has every one of the given properties
	 * and each matches its JSON schema. A missing address or property means the rule does not hold.
	 *
	 * @param array $properties Property name => JSON schema.
	 * @return array
	 */
	private static function billing_rule( $properties ) {
		return array(
			'customer' => array(
				'required'   => array( 'billing_address' ),
				'properties' => array(
					'billing_address' => array(
						'required'   => array_keys( $properties ),
						'properties' => $properties,
					),
				),
			),
		);
	}

	/**
	 * Checkout block: store a well-formed ABN the way it is written, 51 824 753 556.
	 *
	 * Anything else is kept as typed so the validation can refuse it; emptying it here would let a
	 * mistyped ABN pass as "no ABN given".
	 *
	 * @param mixed $value Value from the shopper.
	 * @return string
	 */
	public function block_sanitize( $value ) {
		$text   = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
		$digits = DABN_ABN::digits( $text );
		return 11 === strlen( $digits ) ? DABN_ABN::format( $digits ) : $text;
	}

	/**
	 * Checkout block: refuse an ABN the store's policy does not accept.
	 *
	 * @param mixed $value Sanitised value.
	 * @return true|WP_Error
	 */
	public function block_validate( $value ) {
		$check = DABN_Verify::check( $value );
		if ( ! $check['ok'] ) {
			return new WP_Error( 'dabn_invalid_abn', $check['message'] );
		}
		return true;
	}

	/**
	 * Classic checkout: add the field after Company.
	 *
	 * WooCommerce saves a billing field called billing_abn as order meta _billing_abn and customer
	 * meta billing_abn. "Required" is enforced in classic_validate(), because it depends on the country.
	 *
	 * @param array $fields Checkout fields.
	 * @return array
	 */
	public function classic_field( $fields ) {
		if ( ! DABN_Settings::field_on() || ! is_array( $fields ) ) {
			return $fields;
		}
		if ( ! isset( $fields['billing'] ) || ! is_array( $fields['billing'] ) ) {
			$fields['billing'] = array();
		}

		$field = array(
			'label'             => DABN_Settings::label(),
			'required'          => false,
			'class'             => array( 'form-row-wide', 'dabn-field' ),
			'priority'          => 35,
			'maxlength'         => 14,
			'autocomplete'      => 'off',
			'custom_attributes' => array(
				'inputmode' => 'numeric',
				'data-dabn' => 'abn',
			),
		);

		/**
		 * Arguments of the ABN field in the classic checkout.
		 *
		 * @param array $field See woocommerce_form_field().
		 */
		$fields['billing'][ self::CLASSIC_KEY ] = (array) apply_filters( 'dabn_classic_field', $field );

		return $fields;
	}

	/**
	 * Classic checkout: show the required marker when the store asks for an ABN.
	 *
	 * Only the printed field changes; WooCommerce's own "required" test stays off, so customers the field
	 * does not apply to (a billing address outside Australia) are not refused.
	 *
	 * @param array  $args Field arguments.
	 * @param string $key  Field name.
	 * @return array
	 */
	public function classic_field_marker( $args, $key ) {
		if ( self::CLASSIC_KEY === $key && DABN_Settings::field_on() && DABN_Settings::field_required() ) {
			$args['required'] = true;
		}
		return $args;
	}

	/**
	 * Classic checkout: pre-fill a logged-in customer's saved ABN, formatted.
	 *
	 * @param mixed  $value Null unless another filter answered.
	 * @param string $input Field name.
	 * @return mixed
	 */
	public function classic_value( $value, $input ) {
		if ( self::CLASSIC_KEY !== $input || null !== $value || ! is_user_logged_in() ) {
			return $value;
		}
		$abn = DABN_Orders::get_customer_abn( get_current_user_id() );
		return '' !== $abn ? DABN_ABN::format( $abn ) : $value;
	}

	/**
	 * Classic checkout: normalise the posted ABN before WooCommerce validates and saves it.
	 *
	 * @param array $data Posted checkout data.
	 * @return array
	 */
	public function classic_posted_data( $data ) {
		if ( ! DABN_Settings::field_on() || ! is_array( $data ) || ! isset( $data[ self::CLASSIC_KEY ] ) ) {
			return $data;
		}
		$country = isset( $data['billing_country'] ) ? (string) $data['billing_country'] : '';
		if ( ! DABN_Settings::applies_to_country( $country ) ) {
			// The field is hidden for this country: save nothing, and leave any ABN on the account alone.
			unset( $data[ self::CLASSIC_KEY ] );
			return $data;
		}
		$text   = is_scalar( $data[ self::CLASSIC_KEY ] ) ? trim( (string) $data[ self::CLASSIC_KEY ] ) : '';
		$digits = DABN_ABN::digits( $text );
		// A mistyped ABN is kept as typed, so the validation below refuses it instead of seeing "no ABN".
		$data[ self::CLASSIC_KEY ] = 11 === strlen( $digits ) ? $digits : $text;
		return $data;
	}

	/**
	 * Classic checkout: required and policy checks.
	 *
	 * @param array    $data   Posted checkout data.
	 * @param WP_Error $errors Validation errors.
	 * @return void
	 */
	public function classic_validate( $data, $errors ) {
		if ( ! DABN_Settings::field_on() || ! $errors instanceof WP_Error ) {
			return;
		}
		$country = isset( $data['billing_country'] ) ? (string) $data['billing_country'] : '';
		if ( ! DABN_Settings::applies_to_country( $country ) ) {
			return;
		}
		$value   = isset( $data[ self::CLASSIC_KEY ] ) && is_scalar( $data[ self::CLASSIC_KEY ] ) ? trim( (string) $data[ self::CLASSIC_KEY ] ) : '';
		$company = isset( $data['billing_company'] ) && is_scalar( $data['billing_company'] ) ? (string) $data['billing_company'] : '';

		if ( '' === $value ) {
			if ( DABN_Settings::is_required_for( $country, $company ) ) {
				$errors->add( self::CLASSIC_KEY . '_required', self::required_message( true ), array( 'id' => self::CLASSIC_KEY ) );
			}
			return;
		}

		$check = DABN_Verify::check( $value, array( 'purpose' => 'order' ) );
		if ( ! $check['ok'] ) {
			$errors->add( self::CLASSIC_KEY . '_validation', esc_html( $check['message'] ), array( 'id' => self::CLASSIC_KEY ) );
		}
	}

	/**
	 * What a shopper is told when an order needs an ABN and has none.
	 *
	 * @param bool $html Wrap the label in a strong tag (WooCommerce's classic notices are HTML).
	 * @return string
	 */
	public static function required_message( $html = false ) {
		$label = $html ? '<strong>' . esc_html( DABN_Settings::label() ) . '</strong>' : DABN_Settings::label();
		if ( DABN_Settings::field_required_with_company() ) {
			/* translators: %s: field label, for example ABN. */
			return sprintf( __( '%s is required when you order for a company.', 'demfilz-abn-validation-for-woocommerce' ), $label );
		}
		/* translators: %s: field label, for example ABN. */
		return sprintf( __( '%s is a required field.', 'demfilz-abn-validation-for-woocommerce' ), $label );
	}

	/**
	 * Whether this page shows a checkout form.
	 *
	 * @return bool
	 */
	private static function is_checkout_form() {
		if ( ! function_exists( 'is_checkout' ) ) {
			return false;
		}
		if ( is_checkout() ) {
			return ! is_order_received_page() && ! is_checkout_pay_page();
		}
		return is_singular() && has_block( 'woocommerce/checkout' );
	}

	/**
	 * Load the script and stylesheet on the checkout.
	 *
	 * @return void
	 */
	public function assets() {
		if ( ! DABN_Settings::field_on() || ! self::is_checkout_form() ) {
			return;
		}

		wp_enqueue_style( 'dabn', DABN_URL . 'public/css/dabn.css', array(), DABN_VERSION );
		wp_enqueue_script( 'dabn-checkout', DABN_URL . 'public/js/dabn-checkout.js', array(), DABN_VERSION, true );

		wp_localize_script(
			'dabn-checkout',
			'dabnCheckout',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				// The action starts with "woocommerce" so that WooCommerce ties a visitor's nonce to their own
				// shopping session instead of one nonce shared by every logged-out visitor.
				'nonce'    => wp_create_nonce( 'woocommerce-dabn-lookup' ),
				'lookups'  => DABN_Settings::lookups_on(),
				'autofill' => DABN_Settings::on( 'autofill' ) && DABN_Settings::lookups_on(),
				'auOnly'   => DABN_Settings::australia_only(),
				'i18n'     => array(
					'checking' => __( 'Checking the Australian Business Register...', 'demfilz-abn-validation-for-woocommerce' ),
					'invalid'  => __( 'That is not a valid ABN. Please check the 11 digits.', 'demfilz-abn-validation-for-woocommerce' ),
					'short'    => __( 'An ABN has 11 digits.', 'demfilz-abn-validation-for-woocommerce' ),
					'format'   => __( 'This ABN has a valid format.', 'demfilz-abn-validation-for-woocommerce' ),
				),
			)
		);
	}
}
