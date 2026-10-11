<?php
namespace Lumia\Tools\Modules\Motion;

defined( 'ABSPATH' ) || exit;

/**
 * Markup of the animated elements: the `data-lm` attribute and the inline overrides.
 *
 * `data-lm` lists the effect families of an element (`enter`, `text`, `counter`,
 * `underline`), taken from its own Motion settings and from those of its global classes,
 * for every breakpoint. It exists for the stylesheet (pre-hiding) and to pick the scripts
 * to load; the engine reads the real effect from the computed `--lm-effect`.
 *
 * Elements whose selector is weaker than a class selector (components, global elements,
 * query loop items) also get their own Motion settings as an inline `style`, which beats
 * any rule: without it a class preset would override the element.
 *
 * The pure parts (families, inline_style) call no WordPress function except through
 * Controls::definitions(), so tools/motion/php/MarkerTest.php runs them with stand-ins.
 */
final class Marker {

	/** Order of the families in `data-lm`. */
	const FAMILY_ORDER = [
		Effects::FAMILY_ENTER,
		Effects::FAMILY_TEXT,
		Effects::FAMILY_COUNTER,
		Effects::FAMILY_UNDERLINE,
	];

	/** Characters allowed in an inline value: nothing that could close the declaration or the attribute. */
	const VALUE_PATTERN = '/^[A-Za-z0-9%#(),.\- ]+$/';

	/** Setting key of the effect, with its breakpoint variants (`_lmEffect:mobile_portrait`). */
	const EFFECT_KEY = '_lmEffect';

	/**
	 * Families seen while rendering the current request.
	 *
	 * @var array<string, true>
	 */
	private static array $seen = [];

	/**
	 * Global classes of the site, by ID, read once per request.
	 *
	 * @var array<string, array{name: string, settings: array<string, mixed>}>|null
	 */
	private static ?array $class_index = null;

	/** Whether the legacy class names count (module setting). */
	private static bool $legacy = true;

	/**
	 * Registers the filter that marks the elements.
	 *
	 * @param bool $legacy Whether the legacy class names also count.
	 */
	public static function hook( bool $legacy = true ): void {
		self::$legacy = $legacy;

		add_filter( 'bricks/element/render_attributes', [ self::class, 'filter_attributes' ], 10, 3 );
	}

	/**
	 * Families of the elements rendered so far in this request, in the fixed order.
	 *
	 * @return string[]
	 */
	public static function page_families(): array {
		return array_values( array_intersect( self::FAMILY_ORDER, array_keys( self::$seen ) ) );
	}

	/**
	 * Filter callback: adds `data-lm`, and the inline overrides where a class would win.
	 *
	 * @param mixed $attributes Attributes of the element, by key.
	 * @param mixed $key        Attribute set being rendered; only `_root` is marked.
	 * @param mixed $element    The Bricks element.
	 * @return mixed
	 */
	public static function filter_attributes( $attributes, $key, $element ) {
		if ( '_root' !== $key || ! is_array( $attributes ) || ! isset( $attributes['_root'] ) || ! is_array( $attributes['_root'] ) ) {
			return $attributes;
		}

		if ( ! $element instanceof \Bricks\Element || ! is_array( $element->settings ) ) {
			return $attributes;
		}

		$settings = $element->settings;
		$families = self::families( $settings, self::classes_of( $settings ), self::$legacy );

		if ( array() === $families ) {
			return $attributes;
		}

		foreach ( $families as $family ) {
			self::$seen[ $family ] = true;
		}

		$attributes['_root']['data-lm'] = implode( ' ', $families );

		$data = is_array( $element->element ) ? $element->element : [];
		if ( self::uses_class_selector( $data ) ) {
			$inline = self::inline_style( $settings );
			if ( '' !== $inline ) {
				$existing                     = $attributes['_root']['style'] ?? null;
				$attributes['_root']['style'] = self::merge_style( $existing, $inline );
			}
		}

		return $attributes;
	}

	/**
	 * Families of an element, from its own settings and from its classes.
	 *
	 * @param array<string, mixed>                                              $element_settings Settings of the element.
	 * @param array<int, array{name?: mixed, settings?: mixed}|array<mixed>>    $classes          Global classes of the element, as name and settings.
	 * @param bool                                                              $legacy           Whether the legacy class names also count.
	 * @return string[] Subset of enter, text, counter, underline, in that order.
	 */
	public static function families( array $element_settings, array $classes, bool $legacy ): array {
		$found = [];

		self::collect_setting_families( $element_settings, $found );

		foreach ( $classes as $class ) {
			if ( ! is_array( $class ) ) {
				continue;
			}

			$name = $class['name'] ?? null;
			if ( is_string( $name ) && '' !== $name ) {
				$slug = Effects::slug_for_class( $name, $legacy );
				if ( null !== $slug ) {
					$found[ (string) Effects::family( $slug ) ] = true;
				}
			}

			$settings = $class['settings'] ?? null;
			if ( is_array( $settings ) ) {
				self::collect_setting_families( $settings, $found );
			}
		}

		return array_values( array_intersect( self::FAMILY_ORDER, array_keys( $found ) ) );
	}

	/**
	 * Adds the family of every effect set in a settings array, breakpoint variants included.
	 *
	 * @param array<mixed, mixed> $settings Element or class settings.
	 * @param array<string, true> $found    Families found so far, by reference.
	 */
	private static function collect_setting_families( array $settings, array &$found ): void {
		foreach ( $settings as $key => $value ) {
			$key = (string) $key;
			if ( self::EFFECT_KEY !== $key && ! str_starts_with( $key, self::EFFECT_KEY . ':' ) ) {
				continue;
			}

			if ( ! is_string( $value ) ) {
				continue;
			}

			$family = Effects::family( $value );
			if ( null !== $family ) {
				$found[ $family ] = true;
			}
		}
	}

	/**
	 * The `--lm-*` declarations for the Motion settings of an element, base values only.
	 *
	 * Each value is what Bricks would write in its stylesheet: a bare number gets the fixed
	 * unit of its control (`%`), or `px` for a control with free units; a colour setting
	 * becomes its CSS colour. A value with characters outside the allowed set is dropped.
	 *
	 * @param array<string, mixed> $element_settings Settings of the element.
	 * @return string Declarations ending with a semicolon, or an empty string.
	 */
	public static function inline_style( array $element_settings ): string {
		$definitions = Controls::definitions();
		$style       = '';

		foreach ( Controls::variable_map() as $key => $property ) {
			if ( ! array_key_exists( $key, $element_settings ) ) {
				continue;
			}

			$value = self::css_value( $element_settings[ $key ], $definitions[ $key ] ?? [] );
			if ( null === $value || 1 !== preg_match( self::VALUE_PATTERN, $value ) ) {
				continue;
			}

			$style .= $property . ':' . $value . ';';
		}

		return $style;
	}

	/**
	 * Whether the element's own rule is too weak to beat a global class.
	 *
	 * Measured on Bricks 2.4.2 (assets.php, generate_inline_css_from_element): a class rule
	 * is `.name.brxe-{type}` (0,2,0), and the element rule is
	 * - `.brxe-{id}` (0,1,0) for a component and for a global element: the class wins;
	 * - `.brxe-{id}.brxe-{type}` (0,2,0) for an element that is itself the query loop: a tie,
	 *   and the class stylesheet comes later, so the class wins;
	 * - `.brxe-{loop} .brxe-{id}.brxe-{type}` (0,3,0) for any other element inside a loop:
	 *   the element already wins, and an inline style would only hide its breakpoint values.
	 *
	 * @param array<mixed, mixed> $element Element data (id, name, cid, parentComponent, global...).
	 */
	public static function uses_class_selector( array $element ): bool {
		if ( ! empty( $element['cid'] ) || ! empty( $element['parentComponent'] ) ) {
			return true;
		}

		if ( ! empty( \Bricks\Helpers::get_global_element( $element, 'global' ) ) ) {
			return true;
		}

		if ( ! \Bricks\Query::is_looping() ) {
			return false;
		}

		// Same test as Bricks: the loop element is prefixed to the selector of the others only.
		$query_id     = \Bricks\Query::is_any_looping();
		$loop_element = $query_id ? \Bricks\Query::get_query_element_id( (string) $query_id ) : false;

		return empty( $loop_element ) || ( $element['id'] ?? null ) === $loop_element;
	}

	/**
	 * CSS text of a setting value, as Bricks would generate it.
	 *
	 * @param mixed                $value   Value saved in the settings.
	 * @param array<string, mixed> $control Definition of the control.
	 * @return string|null Null when there is nothing to write.
	 */
	private static function css_value( $value, array $control ): ?string {
		if ( is_array( $value ) ) {
			// Colour control: Bricks prefers the raw value, then rgb, then hex.
			foreach ( [ 'raw', 'rgb', 'hex' ] as $part ) {
				if ( isset( $value[ $part ] ) && is_string( $value[ $part ] ) && '' !== trim( $value[ $part ] ) ) {
					return trim( $value[ $part ] );
				}
			}

			return null;
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			$value = (string) $value;
		}

		if ( ! is_string( $value ) ) {
			return null;
		}

		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}

		if ( 'number' === ( $control['type'] ?? null ) ) {
			// Same tests as Bricks (Assets::generate_inline_css_from_element): the fixed unit is
			// appended unless the value holds it past its first character; free units get px.
			$unit = $control['unit'] ?? '';
			if ( is_string( $unit ) && '' !== $unit && ! strpos( $value, $unit ) ) {
				$value .= $unit;
			} elseif ( ! empty( $control['units'] ) && is_numeric( $value ) ) {
				$value .= 'px';
			}
		}

		return $value;
	}

	/**
	 * Appends declarations to the `style` attribute already set on the element.
	 *
	 * A string or a list is kept as it is; the declarations are not added twice when the
	 * filter runs again on attributes it already completed.
	 *
	 * @param mixed  $existing Current `style` value, if any.
	 * @param string $inline   Declarations to add.
	 * @return string|array<int|string, mixed>
	 */
	private static function merge_style( $existing, string $inline ) {
		if ( is_array( $existing ) ) {
			if ( ! in_array( $inline, $existing, true ) ) {
				$existing[] = $inline;
			}

			return $existing;
		}

		if ( ! is_string( $existing ) || '' === trim( $existing ) ) {
			return $inline;
		}

		if ( str_contains( $existing, $inline ) ) {
			return $existing;
		}

		return rtrim( $existing, "; \t\n" ) . ';' . $inline;
	}

	/**
	 * Global classes of an element, as name and settings.
	 *
	 * @param array<string, mixed> $settings Settings of the element.
	 * @return array<int, array{name: string, settings: array<string, mixed>}>
	 */
	private static function classes_of( array $settings ): array {
		$ids = $settings['_cssGlobalClasses'] ?? [];
		if ( ! is_array( $ids ) || array() === $ids ) {
			return [];
		}

		$index   = self::class_index();
		$classes = [];
		foreach ( $ids as $id ) {
			if ( is_string( $id ) && isset( $index[ $id ] ) ) {
				$classes[] = $index[ $id ];
			}
		}

		return $classes;
	}

	/**
	 * Index of the global classes by ID, built once per request.
	 *
	 * @return array<string, array{name: string, settings: array<string, mixed>}>
	 */
	private static function class_index(): array {
		if ( null !== self::$class_index ) {
			return self::$class_index;
		}

		$all = \Bricks\Database::$global_data['globalClasses'] ?? null;
		if ( ! is_array( $all ) || array() === $all ) {
			$all = get_option( 'bricks_global_classes', [] );
		}

		$index = [];
		foreach ( is_array( $all ) ? $all : [] as $class ) {
			if ( ! is_array( $class ) || ! isset( $class['id'], $class['name'] ) || ! is_string( $class['id'] ) || ! is_string( $class['name'] ) ) {
				continue;
			}

			$index[ $class['id'] ] = [
				'name'     => $class['name'],
				'settings' => is_array( $class['settings'] ?? null ) ? $class['settings'] : [],
			];
		}

		self::$class_index = $index;

		return $index;
	}
}
