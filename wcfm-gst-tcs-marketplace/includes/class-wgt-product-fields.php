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

		// Blocks publishing if the HSN/SAC code is missing (when Settings > GST & TCS
		// requires one) or malformed (a non-empty HSN must always be exactly 6 digits),
		// covering both the WCFM frontend form and wp-admin (both post 'wgt_hsn_code').
		add_filter( 'wp_insert_post_data', array( $this, 'enforce_hsn_rules' ), 10, 2 );
		add_filter( 'redirect_post_location', array( $this, 'add_hsn_error_query_arg' ) );
		add_action( 'admin_notices', array( $this, 'render_hsn_error_notice' ) );
	}

	/**
	 * A non-empty HSN must be exactly 6 digits. Whether an HSN is required at all is a
	 * separate, admin-configurable rule (Settings > GST & TCS > "Require HSN/SAC code").
	 */
	public static function is_valid_hsn( $hsn ) {
		return (bool) preg_match( '/^\d{6}$/', (string) $hsn );
	}

	public static function get_hsn( $product_id ) {
		return get_post_meta( $product_id, self::HSN_META, true );
	}

	/**
	 * @param int $vendor_id Pass 0 for a store-wide count across all vendors.
	 */
	public static function count_missing_hsn( $vendor_id = 0 ) {
		global $wpdb;

		$author_clause = '';
		$args          = array();
		if ( $vendor_id ) {
			$author_clause = 'AND p.post_author = %d';
			$args[]        = $vendor_id;
		}

		$sql = "SELECT COUNT(*) FROM {$wpdb->posts} p
			WHERE p.post_type = 'product' AND p.post_status = 'publish' {$author_clause}
			AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} pm
				WHERE pm.post_id = p.ID AND pm.meta_key = %s AND pm.meta_value REGEXP '^[0-9]{6}$'
			)";
		$args[] = self::HSN_META;

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function get_gst_rate( $product_id ) {
		$rate = get_post_meta( $product_id, self::RATE_META, true );
		if ( '' !== $rate && null !== $rate ) {
			return (float) $rate;
		}

		$settings = WGT_Admin_Settings::get_settings();
		return '' !== $settings['default_gst_rate'] ? (float) $settings['default_gst_rate'] : 0.0;
	}

	private static function sanitize_hsn( $value ) {
		return preg_replace( '/[^0-9]/', '', sanitize_text_field( wp_unslash( (string) $value ) ) );
	}

	private static function sanitize_rate( $value ) {
		$rate = wc_format_decimal( sanitize_text_field( wp_unslash( (string) $value ) ) );
		$rate = (float) $rate;
		return max( 0, min( 100, $rate ) );
	}

	/**
	 * Public wrappers around the same validation save_from_wcfm_form()/save_admin_fields() use,
	 * so bulk-import tooling (WGT_Bulk_Tax) updates products through the identical rules
	 * instead of re-implementing them — a malformed HSN is silently dropped rather than saved,
	 * same as the single-product forms.
	 */
	public static function update_hsn( $product_id, $raw_value ) {
		self::save_hsn( $product_id, $raw_value );
	}

	public static function update_gst_rate( $product_id, $raw_value ) {
		$raw_value = trim( (string) $raw_value );
		if ( '' === $raw_value ) {
			delete_post_meta( $product_id, self::RATE_META );
			return;
		}
		update_post_meta( $product_id, self::RATE_META, self::sanitize_rate( $raw_value ) );
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
			'label'             => __( 'HSN/SAC Code', 'wcfm-gst-tcs' ),
			'name'              => 'wgt_hsn_code',
			'type'              => 'text',
			'value'             => $hsn,
			'class'             => 'wgt-field wgt-hsn-input',
			'custom_attributes' => array( 'maxlength' => 6, 'pattern' => '[0-9]{6}' ),
			'desc'              => __( 'Harmonized System of Nomenclature code — exactly 6 digits.', 'wcfm-gst-tcs' ),
		);

		$general_fields['wgt_gst_rate'] = array(
			'label'   => __( 'GST Rate (%)', 'wcfm-gst-tcs' ),
			'name'    => 'wgt_gst_rate',
			'type'    => 'select',
			'value'   => $rate,
			'options' => $this->rate_options(),
			'class'   => 'wgt-field wgt-gst-rate-select',
			'desc'    => self::completeness_status_text( $hsn, $rate ),
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
			self::save_hsn( $product_id, $_POST['wgt_hsn_code'] );
		}

		if ( isset( $_POST['wgt_gst_rate'] ) && '' !== $_POST['wgt_gst_rate'] ) {
			update_post_meta( $product_id, self::RATE_META, self::sanitize_rate( $_POST['wgt_gst_rate'] ) );
		}
	}

	/**
	 * Only ever persists an empty value or a valid 6-digit HSN — a malformed value (wrong
	 * length, non-digits) is silently dropped here rather than saved, and enforce_hsn_rules()
	 * is what actually blocks the product from publishing and tells the user why.
	 */
	private static function save_hsn( $product_id, $raw_value ) {
		$hsn = self::sanitize_hsn( $raw_value );
		if ( '' === $hsn || self::is_valid_hsn( $hsn ) ) {
			update_post_meta( $product_id, self::HSN_META, $hsn );
		}
	}

	public function render_admin_fields() {
		global $post;

		$hsn  = self::get_hsn( $post->ID );
		$rate = get_post_meta( $post->ID, self::RATE_META, true );
		?>
		<div class="options_group wgt-product-fields">
			<p class="form-field wgt_hsn_code_field">
				<label for="wgt_hsn_code"><?php esc_html_e( 'HSN/SAC Code (6 digits)', 'wcfm-gst-tcs' ); ?></label>
				<input type="text" class="short" id="wgt_hsn_code" name="wgt_hsn_code" maxlength="6" pattern="[0-9]{6}" value="<?php echo esc_attr( $hsn ); ?>" />
			</p>
			<p class="form-field wgt_gst_rate_field">
				<label for="wgt_gst_rate"><?php esc_html_e( 'GST Rate (%)', 'wcfm-gst-tcs' ); ?></label>
				<select id="wgt_gst_rate" name="wgt_gst_rate" class="wgt-gst-rate-select">
					<option value=""><?php esc_html_e( 'Use default', 'wcfm-gst-tcs' ); ?></option>
					<?php foreach ( self::GST_SLABS as $slab ) : ?>
						<option value="<?php echo esc_attr( $slab ); ?>" <?php selected( (string) $rate, $slab ); ?>><?php echo esc_html( $slab ); ?>%</option>
					<?php endforeach; ?>
				</select>
			</p>
			<?php $this->render_completeness_status( $hsn, $rate ); ?>
		</div>
		<?php
	}

	/**
	 * Inline "is this product's tax info ready to publish" line, shown right in the product
	 * form — so a vendor sees and can fix a problem before attempting to publish, rather than
	 * only finding out via enforce_hsn_rules() bouncing the product back to Pending afterwards.
	 */
	private function render_completeness_status( $hsn, $rate ) {
		echo '<p class="wgt-tax-status" style="margin:4px 0 0;">';
		foreach ( self::completeness_messages( $hsn, $rate ) as $message ) {
			list( $level, $text ) = $message;
			$color = 'error' === $level ? '#b32d2e' : ( 'warn' === $level ? '#996800' : '#1a7f37' );
			$icon  = 'ok' === $level ? '✓' : '⚠';
			echo '<span style="display:block;color:' . esc_attr( $color ) . ';">' . esc_html( $icon . ' ' . $text ) . '</span>';
		}
		echo '</p>';
	}

	/**
	 * @return array<int,array{0:string,1:string}> Pairs of ('ok'|'warn'|'error', message).
	 */
	private static function completeness_messages( $hsn, $rate ) {
		$settings     = WGT_Admin_Settings::get_settings();
		$hsn_required = 'yes' === $settings['hsn_mandatory'];
		$messages     = array();

		if ( $hsn && ! self::is_valid_hsn( $hsn ) ) {
			$messages[] = array( 'error', __( 'HSN/SAC must be exactly 6 digits.', 'wcfm-gst-tcs' ) );
		} elseif ( ! $hsn && $hsn_required ) {
			$messages[] = array( 'error', __( 'HSN/SAC is required before this product can be published.', 'wcfm-gst-tcs' ) );
		} elseif ( ! $hsn ) {
			$messages[] = array( 'warn', __( 'No HSN/SAC set — GST reports will show this product as unclassified until one is added.', 'wcfm-gst-tcs' ) );
		}

		if ( '' === $rate ) {
			$messages[] = array( 'warn', __( 'No GST rate selected — the store default rate will be used instead.', 'wcfm-gst-tcs' ) );
		}

		if ( empty( $messages ) ) {
			$messages[] = array( 'ok', __( 'Tax info complete.', 'wcfm-gst-tcs' ) );
		}

		return $messages;
	}

	/**
	 * Plain-text (no markup) version of completeness_messages(), for surfaces like the WCFM
	 * field 'desc' that render description text without trusting embedded HTML.
	 */
	private static function completeness_status_text( $hsn, $rate ) {
		$lines = array();
		foreach ( self::completeness_messages( $hsn, $rate ) as $message ) {
			list( $level, $text ) = $message;
			$icon    = 'ok' === $level ? '✓' : '⚠';
			$lines[] = $icon . ' ' . $text;
		}
		return implode( ' ', $lines );
	}

	public function save_admin_fields( $post_id ) {
		if ( isset( $_POST['wgt_hsn_code'] ) ) {
			self::save_hsn( $post_id, $_POST['wgt_hsn_code'] );
		}

		if ( isset( $_POST['wgt_gst_rate'] ) && '' !== $_POST['wgt_gst_rate'] ) {
			update_post_meta( $post_id, self::RATE_META, self::sanitize_rate( $_POST['wgt_gst_rate'] ) );
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

		$hsn_display = '—';
		if ( $hsn ) {
			$hsn_display = self::is_valid_hsn( $hsn )
				? esc_html( $hsn )
				: '<span style="color:#b32d2e;" title="' . esc_attr__( 'HSN must be exactly 6 digits', 'wcfm-gst-tcs' ) . '">' . esc_html( $hsn ) . ' ⚠</span>';
		}

		echo wp_kses_post( $hsn_display ) . ' / ' . esc_html( '' !== $rate ? $rate . '%' : '—' );
	}

	/**
	 * Forces a product back to 'pending' instead of publishing when its HSN/SAC is either
	 * missing (only blocked if Settings > GST & TCS > "Require HSN/SAC code" is on) or
	 * malformed (blocked unconditionally — a non-empty HSN must always be exactly 6 digits).
	 * Only acts when 'wgt_hsn_code' was actually part of the submitted form (our own
	 * product-manage forms), so REST/bulk/programmatic saves that don't touch this field
	 * are left alone.
	 */
	public function enforce_hsn_rules( $data, $postarr ) {
		if ( ! isset( $data['post_type'] ) || 'product' !== $data['post_type'] ) {
			return $data;
		}
		if ( 'publish' !== $data['post_status'] ) {
			return $data;
		}
		if ( ! isset( $_POST['wgt_hsn_code'] ) ) {
			return $data;
		}

		$hsn      = self::sanitize_hsn( $_POST['wgt_hsn_code'] );
		$settings = WGT_Admin_Settings::get_settings();

		$reason = '';
		if ( '' !== $hsn && ! self::is_valid_hsn( $hsn ) ) {
			$reason = 'invalid';
		} elseif ( '' === $hsn && 'yes' === $settings['hsn_mandatory'] ) {
			$reason = 'missing';
		}

		if ( $reason && ! empty( $postarr['ID'] ) ) {
			$data['post_status'] = 'pending';
			set_transient( 'wgt_hsn_blocked_' . $postarr['ID'], $reason, MINUTE_IN_SECONDS );
		}

		return $data;
	}

	public function add_hsn_error_query_arg( $location ) {
		// This filter runs while wp-admin/post.php is still building the redirect for the
		// POST request that just saved the product, so the post ID comes from the submitted
		// form field rather than the query string of the (not yet loaded) redirect target.
		$post_id = isset( $_POST['post_ID'] ) ? absint( $_POST['post_ID'] ) : 0;
		$reason  = $post_id ? get_transient( 'wgt_hsn_blocked_' . $post_id ) : false;
		if ( $reason ) {
			delete_transient( 'wgt_hsn_blocked_' . $post_id );
			$location = add_query_arg( 'wgt_hsn_error', $reason, $location );
		}
		return $location;
	}

	public function render_hsn_error_notice() {
		if ( empty( $_GET['wgt_hsn_error'] ) ) {
			return;
		}

		$reason  = sanitize_key( wp_unslash( $_GET['wgt_hsn_error'] ) );
		$message = 'invalid' === $reason
			? __( 'This product was saved as Pending, not Published, because its HSN/SAC code must be exactly 6 digits.', 'wcfm-gst-tcs' )
			: __( 'This product was saved as Pending, not Published, because an HSN/SAC code is required (Settings > GST & TCS).', 'wcfm-gst-tcs' );
		?>
		<div class="notice notice-error is-dismissible">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
		<?php
	}
}
