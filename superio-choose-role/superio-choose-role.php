<?php
/**
 * Plugin Name: Superio - Choose Role After Social Login
 * Description: Asks users who signed in with Google / social login to choose Candidate or Employer, then sends them to the matching dashboard.
 * Version:     1.0.0
 * Author:      SOLOMAN-DEV
 * License:     GPL-2.0-or-later
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * ---------------------------------------------------------------------------
 * Settings - adjust here if your WP Job Board Pro version uses other names.
 * ---------------------------------------------------------------------------
 */

// Choice => WordPress role created by WP Job Board Pro.
const SCR_ROLES = array(
	'candidate' => 'wp_job_board_pro_candidate',
	'employer'  => 'wp_job_board_pro_employer',
);

// Slug of the "Choose Your Role" page (created automatically).
const SCR_PAGE_SLUG = 'choose-role';

// Fallback dashboard URL, used only if the WP Job Board Pro dashboard page setting is empty.
const SCR_FALLBACK_DASHBOARD = '/user-dashboard/';

/*
 * ---------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------------
 */

function scr_page_url() {
	return home_url( '/' . SCR_PAGE_SLUG . '/' );
}

/**
 * True for a logged-in user who has neither job role and is not site staff.
 */
function scr_needs_role( $user ) {
	if ( ! $user instanceof WP_User || ! $user->exists() ) {
		return false;
	}
	if ( user_can( $user, 'manage_options' ) || user_can( $user, 'manage_woocommerce' ) || user_can( $user, 'edit_others_posts' ) ) {
		return false;
	}
	return ! array_intersect( array_values( SCR_ROLES ), (array) $user->roles );
}

/**
 * 'candidate', 'employer' or '' for the given user.
 */
function scr_user_type( WP_User $user ) {
	foreach ( SCR_ROLES as $type => $role ) {
		if ( in_array( $role, (array) $user->roles, true ) ) {
			return $type;
		}
	}
	return '';
}

/**
 * Dashboard URL for a candidate or employer.
 *
 * Superio uses one "User Dashboard" page that shows the candidate or employer
 * view based on the user's role. To use separate pages, hook the
 * 'scr_dashboard_url' filter, e.g.:
 *
 *   add_filter( 'scr_dashboard_url', function ( $url, $type ) {
 *       return $type === 'employer' ? home_url( '/employer-dashboard/' ) : home_url( '/candidate-dashboard/' );
 *   }, 10, 2 );
 */
function scr_dashboard_url( $type ) {
	$url = '';
	if ( function_exists( 'wp_job_board_pro_get_option' ) ) {
		$page_id = wp_job_board_pro_get_option( 'user_dashboard_page_id' );
		if ( $page_id ) {
			$url = get_permalink( $page_id );
		}
	}
	if ( ! $url ) {
		$url = home_url( SCR_FALLBACK_DASHBOARD );
	}
	return apply_filters( 'scr_dashboard_url', $url, $type );
}

/**
 * Create the linked candidate/employer profile post that Superio's own
 * registration form would have created.
 */
function scr_ensure_profile( WP_User $user, $type ) {
	$existing = (int) get_user_meta( $user->ID, $type . '_id', true );
	if ( $existing && get_post( $existing ) ) {
		return;
	}

	if ( ! post_type_exists( $type ) ) {
		return; // WP Job Board Pro not active.
	}

	$post_id = wp_insert_post(
		array(
			'post_type'   => $type, // 'candidate' or 'employer'
			'post_title'  => $user->display_name ? $user->display_name : $user->user_login,
			'post_status' => 'publish',
			'post_author' => $user->ID,
		)
	);
	if ( ! $post_id || is_wp_error( $post_id ) ) {
		return;
	}

	update_user_meta( $user->ID, $type . '_id', $post_id );
	update_post_meta( $post_id, '_' . $type . '_user_id', $user->ID );
	update_post_meta( $post_id, '_' . $type . '_email', $user->user_email );

	do_action( 'scr_profile_created', $post_id, $user, $type );
}

/*
 * ---------------------------------------------------------------------------
 * Create the "Choose Your Role" page if it does not exist.
 * ---------------------------------------------------------------------------
 */

function scr_create_page() {
	if ( get_page_by_path( SCR_PAGE_SLUG ) ) {
		return;
	}
	wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_title'   => 'Choose Your Role',
			'post_name'    => SCR_PAGE_SLUG,
			'post_content' => '<!-- wp:shortcode -->[choose_user_role]<!-- /wp:shortcode -->',
			'post_status'  => 'publish',
		)
	);
}
register_activation_hook( __FILE__, 'scr_create_page' );

// Also covers installs as a must-use plugin, where activation hooks never run.
add_action(
	'admin_init',
	function () {
		if ( current_user_can( 'manage_options' ) && ! get_page_by_path( SCR_PAGE_SLUG ) ) {
			scr_create_page();
		}
	}
);

/*
 * ---------------------------------------------------------------------------
 * Redirects
 * ---------------------------------------------------------------------------
 */

// Right after login: users without a role go to the chooser, others to their dashboard.
add_filter(
	'login_redirect',
	function ( $redirect, $requested, $user ) {
		if ( ! $user instanceof WP_User ) {
			return $redirect;
		}
		if ( scr_needs_role( $user ) ) {
			return scr_page_url();
		}
		return $redirect;
	},
	999,
	3
);

// Handle the form, and keep users on the chooser until they pick a role.
// This also catches social login plugins that do not use login_redirect.
add_action(
	'template_redirect',
	function () {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$user = wp_get_current_user();

		if ( is_page( SCR_PAGE_SLUG ) && isset( $_POST['scr_role'] ) ) {
			check_admin_referer( 'scr_choose_role' );
			$type = sanitize_key( wp_unslash( $_POST['scr_role'] ) );

			if ( scr_needs_role( $user ) && isset( SCR_ROLES[ $type ] ) ) {
				$user->set_role( SCR_ROLES[ $type ] );
				scr_ensure_profile( $user, $type );
				wp_safe_redirect( scr_dashboard_url( $type ) );
				exit;
			}
		}

		if ( scr_needs_role( $user ) ) {
			if ( ! is_page( SCR_PAGE_SLUG ) ) {
				wp_safe_redirect( scr_page_url() );
				exit;
			}
			return;
		}

		// A user who already has a role and opens the chooser goes to their dashboard.
		if ( is_page( SCR_PAGE_SLUG ) ) {
			$type = scr_user_type( $user );
			wp_safe_redirect( $type ? scr_dashboard_url( $type ) : home_url( '/' ) );
			exit;
		}
	},
	1
);

/*
 * ---------------------------------------------------------------------------
 * The chooser form: [choose_user_role]
 * ---------------------------------------------------------------------------
 */

add_shortcode(
	'choose_user_role',
	function () {
		if ( ! is_user_logged_in() ) {
			return '<p>Please log in first.</p>';
		}
		$user = wp_get_current_user();
		ob_start();
		?>
		<style>
			.scr-wrap{max-width:720px;margin:40px auto;text-align:center}
			.scr-wrap h2{margin-bottom:8px}
			.scr-wrap p.scr-sub{margin-bottom:28px;opacity:.8}
			.scr-choices{display:flex;gap:20px;flex-wrap:wrap;justify-content:center}
			.scr-choice{flex:1 1 260px;max-width:320px;padding:28px 20px;border:2px solid #e5e7eb;border-radius:12px;background:#fff;cursor:pointer;transition:border-color .2s,box-shadow .2s;text-align:center}
			.scr-choice:hover,.scr-choice:focus{border-color:#1967d2;box-shadow:0 6px 20px rgba(25,103,210,.15);outline:none}
			.scr-choice strong{display:block;font-size:20px;margin-bottom:6px;color:#202124}
			.scr-choice span{display:block;font-size:14px;color:#696969}
			.scr-icon{font-size:36px;display:block;margin-bottom:10px}
		</style>
		<div class="scr-wrap">
			<h2><?php echo esc_html( sprintf( 'Welcome, %s!', $user->display_name ) ); ?></h2>
			<p class="scr-sub">Tell us how you will use the site. You can only choose once.</p>
			<form method="post" class="scr-choices">
				<?php wp_nonce_field( 'scr_choose_role' ); ?>
				<button type="submit" name="scr_role" value="candidate" class="scr-choice">
					<span class="scr-icon" aria-hidden="true">&#128188;</span>
					<strong>I'm a Candidate</strong>
					<span>I'm looking for a job</span>
				</button>
				<button type="submit" name="scr_role" value="employer" class="scr-choice">
					<span class="scr-icon" aria-hidden="true">&#127970;</span>
					<strong>I'm an Employer</strong>
					<span>I want to post jobs and hire</span>
				</button>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}
);
