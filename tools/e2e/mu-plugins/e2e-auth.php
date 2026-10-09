<?php
/**
 * Plugin Name: E2E auth (bench only)
 *
 * TEST BENCH ONLY. Logs a request in as the user named by the X-E2E-User
 * header, with no password and no cookie. It lives in tools/e2e/, which the
 * release workflows exclude from the plugin zip: it must never be shipped or
 * copied to a real site.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the X-E2E-User header to an existing user, or null.
 */
function e2e_auth_header_user(): ?WP_User {
	if ( empty( $_SERVER['HTTP_X_E2E_USER'] ) ) {
		return null;
	}

	$user = get_user_by( 'login', sanitize_user( wp_unslash( $_SERVER['HTTP_X_E2E_USER'] ) ) );

	return $user ? $user : null;
}

add_filter(
	'determine_current_user',
	static function ( $user_id ) {
		$user = e2e_auth_header_user();

		return $user ? $user->ID : $user_id;
	},
	99
);

/*
 * wp-admin pages are guarded by auth_redirect(), which reads the auth cookie
 * itself and ignores determine_current_user. So the header also gets a real,
 * short-lived session and the matching cookies, built before WordPress reads them.
 * Priority 0: after the pluggable functions load, before any plugin boots.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		$user = e2e_auth_header_user();
		if ( ! $user ) {
			return;
		}

		$expiration = time() + HOUR_IN_SECONDS;
		$token      = WP_Session_Tokens::get_instance( $user->ID )->create( $expiration );

		$_COOKIE[ AUTH_COOKIE ]      = wp_generate_auth_cookie( $user->ID, $expiration, 'auth', $token );
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user->ID, $expiration, 'logged_in', $token );
	},
	0
);
