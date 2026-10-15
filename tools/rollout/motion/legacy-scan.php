<?php
// Legacy class names in Bricks content outside the classes fields (interactions, attributes, conditions...).
$names = [ 'sk-fade', 'sk-reveal', 'sk-entry-blur', 'sk-scale-in', 'sk-scale-in-blur', 'sk-text-lines', 'sk-text-highlight-words', 'sk-text-highlight-chars', 'sk-counter', 'sk-hover-underline' ];
global $wpdb;
$hits = 0;
$walk = function ( $v, $path, $where ) use ( &$walk, $names, &$hits ) {
	if ( is_array( $v ) ) { foreach ( $v as $k => $x ) $walk( $x, "$path.$k", $where ); return; }
	if ( ! is_string( $v ) ) return;
	foreach ( $names as $n ) if ( preg_match( '/(?<![\w-])' . preg_quote( $n, '/' ) . '(?![\w-])/', $v ) ) {
		if ( preg_match( '/\._cssGlobalClasses\.|\._cssClasses$/', $path ) ) continue;
		$hits++; echo "$where $path: ", mb_substr( preg_replace( '/\s+/', ' ', $v ), 0, 90 ), "\n"; break;
	}
};
foreach ( $wpdb->get_results( "SELECT m.post_id, m.meta_key, m.meta_value, p.post_title FROM $wpdb->postmeta m JOIN $wpdb->posts p ON p.ID=m.post_id WHERE p.post_type<>'revision' AND p.post_status NOT IN ('trash','auto-draft') AND m.meta_key LIKE '_bricks_%'" ) as $r ) {
	$walk( maybe_unserialize( $r->meta_value ), '', "post {$r->post_id} \"{$r->post_title}\" {$r->meta_key}" );
}
foreach ( [ 'bricks_global_settings', 'bricks_components' ] as $o ) $walk( get_option( $o ), '', "option $o" );
echo "$hits hit(s)\n";
