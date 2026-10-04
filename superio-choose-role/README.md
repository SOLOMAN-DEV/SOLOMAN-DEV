# Superio – Choose Role After Social Login

A WordPress plugin for job portals built with **Superio + WP Job Board Pro**.

Users who sign in with **Google (Site Kit)** or any social login skip Superio's
registration form, so they never pick Candidate or Employer. This plugin:

1. Sends every logged-in user who has neither role to a **Choose Your Role** page.
2. Gives them the `wp_job_board_pro_candidate` or `wp_job_board_pro_employer` role
   and creates their linked candidate/employer profile.
3. Redirects them to their dashboard.

Admins, editors and WooCommerce shop managers are never redirected. The role
can be chosen only once.

## Install

1. **Settings → General → New User Default Role** → set to **Subscriber**.
2. **Plugins → Add New → Upload Plugin** → upload `superio-choose-role.zip` → **Activate**.
3. A page **Choose Your Role** (`/choose-role/`) containing `[choose_user_role]`
   is created automatically. If it isn't, create it yourself with that shortcode.
4. If permalinks show 404 for `/choose-role/`, open **Settings → Permalinks** and click **Save**.

## Test

1. Log in with a Google account that has never used the site → you land on `/choose-role/`.
2. Pick **Candidate** → you land on the candidate dashboard.
3. Log out, log in again → you go straight to the dashboard (no chooser).
4. Repeat with another account as **Employer**.

## Verify the profile meta keys (important)

The plugin writes the same data Superio's registration form normally writes:

| Where     | Key                                         |
|-----------|---------------------------------------------|
| User meta | `candidate_id` / `employer_id`              |
| Post meta | `_candidate_user_id` / `_employer_user_id`  |
| Post meta | `_candidate_email` / `_employer_email`      |

Register one test candidate through Superio's normal form and compare its meta
(phpMyAdmin → `wp_usermeta` / `wp_postmeta`). If your version uses different
keys, edit `scr_ensure_profile()` in `superio-choose-role.php`.

## Separate dashboard pages

By default the plugin uses the **User Dashboard** page set in
**WP Job Board Pro → Settings**, which Superio renders per role. To send each
role to its own page, add this to your child theme's `functions.php`:

```php
add_filter( 'scr_dashboard_url', function ( $url, $type ) {
	return $type === 'employer'
		? home_url( '/employer-dashboard/' )
		: home_url( '/candidate-dashboard/' );
}, 10, 2 );
```
