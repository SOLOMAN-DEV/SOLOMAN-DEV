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
	 * Full GSTIN validation: structure (15 chars: state code + PAN + entity code + 'Z' +
	 * checksum) plus the actual mod-36 checksum digit, so an obviously fabricated GSTIN
	 * (right shape, wrong check digit) is rejected rather than just format-matched.
	 */
	public static function is_valid_gstin( $gstin ) {
		$gstin = strtoupper( trim( (string) $gstin ) );

		if ( ! preg_match( '/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/', $gstin ) ) {
			return false;
		}

		return self::checksum_char( substr( $gstin, 0, 14 ) ) === substr( $gstin, 14, 1 );
	}

	/**
	 * Computes the GSTIN check digit (mod-36) for the first 14 characters.
	 */
	private static function checksum_char( $first_14 ) {
		$code_points = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$mod         = 36;
		$factor      = 2;
		$sum         = 0;
		$chars       = str_split( $first_14 );

		for ( $i = count( $chars ) - 1; $i >= 0; $i-- ) {
			$digit = strpos( $code_points, $chars[ $i ] );
			if ( false === $digit ) {
				return '';
			}
			$digit  = $digit * $factor;
			$factor = ( 2 === $factor ) ? 1 : 2;
			$digit  = intdiv( $digit, $mod ) + ( $digit % $mod );
			$sum   += $digit;
		}

		$check = ( $mod - ( $sum % $mod ) ) % $mod;
		return $code_points[ $check ];
	}

	/**
	 * Extension point for real GSTN-registry verification. This plugin only checks
	 * structure + the mod-36 check digit, which catches typos/fabrications but can't
	 * confirm a GSTIN is actually registered/active — that requires a paid GSP/GSTN
	 * API. Defaults to a pass-through (no-op) so behaviour is unchanged unless a site
	 * wires in a provider, e.g.:
	 *
	 *   add_filter( 'wgt_gstin_is_registered', function( $is_registered, $gstin ) {
	 *       return my_gsp_client()->lookup( $gstin )->active; // cache this yourself.
	 *   }, 10, 2 );
	 *
	 * Only called after is_valid_gstin() already passed, so it's not hit on garbage
	 * input. Cache aggressively in your own callback — this can run on every checkout.
	 */
	public static function passes_external_verification( $gstin ) {
		return (bool) apply_filters( 'wgt_gstin_is_registered', true, $gstin );
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
