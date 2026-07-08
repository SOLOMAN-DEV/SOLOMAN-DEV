<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Vendor-facing GST/TCS summary. Delivered as a WooCommerce My Account endpoint
 * (stable, version-independent WooCommerce API) rather than hooking into WCFM's
 * own dashboard-menu internals, so it keeps working across WCFM versions. A
 * shortcode is also provided for admins who want to surface it elsewhere,
 * e.g. linked from a custom WCFM dashboard menu item.
 */
class WGT_Vendor_Dashboard {

	const ENDPOINT = 'gst-tcs-report';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'add_endpoint' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'add_account_menu_item' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render_account_endpoint' ) );

		add_shortcode( 'wgt_vendor_gst_report', array( $this, 'render_shortcode' ) );

		add_action( 'admin_post_wgt_export_vendor_tcs', array( $this, 'export_own_tcs_csv' ) );
	}

	public function add_endpoint() {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	private function current_user_is_vendor() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}
		if ( function_exists( 'wcfm_is_vendor' ) ) {
			return (bool) wcfm_is_vendor( $user_id );
		}
		return user_can( $user_id, 'wcfm_vendor' ) || user_can( $user_id, 'dc_vendor' );
	}

	public function add_account_menu_item( $items ) {
		if ( ! $this->current_user_is_vendor() ) {
			return $items;
		}

		$new_items = array();
		foreach ( $items as $key => $label ) {
			$new_items[ $key ] = $label;
			if ( 'orders' === $key ) {
				$new_items[ self::ENDPOINT ] = __( 'GST & TCS', 'wcfm-gst-tcs' );
			}
		}
		if ( ! isset( $new_items[ self::ENDPOINT ] ) ) {
			$new_items[ self::ENDPOINT ] = __( 'GST & TCS', 'wcfm-gst-tcs' );
		}
		return $new_items;
	}

	public function render_account_endpoint() {
		echo $this->render_shortcode( array() ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public function render_shortcode( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Please log in to view your GST & TCS summary.', 'wcfm-gst-tcs' ) . '</p>';
		}

		$vendor_id = get_current_user_id();
		if ( ! current_user_can( 'manage_woocommerce' ) && ! $this->current_user_is_vendor() ) {
			return '<p>' . esc_html__( 'This report is only available to vendors.', 'wcfm-gst-tcs' ) . '</p>';
		}

		$fy      = isset( $_GET['wgt_fy'] ) ? sanitize_text_field( wp_unslash( $_GET['wgt_fy'] ) ) : '';
		$summary = WGT_TCS_Engine::get_vendor_summary( $vendor_id, $fy );
		$gst     = WGT_Vendor_Settings::get_vendor_gst( $vendor_id );

		ob_start();
		?>
		<div class="wgt-vendor-report">
			<?php if ( ! $gst['gstin'] ) : ?>
				<p class="woocommerce-info"><?php esc_html_e( 'Add your GSTIN in Store Settings so orders are taxed correctly and your GST invoices show it.', 'wcfm-gst-tcs' ); ?></p>
			<?php endif; ?>

			<form method="get">
				<label><?php esc_html_e( 'Financial Year', 'wcfm-gst-tcs' ); ?>
					<input type="text" name="wgt_fy" value="<?php echo esc_attr( $fy ); ?>" placeholder="<?php echo esc_attr( WGT_TCS_Engine::instance()->financial_year_for( null ) ); ?>" />
				</label>
				<button type="submit"><?php esc_html_e( 'Filter', 'wcfm-gst-tcs' ); ?></button>
			</form>

			<table class="shop_table">
				<tbody>
					<tr><th><?php esc_html_e( 'Orders', 'wcfm-gst-tcs' ); ?></th><td><?php echo esc_html( $summary['order_count'] ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Net Taxable Value', 'wcfm-gst-tcs' ); ?></th><td><?php echo wp_kses_post( wc_price( $summary['net_taxable_value'] ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'GST Collected', 'wcfm-gst-tcs' ); ?></th><td><?php echo wp_kses_post( wc_price( $summary['gst_amount'] ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'CGST TCS Deducted', 'wcfm-gst-tcs' ); ?></th><td><?php echo wp_kses_post( wc_price( $summary['cgst_tcs'] ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'SGST TCS Deducted', 'wcfm-gst-tcs' ); ?></th><td><?php echo wp_kses_post( wc_price( $summary['sgst_tcs'] ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'IGST TCS Deducted', 'wcfm-gst-tcs' ); ?></th><td><?php echo wp_kses_post( wc_price( $summary['igst_tcs'] ) ); ?></td></tr>
					<tr><th><strong><?php esc_html_e( 'Total TCS Deducted', 'wcfm-gst-tcs' ); ?></strong></th><td><strong><?php echo wp_kses_post( wc_price( $summary['tcs_amount'] ) ); ?></strong></td></tr>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wgt_export_vendor_tcs" />
				<input type="hidden" name="wgt_fy" value="<?php echo esc_attr( $fy ); ?>" />
				<?php wp_nonce_field( 'wgt_export_vendor_tcs' ); ?>
				<button type="submit" class="button"><?php esc_html_e( 'Export CSV', 'wcfm-gst-tcs' ); ?></button>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	public function export_own_tcs_csv() {
		check_admin_referer( 'wgt_export_vendor_tcs' );

		$vendor_id = get_current_user_id();
		if ( ! $vendor_id || ( ! current_user_can( 'manage_woocommerce' ) && ! $this->current_user_is_vendor() ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wcfm-gst-tcs' ) );
		}

		$fy   = isset( $_POST['wgt_fy'] ) ? sanitize_text_field( wp_unslash( $_POST['wgt_fy'] ) ) : '';
		$rows = WGT_TCS_Engine::get_ledger_rows(
			array(
				'vendor_id'      => $vendor_id,
				'financial_year' => $fy,
			)
		);

		$csv = array();
		foreach ( $rows as $row ) {
			$csv[] = array(
				$row->order_id,
				$row->order_date,
				number_format( (float) $row->net_taxable_value, 2, '.', '' ),
				number_format( (float) $row->gst_amount, 2, '.', '' ),
				number_format( (float) $row->cgst_tcs, 2, '.', '' ),
				number_format( (float) $row->sgst_tcs, 2, '.', '' ),
				number_format( (float) $row->igst_tcs, 2, '.', '' ),
				number_format( (float) $row->tcs_amount, 2, '.', '' ),
				$row->financial_year,
			);
		}

		WGT_CSV_Export::stream(
			'my-gst-tcs-report',
			array( 'Order ID', 'Order Date', 'Net Taxable Value', 'GST Amount', 'CGST TCS', 'SGST TCS', 'IGST TCS', 'Total TCS', 'Financial Year' ),
			$csv
		);
	}
}
