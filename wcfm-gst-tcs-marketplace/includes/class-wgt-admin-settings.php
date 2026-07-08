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
			'tcs_rate'           => 1,
			'company_legal_name' => get_bloginfo( 'name' ),
			'company_gstin'      => '',
			'company_state'      => '',
			'invoice_prefix'     => 'INV-',
			'default_gst_rate'   => '',
			'hsn_mandatory'      => 'no',
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

		$gstin = isset( $_POST['company_gstin'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['company_gstin'] ) ) ) : '';

		if ( $gstin && ! WGT_States::is_valid_gstin( $gstin ) ) {
			add_settings_error( 'wgt_settings', 'invalid_gstin', __( 'Company GSTIN format looks invalid. It was not saved.', 'wcfm-gst-tcs' ) );
			$gstin = '';
		}

		$settings = array(
			'enable_gst'         => isset( $_POST['enable_gst'] ) ? 'yes' : 'no',
			'enable_tcs'         => isset( $_POST['enable_tcs'] ) ? 'yes' : 'no',
			'tcs_rate'           => isset( $_POST['tcs_rate'] ) ? wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['tcs_rate'] ) ) ) : 1,
			'company_legal_name' => isset( $_POST['company_legal_name'] ) ? sanitize_text_field( wp_unslash( $_POST['company_legal_name'] ) ) : '',
			'company_gstin'      => $gstin,
			'company_state'      => isset( $_POST['company_state'] ) ? sanitize_text_field( wp_unslash( $_POST['company_state'] ) ) : '',
			'invoice_prefix'     => isset( $_POST['invoice_prefix'] ) ? sanitize_text_field( wp_unslash( $_POST['invoice_prefix'] ) ) : 'INV-',
			'default_gst_rate'   => isset( $_POST['default_gst_rate'] ) ? wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['default_gst_rate'] ) ) ) : '',
			'hsn_mandatory'      => isset( $_POST['hsn_mandatory'] ) ? 'yes' : 'no',
		);

		if ( $gstin ) {
			$derived_state = WGT_States::state_from_gstin( $gstin );
			if ( $derived_state ) {
				$settings['company_state'] = $derived_state;
			}
		}

		update_option( 'wgt_settings', $settings );
		add_settings_error( 'wgt_settings', 'saved', __( 'GST & TCS settings saved.', 'wcfm-gst-tcs' ), 'success' );
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

			<form method="post">
				<?php wp_nonce_field( 'wgt_save_settings', 'wgt_settings_nonce' ); ?>
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
							<p class="description"><?php esc_html_e( 'Statutory rate is 1% (0.5% CGST + 0.5% SGST for intra-state, or 1% IGST for inter-state) of net taxable value.', 'wcfm-gst-tcs' ); ?></p>
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
							<input type="number" step="0.01" min="0" max="100" id="default_gst_rate" name="default_gst_rate" value="<?php echo esc_attr( $settings['default_gst_rate'] ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'Used only when a vendor has not set a GST rate on a product. Leave blank to treat un-rated products as 0% (exempt).', 'wcfm-gst-tcs' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Require HSN/SAC code', 'wcfm-gst-tcs' ); ?></th>
						<td><label><input type="checkbox" name="hsn_mandatory" value="1" <?php checked( $settings['hsn_mandatory'], 'yes' ); ?> /> <?php esc_html_e( 'Block vendors from publishing a product without an HSN/SAC code', 'wcfm-gst-tcs' ); ?></label></td>
					</tr>
				</table>
				<?php submit_button( __( 'Save Settings', 'wcfm-gst-tcs' ) ); ?>
			</form>
		</div>
		<?php
	}
}
