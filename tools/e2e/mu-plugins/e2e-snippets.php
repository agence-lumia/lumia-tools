<?php
/**
 * Plugin Name: E2E snippets (bench only)
 *
 * TEST BENCH ONLY. Loads the *.php files that a check drops into
 * wp-content/e2e-snippets/ (a writable folder of the bench volume), the way a
 * site loads a FluentSnippets or theme snippet. `assert-compat` uses it to define
 * legacy SKMT_* constants and hook legacy skmt_* filters, then deletes the file.
 * Like e2e-auth.php, it lives in tools/e2e/ and must never be shipped.
 */

defined( 'ABSPATH' ) || exit;

foreach ( (array) glob( WP_CONTENT_DIR . '/e2e-snippets/*.php' ) as $e2e_snippet ) {
	require $e2e_snippet;
}
unset( $e2e_snippet );
