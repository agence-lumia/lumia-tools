<?php
/**
 * Bench check of the queue-based bulk (BulkProcessor; spec 3 "Bulk", 9.4, 9.5), run with
 * `run.sh assert nginx tools/e2e-images/assert-bulk.php` (WP-CLI eval-file, as admin).
 * Bench only, never shipped.
 *
 * Nothing is encoded in the CLI (spec 9.1): the script prepares media, calls the AJAX
 * endpoints over HTTP as a real administrator (login cookies), and watches the database while
 * the web runtime drains the queue. Needs the plugin installed with the Image Optimizer module
 * active and the delivery self-test passing (stack `nginx`).
 */

use Lumia\Tools\Core\Plugin;
use Lumia\Tools\Modules\ImageOptimizer\AvifState;
use Lumia\Tools\Modules\ImageOptimizer\BulkProcessor;
use Lumia\Tools\Modules\ImageOptimizer\DeliveryProbe;
use Lumia\Tools\Modules\ImageOptimizer\FileLifecycle;
use Lumia\Tools\Modules\ImageOptimizer\QueueRunner;

if ( ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

// wp eval-file includes this file from inside a function: globals must be declared.
global $ab_failures, $ab_created, $wpdb;
$ab_failures = 0;
$ab_created  = [];

const AB_FIXTURES  = '/bench/out/fixtures';
const AB_STATE_OPT = 'lumia_module_image_optimizer_bulk_state';
const AB_ENDPOINTS = [ 'bulk_scan', 'bulk', 'bulk_stop', 'bulk_status' ];

function ab_check( bool $ok, string $label ): void {
	global $ab_failures;
	WP_CLI::log( ( $ok ? '  ok   ' : '  FAIL ' ) . $label );
	if ( ! $ok ) {
		++$ab_failures;
	}
}

function ab_import( string $fixture, string $name ): int {
	global $ab_created;
	$tmp = wp_tempnam( $name );
	copy( AB_FIXTURES . '/' . $fixture, $tmp );
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
	$ab_created[] = (int) $id;
	return (int) $id;
}

/** State read from the database (the web runtime writes it: this process's meta cache is stale). */
function ab_state( int $id ): array {
	wp_cache_delete( $id, 'post_meta' );
	return AvifState::get( $id );
}

/** A media item that has never been queued: no state at all, no sibling. */
function ab_reset( int $id, FileLifecycle $lc ): void {
	$lc->delete_siblings( $id );
	AvifState::clear( $id );
}

/** An option as the database has it (this process's cache is stale: the web runtime writes it). */
function ab_option( string $name ) {
	wp_cache_delete( $name, 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	wp_cache_delete( 'alloptions', 'options' );
	return get_option( $name, false );
}

/** The persistent notices of a user, read from the database. */
function ab_notices( int $user_id ): array {
	wp_cache_delete( $user_id, 'user_meta' );
	return (array) get_user_meta( $user_id, 'lumia_notices', true );
}

/** @return array<string, int> */
function ab_counts(): array {
	return AvifState::count_by_status();
}

function ab_busy(): int {
	$counts = ab_counts();
	return $counts[ AvifState::PENDING ] + $counts[ AvifState::PROCESSING ];
}

/** Waits until the whole queue is empty and no worker holds the lock. */
function ab_wait_idle( QueueRunner $queue, int $timeout ): bool {
	$end = microtime( true ) + $timeout;
	do {
		if ( 0 === ab_busy() && ! $queue->is_running() ) {
			return true;
		}
		usleep( 300000 );
	} while ( microtime( true ) < $end );

	return false;
}

/** JPEG / PNG / HEIC media without any state, counted by an independent query. */
function ab_untouched_expected(): int {
	global $wpdb;
	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s
			WHERE p.post_type = 'attachment' AND p.post_mime_type IN ( 'image/jpeg', 'image/pjpeg', 'image/png', 'image/heic', 'image/heif' ) AND s.meta_value IS NULL",
			AvifState::STATUS
		)
	);
}

/** @return mysqli A second connection holding the queue lock (no worker can start). */
function ab_hold_lock( string $lock ): mysqli {
	$other = mysqli_init();
	$host  = DB_HOST;
	$port  = null;
	if ( str_contains( $host, ':' ) ) {
		[ $host, $port ] = explode( ':', $host, 2 );
		$port            = (int) $port;
	}
	$other->real_connect( $host, DB_USER, DB_PASSWORD, DB_NAME, $port );
	$other->query( "SELECT GET_LOCK( '" . $other->real_escape_string( $lock ) . "', 0 )" );
	return $other;
}

function ab_release_lock( mysqli $other, string $lock ): void {
	$other->query( "SELECT RELEASE_LOCK( '" . $other->real_escape_string( $lock ) . "' )" );
	$other->close();
}

/**
 * Logs a user in over HTTP and reads the admin nonce.
 *
 * @return array{cookies: array<string, string>, nonce: string}|null
 */
function ab_login( string $user, string $pass ): ?array {
	$res = wp_remote_post(
		wp_login_url(),
		[
			'timeout'     => 60,
			'redirection' => 0,
			'cookies'     => [ 'wordpress_test_cookie' => 'WP Cookie check' ],
			'body'        => [
				'log'        => $user,
				'pwd'        => $pass,
				'testcookie' => '1',
			],
		]
	);
	if ( is_wp_error( $res ) ) {
		return null;
	}
	$jar = [];
	foreach ( wp_remote_retrieve_cookies( $res ) as $cookie ) {
		$jar[ $cookie->name ] = $cookie->value;
	}
	$ctx  = [
		'cookies' => $jar,
		'nonce'   => '',
	];
	$page = ab_get( $ctx, admin_url( 'upload.php?mode=list' ) );
	if ( ! preg_match( '/lumiaAdmin = \{"ajaxUrl":"[^"]*","nonce":"([0-9a-f]+)"/', $page, $m ) ) {
		return null;
	}
	$ctx['nonce'] = $m[1];

	return $ctx;
}

function ab_get( array $ctx, string $url ): string {
	$res = wp_remote_get(
		$url,
		[
			'timeout' => 60,
			'cookies' => $ctx['cookies'],
		]
	);
	return is_wp_error( $res ) ? '' : (string) wp_remote_retrieve_body( $res );
}

/**
 * admin-ajax call as a logged-in user.
 *
 * @param array<string, mixed> $fields
 * @return array{code: int, json: array<string, mixed>|null}
 */
function ab_ajax( array $ctx, string $action, array $fields = [], bool $with_nonce = true ): array {
	$body = array_merge( [ 'action' => 'lumia_image_optimizer_' . $action ], $with_nonce ? [ 'nonce' => $ctx['nonce'] ] : [], $fields );
	$res  = wp_remote_post(
		admin_url( 'admin-ajax.php' ),
		[
			'timeout' => 180,
			'cookies' => $ctx['cookies'],
			'body'    => $body,
		]
	);
	if ( is_wp_error( $res ) ) {
		return [
			'code' => 0,
			'json' => null,
		];
	}
	$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	return [
		'code' => (int) wp_remote_retrieve_response_code( $res ),
		'json' => is_array( $json ) ? $json : null,
	];
}

/** The `data` of a successful answer ([] otherwise). */
function ab_data( array $answer ): array {
	return ( is_array( $answer['json'] ) && ! empty( $answer['json']['success'] ) && is_array( $answer['json']['data'] ) ) ? $answer['json']['data'] : [];
}

$plugin = Plugin::instance();
$module = $plugin->modules->get_active_instances()['image_optimizer'] ?? null;

if ( ! $module ) {
	WP_CLI::error( 'The Image Optimizer module is not active (run.sh install-lumia, then activate it).' );
}
if ( ! class_exists( BulkProcessor::class ) || ! method_exists( $module, 'get_queue' ) ) {
	WP_CLI::error( 'The queue is not part of the installed plugin.' );
}

/** @var QueueRunner $queue */
$queue = $module->get_queue();
/** @var FileLifecycle $lc */
$lc = $module->get_lifecycle();

if ( ! DeliveryProbe::is_serving() ) {
	$module->get_delivery_probe()->run();
}
if ( ! DeliveryProbe::is_serving() ) {
	WP_CLI::error( 'Delivery is not served on this stack (' . wp_json_encode( DeliveryProbe::result() ) . '): the bulk would stay idle.' );
}

$run      = substr( md5( uniqid( '', true ) ), 0, 6 );
$admin_id = (int) get_user_by( 'login', 'admin' )->ID;
$delivery = get_option( DeliveryProbe::OPTION );
$lock     = QueueRunner::lock_name();
$started  = microtime( true );

// The recurring drain is pushed one hour away for the run: nothing may drain behind a check's back.
wp_unschedule_hook( QueueRunner::CRON_HOOK );
wp_schedule_event( time() + HOUR_IN_SECONDS, 'lumia_five_minutes', QueueRunner::CRON_HOOK );
delete_option( AB_STATE_OPT );
delete_transient( BulkProcessor::KICK_TRANSIENT );

// --- Registration ------------------------------------------------------------------------------

WP_CLI::log( 'Registration' );

foreach ( AB_ENDPOINTS as $endpoint ) {
	ab_check( (bool) has_action( 'wp_ajax_lumia_image_optimizer_' . $endpoint ), "AJAX endpoint {$endpoint} registered" );
}
ab_check( ! has_action( 'lumia_image_optimizer_cron' ), 'former fixed-batch cron handler removed' );
ab_check( ! ( new ReflectionClass( $module ) )->hasConstant( 'BATCH_SIZE' ), 'former BATCH_SIZE removed' );
ab_check( ! has_action( 'wp_ajax_nopriv_lumia_image_optimizer_bulk' ), 'bulk endpoints are not available logged out' );

// --- Media under test --------------------------------------------------------------------------

WP_CLI::log( 'Fixtures' );

$g1      = ab_import( 'photo-p3.jpg', "bulk-good1-{$run}.jpg" );
$g2      = ab_import( 'logo-flat.png', "bulk-good2-{$run}.png" );
$g3      = ab_import( 'visual-alpha.png', "bulk-good3-{$run}.png" );
$gif     = ab_import( 'anim.gif', "bulk-anim-{$run}.gif" );
$corrupt = ab_import( 'corrupt.jpg', "bulk-corrupt-{$run}.jpg" );
$gone    = ab_import( 'photo-bigicc.jpg', "bulk-gone-{$run}.jpg" );
$stale   = ab_import( 'photo-p3.jpg', "bulk-stale-{$run}.jpg" );
$all     = [ $g1, $g2, $g3, $gif, $corrupt, $gone, $stale ];

ab_check( ! in_array( 0, $all, true ), 'seven media items imported' );
if ( in_array( 0, $all, true ) ) {
	WP_CLI::error( 'Cannot continue without the fixtures.' );
}

// The upload queued them (nothing drains before this script ends): the library is made to look
// like one that never had the module.
foreach ( [ $g1, $g2, $g3, $gif, $corrupt, $gone ] as $id ) {
	ab_reset( $id, $lc );
}
@unlink( (string) get_attached_file( $gone ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- bench.

// A finished media item whose source changed behind WordPress (FTP): the scan must notice it.
$entries = [];
foreach ( $lc->source_files( $stale ) as $path ) {
	$print = AvifState::fingerprint( $path );
	file_put_contents( FileLifecycle::sibling( $path ), 'fake-avif' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- bench.
	$entries[ $path ] = [
		'bytes'      => $print['bytes'],
		'mtime'      => $print['mtime'],
		'avif_bytes' => 9,
	];
}
AvifState::record_sizes( $stale, $entries );
AvifState::set_status( $stale, AvifState::DONE );
file_put_contents( (string) get_attached_file( $stale ), "\0", FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- bench.
clearstatcache();

foreach ( [ $g1, $g2, $g3, $gif, $corrupt, $gone ] as $id ) {
	if ( '' !== ab_state( $id )['status'] ) {
		WP_CLI::warning( "media {$id} still has a state: " . ab_state( $id )['status'] );
	}
}

$admin  = ab_login( 'admin', 'admin' );
$editor = null;
if ( ! $admin ) {
	WP_CLI::error( 'Cannot log in as admin.' );
}

// --- Access control --------------------------------------------------------------------------------

WP_CLI::log( 'Access' );

$no_nonce = ab_ajax( $admin, 'bulk_scan', [], false );
ab_check( 403 === $no_nonce['code'], "scan without a nonce is refused ({$no_nonce['code']})" );

$editor_id = wp_insert_user(
	[
		'user_login' => "bulk-editor-{$run}",
		'user_pass'  => 'editor-pass-' . $run,
		'user_email' => "bulk-editor-{$run}@example.test",
		'role'       => 'editor',
	]
);
$editor    = is_wp_error( $editor_id ) ? null : ab_login( "bulk-editor-{$run}", 'editor-pass-' . $run );
ab_check( null !== $editor, 'an editor can log in (upload_files: has a nonce, not manage_options)' );
if ( $editor ) {
	foreach ( AB_ENDPOINTS as $endpoint ) {
		$answer = ab_ajax( $editor, $endpoint );
		ab_check( is_array( $answer['json'] ) && false === ( $answer['json']['success'] ?? null ), "{$endpoint}: refused to a user without the module capability" );
	}
	ab_check( 0 === ab_busy() && '' === ab_state( $g1 )['status'], 'nothing was queued by the refused calls' );
}

// --- Scan --------------------------------------------------------------------------------------------

WP_CLI::log( 'Scan' );

$expected_untouched = ab_untouched_expected();
$scan               = ab_data( ab_ajax( $admin, 'bulk_scan' ) );
ab_check( ! empty( $scan ), 'scan answers' );
ab_check( ab_counts() === ( $scan['counts'] ?? null ), 'scan: counts per status are the database counts' );
ab_check( ( $scan['untouched'] ?? -1 ) === $expected_untouched && $expected_untouched >= 5, "scan: JPEG/PNG media without state ({$expected_untouched}), the GIF not among them" );
ab_check( ( $scan['requeued'] ?? 0 ) >= 1, 'scan: the fingerprints are reconciled first (the changed source is queued again)' );
$s = ab_state( $stale );
ab_check( AvifState::PENDING === $s['status'] && 'reconcile' === $s['origin'] || in_array( $s['status'], [ AvifState::PROCESSING, AvifState::DONE, AvifState::PARTIAL ], true ), "changed source: queued again by the scan ({$s['status']} / {$s['origin']})" );
ab_check( ! empty( $scan ) && array_key_exists( 'serving', $scan ) && true === $scan['serving'], 'scan: reports that the AVIF is served' );
ab_check( '' === ab_state( $g1 )['status'], 'scan: queues nothing by itself' );
ab_wait_idle( $queue, 120 );

// --- Delivery not served ----------------------------------------------------------------------------

WP_CLI::log( 'Delivery gate' );

update_option( DeliveryProbe::OPTION, array_merge( (array) $delivery, [ 'mode' => DeliveryProbe::MODE_NONE ] ), true );
$page = ab_get( $admin, admin_url( 'admin.php?page=lumia-tools&tab=module_image_optimizer' ) );
ab_check( 1 === preg_match( '/<button[^>]*id="lumia-bulk-start"[^>]*disabled/', $page ), 'Bulk tab: Start is disabled when the AVIF is not served' );
ab_check( 1 === preg_match( '/<p[^>]*data-lumia-bulk-unserved(?![^>]*\bhidden\b)[^>]*>/', $page ), 'Bulk tab: says that nothing would be generated' );
$refused = ab_ajax( $admin, 'bulk' );
ab_check( is_array( $refused['json'] ) && false === ( $refused['json']['success'] ?? null ), 'start refused while the AVIF is not served' );
ab_check( '' === ab_state( $g1 )['status'] && false === ab_option( AB_STATE_OPT ), 'start refused: nothing queued, no bulk state' );
$status = ab_data( ab_ajax( $admin, 'bulk_status' ) );
ab_check( false === ( $status['serving'] ?? null ), 'status: serving is false' );
update_option( DeliveryProbe::OPTION, $delivery, true );

$page = ab_get( $admin, admin_url( 'admin.php?page=lumia-tools&tab=module_image_optimizer' ) );
ab_check( 1 === preg_match( '/<button[^>]*id="lumia-bulk-start"/', $page ) && 0 === preg_match( '/<button[^>]*id="lumia-bulk-start"[^>]*disabled/', $page ), 'Bulk tab: Start is enabled when the AVIF is served' );
ab_check( 1 === preg_match( '/<p[^>]*data-lumia-bulk-unserved[^>]*\bhidden\b[^>]*>/', $page ), 'Bulk tab: the warning is hidden when the AVIF is served' );
foreach ( [ 'pending', 'processing', 'done', 'partial', 'skipped', 'failed', 'excluded' ] as $status_name ) {
	ab_check( str_contains( $page, 'data-lumia-bulk-status="' . $status_name . '"' ), "Bulk tab: tile for {$status_name}" );
}
ab_check( str_contains( $page, 'id="lumia-bulk-stop"' ) && str_contains( $page, 'id="lumia-bulk-scan"' ) && str_contains( $page, 'lumia-progress__bar' ), 'Bulk tab: Scan and Stop buttons, progress bar' );

// --- Start, drain to exhaustion -----------------------------------------------------------------------

WP_CLI::log( 'Start' );

$start = ab_data( ab_ajax( $admin, 'bulk' ) );
ab_check( ( $start['queued'] ?? 0 ) >= 5, 'start: the five eligible media items queued (' . ( $start['queued'] ?? 'no answer' ) . ')' );
ab_check( '' === ab_state( $gif )['status'], 'start: the GIF is never queued' );
$bulk_state = ab_option( AB_STATE_OPT );
ab_check( is_array( $bulk_state ) && 2 === count( $bulk_state ) && isset( $bulk_state['user_id'], $bulk_state['started_at'] ), 'bulk state reduced to user_id and started_at' );
ab_check( is_array( $bulk_state ) && $admin_id === (int) ( $bulk_state['user_id'] ?? 0 ), 'bulk state: the user who started it' );
ab_check( 'bulk' === ab_state( $corrupt )['origin'] || AvifState::FAILED === ab_state( $corrupt )['status'], 'start: origin bulk' );

ab_check( ab_wait_idle( $queue, 300 ), 'the queue drained to exhaustion' );
$counts = ab_counts();
ab_check( 0 === $counts[ AvifState::PENDING ] && 0 === $counts[ AvifState::PROCESSING ], 'no pending, no processing left' );

$web_sapis = [ 'fpm-fcgi', 'apache2handler', 'litespeed', 'cgi-fcgi' ];
foreach ( [ $g1 => 'good1', $g2 => 'good2', $g3 => 'good3' ] as $id => $label ) {
	$st = ab_state( $id );
	ab_check( in_array( $st['status'], [ AvifState::DONE, AvifState::PARTIAL, AvifState::SKIPPED ], true ) && 'bulk' === $st['origin'], "{$label}: processed ({$st['status']}, origin {$st['origin']})" );
}
$st = ab_state( $g1 );
ab_check( AvifState::DONE === $st['status'] && count( array_filter( array_map( [ FileLifecycle::class, 'sibling' ], $lc->source_files( $g1 ) ), 'file_exists' ) ) > 0, 'good1: done, siblings on disk' );
ab_check( in_array( explode( ':', $st['worker'] )[0], $web_sapis, true ), "good1: encoded by the web runtime ({$st['worker']})" );
$st = ab_state( $corrupt );
ab_check( AvifState::FAILED === $st['status'] && '' !== $st['error'], 'corrupt: failed with a message (' . $st['error'] . ')' );
$st = ab_state( $gone );
ab_check( AvifState::FAILED === $st['status'] && str_contains( $st['error'], 'missing' ), 'deleted source: failed, "file missing" (' . $st['error'] . ')' );
ab_check( '' === ab_state( $gif )['status'], 'GIF: still never queued' );
$st = ab_state( $stale );
ab_check( in_array( $st['status'], [ AvifState::DONE, AvifState::PARTIAL ], true ), 'changed source: encoded again' );

sleep( 1 ); // The completion hook runs right after the worker released the lock.
$notices = ab_notices( $admin_id );
ab_check( false === ab_option( AB_STATE_OPT ), 'completion: bulk state removed once pending + processing = 0' );
ab_check( isset( $notices['image_optimizer_bulk_done'] ), 'completion: persistent notice for the user who started the bulk' );

$status = ab_data( ab_ajax( $admin, 'bulk_status' ) );
ab_check( ( $status['counts'][ AvifState::FAILED ] ?? 0 ) >= 2 && false === ( $status['active'] ?? null ) && false === ( $status['running'] ?? null ), 'status when idle: counts, not active, not running' );
ab_check( ( $status['untouched'] ?? -1 ) === ab_untouched_expected(), 'status when idle: the library without state' );
$handled = $status['handled'] ?? -1;
ab_check( $handled === $status['total'], 'status: every media item handled, progress complete (' . $handled . ' / ' . ( $status['total'] ?? '?' ) . ')' );

// --- Start again: failed media resume, attempts reset -------------------------------------------------

WP_CLI::log( 'Resume failed media' );

delete_user_meta( $admin_id, 'lumia_notices' );
$gen_g1 = ab_state( $g1 )['gen'];
// Three attempts already spent: a media item that kept its counter would end "too many attempts".
AvifState::begin_attempt( $corrupt );
AvifState::begin_attempt( $corrupt );
AvifState::set_status( $corrupt, AvifState::FAILED, 'old failure' );
ab_check( 3 === ab_state( $corrupt )['attempts'], 'corrupt: attempts = 3 before the relaunch' );

$start = ab_data( ab_ajax( $admin, 'bulk' ) );
ab_check( ( $start['queued'] ?? 0 ) >= 2, 'relaunch: the failed media queued again (' . ( $start['queued'] ?? 'no answer' ) . ')' );
ab_check( ab_wait_idle( $queue, 300 ), 'relaunch drained' );
$st = ab_state( $corrupt );
ab_check( AvifState::FAILED === $st['status'] && 1 === $st['attempts'] && 'too many attempts' !== $st['error'] && 'old failure' !== $st['error'], "corrupt: attempts reset by the manual relaunch, fails again for its own reason ({$st['attempts']}, {$st['error']})" );
$st = ab_state( $gone );
ab_check( AvifState::FAILED === $st['status'] && 1 === $st['attempts'], 'deleted source: fails again, one attempt' );
ab_check( $gen_g1 === ab_state( $g1 )['gen'] && AvifState::DONE === ab_state( $g1 )['status'], 'finished media are not queued again' );
sleep( 1 );
$notices = ab_notices( $admin_id );
ab_check( isset( $notices['image_optimizer_bulk_done'] ), 'relaunch: the completion notice again' );

// --- Stop ------------------------------------------------------------------------------------------------------

WP_CLI::log( 'Stop' );

$s1 = ab_import( 'photo-p3.jpg', "bulk-stop1-{$run}.jpg" );
$s2 = ab_import( 'logo-flat.png', "bulk-stop2-{$run}.png" );
$s3 = ab_import( 'visual-alpha.png', "bulk-stop3-{$run}.png" );
foreach ( [ $s1, $s2, $s3 ] as $id ) {
	ab_reset( $id, $lc );
}
// `gone` is `failed`: the bulk queues it again, and the Stop gives it back as "no state".
$holder = ab_hold_lock( $lock );
ab_check( $queue->is_running(), 'a second connection holds the queue lock: no worker can start' );

$start = ab_data( ab_ajax( $admin, 'bulk' ) );
ab_check( ( $start['queued'] ?? 0 ) >= 4, 'start with a busy worker: media queued (' . ( $start['queued'] ?? 'no answer' ) . ')' );
// Upload-origin media waiting, and one in flight, added after the bulk was queued.
AvifState::enqueue( $stale, 'upload' );
AvifState::begin_attempt( $g3 );
sleep( 2 );
ab_check( AvifState::PENDING === ab_state( $s1 )['status'] && 'bulk' === ab_state( $s1 )['origin'], 'bulk items wait while the lock is held' );
$bulk_pending = ab_counts()[ AvifState::PENDING ];

$status = ab_data( ab_ajax( $admin, 'bulk_status' ) );
ab_check( true === ( $status['active'] ?? null ) && true === ( $status['running'] ?? null ) && ( $status['counts'][ AvifState::PENDING ] ?? 0 ) === $bulk_pending, 'status while a worker holds the lock: active, running, live counts' );
ab_check( ( $status['handled'] ?? 99999 ) < ( $status['total'] ?? 0 ), 'status: progress below 100 %' );

$stop = ab_data( ab_ajax( $admin, 'bulk_stop' ) );
ab_check( ( $stop['removed'] ?? 0 ) >= 4, 'stop: bulk media given back (' . ( $stop['removed'] ?? 'no answer' ) . ')' );
foreach ( [ $s1, $s2, $s3, $gone ] as $id ) {
	ab_check( '' === ab_state( $id )['status'], "stop: media {$id} back to no state" );
}
ab_check( AvifState::PENDING === ab_state( $stale )['status'] && 'upload' === ab_state( $stale )['origin'], 'stop: a pending item of another origin stays queued' );
ab_check( AvifState::PROCESSING === ab_state( $g3 )['status'], 'stop: an item being processed is left alone' );
$left = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} s JOIN {$wpdb->postmeta} o ON o.post_id = s.post_id AND o.meta_key = %s AND o.meta_value = 'bulk' WHERE s.meta_key = %s AND s.meta_value = %s", AvifState::ORIGIN, AvifState::STATUS, AvifState::PENDING ) );
ab_check( 0 === (int) $left, 'stop: no pending item of origin bulk left' );
ab_check( false === ab_option( AB_STATE_OPT ), 'stop: bulk state removed (no completion notice for a stop)' );
$stop_status = ab_data( ab_ajax( $admin, 'bulk_status' ) );
ab_check( true === ( $stop_status['active'] ?? null ) && array_key_exists( 'untouched', $stop_status ) && null === $stop_status['untouched'], 'status while active: the library without state is only computed when idle' );

ab_release_lock( $holder, $lock );
$queue->trigger(); // The interrupted item and the upload one drain.

// --- Restart by the status endpoint ----------------------------------------------------------------------------

WP_CLI::log( 'Restart from the screen' );

ab_check( ab_wait_idle( $queue, 120 ), 'the upload and the interrupted items drained' );
$r1 = ab_import( 'photo-p3.jpg', "bulk-kick-{$run}.jpg" );
ab_reset( $r1, $lc );
AvifState::enqueue( $r1, 'bulk' ); // Queued without any trigger: nobody will drain it by itself.
set_transient( BulkProcessor::KICK_TRANSIENT, 1, 60 );
ab_ajax( $admin, 'bulk_status' );
sleep( 4 );
ab_check( AvifState::PENDING === ab_state( $r1 )['status'], 'a worker was seen less than 60 s ago: the status endpoint does not restart the queue' );
delete_transient( BulkProcessor::KICK_TRANSIENT );
$answer = ab_ajax( $admin, 'bulk_status' );
ab_check( ! empty( ab_data( $answer ) ), 'status answers' );
ab_check( ab_wait_idle( $queue, 120 ) && in_array( ab_state( $r1 )['status'], [ AvifState::DONE, AvifState::PARTIAL ], true ), 'no worker for more than 60 s and pending items: the status endpoint restarts the queue' );
ab_check( null !== $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", '_transient_' . BulkProcessor::KICK_TRANSIENT ) ), 'the restart is not repeated on every poll (guard of 60 s)' );
delete_transient( BulkProcessor::KICK_TRANSIENT );

// --- Cleanup -------------------------------------------------------------------------------------------------------

foreach ( $ab_created as $id ) {
	wp_delete_attachment( $id, true );
}
if ( ! is_wp_error( $editor_id ) ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( (int) $editor_id );
}
update_option( DeliveryProbe::OPTION, $delivery, true );
delete_option( AB_STATE_OPT );
delete_user_meta( $admin_id, 'lumia_notices' );
wp_unschedule_hook( QueueRunner::CRON_HOOK );
wp_schedule_event( time() + 300, 'lumia_five_minutes', QueueRunner::CRON_HOOK );

WP_CLI::log( sprintf( '  info total %.1f s', microtime( true ) - $started ) );

if ( $ab_failures > 0 ) {
	WP_CLI::error( "{$ab_failures} bulk check(s) failed." );
}
WP_CLI::success( 'Bulk checks passed.' );
