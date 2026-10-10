<?php
/**
 * CLI half of the AvifEncoder assertions (task 3). Bench only, never shipped.
 *
 * The `cron` container (wordpress:cli image, the template's) has an Imagick that lists formats
 * it can neither read nor write: nothing may be encoded there (spec 9.1). `can_encode_here`
 * must say so, and must not borrow the answer cached by PHP-FPM.
 *
 *   tools/e2e-images/run.sh wp-cron nginx eval "$(tail -n +2 tools/e2e-images/assert-encoder-cli.php)"
 *
 * (`wp eval` takes code without the opening tag; the container sees the plugin through the
 * shared volume. Run `assert-encoder.php` first or after: the two share the object cache.)
 */

use Lumia\Tools\Modules\ImageOptimizer\ImageProcessor;

$caps = ( new ImageProcessor( array() ) )->get_capabilities();

$checks = array(
	'SAPI is cli'                                => 'cli' === PHP_SAPI,
	'can_encode_here is false in the CLI image'  => false === $caps['can_encode_here'],
	'avif_engine is not imagick in the CLI image' => 'imagick' !== $caps['avif_engine'],
);

$failures = 0;
foreach ( $checks as $label => $ok ) {
	printf( "[%s] %s\n", $ok ? 'PASS' : 'FAIL', $label );
	$failures += $ok ? 0 : 1;
}
printf( "(sapi=%s avif_engine=%s can_encode_here=%s heic_speed=%s heic_chroma=%s)\n", PHP_SAPI, var_export( $caps['avif_engine'], true ), var_export( $caps['can_encode_here'], true ), var_export( $caps['heic_speed'], true ), var_export( $caps['heic_chroma'], true ) );
printf( "%d check(s), %d failure(s)\n", count( $checks ), $failures );
if ( $failures > 0 ) {
	exit( 1 );
}
