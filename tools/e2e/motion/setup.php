<?php
/**
 * Creates or replaces the lm-e2e-* pages and their global classes, from the JSON written
 * by fixtures.mjs. Run by `run.sh setup` through `wp eval-file setup.php <json>`.
 * Local bench only, never shipped.
 */

if ( ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}

$lm_payload = json_decode( (string) file_get_contents( $args[0] ), true );
if ( ! is_array( $lm_payload ) ) {
	WP_CLI::error( 'Unreadable fixtures JSON.' );
}

// Global classes: by name, created when missing, settings replaced only when the fixtures give some.
$lm_classes = get_option( 'bricks_global_classes', array() );
$lm_classes = is_array( $lm_classes ) ? $lm_classes : array();
$lm_ids     = array();
foreach ( $lm_classes as $lm_class ) {
	$lm_ids[ $lm_class['name'] ] = $lm_class['id'];
}

$lm_wanted = array_keys( $lm_payload['classes'] );
foreach ( $lm_payload['pages'] as $lm_page ) {
	foreach ( $lm_page['elements'] as $lm_el ) {
		$lm_wanted = array_merge( $lm_wanted, $lm_el['classes'] );
	}
}

foreach ( array_unique( $lm_wanted ) as $lm_name ) {
	$lm_settings = $lm_payload['classes'][ $lm_name ] ?? null;
	if ( isset( $lm_ids[ $lm_name ] ) ) {
		if ( null !== $lm_settings ) {
			foreach ( $lm_classes as &$lm_class ) {
				if ( $lm_class['name'] === $lm_name ) {
					$lm_class['settings'] = $lm_settings;
				}
			}
			unset( $lm_class );
		}
		continue;
	}
	do {
		$lm_id = substr( str_shuffle( 'abcdefghijklmnopqrstuvwxyz' ), 0, 6 );
	} while ( in_array( $lm_id, $lm_ids, true ) );
	$lm_ids[ $lm_name ] = $lm_id;
	$lm_classes[]       = array(
		'id'       => $lm_id,
		'name'     => $lm_name,
		'settings' => $lm_settings ?? array(),
	);
}
update_option( 'bricks_global_classes', $lm_classes );

foreach ( $lm_payload['pages'] as $lm_page ) {
	$lm_elements = array();
	foreach ( $lm_page['elements'] as $lm_el ) {
		$lm_settings = (array) $lm_el['settings'];
		if ( $lm_el['classes'] ) {
			$lm_settings['_cssGlobalClasses'] = array_map(
				static function ( $name ) use ( $lm_ids ) {
					return $lm_ids[ $name ];
				},
				$lm_el['classes']
			);
		}
		$lm_elements[] = array(
			'id'       => $lm_el['id'],
			'name'     => $lm_el['name'],
			'parent'   => $lm_el['parent'],
			'children' => $lm_el['children'],
			'settings' => $lm_settings ? $lm_settings : array(),
		);
	}

	$lm_existing = get_page_by_path( $lm_page['slug'], OBJECT, $lm_page['post_type'] );
	$lm_post_id  = wp_insert_post(
		array(
			'ID'          => $lm_existing ? $lm_existing->ID : 0,
			'post_type'   => $lm_page['post_type'],
			'post_status' => 'publish',
			'post_title'  => $lm_page['title'],
			'post_name'   => $lm_page['slug'],
		),
		true
	);
	if ( is_wp_error( $lm_post_id ) ) {
		WP_CLI::error( $lm_post_id->get_error_message() );
	}
	update_post_meta( $lm_post_id, '_bricks_editor_mode', 'bricks' );
	update_post_meta( $lm_post_id, '_bricks_page_content_2', wp_slash( $lm_elements ) );
	foreach ( (array) $lm_page['meta'] as $lm_key => $lm_value ) {
		update_post_meta( $lm_post_id, $lm_key, wp_slash( $lm_value ) );
	}
	WP_CLI::log( sprintf( '%s #%d', $lm_page['slug'], $lm_post_id ) );
}
