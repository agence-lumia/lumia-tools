<?php
/**
 * One line per site, read-only: Lümia version, theme, sk-/lm- classes, their uses (no revisions), snippets naming the former engine.
 */
$names = [];
foreach ( (array) get_option( 'bricks_global_classes', [] ) as $c ) if ( preg_match( '/^(sk|lm)-/', $c['name'] ?? '' ) ) $names[ $c['id'] ] = $c['name'];
global $wpdb; $uses = 0; $posts = [];
foreach ( $wpdb->get_results( "SELECT m.post_id, m.meta_value FROM $wpdb->postmeta m JOIN $wpdb->posts p ON p.ID=m.post_id WHERE p.post_type NOT IN ('revision') AND p.post_status NOT IN ('trash','auto-draft') AND m.meta_key LIKE '_bricks_page_%_2'" ) as $r ) {
  foreach ( (array) maybe_unserialize( $r->meta_value ) as $e ) {
    $s = $e['settings'] ?? []; $hit = array_intersect( array_keys( $names ), (array) ( $s['_cssGlobalClasses'] ?? [] ) ) || preg_match( '/\b(sk|lm)-/', (string) ( $s['_cssClasses'] ?? '' ) );
    if ( $hit ) { $uses++; $posts[ $r->post_id ] = 1; }
  }
}
$snip = ''; $dir = WP_CONTENT_DIR . '/fluent-snippet-storage';
foreach ( (array) glob( "$dir/*.php" ) as $f ) { $t = file_get_contents( $f ); if ( strpos( $t, 'StudioKyneMotion' ) !== false || strpos( $t, 'sk-motion' ) !== false ) { preg_match( '/@status:\s*(\w+)/', $t, $m ); $snip .= basename( $f ) . '=' . ( $m[1] ?? '?' ) . ' '; } }
$v = get_plugins()['lumia-tools/lumia-tools.php']['Version'] ?? '-';
printf( "%s | lumia %s | theme %s | classes %d (%s) | uses %d on %d posts | snippet %s\n", parse_url( home_url(), PHP_URL_HOST ), $v, get_template(), count( $names ), implode( ',', array_unique( array_map( fn( $n ) => substr( $n, 0, 3 ), $names ) ) ), $uses, count( $posts ), $snip ?: 'none' );
