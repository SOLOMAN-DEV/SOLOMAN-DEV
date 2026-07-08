<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GST-TCS ledger (Section 52, CGST Act) — 1% of the net taxable value of goods/services
 * supplied through the marketplace by each vendor, collected by the marketplace operator
 * and reported on GSTR-8. This works whether WCFM splits an order into one suborder per
 * vendor or keeps a single shared order, because attribution happens per line item.
 */
class WGT_TCS_Engine {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'woocommerce_order_status_changed', array( $this, 'handle_status_change' ), 20, 4 );
		add_action( 'woocommerce_order_refunded', array( $this, 'adjust_for_refund' ), 20, 2 );
	}

	public function handle_status_change( $order_id, $from_status, $to_status, $order ) {
		$settings = WGT_Admin_Settings::get_settings();
		if ( 'yes' !== $settings['enable_tcs'] ) {
			return;
		}

		if ( in_array( $to_status, array( 'processing', 'completed' ), true ) ) {
			$this->record_for_order( $order_id, $order );
		} elseif ( in_array( $to_status, array( 'cancelled', 'failed' ), true ) ) {
			// A 'refunded' transition is handled at finer grain by adjust_for_refund(),
			// which recomputes from the actual per-item refunded amounts instead of
			// blanket-zeroing the ledger row.
			$this->reverse_for_order( $order_id );
		}
	}

	/**
	 * Recomputes each vendor's ledger row for an order after a full or partial refund,
	 * using WooCommerce's own per-item refunded-amount tracking so the TCS figure always
	 * reflects what's actually still payable, not just the original order total.
	 */
	public function adjust_for_refund( $order_id, $refund_id = 0 ) {
		$settings = WGT_Admin_Settings::get_settings();
		if ( 'yes' !== $settings['enable_tcs'] ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . WGT_TCS_TABLE;

		$existing_vendor_ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT vendor_id FROM {$table} WHERE order_id = %d", $order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		if ( empty( $existing_vendor_ids ) ) {
			return; // Order never reached Processing/Completed, so there's nothing to adjust.
		}

		$tcs_rate = (float) $settings['tcs_rate'];
		$op_state = $settings['company_state'];

		$remaining = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			$vendor_id = $item->get_meta( '_wgt_vendor_id' );
			if ( ! $vendor_id && function_exists( 'wcfm_get_vendor_id_by_post' ) ) {
				$vendor_id = wcfm_get_vendor_id_by_post( $item->get_product_id() );
			}
			if ( ! $vendor_id ) {
				continue;
			}
			$vendor_id = (int) $vendor_id;

			$refunded_net = abs( (float) $order->get_total_refunded_for_item( $item_id ) );
			$refunded_tax = abs( (float) $order->get_tax_refunded_for_item( $item_id ) );

			if ( ! isset( $remaining[ $vendor_id ] ) ) {
				$remaining[ $vendor_id ] = array( 'net' => 0.0, 'gst' => 0.0 );
			}
			$remaining[ $vendor_id ]['net'] += max( 0, (float) $item->get_total() - $refunded_net );
			$remaining[ $vendor_id ]['gst'] += max( 0, (float) $item->get_total_tax() - $refunded_tax );
		}

		foreach ( $existing_vendor_ids as $vendor_id ) {
			$vendor_id = (int) $vendor_id;
			$totals    = isset( $remaining[ $vendor_id ] ) ? $remaining[ $vendor_id ] : array( 'net' => 0.0, 'gst' => 0.0 );

			if ( $totals['net'] <= 0 ) {
				$wpdb->update( $table, array( 'status' => 'reversed' ), array( 'order_id' => $order_id, 'vendor_id' => $vendor_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				continue;
			}

			$tcs_amount   = round( $totals['net'] * $tcs_rate / 100, 2 );
			$vendor_state = WGT_Vendor_Settings::get_vendor_state( $vendor_id );
			$same_state   = $op_state && $vendor_state && ( $op_state === $vendor_state );
			$cgst         = $same_state ? round( $tcs_amount / 2, 2 ) : 0;
			$sgst         = $same_state ? round( $tcs_amount - $cgst, 2 ) : 0;
			$igst         = $same_state ? 0 : $tcs_amount;

			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'net_taxable_value' => $totals['net'],
					'gst_amount'        => $totals['gst'],
					'tcs_amount'        => $tcs_amount,
					'cgst_tcs'          => $cgst,
					'sgst_tcs'          => $sgst,
					'igst_tcs'          => $igst,
					'status'            => 'collected',
				),
				array( 'order_id' => $order_id, 'vendor_id' => $vendor_id )
			);
		}
	}

	public function record_for_order( $order_id, $order = null ) {
		global $wpdb;

		if ( ! $order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) {
			return;
		}

		$settings   = WGT_Admin_Settings::get_settings();
		$tcs_rate   = (float) $settings['tcs_rate'];
		$op_state   = $settings['company_state'];
		$table      = $wpdb->prefix . WGT_TCS_TABLE;
		$date_created = $order->get_date_created();
		$order_date = $date_created ? $date_created->date( 'Y-m-d H:i:s' ) : current_time( 'mysql' );
		$fy         = $this->financial_year_for( $date_created );
		$vendor_totals = self::get_order_vendor_totals( $order );

		// Clear any previous rows for this order so re-processing (e.g. a partial
		// refund followed by re-completion) never leaves stale/duplicate ledger data.
		$wpdb->delete( $table, array( 'order_id' => $order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		foreach ( $vendor_totals as $vendor_id => $totals ) {
			if ( $totals['net'] <= 0 ) {
				continue;
			}

			$tcs_amount = round( $totals['net'] * $tcs_rate / 100, 2 );
			$vendor_state = WGT_Vendor_Settings::get_vendor_state( $vendor_id );
			$same_state   = $op_state && $vendor_state && ( $op_state === $vendor_state );

			$cgst = $same_state ? round( $tcs_amount / 2, 2 ) : 0;
			$sgst = $same_state ? round( $tcs_amount - $cgst, 2 ) : 0;
			$igst = $same_state ? 0 : $tcs_amount;

			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'order_id'          => $order_id,
					'vendor_id'         => $vendor_id,
					'order_date'        => $order_date,
					'net_taxable_value' => $totals['net'],
					'gst_amount'        => $totals['gst'],
					'tcs_rate'          => $tcs_rate,
					'tcs_amount'        => $tcs_amount,
					'cgst_tcs'          => $cgst,
					'sgst_tcs'          => $sgst,
					'igst_tcs'          => $igst,
					'financial_year'    => $fy,
					'status'            => 'collected',
					'created_at'        => current_time( 'mysql' ),
				),
				array( '%d', '%d', '%s', '%f', '%f', '%f', '%f', '%f', '%f', '%f', '%s', '%s', '%s' )
			);
		}
	}

	public function reverse_for_order( $order_id ) {
		global $wpdb;
		$table = $wpdb->prefix . WGT_TCS_TABLE;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array( 'status' => 'reversed' ),
			array( 'order_id' => $order_id )
		);
	}

	/**
	 * @return array<int,array{net:float,gst:float}> keyed by vendor user ID.
	 */
	public static function get_order_vendor_totals( $order ) {
		$totals = array();

		foreach ( $order->get_items() as $item ) {
			$vendor_id = $item->get_meta( '_wgt_vendor_id' );

			if ( ! $vendor_id && function_exists( 'wcfm_get_vendor_id_by_post' ) ) {
				$vendor_id = wcfm_get_vendor_id_by_post( $item->get_product_id() );
			}

			if ( ! $vendor_id ) {
				continue;
			}

			$vendor_id = (int) $vendor_id;
			if ( ! isset( $totals[ $vendor_id ] ) ) {
				$totals[ $vendor_id ] = array( 'net' => 0.0, 'gst' => 0.0 );
			}

			$totals[ $vendor_id ]['net'] += (float) $item->get_total();
			$totals[ $vendor_id ]['gst'] += (float) $item->get_total_tax();
		}

		return $totals;
	}

	public function financial_year_for( $wc_datetime ) {
		$timestamp = $wc_datetime ? $wc_datetime->getTimestamp() : time();
		$year  = (int) wp_date( 'Y', $timestamp );
		$month = (int) wp_date( 'n', $timestamp );

		if ( $month >= 4 ) {
			return $year . '-' . substr( (string) ( $year + 1 ), -2 );
		}
		return ( $year - 1 ) . '-' . substr( (string) $year, -2 );
	}

	/**
	 * @param array $args {vendor_id, financial_year, date_from, date_to, status}
	 */
	public static function get_ledger_rows( $args = array() ) {
		global $wpdb;
		$table = $wpdb->prefix . WGT_TCS_TABLE;

		$where  = array( '1=1' );
		$values = array();

		if ( ! empty( $args['vendor_id'] ) ) {
			$where[]  = 'vendor_id = %d';
			$values[] = (int) $args['vendor_id'];
		}
		if ( ! empty( $args['financial_year'] ) ) {
			$where[]  = 'financial_year = %s';
			$values[] = $args['financial_year'];
		}
		if ( ! empty( $args['date_from'] ) ) {
			$where[]  = 'order_date >= %s';
			$values[] = $args['date_from'] . ' 00:00:00';
		}
		if ( ! empty( $args['date_to'] ) ) {
			$where[]  = 'order_date <= %s';
			$values[] = $args['date_to'] . ' 23:59:59';
		}
		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$values[] = $args['status'];
		} else {
			$where[] = "status = 'collected'";
		}

		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY order_date DESC';

		if ( $values ) {
			$sql = $wpdb->prepare( $sql, $values ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
		}

		return $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function get_vendor_summary( $vendor_id, $financial_year = '' ) {
		$rows = self::get_ledger_rows(
			array(
				'vendor_id'      => $vendor_id,
				'financial_year' => $financial_year,
			)
		);

		$summary = array(
			'net_taxable_value' => 0.0,
			'gst_amount'        => 0.0,
			'tcs_amount'        => 0.0,
			'cgst_tcs'          => 0.0,
			'sgst_tcs'          => 0.0,
			'igst_tcs'          => 0.0,
			'order_count'       => 0,
		);

		foreach ( $rows as $row ) {
			$summary['net_taxable_value'] += (float) $row->net_taxable_value;
			$summary['gst_amount']        += (float) $row->gst_amount;
			$summary['tcs_amount']        += (float) $row->tcs_amount;
			$summary['cgst_tcs']          += (float) $row->cgst_tcs;
			$summary['sgst_tcs']          += (float) $row->sgst_tcs;
			$summary['igst_tcs']          += (float) $row->igst_tcs;
			++$summary['order_count'];
		}

		return $summary;
	}
}
