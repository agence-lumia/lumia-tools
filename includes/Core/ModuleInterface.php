<?php
namespace Lumia\Tools\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Interface every module must implement.
 */
interface ModuleInterface {

	/**
	 * Initializes the module (hook registration).
	 */
	public function init(): void;

	/**
	 * Returns the module settings.
	 *
	 * @return array<string, mixed>
	 */
	public function get_settings(): array;

	/**
	 * Saves the module settings.
	 *
	 * @param array<string, mixed> $settings
	 */
	public function save_settings( array $settings ): bool;

	/**
	 * Returns the module's admin CSS URLs.
	 *
	 * @return string[]
	 */
	public function get_admin_css(): array;

	/**
	 * Returns the module's admin JS URLs.
	 *
	 * @return string[]
	 */
	public function get_admin_js(): array;

	/**
	 * Returns the JS data to inject into lumiaAdmin for this module.
	 * Typically: ['i18n' => ['key' => 'translated string', ...]]
	 *
	 * @return array<string, mixed>
	 */
	public function get_admin_js_data(): array;

	/**
	 * Script dependencies (WordPress handles) to load before the module's JS.
	 *
	 * @return string[]
	 */
	public function get_admin_js_deps(): array;

	/**
	 * Data to attach to the configuration export, in addition to the settings.
	 *
	 * @return array<string, mixed>
	 */
	public function get_export_extras(): array;

	/**
	 * Re-imports what get_export_extras() produced, after sanitizing.
	 *
	 * @param array<string, mixed> $extras Block read from the imported file.
	 */
	public function import_extras( array $extras ): void;

	/**
	 * Called when the module is activated.
	 */
	public function on_activate(): void;

	/**
	 * Called when the module is deactivated.
	 */
	public function on_deactivate(): void;
}
