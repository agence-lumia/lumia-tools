<?php
namespace Lumia\Tools\Modules\ImageOptimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Bulk generation of the AVIF versions of the media library (spec 3 "Bulk", 9.4, 9.5).
 *
 * The bulk does not process anything itself: it puts the library in the AVIF queue (origin
 * `bulk`) and lets the QueueRunner drain it in the background, one worker per site. What this
 * class owns is the screen's side of it:
 *
 * - Scan: reconciles the fingerprints first (a source changed behind WordPress is queued
 *   again), then counts the media items per status;
 * - Start: queues every JPEG/PNG without state, and every `failed` one again (attempts reset);
 * - Stop: gives back the `pending` items of origin `bulk` (they return to "no state");
 * - Status: live counts, and the queue restarted when items wait and no worker has been seen
 *   for 60 s (spec 3, trigger 4).
 *
 * The progress is the distribution of the statuses: no counter of its own that could drift.
 * The only state kept is who started the run (for the completion notice).
 */
class BulkProcessor {

	/**
	 * Transient: a worker was seen (or the queue was started) less than a minute ago. While it
	 * lives, the status endpoint does not start another one.
	 */
	public const KICK_TRANSIENT = 'lumia_image_optimizer_bulk_kick';

	private const KICK_TTL = 60;

	/** Media items checked per reconcile batch of a scan. */
	private const RECONCILE_BATCH = 200;

	/** Wall time a scan may spend reconciling, in seconds. */
	private const RECONCILE_BUDGET = 20;

	/** Media items given back per query by a stop. */
	private const STOP_BATCH = 500;

	/** WordPress option holding `{ user_id, started_at }` of the run in progress. */
	private string $state_key;

	private QueueRunner $queue;

	private FileLifecycle $lifecycle;

	/**
	 * Called once with the ID of the user who started the run when the queue becomes empty.
	 * Signature: function( int $user_id ): void
	 */
	private \Closure $on_complete_fn;

	public function __construct( string $state_key, QueueRunner $queue, FileLifecycle $lifecycle, \Closure $on_complete_fn ) {
		$this->state_key      = $state_key;
		$this->queue          = $queue;
		$this->lifecycle      = $lifecycle;
		$this->on_complete_fn = $on_complete_fn;
	}

	public function register(): void {
		add_action( 'wp_ajax_lumia_image_optimizer_bulk_scan', [ $this, 'ajax_scan' ] );
		add_action( 'wp_ajax_lumia_image_optimizer_bulk', [ $this, 'ajax_start' ] );
		add_action( 'wp_ajax_lumia_image_optimizer_bulk_stop', [ $this, 'ajax_stop' ] );
		add_action( 'wp_ajax_lumia_image_optimizer_bulk_status', [ $this, 'ajax_status' ] );

		// The queue found itself empty (end of a drain): the run is over.
		add_action( 'lumia_image_optimizer_queue_empty', [ $this, 'maybe_complete' ] );
	}

	/* ================================================================
	 * AJAX HANDLERS
	 * ================================================================ */

	/**
	 * Scan: fingerprints reconciled, then the counts per status and the media items without
	 * state. Queues nothing by itself, except what the reconciliation found out of date.
	 */
	public function ajax_scan(): void {
		$this->authorize();

		$requeued = $this->reconcile_all();

		wp_send_json_success( array_merge( $this->snapshot( true ), [ 'requeued' => $requeued ] ) );
	}

	/**
	 * Start: queues the library (media without state, and the `failed` ones again), then
	 * wakes the queue up.
	 */
	public function ajax_start(): void {
		$this->authorize();

		if ( ! DeliveryProbe::is_serving() ) {
			wp_send_json_error( __( 'The AVIF versions are not served by this server (see the Delivery tab): nothing would be generated.', 'lumia-tools' ) );
		}

		$queued = $this->queue->enqueue_all_eligible( 'bulk' );

		if ( $queued > 0 || $this->is_active() ) {
			update_option(
				$this->state_key,
				[
					'user_id'    => get_current_user_id(),
					'started_at' => time(),
				],
				false
			);
		}

		set_transient( self::KICK_TRANSIENT, 1, self::KICK_TTL );
		$this->queue->trigger();

		wp_send_json_success( array_merge( $this->snapshot( true ), [ 'queued' => $queued ] ) );
	}

	/**
	 * Stop: the `pending` items of origin `bulk` go back to "no state". Items being processed,
	 * and items queued by an upload, are left alone.
	 */
	public function ajax_stop(): void {
		$this->authorize();

		$removed = $this->remove_pending();

		// A stop is not a completion: no notice.
		delete_option( $this->state_key );

		wp_send_json_success( array_merge( $this->snapshot( false ), [ 'removed' => $removed ] ) );
	}

	/**
	 * Status (polled by the screen): live counts. Restarts the queue when items wait and no
	 * worker has been running for more than 60 s.
	 */
	public function ajax_status(): void {
		$this->authorize();

		$this->maybe_complete();

		$snapshot = $this->snapshot( false );
		$this->restart_if_stalled( $snapshot );

		// The library without state is a heavier query: only worth it once the queue is quiet.
		if ( ! $snapshot['active'] ) {
			$snapshot['untouched'] = $this->queue->count_without_state();
		}

		wp_send_json_success( $snapshot );
	}

	/* ================================================================
	 * STATE
	 * ================================================================ */

	/**
	 * What the screen shows.
	 *
	 * - `counts`    media items per status (every status present)
	 * - `total`     media items with a state
	 * - `handled`   those that left the queue: done, partial, skipped, failed, excluded
	 * - `active`    items are waiting or being processed
	 * - `running`   a worker holds the queue lock
	 * - `serving`   the AVIF versions are served (otherwise nothing is generated)
	 * - `untouched` JPEG/PNG media items without state (null when not asked)
	 *
	 * @return array{counts: array<string, int>, total: int, handled: int, active: bool, running: bool, serving: bool, untouched: int|null}
	 */
	public function snapshot( bool $with_untouched = false ): array {
		$counts  = AvifState::count_by_status();
		$waiting = $counts[ AvifState::PENDING ] + $counts[ AvifState::PROCESSING ];
		$total   = array_sum( $counts );

		return [
			'counts'    => $counts,
			'total'     => $total,
			'handled'   => $total - $waiting,
			'active'    => $waiting > 0,
			'running'   => $this->queue->is_running(),
			'serving'   => DeliveryProbe::is_serving(),
			'untouched' => $with_untouched ? $this->queue->count_without_state() : null,
		];
	}

	/**
	 * Ends the run when the queue is empty: the state is removed and the user who started it
	 * is told. Called at the end of a drain and by the status endpoint (whichever comes
	 * first; the state is removed before the notice, so it is sent once).
	 */
	public function maybe_complete(): void {
		// The state may have been written by another request since this process read options.
		wp_cache_delete( $this->state_key, 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		$state = get_option( $this->state_key, false );
		if ( ! is_array( $state ) || $this->is_active() ) {
			return;
		}

		delete_option( $this->state_key );
		( $this->on_complete_fn )( (int) ( $state['user_id'] ?? 0 ) );
	}

	private function is_active(): bool {
		$counts = AvifState::count_by_status();

		return ( $counts[ AvifState::PENDING ] + $counts[ AvifState::PROCESSING ] ) > 0;
	}

	/* ================================================================
	 * OPERATIONS
	 * ================================================================ */

	/**
	 * Checks the fingerprints of every finished media item (the cursor of the background walk
	 * is reset: the scan covers the whole library, within its time budget).
	 *
	 * @return int Media items queued again.
	 */
	private function reconcile_all(): int {
		ignore_user_abort( true );
		delete_option( FileLifecycle::RECONCILE_CURSOR );

		$end      = microtime( true ) + self::RECONCILE_BUDGET;
		$requeued = 0;

		do {
			if ( function_exists( 'set_time_limit' ) ) {
				set_time_limit( 60 );
			}
			$requeued += $this->lifecycle->reconcile( self::RECONCILE_BATCH );
			// The cursor is removed when the walk reaches the end.
		} while ( false !== get_option( FileLifecycle::RECONCILE_CURSOR, false ) && microtime( true ) < $end );

		return $requeued;
	}

	/**
	 * Gives back the `pending` media items of origin `bulk`: status, origin and queue date go;
	 * the generation counter stays, so that it keeps growing. The status is removed only while
	 * it is still `pending` (a worker that picks the item meanwhile keeps it).
	 *
	 * @return int Media items given back.
	 */
	private function remove_pending(): int {
		global $wpdb;

		$removed = 0;
		$last    = 0;

		do {
			$ids = array_map(
				'intval',
				(array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- queue read, must see the other processes' writes.
					$wpdb->prepare(
						"SELECT s.post_id FROM {$wpdb->postmeta} s
						JOIN {$wpdb->postmeta} o ON o.post_id = s.post_id AND o.meta_key = %s AND o.meta_value = %s
						WHERE s.meta_key = %s AND s.meta_value = %s AND s.post_id > %d
						ORDER BY s.post_id ASC LIMIT %d",
						AvifState::ORIGIN,
						'bulk',
						AvifState::STATUS,
						AvifState::PENDING,
						$last,
						self::STOP_BATCH
					)
				)
			);

			$batch = count( $ids );

			foreach ( $ids as $id ) {
				if ( delete_post_meta( $id, AvifState::STATUS, AvifState::PENDING ) ) {
					delete_post_meta( $id, AvifState::ORIGIN );
					delete_post_meta( $id, AvifState::QUEUED_AT );
					++$removed;
				}
				$last = $id;
			}
		} while ( self::STOP_BATCH === $batch );

		return $removed;
	}

	/**
	 * Items wait, nobody works on them: starts the queue, at most once a minute. A worker seen
	 * running refreshes the guard, so a restart only follows a minute without any.
	 *
	 * @param array{active: bool, running: bool, serving: bool} $snapshot
	 */
	private function restart_if_stalled( array $snapshot ): void {
		if ( ! $snapshot['active'] || ! $snapshot['serving'] ) {
			return;
		}

		if ( $snapshot['running'] ) {
			set_transient( self::KICK_TRANSIENT, 1, self::KICK_TTL );
			return;
		}

		if ( false !== get_transient( self::KICK_TRANSIENT ) ) {
			return;
		}

		set_transient( self::KICK_TRANSIENT, 1, self::KICK_TTL );
		$this->queue->trigger();
	}

	/**
	 * Nonce, then the module's capability.
	 */
	private function authorize(): void {
		check_ajax_referer( 'lumia_admin_nonce', 'nonce' );

		if ( ! current_user_can( Module::get_required_capability() ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'lumia-tools' ), 403 );
		}
	}
}
