<?php
namespace Lumia\Tools\Core;

defined( 'ABSPATH' ) || exit;

use Lumia\Tools\Admin\Admin;
use Lumia\Tools\Core\Migration\FromSkmt;

/**
 * Main plugin class.
 * Singleton pattern to guarantee a single instance.
 */
class Plugin {

	/**
	 * Unique plugin instance.
	 */
	private static ?Plugin $instance = null;

	/**
	 * Modules manager.
	 */
	public Modules $modules;

	/**
	 * Settings manager.
	 */
	public Settings $settings;

	/**
	 * GitHub updater.
	 */
	public Updater $updater;

	/**
	 * Private constructor (Singleton).
	 */
	private function __construct() {
		$this->settings = new Settings();
		$this->modules  = new Modules( $this->settings );
		$this->updater  = new Updater();

		$this->init();
	}

	/**
	 * Returns the unique plugin instance.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Initializes the plugin.
	 */
	private function init(): void {
		// Updater: also on hold, a fixed version must remain installable.
		$this->updater->init();

		// Studio Kyne Mini Tools still active, or its migration stopped half-way:
		// no module, no admin screen (a module enabled from it would create the
		// `lumia_*` options the migration must still copy), only a notice.
		// Evaluated here, on plugins_loaded: lumia-tools/ loads before
		// studio-kyne-mini-tools/, its constants do not exist yet when this
		// plugin's main file runs.
		if ( FromSkmt::on_hold() ) {
			add_action( 'init', [ $this, 'load_textdomain' ] );
			// all_admin_notices, not admin_notices: SKMT, active, buffers every
			// admin_notices output into its notification drawer.
			add_action( 'all_admin_notices', [ $this, 'render_hold_notice' ] );

			// SKMT's optimizer would re-encode the images whose meta is migrated.
			if ( FromSkmt::legacy_optimizer_frozen() ) {
				add_action( 'init', [ FromSkmt::class, 'freeze_legacy_optimizer' ], PHP_INT_MAX );
			}
			return;
		}

		// Load translations and register the modules on the init hook, to avoid
		// the just-in-time translation warning of WP 6.7+
		add_action( 'init', [ $this, 'on_init' ] );

		// Admin interface
		if ( is_admin() ) {
			new Admin( $this->modules, $this->settings );
			add_action( 'admin_init', [ $this, 'announce_migration' ] );
		}
	}

	/**
	 * Init hook: translations, modules and initialization.
	 */
	public function on_init(): void {
		$this->load_textdomain();
		$this->modules->register_default_modules( ! is_admin() );
		$this->modules->init_active_modules();
	}

	/**
	 * Notice shown while the plugin is on hold (see FromSkmt::on_hold()).
	 */
	public function render_hold_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$step = FromSkmt::failed_step();

		if ( '' !== $step ) {
			$message = sprintf(
				/* translators: %s: identifier of the migration step that failed, e.g. post_meta. */
				esc_html__( 'The migration from Studio Kyne Mini Tools stopped at the %s step: Lümia Tools loads none of its modules until it is complete. Do not delete Studio Kyne Mini Tools before then: its uninstallation would erase the data not migrated yet. Deactivate then reactivate Lümia Tools to resume it.', 'lumia-tools' ),
				'<code>' . esc_html( $step ) . '</code>'
			);

			$detail = FromSkmt::failure_detail();
			if ( '' !== $detail ) {
				$message .= '<br>' . sprintf(
					/* translators: %s: database error message. */
					esc_html__( 'Database error: %s', 'lumia-tools' ),
					'<code>' . esc_html( $detail ) . '</code>'
				);
			}

			wp_admin_notice(
				$message,
				[
					'type' => 'error',
					'id'   => 'lumia-migration-notice',
				]
			);
			return;
		}

		wp_admin_notice(
			esc_html__( 'Studio Kyne Mini Tools is still active: Lümia Tools loads none of its modules until it is deactivated.', 'lumia-tools' ),
			[
				'type' => 'warning',
				'id'   => 'lumia-migration-notice',
			]
		);
	}

	/**
	 * First admin page after a completed migration: persistent success notice
	 * for the administrator who sees it.
	 */
	public function announce_migration(): void {
		if ( ! FromSkmt::has_pending_notice() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		Admin::add_persistent_notice(
			FromSkmt::MARKER,
			__( 'Migration from Studio Kyne Mini Tools complete. You can delete the old plugin.', 'lumia-tools' ),
			'success'
		);
		delete_option( FromSkmt::NOTICE_OPTION );
	}

	/**
	 * Loads the translation text domain.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'lumia-tools',
			false,
			dirname( plugin_basename( LUMIA_PLUGIN_FILE ) ) . '/languages/'
		);
	}
}
