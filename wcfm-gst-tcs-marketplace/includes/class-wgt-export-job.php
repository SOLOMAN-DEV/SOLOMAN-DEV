<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * For date ranges too large to stream synchronously without risking a PHP
 * max_execution_time timeout, queues a background job on WooCommerce's bundled Action
 * Scheduler, writes the CSV to a protected uploads subfolder, and emails the requesting
 * admin a token-gated download link. Small ranges are unaffected — WGT_Admin_Reports
 * only calls into this class once a date range's order count crosses the threshold.
 */
class WGT_Export_Job {

	const THRESHOLD        = 2000; // orders; below this, reports stream synchronously.
	const REGISTRY_OPTION  = 'wgt_export_downloads';
	const EXPORT_SUBDIR    = 'wgt-exports';
	const FILE_TTL_SECONDS = 2 * DAY_IN_SECONDS;

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wgt_run_export_job', array( $this, 'run_job' ) );
		add_action( 'admin_post_wgt_download_export', array( $this, 'handle_download' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_queued_notice' ) );
		add_action( 'wgt_cleanup_exports', array( $this, 'cleanup_expired_files' ) );

		if ( ! wp_next_scheduled( 'wgt_cleanup_exports' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wgt_cleanup_exports' );
		}
	}

	public static function is_available() {
		return function_exists( 'as_enqueue_async_action' );
	}

	/**
	 * Returns true (and queues a background job) if the range is large enough to warrant
	 * it; the caller should stream synchronously as before when this returns false.
	 */
	public function maybe_queue( $report_type, $date_from, $date_to, $vendor_id ) {
		if ( ! self::is_available() ) {
			return false;
		}

		if ( WGT_Admin_Reports::count_orders_in_range( $date_from, $date_to ) <= self::THRESHOLD ) {
			return false;
		}

		$job_id = wp_generate_password( 24, false );

		as_enqueue_async_action(
			'wgt_run_export_job',
			array(
				array(
					'report_type' => $report_type,
					'date_from'   => $date_from,
					'date_to'     => $date_to,
					'vendor_id'   => $vendor_id,
					'job_id'      => $job_id,
					'user_id'     => get_current_user_id(),
				),
			),
			'wgt-exports'
		);

		set_transient( 'wgt_export_queued_' . get_current_user_id(), $job_id, 5 * MINUTE_IN_SECONDS );

		return true;
	}

	public function run_job( $args ) {
		if ( ! is_array( $args ) || empty( $args['report_type'] ) ) {
			return;
		}

		$report_type = $args['report_type'];
		$date_from   = $args['date_from'];
		$date_to     = $args['date_to'];
		$vendor_id   = (int) $args['vendor_id'];
		$job_id      = $args['job_id'];
		$user_id     = (int) $args['user_id'];

		$dir = $this->export_dir();
		if ( ! $dir ) {
			return;
		}

		$filename = 'wgt-' . sanitize_key( $report_type ) . '-' . $job_id . '.csv';
		$path     = trailingslashit( $dir ) . $filename;

		$fh = fopen( $path, 'w' );
		if ( ! $fh ) {
			return;
		}
		fwrite( $fh, "\xEF\xBB\xBF" );

		if ( 'gst_report' === $report_type ) {
			fputcsv( $fh, array( 'Vendor', 'Vendor ID', 'Orders', 'Net Taxable Value', 'CGST', 'SGST', 'IGST', 'Total GST' ) );
			$rows = WGT_Admin_Reports::gather_gst_report( $date_from, $date_to, $vendor_id );
			foreach ( $rows as $vid => $row ) {
				fputcsv(
					$fh,
					array(
						WGT_Admin_Reports::vendor_label( $vid ),
						$vid,
						$row['order_count'],
						number_format( $row['net'], 2, '.', '' ),
						number_format( $row['cgst'], 2, '.', '' ),
						number_format( $row['sgst'], 2, '.', '' ),
						number_format( $row['igst'], 2, '.', '' ),
						number_format( $row['gst'], 2, '.', '' ),
					)
				);
			}
		} elseif ( 'gstr1' === $report_type ) {
			fputcsv( $fh, array( 'Vendor', 'Vendor GSTIN', 'Order ID', 'Invoice Date', 'Type', 'Buyer Name/Company', 'Buyer GSTIN', 'Place of Supply', 'HSN/SAC', 'Taxable Value', 'GST Rate', 'CGST', 'SGST', 'IGST', 'Invoice Value' ) );
			foreach ( WGT_Admin_Reports::gather_gstr1_rows( $date_from, $date_to, $vendor_id ) as $row ) {
				fputcsv( $fh, $row );
			}
		}

		fclose( $fh );

		$registry            = get_option( self::REGISTRY_OPTION, array() );
		$registry[ $job_id ] = array(
			'file'        => $filename,
			'user_id'     => $user_id,
			'created'     => time(),
			'report_type' => $report_type,
		);
		update_option( self::REGISTRY_OPTION, $registry );

		$this->email_download_link( $user_id, $job_id, $report_type );
	}

	private function export_dir() {
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			return false;
		}
		$dir = trailingslashit( $upload['basedir'] ) . self::EXPORT_SUBDIR;
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		// Best-effort: blocks directory listing/direct access on Apache. Not effective on
		// nginx, which ignores .htaccess — the token check in handle_download() is the
		// real access control either way.
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		return $dir;
	}

	private function email_download_link( $user_id, $job_id, $report_type ) {
		$user = get_userdata( $user_id );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return;
		}

		$url = add_query_arg(
			array(
				'action' => 'wgt_download_export',
				'job'    => $job_id,
				'_wpnonce' => wp_create_nonce( 'wgt_download_export_' . $job_id ),
			),
			admin_url( 'admin-post.php' )
		);

		$labels = array(
			'gst_report' => __( 'GST Tax Summary', 'wcfm-gst-tcs' ),
			'gstr1'      => __( 'GSTR-1 Export', 'wcfm-gst-tcs' ),
		);
		$label = isset( $labels[ $report_type ] ) ? $labels[ $report_type ] : $report_type;

		wp_mail(
			$user->user_email,
			sprintf( '[%s] %s', wp_specialchars_decode( get_bloginfo( 'name' ) ), sprintf( __( 'Your %s export is ready', 'wcfm-gst-tcs' ), $label ) ),
			sprintf(
				/* translators: 1: report name, 2: download URL */
				__( "Your requested %1\$s export has finished. Download it here (link expires in 48 hours, and only works while logged in as an administrator):\n\n%2\$s", 'wcfm-gst-tcs' ),
				$label,
				$url
			)
		);
	}

	public function maybe_show_queued_notice() {
		$job_id = get_transient( 'wgt_export_queued_' . get_current_user_id() );
		if ( ! $job_id ) {
			return;
		}
		delete_transient( 'wgt_export_queued_' . get_current_user_id() );
		?>
		<div class="notice notice-info is-dismissible">
			<p><?php esc_html_e( 'This export covers a large date range, so it\'s being prepared in the background. You\'ll get an email with a download link when it\'s ready.', 'wcfm-gst-tcs' ); ?></p>
		</div>
		<?php
	}

	public function handle_download() {
		$job_id = isset( $_GET['job'] ) ? sanitize_text_field( wp_unslash( $_GET['job'] ) ) : '';
		if ( ! $job_id || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'wgt_download_export_' . $job_id ) ) {
			wp_die( esc_html__( 'Invalid or expired download link.', 'wcfm-gst-tcs' ) );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wcfm-gst-tcs' ) );
		}

		$registry = get_option( self::REGISTRY_OPTION, array() );
		if ( empty( $registry[ $job_id ] ) ) {
			wp_die( esc_html__( 'This export has expired or does not exist. Please generate it again.', 'wcfm-gst-tcs' ) );
		}

		$dir  = $this->export_dir();
		$path = trailingslashit( $dir ) . $registry[ $job_id ]['file'];
		if ( ! file_exists( $path ) ) {
			wp_die( esc_html__( 'This export file is missing. Please generate it again.', 'wcfm-gst-tcs' ) );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $registry[ $job_id ]['file'] );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile
		exit;
	}

	public function cleanup_expired_files() {
		$registry = get_option( self::REGISTRY_OPTION, array() );
		if ( empty( $registry ) ) {
			return;
		}

		$dir     = $this->export_dir();
		$changed = false;

		foreach ( $registry as $job_id => $entry ) {
			if ( ( time() - $entry['created'] ) < self::FILE_TTL_SECONDS ) {
				continue;
			}
			$path = $dir ? trailingslashit( $dir ) . $entry['file'] : '';
			if ( $path && file_exists( $path ) ) {
				wp_delete_file( $path );
			}
			unset( $registry[ $job_id ] );
			$changed = true;
		}

		if ( $changed ) {
			update_option( self::REGISTRY_OPTION, $registry );
		}
	}
}
