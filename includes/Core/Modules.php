<?php
namespace Lumia\Tools\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Handles module registration and loading.
 */
class Modules {

	/**
	 * List of registered modules.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $registered = [];

	/**
	 * Instances of the active modules.
	 *
	 * @var array<string, ModuleInterface>
	 */
	private array $active = [];

	/**
	 * Settings manager.
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Registers the default modules through a filter plus an imperative hook.
	 * Must be called on the init hook or later (just-in-time i18n of WP 6.7+).
	 */
	public function register_default_modules( bool $only_active = false ): void {
		$defaults = [
			'image_optimizer' => [
				'name'        => __( 'Image Optimizer', 'lumia-tools' ),
				'description' => __( 'Image optimization', 'lumia-tools' ),
				'menu_label'  => __( 'Image Optimizer', 'lumia-tools' ),
				'menu_desc'   => __( 'Optimize images', 'lumia-tools' ),
				'class'       => 'Lumia\\Tools\\Modules\\ImageOptimizer\\Module',
				'icon'        => 'image',
			],
			'security'        => [
				'name'        => __( 'Security', 'lumia-tools' ),
				'description' => __( 'Authentication, hardening and security logging', 'lumia-tools' ),
				'menu_label'  => __( 'Security', 'lumia-tools' ),
				'menu_desc'   => __( 'Manage security', 'lumia-tools' ),
				'class'       => 'Lumia\\Tools\\Modules\\Security\\Module',
				'icon'        => 'shield',
			],
			'login'           => [
				'name'        => __( 'Login', 'lumia-tools' ),
				'description' => __( 'Customize the design and branding of the WordPress login page.', 'lumia-tools' ),
				'menu_label'  => __( 'Login', 'lumia-tools' ),
				'menu_desc'   => __( 'Customize the login page', 'lumia-tools' ),
				'class'       => 'Lumia\\Tools\\Modules\\Login\\Module',
				'icon'        => 'log-in',
			],
			'files'           => [
				'name'        => __( 'Files', 'lumia-tools' ),
				'description' => __( 'WordPress file explorer and manager.', 'lumia-tools' ),
				'menu_label'  => __( 'Files', 'lumia-tools' ),
				'menu_desc'   => __( 'Manage files', 'lumia-tools' ),
				'class'       => 'Lumia\\Tools\\Modules\\Files\\Module',
				'icon'        => 'folder',
			],
			'white_label'     => [
				'name'        => __( 'White label', 'lumia-tools' ),
				'description' => __( 'Customize the WordPress admin interface for your clients.', 'lumia-tools' ),
				'menu_label'  => __( 'White label', 'lumia-tools' ),
				'menu_desc'   => __( 'Customize the WP admin', 'lumia-tools' ),
				'class'       => 'Lumia\\Tools\\Modules\\WhiteLabel\\Module',
				'icon'        => 'palette',
			],
			'menu_creator'    => [
				'name'        => __( 'Menu creator', 'lumia-tools' ),
				'description' => __( 'Customize WordPress navigation for your roles and users.', 'lumia-tools' ),
				'menu_label'  => __( 'Menu creator', 'lumia-tools' ),
				'menu_desc'   => __( 'Customize menus', 'lumia-tools' ),
				'class'       => 'Lumia\\Tools\\Modules\\MenuCreator\\Module',
				'icon'        => 'menu',
			],
			'database'        => [
				'name'        => __( 'Database', 'lumia-tools' ),
				'description' => __( 'Explore, edit and export your WordPress tables.', 'lumia-tools' ),
				'menu_label'  => __( 'Database', 'lumia-tools' ),
				'menu_desc'   => __( 'Manage the database', 'lumia-tools' ),
				'class'       => 'Lumia\\Tools\\Modules\\Database\\Module',
				'icon'        => 'database',
			],
			'media'           => [
				'name'        => __( 'Media', 'lumia-tools' ),
				'description' => __( 'Organize your media into virtual folders.', 'lumia-tools' ),
				'menu_label'  => __( 'Media', 'lumia-tools' ),
				'menu_desc'   => __( 'Organize media', 'lumia-tools' ),
				'class'       => 'Lumia\\Tools\\Modules\\Media\\Module',
				'icon'        => 'folder-tree',
			],
			'activity_log'    => [
				'name'        => __( 'Activity log', 'lumia-tools' ),
				'description' => __( 'Who changed what, and when: logins, content, plugins, users and settings.', 'lumia-tools' ),
				'menu_label'  => __( 'Activity log', 'lumia-tools' ),
				'menu_desc'   => __( 'View the history', 'lumia-tools' ),
				'class'       => 'Lumia\\Tools\\Modules\\ActivityLog\\Module',
				'icon'        => 'history',
			],
			'smtp'            => [
				'name'        => __( 'SMTP', 'lumia-tools' ),
				'description' => __( 'Send emails through an authenticated SMTP server or the Brevo API, with a test email and an email log.', 'lumia-tools' ),
				'menu_label'  => __( 'SMTP', 'lumia-tools' ),
				'menu_desc'   => __( 'Configure email sending', 'lumia-tools' ),
				'class'       => 'Lumia\\Tools\\Modules\\Smtp\\Module',
				'icon'        => 'mail',
			],
		];

		/**
		 * Allows adding/overriding modules from other plugins/themes.
		 * Format: [ 'module_id' => [ 'name' => ..., 'class' => ..., ... ] ]
		 */
		$definitions = Compat::apply_filters( 'module_definitions', $defaults );

		if ( ! is_array( $definitions ) ) {
			$definitions = $defaults;
		}

		foreach ( $definitions as $id => $args ) {
			$id = sanitize_key( (string) $id );
			if ( '' === $id || ! is_array( $args ) ) {
				continue;
			}

			$args = $this->normalize_definition( $args );
			if ( empty( $args['class'] ) ) {
				continue;
			}

			if ( $only_active && ! $this->is_active( $id ) ) {
				continue;
			}

			$this->register( $id, $args );
		}

		/**
		 * Imperative hook to register modules through $modules->register(...).
		 */
		Compat::do_action( 'register_modules', $this, $only_active );
	}

	/**
	 * Normalizes a module definition.
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	private function normalize_definition( array $args ): array {
		$normalized = wp_parse_args(
			$args,
			[
				'name'        => '',
				'description' => '',
				'menu_label'  => '',
				'menu_desc'   => '',
				'class'       => '',
				'icon'        => 'package',
			]
		);

		$normalized['name']        = is_string( $normalized['name'] ) ? $normalized['name'] : '';
		$normalized['description'] = is_string( $normalized['description'] ) ? $normalized['description'] : '';
		$normalized['menu_label']  = is_string( $normalized['menu_label'] ) ? $normalized['menu_label'] : '';
		$normalized['menu_desc']   = is_string( $normalized['menu_desc'] ) ? $normalized['menu_desc'] : '';
		$normalized['class']       = is_string( $normalized['class'] ) ? ltrim( $normalized['class'], '\\' ) : '';
		$normalized['icon']        = is_string( $normalized['icon'] ) ? sanitize_key( $normalized['icon'] ) : 'package';

		return $normalized;
	}

	/**
	 * Registers a module.
	 *
	 * @param array<string, mixed> $args
	 */
	public function register( string $id, array $args ): void {
		$this->registered[ $id ] = wp_parse_args(
			$args,
			[
				'name'        => '',
				'description' => '',
				'menu_label'  => '',
				'menu_desc'   => '',
				'class'       => '',
				'icon'        => 'package',
			]
		);
	}

	/**
	 * Returns all the registered modules.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_all(): array {
		return $this->registered;
	}

	/**
	 * Returns a specific module.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get( string $id ): ?array {
		return $this->registered[ $id ] ?? null;
	}

	/**
	 * Checks whether a module is active.
	 */
	public function is_active( string $id ): bool {
		return (bool) $this->settings->get( "modules.{$id}", false );
	}

	/**
	 * Activates a module and calls its on_activate() hook.
	 *
	 * An already reached state is a success, not a failure: update_option()
	 * returns false when the value does not change, and relying on it made an
	 * error show up — without calling on_activate() — for an already active
	 * module.
	 */
	public function activate( string $id ): bool {
		if ( ! isset( $this->registered[ $id ] ) ) {
			return false;
		}

		if ( $this->is_active( $id ) ) {
			return true;
		}

		$result = $this->settings->set( "modules.{$id}", true );

		if ( $result ) {
			$instance = $this->make_instance( $id );
			if ( $instance ) {
				$instance->on_activate();
			}
		}

		return $result;
	}

	/**
	 * Deactivates a module and calls its on_deactivate() hook.
	 *
	 * See activate(): an already reached state is a success.
	 */
	public function deactivate( string $id ): bool {
		if ( ! isset( $this->registered[ $id ] ) ) {
			return false;
		}

		if ( ! $this->is_active( $id ) ) {
			return true;
		}

		// Use the active instance when available, otherwise create a temporary one.
		$instance = $this->active[ $id ] ?? $this->make_instance( $id );

		$result = $this->settings->set( "modules.{$id}", false );

		if ( $result && $instance ) {
			$instance->on_deactivate();
		}

		return $result;
	}

	/**
	 * Instantiates and initializes all the active modules.
	 */
	public function init_active_modules(): void {
		foreach ( $this->registered as $id => $module ) {
			if ( ! $this->is_active( $id ) ) {
				continue;
			}

			if ( ! class_exists( $module['class'] ) ) {
				continue;
			}

			$instance = new $module['class']( $id );

			if ( $instance instanceof ModuleInterface ) {
				$instance->init();
				$this->active[ $id ] = $instance;
			}
		}
	}

	/**
	 * Returns the instances of the active modules.
	 *
	 * @return array<string, ModuleInterface>
	 */
	public function get_active_instances(): array {
		return $this->active;
	}

	/**
	 * Returns an instance of a registered module, active or not.
	 *
	 * The active instance is reused when it exists; otherwise an uninitialized
	 * instance is created. Lets the settings schema of a deactivated module be
	 * read (configuration import) without hooking it into WordPress.
	 */
	public function get_instance( string $id ): ?ModuleInterface {
		return $this->active[ $id ] ?? $this->make_instance( $id );
	}

	/**
	 * Creates a module instance without initializing it (for the lifecycle hooks).
	 */
	private function make_instance( string $id ): ?ModuleInterface {
		$class = $this->registered[ $id ]['class'] ?? '';

		if ( empty( $class ) || ! class_exists( $class ) ) {
			return null;
		}

		$instance = new $class( $id );

		return $instance instanceof ModuleInterface ? $instance : null;
	}
}
