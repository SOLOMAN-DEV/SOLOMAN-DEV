<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets a customer mark an order as a business purchase and supply their
 * Company Name + GSTIN, so the order/invoice carries what they need to claim
 * input tax credit. Rides on WooCommerce's own billing field pipeline
 * (billing_company already exists natively; billing_gstin is the one custom
 * field added here) rather than a bespoke form, so it also shows up for free
 * in My Account address editing, the admin order billing box, and address
 * autofill on repeat orders.
 */
class WGT_B2B_Checkout {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		if ( ! $this->enabled() ) {
			return;
		}

		add_filter( 'woocommerce_billing_fields', array( $this, 'add_fields' ), 20, 1 );
		add_action( 'woocommerce_checkout_process', array( $this, 'validate' ) );
		add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'save_order_meta' ) );
		add_filter( 'woocommerce_admin_billing_fields', array( $this, 'add_admin_fields' ) );
		add_action( 'woocommerce_order_details_after_customer_details', array( $this, 'render_on_order_details' ) );
		add_action( 'woocommerce_email_customer_details', array( $this, 'render_on_order_details' ), 20, 4 );
	}

	private function enabled() {
		$settings = WGT_Admin_Settings::get_settings();
		return 'yes' === $settings['enable_b2b'];
	}

	private function require_gstin() {
		$settings = WGT_Admin_Settings::get_settings();
		return 'yes' === $settings['require_gstin_for_business'];
	}

	public function add_fields( $fields ) {
		if ( isset( $fields['billing_company'] ) ) {
			$fields['billing_company']['class'][] = 'wgt-b2b-field';
			$fields['billing_company']['priority'] = 31;
		}

		$fields['billing_is_business'] = array(
			'type'     => 'checkbox',
			'label'    => __( 'This is a business purchase (I need a GST invoice)', 'wcfm-gst-tcs' ),
			'required' => false,
			'class'    => array( 'form-row-wide', 'wgt-b2b-toggle' ),
			'priority' => 25,
		);

		$default_gstin = '';
		if ( is_user_logged_in() ) {
			$default_gstin = get_user_meta( get_current_user_id(), 'billing_gstin', true );
		}

		$fields['billing_gstin'] = array(
			'type'              => 'text',
			'label'             => __( 'GSTIN', 'wcfm-gst-tcs' ),
			'required'          => false,
			'class'             => array( 'form-row-wide', 'wgt-gstin-input', 'wgt-b2b-field' ),
			'priority'          => 32,
			'default'           => $default_gstin,
			'custom_attributes' => array( 'maxlength' => 15 ),
		);

		return $fields;
	}

	public function validate() {
		if ( empty( $_POST['billing_is_business'] ) ) {
			return;
		}

		$company = isset( $_POST['billing_company'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_company'] ) ) : '';
		$gstin   = isset( $_POST['billing_gstin'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['billing_gstin'] ) ) ) : '';

		if ( '' === $company ) {
			wc_add_notice( __( 'Please enter your Company Name for a GST invoice.', 'wcfm-gst-tcs' ), 'error' );
		}

		if ( $this->require_gstin() && ( '' === $gstin || ! WGT_States::is_valid_gstin( $gstin ) ) ) {
			wc_add_notice( __( 'Please enter a valid 15-character GSTIN.', 'wcfm-gst-tcs' ), 'error' );
			return;
		} elseif ( '' !== $gstin && ! WGT_States::is_valid_gstin( $gstin ) ) {
			wc_add_notice( __( 'The GSTIN entered does not look valid. Please check and try again.', 'wcfm-gst-tcs' ), 'error' );
			return;
		}

		if ( '' === $gstin ) {
			return;
		}

		if ( ! WGT_States::passes_external_verification( $gstin ) ) {
			wc_add_notice( __( 'This GSTIN could not be verified. Please check and try again.', 'wcfm-gst-tcs' ), 'error' );
			return;
		}

		// Not blocking: a registered address can legitimately differ from the ship-to/bill-to
		// address entered here, so this is a nudge to double-check, not a hard requirement.
		$gstin_state  = WGT_States::state_from_gstin( $gstin );
		$billing_state = isset( $_POST['billing_state'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_state'] ) ) : '';
		if ( $gstin_state && $billing_state && $gstin_state !== $billing_state ) {
			$states = WGT_States::get_indian_states();
			wc_add_notice(
				sprintf(
					/* translators: 1: state the GSTIN is registered in, 2: state entered as the billing address */
					__( 'Heads up: the GSTIN you entered is registered in %1$s, but your billing address state is %2$s. Double-check this is correct before placing the order.', 'wcfm-gst-tcs' ),
					isset( $states[ $gstin_state ] ) ? $states[ $gstin_state ] : $gstin_state,
					isset( $states[ $billing_state ] ) ? $states[ $billing_state ] : $billing_state
				),
				'notice'
			);
		}
	}

	public function save_order_meta( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$is_business = ! empty( $_POST['billing_is_business'] );
		$order->update_meta_data( '_billing_is_business', $is_business ? 'yes' : 'no' );

		if ( $is_business && ! empty( $_POST['billing_gstin'] ) ) {
			$gstin = strtoupper( sanitize_text_field( wp_unslash( $_POST['billing_gstin'] ) ) );
			$order->update_meta_data( '_billing_gstin', $gstin );

			if ( is_user_logged_in() ) {
				update_user_meta( get_current_user_id(), 'billing_gstin', $gstin );
			}
		}

		$order->save();
	}

	public function add_admin_fields( $fields ) {
		$fields['is_business'] = array(
			'label' => __( 'Business Purchase', 'wcfm-gst-tcs' ),
			'show'  => true,
		);
		$fields['gstin'] = array(
			'label' => __( 'GSTIN', 'wcfm-gst-tcs' ),
			'show'  => true,
		);
		return $fields;
	}

	public function render_on_order_details( $order, $sent_to_admin = false, $plain_text = false, $email = null ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		if ( 'yes' !== $order->get_meta( '_billing_is_business' ) ) {
			return;
		}

		$gstin   = $order->get_meta( '_billing_gstin' );
		$company = $order->get_billing_company();

		if ( ! $gstin && ! $company ) {
			return;
		}

		if ( $plain_text ) {
			echo "\n" . esc_html__( 'Business Purchase', 'wcfm-gst-tcs' ) . "\n";
			if ( $company ) {
				echo esc_html__( 'Company:', 'wcfm-gst-tcs' ) . ' ' . esc_html( $company ) . "\n";
			}
			if ( $gstin ) {
				echo esc_html__( 'GSTIN:', 'wcfm-gst-tcs' ) . ' ' . esc_html( $gstin ) . "\n";
			}
			return;
		}

		echo '<p class="wgt-buyer-gstin">';
		if ( $company ) {
			echo '<strong>' . esc_html__( 'Company:', 'wcfm-gst-tcs' ) . '</strong> ' . esc_html( $company ) . '<br/>';
		}
		if ( $gstin ) {
			echo '<strong>' . esc_html__( 'GSTIN:', 'wcfm-gst-tcs' ) . '</strong> ' . esc_html( $gstin );
		}
		echo '</p>';
	}
}
