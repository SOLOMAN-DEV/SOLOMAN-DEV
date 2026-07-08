<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WGT_Admin_Reports {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 20 );
		add_action( 'admin_post_wgt_export_gst_report', array( $this, 'export_gst_report' ) );
		add_action( 'admin_post_wgt_export_tcs_report', array( $this, 'export_tcs_report' ) );
		add_action( 'admin_post_wgt_export_gstr1', array( $this, 'export_gstr1_report' ) );
	}

	public function add_menu() {
		add_submenu_page( 'wgt-settings', __( 'GST Report', 'wcfm-gst-tcs' ), __( 'GST Report', 'wcfm-gst-tcs' ), 'manage_woocommerce', 'wgt-gst-report', array( $this, 'render_gst_report' ) );
		add_submenu_page( 'wgt-settings', __( 'TCS Report (GSTR-8)', 'wcfm-gst-tcs' ), __( 'TCS Report (GSTR-8)', 'wcfm-gst-tcs' ), 'manage_woocommerce', 'wgt-tcs-report', array( $this, 'render_tcs_report' ) );
		add_submenu_page( 'wgt-settings', __( 'GSTR-1 Export', 'wcfm-gst-tcs' ), __( 'GSTR-1 Export', 'wcfm-gst-tcs' ), 'manage_woocommerce', 'wgt-gstr1-report', array( $this, 'render_gstr1_report' ) );
	}

	/**
	 * Yields matching orders in fixed-size pages instead of loading the whole date range
	 * into memory at once (wc_get_orders with limit => -1), so a report over a large
	 * order history doesn't exhaust PHP's memory limit.
	 */
	public static function iterate_orders( $date_from, $date_to, $batch_size = 200 ) {
		$page = 1;
		do {
			$orders = wc_get_orders(
				array(
					'limit'        => $batch_size,
					'paged'        => $page,
					'status'       => array( 'processing', 'completed' ),
					'date_created' => $date_from . '...' . $date_to,
					'return'       => 'objects',
					'orderby'      => 'ID',
					'order'        => 'ASC',
				)
			);
			foreach ( $orders as $order ) {
				yield $order;
			}
			++$page;
		} while ( count( $orders ) === $batch_size );
	}

	/**
	 * Cheap order count (IDs only) for a date range, used to decide whether an export
	 * should run synchronously or be queued as a background job.
	 */
	public static function count_orders_in_range( $date_from, $date_to ) {
		$ids = wc_get_orders(
			array(
				'limit'        => -1,
				'status'       => array( 'processing', 'completed' ),
				'date_created' => $date_from . '...' . $date_to,
				'return'       => 'ids',
			)
		);
		return count( $ids );
	}

	public static function vendor_label( $vendor_id ) {
		if ( function_exists( 'wcfm_get_vendor_store_name' ) ) {
			$name = wcfm_get_vendor_store_name( $vendor_id );
			if ( $name ) {
				return $name;
			}
		}
		$name = get_the_author_meta( 'display_name', $vendor_id );
		return $name ? $name : ( 'Vendor #' . $vendor_id );
	}

	/**
	 * Splits an order item's total tax into CGST/SGST/IGST. Prefers the '_wgt_tax_type'
	 * meta stamped at checkout (immune to rate labels being renamed later); falls back to
	 * matching on the tax rate's display label for orders placed before that meta existed.
	 */
	public static function item_gst_split( $item ) {
		$split = array( 'cgst' => 0.0, 'sgst' => 0.0, 'igst' => 0.0 );
		$type  = $item->get_meta( '_wgt_tax_type' );

		if ( 'intra' === $type || 'inter' === $type ) {
			$total_tax = (float) $item->get_total_tax();
			if ( 'intra' === $type ) {
				$split['cgst'] = round( $total_tax / 2, 2 );
				$split['sgst'] = round( $total_tax - $split['cgst'], 2 );
			} else {
				$split['igst'] = $total_tax;
			}
			return $split;
		}

		$taxes = $item->get_taxes();
		if ( empty( $taxes['total'] ) ) {
			return $split;
		}
		foreach ( $taxes['total'] as $rate_id => $amount ) {
			if ( '' === $amount ) {
				continue;
			}
			$label = wc_get_rate_label( $rate_id );
			$amt   = (float) $amount;
			if ( false !== stripos( $label, 'CGST' ) ) {
				$split['cgst'] += $amt;
			} elseif ( false !== stripos( $label, 'SGST' ) ) {
				$split['sgst'] += $amt;
			} elseif ( false !== stripos( $label, 'IGST' ) ) {
				$split['igst'] += $amt;
			}
		}
		return $split;
	}

	private function get_filters() {
		return array(
			'date_from' => isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : gmdate( 'Y-m-01' ),
			'date_to'   => isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : gmdate( 'Y-m-d' ),
			'vendor_id' => isset( $_GET['vendor_id'] ) ? absint( $_GET['vendor_id'] ) : 0,
		);
	}

	/**
	 * @return array<int,array{net:float,gst:float,cgst:float,sgst:float,igst:float,order_count:int}>
	 */
	public static function gather_gst_report( $date_from, $date_to, $vendor_id = 0 ) {
		$data = array();

		foreach ( self::iterate_orders( $date_from, $date_to ) as $order ) {
			foreach ( $order->get_items() as $item ) {
				$v = $item->get_meta( '_wgt_vendor_id' );
				if ( ! $v && function_exists( 'wcfm_get_vendor_id_by_post' ) ) {
					$v = wcfm_get_vendor_id_by_post( $item->get_product_id() );
				}
				if ( ! $v ) {
					continue;
				}
				$v = (int) $v;
				if ( $vendor_id && $v !== $vendor_id ) {
					continue;
				}

				if ( ! isset( $data[ $v ] ) ) {
					$data[ $v ] = array(
						'orders' => array(),
						'net'    => 0.0,
						'gst'    => 0.0,
						'cgst'   => 0.0,
						'sgst'   => 0.0,
						'igst'   => 0.0,
					);
				}

				$data[ $v ]['orders'][ $order->get_id() ] = true;
				$data[ $v ]['net']                        += (float) $item->get_total();
				$data[ $v ]['gst']                        += (float) $item->get_total_tax();

				$split               = self::item_gst_split( $item );
				$data[ $v ]['cgst'] += $split['cgst'];
				$data[ $v ]['sgst'] += $split['sgst'];
				$data[ $v ]['igst'] += $split['igst'];
			}
		}

		foreach ( $data as &$row ) {
			$row['order_count'] = count( $row['orders'] );
			unset( $row['orders'] );
		}

		return $data;
	}

	public function render_gst_report() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$filters = $this->get_filters();
		$rows    = self::gather_gst_report( $filters['date_from'], $filters['date_to'], $filters['vendor_id'] );
		?>
		<div class="wrap wgt-admin-wrap">
			<h1><?php esc_html_e( 'Vendor GST Tax Summary', 'wcfm-gst-tcs' ); ?></h1>

			<form method="get" class="wgt-filter-form">
				<input type="hidden" name="page" value="wgt-gst-report" />
				<label><?php esc_html_e( 'From', 'wcfm-gst-tcs' ); ?> <input type="date" name="date_from" value="<?php echo esc_attr( $filters['date_from'] ); ?>" /></label>
				<label><?php esc_html_e( 'To', 'wcfm-gst-tcs' ); ?> <input type="date" name="date_to" value="<?php echo esc_attr( $filters['date_to'] ); ?>" /></label>
				<label><?php esc_html_e( 'Vendor ID', 'wcfm-gst-tcs' ); ?> <input type="number" name="vendor_id" value="<?php echo esc_attr( $filters['vendor_id'] ?: '' ); ?>" placeholder="<?php esc_attr_e( 'All', 'wcfm-gst-tcs' ); ?>" /></label>
				<?php submit_button( __( 'Filter', 'wcfm-gst-tcs' ), 'secondary', '', false ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:10px 0;">
				<input type="hidden" name="action" value="wgt_export_gst_report" />
				<?php wp_nonce_field( 'wgt_export_gst_report' ); ?>
				<input type="hidden" name="date_from" value="<?php echo esc_attr( $filters['date_from'] ); ?>" />
				<input type="hidden" name="date_to" value="<?php echo esc_attr( $filters['date_to'] ); ?>" />
				<input type="hidden" name="vendor_id" value="<?php echo esc_attr( $filters['vendor_id'] ); ?>" />
				<?php submit_button( __( 'Export CSV', 'wcfm-gst-tcs' ), 'primary', '', false ); ?>
			</form>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Vendor', 'wcfm-gst-tcs' ); ?></th>
						<th><?php esc_html_e( 'Orders', 'wcfm-gst-tcs' ); ?></th>
						<th><?php esc_html_e( 'Net Taxable Value', 'wcfm-gst-tcs' ); ?></th>
						<th><?php esc_html_e( 'CGST', 'wcfm-gst-tcs' ); ?></th>
						<th><?php esc_html_e( 'SGST', 'wcfm-gst-tcs' ); ?></th>
						<th><?php esc_html_e( 'IGST', 'wcfm-gst-tcs' ); ?></th>
						<th><?php esc_html_e( 'Total GST', 'wcfm-gst-tcs' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="7"><?php esc_html_e( 'No vendor sales in this period.', 'wcfm-gst-tcs' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $rows as $vendor_id => $row ) : ?>
							<tr>
								<td><?php echo esc_html( self::vendor_label( $vendor_id ) ); ?></td>
								<td><?php echo esc_html( $row['order_count'] ); ?></td>
								<td><?php echo wc_price( $row['net'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
								<td><?php echo wc_price( $row['cgst'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
								<td><?php echo wc_price( $row['sgst'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
								<td><?php echo wc_price( $row['igst'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
								<td><?php echo wc_price( $row['gst'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public function export_gst_report() {
		check_admin_referer( 'wgt_export_gst_report' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wcfm-gst-tcs' ) );
		}

		$date_from = isset( $_POST['date_from'] ) ? sanitize_text_field( wp_unslash( $_POST['date_from'] ) ) : gmdate( 'Y-m-01' );
		$date_to   = isset( $_POST['date_to'] ) ? sanitize_text_field( wp_unslash( $_POST['date_to'] ) ) : gmdate( 'Y-m-d' );
		$vendor_id = isset( $_POST['vendor_id'] ) ? absint( $_POST['vendor_id'] ) : 0;

		if ( WGT_Export_Job::instance()->maybe_queue( 'gst_report', $date_from, $date_to, $vendor_id ) ) {
			wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=wgt-gst-report' ) );
			exit;
		}

		$rows = self::gather_gst_report( $date_from, $date_to, $vendor_id );
		$csv  = array();

		foreach ( $rows as $vendor_id => $row ) {
			$csv[] = array(
				self::vendor_label( $vendor_id ),
				$vendor_id,
				$row['order_count'],
				number_format( $row['net'], 2, '.', '' ),
				number_format( $row['cgst'], 2, '.', '' ),
				number_format( $row['sgst'], 2, '.', '' ),
				number_format( $row['igst'], 2, '.', '' ),
				number_format( $row['gst'], 2, '.', '' ),
			);
		}

		WGT_CSV_Export::stream(
			'gst-report-' . $date_from . '-to-' . $date_to,
			array( 'Vendor', 'Vendor ID', 'Orders', 'Net Taxable Value', 'CGST', 'SGST', 'IGST', 'Total GST' ),
			$csv
		);
	}

	public function render_tcs_report() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$filters = $this->get_filters();
		$rows    = WGT_TCS_Engine::get_ledger_rows(
			array(
				'date_from' => $filters['date_from'],
				'date_to'   => $filters['date_to'],
				'vendor_id' => $filters['vendor_id'],
			)
		);

		$by_vendor = array();
		foreach ( $rows as $row ) {
			$vid = (int) $row->vendor_id;
			if ( ! isset( $by_vendor[ $vid ] ) ) {
				$by_vendor[ $vid ] = array( 'net' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0, 'igst' => 0.0, 'tcs' => 0.0, 'orders' => 0 );
			}
			$by_vendor[ $vid ]['net']    += (float) $row->net_taxable_value;
			$by_vendor[ $vid ]['cgst']   += (float) $row->cgst_tcs;
			$by_vendor[ $vid ]['sgst']   += (float) $row->sgst_tcs;
			$by_vendor[ $vid ]['igst']   += (float) $row->igst_tcs;
			$by_vendor[ $vid ]['tcs']    += (float) $row->tcs_amount;
			++$by_vendor[ $vid ]['orders'];
		}
		?>
		<div class="wrap wgt-admin-wrap">
			<h1><?php esc_html_e( 'GST-TCS Report (GSTR-8 style)', 'wcfm-gst-tcs' ); ?></h1>
			<p><?php esc_html_e( 'Vendor-wise net taxable value and TCS collected — the figures needed to file GSTR-8.', 'wcfm-gst-tcs' ); ?></p>

			<form method="get" class="wgt-filter-form">
				<input type="hidden" name="page" value="wgt-tcs-report" />
				<label><?php esc_html_e( 'From', 'wcfm-gst-tcs' ); ?> <input type="date" name="date_from" value="<?php echo esc_attr( $filters['date_from'] ); ?>" /></label>
				<label><?php esc_html_e( 'To', 'wcfm-gst-tcs' ); ?> <input type="date" name="date_to" value="<?php echo esc_attr( $filters['date_to'] ); ?>" /></label>
				<label><?php esc_html_e( 'Vendor ID', 'wcfm-gst-tcs' ); ?> <input type="number" name="vendor_id" value="<?php echo esc_attr( $filters['vendor_id'] ?: '' ); ?>" placeholder="<?php esc_attr_e( 'All', 'wcfm-gst-tcs' ); ?>" /></label>
				<?php submit_button( __( 'Filter', 'wcfm-gst-tcs' ), 'secondary', '', false ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:10px 0;">
				<input type="hidden" name="action" value="wgt_export_tcs_report" />
				<?php wp_nonce_field( 'wgt_export_tcs_report' ); ?>
				<input type="hidden" name="date_from" value="<?php echo esc_attr( $filters['date_from'] ); ?>" />
				<input type="hidden" name="date_to" value="<?php echo esc_attr( $filters['date_to'] ); ?>" />
				<input type="hidden" name="vendor_id" value="<?php echo esc_attr( $filters['vendor_id'] ); ?>" />
				<?php submit_button( __( 'Export CSV', 'wcfm-gst-tcs' ), 'primary', '', false ); ?>
			</form>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Vendor', 'wcfm-gst-tcs' ); ?></th>
						<th><?php esc_html_e( 'GSTIN', 'wcfm-gst-tcs' ); ?></th>
						<th><?php esc_html_e( 'Orders', 'wcfm-gst-tcs' ); ?></th>
						<th><?php esc_html_e( 'Net Taxable Value', 'wcfm-gst-tcs' ); ?></th>
						<th><?php esc_html_e( 'CGST TCS', 'wcfm-gst-tcs' ); ?></th>
						<th><?php esc_html_e( 'SGST TCS', 'wcfm-gst-tcs' ); ?></th>
						<th><?php esc_html_e( 'IGST TCS', 'wcfm-gst-tcs' ); ?></th>
						<th><?php esc_html_e( 'Total TCS', 'wcfm-gst-tcs' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $by_vendor ) ) : ?>
						<tr><td colspan="8"><?php esc_html_e( 'No TCS collected in this period.', 'wcfm-gst-tcs' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $by_vendor as $vendor_id => $row ) : ?>
							<?php $gst = WGT_Vendor_Settings::get_vendor_gst( $vendor_id ); ?>
							<tr>
								<td><?php echo esc_html( self::vendor_label( $vendor_id ) ); ?></td>
								<td><?php echo esc_html( $gst['gstin'] ? $gst['gstin'] : '—' ); ?></td>
								<td><?php echo esc_html( $row['orders'] ); ?></td>
								<td><?php echo wc_price( $row['net'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
								<td><?php echo wc_price( $row['cgst'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
								<td><?php echo wc_price( $row['sgst'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
								<td><?php echo wc_price( $row['igst'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
								<td><?php echo wc_price( $row['tcs'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public function export_tcs_report() {
		check_admin_referer( 'wgt_export_tcs_report' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wcfm-gst-tcs' ) );
		}

		$date_from = isset( $_POST['date_from'] ) ? sanitize_text_field( wp_unslash( $_POST['date_from'] ) ) : gmdate( 'Y-m-01' );
		$date_to   = isset( $_POST['date_to'] ) ? sanitize_text_field( wp_unslash( $_POST['date_to'] ) ) : gmdate( 'Y-m-d' );
		$vendor_id = isset( $_POST['vendor_id'] ) ? absint( $_POST['vendor_id'] ) : 0;

		$rows = WGT_TCS_Engine::get_ledger_rows(
			array(
				'date_from' => $date_from,
				'date_to'   => $date_to,
				'vendor_id' => $vendor_id,
			)
		);

		$csv = array();
		foreach ( $rows as $row ) {
			$gst = WGT_Vendor_Settings::get_vendor_gst( $row->vendor_id );
			$csv[] = array(
				$row->order_id,
				self::vendor_label( $row->vendor_id ),
				$gst['gstin'],
				$row->order_date,
				number_format( (float) $row->net_taxable_value, 2, '.', '' ),
				number_format( (float) $row->cgst_tcs, 2, '.', '' ),
				number_format( (float) $row->sgst_tcs, 2, '.', '' ),
				number_format( (float) $row->igst_tcs, 2, '.', '' ),
				number_format( (float) $row->tcs_amount, 2, '.', '' ),
				$row->financial_year,
				$row->status,
			);
		}

		WGT_CSV_Export::stream(
			'gstr8-tcs-report-' . $date_from . '-to-' . $date_to,
			array( 'Order ID', 'Vendor', 'Vendor GSTIN', 'Order Date', 'Net Taxable Value', 'CGST TCS', 'SGST TCS', 'IGST TCS', 'Total TCS', 'Financial Year', 'Status' ),
			$csv
		);
	}

	/**
	 * Invoice-level rows (one per order line item) with everything a vendor/CA needs to
	 * populate GSTR-1's B2B (buyer GSTIN present) and B2CS (no buyer GSTIN) sections by
	 * hand or via their own filing tool. This is a convenience export, not the GSTN
	 * portal's JSON upload format.
	 */
	/**
	 * Column headers for gather_gstr1_rows(), kept alongside it so every caller
	 * (admin export, vendor export, monthly email) stays in sync with the row shape.
	 */
	public static function gstr1_headers() {
		return array(
			'Order ID', 'Order Date', 'Order Status', 'Payment Method',
			'Vendor', 'Vendor GSTIN', 'Type',
			'Customer Name', 'Customer Email', 'Customer Phone',
			'Billing Address', 'Shipping Address',
			'Buyer Company', 'Buyer GSTIN', 'Place of Supply',
			'Product Name', 'SKU', 'HSN/SAC', 'Quantity', 'Unit Price',
			'Item Subtotal', 'Taxable Value', 'GST Rate', 'CGST', 'SGST', 'IGST', 'Line Total (Incl. Tax)',
			'Order Subtotal', 'Order Discount', 'Order Shipping', 'Order Total',
		);
	}

	/**
	 * One row per order line item, with full order/customer/product context — not just
	 * the tax figures — so this doubles as a complete sales report, not only a GST filing
	 * aid. Order-level fields (customer, addresses, totals) repeat on every item row of
	 * that order, which is the normal shape for a flat per-line-item export.
	 */
	public static function gather_gstr1_rows( $date_from, $date_to, $vendor_id = 0 ) {
		$states = WGT_States::get_indian_states();
		$rows   = array();

		foreach ( self::iterate_orders( $date_from, $date_to ) as $order ) {
			$is_b2b          = 'yes' === $order->get_meta( '_billing_is_business' );
			$buyer_gstin     = $is_b2b ? $order->get_meta( '_billing_gstin' ) : '';
			$buyer_company   = $is_b2b ? $order->get_billing_company() : '';
			$buyer_state     = $order->get_billing_state();
			$place_of_supply = isset( $states[ $buyer_state ] ) ? $states[ $buyer_state ] : $buyer_state;

			$customer_name    = $order->get_formatted_billing_full_name();
			$customer_email   = $order->get_billing_email();
			$customer_phone   = $order->get_billing_phone();
			$billing_address  = $order->get_formatted_billing_address( '' );
			$shipping_address = $order->get_formatted_shipping_address( '' );
			$payment_method   = $order->get_payment_method_title();
			$order_status     = wc_get_order_status_name( $order->get_status() );
			$order_date       = $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : '';

			foreach ( $order->get_items() as $item ) {
				$v = $item->get_meta( '_wgt_vendor_id' );
				if ( ! $v && function_exists( 'wcfm_get_vendor_id_by_post' ) ) {
					$v = wcfm_get_vendor_id_by_post( $item->get_product_id() );
				}
				if ( ! $v ) {
					continue;
				}
				$v = (int) $v;
				if ( $vendor_id && $v !== $vendor_id ) {
					continue;
				}

				$split = self::item_gst_split( $item );
				$cgst  = $split['cgst'];
				$sgst  = $split['sgst'];
				$igst  = $split['igst'];

				$vendor_gst    = WGT_Vendor_Settings::get_vendor_gst( $v );
				$product       = $item->get_product();
				$sku           = $product ? $product->get_sku() : '';
				$qty           = $item->get_quantity();
				$item_total    = (float) $item->get_total();
				$item_subtotal = (float) $item->get_subtotal();
				$unit_price    = $qty ? round( $item_total / $qty, 2 ) : 0;

				$rows[] = array(
					$order->get_id(),
					$order_date,
					$order_status,
					$payment_method,
					self::vendor_label( $v ),
					$vendor_gst['gstin'],
					$is_b2b ? 'B2B' : 'B2C',
					$customer_name,
					$customer_email,
					$customer_phone,
					$billing_address,
					$shipping_address,
					$buyer_company,
					$buyer_gstin,
					$place_of_supply,
					$item->get_name(),
					$sku,
					$item->get_meta( '_wgt_hsn_code' ),
					$qty,
					number_format( $unit_price, 2, '.', '' ),
					number_format( $item_subtotal, 2, '.', '' ),
					number_format( $item_total, 2, '.', '' ),
					$item->get_meta( '_wgt_gst_rate' ),
					number_format( $cgst, 2, '.', '' ),
					number_format( $sgst, 2, '.', '' ),
					number_format( $igst, 2, '.', '' ),
					number_format( $item_total + $cgst + $sgst + $igst, 2, '.', '' ),
					number_format( (float) $order->get_subtotal(), 2, '.', '' ),
					number_format( (float) $order->get_total_discount(), 2, '.', '' ),
					number_format( (float) $order->get_shipping_total(), 2, '.', '' ),
					number_format( (float) $order->get_total(), 2, '.', '' ),
				);
			}
		}

		return $rows;
	}

	public function render_gstr1_report() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$filters = $this->get_filters();
		?>
		<div class="wrap wgt-admin-wrap">
			<h1><?php esc_html_e( 'GSTR-1 Style Invoice Export', 'wcfm-gst-tcs' ); ?></h1>
			<p><?php esc_html_e( 'One row per order line item — full order details (customer, addresses, payment method, product, quantity, pricing) plus vendor, buyer GSTIN (if a business purchase), place of supply, HSN and the tax split — a complete sales report as well as a GSTR-1 filing aid. This is a convenience export, not the GSTN portal\'s JSON upload format.', 'wcfm-gst-tcs' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wgt_export_gstr1" />
				<?php wp_nonce_field( 'wgt_export_gstr1' ); ?>
				<label><?php esc_html_e( 'From', 'wcfm-gst-tcs' ); ?> <input type="date" name="date_from" value="<?php echo esc_attr( $filters['date_from'] ); ?>" /></label>
				<label><?php esc_html_e( 'To', 'wcfm-gst-tcs' ); ?> <input type="date" name="date_to" value="<?php echo esc_attr( $filters['date_to'] ); ?>" /></label>
				<label><?php esc_html_e( 'Vendor ID', 'wcfm-gst-tcs' ); ?> <input type="number" name="vendor_id" value="<?php echo esc_attr( $filters['vendor_id'] ?: '' ); ?>" placeholder="<?php esc_attr_e( 'All', 'wcfm-gst-tcs' ); ?>" /></label>
				<?php submit_button( __( 'Export CSV', 'wcfm-gst-tcs' ), 'primary', '', false ); ?>
			</form>
		</div>
		<?php
	}

	public function export_gstr1_report() {
		check_admin_referer( 'wgt_export_gstr1' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wcfm-gst-tcs' ) );
		}

		$date_from = isset( $_POST['date_from'] ) ? sanitize_text_field( wp_unslash( $_POST['date_from'] ) ) : gmdate( 'Y-m-01' );
		$date_to   = isset( $_POST['date_to'] ) ? sanitize_text_field( wp_unslash( $_POST['date_to'] ) ) : gmdate( 'Y-m-d' );
		$vendor_id = isset( $_POST['vendor_id'] ) ? absint( $_POST['vendor_id'] ) : 0;

		if ( WGT_Export_Job::instance()->maybe_queue( 'gstr1', $date_from, $date_to, $vendor_id ) ) {
			wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=wgt-gstr1-report' ) );
			exit;
		}

		$rows = self::gather_gstr1_rows( $date_from, $date_to, $vendor_id );

		WGT_CSV_Export::stream(
			'gstr1-export-' . $date_from . '-to-' . $date_to,
			self::gstr1_headers(),
			$rows
		);
	}
}
