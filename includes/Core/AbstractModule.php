<?php
namespace Lumia\Tools\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Common base for the Lümia modules.
 *
 * Provides: option handling, empty admin assets by default, lifecycle
 * hooks and static methods for install/uninstall.
 */
abstract class AbstractModule implements ModuleInterface {

	/**
	 * Unique module identifier (e.g. "image_optimizer").
	 */
	protected string $id;

	/**
	 * Constructor: receives the module ID from the registry.
	 */
	public function __construct( string $id ) {
		$this->id = $id;
	}

	/* ================================================================
	 * OPTIONS
	 * ================================================================ */

	/**
	 * WordPress option key for this module.
	 */
	protected function get_module_option_key(): string {
		return 'lumia_module_' . $this->id;
	}

	/**
	 * Reads the module settings from the database, merged with the defaults.
	 *
	 * The merge is RECURSIVE. wp_parse_args() only merges the first level: as
	 * soon as a key exists in the database, its value replaces the default as a
	 * whole. For modules with nested settings (Security, Login, White label),
	 * any sub-key added in a later version would therefore be missing from
	 * existing installs until the user reopens the screen and saves again — a
	 * new setting whose default is true would silently become false for
	 * everyone.
	 *
	 * @param array<string, mixed> $defaults Default values to apply.
	 * @return array<string, mixed>
	 */
	protected function get_module_settings( array $defaults = [] ): array {
		$stored = get_option( $this->get_module_option_key(), [] );

		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		return empty( $defaults ) ? $stored : self::merge_defaults( $defaults, $stored );
	}

	/**
	 * Merges the stored settings over the default values.
	 *
	 * Only ASSOCIATIVE arrays are descended into: a list (allowed roles,
	 * whitelisted IPs…) must be replaced as a whole, never merged index by
	 * index — otherwise removing an entry would be impossible, the default
	 * value coming back at its position.
	 *
	 * @param array<string, mixed> $defaults Reference values.
	 * @param array<string, mixed> $stored   Values read from the database.
	 * @return array<string, mixed>
	 */
	protected static function merge_defaults( array $defaults, array $stored ): array {
		$merged = $defaults;

		foreach ( $stored as $key => $value ) {
			$default = $defaults[ $key ] ?? null;

			$merged[ $key ] = ( is_array( $value ) && is_array( $default ) && ! self::is_list( $default ) )
				? self::merge_defaults( $default, $value )
				: $value;
		}

		return $merged;
	}

	/**
	 * True for an array with consecutive numeric keys (or an empty one).
	 *
	 * @param array<mixed> $value
	 */
	private static function is_list( array $value ): bool {
		return [] === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * Saves the module settings to the database.
	 *
	 * @param array<string, mixed> $data
	 */
	protected function save_module_settings( array $data ): bool {
		return update_option( $this->get_module_option_key(), $data );
	}

	/**
	 * Converts STORED settings into a FORM payload.
	 *
	 * save_settings() is written for what the settings screen posts. For most
	 * modules this shape matches the stored one, and the identity is enough.
	 * When it differs — Security stores under authentication/hardening what the
	 * form sends flat — the module overrides this method.
	 *
	 * Used by the configuration import: an imported file must go through
	 * exactly the form's sanitization path, never a second one.
	 *
	 * @param array<string, mixed> $stored Settings as they are in the database.
	 * @return array<string, mixed>
	 */
	public function to_form_payload( array $stored ): array {
		return $stored;
	}

	/**
	 * Module data to attach to the configuration export, IN ADDITION to its
	 * `lumia_module_{id}` option.
	 *
	 * A module that keeps part of its state in an option of its own (Menu
	 * creator: the profiles under `lumia_wl_menu_profiles`) must declare it
	 * here, otherwise it is missing from the export JSON and the user believes
	 * they saved a complete configuration.
	 *
	 * @return array<string, mixed> Empty = nothing to export beyond the settings.
	 */
	public function get_export_extras(): array {
		return [];
	}

	/**
	 * Re-imports what get_export_extras() produced.
	 *
	 * Same rule as for the settings: the file content must never land in the
	 * database as is — it goes back through the module's sanitizer.
	 *
	 * @param array<string, mixed> $extras Block read from the imported file.
	 */
	public function import_extras( array $extras ): void {
		// Nothing by default.
	}

	/* ================================================================
	 * ASSETS (empty defaults)
	 * ================================================================ */

	public function get_admin_css(): array {
		return [];
	}

	public function get_admin_js(): array {
		return [];
	}

	/**
	 * Already registered script handles the module's JS depends on.
	 *
	 * Allows reusing a shared third-party library (SortableJS…) rather than
	 * returning its URL from get_admin_js(): two modules making that latter
	 * choice produce two different handles for the same file, which WordPress
	 * cannot deduplicate.
	 *
	 * @return string[]
	 */
	public function get_admin_js_deps(): array {
		return [];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_admin_js_data(): array {
		return [];
	}

	/* ================================================================
	 * LIFECYCLE
	 * ================================================================ */

	/**
	 * Called when the module is activated.
	 * Override to create tables, schedule crons, etc.
	 */
	public function on_activate(): void {}

	/**
	 * Called when the module is deactivated.
	 * Override to clean up crons, etc.
	 */
	public function on_deactivate(): void {}

	/* ================================================================
	 * REQUIRED CAPABILITY
	 * ================================================================ */

	/**
	 * Capability required to open the module screen and call its endpoints.
	 *
	 * `manage_options` by default, like the rest of the plugin. To be overridden
	 * by any module whose power goes beyond the current site: under multisite,
	 * `manage_options` is a PER-SITE capability, so the administrator of a
	 * mere sub-site gets it. A file manager or an SQL editor would then hand
	 * them the whole network — the files and the database are shared.
	 *
	 * The screens concerned therefore override it to `manage_network_options`,
	 * which only a super administrator holds (and which nobody holds outside
	 * multisite: hence the test).
	 */
	public static function get_required_capability(): string {
		return 'manage_options';
	}

	/* ================================================================
	 * INSTALL / UNINSTALL
	 * ================================================================ */

	/**
	 * Default values of the module options (created when the plugin is activated).
	 * Return [] if the defaults are handled on the fly in get_settings().
	 *
	 * @return array<string, mixed>
	 */
	public static function get_defaults(): array {
		return [];
	}

	/**
	 * Keys to delete on uninstall.
	 *
	 * `meta` designates post meta and `user_meta` user metadata: they are two
	 * distinct tables, and a key filed in the wrong one is never deleted.
	 *
	 * All the keys are optional: uninstall.php reads each with `?? []`.
	 * `post_type` and `taxonomy` trigger the deletion of the matching contents
	 * and terms. `tables` lists tables owned by the module, WITHOUT a prefix
	 * (dropped with DROP TABLE), `cron` lists scheduled task hooks — also
	 * unscheduled when the plugin is deactivated.
	 *
	 * @return array{options?: string[], meta?: string[], user_meta?: string[], post_type?: string[], taxonomy?: string[], tables?: string[], cron?: string[]}
	 */
	public static function get_uninstall_keys(): array {
		return [
			'options'   => [],
			'meta'      => [],
			'user_meta' => [],
		];
	}
}
