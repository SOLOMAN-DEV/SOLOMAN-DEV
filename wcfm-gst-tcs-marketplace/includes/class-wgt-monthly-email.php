<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Automated monthly "sales & GST" email to vendors, for their own GST filing: a plain-text
 * summary (orders, net taxable value, CGST/SGST/IGST, TCS if enabled) plus a detailed
 * line-item invoice CSV attachment. Scheduled via WooCommerce's bundled Action Scheduler
 * using a real cron expression (day-of-month), which — unlike wp_schedule_event — supports
 * "run on day N of every month" natively instead of approximating it with a daily poll.
 */
class WGT_Monthly_Email {

	const CRON_HOOK = 'wgt_monthly_vendor_email';
	const AS_GROUP   = 'wgt-monthly-email';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'maybe_schedule' ), 20 );
		add_action( self::CRON_HOOK, array( $this, 'run' ) );
		add_action( 'admin_post_wgt_run_monthly_email_now', array( $this, 'handle_run_now' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_sent_notice' ) );
	}

	public function maybe_schedule() {
		if ( ! function_exists( 'as_schedule_cron_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return; // Action Scheduler not available (shouldn't happen on a WooCommerce site).
		}

		$settings = WGT_Admin_Settings::get_settings();
		$enabled  = 'yes' === $settings['monthly_email_enabled'];
		$day      = max( 1, min( 28, (int) $settings['monthly_email_day'] ) );

		$already_scheduled = as_has_scheduled_action( self::CRON_HOOK, array(), self::AS_GROUP );

		if ( ! $enabled ) {
			if ( $already_scheduled && function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( self::CRON_HOOK, array(), self::AS_GROUP );
			}
			return;
		}

		$scheduled_day = (int) get_option( 'wgt_monthly_email_day_scheduled' );
		if ( $already_scheduled && $scheduled_day !== $day && function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::CRON_HOOK, array(), self::AS_GROUP );
			$already_scheduled = false;
		}

		if ( ! $already_scheduled ) {
			as_schedule_cron_action( time(), sprintf( '0 6 %d * *', $day ), self::CRON_HOOK, array(), self::AS_GROUP );
			update_option( 'wgt_monthly_email_day_scheduled', $day );
		}
	}

	public function run() {
		list( $date_from, $date_to ) = $this->previous_month_range();
		$this->send_for_period( $date_from, $date_to );
	}

	public function handle_run_now() {
		check_admin_referer( 'wgt_run_monthly_email_now' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wcfm-gst-tcs' ) );
		}

		list( $date_from, $date_to ) = $this->previous_month_range();
		$sent = $this->send_for_period( $date_from, $date_to );

		$redirect = wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=wgt-settings' );
		wp_safe_redirect( add_query_arg( 'wgt_monthly_sent', $sent, $redirect ) );
		exit;
	}

	public function maybe_show_sent_notice() {
		if ( ! isset( $_GET['wgt_monthly_sent'] ) ) {
			return;
		}
		$count = absint( $_GET['wgt_monthly_sent'] );
		?>
		<div class="notice notice-success is-dismissible">
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of vendor emails sent */
						_n( 'Monthly GST email sent to %d vendor.', 'Monthly GST email sent to %d vendors.', $count, 'wcfm-gst-tcs' ),
						$count
					)
				);
				?>
			</p>
		</div>
		<?php
	}

	private function previous_month_range() {
		return WGT_Admin_Reports::previous_month_range();
	}

	private function send_for_period( $date_from, $date_to ) {
		$settings  = WGT_Admin_Settings::get_settings();
		$skip_zero = 'yes' === $settings['monthly_email_skip_zero'];

		$vendor_ids = array_unique(
			array_merge(
				get_users( array( 'role' => 'wcfm_vendor', 'fields' => 'ID' ) ),
				get_users( array( 'role' => 'dc_vendor', 'fields' => 'ID' ) )
			)
		);
		if ( empty( $vendor_ids ) ) {
			return 0;
		}

		$sales_by_vendor = WGT_Admin_Reports::gather_gst_report( $date_from, $date_to, 0 );
		$sent_count      = 0;

		foreach ( $vendor_ids as $vendor_id ) {
			$vendor_id = (int) $vendor_id;
			$sales     = isset( $sales_by_vendor[ $vendor_id ] ) ? $sales_by_vendor[ $vendor_id ] : null;

			if ( ! $sales && $skip_zero ) {
				continue;
			}
			if ( ! $sales ) {
				$sales = array(
					'order_count' => 0,
					'net'         => 0.0,
					'gst'         => 0.0,
					'cgst'        => 0.0,
					'sgst'        => 0.0,
					'igst'        => 0.0,
				);
			}

			$user = get_userdata( $vendor_id );
			if ( ! $user || ! is_email( $user->user_email ) ) {
				continue;
			}

			$attachment_path = '';
			if ( $sales['order_count'] > 0 ) {
				$rows            = WGT_Admin_Reports::gather_gstr1_rows( $date_from, $date_to, $vendor_id );
				$attachment_path = $this->write_csv_attachment( $vendor_id, $date_from, $rows );
			}

			$this->send_email( $user, $vendor_id, $date_from, $date_to, $sales, $attachment_path );
			++$sent_count;

			if ( $attachment_path ) {
				wp_delete_file( $attachment_path );
			}
		}

		return $sent_count;
	}

	private function write_csv_attachment( $vendor_id, $date_from, $rows ) {
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			return '';
		}

		$dir = trailingslashit( $upload['basedir'] ) . 'wgt-exports';
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$path = trailingslashit( $dir ) . 'monthly-invoice-' . $vendor_id . '-' . $date_from . '-' . wp_generate_password( 8, false ) . '.csv';
		$fh   = fopen( $path, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			return '';
		}

		fwrite( $fh, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fputcsv( $fh, WGT_Admin_Reports::gstr1_headers() );
		foreach ( $rows as $row ) {
			fputcsv( $fh, $row );
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $path;
	}

	private function plain_price( $amount ) {
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES );
	}

	private function send_email( $user, $vendor_id, $date_from, $date_to, $sales, $attachment_path ) {
		$period_label = date_i18n( 'F Y', strtotime( $date_from ) );
		$site_name    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		$subject = sprintf(
			/* translators: 1: month/year, 2: site name */
			__( 'Your %1$s sales & GST report — %2$s', 'wcfm-gst-tcs' ),
			$period_label,
			$site_name
		);

		$body  = sprintf( __( 'Hi %s,', 'wcfm-gst-tcs' ), $user->display_name ) . "\n\n";
		$body .= sprintf( __( 'Here is your sales and GST summary for %s, for your own GST filing records.', 'wcfm-gst-tcs' ), $period_label ) . "\n\n";
		$body .= __( 'Orders:', 'wcfm-gst-tcs' ) . ' ' . $sales['order_count'] . "\n";
		$body .= __( 'Net Taxable Value:', 'wcfm-gst-tcs' ) . ' ' . $this->plain_price( $sales['net'] ) . "\n";
		$body .= __( 'GST Collected:', 'wcfm-gst-tcs' ) . ' ' . $this->plain_price( $sales['gst'] ) . "\n";
		$body .= '  ' . __( 'CGST:', 'wcfm-gst-tcs' ) . ' ' . $this->plain_price( $sales['cgst'] ) . "\n";
		$body .= '  ' . __( 'SGST:', 'wcfm-gst-tcs' ) . ' ' . $this->plain_price( $sales['sgst'] ) . "\n";
		$body .= '  ' . __( 'IGST:', 'wcfm-gst-tcs' ) . ' ' . $this->plain_price( $sales['igst'] ) . "\n\n";

		$settings = WGT_Admin_Settings::get_settings();
		if ( 'yes' === $settings['enable_tcs'] ) {
			$tcs = WGT_TCS_Engine::get_vendor_summary( $vendor_id, array( 'date_from' => $date_from, 'date_to' => $date_to ) );
			$body .= __( 'GST-TCS Deducted:', 'wcfm-gst-tcs' ) . ' ' . $this->plain_price( $tcs['tcs_amount'] ) . "\n\n";
		}

		if ( $attachment_path ) {
			$body .= __( 'A detailed sales report is attached as a CSV — full order details (customer, addresses, payment method, product, quantity, pricing) plus HSN/SAC, taxable value, tax split, and buyer details for business purchases.', 'wcfm-gst-tcs' ) . "\n\n";
		} else {
			$body .= __( 'No orders were recorded for you in this period.', 'wcfm-gst-tcs' ) . "\n\n";
		}

		$body .= __( 'You can view and download these reports anytime from My Account > GST & TCS.', 'wcfm-gst-tcs' ) . "\n\n";
		$body .= sprintf( __( 'This is an automated message from %s.', 'wcfm-gst-tcs' ), $site_name ) . "\n";

		$attachments = $attachment_path ? array( $attachment_path ) : array();

		wp_mail( $user->user_email, $subject, $body, array(), $attachments );
	}
}
