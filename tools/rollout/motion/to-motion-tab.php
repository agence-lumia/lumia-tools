<?php
/**
 * One-off: moves the Motion classes (sk-* and lm-*) of a site into the Motion tab of each element,
 * then deletes those classes. For the boilerplate (and new sites), never for client sites.
 *
 *   wp eval-file to-motion-tab.php --user=<admin>          dry run: writes nothing, lists what would change
 *   wp eval-file to-motion-tab.php apply --user=<admin>    writes, then `wp bricks regenerate_assets`
 *
 * Revisions, trash and auto-drafts are left alone. Stops without writing anything when a class
 * carries settings that are not Motion settings (styles would be lost), or when an element would
 * get two different effects.
 */

use Lumia\Tools\Modules\Motion\Legacy;

$apply = in_array( 'apply', (array) ( $args ?? [] ), true );

// Effect classes of the site: id => [ name, slug, motion settings of the class ].
$classes = (array) get_option( 'bricks_global_classes', [] );
$effect  = [];
$blocked = [];
foreach ( $classes as $c ) {
	$name = (string) ( $c['name'] ?? '' );
	$slug = Legacy::CLASS_ALIASES[ $name ] ?? ( 0 === strpos( $name, 'lm-' ) && in_array( substr( $name, 3 ), array_values( Legacy::CLASS_ALIASES ), true ) ? substr( $name, 3 ) : null );
	if ( null === $slug ) {
		continue;
	}
	$settings = (array) ( $c['settings'] ?? [] );
	$motion   = array_filter( $settings, static fn( $k ) => 0 === strpos( (string) $k, '_lm' ), ARRAY_FILTER_USE_KEY );
	$other    = array_diff_key( $settings, $motion );
	if ( $other ) {
		$blocked[] = "class $name carries non-Motion settings (" . implode( ', ', array_keys( $other ) ) . ')';
	}
	$effect[ $c['id'] ] = [ $name, $slug, $motion ];
}
$by_name = [];
foreach ( $effect as $id => $e ) {
	$by_name[ $e[0] ] = $id;
}

echo 'Effect classes: ', $effect ? implode( ', ', array_column( $effect, 0 ) ) : 'none', "\n";

global $wpdb;
$rows    = $wpdb->get_results(
	"SELECT m.meta_id, m.post_id, m.meta_key, m.meta_value, p.post_title, p.post_type FROM $wpdb->postmeta m
	 JOIN $wpdb->posts p ON p.ID = m.post_id
	 WHERE p.post_type <> 'revision' AND p.post_status NOT IN ('trash', 'auto-draft')
	 AND m.meta_key IN ('_bricks_page_content_2', '_bricks_page_header_2', '_bricks_page_footer_2')"
);
$changes = [];
$moved   = 0;
foreach ( $rows as $r ) {
	$elements = maybe_unserialize( $r->meta_value );
	if ( ! is_array( $elements ) ) {
		continue;
	}
	$dirty = false;
	foreach ( $elements as $i => $el ) {
		$s      = (array) ( $el['settings'] ?? [] );
		$global = array_values( array_map( 'strval', (array) ( $s['_cssGlobalClasses'] ?? [] ) ) );
		$typed  = preg_split( '/\s+/', trim( (string) ( $s['_cssClasses'] ?? '' ) ) ) ?: [];
		$found  = []; // [ class name, slug, motion settings ].
		foreach ( $global as $gid ) {
			if ( isset( $effect[ $gid ] ) ) {
				$found[] = $effect[ $gid ];
			}
		}
		foreach ( $typed as $t ) {
			$slug = Legacy::CLASS_ALIASES[ $t ] ?? ( 0 === strpos( $t, 'lm-' ) && in_array( substr( $t, 3 ), array_values( Legacy::CLASS_ALIASES ), true ) ? substr( $t, 3 ) : null );
			if ( null !== $slug ) {
				$found[] = isset( $by_name[ $t ] ) ? $effect[ $by_name[ $t ] ] : [ $t, $slug, [] ];
			}
		}
		if ( ! $found ) {
			continue;
		}
		$slugs = array_unique( array_merge( array_column( $found, 1 ), isset( $s['_lmEffect'] ) ? [ (string) $s['_lmEffect'] ] : [] ) );
		$where = "post {$r->post_id} \"{$r->post_title}\" element {$el['id']}";
		if ( count( $slugs ) > 1 ) {
			$blocked[] = "$where would get two effects (" . implode( ', ', $slugs ) . ')';
			continue;
		}
		// The element's own Motion settings win over the ones of its class.
		foreach ( $found as $f ) {
			$s = $s + $f[2];
		}
		$s['_lmEffect'] = $slugs[0];
		$names          = array_column( $found, 0 );
		$global         = array_values( array_diff( $global, array_keys( $effect ) ) );
		$typed          = array_values( array_diff( $typed, $names ) );
		if ( $global ) {
			$s['_cssGlobalClasses'] = $global;
		} else {
			unset( $s['_cssGlobalClasses'] );
		}
		if ( $typed && '' !== implode( '', $typed ) ) {
			$s['_cssClasses'] = implode( ' ', $typed );
		} else {
			unset( $s['_cssClasses'] );
		}
		$elements[ $i ]['settings'] = $s;
		$dirty                      = true;
		$moved++;
		$changes[] = "$where ({$el['name']}): " . implode( '+', $names ) . " -> Motion tab \"{$slugs[0]}\"";
	}
	if ( $dirty ) {
		$changes_by_meta[ $r->meta_id ] = [ $r->post_id, $r->meta_key, $elements ];
	}
}

echo implode( "\n", $changes ), $changes ? "\n" : '';
echo "$moved element(s) to move.\n";

// Classes still used where this script does not write (revisions are not counted: they keep a stale id, harmless).
$left = [];
foreach ( array_keys( Legacy::CLASS_ALIASES ) as $legacy ) {
	foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM $wpdb->options WHERE option_value LIKE %s AND option_name NOT IN ('bricks_global_classes', 'bricks_global_classes_locked', 'bricks_global_classes_changes') AND option_name NOT LIKE %s", '%' . $wpdb->esc_like( $legacy ) . '%', '%transient%' ) ) as $o ) {
		$left[] = "option $o mentions $legacy";
	}
}
// An effect class referenced outside page content (Bricks components, templates stored in options...)
// would keep a dangling ID once the class is deleted: its effect would be lost without a word.
foreach ( array_keys( $effect ) as $class_id ) {
	foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM $wpdb->options WHERE option_value LIKE %s AND option_name NOT IN ('bricks_global_classes', 'bricks_global_classes_locked', 'bricks_global_classes_changes', 'bricks_global_classes_trash') AND option_name NOT LIKE %s", '%"' . $wpdb->esc_like( (string) $class_id ) . '"%', '%transient%' ) ) as $o ) {
		$blocked[] = "option $o references the class {$effect[ $class_id ][0]} (id $class_id): move it by hand first";
	}
}
if ( $left ) {
	echo "To check by hand:\n  ", implode( "\n  ", $left ), "\n";
}

if ( $blocked ) {
	echo "STOP, nothing written:\n  ", implode( "\n  ", $blocked ), "\n";
	return;
}
if ( ! $apply ) {
	echo 'Dry run: nothing written. Classes that would be deleted: ', implode( ', ', array_column( $effect, 0 ) ) ?: 'none', "\n";
	return;
}

// Bricks refuses, without a word, to write page content for a user who cannot use the builder.
if ( ! current_user_can( 'manage_options' ) ) {
	echo "STOP, nothing written: run it with --user=<administrator>.
";
	return;
}
foreach ( $changes_by_meta ?? [] as [ $post_id, $key, $elements ] ) {
	update_post_meta( $post_id, $key, wp_slash( $elements ) );
	wp_cache_delete( $post_id, 'post_meta' );
	if ( get_post_meta( $post_id, $key, true ) !== $elements ) {
		echo "STOP: post $post_id $key was not written as expected; the classes are kept. Restore the backup if needed.
";
		return;
	}
}
$kept = array_values( array_filter( $classes, static fn( $c ) => ! isset( $effect[ $c['id'] ] ) ) );
\Bricks\Helpers::save_global_classes_in_db( $kept );
$locked = array_values( array_diff( array_map( 'strval', (array) get_option( 'bricks_global_classes_locked', [] ) ), array_keys( $effect ) ) );
update_option( 'bricks_global_classes_locked', $locked, false );
echo 'Applied: ', count( $changes_by_meta ?? [] ), ' content field(s) written, ', count( $effect ), " class(es) deleted.\n";
