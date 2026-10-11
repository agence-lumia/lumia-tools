<?php
namespace Lumia\Tools\Modules\Motion;

defined( 'ABSPATH' ) || exit;

/**
 * Load animations: entrance effects of the first screen, played by the stylesheet.
 *
 * An entrance effect in the header or in the first section of the content would wait for the
 * deferred scripts while pre-hidden, and Chrome would pick an invisible LCP. These elements get
 * `data-lm-load` instead: the stylesheet animates them from the first frame, with the values
 * resolved here, at render time, written as inline custom properties. The engine leaves them
 * alone.
 *
 * The resolution mirrors `resolve()` in assets/front/js/motion-core.js (tempo, duration, delay,
 * stagger, curve, effect parameters), for the base breakpoint only: the stylesheet cannot read
 * a breakpoint value from PHP, and that limit is documented.
 *
 * Pure PHP with a per-request state (the zone and the ranks), no WordPress function: the unit
 * tests under tools/motion/php run it without WordPress.
 */
final class LoadAnimation {

	/** Control key of the "On load" setting. */
	const SETTING = '_lmLoad';

	/** Values of the "On load" setting; `auto` is the default. */
	const MODES = [ 'auto', 'always', 'never' ];

	/** Spring curves, which have a CSS `linear()` token and a settle factor in motion.css. */
	const SPRINGS = [ 'smooth', 'snappy', 'bouncy' ];

	/** Values saved by the first trials, read as their current name (same as motion-core.js). */
	const LEGACY_EASE  = [ 'lumia' => 'smooth' ];
	const LEGACY_TEMPO = [ 'exact' => 'custom' ];

	/** Accepted range of each number, as RANGES in motion-core.js. */
	const RANGES = [
		'duration' => [ 0.0, 60.0 ],
		'stagger'  => [ 0.0, 10.0 ],
		'delay'    => [ 0.0, 60.0 ],
		'distance' => [ -2000.0, 2000.0 ],
		'blur'     => [ 0.0, 200.0 ],
		'scale'    => [ 0.0, 10.0 ],
	];

	/** Used when neither the element nor the module gives a valid value, as INTERNAL in motion-core.js. */
	const INTERNAL = [
		'duration' => 0.6,
		'stagger'  => 0.08,
		'delay'    => 0.0,
		'distance' => 40.0,
		'blur'     => 10.0,
		'scale'    => 0.9,
	];

	/** Element settings read for a load animation, by name. */
	const KEYS = [
		'tempo'       => '_lmTempo',
		'duration'    => '_lmDuration',
		'delay'       => '_lmDelay',
		'stagger'     => '_lmStagger',
		'ease'        => '_lmEase',
		'ease_custom' => '_lmEaseCustom',
		'distance'    => '_lmDistance',
		'blur'        => '_lmBlur',
		'scale'       => '_lmScale',
		'load'        => self::SETTING,
	];

	/**
	 * IDs of the elements of the load zone rendered so far.
	 *
	 * @var array<string, true>
	 */
	private static array $zone = [];

	/** ID of the first root element of the content, once rendered. */
	private static ?string $first_root = null;

	/**
	 * Load animations already given, by signature: the rank of the next one in its lot.
	 *
	 * @var array<string, int>
	 */
	private static array $ranks = [];

	/**
	 * Module values: effects, tempo, ease, ease_custom.
	 *
	 * @var array<string, mixed>
	 */
	private static array $config = [];

	/**
	 * Sets the module values used by Marker for this request.
	 *
	 * @param array<string, mixed> $config See config_from().
	 */
	public static function configure( array $config ): void {
		self::$config = $config;
	}

	/**
	 * The module values set by configure().
	 *
	 * @return array<string, mixed>
	 */
	public static function config(): array {
		return self::$config;
	}

	/**
	 * The values a load animation needs, from the sanitized module settings.
	 *
	 * The settings must come from SettingsSanitizer::sanitize(), the same merge of the reference
	 * values and the admin overrides that Assets::config() hands to the engine.
	 *
	 * @param array<string, mixed> $settings Sanitized module settings.
	 * @return array{effects: array<string, mixed>, tempo: string, ease: string, ease_custom: string}
	 */
	public static function config_from( array $settings ): array {
		return [
			'effects'     => is_array( $settings['effects'] ?? null ) ? $settings['effects'] : Effects::reference_values(),
			'tempo'       => is_string( $settings['tempo'] ?? null ) ? $settings['tempo'] : 'normal',
			'ease'        => is_string( $settings['ease'] ?? null ) ? $settings['ease'] : 'smooth',
			'ease_custom' => is_string( $settings['ease_custom'] ?? null ) ? $settings['ease_custom'] : '',
		];
	}

	/**
	 * Forgets the zone and the ranks (tests; a new request starts empty anyway).
	 */
	public static function reset(): void {
		self::$zone       = [];
		self::$first_root = null;
		self::$ranks      = [];
	}

	/**
	 * Records an element being rendered and says whether it is in the load zone.
	 *
	 * The zone is every element of the header, and the first root element (parent 0) rendered in
	 * the content with all its descendants. Bricks renders a parent's root attributes before its
	 * children, so a child is in the zone when its parent is. A component child points to its
	 * instance through `parentComponent`.
	 *
	 * @param array<mixed, mixed> $element Element data (id, parent, parentComponent).
	 * @param string              $area    Bricks render area: header, content, footer, popup...
	 */
	public static function observe( array $element, string $area ): bool {
		$id = self::id( $element['id'] ?? null );
		if ( '' === $id ) {
			return false;
		}

		if ( isset( self::$zone[ $id ] ) ) {
			return true;
		}

		if ( 'header' === $area ) {
			self::$zone[ $id ] = true;

			return true;
		}

		if ( 'content' !== $area ) {
			return false;
		}

		$parent = self::id( $element['parent'] ?? null );
		$in     = false;

		if ( '' === $parent || '0' === $parent ) {
			if ( null === self::$first_root ) {
				self::$first_root = $id;
				$in               = true;
			}
		} else {
			$in = isset( self::$zone[ $parent ] );
		}

		$instance = self::id( $element['parentComponent'] ?? null );
		if ( ! $in && '' !== $instance && isset( self::$zone[ $instance ] ) ) {
			$in = true;
		}

		if ( $in ) {
			self::$zone[ $id ] = true;
		}

		return $in;
	}

	/**
	 * The "On load" setting of an element: its base value, else that of its first class with one.
	 *
	 * @param array<string, mixed>                                           $element_settings Element settings.
	 * @param array<int, array{name?: mixed, settings?: mixed}|array<mixed>> $classes          Global classes, as name and settings.
	 * @return string auto, always or never.
	 */
	public static function mode( array $element_settings, array $classes ): string {
		$value = strtolower( self::text( self::merged_settings( $element_settings, $classes )[ self::SETTING ] ?? null ) );

		return in_array( $value, self::MODES, true ) ? $value : 'auto';
	}

	/**
	 * Base effect of an element, as the engine finds it on a wide screen.
	 *
	 * The element setting, then a class setting (classes in order), then the first effect class
	 * name, then a name typed in the "CSS classes" field. Breakpoint values are not read.
	 *
	 * @param array<string, mixed>                                           $element_settings Element settings.
	 * @param array<int, array{name?: mixed, settings?: mixed}|array<mixed>> $classes          Global classes, as name and settings.
	 * @param bool                                                           $legacy           Whether the legacy class names count.
	 * @return string|null The slug (or `none`), null when the element has no base effect.
	 */
	public static function effect( array $element_settings, array $classes, bool $legacy ): ?string {
		$own = strtolower( self::text( $element_settings['_lmEffect'] ?? null ) );
		if ( '' !== $own ) {
			return $own;
		}

		foreach ( $classes as $class ) {
			$settings = is_array( $class ) && is_array( $class['settings'] ?? null ) ? $class['settings'] : [];
			$value    = strtolower( self::text( $settings['_lmEffect'] ?? null ) );
			if ( '' !== $value ) {
				return $value;
			}
		}

		foreach ( $classes as $class ) {
			$name = is_array( $class ) ? ( $class['name'] ?? null ) : null;
			$slug = is_string( $name ) ? Effects::slug_for_class( $name, $legacy ) : null;
			if ( null !== $slug ) {
				return $slug;
			}
		}

		$typed  = self::text( $element_settings['_cssClasses'] ?? null );
		$tokens = '' === $typed ? [] : preg_split( '/\s+/', $typed, -1, PREG_SPLIT_NO_EMPTY );
		foreach ( false === $tokens ? [] : $tokens as $token ) {
			$slug = Effects::slug_for_class( $token, $legacy );
			if ( null !== $slug ) {
				return $slug;
			}
		}

		return null;
	}

	/**
	 * Base values of the settings a load animation reads: the element's, else its first class's.
	 *
	 * @param array<string, mixed>                                           $element_settings Element settings.
	 * @param array<int, array{name?: mixed, settings?: mixed}|array<mixed>> $classes          Global classes, as name and settings.
	 * @return array<string, mixed> By control key, base breakpoint only.
	 */
	public static function merged_settings( array $element_settings, array $classes ): array {
		$merged = [];

		foreach ( self::KEYS as $key ) {
			if ( '' !== self::text( $element_settings[ $key ] ?? null ) ) {
				$merged[ $key ] = $element_settings[ $key ];
				continue;
			}

			foreach ( $classes as $class ) {
				$settings = is_array( $class ) && is_array( $class['settings'] ?? null ) ? $class['settings'] : [];
				if ( '' !== self::text( $settings[ $key ] ?? null ) ) {
					$merged[ $key ] = $settings[ $key ];
					break;
				}
			}
		}

		return $merged;
	}

	/**
	 * Attributes of an element animated on load: `data-lm-load` and its resolved values.
	 *
	 * Counts the element in its lot: the next one with the same signature (effect and resolved
	 * values) starts one stagger later.
	 *
	 * @param string               $slug     Entrance effect.
	 * @param array<string, mixed> $settings Base settings, see merged_settings().
	 * @param array<string, mixed> $config   Module values, see config_from().
	 * @return array{data-lm-load: string, style: string}
	 */
	public static function attributes( string $slug, array $settings, array $config ): array {
		$effects   = is_array( $config['effects'] ?? null ) ? $config['effects'] : [];
		$reference = Effects::reference_values()[ $slug ] ?? [];
		$module    = is_array( $effects[ $slug ] ?? null ) ? $effects[ $slug ] : $reference;
		$params    = Effects::catalog()[ $slug ]['params'] ?? [];

		$tempo   = self::tempo( $settings, $config );
		$delay   = self::pick( 'delay', $settings, $module );
		$stagger = self::pick( 'stagger', $settings, $module );

		if ( 'custom' === $tempo ) {
			// Typed duration, unscaled; the stagger is not scaled either (motion-core.js).
			$duration = self::pick( 'duration', $settings, $module );
		} else {
			// Other tempos scale the module duration; a typed one is for "custom" only.
			$factor   = Effects::TEMPOS[ $tempo ];
			$duration = self::pick( 'duration', [], $module ) * $factor;
			$stagger *= $factor;
		}

		$curve    = self::curve( $settings, $config );
		$distance = in_array( 'distance', $params, true ) ? self::pick( 'distance', $settings, $module ) : 0.0;
		$blur     = in_array( 'blur', $params, true ) ? self::pick( 'blur', $settings, $module ) : 0.0;
		$scale    = in_array( 'scale', $params, true ) ? self::pick( 'scale', $settings, $module ) : 1.0;

		$seconds = self::number( $duration ) . 's';
		if ( in_array( $curve, self::SPRINGS, true ) ) {
			$ease      = 'var(--lm-curve-' . $curve . ')';
			$safe_ease = 'var(--lm-curve-smooth)';
			$move      = 'calc(' . $seconds . ' * var(--lm-settle-' . $curve . '))';
		} else {
			// linear, or a valid cubic-bezier(): neither overshoots its own range of time.
			$ease      = $curve;
			$safe_ease = $curve;
			$move      = $seconds;
		}

		$values = [
			'--lm-load-duration'      => $seconds,
			'--lm-load-move-duration' => $move,
			'--lm-load-ease'          => $ease,
			'--lm-load-safe-ease'     => $safe_ease,
			'--lm-distance'           => self::number( $distance ) . 'px',
			'--lm-blur'               => self::number( $blur ) . 'px',
			'--lm-scale'              => self::number( $scale ),
		];

		$signature                 = $slug . '|' . self::number( $delay ) . '|' . self::number( $stagger ) . '|' . implode( '|', $values );
		$rank                      = self::$ranks[ $signature ] ?? 0;
		self::$ranks[ $signature ] = $rank + 1;

		$style = '';
		foreach ( $values as $property => $value ) {
			$style .= $property . ':' . $value . ';';
			if ( '--lm-load-move-duration' === $property ) {
				$style .= '--lm-load-delay:' . self::number( $delay + $rank * $stagger ) . 's;';
			}
		}

		return [
			'data-lm-load' => $slug,
			'style'        => $style,
		];
	}

	/**
	 * Tempo of an element: its own when valid, else the module's.
	 *
	 * @param array<string, mixed> $settings Base settings.
	 * @param array<string, mixed> $config   Module values.
	 * @return string A key of Effects::TEMPOS, or custom.
	 */
	private static function tempo( array $settings, array $config ): string {
		$site = self::text( $config['tempo'] ?? null );
		$site = self::LEGACY_TEMPO[ $site ] ?? $site;
		$site = isset( Effects::TEMPOS[ $site ] ) || 'custom' === $site ? $site : 'normal';

		$typed = strtolower( self::text( $settings[ self::KEYS['tempo'] ] ?? null ) );
		$typed = self::LEGACY_TEMPO[ $typed ] ?? $typed;

		return isset( Effects::TEMPOS[ $typed ] ) || 'custom' === $typed ? $typed : $site;
	}

	/**
	 * Curve of an element, as resolve() and resolveEase() of motion-core.js pick it.
	 *
	 * @param array<string, mixed> $settings Base settings.
	 * @param array<string, mixed> $config   Module values.
	 * @return string smooth, snappy, bouncy, linear, or a valid cubic-bezier() as typed.
	 */
	private static function curve( array $settings, array $config ): string {
		$config_ease = self::text( $config['ease'] ?? null );
		$config_ease = self::LEGACY_EASE[ $config_ease ] ?? $config_ease;
		$site        = in_array( $config_ease, self::SPRINGS, true ) || 'linear' === $config_ease ? $config_ease : 'smooth';

		$typed = strtolower( self::text( $settings[ self::KEYS['ease'] ] ?? null ) );
		$typed = self::LEGACY_EASE[ $typed ] ?? $typed;

		$keyword = '' !== $typed ? $typed : ( 'custom' === $config_ease ? 'custom' : $site );
		if ( ! in_array( $keyword, Effects::EASES, true ) ) {
			return $site;
		}

		if ( 'custom' !== $keyword ) {
			return $keyword;
		}

		$custom = self::text( $settings[ self::KEYS['ease_custom'] ] ?? null );
		if ( '' === $custom ) {
			$custom = self::text( $config['ease_custom'] ?? null );
		}

		return null !== SettingsSanitizer::parse_bezier( $custom ) ? $custom : $site;
	}

	/**
	 * A number setting: typed on the element when valid, else the module value, else the internal one.
	 *
	 * @param string              $name     Setting name, a key of RANGES.
	 * @param array<string, mixed> $settings Base settings (empty to skip the typed value).
	 * @param array<mixed, mixed>  $module   Module values of the effect.
	 */
	private static function pick( string $name, array $settings, array $module ): float {
		$typed = self::read( $settings[ self::KEYS[ $name ] ] ?? null, $name );
		if ( null !== $typed ) {
			return $typed;
		}

		return self::read( $module[ $name ] ?? null, $name ) ?? self::INTERNAL[ $name ];
	}

	/**
	 * Reads a number as motion-core.js does: a unit suffix is tolerated, `ms` converted for times.
	 *
	 * @param mixed  $value Raw value.
	 * @param string $name  Setting name, a key of RANGES.
	 * @return float|null Null when the value is not a number in range.
	 */
	private static function read( $value, string $name ): ?float {
		$text = self::text( $value );
		if ( 1 !== preg_match( '/^([+-]?(?:\d+(?:\.\d*)?|\.\d+))\s*(ms|s|px|%|deg)?$/iD', $text, $m ) ) {
			return null;
		}

		$number = (float) $m[1];
		$time   = in_array( $name, [ 'duration', 'delay', 'stagger' ], true );
		if ( isset( $m[2] ) && 'ms' === strtolower( $m[2] ) ) {
			if ( ! $time ) {
				return null;
			}
			$number /= 1000;
		}

		[ $min, $max ] = self::RANGES[ $name ];

		return is_finite( $number ) && $number >= $min && $number <= $max ? $number : null;
	}

	/**
	 * A number as CSS text: four decimals at most, no trailing zero.
	 *
	 * @param float $value Number.
	 */
	private static function number( float $value ): string {
		$text = rtrim( rtrim( sprintf( '%.4F', round( $value, 4 ) ), '0' ), '.' );

		return '-0' === $text ? '0' : $text;
	}

	/**
	 * A setting value as trimmed text: strings and numbers, anything else is empty.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function text( $value ): string {
		if ( is_string( $value ) ) {
			return trim( $value );
		}

		if ( is_int( $value ) || ( is_float( $value ) && is_finite( $value ) ) ) {
			return (string) $value;
		}

		return '';
	}

	/**
	 * An element ID as a string ('' when missing).
	 *
	 * @param mixed $value Raw ID.
	 */
	private static function id( $value ): string {
		return is_string( $value ) || is_int( $value ) ? (string) $value : '';
	}
}
