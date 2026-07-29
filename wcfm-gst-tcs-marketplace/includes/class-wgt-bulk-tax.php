<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bulk HSN/GST-rate update via CSV — the marketplace-seller pattern Amazon/Flipkart use for
 * tax codes: a seller downloads their current listing tax data, edits it offline (e.g. in a
 * spreadsheet), and re-uploads it to update many SKUs in one pass instead of editing products
 * one at a time. Two entry points share the same underlying logic:
 *  - Admin (Settings > GST & TCS > Bulk HSN/GST Update): operates on any vendor's products.
 *  - Vendor-facing (GST & TCS tab, wired in from WGT_Vendor_Dashboard): always forced to the
 *    logged-in vendor's own products, both on export and on import (a row for a product the
 *    vendor doesn't own is skipped, never trusted from the upload).
 */
class WGT_Bulk_Tax {

	const MAX_UPLOAD_BYTES = 5 * 1024 * 1024; // 5MB is generous for a HSN/rate-only CSV.

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 20 );
		add_action( 'admin_post_wgt_export_product_tax', array( $this, 'handle_export' ) );
		add_action( 'admin_post_wgt_import_product_tax', array( $this, 'handle_import' ) );
	}

	public function add_menu() {
		add_submenu_page( 'wgt-settings', __( 'Bulk HSN/GST Update', 'wcfm-gst-tcs' ), __( 'Bulk HSN/GST Update', 'wcfm-gst-tcs' ), 'manage_woocommerce', 'wgt-bulk-tax', array( $this, 'render_admin_page' ) );
	}

	public function render_admin_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		?>
		<div class="wrap wgt-admin-wrap">
			<h1><?php esc_html_e( 'Bulk HSN/GST Update', 'wcfm-gst-tcs' ); ?></h1>
			<p><?php esc_html_e( 'Download your current product HSN/GST listing data, edit it in a spreadsheet, and re-upload it to update many products at once — the same pattern Amazon/Flipkart use for seller tax-code updates.', 'wcfm-gst-tcs' ); ?></p>
			<?php $this->render_section( 'admin' ); ?>
		</div>
		<?php
	}

	/**
	 * Shared export/import UI, used by both the wp-admin page and the vendor-facing tab.
	 *
	 * @param string $scope 'admin' (any vendor, vendor_id filter box shown) or 'vendor'
	 *                      (always the logged-in vendor, no filter box).
	 */
	public function render_section( $scope ) {
		$this->render_result_notice();
		?>
		<h2><?php esc_html_e( 'Step 1: Export current tax data', 'wcfm-gst-tcs' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:20px;">
			<input type="hidden" name="action" value="wgt_export_product_tax" />
			<input type="hidden" name="scope" value="<?php echo esc_attr( $scope ); ?>" />
			<?php wp_nonce_field( 'wgt_export_product_tax' ); ?>
			<?php if ( 'admin' === $scope ) : ?>
				<label>
					<?php esc_html_e( 'Vendor ID', 'wcfm-gst-tcs' ); ?>
					<input type="number" name="vendor_id" value="" placeholder="<?php esc_attr_e( 'All vendors', 'wcfm-gst-tcs' ); ?>" />
				</label>
			<?php endif; ?>
			<?php submit_button( __( 'Export CSV', 'wcfm-gst-tcs' ), 'secondary', '', false ); ?>
		</form>

		<h2><?php esc_html_e( 'Step 2: Upload your edited CSV', 'wcfm-gst-tcs' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Columns: Product ID, SKU, Product Name, HSN, GST Rate. Product ID is used to match a row to a product; if it\'s blank or wrong, SKU is used instead. Product Name is ignored on import — it\'s only there so the sheet is readable. A blank HSN or GST Rate cell clears that field on the product, so delete any row you don\'t want touched rather than leaving its cells blank.', 'wcfm-gst-tcs' ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<input type="hidden" name="action" value="wgt_import_product_tax" />
			<input type="hidden" name="scope" value="<?php echo esc_attr( $scope ); ?>" />
			<?php wp_nonce_field( 'wgt_import_product_tax' ); ?>
			<input type="file" name="tax_csv" accept=".csv,text/csv" required="required" />
			<?php submit_button( __( 'Upload & Update', 'wcfm-gst-tcs' ), 'primary', '', false ); ?>
		</form>
		<?php
	}

	private function render_result_notice() {
		$key    = 'wgt_bulk_tax_result_' . get_current_user_id();
		$result = get_transient( $key );
		if ( ! $result ) {
			return;
		}
		delete_transient( $key );
		?>
		<div class="notice notice-<?php echo esc_attr( ! empty( $result['errors'] ) ? 'warning' : 'success' ); ?> is-dismissible" style="padding:10px 12px;">
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: number of products updated, 2: number of rows skipped */
						__( 'Bulk update finished: %1$d product(s) updated, %2$d row(s) skipped.', 'wcfm-gst-tcs' ),
						$result['updated'],
						count( $result['errors'] )
					)
				);
				?>
			</p>
			<?php if ( ! empty( $result['errors'] ) ) : ?>
				<ul style="margin-left:1.5em;list-style:disc;">
					<?php foreach ( array_slice( $result['errors'], 0, 20 ) as $error ) : ?>
						<li><?php echo esc_html( $error ); ?></li>
					<?php endforeach; ?>
					<?php if ( count( $result['errors'] ) > 20 ) : ?>
						<li>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: number of additional skipped rows not shown */
									__( '… and %d more.', 'wcfm-gst-tcs' ),
									count( $result['errors'] ) - 20
								)
							);
							?>
						</li>
					<?php endif; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}

	public function handle_export() {
		check_admin_referer( 'wgt_export_product_tax' );

		$scope = isset( $_POST['scope'] ) ? sanitize_key( $_POST['scope'] ) : 'admin';

		if ( 'vendor' === $scope ) {
			$vendor_id = get_current_user_id();
			if ( ! $vendor_id ) {
				wp_die( esc_html__( 'You do not have permission to do this.', 'wcfm-gst-tcs' ) );
			}
		} else {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( esc_html__( 'You do not have permission to do this.', 'wcfm-gst-tcs' ) );
			}
			$vendor_id = isset( $_POST['vendor_id'] ) ? absint( $_POST['vendor_id'] ) : 0;
		}

		$csv = array();
		foreach ( $this->get_products( $vendor_id ) as $product ) {
			$product_id = $product->get_id();
			$csv[]      = array(
				$product_id,
				$product->get_sku(),
				$product->get_name(),
				WGT_Product_Fields::get_hsn( $product_id ),
				get_post_meta( $product_id, WGT_Product_Fields::RATE_META, true ),
			);
		}

		WGT_CSV_Export::stream(
			'wgt-product-tax-' . ( $vendor_id ? $vendor_id . '-' : 'all-' ) . gmdate( 'Y-m-d' ),
			array( 'Product ID', 'SKU', 'Product Name', 'HSN', 'GST Rate' ),
			$csv
		);
	}

	/**
	 * @return WC_Product[]
	 */
	private function get_products( $vendor_id ) {
		$args = array(
			'status' => 'publish',
			'limit'  => -1,
			'return' => 'objects',
		);
		if ( $vendor_id ) {
			$args['author'] = $vendor_id;
		}
		return wc_get_products( $args );
	}

	public function handle_import() {
		check_admin_referer( 'wgt_import_product_tax' );

		$scope = isset( $_POST['scope'] ) ? sanitize_key( $_POST['scope'] ) : 'admin';

		if ( 'vendor' === $scope ) {
			$vendor_id = get_current_user_id();
			if ( ! $vendor_id ) {
				wp_die( esc_html__( 'You do not have permission to do this.', 'wcfm-gst-tcs' ) );
			}
		} else {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( esc_html__( 'You do not have permission to do this.', 'wcfm-gst-tcs' ) );
			}
			$vendor_id = 0; // Admin import trusts the Product ID/SKU in the file, any vendor.
		}

		$result   = array( 'updated' => 0, 'errors' => array() );
		$redirect = wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=wgt-bulk-tax' );

		if ( empty( $_FILES['tax_csv'] ) || UPLOAD_ERR_OK !== $_FILES['tax_csv']['error'] ) {
			$result['errors'][] = __( 'No file was uploaded, or the upload failed.', 'wcfm-gst-tcs' );
			$this->store_result_and_redirect( $result, $redirect );
		}

		if ( $_FILES['tax_csv']['size'] > self::MAX_UPLOAD_BYTES ) {
			$result['errors'][] = __( 'File is too large (limit 5MB).', 'wcfm-gst-tcs' );
			$this->store_result_and_redirect( $result, $redirect );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.Security.NonceVerification.Missing
		$fh = fopen( $_FILES['tax_csv']['tmp_name'], 'r' );
		if ( ! $fh ) {
			$result['errors'][] = __( 'Could not read the uploaded file.', 'wcfm-gst-tcs' );
			$this->store_result_and_redirect( $result, $redirect );
		}

		$row_number = 0;
		while ( ( $row = fgetcsv( $fh ) ) !== false ) {
			++$row_number;
			if ( 1 === $row_number && $this->looks_like_header( $row ) ) {
				continue;
			}
			if ( count( array_filter( $row, 'strlen' ) ) === 0 ) {
				continue; // Blank line.
			}

			$this->process_row( $row_number, $row, $vendor_id, $result );
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$this->store_result_and_redirect( $result, $redirect );
	}

	private function looks_like_header( $row ) {
		$first = isset( $row[0] ) ? strtolower( trim( $row[0] ) ) : '';
		return in_array( $first, array( 'product id', 'product_id', 'id' ), true );
	}

	private function process_row( $row_number, $row, $vendor_id, array &$result ) {
		$raw_product_id = isset( $row[0] ) ? trim( $row[0] ) : '';
		$raw_sku        = isset( $row[1] ) ? trim( $row[1] ) : '';
		$raw_hsn        = isset( $row[3] ) ? $row[3] : '';
		$raw_rate       = isset( $row[4] ) ? $row[4] : '';

		$product_id = 0;
		if ( $raw_product_id && absint( $raw_product_id ) && 'product' === get_post_type( absint( $raw_product_id ) ) ) {
			$product_id = absint( $raw_product_id );
		} elseif ( $raw_sku ) {
			$product_id = (int) wc_get_product_id_by_sku( $raw_sku );
		}

		if ( ! $product_id ) {
			/* translators: 1: row number, 2: product ID or SKU that couldn't be matched */
			$result['errors'][] = sprintf( __( 'Row %1$d: no matching product for "%2$s".', 'wcfm-gst-tcs' ), $row_number, $raw_product_id ? $raw_product_id : $raw_sku );
			return;
		}

		if ( $vendor_id ) {
			$owner = function_exists( 'wcfm_get_vendor_id_by_post' ) ? (int) wcfm_get_vendor_id_by_post( $product_id ) : 0;
			if ( $owner !== $vendor_id ) {
				/* translators: 1: row number, 2: product ID */
				$result['errors'][] = sprintf( __( 'Row %1$d: product #%2$d is not one of your products.', 'wcfm-gst-tcs' ), $row_number, $product_id );
				return;
			}
		}

		if ( '' !== trim( (string) $raw_hsn ) && ! WGT_Product_Fields::is_valid_hsn( preg_replace( '/[^0-9]/', '', (string) $raw_hsn ) ) ) {
			/* translators: 1: row number, 2: product ID */
			$result['errors'][] = sprintf( __( 'Row %1$d: HSN for product #%2$d must be exactly 6 digits — row skipped.', 'wcfm-gst-tcs' ), $row_number, $product_id );
			return;
		}

		if ( '' !== trim( (string) $raw_rate ) && ! is_numeric( str_replace( ',', '', (string) $raw_rate ) ) ) {
			/* translators: 1: row number, 2: product ID */
			$result['errors'][] = sprintf( __( 'Row %1$d: GST rate for product #%2$d is not a number — row skipped.', 'wcfm-gst-tcs' ), $row_number, $product_id );
			return;
		}

		WGT_Product_Fields::update_hsn( $product_id, $raw_hsn );
		WGT_Product_Fields::update_gst_rate( $product_id, $raw_rate );
		++$result['updated'];
	}

	private function store_result_and_redirect( $result, $redirect ) {
		set_transient( 'wgt_bulk_tax_result_' . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( $redirect );
		exit;
	}
}
