<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets the marketplace operator (any user with 'manage_woocommerce' — administrator or
 * shop manager) view and update GST/TCS rates and the rest of this plugin's settings from
 * WCFM's own frontend dashboard, without needing wp-admin at all. WCFM's frontend
 * dashboard is role-aware under one URL structure: the same 'wcfm_menus'/'wcfm_load_views'
 * extension points the vendor-facing GST & TCS tab (WGT_Vendor_Dashboard) already uses are
 * reused here, just gated on 'manage_woocommerce' instead of a vendor role — exactly how
 * WCFM's own dashboard tells an admin's view of the dashboard apart from a vendor's.
 *
 * The save path goes through admin-post.php rather than a same-page POST, because
 * admin-post.php always bootstraps the full wp-admin environment before running (the same
 * reason every other frontend export/download form in this plugin already posts there) —
 * that keeps check_admin_referer()/current_user_can() behaving identically to the wp-admin
 * settings page, and lets the handler safely reuse WGT_Admin_Settings::build_settings_from_post().
 * The *rendering* of this tab, however, runs in a pure frontend request (via 'wcfm_load_views'),
 * where wp-admin-only functions such as submit_button(), add_settings_error() and
 * settings_errors() are undefined and would fatal — this class never calls them, using a
 * plain <button> and a short-lived transient notice instead.
 */
class WGT_Admin_Frontend_Settings {

	const WCFM_ENDPOINT = 'wgt-admin-settings';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'add_wcfm_endpoint' ), 5 );
		add_filter( 'wcfm_query_vars', array( $this, 'add_wcfm_query_var' ) );
		add_filter( 'wcfm_menus', array( $this, 'add_wcfm_menu_item' ) );
		add_filter( 'wcfm_endpoint_' . self::WCFM_ENDPOINT . '_title', array( $this, 'wcfm_page_title' ) );
		add_action( 'wcfm_load_views', array( $this, 'render_wcfm_view' ) );

		add_action( 'admin_post_wgt_save_settings_frontend', array( $this, 'handle_save' ) );
	}

	/**
	 * Guarded by its own one-time flush flag (not WGT_Vendor_Dashboard's) so this endpoint
	 * works even on a site that already flushed rewrite rules before this tab existed.
	 */
	public function add_wcfm_endpoint() {
		add_rewrite_endpoint( self::WCFM_ENDPOINT, EP_ALL );

		if ( 'yes' !== get_option( 'wgt_admin_settings_endpoint_flushed' ) ) {
			flush_rewrite_rules( false );
			update_option( 'wgt_admin_settings_endpoint_flushed', 'yes' );
		}
	}

	public function add_wcfm_query_var( $query_vars ) {
		if ( is_array( $query_vars ) ) {
			$query_vars[ self::WCFM_ENDPOINT ] = self::WCFM_ENDPOINT;
		}
		return $query_vars;
	}

	/**
	 * Only shown to a 'manage_woocommerce' user (administrator/shop manager) viewing their
	 * own WCFM dashboard — ordinary vendors don't hold that capability, so this never
	 * appears on a vendor's sidebar alongside their own GST & TCS report tab.
	 */
	public function add_wcfm_menu_item( $wcfm_menus ) {
		if ( ! is_array( $wcfm_menus ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return $wcfm_menus;
		}

		$url = function_exists( 'wcfm_get_endpoint_url' )
			? wcfm_get_endpoint_url( self::WCFM_ENDPOINT )
			: add_query_arg( self::WCFM_ENDPOINT, '1' );

		$wcfm_menus[ self::WCFM_ENDPOINT ] = array(
			'label' => __( 'GST & TCS Settings', 'wcfm-gst-tcs' ),
			'url'   => $url,
			'icon'  => 'cog',
		);

		return $wcfm_menus;
	}

	public function wcfm_page_title( $title ) {
		return __( 'GST & TCS Settings', 'wcfm-gst-tcs' );
	}

	private function endpoint_url() {
		return function_exists( 'wcfm_get_endpoint_url' )
			? wcfm_get_endpoint_url( self::WCFM_ENDPOINT )
			: add_query_arg( self::WCFM_ENDPOINT, '1' );
	}

	/**
	 * Fires for every WCFM dashboard endpoint WCFM doesn't handle internally; we only act
	 * when it's ours (same pattern as WGT_Vendor_Dashboard::render_wcfm_view()).
	 */
	public function render_wcfm_view( $end_point ) {
		if ( self::WCFM_ENDPOINT !== $end_point ) {
			return;
		}

		echo '<div class="wcfm-content-inner wgt-wcfm-settings">';

		if ( ! is_user_logged_in() || ! current_user_can( 'manage_woocommerce' ) ) {
			// Belt-and-braces: the menu item is already hidden from anyone else, but the
			// endpoint URL itself isn't secret, so a direct visit is checked again here.
			echo '<p>' . esc_html__( 'These settings are only available to the marketplace operator.', 'wcfm-gst-tcs' ) . '</p>';
			echo '</div>';
			return;
		}

		$this->render_notice();

		$settings = WGT_Admin_Settings::get_settings();
		$states   = WGT_States::get_indian_states();
		?>
		<p><?php esc_html_e( 'Configure GST tax calculation and GST-TCS (Section 52) collection for your WCFM multivendor marketplace — the same settings as wp-admin\'s GST & TCS page.', 'wcfm-gst-tcs' ); ?></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wgt_save_settings_frontend" />
			<?php wp_nonce_field( 'wgt_save_settings_frontend' ); ?>
			<?php WGT_Admin_Settings::render_settings_fields( $settings, $states ); ?>
			<p class="submit"><button type="submit" class="button wgt-wcfm-settings-submit"><?php esc_html_e( 'Save Settings', 'wcfm-gst-tcs' ); ?></button></p>
		</form>
		<?php
		echo '</div>';
	}

	private function render_notice() {
		$result = get_transient( 'wgt_admin_settings_frontend_result_' . get_current_user_id() );
		if ( ! $result ) {
			return;
		}
		delete_transient( 'wgt_admin_settings_frontend_result_' . get_current_user_id() );
		?>
		<div class="woocommerce-<?php echo esc_attr( empty( $result['errors'] ) ? 'message' : 'error' ); ?>">
			<p><?php echo esc_html( $result['message'] ); ?></p>
			<?php if ( ! empty( $result['errors'] ) ) : ?>
				<ul style="margin-left:1.5em;list-style:disc;">
					<?php foreach ( $result['errors'] as $error ) : ?>
						<li><?php echo esc_html( $error ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Runs via admin-post.php, so it is always in a full wp-admin-bootstrapped context
	 * even though the form that posts here is rendered on the frontend — current_user_can(),
	 * check_admin_referer() and WGT_Admin_Settings::build_settings_from_post() all behave
	 * exactly as they do for the wp-admin settings page's own save handler.
	 */
	public function handle_save() {
		check_admin_referer( 'wgt_save_settings_frontend' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wcfm-gst-tcs' ) );
		}

		$errors   = array();
		$settings = WGT_Admin_Settings::build_settings_from_post( $errors );
		update_option( 'wgt_settings', $settings );

		$messages = array();
		foreach ( $errors as $error ) {
			$messages[] = $error['message'];
		}

		set_transient(
			'wgt_admin_settings_frontend_result_' . get_current_user_id(),
			array(
				'message' => __( 'GST & TCS settings saved.', 'wcfm-gst-tcs' ),
				'errors'  => $messages,
			),
			MINUTE_IN_SECONDS
		);

		$redirect = wp_get_referer() ? wp_get_referer() : $this->endpoint_url();
		wp_safe_redirect( $redirect );
		exit;
	}
}
