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

	const EINVOICE_META = '_wgt_einvoice';

	private function __construct() {
		add_filter( 'woocommerce_order_item_name', array( $this, 'append_hsn_to_item_name' ), 10, 2 );

		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_vendor_gst_block' ) );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'render_vendor_gst_block' ) );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_invoice_link' ), 5 );

		add_action( 'add_meta_boxes', array( $this, 'add_admin_meta_box' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save_einvoice_fields' ) );

		add_action( 'init', array( $this, 'add_invoice_endpoint' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render_invoice' ) );
	}

	/**
	 * E-invoicing (IRN/QR) applies to vendors above the government's e-invoicing
	 * turnover threshold, generated on the govt e-invoice portal outside this plugin.
	 * These fields just record what came back so it can be printed on the invoice.
	 */
	private function get_einvoice( $order, $vendor_id ) {
		$all = $order->get_meta( self::EINVOICE_META );
		$all = is_array( $all ) ? $all : array();
		$defaults = array(
			'irn'      => '',
			'ack_no'   => '',
			'ack_date' => '',
			'qr'       => '',
		);
		return wp_parse_args( isset( $all[ $vendor_id ] ) ? $all[ $vendor_id ] : array(), $defaults );
	}

	public function save_einvoice_fields( $order_id ) {
		if ( ! isset( $_POST['wgt_einvoice_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wgt_einvoice_nonce'] ) ), 'wgt_save_einvoice' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || empty( $_POST['wgt_einvoice'] ) || ! is_array( $_POST['wgt_einvoice'] ) ) {
			return;
		}

		$all = array();
		foreach ( wp_unslash( $_POST['wgt_einvoice'] ) as $vendor_id => $fields ) {
			$vendor_id = absint( $vendor_id );
			if ( ! $vendor_id || ! is_array( $fields ) ) {
				continue;
			}
			$all[ $vendor_id ] = array(
				'irn'      => isset( $fields['irn'] ) ? sanitize_text_field( $fields['irn'] ) : '',
				'ack_no'   => isset( $fields['ack_no'] ) ? sanitize_text_field( $fields['ack_no'] ) : '',
				'ack_date' => isset( $fields['ack_date'] ) ? sanitize_text_field( $fields['ack_date'] ) : '',
				'qr'       => isset( $fields['qr'] ) ? sanitize_textarea_field( $fields['qr'] ) : '',
			);
		}

		$order->update_meta_data( self::EINVOICE_META, $all );
		$order->save();
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
		$args = array(
			'wgt_invoice' => $order->get_id(),
			'key'         => $order->get_order_key(),
		);
		$view_url = add_query_arg( $args, home_url( '/' ) );
		$pdf_url  = add_query_arg( array_merge( $args, array( 'format' => 'pdf' ) ), home_url( '/' ) );

		echo '<p class="wgt-invoice-link">';
		echo '<a class="button" target="_blank" href="' . esc_url( $view_url ) . '">' . esc_html__( 'View / Print GST Invoice', 'wcfm-gst-tcs' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( $pdf_url ) . '">' . esc_html__( 'Download PDF', 'wcfm-gst-tcs' ) . '</a>';
		echo '</p>';
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
		wp_nonce_field( 'wgt_save_einvoice', 'wgt_einvoice_nonce' );

		echo '<table class="wgt-order-summary">';
		foreach ( $totals as $vendor_id => $t ) {
			$tcs = round( $t['net'] * (float) $settings['tcs_rate'] / 100, 2 );
			$ei  = $this->get_einvoice( $order, $vendor_id );

			echo '<tr><th colspan="2">' . esc_html( WGT_Admin_Reports::vendor_label( $vendor_id ) ) . '</th></tr>';
			echo '<tr><td>' . esc_html__( 'Net Taxable', 'wcfm-gst-tcs' ) . '</td><td>' . wc_price( $t['net'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<tr><td>' . esc_html__( 'GST', 'wcfm-gst-tcs' ) . '</td><td>' . wc_price( $t['gst'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<tr><td>' . esc_html__( 'Est. TCS', 'wcfm-gst-tcs' ) . '</td><td>' . wc_price( $tcs ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput

			echo '<tr><td colspan="2"><em>' . esc_html__( 'E-Invoice (optional, from GST e-invoice portal)', 'wcfm-gst-tcs' ) . '</em></td></tr>';
			echo '<tr><td>' . esc_html__( 'IRN', 'wcfm-gst-tcs' ) . '</td><td><input type="text" style="width:100%" name="wgt_einvoice[' . esc_attr( $vendor_id ) . '][irn]" value="' . esc_attr( $ei['irn'] ) . '" /></td></tr>';
			echo '<tr><td>' . esc_html__( 'Ack No', 'wcfm-gst-tcs' ) . '</td><td><input type="text" style="width:100%" name="wgt_einvoice[' . esc_attr( $vendor_id ) . '][ack_no]" value="' . esc_attr( $ei['ack_no'] ) . '" /></td></tr>';
			echo '<tr><td>' . esc_html__( 'Ack Date', 'wcfm-gst-tcs' ) . '</td><td><input type="date" style="width:100%" name="wgt_einvoice[' . esc_attr( $vendor_id ) . '][ack_date]" value="' . esc_attr( $ei['ack_date'] ) . '" /></td></tr>';
			echo '<tr><td>' . esc_html__( 'QR (text)', 'wcfm-gst-tcs' ) . '</td><td><textarea style="width:100%" rows="2" name="wgt_einvoice[' . esc_attr( $vendor_id ) . '][qr]">' . esc_textarea( $ei['qr'] ) . '</textarea></td></tr>';
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

		$format = isset( $_GET['format'] ) ? sanitize_text_field( wp_unslash( $_GET['format'] ) ) : 'html';

		if ( 'pdf' === $format ) {
			$this->stream_invoice_pdf( $order );
		} else {
			echo $this->get_invoice_html( $order ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from already-escaped fragments.
		}
		exit;
	}

	private function stream_invoice_pdf( $order ) {
		if ( ! class_exists( 'Dompdf\\Dompdf' ) ) {
			wp_die( esc_html__( 'PDF generation is not available on this install.', 'wcfm-gst-tcs' ) );
		}

		$dompdf = new \Dompdf\Dompdf( array( 'isRemoteEnabled' => false ) );
		$dompdf->loadHtml( $this->get_invoice_html( $order, true ) );
		$dompdf->setPaper( 'A4' );
		$dompdf->render();

		nocache_headers();
		$dompdf->stream(
			'gst-invoice-order-' . $order->get_order_number() . '.pdf',
			array( 'Attachment' => true )
		);
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

	/**
	 * Builds the GST invoice markup. Shared by the browser "View / Print" endpoint and
	 * the Dompdf-rendered "Download PDF" endpoint, so both always stay in sync.
	 */
	private function get_invoice_html( $order, $for_pdf = false ) {
		$settings = WGT_Admin_Settings::get_settings();
		$totals   = WGT_TCS_Engine::get_order_vendor_totals( $order );
		ob_start();
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
			<?php if ( ! $for_pdf ) : ?>
				<div class="wgt-print">
					<button onclick="window.print()"><?php esc_html_e( 'Print', 'wcfm-gst-tcs' ); ?></button>
					<a class="button" href="<?php echo esc_url( add_query_arg( 'format', 'pdf' ) ); ?>"><?php esc_html_e( 'Download PDF', 'wcfm-gst-tcs' ); ?></a>
				</div>
			<?php endif; ?>

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
				<?php $ei = $this->get_einvoice( $order, $vendor_id ); ?>
				<h2><?php echo esc_html( WGT_Admin_Reports::vendor_label( $vendor_id ) ); ?></h2>
				<p>
					<?php echo esc_html__( 'GSTIN:', 'wcfm-gst-tcs' ) . ' ' . esc_html( $gst['gstin'] ? $gst['gstin'] : __( 'Unregistered', 'wcfm-gst-tcs' ) ); ?><br/>
					<?php echo esc_html__( 'State of Supply:', 'wcfm-gst-tcs' ) . ' ' . esc_html( $state ); ?>
					<?php if ( $ei['irn'] ) : ?>
						<br/><?php echo esc_html__( 'IRN:', 'wcfm-gst-tcs' ) . ' ' . esc_html( $ei['irn'] ); ?>
					<?php endif; ?>
					<?php if ( $ei['ack_no'] ) : ?>
						<br/><?php echo esc_html__( 'Ack No:', 'wcfm-gst-tcs' ) . ' ' . esc_html( $ei['ack_no'] ) . ( $ei['ack_date'] ? ' (' . esc_html( $ei['ack_date'] ) . ')' : '' ); ?>
					<?php endif; ?>
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
				<?php if ( $ei['qr'] ) : ?>
					<p style="font-size:.75em;word-break:break-all;"><?php esc_html_e( 'E-Invoice QR:', 'wcfm-gst-tcs' ); ?> <code><?php echo esc_html( $ei['qr'] ); ?></code></p>
				<?php endif; ?>
			<?php endforeach; ?>

			<p><em><?php esc_html_e( 'This is a system-generated GST invoice.', 'wcfm-gst-tcs' ); ?></em></p>
		</body>
		</html>
		<?php
		return ob_get_clean();
	}
}
