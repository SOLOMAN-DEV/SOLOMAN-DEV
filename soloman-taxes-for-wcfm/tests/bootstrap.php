<?php
/**
 * Minimal bootstrap for the pure-logic unit tests only (WGT_States, WGT_TCS_Engine's
 * calculate_tcs_split(), WGT_Product_Fields::is_valid_hsn()). These classes' *other*
 * methods talk to WordPress/WooCommerce/$wpdb and are intentionally out of scope here —
 * this suite is not a WP integration test environment, it only exercises functions with
 * no such dependencies.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once dirname( __DIR__ ) . '/includes/class-wgt-states.php';
require_once dirname( __DIR__ ) . '/includes/class-wgt-tcs-engine.php';
require_once dirname( __DIR__ ) . '/includes/class-wgt-product-fields.php';
