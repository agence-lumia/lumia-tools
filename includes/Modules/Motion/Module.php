<?php
namespace Lumia\Tools\Modules\Motion;

defined( 'ABSPATH' ) || exit;

use Lumia\Tools\Core\AbstractModule;

/**
 * Motion module: entrance and scroll animations for Bricks, adjustable per element.
 *
 * The module only makes sense on a Bricks site. When Bricks is not the active
 * theme, init() hooks nothing and the settings screen explains why.
 */
class Module extends AbstractModule {

	/**
	 * Registers the WordPress hooks.
	 *
	 * Nothing is hooked without Bricks. The Bricks controls, the markup and the
	 * assets each have a class of their own, started from here as they land.
	 */
	public function init(): void {
		// `wp lumia motion classes|migrate`; the commands say so themselves when Bricks is not the theme.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'lumia motion', new Command() );
		}

		if ( ! self::is_bricks_active() ) {
			return;
		}

		$settings = $this->get_settings();

		( new Controls() )->hook();
		Marker::hook(
			! empty( $settings['legacy_classes'] ),
			LoadAnimation::config_from( SettingsSanitizer::sanitize( $settings, static::get_defaults() ) )
		);
		( new Assets( $settings ) )->hook();
	}

	/**
	 * Whether the active theme is Bricks.
	 *
	 * get_template() is the parent theme: a child theme of Bricks counts.
	 */
	public static function is_bricks_active(): bool {
		return 'bricks' === get_template();
	}

	/* ================================================================
	 * SETTINGS
	 * ================================================================ */

	/**
	 * Current settings, merged over the defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function get_settings(): array {
		return $this->get_module_settings( static::get_defaults() );
	}

	/**
	 * Validates and saves the settings.
	 *
	 * @param array<string, mixed> $settings Settings posted by the admin screen.
	 */
	public function save_settings( array $settings ): bool {
		return $this->save_module_settings( SettingsSanitizer::sanitize( $settings, static::get_defaults() ) );
	}

	/**
	 * Default values of the settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_defaults(): array {
		return [
			'effects'         => Effects::reference_values(),
			'tempo'           => 'normal',
			'ease'            => 'smooth',
			'ease_custom'     => '',
			'lenis'           => [
				'enabled' => true,
				'lerp'    => 0.1,
			],
			'legacy_classes'  => true,
			'builder_preview' => 'replay',
		];
	}

	/**
	 * Keys to delete on uninstall.
	 *
	 * The cached usage scan is a transient: its two rows are deleted as options, so they go even
	 * when an object cache does not hold them.
	 *
	 * @return array{options: string[], meta: string[]}
	 */
	public static function get_uninstall_keys(): array {
		return [
			'options' => [
				'lumia_module_motion',
				'_transient_' . ClassManager::USAGE_TRANSIENT,
				'_transient_timeout_' . ClassManager::USAGE_TRANSIENT,
			],
			'meta'    => [],
		];
	}

	/**
	 * CSS assets for the admin settings page.
	 *
	 * @return string[]
	 */
	public function get_admin_css(): array {
		return [ LUMIA_ASSETS_URL . 'admin/css/modules/motion.css' ];
	}
}
