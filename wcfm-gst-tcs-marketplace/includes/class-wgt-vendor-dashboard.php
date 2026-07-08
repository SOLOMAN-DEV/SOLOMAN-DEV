<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Vendor-facing GST/TCS reports. Delivered as a WooCommerce My Account endpoint
 * (stable, version-independent WooCommerce API) rather than hooking into WCFM's
 * own dashboard-menu internals, so it keeps working across WCFM versions. A
 * shortcode is also provided for admins who want to surface it elsewhere,
 * e.g. linked from a custom WCFM dashboard menu item.
 *
 * Every export here forces vendor_id to the logged-in user's own ID server-side —
 * a vendor can never pull another vendor's figures by tampering with form fields.
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
		add_action( 'admin_post_wgt_export_vendor_invoices', array( $this, 'export_own_invoices_csv' ) );
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

	private function can_view_own_reports() {
		return current_user_can( 'manage_woocommerce' ) || $this->current_user_is_vendor();
	}

	/**
	 * Defaults the report range to "financial-year-to-date" (1 April, or last year's
	 * 1 April if we're before April), matching how the admin-side ledger is grouped.
	 */
	private function default_date_from() {
		$year  = (int) gmdate( 'Y' );
		$month = (int) gmdate( 'n' );
		if ( $month < 4 ) {
			--$year;
		}
		return $year . '-04-01';
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
			return '<p>' . esc_html__( 'Please log in to view your GST & TCS reports.', 'wcfm-gst-tcs' ) . '</p>';
		}

		if ( ! $this->can_view_own_reports() ) {
			return '<p>' . esc_html__( 'These reports are only available to vendors.', 'wcfm-gst-tcs' ) . '</p>';
		}

		$vendor_id = get_current_user_id();
		$date_from = isset( $_GET['wgt_date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['wgt_date_from'] ) ) : $this->default_date_from();
		$date_to   = isset( $_GET['wgt_date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['wgt_date_to'] ) ) : gmdate( 'Y-m-d' );

		$summary     = WGT_TCS_Engine::get_vendor_summary( $vendor_id, array( 'date_from' => $date_from, 'date_to' => $date_to ) );
		$gst         = WGT_Vendor_Settings::get_vendor_gst( $vendor_id );
		$missing_hsn = class_exists( 'WGT_Product_Fields' ) ? WGT_Product_Fields::count_missing_hsn( $vendor_id ) : 0;

		ob_start();
		?>
		<div class="wgt-vendor-report">
			<?php if ( ! $gst['gstin'] ) : ?>
				<p class="woocommerce-info"><?php esc_html_e( 'Add your GSTIN in Store Settings so orders are taxed correctly and your GST invoices show it.', 'wcfm-gst-tcs' ); ?></p>
			<?php endif; ?>
			<?php if ( $missing_hsn > 0 ) : ?>
				<p class="woocommerce-info">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of products missing an HSN/SAC code */
							_n( '%d of your published products is missing an HSN/SAC code.', '%d of your published products are missing an HSN/SAC code.', $missing_hsn, 'wcfm-gst-tcs' ),
							$missing_hsn
						)
					);
					?>
				</p>
			<?php endif; ?>

			<form method="get">
				<label><?php esc_html_e( 'From', 'wcfm-gst-tcs' ); ?> <input type="date" name="wgt_date_from" value="<?php echo esc_attr( $date_from ); ?>" /></label>
				<label><?php esc_html_e( 'To', 'wcfm-gst-tcs' ); ?> <input type="date" name="wgt_date_to" value="<?php echo esc_attr( $date_to ); ?>" /></label>
				<button type="submit"><?php esc_html_e( 'Filter', 'wcfm-gst-tcs' ); ?></button>
			</form>

			<h3><?php esc_html_e( 'Summary', 'wcfm-gst-tcs' ); ?></h3>
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

			<h3><?php esc_html_e( 'Download Reports', 'wcfm-gst-tcs' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Reports cover the date range selected above.', 'wcfm-gst-tcs' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:10px;">
				<input type="hidden" name="action" value="wgt_export_vendor_tcs" />
				<input type="hidden" name="date_from" value="<?php echo esc_attr( $date_from ); ?>" />
				<input type="hidden" name="date_to" value="<?php echo esc_attr( $date_to ); ?>" />
				<?php wp_nonce_field( 'wgt_export_vendor_tcs' ); ?>
				<button type="submit" class="button"><?php esc_html_e( 'Summary Report (CSV)', 'wcfm-gst-tcs' ); ?></button>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
				<input type="hidden" name="action" value="wgt_export_vendor_invoices" />
				<input type="hidden" name="date_from" value="<?php echo esc_attr( $date_from ); ?>" />
				<input type="hidden" name="date_to" value="<?php echo esc_attr( $date_to ); ?>" />
				<?php wp_nonce_field( 'wgt_export_vendor_invoices' ); ?>
				<button type="submit" class="button"><?php esc_html_e( 'Detailed Invoice Report (CSV)', 'wcfm-gst-tcs' ); ?></button>
			</form>
			<p class="description"><?php esc_html_e( 'The detailed report lists every order line item — HSN/SAC code, taxable value, CGST/SGST/IGST and buyer details for business purchases — for your own bookkeeping or your accountant.', 'wcfm-gst-tcs' ); ?></p>
		</div>
		<?php
		return ob_get_clean();
	}

	private function get_export_date_range() {
		return array(
			isset( $_POST['date_from'] ) ? sanitize_text_field( wp_unslash( $_POST['date_from'] ) ) : $this->default_date_from(),
			isset( $_POST['date_to'] ) ? sanitize_text_field( wp_unslash( $_POST['date_to'] ) ) : gmdate( 'Y-m-d' ),
		);
	}

	public function export_own_tcs_csv() {
		check_admin_referer( 'wgt_export_vendor_tcs' );

		$vendor_id = get_current_user_id();
		if ( ! $vendor_id || ! $this->can_view_own_reports() ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wcfm-gst-tcs' ) );
		}

		list( $date_from, $date_to ) = $this->get_export_date_range();

		$rows = WGT_TCS_Engine::get_ledger_rows(
			array(
				'vendor_id' => $vendor_id,
				'date_from' => $date_from,
				'date_to'   => $date_to,
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
			'my-gst-tcs-summary-' . $date_from . '-to-' . $date_to,
			array( 'Order ID', 'Order Date', 'Net Taxable Value', 'GST Amount', 'CGST TCS', 'SGST TCS', 'IGST TCS', 'Total TCS', 'Financial Year' ),
			$csv
		);
	}

	/**
	 * Line-item-level export of the vendor's own orders: HSN, taxable value, tax split,
	 * and buyer name/GSTIN for business purchases — the same data admin's GSTR-1 export
	 * uses, filtered to just this vendor. Large ranges queue in the background the same
	 * way the admin export does, so a high-volume vendor can't time out the request.
	 */
	public function export_own_invoices_csv() {
		check_admin_referer( 'wgt_export_vendor_invoices' );

		$vendor_id = get_current_user_id();
		if ( ! $vendor_id || ! $this->can_view_own_reports() ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wcfm-gst-tcs' ) );
		}

		list( $date_from, $date_to ) = $this->get_export_date_range();

		if ( class_exists( 'WGT_Export_Job' ) && WGT_Export_Job::instance()->maybe_queue( 'gstr1', $date_from, $date_to, $vendor_id ) ) {
			wp_safe_redirect( wp_get_referer() ? wp_get_referer() : wc_get_account_endpoint_url( self::ENDPOINT ) );
			exit;
		}

		$rows = WGT_Admin_Reports::gather_gstr1_rows( $date_from, $date_to, $vendor_id );

		WGT_CSV_Export::stream(
			'my-invoice-report-' . $date_from . '-to-' . $date_to,
			array( 'Vendor', 'Vendor GSTIN', 'Order ID', 'Invoice Date', 'Type', 'Buyer Name/Company', 'Buyer GSTIN', 'Place of Supply', 'HSN/SAC', 'Taxable Value', 'GST Rate', 'CGST', 'SGST', 'IGST', 'Invoice Value' ),
			$rows
		);
	}
}
