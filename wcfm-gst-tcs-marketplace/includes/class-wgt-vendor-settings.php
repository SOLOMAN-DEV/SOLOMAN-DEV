<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Captures each vendor's GSTIN/PAN/state on the WCFM vendor "Settings > General" tab,
 * with a wp-admin user-profile fallback so the data can always be entered/edited even
 * if a WCFM version renders the frontend filter fields differently.
 *
 * Stored as a single serialized array in user meta '_wgt_vendor_gst' so the rest of the
 * plugin has one place to read a vendor's GST identity from.
 */
class WGT_Vendor_Settings {

	const META_KEY = '_wgt_vendor_gst';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// WCFM frontend vendor settings ("Settings" dashboard page, General tab).
		add_filter( 'wcfm_marketplace_settings_fields_general', array( $this, 'add_fields_to_wcfm_form' ), 50, 2 );
		add_action( 'wcfm_vendor_settings_update', array( $this, 'save_from_wcfm_settings' ), 20, 2 );

		// wp-admin user profile fallback (works for shop_manager/administrator editing a vendor too).
		add_action( 'show_user_profile', array( $this, 'render_profile_fields' ) );
		add_action( 'edit_user_profile', array( $this, 'render_profile_fields' ) );
		add_action( 'personal_options_update', array( $this, 'save_profile_fields' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_profile_fields' ) );

		add_action( 'wp_ajax_wgt_validate_gstin', array( $this, 'ajax_validate_gstin' ) );
	}

	public static function get_vendor_gst( $vendor_id ) {
		$defaults = array(
			'gstin'          => '',
			'pan'            => '',
			'legal_name'     => '',
			'gst_registered' => 'no',
			'state'          => '',
		);
		$saved = get_user_meta( $vendor_id, self::META_KEY, true );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
	}

	public static function get_vendor_state( $vendor_id ) {
		$gst = self::get_vendor_gst( $vendor_id );
		if ( $gst['state'] ) {
			return $gst['state'];
		}
		if ( $gst['gstin'] ) {
			return WGT_States::state_from_gstin( $gst['gstin'] );
		}
		return '';
	}

	private function sanitize_and_store( $vendor_id, $raw ) {
		$gstin = isset( $raw['gstin'] ) ? strtoupper( sanitize_text_field( wp_unslash( $raw['gstin'] ) ) ) : '';
		$state = isset( $raw['state'] ) ? sanitize_text_field( wp_unslash( $raw['state'] ) ) : '';

		if ( $gstin && ! WGT_States::is_valid_gstin( $gstin ) ) {
			$gstin = '';
		}
		if ( $gstin && ! $state ) {
			$state = WGT_States::state_from_gstin( $gstin );
		}

		$data = array(
			'gstin'          => $gstin,
			'pan'            => isset( $raw['pan'] ) ? strtoupper( sanitize_text_field( wp_unslash( $raw['pan'] ) ) ) : '',
			'legal_name'     => isset( $raw['legal_name'] ) ? sanitize_text_field( wp_unslash( $raw['legal_name'] ) ) : '',
			'gst_registered' => ! empty( $raw['gst_registered'] ) ? 'yes' : 'no',
			'state'          => $state,
		);

		update_user_meta( $vendor_id, self::META_KEY, $data );
	}

	/**
	 * Best-effort injection into the WCFM vendor settings "General" tab field array.
	 * Field schema mirrors WCFM's own convention (label/name/type/class/value).
	 */
	public function add_fields_to_wcfm_form( $general_settings_fields, $user_id ) {
		if ( ! is_array( $general_settings_fields ) ) {
			return $general_settings_fields;
		}

		$gst    = self::get_vendor_gst( $user_id );
		$states = WGT_States::get_indian_states();

		$options = array( '' => __( '— Select state —', 'wcfm-gst-tcs' ) );
		foreach ( $states as $code => $name ) {
			$options[ $code ] = $name;
		}

		$general_settings_fields['wgt_gst_heading'] = array(
			'label' => __( 'GST Details', 'wcfm-gst-tcs' ),
			'type'  => 'sectionstart',
			'name'  => 'wgt_gst_heading',
		);

		$general_settings_fields['wgt_gst_registered'] = array(
			'label' => __( 'GST Registered', 'wcfm-gst-tcs' ),
			'name'  => 'wcfm_settings_general[wgt_gst_registered]',
			'type'  => 'checkbox',
			'value' => $gst['gst_registered'],
			'class' => 'wgt-field',
		);

		$general_settings_fields['wgt_gstin'] = array(
			'label' => __( 'GSTIN', 'wcfm-gst-tcs' ),
			'name'  => 'wcfm_settings_general[wgt_gstin]',
			'type'  => 'text',
			'value' => $gst['gstin'],
			'class' => 'wgt-field wgt-gstin-input',
			'desc'  => __( '15-character GST Identification Number. State is auto-detected from it.', 'wcfm-gst-tcs' ),
		);

		$general_settings_fields['wgt_pan'] = array(
			'label' => __( 'PAN', 'wcfm-gst-tcs' ),
			'name'  => 'wcfm_settings_general[wgt_pan]',
			'type'  => 'text',
			'value' => $gst['pan'],
			'class' => 'wgt-field',
		);

		$general_settings_fields['wgt_legal_name'] = array(
			'label' => __( 'Registered Business/Legal Name', 'wcfm-gst-tcs' ),
			'name'  => 'wcfm_settings_general[wgt_legal_name]',
			'type'  => 'text',
			'value' => $gst['legal_name'],
			'class' => 'wgt-field',
		);

		$general_settings_fields['wgt_state'] = array(
			'label'   => __( 'Business State', 'wcfm-gst-tcs' ),
			'name'    => 'wcfm_settings_general[wgt_state]',
			'type'    => 'select',
			'value'   => $gst['state'],
			'options' => $options,
			'class'   => 'wgt-field wgt-state-select',
			'desc'    => __( 'Used to determine CGST+SGST (same state as buyer) vs IGST (different state).', 'wcfm-gst-tcs' ),
		);

		$general_settings_fields['wgt_gst_heading_end'] = array(
			'type' => 'sectionend',
			'name' => 'wgt_gst_heading',
		);

		return $general_settings_fields;
	}

	public function save_from_wcfm_settings( $vendor_id, $wcfm_settings_form = array() ) {
		if ( ! $vendor_id ) {
			return;
		}

		// Read straight from $_POST as a defensive fallback in case the settings-form
		// argument shape differs across WCFM versions; the nonce is WCFM's own, already
		// verified before this action fires.
		$posted = isset( $_POST['wcfm_settings_general'] ) ? wp_unslash( $_POST['wcfm_settings_general'] ) : array();
		if ( ! is_array( $posted ) || ! isset( $posted['wgt_gstin'] ) ) {
			return;
		}

		$this->sanitize_and_store(
			$vendor_id,
			array(
				'gstin'          => $posted['wgt_gstin'] ?? '',
				'pan'            => $posted['wgt_pan'] ?? '',
				'legal_name'     => $posted['wgt_legal_name'] ?? '',
				'gst_registered' => $posted['wgt_gst_registered'] ?? '',
				'state'          => $posted['wgt_state'] ?? '',
			)
		);
	}

	public function render_profile_fields( $user ) {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}

		$gst    = self::get_vendor_gst( $user->ID );
		$states = WGT_States::get_indian_states();
		wp_nonce_field( 'wgt_save_profile_gst', 'wgt_profile_gst_nonce' );
		?>
		<h2><?php esc_html_e( 'GST Details', 'wcfm-gst-tcs' ); ?></h2>
		<table class="form-table">
			<tr>
				<th><label for="wgt_gst_registered"><?php esc_html_e( 'GST Registered', 'wcfm-gst-tcs' ); ?></label></th>
				<td><input type="checkbox" id="wgt_gst_registered" name="wgt_gst_registered" value="1" <?php checked( $gst['gst_registered'], 'yes' ); ?> /></td>
			</tr>
			<tr>
				<th><label for="wgt_gstin"><?php esc_html_e( 'GSTIN', 'wcfm-gst-tcs' ); ?></label></th>
				<td><input type="text" id="wgt_gstin" name="wgt_gstin" value="<?php echo esc_attr( $gst['gstin'] ); ?>" class="regular-text wgt-gstin-input" maxlength="15" /></td>
			</tr>
			<tr>
				<th><label for="wgt_pan"><?php esc_html_e( 'PAN', 'wcfm-gst-tcs' ); ?></label></th>
				<td><input type="text" id="wgt_pan" name="wgt_pan" value="<?php echo esc_attr( $gst['pan'] ); ?>" class="regular-text" maxlength="10" /></td>
			</tr>
			<tr>
				<th><label for="wgt_legal_name"><?php esc_html_e( 'Registered Business/Legal Name', 'wcfm-gst-tcs' ); ?></label></th>
				<td><input type="text" id="wgt_legal_name" name="wgt_legal_name" value="<?php echo esc_attr( $gst['legal_name'] ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="wgt_state"><?php esc_html_e( 'Business State', 'wcfm-gst-tcs' ); ?></label></th>
				<td>
					<select id="wgt_state" name="wgt_state" class="wgt-state-select">
						<option value=""><?php esc_html_e( '— Select state —', 'wcfm-gst-tcs' ); ?></option>
						<?php foreach ( $states as $code => $name ) : ?>
							<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $gst['state'], $code ); ?>><?php echo esc_html( $name ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
		</table>
		<?php
	}

	public function save_profile_fields( $user_id ) {
		if ( ! isset( $_POST['wgt_profile_gst_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wgt_profile_gst_nonce'] ) ), 'wgt_save_profile_gst' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		$this->sanitize_and_store(
			$user_id,
			array(
				'gstin'          => $_POST['wgt_gstin'] ?? '',
				'pan'            => $_POST['wgt_pan'] ?? '',
				'legal_name'     => $_POST['wgt_legal_name'] ?? '',
				'gst_registered' => $_POST['wgt_gst_registered'] ?? '',
				'state'          => $_POST['wgt_state'] ?? '',
			)
		);
	}

	public function ajax_validate_gstin() {
		check_ajax_referer( 'wgt_ajax_nonce', 'nonce' );

		$gstin = isset( $_POST['gstin'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['gstin'] ) ) ) : '';
		$valid = WGT_States::is_valid_gstin( $gstin );
		$state = $valid ? WGT_States::state_from_gstin( $gstin ) : '';

		wp_send_json(
			array(
				'valid' => $valid,
				'state' => $state,
			)
		);
	}
}
