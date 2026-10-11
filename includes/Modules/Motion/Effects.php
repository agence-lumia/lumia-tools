<?php
namespace Lumia\Tools\Modules\Motion;

defined( 'ABSPATH' ) || exit;

/**
 * Catalog of the Motion effects.
 *
 * Pure data and lookups, with no WordPress function: the unit tests under
 * tools/motion/php load this class without WordPress.
 */
final class Effects {

	const FAMILY_ENTER     = 'enter';
	const FAMILY_TEXT      = 'text';
	const FAMILY_COUNTER   = 'counter';
	const FAMILY_UNDERLINE = 'underline';

	/** Tempo keywords that scale durations and delays, with their multiplier. */
	const TEMPOS = [
		'very-fast' => 0.5,
		'fast'      => 0.75,
		'normal'    => 1.0,
		'slow'      => 1.5,
		'very-slow' => 2.0,
	];

	/** Every accepted tempo keyword: the five profiles plus the unscaled one (a typed duration). */
	const TEMPO_KEYWORDS = [ 'very-fast', 'fast', 'normal', 'slow', 'very-slow', 'custom' ];

	/** Easing keywords. */
	const EASES = [ 'smooth', 'snappy', 'bouncy', 'linear', 'custom' ];

	/** Viewport edges accepted for the start and end markers. */
	const EDGES = [ 'top', 'center', 'bottom' ];

	/**
	 * CSS custom properties read by the engine, without the leading dashes.
	 * Registered with `@property` and never inherited. The underline variables
	 * are inheritable on purpose and are not part of this list.
	 */
	const ENGINE_VARIABLES = [
		'lm-effect',
		'lm-start-edge',
		'lm-start-at',
		'lm-end-edge',
		'lm-end-at',
		'lm-start',
		'lm-end',
		'lm-tempo',
		'lm-duration',
		'lm-delay',
		'lm-stagger',
		'lm-ease',
		'lm-ease-custom',
		'lm-distance',
		'lm-blur',
		'lm-scale',
		'lm-from-opacity',
		'lm-start-value',
	];

	/**
	 * The ten effects, keyed by slug.
	 *
	 * `params` lists the effect-specific settings, beyond the shared ones
	 * (start, delay, duration, stagger). `scrub` effects follow the scroll
	 * instead of playing once.
	 *
	 * @return array<string, array{class: string, family: string, scrub: bool, params: string[]}>
	 */
	public static function catalog(): array {
		// Asked for every rendered element: built once per request.
		static $catalog = null;
		if ( null !== $catalog ) {
			return $catalog;
		}

		$definitions = [
			'fade'      => [ self::FAMILY_ENTER, false, [] ],
			'slide-up'  => [ self::FAMILY_ENTER, false, [ 'distance' ] ],
			'blur'      => [ self::FAMILY_ENTER, false, [ 'blur' ] ],
			'blur-up'   => [ self::FAMILY_ENTER, false, [ 'distance', 'blur' ] ],
			'zoom'      => [ self::FAMILY_ENTER, false, [ 'scale' ] ],
			'zoom-blur' => [ self::FAMILY_ENTER, false, [ 'scale', 'blur' ] ],
			'lines'     => [ self::FAMILY_TEXT, false, [ 'distance' ] ],
			'words'     => [ self::FAMILY_TEXT, true, [ 'from_opacity' ] ],
			'letters'   => [ self::FAMILY_TEXT, true, [ 'from_opacity' ] ],
			'counter'   => [ self::FAMILY_COUNTER, false, [ 'start_value' ] ],
			'underline' => [ self::FAMILY_UNDERLINE, false, [] ],
		];

		$built = [];
		foreach ( $definitions as $slug => $definition ) {
			$built[ $slug ] = [
				'class'  => 'lm-' . $slug,
				'family' => $definition[0],
				'scrub'  => $definition[1],
				'params' => $definition[2],
			];
		}

		$catalog = $built;

		return $catalog;
	}

	/**
	 * Reference value of every setting, per effect.
	 *
	 * Numbers are floats, markers are strings. Scrub effects have no
	 * `duration` and carry `end_edge` / `end_at`. Underline is CSS only and
	 * has no value.
	 *
	 * @return array<string, array<string, float|string>>
	 */
	public static function reference_values(): array {
		$enter = static function ( float $duration, float $stagger, array $params = [] ): array {
			return array_merge(
				[
					'start_edge' => 'top',
					'start_at'   => '98%',
					'delay'      => 0.0,
					'duration'   => $duration,
					'stagger'    => $stagger,
				],
				$params
			);
		};

		$scrub = static function ( float $stagger, float $from_opacity ): array {
			return [
				'start_edge'   => 'top',
				'start_at'     => '98%',
				'delay'        => 0.0,
				'stagger'      => $stagger,
				'from_opacity' => $from_opacity,
				'end_edge'     => 'top',
				'end_at'       => '-120%',
			];
		};

		return [
			'fade'      => $enter( 0.45, 0.06 ),
			'slide-up'  => $enter( 0.65, 0.1, [ 'distance' => 48.0 ] ),
			'blur'      => $enter( 0.8, 0.08, [ 'blur' => 12.0 ] ),
			'blur-up'   => $enter(
				0.8,
				0.08,
				[
					'distance' => 20.0,
					'blur'     => 12.0,
				]
			),
			'zoom'      => $enter( 0.55, 0.08, [ 'scale' => 0.9 ] ),
			'zoom-blur' => $enter(
				0.7,
				0.08,
				[
					'scale' => 0.86,
					'blur'  => 10.0,
				]
			),
			'lines'     => $enter( 0.95, 0.08, [ 'distance' => 120.0 ] ),
			'words'     => $scrub( 0.25, 0.08 ),
			'letters'   => $scrub( 0.1, 0.08 ),
			'counter'   => [
				'start_edge'  => 'top',
				'start_at'    => '98%',
				'delay'       => 0.0,
				'duration'    => 1.35,
				'start_value' => 0.0,
			],
			'underline' => [],
		];
	}

	/**
	 * Finds the effect behind a class name.
	 *
	 * @param string $class_name A single CSS class, without the dot.
	 * @param bool   $legacy     Whether the legacy names also count.
	 * @return string|null The effect slug, or null when the class is not an effect.
	 */
	public static function slug_for_class( string $class_name, bool $legacy ): ?string {
		static $by_class = null;
		if ( null === $by_class ) {
			$by_class = [];
			foreach ( self::catalog() as $slug => $effect ) {
				$by_class[ $effect['class'] ] = $slug;
			}
		}

		if ( isset( $by_class[ $class_name ] ) ) {
			return $by_class[ $class_name ];
		}

		if ( $legacy && isset( Legacy::CLASS_ALIASES[ $class_name ] ) ) {
			return Legacy::CLASS_ALIASES[ $class_name ];
		}

		return null;
	}

	/**
	 * Family of an effect: enter, text, counter or underline.
	 *
	 * @param string $slug Effect slug.
	 * @return string|null The family, or null for an unknown slug.
	 */
	public static function family( string $slug ): ?string {
		return self::catalog()[ $slug ]['family'] ?? null;
	}
}
