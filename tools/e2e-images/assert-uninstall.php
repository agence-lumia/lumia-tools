<?php
/**
 * Bench check of the plugin deactivation and uninstall for the Image Optimizer (spec 6, 9.4,
 * 9.11), run by `assert-uninstall.sh` in three phases through `run.sh assert <stack>
 * tools/e2e-images/assert-uninstall.php <phase>` (WP-CLI eval-file, as admin). Bench only,
 * never shipped.
 *
 *   seed         plugin active, module active: a media item with fake siblings, a legacy file
 *                hard-linked to one of them (migrated item), an excluded item, the former
 *                pipeline's metas and options, a kept original, the uploads/.htaccess block,
 *                cron events of every module hook (some with arguments, which
 *                wp_clear_scheduled_hook() never removed).
 *   deactivated  after `wp plugin deactivate`: no generated sibling of any item that had a
 *                state, the legacy file intact, the excluded status kept, no block, no event;
 *                the settings kept. Then the item gets its siblings, state and events back
 *                (plain WordPress calls: the plugin is not loaded).
 *   uninstalled  after `wp plugin uninstall`: no sibling, no block, no uploads/lumia-tools/,
 *                no option, meta or event of the module; the legacy file and the kept original
 *                still on disk.
 *
 * State shared between phases: /bench/out/uninstall-state.json.
 */

if ( ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

// wp eval-file includes this file from inside a function: globals must be declared.
global $un_failures, $wpdb;
$un_failures = 0;

const UN_STATE    = '/bench/out/uninstall-state.json';
const UN_FIXTURES = '/bench/out/fixtures';
const UN_HOOKS    = [ 'lumia_image_optimizer_drain', 'lumia_image_optimizer_delivery_check', 'lumia_image_optimizer_cron', 'lumia_image_optimizer_reconcile' ];
const UN_BEGIN    = '# BEGIN Lumia Tools AVIF';

function un_check( bool $ok, string $label ): void {
	global $un_failures;
	WP_CLI::log( ( $ok ? '  ok   ' : '  FAIL ' ) . $label );
	if ( ! $ok ) {
		++$un_failures;
	}
}

function un_finish( string $phase ): void {
	global $un_failures;
	if ( $un_failures > 0 ) {
		WP_CLI::error( "{$phase}: {$un_failures} check(s) failed" );
	}
	WP_CLI::success( "{$phase}: all checks passed." );
}

function un_uploads(): string {
	return trailingslashit( wp_upload_dir( null, false )['basedir'] );
}

/**
 * Source files (JPEG/PNG) of an attachment, absolute paths.
 *
 * @return string[]
 */
function un_sources( int $id ): array {
	$metadata = wp_get_attachment_metadata( $id );
	if ( ! is_array( $metadata ) || empty( $metadata['file'] ) ) {
		return [];
	}
	$main  = un_uploads() . $metadata['file'];
	$dir   = trailingslashit( dirname( $main ) );
	$files = [ $main ];
	foreach ( (array) ( $metadata['sizes'] ?? [] ) as $size ) {
		if ( ! empty( $size['file'] ) ) {
			$files[] = $dir . $size['file'];
		}
	}

	return array_values( array_unique( array_filter( $files, static fn( string $f ): bool => (bool) preg_match( '/\.(jpe?g|png)$/i', $f ) ) ) );
}

/**
 * Hooks of the module with at least one scheduled event.
 *
 * @return string[]
 */
function un_scheduled_hooks(): array {
	$found = [];
	foreach ( (array) _get_cron_array() as $hooks ) {
		foreach ( array_keys( (array) $hooks ) as $hook ) {
			if ( in_array( $hook, UN_HOOKS, true ) ) {
				$found[] = $hook;
			}
		}
	}

	return array_values( array_unique( $found ) );
}

/**
 * Events with arguments for the two hooks that never had any: the case
 * wp_clear_scheduled_hook() misses.
 */
function un_schedule_events(): void {
	wp_schedule_single_event( time() + 3600, 'lumia_image_optimizer_cron', [ 'batch' => 1 ] );
	wp_schedule_single_event( time() + 3600, 'lumia_image_optimizer_reconcile', [ 42 ] );
	if ( ! wp_next_scheduled( 'lumia_image_optimizer_drain' ) ) {
		wp_schedule_single_event( time() + 3600, 'lumia_image_optimizer_drain' );
	}
	if ( ! wp_next_scheduled( 'lumia_image_optimizer_delivery_check' ) ) {
		wp_schedule_single_event( time() + 3600, 'lumia_image_optimizer_delivery_check' );
	}
}

function un_htaccess_has_block(): bool {
	$path = un_uploads() . '.htaccess';

	return is_file( $path ) && false !== strpos( (string) file_get_contents( $path ), UN_BEGIN );
}

$phase = $args[0] ?? '';

/* ------------------------------------------------------------------ */

if ( 'seed' === $phase ) {
	$plugin = \Lumia\Tools\Core\Plugin::instance();
	$module = $plugin->modules->get_active_instances()['image_optimizer'] ?? null;
	if ( ! $module ) {
		WP_CLI::error( 'The Image Optimizer module is not active (run.sh install-lumia, then activate it).' );
	}

	$import = static function ( string $fixture, string $name ): int {
		$tmp = wp_tempnam( $name );
		copy( UN_FIXTURES . '/' . $fixture, $tmp );
		$id = media_handle_sideload(
			[
				'name'     => $name,
				'tmp_name' => $tmp,
			],
			0
		);
		if ( is_wp_error( $id ) ) {
			WP_CLI::error( "import {$name}: " . $id->get_error_message() );
		}
		return (int) $id;
	};

	$run      = substr( md5( uniqid( '', true ) ), 0, 6 );
	$photo    = $import( 'photo-p3.jpg', "uninst-{$run}.jpg" );
	$excluded = $import( 'logo-flat.png', "uninst-excluded-{$run}.png" );
	$state    = \Lumia\Tools\Modules\ImageOptimizer\AvifState::class;

	// What the queue records after encoding: a sibling and a fingerprint per source file.
	$sizes = [];
	foreach ( un_sources( $photo ) as $path ) {
		file_put_contents( $path . '.avif', 'fake-avif' );
		$sizes[ $path ] = $state::fingerprint( $path ) + [ 'avif_bytes' => 9 ];
	}
	$state::record_sizes( $photo, $sizes );
	$state::set_status( $photo, $state::DONE );
	$state::set_status( $excluded, $state::EXCLUDED );

	// A migrated item: its main sibling is a hard link to the legacy AVIF, which must outlive
	// both the purge and the uninstall (old URLs keep working).
	$main   = un_sources( $photo )[0];
	$legacy = preg_replace( '/\.jpe?g$/i', '.avif', $main );
	file_put_contents( $legacy, 'legacy-avif-' . $run );
	unlink( $main . '.avif' );
	link( $legacy, $main . '.avif' );
	update_post_meta( $photo, $state::LEGACY, [ $state::rel( $legacy ) ] );

	// Metas and options of the former pipeline and of the migration.
	foreach ( [ '_lumia_optimized' => time(), '_lumia_original_bytes' => 100, '_lumia_optimized_bytes' => 60, '_lumia_bytes_saved' => 40, '_lumia_main_original_bytes' => 50, '_lumia_main_optimized_bytes' => 30, '_lumia_main_bytes_saved' => 20, '_lumia_optimized_format' => 'avif', '_lumia_optimized_mime' => 'image/avif', '_lumia_backup_file' => 'x.jpg', '_lumia_fallback_files' => [ 'x.jpg' ], '_lumia_migration' => [ 'step' => 'planned' ] ] as $key => $value ) {
		update_post_meta( $photo, $key, $value );
	}
	$token = (string) get_option( 'lumia_module_image_optimizer_backup_token', '' );
	if ( '' === $token ) {
		$token = 'e2euninst' . $run;
		update_option( 'lumia_module_image_optimizer_backup_token', $token, false );
	}
	$original = un_uploads() . 'lumia-originals-' . $token . '/2026/01/kept-' . $run . '.jpg';
	wp_mkdir_p( dirname( $original ) );
	file_put_contents( $original, 'kept-original' );
	update_option( 'lumia_module_image_optimizer_stats', [ 'optimized' => 1 ], false );
	update_option( 'lumia_module_image_optimizer_bulk_state', [ 'user_id' => 1 ], false );
	update_option( 'lumia_module_image_optimizer_migration_pairs', [ 'a.avif' => 'a.jpg' ], false );
	update_option( 'lumia_module_image_optimizer_reconcile_cursor', 1, false );
	update_option( 'lumia_module_image_optimizer_tombstones', [ '2026/01/gone.jpg' => time() ], false );

	( new \Lumia\Tools\Modules\ImageOptimizer\HtaccessWriter() )->write();
	( new \Lumia\Tools\Modules\ImageOptimizer\DeliveryProbe( '__return_null' ) )->ensure_probe_files();
	un_schedule_events();

	// Every item with a state and its siblings on disk now: deactivation must take them all.
	$with_state = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ( %s, %s )", $state::META, $state::STATUS ) ) );
	$siblings   = [];
	foreach ( $with_state as $id ) {
		foreach ( un_sources( $id ) as $path ) {
			if ( is_file( $path . '.avif' ) ) {
				$siblings[] = $path . '.avif';
			}
		}
	}

	WP_CLI::log( 'Seeded' );
	un_check( count( $sizes ) > 1 && is_file( $main . '.avif' ) && fileinode( $main . '.avif' ) === fileinode( $legacy ), 'photo: ' . count( $sizes ) . ' siblings, the main one hard-linked to the legacy AVIF' );
	un_check( un_htaccess_has_block(), 'uploads/.htaccess holds the block' );
	un_check( is_dir( un_uploads() . 'lumia-tools' ), 'uploads/lumia-tools/ exists' );
	un_check( 4 === count( un_scheduled_hooks() ), 'an event for each module hook (' . implode( ', ', un_scheduled_hooks() ) . ')' );
	WP_CLI::log( '  info ' . count( $siblings ) . ' generated siblings on disk for ' . count( $with_state ) . ' items with a state' );

	file_put_contents(
		UN_STATE,
		wp_json_encode(
			[
				'photo'    => $photo,
				'excluded' => $excluded,
				'legacy'   => $legacy,
				'legacy_v' => 'legacy-avif-' . $run,
				'original' => $original,
				'siblings' => $siblings,
				'detail'   => get_post_meta( $photo, $state::META, true ),
			]
		)
	);
	un_finish( 'seed' );
	return;
}

/* ------------------------------------------------------------------ */

$saved = json_decode( (string) file_get_contents( UN_STATE ), true );
if ( ! is_array( $saved ) ) {
	WP_CLI::error( 'No state file: run the seed phase first.' );
}
$photo = (int) $saved['photo'];

if ( 'deactivated' === $phase ) {
	un_check( ! is_plugin_active( 'lumia-tools/lumia-tools.php' ), 'the plugin is inactive' );

	WP_CLI::log( 'Plugin deactivation' );
	$left = array_values( array_filter( $saved['siblings'], 'file_exists' ) );
	un_check( [] === $left, 'no generated sibling left of the ' . count( $saved['siblings'] ) . ' (' . implode( ', ', array_slice( $left, 0, 3 ) ) . ')' );
	un_check( is_file( $saved['legacy'] ) && file_get_contents( $saved['legacy'] ) === $saved['legacy_v'], 'the legacy AVIF of the migrated item is intact' );
	un_check( [] !== get_post_meta( $photo, '_lumia_avif_legacy', true ), 'the legacy list is kept' );
	un_check( '' === get_post_meta( $photo, '_lumia_avif', true ) && '' === get_post_meta( $photo, '_lumia_avif_status', true ), 'state metas reset' );
	un_check( 'excluded' === get_post_meta( (int) $saved['excluded'], '_lumia_avif_status', true ), 'the excluded item keeps its status' );
	un_check( ! un_htaccess_has_block(), 'no block in uploads/.htaccess' );
	un_check( [] === un_scheduled_hooks(), 'no event of a module hook, with or without arguments (' . implode( ', ', un_scheduled_hooks() ) . ')' );
	$global = get_option( 'lumia_settings' );
	un_check( is_array( $global ) && ! empty( $global['modules']['image_optimizer'] ), 'the module stays on in the plugin settings' );
	un_check( is_array( get_option( 'lumia_module_image_optimizer_tombstones' ) ), 'the module data is kept (name registry)' );

	// Back to an uninstallable state, without the plugin loaded.
	foreach ( un_sources( $photo ) as $path ) {
		file_put_contents( $path . '.avif', 'fake-avif' );
	}
	update_post_meta( $photo, '_lumia_avif', $saved['detail'] );
	update_post_meta( $photo, '_lumia_avif_status', 'done' );
	file_put_contents( un_uploads() . '.htaccess', "# keep-me\n\n" . UN_BEGIN . "\n# END Lumia Tools AVIF\n" );
	un_schedule_events();
	un_check( 4 === count( un_scheduled_hooks() ) && un_htaccess_has_block(), 'siblings, state, block and events seeded again' );

	un_finish( 'deactivated' );
	return;
}

/* ------------------------------------------------------------------ */

if ( 'uninstalled' === $phase ) {
	un_check( ! is_dir( WP_PLUGIN_DIR . '/lumia-tools' ), 'the plugin files are deleted' );

	WP_CLI::log( 'Files' );
	$left = array_values( array_filter( array_map( static fn( string $p ): string => $p . '.avif', un_sources( $photo ) ), 'file_exists' ) );
	un_check( [] === $left, 'no sibling of the media item (' . implode( ', ', $left ) . ')' );
	un_check( is_file( $saved['legacy'] ) && file_get_contents( $saved['legacy'] ) === $saved['legacy_v'], 'the legacy AVIF is still on disk' );
	un_check( is_file( $saved['original'] ), 'the kept original is still on disk' );
	un_check( ! un_htaccess_has_block(), 'no block in uploads/.htaccess' );
	un_check( is_file( un_uploads() . '.htaccess' ) && false !== strpos( (string) file_get_contents( un_uploads() . '.htaccess' ), '# keep-me' ), 'the rest of uploads/.htaccess is kept' );
	un_check( ! file_exists( un_uploads() . 'lumia-tools' ), 'uploads/lumia-tools/ is deleted' );

	WP_CLI::log( 'Database' );
	$options = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'lumia_module_image_optimizer' ) . '%' ) );
	un_check( [] === $options, 'no option of the module (' . implode( ', ', $options ) . ')' );
	$metas = $wpdb->get_col( "SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_lumia\\_avif%' OR meta_key LIKE '\\_lumia\\_optimized%' OR meta_key LIKE '\\_lumia\\_%bytes%' OR meta_key IN ( '_lumia_backup_file', '_lumia_fallback_files', '_lumia_migration' )" );
	un_check( [] === $metas, 'no meta of the module (' . implode( ', ', $metas ) . ')' );
	un_check( [] === un_scheduled_hooks(), 'no event of a module hook (' . implode( ', ', un_scheduled_hooks() ) . ')' );
	$others = $wpdb->get_col( "SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_lumia\\_%'" );
	WP_CLI::log( '  info other _lumia_* post metas left: ' . ( $others ? implode( ', ', $others ) : 'none' ) );

	// The bench data of this run.
	wp_delete_attachment( $photo, true );
	wp_delete_attachment( (int) $saved['excluded'], true );
	wp_delete_file( $saved['legacy'] );
	wp_delete_file( $saved['original'] );
	wp_delete_file( UN_STATE );

	un_finish( 'uninstalled' );
	return;
}

WP_CLI::error( "Unknown phase '{$phase}' (seed, deactivated, uninstalled)." );
