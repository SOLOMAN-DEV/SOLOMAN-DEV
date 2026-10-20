<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WGT_Admin_Settings {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'maybe_save' ) );
		add_action( 'admin_init', array( $this, 'maybe_migrate_tcs_rate' ) );
		add_action( 'admin_notices', array( $this, 'render_tcs_rate_migration_notice' ) );
	}

	public function add_menu() {
		add_menu_page(
			__( 'GST & TCS', 'wcfm-gst-tcs' ),
			__( 'GST & TCS', 'wcfm-gst-tcs' ),
			'manage_woocommerce',
			'wgt-settings',
			array( $this, 'render_settings_page' ),
			'dashicons-media-spreadsheet',
			56
		);
	}

	public static function get_settings() {
		$defaults = array(
			'enable_gst'         => 'yes',
			'enable_tcs'         => 'yes',
			// 0.5% (0.25% CGST + 0.25% SGST intra-state, or 0.5% IGST inter-state) — the
			// Section 52 TCS rate currently in force under CBIC Notification No. 15/2024-
			// Central Tax (10 July 2024), which halved the original 1% rate. See
			// maybe_migrate_tcs_rate() for the one-time correction applied to sites that
			// were already running this plugin on the old 1% default.
			'tcs_rate'           => 0.5,
			'company_legal_name' => get_bloginfo( 'name' ),
			'company_gstin'      => '',
			'company_state'      => '',
			'invoice_prefix'     => 'INV-',
			'default_gst_rate'   => '',
			'hsn_mandatory'      => 'no',
			'enable_b2b'         => 'yes',
			'require_gstin_for_business' => 'yes',
			'delete_data_on_uninstall' => 'no',
			'monthly_email_enabled'   => 'no',
			'monthly_email_day'       => 3,
			'monthly_email_skip_zero' => 'yes',
			'monthly_email_delivery_enabled'  => 'no',
			'monthly_email_affiliate_enabled' => 'no',
		);
		return wp_parse_args( get_option( 'wgt_settings', array() ), $defaults );
	}

	public function maybe_save() {
		if ( ! isset( $_POST['wgt_settings_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wgt_settings_nonce'] ) ), 'wgt_save_settings' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$errors   = array();
		$settings = self::build_settings_from_post( $errors );

		foreach ( $errors as $error ) {
			add_settings_error( 'wgt_settings', $error['code'], $error['message'] );
		}

		update_option( 'wgt_settings', $settings );
		add_settings_error( 'wgt_settings', 'saved', __( 'GST & TCS settings saved.', 'wcfm-gst-tcs' ), 'success' );
	}

	/**
	 * Builds the settings array from the current $_POST, shared by the wp-admin settings
	 * page (maybe_save(), above) and the WCFM frontend admin settings tab
	 * (WGT_Admin_Frontend_Settings::handle_save()) so both save paths validate and map
	 * fields identically — a second, independently-written copy of this logic is exactly
	 * how the two paths would eventually drift out of sync with each other.
	 *
	 * @param array $errors Appended to by reference: array{code:string,message:string}.
	 * @return array The settings array, ready for update_option( 'wgt_settings', ... ).
	 */
	public static function build_settings_from_post( array &$errors = array() ) {
		$gstin = isset( $_POST['company_gstin'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['company_gstin'] ) ) ) : '';

		if ( $gstin && ! WGT_States::is_valid_gstin( $gstin ) ) {
			$errors[] = array(
				'code'    => 'invalid_gstin',
				'message' => __( 'Company GSTIN format looks invalid. It was not saved.', 'wcfm-gst-tcs' ),
			);
			$gstin = '';
		}

		// The field is a dropdown restricted to GST_SLABS, so a value outside that list only
		// happens via a tampered POST — fall back to "no default" rather than trust it.
		$default_gst_rate = isset( $_POST['default_gst_rate'] ) ? sanitize_text_field( wp_unslash( $_POST['default_gst_rate'] ) ) : '';
		if ( '' !== $default_gst_rate && ! in_array( $default_gst_rate, WGT_Product_Fields::GST_SLABS, true ) ) {
			$errors[] = array(
				'code'    => 'invalid_default_rate',
				'message' => __( 'Default GST rate must be one of the listed slabs. It was not saved.', 'wcfm-gst-tcs' ),
			);
			$default_gst_rate = '';
		}

		$settings = array(
			'enable_gst'         => isset( $_POST['enable_gst'] ) ? 'yes' : 'no',
			'enable_tcs'         => isset( $_POST['enable_tcs'] ) ? 'yes' : 'no',
			'tcs_rate'           => isset( $_POST['tcs_rate'] ) ? wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['tcs_rate'] ) ) ) : 0.5,
			'company_legal_name' => isset( $_POST['company_legal_name'] ) ? sanitize_text_field( wp_unslash( $_POST['company_legal_name'] ) ) : '',
			'company_gstin'      => $gstin,
			'company_state'      => isset( $_POST['company_state'] ) ? sanitize_text_field( wp_unslash( $_POST['company_state'] ) ) : '',
			'invoice_prefix'     => isset( $_POST['invoice_prefix'] ) ? sanitize_text_field( wp_unslash( $_POST['invoice_prefix'] ) ) : 'INV-',
			'default_gst_rate'   => $default_gst_rate,
			'hsn_mandatory'      => isset( $_POST['hsn_mandatory'] ) ? 'yes' : 'no',
			'enable_b2b'         => isset( $_POST['enable_b2b'] ) ? 'yes' : 'no',
			'require_gstin_for_business' => isset( $_POST['require_gstin_for_business'] ) ? 'yes' : 'no',
			'delete_data_on_uninstall' => isset( $_POST['delete_data_on_uninstall'] ) ? 'yes' : 'no',
			'monthly_email_enabled'   => isset( $_POST['monthly_email_enabled'] ) ? 'yes' : 'no',
			'monthly_email_day'       => isset( $_POST['monthly_email_day'] ) ? max( 1, min( 28, absint( $_POST['monthly_email_day'] ) ) ) : 3,
			'monthly_email_skip_zero' => isset( $_POST['monthly_email_skip_zero'] ) ? 'yes' : 'no',
			'monthly_email_delivery_enabled'  => isset( $_POST['monthly_email_delivery_enabled'] ) ? 'yes' : 'no',
			'monthly_email_affiliate_enabled' => isset( $_POST['monthly_email_affiliate_enabled'] ) ? 'yes' : 'no',
		);

		if ( $gstin ) {
			$derived_state = WGT_States::state_from_gstin( $gstin );
			if ( $derived_state ) {
				$settings['company_state'] = $derived_state;
			}
		}

		return $settings;
	}

	/**
	 * One-time correction for sites running this plugin from before this fix, when both
	 * the default and the settings-page description incorrectly stated the Section 52 TCS
	 * rate as 1% — CBIC Notification No. 15/2024-Central Tax (effective 10 July 2024)
	 * actually halved it to 0.5% back in 2024. Only touches a site whose stored tcs_rate is
	 * still exactly the old untouched default (1); a deliberately-set custom value (e.g. an
	 * admin who already corrected it, or a future rate change) is left alone. Runs once.
	 */
	public function maybe_migrate_tcs_rate() {
		if ( 'yes' === get_option( 'wgt_tcs_rate_migrated_2024_notification', 'no' ) ) {
			return;
		}

		$settings = get_option( 'wgt_settings', array() );
		if ( is_array( $settings ) && isset( $settings['tcs_rate'] ) && 1.0 === round( (float) $settings['tcs_rate'], 2 ) ) {
			$settings['tcs_rate'] = 0.5;
			update_option( 'wgt_settings', $settings );
			update_option( 'wgt_tcs_rate_migration_notice', 'yes' );
		}

		update_option( 'wgt_tcs_rate_migrated_2024_notification', 'yes' );
	}

	public function render_tcs_rate_migration_notice() {
		if ( 'yes' !== get_option( 'wgt_tcs_rate_migration_notice', 'no' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		?>
		<div class="notice notice-warning is-dismissible">
			<p>
				<strong><?php esc_html_e( 'SOLOMAN TAXES FOR WCFM:', 'wcfm-gst-tcs' ); ?></strong>
				<?php esc_html_e( 'Your GST-TCS rate (Settings > GST & TCS) was just updated from 1% to 0.5%. CBIC Notification No. 15/2024-Central Tax (effective 10 July 2024) halved the Section 52 TCS rate, and this plugin\'s old default/description incorrectly still said 1%. Please verify this against your own GSTR-8 filings and with your CA, and review any TCS already collected at the old rate before this fix.', 'wcfm-gst-tcs' ); ?>
			</p>
		</div>
		<?php
		delete_option( 'wgt_tcs_rate_migration_notice' );
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$settings = self::get_settings();
		$states   = WGT_States::get_indian_states();
		settings_errors( 'wgt_settings' );
		?>
		<div class="wrap wgt-admin-wrap">
			<h1><?php esc_html_e( 'GST & TCS Settings', 'wcfm-gst-tcs' ); ?></h1>
			<p><?php esc_html_e( 'Configure GST tax calculation and GST-TCS (Section 52) collection for your WCFM multivendor marketplace.', 'wcfm-gst-tcs' ); ?></p>

			<?php $this->render_compliance_health(); ?>

			<form method="post">
				<?php wp_nonce_field( 'wgt_save_settings', 'wgt_settings_nonce' ); ?>
				<?php self::render_settings_fields( $settings, $states ); ?>
				<?php submit_button( __( 'Save Settings', 'wcfm-gst-tcs' ) ); ?>
			</form>

			<?php $this->render_monthly_email_test_button(); ?>
		</div>
		<?php
	}

	/**
	 * The settings form's field table (no <form>, nonce, or submit button of its own) —
	 * shared by the wp-admin settings page and WGT_Admin_Frontend_Settings's WCFM
	 * frontend tab, so both surfaces offer exactly the same fields. Uses only core
	 * template functions (esc_*, checked(), selected()) that are available on both the
	 * frontend and in wp-admin — never submit_button() or settings_errors(), which are
	 * wp-admin-only and fatal with "call to undefined function" if rendered on the
	 * frontend; callers add their own form wrapper, nonce, submit button, and notices.
	 *
	 * @param array $settings Return value of get_settings().
	 * @param array $states   Return value of WGT_States::get_indian_states().
	 */
	public static function render_settings_fields( $settings, $states ) {
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Enable GST calculation', 'wcfm-gst-tcs' ); ?></th>
				<td><label><input type="checkbox" name="enable_gst" value="1" <?php checked( $settings['enable_gst'], 'yes' ); ?> /> <?php esc_html_e( 'Calculate CGST/SGST/IGST on vendor product sales', 'wcfm-gst-tcs' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Enable GST-TCS collection', 'wcfm-gst-tcs' ); ?></th>
				<td><label><input type="checkbox" name="enable_tcs" value="1" <?php checked( $settings['enable_tcs'], 'yes' ); ?> /> <?php esc_html_e( 'Collect TCS under Section 52 of the CGST Act on net taxable value of vendor sales', 'wcfm-gst-tcs' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><label for="tcs_rate"><?php esc_html_e( 'TCS rate (%)', 'wcfm-gst-tcs' ); ?></label></th>
				<td>
					<input type="number" step="0.01" min="0" max="100" id="tcs_rate" name="tcs_rate" value="<?php echo esc_attr( $settings['tcs_rate'] ); ?>" class="small-text" />
					<p class="description"><?php esc_html_e( 'Current statutory rate is 0.5% (0.25% CGST + 0.25% SGST for intra-state, or 0.5% IGST for inter-state) of net taxable value, per CBIC Notification No. 15/2024-Central Tax (effective 10 July 2024), which halved the original 1% rate. Confirm with your CA before relying on this — tax notifications can change again.', 'wcfm-gst-tcs' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="company_legal_name"><?php esc_html_e( 'Marketplace operator legal name', 'wcfm-gst-tcs' ); ?></label></th>
				<td><input type="text" id="company_legal_name" name="company_legal_name" value="<?php echo esc_attr( $settings['company_legal_name'] ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="company_gstin"><?php esc_html_e( 'Marketplace operator GSTIN', 'wcfm-gst-tcs' ); ?></label></th>
				<td>
					<input type="text" id="company_gstin" name="company_gstin" value="<?php echo esc_attr( $settings['company_gstin'] ); ?>" class="regular-text wgt-gstin-input" maxlength="15" />
					<p class="description"><?php esc_html_e( 'Used as the operator GSTIN on TCS reports (GSTR-8) and printed on vendor invoices.', 'wcfm-gst-tcs' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="company_state"><?php esc_html_e( 'Marketplace operator state', 'wcfm-gst-tcs' ); ?></label></th>
				<td>
					<select id="company_state" name="company_state">
						<option value=""><?php esc_html_e( '— Select state —', 'wcfm-gst-tcs' ); ?></option>
						<?php foreach ( $states as $code => $name ) : ?>
							<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $settings['company_state'], $code ); ?>><?php echo esc_html( $name ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Auto-filled from GSTIN when possible; used to compare against vendor state for platform-level tax display.', 'wcfm-gst-tcs' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="invoice_prefix"><?php esc_html_e( 'Invoice number prefix', 'wcfm-gst-tcs' ); ?></label></th>
				<td><input type="text" id="invoice_prefix" name="invoice_prefix" value="<?php echo esc_attr( $settings['invoice_prefix'] ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="default_gst_rate"><?php esc_html_e( 'Default GST rate (%)', 'wcfm-gst-tcs' ); ?></label></th>
				<td>
					<select id="default_gst_rate" name="default_gst_rate">
						<option value=""><?php esc_html_e( 'None (treat un-rated products as 0% / exempt)', 'wcfm-gst-tcs' ); ?></option>
						<?php foreach ( WGT_Product_Fields::GST_SLABS as $slab ) : ?>
							<option value="<?php echo esc_attr( $slab ); ?>" <?php selected( (string) $settings['default_gst_rate'], $slab ); ?>><?php echo esc_html( $slab ); ?>%</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Used only when a vendor has not set a GST rate on a product.', 'wcfm-gst-tcs' ); ?></p>
					<p class="description"><?php echo esc_html( WGT_Product_Fields::slab_help_text() ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Require HSN/SAC code', 'wcfm-gst-tcs' ); ?></th>
				<td><label><input type="checkbox" name="hsn_mandatory" value="1" <?php checked( $settings['hsn_mandatory'], 'yes' ); ?> /> <?php esc_html_e( 'Block vendors from publishing a product without an HSN/SAC code', 'wcfm-gst-tcs' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Allow B2B purchases', 'wcfm-gst-tcs' ); ?></th>
				<td><label><input type="checkbox" name="enable_b2b" value="1" <?php checked( $settings['enable_b2b'], 'yes' ); ?> /> <?php esc_html_e( 'Show a "This is a business purchase" option at checkout so customers can add their Company Name and GSTIN for a GST invoice', 'wcfm-gst-tcs' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Require GSTIN for business purchases', 'wcfm-gst-tcs' ); ?></th>
				<td><label><input type="checkbox" name="require_gstin_for_business" value="1" <?php checked( $settings['require_gstin_for_business'], 'yes' ); ?> /> <?php esc_html_e( 'Block checkout unless a valid GSTIN and Company Name are entered when the business purchase option is ticked', 'wcfm-gst-tcs' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Delete data on uninstall', 'wcfm-gst-tcs' ); ?></th>
				<td>
					<label><input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked( $settings['delete_data_on_uninstall'], 'yes' ); ?> /> <?php esc_html_e( 'Permanently delete the GST-TCS ledger, settings, and vendor/product GST meta when this plugin is deleted from Plugins', 'wcfm-gst-tcs' ); ?></label>
					<p class="description"><?php esc_html_e( 'Leave this off to keep your TCS ledger and GST records for audit purposes even if the plugin is removed.', 'wcfm-gst-tcs' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Monthly vendor GST email', 'wcfm-gst-tcs' ); ?></th>
				<td>
					<label><input type="checkbox" name="monthly_email_enabled" value="1" <?php checked( $settings['monthly_email_enabled'], 'yes' ); ?> /> <?php esc_html_e( 'Automatically email each vendor a sales & GST summary for the previous month, with a detailed invoice CSV attached', 'wcfm-gst-tcs' ); ?></label>
					<p class="description"><?php esc_html_e( 'Requires WooCommerce\'s Action Scheduler (present on any current WooCommerce install).', 'wcfm-gst-tcs' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="monthly_email_day"><?php esc_html_e( 'Send on day of month', 'wcfm-gst-tcs' ); ?></label></th>
				<td>
					<input type="number" min="1" max="28" id="monthly_email_day" name="monthly_email_day" value="<?php echo esc_attr( $settings['monthly_email_day'] ); ?>" class="small-text" />
					<p class="description"><?php esc_html_e( 'Day of the following month the report for the previous month is sent (e.g. 3 = the 3rd of each month, giving a few days\' buffer after month-end).', 'wcfm-gst-tcs' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Skip vendors with no sales', 'wcfm-gst-tcs' ); ?></th>
				<td><label><input type="checkbox" name="monthly_email_skip_zero" value="1" <?php checked( $settings['monthly_email_skip_zero'], 'yes' ); ?> /> <?php esc_html_e( 'Don\'t email a vendor who had no orders in the period (also applies to delivery persons/affiliates with no commission below)', 'wcfm-gst-tcs' ); ?></label></td>
			</tr>
			<?php if ( class_exists( 'WGT_Partner_Reports' ) && WGT_Partner_Reports::is_active( WGT_Partner_Reports::TYPE_DELIVERY ) ) : ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Monthly delivery person email', 'wcfm-gst-tcs' ); ?></th>
				<td>
					<label><input type="checkbox" name="monthly_email_delivery_enabled" value="1" <?php checked( $settings['monthly_email_delivery_enabled'], 'yes' ); ?> /> <?php esc_html_e( 'Also email each delivery person a commission/earnings summary for the previous month, with an order-level CSV attached, for their own tax records', 'wcfm-gst-tcs' ); ?></label>
					<p class="description"><?php esc_html_e( 'This is an earnings summary, not a GST calculation — this plugin does not compute or deduct GST on delivery commission.', 'wcfm-gst-tcs' ); ?></p>
				</td>
			</tr>
			<?php endif; ?>
			<?php if ( class_exists( 'WGT_Partner_Reports' ) && WGT_Partner_Reports::is_active( WGT_Partner_Reports::TYPE_AFFILIATE ) ) : ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Monthly affiliate email', 'wcfm-gst-tcs' ); ?></th>
				<td>
					<label><input type="checkbox" name="monthly_email_affiliate_enabled" value="1" <?php checked( $settings['monthly_email_affiliate_enabled'], 'yes' ); ?> /> <?php esc_html_e( 'Also email each affiliate a commission/earnings summary for the previous month, with an order-level CSV attached, for their own tax records', 'wcfm-gst-tcs' ); ?></label>
					<p class="description"><?php esc_html_e( 'This is an earnings summary, not a GST calculation — this plugin does not compute or deduct GST on affiliate commission.', 'wcfm-gst-tcs' ); ?></p>
				</td>
			</tr>
			<?php endif; ?>
		</table>
		<?php
	}

	private function render_monthly_email_test_button() {
		if ( ! class_exists( 'WGT_Monthly_Email' ) ) {
			return;
		}
		?>
		<h2><?php esc_html_e( 'Monthly Report Email', 'wcfm-gst-tcs' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wgt_run_monthly_email_now" />
			<?php wp_nonce_field( 'wgt_run_monthly_email_now' ); ?>
			<?php submit_button( __( 'Send Now (Previous Month)', 'wcfm-gst-tcs' ), 'secondary', '', false ); ?>
			<p class="description"><?php esc_html_e( 'Sends the previous month\'s report immediately to every qualifying vendor, and — if enabled above — every qualifying delivery person and affiliate, regardless of the scheduled day above. Useful to test or to catch up a missed run.', 'wcfm-gst-tcs' ); ?></p>
		</form>
		<?php
	}

	private function render_compliance_health() {
		$missing_hsn = class_exists( 'WGT_Product_Fields' ) ? WGT_Product_Fields::count_missing_hsn() : 0;
		$legacy_rate = class_exists( 'WGT_Product_Fields' ) ? WGT_Product_Fields::legacy_rate_products() : array( 'count' => 0 );

		$vendor_ids    = array_unique(
			array_merge(
				get_users( array( 'role' => 'wcfm_vendor', 'fields' => 'ID' ) ),
				get_users( array( 'role' => 'dc_vendor', 'fields' => 'ID' ) )
			)
		);
		$missing_gstin = 0;
		foreach ( $vendor_ids as $vendor_id ) {
			$gst = WGT_Vendor_Settings::get_vendor_gst( $vendor_id );
			if ( ! $gst['gstin'] ) {
				++$missing_gstin;
			}
		}

		if ( 0 === $missing_hsn && 0 === $missing_gstin && 0 === $legacy_rate['count'] ) {
			return;
		}
		?>
		<div class="notice notice-warning inline" style="padding:10px 12px;">
			<p style="margin:.3em 0;"><strong><?php esc_html_e( 'Compliance check', 'wcfm-gst-tcs' ); ?></strong></p>
			<?php if ( $missing_gstin > 0 ) : ?>
				<p style="margin:.3em 0;">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of vendors without a GSTIN on file */
							_n( '%d vendor has not added a GSTIN.', '%d vendors have not added a GSTIN.', $missing_gstin, 'wcfm-gst-tcs' ),
							$missing_gstin
						)
					);
					?>
				</p>
			<?php endif; ?>
			<?php if ( $missing_hsn > 0 ) : ?>
				<p style="margin:.3em 0;">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of published products missing an HSN/SAC code */
							_n( '%d published product is missing an HSN/SAC code.', '%d published products are missing an HSN/SAC code.', $missing_hsn, 'wcfm-gst-tcs' ),
							$missing_hsn
						)
					);
					?>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=product' ) ); ?>"><?php esc_html_e( 'Review products', 'wcfm-gst-tcs' ); ?></a>
				</p>
			<?php endif; ?>
			<?php if ( $legacy_rate['count'] > 0 ) : ?>
				<p style="margin:.3em 0;">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of published products still at a 12%%/28%% GST rate */
							_n( '%d published product is still at a GST-2.0-legacy rate (12%% or 28%%).', '%d published products are still at a GST-2.0-legacy rate (12%% or 28%%).', $legacy_rate['count'], 'wcfm-gst-tcs' ),
							$legacy_rate['count']
						)
					);
					?>
					<?php esc_html_e( 'Not necessarily wrong — some items do stay at the old rate — but worth a CA confirming each one wasn\'t simply missed during reclassification.', 'wcfm-gst-tcs' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wgt-bulk-tax' ) ); ?>"><?php esc_html_e( 'Review via Bulk HSN/GST Update', 'wcfm-gst-tcs' ); ?></a>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}
}
