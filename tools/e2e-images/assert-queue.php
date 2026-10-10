<?php
/**
 * Bench check of the background AVIF queue (QueueRunner; spec 3, 9.1, 9.5, 9.6), run with
 * `run.sh assert <nginx|apache> tools/e2e-images/assert-queue.php` (WP-CLI eval-file, as admin).
 * Bench only, never shipped.
 *
 * Nothing is encoded in the CLI (spec 9.1): this script only prepares states and triggers the
 * drain through the HTTP loopback, then watches the database while the web runtime (PHP-FPM
 * on nginx, mod_php on apache) does the work. Needs the plugin installed with the Image
 * Optimizer module active and the delivery self-test passing (mode nginx / htaccess).
 */

use Lumia\Tools\Core\Plugin;
use Lumia\Tools\Modules\ImageOptimizer\AvifState;
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
global $aq_failures, $aq_created, $wpdb;
$aq_failures = 0;
$aq_created  = [];

const AQ_FIXTURES = '/bench/out/fixtures';

function aq_check( bool $ok, string $label ): void {
	global $aq_failures;
	WP_CLI::log( ( $ok ? '  ok   ' : '  FAIL ' ) . $label );
	if ( ! $ok ) {
		++$aq_failures;
	}
}

function aq_import( string $fixture, string $name ): int {
	global $aq_created;
	$tmp = wp_tempnam( $name );
	copy( AQ_FIXTURES . '/' . $fixture, $tmp );
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
	$aq_created[] = (int) $id;
	return (int) $id;
}

/**
 * State read from the database (the web runtime writes it: this process's meta cache is stale).
 */
function aq_state( int $id ): array {
	wp_cache_delete( $id, 'post_meta' );
	return AvifState::get( $id );
}

/**
 * Waits until none of the media items is pending or processing (true), or the timeout (false).
 *
 * @param int[] $ids
 */
function aq_wait_idle( array $ids, int $timeout ): bool {
	$end = microtime( true ) + $timeout;
	do {
		$busy = false;
		foreach ( $ids as $id ) {
			if ( in_array( aq_state( $id )['status'], [ AvifState::PENDING, AvifState::PROCESSING ], true ) ) {
				$busy = true;
				break;
			}
		}
		if ( ! $busy ) {
			return true;
		}
		usleep( 250000 );
	} while ( microtime( true ) < $end );

	return false;
}

/**
 * The siblings present for the media item's source files.
 *
 * @return string[]
 */
function aq_siblings( int $id, FileLifecycle $lc ): array {
	clearstatcache();
	return array_values( array_filter( array_map( [ FileLifecycle::class, 'sibling' ], $lc->source_files( $id ) ), 'file_exists' ) );
}

/**
 * A raw POST to admin-ajax.php (blocking), as the loopback would send it.
 *
 * @return array{code: int, body: string}
 */
function aq_ajax( array $body ): array {
	$response = wp_remote_post(
		admin_url( 'admin-ajax.php' ),
		[
			'timeout' => 120,
			'body'    => $body,
		]
	);
	return [
		'code' => (int) wp_remote_retrieve_response_code( $response ),
		'body' => (string) wp_remote_retrieve_body( $response ),
	];
}

$plugin = Plugin::instance();
$module = $plugin->modules->get_active_instances()['image_optimizer'] ?? null;

if ( ! $module ) {
	WP_CLI::error( 'The Image Optimizer module is not active (run.sh install-lumia, then activate it).' );
}
if ( ! class_exists( QueueRunner::class ) || ! method_exists( $module, 'get_queue' ) ) {
	WP_CLI::error( 'QueueRunner is not part of the installed plugin.' );
}

/** @var QueueRunner $queue */
$queue = $module->get_queue();
/** @var FileLifecycle $lc */
$lc = $module->get_lifecycle();

if ( ! DeliveryProbe::is_serving() ) {
	$module->get_delivery_probe()->run();
}
if ( ! DeliveryProbe::is_serving() ) {
	WP_CLI::error( 'Delivery is not served on this stack (' . wp_json_encode( DeliveryProbe::result() ) . '): the queue would stay idle.' );
}

$run       = substr( md5( uniqid( '', true ) ), 0, 6 );
$cli_pid   = getmypid();
$delivery  = get_option( DeliveryProbe::OPTION );
$settings  = get_option( 'lumia_settings' );
$started   = microtime( true );
$web_sapis = [ 'fpm-fcgi', 'apache2handler', 'litespeed', 'cgi-fcgi' ];

// --- Registration ---------------------------------------------------------------------------

WP_CLI::log( 'Registration' );

$schedules = wp_get_schedules();
aq_check( 300 === (int) ( $schedules['lumia_five_minutes']['interval'] ?? 0 ), 'cron schedule lumia_five_minutes = 300 s' );
aq_check( 'lumia_five_minutes' === wp_get_schedule( QueueRunner::CRON_HOOK ), 'recurring drain event scheduled every five minutes' );
aq_check( in_array( QueueRunner::CRON_HOOK, $module::get_uninstall_keys()['cron'], true ) && in_array( 'lumia_image_optimizer_cron', $module::get_uninstall_keys()['cron'], true ), 'uninstall keys list the drain hook and the former cron hook' );
aq_check( (bool) has_action( 'wp_ajax_nopriv_' . QueueRunner::AJAX_ACTION ) && (bool) has_action( 'wp_ajax_' . QueueRunner::AJAX_ACTION ), 'AJAX drain endpoint registered (nopriv and logged-in)' );
$lock = QueueRunner::lock_name();
aq_check( 1 === preg_match( '/^lumia_avif_[0-9a-f]{32}$/', $lock ), "lock name per site ({$lock})" );

// The recurring drain is pushed one hour away for the run: on a stack with WP-Cron (apache),
// any request of this script could otherwise spawn it and drain while a check expects nothing.
wp_unschedule_hook( QueueRunner::CRON_HOOK );
wp_schedule_event( time() + HOUR_IN_SECONDS, 'lumia_five_minutes', QueueRunner::CRON_HOOK );

// --- Media under test ------------------------------------------------------------------------

WP_CLI::log( 'Fixtures' );

$good    = aq_import( 'photo-p3.jpg', "queue-good-{$run}.jpg" );
$corrupt = aq_import( 'corrupt.jpg', "queue-corrupt-{$run}.jpg" );
$missing = aq_import( 'logo-flat.png', "queue-missing-{$run}.png" );
$retried = aq_import( 'visual-alpha.png', "queue-retried-{$run}.png" );
$ids     = [ $good, $corrupt, $missing ];

aq_check( $good && $corrupt && $missing && $retried, 'four media items imported' );
if ( ! ( $good && $corrupt && $missing && $retried ) ) {
	WP_CLI::error( 'Cannot continue without the fixtures.' );
}

foreach ( [ $good, $corrupt, $missing, $retried ] as $id ) {
	AvifState::enqueue( $id, 'manual' );
}
$missing_file = (string) get_attached_file( $missing );
@unlink( $missing_file );

// An interrupted attempt: `processing` with three attempts already spent.
AvifState::begin_attempt( $retried );
AvifState::begin_attempt( $retried );
AvifState::begin_attempt( $retried );
aq_check( AvifState::PROCESSING === aq_state( $retried )['status'] && 3 === aq_state( $retried )['attempts'], 'interrupted media: processing, attempts = 3' );

// --- CLI: nothing encoded in this process -------------------------------------------------------

WP_CLI::log( 'CLI (spec 9.1)' );

// Lock held by another connection while the CLI drains: the CLI must not even try to encode.
$other = mysqli_init();
$host  = DB_HOST;
$port  = null;
if ( str_contains( $host, ':' ) ) {
	[ $host, $port ] = explode( ':', $host, 2 );
	$port            = (int) $port;
}
$other->real_connect( $host, DB_USER, DB_PASSWORD, DB_NAME, $port );
$got = $other->query( "SELECT GET_LOCK( '" . $other->real_escape_string( $lock ) . "', 0 )" )->fetch_row()[0] ?? null;
aq_check( '1' === (string) $got, 'second connection holds the queue lock' );
aq_check( $queue->is_running(), 'is_running() sees the lock held elsewhere' );

$t0      = microtime( true );
$drained = $queue->drain( 20 );
$elapsed = microtime( true ) - $t0;
aq_check( 0 === $drained, "drain() under WP-CLI processes nothing itself ({$drained})" );
aq_check( $elapsed < 2.0, sprintf( 'drain() under WP-CLI returns at once (%.2f s)', $elapsed ) );

// --- Lock held elsewhere: the web worker gives up --------------------------------------------------

WP_CLI::log( 'Lock' );

$queue->trigger();
sleep( 5 );
aq_check( AvifState::PENDING === aq_state( $good )['status'] && ! aq_siblings( $good, $lc ), 'lock held elsewhere: the triggered worker encodes nothing (still pending, no .avif)' );
aq_check( AvifState::PROCESSING === aq_state( $retried )['status'], 'lock held elsewhere: the interrupted media is left alone' );
$other->query( "SELECT RELEASE_LOCK( '" . $other->real_escape_string( $lock ) . "' )" );
$other->close();
aq_check( ! $queue->is_running(), 'lock released: is_running() false' );

// --- Delivery not served: nothing generated ------------------------------------------------------

WP_CLI::log( 'Delivery gate' );

update_option( DeliveryProbe::OPTION, array_merge( (array) $delivery, [ 'mode' => DeliveryProbe::MODE_NONE ] ), true );
$queue->trigger(); // Refused here (not serving): nothing sent.
$token = hash_hmac( 'sha256', (string) floor( time() / 300 ), wp_salt( 'nonce' ) . 'lumia-avif' );
$res   = aq_ajax(
	[
		'action' => QueueRunner::AJAX_ACTION,
		'token'  => $token,
	]
);
aq_check( 200 === $res['code'], "valid token accepted by the drain endpoint (HTTP {$res['code']})" );
sleep( 2 );
aq_check( AvifState::PENDING === aq_state( $good )['status'] && ! aq_siblings( $good, $lc ), 'delivery off: a direct drain request encodes nothing' );
update_option( DeliveryProbe::OPTION, $delivery, true );

// --- Module off: the worker bails out ------------------------------------------------------------

WP_CLI::log( 'Module gate' );

$off                                  = (array) $settings;
$off['modules']['image_optimizer'] = false;
update_option( 'lumia_settings', $off );
$res = aq_ajax(
	[
		'action' => QueueRunner::AJAX_ACTION,
		'token'  => $token,
	]
);
sleep( 2 );
aq_check( AvifState::PENDING === aq_state( $good )['status'] && ! aq_siblings( $good, $lc ), "module off: a drain request encodes nothing (HTTP {$res['code']})" );
update_option( 'lumia_settings', $settings );

// --- Token ---------------------------------------------------------------------------------------

WP_CLI::log( 'Token' );

$res = aq_ajax(
	[
		'action' => QueueRunner::AJAX_ACTION,
		'token'  => str_repeat( '0', 64 ),
	]
);
aq_check( 403 === $res['code'], "forged token refused (HTTP {$res['code']})" );
$res = aq_ajax( [ 'action' => QueueRunner::AJAX_ACTION ] );
aq_check( 403 === $res['code'], "missing token refused (HTTP {$res['code']})" );
$old = hash_hmac( 'sha256', (string) ( floor( time() / 300 ) - 2 ), wp_salt( 'nonce' ) . 'lumia-avif' );
$res = aq_ajax(
	[
		'action' => QueueRunner::AJAX_ACTION,
		'token'  => $old,
	]
);
aq_check( 403 === $res['code'], "expired token (two windows back) refused (HTTP {$res['code']})" );
sleep( 1 );
aq_check( AvifState::PENDING === aq_state( $good )['status'], 'refused requests encode nothing' );

// --- Drain through the loopback ------------------------------------------------------------------

WP_CLI::log( 'Drain' );

$t0 = time();
$queue->trigger();
$idle = aq_wait_idle( array_merge( $ids, [ $retried ] ), 120 );
aq_check( $idle, 'the loopback worker drained the queue' );

$s = aq_state( $good );
aq_check( in_array( $s['status'], [ AvifState::DONE, AvifState::PARTIAL ], true ), "good media: done / partial ({$s['status']} {$s['error']})" );
aq_check( count( aq_siblings( $good, $lc ) ) === count( array_filter( $s['sizes'], static fn( $e ) => null !== $e['avif_bytes'] ) ) && aq_siblings( $good, $lc ), 'good media: one .avif per recorded AVIF size (' . count( aq_siblings( $good, $lc ) ) . ')' );
$fresh = true;
foreach ( $lc->source_files( $good ) as $path ) {
	$fresh = $fresh && AvifState::is_fresh( $good, $path );
}
aq_check( $fresh, 'good media: every source file recorded fresh (fingerprint + sibling)' );
[ $sapi, $pid ] = array_pad( explode( ':', (string) $s['worker'] ), 2, '' );
aq_check( in_array( $sapi, $web_sapis, true ) && (int) $pid !== $cli_pid, "good media: encoded by the web runtime ({$s['worker']}), not this CLI process ({$cli_pid})" );
aq_check( 1 === $s['attempts'], "good media: one attempt ({$s['attempts']})" );

$s = aq_state( $corrupt );
aq_check( AvifState::FAILED === $s['status'] && '' !== $s['error'], "corrupt.jpg: failed with a message ({$s['error']})" );
// WordPress may have made sub-sizes out of what it could decode: those are valid files and
// keep their (recorded) AVIF; the broken file itself gets none.
clearstatcache();
aq_check( ! file_exists( FileLifecycle::sibling( (string) get_attached_file( $corrupt ) ) ), 'corrupt.jpg: no .avif for the broken file' );
$recorded = array_map( [ AvifState::class, 'abs' ], array_keys( array_filter( $s['sizes'], static fn( $e ) => null !== $e['avif_bytes'] ) ) );
$expected = array_map( [ FileLifecycle::class, 'sibling' ], $recorded );
$present  = aq_siblings( $corrupt, $lc );
sort( $expected );
sort( $present );
aq_check( $expected === $present, 'corrupt.jpg: every .avif left is recorded in the state (' . count( $recorded ) . ')' );

$s = aq_state( $missing );
aq_check( AvifState::FAILED === $s['status'] && str_contains( $s['error'], 'source file missing' ), "deleted file: failed, source file missing ({$s['error']})" );

$s = aq_state( $retried );
aq_check( AvifState::FAILED === $s['status'] && str_contains( $s['error'], 'too many attempts' ), "interrupted media at 3 attempts: failed, too many attempts ({$s['status']} {$s['error']})" );
aq_check( ! aq_siblings( $retried, $lc ), 'interrupted media: no .avif' );

aq_check( ! $queue->is_running(), 'lock released after the drain' );

// --- Generation changed during an encode ---------------------------------------------------------

WP_CLI::log( 'Generation (spec 9.5)' );

$big = aq_import( 'photo-4000.jpg', "queue-big-{$run}.jpg" );
aq_check( $big > 0, 'photo-4000.jpg imported' );
if ( $big ) {
	AvifState::enqueue( $big, 'manual' );
	$queue->trigger();

	// Caught in the middle: processing, some siblings written, not all of them yet.
	$total  = count( $lc->source_files( $big ) );
	$caught = [];
	$end    = microtime( true ) + 30;
	while ( microtime( true ) < $end ) {
		$written = aq_siblings( $big, $lc );
		if ( AvifState::PROCESSING === aq_state( $big )['status'] && $written && count( $written ) < $total ) {
			foreach ( $written as $file ) {
				$caught[ $file ] = fileinode( $file );
			}
			break;
		}
		usleep( 10000 );
	}
	aq_check( (bool) $caught, 'photo-4000.jpg caught mid-encoding (' . count( $caught ) . " of {$total} siblings written)" );

	// Queued again while it encodes (a regeneration, a replacement): the running result is
	// thrown away and the image processed again for the new generation: the siblings written
	// before are written again (new inode: temporary file + rename).
	$gen = AvifState::enqueue( $big, 'manual' );
	aq_check( aq_wait_idle( [ $big ], 120 ), 'photo-4000.jpg drained' );
	$s = aq_state( $big );
	aq_check( in_array( $s['status'], [ AvifState::DONE, AvifState::PARTIAL ], true ), "photo-4000.jpg: done / partial ({$s['status']} {$s['error']})" );
	aq_check( $gen === $s['gen'], "photo-4000.jpg: state of generation {$gen} ({$s['gen']})" );
	clearstatcache();
	$rewritten = 0;
	foreach ( $caught as $file => $inode ) {
		if ( file_exists( $file ) && fileinode( $file ) !== $inode ) {
			++$rewritten;
		}
	}
	aq_check( $caught && count( $caught ) === $rewritten, "photo-4000.jpg: the siblings of the dropped pass were encoded again ({$rewritten} of " . count( $caught ) . ')' );
	$fresh = (bool) $lc->source_files( $big );
	foreach ( $lc->source_files( $big ) as $path ) {
		$fresh = $fresh && AvifState::is_fresh( $big, $path );
	}
	aq_check( $fresh, 'photo-4000.jpg: every source file fresh' );
}

// --- Delivery back: every eligible media item queued again -----------------------------------------

WP_CLI::log( 'Delivery back (enqueued with ID 0)' );

// What purge_all() leaves: no state, no sibling. An excluded item and a GIF stay out.
$lc->delete_siblings( $good );
AvifState::clear( $good );
$gif      = aq_import( 'anim.gif', "queue-anim-{$run}.gif" );
$excluded = aq_import( 'logo-flat.png', "queue-excluded-{$run}.png" );
AvifState::clear( $excluded );
AvifState::set_status( $excluded, AvifState::EXCLUDED );

$count = $queue->enqueue_all_eligible( 'reconcile' );
aq_check( $count >= 2, "enqueue_all_eligible() queued the items without state and the failed ones ({$count})" );
$s = aq_state( $good );
aq_check( AvifState::PENDING === $s['status'] && 'reconcile' === $s['origin'], "media without state: pending, origin reconcile ({$s['status']} / {$s['origin']})" );
aq_check( AvifState::PENDING === aq_state( $corrupt )['status'], 'failed media: queued again' );
aq_check( AvifState::EXCLUDED === aq_state( $excluded )['status'], 'excluded media: left alone' );
aq_check( '' === aq_state( $gif )['status'] || AvifState::SKIPPED === aq_state( $gif )['status'], 'GIF: not queued (' . aq_state( $gif )['status'] . ')' );

// The probe's switch from none to served fires the action with ID 0.
AvifState::clear( $good );
$lc->delete_siblings( $good );
do_action( 'lumia_image_optimizer_enqueued', 0 );
aq_check( AvifState::PENDING === aq_state( $good )['status'], 'action lumia_image_optimizer_enqueued(0) queues the library again' );
$queue->trigger();
aq_check( aq_wait_idle( [ $good ], 600 ), 'the queued library drained' );
aq_check( in_array( aq_state( $good )['status'], [ AvifState::DONE, AvifState::PARTIAL ], true ) && aq_siblings( $good, $lc ), 'media without state: AVIF generated again' );

// --- Cleanup -----------------------------------------------------------------------------------------

foreach ( $aq_created as $id ) {
	wp_delete_attachment( $id, true );
}
update_option( DeliveryProbe::OPTION, $delivery, true );
update_option( 'lumia_settings', $settings );
wp_unschedule_hook( QueueRunner::CRON_HOOK );
wp_schedule_event( time() + 300, 'lumia_five_minutes', QueueRunner::CRON_HOOK );

WP_CLI::log( sprintf( '  info total %.1f s', microtime( true ) - $started ) );

if ( $aq_failures > 0 ) {
	WP_CLI::error( "{$aq_failures} queue check(s) failed." );
}
WP_CLI::success( 'Queue checks passed.' );
