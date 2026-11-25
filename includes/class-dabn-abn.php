<?php
/**
 * ABN and ACN arithmetic.
 *
 * @package DemfilzAbnValidation
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pure helpers: no settings, no database, no remote requests.
 */
class DABN_ABN {

	/**
	 * Weights of the eleven ABN digits.
	 */
	const ABN_WEIGHTS = array( 10, 1, 3, 5, 7, 9, 11, 13, 15, 17, 19 );

	/**
	 * Keep the digits of whatever was typed ("51 824 753 556", "ABN: 51-824-753-556").
	 *
	 * @param mixed $value Raw value.
	 * @return string Digits only.
	 */
	public static function digits( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		return (string) preg_replace( '/[^0-9]/', '', (string) $value );
	}

	/**
	 * Whether the value is a well-formed ABN.
	 *
	 * Eleven digits; subtract 1 from the first digit, multiply each digit by its weight and the
	 * sum must divide by 89. This proves the number is well formed, not that it is registered.
	 *
	 * @param mixed $value Raw or normalised ABN.
	 * @return bool
	 */
	public static function is_valid( $value ) {
		$abn = self::digits( $value );
		if ( 11 !== strlen( $abn ) || '0' === $abn[0] ) {
			return false;
		}
		$sum = 0;
		foreach ( self::ABN_WEIGHTS as $i => $weight ) {
			$digit = (int) $abn[ $i ];
			if ( 0 === $i ) {
				--$digit;
			}
			$sum += $digit * $weight;
		}
		return 0 === $sum % 89;
	}

	/**
	 * Whether the value is a well-formed ACN (nine digits, the last one a check digit).
	 *
	 * @param mixed $value Raw or normalised ACN.
	 * @return bool
	 */
	public static function is_valid_acn( $value ) {
		$acn = self::digits( $value );
		if ( 9 !== strlen( $acn ) ) {
			return false;
		}
		$sum = 0;
		for ( $i = 0; $i < 8; $i++ ) {
			$sum += (int) $acn[ $i ] * ( 8 - $i );
		}
		return ( 10 - ( $sum % 10 ) ) % 10 === (int) $acn[8];
	}

	/**
	 * An ABN the way it is written: 51 824 753 556.
	 *
	 * @param mixed $value Raw or normalised ABN.
	 * @return string Formatted ABN, or the digits as they are when there are not eleven of them.
	 */
	public static function format( $value ) {
		$abn = self::digits( $value );
		if ( 11 !== strlen( $abn ) ) {
			return $abn;
		}
		return substr( $abn, 0, 2 ) . ' ' . substr( $abn, 2, 3 ) . ' ' . substr( $abn, 5, 3 ) . ' ' . substr( $abn, 8, 3 );
	}

	/**
	 * An ACN the way it is written: 004 085 616.
	 *
	 * @param mixed $value Raw or normalised ACN.
	 * @return string
	 */
	public static function format_acn( $value ) {
		$acn = self::digits( $value );
		if ( 9 !== strlen( $acn ) ) {
			return $acn;
		}
		return substr( $acn, 0, 3 ) . ' ' . substr( $acn, 3, 3 ) . ' ' . substr( $acn, 6, 3 );
	}
}
