<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WGT_Install {

	public static function activate() {
		self::create_tables();
		self::create_default_options();

		add_rewrite_endpoint( 'gst-tcs-report', EP_ROOT | EP_PAGES );
		flush_rewrite_rules();
	}

	private static function create_tables() {
		global $wpdb;

		$table_name      = $wpdb->prefix . WGT_TCS_TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NOT NULL,
			vendor_id BIGINT UNSIGNED NOT NULL,
			order_date DATETIME NOT NULL,
			net_taxable_value DECIMAL(15,2) NOT NULL DEFAULT 0,
			gst_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
			tcs_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
			tcs_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
			cgst_tcs DECIMAL(15,2) NOT NULL DEFAULT 0,
			sgst_tcs DECIMAL(15,2) NOT NULL DEFAULT 0,
			igst_tcs DECIMAL(15,2) NOT NULL DEFAULT 0,
			financial_year VARCHAR(9) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'collected',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY order_vendor (order_id, vendor_id),
			KEY vendor_id (vendor_id),
			KEY financial_year (financial_year)
		) {$charset_collate};";

		dbDelta( $sql );

		update_option( 'wgt_db_version', WGT_VERSION );
	}

	private static function create_default_options() {
		if ( false === get_option( 'wgt_settings', false ) ) {
			update_option(
				'wgt_settings',
				array(
					'enable_gst'        => 'yes',
					'enable_tcs'        => 'yes',
					'tcs_rate'          => 1,
					'company_legal_name' => get_bloginfo( 'name' ),
					'company_gstin'     => '',
					'company_state'     => '',
					'invoice_prefix'    => 'INV-',
					'default_gst_rate'  => '',
					'hsn_mandatory'     => 'no',
				)
			);
		}

		if ( false === get_option( 'wgt_provisioned_gst_rates', false ) ) {
			update_option( 'wgt_provisioned_gst_rates', array() );
		}
	}
}
