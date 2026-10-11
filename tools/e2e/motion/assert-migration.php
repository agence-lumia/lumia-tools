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
 *   expected-usage   JSON {posts, elements, unfiltered_posts}: what uses the legacy classes by ID, computed here
 *                    on real posts only (no revision, trash or auto-draft), and with no filter at all.
 *   snippet-status   status in the header of the former snippet file (published, draft, absent).
 *   noise-ids        IDs of the revision, trashed and auto-draft posts the seed made (not to be counted).
 *   usage-cached     JSON {classes} from ClassManager::legacy_usage_cached() (fills the transient).
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
			// A selector of a class that is renamed: stops matching at --apply.
			$lm_el( 'mgtext3', 'text-basic', 'mgcnt1', array(), array( 'text' => 'Selector', '_cssCustom' => '.sk-fade .note { color: red; }' ) ),
			// Nothing legacy.
			$lm_el( 'mgtext4', 'text-basic', 'mgcnt1', array(), array( 'text' => 'Plain', '_cssGlobalClasses' => array( $lm_id( 'nodrag' ) ) ) ),
			// An agency class that merely starts like the legacy ones: never reported.
			$lm_el( 'mgtext5', 'text-basic', 'mgcnt1', array(), array( 'text' => 'Agency class', '_cssCustom' => '.sk-container { padding: 0; } .sk-fade-note { color: red; }' ) ),
			// A selector of a class that stays (it collides with a class made by hand): keeps matching.
			$lm_el( 'mgtext6', 'text-basic', 'mgcnt1', array(), array( 'text' => 'Kept', '_cssCustom' => '.sk-counter .digit { color: red; }' ) ),
		);
		$lm_content[1]['children'][] = 'mgtext5';
		$lm_content[1]['children'][] = 'mgtext6';

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

		// Noise that must not be counted: a revision of the page (Bricks copies its meta to revisions),
		// a trashed page and an auto-draft, all carrying the same content.
		global $wpdb;
		// The revision goes straight into the table: wp_insert_post() does not create one for a revision type.
		$lm_made = $wpdb->insert(
			$wpdb->posts,
			array(
				'post_content'          => '',
				'post_excerpt'          => '',
				'post_content_filtered' => '',
				'to_ping'               => '',
				'pinged'                => '',
				'post_type'         => 'revision',
				'post_status'       => 'inherit',
				'post_parent'       => $lm_post,
				'post_name'         => $lm_post . '-revision-v99',
				'post_title'        => 'LM E2E migration noise',
				'post_date'         => current_time( 'mysql' ),
				'post_date_gmt'     => current_time( 'mysql', true ),
				'post_modified'     => current_time( 'mysql' ),
				'post_modified_gmt' => current_time( 'mysql', true ),
			)
		);
		if ( ! $lm_made ) {
			WP_CLI::error( 'The revision could not be inserted: ' . $wpdb->last_error );
		}
		$lm_noise_ids = array( (int) $wpdb->insert_id );
		foreach ( array( 'trash', 'auto-draft' ) as $lm_status ) {
			$lm_nid = wp_insert_post(
				array(
					'post_type'   => 'page',
					'post_status' => $lm_status,
					'post_title'  => 'LM E2E migration noise',
					'post_name'   => 'lm-e2e-migration-' . $lm_status,
				),
				true
			);
			if ( is_wp_error( $lm_nid ) ) {
				WP_CLI::error( $lm_nid->get_error_message() );
			}
			$lm_noise_ids[] = $lm_nid;
		}
		// The revision WordPress made itself, if any, carries the content too (Bricks copies its meta).
		foreach ( wp_get_post_revisions( $lm_post, array( 'fields' => 'ids' ) ) as $lm_rev ) {
			$lm_noise_ids[] = (int) $lm_rev;
		}
		foreach ( array_unique( $lm_noise_ids ) as $lm_nid ) {
			// update_metadata(), not update_post_meta(): the latter writes a revision's meta on its parent.
			update_metadata( 'post', $lm_nid, '_bricks_page_content_2', wp_slash( $lm_content ) );
			update_metadata( 'post', $lm_nid, '_lm_e2e_noise', 1 );
		}
		wp_cache_flush();
		WP_CLI::log( sprintf( 'seeded: %d classes, page #%d', count( $lm_classes ), $lm_post ) );
		break;

	case 'snippet-status':
		$lm_file = WP_CONTENT_DIR . '/fluent-snippet-storage/4-sk-motion.php';
		echo is_readable( $lm_file ) && preg_match( '/@status:[ \t]*(\w+)/', substr( (string) file_get_contents( $lm_file ), 0, 2048 ), $lm_m ) ? $lm_m[1] : 'absent', "\n";
		break;

	case 'noise-ids':
		global $wpdb;
		echo implode( ' ', $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_lm_e2e_noise'" ) ), "\n";
		break;

	case 'usage-cached':
		$lm_usage = \Lumia\Tools\Modules\Motion\ClassManager::legacy_usage_cached();
		echo wp_json_encode( array( 'classes' => array_values( $lm_usage['classes'] ) ) ), "\n";
		break;

	case 'expected-usage':
		global $wpdb;
		$lm_ids = array();
		foreach ( $lm_classes as $lm_class ) {
			if ( isset( $lm_pairs[ $lm_class['name'] ] ) ) {
				$lm_ids[] = $lm_class['id'];
			}
		}
		$lm_count = static function ( $elements ) use ( $lm_ids ) {
			$n = 0;
			foreach ( (array) $elements as $e ) {
				if ( is_array( $e ) && array_intersect( $lm_ids, (array) ( $e['settings']['_cssGlobalClasses'] ?? array() ) ) ) {
					++$n;
				}
			}
			return $n;
		};
		$lm_keys = array( '_bricks_page_content_2', '_bricks_page_header_2', '_bricks_page_footer_2' );

		// Real posts: every type but revisions, every status but trash, auto-draft and inherit.
		$lm_types    = array_diff( get_post_types(), array( 'revision' ) );
		$lm_statuses = array_diff( get_post_stati(), array( 'trash', 'auto-draft', 'inherit' ) );
		$lm_posts    = get_posts(
			array(
				'post_type'        => array_values( $lm_types ),
				'post_status'      => array_values( $lm_statuses ),
				'numberposts'      => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);
		$lm_real_posts = 0;
		$lm_elements   = 0;
		foreach ( $lm_posts as $lm_pid ) {
			$n = 0;
			foreach ( $lm_keys as $lm_key ) {
				$n += $lm_count( get_post_meta( $lm_pid, $lm_key, true ) );
			}
			if ( $n > 0 ) {
				++$lm_real_posts;
			}
			$lm_elements += $n;
		}
		foreach ( (array) get_option( 'bricks_components', array() ) as $lm_component ) {
			$lm_elements += $lm_count( $lm_component['elements'] ?? array() );
		}

		// Same count with no filter: what the scan would report if it counted every row.
		$lm_all = array();
		foreach ( $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ( '_bricks_page_content_2', '_bricks_page_header_2', '_bricks_page_footer_2' )" ) as $lm_row ) {
			if ( $lm_count( maybe_unserialize( $lm_row->meta_value ) ) > 0 ) {
				$lm_all[ $lm_row->post_id ] = true;
			}
		}
		echo wp_json_encode(
			array(
				'posts'            => $lm_real_posts,
				'elements'         => $lm_elements,
				'unfiltered_posts' => count( $lm_all ),
			)
		), "\n";
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
