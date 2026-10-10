<?php
namespace Lumia\Tools\Modules\ImageOptimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Drains the AVIF queue in the background (spec 3, 9.1, 9.5, 9.6).
 *
 * An upload returns at once: the media item is only queued (FileLifecycle). The encoding runs
 * after the response, in the web runtime:
 *
 * - at the end of a request that queued something (`shutdown`, last priority): under PHP-FPM,
 *   `fastcgi_finish_request()` hands the response over, then the same process drains;
 * - anywhere else (Apache mod_php, CGI, WP-CLI, the CLI cron container), a non-blocking HTTP
 *   loopback to admin-ajax.php starts a drain in the web runtime, authenticated by a short-lived
 *   HMAC token (the documented exception to the nonce + capability rule: it only drains);
 * - a recurring cron event (five minutes) catches whatever was left.
 *
 * Nothing is ever encoded in the CLI: the `cli` image's Imagick has no codec at all, and GD
 * would lose the colour profile (spec 9.1). Nothing is generated while the delivery self-test
 * does not pass, nor while the module is off.
 *
 * One worker per site: a MySQL named lock, released by the server if the process dies. Its
 * ownership is checked again before every image (a `$wpdb` reconnection loses it). A pass
 * stops after 20 s of wall time; what is left triggers the next one.
 */
final class QueueRunner {

	public const CRON_HOOK   = 'lumia_image_optimizer_drain';
	public const AJAX_ACTION = 'lumia_image_optimizer_drain';

	/** Interval of the recurring drain (declared through `cron_schedules`). */
	public const SCHEDULE = 'lumia_five_minutes';

	/** Bulk cron hook of the former pipeline (BulkProcessor), cleaned with this one. */
	public const LEGACY_CRON_HOOK = 'lumia_image_optimizer_cron';

	/** A media item whose attempt counter goes above this fails for good. */
	private const MAX_ATTEMPTS = 3;

	/** max_execution_time granted before every image (it counts the CPU of the aom threads). */
	private const IMAGE_TIME_LIMIT = 120;

	/** Lifetime of a loopback token window, in seconds (the previous window is accepted too). */
	private const TOKEN_WINDOW = 300;

	/** Media items read per query by enqueue_all_eligible(). */
	private const ENQUEUE_BATCH = 500;

	/**
	 * Attachment MIME types that may have JPEG/PNG source files (HEIC: WordPress converts it).
	 * Five entries: enqueue_all_eligible() has one placeholder per type.
	 */
	private const ELIGIBLE_MIMES = [ 'image/jpeg', 'image/pjpeg', 'image/png', 'image/heic', 'image/heif' ];

	/** Command-line runtimes: they never encode (spec 9.1), they only trigger the loopback. */
	private const CLI_SAPIS = [ 'cli', 'phpdbg' ];

	private Module $module;

	private FileLifecycle $lifecycle;

	private ImageProcessor $processor;

	private ?AvifEncoder $encoder = null;

	/** Something was queued during this request: drain (or trigger) at shutdown. */
	private bool $requested = false;

	public function __construct( Module $module, FileLifecycle $lifecycle, ImageProcessor $processor ) {
		$this->module    = $module;
		$this->lifecycle = $lifecycle;
		$this->processor = $processor;
	}

	public function register(): void {
		add_filter( 'cron_schedules', [ $this, 'add_schedule' ] ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- five minutes, the spec's recurring drain.
		add_action( self::CRON_HOOK, [ $this, 'run_cron' ] );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, [ $this, 'ajax_drain' ] );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, [ $this, 'ajax_drain' ] );
		add_action( 'lumia_image_optimizer_enqueued', [ $this, 'on_enqueued' ] );
		add_action( 'shutdown', [ $this, 'on_shutdown' ], PHP_INT_MAX );

		$this->schedule();
	}

	/**
	 * Filter cron_schedules: the five-minute interval of the recurring drain.
	 *
	 * @param mixed $schedules
	 * @return mixed
	 */
	public function add_schedule( $schedules ) {
		if ( is_array( $schedules ) ) {
			$schedules[ self::SCHEDULE ] = [
				'interval' => 300,
				'display'  => __( 'Every five minutes', 'lumia-tools' ),
			];
		}

		return $schedules;
	}

	/**
	 * The recurring drain, scheduled when missing (checked on every `init`: one autoloaded
	 * option read).
	 */
	public function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, self::SCHEDULE, self::CRON_HOOK );
		}
	}

	/* ================================================================
	 * TRIGGERS
	 * ================================================================ */

	/**
	 * Action lumia_image_optimizer_enqueued. ID 0 is the delivery self-test switching from
	 * "none" to served: the purge that came with "none" cleared every state, so the whole
	 * library is queued again.
	 *
	 * @param mixed $attachment_id
	 */
	public function on_enqueued( $attachment_id ): void {
		if ( 0 === (int) $attachment_id ) {
			$this->enqueue_all_eligible( 'reconcile' );
		}

		$this->requested = true;
	}

	/**
	 * Shutdown, last priority: the request queued something. Under PHP-FPM the response is
	 * handed over first, then this process drains; anywhere else, a loopback starts the drain.
	 */
	public function on_shutdown(): void {
		if ( ! $this->requested ) {
			return;
		}
		$this->requested = false;

		if ( ! DeliveryProbe::is_serving() ) {
			return;
		}

		if ( $this->is_cli() ) {
			$this->trigger();
			return;
		}

		if ( ! $this->can_encode_here() ) {
			return; // The loopback would land in this same runtime.
		}

		if ( function_exists( 'fastcgi_finish_request' ) ) {
			if ( function_exists( 'session_status' ) && PHP_SESSION_ACTIVE === session_status() ) {
				session_write_close();
			}
			ignore_user_abort( true );
			fastcgi_finish_request();
			$this->drain();
			return;
		}

		$this->trigger();
	}

	/**
	 * Starts a drain in the web runtime without waiting for it: a non-blocking POST to
	 * admin-ajax.php carrying the current token. Nothing is sent while the AVIF is not served.
	 */
	public function trigger(): void {
		if ( ! DeliveryProbe::is_serving() ) {
			return;
		}

		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			[
				'blocking'  => false,
				'timeout'   => 0.01,
				/** This filter is documented in wp-includes/class-wp-http-streams.php */
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter, as wp-cron's own loopback.
				'body'      => [
					'action' => self::AJAX_ACTION,
					'token'  => self::token(),
				],
			]
		);
	}

	/**
	 * Cron handler: drains in the web runtime (WP-Cron spawned by a visit), only triggers the
	 * loopback from the CLI (system cron, WP-CLI).
	 */
	public function run_cron(): void {
		$this->drain();
	}

	/**
	 * AJAX handler of the loopback (logged in or not). The token is the only accepted input:
	 * no nonce, no capability, since the request comes from the site itself, without a user.
	 * It can only start a drain, which works on what is already queued.
	 */
	public function ajax_drain(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- HMAC token instead of a nonce: documented exception (docs/core.md, AJAX endpoints).
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';

		if ( ! self::is_valid_token( $token ) ) {
			wp_send_json_error( null, 403 );
		}

		$this->respond_early();
		$this->drain();

		wp_die( '' );
	}

	/**
	 * Answers the loopback before draining. WordPress's HTTP API raises any timeout to 1 s
	 * (Requests, cURL resolver limit): a "non-blocking" request waits for the response up to
	 * that long, in the request that triggered it (an upload under Apache). The complete
	 * answer (Content-Length, Connection: close) is flushed first; under PHP-FPM the request
	 * is finished as well. The drain must then survive the client hanging up.
	 */
	private function respond_early(): void {
		ignore_user_abort( true );

		if ( function_exists( 'session_status' ) && PHP_SESSION_ACTIVE === session_status() ) {
			session_write_close();
		}

		$body = (string) wp_json_encode( [ 'success' => true ] );
		if ( ! headers_sent() ) {
			status_header( 200 );
			header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
			header( 'Content-Length: ' . strlen( $body ) );
			header( 'Connection: close' );
		}

		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON built above.
		flush();

		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}
	}

	/**
	 * Token of the current window (offset -1: the previous one).
	 */
	public static function token( int $offset = 0 ): string {
		return hash_hmac( 'sha256', (string) ( floor( time() / self::TOKEN_WINDOW ) + $offset ), wp_salt( 'nonce' ) . 'lumia-avif' );
	}

	private static function is_valid_token( string $token ): bool {
		if ( 64 !== strlen( $token ) ) {
			return false;
		}

		return hash_equals( self::token(), $token ) || hash_equals( self::token( -1 ), $token );
	}

	/* ================================================================
	 * DRAIN
	 * ================================================================ */

	/**
	 * Processes the queue in arrival order until it is empty or the budget is spent (wall time,
	 * bounded by max_execution_time - 5 when that is set). Only one worker per site: without
	 * the lock, returns at once. What is left triggers the next pass.
	 *
	 * Under the CLI, or where Imagick cannot encode here, nothing is encoded: from the CLI the
	 * drain is only triggered in the web runtime.
	 *
	 * @return int Media items processed.
	 */
	public function drain( int $budget_seconds = 20 ): int {
		if ( ! $this->may_run() ) {
			return 0;
		}

		if ( $this->is_cli() ) {
			$this->trigger();
			return 0;
		}
		if ( ! $this->can_encode_here() ) {
			return 0;
		}

		$lock = self::lock_name();
		if ( ! $this->acquire( $lock ) ) {
			return 0; // Another worker drains.
		}

		$budget    = $this->budget( $budget_seconds );
		$start     = microtime( true );
		$processed = 0;
		$go_on     = true;

		try {
			// The lock is ours: a `processing` item is the trace of a process that died.
			AvifState::requeue_interrupted();

			while ( microtime( true ) - $start < $budget ) {
				if ( ! $this->holds( $lock ) || ! $this->may_run() ) {
					$go_on = false;
					break;
				}

				$id = AvifState::next_pending();
				if ( null === $id ) {
					break;
				}

				$began = microtime( true );
				$this->process_one( $id );
				++$processed;

				// A bulk leaves the visitors half of the CPU: pause as long as the image took.
				if ( 'bulk' === (string) get_post_meta( $id, AvifState::ORIGIN, true ) ) {
					usleep( (int) ( ( microtime( true ) - $began ) * 1000000 ) );
				}
			}
		} finally {
			$this->release( $lock );
		}

		// Checked after the release: an item queued while the lock was held (its own drain
		// gave up) is seen here.
		if ( $go_on && null !== AvifState::next_pending() ) {
			$this->trigger();
		}

		return $processed;
	}

	/**
	 * Encodes the files of one media item that are not fresh, and records the result.
	 *
	 * Status: `done` (every file has its AVIF), `partial` (some files skipped), `skipped` (none
	 * kept), `failed` (an error: message of the first one; also a missing source file, or a
	 * fourth attempt). When the item is queued again meanwhile (new generation), the result
	 * is thrown away and the item stays `pending`.
	 *
	 * @return string The status after the call.
	 */
	public function process_one( int $id ): string {
		if ( $this->is_cli() || ! $this->can_encode_here() ) {
			return AvifState::status_now( $id );
		}

		// This process may have cached the item before another request changed it.
		wp_cache_delete( $id, 'post_meta' );

		$gen      = AvifState::gen( $id );
		$attempts = AvifState::begin_attempt( $id );
		AvifState::set_worker( $id, PHP_SAPI . ':' . getmypid() );

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( self::IMAGE_TIME_LIMIT );
		}

		if ( $attempts > self::MAX_ATTEMPTS ) {
			return $this->finish( $id, $gen, AvifState::FAILED, 'too many attempts' );
		}

		$files = $this->lifecycle->source_files( $id );
		if ( ! $files ) {
			return $this->finish( $id, $gen, AvifState::SKIPPED, 'no JPEG or PNG file to encode' );
		}
		foreach ( $files as $path ) {
			if ( ! is_file( $path ) ) {
				return $this->finish( $id, $gen, AvifState::FAILED, 'source file missing: ' . AvifState::rel( $path ) );
			}
		}

		$strip      = (bool) ( $this->module->get_settings()['strip_exif'] ?? false );
		$can_commit = function () use ( $id, $gen ): bool {
			return AvifState::gen( $id ) === $gen && $this->may_run();
		};

		$sizes   = [];
		$kept    = 0;
		$skipped = 0;
		$failure = '';
		$reason  = '';

		foreach ( $files as $path ) {
			$state = AvifState::get( $id )['sizes'][ AvifState::rel( $path ) ] ?? null;
			if ( null !== $state && AvifState::is_fresh( $id, $path ) ) {
				if ( null === $state['avif_bytes'] ) {
					++$skipped;
				} else {
					++$kept;
				}
				continue;
			}

			if ( function_exists( 'set_time_limit' ) ) {
				set_time_limit( self::IMAGE_TIME_LIMIT );
			}

			// EXIF / XMP of the served JPEG, removed losslessly before the fingerprint
			// (spec 9.8; never original_image, which is not a source file).
			if ( $strip && 'image/jpeg' === wp_get_image_mime( $path ) ) {
				JpegMetadata::strip_app1( $path );
			}

			$print  = AvifState::fingerprint( $path );
			$result = $this->encoder()->encode( $path, $can_commit );

			if ( EncodeResult::SKIPPED === $result->status && 'stale' === $result->error ) {
				return $this->abandon( $id );
			}

			if ( EncodeResult::FAILED === $result->status ) {
				$failure = '' !== $failure ? $failure : ( '' !== $result->error ? $result->error : 'encoding failed' );
				continue;
			}

			$sizes[ $path ] = [
				'bytes'      => $print['bytes'],
				'mtime'      => $print['mtime'],
				'avif_bytes' => EncodeResult::DONE === $result->status ? $result->avif_bytes : null,
			];

			if ( EncodeResult::DONE === $result->status ) {
				++$kept;
			} else {
				++$skipped;
				$reason = '' !== $reason ? $reason : $result->error;
			}
		}

		if ( ! $this->is_current( $id, $gen ) ) {
			return $this->abandon( $id );
		}

		if ( $sizes ) {
			AvifState::record_sizes( $id, $sizes );
		}

		if ( '' !== $failure ) {
			return $this->finish( $id, $gen, AvifState::FAILED, $failure );
		}
		if ( 0 === $kept ) {
			return $this->finish( $id, $gen, AvifState::SKIPPED, $reason );
		}

		return $this->finish( $id, $gen, $skipped > 0 ? AvifState::PARTIAL : AvifState::DONE );
	}

	/**
	 * True while another process (or this one) holds the queue lock.
	 */
	public function is_running(): bool {
		global $wpdb;

		return null !== $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK( %s )', self::lock_name() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- named lock.
	}

	/**
	 * Name of the queue lock. GET_LOCK is global to the MySQL server and limited to 64
	 * characters: several sites may share the server (shared hosting). `$wpdb->dbname` is
	 * DB_NAME (the value wpdb was built with).
	 */
	public static function lock_name(): string {
		global $wpdb;

		return 'lumia_avif_' . md5( $wpdb->dbname . $wpdb->prefix . home_url() );
	}

	/* ================================================================
	 * QUEUE THE LIBRARY
	 * ================================================================ */

	/**
	 * Queues every media item that should have AVIF siblings and has none in the works: JPEG /
	 * PNG (or HEIC converted by WordPress), without state or `failed`, never `excluded` (a name
	 * matching an exclusion suffix gets `excluded`). Read in batches of 500.
	 *
	 * @param string $origin `reconcile` (delivery came back), `bulk`...
	 * @return int Media items queued.
	 */
	public function enqueue_all_eligible( string $origin ): int {
		global $wpdb;

		ignore_user_abort( true );

		$mimes  = self::ELIGIBLE_MIMES;
		$last   = 0;
		$queued = 0;

		do {
			$ids   = array_map(
				'intval',
				(array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- batch walk over the library.
					$wpdb->prepare(
						"SELECT p.ID FROM {$wpdb->posts} p
						LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s
						WHERE p.post_type = 'attachment' AND p.post_mime_type IN ( %s, %s, %s, %s, %s ) AND p.ID > %d
						AND ( s.meta_value IS NULL OR s.meta_value = %s )
						ORDER BY p.ID ASC LIMIT %d",
						AvifState::STATUS,
						$mimes[0],
						$mimes[1],
						$mimes[2],
						$mimes[3],
						$mimes[4],
						$last,
						AvifState::FAILED,
						self::ENQUEUE_BATCH
					)
				)
			);
			$batch = count( $ids );

			if ( ! $ids ) {
				break;
			}

			if ( function_exists( 'set_time_limit' ) ) {
				set_time_limit( self::IMAGE_TIME_LIMIT );
			}
			update_meta_cache( 'post', $ids );

			foreach ( $ids as $id ) {
				$files = $this->lifecycle->source_files( $id );
				if ( ! $files ) {
					continue;
				}

				$metadata = wp_get_attachment_metadata( $id );
				$name     = is_array( $metadata ) ? (string) ( $metadata['original_image'] ?? $metadata['file'] ?? '' ) : '';
				if ( '' !== $name && $this->lifecycle->is_excluded_by_name( $name ) ) {
					AvifState::set_status( $id, AvifState::EXCLUDED );
					continue;
				}

				AvifState::enqueue( $id, $origin );
				++$queued;
			}

			$last = (int) end( $ids );
		} while ( self::ENQUEUE_BATCH === $batch );

		return $queued;
	}

	/* ================================================================
	 * HELPERS
	 * ================================================================ */

	/**
	 * Final status, written only when the item still belongs to this attempt.
	 */
	private function finish( int $id, int $gen, string $status, string $error = '' ): string {
		if ( ! $this->is_current( $id, $gen ) ) {
			return $this->abandon( $id );
		}

		AvifState::set_status( $id, $status, $error );

		return $status;
	}

	/**
	 * The item was queued again (or excluded, or purged) during the attempt: its result is
	 * dropped. Queued again: it goes back to `pending` for the new generation.
	 */
	private function abandon( int $id ): string {
		$status = AvifState::status_now( $id );

		if ( AvifState::PROCESSING === $status ) {
			update_post_meta( $id, AvifState::STATUS, AvifState::PENDING, AvifState::PROCESSING );
			return AvifState::PENDING;
		}

		if ( AvifState::EXCLUDED === $status || '' === $status ) {
			$this->lifecycle->delete_siblings( $id );
		}

		return $status;
	}

	/**
	 * Same generation, still `processing`: nobody queued, excluded or purged the item meanwhile.
	 */
	private function is_current( int $id, int $gen ): bool {
		return AvifState::gen( $id ) === $gen && AvifState::PROCESSING === AvifState::status_now( $id );
	}

	/**
	 * The module is on and the AVIF is served. Both read from the database: a worker running
	 * for seconds must notice a deactivation or a failed self-test (the in-request option
	 * cache would not).
	 *
	 * @phpstan-impure
	 */
	private function may_run(): bool {
		$settings = $this->fresh_option( 'lumia_settings' );
		if ( ! is_array( $settings ) || empty( $settings['modules']['image_optimizer'] ) ) {
			return false;
		}

		$delivery = $this->fresh_option( DeliveryProbe::OPTION );

		return is_array( $delivery ) && in_array( $delivery['mode'] ?? '', [ DeliveryProbe::MODE_NGINX, DeliveryProbe::MODE_HTACCESS ], true );
	}

	/**
	 * @return mixed
	 */
	private function fresh_option( string $name ) {
		global $wpdb;

		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the cached value is precisely what must be bypassed.

		return null === $raw ? null : maybe_unserialize( (string) $raw );
	}

	private function is_cli(): bool {
		return in_array( PHP_SAPI, self::CLI_SAPIS, true ) || ( defined( 'WP_CLI' ) && WP_CLI );
	}

	private function can_encode_here(): bool {
		return (bool) ( $this->processor->get_capabilities()['can_encode_here'] ?? false );
	}

	private function encoder(): AvifEncoder {
		if ( null === $this->encoder ) {
			$settings      = $this->module->get_settings();
			$this->encoder = new AvifEncoder(
				(int) ( $settings['quality'] ?? 70 ),
				(string) ( $settings['speed'] ?? 'balanced' ),
				(bool) ( $settings['strip_exif'] ?? true ),
				$this->processor
			);
		}

		return $this->encoder;
	}

	/**
	 * Budget of a pass in seconds: the requested one, at most max_execution_time - 5.
	 */
	private function budget( int $requested ): int {
		$budget = max( 1, $requested );
		$limit  = (int) ini_get( 'max_execution_time' );

		return $limit > 0 ? max( 1, min( $budget, $limit - 5 ) ) : $budget;
	}

	private function acquire( string $lock ): bool {
		global $wpdb;

		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 0 )', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- named lock.
	}

	/**
	 * The lock still belongs to this connection (a reconnection of $wpdb loses it).
	 */
	private function holds( string $lock ): bool {
		global $wpdb;

		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK( %s ) = CONNECTION_ID()', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- named lock.
	}

	private function release( string $lock ): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- named lock.
	}
}
