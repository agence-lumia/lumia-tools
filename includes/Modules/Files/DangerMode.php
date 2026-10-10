<?php
namespace Lumia\Tools\Modules\Files;

defined( 'ABSPATH' ) || exit;

/**
 * "Danger" mode: lifts DISALLOW_FILE_EDIT for the Files module, for one hour.
 *
 * On sites deployed with the Dokploy template, DISALLOW_FILE_EDIT is injected
 * through WORDPRESS_CONFIG_EXTRA: it is not in wp-config.php and cannot be
 * removed from the admin. The danger mode lets an administrator save and upload
 * from the Files module anyway, for a limited time, then falls back to the
 * safe mode by itself.
 *
 * - The state lives server side only (option, not autoloaded): the browser
 *   only receives the remaining time.
 * - Site-wide, not per user: the constant it lifts is site-wide too.
 * - DISALLOW_FILE_MODS and the `file_mod_allowed` filter always win: the
 *   danger mode only lifts DISALLOW_FILE_EDIT.
 * - It only concerns this module: the core theme and plugin editors stay
 *   disabled.
 * - Expiry is enforced twice: every read compares the deadline to the clock
 *   (the source of truth), and a single cron event clears the state and
 *   reports the expiry even when nobody comes back to the screen.
 *
 * Actions fired (listened to by the Activity Log):
 * - `lumia_files_danger_mode_enabled` ( int $expires, int $user_id )
 * - `lumia_files_danger_mode_disabled` ( string $reason 'manual'|'expired', int $enabled_by )
 * - `lumia_files_danger_mode_write` ( string $relative_path, string $operation 'save'|'upload' )
 */
final class DangerMode {

	const OPTION = 'lumia_files_danger_mode';

	const CRON_HOOK = 'lumia_files_danger_mode_expire';

	/** Duration of the danger mode, in seconds. */
	const TTL = 3600;

	/** True if DISALLOW_FILE_EDIT is set and true. */
	public static function edit_disallowed(): bool {
		return defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT;
	}

	/** True unless DISALLOW_FILE_MODS or the `file_mod_allowed` filter locks the files. */
	public static function mods_allowed(): bool {
		return wp_is_file_mod_allowed( 'lumia_files' );
	}

	/**
	 * True if the danger mode can be turned on: there is something to lift
	 * (DISALLOW_FILE_EDIT), nothing stronger forbids it (DISALLOW_FILE_MODS),
	 * and the site did not opt out.
	 */
	public static function is_available(): bool {
		if ( ! self::edit_disallowed() || ! self::mods_allowed() ) {
			return false;
		}

		/**
		 * Whether the Files module may offer its temporary "danger" mode.
		 *
		 * Return false to enforce DISALLOW_FILE_EDIT strictly: the button
		 * disappears and an active danger mode stops working at once.
		 *
		 * @param bool $allowed Default true.
		 */
		return (bool) apply_filters( 'lumia_files_danger_mode_allowed', true );
	}

	/**
	 * Current state, or null if the danger mode is off. An expired state is
	 * cleared (and reported) on the way.
	 *
	 * @return array{expires: int, user_id: int}|null
	 */
	public static function state(): ?array {
		$raw = get_option( self::OPTION );

		if ( ! is_array( $raw ) || empty( $raw['expires'] ) ) {
			return null;
		}

		$state = [
			'expires' => (int) $raw['expires'],
			'user_id' => (int) ( $raw['user_id'] ?? 0 ),
		];

		if ( $state['expires'] <= time() ) {
			self::expire();
			return null;
		}

		return $state;
	}

	/** True if saving and uploading are currently unlocked by the danger mode. */
	public static function is_active(): bool {
		return self::is_available() && null !== self::state();
	}

	/**
	 * Turns the danger mode on for TTL seconds (a second call restarts the
	 * hour).
	 *
	 * @return int Expiry timestamp.
	 */
	public static function enable( int $user_id ): int {
		$expires = time() + self::TTL;

		update_option(
			self::OPTION,
			[
				'expires' => $expires,
				'user_id' => $user_id,
			],
			false
		);

		wp_unschedule_hook( self::CRON_HOOK );
		wp_schedule_single_event( $expires, self::CRON_HOOK );

		do_action( 'lumia_files_danger_mode_enabled', $expires, $user_id );

		return $expires;
	}

	/** Turns the danger mode off by hand. */
	public static function disable(): void {
		self::clear( 'manual' );
	}

	/**
	 * Cron callback, also called by state() on an expired deadline. Does
	 * nothing if the mode was turned off, or restarted for a later deadline,
	 * in the meantime.
	 */
	public static function expire(): void {
		$raw = get_option( self::OPTION );

		if ( ! is_array( $raw ) ) {
			return;
		}

		$expires = (int) ( $raw['expires'] ?? 0 );

		if ( $expires > time() ) {
			// Restarted after this event was scheduled: make sure the new
			// deadline has its own event.
			if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_schedule_single_event( $expires, self::CRON_HOOK );
			}
			return;
		}

		self::clear( 'expired' );
	}

	/**
	 * Deletes the state and reports why. delete_option() only returns true to
	 * the request that actually deleted the row: when the cron event and a
	 * page load expire the mode at the same moment, a single one reports it.
	 */
	private static function clear( string $reason ): void {
		$raw = get_option( self::OPTION );

		wp_unschedule_hook( self::CRON_HOOK );

		if ( ! delete_option( self::OPTION ) ) {
			return;
		}

		$enabled_by = is_array( $raw ) ? (int) ( $raw['user_id'] ?? 0 ) : 0;

		do_action( 'lumia_files_danger_mode_disabled', $reason, $enabled_by );
	}

	/**
	 * State handed to files.js: what to display, and the remaining time in
	 * seconds (computed server side, so a wrong client clock does not matter).
	 *
	 * @return array<string, mixed>
	 */
	public static function to_js(): array {
		$state     = self::is_available() ? self::state() : null;
		$remaining = $state ? max( 0, $state['expires'] - time() ) : 0;
		$until     = '';

		if ( $state ) {
			$user  = $state['user_id'] ? get_userdata( $state['user_id'] ) : false;
			$until = sprintf(
				/* translators: 1: time, e.g. "23:45". 2: display name of the user who turned it on. */
				__( 'File editing is unlocked until %1$s (turned on by %2$s).', 'lumia-tools' ),
				wp_date( (string) get_option( 'time_format' ), $state['expires'] ),
				$user ? $user->display_name : __( 'an unknown user', 'lumia-tools' )
			);
		}

		return [
			'editDisallowed' => self::edit_disallowed(),
			'modsAllowed'    => self::mods_allowed(),
			'available'      => self::is_available(),
			'active'         => null !== $state,
			'remaining'      => $remaining,
			'until'          => $until,
		];
	}
}
