<?php
namespace Lumia\Tools\Core;

defined( 'ABSPATH' ) || exit;

use Lumia\Tools\Admin\Admin;

/**
 * Classe principale du plugin.
 * Pattern Singleton pour garantir une seule instance.
 */
class Plugin {

	/**
	 * Instance unique du plugin.
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
	 * Updater GitHub.
	 */
	public Updater $updater;

	/**
	 * Constructeur privé (Singleton).
	 */
	private function __construct() {
		$this->settings = new Settings();
		$this->modules  = new Modules( $this->settings );
		$this->updater  = new Updater();

		$this->init();
	}

	/**
	 * Retourne l'instance unique du plugin.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Initialise le plugin.
	 */
	private function init(): void {
		// Chargement des traductions + enregistrement des modules
		// au hook init pour éviter le JIT warning de WP 6.7+
		add_action( 'init', [ $this, 'on_init' ] );

		// Interface admin
		if ( is_admin() ) {
			new Admin( $this->modules, $this->settings );
		}

		// Updater
		$this->updater->init();
	}

	/**
	 * Hook init : traductions, modules, et initialisation.
	 */
	public function on_init(): void {
		$this->load_textdomain();
		$this->modules->register_default_modules( ! is_admin() );
		$this->modules->init_active_modules();
	}

	/**
	 * Charge le domaine de traduction.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'lumia-tools',
			false,
			dirname( plugin_basename( LUMIA_PLUGIN_FILE ) ) . '/languages/'
		);
	}
}
