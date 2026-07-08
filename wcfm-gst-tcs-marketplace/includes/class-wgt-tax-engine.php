<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-vendor CGST/SGST/IGST calculation.
 *
 * Rather than reinventing tax calculation, this provisions native WooCommerce
 * tax classes/rates for each GST slab (an "Intra" class carrying CGST+SGST rows,
 * and an "Inter" class carrying a single IGST row) and then, at runtime, tells
 * WooCommerce which of the two classes a given cart/order line should use by
 * comparing the selling vendor's state (from their GSTIN/profile) against the
 * buyer's state. WooCommerce's own tax engine then does the actual math,
 * rounding, storage, refunds and display — including inside WCFM's own order
 * views, which just read the standard WC_Order tax data.
 */
class WGT_Tax_Engine {

	const PROVISIONED_OPTION = 'wgt_provisioned_gst_rates';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'maybe_bootstrap_common_slabs' ), 20 );
		add_action( 'added_post_meta', array( $this, 'maybe_provision_from_meta' ), 10, 4 );
		add_action( 'updated_post_meta', array( $this, 'maybe_provision_from_meta' ), 10, 4 );

		add_filter( 'woocommerce_product_get_tax_class', array( $this, 'filter_product_tax_class' ), 20, 2 );
		add_filter( 'woocommerce_product_variation_get_tax_class', array( $this, 'filter_product_tax_class' ), 20, 2 );

		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'stamp_line_item_meta' ), 10, 4 );
	}

	public function maybe_bootstrap_common_slabs() {
		if ( get_option( 'wgt_slabs_bootstrapped' ) ) {
			return;
		}
		foreach ( WGT_Product_Fields::GST_SLABS as $slab ) {
			$this->ensure_gst_tax_classes( (float) $slab );
		}
		update_option( 'wgt_slabs_bootstrapped', 1 );
	}

	public function maybe_provision_from_meta( $meta_id, $object_id, $meta_key, $meta_value ) {
		if ( WGT_Product_Fields::RATE_META !== $meta_key ) {
			return;
		}
		$this->ensure_gst_tax_classes( (float) $meta_value );
	}

	/**
	 * Idempotently makes sure the WooCommerce tax classes + rate rows exist for a slab.
	 */
	public function ensure_gst_tax_classes( $rate ) {
		$settings = WGT_Admin_Settings::get_settings();
		if ( 'yes' !== $settings['enable_gst'] ) {
			return;
		}

		$rate        = round( (float) $rate, 2 );
		$key         = $this->rate_key( $rate );
		$provisioned = get_option( self::PROVISIONED_OPTION, array() );

		if ( ! empty( $provisioned[ $key ] ) ) {
			return;
		}

		$this->register_tax_class_label( $this->intra_label( $rate ) );
		$this->register_tax_class_label( $this->inter_label( $rate ) );

		if ( $rate > 0 ) {
			$half = round( $rate / 2, 3 );

			$this->insert_rate_row(
				$this->intra_slug( $rate ),
				sprintf( 'CGST %s%%', $this->format_number( $half ) ),
				$half,
				1
			);
			$this->insert_rate_row(
				$this->intra_slug( $rate ),
				sprintf( 'SGST %s%%', $this->format_number( $half ) ),
				$half,
				2
			);
			$this->insert_rate_row(
				$this->inter_slug( $rate ),
				sprintf( 'IGST %s%%', $this->format_number( $rate ) ),
				$rate,
				1
			);
		}

		$provisioned[ $key ] = true;
		update_option( self::PROVISIONED_OPTION, $provisioned );
	}

	private function register_tax_class_label( $label ) {
		if ( ! class_exists( 'WC_Tax' ) ) {
			return;
		}

		$classes = WC_Tax::get_tax_classes();
		if ( in_array( $label, $classes, true ) ) {
			return;
		}

		$classes[] = $label;
		update_option( 'woocommerce_tax_classes', implode( "\n", $classes ) );
	}

	private function insert_rate_row( $class_slug, $name, $rate, $priority ) {
		if ( ! class_exists( 'WC_Tax' ) ) {
			return;
		}

		WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => '',
				'tax_rate_state'    => '',
				'tax_rate'          => number_format( (float) $rate, 4, '.', '' ),
				'tax_rate_name'     => $name,
				'tax_rate_priority' => $priority,
				'tax_rate_compound' => 0,
				'tax_rate_shipping' => 1,
				'tax_rate_order'    => $priority,
				'tax_rate_class'    => $class_slug,
			)
		);
	}

	/**
	 * Chooses the Intra (CGST+SGST) or Inter (IGST) tax class for a product at
	 * calculation time, based on the selling vendor's state vs the buyer's state.
	 */
	public function filter_product_tax_class( $tax_class, $product ) {
		$settings = WGT_Admin_Settings::get_settings();
		if ( 'yes' !== $settings['enable_gst'] || ! $product ) {
			return $tax_class;
		}

		if ( ! $this->buyer_is_in_india() ) {
			return $tax_class;
		}

		$product_id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		$rate       = WGT_Product_Fields::get_gst_rate( $product_id );

		if ( $rate <= 0 ) {
			return $tax_class;
		}

		$this->ensure_gst_tax_classes( $rate );

		$vendor_state   = $this->get_vendor_state_for_product( $product_id );
		$customer_state = $this->get_customer_state();

		if ( $vendor_state && $customer_state && $vendor_state === $customer_state ) {
			return $this->intra_slug( $rate );
		}

		return $this->inter_slug( $rate );
	}

	/**
	 * Records the HSN code, GST rate and selling vendor on each order line at
	 * checkout time, so later reporting/invoicing never has to re-derive them
	 * from a product that may since have changed or been deleted.
	 */
	public function stamp_line_item_meta( $item, $cart_item_key, $values, $order ) {
		if ( ! isset( $values['product_id'] ) ) {
			return;
		}

		$product_id = $values['product_id'];
		$hsn        = WGT_Product_Fields::get_hsn( $product_id );
		$rate       = WGT_Product_Fields::get_gst_rate( $product_id );
		$vendor_id  = function_exists( 'wcfm_get_vendor_id_by_post' ) ? wcfm_get_vendor_id_by_post( $product_id ) : 0;

		if ( $hsn ) {
			$item->add_meta_data( '_wgt_hsn_code', $hsn, true );
		}
		$item->add_meta_data( '_wgt_gst_rate', $rate, true );
		if ( $vendor_id ) {
			$item->add_meta_data( '_wgt_vendor_id', $vendor_id, true );
			$item->add_meta_data( '_wgt_vendor_state', WGT_Vendor_Settings::get_vendor_state( $vendor_id ), true );
		}
	}

	private function get_vendor_state_for_product( $product_id ) {
		if ( ! function_exists( 'wcfm_get_vendor_id_by_post' ) ) {
			return '';
		}
		$vendor_id = wcfm_get_vendor_id_by_post( $product_id );
		if ( ! $vendor_id ) {
			return '';
		}
		return WGT_Vendor_Settings::get_vendor_state( $vendor_id );
	}

	private function get_customer_state() {
		if ( ! function_exists( 'WC' ) || ! WC()->customer ) {
			return '';
		}

		$state = WC()->customer->get_shipping_state();
		if ( ! $state ) {
			$state = WC()->customer->get_billing_state();
		}
		if ( ! $state && WC()->countries ) {
			$state = WC()->countries->get_base_state();
		}
		return $state;
	}

	private function buyer_is_in_india() {
		if ( ! function_exists( 'WC' ) || ! WC()->customer ) {
			return true;
		}

		$country = WC()->customer->get_shipping_country();
		if ( ! $country ) {
			$country = WC()->customer->get_billing_country();
		}
		if ( ! $country && WC()->countries ) {
			$country = WC()->countries->get_base_country();
		}

		return ! $country || 'IN' === $country;
	}

	private function rate_key( $rate ) {
		return str_replace( '.', '_', (string) round( (float) $rate, 2 ) );
	}

	private function format_number( $number ) {
		$formatted = rtrim( rtrim( number_format( (float) $number, 3, '.', '' ), '0' ), '.' );
		return '' === $formatted ? '0' : $formatted;
	}

	private function intra_label( $rate ) {
		return sprintf( 'GST %s%% Intra-State', $this->format_number( $rate ) );
	}

	private function inter_label( $rate ) {
		return sprintf( 'GST %s%% Inter-State', $this->format_number( $rate ) );
	}

	public function intra_slug( $rate ) {
		return sanitize_title( $this->intra_label( $rate ) );
	}

	public function inter_slug( $rate ) {
		return sanitize_title( $this->inter_label( $rate ) );
	}
}
