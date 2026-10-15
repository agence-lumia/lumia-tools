<?php
/**
 * Tests for the Motion module declarations.
 *
 * @package Lumia\Tools
 */

// Development-only test file: it is not plugin code. Assertions read as
// "actual === expected", so Yoda conditions would only make them harder to read.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.PHP.YodaConditions

declare( strict_types=1 );

use Lumia\Tools\Modules\Motion\ClassManager;
use Lumia\Tools\Modules\Motion\Module;

return [
	'uninstall deletes the cached usage scan' => static function (): void {
		$options = Module::get_uninstall_keys()['options'] ?? [];
		lm_assert( in_array( 'lumia_module_motion', $options, true ), 'settings option' );
		lm_assert( in_array( '_transient_' . ClassManager::USAGE_TRANSIENT, $options, true ), 'transient row: ' . implode( ', ', $options ) );
		lm_assert( in_array( '_transient_timeout_' . ClassManager::USAGE_TRANSIENT, $options, true ), 'transient timeout row: ' . implode( ', ', $options ) );
	},
];
