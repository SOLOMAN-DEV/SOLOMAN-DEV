<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GST state-code table and GSTIN helpers.
 *
 * State codes and abbreviations follow the CBIC GST state code list, and the
 * abbreviations are kept identical to WooCommerce's own `WC()->countries->get_states('IN')`
 * keys so a GSTIN-derived state can be written straight into billing/shipping fields.
 */
class WGT_States {

	/** @var array<string,string> GST numeric state code => WooCommerce IN state abbreviation */
	private static $code_to_abbr = array(
		'01' => 'JK', '02' => 'HP', '03' => 'PB', '04' => 'CH', '05' => 'UT',
		'06' => 'HR', '07' => 'DL', '08' => 'RJ', '09' => 'UP', '10' => 'BR',
		'11' => 'SK', '12' => 'AR', '13' => 'NL', '14' => 'MN', '15' => 'MZ',
		'16' => 'TR', '17' => 'ML', '18' => 'AS', '19' => 'WB', '20' => 'JH',
		'21' => 'OR', '22' => 'CT', '23' => 'MP', '24' => 'GJ', '26' => 'DN',
		'27' => 'MH', '28' => 'AP', '29' => 'KA', '30' => 'GA', '31' => 'LD',
		'32' => 'KL', '33' => 'TN', '34' => 'PY', '35' => 'AN', '36' => 'TG',
		'37' => 'AP', '38' => 'LA', '97' => 'DL', '99' => 'DL',
	);

	public static function code_to_abbr( $code ) {
		$code = str_pad( (string) $code, 2, '0', STR_PAD_LEFT );
		return isset( self::$code_to_abbr[ $code ] ) ? self::$code_to_abbr[ $code ] : '';
	}

	/**
	 * Derives the WooCommerce state abbreviation from a GSTIN's first two digits.
	 */
	public static function state_from_gstin( $gstin ) {
		$gstin = strtoupper( trim( (string) $gstin ) );
		if ( strlen( $gstin ) < 2 || ! ctype_digit( substr( $gstin, 0, 2 ) ) ) {
			return '';
		}
		return self::code_to_abbr( substr( $gstin, 0, 2 ) );
	}

	/**
	 * Structural GSTIN validation (15 chars: state code + PAN + entity code + 'Z' + checksum).
	 * This checks format only, not the checksum digit itself.
	 */
	public static function is_valid_gstin( $gstin ) {
		$gstin = strtoupper( trim( (string) $gstin ) );
		return (bool) preg_match( '/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/', $gstin );
	}

	public static function get_indian_states() {
		if ( function_exists( 'WC' ) && WC()->countries ) {
			$states = WC()->countries->get_states( 'IN' );
			if ( ! empty( $states ) ) {
				return $states;
			}
		}
		return array();
	}
}
