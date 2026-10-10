<?php
/**
 * Bench check of the media library integration (spec 4, 9.5, 9.12, 9.13): the AVIF column,
 * the details panel for each status, the "serve the original format" toggle, the regenerate
 * action, the original URL. Run with
 * `run.sh assert <stack> tools/e2e-images/assert-media.php` (WP-CLI eval-file, as admin).
 * Bench only, never shipped.
 *
 * Needs the plugin installed (`run.sh install-lumia <stack>`), the Image Optimizer module
 * active and its delivery proven (use the `cdn-vary` stack, or `nginx`): the AJAX actions and
 * the media screens are requested over HTTP, as a real administrator (login cookies), so that
 * the web runtime handles them. No AVIF is encoded by the plugin here (the queue is not part
 * of this check): siblings are fake files, except the one of the `?original` check, which is a
 * real AVIF written with this runtime's Imagick.
 */

use Lumia\Tools\Core\Plugin;
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
global $ml_failures, $ml_created, $ml_enqueued, $ml_http;
$ml_failures = 0;
$ml_created  = [];
$ml_enqueued = [];
$ml_http     = [
	'cookies' => [],
	'nonce'   => '',
];

const ML_FIXTURES = '/bench/out/fixtures';
const ML_MINUS    = "\u{2212}";

function ml_check( bool $ok, string $label ): void {
	global $ml_failures;
	WP_CLI::log( ( $ok ? '  ok   ' : '  FAIL ' ) . $label );
	if ( ! $ok ) {
		++$ml_failures;
	}
}

function ml_has( string $haystack, string $needle, string $label ): void {
	ml_check( str_contains( $haystack, $needle ), $label );
}

function ml_lacks( string $haystack, string $needle, string $label ): void {
	ml_check( ! str_contains( $haystack, $needle ), $label );
}

function ml_import( string $fixture, string $name ): int {
	global $ml_created;
	$tmp = wp_tempnam( $name );
	copy( ML_FIXTURES . '/' . $fixture, $tmp );
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
	$ml_created[] = (int) $id;
	return (int) $id;
}

/** Panel HTML as the attachment form renders it ('' when there is none). */
function ml_panel( int $id ): string {
	$fields = apply_filters( 'attachment_fields_to_edit', [], get_post( $id ) );
	return (string) ( $fields['lumia_image_optimizer']['html'] ?? '' );
}

/** Column cell HTML. */
function ml_column( int $id ): string {
	ob_start();
	do_action( 'manage_media_custom_column', 'lumia_avif', $id );
	return trim( (string) ob_get_clean() );
}

function ml_fake_sibling( string $path, string $content = 'fake-avif' ): void {
	file_put_contents( FileLifecycle::sibling( $path ), $content );
}

/**
 * Sets a state by hand: `$sizes` is a list of [bytes, avif_bytes|null] in source_files() order,
 * the AVIF siblings of the entries with an `avif_bytes` are written (fake).
 *
 * @param array<int, array{0:int, 1:int|null}> $sizes
 */
function ml_state( int $id, FileLifecycle $lc, string $status, array $sizes = [], string $error = '' ): void {
	$entries = [];
	foreach ( array_values( $lc->source_files( $id ) ) as $i => $path ) {
		if ( ! isset( $sizes[ $i ] ) ) {
			continue;
		}
		[ $bytes, $avif ] = $sizes[ $i ];
		if ( null !== $avif ) {
			ml_fake_sibling( $path );
		} else {
			@unlink( FileLifecycle::sibling( $path ) );
		}
		$entries[ $path ] = [
			'bytes'      => $bytes,
			'mtime'      => (int) filemtime( $path ),
			'avif_bytes' => $avif,
		];
	}
	if ( $entries ) {
		AvifState::record_sizes( $id, $entries );
	}
	AvifState::set_status( $id, $status, $error );
}

/** Logs in over HTTP (real cookies: stable session, nonces stay valid) and reads the admin nonce. */
function ml_login(): bool {
	global $ml_http;

	$cookies = [ 'wordpress_test_cookie' => 'WP Cookie check' ];
	$res     = wp_remote_post(
		wp_login_url(),
		[
			'timeout'     => 60,
			'redirection' => 0,
			'cookies'     => $cookies,
			'body'        => [
				'log'        => 'admin',
				'pwd'        => 'admin',
				'testcookie' => '1',
			],
		]
	);
	if ( is_wp_error( $res ) ) {
		return false;
	}
	$jar = [];
	foreach ( wp_remote_retrieve_cookies( $res ) as $cookie ) {
		$jar[ $cookie->name ] = $cookie->value;
	}
	$ml_http['cookies'] = $jar;

	$page = ml_get( admin_url( 'upload.php?mode=list' ) );
	if ( ! preg_match( '/lumiaAdmin = \{"ajaxUrl":"[^"]*","nonce":"([0-9a-f]+)"/', $page, $m ) ) {
		return false;
	}
	$ml_http['nonce'] = $m[1];

	return true;
}

function ml_get( string $url, array $headers = [] ): string {
	global $ml_http;
	$res = wp_remote_get(
		$url,
		[
			'timeout' => 60,
			'cookies' => $ml_http['cookies'],
			'headers' => $headers,
		]
	);
	return is_wp_error( $res ) ? '' : (string) wp_remote_retrieve_body( $res );
}

/**
 * admin-ajax call as the administrator.
 *
 * @param array<string, mixed> $fields
 * @return array<string, mixed>|null Decoded JSON (null: not JSON).
 */
function ml_ajax( string $action, array $fields = [], bool $with_nonce = true ): ?array {
	global $ml_http;
	$body = array_merge( [ 'action' => $action ], $with_nonce ? [ 'nonce' => $ml_http['nonce'] ] : [], $fields );
	$res  = wp_remote_post(
		admin_url( 'admin-ajax.php' ),
		[
			'timeout' => 60,
			'cookies' => $ml_http['cookies'],
			'body'    => $body,
		]
	);
	if ( is_wp_error( $res ) ) {
		return null;
	}
	$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	return is_array( $json ) ? $json : null;
}

function ml_siblings( int $id, FileLifecycle $lc ): array {
	return array_values( array_filter( array_map( [ FileLifecycle::class, 'sibling' ], $lc->source_files( $id ) ), 'file_exists' ) );
}

function ml_status( int $id ): string {
	// The web runtime wrote it: read the database, not this process's cache.
	wp_cache_delete( $id, 'post_meta' );
	return AvifState::get( $id )['status'];
}

$run    = substr( md5( uniqid( '', true ) ), 0, 6 );
$plugin = Plugin::instance();
$module = $plugin->modules->get_active_instances()['image_optimizer'] ?? null;

if ( ! $module ) {
	WP_CLI::error( 'The Image Optimizer module is not active (run.sh install-lumia, then activate it).' );
}
if ( ! method_exists( $module, 'get_lifecycle' ) || ! class_exists( AvifState::class ) ) {
	WP_CLI::error( 'AvifState / FileLifecycle are not part of the installed plugin.' );
}
if ( ! DeliveryProbe::is_serving() ) {
	WP_CLI::error( 'The delivery self-test does not say "served" (use the cdn-vary or nginx stack, and activate the module through the admin).' );
}
if ( ! ml_login() ) {
	WP_CLI::error( 'Could not log in over HTTP or read the admin nonce.' );
}

/** @var FileLifecycle $lc */
$lc = $module->get_lifecycle();

add_action(
	'lumia_image_optimizer_enqueued',
	static function ( int $id ): void {
		global $ml_enqueued;
		$ml_enqueued[ $id ] = ( $ml_enqueued[ $id ] ?? 0 ) + 1;
	}
);

$jpg = ml_import( 'photo-p3.jpg', "ml-{$run}.jpg" );
$png = ml_import( 'logo-flat.png', "ml-{$run}-logo.png" );
$gif = ml_import( 'anim.gif', "ml-{$run}.gif" );
if ( ! $jpg || ! $png || ! $gif ) {
	WP_CLI::error( 'Fixtures could not be imported.' );
}
$sources = array_values( $lc->source_files( $jpg ) );
WP_CLI::log( 'Media ' . $jpg . ' has ' . count( $sources ) . ' source files' );

$forbidden = [ 'Convert', 'Optimize this image', 'Re-optimize', 'Restore original', 'Regenerate thumbnails' ];

// --- Old actions are gone --------------------------------------------------------------------

WP_CLI::log( 'Old AJAX actions' );

foreach ( [ 'optimize', 'reoptimize', 'convert', 'restore' ] as $old ) {
	ml_check( ! has_action( 'wp_ajax_lumia_image_optimizer_media_' . $old ), "wp_ajax_lumia_image_optimizer_media_{$old} removed" );
}
ml_check( (bool) has_action( 'wp_ajax_lumia_image_optimizer_media_regenerate' ), 'regenerate registered' );
ml_check( (bool) has_action( 'wp_ajax_lumia_image_optimizer_media_toggle_original' ), 'toggle_original registered' );
ml_check( ! isset( apply_filters( 'manage_media_columns', [ 'title' => 'T' ] )['lumia_format'] ), 'the Format column is gone' );
ml_check( isset( apply_filters( 'manage_media_columns', [ 'title' => 'T' ] )['lumia_avif'] ), 'the AVIF column exists' );

// --- Column ------------------------------------------------------------------------------------

WP_CLI::log( 'Column' );

AvifState::clear( $jpg );
ml_has( ml_column( $jpg ), 'Not generated', 'JPEG with no state: "Not generated"' );

AvifState::set_status( $jpg, AvifState::PENDING );
ml_has( ml_column( $jpg ), 'Pending', 'pending' );
AvifState::set_status( $jpg, AvifState::PROCESSING );
ml_has( ml_column( $jpg ), 'Processing', 'processing' );
AvifState::set_status( $jpg, AvifState::FAILED, 'Encoder said no <b>' );
$cell = ml_column( $jpg );
ml_has( $cell, 'Failed', 'failed' );
ml_has( $cell, 'Encoder said no &lt;b&gt;', 'failed: error in a tooltip, escaped' );
AvifState::set_status( $jpg, AvifState::EXCLUDED );
ml_has( ml_column( $jpg ), 'Original format', 'excluded: "Original format"' );
AvifState::set_status( $jpg, AvifState::SKIPPED, 'Nothing to gain' );
$cell = ml_column( $jpg );
ml_has( $cell, 'Not applicable', 'skipped: "Not applicable"' );
ml_has( $cell, 'Nothing to gain', 'skipped: reason in a tooltip' );

// 1000 + 400 of source for 370 + 150 of AVIF = 1 - 520 / 1400 = 62.86 % -> 63 %.
ml_state( $jpg, $lc, AvifState::DONE, [ [ 1000, 370 ], [ 400, 150 ] ] );
$extra = count( $sources ) > 2 ? 'sizes beyond the first two have no entry (ignored)' : '';
ml_has( ml_column( $jpg ), 'AVIF ' . ML_MINUS . '63%', 'done: weight saved on the served sizes (AVIF ' . ML_MINUS . '63%) ' . $extra );

// A size with no sibling (kept as JPEG) counts for nothing: 1000 / 400 -> (370 + 0) vs 1000 only.
ml_state( $jpg, $lc, AvifState::PARTIAL, [ [ 1000, 370 ], [ 400, null ] ] );
ml_has( ml_column( $jpg ), 'AVIF ' . ML_MINUS . '63%', 'partial: sizes with no AVIF left out of the ratio (1 - 370 / 1000)' );

AvifState::clear( $png );
ml_state( $png, $lc, AvifState::DONE, [ [ 2000, 500 ] ] );
ml_has( ml_column( $png ), 'AVIF ' . ML_MINUS . '75%', 'PNG done: 1 - 500 / 2000 = 75 %' );

ml_has( ml_column( $gif ), 'Not applicable', 'GIF: "Not applicable"' );
$html = ml_column( 0 );
ml_check( str_contains( $html, '—' ) || '' === $html, 'unknown ID: dash or nothing' );

// --- Panel per status -------------------------------------------------------------------------

WP_CLI::log( 'Panel' );

$cases = [
	// status, sizes, error, [must contain], [must not contain]
	'none'       => [ '', [], '', [ 'AVIF not generated yet' ], [] ],
	'pending'    => [ AvifState::PENDING, [], '', [ 'Waiting' ], [ 'data-lumia-io-action="regenerate"' ] ],
	'processing' => [ AvifState::PROCESSING, [], '', [ 'being generated' ], [ 'data-lumia-io-action="regenerate"' ] ],
	'done'       => [ AvifState::DONE, [ [ 1000, 370 ], [ 400, 150 ] ], '', [ 'Main file', 'Total (all sizes)', 'Saved', '63%', 'data-lumia-io-action="regenerate"' ], [] ],
	'partial'    => [ AvifState::PARTIAL, [ [ 1000, 370 ], [ 400, null ] ], '', [ 'Main file', 'Total (all sizes)', 'files only', 'data-lumia-io-action="regenerate"' ], [] ],
	'skipped'    => [ AvifState::SKIPPED, [], 'Incompatible with some email clients', [ 'Incompatible with some email clients' ], [] ],
	'failed'     => [ AvifState::FAILED, [], 'Imagick exploded <script>', [ 'Generation failed', 'Imagick exploded &lt;script&gt;', 'data-lumia-io-action="regenerate"' ], [ '<script>' ] ],
	'excluded'   => [ AvifState::EXCLUDED, [], '', [ 'is served in its original format' ], [ 'data-lumia-io-action="regenerate"' ] ],
];

foreach ( $cases as $label => [ $status, $sizes, $error, $must, $must_not ] ) {
	AvifState::clear( $jpg );
	foreach ( $sources as $path ) {
		@unlink( FileLifecycle::sibling( $path ) );
	}
	if ( '' !== $status ) {
		ml_state( $jpg, $lc, $status, $sizes, $error );
	}
	$panel = ml_panel( $jpg );
	ml_check( '' !== $panel, "{$label}: panel rendered" );
	foreach ( $must as $needle ) {
		ml_has( $panel, $needle, "{$label}: contains \"{$needle}\"" );
	}
	foreach ( $must_not as $needle ) {
		ml_lacks( $panel, $needle, "{$label}: no \"{$needle}\"" );
	}
	foreach ( $forbidden as $needle ) {
		ml_lacks( $panel, $needle, "{$label}: no \"{$needle}\"" );
	}
	ml_has( $panel, 'Serve the original format', "{$label}: toggle label" );
	ml_has( $panel, 'up to one year', "{$label}: cache warning next to the toggle (9.12)" );
	ml_has( $panel, 'data-lumia-io-toggle', "{$label}: toggle control" );
	ml_has( $panel, 'Copy original URL', "{$label}: copy button" );
	ml_has( $panel, 'Download original', "{$label}: download link" );
	ml_has( $panel, 'data-attachment="' . $jpg . '"', "{$label}: carries the attachment ID" );
	$checked = (bool) preg_match( '/data-lumia-io-toggle[^>]*\bchecked\b/', $panel );
	ml_check( AvifState::EXCLUDED === $status ? $checked : ! $checked, "{$label}: toggle " . ( AvifState::EXCLUDED === $status ? 'on' : 'off' ) );
}

ml_state( $jpg, $lc, AvifState::DONE, [ [ 1000, 370 ], [ 400, 150 ] ] );
$panel = ml_panel( $jpg );
$url   = wp_get_attachment_url( $jpg ) . '?original';
ml_has( $panel, 'data-url="' . esc_attr( $url ) . '"', 'copy button carries the original URL (url?original)' );
ml_has( $panel, 'href="' . esc_url( $url ) . '"', 'download link targets the same URL' );
ml_check( (bool) preg_match( '/<a [^>]*\bdownload\b[^>]*href="[^"]*\?original"|<a [^>]*href="[^"]*\?original"[^>]*\bdownload\b/', $panel ), 'download link has the download attribute' );
ml_lacks( $panel, 'not served on this server', 'delivery served: no reminder' );

// Delivery mode none: the reminder.
$delivery_before = get_option( DeliveryProbe::OPTION, null );
$delivery        = is_array( $delivery_before ) ? $delivery_before : [];
update_option( DeliveryProbe::OPTION, array_merge( $delivery, [ 'mode' => 'none' ] ) );
ml_has( ml_panel( $jpg ), 'not served on this server', 'delivery mode none: reminder that AVIF is not served' );
if ( null === $delivery_before ) {
	delete_option( DeliveryProbe::OPTION );
} else {
	update_option( DeliveryProbe::OPTION, $delivery_before );
}

// Excluded by the file name suffix: the toggle cannot be turned off.
$noopt = ml_import( 'visual-alpha-noopt.png', "ml-{$run}-noopt.png" );
$panel = ml_panel( $noopt );
ml_check( AvifState::EXCLUDED === AvifState::get( $noopt )['status'], 'noopt: excluded by the lifecycle' );
ml_check( (bool) preg_match( '/data-lumia-io-toggle[^>]*\bchecked\b[^>]*\bdisabled\b|data-lumia-io-toggle[^>]*\bdisabled\b[^>]*\bchecked\b/', $panel ), 'name-excluded: toggle on and disabled' );
ml_has( $panel, 'file name', 'name-excluded: explains the suffix rule' );

// Uploaded animated WebP: skipped, the email-client mention shows in the panel and the column.
if ( file_exists( ML_FIXTURES . '/modern-anim.webp' ) ) {
	$anim = ml_import( 'modern-anim.webp', "ml-{$run}-anim.webp" );
	if ( $anim && str_ends_with( (string) get_attached_file( $anim ), '.webp' ) ) {
		ml_has( ml_panel( $anim ), 'Incompatible with some email clients', 'animated WebP: panel mentions email clients' );
		ml_has( ml_column( $anim ), 'Incompatible with some email clients', 'animated WebP: column tooltip mentions email clients' );
		ml_lacks( ml_panel( $anim ), 'Serve the original format', 'animated WebP: no toggle (nothing to exclude)' );
	}
}

ml_check( '' === ml_panel( $gif ), 'GIF: no panel' );

// --- Toggle (AJAX) ------------------------------------------------------------------------------

WP_CLI::log( 'Toggle: serve the original format' );

AvifState::clear( $jpg );
ml_state( $jpg, $lc, AvifState::DONE, [ [ 1000, 370 ], [ 400, 150 ] ] );
ml_check( count( ml_siblings( $jpg, $lc ) ) >= 1, 'before: siblings on disk' );
$gen = AvifState::gen( $jpg );

$res = ml_ajax( 'lumia_image_optimizer_media_toggle_original', [ 'attachment_id' => $jpg ], false );
ml_check( null === $res || empty( $res['success'] ), 'no nonce: refused (-1)' );
ml_check( ml_status( $jpg ) === AvifState::DONE, 'no nonce: state untouched' );
$res = ml_ajax( 'lumia_image_optimizer_media_toggle_original', [ 'attachment_id' => $gif, 'enabled' => '1' ] );
ml_check( is_array( $res ) && empty( $res['success'] ), 'GIF: refused' );
$res = ml_ajax( 'lumia_image_optimizer_media_toggle_original', [ 'attachment_id' => 999999999, 'enabled' => '1' ] );
ml_check( is_array( $res ) && empty( $res['success'] ), 'unknown ID: refused' );

$res = ml_ajax( 'lumia_image_optimizer_media_toggle_original', [ 'attachment_id' => $jpg, 'enabled' => '1' ] );
ml_check( is_array( $res ) && ! empty( $res['success'] ), 'enable: success' );
ml_check( ml_status( $jpg ) === AvifState::EXCLUDED, 'enable: status excluded' );
ml_check( ! ml_siblings( $jpg, $lc ), 'enable: every .avif deleted' );
ml_check( AvifState::gen( $jpg ) > $gen, 'enable: generation bumped (an encode in flight is discarded)' );
$html = (string) ( $res['data']['html'] ?? '' );
ml_has( $html, 'lumia-media-optimizer', 'enable: answer carries the panel' );
ml_check( (bool) preg_match( '/data-lumia-io-toggle[^>]*\bchecked\b/', $html ), 'enable: panel shows the toggle on' );
ml_check( is_string( $res['data']['message'] ?? null ) && '' !== $res['data']['message'], 'enable: message for the toast' );
$again = ml_ajax( 'lumia_image_optimizer_media_toggle_original', [ 'attachment_id' => $jpg, 'enabled' => '1' ] );
ml_check( is_array( $again ) && ! empty( $again['success'] ) && ml_status( $jpg ) === AvifState::EXCLUDED, 'enable twice: idempotent' );

// A later metadata save keeps the exclusion (lifecycle sync).
wp_update_attachment_metadata( $jpg, wp_get_attachment_metadata( $jpg ) );
ml_check( ml_status( $jpg ) === AvifState::EXCLUDED, 'a metadata save keeps the exclusion' );

$enq_before = $ml_enqueued[ $jpg ] ?? 0;
$gen        = AvifState::gen( $jpg );
$res        = ml_ajax( 'lumia_image_optimizer_media_toggle_original', [ 'attachment_id' => $jpg, 'enabled' => '0' ] );
ml_check( is_array( $res ) && ! empty( $res['success'] ), 'disable: success' );
ml_check( ml_status( $jpg ) === AvifState::PENDING, 'disable: status pending' );
$state = AvifState::get( $jpg );
$state = ( static function () use ( $jpg ) {
	wp_cache_delete( $jpg, 'post_meta' );
	return AvifState::get( $jpg );
} )();
ml_check( 'manual' === $state['origin'] && $state['gen'] > $gen, 'disable: queued by hand, new generation' );
ml_check( ! preg_match( '/data-lumia-io-toggle[^>]*\bchecked\b/', (string) ( $res['data']['html'] ?? '' ) ), 'disable: panel shows the toggle off' );

// The name-excluded media cannot be turned back on.
$res = ml_ajax( 'lumia_image_optimizer_media_toggle_original', [ 'attachment_id' => $noopt, 'enabled' => '0' ] );
ml_check( is_array( $res ) && empty( $res['success'] ) && ml_status( $noopt ) === AvifState::EXCLUDED, 'name-excluded: cannot be turned off' );

// Without the toggle value: a flip, as the brief says (excluded <-> pending).
AvifState::clear( $jpg );
ml_state( $jpg, $lc, AvifState::DONE, [ [ 1000, 370 ] ] );
$res = ml_ajax( 'lumia_image_optimizer_media_toggle_original', [ 'attachment_id' => $jpg ] );
ml_check( is_array( $res ) && ! empty( $res['success'] ) && ml_status( $jpg ) === AvifState::EXCLUDED, 'no value: flips to excluded' );
$res = ml_ajax( 'lumia_image_optimizer_media_toggle_original', [ 'attachment_id' => $jpg ] );
ml_check( is_array( $res ) && ! empty( $res['success'] ) && ml_status( $jpg ) === AvifState::PENDING, 'no value: flips back to pending' );

// --- Regenerate (AJAX) --------------------------------------------------------------------------

WP_CLI::log( 'Regenerate' );

AvifState::clear( $jpg );
ml_state( $jpg, $lc, AvifState::DONE, [ [ 1000, 370 ], [ 400, 150 ] ] );
$gen = AvifState::gen( $jpg );
$res = ml_ajax( 'lumia_image_optimizer_media_regenerate', [ 'attachment_id' => $jpg ], false );
ml_check( null === $res || empty( $res['success'] ), 'no nonce: refused (-1)' );
$res = ml_ajax( 'lumia_image_optimizer_media_regenerate', [ 'attachment_id' => $jpg ] );
wp_cache_delete( $jpg, 'post_meta' );
$state = AvifState::get( $jpg );
ml_check( is_array( $res ) && ! empty( $res['success'] ), 'success' );
ml_check( AvifState::PENDING === $state['status'] && 'manual' === $state['origin'] && $state['gen'] > $gen, 'pending, origin manual, new generation' );
ml_check( ! ml_siblings( $jpg, $lc ), 'old .avif deleted (never a stale AVIF while the new one is encoded)' );
ml_check( ! array_filter( $state['sizes'], static fn( $e ) => null !== $e['bytes'] || null !== $e['avif_bytes'] ), 'fingerprints cleared: nothing counts as fresh' );
ml_has( (string) ( $res['data']['html'] ?? '' ), 'Waiting', 'answer carries the pending panel' );

$res = ml_ajax( 'lumia_image_optimizer_media_regenerate', [ 'attachment_id' => $gif ] );
ml_check( is_array( $res ) && empty( $res['success'] ), 'GIF: refused' );

// The excluded media is not regenerated (the toggle comes first).
AvifState::set_status( $jpg, AvifState::EXCLUDED );
$res = ml_ajax( 'lumia_image_optimizer_media_regenerate', [ 'attachment_id' => $jpg ] );
ml_check( is_array( $res ) && empty( $res['success'] ) && ml_status( $jpg ) === AvifState::EXCLUDED, 'excluded: regenerate refused' );

// Order of a regeneration: when the item becomes `pending` (a worker may pick it up at once),
// its siblings are already gone and its fingerprints cleared. Called in this process, through
// the same private method the AJAX action uses, with a watcher on the status write.
AvifState::clear( $jpg );
ml_state( $jpg, $lc, AvifState::DONE, [ [ 1000, 370 ], [ 400, 150 ] ] );
$seen    = null;
$watcher = static function ( $meta_id, $object_id, $meta_key, $value ) use ( $jpg, $lc, &$seen ): void {
	if ( (int) $object_id === $jpg && AvifState::STATUS === $meta_key && AvifState::PENDING === $value ) {
		$state = AvifState::get( $jpg );
		$seen  = [
			'siblings' => count( ml_siblings( $jpg, $lc ) ),
			'fresh'    => count( array_filter( $state['sizes'], static fn( $e ) => null !== $e['bytes'] ) ),
		];
	}
};
add_action( 'updated_post_meta', $watcher, 10, 4 );
add_action( 'added_post_meta', $watcher, 10, 4 );
$media_library = new \Lumia\Tools\Modules\ImageOptimizer\MediaLibrary( $module, new \Lumia\Tools\Modules\ImageOptimizer\ImageProcessor() );
$queue_method  = new ReflectionMethod( $media_library, 'queue' );
$queue_method->invoke( $media_library, $jpg );
remove_action( 'updated_post_meta', $watcher, 10 );
remove_action( 'added_post_meta', $watcher, 10 );
ml_check( is_array( $seen ) && 0 === $seen['siblings'] && 0 === $seen['fresh'], 'regenerate: siblings deleted and fingerprints cleared before the item becomes pending (' . wp_json_encode( $seen ) . ')' );

// --- HEIC upload converted by WordPress (attachment image/heic, served file a JPEG) ------------

WP_CLI::log( 'HEIC attachment' );

$heic = ml_import( 'photo-p3.jpg', "ml-{$run}-heic.jpg" );
$GLOBALS['wpdb']->update( $GLOBALS['wpdb']->posts, [ 'post_mime_type' => 'image/heic' ], [ 'ID' => $heic ] );
clean_post_cache( $heic );
ml_check( 'image/heic' === get_post_mime_type( $heic ), 'attachment typed image/heic, its file a JPEG' );
AvifState::clear( $heic );
ml_has( ml_column( $heic ), 'Not generated', 'HEIC: column treats the JPEG it was converted to ("Not generated")' );
$panel = ml_panel( $heic );
ml_has( $panel, 'Serve the original format', 'HEIC: panel rendered with its toggle' );
ml_has( $panel, 'data-lumia-io-action="regenerate"', 'HEIC: regenerate offered' );
$res = ml_ajax( 'lumia_image_optimizer_media_regenerate', [ 'attachment_id' => $heic ] );
ml_check( is_array( $res ) && ! empty( $res['success'] ), 'HEIC: regenerate accepted (' . ( $res['data']['message'] ?? wp_json_encode( $res ) ) . ')' );
ml_check( in_array( ml_status( $heic ), [ AvifState::PENDING, AvifState::PROCESSING, AvifState::DONE, AvifState::PARTIAL ], true ), 'HEIC: queued like the queue sees it (' . ml_status( $heic ) . ')' );
$res = ml_ajax( 'lumia_image_optimizer_media_toggle_original', [ 'attachment_id' => $heic, 'enabled' => '1' ] );
ml_check( is_array( $res ) && ! empty( $res['success'] ) && AvifState::EXCLUDED === ml_status( $heic ), 'HEIC: original format can be switched on' );

// --- Screens: list column, attachment form ------------------------------------------------------

WP_CLI::log( 'Screens' );

ml_state( $jpg, $lc, AvifState::DONE, [ [ 1000, 370 ], [ 400, 150 ] ] );
$list = ml_get( admin_url( 'upload.php?mode=list' ) );
ml_has( $list, 'column-lumia_avif', 'list mode: AVIF column' );
ml_lacks( $list, 'column-lumia_format', 'list mode: no Format column' );
ml_has( $list, 'AVIF ' . ML_MINUS . '63%', 'list mode: status of the media shown' );
ml_has( $list, 'image-optimizer.js', 'list mode: module script loaded' );

$edit = ml_get( admin_url( 'post.php?post=' . $jpg . '&action=edit' ) );
ml_has( $edit, 'lumia-media-optimizer', 'attachment screen: panel present' );
ml_has( $edit, 'Serve the original format', 'attachment screen: toggle present' );

// --- ?original over HTTP ------------------------------------------------------------------------

WP_CLI::log( '?original' );

$orig = ml_import( 'photo-p3.jpg', "ml-{$run}-orig.jpg" );
$file = (string) get_attached_file( $orig );
$im   = new Imagick( $file );
$im->setImageFormat( 'avif' );
$im->setCompressionQuality( 60 );
$im->writeImage( $file . '.avif' );
$im->clear();
chmod( $file . '.avif', 0644 );
ml_check( is_file( $file . '.avif' ), 'real AVIF sibling written' );

$url   = wp_get_attachment_url( $orig );
$chrome = 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8';
$type  = static function ( string $u, string $accept ): string {
	$res = wp_remote_get( $u, [ 'timeout' => 60, 'headers' => [ 'Accept' => $accept ] ] );
	return is_wp_error( $res ) ? 'error: ' . $res->get_error_message() : strtolower( trim( explode( ';', (string) wp_remote_retrieve_header( $res, 'content-type' ) )[0] ) );
};
ml_check( 'image/avif' === $type( $url, $chrome ), 'Chrome gets the AVIF (sibling in place)' );
ml_check( 'image/jpeg' === $type( $url . '?original', $chrome ), 'Chrome + ?original gets the JPEG (' . $type( $url . '?original', $chrome ) . ')' );
ml_check( 'image/jpeg' === $type( $url . '?original', '*/*' ), '*/* + ?original gets the JPEG' );
ml_check( 'image/jpeg' === $type( $url, '*/*' ), '*/* gets the JPEG' );

// --- Cleanup --------------------------------------------------------------------------------------

foreach ( $ml_created as $id ) {
	wp_delete_attachment( $id, true );
}

if ( $ml_failures > 0 ) {
	WP_CLI::error( "{$ml_failures} media library check(s) failed." );
}
WP_CLI::success( 'Media library checks passed.' );
