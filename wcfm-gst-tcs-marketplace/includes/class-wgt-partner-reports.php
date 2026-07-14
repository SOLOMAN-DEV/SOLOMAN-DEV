<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Commission reporting for WCFM's Delivery (wc-frontend-manager-delivery) and Affiliate
 * (wc-frontend-manager-affiliate) add-ons. Delivery persons and affiliates aren't sellers —
 * they earn a commission from the marketplace/vendor for a service (delivery, referral), so
 * none of this plugin's GST/TCS-on-product-sales machinery applies to them. What this class
 * provides instead is an income/commission summary (orders + commission total for the period,
 * plus an order-level CSV) so a delivery person or affiliate has the figures they'd need for
 * their own tax filing — it does not calculate or deduct any GST on their commission.
 *
 * Reads directly from the wp_wcfm_delivery_orders / wp_wcfm_affiliate_orders tables those
 * add-ons create; entirely no-ops (no menu items, no emails) if the corresponding add-on
 * isn't installed/active, detected via the helper functions they define.
 */
class WGT_Partner_Reports {

	const TYPE_DELIVERY = 'delivery';
	const TYPE_AFFILIATE = 'affiliate';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 20 );
		add_action( 'admin_post_wgt_export_partner_commission', array( $this, 'export_report' ) );
	}

	public static function is_active( $type ) {
		if ( self::TYPE_DELIVERY === $type ) {
			return function_exists( 'wcfm_get_delivery_boys' );
		}
		if ( self::TYPE_AFFILIATE === $type ) {
			return function_exists( 'wcfm_get_affiliate' );
		}
		return false;
	}

	private static function table( $type ) {
		global $wpdb;
		return self::TYPE_DELIVERY === $type ? $wpdb->prefix . 'wcfm_delivery_orders' : $wpdb->prefix . 'wcfm_affiliate_orders';
	}

	private static function person_column( $type ) {
		return self::TYPE_DELIVERY === $type ? 'delivery_boy' : 'affiliate_id';
	}

	public static function type_label( $type ) {
		return self::TYPE_DELIVERY === $type ? __( 'Delivery Person', 'wcfm-gst-tcs' ) : __( 'Affiliate', 'wcfm-gst-tcs' );
	}

	/**
	 * @return WP_User[]
	 */
	public static function get_people( $type ) {
		if ( ! self::is_active( $type ) ) {
			return array();
		}
		return self::TYPE_DELIVERY === $type ? wcfm_get_delivery_boys( -1, 0 ) : wcfm_get_affiliate( -1, 0 );
	}

	public static function person_label( $person_id ) {
		$user = get_userdata( $person_id );
		if ( ! $user ) {
			/* translators: %d: user ID */
			return sprintf( __( 'User #%d', 'wcfm-gst-tcs' ), $person_id );
		}
		$name = trim( $user->first_name . ' ' . $user->last_name );
		return $name ? $name : $user->display_name;
	}

	/**
	 * @return array<int,array{order_count:int,commission:float}>
	 */
	public static function gather_summary( $type, $date_from, $date_to, $person_id = 0 ) {
		global $wpdb;

		if ( ! self::is_active( $type ) ) {
			return array();
		}

		$table  = self::table( $type );
		$column = self::person_column( $type );

		$sql    = "SELECT {$column} AS person_id, COUNT(DISTINCT order_id) AS order_count, SUM(commission_amount) AS commission FROM {$table} WHERE is_trashed = 0 AND DATE(created) BETWEEN %s AND %s";
		$params = array( $date_from, $date_to );
		if ( $person_id ) {
			$sql     .= " AND {$column} = %d";
			$params[] = $person_id;
		}
		$sql .= " GROUP BY {$column}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$results = $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$out = array();
		if ( $results ) {
			foreach ( $results as $row ) {
				$pid = (int) $row->person_id;
				if ( ! $pid ) {
					continue;
				}
				$out[ $pid ] = array(
					'order_count' => (int) $row->order_count,
					'commission'  => (float) $row->commission,
				);
			}
		}
		return $out;
	}

	/**
	 * @return object[] Row objects with person_id, order_id, vendor_id, item_total,
	 *                   commission_amount, commission_status, created.
	 */
	public static function gather_rows( $type, $date_from, $date_to, $person_id = 0 ) {
		global $wpdb;

		if ( ! self::is_active( $type ) ) {
			return array();
		}

		$table  = self::table( $type );
		$column = self::person_column( $type );

		$sql    = "SELECT {$column} AS person_id, order_id, vendor_id, item_total, commission_amount, commission_status, created FROM {$table} WHERE is_trashed = 0 AND DATE(created) BETWEEN %s AND %s";
		$params = array( $date_from, $date_to );
		if ( $person_id ) {
			$sql     .= " AND {$column} = %d";
			$params[] = $person_id;
		}
		$sql .= " ORDER BY created ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private function previous_month_link( $page, $person_id = 0 ) {
		list( $date_from, $date_to ) = WGT_Admin_Reports::previous_month_range();

		$args = array(
			'page'      => $page,
			'date_from' => $date_from,
			'date_to'   => $date_to,
		);
		if ( $person_id ) {
			$args['person_id'] = $person_id;
		}

		$url = add_query_arg( $args, admin_url( 'admin.php' ) );
		return '<a href="' . esc_url( $url ) . '" class="button">' . esc_html__( 'Previous Month', 'wcfm-gst-tcs' ) . '</a>';
	}

	private function get_filters() {
		return array(
			'date_from' => isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : gmdate( 'Y-m-01' ),
			'date_to'   => isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : gmdate( 'Y-m-d' ),
			'person_id' => isset( $_GET['person_id'] ) ? absint( $_GET['person_id'] ) : 0,
		);
	}

	public function add_menu() {
		if ( self::is_active( self::TYPE_DELIVERY ) ) {
			add_submenu_page( 'wgt-settings', __( 'Delivery Commission Report', 'wcfm-gst-tcs' ), __( 'Delivery Commissions', 'wcfm-gst-tcs' ), 'manage_woocommerce', 'wgt-delivery-report', array( $this, 'render_delivery_report' ) );
		}
		if ( self::is_active( self::TYPE_AFFILIATE ) ) {
			add_submenu_page( 'wgt-settings', __( 'Affiliate Commission Report', 'wcfm-gst-tcs' ), __( 'Affiliate Commissions', 'wcfm-gst-tcs' ), 'manage_woocommerce', 'wgt-affiliate-report', array( $this, 'render_affiliate_report' ) );
		}
	}

	public function render_delivery_report() {
		$this->render_report( self::TYPE_DELIVERY, 'wgt-delivery-report' );
	}

	public function render_affiliate_report() {
		$this->render_report( self::TYPE_AFFILIATE, 'wgt-affiliate-report' );
	}

	private function render_report( $type, $page ) {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! self::is_active( $type ) ) {
			return;
		}

		$filters = $this->get_filters();
		$summary = self::gather_summary( $type, $filters['date_from'], $filters['date_to'], $filters['person_id'] );
		$label   = self::type_label( $type );
		?>
		<div class="wrap wgt-admin-wrap">
			<h1>
				<?php
				/* translators: %s: "Delivery Person" or "Affiliate" */
				echo esc_html( sprintf( __( '%s Commission Report', 'wcfm-gst-tcs' ), $label ) );
				?>
			</h1>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: "delivery persons" or "affiliates" */
						__( 'Orders and commission earned per %s for the period — an income summary for their own tax records, not a GST calculation. This plugin does not compute or deduct GST on commission income.', 'wcfm-gst-tcs' ),
						strtolower( $label )
					)
				);
				?>
			</p>

			<form method="get" class="wgt-filter-form">
				<input type="hidden" name="page" value="<?php echo esc_attr( $page ); ?>" />
				<label><?php esc_html_e( 'From', 'wcfm-gst-tcs' ); ?> <input type="date" name="date_from" value="<?php echo esc_attr( $filters['date_from'] ); ?>" /></label>
				<label><?php esc_html_e( 'To', 'wcfm-gst-tcs' ); ?> <input type="date" name="date_to" value="<?php echo esc_attr( $filters['date_to'] ); ?>" /></label>
				<label><?php echo esc_html( $label ); ?> ID <input type="number" name="person_id" value="<?php echo esc_attr( $filters['person_id'] ?: '' ); ?>" placeholder="<?php esc_attr_e( 'All', 'wcfm-gst-tcs' ); ?>" /></label>
				<?php submit_button( __( 'Filter', 'wcfm-gst-tcs' ), 'secondary', '', false ); ?>
				<?php echo $this->previous_month_link( $page, $filters['person_id'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:10px 0;">
				<input type="hidden" name="action" value="wgt_export_partner_commission" />
				<input type="hidden" name="partner_type" value="<?php echo esc_attr( $type ); ?>" />
				<?php wp_nonce_field( 'wgt_export_partner_commission' ); ?>
				<input type="hidden" name="date_from" value="<?php echo esc_attr( $filters['date_from'] ); ?>" />
				<input type="hidden" name="date_to" value="<?php echo esc_attr( $filters['date_to'] ); ?>" />
				<input type="hidden" name="person_id" value="<?php echo esc_attr( $filters['person_id'] ); ?>" />
				<?php submit_button( __( 'Export CSV', 'wcfm-gst-tcs' ), 'primary', '', false ); ?>
			</form>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php echo esc_html( $label ); ?></th>
						<th><?php esc_html_e( 'Orders', 'wcfm-gst-tcs' ); ?></th>
						<th><?php esc_html_e( 'Commission Earned', 'wcfm-gst-tcs' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $summary ) ) : ?>
						<tr><td colspan="3"><?php esc_html_e( 'No commission activity in this period.', 'wcfm-gst-tcs' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $summary as $person_id => $row ) : ?>
							<tr>
								<td><?php echo esc_html( self::person_label( $person_id ) ); ?></td>
								<td><?php echo esc_html( $row['order_count'] ); ?></td>
								<td><?php echo wc_price( $row['commission'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public function export_report() {
		check_admin_referer( 'wgt_export_partner_commission' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wcfm-gst-tcs' ) );
		}

		$type = isset( $_POST['partner_type'] ) ? sanitize_text_field( wp_unslash( $_POST['partner_type'] ) ) : '';
		if ( ! in_array( $type, array( self::TYPE_DELIVERY, self::TYPE_AFFILIATE ), true ) || ! self::is_active( $type ) ) {
			wp_die( esc_html__( 'Invalid report type.', 'wcfm-gst-tcs' ) );
		}

		$date_from = isset( $_POST['date_from'] ) ? sanitize_text_field( wp_unslash( $_POST['date_from'] ) ) : gmdate( 'Y-m-01' );
		$date_to   = isset( $_POST['date_to'] ) ? sanitize_text_field( wp_unslash( $_POST['date_to'] ) ) : gmdate( 'Y-m-d' );
		$person_id = isset( $_POST['person_id'] ) ? absint( $_POST['person_id'] ) : 0;

		$rows = self::gather_rows( $type, $date_from, $date_to, $person_id );

		$csv = array();
		foreach ( $rows as $row ) {
			$csv[] = array(
				self::person_label( (int) $row->person_id ),
				$row->order_id,
				WGT_Admin_Reports::vendor_label( (int) $row->vendor_id ),
				gmdate( 'Y-m-d', strtotime( $row->created ) ),
				number_format( (float) $row->item_total, 2, '.', '' ),
				number_format( (float) $row->commission_amount, 2, '.', '' ),
				$row->commission_status,
			);
		}

		WGT_CSV_Export::stream(
			'wgt-' . $type . '-commission-' . $date_from . '-to-' . $date_to,
			array( self::type_label( $type ), 'Order ID', 'Vendor', 'Order Date', 'Order Item Value', 'Commission Amount', 'Commission Status' ),
			$csv
		);
	}
}
