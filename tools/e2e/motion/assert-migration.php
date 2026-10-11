<?php
/**
 * Helper of assert-migration.sh, run through `wp eval-file assert-migration.php <mode> [arg]`.
 * Local bench only, never shipped.
 *
 * Modes:
 *   seed             put the bench in the state of a site that still has the former classes:
 *                    the ten legacy classes (same IDs as the site's when they exist), none of the
 *                    new ones, no Motion category, plus the page lm-e2e-migration.
 *   add-class <name> add an empty global class named <name> (a "made by hand" class); prints its ID.
 *   remove-class <name>  delete the global classes named <name>.
 *   on-activate      what turning the module on does: Module::on_activate().
 *   without-bricks   the same while the active theme is not Bricks: must create nothing.
 *   snapshot         JSON: classes, categories, locked IDs, sync timestamp.
 *   checksum         "<options md5> <bricks post meta md5>" (transients and cron excluded).
 */

if ( ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}

$lm_mode = $args[0] ?? '';

// Legacy class => class that replaces it. Spelled out here on purpose: the bench must not
// rely on the code under test for what it expects.
$lm_pairs = array(
	'sk-fade'                 => 'lm-fade',
	'sk-reveal'               => 'lm-reveal',
	'sk-entry-blur'           => 'lm-blur-in',
	'sk-scale-in'             => 'lm-scale-in',
	'sk-scale-in-blur'        => 'lm-scale-blur-in',
	'sk-text-lines'           => 'lm-text-lines',
	'sk-text-highlight-words' => 'lm-text-words',
	'sk-text-highlight-chars' => 'lm-text-chars',
	'sk-counter'              => 'lm-counter',
	'sk-hover-underline'      => 'lm-underline',
);

$lm_classes    = get_option( 'bricks_global_classes', array() );
$lm_classes    = is_array( $lm_classes ) ? $lm_classes : array();
$lm_categories = get_option( 'bricks_global_classes_categories', array() );
$lm_categories = is_array( $lm_categories ) ? $lm_categories : array();

/** Saves the classes the way the bench needs them (no Bricks session, so the option directly). */
function lm_mig_save( array $classes ) {
	update_option( 'bricks_global_classes', array_values( $classes ) );
}

switch ( $lm_mode ) {
	case 'seed':
		// The category the former classes live in, when the site has one (the bench's ANIMATIONS).
		$lm_home = '';
		foreach ( $lm_categories as $lm_cat ) {
			if ( 0 === strcasecmp( (string) $lm_cat['name'], 'animations' ) ) {
				$lm_home = $lm_cat['id'];
			}
		}

		$lm_drop = array();
		$lm_keep = array();
		foreach ( $lm_pairs as $lm_old => $lm_new ) {
			$lm_found = array();
			foreach ( $lm_classes as $lm_i => $lm_class ) {
				if ( in_array( $lm_class['name'], array( $lm_old, $lm_new ), true ) ) {
					$lm_found[] = $lm_i;
				}
			}
			// Keep the class that belongs to a category (the site's own), then the legacy-named one.
			usort(
				$lm_found,
				static function ( $a, $b ) use ( $lm_classes, $lm_old ) {
					$score = static function ( $c ) use ( $lm_old ) {
						return ( empty( $c['category'] ) ? 0 : 2 ) + ( $c['name'] === $lm_old ? 1 : 0 );
					};
					return $score( $lm_classes[ $b ] ) <=> $score( $lm_classes[ $a ] );
				}
			);
			$lm_first = array_shift( $lm_found );
			foreach ( $lm_found as $lm_i ) {
				$lm_drop[] = $lm_i;
			}
			if ( null === $lm_first ) {
				$lm_classes[] = array(
					'id'       => 'sd' . substr( md5( $lm_old ), 0, 4 ),
					'name'     => $lm_old,
					'settings' => array(),
				);
				$lm_first     = count( $lm_classes ) - 1;
			}
			$lm_classes[ $lm_first ]['name'] = $lm_old;
			if ( $lm_home ) {
				$lm_classes[ $lm_first ]['category'] = $lm_home;
			}
			$lm_keep[ $lm_old ] = $lm_first;
		}
		foreach ( $lm_drop as $lm_i ) {
			unset( $lm_classes[ $lm_i ] );
		}
		$lm_classes = array_values( $lm_classes );

		// CSS that names the class itself: rewritten by the migration (the class is renamed), except the longer name.
		foreach ( $lm_classes as $lm_i => $lm_class ) {
			if ( 'sk-text-lines' === $lm_class['name'] ) {
				$lm_classes[ $lm_i ]['settings'] = array( '_cssCustom' => ".sk-text-lines .line { overflow: hidden; }\n.sk-text-lines-extra { color: red; }" );
			}
			if ( 'sk-hover-underline' === $lm_class['name'] ) {
				$lm_classes[ $lm_i ]['settings'] = array( '_cssCustom:tablet_portrait' => '.sk-hover-underline { --sk-hover-underline-gap: 4px; }' );
			}
		}
		lm_mig_save( $lm_classes );

		// No Motion category: the migration has to make it.
		$lm_categories = array_values(
			array_filter(
				$lm_categories,
				static function ( $c ) {
					return 0 !== strcasecmp( (string) $c['name'], 'motion' );
				}
			)
		);
		update_option( 'bricks_global_classes_categories', $lm_categories, false );

		$lm_locked = get_option( 'bricks_global_classes_locked', array() );
		$lm_locked = is_array( $lm_locked ) ? $lm_locked : array();
		foreach ( $lm_classes as $lm_class ) {
			if ( isset( $lm_pairs[ $lm_class['name'] ] ) ) {
				$lm_locked[] = $lm_class['id'];
			}
		}
		update_option( 'bricks_global_classes_locked', array_values( array_unique( $lm_locked ) ), false );

		// The page: a few elements on the legacy classes, and the places the migration reports.
		$lm_id = function ( $name ) use ( $lm_classes ) {
			foreach ( $lm_classes as $c ) {
				if ( $c['name'] === $name ) {
					return $c['id'];
				}
			}
			WP_CLI::error( "No class $name after the seed." );
		};
		$lm_el = static function ( $id, $name, $parent, $children, $settings ) {
			return array(
				'id'       => $id,
				'name'     => $name,
				'parent'   => $parent,
				'children' => $children,
				'settings' => $settings,
			);
		};

		$lm_content = array(
			$lm_el( 'mgsec1', 'section', 0, array( 'mgcnt1' ), array() ),
			$lm_el( 'mgcnt1', 'container', 'mgsec1', array( 'mghead1', 'mghead2', 'mgtext1', 'mgtext2', 'mgtext3', 'mgtext4' ), array() ),
			// Two classes by ID.
			$lm_el( 'mghead1', 'heading', 'mgcnt1', array(), array( 'text' => 'Fade and blur', '_cssGlobalClasses' => array( $lm_id( 'sk-fade' ), $lm_id( 'sk-entry-blur' ) ) ) ),
			// One renamed, one kept (counter collides with a class made by hand).
			$lm_el( 'mghead2', 'heading', 'mgcnt1', array(), array( 'text' => 'Scale and counter', '_cssGlobalClasses' => array( $lm_id( 'sk-scale-in' ), $lm_id( 'sk-counter' ) ) ) ),
			// Underline, with a variable in the element's own CSS: reported, never rewritten.
			$lm_el( 'mgtext1', 'text-basic', 'mgcnt1', array(), array( 'text' => 'Underlined', '_cssGlobalClasses' => array( $lm_id( 'sk-hover-underline' ) ), '_cssCustom' => '#brxe-mgtext1 { --sk-hover-underline-gap: 3px; }' ) ),
			// A class typed by hand in the "CSS classes" field: not an ID, reported.
			$lm_el( 'mgtext2', 'text-basic', 'mgcnt1', array(), array( 'text' => 'Typed by hand', '_cssClasses' => 'sk-reveal card' ) ),
			// A selector in the element's own CSS: reported.
			$lm_el( 'mgtext3', 'text-basic', 'mgcnt1', array(), array( 'text' => 'Selector', '_cssCustom' => '.sk-fade-note { color: red; }' ) ),
			// Nothing legacy.
			$lm_el( 'mgtext4', 'text-basic', 'mgcnt1', array(), array( 'text' => 'Plain', '_cssGlobalClasses' => array( $lm_id( 'nodrag' ) ) ) ),
		);

		$lm_existing = get_page_by_path( 'lm-e2e-migration', OBJECT, 'page' );
		$lm_post     = wp_insert_post(
			array(
				'ID'          => $lm_existing ? $lm_existing->ID : 0,
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'LM E2E migration',
				'post_name'   => 'lm-e2e-migration',
			),
			true
		);
		if ( is_wp_error( $lm_post ) ) {
			WP_CLI::error( $lm_post->get_error_message() );
		}
		update_post_meta( $lm_post, '_bricks_editor_mode', 'bricks' );
		update_post_meta( $lm_post, '_bricks_page_content_2', wp_slash( $lm_content ) );
		wp_cache_flush();
		WP_CLI::log( sprintf( 'seeded: %d classes, page #%d', count( $lm_classes ), $lm_post ) );
		break;

	case 'add-class':
		$lm_name = $args[1] ?? '';
		if ( '' === $lm_name ) {
			WP_CLI::error( 'add-class needs a name.' );
		}
		$lm_ids = array_column( $lm_classes, 'id' );
		do {
			$lm_new = substr( str_shuffle( 'abcdefghijklmnopqrstuvwxyz' ), 0, 6 );
		} while ( in_array( $lm_new, $lm_ids, true ) );
		$lm_classes[] = array(
			'id'       => $lm_new,
			'name'     => $lm_name,
			'settings' => array( '_lmDuration' => 2 ),
		);
		lm_mig_save( $lm_classes );
		echo $lm_new, "\n";
		break;

	case 'remove-class':
		$lm_name = $args[1] ?? '';
		lm_mig_save(
			array_filter(
				$lm_classes,
				static function ( $c ) use ( $lm_name ) {
					return $c['name'] !== $lm_name;
				}
			)
		);
		break;

	case 'on-activate':
		( new \Lumia\Tools\Modules\Motion\Module( 'motion' ) )->on_activate();
		break;

	case 'without-bricks':
		// A theme that is not Bricks, and an empty class list that would make every class missing.
		add_filter(
			'template',
			static function () {
				return 'twentytwentyfour';
			}
		);
		add_filter(
			'pre_option_bricks_global_classes',
			static function () {
				return array();
			}
		);
		$lm_made = \Lumia\Tools\Modules\Motion\ClassManager::create_missing();
		( new \Lumia\Tools\Modules\Motion\Module( 'motion' ) )->on_activate();
		echo wp_json_encode( $lm_made ), "\n";
		break;

	case 'snapshot':
		echo wp_json_encode(
			array(
				'classes'    => $lm_classes,
				'categories' => $lm_categories,
				'locked'     => get_option( 'bricks_global_classes_locked', array() ),
				'timestamp'  => get_option( 'bricks_global_classes_timestamp', null ),
			)
		), "\n";
		break;

	case 'checksum':
		global $wpdb;
		$lm_options = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name NOT LIKE '\_transient%' AND option_name NOT LIKE '\_site\_transient%' AND option_name <> 'cron' ORDER BY option_name" );
		$lm_meta    = $wpdb->get_results( "SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_bricks%' ORDER BY meta_id" );
		echo md5( serialize( $lm_options ) ), ' ', md5( serialize( $lm_meta ) ), "\n";
		break;

	default:
		WP_CLI::error( 'Unknown mode.' );
}
