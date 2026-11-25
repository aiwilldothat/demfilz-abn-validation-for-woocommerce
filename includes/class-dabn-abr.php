<?php
/**
 * Australian Business Register client.
 *
 * @package DemfilzAbnValidation
 */

defined( 'ABSPATH' ) || exit;

/**
 * Talks to the ABN Lookup JSON services, with a cache, a circuit breaker and request limits.
 *
 * The services answer with JSONP, for example:
 * callback({"Abn":"51824753556","AbnStatus":"Active","AbnStatusEffectiveFrom":"1999-11-01","Acn":"",
 * "AddressDate":"...","AddressPostcode":"2640","AddressState":"NSW","BusinessName":[],
 * "EntityName":"...","EntityTypeCode":"CGE","EntityTypeName":"...","Gst":"2000-07-01","Message":""})
 */
class DABN_ABR {

	/**
	 * Base URL of the JSON services.
	 */
	const BASE = 'https://abr.business.gov.au/json/';

	/**
	 * Public record of an ABN on ABN Lookup.
	 */
	const RECORD_URL = 'https://abr.business.gov.au/ABN/View?abn=';

	/**
	 * Where a store registers for its GUID.
	 */
	const REGISTER_URL = 'https://abr.business.gov.au/Tools/WebServices';

	/**
	 * An ABN that is always on the register (the Australian Taxation Office), used to test the connection.
	 */
	const PROBE_ABN = '51824753556';

	/**
	 * Lookups one IP address may cause per hour.
	 */
	const RATE_LIMIT = 20;

	/**
	 * Lookups all visitors together may cause per hour.
	 */
	const RATE_LIMIT_SITE = 300;

	/**
	 * Lookups that placing orders may cause per hour (uncached ABNs only), counted apart from the above.
	 */
	const RATE_LIMIT_ORDERS = 300;

	/**
	 * Results fetched in this request.
	 *
	 * @var array
	 */
	private static $memo = array();

	/**
	 * Look an ABN up.
	 *
	 * @param mixed $abn  ABN, in any format.
	 * @param array $args {
	 *     Optional.
	 *
	 *     @type bool   $fresh   Skip the cache and the pauses (used by "Check again" and the lookup tool).
	 *     @type string $guid    GUID to use instead of the saved one.
	 *     @type string $purpose "order" when an order is being placed or saved, "lookup" (default) for the
	 *                           live check while a visitor types. Each has its own hourly allowance, so a
	 *                           visitor who uses up the live lookups cannot switch off the check on orders.
	 * }
	 * @return array|WP_Error Register record, or an error with one of the codes dabn_invalid, dabn_no_guid,
	 *                        dabn_bad_guid, dabn_rate_limited, dabn_unavailable.
	 */
	public static function lookup( $abn, $args = array() ) {
		$abn   = DABN_ABN::digits( $abn );
		$fresh = ! empty( $args['fresh'] );
		$guid  = isset( $args['guid'] ) ? (string) $args['guid'] : DABN_Settings::guid();

		if ( ! DABN_ABN::is_valid( $abn ) ) {
			return new WP_Error( 'dabn_invalid', __( 'That is not a valid ABN.', 'demfilz-abn-validation-for-woocommerce' ) );
		}
		if ( '' === $guid ) {
			return new WP_Error( 'dabn_no_guid', __( 'No ABN Lookup GUID is saved, so ABNs are checked for a valid format only.', 'demfilz-abn-validation-for-woocommerce' ) );
		}

		if ( ! $fresh ) {
			if ( isset( self::$memo[ $abn ] ) ) {
				return self::$memo[ $abn ];
			}
			$cached = get_transient( 'dabn_abr_' . $abn );
			if ( is_array( $cached ) && isset( $cached['abn'], $cached['result'] ) && $abn === $cached['abn'] ) {
				self::$memo[ $abn ] = $cached;
				return $cached;
			}
			$paused = self::paused( $guid );
			if ( is_wp_error( $paused ) ) {
				return $paused;
			}
		}

		if ( ! self::allow_remote( isset( $args['purpose'] ) && 'order' === $args['purpose'] ? 'order' : 'lookup' ) ) {
			return new WP_Error( 'dabn_rate_limited', __( 'Too many ABN checks from this address. Please try again later.', 'demfilz-abn-validation-for-woocommerce' ) );
		}

		$data = self::request(
			'AbnDetails.aspx',
			array(
				'abn'  => $abn,
				'guid' => $guid,
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$result = self::parse_details( $data, $abn, $guid );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		/**
		 * The register's answer for an ABN, before it is cached.
		 *
		 * @param array  $result Normalised record: abn, result (active, cancelled or not_found), status, since,
		 *                       name, type, code, names, gst, state, postcode, acn, checked.
		 * @param string $abn    The ABN, eleven digits.
		 */
		$result = (array) apply_filters( 'dabn_lookup_result', $result, $abn );

		self::store( $result );
		return $result;
	}

	/**
	 * Look a company up by its ACN. The register answers with the ABN record of that company.
	 *
	 * @param mixed $acn ACN, in any format.
	 * @return array|WP_Error
	 */
	public static function lookup_acn( $acn ) {
		$acn  = DABN_ABN::digits( $acn );
		$guid = DABN_Settings::guid();
		if ( ! DABN_ABN::is_valid_acn( $acn ) ) {
			return new WP_Error( 'dabn_invalid', __( 'That is not a valid ACN.', 'demfilz-abn-validation-for-woocommerce' ) );
		}
		if ( '' === $guid ) {
			return new WP_Error( 'dabn_no_guid', __( 'No ABN Lookup GUID is saved, so nothing can be looked up yet.', 'demfilz-abn-validation-for-woocommerce' ) );
		}
		if ( ! self::allow_remote() ) {
			return new WP_Error( 'dabn_rate_limited', __( 'Too many ABN checks from this address. Please try again later.', 'demfilz-abn-validation-for-woocommerce' ) );
		}
		$data = self::request(
			'AcnDetails.aspx',
			array(
				'acn'  => $acn,
				'guid' => $guid,
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$result = self::parse_details( $data, '', $guid );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( 'not_found' !== $result['result'] ) {
			self::store( $result );
		}
		return $result;
	}

	/**
	 * Search the register by name.
	 *
	 * @param string $name Entity, business or trading name.
	 * @param int    $max  Most matches to return (1 to 20).
	 * @return array|WP_Error List of matches: abn, name, score, state, postcode.
	 */
	public static function search_names( $name, $max = 10 ) {
		$name = trim( sanitize_text_field( (string) $name ) );
		$guid = DABN_Settings::guid();
		if ( strlen( $name ) < 3 ) {
			return new WP_Error( 'dabn_invalid', __( 'Type at least three characters of the name.', 'demfilz-abn-validation-for-woocommerce' ) );
		}
		if ( '' === $guid ) {
			return new WP_Error( 'dabn_no_guid', __( 'No ABN Lookup GUID is saved, so nothing can be looked up yet.', 'demfilz-abn-validation-for-woocommerce' ) );
		}
		if ( ! self::allow_remote() ) {
			return new WP_Error( 'dabn_rate_limited', __( 'Too many ABN checks from this address. Please try again later.', 'demfilz-abn-validation-for-woocommerce' ) );
		}
		$data = self::request(
			'MatchingNames.aspx',
			array(
				'name'       => mb_substr( $name, 0, 200 ),
				'maxResults' => max( 1, min( 20, (int) $max ) ),
				'guid'       => $guid,
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$message = isset( $data['Message'] ) ? trim( (string) $data['Message'] ) : '';
		if ( '' !== $message && false !== stripos( $message, 'guid' ) ) {
			return self::bad_guid( $guid, $message );
		}
		$out = array();
		if ( isset( $data['Names'] ) && is_array( $data['Names'] ) ) {
			foreach ( $data['Names'] as $row ) {
				if ( ! is_array( $row ) || empty( $row['Abn'] ) ) {
					continue;
				}
				$out[] = array(
					'abn'      => DABN_ABN::digits( $row['Abn'] ),
					'name'     => isset( $row['Name'] ) ? self::text( $row['Name'], 200 ) : '',
					'score'    => isset( $row['Score'] ) ? (int) $row['Score'] : 0,
					'state'    => isset( $row['State'] ) ? self::text( $row['State'], 10 ) : '',
					'postcode' => isset( $row['Postcode'] ) ? self::text( $row['Postcode'], 10 ) : '',
				);
			}
		}
		return $out;
	}

	/**
	 * Whether the saved GUID works, checked once and remembered for twelve hours.
	 *
	 * @param bool $refresh Ask the register again.
	 * @return array ok (bool), code, message, checked (timestamp); ok is null when no GUID is saved.
	 */
	public static function connection( $refresh = false ) {
		$guid = DABN_Settings::guid();
		if ( '' === $guid ) {
			return array(
				'ok'      => null,
				'code'    => 'dabn_no_guid',
				'message' => '',
				'checked' => 0,
			);
		}
		$key = 'dabn_conn_' . md5( $guid );
		if ( ! $refresh ) {
			$known = get_transient( $key );
			if ( is_array( $known ) && isset( $known['ok'] ) ) {
				return $known;
			}
		}
		$result = self::lookup( self::PROBE_ABN, array( 'fresh' => true ) );
		$status = array(
			'ok'      => ! is_wp_error( $result ),
			'code'    => is_wp_error( $result ) ? $result->get_error_code() : '',
			'message' => is_wp_error( $result ) ? $result->get_error_message() : '',
			'checked' => time(),
		);
		// A failure is asked about again sooner than a success.
		set_transient( $key, $status, $status['ok'] ? 12 * HOUR_IN_SECONDS : 5 * MINUTE_IN_SECONDS );
		return $status;
	}

	/**
	 * Forget the pauses and the connection status (after the settings change).
	 *
	 * @return void
	 */
	public static function reset() {
		delete_transient( 'dabn_guid_bad' );
		delete_transient( 'dabn_abr_down' );
		$guid = DABN_Settings::guid();
		if ( '' !== $guid ) {
			delete_transient( 'dabn_conn_' . md5( $guid ) );
		}
		self::$memo = array();
	}

	/**
	 * The name to show for a record: a sole trader's business name when there is one, otherwise the entity name.
	 *
	 * @param array $record Register record or stored check.
	 * @return string
	 */
	public static function display_name( $record ) {
		$name  = isset( $record['name'] ) ? (string) $record['name'] : '';
		$names = isset( $record['names'] ) && is_array( $record['names'] ) ? $record['names'] : array();
		$code  = isset( $record['code'] ) ? (string) $record['code'] : '';
		if ( 'IND' === $code && ! empty( $names[0] ) ) {
			return (string) $names[0];
		}
		return '' !== $name ? $name : ( ! empty( $names[0] ) ? (string) $names[0] : '' );
	}

	/**
	 * Link to the public record of an ABN.
	 *
	 * @param string $abn ABN.
	 * @return string
	 */
	public static function record_url( $abn ) {
		return self::RECORD_URL . rawurlencode( DABN_ABN::digits( $abn ) );
	}

	/**
	 * Whether lookups are paused: the register rejected this GUID, or it did not answer a moment ago.
	 *
	 * @param string $guid GUID in use.
	 * @return true|WP_Error
	 */
	private static function paused( $guid ) {
		$bad = get_transient( 'dabn_guid_bad' );
		if ( is_array( $bad ) && isset( $bad['hash'] ) && md5( $guid ) === $bad['hash'] ) {
			return new WP_Error( 'dabn_bad_guid', isset( $bad['message'] ) ? (string) $bad['message'] : '' );
		}
		if ( get_transient( 'dabn_abr_down' ) ) {
			return new WP_Error( 'dabn_unavailable', __( 'The Australian Business Register could not be reached.', 'demfilz-abn-validation-for-woocommerce' ) );
		}
		return true;
	}

	/**
	 * Remember that the register rejected the GUID, and pause lookups for ten minutes.
	 *
	 * @param string $guid    GUID that was sent.
	 * @param string $message The register's message.
	 * @return WP_Error
	 */
	private static function bad_guid( $guid, $message ) {
		$message = self::text( $message, 300 );
		set_transient(
			'dabn_guid_bad',
			array(
				'hash'    => md5( $guid ),
				'message' => $message,
			),
			10 * MINUTE_IN_SECONDS
		);
		return new WP_Error( 'dabn_bad_guid', $message );
	}

	/**
	 * Send one request and decode the JSONP answer.
	 *
	 * @param string $endpoint File name of the service.
	 * @param array  $params   Query arguments.
	 * @return array|WP_Error Decoded answer.
	 */
	private static function request( $endpoint, $params ) {
		$params['callback'] = 'callback';
		$url                = add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $params ) ), self::BASE . $endpoint );

		/**
		 * Arguments of the request to the ABN Lookup service.
		 *
		 * @param array  $args     wp_safe_remote_get() arguments.
		 * @param string $endpoint Service file name.
		 */
		$args = (array) apply_filters(
			'dabn_request_args',
			array(
				'timeout'     => 8,
				'redirection' => 2,
				'headers'     => array( 'Accept' => 'application/json, text/javascript' ),
			),
			$endpoint
		);

		$response = wp_safe_remote_get( $url, $args );
		$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$body     = is_wp_error( $response ) ? '' : trim( (string) wp_remote_retrieve_body( $response ) );

		// The answer is wrapped as callback({...}); take the object out.
		if ( 1 === preg_match( '/^[A-Za-z_$][\w$.]*\s*\((.*)\)\s*;?$/s', $body, $match ) ) {
			$body = $match[1];
		}
		$data = 200 === $code && '' !== $body ? json_decode( $body, true ) : null;

		if ( ! is_array( $data ) ) {
			// Do not make every checkout wait for a register that is not answering.
			set_transient( 'dabn_abr_down', 1, MINUTE_IN_SECONDS );
			return new WP_Error( 'dabn_unavailable', __( 'The Australian Business Register could not be reached.', 'demfilz-abn-validation-for-woocommerce' ) );
		}
		return $data;
	}

	/**
	 * Turn an AbnDetails or AcnDetails answer into a record.
	 *
	 * @param array  $data     Decoded answer.
	 * @param string $expected ABN that was asked for, empty for an ACN lookup.
	 * @param string $guid     GUID that was sent.
	 * @return array|WP_Error
	 */
	private static function parse_details( $data, $expected, $guid ) {
		$message = isset( $data['Message'] ) ? trim( (string) $data['Message'] ) : '';
		$abn     = isset( $data['Abn'] ) ? DABN_ABN::digits( $data['Abn'] ) : '';

		if ( '' !== $message && false !== stripos( $message, 'guid' ) ) {
			return self::bad_guid( $guid, $message );
		}

		if ( '' === $abn ) {
			// Only a message saying the number is not held counts as "not found". An empty or unexpected
			// answer is treated as the register being unavailable, so nobody is refused by mistake.
			$says_missing = '' !== $message && 1 === preg_match( '/not\s+(a\s+)?valid|no\s+record|not\s+found|cannot\s+be\s+found|does\s+not\s+exist/i', $message );
			if ( ! $says_missing ) {
				return new WP_Error( 'dabn_unavailable', '' !== $message ? self::text( $message, 300 ) : __( 'The Australian Business Register sent an empty answer.', 'demfilz-abn-validation-for-woocommerce' ) );
			}
			return array(
				'abn'      => $expected,
				'result'   => 'not_found',
				'status'   => self::text( $message, 300 ),
				'since'    => '',
				'name'     => '',
				'type'     => '',
				'code'     => '',
				'names'    => array(),
				'gst'      => '',
				'state'    => '',
				'postcode' => '',
				'acn'      => '',
				'checked'  => time(),
			);
		}

		if ( '' !== $expected && $abn !== $expected ) {
			return new WP_Error( 'dabn_unavailable', __( 'The Australian Business Register answered for a different ABN.', 'demfilz-abn-validation-for-woocommerce' ) );
		}

		$status = isset( $data['AbnStatus'] ) ? self::text( $data['AbnStatus'], 40 ) : '';
		$names  = array();
		if ( isset( $data['BusinessName'] ) && is_array( $data['BusinessName'] ) ) {
			foreach ( array_slice( $data['BusinessName'], 0, 10 ) as $business_name ) {
				$business_name = self::text( $business_name, 200 );
				if ( '' !== $business_name ) {
					$names[] = $business_name;
				}
			}
		}

		return array(
			'abn'      => $abn,
			'result'   => 0 === strcasecmp( $status, 'Active' ) ? 'active' : 'cancelled',
			'status'   => $status,
			'since'    => self::date( isset( $data['AbnStatusEffectiveFrom'] ) ? $data['AbnStatusEffectiveFrom'] : '' ),
			'name'     => isset( $data['EntityName'] ) ? self::text( $data['EntityName'], 200 ) : '',
			'type'     => isset( $data['EntityTypeName'] ) ? self::text( $data['EntityTypeName'], 120 ) : '',
			'code'     => isset( $data['EntityTypeCode'] ) ? strtoupper( self::text( $data['EntityTypeCode'], 10 ) ) : '',
			'names'    => $names,
			'gst'      => self::date( isset( $data['Gst'] ) ? $data['Gst'] : '' ),
			'state'    => isset( $data['AddressState'] ) ? self::text( $data['AddressState'], 10 ) : '',
			'postcode' => isset( $data['AddressPostcode'] ) ? self::text( $data['AddressPostcode'], 10 ) : '',
			'acn'      => isset( $data['Acn'] ) ? DABN_ABN::digits( $data['Acn'] ) : '',
			'checked'  => time(),
		);
	}

	/**
	 * Cache a record.
	 *
	 * @param array $result Record.
	 * @return void
	 */
	private static function store( $result ) {
		if ( empty( $result['abn'] ) ) {
			return;
		}
		// A number the register does not hold may be registered tomorrow, so it is asked about again sooner.
		$ttl = 'not_found' === $result['result'] ? HOUR_IN_SECONDS : DABN_Settings::cache_seconds();
		set_transient( 'dabn_abr_' . $result['abn'], $result, $ttl );
		self::$memo[ $result['abn'] ] = $result;
	}

	/**
	 * Plain text from the register, trimmed to a length.
	 *
	 * @param mixed $value Value.
	 * @param int   $max   Most characters to keep.
	 * @return string
	 */
	private static function text( $value, $max ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		return mb_substr( trim( sanitize_text_field( (string) $value ) ), 0, $max );
	}

	/**
	 * A date from the register as Y-m-d, or empty. The register uses 0001-01-01 for "no date".
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function date( $value ) {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $value, $m ) ) {
			return '';
		}
		if ( (int) $m[1] < 1900 || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return '';
		}
		return $m[1] . '-' . $m[2] . '-' . $m[3];
	}

	/**
	 * Count this lookup against the hourly ceilings; false when one of them is used up.
	 *
	 * Shop managers are not limited. The ceilings stop a visitor from draining the store's GUID through
	 * the public checkout. They are soft: the counters live in transients, so two requests arriving at
	 * the same moment can count as one.
	 *
	 * @param string $purpose "lookup" (live check while typing) or "order" (an order is placed or saved).
	 * @return bool
	 */
	private static function allow_remote( $purpose = 'lookup' ) {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}
		if ( 'order' === $purpose ) {
			/**
			 * Register lookups that placing orders may cause per hour (0 = no limit). Counted apart from
			 * the live lookups, so using those up does not switch off the check on orders.
			 *
			 * @param int $limit Default 300.
			 */
			return self::take( 'dabn_rl_orders', (int) apply_filters( 'dabn_rate_limit_orders', self::RATE_LIMIT_ORDERS ) );
		}
		/**
		 * Register lookups one IP address may cause per hour (0 = no limit).
		 *
		 * @param int $limit Default 20.
		 */
		$per_ip = (int) apply_filters( 'dabn_rate_limit', self::RATE_LIMIT );
		/**
		 * Register lookups all visitors together may cause per hour (0 = no limit).
		 *
		 * @param int $limit Default 300.
		 */
		$site = (int) apply_filters( 'dabn_rate_limit_site', self::RATE_LIMIT_SITE );

		return self::take( self::rate_key(), $per_ip ) && self::take( 'dabn_rl_site', $site );
	}

	/**
	 * Take one from an hourly allowance kept in a transient.
	 *
	 * The hour starts at the first lookup and does not slide, so steady traffic cannot keep a full
	 * counter alive for ever.
	 *
	 * @param string $key   Transient name.
	 * @param int    $limit Allowance per hour; 0 or less means unlimited.
	 * @return bool
	 */
	private static function take( $key, $limit ) {
		if ( $limit <= 0 ) {
			return true;
		}
		$now  = time();
		$slot = get_transient( $key );
		if ( ! is_array( $slot ) || empty( $slot['t'] ) || $now - (int) $slot['t'] >= HOUR_IN_SECONDS ) {
			$slot = array(
				't' => $now,
				'n' => 0,
			);
		}
		if ( (int) $slot['n'] >= $limit ) {
			return false;
		}
		$slot['n'] = (int) $slot['n'] + 1;
		set_transient( $key, $slot, max( 1, HOUR_IN_SECONDS - ( $now - (int) $slot['t'] ) ) );
		return true;
	}

	/**
	 * Transient name of this visitor's counter. Keyed on REMOTE_ADDR only: forwarded-for headers can be forged.
	 *
	 * @return string
	 */
	private static function rate_key() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		// One IPv6 connection usually owns a whole /64, so count the /64 rather than each address in it.
		// IPv4-mapped addresses (::ffff:1.2.3.4) count as the IPv4 address they carry.
		if ( false !== strpos( $ip, ':' ) && false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$packed = inet_pton( $ip );
			if ( false !== $packed && 16 === strlen( $packed ) ) {
				if ( str_repeat( "\0", 10 ) . "\xff\xff" === substr( $packed, 0, 12 ) ) {
					$ip = (string) inet_ntop( substr( $packed, 12 ) );
				} else {
					$ip = bin2hex( substr( $packed, 0, 8 ) ) . '::/64';
				}
			}
		}
		return 'dabn_rl_' . md5( $ip . '|' . wp_salt( 'nonce' ) );
	}
}
