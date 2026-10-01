<?php
/**
 * Fires only when the plugin is deleted from Plugins > Installed Plugins (not on
 * deactivate). Off by default: only wipes data when the site owner has explicitly
 * ticked "Delete data on uninstall" in Settings > GST & TCS, since a TCS ledger is
 * audit/compliance data most stores will want to keep even if the plugin is removed.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$settings = get_option( 'wgt_settings', array() );
if ( empty( $settings['delete_data_on_uninstall'] ) || 'yes' !== $settings['delete_data_on_uninstall'] ) {
	return;
}

global $wpdb;

// Custom TCS ledger table.
$table = $wpdb->prefix . 'wgt_tcs_ledger';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared

// Plugin-level options.
$options = array(
	'wgt_settings',
	'wgt_provisioned_gst_rates',
	'wgt_slabs_bootstrapped',
	'wgt_db_version',
);
foreach ( $options as $option ) {
	delete_option( $option );
	delete_site_option( $option );
}

// Vendor GST identity (GSTIN/PAN/state/exemption).
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s", '_wgt_vendor_gst' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared

// Per-customer saved GSTIN used to prefill checkout.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s", 'billing_gstin' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared

// Product HSN/GST rate meta.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s)", '_wgt_hsn_code', '_wgt_gst_rate' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared

/*
 * Order/order-item level data (buyer GSTIN, e-invoice IRN, per-line HSN/tax-type
 * stamps) is deliberately left in place: it lives inside WooCommerce's own order
 * records, and clearing it means directly modifying another plugin's data store
 * (and, under HPOS, a different table entirely) for a low-value cleanup — those
 * meta keys are simply inert once this plugin is gone.
 */
