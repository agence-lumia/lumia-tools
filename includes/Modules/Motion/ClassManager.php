<?php
namespace Lumia\Tools\Modules\Motion;

defined( 'ABSPATH' ) || exit;

/**
 * The Bricks global classes of the Motion module: creation of the `lm-*` classes and
 * migration of the classes of the former snippet.
 *
 * The planning, the CSS rewrite and the scans are pure functions (no WordPress call), tested
 * under tools/motion/php. The rest reads and writes the Bricks options, with
 * `\Bricks\Helpers::save_global_classes_in_db()`, which also stamps the sync timestamp the
 * builder watches.
 *
 * Every legacy name comes from the Legacy class: none is written here.
 */
final class ClassManager {

	/** Option of the global classes (BRICKS_DB_GLOBAL_CLASSES). */
	const OPTION_CLASSES = 'bricks_global_classes';

	/** Option of the class categories (BRICKS_DB_GLOBAL_CLASSES_CATEGORIES). */
	const OPTION_CATEGORIES = 'bricks_global_classes_categories';

	/** Option of the locked class IDs (BRICKS_DB_GLOBAL_CLASSES_LOCKED). */
	const OPTION_LOCKED = 'bricks_global_classes_locked';

	/** Option of the deleted classes, kept by Bricks for 30 days (BRICKS_DB_GLOBAL_CLASSES_TRASH). */
	const OPTION_TRASH = 'bricks_global_classes_trash';

	/** Option of the components (BRICKS_DB_COMPONENTS). */
	const OPTION_COMPONENTS = 'bricks_components';

	/** Option of the theme styles (BRICKS_DB_THEME_STYLES). */
	const OPTION_THEME_STYLES = 'bricks_theme_styles';

	/** Option of the global settings, which hold the global custom CSS. */
	const OPTION_SETTINGS = 'bricks_global_settings';

	/** Post meta of the page, header and footer content (BRICKS_DB_PAGE_CONTENT, _HEADER, _FOOTER). */
	const CONTENT_META_KEYS = [ '_bricks_page_content_2', '_bricks_page_header_2', '_bricks_page_footer_2' ];

	/** Post meta of the page settings (BRICKS_DB_PAGE_SETTINGS), which can hold a page custom CSS. */
	const PAGE_SETTINGS_META_KEY = '_bricks_page_settings';

	/** Name of the category that holds the Motion classes. */
	const CATEGORY_NAME = 'Motion';

	/** Directory of the FluentSnippets files, under wp-content. */
	const SNIPPET_DIR = 'fluent-snippet-storage';

	/** A snippet file larger than this is not read. */
	const SNIPPET_MAX_BYTES = 2097152;

	/** Post meta rows read per query by the usage scan. */
	const SCAN_BATCH = 50;

	/* ================================================================
	 * PURE PLANNING
	 * ================================================================ */

	/**
	 * Motion classes to create: absent, and whose legacy class is absent too.
	 *
	 * A class whose legacy twin exists is left to the migration, which renames the twin
	 * (same ID, so no element reference has to change).
	 *
	 * @param array<int|string, mixed> $classes Global classes (Bricks format).
	 * @return string[] Class names, in catalog order.
	 */
	public static function plan_creation( array $classes ): array {
		$names = array_flip( self::names( $classes ) );

		$legacy_of = [];
		foreach ( array_keys( Legacy::CLASS_ALIASES ) as $legacy ) {
			$new = Legacy::new_class_name( $legacy );
			if ( null !== $new ) {
				$legacy_of[ $new ] = $legacy;
			}
		}

		$plan = [];
		foreach ( Effects::catalog() as $definition ) {
			$name = $definition['class'];
			if ( isset( $names[ $name ] ) ) {
				continue;
			}
			if ( isset( $legacy_of[ $name ], $names[ $legacy_of[ $name ] ] ) ) {
				continue;
			}
			$plan[] = $name;
		}

		return $plan;
	}

	/**
	 * Legacy classes to rename, and those that cannot be.
	 *
	 * A class is renamed to its Motion name unless a class already has that name (made by hand,
	 * or a second class of the same legacy name): nothing is merged, the legacy name is reported.
	 *
	 * @param array<int|string, mixed> $classes Global classes (Bricks format).
	 * @return array{rename: array<string, array{from: string, to: string}>, conflicts: string[]} `rename` is keyed by class ID.
	 */
	public static function plan_migration( array $classes ): array {
		$taken     = array_flip( self::names( $classes ) );
		$rename    = [];
		$conflicts = [];

		foreach ( $classes as $class ) {
			if ( ! is_array( $class ) || ! isset( $class['id'], $class['name'] ) || ! is_string( $class['name'] ) ) {
				continue;
			}
			$from = $class['name'];
			$to   = Legacy::new_class_name( $from );
			if ( null === $to ) {
				continue;
			}
			if ( isset( $taken[ $to ] ) ) {
				if ( ! in_array( $from, $conflicts, true ) ) {
					$conflicts[] = $from;
				}
				continue;
			}
			// The target is taken from now on: a second class of the same legacy name collides with it.
			$taken[ $to ]                    = true;
			$rename[ (string) $class['id'] ] = [
				'from' => $from,
				'to'   => $to,
			];
		}

		return [
			'rename'    => $rename,
			'conflicts' => $conflicts,
		];
	}

	/**
	 * Replaces `.from` by `.to`, as a whole class name, in the custom CSS of a class.
	 *
	 * Every key that starts with `_cssCustom` is read (one per breakpoint). `.from-extra` is another
	 * class and stays as it is.
	 *
	 * @param array<string, mixed> $settings Settings of a global class.
	 * @param string               $from     Class name, without the dot.
	 * @param string               $to       New class name, without the dot.
	 * @return array<string, mixed>
	 */
	public static function rewrite_class_css( array $settings, string $from, string $to ): array {
		$pattern = '/\.' . preg_quote( $from, '/' ) . '(?![\w-])/';

		foreach ( $settings as $key => $value ) {
			if ( ! is_string( $value ) || 0 !== strpos( (string) $key, '_cssCustom' ) ) {
				continue;
			}
			$settings[ $key ] = preg_replace_callback(
				$pattern,
				static function () use ( $to ): string {
					return '.' . $to;
				},
				$value
			) ?? $value;
		}

		return $settings;
	}

	/**
	 * Whether some custom CSS of these settings still relies on a legacy name.
	 *
	 * Reads the `_cssCustom*` keys of an element or a class, the `customCss` key of a page, of the
	 * global settings and of a theme style, at any depth.
	 *
	 * @param array<int|string, mixed> $settings Settings (or any nested array of settings).
	 */
	public static function has_legacy_css( array $settings ): bool {
		foreach ( $settings as $key => $value ) {
			if ( is_array( $value ) ) {
				if ( self::has_legacy_css( $value ) ) {
					return true;
				}
				continue;
			}
			if ( ! is_string( $value ) || ! is_string( $key ) ) {
				continue;
			}
			if ( 'customCss' !== $key && 0 !== strpos( $key, '_cssCustom' ) ) {
				continue;
			}
			foreach ( Legacy::CSS_NEEDLES as $needle ) {
				if ( false !== strpos( $value, $needle ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Reads a list of Bricks elements for what still depends on legacy names.
	 *
	 * @param array<int|string, mixed> $elements     Elements (Bricks format).
	 * @param string[]                 $legacy_ids   IDs of the global classes to look for.
	 * @param string[]                 $legacy_names Class names to look for in the "CSS classes" field.
	 * @return array{by_id: string[], raw: string[], css: string[]} Element IDs that carry one of the classes by ID,
	 *                                                              that name one in their "CSS classes" field, and
	 *                                                              whose custom CSS uses a legacy name.
	 */
	public static function scan_elements( array $elements, array $legacy_ids, array $legacy_names ): array {
		$ids   = array_flip( array_map( 'strval', $legacy_ids ) );
		$names = array_flip( $legacy_names );
		$scan  = [
			'by_id' => [],
			'raw'   => [],
			'css'   => [],
		];

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) || ! isset( $element['id'] ) || ! is_array( $element['settings'] ?? null ) ) {
				continue;
			}
			$id       = (string) $element['id'];
			$settings = $element['settings'];

			$classes = $settings['_cssGlobalClasses'] ?? [];
			foreach ( is_array( $classes ) ? $classes : [] as $class_id ) {
				if ( is_string( $class_id ) && isset( $ids[ $class_id ] ) ) {
					$scan['by_id'][] = $id;
					break;
				}
			}

			$raw    = $settings['_cssClasses'] ?? '';
			$tokens = is_string( $raw ) ? preg_split( '/\s+/', $raw, -1, PREG_SPLIT_NO_EMPTY ) : [];
			foreach ( false === $tokens ? [] : $tokens as $token ) {
				if ( isset( $names[ $token ] ) ) {
					$scan['raw'][] = $id;
					break;
				}
			}

			if ( self::has_legacy_css( $settings ) ) {
				$scan['css'][] = $id;
			}
		}

		return $scan;
	}

	/**
	 * FluentSnippets files that still name the former snippet's classes, variables or global.
	 *
	 * Read only: a snippet is never modified.
	 *
	 * @param string $dir Directory of the snippet files.
	 * @return string[] One line per file: its path under wp-content and the names found.
	 */
	public static function snippet_hits( string $dir ): array {
		$files = is_dir( $dir ) ? glob( rtrim( $dir, '/' ) . '/*.php' ) : false;
		if ( ! is_array( $files ) ) {
			return [];
		}
		sort( $files );

		$hits = [];
		foreach ( $files as $file ) {
			$size = filesize( $file );
			if ( false === $size || $size > self::SNIPPET_MAX_BYTES ) {
				continue;
			}
			$source = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file, not a URL.
			if ( ! is_string( $source ) ) {
				continue;
			}

			$found = [];
			foreach ( array_keys( Legacy::CLASS_ALIASES ) as $name ) {
				// A whole name: a longer name that merely contains it must not match.
				if ( 1 === preg_match( '/(?<![\w-])' . preg_quote( $name, '/' ) . '(?![\w-])/', $source ) ) {
					$found[] = $name;
				}
			}
			foreach ( Legacy::SNIPPET_MARKERS as $marker ) {
				if ( false !== strpos( $source, $marker ) ) {
					$found[] = $marker;
				}
			}

			if ( [] !== $found ) {
				$hits[] = basename( $dir ) . '/' . basename( $file ) . ' (' . implode( ', ', $found ) . ')';
			}
		}

		return $hits;
	}

	/**
	 * The Motion category among the categories, added when missing.
	 *
	 * @param array<int|string, mixed> $categories Class categories (Bricks format).
	 * @return array{categories: array<int, array<string, mixed>>, id: string, created: bool}
	 */
	public static function with_category( array $categories ): array {
		$categories = array_values( array_filter( $categories, 'is_array' ) );
		$taken      = [];

		foreach ( $categories as $category ) {
			$id = (string) ( $category['id'] ?? '' );
			if ( '' !== $id ) {
				$taken[] = $id;
			}
			if ( is_string( $category['name'] ?? null ) && 0 === strcasecmp( $category['name'], self::CATEGORY_NAME ) && '' !== $id ) {
				return [
					'categories' => $categories,
					'id'         => $id,
					'created'    => false,
				];
			}
		}

		$id           = self::new_id( $taken );
		$categories[] = [
			'id'   => $id,
			'name' => self::CATEGORY_NAME,
		];

		return [
			'categories' => $categories,
			'id'         => $id,
			'created'    => true,
		];
	}

	/**
	 * A new Bricks ID: six lowercase letters, not in the given list.
	 *
	 * @param string[] $taken IDs already in use.
	 */
	public static function new_id( array $taken ): string {
		do {
			$id = '';
			for ( $i = 0; $i < 6; $i++ ) {
				$id .= chr( random_int( 97, 122 ) );
			}
		} while ( in_array( $id, $taken, true ) );

		return $id;
	}

	/* ================================================================
	 * CREATION
	 * ================================================================ */

	/**
	 * Creates the missing Motion classes: empty, locked, in the Motion category.
	 *
	 * Does nothing unless Bricks is the active theme. An existing class is never modified.
	 *
	 * @return string[] Names of the classes created.
	 * @throws \RuntimeException When Bricks did not keep the classes.
	 */
	public static function create_missing(): array {
		if ( ! Module::is_bricks_active() || ! class_exists( '\Bricks\Helpers' ) ) {
			return [];
		}

		$classes = self::read_classes();
		$names   = self::plan_creation( $classes );
		if ( [] === $names ) {
			return [];
		}

		$with  = self::with_category( self::read_option_list( self::OPTION_CATEGORIES ) );
		$taken = array_merge( self::ids( $classes ), self::ids( self::read_option_list( self::OPTION_TRASH ) ) );
		$added = [];

		foreach ( $names as $name ) {
			$id        = self::new_id( $taken );
			$taken[]   = $id;
			$added[]   = $id;
			$classes[] = [
				'id'       => $id,
				'name'     => $name,
				'settings' => [],
				'category' => $with['id'],
			];
		}

		// The category first: a class that names a category Bricks does not know would look uncategorized.
		if ( $with['created'] ) {
			update_option( self::OPTION_CATEGORIES, $with['categories'], false );
		}
		self::save_classes( $classes );

		$locked = self::read_option_list( self::OPTION_LOCKED );
		update_option( self::OPTION_LOCKED, array_values( array_unique( array_merge( array_map( 'strval', $locked ), $added ) ) ), false );

		return $names;
	}

	/* ================================================================
	 * MIGRATION
	 * ================================================================ */

	/**
	 * Renames the legacy classes to their Motion names and reports what still depends on the old ones.
	 *
	 * A dry run (`$apply` false) writes nothing. Applied, it renames each class in place (same ID,
	 * so every element follows), moves it to the Motion category, rewrites the legacy name in the
	 * class's own custom CSS, saves the classes and regenerates the Bricks CSS files. Run again, it
	 * finds nothing to rename and writes nothing.
	 *
	 * `elements` and `posts` count what uses a class that is renamed (by ID); `css_hits` lists what
	 * stays to be fixed by hand once the renames are done.
	 *
	 * @param bool $apply Whether to write.
	 * @return array{renamed: string[], conflicts: string[], elements: int, posts: int, css_hits: string[]}
	 *         `renamed` and `conflicts` hold legacy class names; in a dry run `renamed` is what would be renamed.
	 * @throws \RuntimeException When Bricks is not available, or did not keep the classes.
	 */
	public static function migrate( bool $apply ): array {
		$classes = self::read_classes();
		$plan    = self::plan_migration( $classes );
		$with    = self::with_category( self::read_option_list( self::OPTION_CATEGORIES ) );

		$next = $classes;
		foreach ( $next as $i => $class ) {
			$id = is_array( $class ) && isset( $class['id'] ) ? (string) $class['id'] : '';
			if ( '' === $id || ! isset( $plan['rename'][ $id ] ) ) {
				continue;
			}
			$rename = $plan['rename'][ $id ];

			$next[ $i ]['name']     = $rename['to'];
			$next[ $i ]['category'] = $with['id'];
			if ( is_array( $class['settings'] ?? null ) ) {
				$next[ $i ]['settings'] = self::rewrite_class_css( $class['settings'], $rename['from'], $rename['to'] );
			}
		}

		$renamed = array_values( array_column( $plan['rename'], 'from' ) );

		if ( $apply && [] !== $renamed ) {
			if ( ! class_exists( '\Bricks\Helpers' ) ) {
				throw new \RuntimeException( 'Bricks is not available: the classes cannot be saved.' );
			}
			if ( $with['created'] ) {
				update_option( self::OPTION_CATEGORIES, $with['categories'], false );
			}
			self::save_classes( $next );
			self::regenerate_assets();
		}

		// What is left to fix is read on the classes as they stand once renamed (also in a dry run).
		$survey = self::survey( $next, array_map( 'strval', array_keys( $plan['rename'] ) ), array_keys( Legacy::CLASS_ALIASES ), false );

		return [
			'renamed'   => $renamed,
			'conflicts' => $plan['conflicts'],
			'elements'  => $survey['elements'],
			'posts'     => $survey['posts'],
			'css_hits'  => $survey['css_hits'],
		];
	}

	/**
	 * What still uses a legacy class on this site.
	 *
	 * Reads the Bricks content (pages, headers, footers, templates), the components, the class
	 * settings, the global and theme custom CSS and the FluentSnippets files. Writes nothing.
	 *
	 * @return array{classes: array<string, string>, elements: int, posts: int, css_hits: string[]}
	 *         `classes` maps the ID of each class that carries a legacy name to that name; `elements` also counts
	 *         the elements that name a legacy class in their "CSS classes" field.
	 */
	public static function legacy_usage(): array {
		$classes = self::read_classes();
		$legacy  = [];
		foreach ( $classes as $class ) {
			if ( is_array( $class ) && isset( $class['id'], $class['name'] ) && is_string( $class['name'] ) && isset( Legacy::CLASS_ALIASES[ $class['name'] ] ) ) {
				$legacy[ (string) $class['id'] ] = $class['name'];
			}
		}

		$survey = self::survey( $classes, array_map( 'strval', array_keys( $legacy ) ), array_values( $legacy ), true );

		return [
			'classes'  => $legacy,
			'elements' => $survey['elements'],
			'posts'    => $survey['posts'],
			'css_hits' => $survey['css_hits'],
		];
	}

	/* ================================================================
	 * READING
	 * ================================================================ */

	/**
	 * Counts the usage of some classes and lists the custom CSS that names a legacy class.
	 *
	 * @param array<int|string, mixed> $classes      Global classes, as they should be read.
	 * @param string[]                 $ids          IDs of the classes whose usage is counted.
	 * @param string[]                 $names        Class names looked for in the "CSS classes" fields.
	 * @param bool                     $count_by_name Whether an element that names a class in that field is counted.
	 * @return array{elements: int, posts: int, css_hits: string[]}
	 */
	private static function survey( array $classes, array $ids, array $names, bool $count_by_name ): array {
		$elements = 0;
		$posts    = [];
		$hits     = [];

		foreach ( $classes as $class ) {
			if ( is_array( $class ) && is_array( $class['settings'] ?? null ) && self::has_legacy_css( $class['settings'] ) ) {
				$hits[] = sprintf( 'class %s', (string) ( $class['name'] ?? $class['id'] ?? '?' ) );
			}
		}

		$settings = get_option( self::OPTION_SETTINGS, [] );
		if ( is_array( $settings ) && is_string( $settings['customCss'] ?? null ) && self::has_legacy_css( [ 'customCss' => $settings['customCss'] ] ) ) {
			$hits[] = 'Bricks global custom CSS';
		}

		$themes = get_option( self::OPTION_THEME_STYLES, [] );
		if ( is_array( $themes ) ) {
			foreach ( $themes as $key => $style ) {
				if ( is_array( $style ) && self::has_legacy_css( $style ) ) {
					$hits[] = sprintf( 'theme style %s', is_string( $style['label'] ?? null ) ? $style['label'] : (string) $key );
				}
			}
		}

		foreach ( self::read_option_list( self::OPTION_COMPONENTS ) as $component ) {
			if ( ! is_array( $component ) || ! is_array( $component['elements'] ?? null ) ) {
				continue;
			}
			$scan      = self::scan_elements( $component['elements'], $ids, $names );
			$elements += self::used( $scan, $count_by_name );
			$label     = sprintf( 'component %s', (string) ( $component['label'] ?? $component['id'] ?? '?' ) );
			$hits      = array_merge( $hits, self::element_hits( $label, $scan ) );
		}

		foreach ( self::scan_posts( $ids, $names ) as $post_id => $row ) {
			$scan      = $row['scan'];
			$used      = self::used( $scan, $count_by_name );
			$elements += $used;
			if ( $used > 0 ) {
				$posts[ $post_id ] = true;
			}
			$label = sprintf( 'post %d "%s"', $post_id, get_the_title( $post_id ) );
			$hits  = array_merge( $hits, self::element_hits( $label, $scan ) );
			if ( $row['settings_css'] ) {
				$hits[] = $label . ' page settings';
			}
		}

		$hits = array_merge( $hits, self::snippet_hits( trailingslashit( WP_CONTENT_DIR ) . self::SNIPPET_DIR ) );

		return [
			'elements' => $elements,
			'posts'    => count( $posts ),
			'css_hits' => array_values( array_unique( $hits ) ),
		];
	}

	/**
	 * Number of elements of a scan that count as users of the classes.
	 *
	 * @param array{by_id: string[], raw: string[], css: string[]} $scan          Result of scan_elements().
	 * @param bool                                                 $count_by_name Whether the "CSS classes" field counts.
	 */
	private static function used( array $scan, bool $count_by_name ): int {
		$ids = $count_by_name ? array_merge( $scan['by_id'], $scan['raw'] ) : $scan['by_id'];

		return count( array_unique( $ids ) );
	}

	/**
	 * Report lines for the elements of a scan that need a manual fix.
	 *
	 * @param string                                               $label Where the elements are.
	 * @param array{by_id: string[], raw: string[], css: string[]} $scan  Result of scan_elements().
	 * @return string[]
	 */
	private static function element_hits( string $label, array $scan ): array {
		$hits = [];
		foreach ( $scan['raw'] as $id ) {
			$hits[] = sprintf( '%s element %s: legacy class in the CSS classes field', $label, $id );
		}
		foreach ( $scan['css'] as $id ) {
			$hits[] = sprintf( '%s element %s: legacy name in the custom CSS', $label, $id );
		}

		return $hits;
	}

	/**
	 * Scans the Bricks content meta of every post, a few rows at a time.
	 *
	 * Only the rows that mention a class ID, a legacy name or a CSS needle are decoded: the
	 * database does the filtering, PHP checks the exact matches.
	 *
	 * @param string[] $ids   IDs of the classes looked for.
	 * @param string[] $names Class names looked for in the "CSS classes" fields.
	 * @return array<int, array{scan: array{by_id: string[], raw: string[], css: string[]}, settings_css: bool}> By post ID.
	 */
	private static function scan_posts( array $ids, array $names ): array {
		global $wpdb;

		$terms = array_merge( array_map( 'strval', $ids ), $names, Legacy::CSS_NEEDLES );
		$like  = [];
		foreach ( array_unique( $terms ) as $term ) {
			if ( '' !== $term ) {
				$like[] = $wpdb->prepare( 'meta_value LIKE %s', '%' . $wpdb->esc_like( $term ) . '%' );
			}
		}
		if ( [] === $like ) {
			return [];
		}
		$where = implode( ' OR ', $like );

		$found = [];
		$keys  = array_merge( self::CONTENT_META_KEYS, [ self::PAGE_SETTINGS_META_KEY ] );
		foreach ( $keys as $key ) {
			$last = 0;
			do {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Reads the Bricks content in batches; $where was built with prepare() above.
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $where was built with prepare() above.
						"SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_id > %d AND ( {$where} ) ORDER BY meta_id ASC LIMIT %d",
						$key,
						$last,
						self::SCAN_BATCH
					)
				);
				foreach ( is_array( $rows ) ? $rows : [] as $row ) {
					$last    = (int) $row->meta_id;
					$post_id = (int) $row->post_id;
					$value   = maybe_unserialize( $row->meta_value );
					if ( ! is_array( $value ) ) {
						continue;
					}
					if ( ! isset( $found[ $post_id ] ) ) {
						$found[ $post_id ] = [
							'scan'         => [
								'by_id' => [],
								'raw'   => [],
								'css'   => [],
							],
							'settings_css' => false,
						];
					}
					if ( self::PAGE_SETTINGS_META_KEY === $key ) {
						$found[ $post_id ]['settings_css'] = self::has_legacy_css( $value );
						continue;
					}
					$scan = self::scan_elements( $value, $ids, $names );
					foreach ( $scan as $part => $list ) {
						$found[ $post_id ]['scan'][ $part ] = array_merge( $found[ $post_id ]['scan'][ $part ], $list );
					}
				}
				$batch = is_array( $rows ) ? count( $rows ) : 0;
			} while ( self::SCAN_BATCH === $batch );
		}

		return $found;
	}

	/* ================================================================
	 * OPTIONS
	 * ================================================================ */

	/**
	 * The global classes, as an array of entries.
	 *
	 * @return array<int|string, mixed>
	 */
	private static function read_classes(): array {
		return self::read_option_list( self::OPTION_CLASSES );
	}

	/**
	 * An option that holds a list, [] when it is missing or not an array.
	 *
	 * @param string $option Option name.
	 * @return array<int|string, mixed>
	 */
	private static function read_option_list( string $option ): array {
		$value = get_option( $option, [] );

		return is_array( $value ) ? $value : [];
	}

	/**
	 * Saves the global classes through Bricks and checks that they were kept.
	 *
	 * save_global_classes_in_db() also updates the timestamp and the user the builder compares to
	 * warn of a concurrent change. update_option() returns false for an unchanged value as well as
	 * for a failure, so the stored value is read back.
	 *
	 * @param array<int|string, mixed> $classes Every global class.
	 * @throws \RuntimeException When the stored classes differ from the given ones.
	 */
	private static function save_classes( array $classes ): void {
		\Bricks\Helpers::save_global_classes_in_db( $classes );

		if ( array_values( array_filter( $classes ) ) !== self::read_classes() ) {
			throw new \RuntimeException( 'Bricks did not keep the global classes: nothing else was changed.' );
		}
	}

	/**
	 * Regenerates the Bricks CSS files (a no-op with inline styles) when run by WP-CLI.
	 *
	 * The command runs in this process, so it keeps the current user. Its output (one line per file)
	 * is captured: the caller may be printing JSON.
	 */
	private static function regenerate_assets(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		$problem = '';
		try {
			$result = \WP_CLI::runcommand(
				'bricks regenerate_assets',
				[
					'launch'     => false,
					'exit_error' => false,
					'return'     => 'all',
				]
			);
			if ( ! is_object( $result ) ) {
				$problem = 'no result';
			} elseif ( 0 !== (int) ( $result->return_code ?? 1 ) ) {
				$problem = trim( (string) ( $result->stderr ?? '' ) );
			}
		} catch ( \Throwable $e ) {
			$problem = $e->getMessage();
		}

		if ( '' !== $problem ) {
			\WP_CLI::warning( 'The Bricks CSS files were not regenerated (' . $problem . '). Run: wp bricks regenerate_assets' );
		}
	}

	/**
	 * Names of the classes of a list.
	 *
	 * @param array<int|string, mixed> $classes Global classes.
	 * @return string[]
	 */
	private static function names( array $classes ): array {
		$names = [];
		foreach ( $classes as $class ) {
			if ( is_array( $class ) && is_string( $class['name'] ?? null ) ) {
				$names[] = $class['name'];
			} elseif ( is_string( $class ) ) {
				$names[] = $class;
			}
		}

		return $names;
	}

	/**
	 * IDs of the entries of a list.
	 *
	 * @param array<int|string, mixed> $entries Classes or categories.
	 * @return string[]
	 */
	private static function ids( array $entries ): array {
		$ids = [];
		foreach ( $entries as $entry ) {
			if ( is_array( $entry ) && isset( $entry['id'] ) ) {
				$ids[] = (string) $entry['id'];
			}
		}

		return $ids;
	}
}
