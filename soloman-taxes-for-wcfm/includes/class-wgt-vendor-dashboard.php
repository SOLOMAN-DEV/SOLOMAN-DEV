<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Vendor-facing GST/TCS reports, delivered two ways so vendors find them wherever they
 * actually work: a WooCommerce My Account endpoint (stable, version-independent WC API),
 * and a tab inside the WCFM vendor dashboard itself (most WCFM vendors rarely visit My
 * Account at all — they live in the WCFM dashboard). Both render the exact same content via
 * render_shortcode(), which is also exposed as the [wgt_vendor_gst_report] shortcode.
 *
 * The WCFM integration uses WCFM's own extension points — 'wcfm_query_vars' to register the
 * endpoint, 'wcfm_menus' to add the sidebar item, 'wcfm_load_views' to render content for it
 * (this is the hook WCFM itself falls back to for any endpoint it doesn't know internally) —
 * rather than reading/writing WCFM's private view templates directly.
 *
 * Every export here forces vendor_id to the logged-in user's own ID server-side —
 * a vendor can never pull another vendor's figures by tampering with form fields.
 */
class WGT_Vendor_Dashboard {

	const ENDPOINT      = 'gst-tcs-report';
	const WCFM_ENDPOINT = 'wgt-reports';

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
		add_action( 'admin_post_wgt_export_vendor_hsn_rate', array( $this, 'export_own_hsn_rate_csv' ) );

		// WCFM vendor dashboard tab (best-effort: only takes effect on sites that have
		// WCFM Marketplace active and firing these hooks; harmless no-op otherwise).
		add_action( 'init', array( $this, 'add_wcfm_endpoint' ), 5 );
		add_filter( 'wcfm_query_vars', array( $this, 'add_wcfm_query_var' ) );
		add_filter( 'wcfm_menus', array( $this, 'add_wcfm_menu_item' ) );
		add_filter( 'wcfm_endpoint_' . self::WCFM_ENDPOINT . '_title', array( $this, 'wcfm_page_title' ) );
		add_action( 'wcfm_load_views', array( $this, 'render_wcfm_view' ) );
	}

	public function add_endpoint() {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	/**
	 * Registers the WCFM dashboard endpoint. Guarded by a one-time flush so sites that
	 * update the plugin in place (no re-activation, so register_activation_hook never
	 * fires again) still get a working permalink instead of a 404 until they happen to
	 * resave Settings > Permalinks themselves.
	 */
	public function add_wcfm_endpoint() {
		add_rewrite_endpoint( self::WCFM_ENDPOINT, EP_ALL );

		if ( 'yes' !== get_option( 'wgt_wcfm_endpoint_flushed' ) ) {
			flush_rewrite_rules( false );
			update_option( 'wgt_wcfm_endpoint_flushed', 'yes' );
		}
	}

	public function add_wcfm_query_var( $query_vars ) {
		if ( is_array( $query_vars ) ) {
			$query_vars[ self::WCFM_ENDPOINT ] = self::WCFM_ENDPOINT;
		}
		return $query_vars;
	}

	public function add_wcfm_menu_item( $wcfm_menus ) {
		if ( ! is_array( $wcfm_menus ) || ! $this->current_user_is_vendor() ) {
			return $wcfm_menus;
		}

		$url = function_exists( 'wcfm_get_endpoint_url' )
			? wcfm_get_endpoint_url( self::WCFM_ENDPOINT )
			: add_query_arg( self::WCFM_ENDPOINT, '1' );

		$wcfm_menus[ self::WCFM_ENDPOINT ] = array(
			'label' => __( 'GST & TCS', 'wcfm-gst-tcs' ),
			'url'   => $url,
			'icon'  => 'money',
		);

		return $wcfm_menus;
	}

	public function wcfm_page_title( $title ) {
		return __( 'GST & TCS Reports', 'wcfm-gst-tcs' );
	}

	/**
	 * Fires for every WCFM dashboard endpoint WCFM doesn't handle internally
	 * (its own dispatcher's default case) — we only act when it's ours.
	 */
	public function render_wcfm_view( $end_point ) {
		if ( self::WCFM_ENDPOINT !== $end_point ) {
			return;
		}
		echo '<div class="wcfm-content-inner wgt-wcfm-reports">';
		echo $this->render_shortcode( array() ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</div>';
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

		// Sales/GST figures come from the vendor's actual orders (same source as the admin
		// GST report), not the TCS ledger — the ledger only gets rows when GST-TCS
		// collection is switched on, and a store can run GST without ever enabling TCS.
		$sales_by_vendor = WGT_Admin_Reports::gather_gst_report( $date_from, $date_to, $vendor_id );
		$sales           = isset( $sales_by_vendor[ $vendor_id ] ) ? $sales_by_vendor[ $vendor_id ] : array(
			'order_count' => 0,
			'net'         => 0.0,
			'gst'         => 0.0,
			'cgst'        => 0.0,
			'sgst'        => 0.0,
			'igst'        => 0.0,
		);

		$tcs_enabled = 'yes' === WGT_Admin_Settings::get_settings()['enable_tcs'];
		$tcs_summary = $tcs_enabled
			? WGT_TCS_Engine::get_vendor_summary( $vendor_id, array( 'date_from' => $date_from, 'date_to' => $date_to ) )
			: array();

		$empty_type_row = array( 'order_count' => 0, 'net' => 0.0, 'gst' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0, 'igst' => 0.0 );
		$sales_by_type  = WGT_Admin_Reports::gather_gst_report_by_type( $date_from, $date_to, $vendor_id );
		$by_type        = isset( $sales_by_type[ $vendor_id ] )
			? $sales_by_type[ $vendor_id ]
			: array( 'B2B' => $empty_type_row, 'B2C' => $empty_type_row );

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
				<?php echo $this->previous_month_link(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</form>

			<h3><?php esc_html_e( 'Sales Summary', 'wcfm-gst-tcs' ); ?></h3>
			<table class="shop_table">
				<tbody>
					<tr><th><?php esc_html_e( 'Orders', 'wcfm-gst-tcs' ); ?></th><td><?php echo esc_html( $sales['order_count'] ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Net Taxable Value', 'wcfm-gst-tcs' ); ?></th><td><?php echo wp_kses_post( wc_price( $sales['net'] ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'GST Collected', 'wcfm-gst-tcs' ); ?></th><td><?php echo wp_kses_post( wc_price( $sales['gst'] ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'CGST', 'wcfm-gst-tcs' ); ?></th><td><?php echo wp_kses_post( wc_price( $sales['cgst'] ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'SGST', 'wcfm-gst-tcs' ); ?></th><td><?php echo wp_kses_post( wc_price( $sales['sgst'] ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'IGST', 'wcfm-gst-tcs' ); ?></th><td><?php echo wp_kses_post( wc_price( $sales['igst'] ) ); ?></td></tr>
				</tbody>
			</table>

			<h3><?php esc_html_e( 'B2B / B2C Breakdown', 'wcfm-gst-tcs' ); ?></h3>
			<p class="description"><?php esc_html_e( 'GSTR-1 reports B2B (registered buyers) and B2C (unregistered/consumer) supplies separately — use these figures for your own filing.', 'wcfm-gst-tcs' ); ?></p>
			<table class="shop_table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Type', 'wcfm-gst-tcs' ); ?></th>
						<th><?php esc_html_e( 'Orders', 'wcfm-gst-tcs' ); ?></th>
						<th><?php esc_html_e( 'Net Taxable Value', 'wcfm-gst-tcs' ); ?></th>
						<th><?php esc_html_e( 'CGST', 'wcfm-gst-tcs' ); ?></th>
						<th><?php esc_html_e( 'SGST', 'wcfm-gst-tcs' ); ?></th>
						<th><?php esc_html_e( 'IGST', 'wcfm-gst-tcs' ); ?></th>
						<th><?php esc_html_e( 'Total GST', 'wcfm-gst-tcs' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( array( 'B2B', 'B2C' ) as $type ) : ?>
						<?php $row = $by_type[ $type ]; ?>
						<tr>
							<td><?php echo esc_html( $type ); ?></td>
							<td><?php echo esc_html( $row['order_count'] ); ?></td>
							<td><?php echo wp_kses_post( wc_price( $row['net'] ) ); ?></td>
							<td><?php echo wp_kses_post( wc_price( $row['cgst'] ) ); ?></td>
							<td><?php echo wp_kses_post( wc_price( $row['sgst'] ) ); ?></td>
							<td><?php echo wp_kses_post( wc_price( $row['igst'] ) ); ?></td>
							<td><?php echo wp_kses_post( wc_price( $row['gst'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $tcs_enabled ) : ?>
				<h3><?php esc_html_e( 'GST-TCS Deducted', 'wcfm-gst-tcs' ); ?></h3>
				<table class="shop_table">
					<tbody>
						<tr><th><?php esc_html_e( 'CGST TCS', 'wcfm-gst-tcs' ); ?></th><td><?php echo wp_kses_post( wc_price( $tcs_summary['cgst_tcs'] ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'SGST TCS', 'wcfm-gst-tcs' ); ?></th><td><?php echo wp_kses_post( wc_price( $tcs_summary['sgst_tcs'] ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'IGST TCS', 'wcfm-gst-tcs' ); ?></th><td><?php echo wp_kses_post( wc_price( $tcs_summary['igst_tcs'] ) ); ?></td></tr>
						<tr><th><strong><?php esc_html_e( 'Total TCS Deducted', 'wcfm-gst-tcs' ); ?></strong></th><td><strong><?php echo wp_kses_post( wc_price( $tcs_summary['tcs_amount'] ) ); ?></strong></td></tr>
					</tbody>
				</table>
			<?php endif; ?>

			<h3><?php esc_html_e( 'Download Reports', 'wcfm-gst-tcs' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Reports cover the date range selected above.', 'wcfm-gst-tcs' ); ?></p>

			<?php if ( $tcs_enabled ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:10px;">
					<input type="hidden" name="action" value="wgt_export_vendor_tcs" />
					<input type="hidden" name="date_from" value="<?php echo esc_attr( $date_from ); ?>" />
					<input type="hidden" name="date_to" value="<?php echo esc_attr( $date_to ); ?>" />
					<?php wp_nonce_field( 'wgt_export_vendor_tcs' ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'TCS Ledger Report (CSV)', 'wcfm-gst-tcs' ); ?></button>
				</form>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:10px;">
				<input type="hidden" name="action" value="wgt_export_vendor_invoices" />
				<input type="hidden" name="date_from" value="<?php echo esc_attr( $date_from ); ?>" />
				<input type="hidden" name="date_to" value="<?php echo esc_attr( $date_to ); ?>" />
				<?php wp_nonce_field( 'wgt_export_vendor_invoices' ); ?>
				<button type="submit" class="button"><?php esc_html_e( 'Detailed Invoice Report — All (CSV)', 'wcfm-gst-tcs' ); ?></button>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:10px;">
				<input type="hidden" name="action" value="wgt_export_vendor_invoices" />
				<input type="hidden" name="type" value="B2B" />
				<input type="hidden" name="date_from" value="<?php echo esc_attr( $date_from ); ?>" />
				<input type="hidden" name="date_to" value="<?php echo esc_attr( $date_to ); ?>" />
				<?php wp_nonce_field( 'wgt_export_vendor_invoices' ); ?>
				<button type="submit" class="button"><?php esc_html_e( 'B2B Only (CSV)', 'wcfm-gst-tcs' ); ?></button>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
				<input type="hidden" name="action" value="wgt_export_vendor_invoices" />
				<input type="hidden" name="type" value="B2C" />
				<input type="hidden" name="date_from" value="<?php echo esc_attr( $date_from ); ?>" />
				<input type="hidden" name="date_to" value="<?php echo esc_attr( $date_to ); ?>" />
				<?php wp_nonce_field( 'wgt_export_vendor_invoices' ); ?>
				<button type="submit" class="button"><?php esc_html_e( 'B2C Only (CSV)', 'wcfm-gst-tcs' ); ?></button>
			</form>
			<p class="description"><?php esc_html_e( 'The detailed report lists every order line item with full order details — customer, addresses, payment method, product, quantity, pricing, HSN/SAC code, taxable value, CGST/SGST/IGST and buyer details for business purchases — for your own bookkeeping, your accountant, or filing GSTR-1 (B2B and B2C are reported separately).', 'wcfm-gst-tcs' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:10px;">
				<input type="hidden" name="action" value="wgt_export_vendor_hsn_rate" />
				<input type="hidden" name="kind" value="hsn" />
				<input type="hidden" name="date_from" value="<?php echo esc_attr( $date_from ); ?>" />
				<input type="hidden" name="date_to" value="<?php echo esc_attr( $date_to ); ?>" />
				<?php wp_nonce_field( 'wgt_export_vendor_hsn_rate' ); ?>
				<button type="submit" class="button"><?php esc_html_e( 'HSN Summary — GSTR-1 Table 12 (CSV)', 'wcfm-gst-tcs' ); ?></button>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
				<input type="hidden" name="action" value="wgt_export_vendor_hsn_rate" />
				<input type="hidden" name="kind" value="rate" />
				<input type="hidden" name="date_from" value="<?php echo esc_attr( $date_from ); ?>" />
				<input type="hidden" name="date_to" value="<?php echo esc_attr( $date_to ); ?>" />
				<?php wp_nonce_field( 'wgt_export_vendor_hsn_rate' ); ?>
				<button type="submit" class="button"><?php esc_html_e( 'Rate Summary — GSTR-3B Table 3.1 (CSV)', 'wcfm-gst-tcs' ); ?></button>
			</form>
			<p class="description"><?php esc_html_e( 'HSN-wise and rate-wise summaries of your sales, for the HSN summary and outward-supply tables in your own filing. UQC (unit) is always shown as NOS (Numbers), since WooCommerce doesn\'t track a unit of measure — check this if you sell by weight, length, or volume.', 'wcfm-gst-tcs' ); ?></p>

			<?php if ( class_exists( 'WGT_Bulk_Tax' ) ) : ?>
				<h3><?php esc_html_e( 'Bulk Update Your Products\' HSN/GST', 'wcfm-gst-tcs' ); ?></h3>
				<?php WGT_Bulk_Tax::instance()->render_section( 'vendor' ); ?>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Links back to the current page (My Account tab, WCFM tab, or a page with the
	 * shortcode — whichever this is being rendered on) with the date range set to last
	 * calendar month, the cadence GST/TCS filing actually runs on.
	 */
	private function previous_month_link() {
		list( $date_from, $date_to ) = WGT_Admin_Reports::previous_month_range();
		$url = add_query_arg(
			array(
				'wgt_date_from' => $date_from,
				'wgt_date_to'   => $date_to,
			)
		);
		return '<a href="' . esc_url( $url ) . '" class="button">' . esc_html__( 'Previous Month', 'wcfm-gst-tcs' ) . '</a>';
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
		$type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
		$type = in_array( $type, array( 'B2B', 'B2C' ), true ) ? $type : '';

		if ( class_exists( 'WGT_Export_Job' ) && WGT_Export_Job::instance()->maybe_queue( 'gstr1', $date_from, $date_to, $vendor_id, $type ) ) {
			wp_safe_redirect( wp_get_referer() ? wp_get_referer() : wc_get_account_endpoint_url( self::ENDPOINT ) );
			exit;
		}

		$rows = WGT_Admin_Reports::gather_gstr1_rows( $date_from, $date_to, $vendor_id, $type );

		WGT_CSV_Export::stream(
			'my-invoice-report-' . ( $type ? strtolower( $type ) . '-' : '' ) . $date_from . '-to-' . $date_to,
			WGT_Admin_Reports::gstr1_headers(),
			$rows
		);
	}

	/**
	 * HSN-wise (GSTR-1 Table 12) or rate-wise (GSTR-3B Table 3.1) summary of the vendor's own
	 * sales, same underlying figures as the admin HSN & Rate Summary page, filtered to this
	 * vendor only.
	 */
	public function export_own_hsn_rate_csv() {
		check_admin_referer( 'wgt_export_vendor_hsn_rate' );

		$vendor_id = get_current_user_id();
		if ( ! $vendor_id || ! $this->can_view_own_reports() ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wcfm-gst-tcs' ) );
		}

		list( $date_from, $date_to ) = $this->get_export_date_range();
		$kind = isset( $_POST['kind'] ) ? sanitize_key( $_POST['kind'] ) : 'hsn';

		if ( 'rate' === $kind ) {
			WGT_CSV_Export::stream(
				'my-rate-summary-' . $date_from . '-to-' . $date_to,
				WGT_Admin_Reports::rate_summary_headers(),
				WGT_Admin_Reports::rate_summary_rows( $date_from, $date_to, $vendor_id )
			);
		}

		WGT_CSV_Export::stream(
			'my-hsn-summary-' . $date_from . '-to-' . $date_to,
			WGT_Admin_Reports::hsn_summary_headers(),
			WGT_Admin_Reports::hsn_summary_rows( $date_from, $date_to, $vendor_id )
		);
	}
}
