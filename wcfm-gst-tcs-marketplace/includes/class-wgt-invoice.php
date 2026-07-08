<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Surfaces HSN codes and vendor GSTIN on order tables/emails, and provides a
 * simple printable GST invoice. The CGST/SGST/IGST breakdown itself needs no
 * special handling here — it already appears in order tables/emails because
 * WGT_Tax_Engine feeds it through WooCommerce's own tax line items.
 */
class WGT_Invoice {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'woocommerce_order_item_name', array( $this, 'append_hsn_to_item_name' ), 10, 2 );

		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_vendor_gst_block' ) );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'render_vendor_gst_block' ) );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_invoice_link' ), 5 );

		add_action( 'add_meta_boxes', array( $this, 'add_admin_meta_box' ) );

		add_action( 'init', array( $this, 'add_invoice_endpoint' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render_invoice' ) );
	}

	public function append_hsn_to_item_name( $item_name, $item ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $item_name;
		}
		$hsn = $item->get_meta( '_wgt_hsn_code' );
		if ( $hsn ) {
			$item_name .= ' <span class="wgt-hsn">(' . esc_html__( 'HSN', 'wcfm-gst-tcs' ) . ': ' . esc_html( $hsn ) . ')</span>';
		}
		return $item_name;
	}

	private function get_order_vendors( $order ) {
		$vendor_ids = array();
		foreach ( $order->get_items() as $item ) {
			$v = $item->get_meta( '_wgt_vendor_id' );
			if ( $v ) {
				$vendor_ids[ (int) $v ] = true;
			}
		}
		return array_keys( $vendor_ids );
	}

	public function render_vendor_gst_block( $order ) {
		if ( is_a( $order, 'WC_Order' ) === false ) {
			return;
		}

		$vendor_ids = $this->get_order_vendors( $order );
		if ( empty( $vendor_ids ) ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Seller GST Details', 'wcfm-gst-tcs' ) . '</h2>';
		echo '<table class="shop_table wgt-vendor-gst-table"><thead><tr><th>' . esc_html__( 'Seller', 'wcfm-gst-tcs' ) . '</th><th>' . esc_html__( 'GSTIN', 'wcfm-gst-tcs' ) . '</th><th>' . esc_html__( 'State', 'wcfm-gst-tcs' ) . '</th></tr></thead><tbody>';

		foreach ( $vendor_ids as $vendor_id ) {
			$gst    = WGT_Vendor_Settings::get_vendor_gst( $vendor_id );
			$states = WGT_States::get_indian_states();
			$state  = isset( $states[ $gst['state'] ] ) ? $states[ $gst['state'] ] : $gst['state'];

			echo '<tr>';
			echo '<td>' . esc_html( WGT_Admin_Reports::vendor_label( $vendor_id ) ) . '</td>';
			echo '<td>' . esc_html( $gst['gstin'] ? $gst['gstin'] : __( 'Unregistered', 'wcfm-gst-tcs' ) ) . '</td>';
			echo '<td>' . esc_html( $state ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	public function render_invoice_link( $order ) {
		if ( is_a( $order, 'WC_Order' ) === false ) {
			return;
		}
		$url = add_query_arg(
			array(
				'wgt_invoice' => $order->get_id(),
				'key'         => $order->get_order_key(),
			),
			home_url( '/' )
		);
		echo '<p class="wgt-invoice-link"><a class="button" target="_blank" href="' . esc_url( $url ) . '">' . esc_html__( 'View / Print GST Invoice', 'wcfm-gst-tcs' ) . '</a></p>';
	}

	public function add_admin_meta_box() {
		add_meta_box( 'wgt_order_gst_summary', __( 'GST & TCS Summary', 'wcfm-gst-tcs' ), array( $this, 'render_admin_meta_box' ), wc_get_page_screen_id( 'shop-order' ), 'side', 'default' );
	}

	public function render_admin_meta_box( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}

		$totals = WGT_TCS_Engine::get_order_vendor_totals( $order );
		if ( empty( $totals ) ) {
			echo '<p>' . esc_html__( 'No vendor line items found on this order.', 'wcfm-gst-tcs' ) . '</p>';
			return;
		}

		$settings = WGT_Admin_Settings::get_settings();
		echo '<table class="wgt-order-summary">';
		foreach ( $totals as $vendor_id => $t ) {
			$tcs = round( $t['net'] * (float) $settings['tcs_rate'] / 100, 2 );
			echo '<tr><th colspan="2">' . esc_html( WGT_Admin_Reports::vendor_label( $vendor_id ) ) . '</th></tr>';
			echo '<tr><td>' . esc_html__( 'Net Taxable', 'wcfm-gst-tcs' ) . '</td><td>' . wc_price( $t['net'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<tr><td>' . esc_html__( 'GST', 'wcfm-gst-tcs' ) . '</td><td>' . wc_price( $t['gst'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<tr><td>' . esc_html__( 'Est. TCS', 'wcfm-gst-tcs' ) . '</td><td>' . wc_price( $tcs ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</table>';
	}

	public function add_invoice_endpoint() {
		add_rewrite_endpoint( 'wgt-invoice', EP_ROOT );
	}

	public function maybe_render_invoice() {
		if ( ! isset( $_GET['wgt_invoice'] ) ) {
			return;
		}

		$order_id = absint( $_GET['wgt_invoice'] );
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			wp_die( esc_html__( 'Invoice not found.', 'wcfm-gst-tcs' ) );
		}

		$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		if ( ! $this->current_user_can_view_invoice( $order, $key ) ) {
			wp_die( esc_html__( 'You do not have permission to view this invoice.', 'wcfm-gst-tcs' ) );
		}

		$this->output_invoice_html( $order );
		exit;
	}

	private function current_user_can_view_invoice( $order, $key ) {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}
		if ( $order->get_order_key() === $key ) {
			return true;
		}
		$user_id = get_current_user_id();
		if ( $user_id && (int) $order->get_customer_id() === $user_id ) {
			return true;
		}
		if ( $user_id && in_array( $user_id, $this->get_order_vendors( $order ), true ) ) {
			return true;
		}
		return false;
	}

	private function output_invoice_html( $order ) {
		$settings = WGT_Admin_Settings::get_settings();
		$totals   = WGT_TCS_Engine::get_order_vendor_totals( $order );
		?>
		<!DOCTYPE html>
		<html>
		<head>
			<meta charset="utf-8" />
			<title><?php echo esc_html( sprintf( __( 'GST Invoice #%s', 'wcfm-gst-tcs' ), $order->get_order_number() ) ); ?></title>
			<style>
				body{font-family:Arial,Helvetica,sans-serif;color:#222;margin:2em;}
				h1{font-size:1.4em;}
				table{width:100%;border-collapse:collapse;margin-bottom:1.5em;}
				th,td{border:1px solid #ccc;padding:6px 8px;text-align:left;font-size:.9em;}
				th{background:#f5f5f5;}
				.wgt-totals td{text-align:right;}
				.wgt-print{margin-bottom:1em;}
				@media print{.wgt-print{display:none;}}
			</style>
		</head>
		<body>
			<div class="wgt-print"><button onclick="window.print()"><?php esc_html_e( 'Print', 'wcfm-gst-tcs' ); ?></button></div>

			<h1><?php echo esc_html( $settings['company_legal_name'] ); ?></h1>
			<p>
				<?php if ( $settings['company_gstin'] ) : ?>
					<?php echo esc_html__( 'Marketplace Operator GSTIN:', 'wcfm-gst-tcs' ) . ' ' . esc_html( $settings['company_gstin'] ); ?><br/>
				<?php endif; ?>
				<?php echo esc_html__( 'Invoice for Order', 'wcfm-gst-tcs' ) . ' #' . esc_html( $order->get_order_number() ) . ' — ' . esc_html( wc_format_datetime( $order->get_date_created() ) ); ?>
			</p>

			<h2><?php esc_html_e( 'Bill To', 'wcfm-gst-tcs' ); ?></h2>
			<p>
				<?php echo esc_html( $order->get_formatted_billing_full_name() ); ?><br/>
				<?php echo wp_kses_post( $order->get_formatted_billing_address() ); ?>
				<?php if ( 'yes' === $order->get_meta( '_billing_is_business' ) ) : ?>
					<?php if ( $order->get_billing_company() ) : ?>
						<br/><?php echo esc_html__( 'Company:', 'wcfm-gst-tcs' ) . ' ' . esc_html( $order->get_billing_company() ); ?>
					<?php endif; ?>
					<?php if ( $order->get_meta( '_billing_gstin' ) ) : ?>
						<br/><?php echo esc_html__( 'Buyer GSTIN:', 'wcfm-gst-tcs' ) . ' ' . esc_html( $order->get_meta( '_billing_gstin' ) ); ?>
					<?php endif; ?>
				<?php endif; ?>
			</p>

			<?php foreach ( $totals as $vendor_id => $t ) : ?>
				<?php
				$gst    = WGT_Vendor_Settings::get_vendor_gst( $vendor_id );
				$states = WGT_States::get_indian_states();
				$state  = isset( $states[ $gst['state'] ] ) ? $states[ $gst['state'] ] : $gst['state'];
				?>
				<h2><?php echo esc_html( WGT_Admin_Reports::vendor_label( $vendor_id ) ); ?></h2>
				<p>
					<?php echo esc_html__( 'GSTIN:', 'wcfm-gst-tcs' ) . ' ' . esc_html( $gst['gstin'] ? $gst['gstin'] : __( 'Unregistered', 'wcfm-gst-tcs' ) ); ?><br/>
					<?php echo esc_html__( 'State of Supply:', 'wcfm-gst-tcs' ) . ' ' . esc_html( $state ); ?>
				</p>

				<table>
					<thead>
						<tr>
							<th><?php esc_html_e( 'Item', 'wcfm-gst-tcs' ); ?></th>
							<th><?php esc_html_e( 'HSN/SAC', 'wcfm-gst-tcs' ); ?></th>
							<th><?php esc_html_e( 'Qty', 'wcfm-gst-tcs' ); ?></th>
							<th><?php esc_html_e( 'Taxable Value', 'wcfm-gst-tcs' ); ?></th>
							<th><?php esc_html_e( 'GST Rate', 'wcfm-gst-tcs' ); ?></th>
							<th><?php esc_html_e( 'GST Amount', 'wcfm-gst-tcs' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $order->get_items() as $item ) : ?>
							<?php
							if ( (int) $item->get_meta( '_wgt_vendor_id' ) !== (int) $vendor_id ) {
								continue;
							}
							?>
							<tr>
								<td><?php echo esc_html( $item->get_name() ); ?></td>
								<td><?php echo esc_html( $item->get_meta( '_wgt_hsn_code' ) ); ?></td>
								<td><?php echo esc_html( $item->get_quantity() ); ?></td>
								<td><?php echo wp_kses_post( wc_price( $item->get_total() ) ); ?></td>
								<td><?php echo esc_html( $item->get_meta( '_wgt_gst_rate' ) ); ?>%</td>
								<td><?php echo wp_kses_post( wc_price( $item->get_total_tax() ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
					<tfoot class="wgt-totals">
						<tr><td colspan="5"><?php esc_html_e( 'Net Taxable Value', 'wcfm-gst-tcs' ); ?></td><td><?php echo wp_kses_post( wc_price( $t['net'] ) ); ?></td></tr>
						<tr><td colspan="5"><?php esc_html_e( 'Total GST', 'wcfm-gst-tcs' ); ?></td><td><?php echo wp_kses_post( wc_price( $t['gst'] ) ); ?></td></tr>
					</tfoot>
				</table>
			<?php endforeach; ?>

			<p><em><?php esc_html_e( 'This is a system-generated GST invoice.', 'wcfm-gst-tcs' ); ?></em></p>
		</body>
		</html>
		<?php
	}
}
