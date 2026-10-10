<?php
namespace Lumia\Tools\Modules\ImageOptimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Delivery self-test (spec sections 1, 9.2, 9.10): decides whether the AVIF siblings can be
 * served. Nothing is generated while it has not proven that the web server answers
 * `photo.jpg` with `photo.jpg.avif` to the clients that announce `image/avif` only, always
 * with `Vary: Accept`.
 *
 * The test requests the plugin's own probe (`uploads/lumia-tools/probe.png`, its sibling
 * `probe.png.avif`, and `probe-plain.png` without a sibling) through the site's public URL,
 * as a visitor would:
 *
 * 1. `Accept: image/avif,image/*` -> `image/avif`, with `Vary: Accept`;
 * 2. `Accept: image/png,image/*`  -> `image/png`, with `Vary: Accept`;
 * 3. `?original` with AVIF Accept -> `image/png`;
 * 4. the file without a sibling   -> 200 `image/png` (a broken rewrite shows up here).
 *
 * The series runs twice in alternating order (AVIF first, then plain first) on one URL per
 * run (`?lumia_probe=<token>`): a cache that ignores `Vary` serves the first variant it stored
 * to the next client, whichever came first. `Vary` is required on the JPEG/PNG answer too:
 * OpenLiteSpeed ignores `Header` in `.htaccess` and caches the rewrite target per URL, and it
 * calls itself "LiteSpeed" like LiteSpeed Enterprise (bench finding, tools/e2e-images).
 *
 * Outcome (`OPTION`): `nginx` (a server rule outside the plugin), `htaccess` (the plugin's
 * block in uploads/.htaccess), or `none`:
 *
 * - incorrect delivery (wrong type, no Vary, HTTP 500) -> `none` at once, block removed;
 * - every generated sibling is also deleted when it may still reach a client that cannot
 *   display it: an AVIF served where the JPEG/PNG was expected, an AVIF served without Vary
 *   by a rule outside the plugin, or any incorrect delivery behind a CDN;
 * - a CDN that is not recognized -> `none` (it cannot be trusted with Vary); a recognized one
 *   must prove it keeps the variants apart: a repeated variant answered from its cache
 *   (`cf-cache-status` / `X-Cache` HIT) with the right type, otherwise `none` (`cdn_unproven`);
 * - unreachable (network error, timeout, 4xx) -> previous result kept up to 3 consecutive
 *   failures, except right after the block was written (removed at once).
 *
 * The loopback may bypass a CDN (internal resolution): the Delivery tab repeats the test from
 * the administrator's browser (`apply_browser_result()`); a wrong answer there forces `none`
 * until a browser check passes again.
 */
final class DeliveryProbe {

	public const OPTION        = 'lumia_module_image_optimizer_delivery';
	public const MODE_NGINX    = 'nginx';
	public const MODE_HTACCESS = 'htaccess';
	public const MODE_NONE     = 'none';
	public const CRON_HOOK     = 'lumia_image_optimizer_delivery_check';

	/** Folder of the probe files, under uploads. */
	public const DIR = 'lumia-tools';

	private const MODES = [ self::MODE_NGINX, self::MODE_HTACCESS, self::MODE_NONE ];

	/** Consecutive unreachable checks tolerated before falling back to `none`. */
	private const MAX_UNREACHABLE = 3;

	private const ACCEPT_AVIF  = 'image/avif,image/*';
	private const ACCEPT_PLAIN = 'image/png,image/*';

	/** 8x8 PNG (104 bytes). */
	private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAgAAAAICAIAAABLbSncAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAGklEQVQI12OUqzghycCAiVgYGN4wYAODUwIADI8In3HIm8UAAAAASUVORK5CYII=';

	/**
	 * The same image in AVIF (293 bytes), encoded once by the production image (the official
	 * WordPress 7 / PHP 8.5 FPM Alpine image, ImageMagick 7.1.2-30) and checked decodable by its
	 * Imagick and by macOS ImageIO. Embedded: the probe must not depend on the encoder.
	 */
	private const AVIF = 'AAAAHGZ0eXBhdmlmAAAAAG1pZjFhdmlmbWlhZgAAANZtZXRhAAAAAAAAACFoZGxyAAAAAAAAAABwaWN0AAAAAAAAAAAAAAAAAAAAACJpbG9jAAAAAERAAAEAAQAAAAAA+gABAAAAAAAAACsAAAAjaWluZgAAAAAAAQAAABVpbmZlAgAAAAABAABhdjAxAAAAAA5waXRtAAAAAAABAAAAVmlwcnAAAAA4aXBjbwAAAAxhdjFDgQAMAAAAABRpc3BlAAAAAAAAAAgAAAAIAAAAEHBpeGkAAAAAAwgICAAAABZpcG1hAAAAAAAAAAEAAQOBAgMAAAAzbWRhdBIACggYCL9ogIaDQjIdF4eHhiGEkkkmQQAAkD6wnIyPzrfvXzL6DunOVyI=';

	/** CDNs recognized by their headers; anything else that looks like a cache is `unknown`. */
	private const CDNS = [ 'cloudflare', 'fastly', 'sucuri', 'hostinger' ];

	/**
	 * Deletes every generated sibling (keeps the user's exclusions).
	 *
	 * @var callable(): mixed
	 */
	private $purge;

	private HtaccessWriter $htaccess;

	/**
	 * @param callable(): mixed $purge Deletes every generated `.avif` sibling.
	 */
	public function __construct( callable $purge, ?HtaccessWriter $htaccess = null ) {
		$this->purge    = $purge;
		$this->htaccess = $htaccess ?? new HtaccessWriter();
	}

	public function register(): void {
		add_action( self::CRON_HOOK, [ $this, 'run_scheduled' ] );
		add_action( 'wp_ajax_lumia_image_optimizer_delivery_retest', [ $this, 'ajax_retest' ] );
		add_action( 'wp_ajax_lumia_image_optimizer_delivery_browser', [ $this, 'ajax_browser' ] );

		$this->schedule();
	}

	/**
	 * Daily check. Never tested yet (plugin reactivated, result reset): the first one runs at
	 * once instead of a day later.
	 */
	public function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( 0 === self::result()['checked_at'] ? time() : time() + DAY_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Module or plugin deactivation: no block in uploads/.htaccess (the server would keep
	 * serving siblings nobody maintains), no daily check, no verdict. Only the web server seen
	 * last is kept: a check run later by WP-CLI or a system cron needs it. The probe files
	 * stay (the uninstall removes them).
	 */
	public static function reset(): void {
		wp_unschedule_hook( self::CRON_HOOK );
		( new HtaccessWriter() )->remove();

		$server = self::result()['server'];
		update_option( self::OPTION, '' === $server ? [] : [ 'server' => $server ], true );
	}

	/* ================================================================
	 * RESULT
	 * ================================================================ */

	/**
	 * AVIF siblings are served (and may therefore be generated).
	 */
	public static function is_serving(): bool {
		return in_array( self::result()['mode'], [ self::MODE_NGINX, self::MODE_HTACCESS ], true );
	}

	/**
	 * The recorded result:
	 *
	 * - `mode`        nginx | htaccess | none (what is used)
	 * - `server_mode` what the server-side test alone allows (the browser check may veto it)
	 * - `cdn`         '' | cloudflare | fastly | sucuri | hostinger | unknown
	 * - `reason`      pending | ok | no_rule | no_vary | leak | server_error | unreachable |
	 *                 cdn_unknown | cdn_vary | cdn_unproven | browser | write_failed | probe_files
	 * - `detail`      technical detail of the last check (not translated)
	 * - `checked_at`  timestamp of the last server-side check (0: never)
	 * - `failures`    consecutive unreachable checks
	 * - `server`      apache | litespeed | nginx | other | '' (last known web server)
	 * - `browser`     '' | ok | failed (last browser check), `browser_at` its timestamp
	 *
	 * @return array{mode: string, server_mode: string, cdn: string, reason: string, detail: string, checked_at: int, failures: int, server: string, browser: string, browser_at: int}
	 */
	public static function result(): array {
		$raw = get_option( self::OPTION, [] );
		$raw = is_array( $raw ) ? $raw : [];

		$mode        = (string) ( $raw['mode'] ?? '' );
		$server_mode = (string) ( $raw['server_mode'] ?? '' );
		$browser     = (string) ( $raw['browser'] ?? '' );

		return [
			'mode'        => in_array( $mode, self::MODES, true ) ? $mode : self::MODE_NONE,
			'server_mode' => in_array( $server_mode, self::MODES, true ) ? $server_mode : self::MODE_NONE,
			'cdn'         => (string) ( $raw['cdn'] ?? '' ),
			'reason'      => (string) ( $raw['reason'] ?? 'pending' ),
			'detail'      => (string) ( $raw['detail'] ?? '' ),
			'checked_at'  => (int) ( $raw['checked_at'] ?? 0 ),
			'failures'    => (int) ( $raw['failures'] ?? 0 ),
			'server'      => (string) ( $raw['server'] ?? '' ),
			'browser'     => in_array( $browser, [ 'ok', 'failed' ], true ) ? $browser : '',
			'browser_at'  => (int) ( $raw['browser_at'] ?? 0 ),
		];
	}

	/* ================================================================
	 * SERVER-SIDE TEST
	 * ================================================================ */

	/**
	 * Runs the self-test and records the result. A test already running elsewhere: the
	 * recorded result is returned unchanged.
	 *
	 * @return array{mode: string, server_mode: string, cdn: string, reason: string, detail: string, checked_at: int, failures: int, server: string, browser: string, browser_at: int}
	 */
	public function run(): array {
		$lock = $this->lock();
		if ( '' === $lock ) {
			return self::result();
		}

		try {
			return $this->evaluate();
		} finally {
			$this->unlock( $lock );
		}
	}

	/**
	 * Cron handler (daily).
	 */
	public function run_scheduled(): void {
		$this->run();
	}

	/**
	 * @return array{mode: string, server_mode: string, cdn: string, reason: string, detail: string, checked_at: int, failures: int, server: string, browser: string, browser_at: int}
	 */
	private function evaluate(): array {
		$previous = self::result();

		// WP-CLI and a system cron do not know the web server: the last one seen is used.
		$server = HtaccessWriter::server_software();
		if ( '' === $server ) {
			$server = $previous['server'];
		}

		$next = array_merge(
			$previous,
			[
				'server'     => $server,
				'checked_at' => time(),
				'failures'   => 0,
			]
		);

		if ( ! $this->ensure_probe_files() ) {
			$this->htaccess->remove();
			return $this->commit( $previous, $this->verdict( $next, self::MODE_NONE, 'probe_files', '', '' ), false );
		}

		$wrote        = false;
		$write_failed = false;
		if ( in_array( $server, [ 'apache', 'litespeed' ], true ) && ! $this->htaccess->is_current() ) {
			$wrote        = $this->htaccess->write();
			$write_failed = ! $wrote;
		}

		$tested_with_block = $this->htaccess->is_present();
		$check             = $this->check();

		if ( 'unreachable' === $check['status'] && ! $wrote ) {
			$failures = $previous['failures'] + 1;
			if ( $failures < self::MAX_UNREACHABLE && self::MODE_NONE !== $previous['server_mode'] ) {
				// A passing glitch: the previous verdict (and the block) stay.
				$next['failures'] = $failures;
				$next['detail']   = $check['detail'];
				return $this->commit( $previous, $next, false );
			}
		}

		if ( 'pass' !== $check['status'] ) {
			// The siblings go whenever they may still reach a client that cannot display them
			// (spec 1 keeps them only when the server does not serve them): an AVIF served to
			// the wrong client, an AVIF served without Vary by a rule outside the plugin (no
			// block of ours to remove: it keeps serving them), or any incorrect delivery with a
			// CDN in front (its cache may already mix the variants up).
			$purge = $check['purge']
				|| ( 'no_vary' === $check['reason'] && ! $tested_with_block )
				|| ( 'incorrect' === $check['status'] && '' !== $check['cdn'] );

			if ( $tested_with_block ) {
				// The block is never left in place without a successful test (spec 9.2).
				$this->htaccess->remove();

				// OpenLiteSpeed keeps applying a removed .htaccess in the workers that loaded
				// it, and once an AVIF client was rewritten, it serves the AVIF to everyone at
				// that URL (measured, tools/e2e-images README). Only a missing sibling stops
				// it: the probe's goes, and on LiteSpeed every generated one too.
				wp_delete_file( self::dir() . '/probe.png.avif' );
				$purge = $purge || 'litespeed' === $server;
			}

			$reason = $check['reason'];
			if ( $write_failed && in_array( $reason, [ 'no_rule', 'no_vary' ], true ) ) {
				$reason = 'write_failed';
			}
			return $this->commit( $previous, $this->verdict( $next, self::MODE_NONE, $reason, $check['detail'], $check['cdn'] ), $purge );
		}

		$server_mode = $this->htaccess->is_present() ? self::MODE_HTACCESS : self::MODE_NGINX;

		return $this->commit( $previous, $this->verdict( $next, $server_mode, 'ok', $check['detail'], $check['cdn'] ), false );
	}

	/**
	 * Applies the server-side verdict, under the veto of a failed browser check.
	 *
	 * @param array<string, mixed> $next
	 * @return array<string, mixed>
	 */
	private function verdict( array $next, string $server_mode, string $reason, string $detail, string $cdn ): array {
		$next['server_mode'] = $server_mode;
		$next['cdn']         = $cdn;
		$next['detail']      = $detail;
		$next['mode']        = $server_mode;
		$next['reason']      = $reason;

		if ( self::MODE_NONE !== $server_mode && 'failed' === $next['browser'] ) {
			$next['mode']   = self::MODE_NONE;
			$next['reason'] = 'browser';
		}

		return $next;
	}

	/**
	 * Records the result, deletes the siblings when asked, restarts the queue when AVIF
	 * becomes served.
	 *
	 * @param array<string, mixed> $previous
	 * @param array<string, mixed> $next
	 * @return array{mode: string, server_mode: string, cdn: string, reason: string, detail: string, checked_at: int, failures: int, server: string, browser: string, browser_at: int}
	 */
	private function commit( array $previous, array $next, bool $purge ): array {
		update_option( self::OPTION, $next, true );

		if ( $purge ) {
			( $this->purge )();
		}

		$was_serving = in_array( $previous['mode'], [ self::MODE_NGINX, self::MODE_HTACCESS ], true ) && $previous['checked_at'] > 0;
		if ( ! $was_serving && self::is_serving() ) {
			/** This action is documented in includes/Modules/ImageOptimizer/FileLifecycle.php */
			do_action( 'lumia_image_optimizer_enqueued', 0 );
		}

		return self::result();
	}

	/**
	 * The requests of the test, in alternating order, on one URL per run.
	 *
	 * @return array{status: string, reason: string, detail: string, cdn: string, purge: bool}
	 */
	private function check(): array {
		$token    = time() . wp_rand( 100, 999 );
		$probe    = add_query_arg( 'lumia_probe', $token, $this->url( 'probe.png' ) );
		$original = add_query_arg( 'original', '1', $probe );
		$witness  = add_query_arg( 'lumia_probe', $token, $this->url( 'probe-plain.png' ) );

		// url, Accept, expected type, Vary required, same variant already requested in this run
		// (a cache that keeps the variants apart answers it from its cache).
		$plan = [
			[ $probe, self::ACCEPT_AVIF, 'image/avif', true, false ],
			[ $probe, self::ACCEPT_PLAIN, 'image/png', true, false ],
			[ $original, self::ACCEPT_AVIF, 'image/png', true, false ],
			[ $witness, self::ACCEPT_AVIF, 'image/png', false, false ],
			[ $probe, self::ACCEPT_PLAIN, 'image/png', true, true ],
			[ $probe, self::ACCEPT_AVIF, 'image/avif', true, true ],
			[ $original, self::ACCEPT_AVIF, 'image/png', true, true ],
		];

		$failures   = [];
		$cdn        = '';
		$details    = [];
		$caches     = [];
		$cache_used = false;

		foreach ( $plan as [ $url, $accept, $expected, $vary_required, $repeat ] ) {
			$response = wp_remote_get(
				$url,
				[
					'timeout'             => 10,
					'redirection'         => 3,
					'sslverify'           => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter, as WordPress's own loopback requests.
					'user-agent'          => 'Lumia Tools delivery check; ' . home_url( '/' ),
					'headers'             => [ 'Accept' => $accept ],
					'limit_response_size' => 65536,
				]
			);

			$label = ( str_contains( $url, 'original=' ) ? 'original ' : ( str_contains( $url, 'probe-plain' ) ? 'no-sibling ' : '' ) ) . $accept;

			if ( is_wp_error( $response ) ) {
				return $this->outcome( 'unreachable', 'unreachable', $label . ': ' . $response->get_error_message(), $cdn );
			}

			$cdn       = $this->merge_cdn( $cdn, self::detect_cdn( $response ) );
			$code      = (int) wp_remote_retrieve_response_code( $response );
			$type      = strtolower( trim( explode( ';', $this->header( $response, 'content-type' ) )[0] ) );
			$vary      = $this->header( $response, 'vary' );
			$body      = (string) wp_remote_retrieve_body( $response );
			$cache     = $this->cache_status( $response );
			$line      = sprintf( '%s -> %d %s, Vary: %s', $label, $code, '' === $type ? '-' : $type, '' === $vary ? '-' : $vary )
				. ( '' === $cache ? '' : ', cache: ' . $cache );
			$details[] = $line;
			$caches[]  = '' === $cache ? '-' : $cache;

			if ( 500 === $code ) {
				return $this->outcome( 'incorrect', 'server_error', $line, $cdn );
			}
			if ( 200 !== $code || ! in_array( $type, [ 'image/avif', 'image/png' ], true ) ) {
				// 401/403 (protected site), 404 (uploads elsewhere), 502/503, an HTML page...
				return $this->outcome( 'unreachable', 'unreachable', $line, $cdn );
			}

			$is_avif = 'image/avif' === $type && str_contains( substr( $body, 0, 32 ), 'ftypavif' );
			// The first failing answer of each kind is the one reported.
			if ( 'image/avif' === $expected && ! $is_avif ) {
				$failures['no_rule'] ??= $line;
			} elseif ( 'image/png' === $expected && ( 'image/png' !== $type || ! str_starts_with( $body, "\x89PNG" ) ) ) {
				$failures['leak'] ??= $line;
			}
			if ( $vary_required && ! preg_match( '/(^|,)\s*accept\s*(,|$)/i', $vary ) ) {
				$failures['no_vary'] ??= $line;
			}

			// The types are checked on every answer: a hit on a repeated variant proves the
			// cache served the right one.
			if ( $repeat && preg_match( '/\bHIT\b/i', $cache ) ) {
				$cache_used = true;
			}
		}

		$detail = implode( ' | ', array_slice( $details, 0, 4 ) );

		if ( 'unknown' === $cdn ) {
			// An unknown cache in front of the site cannot be trusted with Vary (spec 9.10).
			return $this->outcome( 'incorrect', 'cdn_unknown', $detail, $cdn, true );
		}

		foreach ( [ 'leak', 'no_rule', 'no_vary' ] as $reason ) {
			if ( isset( $failures[ $reason ] ) ) {
				$type_mismatch = isset( $failures['leak'] ) || isset( $failures['no_rule'] );
				if ( '' !== $cdn && $type_mismatch ) {
					// A CDN that does not keep the variants apart: the siblings go, the JPEG/PNG
					// must be guaranteed (spec 1).
					return $this->outcome( 'incorrect', 'cdn_vary', $failures[ $reason ], $cdn, true );
				}
				return $this->outcome( 'incorrect', $reason, $failures[ $reason ], $cdn, isset( $failures['leak'] ) );
			}
		}

		if ( '' !== $cdn && ! $cache_used ) {
			// A recognized CDN that never answered a repeated variant from its cache: these
			// probe URLs (with a query string) may bypass it while the plain image URLs that
			// visitors and mail clients fetch are cached with Vary ignored. Not proven, so off
			// (spec 1: Cloudflare only with proof; 9.10: cache status read).
			return $this->outcome( 'incorrect', 'cdn_unproven', 'cache status per request: ' . implode( ' ', $caches ) . ' | ' . $detail, $cdn );
		}

		if ( '' !== $cdn ) {
			$detail = 'cache status per request: ' . implode( ' ', $caches ) . ' | ' . $detail;
		}

		return $this->outcome( 'pass', 'ok', $detail, $cdn );
	}

	/**
	 * Cache status a CDN reports for a response ('' when none): Cloudflare's
	 * `cf-cache-status`, the `X-Cache` of Fastly and others, Sucuri's and Hostinger's.
	 *
	 * @param array<string, mixed> $response
	 */
	private function cache_status( array $response ): string {
		foreach ( [ 'cf-cache-status', 'x-cache', 'x-sucuri-cache', 'x-hcdn-cache-status', 'x-cache-status' ] as $name ) {
			$value = trim( $this->header( $response, $name ) );
			if ( '' !== $value ) {
				return strtoupper( substr( $value, 0, 40 ) );
			}
		}

		return '';
	}

	/**
	 * @return array{status: string, reason: string, detail: string, cdn: string, purge: bool}
	 */
	private function outcome( string $status, string $reason, string $detail, string $cdn, bool $purge = false ): array {
		return [
			'status' => $status,
			'reason' => $reason,
			'detail' => $detail,
			'cdn'    => $cdn,
			'purge'  => $purge,
		];
	}

	/**
	 * Value of a response header, several occurrences joined with commas ('' when absent).
	 *
	 * @param array<string, mixed>|\WP_Error $response
	 */
	private function header( $response, string $name ): string {
		$value = wp_remote_retrieve_header( $response, $name );

		return is_array( $value ) ? implode( ', ', $value ) : (string) $value;
	}

	/**
	 * CDN in front of the site, from the response headers.
	 *
	 * @param array<string, mixed> $response
	 */
	public static function detect_cdn( array $response ): string {
		$headers = wp_remote_retrieve_headers( $response );
		$names   = [];
		if ( $headers instanceof \WpOrg\Requests\Utility\CaseInsensitiveDictionary || is_array( $headers ) ) {
			foreach ( $headers as $name => $value ) {
				$names[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
			}
		}

		if ( isset( $names['cf-ray'] ) || isset( $names['cf-cache-status'] ) || str_contains( strtolower( $names['server'] ?? '' ), 'cloudflare' ) ) {
			return 'cloudflare';
		}
		if ( isset( $names['x-fastly-request-id'] ) || preg_match( '/\bcache-[a-z0-9-]+/i', $names['x-served-by'] ?? '' ) ) {
			return 'fastly';
		}
		if ( isset( $names['x-sucuri-id'] ) || isset( $names['x-sucuri-cache'] ) ) {
			return 'sucuri';
		}
		foreach ( array_keys( $names ) as $name ) {
			if ( str_starts_with( $name, 'x-hcdn-' ) ) {
				return 'hostinger';
			}
		}
		if ( isset( $names['x-cache'] ) || isset( $names['via'] ) || isset( $names['x-served-by'] ) ) {
			return 'unknown';
		}

		return '';
	}

	/**
	 * A recognized CDN wins over `unknown` (generic headers such as Via are also sent by them).
	 */
	private function merge_cdn( string $known, string $found ): string {
		if ( in_array( $known, self::CDNS, true ) ) {
			return $known;
		}

		return '' === $found ? $known : $found;
	}

	/* ================================================================
	 * BROWSER CHECK (spec 9.10)
	 * ================================================================ */

	/**
	 * Result of the Delivery tab's two fetch() of the probe from the administrator's browser
	 * (`Accept: image/avif,*\/*`, then `*\/*`): the loopback may bypass a CDN that visitors go
	 * through. Only ever a veto: a wrong answer means a cache that does not keep the variants
	 * apart, so it forces `none` and deletes the generated siblings; a right one lifts a
	 * previous veto. Statuses other than 200, or types that are not images, prove nothing and
	 * change nothing.
	 *
	 * @return array{mode: string, server_mode: string, cdn: string, reason: string, detail: string, checked_at: int, failures: int, server: string, browser: string, browser_at: int}
	 */
	public function apply_browser_result( int $avif_status, string $avif_type, int $plain_status, string $plain_type ): array {
		$previous = self::result();

		$images = [ 'image/avif', 'image/png' ];
		if ( self::MODE_NONE === $previous['server_mode'] || 200 !== $avif_status || 200 !== $plain_status
			|| ! in_array( $avif_type, $images, true ) || ! in_array( $plain_type, $images, true ) ) {
			return $previous;
		}

		$leak = 'image/avif' === $plain_type;
		$ok   = 'image/avif' === $avif_type && ! $leak;

		$next               = $previous;
		$next['browser']    = $ok ? 'ok' : 'failed';
		$next['browser_at'] = time();
		$next['detail']     = sprintf( 'browser: image/avif,*/* -> %s, */* -> %s', $avif_type, $plain_type );

		if ( $ok ) {
			$next['mode']   = $previous['server_mode'];
			$next['reason'] = 'ok';
		} else {
			$next['mode']   = self::MODE_NONE;
			$next['reason'] = 'browser';
		}

		return $this->commit( $previous, $next, ! $ok );
	}

	/* ================================================================
	 * AJAX
	 * ================================================================ */

	/**
	 * "Retest" button: runs the server-side test.
	 */
	public function ajax_retest(): void {
		$this->check_request();

		$this->send( $this->run() );
	}

	/**
	 * Result of the browser check.
	 */
	public function ajax_browser(): void {
		$this->check_request();

		$type   = static function ( string $key ): string {
			$value = isset( $_POST[ $key ] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in check_request().
			$value = trim( explode( ';', $value )[0] );

			return preg_match( '#^[a-z0-9.+-]+/[a-z0-9.+-]+$#', $value ) ? $value : '';
		};
		$status = static function ( string $key ): int {
			return isset( $_POST[ $key ] ) ? absint( wp_unslash( $_POST[ $key ] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in check_request().
		};

		$this->send( $this->apply_browser_result( $status( 'avif_status' ), $type( 'avif_type' ), $status( 'plain_status' ), $type( 'plain_type' ) ) );
	}

	private function check_request(): void {
		check_ajax_referer( 'lumia_admin_nonce', 'nonce' );

		if ( ! current_user_can( Module::get_required_capability() ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'lumia-tools' ), 403 );
		}
	}

	/**
	 * @param array<string, mixed> $result
	 */
	private function send( array $result ): void {
		wp_send_json_success(
			[
				'result' => $result,
				'html'   => self::render_status( $result ),
			]
		);
	}

	/**
	 * The status block of the Delivery tab.
	 *
	 * @param array<string, mixed>|null $result Defaults to the recorded result.
	 */
	public static function render_status( ?array $result = null ): string {
		$lumia_delivery  = $result ?? self::result();
		$lumia_probe_url = self::probe_url();

		ob_start();
		include __DIR__ . '/delivery-status.php';

		return (string) ob_get_clean();
	}

	/* ================================================================
	 * PROBE FILES
	 * ================================================================ */

	/**
	 * Creates (or repairs) the probe files: `probe.png`, its sibling `probe.png.avif`, and
	 * `probe-plain.png` without a sibling.
	 */
	public function ensure_probe_files(): bool {
		$dir = self::dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$png   = (string) base64_decode( self::PNG ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- embedded probe images.
		$files = [
			'probe.png'       => $png,
			'probe.png.avif'  => (string) base64_decode( self::AVIF ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- embedded probe images.
			'probe-plain.png' => $png,
			'index.php'       => "<?php\n// Silence is golden.\n",
		];

		foreach ( $files as $name => $content ) {
			$path = $dir . '/' . $name;
			clearstatcache( true, $path );
			if ( is_file( $path ) && filesize( $path ) === strlen( $content ) && file_get_contents( $path ) === $content ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
				continue;
			}

			$tmp = $dir . '/.' . $name . '.' . wp_generate_password( 8, false ) . '.tmp';
			if ( false === file_put_contents( $tmp, $content ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local file, renamed below.
				return false;
			}
			chmod( $tmp, 0644 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- a public, static file.
			if ( ! rename( $tmp, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- same-folder atomic rename of a local file.
				wp_delete_file( $tmp );
				return false;
			}
		}

		return true;
	}

	public static function dir(): string {
		return trailingslashit( wp_upload_dir( null, false )['basedir'] ) . self::DIR;
	}

	/**
	 * Public URL of the probe image (the one with a sibling).
	 */
	public static function probe_url(): string {
		return trailingslashit( wp_upload_dir( null, false )['baseurl'] ) . self::DIR . '/probe.png';
	}

	private function url( string $file ): string {
		return trailingslashit( wp_upload_dir( null, false )['baseurl'] ) . self::DIR . '/' . $file;
	}

	/* ================================================================
	 * LOCK
	 * ================================================================ */

	/**
	 * One test at a time per site: a MySQL named lock, released by the server if the process
	 * dies. Returns the lock name ('' when another test holds it).
	 */
	private function lock(): string {
		global $wpdb;

		$name = 'lumia_delivery_' . md5( $wpdb->dbname . $wpdb->prefix . home_url() );
		$got  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 0 )', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- named lock.

		return '1' === (string) $got ? $name : '';
	}

	private function unlock( string $name ): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- named lock.
	}
}
