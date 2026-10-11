<?php
namespace Lumia\Tools\Modules\Motion;

defined( 'ABSPATH' ) || exit;

/**
 * Validation of the Motion module settings.
 *
 * Pure PHP, with no WordPress function: the unit tests under tools/motion/php
 * load this class without WordPress. Every value is brought back to a safe
 * one, so the result can be printed into a script or a stylesheet.
 */
final class SettingsSanitizer {

	/** Numeric settings: name => [ minimum, maximum ]. */
	const BOUNDS = [
		'duration'     => [ 0.0, 10.0 ],
		'delay'        => [ 0.0, 10.0 ],
		'stagger'      => [ 0.0, 2.0 ],
		'distance'     => [ -500.0, 500.0 ],
		'blur'         => [ 0.0, 100.0 ],
		'scale'        => [ 0.0, 3.0 ],
		'from_opacity' => [ 0.0, 1.0 ],
		'start_value'  => [ -1e9, 1e9 ],
		'lerp'         => [ 0.01, 1.0 ],
	];

	/** Builder preview modes. */
	const BUILDER_PREVIEWS = [ 'replay', 'off' ];

	/**
	 * Sanitizes the settings posted by the admin screen.
	 *
	 * Anything missing or invalid falls back to the matching default, except
	 * the check boxes: an unchecked box is absent from a posted form, so a
	 * missing one is false.
	 *
	 * @param array<string, mixed> $input    Raw settings.
	 * @param array<string, mixed> $defaults Factory defaults (Module::get_defaults()).
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input, array $defaults ): array {
		$default_effects = is_array( $defaults['effects'] ?? null ) ? $defaults['effects'] : [];
		$input_effects   = is_array( $input['effects'] ?? null ) ? $input['effects'] : [];
		$default_lenis   = is_array( $defaults['lenis'] ?? null ) ? $defaults['lenis'] : [];
		$input_lenis     = is_array( $input['lenis'] ?? null ) ? $input['lenis'] : [];

		$effects = [];
		foreach ( Effects::reference_values() as $slug => $reference ) {
			$posted    = is_array( $input_effects[ $slug ] ?? null ) ? $input_effects[ $slug ] : [];
			$fallbacks = is_array( $default_effects[ $slug ] ?? null ) ? $default_effects[ $slug ] : [];
			$sanitized = [];

			foreach ( $reference as $key => $reference_value ) {
				$fallback = $fallbacks[ $key ] ?? $reference_value;
				$value    = $posted[ $key ] ?? null;

				$sanitized[ $key ] = self::sanitize_effect_value( (string) $key, $value, $fallback );
			}

			$effects[ $slug ] = $sanitized;
		}

		$ease        = self::keyword( $input['ease'] ?? null, Effects::EASES, 'lumia', (string) ( $defaults['ease'] ?? 'lumia' ) );
		$ease_custom = self::sanitize_curve( $input['ease_custom'] ?? '' );
		if ( 'custom' === $ease && '' === $ease_custom ) {
			$ease = 'lumia';
		}

		// The global tempo is one of the three profiles: an exact duration only makes sense per element.
		$tempo_default = (string) ( $defaults['tempo'] ?? 'normal' );

		return [
			'effects'         => $effects,
			'tempo'           => self::keyword( $input['tempo'] ?? null, array_keys( Effects::TEMPOS ), 'normal', $tempo_default ),
			'ease'            => $ease,
			'ease_custom'     => $ease_custom,
			'lenis'           => [
				'enabled' => self::checked( $input_lenis['enabled'] ?? null ),
				'lerp'    => self::number( $input_lenis['lerp'] ?? null, 'lerp', (float) ( $default_lenis['lerp'] ?? 0.1 ) ),
			],
			'legacy_classes'  => self::checked( $input['legacy_classes'] ?? null ),
			'builder_preview' => self::keyword( $input['builder_preview'] ?? null, self::BUILDER_PREVIEWS, 'replay', (string) ( $defaults['builder_preview'] ?? 'replay' ) ),
		];
	}

	/**
	 * Whether a value is a ScrollTrigger-style position ("top 80%", "center bottom").
	 *
	 * @param string $v Value to test.
	 */
	public static function is_position( string $v ): bool {
		return 1 === preg_match( '/^[a-z0-9%+\-. ]{1,40}$/iD', $v );
	}

	/**
	 * Whether a value is a percentage ("98%", "-120%", "12.5%").
	 *
	 * @param string $v Value to test.
	 */
	public static function is_percent( string $v ): bool {
		return 1 === preg_match( '/^-?\d{1,4}(\.\d{1,2})?%$/D', $v );
	}

	/**
	 * Reads a `cubic-bezier(x1, y1, x2, y2)` value.
	 *
	 * `x1` and `x2` must stay in [0, 1], as in CSS: outside that range the
	 * curve is not a function of time. `y1` and `y2` are free, which allows
	 * an overshoot.
	 *
	 * @param string $v Value to read.
	 * @return array{0: float, 1: float, 2: float, 3: float}|null The four numbers, or null when invalid.
	 */
	public static function parse_bezier( string $v ): ?array {
		$number = '(-?(?:\d+(?:\.\d+)?|\.\d+))';
		$regex  = '/^cubic-bezier\(\s*' . $number . '\s*,\s*' . $number . '\s*,\s*' . $number . '\s*,\s*' . $number . '\s*\)$/D';

		if ( 1 !== preg_match( $regex, $v, $m ) ) {
			return null;
		}

		$x1 = (float) $m[1];
		$y1 = (float) $m[2];
		$x2 = (float) $m[3];
		$y2 = (float) $m[4];

		if ( $x1 < 0.0 || $x1 > 1.0 || $x2 < 0.0 || $x2 > 1.0 ) {
			return null;
		}

		return [ $x1, $y1, $x2, $y2 ];
	}

	/**
	 * Sanitizes one setting of one effect.
	 *
	 * @param string           $key      Setting name (snake_case).
	 * @param mixed            $value    Raw value, null when absent.
	 * @param float|int|string $fallback Value used when the raw one is invalid.
	 * @return float|string
	 */
	private static function sanitize_effect_value( string $key, $value, $fallback ) {
		if ( 'start_edge' === $key || 'end_edge' === $key ) {
			return self::keyword( $value, Effects::EDGES, 'top', (string) $fallback );
		}

		if ( 'start_at' === $key || 'end_at' === $key ) {
			$text = is_string( $value ) ? trim( $value ) : '';
			return self::is_percent( $text ) ? $text : (string) $fallback;
		}

		return self::number( $value, $key, (float) $fallback );
	}

	/**
	 * Brings a numeric setting into its bounds.
	 *
	 * @param mixed  $value    Raw value.
	 * @param string $key      Setting name, a key of BOUNDS.
	 * @param float  $fallback Value used when the raw one is not a finite number.
	 */
	private static function number( $value, string $key, float $fallback ): float {
		if ( ! is_int( $value ) && ! is_float( $value ) && ! is_string( $value ) ) {
			return $fallback;
		}

		if ( ! is_numeric( $value ) ) {
			return $fallback;
		}

		$number = (float) $value;
		if ( ! is_finite( $number ) ) {
			return $fallback;
		}

		[ $min, $max ] = self::BOUNDS[ $key ] ?? [ -1e9, 1e9 ];

		return min( $max, max( $min, $number ) );
	}

	/**
	 * Keeps a value only when it is one of the allowed keywords.
	 *
	 * @param mixed    $value    Raw value.
	 * @param string[] $allowed  Allowed keywords.
	 * @param string   $last     Keyword used when neither the value nor the default is allowed.
	 * @param string   $fallback Preferred fallback (the default setting).
	 */
	private static function keyword( $value, array $allowed, string $last, string $fallback ): string {
		if ( is_string( $value ) && in_array( $value, $allowed, true ) ) {
			return $value;
		}

		return in_array( $fallback, $allowed, true ) ? $fallback : $last;
	}

	/**
	 * Keeps a custom curve when it is a valid cubic-bezier(), as typed.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function sanitize_curve( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$curve = trim( $value );

		return null !== self::parse_bezier( $curve ) ? $curve : '';
	}

	/**
	 * Reads a check box: only an explicit "on" value counts.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function checked( $value ): bool {
		return in_array( $value, [ true, 1, '1', 'on', 'true' ], true );
	}
}
