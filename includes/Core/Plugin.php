<?php
namespace Lumia\Tools\Core;

defined( 'ABSPATH' ) || exit;

use Lumia\Tools\Admin\Admin;

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
		// Load translations and register the modules on the init hook, to avoid
		// the just-in-time translation warning of WP 6.7+
		add_action( 'init', [ $this, 'on_init' ] );

		// Admin interface
		if ( is_admin() ) {
			new Admin( $this->modules, $this->settings );
		}

		// Updater
		$this->updater->init();
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
