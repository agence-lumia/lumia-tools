<?php
namespace Lumia\Tools\Core\Migration;

defined( 'ABSPATH' ) || exit;

use Lumia\Tools\Modules\ActivityLog\Store as ActivityLogStore;
use Lumia\Tools\Modules\ImageOptimizer\Module as ImageOptimizer;
use Lumia\Tools\Modules\Smtp\Crypto;

/**
 * One-time migration of the data of Studio Kyne Mini Tools (SKMT), the former
 * name of the plugin, run by Activator::activate() BEFORE the defaults are
 * created (a `lumia_settings` created empty would make the copy skip it).
 *
 * Options are COPIED (the originals stay as a safety net until SKMT is
 * deleted); tables, meta and the media folder taxonomy are RENAMED in place.
 * Renaming rather than copying is what makes the deletion of SKMT safe: its
 * uninstall.php drops the `skmt_*` tables, deletes the `_skmt_*` meta and every
 * term of `skmt_media_folder` — after the migration it finds none of them.
 *
 * Every step checks its own state and can run again: a migration stopped by a
 * failure resumes where it stopped at the next activation, without duplicating
 * anything. Each step is recorded in ERROR_OPTION BEFORE it runs, so that a
 * request killed inside it (fatal error, timeout, lock wait) still leaves a
 * trace; a step that returns false also gets ERROR_DETAIL_OPTION (the database
 * error, possibly ''). ERROR_OPTION without ERROR_DETAIL_OPTION therefore means
 * "interrupted" (see interrupted()). The marker is set last and clears both. On
 * failure SKMT stays active and Plugin keeps every module off (see on_hold()).
 *
 * Overwriting: a first run never overwrites an existing `lumia_*` option. A
 * resumed run (ERROR_OPTION set: failed or interrupted) refreshes the copies
 * from `skmt_*`: Lumia was on hold (or inactive) since that attempt, so nothing
 * of Lumia wrote them, while SKMT, still the live plugin, may have changed its
 * settings or secrets. Likewise a
 * `_skmt_*` meta written by SKMT on an object whose meta was already renamed
 * replaces the `_lumia_*` one instead of duplicating it.
 *
 * Only exact names from the closed lists below are touched: never a LIKE
 * pattern that could catch the keys of another plugin.
 *
 * This file is one of the few places where the legacy names may appear.
 */
final class FromSkmt {

	/**
	 * Main file of the former plugin, relative to the plugins folder, when it
	 * lives in its usual folder. Read legacy_plugin() instead.
	 */
	public const LEGACY_PLUGIN = 'studio-kyne-mini-tools/studio-kyne-mini-tools.php';

	/** Set when the migration is complete; value: timestamp. */
	public const MARKER = 'lumia_migrated_from_skmt';

	/**
	 * Step being run or at which the migration stopped (written before each
	 * step, deleted once the migration completes). Autoloaded, see on_hold().
	 */
	public const ERROR_OPTION = 'lumia_migration_error';

	/**
	 * Database error of the failed step ('' when the failure was not a query).
	 * Absent while ERROR_OPTION is set: the step was interrupted, not failed.
	 */
	public const ERROR_DETAIL_OPTION = 'lumia_migration_error_detail';

	/** Set when the migration completes, until an administrator gets the success notice. Autoloaded. */
	public const NOTICE_OPTION = 'lumia_migration_notice';

	/**
	 * Steps, in order. Tables and meta are renamed before SKMT is deactivated.
	 * `crons` comes before the meta: SKMT's bulk optimizer selects the images
	 * without `_skmt_optimized`; with that key renamed and its event still
	 * scheduled, a SKMT left active by a failure would re-encode every
	 * optimized image and keep the lossy result as the new "original".
	 */
	private const STEPS = [
		'options',
		'secrets',
		'crons',
		'tables',
		'activity_rows',
		'post_meta',
		'user_meta',
		'term_meta',
		'taxonomy',
		'originals',
		'deactivate',
	];

	/** Copied to `lumia_*`, never over an existing `lumia_*` option. */
	private const OPTIONS = [
		'skmt_settings',
		'skmt_module_image_optimizer',
		'skmt_module_security',
		'skmt_module_login',
		'skmt_module_files',
		'skmt_module_white_label',
		'skmt_module_menu_creator',
		'skmt_module_database',
		'skmt_module_media',
		'skmt_module_activity_log',
		'skmt_module_smtp',
		'skmt_module_image_optimizer_stats',
		'skmt_module_image_optimizer_bulk_state',
		'skmt_module_image_optimizer_backup_token',
		'skmt_wl_menu_profiles',
		'skmt_wl_menu_cache_gen',
		'skmt_activity_log_schema',
		'skmt_mail_log_schema',
	];

	/** Copied options that may store the admin page slug (menu profiles). */
	private const SLUG_OPTIONS = [ 'skmt_wl_menu_profiles', 'skmt_module_menu_creator' ];

	/**
	 * Other admin menu slugs of SKMT that a menu profile may store, mapped
	 * exactly (closed list): the separator SKMT added to its own submenu,
	 * which the MenuCreator editor lists as a child item of the plugin page.
	 */
	private const LEGACY_MENU_SLUGS = [ 'skmt-separator' => 'lumia-separator' ];

	/** Encrypted options: re-encrypted with the Lumia key while copied. */
	private const SECRETS = [ 'skmt_smtp_password', 'skmt_smtp_brevo_key' ];

	private const LEGACY_SLUG = 'studio-kyne-mini-tools';
	private const SLUG        = 'lumia-tools';

	private const TABLES = [ 'skmt_activity_log', 'skmt_mail_log' ];

	private const POST_META = [
		'_skmt_optimized',
		'_skmt_original_bytes',
		'_skmt_optimized_bytes',
		'_skmt_bytes_saved',
		'_skmt_main_original_bytes',
		'_skmt_main_optimized_bytes',
		'_skmt_main_bytes_saved',
		'_skmt_optimized_format',
		'_skmt_optimized_mime',
		'_skmt_backup_file',
		'_skmt_fallback_files',
	];

	private const USER_META = [ 'skmt_notices', 'skmt_local_avatar' ];

	private const TERM_META = [ 'skmt_folder_color' ];

	private const TAXONOMY = 'skmt_media_folder';

	/** Activity log rows written by the plugin about its own settings. */
	private const LEGACY_SETTINGS_EVENT = 'skmt_settings';
	private const LEGACY_OBJECT_TYPE    = 'skmt';

	/** The optimizer's bulk event is moved with its arguments and time; the purges are rescheduled by their module. */
	private const BULK_CRON = 'skmt_image_optimizer_cron';
	private const CRONS     = [ 'skmt_smtp_log_purge', 'skmt_activity_log_purge', self::BULK_CRON ];

	/** Activity log filter of the former plugin, see migrate_tables(). */
	private const LEGACY_RECORD_FILTER = 'skmt_activity_log_record';

	/** Namespace of SKMT's image optimizer classes, see freeze_legacy_optimizer(). */
	private const LEGACY_OPTIMIZER_NAMESPACE = 'StudioKyne\\MiniTools\\Modules\\ImageOptimizer\\';

	/**
	 * Every AJAX action of SKMT's image optimizer (8d4cd85): bulk scan, start and
	 * status, and the per-media actions, all of which can write files or meta.
	 */
	private const LEGACY_OPTIMIZER_AJAX = [
		'skmt_image_optimizer_bulk_scan',
		'skmt_image_optimizer_bulk',
		'skmt_image_optimizer_bulk_status',
		'skmt_image_optimizer_media_optimize',
		'skmt_image_optimizer_media_reoptimize',
		'skmt_image_optimizer_media_convert',
		'skmt_image_optimizer_media_regenerate',
		'skmt_image_optimizer_media_restore',
	];

	/** True while resuming a failed migration: the copies are refreshed from `skmt_*`. */
	private static bool $refresh = false;

	/**
	 * Whether there is something to migrate: SKMT has data and the migration
	 * has not completed yet.
	 */
	public static function needed(): bool {
		return false !== get_option( 'skmt_settings' ) && false === get_option( self::MARKER );
	}

	/**
	 * Whether Lumia must keep its modules off: SKMT is loaded in this request
	 * (both would hook the login URL, SMTP, white label…), or a migration
	 * stopped half-way (the modules would write `lumia_*` data the resumed
	 * migration must not find, and see half-renamed meta).
	 *
	 * To be called once all the plugins are loaded (`plugins_loaded`): Lumia
	 * loads before SKMT, alphabetically. ERROR_OPTION is autoloaded and read
	 * from the autoloaded options, so the healthy case costs no query.
	 */
	public static function on_hold(): bool {
		return self::legacy_loaded() || isset( wp_load_alloptions()[ self::ERROR_OPTION ] );
	}

	/**
	 * Whether SKMT is loaded in this request (meaningful from `plugins_loaded` on).
	 */
	public static function legacy_loaded(): bool {
		return defined( 'SKMT_VERSION' );
	}

	/**
	 * Basename of SKMT's main file (`folder/file.php`). SKMT may live in another
	 * folder than its usual one (a `-main` suffix left by a GitHub archive, a
	 * renamed folder): when it is loaded in this request, its own constant says
	 * where. SKMT is loaded iff it is active, so the fallback only serves when
	 * it is not, where the name does not matter.
	 */
	public static function legacy_plugin(): string {
		return defined( 'SKMT_PLUGIN_FILE' ) ? plugin_basename( (string) constant( 'SKMT_PLUGIN_FILE' ) ) : self::LEGACY_PLUGIN;
	}

	/**
	 * Whether SKMT's image optimizer must be frozen in this request: SKMT is
	 * loaded and a migration has started (failed attempt) or completed (SKMT
	 * re-enabled since). Its optimized images then no longer carry
	 * `_skmt_optimized`: SKMT would take them for new ones and re-encode them,
	 * through its bulk (the settings page restarts a running bulk on load), its
	 * per-media buttons or its upload filter, and keep the degraded file as the
	 * "original". To be called from `plugins_loaded` on.
	 */
	public static function legacy_optimizer_frozen(): bool {
		return self::legacy_loaded() && ( isset( wp_load_alloptions()[ self::ERROR_OPTION ] ) || false !== get_option( self::MARKER ) );
	}

	/**
	 * Hooked on `init` at PHP_INT_MAX, once SKMT's modules have hooked theirs:
	 * unhooks SKMT's optimizer from the attachment metadata filter (uploads,
	 * thumbnail regenerations), from its cron event and from its AJAX actions,
	 * answers those actions with an error instead, and drops a re-armed event.
	 * New uploads stay unprocessed until Lumia's optimizer takes over.
	 */
	public static function freeze_legacy_optimizer(): void {
		$hooks = [ 'wp_generate_attachment_metadata', self::BULK_CRON ];
		foreach ( self::LEGACY_OPTIMIZER_AJAX as $action ) {
			$hooks[] = 'wp_ajax_' . $action;
		}

		foreach ( $hooks as $hook ) {
			self::unhook_legacy_optimizer( $hook );
		}

		foreach ( self::LEGACY_OPTIMIZER_AJAX as $action ) {
			add_action( 'wp_ajax_' . $action, [ self::class, 'refuse_legacy_optimizer' ], 0 );
		}

		if ( self::has_cron_event( self::BULK_CRON ) ) {
			wp_unschedule_hook( self::BULK_CRON );
		}
	}

	/**
	 * Answer of the frozen SKMT optimizer AJAX actions (same shape as SKMT's own
	 * errors: the message as data).
	 */
	public static function refuse_legacy_optimizer(): void {
		wp_send_json_error( __( 'Image optimization is paused until the migration to Lümia Tools is complete.', 'lumia-tools' ) );
	}

	/**
	 * Step at which the last migration stopped, '' if none did.
	 */
	public static function failed_step(): string {
		return (string) get_option( self::ERROR_OPTION, '' );
	}

	/**
	 * Database error of the failed step, '' if none.
	 */
	public static function failure_detail(): string {
		return (string) get_option( self::ERROR_DETAIL_OPTION, '' );
	}

	/**
	 * Whether the last migration was interrupted inside failed_step() rather
	 * than stopped by a failure of it: the request ended before the step
	 * returned (PHP fatal error, timeout, killed process), see run().
	 */
	public static function interrupted(): bool {
		return '' !== self::failed_step() && false === get_option( self::ERROR_DETAIL_OPTION );
	}

	/**
	 * Forgets a failed attempt.
	 */
	public static function clear_error(): void {
		delete_option( self::ERROR_OPTION );
		delete_option( self::ERROR_DETAIL_OPTION );
	}

	/**
	 * Whether the success notice is still to be shown (autoloaded: no query).
	 */
	public static function has_pending_notice(): bool {
		return isset( wp_load_alloptions()[ self::NOTICE_OPTION ] );
	}

	/**
	 * Runs every step in order, stopping at the first failure.
	 *
	 * @return bool True when the marker is set and SKMT deactivated.
	 */
	public static function run(): bool {
		global $wpdb;

		// Renaming meta on a large media library takes time and memory: as much as
		// an admin request may get. A killed request is still detected (below),
		// but better avoided. A PHP-FPM request_terminate_timeout is not lifted by
		// set_time_limit(): hence the WP-CLI activation of the migration guide.
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 );
		}

		// A database error must not be printed: during an activation, any output
		// is reported as "unexpected output". It still reaches the PHP error log.
		$show_errors = $wpdb->hide_errors();

		// Resuming after a failed or interrupted attempt: SKMT's current values
		// win (see the class doc).
		self::$refresh = false !== get_option( self::ERROR_OPTION );

		try {
			foreach ( self::STEPS as $step ) {
				// Recorded BEFORE the step runs, without detail: if the request dies
				// inside it, Lumia is not even active (WordPress adds it to
				// active_plugins only once the activation hook returns), and this
				// option is the only trace left — `wp option get` shows the step,
				// and the next activation resumes in refresh mode.
				update_option( self::ERROR_OPTION, $step, true );
				delete_option( self::ERROR_DETAIL_OPTION );

				// last_error would otherwise still hold an error of an earlier,
				// unrelated query of the request. Each step checks it right after
				// its own queries (wpdb clears it at every query); not after the
				// step as a whole, which may run other plugins' hooks.
				$wpdb->last_error = '';

				if ( ! self::run_step( $step ) ) {
					update_option( self::ERROR_DETAIL_OPTION, (string) $wpdb->last_error, false );
					return false;
				}
			}

			update_option( self::MARKER, time(), false );
			self::clear_error();
			update_option( self::NOTICE_OPTION, 1, true );

			return true;
		} finally {
			if ( $show_errors ) {
				$wpdb->show_errors();
			}
		}
	}

	private static function run_step( string $step ): bool {
		global $wpdb;

		switch ( $step ) {
			case 'options':
				return self::migrate_options();
			case 'secrets':
				return self::migrate_secrets();
			case 'tables':
				return self::migrate_tables();
			case 'activity_rows':
				return self::migrate_activity_rows();
			case 'post_meta':
				return self::rename_meta( $wpdb->postmeta, 'post_id', 'post_meta', self::POST_META );
			case 'user_meta':
				return self::rename_meta( $wpdb->usermeta, 'user_id', 'user_meta', self::USER_META );
			case 'term_meta':
				return self::rename_meta( $wpdb->termmeta, 'term_id', 'term_meta', self::TERM_META );
			case 'taxonomy':
				return self::migrate_taxonomy();
			case 'originals':
				return self::migrate_originals();
			case 'crons':
				return self::migrate_crons();
			case 'deactivate':
				return self::deactivate_legacy();
		}

		return false;
	}

	/* ================================================================
	 * STEPS
	 * ================================================================ */

	private static function migrate_options(): bool {
		foreach ( self::OPTIONS as $legacy ) {
			$transform = in_array( $legacy, self::SLUG_OPTIONS, true ) ? [ self::class, 'rewrite_slugs' ] : null;

			if ( ! self::copy_option( $legacy, $transform ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The SMTP secrets are encrypted with a key derived from a context that
	 * changed with the name: re-encrypted on the way (see Crypto).
	 */
	private static function migrate_secrets(): bool {
		$reencrypt = static function ( $value ) {
			return is_string( $value ) ? Crypto::reencrypt_from_legacy( $value ) : $value;
		};

		foreach ( self::SECRETS as $legacy ) {
			if ( ! self::copy_option( $legacy, $reencrypt ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * RENAME TABLE when the target does not exist yet. When both exist — SKMT,
	 * left active after a failed attempt, re-created its table on its next write
	 * — the rows of the legacy table are appended to the Lumia one (new ids),
	 * then the legacy table is dropped.
	 */
	private static function migrate_tables(): bool {
		global $wpdb;

		foreach ( self::TABLES as $legacy ) {
			$from = $wpdb->prefix . $legacy;
			$to   = $wpdb->prefix . self::lumia_name( $legacy );

			if ( ! self::table_exists( $from ) ) {
				if ( ! self::db_ok() ) {
					return false;
				}
				continue;
			}

			if ( self::table_exists( $to ) ) {
				if ( ! self::merge_table( $from, $to ) ) {
					return false;
				}
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names built from $wpdb->prefix and constants only.
			$wpdb->query( "RENAME TABLE `{$from}` TO `{$to}`" );

			if ( ! self::db_ok() ) {
				return false;
			}
		}

		// SKMT is still loaded in this request (and stays so until the end of it,
		// even once deactivated). Its activity log writes the deactivation of SKMT
		// and the activation of Lumia after this point, and would re-create its
		// table to do so: those rows go to the renamed table instead.
		add_filter( self::LEGACY_RECORD_FILTER, [ self::class, 'redirect_legacy_log_row' ], PHP_INT_MAX );

		return true;
	}

	/**
	 * The plugin's own settings rows read `skmt_settings` / `skmt`: the activity
	 * log would no longer recognize them.
	 */
	private static function migrate_activity_rows(): bool {
		global $wpdb;

		$table = $wpdb->prefix . ActivityLogStore::TABLE;
		if ( ! self::table_exists( $table ) ) {
			return self::db_ok();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built from $wpdb->prefix and a constant.
		$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET event = %s WHERE event = %s", self::lumia_name( self::LEGACY_SETTINGS_EVENT ), self::LEGACY_SETTINGS_EVENT ) );
		if ( ! self::db_ok() ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- same.
		$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET object_type = %s WHERE object_type = %s", 'lumia', self::LEGACY_OBJECT_TYPE ) );

		return self::db_ok();
	}

	/**
	 * Renames meta keys in place, key by key, then drops the meta cache of every
	 * object involved (a persistent object cache would otherwise keep serving
	 * the old keys).
	 *
	 * @param string   $table       Meta table ($wpdb->postmeta…).
	 * @param string   $id_column   Object id column.
	 * @param string   $cache_group Meta cache group of that object type.
	 * @param string[] $keys        Legacy keys.
	 */
	private static function rename_meta( string $table, string $id_column, string $cache_group, array $keys ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table and column names; the keys are prepared.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT {$id_column} FROM {$table} WHERE meta_key IN (" . implode( ', ', array_fill( 0, count( $keys ), '%s' ) ) . ')', $keys ) );
		if ( ! self::db_ok() ) {
			return false;
		}

		$ok = true;
		foreach ( $keys as $legacy ) {
			// An object holding both keys: the `_skmt_*` one was written by SKMT after
			// a failed attempt renamed the first one, it is the current value. The
			// renamed copy goes, so that the rename below does not duplicate the key.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table and column names; exact keys prepared.
			$wpdb->query( $wpdb->prepare( "DELETE target FROM {$table} AS target INNER JOIN {$table} AS legacy ON legacy.{$id_column} = target.{$id_column} AND legacy.meta_key = %s WHERE target.meta_key = %s", $legacy, self::lumia_name( $legacy ) ) );
			if ( ! self::db_ok() ) {
				$ok = false;
				break;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table name; one exact key per query.
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET meta_key = %s WHERE meta_key = %s", self::lumia_name( $legacy ), $legacy ) );
			if ( ! self::db_ok() ) {
				$ok = false;
				break;
			}
		}

		// Also after a failure: some keys may already be renamed.
		foreach ( $ids as $id ) {
			wp_cache_delete( (int) $id, $cache_group );
		}

		return $ok;
	}

	/**
	 * Renames the media folder taxonomy in place: the terms, their ids, their
	 * meta and the media filed in them stay as they are.
	 */
	private static function migrate_taxonomy(): bool {
		global $wpdb;

		$target = self::lumia_name( self::TAXONOMY );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- no API renames a taxonomy.
		$tt_ids = $wpdb->get_col( $wpdb->prepare( "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", self::TAXONOMY ) );
		if ( ! self::db_ok() ) {
			return false;
		}

		if ( $tt_ids ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- same.
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->term_taxonomy} SET taxonomy = %s WHERE taxonomy = %s", $target, self::TAXONOMY ) );
			if ( ! self::db_ok() ) {
				return false;
			}

			// Without a taxonomy, the ids are term_taxonomy ids: the terms' cache
			// and the (new) taxonomy's lists and last_changed are cleared.
			clean_term_cache( array_map( 'intval', $tt_ids ), '', true );
		}

		// The old taxonomy's cached lists, and its hierarchy cache option (which
		// SKMT's uninstall.php leaves behind).
		clean_taxonomy_cache( self::TAXONOMY );
		delete_option( self::TAXONOMY . '_children' );

		return self::db_ok();
	}

	/**
	 * Renames uploads/skmt-originals-{token} to lumia-originals-{token}. A failed
	 * rename (permissions) is not a failure of the migration: get_backup_dir()
	 * keeps reading the old folder as long as the new one does not exist.
	 */
	private static function migrate_originals(): bool {
		$token = (string) get_option( self::lumia_name( 'skmt_module_image_optimizer_backup_token' ), '' );

		// The token ends up in a path: letters and digits only (wp_generate_password( 24, false )).
		if ( ! preg_match( '/^[A-Za-z0-9]+$/', $token ) ) {
			return true;
		}

		$base = trailingslashit( wp_upload_dir( null, false )['basedir'] );
		$from = $base . ImageOptimizer::LEGACY_BACKUP_DIR . '-' . $token;
		$to   = $base . ImageOptimizer::BACKUP_DIR . '-' . $token;

		if ( is_dir( $from ) && ! file_exists( $to ) ) {
			// Silenced: a warning printed during an activation is reported as unexpected output.
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- atomic on the same file system, unlike WP_Filesystem::move(); a failure is handled above.
			@rename( $from, $to );
		}

		return true;
	}

	/**
	 * Moves a pending bulk optimization event to the Lumia hook (same arguments,
	 * same time), then removes every legacy event. The log purges are scheduled
	 * again by their module's init().
	 */
	private static function migrate_crons(): bool {
		$target = self::lumia_name( self::BULK_CRON );
		$events = [];

		foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
			foreach ( (array) ( $hooks[ self::BULK_CRON ] ?? [] ) as $event ) {
				$events[] = [ (int) $timestamp, array_values( (array) ( $event['args'] ?? [] ) ) ];
			}
		}

		foreach ( $events as [ $timestamp, $args ] ) {
			if ( false !== wp_next_scheduled( $target, $args ) ) {
				continue;
			}

			if ( true !== wp_schedule_single_event( $timestamp, $target, $args, true ) ) {
				return false;
			}
		}

		foreach ( self::CRONS as $hook ) {
			if ( is_wp_error( wp_unschedule_hook( $hook, true ) ) || ! self::db_ok() ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Deactivates SKMT on this site, last: both plugins would otherwise hook the
	 * login URL, SMTP and white label. Not silent, so that SKMT runs its own
	 * deactivation routine.
	 */
	private static function deactivate_legacy(): bool {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$legacy = self::legacy_plugin();

		if ( in_array( $legacy, (array) get_option( 'active_plugins', [] ), true ) ) {
			deactivate_plugins( $legacy, false, false );
		}

		return ! in_array( $legacy, (array) get_option( 'active_plugins', [] ), true );
	}

	/* ================================================================
	 * HELPERS
	 * ================================================================ */

	/**
	 * Copies an option to its `lumia_*` name, with its autoload flag. Skipped when
	 * the legacy one is absent; when the target exists, skipped on a first run,
	 * refreshed on a resumed one.
	 *
	 * @param callable|null $transform Applied to the value before the copy.
	 */
	private static function copy_option( string $legacy, ?callable $transform = null ): bool {
		$target = self::lumia_name( $legacy );
		$value  = get_option( $legacy );

		if ( false === $value ) {
			return self::db_ok();
		}

		$exists = false !== get_option( $target );
		if ( $exists && ! self::$refresh ) {
			return self::db_ok();
		}

		if ( null !== $transform ) {
			$value = $transform( $value );
		}

		$autoload = self::autoload( $legacy );
		if ( ! self::db_ok() ) {
			return false;
		}

		if ( $exists ) {
			// false also when the value is unchanged: only a database error counts.
			update_option( $target, $value, $autoload );
			return self::db_ok();
		}

		$added = add_option( $target, $value, '', $autoload );

		return $added && self::db_ok();
	}

	/** Whether a stored option is autoloaded. */
	private static function autoload( string $option ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- no API exposes the autoload flag of one option.
		$flag = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $option ) );

		return in_array( $flag, wp_autoload_values_to_autoload(), true );
	}

	/**
	 * Replaces the admin page slug in stored menu profiles: any string equal to
	 * the legacy slug, or starting with it followed by `&` (a tab of the page),
	 * and any string equal to one of LEGACY_MENU_SLUGS.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	public static function rewrite_slugs( $value ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::rewrite_slugs( $item );
			}
			return $value;
		}

		if ( is_string( $value ) && ( self::LEGACY_SLUG === $value || 0 === strpos( $value, self::LEGACY_SLUG . '&' ) ) ) {
			return self::SLUG . substr( $value, strlen( self::LEGACY_SLUG ) );
		}

		if ( is_string( $value ) && isset( self::LEGACY_MENU_SLUGS[ $value ] ) ) {
			return self::LEGACY_MENU_SLUGS[ $value ];
		}

		return $value;
	}

	/**
	 * Hooked on SKMT's activity log filter once the tables are renamed: writes
	 * the row into the Lumia table and tells SKMT not to write it.
	 *
	 * @param mixed $row Row about to be written, or false.
	 * @return mixed
	 */
	public static function redirect_legacy_log_row( $row ) {
		global $wpdb;

		if ( ! is_array( $row ) || ! self::table_exists( $wpdb->prefix . ActivityLogStore::TABLE ) ) {
			return $row;
		}

		if ( self::LEGACY_SETTINGS_EVENT === ( $row['event'] ?? '' ) ) {
			$row['event'] = self::lumia_name( self::LEGACY_SETTINGS_EVENT );
		}
		if ( self::LEGACY_OBJECT_TYPE === ( $row['object_type'] ?? '' ) ) {
			$row['object_type'] = 'lumia';
		}

		ActivityLogStore::insert( $row );

		return false;
	}

	/**
	 * Appends the rows of $from to $to (columns both share, except the id),
	 * then drops $from.
	 */
	private static function merge_table( string $from, string $to ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names built from $wpdb->prefix and constants only.
		$source = $wpdb->get_col( "SHOW COLUMNS FROM `{$from}`" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- same.
		$target = $wpdb->get_col( "SHOW COLUMNS FROM `{$to}`" );
		if ( ! self::db_ok() ) {
			return false;
		}

		$columns = array_values(
			array_filter(
				array_intersect( $source, $target ),
				static function ( $column ): bool {
					return 'id' !== $column && 1 === preg_match( '/^[a-z0-9_]+$/', (string) $column );
				}
			)
		);
		if ( ! $columns ) {
			return false;
		}

		$list = '`' . implode( '`, `', $columns ) . '`';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from the schema, checked above.
		$wpdb->query( "INSERT INTO `{$to}` ({$list}) SELECT {$list} FROM `{$from}` ORDER BY id" );
		if ( ! self::db_ok() ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- same.
		$wpdb->query( "DROP TABLE `{$from}`" );

		return self::db_ok();
	}

	/**
	 * Removes from $hook every callback that is a method of one of SKMT's image
	 * optimizer classes.
	 */
	private static function unhook_legacy_optimizer( string $hook ): void {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $hook ] ) || ! $wp_filter[ $hook ] instanceof \WP_Hook ) {
			return;
		}

		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'];
				if ( is_array( $function ) && is_object( $function[0] ) && 0 === strpos( get_class( $function[0] ), self::LEGACY_OPTIMIZER_NAMESPACE ) ) {
					remove_filter( $hook, $function, $priority );
				}
			}
		}
	}

	/** Whether any event of $hook is scheduled, whatever its arguments. */
	private static function has_cron_event( string $hook ): bool {
		foreach ( (array) _get_cron_array() as $hooks ) {
			if ( ! empty( $hooks[ $hook ] ) ) {
				return true;
			}
		}

		return false;
	}

	private static function table_exists( string $table ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema read.
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/** `skmt_x` → `lumia_x`, `_skmt_x` → `_lumia_x`. */
	private static function lumia_name( string $legacy ): string {
		return (string) preg_replace( '/^(_?)skmt_/', '$1lumia_', $legacy );
	}

	/**
	 * Whether the last query succeeded.
	 *
	 * @phpstan-impure
	 */
	private static function db_ok(): bool {
		global $wpdb;

		return '' === $wpdb->last_error;
	}
}
