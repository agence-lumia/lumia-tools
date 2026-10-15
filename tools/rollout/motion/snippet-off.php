<?php
/**
 * Puts the SK Motion FluentSnippets snippet (the one that loads @studiokyne/sk-motion) in draft and rebuilds the snippet index
 * (updateSnippet() alone leaves it in the published list of index.php: it keeps loading).
 * Prints "draft <file>", "absent", or "STOP ..." when another snippet uses the former engine.
 * With the argument "check": only that last test, nothing written ("ok" or "STOP ...").
 */
$check = in_array( 'check', (array) ( $args ?? [] ), true );
$dir = WP_CONTENT_DIR . '/fluent-snippet-storage';
$sk  = [];
$use = [];
foreach ( (array) glob( "$dir/*.php" ) as $f ) {
	if ( 'index.php' === basename( $f ) ) {
		continue;
	}
	$t = (string) file_get_contents( $f );
	// The engine snippet loads the former library (its name differs between sites).
	if ( preg_match( '#@studiokyne/sk-motion|sk-motion(\.full)?(\.iife)?(\.min)?\.js#i', $t ) ) {
		$sk[] = basename( $f );
	} elseif ( preg_match( '/@status:\s*published/i', $t ) && false !== strpos( $t, 'StudioKyneMotion' ) ) {
		$use[] = basename( $f );
	}
}
if ( $use ) {
	echo 'STOP: published snippet(s) use the former engine (window.StudioKyneMotion): ', implode( ', ', $use ), "\n";
	return;
}
if ( $check ) {
	echo $sk ? 'ok (SK Motion: ' . implode( ', ', $sk ) . ")\n" : "ok (no SK Motion snippet)\n";
	return;
}
if ( ! $sk ) {
	echo "absent\n";
	return;
}
$m = new \FluentSnippets\App\Model\Snippet();
foreach ( $sk as $file ) {
	$s                   = $m->findByFileName( $file );
	$s['meta']['status'] = 'draft';
	$m->updateSnippet( $file, $s['code'], $s['meta'] );
	echo "draft $file\n";
}
\FluentSnippets\App\Helpers\Helper::cacheSnippetIndex();
$index = (string) file_get_contents( "$dir/index.php" );
foreach ( $sk as $file ) {
	if ( ! preg_match( "/'draft'\s*=>\s*array\s*\([^;]*'" . preg_quote( $file, '/' ) . "'/s", $index ) ) {
		echo "STOP: $file is not in the draft list of index.php\n";
	}
}
