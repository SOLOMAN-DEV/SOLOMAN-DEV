<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HSN/SAC code + GST rate on each product, editable from both the WCFM vendor
 * product manager and the wp-admin product edit screen, backed by the same
 * post meta keys so either UI works interchangeably.
 */
class WGT_Product_Fields {

	const HSN_META  = '_wgt_hsn_code';
	const RATE_META = '_wgt_gst_rate';

	/** Common Indian GST slabs, offered as quick picks; vendors can still type any rate. */
	const GST_SLABS = array( '0', '0.25', '3', '5', '12', '18', '28' );

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// WCFM frontend product manager.
		add_filter( 'wcfm_product_manage_fields_general', array( $this, 'add_fields_to_wcfm_form' ), 50, 2 );
		add_action( 'after_wcfm_products_manage_meta_save', array( $this, 'save_from_wcfm_form' ), 20, 2 );

		// wp-admin product edit screen.
		add_action( 'woocommerce_product_options_tax', array( $this, 'render_admin_fields' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_admin_fields' ) );

		// Admin products list: HSN + GST rate column.
		add_filter( 'manage_edit-product_columns', array( $this, 'add_product_column' ) );
		add_action( 'manage_product_posts_custom_column', array( $this, 'render_product_column' ), 10, 2 );
	}

	public static function get_hsn( $product_id ) {
		return get_post_meta( $product_id, self::HSN_META, true );
	}

	public static function get_gst_rate( $product_id ) {
		$rate = get_post_meta( $product_id, self::RATE_META, true );
		if ( '' !== $rate && null !== $rate ) {
			return (float) $rate;
		}

		$settings = WGT_Admin_Settings::get_settings();
		return '' !== $settings['default_gst_rate'] ? (float) $settings['default_gst_rate'] : 0.0;
	}

	private function sanitize_hsn( $value ) {
		return preg_replace( '/[^0-9]/', '', sanitize_text_field( wp_unslash( $value ) ) );
	}

	private function sanitize_rate( $value ) {
		$rate = wc_format_decimal( sanitize_text_field( wp_unslash( $value ) ) );
		$rate = (float) $rate;
		return max( 0, min( 100, $rate ) );
	}

	/**
	 * Best-effort injection into the WCFM product manager "General" tab field array.
	 */
	public function add_fields_to_wcfm_form( $general_fields, $product_id = 0 ) {
		if ( ! is_array( $general_fields ) ) {
			return $general_fields;
		}

		$hsn  = self::get_hsn( $product_id );
		$rate = get_post_meta( $product_id, self::RATE_META, true );

		$general_fields['wgt_hsn_code'] = array(
			'label' => __( 'HSN/SAC Code', 'wcfm-gst-tcs' ),
			'name'  => 'wgt_hsn_code',
			'type'  => 'text',
			'value' => $hsn,
			'class' => 'wgt-field wgt-hsn-input',
			'desc'  => __( 'Harmonized System of Nomenclature code for this product/service.', 'wcfm-gst-tcs' ),
		);

		$general_fields['wgt_gst_rate'] = array(
			'label'   => __( 'GST Rate (%)', 'wcfm-gst-tcs' ),
			'name'    => 'wgt_gst_rate',
			'type'    => 'select',
			'value'   => $rate,
			'options' => $this->rate_options(),
			'class'   => 'wgt-field wgt-gst-rate-select',
		);

		return $general_fields;
	}

	private function rate_options() {
		$options = array();
		foreach ( self::GST_SLABS as $slab ) {
			/* translators: %s: GST percentage */
			$options[ $slab ] = sprintf( __( '%s%%', 'wcfm-gst-tcs' ), $slab );
		}
		return $options;
	}

	public function save_from_wcfm_form( $product_id, $form_data = array() ) {
		if ( ! $product_id ) {
			return;
		}

		if ( isset( $_POST['wgt_hsn_code'] ) ) {
			update_post_meta( $product_id, self::HSN_META, $this->sanitize_hsn( $_POST['wgt_hsn_code'] ) );
		}

		if ( isset( $_POST['wgt_gst_rate'] ) && '' !== $_POST['wgt_gst_rate'] ) {
			update_post_meta( $product_id, self::RATE_META, $this->sanitize_rate( $_POST['wgt_gst_rate'] ) );
		}
	}

	public function render_admin_fields() {
		global $post;

		$hsn  = self::get_hsn( $post->ID );
		$rate = get_post_meta( $post->ID, self::RATE_META, true );
		?>
		<div class="options_group wgt-product-fields">
			<p class="form-field wgt_hsn_code_field">
				<label for="wgt_hsn_code"><?php esc_html_e( 'HSN/SAC Code', 'wcfm-gst-tcs' ); ?></label>
				<input type="text" class="short" id="wgt_hsn_code" name="wgt_hsn_code" value="<?php echo esc_attr( $hsn ); ?>" />
			</p>
			<p class="form-field wgt_gst_rate_field">
				<label for="wgt_gst_rate"><?php esc_html_e( 'GST Rate (%)', 'wcfm-gst-tcs' ); ?></label>
				<select id="wgt_gst_rate" name="wgt_gst_rate">
					<option value=""><?php esc_html_e( 'Use default', 'wcfm-gst-tcs' ); ?></option>
					<?php foreach ( self::GST_SLABS as $slab ) : ?>
						<option value="<?php echo esc_attr( $slab ); ?>" <?php selected( (string) $rate, $slab ); ?>><?php echo esc_html( $slab ); ?>%</option>
					<?php endforeach; ?>
				</select>
			</p>
		</div>
		<?php
	}

	public function save_admin_fields( $post_id ) {
		if ( isset( $_POST['wgt_hsn_code'] ) ) {
			update_post_meta( $post_id, self::HSN_META, $this->sanitize_hsn( $_POST['wgt_hsn_code'] ) );
		}

		if ( isset( $_POST['wgt_gst_rate'] ) && '' !== $_POST['wgt_gst_rate'] ) {
			update_post_meta( $post_id, self::RATE_META, $this->sanitize_rate( $_POST['wgt_gst_rate'] ) );
		} elseif ( isset( $_POST['wgt_gst_rate'] ) ) {
			delete_post_meta( $post_id, self::RATE_META );
		}
	}

	public function add_product_column( $columns ) {
		$columns['wgt_gst'] = __( 'HSN / GST', 'wcfm-gst-tcs' );
		return $columns;
	}

	public function render_product_column( $column, $product_id ) {
		if ( 'wgt_gst' !== $column ) {
			return;
		}

		$hsn  = self::get_hsn( $product_id );
		$rate = get_post_meta( $product_id, self::RATE_META, true );

		if ( ! $hsn && '' === $rate ) {
			echo '<span style="color:#b32d2e;">' . esc_html__( 'Not set', 'wcfm-gst-tcs' ) . '</span>';
			return;
		}

		echo esc_html( $hsn ? $hsn : '—' ) . ' / ' . esc_html( '' !== $rate ? $rate . '%' : '—' );
	}
}
