<?php
/**
 * Bench check of the per-media AVIF state and of the file lifecycle (spec sections 3, 4, 9.4,
 * 9.5, 9.7, 9.8, 9.13), run with `run.sh assert <stack> tools/e2e-images/assert-lifecycle.php`
 * (WP-CLI eval-file, as admin). Bench only, never shipped.
 *
 * Needs the plugin installed (`run.sh install-lumia <stack>`) with the Image Optimizer module
 * active. No AVIF is encoded here (the queue runner is not part of this check): the siblings
 * are fake files written by hand, and the encoder's bookkeeping is simulated through
 * AvifState::record_sizes().
 */

use Lumia\Tools\Core\Plugin;
use Lumia\Tools\Modules\Files\FileManager;
use Lumia\Tools\Modules\ImageOptimizer\AvifState;
use Lumia\Tools\Modules\ImageOptimizer\DeliveryProbe;
use Lumia\Tools\Modules\ImageOptimizer\FileLifecycle;

if ( ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

// wp eval-file includes this file from inside a function: globals must be declared.
global $lc_failures, $lc_created, $lc_enqueued, $wpdb;
$lc_failures = 0;
$lc_created  = [];
$lc_enqueued = [];

function lc_check( bool $ok, string $label ): void {
	global $lc_failures;
	WP_CLI::log( ( $ok ? '  ok   ' : '  FAIL ' ) . $label );
	if ( ! $ok ) {
		++$lc_failures;
	}
}

function lc_skip( string $label ): void {
	WP_CLI::log( '  skip ' . $label );
}

const LC_FIXTURES = '/bench/out/fixtures';

/**
 * Sideloads a fixture under a chosen name, like an upload. Returns the attachment ID (0 on error).
 */
function lc_import( string $fixture, string $name ): int {
	global $lc_created;
	$tmp = wp_tempnam( $name );
	copy( LC_FIXTURES . '/' . $fixture, $tmp );
	$id = media_handle_sideload(
		[
			'name'     => $name,
			'tmp_name' => $tmp,
		],
		0
	);
	if ( is_wp_error( $id ) ) {
		WP_CLI::warning( "import {$name}: " . $id->get_error_message() );
		@unlink( $tmp );
		return 0;
	}
	$lc_created[] = (int) $id;
	return (int) $id;
}

/**
 * Hand-made sibling (not a real AVIF: the lifecycle never reads it).
 */
function lc_fake_sibling( string $path ): void {
	file_put_contents( FileLifecycle::sibling( $path ), 'fake-avif' );
}

/**
 * Simulates what the queue runner (task 5) records after encoding every source file: fake
 * sibling + fingerprint, status done. Sources are first dated one hour back, so that a
 * rewrite of the same file within the same second still changes the fingerprint.
 */
function lc_simulate_encoded( int $id, FileLifecycle $lc ): void {
	$sizes = [];
	foreach ( $lc->source_files( $id ) as $path ) {
		touch( $path, time() - 3600 );
		clearstatcache( true, $path );
		lc_fake_sibling( $path );
		$sizes[ $path ] = AvifState::fingerprint( $path ) + [ 'avif_bytes' => 9 ];
	}
	AvifState::record_sizes( $id, $sizes );
	AvifState::set_status( $id, AvifState::DONE );
}

function lc_siblings( int $id, FileLifecycle $lc ): array {
	return array_values( array_filter( array_map( [ FileLifecycle::class, 'sibling' ], $lc->source_files( $id ) ), 'file_exists' ) );
}

add_action(
	'lumia_image_optimizer_enqueued',
	static function ( int $id ): void {
		global $lc_enqueued;
		$lc_enqueued[ $id ] = ( $lc_enqueued[ $id ] ?? 0 ) + 1;
	}
);

$run    = substr( md5( uniqid( '', true ) ), 0, 6 );
$plugin = Plugin::instance();
$module = $plugin->modules->get_active_instances()['image_optimizer'] ?? null;

if ( ! $module ) {
	WP_CLI::error( 'The Image Optimizer module is not active (run.sh install-lumia, then activate it).' );
}

if ( ! class_exists( AvifState::class ) || ! method_exists( $module, 'get_lifecycle' ) ) {
	WP_CLI::error( 'AvifState / FileLifecycle are not part of the installed plugin.' );
}

/** @var FileLifecycle $lc */
$lc          = $module->get_lifecycle();
$option_key  = 'lumia_module_image_optimizer';
$saved_opt   = get_option( $option_key, null );
$uploads_dir = wp_upload_dir();

// --- Settings v2 ----------------------------------------------------------------

WP_CLI::log( 'Settings' );

update_option(
	$option_key,
	[
		'quality'       => 75,
		'format_mode'   => 'auto',
		'keep_original' => false,
		'max_width'     => 1920,
		'max_height'    => 2560,
		'strip_exif'    => false,
	]
);
$settings = $module->get_settings();
$stored   = get_option( $option_key );
lc_check( 70 === $settings['quality'], 'old quality 75 read as 70' );
lc_check( 2560 === $settings['max_dimension'], 'max_dimension = max(max_width, max_height) = 2560' );
lc_check( ! array_intersect_key( $settings, array_flip( [ 'format_mode', 'keep_original', 'max_width', 'max_height' ] ) ), 'removed keys absent from the settings' );
lc_check( 2 === ( $stored['settings_version'] ?? null ) && ! isset( $stored['format_mode'] ), 'migration persisted (settings_version 2, old keys gone)' );
lc_check( false === $settings['strip_exif'], 'unrelated stored value kept (strip_exif false)' );
lc_check( 'balanced' === $settings['speed'] && [ '-noopt' ] === $settings['exclude_suffixes'] && true === $settings['convert_modern_uploads'], 'new keys default: speed balanced, exclude_suffixes [-noopt], convert_modern_uploads' );

$stored['quality'] = 55;
update_option( $option_key, $stored );
lc_check( 55 === $module->get_settings()['quality'], 'migration runs once: a later quality 55 is kept' );

$module->save_settings(
	[
		'quality'          => '150',
		'speed'            => 'turbo',
		'max_dimension'    => '0',
		'exclude_suffixes' => "-NoOpt, -raw\n bad suffix!",
		'svg_roles'        => [ 'administrator' ],
	]
);
$saved = get_option( $option_key );
lc_check( 100 === $saved['quality'] && 'balanced' === $saved['speed'] && 0 === $saved['max_dimension'], 'save: quality clamped, unknown speed -> balanced, max_dimension 0 allowed' );
lc_check( [ '-noopt', '-raw', '-badsuffix' ] === $saved['exclude_suffixes'], 'save: suffixes lowercased and cleaned (' . wp_json_encode( $saved['exclude_suffixes'] ) . ')' );
lc_check( false === $saved['convert_modern_uploads'] && 2 === $saved['settings_version'], 'save: unchecked convert_modern_uploads = false, settings_version 2' );

// Back to the defaults for the rest of the run.
delete_option( $option_key );

// --- Filters ---------------------------------------------------------------------

WP_CLI::log( 'Filters' );

lc_check( 2560 === apply_filters( 'big_image_size_threshold', 2560, [ 4000, 3000 ], '', 0 ), 'big_image_size_threshold = max_dimension (2560)' );
update_option( $option_key, [ 'max_dimension' => 0 ] );
lc_check( false === apply_filters( 'big_image_size_threshold', 2560, [ 4000, 3000 ], '', 0 ), 'max_dimension 0 -> threshold disabled (false)' );
update_option( $option_key, [ 'max_dimension' => 1600 ] );
lc_check( 1600 === apply_filters( 'big_image_size_threshold', 2560, [ 4000, 3000 ], '', 0 ), 'max_dimension 1600 -> 1600' );
delete_option( $option_key );

lc_check( true === apply_filters( 'image_save_progressive', false, 'image/jpeg' ), 'image_save_progressive: true for image/jpeg' );
lc_check( false === apply_filters( 'image_save_progressive', false, 'image/png' ), 'image_save_progressive: unchanged for image/png' );
lc_check( false === apply_filters( 'wp_client_side_media_processing_enabled', true ), 'wp_client_side_media_processing_enabled: false' );

// --- Exclusion by name -------------------------------------------------------------

WP_CLI::log( 'Exclusion by name' );

foreach ( [ 'logo-noopt.png', 'logo-NOOPT.PNG', 'logo-noopt-1.png', 'logo-noopt-12.jpg', '2026/10/photo-noopt-scaled.jpg' ] as $name ) {
	lc_check( $lc->is_excluded_by_name( $name ), "{$name} excluded" );
}
foreach ( [ 'logo-nooptimal.png', 'noopt.png', 'logo-noopt-x.png', 'logo.png' ] as $name ) {
	lc_check( ! $lc->is_excluded_by_name( $name ), "{$name} not excluded" );
}

// --- Upload: queued once, scaled at 2560 ------------------------------------------

WP_CLI::log( 'Upload' );

$big = lc_import( 'photo-4000.jpg', "big-{$run}.jpg" );
$meta = wp_get_attachment_metadata( $big );
$state = AvifState::get( $big );
lc_check( $big > 0 && AvifState::PENDING === $state['status'], 'photo-4000.jpg: status pending (got ' . ( $state['status'] ?? 'none' ) . ')' );
lc_check( is_array( $meta ) && str_ends_with( (string) $meta['file'], "big-{$run}-scaled.jpg" ) && 2560 === (int) $meta['width'], 'metadata file is -scaled at 2560 px' );
lc_check( $state['gen'] >= 1 && $state['gen'] <= 2, 'one useful generation during the upload (_lumia_avif_gen = ' . $state['gen'] . ')' );
lc_check( ( $lc_enqueued[ $big ] ?? 0 ) === $state['gen'], 'lumia_image_optimizer_enqueued fired once per generation (' . ( $lc_enqueued[ $big ] ?? 0 ) . ')' );
lc_check( 'upload' === $state['origin'] && $state['queued_at'] > 0, 'origin upload, queued_at set' );
lc_check( AvifState::gen( $big ) === $state['gen'], 'AvifState::gen() reads the stored generation' );

$sources = $lc->source_files( $big );
$orig    = wp_get_original_image_path( $big );
lc_check( count( $sources ) === 1 + count( $meta['sizes'] ) && ! in_array( $orig, $sources, true ), 'source_files: main + every size, never original_image (' . count( $sources ) . ')' );
lc_check( FileLifecycle::sibling( '/x/a.jpg' ) === '/x/a.jpg.avif', 'sibling() appends .avif' );

$gen = $state['gen'];
wp_update_attachment_metadata( $big, $meta );
lc_check( AvifState::gen( $big ) === $gen, 'unchanged wp_update_attachment_metadata: not queued again' );

$next = AvifState::next_pending();
lc_check( null !== $next && AvifState::PENDING === AvifState::get( $next )['status'], 'next_pending() returns a pending media item' );

update_option( $option_key, [ 'optimize_on_upload' => false ] );
$off = lc_import( 'logo-flat.png', "off-{$run}.png" );
lc_check( $off > 0 && '' === AvifState::get( $off )['status'] && '' === get_post_meta( $off, AvifState::META, true ) && ! isset( $lc_enqueued[ $off ] ), 'optimize_on_upload off: no state written, not queued' );
delete_option( $option_key );

// --- Excluded names ------------------------------------------------------------------

WP_CLI::log( 'Excluded uploads' );

$noopt = lc_import( 'visual-alpha-noopt.png', "visual-alpha-{$run}-noopt.png" );
lc_check( $noopt > 0 && AvifState::EXCLUDED === AvifState::get( $noopt )['status'], 'visual-alpha-noopt.png: excluded' );
lc_check( ! isset( $lc_enqueued[ $noopt ] ), 'excluded media: no enqueued action' );

$noopt1 = lc_import( 'logo-flat.png', "logo-{$run}-noopt-1.png" );
lc_check( $noopt1 > 0 && AvifState::EXCLUDED === AvifState::get( $noopt1 )['status'], 'logo-noopt-1.png: excluded' );

// --- EXIF stripped at upload, whether or not an AVIF is ever made -----------------------

WP_CLI::log( 'EXIF at upload' );

/**
 * EXIF / XMP APP1 segments and ICC profile present in a JPEG file.
 *
 * @return array{exif: bool, xmp: bool, icc: bool}
 */
function lc_jpeg_segments( string $path ): array {
	$data = (string) file_get_contents( $path );
	return [
		'exif' => str_contains( $data, "Exif\0\0" ),
		'xmp'  => str_contains( $data, 'http://ns.adobe.com/xap/1.0/' ),
		'icc'  => str_contains( $data, 'ICC_PROFILE' ),
	];
}

$gps_fixture = lc_jpeg_segments( LC_FIXTURES . '/photo-gps.jpg' );
lc_check( $gps_fixture['exif'] && $gps_fixture['xmp'] && $gps_fixture['icc'], 'photo-gps.jpg fixture: EXIF, XMP and ICC present' );

$exif_cases = [
	'excluded name'           => [ [], "gps-{$run}-noopt.jpg" ],
	'automatic processing off' => [ [ 'optimize_on_upload' => false ], "gps-off-{$run}.jpg" ],
];
foreach ( $exif_cases as $label => [ $settings, $name ] ) {
	if ( $settings ) {
		update_option( $option_key, $settings );
	}
	$gps  = lc_import( 'photo-gps.jpg', $name );
	$meta = wp_get_attachment_metadata( $gps );
	$main = (string) get_attached_file( $gps );
	$segs = lc_jpeg_segments( $main );
	lc_check( $gps > 0 && ! $segs['exif'] && ! $segs['xmp'] && $segs['icc'], "{$label}: served JPEG without EXIF / XMP, ICC kept (" . wp_json_encode( $segs ) . ')' );
	$stale = [];
	foreach ( (array) ( $meta['sizes'] ?? [] ) as $size ) {
		$path = path_join( dirname( $main ), (string) $size['file'] );
		if ( lc_jpeg_segments( $path )['exif'] || ( isset( $size['filesize'] ) && (int) $size['filesize'] !== (int) filesize( $path ) ) ) {
			$stale[] = $size['file'];
		}
	}
	lc_check( ! $stale && (int) ( $meta['filesize'] ?? -1 ) === (int) filesize( $main ), "{$label}: sizes stripped, metadata filesize matches the files" . ( $stale ? ' (' . implode( ', ', $stale ) . ')' : '' ) );
	delete_option( $option_key );
}

update_option( $option_key, [ 'strip_exif' => false ] );
$gps_kept = lc_import( 'photo-gps.jpg', "gps-kept-{$run}.jpg" );
lc_check( lc_jpeg_segments( (string) get_attached_file( $gps_kept ) )['exif'], 'strip_exif off: EXIF left in the served JPEG' );
delete_option( $option_key );

$counts = AvifState::count_by_status();
lc_check( $counts[ AvifState::EXCLUDED ] >= 2 && $counts[ AvifState::PENDING ] >= 1 && array_key_exists( AvifState::FAILED, $counts ), 'count_by_status() lists every status' );

// --- Deletion: siblings gone, names recorded ---------------------------------------

WP_CLI::log( 'Deletion and names' );

$tomb      = lc_import( 'logo-flat.png', "tomb-{$run}.png" );
$tomb_rel  = (string) get_post_meta( $tomb, '_wp_attached_file', true );
$tomb_srcs = $lc->source_files( $tomb );
foreach ( $tomb_srcs as $path ) {
	lc_fake_sibling( $path );
}
lc_fake_sibling( dirname( $tomb_srcs[0] ) . "/tomb-{$run}-e1700000000000.png" ); // Not a source: must survive.
wp_delete_attachment( $tomb, true );
$left = array_filter( array_map( [ FileLifecycle::class, 'sibling' ], $tomb_srcs ), 'file_exists' );
lc_check( ! $left, 'wp_delete_attachment: no .avif of the media item left (' . count( $left ) . ')' );
$lc->flush_tombstones();
$tombstones = (array) get_option( 'lumia_module_image_optimizer_tombstones', [] );
lc_check( isset( $tombstones[ $tomb_rel ] ), "registry holds {$tomb_rel}" );
lc_check( ! isset( wp_load_alloptions()['lumia_module_image_optimizer_tombstones'] ), 'registry option not autoloaded' );
lc_check( file_exists( dirname( $tomb_srcs[0] ) . "/tomb-{$run}-e1700000000000.png.avif" ), 'an .avif that is not the media item\'s is left alone' );

$re     = lc_import( 'logo-flat.png', "tomb-{$run}.png" );
$re_rel = (string) get_post_meta( $re, '_wp_attached_file', true );
lc_check( $re > 0 && basename( $re_rel ) !== "tomb-{$run}.png" && preg_match( "/^tomb-{$run}-\\d+\\.png$/", basename( $re_rel ) ), "reimport of a deleted name gets another name ({$re_rel})" );

$dir = $uploads_dir['path'];
file_put_contents( "{$dir}/orphan-{$run}.jpg.avif", 'orphan' );
$orphan = lc_import( 'photo-p3.jpg', "orphan-{$run}.jpg" );
$orphan_name = basename( (string) get_post_meta( $orphan, '_wp_attached_file', true ) );
lc_check( $orphan > 0 && "orphan-{$run}.jpg" !== $orphan_name, "orphan .avif on disk: orphan.jpg renamed ({$orphan_name})" );

file_put_contents( "{$dir}/fam-{$run}-300x200.jpg.avif", 'orphan size' );
$fam      = lc_import( 'photo-p3.jpg', "fam-{$run}.jpg" );
$fam_name = basename( (string) get_post_meta( $fam, '_wp_attached_file', true ) );
lc_check( $fam > 0 && "fam-{$run}.jpg" !== $fam_name, "only photo-300x200.jpg.avif left: photo.jpg renamed ({$fam_name})" );

$free      = lc_import( 'photo-p3.jpg', "free-{$run}.jpg" );
$free_name = basename( (string) get_post_meta( $free, '_wp_attached_file', true ) );
lc_check( "free-{$run}.jpg" === $free_name, "a free name is kept ({$free_name})" );

// --- Regeneration and metadata diffs --------------------------------------------------

WP_CLI::log( 'Regeneration' );

$regen = $free;
lc_simulate_encoded( $regen, $lc );
lc_check( AvifState::is_fresh( $regen, $lc->source_files( $regen )[0] ), 'recorded fingerprint: is_fresh() true' );
$gen_before = AvifState::gen( $regen );

// photo-p3.jpg is 1600 px: below the threshold, WordPress keeps the main file as uploaded and
// `wp media regenerate` only unlinks and rewrites the sizes. The main file's AVIF is still valid.
$main = $lc->source_files( $regen )[0];
WP_CLI::runcommand( "media regenerate {$regen} --yes", [ 'launch' => false, 'return' => 'all' ] );
$left = lc_siblings( $regen, $lc );
lc_check( [ FileLifecycle::sibling( $main ) ] === $left, 'wp media regenerate (unlink + rewrite): every size\'s stale .avif deleted, the untouched main file\'s kept (' . count( $left ) . ' left)' );
lc_check( AvifState::PENDING === AvifState::get( $regen )['status'] && AvifState::gen( $regen ) > $gen_before, 'wp media regenerate: queued again (pending, new generation)' );
$sizes_now = $lc->source_files( $regen );
lc_check( ! AvifState::is_fresh( $regen, (string) end( $sizes_now ) ) && AvifState::is_fresh( $regen, $main ), 'is_fresh(): false for a rewritten size, true for the untouched main file' );

// A scaled image: the -scaled main file is rewritten too, nothing survives.
$big_regen = $big;
lc_simulate_encoded( $big_regen, $lc );
WP_CLI::runcommand( "media regenerate {$big_regen} --yes", [ 'launch' => false, 'return' => 'all' ] );
$left = lc_siblings( $big_regen, $lc );
lc_check( ! $left && AvifState::PENDING === AvifState::get( $big_regen )['status'], 'wp media regenerate of a -scaled image: every .avif deleted, pending (' . count( $left ) . ' left)' );

// One file changed: only that one is queued, the others keep their AVIF.
lc_simulate_encoded( $regen, $lc );
$srcs    = $lc->source_files( $regen );
$changed = end( $srcs );
file_put_contents( $changed, file_get_contents( $changed ) . 'x' );
wp_update_attachment_metadata( $regen, wp_get_attachment_metadata( $regen ) );
lc_check( ! file_exists( FileLifecycle::sibling( $changed ) ) && ! AvifState::is_fresh( $regen, $changed ), 'changed size: its .avif deleted, not fresh' );
lc_check( count( lc_siblings( $regen, $lc ) ) === count( $srcs ) - 1, 'unchanged sizes keep their .avif' );
lc_check( AvifState::PENDING === AvifState::get( $regen )['status'], 'changed size: media item pending' );

// One size dropped from the metadata: its .avif goes, nothing else is queued.
lc_simulate_encoded( $regen, $lc );
$meta    = wp_get_attachment_metadata( $regen );
$dropped = array_key_first( $meta['sizes'] );
$dropped_path = path_join( dirname( get_attached_file( $regen ) ), $meta['sizes'][ $dropped ]['file'] );
unset( $meta['sizes'][ $dropped ] );
$gen_before = AvifState::gen( $regen );
wp_update_attachment_metadata( $regen, $meta );
lc_check( ! file_exists( FileLifecycle::sibling( $dropped_path ) ), "size {$dropped} dropped: its .avif deleted" );
lc_check( AvifState::DONE === AvifState::get( $regen )['status'] && AvifState::gen( $regen ) === $gen_before, 'size dropped only: status stays done, not queued' );

// --- Files module ------------------------------------------------------------------------

WP_CLI::log( 'Files module' );

$fm      = new FileManager( ABSPATH );
$root    = $fm->get_root();
$abs     = "{$dir}/fm-{$run}.jpg";
copy( LC_FIXTURES . '/photo-p3.jpg', $abs );
lc_fake_sibling( $abs );
$rel_of  = static fn( string $path ): string => ltrim( substr( $path, strlen( $root ) ), '/' );

$fm->rename( $rel_of( $abs ), "fm-{$run}-renamed.jpg" );
$renamed = "{$dir}/fm-{$run}-renamed.jpg";
lc_check( file_exists( FileLifecycle::sibling( $renamed ) ) && ! file_exists( FileLifecycle::sibling( $abs ) ), 'rename: the .avif follows' );

mkdir( "{$dir}/fm-{$run}-dir" );
$fm->move( $rel_of( $renamed ), $rel_of( "{$dir}/fm-{$run}-dir" ) );
$moved = "{$dir}/fm-{$run}-dir/fm-{$run}-renamed.jpg";
lc_check( file_exists( FileLifecycle::sibling( $moved ) ) && ! file_exists( FileLifecycle::sibling( $renamed ) ), 'move: the .avif follows' );

$fm->delete( $rel_of( $moved ) );
lc_check( ! file_exists( FileLifecycle::sibling( $moved ) ), 'delete: the .avif is deleted' );

// A file whose content is replaced loses the AVIF of the former picture.
$edited = "{$dir}/fm-{$run}-edited.jpg";
copy( LC_FIXTURES . '/photo-p3.jpg', $edited );
lc_fake_sibling( $edited );
$fm->save_content( $rel_of( $edited ), (string) file_get_contents( LC_FIXTURES . '/photo-gps.jpg' ) );
lc_check( ! file_exists( FileLifecycle::sibling( $edited ) ), 'save_content over a JPEG: its .avif is deleted' );

if ( class_exists( 'ZipArchive' ) ) {
	$zipped = "{$dir}/fm-{$run}-dir/fm-{$run}-zipped.jpg";
	copy( LC_FIXTURES . '/photo-p3.jpg', $zipped );
	lc_fake_sibling( $zipped );
	$zip_path = "{$dir}/fm-{$run}-dir/fm-{$run}.zip";
	$zip      = new ZipArchive();
	$zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	$zip->addFile( LC_FIXTURES . '/photo-gps.jpg', "fm-{$run}-zipped.jpg" );
	$zip->close();
	$fm->extract_zip( $rel_of( $zip_path ) );
	lc_check( md5_file( $zipped ) === md5_file( LC_FIXTURES . '/photo-gps.jpg' ) && ! file_exists( FileLifecycle::sibling( $zipped ) ), 'archive extracted over a JPEG: its .avif is deleted' );
	@unlink( $zipped );
	@unlink( $zip_path );
} else {
	lc_skip( 'archive extraction: ZipArchive missing' );
}

// Renamed or moved onto a name that has an orphan .avif (its JPEG deleted outside WordPress),
// the source having none: the orphan would be served in place of the moved image.
$plain = "{$dir}/fm-{$run}-plain.jpg";
copy( LC_FIXTURES . '/photo-p3.jpg', $plain );
file_put_contents( "{$dir}/fm-{$run}-target.jpg.avif", 'orphan' );
$fm->rename( $rel_of( $plain ), "fm-{$run}-target.jpg" );
lc_check( ! file_exists( "{$dir}/fm-{$run}-target.jpg.avif" ), 'rename onto a name with an orphan .avif: the orphan is deleted' );
file_put_contents( "{$dir}/fm-{$run}-dir/fm-{$run}-target.jpg.avif", 'orphan' );
$fm->move( $rel_of( "{$dir}/fm-{$run}-target.jpg" ), $rel_of( "{$dir}/fm-{$run}-dir" ) );
lc_check( ! file_exists( "{$dir}/fm-{$run}-dir/fm-{$run}-target.jpg.avif" ), 'move onto a name with an orphan .avif: the orphan is deleted' );
@unlink( "{$dir}/fm-{$run}-dir/fm-{$run}-target.jpg" );
@unlink( $edited );
@rmdir( "{$dir}/fm-{$run}-dir" );

// --- Uploaded AVIF / WebP (spec 9.13) ------------------------------------------------------

WP_CLI::log( 'Uploaded AVIF / WebP' );

$can_avif = class_exists( 'Imagick' ) && Imagick::queryFormats( 'AVIF' ) && file_exists( LC_FIXTURES . '/modern-opaque.avif' );
$can_webp = class_exists( 'Imagick' ) && Imagick::queryFormats( 'WEBP' ) && file_exists( LC_FIXTURES . '/modern-alpha.webp' );

if ( ! $can_avif ) {
	lc_skip( 'AVIF conversion: this stack\'s Imagick cannot read AVIF, or the fixture could not be generated' );
} else {
	$opaque = lc_import( 'modern-opaque.avif', "modern-{$run}.avif" );
	$file   = (string) get_attached_file( $opaque );
	$meta   = wp_get_attachment_metadata( $opaque );
	lc_check( $opaque > 0 && str_ends_with( $file, "modern-{$run}.jpg" ) && 'image/jpeg' === get_post_mime_type( $opaque ), 'opaque AVIF -> JPEG (' . basename( $file ) . ')' );
	lc_check( 'image/jpeg' === wp_get_image_mime( $file ) && ! file_exists( "{$dir}/modern-{$run}.avif" ), 'real JPEG on disk, uploaded AVIF removed' );
	lc_check( ! empty( $meta['sizes'] ) && str_ends_with( (string) reset( $meta['sizes'] )['file'], '.jpg' ), 'JPEG sizes generated' );
	lc_check( AvifState::PENDING === AvifState::get( $opaque )['status'], 'converted upload queued for AVIF siblings' );
	$im = new Imagick( $file );
	// Progressive = SOF2 marker (0xFFC2); a baseline JPEG has SOF0 (0xFFC0).
	lc_check( false !== strpos( (string) file_get_contents( $file ), "\xFF\xC2" ) && 90 === $im->getImageCompressionQuality(), 'JPEG fallback progressive (SOF2), quality 90 (' . $im->getImageCompressionQuality() . ')' );
	$im->clear();

	update_option( $option_key, [ 'convert_modern_uploads' => false ] );
	$kept = lc_import( 'modern-opaque.avif', "modern-off-{$run}.avif" );
	lc_check( $kept > 0 && str_ends_with( (string) get_attached_file( $kept ), '.avif' ), 'setting off: AVIF left as uploaded' );
	lc_check( AvifState::SKIPPED === AvifState::get( $kept )['status'] && '' !== AvifState::get( $kept )['error'], 'setting off: skipped with a reason (' . AvifState::get( $kept )['error'] . ')' );
	delete_option( $option_key );
}

if ( ! $can_webp ) {
	lc_skip( 'WebP conversion: this stack\'s Imagick cannot read WebP, or the fixture could not be generated' );
} else {
	$alpha = lc_import( 'modern-alpha.webp', "modern-alpha-{$run}.webp" );
	$file  = (string) get_attached_file( $alpha );
	lc_check( $alpha > 0 && str_ends_with( $file, '.png' ) && 'image/png' === wp_get_image_mime( $file ), 'WebP with alpha -> PNG (' . basename( $file ) . ')' );

	if ( file_exists( LC_FIXTURES . '/modern-anim.webp' ) ) {
		$anim = lc_import( 'modern-anim.webp', "modern-anim-{$run}.webp" );
		lc_check( $anim > 0 && str_ends_with( (string) get_attached_file( $anim ), '.webp' ), 'animated WebP left as uploaded' );
		lc_check( AvifState::SKIPPED === AvifState::get( $anim )['status'], 'animated WebP: skipped (' . AvifState::get( $anim )['error'] . ')' );
	} else {
		lc_skip( 'animated WebP: fixture could not be generated on this stack' );
	}
}

// --- Reconcile, then deactivation purge ------------------------------------------------------

WP_CLI::log( 'Reconcile and deactivation' );

$rec = $orphan;
lc_simulate_encoded( $rec, $lc );
$rec_src = $lc->source_files( $rec )[0];
file_put_contents( $rec_src, file_get_contents( $rec_src ) . 'x' ); // Changed behind WordPress (FTP).
$requeued = 0;
for ( $i = 0; $i < 50 && AvifState::DONE === AvifState::get( $rec )['status']; $i++ ) {
	$requeued += $lc->reconcile( 100 );
}
lc_check( $requeued >= 1 && AvifState::PENDING === AvifState::get( $rec )['status'] && 'reconcile' === AvifState::get( $rec )['origin'], 'reconcile(): changed source queued again (origin reconcile)' );
lc_check( ! file_exists( FileLifecycle::sibling( $rec_src ) ), 'reconcile(): stale .avif deleted' );
lc_check( 20 === has_action( DeliveryProbe::CRON_HOOK, [ $lc, 'run_reconcile' ] ), 'the daily delivery check also walks the fingerprints (run_reconcile on ' . DeliveryProbe::CRON_HOOK . ')' );

// Generation flag: wp_update_image_subsizes() with no size missing (REST post-process retry,
// media_create_image_subsizes) ends without wp_generate_attachment_metadata. A flag left up
// would make every later metadata save of the item ignored for the rest of the request.
lc_simulate_encoded( $fam, $lc );
wp_update_image_subsizes( $fam );
$fam_srcs = $lc->source_files( $fam );
$fam_size = end( $fam_srcs );
file_put_contents( $fam_size, file_get_contents( $fam_size ) . 'x' );
wp_update_attachment_metadata( $fam, wp_get_attachment_metadata( $fam ) );
lc_check( AvifState::PENDING === AvifState::get( $fam )['status'] && ! file_exists( FileLifecycle::sibling( $fam_size ) ), 'after wp_update_image_subsizes() with nothing missing, a metadata save is still handled (no stuck generation flag)' );

// Deactivation at scale: 3 000 fake media items (state metas only, files absent), one in ten
// excluded, one in a hundred with a legacy list, purged with the real ones.
$fake_first = 900000001;
$fake_count = 3000;
$fake_where = $wpdb->prepare( 'post_id BETWEEN %d AND %d', $fake_first, $fake_first + $fake_count - 1 );
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE {$fake_where}" );
$rows = [];
for ( $n = 0; $n < $fake_count; $n++ ) {
	$fid    = $fake_first + $n;
	$sizes  = [];
	foreach ( [ '', '-150x150', '-300x225', '-768x576', '-1024x768', '-1536x1152', '-2048x1536' ] as $suffix ) {
		$sizes[ "2026/10/purge-{$n}{$suffix}.jpg" ] = [ 'bytes' => 1000, 'mtime' => 1, 'avif_bytes' => 600 ];
	}
	$detail = maybe_serialize( [ 'sizes' => $sizes, 'error' => '', 'attempts' => 1, 'updated' => 1 ] );
	$status = 0 === $n % 10 ? AvifState::EXCLUDED : AvifState::DONE;
	foreach ( [ AvifState::META => $detail, AvifState::STATUS => $status, AvifState::GEN => '1', AvifState::QUEUED_AT => '1', AvifState::ORIGIN => 'bulk' ] as $key => $value ) {
		$rows[] = $wpdb->prepare( '( %d, %s, %s )', $fid, $key, $value );
	}
	if ( 0 === $n % 100 ) {
		$rows[] = $wpdb->prepare( '( %d, %s, %s )', $fid, AvifState::LEGACY, maybe_serialize( [ 'legacy.avif' ] ) );
	}
}
foreach ( array_chunk( $rows, 1000 ) as $chunk ) {
	$wpdb->query( "INSERT INTO {$wpdb->postmeta} ( post_id, meta_key, meta_value ) VALUES " . implode( ', ', $chunk ) );
}

lc_simulate_encoded( $fam, $lc );
lc_simulate_encoded( $big, $lc );
$t0 = microtime( true );
$module->on_deactivate();
$purge_ms = (int) round( ( microtime( true ) - $t0 ) * 1000 );
WP_CLI::log( "  info purge of {$fake_count} fake + the bench's media items: {$purge_ms} ms" );
$left = array_merge( lc_siblings( $fam, $lc ), lc_siblings( $big, $lc ) );
lc_check( ! $left, 'deactivation: no generated .avif left (' . count( $left ) . ')' );
lc_check( '' === (string) get_post_meta( $big, '_lumia_avif_status', true ) && '' === (string) get_post_meta( $big, '_lumia_avif', true ), 'deactivation: _lumia_avif* metas reset (meta cache cleared too)' );
lc_check( AvifState::EXCLUDED === AvifState::get( $noopt )['status'], 'deactivation: an exclusion survives (no AVIF to purge)' );
$fake_left = $wpdb->get_results( "SELECT meta_key, meta_value, COUNT(*) AS n FROM {$wpdb->postmeta} WHERE {$fake_where} GROUP BY meta_key, meta_value", ARRAY_A );
$by_key    = [];
foreach ( $fake_left as $row ) {
	$by_key[ $row['meta_key'] . '=' . ( AvifState::STATUS === $row['meta_key'] ? $row['meta_value'] : '*' ) ] = (int) $row['n'];
}
lc_check( [ AvifState::LEGACY . '=*' => 30, AvifState::STATUS . '=' . AvifState::EXCLUDED => 300 ] == $by_key, "purge at scale: only the 300 exclusions and the 30 legacy lists remain (" . wp_json_encode( $by_key ) . ')' );
lc_check( $purge_ms < 30000, "purge at scale completes well within a request ({$purge_ms} ms)" );
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE {$fake_where}" );

// --- Cleanup ------------------------------------------------------------------------------------

foreach ( $lc_created as $id ) {
	wp_delete_attachment( $id, true );
}
@unlink( "{$dir}/orphan-{$run}.jpg.avif" );
@unlink( "{$dir}/fam-{$run}-300x200.jpg.avif" );
@unlink( "{$dir}/tomb-{$run}-e1700000000000.png.avif" );
if ( null === $saved_opt ) {
	delete_option( $option_key );
} else {
	update_option( $option_key, $saved_opt );
}

if ( $lc_failures > 0 ) {
	WP_CLI::error( "{$lc_failures} lifecycle check(s) failed." );
}
WP_CLI::success( 'Lifecycle checks passed.' );
