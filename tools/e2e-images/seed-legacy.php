<?php
/**
 * Seeds media items as the FORMER Image Optimizer left them in production (before the AVIF
 * siblings): each JPEG/PNG replaced by an AVIF (or a WebP), the metadata and the URLs in the
 * database rewritten. Input of `assert-migration.php` (spec 5, 9.3, 9.9). Bench only, never
 * shipped.
 *
 *   run.sh assert nginx-mig tools/e2e-images/seed-legacy.php
 *
 * Runs in the FPM image's PHP (Imagick encodes AVIF there). The layout is the one of the
 * former `ImageProcessor::convert()` + `update_attachment_database_refs()` +
 * `update_metadata_after_conversion()` (commit 7a606e2): `stripImage()`, the AVIF next to the
 * source with the extension replaced, the source deleted (keep_original off, as on every
 * production site), `original_image` left alone, `_wp_attached_file` / `post_mime_type` /
 * metadata `file`, `sizes[*].file`, `sizes[*].mime-type`, `filesize` rewritten, the guid's
 * basename replaced, the `_lumia_optimized*` / `_lumia_*bytes*` metas.
 *
 * Everything lives in `uploads/e2e-legacy/` and is replaced on every run. Seeded items:
 *
 *   big-photo    photo-4000.jpg: scaled, original `big-photo.jpg` still on disk (case 1)
 *   big-lost     photo-4000.jpg: scaled, original deleted (case 2, `original_image` dropped)
 *   photo        photo-p3.jpg (case 2, JPEG fallback); URLs in content, Bricks, Rank Math...
 *   alpha        visual-alpha.png (case 2, PNG fallback: alpha used)
 *   logo         logo-flat.png (case 2, PNG fallback: opaque, flat colours)
 *   webp-photo   photo-bigicc.jpg converted to WebP (case 2, JPEG fallback, AVIF queued)
 *   collide      photo-p3.jpg, while a later upload `collide.jpg` (not legacy) took the name:
 *                the fallback family must move to `collide-1`
 *   backup       photo-gps.jpg with the former backup copy in `lumia-originals-<token>/`
 *                (`_lumia_backup_file`): regenerated from it (case 1)
 *   seq-a, seq-b photo-gps.jpg (case 2): a database step failing on a resumed seq-b must not
 *                touch seq-a's files
 *   late         photo-gps.jpg (case 2), late-backup (case 1 from a backup copy): killed at
 *                "planned", their reserved names then taken by other uploads
 *
 * Writes `out/mig-manifest.json` (IDs, page, paths) for assert-migration.php.
 */

use Lumia\Tools\Core\Plugin;

if ( ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

global $wpdb;

const SEED_FIXTURES = '/bench/out/fixtures';
const SEED_SUBDIR   = 'e2e-legacy';

// The plugin's lifecycle (if installed) must not queue or convert what is seeded here.
$seed_module = class_exists( Plugin::class ) ? ( Plugin::instance()->modules->get_active_instances()['image_optimizer'] ?? null ) : null;
if ( $seed_module && method_exists( $seed_module, 'get_lifecycle' ) && method_exists( $seed_module->get_lifecycle(), 'suspend' ) ) {
	$seed_module->get_lifecycle()->suspend();
}

$uploads = wp_upload_dir( null, false );
$base    = $uploads['basedir'];
$baseurl = $uploads['baseurl'];
$dir     = $base . '/' . SEED_SUBDIR;

// --- Previous run removed ----------------------------------------------------------------

foreach ( get_posts( [ 'post_type' => 'any', 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_lumia_e2e_seed', 'fields' => 'ids' ] ) as $old ) { // phpcs:ignore
	wp_delete_post( (int) $old, true );
}
foreach ( get_posts( [ 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_lumia_e2e_seed', 'fields' => 'ids' ] ) as $old ) { // phpcs:ignore
	wp_delete_attachment( (int) $old, true );
}
seed_rmdir( $dir );
seed_rmdir( $base . '/bricks' );
foreach ( (array) glob( $base . '/lumia-originals-*', GLOB_ONLYDIR ) as $old_dir ) {
	seed_rmdir( (string) $old_dir );
}
delete_option( 'lumia_e2e_legacy_hero' );
delete_option( 'lumia_e2e_legacy_double' );
// Names deleted by earlier runs would push the seeded names to -1 (registry, spec 9.4).
delete_option( 'lumia_module_image_optimizer_tombstones' );
delete_option( 'lumia_module_image_optimizer_migration_pairs' );

function seed_rmdir( string $path ): void {
	if ( ! is_dir( $path ) ) {
		return;
	}
	$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $items as $item ) {
		$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
	}
	rmdir( $path );
}

// --- Helpers -------------------------------------------------------------------------------

add_filter(
	'upload_dir',
	static function ( array $u ): array {
		$u['subdir'] = '/' . SEED_SUBDIR;
		$u['path']   = $u['basedir'] . '/' . SEED_SUBDIR;
		$u['url']    = $u['baseurl'] . '/' . SEED_SUBDIR;
		return $u;
	}
);

function seed_import( string $fixture, string $name ): int {
	$tmp = wp_tempnam( $name );
	copy( SEED_FIXTURES . '/' . $fixture, $tmp );
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
	update_post_meta( (int) $id, '_lumia_e2e_seed', 1 );
	return (int) $id;
}

/**
 * The former convert(): AVIF (or WebP) next to the source, extension replaced, source deleted.
 * The quality setting was ignored by Imagick for AVIF (q50 in fact): not set here either.
 */
function seed_convert( string $source, string $format ): string {
	$output = preg_replace( '/\.[^.]+$/', '.' . $format, $source );
	if ( ! file_exists( $output ) ) {
		$im = new Imagick( $source );
		$im->stripImage();
		$im->setImageFormat( $format );
		$im->setOption( 'heic:speed', '9' ); // Bench speed only: the layout matters, not the bytes.
		$im->writeImage( $output );
		$im->clear();
		chmod( $output, 0644 );
	}
	if ( file_exists( $source ) ) {
		unlink( $source );
	}
	return $output;
}

/**
 * A media item converted by the former pipeline.
 */
function seed_legacy( int $id, string $format = 'avif', bool $keep_original_image = true ): void {
	global $wpdb;

	$meta  = wp_get_attachment_metadata( $id );
	$file  = get_attached_file( $id );
	$dir   = dirname( $file );
	$total = 0;
	$after = 0;

	foreach ( $meta['sizes'] as $size => $data ) {
		$src    = $dir . '/' . $data['file'];
		$total += file_exists( $src ) ? filesize( $src ) : 0;
		$new    = seed_convert( $src, $format );
		$after += filesize( $new );

		$meta['sizes'][ $size ]['file']      = basename( $new );
		$meta['sizes'][ $size ]['mime-type'] = 'image/' . $format;
		$meta['sizes'][ $size ]['filesize']  = filesize( $new );
	}

	$main_before = filesize( $file );
	$new_main    = seed_convert( $file, $format );
	$main_after  = filesize( $new_main );

	$meta['file']     = SEED_SUBDIR . '/' . basename( $new_main );
	$meta['filesize'] = $main_after;

	if ( ! $keep_original_image && ! empty( $meta['original_image'] ) ) {
		unlink( $dir . '/' . $meta['original_image'] ); // The key stays: the former pipeline never touched it.
	}

	update_post_meta( $id, '_wp_attachment_metadata', $meta );
	update_post_meta( $id, '_wp_attached_file', SEED_SUBDIR . '/' . basename( $new_main ) );

	$guid = (string) get_post_field( 'guid', $id, 'raw' );
	$wpdb->update(
		$wpdb->posts,
		[
			'post_mime_type' => 'image/' . $format,
			'guid'           => str_replace( basename( $file ), basename( $new_main ), $guid ),
		],
		[ 'ID' => $id ]
	);
	clean_post_cache( $id );

	$total += $main_before;
	$after += $main_after;
	update_post_meta( $id, '_lumia_optimized', time() );
	update_post_meta( $id, '_lumia_original_bytes', $total );
	update_post_meta( $id, '_lumia_optimized_bytes', $after );
	update_post_meta( $id, '_lumia_bytes_saved', max( $total - $after, 0 ) );
	update_post_meta( $id, '_lumia_main_original_bytes', $main_before );
	update_post_meta( $id, '_lumia_main_optimized_bytes', $main_after );
	update_post_meta( $id, '_lumia_main_bytes_saved', max( $main_before - $main_after, 0 ) );
	update_post_meta( $id, '_lumia_optimized_format', $format );
	update_post_meta( $id, '_lumia_optimized_mime', 'image/' . $format );
}

function seed_url( int $id, string $size = 'full' ): string {
	$src = wp_get_attachment_image_src( $id, $size );
	return $src ? (string) $src[0] : '';
}

// --- Media ---------------------------------------------------------------------------------

WP_CLI::log( 'Seeding legacy media in uploads/' . SEED_SUBDIR . ' (AVIF encodes take a while)...' );

$ids = [];

$ids['big-photo'] = seed_import( 'photo-4000.jpg', 'big-photo.jpg' );
seed_legacy( $ids['big-photo'] );

$ids['big-lost'] = seed_import( 'photo-4000.jpg', 'big-lost.jpg' );
seed_legacy( $ids['big-lost'], 'avif', false );

$ids['photo'] = seed_import( 'photo-p3.jpg', 'photo.jpg' );
seed_legacy( $ids['photo'] );
// A backslash in image_meta (a Windows path in a copyright, an escaped quote): the journal
// and the metadata must keep it (update_post_meta() unslashes).
$photo_meta                             = wp_get_attachment_metadata( $ids['photo'] );
$photo_meta['image_meta']['copyright'] = 'C:\\Studio\\photo "x"';
update_post_meta( $ids['photo'], '_wp_attachment_metadata', wp_slash( $photo_meta ) );

$ids['alpha'] = seed_import( 'visual-alpha.png', 'alpha.png' );
seed_legacy( $ids['alpha'] );

$ids['logo'] = seed_import( 'logo-flat.png', 'logo.png' );
seed_legacy( $ids['logo'] );

$ids['webp-photo'] = seed_import( 'photo-bigicc.jpg', 'webp-photo.jpg' );
seed_legacy( $ids['webp-photo'], 'webp' );

$ids['collide'] = seed_import( 'photo-p3.jpg', 'collide.jpg' );
seed_legacy( $ids['collide'] );
// A later upload took the freed name (not a legacy item).
$ids['collide-new'] = seed_import( 'photo-gps.jpg', 'collide.jpg' );
if ( 'collide.jpg' !== basename( get_attached_file( $ids['collide-new'] ) ) ) {
	WP_CLI::error( 'seed: the second collide.jpg did not get its name: ' . get_attached_file( $ids['collide-new'] ) );
}

// The former backup copy (keep_original): uploads/lumia-originals-<token>/<rel>.
$ids['backup'] = seed_import( 'photo-gps.jpg', 'backup.jpg' );
$token         = get_option( 'lumia_module_image_optimizer_backup_token', '' );
if ( '' === $token ) {
	$token = 'e2etoken' . strtolower( wp_generate_password( 16, false ) );
	update_option( 'lumia_module_image_optimizer_backup_token', $token, false );
}
$backup_rel = SEED_SUBDIR . '/backup.jpg';
wp_mkdir_p( $base . '/lumia-originals-' . $token . '/' . SEED_SUBDIR );
copy( get_attached_file( $ids['backup'] ), $base . '/lumia-originals-' . $token . '/' . $backup_rel );
seed_legacy( $ids['backup'] );
update_post_meta( $ids['backup'], '_lumia_backup_file', $backup_rel );

// Review scenarios (assert-migration.sh): seq-a / seq-b (a failed database step on a resumed
// item must not touch the previous item), late / late-backup (a name taken between a killed
// run and its resume).
foreach ( [ 'seq-a', 'seq-b', 'late' ] as $key ) {
	$ids[ $key ] = seed_import( 'photo-gps.jpg', $key . '.jpg' );
	seed_legacy( $ids[ $key ] );
}
$ids['late-backup'] = seed_import( 'photo-gps.jpg', 'late-backup.jpg' );
$late_backup_rel    = SEED_SUBDIR . '/late-backup.jpg';
copy( get_attached_file( $ids['late-backup'] ), $base . '/lumia-originals-' . $token . '/' . $late_backup_rel );
seed_legacy( $ids['late-backup'] );
update_post_meta( $ids['late-backup'], '_lumia_backup_file', $late_backup_rel );

// --- URLs in the database and in Bricks' CSS ------------------------------------------------

$urls = [];
foreach ( $ids as $key => $id ) {
	$urls[ $key ] = seed_url( $id );
}
$photo_medium = seed_url( $ids['photo'], 'medium' );
$photo_large  = seed_url( $ids['photo'], 'large' );
$big_medium   = seed_url( $ids['big-photo'], 'medium' );

$page_id = wp_insert_post(
	[
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Legacy gallery',
		'post_name'    => 'legacy-gallery',
		'post_content' => '',
	]
);
update_post_meta( $page_id, '_lumia_e2e_seed', 1 );

$css_file = $base . '/bricks/css/post-' . $page_id . '.min.css';
$css_url  = $baseurl . '/bricks/css/post-' . $page_id . '.min.css';

$content  = '<link rel="stylesheet" href="' . esc_url( $css_url ) . '">' . "\n";
$content .= '<!-- wp:image {"id":' . $ids['photo'] . ',"sizeSlug":"large"} -->' . "\n";
$content .= '<figure class="wp-block-image size-large"><img src="' . $photo_large . '" alt="" class="wp-image-' . $ids['photo'] . '" srcset="' . $photo_medium . ' 300w, ' . $photo_large . ' 1024w, ' . $urls['photo'] . ' 1600w" sizes="(max-width: 1024px) 100vw, 1024px"/></figure>' . "\n";
$content .= '<!-- /wp:image -->' . "\n";
$content .= '<!-- wp:html -->' . "\n";
foreach ( [ 'big-photo', 'big-lost', 'alpha', 'logo', 'webp-photo', 'collide', 'backup' ] as $key ) {
	$content .= '<img src="' . $urls[ $key ] . '" alt="' . $key . '">' . "\n";
}
$content .= '<img src="' . $big_medium . '" alt="big-photo medium">' . "\n";
$content .= '<div class="legacy-hero" style="background-image:url(' . $urls['alpha'] . ');min-height:40px"></div>' . "\n";
$content .= '<div class="brxe-hero">Bricks hero</div>' . "\n";
$content .= '<!-- /wp:html -->';

$wpdb->update( $wpdb->posts, [ 'post_content' => $content ], [ 'ID' => $page_id ] );
clean_post_cache( $page_id );

// Bricks: serialized element array with image URLs.
update_post_meta(
	$page_id,
	'_bricks_page_content_2',
	[
		[
			'id'       => 'abc123',
			'name'     => 'image',
			'settings' => [
				'image' => [
					'id'   => $ids['logo'],
					'url'  => $urls['logo'],
					'size' => 'full',
				],
			],
		],
		[
			'id'       => 'def456',
			'name'     => 'section',
			'settings' => [
				'_background' => [
					'image' => [
						'id'  => $ids['big-lost'],
						'url' => $urls['big-lost'],
					],
				],
			],
		],
	]
);
// Rank Math: plain URL.
update_post_meta( $page_id, 'rank_math_facebook_image', $urls['photo'] );
update_post_meta( $page_id, 'rank_math_facebook_image_id', $ids['photo'] );
// JSON with escaped slashes (block attributes, page builders).
update_post_meta( $page_id, '_e2e_json', wp_slash( wp_json_encode( [ 'src' => $urls['webp-photo'] ] ) ) ); // Slashed: update_post_meta() unslashes.
// An option.
update_option( 'lumia_e2e_legacy_hero', [ 'image' => $urls['collide'] ], false );
// Doubly serialized: a plugin that serializes its value itself before WordPress does.
delete_option( 'lumia_e2e_legacy_double' );
update_option( 'lumia_e2e_legacy_double', serialize( [ 'image' => $urls['logo'], 'label' => "\u{2192} x" ] ), false ); // phpcs:ignore
update_post_meta( $page_id, '_e2e_double', wp_slash( serialize( [ 'bg' => [ 'url' => $urls['alpha'] ] ] ) ) ); // phpcs:ignore

// Bricks' external CSS file.
wp_mkdir_p( dirname( $css_file ) );
file_put_contents(
	$css_file,
	'.brxe-hero{background-image:url(' . $urls['photo'] . ');min-height:40px}.brxe-x{background:url("' . $urls['big-lost'] . '") no-repeat}'
);
chmod( $css_file, 0644 );

// --- Manifest --------------------------------------------------------------------------------

$manifest = [
	'ids'      => $ids,
	'page'     => $page_id,
	'css'      => 'bricks/css/post-' . $page_id . '.min.css',
	'urls'     => $urls,
	'extra'    => [ $photo_medium, $photo_large, $big_medium ],
	'backup'   => 'lumia-originals-' . $token . '/' . $backup_rel,
	'backup_late' => 'lumia-originals-' . $token . '/' . $late_backup_rel,
	'legacy'   => [],
	'baseurl'  => $baseurl,
	'seeded'   => time(),
];
foreach ( $ids as $key => $id ) {
	if ( 'collide-new' === $key ) {
		continue;
	}
	$meta  = wp_get_attachment_metadata( $id );
	$files = [ $meta['file'] ];
	foreach ( $meta['sizes'] as $data ) {
		$files[] = SEED_SUBDIR . '/' . $data['file'];
	}
	$manifest['legacy'][ $key ] = array_values( array_unique( $files ) );
}
file_put_contents( '/bench/out/mig-manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

foreach ( $ids as $key => $id ) {
	WP_CLI::log( sprintf( '  %-12s #%-4d %s', $key, $id, get_post_meta( $id, '_wp_attached_file', true ) ) );
}
WP_CLI::success( 'Legacy media seeded, page #' . $page_id . ' (/legacy-gallery/).' );
