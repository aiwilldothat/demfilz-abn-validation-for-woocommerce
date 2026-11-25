<?php
/**
 * Settings storage.
 *
 * @package DemfilzAbnValidation
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and sanitises the plugin settings.
 */
class DABN_Settings {

	/**
	 * Option name.
	 */
	const OPTION = 'dabn_settings';

	/**
	 * Per-request cache.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Default values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'field'        => 'optional',
			'scope'        => 'au',
			'label'        => '',
			'autofill'     => 'yes',
			'address_line' => 'no',
			'guid'         => '',
			'verify'       => 'active',
			'require_gst'  => 'no',
			'cache_hours'  => 24,
			'delete_data'  => 'no',
		);
	}

	/**
	 * All settings, merged over the defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = self::sanitize( is_array( $stored ) ? array_merge( self::defaults(), $stored ) : self::defaults() );
		}
		return self::$cache;
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Whether a yes/no setting is on.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public static function on( $key ) {
		return 'yes' === self::get( $key );
	}

	/**
	 * Save settings.
	 *
	 * @param array $input Raw values.
	 * @return void
	 */
	public static function save( $input ) {
		update_option( self::OPTION, self::sanitize( array_merge( self::defaults(), (array) $input ) ), true );
		self::$cache = null;
	}

	/**
	 * Clear the per-request cache (after another process changed the option).
	 *
	 * @return void
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Whether the ABN field is offered at all.
	 *
	 * @return bool
	 */
	public static function field_on() {
		return 'off' !== self::get( 'field' );
	}

	/**
	 * Whether an ABN must be given (by those the field applies to).
	 *
	 * @return bool
	 */
	public static function field_required() {
		return 'required' === self::get( 'field' );
	}

	/**
	 * Whether an ABN must be given once a company name is filled in.
	 *
	 * @return bool
	 */
	public static function field_required_with_company() {
		return 'company' === self::get( 'field' );
	}

	/**
	 * Whether an order with this billing country and company must carry an ABN.
	 *
	 * @param string $country Billing country code.
	 * @param string $company Billing company.
	 * @return bool
	 */
	public static function is_required_for( $country, $company = '' ) {
		if ( ! self::field_on() || ! self::applies_to_country( $country ) ) {
			return false;
		}
		if ( self::field_required() ) {
			return true;
		}
		return self::field_required_with_company() && '' !== trim( (string) $company );
	}

	/**
	 * Whether the field is only for Australian billing addresses.
	 *
	 * @return bool
	 */
	public static function australia_only() {
		return 'au' === self::get( 'scope' );
	}

	/**
	 * Whether the field applies to a billing country.
	 *
	 * @param string $country Two letter country code; empty means not known yet.
	 * @return bool
	 */
	public static function applies_to_country( $country ) {
		if ( ! self::australia_only() ) {
			return true;
		}
		return 'AU' === strtoupper( (string) $country );
	}

	/**
	 * Label of the field.
	 *
	 * @return string
	 */
	public static function label() {
		$label = (string) self::get( 'label' );
		return '' !== $label ? $label : __( 'ABN', 'demfilz-abn-validation-for-woocommerce' );
	}

	/**
	 * The store's ABN Lookup GUID, empty when none is saved.
	 *
	 * @return string
	 */
	public static function guid() {
		return (string) self::get( 'guid' );
	}

	/**
	 * What happens when an ABN is entered: format, record or active.
	 *
	 * Without a GUID nothing can be looked up, so the answer is always "format".
	 *
	 * @return string
	 */
	public static function verify_mode() {
		if ( '' === self::guid() ) {
			return 'format';
		}
		return (string) self::get( 'verify' );
	}

	/**
	 * Whether ABNs are looked up on the register.
	 *
	 * @return bool
	 */
	public static function lookups_on() {
		return 'format' !== self::verify_mode();
	}

	/**
	 * Seconds a found record stays in the cache.
	 *
	 * @return int
	 */
	public static function cache_seconds() {
		return (int) self::get( 'cache_hours' ) * HOUR_IN_SECONDS;
	}

	/**
	 * Whether a string has the shape of an ABN Lookup GUID.
	 *
	 * @param string $guid Candidate.
	 * @return bool
	 */
	public static function is_guid( $guid ) {
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', (string) $guid );
	}

	/**
	 * Sanitise a full settings array.
	 *
	 * @param array $input Values merged over the defaults.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$out = self::defaults();

		$choices = array(
			'field'  => array( 'optional', 'required', 'company', 'off' ),
			'scope'  => array( 'au', 'all' ),
			'verify' => array( 'format', 'record', 'active' ),
		);
		foreach ( $choices as $key => $allowed ) {
			$value       = isset( $input[ $key ] ) ? sanitize_key( $input[ $key ] ) : $out[ $key ];
			$out[ $key ] = in_array( $value, $allowed, true ) ? $value : $out[ $key ];
		}

		foreach ( array( 'autofill', 'address_line', 'require_gst', 'delete_data' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = in_array( $input[ $key ], array( 'yes', '1', 1, true ), true ) ? 'yes' : 'no';
			}
		}

		$out['label'] = isset( $input['label'] ) ? mb_substr( sanitize_text_field( $input['label'] ), 0, 40 ) : '';

		// GUIDs are hexadecimal with hyphens; braces and upper case from a pasted email are tidied away.
		$guid        = isset( $input['guid'] ) ? strtolower( trim( sanitize_text_field( $input['guid'] ), " \t{}" ) ) : '';
		$out['guid'] = self::is_guid( $guid ) ? $guid : '';

		$hours              = isset( $input['cache_hours'] ) ? (int) $input['cache_hours'] : $out['cache_hours'];
		$out['cache_hours'] = max( 1, min( 720, $hours ) );

		return $out;
	}
}
