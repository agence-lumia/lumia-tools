<?php
/**
 * Bench check of the SKMT -> Lumia data migration (Lumia\Tools\Core\Migration\FromSkmt),
 * run by run.sh through `wp --user=admin eval-file assert-migration.php <phase>`.
 * Bench only, never shipped.
 *
 * Phases:
 *   snapshot        before Lumia is installed (SKMT seeded and active): records what
 *                   SKMT holds into out/skmt-snapshot.json.
 *   migration       after the activation: every row of the spec's migration table,
 *                   compared with the snapshot.
 *   kept-original   the module still reads a migrated image's kept original from
 *                   lumia-originals-* (the legacy media migration uses it as a source).
 *   lumia-snapshot  records the Lumia data into out/lumia-snapshot.json.
 *   lumia-compare   the Lumia data still equals out/lumia-snapshot.json (after SKMT's
 *                   uninstall.php, after a reactivation).
 *   hold            a migration stopped by a failure: error recorded, marker absent, SKMT
 *                   left active, Lumia initialized no module, SKMT's bulk event moved.
 *   freeze-state    prints a digest of the optimized images' meta and of the uploads
 *                   files, and the number of skmt_image_optimizer_cron events.
 *   hold-writes     while on hold, writes the way the live SKMT would (a setting, the SMTP
 *                   password, an _skmt_* meta on an object already migrated), and records
 *                   them in the snapshot: they must win when the migration resumes.
 *   login-path      prints the custom login path the Security settings ask for.
 *   decrypt         prints "match" when the Lumia SMTP password and Brevo key decrypt to
 *                   the plain texts of the snapshot, the decrypted values otherwise.
 *   legacy-key      with a LUMIA_ENCRYPTION_KEY defined in the request, the SKMT secrets
 *                   still re-encrypt (the legacy key ignores it, as SKMT did).
 *   l10n <key> [arg]
 *                   prints a text the plugin shows, in the bench language (read through
 *                   the plugin's own .mo), so that run.sh does not grep English sources.
 *   interrupted <step>
 *                   a migration killed inside <step>: step recorded without detail,
 *                   Lumia left inactive, SKMT active, earlier steps done.
 *   reinstall       after Lumia's uninstall and reinstall over leftover skmt_* options:
 *                   the marker survived, nothing was migrated again.
 */

use Lumia\Tools\Core\Activator;
use Lumia\Tools\Core\Plugin;
use Lumia\Tools\Modules\ImageOptimizer\Module as ImageOptimizer;
use Lumia\Tools\Modules\Smtp\Crypto;

if ( ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}

const E2E_SKMT_SNAPSHOT  = '/e2e/out/skmt-snapshot.json';
const E2E_LUMIA_SNAPSHOT = '/e2e/out/lumia-snapshot.json';
const E2E_LEGACY_SLUG    = 'studio-kyne-mini-tools';
const E2E_LEGACY_NAME    = 'Studio Kyne Mini Tools';
const E2E_MARKER         = 'lumia_migrated_from_skmt';
const E2E_ERROR_OPTION   = 'lumia_migration_error';

// The closed lists of the brief, written out again here on purpose: the test must not
// trust the lists of the code it checks.
const E2E_OPTIONS = [
	'skmt_settings',
	'skmt_module_image_optimizer',
	'skmt_module_security',
	'skmt_module_login',
	'skmt_module_files',
	'skmt_module_white_label',
	'skmt_module_menu_creator',
	'skmt_module_database',
	'skmt_module_media',
	'skmt_module_activity_log',
	'skmt_module_smtp',
	'skmt_module_image_optimizer_stats',
	'skmt_module_image_optimizer_bulk_state',
	'skmt_module_image_optimizer_backup_token',
	'skmt_wl_menu_profiles',
	'skmt_wl_menu_cache_gen',
	'skmt_activity_log_schema',
	'skmt_mail_log_schema',
];
const E2E_SLUG_OPTIONS   = [ 'skmt_wl_menu_profiles', 'skmt_module_menu_creator' ];
const E2E_SECRETS        = [
	'skmt_smtp_password'  => 'e2e-secret',
	'skmt_smtp_brevo_key' => 'e2e-brevo',
];
const E2E_POST_META      = [
	'_skmt_optimized',
	'_skmt_original_bytes',
	'_skmt_optimized_bytes',
	'_skmt_bytes_saved',
	'_skmt_main_original_bytes',
	'_skmt_main_optimized_bytes',
	'_skmt_main_bytes_saved',
	'_skmt_optimized_format',
	'_skmt_optimized_mime',
	'_skmt_backup_file',
	'_skmt_fallback_files',
];
const E2E_USER_META      = [ 'skmt_notices', 'skmt_local_avatar' ];
const E2E_TERM_META      = [ 'skmt_folder_color' ];
const E2E_TAXONOMY       = 'skmt_media_folder';
const E2E_TABLES         = [ 'skmt_activity_log', 'skmt_mail_log' ];
const E2E_CRONS          = [ 'skmt_smtp_log_purge', 'skmt_activity_log_purge', 'skmt_image_optimizer_cron' ];

// wp eval-file includes this file from inside a function: without the global
// statement, this variable and the one e2e_check() increments would differ.
global $e2e_failures;
$e2e_failures = 0;

/** Plain texts of the SKMT secrets, decrypted with SKMT's own Crypto (SKMT loaded). */
function e2e_legacy_plains(): array {
	$out = [];
	foreach ( array_keys( E2E_SECRETS ) as $name ) {
		$stored       = get_option( $name );
		$out[ $name ] = false === $stored ? null : StudioKyne\MiniTools\Modules\Smtp\Crypto::decrypt( (string) $stored );
	}
	return $out;
}

/** Refreshes the option part of the SKMT snapshot from the database. */
function e2e_snapshot_options( array $snap ): array {
	foreach ( array_merge( E2E_OPTIONS, array_keys( E2E_SECRETS ) ) as $name ) {
		$snap['options'][ $name ]  = e2e_raw_option( $name );
		$snap['autoload'][ $name ] = e2e_autoload( $name );
	}
	$snap['secret_plain'] = e2e_legacy_plains();
	return $snap;
}

function e2e_check( bool $ok, string $label ): void {
	global $e2e_failures;
	WP_CLI::log( ( $ok ? '  ok   ' : '  FAIL ' ) . $label );
	if ( ! $ok ) {
		++$e2e_failures;
	}
}

/**
 * Basename of the installed SKMT, found by its Plugin Name header: the bench may install
 * it under another folder than studio-kyne-mini-tools/ (seed-skmt --folder).
 */
function e2e_legacy_basename(): string {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	foreach ( get_plugins() as $file => $data ) {
		if ( E2E_LEGACY_NAME === $data['Name'] ) {
			return $file;
		}
	}
	return '';
}

/** skmt_x -> lumia_x, _skmt_x -> _lumia_x. */
function e2e_lumia( string $legacy ): string {
	return preg_replace( '/^(_?)skmt_/', '$1lumia_', $legacy );
}

function e2e_read_json( string $path ): array {
	if ( ! is_readable( $path ) ) {
		WP_CLI::error( "{$path} is missing: run install-lumia on a seeded bench first." );
	}
	return json_decode( (string) file_get_contents( $path ), true );
}

function e2e_write_json( string $path, array $data ): void {
	file_put_contents( $path, wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
}

/** Raw serialized value of an option straight from the table (null if absent). */
function e2e_raw_option( string $name ): ?string {
	global $wpdb;
	$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
	return null === $value ? null : (string) $value;
}

function e2e_autoload( string $name ): ?string {
	global $wpdb;
	$value = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $name ) );
	return null === $value ? null : (string) $value;
}

/** Whether an autoload value means "loaded on every request". */
function e2e_autoloaded( ?string $value ): bool {
	return in_array( $value, wp_autoload_values_to_autoload(), true );
}

function e2e_meta_count( string $table, string $key ): int {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE meta_key = %s", $key ) );
}

function e2e_like_count( string $table, string $column, string $prefix ): int {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$column} LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
}

function e2e_table_exists( string $table ): bool {
	global $wpdb;
	return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
}

/** @return array{count:int, max_id:int, digest:string} */
function e2e_table_state( string $table, int $up_to_id = 0 ): array {
	global $wpdb;
	if ( ! e2e_table_exists( $table ) ) {
		return [ 'count' => -1, 'max_id' => 0, 'digest' => '' ];
	}
	$where = $up_to_id > 0 ? $wpdb->prepare( 'WHERE id <= %d', $up_to_id ) : '';
	$rows  = $wpdb->get_results( "SELECT * FROM `{$table}` {$where} ORDER BY id", ARRAY_A );
	return [
		'count'  => count( $rows ),
		'max_id' => (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM `{$table}`" ),
		'digest' => md5( serialize( $rows ) ),
	];
}

/** Activity rows reduced to what the migration may rewrite, the two legacy markers normalized. */
function e2e_activity_digest( string $table, int $up_to_id ): string {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id <= %d ORDER BY id", $up_to_id ), ARRAY_A );
	foreach ( $rows as &$row ) {
		$row['event']       = 'skmt_settings' === $row['event'] ? 'lumia_settings' : $row['event'];
		$row['object_type'] = 'skmt' === $row['object_type'] ? 'lumia' : $row['object_type'];
	}
	unset( $row );
	return md5( serialize( $rows ) );
}

/** Files under a directory, relative path => size. */
function e2e_listing( string $dir ): array {
	$out = [];
	if ( ! is_dir( $dir ) ) {
		return $out;
	}
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		$out[ substr( $file->getPathname(), strlen( $dir ) + 1 ) ] = $file->getSize();
	}
	ksort( $out );
	return $out;
}

function e2e_uploads(): string {
	return trailingslashit( wp_upload_dir( null, false )['basedir'] );
}

/** @return array<int, array{timestamp:int, args:array}> */
function e2e_cron_events( string $hook ): array {
	$out = [];
	foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
		foreach ( $hooks[ $hook ] ?? [] as $event ) {
			$out[] = [
				'timestamp' => (int) $timestamp,
				'args'      => $event['args'],
			];
		}
	}
	return $out;
}

/** Same rewrite as the brief: exact slug, or slug followed by "&"; SKMT's submenu separator exactly. */
function e2e_rewrite_slugs( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'e2e_rewrite_slugs', $value );
	}
	if ( is_string( $value ) && ( E2E_LEGACY_SLUG === $value || 0 === strpos( $value, E2E_LEGACY_SLUG . '&' ) ) ) {
		return 'lumia-tools' . substr( $value, strlen( E2E_LEGACY_SLUG ) );
	}
	if ( 'skmt-separator' === $value ) {
		return 'lumia-separator';
	}
	return $value;
}

/** Module id => default settings (empty array when the module has none). */
function e2e_module_defaults(): array {
	$out = [];
	foreach ( Activator::MODULE_CLASSES as $id => $class ) {
		$out[ $id ] = $class::get_defaults();
	}
	return $out;
}

function e2e_security_settings(): array {
	$defaults = Lumia\Tools\Modules\Security\Module::get_defaults();
	$stored   = get_option( 'lumia_module_security', [] );
	$stored   = is_array( $stored ) ? $stored : [];
	return [
		'authentication' => array_merge( $defaults['authentication'], (array) ( $stored['authentication'] ?? [] ) ),
	];
}

/** The Lumia-side data a later step must not alter. */
function e2e_lumia_state(): array {
	global $wpdb;
	$state = [ 'options' => [] ];
	foreach ( array_merge( E2E_OPTIONS, array_keys( E2E_SECRETS ) ) as $legacy ) {
		$state['options'][ e2e_lumia( $legacy ) ] = e2e_raw_option( e2e_lumia( $legacy ) );
	}
	foreach ( [ $wpdb->postmeta => E2E_POST_META, $wpdb->usermeta => E2E_USER_META, $wpdb->termmeta => E2E_TERM_META ] as $table => $keys ) {
		foreach ( $keys as $legacy ) {
			$state['meta'][ $table ][ e2e_lumia( $legacy ) ] = e2e_meta_count( $table, e2e_lumia( $legacy ) );
		}
	}
	$state['terms']         = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", e2e_lumia( E2E_TAXONOMY ) ) );
	$state['relationships'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy = %s", e2e_lumia( E2E_TAXONOMY ) ) );
	foreach ( E2E_TABLES as $legacy ) {
		$state['tables'][ e2e_lumia( $legacy ) ] = e2e_table_state( $wpdb->prefix . e2e_lumia( $legacy ) );
	}
	$token              = (string) get_option( 'lumia_module_image_optimizer_backup_token', '' );
	$state['originals'] = '' === $token ? [] : e2e_listing( e2e_uploads() . 'lumia-originals-' . $token );
	$state['io_cron']   = e2e_cron_events( 'lumia_image_optimizer_cron' );
	return $state;
}

$e2e_phase = $args[0] ?? '';

/* ------------------------------------------------------------------ */

if ( 'snapshot' === $e2e_phase ) {
	global $wpdb;
	$snap = [
		'encryption_key' => defined( 'SKMT_ENCRYPTION_KEY' ),
		'options'        => [],
		'autoload'       => [],
	];
	$snap = e2e_snapshot_options( $snap );
	foreach ( [ 'post' => [ $wpdb->postmeta, E2E_POST_META ], 'user' => [ $wpdb->usermeta, E2E_USER_META ], 'term' => [ $wpdb->termmeta, E2E_TERM_META ] ] as $type => [ $table, $keys ] ) {
		foreach ( $keys as $key ) {
			$snap['meta'][ $type ][ $key ] = e2e_meta_count( $table, $key );
		}
	}
	$snap['notices']       = get_user_meta( 1, 'skmt_notices', true );
	$snap['avatar']        = (int) get_user_meta( 1, 'skmt_local_avatar', true );
	$snap['terms']         = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", E2E_TAXONOMY ) );
	$snap['relationships'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy = %s", E2E_TAXONOMY ) );
	foreach ( E2E_TABLES as $table ) {
		$snap['tables'][ $table ] = e2e_table_state( $wpdb->prefix . $table );
	}
	$activity                   = $wpdb->prefix . 'skmt_activity_log';
	$snap['activity_digest']    = e2e_table_exists( $activity ) ? e2e_activity_digest( $activity, $snap['tables']['skmt_activity_log']['max_id'] ) : '';
	$snap['settings_rows']      = e2e_table_exists( $activity ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$activity}` WHERE event = 'skmt_settings'" ) : 0;
	$token                      = (string) get_option( 'skmt_module_image_optimizer_backup_token', '' );
	$snap['token']              = $token;
	$snap['originals']          = '' === $token ? [] : e2e_listing( e2e_uploads() . 'skmt-originals-' . $token );
	$snap['io_cron']            = e2e_cron_events( 'skmt_image_optimizer_cron' );
	$snap['optimized_post_ids'] = array_map( 'intval', $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_skmt_backup_file' ORDER BY post_id" ) );

	e2e_write_json( E2E_SKMT_SNAPSHOT, $snap );
	WP_CLI::success(
		sprintf(
			'SKMT snapshot: %d options, %d optimized images, %d activity rows, %d mail rows, %d originals.',
			count( array_filter( $snap['options'], 'is_string' ) ),
			count( $snap['optimized_post_ids'] ),
			$snap['tables']['skmt_activity_log']['count'],
			$snap['tables']['skmt_mail_log']['count'],
			count( $snap['originals'] )
		)
	);
	return;
}

/* ------------------------------------------------------------------ */

if ( 'login-path' === $e2e_phase ) {
	$auth = e2e_security_settings()['authentication'];
	echo $auth['enable_custom_login_url'] ? (string) $auth['custom_login_url'] : '';
	return;
}

if ( 'legacy-key' === $e2e_phase ) {
	// A site may define LUMIA_ENCRYPTION_KEY before migrating (the help texts name it).
	// SKMT never read it: the legacy key must not either, or nothing decrypts.
	$snap = e2e_read_json( E2E_SKMT_SNAPSHOT );
	define( 'LUMIA_ENCRYPTION_KEY', 'e2e-lumia-only-key' );
	foreach ( $snap['secret_plain'] as $legacy => $plain ) {
		$stored = get_option( $legacy );
		if ( null === $plain || false === $stored ) {
			continue;
		}
		$new = Crypto::reencrypt_from_legacy( (string) $stored );
		e2e_check( $new !== $stored && $plain === Crypto::decrypt( $new ), "{$legacy} re-encrypts under LUMIA_ENCRYPTION_KEY and decrypts to {$plain}" );
	}
	if ( $e2e_failures > 0 ) {
		WP_CLI::error( "legacy-key: {$e2e_failures} check(s) failed" );
	}
	WP_CLI::success( 'legacy-key: the legacy key ignores LUMIA_ENCRYPTION_KEY.' );
	return;
}

if ( 'l10n' === $e2e_phase ) {
	// The English sources are written out again on purpose (a changed source must fail
	// here); the translation is the plugin's, in the bench language.
	switch ( $args[1] ?? '' ) {
		case 'hold-notice':
			// As Plugin::render_hold_notice() formats it for step $args[2].
			echo sprintf(
				esc_html__( 'The migration from Studio Kyne Mini Tools stopped at the %s step: Lümia Tools loads none of its modules until it is complete. Do not delete Studio Kyne Mini Tools before then: its uninstallation would erase the data not migrated yet. Deactivate then reactivate Lümia Tools to resume it.', 'lumia-tools' ), // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment
				'<code>' . esc_html( (string) ( $args[2] ?? '' ) ) . '</code>'
			);
			return;
		case 'freeze-json':
			// The whole body of FromSkmt::refuse_legacy_optimizer() (wp_send_json_error()).
			echo wp_json_encode(
				[
					'success' => false,
					'data'    => __( 'Image optimization is paused until the migration to Lümia Tools is complete.', 'lumia-tools' ),
				]
			);
			return;
		case 'complete':
			echo __( 'Migration from Studio Kyne Mini Tools complete. You can delete the old plugin.', 'lumia-tools' );
			return;
		case 'locale':
			echo determine_locale();
			return;
	}
	WP_CLI::error( 'l10n: unknown key.' );
}

if ( 'decrypt' === $e2e_phase ) {
	$snap = e2e_read_json( E2E_SKMT_SNAPSHOT );
	$got  = [];
	foreach ( $snap['secret_plain'] as $legacy => $plain ) {
		$got[ $legacy ] = null === $plain ? null : Crypto::decrypt( (string) get_option( e2e_lumia( $legacy ), '' ) );
	}
	echo $got === $snap['secret_plain'] ? 'match' : 'mismatch ' . wp_json_encode( $got ) . ' expected ' . wp_json_encode( $snap['secret_plain'] );
	return;
}

/* ------------------------------------------------------------------ */

if ( 'migration' === $e2e_phase ) {
	global $wpdb;
	$snap     = e2e_read_json( E2E_SKMT_SNAPSHOT );
	$defaults = e2e_module_defaults();
	$minimal  = null === $snap['options']['skmt_module_security'];

	WP_CLI::log( sprintf( 'Scenario: %s%s', $minimal ? 'minimal' : 'default', $snap['encryption_key'] ? ', SKMT_ENCRYPTION_KEY' : '' ) );

	WP_CLI::log( 'Plugin state' );
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$legacy_file = e2e_legacy_basename();
	e2e_check( '' !== $legacy_file && ! is_plugin_active( $legacy_file ), "SKMT ({$legacy_file}) is deactivated" );
	e2e_check( ! defined( 'SKMT_VERSION' ), 'SKMT is not loaded in this request' );
	e2e_check( is_plugin_active( 'lumia-tools/lumia-tools.php' ), 'Lumia Tools is active' );
	$marker = get_option( E2E_MARKER );
	e2e_check( is_numeric( $marker ) && (int) $marker > time() - DAY_IN_SECONDS && (int) $marker <= time(), 'marker set to a recent timestamp (' . var_export( $marker, true ) . ')' );
	e2e_check( false === get_option( E2E_ERROR_OPTION ), 'no migration error recorded' );
	e2e_check( [] !== Plugin::instance()->modules->get_active_instances(), 'Lumia initialized its active modules' );

	WP_CLI::log( 'Options (copied, originals kept)' );
	foreach ( E2E_OPTIONS as $legacy ) {
		$target   = e2e_lumia( $legacy );
		$original = $snap['options'][ $legacy ];
		$current  = e2e_raw_option( $target );
		e2e_check( $original === e2e_raw_option( $legacy ), "{$legacy} still there, unchanged" );

		if ( null === $original ) {
			$module = 0 === strpos( $legacy, 'skmt_module_' ) ? substr( $legacy, strlen( 'skmt_module_' ) ) : '';
			if ( isset( $defaults[ $module ] ) && [] !== $defaults[ $module ] ) {
				e2e_check( null !== $current && $defaults[ $module ] == maybe_unserialize( $current ), "{$target} holds the Lumia defaults (nothing to migrate)" ); // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- nested arrays, key order may differ.
			} else {
				e2e_check( null === $current, "{$target} not created (nothing to migrate)" );
			}
			continue;
		}

		$expected = maybe_unserialize( $original );
		if ( in_array( $legacy, E2E_SLUG_OPTIONS, true ) ) {
			$expected = e2e_rewrite_slugs( $expected );
		}
		e2e_check( null !== $current && serialize( $expected ) === serialize( maybe_unserialize( $current ) ), "{$target} equals {$legacy}" . ( in_array( $legacy, E2E_SLUG_OPTIONS, true ) ? ' (slug rewritten)' : '' ) );
		e2e_check( e2e_autoloaded( $snap['autoload'][ $legacy ] ) === e2e_autoloaded( e2e_autoload( $target ) ), "{$target} keeps the autoload flag of {$legacy}" );
	}

	WP_CLI::log( 'Menu profiles' );
	$profiles = (string) e2e_raw_option( 'lumia_wl_menu_profiles' );
	if ( null !== $snap['options']['skmt_wl_menu_profiles'] ) {
		e2e_check( false === strpos( $profiles, E2E_LEGACY_SLUG ), 'lumia_wl_menu_profiles no longer mentions the legacy slug' );
		e2e_check( false !== strpos( $profiles, '"lumia-tools"' ) && false !== strpos( $profiles, '"lumia-tools&tab=module_smtp"' ), 'lumia_wl_menu_profiles points at lumia-tools and lumia-tools&tab=module_smtp' );
		e2e_check( false === strpos( $profiles, 'skmt-separator' ) && false !== strpos( $profiles, '"lumia-separator"' ), "the hidden submenu separator child now reads lumia-separator (Lumia's own slug)" );
	} else {
		e2e_check( '' === $profiles, 'no menu profile to migrate' );
	}

	WP_CLI::log( 'Secrets (re-encrypted)' );
	foreach ( array_keys( E2E_SECRETS ) as $legacy ) {
		$plain  = $snap['secret_plain'][ $legacy ];
		$target = e2e_lumia( $legacy );
		if ( null === $snap['options'][ $legacy ] ) {
			e2e_check( false === get_option( $target ), "{$target} not created (nothing to migrate)" );
			continue;
		}
		$stored = (string) get_option( $target, '' );
		e2e_check( null !== $plain && $plain === Crypto::decrypt( $stored ), "{$target} decrypts to the secret SKMT last held ({$plain})" );
		e2e_check( $stored !== maybe_unserialize( $snap['options'][ $legacy ] ), "{$target} is a new cipher text (Lumia key), not a copy" );
		e2e_check( null === Crypto::decrypt( (string) maybe_unserialize( $snap['options'][ $legacy ] ) ), "{$legacy} does not decrypt with the Lumia key (contexts differ)" );
		e2e_check( e2e_autoloaded( e2e_autoload( $target ) ) === false, "{$target} is not autoloaded" );
	}

	WP_CLI::log( 'Meta (renamed in place)' );
	foreach ( [ 'post' => [ $wpdb->postmeta, E2E_POST_META ], 'user' => [ $wpdb->usermeta, E2E_USER_META ], 'term' => [ $wpdb->termmeta, E2E_TERM_META ] ] as $type => [ $table, $keys ] ) {
		foreach ( $keys as $legacy ) {
			$expected = (int) $snap['meta'][ $type ][ $legacy ];
			e2e_check( 0 === e2e_meta_count( $table, $legacy ) && $expected === e2e_meta_count( $table, e2e_lumia( $legacy ) ), "{$type} meta {$legacy}: 0 left, " . e2e_lumia( $legacy ) . " x{$expected}" );
		}
	}
	e2e_check( 0 === e2e_like_count( $wpdb->postmeta, 'meta_key', '_skmt_' ), 'no post meta _skmt_* at all' );
	e2e_check( 0 === e2e_like_count( $wpdb->usermeta, 'meta_key', 'skmt_' ), 'no user meta skmt_* at all' );
	e2e_check( 0 === e2e_like_count( $wpdb->termmeta, 'meta_key', 'skmt_' ), 'no term meta skmt_* at all' );
	foreach ( [ $wpdb->postmeta => [ 'post_id', '_lumia_' ], $wpdb->usermeta => [ 'user_id', 'lumia_' ], $wpdb->termmeta => [ 'term_id', 'lumia_' ] ] as $table => [ $column, $prefix ] ) {
		$dupes = $wpdb->get_col( $wpdb->prepare( "SELECT CONCAT({$column}, ':', meta_key) FROM {$table} WHERE meta_key LIKE %s GROUP BY {$column}, meta_key HAVING COUNT(*) > 1", $wpdb->esc_like( $prefix ) . '%' ) );
		e2e_check( [] === $dupes, "{$table}: no object holds a {$prefix}* key twice" . ( $dupes ? ' (' . implode( ', ', $dupes ) . ')' : '' ) );
	}
	if ( isset( $snap['hold_meta'] ) ) {
		$hold = $snap['hold_meta'];
		e2e_check( (string) $hold['value'] === (string) get_post_meta( (int) $hold['post_id'], e2e_lumia( $hold['key'] ), true ), "attachment {$hold['post_id']}: " . e2e_lumia( $hold['key'] ) . " holds the value SKMT wrote while on hold ({$hold['value']})" );
	}
	if ( is_array( $snap['notices'] ) && isset( $snap['notices']['e2e_notice'] ) ) {
		$notices = get_user_meta( 1, 'lumia_notices', true );
		e2e_check( is_array( $notices ) && ( $notices['e2e_notice'] ?? null ) === $snap['notices']['e2e_notice'], 'the pending user notice survived as lumia_notices' );
		e2e_check( (int) $snap['avatar'] === (int) get_user_meta( 1, 'lumia_local_avatar', true ), "the local avatar points at the same attachment ({$snap['avatar']})" );
	}

	WP_CLI::log( 'Media folders (taxonomy renamed)' );
	$terms = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", 'lumia_media_folder' ) );
	$rels  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy = %s", 'lumia_media_folder' ) );
	e2e_check( 0 === e2e_like_count( $wpdb->term_taxonomy, 'taxonomy', 'skmt_' ), 'no skmt_* taxonomy left' );
	e2e_check( (int) $snap['terms'] === $terms && (int) $snap['relationships'] === $rels, "lumia_media_folder: {$terms} folder(s), {$rels} filed media item(s), as before" );
	if ( $snap['terms'] > 0 ) {
		$folders = get_terms(
			[
				'taxonomy'   => 'lumia_media_folder',
				'hide_empty' => false,
			]
		);
		$names   = is_wp_error( $folders ) ? [] : wp_list_pluck( $folders, 'name' );
		sort( $names );
		e2e_check( [ 'Dossier E2E rouge', 'Dossier E2E sans couleur' ] === $names, 'get_terms() sees both folders: ' . implode( ', ', $names ) );
		$red = get_term_by( 'name', 'Dossier E2E rouge', 'lumia_media_folder' );
		e2e_check( $red && '#ef4444' === get_term_meta( $red->term_id, 'lumia_folder_color', true ), 'the red folder keeps its colour' );
	}
	e2e_check( false === get_option( 'skmt_media_folder_children' ), 'the hierarchy cache of the old taxonomy is gone' );

	WP_CLI::log( 'Tables (renamed)' );
	foreach ( E2E_TABLES as $legacy ) {
		$before = $snap['tables'][ $legacy ];
		$table  = $wpdb->prefix . e2e_lumia( $legacy );
		e2e_check( ! e2e_table_exists( $wpdb->prefix . $legacy ), "{$wpdb->prefix}{$legacy} is gone" );
		if ( -1 === $before['count'] ) {
			continue;
		}
		$after = e2e_table_state( $table, $before['max_id'] );
		e2e_check( $before['count'] === $after['count'], "{$table} holds the {$before['count']} rows of {$legacy}" );
		if ( 'skmt_mail_log' === $legacy ) {
			e2e_check( $before['digest'] === $after['digest'], "{$table}: rows identical" );
		}
	}
	e2e_check( [] === $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'skmt_' ) . '%' ) ), 'no skmt_* table at all (none re-created during the activation request)' );
	$activity = $wpdb->prefix . 'lumia_activity_log';
	if ( -1 !== $snap['tables']['skmt_activity_log']['count'] ) {
		$max = (int) $snap['tables']['skmt_activity_log']['max_id'];
		e2e_check( $snap['activity_digest'] === e2e_activity_digest( $activity, $max ), 'activity rows identical apart from the rewritten skmt markers' );
		e2e_check( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$activity}` WHERE object_type = 'skmt' OR event = 'skmt_settings'" ), "no row left with object_type 'skmt' or event 'skmt_settings'" );
		e2e_check( (int) $snap['settings_rows'] === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$activity}` WHERE id <= %d AND event = 'lumia_settings' AND object_type = 'lumia'", $max ) ), "the {$snap['settings_rows']} settings rows now read lumia_settings / lumia" );
		// Written by SKMT's recorder during the activation request, after the rename.
		$late = $wpdb->get_col( $wpdb->prepare( "SELECT CONCAT(event, ':', object_label) FROM `{$activity}` WHERE id > %d ORDER BY id", $max ) );
		e2e_check( in_array( 'plugin_disabled:Studio Kyne Mini Tools', $late, true ) && in_array( 'plugin_activated:Lümia Tools', $late, true ), 'deactivation of SKMT and activation of Lumia logged in the Lumia table (' . implode( ', ', $late ) . ')' );
	}

	WP_CLI::log( 'Originals folder (renamed)' );
	$token = (string) $snap['token'];
	if ( '' !== $token ) {
		$new = e2e_uploads() . 'lumia-originals-' . $token;
		e2e_check( (string) get_option( 'lumia_module_image_optimizer_backup_token' ) === $token, 'same backup token' );
		e2e_check( is_dir( $new ) && $snap['originals'] === e2e_listing( $new ), "lumia-originals-{$token} holds the " . count( $snap['originals'] ) . ' original files' );
		e2e_check( [] === glob( e2e_uploads() . 'skmt-originals-*' ), 'no skmt-originals-* folder left' );
		foreach ( $snap['optimized_post_ids'] as $id ) {
			e2e_check( '' !== Plugin::instance()->modules->get_instance( 'image_optimizer' )->get_backup_path( (int) $id ), "attachment {$id}: the module finds its original" );
		}
	}

	WP_CLI::log( 'Scheduled tasks' );
	foreach ( E2E_CRONS as $hook ) {
		e2e_check( [] === e2e_cron_events( $hook ), "{$hook} unscheduled" );
	}
	$skmt_hooks = [];
	foreach ( (array) _get_cron_array() as $hooks ) {
		foreach ( array_keys( (array) $hooks ) as $hook ) {
			if ( 0 === strpos( (string) $hook, 'skmt_' ) ) {
				$skmt_hooks[] = $hook;
			}
		}
	}
	e2e_check( [] === $skmt_hooks, 'no skmt_* event in the cron array' );
	$io_cron = e2e_cron_events( 'lumia_image_optimizer_cron' );
	e2e_check( $snap['io_cron'] === $io_cron, 'lumia_image_optimizer_cron: same arguments and timestamp as before (' . wp_json_encode( $io_cron ) . ')' );
	$settings = get_option( 'lumia_settings' );
	foreach ( [ 'activity_log' => 'lumia_activity_log_purge', 'smtp' => 'lumia_smtp_log_purge' ] as $module => $hook ) {
		if ( ! empty( $settings['modules'][ $module ] ) ) {
			e2e_check( false !== wp_next_scheduled( $hook ), "{$hook} scheduled (module active)" );
		}
	}

	WP_CLI::log( 'Security' );
	e2e_check( true === e2e_security_settings()['authentication']['rate_limiting'], 'rate_limiting is true' );

	WP_CLI::log( 'Lumia option list' );
	$stray = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'lumia_' ) . '%' ) );
	WP_CLI::log( '  ' . implode( ' ', $stray ) );

	if ( $e2e_failures > 0 ) {
		WP_CLI::error( "migration: {$e2e_failures} check(s) failed" );
	}
	WP_CLI::success( 'migration: all in-process checks passed.' );
	return;
}

/* ------------------------------------------------------------------ */

if ( 'kept-original' === $e2e_phase ) {
	$snap = e2e_read_json( E2E_SKMT_SNAPSHOT );
	$ids  = $snap['optimized_post_ids'];
	if ( [] === $ids ) {
		WP_CLI::success( 'kept-original: no optimized image in this scenario.' );
		return;
	}
	$id     = (int) $ids[0];
	$module = Plugin::instance()->modules->get_instance( 'image_optimizer' );
	$backup = $module->get_backup_path( $id );

	// The "Restore original" action is gone with the former pipeline: the kept original is
	// now only a source for `wp lumia images migrate`, read through the same method.
	WP_CLI::log( "Kept original of attachment {$id}" );
	e2e_check( 0 === strpos( $backup, e2e_uploads() . 'lumia-originals-' ), 'the original is read from lumia-originals-*' );
	e2e_check( '' !== $backup && is_file( $backup ) && filesize( $backup ) > 0, 'the kept original is on disk' );

	if ( $e2e_failures > 0 ) {
		WP_CLI::error( "kept-original: {$e2e_failures} check(s) failed" );
	}
	WP_CLI::success( 'kept-original: the module finds the original kept by SKMT.' );
	return;
}

/* ------------------------------------------------------------------ */

if ( 'lumia-snapshot' === $e2e_phase ) {
	$state           = e2e_lumia_state();
	$state['marker'] = get_option( E2E_MARKER );
	e2e_write_json( E2E_LUMIA_SNAPSHOT, $state );
	WP_CLI::success( 'Lumia snapshot written.' );
	return;
}

if ( 'lumia-compare' === $e2e_phase ) {
	global $wpdb;
	$before = e2e_read_json( E2E_LUMIA_SNAPSHOT );
	$now    = e2e_lumia_state();
	$skip   = $args[1] ?? '';

	WP_CLI::log( 'Lumia data unchanged' );
	e2e_check( (string) $before['marker'] === (string) get_option( E2E_MARKER ), 'marker unchanged (' . var_export( get_option( E2E_MARKER ), true ) . ')' );
	e2e_check( false === get_option( E2E_ERROR_OPTION ), 'no migration error recorded' );
	foreach ( $before['options'] as $name => $value ) {
		if ( $name === $skip ) {
			continue;
		}
		e2e_check( $value === $now['options'][ $name ], "{$name} unchanged" );
	}
	foreach ( $before['meta'] as $table => $keys ) {
		foreach ( $keys as $key => $count ) {
			e2e_check( $count === $now['meta'][ $table ][ $key ], "{$key}: {$count} row(s)" );
		}
	}
	e2e_check( $before['terms'] === $now['terms'] && $before['relationships'] === $now['relationships'], "lumia_media_folder: {$now['terms']} folder(s), {$now['relationships']} filed item(s)" );
	foreach ( $before['tables'] as $table => $state ) {
		if ( -1 === $state['count'] ) {
			continue;
		}
		$rows = e2e_table_state( $wpdb->prefix . $table, $state['max_id'] );
		e2e_check( $state['count'] === $rows['count'] && $state['digest'] === $rows['digest'], "{$table}: the {$state['count']} rows are intact" );
	}
	e2e_check( $before['originals'] === $now['originals'], 'originals folder intact (' . count( $now['originals'] ) . ' files)' );
	e2e_check( $before['io_cron'] === $now['io_cron'], 'lumia_image_optimizer_cron unchanged' );
	foreach ( e2e_read_json( E2E_SKMT_SNAPSHOT )['secret_plain'] as $legacy => $plain ) {
		$name = e2e_lumia( $legacy );
		if ( null !== $before['options'][ $name ] ) {
			e2e_check( $plain === Crypto::decrypt( (string) get_option( $name, '' ) ), "{$name} still decrypts" );
		}
	}
	e2e_check( true === e2e_security_settings()['authentication']['rate_limiting'], 'rate_limiting is true' );

	if ( $e2e_failures > 0 ) {
		WP_CLI::error( "lumia-compare: {$e2e_failures} check(s) failed" );
	}
	WP_CLI::success( 'lumia-compare: Lumia data intact.' );
	return;
}

/* ------------------------------------------------------------------ */

if ( 'hold' === $e2e_phase ) {
	global $wpdb;
	$expected_step = $args[1] ?? '';
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	WP_CLI::log( 'Migration stopped by a failure' );
	e2e_check( $expected_step === get_option( E2E_ERROR_OPTION ), "error option names the step '{$expected_step}' (got " . var_export( get_option( E2E_ERROR_OPTION ), true ) . ')' );
	e2e_check( false === get_option( E2E_MARKER ), 'marker not set' );
	e2e_check( is_plugin_active( e2e_legacy_basename() ) && defined( 'SKMT_VERSION' ), 'SKMT left active (and loaded in this request)' );
	e2e_check( is_plugin_active( 'lumia-tools/lumia-tools.php' ), 'Lumia Tools active' );
	e2e_check( false === has_action( 'init', [ Plugin::instance(), 'on_init' ] ), 'Lumia did not hook its module initialization' );
	e2e_check( [] === Plugin::instance()->modules->get_active_instances() && [] === Plugin::instance()->modules->get_all(), 'Lumia registered and initialized no module' );
	e2e_check( false === has_action( 'lumia_image_optimizer_cron' ), 'no Lumia module hook present' );
	e2e_check( e2e_raw_option( 'skmt_settings' ) === e2e_raw_option( 'lumia_settings' ), 'lumia_settings is the copy of skmt_settings, not the Lumia defaults' );
	e2e_check( 0 < e2e_meta_count( $wpdb->postmeta, '_lumia_optimized' ) && 0 < e2e_meta_count( $wpdb->postmeta, '_skmt_optimized_mime' ), 'partial state: some post meta renamed, the failing key not' );
	$detail = (string) get_option( 'lumia_migration_error_detail', '' );
	e2e_check( false !== strpos( $detail, 'e2e_missing_table' ), "the database error is kept next to the step ({$detail})" );
	$snap = e2e_read_json( E2E_SKMT_SNAPSHOT );
	e2e_check( [] === e2e_cron_events( 'skmt_image_optimizer_cron' ), 'SKMT bulk event no longer scheduled: the live SKMT cannot re-encode the images whose meta is renamed' );
	e2e_check( $snap['io_cron'] === e2e_cron_events( 'lumia_image_optimizer_cron' ), 'bulk event already moved to lumia_image_optimizer_cron, same arguments and time' );

	if ( $e2e_failures > 0 ) {
		WP_CLI::error( "hold: {$e2e_failures} check(s) failed" );
	}
	WP_CLI::success( 'hold: Lumia waits, SKMT keeps running.' );
	return;
}

if ( 'freeze-state' === $e2e_phase ) {
	// Everything SKMT's optimizer could change on the optimized images: their post
	// meta (Lumia and legacy keys, attachment metadata), the uploads files, its event.
	global $wpdb;
	$snap  = e2e_read_json( E2E_SKMT_SNAPSHOT );
	$ids   = array_map( 'intval', $snap['optimized_post_ids'] );
	$meta  = $ids ? $wpdb->get_results( 'SELECT post_id, meta_key, meta_value FROM ' . $wpdb->postmeta . ' WHERE post_id IN (' . implode( ',', $ids ) . ') ORDER BY meta_id', ARRAY_A ) : [];
	$files = [];
	foreach ( e2e_listing( rtrim( e2e_uploads(), '/' ) ) as $rel => $size ) {
		$files[ $rel ] = md5_file( e2e_uploads() . $rel );
	}
	echo wp_json_encode(
		[
			'meta'  => md5( serialize( $meta ) ),
			'files' => md5( serialize( $files ) ),
			'cron'  => count( e2e_cron_events( 'skmt_image_optimizer_cron' ) ),
		]
	);
	return;
}

if ( 'hold-writes' === $e2e_phase ) {
	if ( ! defined( 'SKMT_VERSION' ) ) {
		WP_CLI::error( 'SKMT is not loaded: hold-writes simulates the live SKMT.' );
	}
	$snap = e2e_read_json( E2E_SKMT_SNAPSHOT );

	// A setting saved in SKMT: the login URL.
	$security = get_option( 'skmt_module_security' );
	$security['authentication']['custom_login_url'] = '/connexion-hold';
	update_option( 'skmt_module_security', $security );

	// The SMTP password typed again in SKMT (its own Crypto, 'skmt-smtp|' context).
	update_option( 'skmt_smtp_password', StudioKyne\MiniTools\Modules\Smtp\Crypto::encrypt( 'e2e-hold-secret' ), false );

	// SKMT re-optimizing an image whose meta the failed attempt already renamed.
	$id = (int) $snap['optimized_post_ids'][0];
	update_post_meta( $id, '_skmt_optimized', 1234567890 );

	$snap              = e2e_snapshot_options( $snap );
	$snap['hold_meta'] = [
		'post_id' => $id,
		'key'     => '_skmt_optimized',
		'value'   => '1234567890',
	];
	e2e_write_json( E2E_SKMT_SNAPSHOT, $snap );
	WP_CLI::success( "hold-writes: login URL /connexion-hold, new SMTP password, _skmt_optimized on attachment {$id}." );
	return;
}

if ( 'interrupted' === $e2e_phase ) {
	// Lumia is NOT active here (WordPress adds it to active_plugins only once the
	// activation hook returns): only its options and the data can be read.
	global $wpdb;
	$step = $args[1] ?? '';
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	WP_CLI::log( 'Migration killed inside a step' );
	e2e_check( $step === get_option( E2E_ERROR_OPTION ), "lumia_migration_error names the interrupted step '{$step}' (got " . var_export( get_option( E2E_ERROR_OPTION ), true ) . ')' );
	e2e_check( false === get_option( 'lumia_migration_error_detail' ), 'no error detail: interrupted, not failed' );
	e2e_check( e2e_autoloaded( e2e_autoload( E2E_ERROR_OPTION ) ), 'lumia_migration_error is autoloaded (the hold reads it for free)' );
	e2e_check( false === get_option( E2E_MARKER ), 'marker not set' );
	e2e_check( ! is_plugin_active( 'lumia-tools/lumia-tools.php' ), 'Lumia Tools left inactive by WordPress' );
	e2e_check( is_plugin_active( e2e_legacy_basename() ) && defined( 'SKMT_VERSION' ), 'SKMT left active' );
	e2e_check( 0 < e2e_meta_count( $wpdb->postmeta, '_lumia_optimized' ) && 0 === e2e_like_count( $wpdb->postmeta, 'meta_key', '_skmt_' ), 'the earlier post_meta step completed' );
	e2e_check( 0 < e2e_meta_count( $wpdb->usermeta, 'skmt_local_avatar' ), 'the interrupted user meta is not renamed yet' );
	e2e_check( [] === e2e_cron_events( 'skmt_image_optimizer_cron' ), "SKMT's bulk event already removed (crons step)" );

	if ( $e2e_failures > 0 ) {
		WP_CLI::error( "interrupted: {$e2e_failures} check(s) failed" );
	}
	WP_CLI::success( 'interrupted: the killed step is recorded.' );
	return;
}

if ( 'reinstall' === $e2e_phase ) {
	$before = e2e_read_json( E2E_LUMIA_SNAPSHOT );
	$snap   = e2e_read_json( E2E_SKMT_SNAPSHOT );

	WP_CLI::log( 'Reinstall over leftover skmt_* options' );
	e2e_check( (string) $before['marker'] === (string) get_option( E2E_MARKER ), 'the marker survived the uninstall of Lumia (tombstone)' );
	e2e_check( $snap['options']['skmt_settings'] === e2e_raw_option( 'skmt_settings' ), 'skmt_settings is still there (SKMT deleted without its uninstall.php)' );
	e2e_check( false === get_option( E2E_ERROR_OPTION ), 'no migration error recorded' );
	$settings = get_option( 'lumia_settings' );
	e2e_check( is_array( $settings ) && [] === array_filter( (array) $settings['modules'] ), 'lumia_settings holds the fresh defaults (every module off), not the old skmt_settings' );
	$security = get_option( 'lumia_module_security' );
	e2e_check( Lumia\Tools\Modules\Security\Module::get_defaults() == $security, 'lumia_module_security holds the Lumia defaults, not the old SKMT login URL' ); // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- nested arrays, key order may differ.
	foreach ( array_keys( E2E_SECRETS ) as $legacy ) {
		e2e_check( false === get_option( e2e_lumia( $legacy ) ), e2e_lumia( $legacy ) . ' not re-imported' );
	}

	if ( $e2e_failures > 0 ) {
		WP_CLI::error( "reinstall: {$e2e_failures} check(s) failed" );
	}
	WP_CLI::success( 'reinstall: nothing migrated again.' );
	return;
}

WP_CLI::error( "Unknown phase '{$e2e_phase}'." );
