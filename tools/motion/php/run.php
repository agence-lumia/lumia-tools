<?php
/**
 * Unit test runner for the Motion module (development only, never shipped).
 *
 * Usage: php tools/motion/php/run.php
 *
 * Runs every tools/motion/php/*Test.php. Each file returns an array of
 * `name => callable`; a callable fails by throwing (see lm_assert()). Exits 1
 * when at least one test fails.
 *
 * Pure PHP CLI, no WordPress: the classes under test must not call any
 * WordPress function.
 *
 * @package Lumia\Tools
 */

// This is a standalone CLI script, not plugin code: direct output and a global helper are the point.
// phpcs:disable WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals, WordPress.PHP.DevelopmentFunctions

declare( strict_types=1 );

define( 'ABSPATH', dirname( __DIR__, 3 ) . '/' );

require_once ABSPATH . 'includes/Core/Autoloader.php';
\Lumia\Tools\Core\Autoloader::register();

/**
 * Fails the running test when the condition is false.
 *
 * @param bool   $cond Condition expected to be true.
 * @param string $msg  Message reported on failure.
 *
 * @throws \RuntimeException When the condition is false.
 */
function lm_assert( bool $cond, string $msg ): void {
	if ( ! $cond ) {
		throw new \RuntimeException( $msg );
	}
}

$failures = 0;
$files    = glob( __DIR__ . '/*Test.php' );

foreach ( false === $files ? [] : $files as $file ) {
	$tests = require $file;

	foreach ( $tests as $name => $test ) {
		try {
			$test();
			echo "ok {$name}\n";
		} catch ( \Throwable $e ) {
			++$failures;
			echo "FAIL {$name}: {$e->getMessage()}\n";
		}
	}
}

exit( $failures > 0 ? 1 : 0 );
