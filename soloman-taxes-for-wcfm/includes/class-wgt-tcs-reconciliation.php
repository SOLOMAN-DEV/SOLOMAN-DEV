<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reconciles this plugin's own computed TCS ledger against what the government actually
 * shows as TCS credit (GSTR-2X, auto-populated on the GST portal from the e-commerce
 * operator's GSTR-8 filing). The plugin computes TCS at order time; GSTR-2X is the
 * authoritative record of what's actually been credited to each vendor — this tool doesn't
 * replace that reconciliation, it just makes mismatches visible early instead of only being
 * found at filing time or during a vendor dispute.
 *
 * Admin exports the vendor-wise TCS credit for a period from the GST portal (or compiles it
 * from GSTR-8 filing data), uploads it here as a CSV, and gets a side-by-side comparison
 * against the plugin's own ledger for the same vendor/period.
 */
class WGT_TCS_Reconciliation {

	const MAX_UPLOAD_BYTES = 2 * 1024 * 1024; // 2MB is generous for a GSTIN/period/amount CSV.
	const MATCH_TOLERANCE  = 1.0; // Rupees; GSTR-2X and our ledger can differ by paise-level rounding.

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 20 );
		add_action( 'admin_post_wgt_import_tcs_reconciliation', array( $this, 'handle_import' ) );
		add_action( 'admin_post_wgt_export_tcs_reconciliation', array( $this, 'handle_export' ) );
	}

	public function add_menu() {
		add_submenu_page( 'wgt-settings', __( 'TCS Reconciliation (GSTR-2X)', 'wcfm-gst-tcs' ), __( 'TCS Reconciliation', 'wcfm-gst-tcs' ), 'manage_woocommerce', 'wgt-tcs-reconciliation', array( $this, 'render_page' ) );
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		?>
		<div class="wrap wgt-admin-wrap">
			<h1><?php esc_html_e( 'TCS Reconciliation (GSTR-2X)', 'wcfm-gst-tcs' ); ?></h1>
			<p><?php esc_html_e( 'Compares this plugin\'s own computed TCS ledger against the TCS credit the government actually shows for each vendor (GSTR-2X, auto-populated from GSTR-8), so a mismatch is caught early instead of at filing time or in a vendor dispute.', 'wcfm-gst-tcs' ); ?></p>
			<p class="description"><?php esc_html_e( 'This doesn\'t fetch anything from the GST portal automatically — export the vendor-wise TCS credit for the period yourself (or compile it from your GSTR-8 filing) and upload it below.', 'wcfm-gst-tcs' ); ?></p>

			<h2><?php esc_html_e( 'Upload GSTR-2X data', 'wcfm-gst-tcs' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Columns: Vendor GSTIN, Period (YYYY-MM), Reported TCS Amount. A row is matched to a vendor by GSTIN — a vendor whose GSTIN on file doesn\'t match any row, or a row whose GSTIN doesn\'t match any vendor, is listed as unmatched rather than silently skipped.', 'wcfm-gst-tcs' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="wgt_import_tcs_reconciliation" />
				<?php wp_nonce_field( 'wgt_import_tcs_reconciliation' ); ?>
				<input type="file" name="reconciliation_csv" accept=".csv,text/csv" required="required" />
				<?php submit_button( __( 'Upload & Compare', 'wcfm-gst-tcs' ), 'primary', '', false ); ?>
			</form>

			<?php $this->render_result(); ?>
		</div>
		<?php
	}

	private function render_result() {
		$result = get_transient( 'wgt_tcs_reconciliation_' . get_current_user_id() );
		if ( ! $result ) {
			return;
		}
		?>
		<h2><?php esc_html_e( 'Reconciliation Result', 'wcfm-gst-tcs' ); ?></h2>
		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: number of matching rows, 2: number of mismatched rows, 3: number of unmatched rows */
					__( '%1$d matched, %2$d mismatched, %3$d unmatched (no vendor found for that GSTIN).', 'wcfm-gst-tcs' ),
					$result['matched'],
					$result['mismatched'],
					$result['unmatched']
				)
			);
			?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:10px 0;">
			<input type="hidden" name="action" value="wgt_export_tcs_reconciliation" />
			<?php wp_nonce_field( 'wgt_export_tcs_reconciliation' ); ?>
			<?php submit_button( __( 'Export Full Result (CSV)', 'wcfm-gst-tcs' ), 'secondary', '', false ); ?>
		</form>
		<table class="widefat striped">
			<thead>
				<tr>
					<?php foreach ( self::headers() as $header ) : ?>
						<th><?php echo esc_html( $header ); ?></th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( array_slice( $result['rows'], 0, 100 ) as $row ) : ?>
					<?php
					$status_key   = isset( $row[6] ) ? $row[6] : 'mismatch';
					$status_color = 'match' === $status_key ? '#1a7f37' : ( 'unmatched' === $status_key ? '#996800' : '#b32d2e' );
					?>
					<tr>
						<?php foreach ( array_slice( $row, 0, 6 ) as $i => $cell ) : ?>
							<?php if ( 5 === $i ) : // Status column, color by the raw (untranslated) status key. ?>
								<td style="color:<?php echo esc_attr( $status_color ); ?>;"><strong><?php echo esc_html( $cell ); ?></strong></td>
							<?php else : ?>
								<td><?php echo esc_html( $cell ); ?></td>
							<?php endif; ?>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( count( $result['rows'] ) > 100 ) : ?>
			<p class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of additional rows not shown on screen */
						__( '… and %d more row(s) — export the full CSV above to see all of them.', 'wcfm-gst-tcs' ),
						count( $result['rows'] ) - 100
					)
				);
				?>
			</p>
		<?php endif; ?>
		<?php
	}

	private static function headers() {
		return array( 'Vendor', 'Vendor GSTIN', 'Period', 'GSTR-2X Reported TCS', 'Our Ledger TCS', 'Status' );
	}

	public function handle_import() {
		check_admin_referer( 'wgt_import_tcs_reconciliation' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wcfm-gst-tcs' ) );
		}

		$redirect = wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=wgt-tcs-reconciliation' );

		if ( empty( $_FILES['reconciliation_csv'] ) || UPLOAD_ERR_OK !== $_FILES['reconciliation_csv']['error'] ) {
			wp_die( esc_html__( 'No file was uploaded, or the upload failed.', 'wcfm-gst-tcs' ) );
		}
		if ( $_FILES['reconciliation_csv']['size'] > self::MAX_UPLOAD_BYTES ) {
			wp_die( esc_html__( 'File is too large (limit 2MB).', 'wcfm-gst-tcs' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.Security.NonceVerification.Missing
		$fh = fopen( $_FILES['reconciliation_csv']['tmp_name'], 'r' );
		if ( ! $fh ) {
			wp_die( esc_html__( 'Could not read the uploaded file.', 'wcfm-gst-tcs' ) );
		}

		$gstin_to_vendor = $this->gstin_to_vendor_map();
		$rows            = array();
		$matched         = 0;
		$mismatched      = 0;
		$unmatched       = 0;
		$row_number      = 0;

		while ( ( $line = fgetcsv( $fh ) ) !== false ) {
			++$row_number;
			if ( 1 === $row_number && $this->looks_like_header( $line ) ) {
				continue;
			}
			if ( count( array_filter( $line, 'strlen' ) ) === 0 ) {
				continue;
			}

			$gstin    = isset( $line[0] ) ? strtoupper( trim( $line[0] ) ) : '';
			$period   = isset( $line[1] ) ? trim( $line[1] ) : '';
			$reported = isset( $line[2] ) ? (float) str_replace( ',', '', trim( $line[2] ) ) : 0.0;

			if ( ! $gstin || ! isset( $gstin_to_vendor[ $gstin ] ) ) {
				$rows[] = array( __( '(unmatched)', 'wcfm-gst-tcs' ), $gstin, $period, number_format( $reported, 2, '.', '' ), '—', __( 'Unmatched', 'wcfm-gst-tcs' ), 'unmatched' );
				++$unmatched;
				continue;
			}

			$vendor_id            = $gstin_to_vendor[ $gstin ];
			list( $date_from, $date_to ) = $this->period_to_date_range( $period );

			$our_tcs = WGT_TCS_Engine::get_vendor_summary( $vendor_id, array( 'date_from' => $date_from, 'date_to' => $date_to ) );
			$our_amount = (float) $our_tcs['tcs_amount'];

			$is_match = abs( $our_amount - $reported ) <= self::MATCH_TOLERANCE;
			$status   = $is_match ? __( 'Match', 'wcfm-gst-tcs' ) : __( 'Mismatch', 'wcfm-gst-tcs' );
			if ( $is_match ) {
				++$matched;
			} else {
				++$mismatched;
			}

			$rows[] = array(
				WGT_Admin_Reports::vendor_label( $vendor_id ),
				$gstin,
				$period,
				number_format( $reported, 2, '.', '' ),
				number_format( $our_amount, 2, '.', '' ),
				$status,
				$is_match ? 'match' : 'mismatch',
			);
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		set_transient(
			'wgt_tcs_reconciliation_' . get_current_user_id(),
			array(
				'rows'       => $rows,
				'matched'    => $matched,
				'mismatched' => $mismatched,
				'unmatched'  => $unmatched,
			),
			HOUR_IN_SECONDS
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	public function handle_export() {
		check_admin_referer( 'wgt_export_tcs_reconciliation' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wcfm-gst-tcs' ) );
		}

		$result = get_transient( 'wgt_tcs_reconciliation_' . get_current_user_id() );
		if ( ! $result ) {
			wp_die( esc_html__( 'No reconciliation result to export — upload a CSV first.', 'wcfm-gst-tcs' ) );
		}

		$csv_rows = array_map(
			function ( $row ) {
				return array_slice( $row, 0, 6 );
			},
			$result['rows']
		);

		WGT_CSV_Export::stream( 'tcs-reconciliation-' . gmdate( 'Y-m-d' ), self::headers(), $csv_rows );
	}

	private function looks_like_header( $row ) {
		$first = isset( $row[0] ) ? strtolower( trim( $row[0] ) ) : '';
		return in_array( $first, array( 'vendor gstin', 'gstin' ), true );
	}

	/**
	 * @return array<string,int> GSTIN => vendor_id, for every vendor that has a GSTIN on file.
	 */
	private function gstin_to_vendor_map() {
		$vendor_ids = array_unique(
			array_merge(
				get_users( array( 'role' => 'wcfm_vendor', 'fields' => 'ID' ) ),
				get_users( array( 'role' => 'dc_vendor', 'fields' => 'ID' ) )
			)
		);

		$map = array();
		foreach ( $vendor_ids as $vendor_id ) {
			$gst = WGT_Vendor_Settings::get_vendor_gst( $vendor_id );
			if ( ! empty( $gst['gstin'] ) ) {
				$map[ strtoupper( $gst['gstin'] ) ] = (int) $vendor_id;
			}
		}
		return $map;
	}

	/**
	 * Accepts "YYYY-MM" (the usual GSTR-2X period shape) and falls back to treating the whole
	 * string as both date_from and date_to if it doesn't parse, so a differently-formatted
	 * period still does *something* sensible rather than silently matching all-time.
	 */
	private function period_to_date_range( $period ) {
		if ( preg_match( '/^(\d{4})-(\d{2})$/', trim( $period ), $m ) ) {
			$date_from = $m[1] . '-' . $m[2] . '-01';
			$date_to   = gmdate( 'Y-m-t', strtotime( $date_from ) );
			return array( $date_from, $date_to );
		}
		return array( $period, $period );
	}
}
