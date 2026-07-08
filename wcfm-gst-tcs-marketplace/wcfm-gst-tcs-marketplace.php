<?php
/**
 * Plugin Name: WCFM GST & TCS for Multivendor Marketplace
 * Plugin URI: https://example.com/wcfm-gst-tcs-marketplace
 * Description: Adds India GST (CGST/SGST/IGST) tax calculation and GST-TCS (Sec 52) compliance to a WCFM Marketplace multivendor store — per-product HSN/GST rates, vendor GSTIN capture, B2B checkout, PDF GST invoices, and GSTR-1/GSTR-8 style reports.
 * Version: 1.3.0
 * Author: Soloman Dev
 * Text Domain: wcfm-gst-tcs
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce, wc-frontend-manager, wc-multivendor-marketplace
 * WC requires at least: 6.0
 * WC tested up to: 9.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WGT_VERSION', '1.3.0' );
define( 'WGT_PLUGIN_FILE', __FILE__ );
define( 'WGT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WGT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WGT_TCS_TABLE', 'wgt_tcs_ledger' );

/*
 * Loaded unconditionally and immediately (not deferred to plugins_loaded) because
 * register_activation_hook()'s callback runs in the same request that first includes
 * this file — plugins_loaded has typically already fired for the rest of the site by
 * then, so a class only required inside a plugins_loaded callback isn't defined yet
 * when WordPress calls the activation hook, and activation fatals with a "class not
 * found" error. None of these files touch WooCommerce/WCFM at parse time — they only
 * call into it from inside methods — so it's safe to load them before checking whether
 * WooCommerce/WCFM are even active; that check still gates whether anything is
 * *instantiated*, in init() below.
 */
$wgt_autoload = WGT_PLUGIN_DIR . 'vendor/autoload.php';
if ( file_exists( $wgt_autoload ) && ! class_exists( 'Dompdf\\Dompdf' ) ) {
	require_once $wgt_autoload;
}
unset( $wgt_autoload );

require_once WGT_PLUGIN_DIR . 'includes/class-wgt-states.php';
require_once WGT_PLUGIN_DIR . 'includes/class-wgt-install.php';
require_once WGT_PLUGIN_DIR . 'includes/class-wgt-admin-settings.php';
require_once WGT_PLUGIN_DIR . 'includes/class-wgt-vendor-settings.php';
require_once WGT_PLUGIN_DIR . 'includes/class-wgt-product-fields.php';
require_once WGT_PLUGIN_DIR . 'includes/class-wgt-tax-engine.php';
require_once WGT_PLUGIN_DIR . 'includes/class-wgt-tcs-engine.php';
require_once WGT_PLUGIN_DIR . 'includes/class-wgt-invoice.php';
require_once WGT_PLUGIN_DIR . 'includes/class-wgt-csv-export.php';
require_once WGT_PLUGIN_DIR . 'includes/class-wgt-admin-reports.php';
require_once WGT_PLUGIN_DIR . 'includes/class-wgt-export-job.php';
require_once WGT_PLUGIN_DIR . 'includes/class-wgt-vendor-dashboard.php';
require_once WGT_PLUGIN_DIR . 'includes/class-wgt-b2b-checkout.php';

final class WCFM_GST_TCS_Plugin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		register_activation_hook( WGT_PLUGIN_FILE, array( 'WGT_Install', 'activate' ) );
		register_deactivation_hook( WGT_PLUGIN_FILE, array( $this, 'deactivate' ) );

		add_action( 'plugins_loaded', array( $this, 'init' ), 20 );
		add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );
	}

	public function deactivate() {
		wp_clear_scheduled_hook( 'wgt_cleanup_exports' );
	}

	public function declare_hpos_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WGT_PLUGIN_FILE, true );
		}
	}

	public function init() {
		if ( ! $this->dependencies_active() ) {
			add_action( 'admin_notices', array( $this, 'dependency_notice' ) );
			return;
		}

		load_plugin_textdomain( 'wcfm-gst-tcs', false, dirname( plugin_basename( WGT_PLUGIN_FILE ) ) . '/languages' );

		WGT_Admin_Settings::instance();
		WGT_Vendor_Settings::instance();
		WGT_Product_Fields::instance();
		WGT_Tax_Engine::instance();
		WGT_TCS_Engine::instance();
		WGT_Invoice::instance();
		WGT_Admin_Reports::instance();
		WGT_Export_Job::instance();
		WGT_Vendor_Dashboard::instance();
		WGT_B2B_Checkout::instance();

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function enqueue_assets() {
		if ( is_admin() ) {
			$load = true;
		} else {
			$load = is_account_page() || is_checkout() || ( function_exists( 'wcfm_is_wcfm_page' ) && wcfm_is_wcfm_page() );
		}

		if ( ! $load ) {
			return;
		}

		wp_enqueue_style( 'wgt-admin', WGT_PLUGIN_URL . 'assets/css/wgt-admin.css', array(), WGT_VERSION );
		wp_enqueue_script( 'wgt-admin', WGT_PLUGIN_URL . 'assets/js/wgt-admin.js', array( 'jquery' ), WGT_VERSION, true );
		wp_localize_script(
			'wgt-admin',
			'wgtAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wgt_ajax_nonce' ),
			)
		);
	}

	private function dependencies_active() {
		$active_plugins = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active_plugins = array_merge( $active_plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		$has_wc    = class_exists( 'WooCommerce' );
		$has_wcfm  = defined( 'WCFM_VERSION' ) || class_exists( 'WeCodeArt\\WCFM' ) || function_exists( 'wcfm_is_vendor' ) || class_exists( 'WCFMmp' );

		return $has_wc && $has_wcfm;
	}

	public function dependency_notice() {
		?>
		<div class="notice notice-error">
			<p>
				<?php esc_html_e( 'WCFM GST & TCS for Multivendor Marketplace requires WooCommerce, WCFM – Frontend Manager and WCFM Marketplace to be installed and active.', 'wcfm-gst-tcs' ); ?>
			</p>
		</div>
		<?php
	}

}

WCFM_GST_TCS_Plugin::instance();
