<?php
/**
 * Bench check of the SKMT compatibility layer (Lumia\Tools\Core\Compat), run by
 * `run.sh assert-compat` through `wp --user=admin eval-file`. Bench only, never shipped.
 *
 * In-process half of the check: constants, filters and actions, the SMTP and
 * encryption lookups, and the attribution of leftover tables. The HTTP half
 * (wp-login.php, redirect filter, debug.log) lives in run.sh.
 */

use Lumia\Tools\Core\Compat;
use Lumia\Tools\Modules\Database\Cleanup;
use Lumia\Tools\Modules\Smtp\Crypto;
use Lumia\Tools\Modules\Smtp\Mailer;

if ( ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}

$e2e_failures = 0;

function e2e_check( bool $ok, string $label ): void {
	global $e2e_failures;
	WP_CLI::log( ( $ok ? '  ok   ' : '  FAIL ' ) . $label );
	if ( ! $ok ) {
		++$e2e_failures;
	}
}

// --- Constants -------------------------------------------------------------

WP_CLI::log( 'Constants' );
e2e_check( null === Compat::constant( 'E2E_NONE' ) && ! Compat::has_constant( 'E2E_NONE' ), 'neither defined: null / false' );

define( 'SKMT_E2E_LEGACY', 'legacy-value' );
e2e_check( 'legacy-value' === Compat::constant( 'E2E_LEGACY' ) && Compat::has_constant( 'E2E_LEGACY' ), 'only SKMT_ defined: its value is read' );

define( 'SKMT_E2E_BOTH', 'from-skmt' );
define( 'LUMIA_E2E_BOTH', 'from-lumia' );
e2e_check( 'from-lumia' === Compat::constant( 'E2E_BOTH' ), 'both defined: LUMIA_ wins' );

define( 'LUMIA_E2E_FALSE', false );
define( 'SKMT_E2E_FALSE', true );
e2e_check( false === Compat::constant( 'E2E_FALSE' ), 'LUMIA_ defined as false is not overridden by SKMT_' );

// --- Filters ---------------------------------------------------------------

WP_CLI::log( 'Filters' );

$e2e_deprecations = [];
add_action(
	'deprecated_hook_run',
	static function ( $hook, $replacement, $version ) use ( &$e2e_deprecations ) {
		$e2e_deprecations[] = [ $hook, $replacement, $version ];
	},
	10,
	3
);
// Keep the notice out of the cli output: the check reads the action above.
add_filter( 'deprecated_hook_trigger_error', '__return_false' );

$e2e_args = [];
add_filter(
	'lumia_e2e_filter',
	static function ( $value, $extra ) use ( &$e2e_args ) {
		$e2e_args['lumia'] = $extra;
		return $value . '+lumia';
	},
	10,
	2
);
e2e_check( 'base+lumia' === Compat::apply_filters( 'e2e_filter', 'base', 'arg' ) && [] === $e2e_deprecations, 'no legacy hook: lumia_ only, no notice' );

add_filter(
	'skmt_e2e_filter',
	static function ( $value, $extra ) use ( &$e2e_args ) {
		$e2e_args['skmt'] = $extra;
		return $value . '+skmt';
	},
	10,
	2
);
e2e_check( 'base+skmt+lumia' === Compat::apply_filters( 'e2e_filter', 'base', 'arg' ), 'legacy filter runs first, its result feeds lumia_' );
e2e_check( 'arg' === ( $e2e_args['skmt'] ?? null ) && 'arg' === ( $e2e_args['lumia'] ?? null ), 'extra arguments reach both filters' );
e2e_check(
	1 === count( $e2e_deprecations ) && array( 'skmt_e2e_filter', 'lumia_e2e_filter', '2.0.0' ) === $e2e_deprecations[0],
	'one deprecation: skmt_e2e_filter -> lumia_e2e_filter since 2.0.0'
);

// --- Actions ---------------------------------------------------------------

WP_CLI::log( 'Actions' );

$e2e_deprecations = [];
$e2e_calls        = [];
add_action(
	'lumia_e2e_action',
	static function ( $a, $b ) use ( &$e2e_calls ) {
		$e2e_calls[] = [ 'lumia', $a, $b ];
	},
	10,
	2
);
Compat::do_action( 'e2e_action', 1, 2 );
e2e_check( [ [ 'lumia', 1, 2 ] ] === $e2e_calls && [] === $e2e_deprecations, 'no legacy hook: lumia_ only, no notice' );

$e2e_calls = [];
add_action(
	'skmt_e2e_action',
	static function ( $a, $b ) use ( &$e2e_calls ) {
		$e2e_calls[] = [ 'skmt', $a, $b ];
	},
	10,
	2
);
Compat::do_action( 'e2e_action', 1, 2 );
e2e_check( [ [ 'skmt', 1, 2 ], [ 'lumia', 1, 2 ] ] === $e2e_calls, 'legacy action fires first, with the same arguments, then lumia_' );
e2e_check(
	1 === count( $e2e_deprecations ) && array( 'skmt_e2e_action', 'lumia_e2e_action', '2.0.0' ) === $e2e_deprecations[0],
	'one deprecation: skmt_e2e_action -> lumia_e2e_action since 2.0.0'
);

// --- No direct read of the five constants nor of the nine hooks left -------

WP_CLI::log( 'Call sites' );

$e2e_literals = [];
foreach ( [ 'module_definitions', 'register_modules', 'custom_login_redirect', 'db_table_owner_aliases', 'activity_log_post_types', 'activity_log_content_meta_keys', 'activity_log_tracked_options', 'activity_log_record', 'mc_editor_excluded_slugs' ] as $e2e_hook ) {
	$e2e_literals[ 'lumia_' . $e2e_hook ] = true;
	$e2e_literals[ 'skmt_' . $e2e_hook ]  = true;
}
foreach ( [ 'DISABLE_LOGIN_URL', 'SMTP_USER', 'SMTP_PASSWORD', 'BREVO_API_KEY', 'ENCRYPTION_KEY' ] as $e2e_const ) {
	$e2e_literals[ 'LUMIA_' . $e2e_const ] = true;
	$e2e_literals[ 'SKMT_' . $e2e_const ]  = true;
}

// A token-level scan: a string literal that is exactly one of these names (a hook
// or a defined() argument), or a bare constant use. Help texts (longer strings)
// and comments are not tokens of that kind.
$e2e_direct   = [];
$e2e_iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WP_PLUGIN_DIR . '/lumia-tools/includes', FilesystemIterator::SKIP_DOTS ) );
foreach ( $e2e_iterator as $e2e_file ) {
	// Compat reads the legacy names on purpose; FromSkmt hooks SKMT's own activity log
	// filter during the migration (it writes, it does not offer a compatibility read).
	if ( 'php' !== $e2e_file->getExtension() || in_array( $e2e_file->getFilename(), [ 'Compat.php', 'FromSkmt.php' ], true ) ) {
		continue;
	}
	foreach ( token_get_all( (string) file_get_contents( $e2e_file->getPathname() ) ) as $e2e_token ) {
		if ( ! is_array( $e2e_token ) ) {
			continue;
		}
		$e2e_text = T_CONSTANT_ENCAPSED_STRING === $e2e_token[0] ? trim( $e2e_token[1], '\'"' ) : ( T_STRING === $e2e_token[0] ? $e2e_token[1] : '' );
		if ( isset( $e2e_literals[ $e2e_text ] ) ) {
			$e2e_direct[] = $e2e_file->getFilename() . ':' . $e2e_token[2] . ' ' . $e2e_text;
		}
	}
}
e2e_check( [] === $e2e_direct, 'no direct apply_filters/do_action/defined()/constant use left' . ( $e2e_direct ? ' (' . implode( ', ', $e2e_direct ) . ')' : '' ) );

// --- SMTP and encryption lookups ------------------------------------------

WP_CLI::log( 'SMTP and encryption (SKMT_ constants)' );

delete_option( Mailer::PASSWORD_OPTION );
delete_option( Mailer::BREVO_KEY_OPTION );
e2e_check( '' === Mailer::username( [] ) && ! Mailer::has_brevo_key(), 'baseline: no user, no Brevo key' );

define( 'SKMT_SMTP_USER', 'legacy-user' );
define( 'SKMT_SMTP_PASSWORD', 'legacy-password' );
define( 'SKMT_BREVO_API_KEY', 'xkeysib-legacy' );
e2e_check( 'legacy-user' === Mailer::username( [ 'username' => 'db-user' ] ), 'SKMT_SMTP_USER overrides the saved user' );
e2e_check( 'legacy-password' === Mailer::password(), 'SKMT_SMTP_PASSWORD is read' );
e2e_check( Mailer::has_brevo_key() && 'xkeysib-legacy' === Mailer::brevo_key(), 'SKMT_BREVO_API_KEY is read' );

$e2e_plain = Crypto::encrypt( 'e2e-secret' );
e2e_check( is_string( $e2e_plain ) && 'e2e-secret' === Crypto::decrypt( $e2e_plain ), 'encryption round trip with the site keys' );
define( 'SKMT_ENCRYPTION_KEY', 'e2e-legacy-key' );
e2e_check( null === Crypto::decrypt( (string) $e2e_plain ), 'SKMT_ENCRYPTION_KEY changes the key: a secret made without it is unreadable' );
$e2e_keyed = Crypto::encrypt( 'e2e-secret' );
e2e_check( is_string( $e2e_keyed ) && 'e2e-secret' === Crypto::decrypt( $e2e_keyed ), 'encryption round trip with SKMT_ENCRYPTION_KEY' );

// --- Database: table attribution ------------------------------------------

WP_CLI::log( 'Database: table owners' );

global $wpdb;
$e2e_lumia_table = $wpdb->prefix . 'lumia_e2e_owned';
$e2e_skmt_table  = $wpdb->prefix . 'skmt_e2e_leftover';
foreach ( [ $e2e_lumia_table, $e2e_skmt_table ] as $e2e_table ) {
	// phpcs:ignore
	$wpdb->query( "CREATE TABLE IF NOT EXISTS `{$e2e_table}` (id INT NOT NULL) ENGINE=InnoDB" );
}

$e2e_cleanup = new Cleanup();
$e2e_tables  = [];
foreach ( $e2e_cleanup->foreign_tables() as $e2e_row ) {
	$e2e_tables[ $e2e_row['name'] ] = $e2e_row;
}
// phpcs:ignore
$wpdb->query( "DROP TABLE IF EXISTS `{$e2e_lumia_table}`, `{$e2e_skmt_table}`" );

$e2e_owned = $e2e_tables[ $e2e_lumia_table ] ?? null;
e2e_check(
	null !== $e2e_owned && 'active' === $e2e_owned['status'] && in_array( 'Lümia Tools', $e2e_owned['owners'], true ),
	'{prefix}lumia_* is attributed to the active plugin "Lümia Tools"'
);
$e2e_left = $e2e_tables[ $e2e_skmt_table ] ?? null;
e2e_check(
	null !== $e2e_left && 'unknown' === $e2e_left['status'] && [] === $e2e_left['owners'],
	'a leftover {prefix}skmt_* table is orphaned (status unknown, no owner)'
);

if ( $e2e_failures > 0 ) {
	WP_CLI::error( "{$e2e_failures} in-process check(s) failed." );
}
WP_CLI::success( 'In-process compat checks passed.' );
