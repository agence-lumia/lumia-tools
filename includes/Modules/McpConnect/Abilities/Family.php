<?php
namespace Lumia\Tools\Modules\McpConnect\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * A family of `lumia/*` abilities (see Registry).
 */
interface Family {

	/**
	 * Label of the family on the settings screen.
	 */
	public static function label(): string;

	/**
	 * One sentence on what the family lets Claude do.
	 */
	public static function description(): string;

	/**
	 * Whether the family can work on this site (its plugin is active).
	 */
	public static function available(): bool;

	/**
	 * Abilities of the family, by name without the `lumia/` prefix.
	 *
	 * Keys of a definition: `label`, `description` (what Claude reads to
	 * choose the tool), `input` (JSON Schema properties), `required`,
	 * `execute` (callable receiving the input array, returning a value or a
	 * WP_Error), `capability`, `readonly`, `destructive`, `idempotent`.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definitions(): array;
}
