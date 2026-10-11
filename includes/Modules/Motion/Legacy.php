<?php
namespace Lumia\Tools\Modules\Motion;

defined( 'ABSPATH' ) || exit;

/**
 * Legacy names of the Motion module: the only place that knows them.
 *
 * They come from the agency's former "SK Motion" snippet. Sites that still
 * use it keep working through the compatibility setting, until their content
 * is migrated to the `lm-*` classes. This file is on the legacy-name
 * allow-list of CLAUDE.md. Pure data, with no WordPress function.
 */
final class Legacy {

	/** Legacy class name => effect slug. */
	const CLASS_ALIASES = [
		'sk-fade'                 => 'fade',
		'sk-reveal'               => 'reveal',
		'sk-entry-blur'           => 'blur-in',
		'sk-scale-in'             => 'scale-in',
		'sk-scale-in-blur'        => 'scale-blur-in',
		'sk-text-lines'           => 'text-lines',
		'sk-text-highlight-words' => 'text-words',
		'sk-text-highlight-chars' => 'text-chars',
		'sk-counter'              => 'counter',
		'sk-hover-underline'      => 'underline',
	];

	/** Underline settings: short name => legacy variable and the variable that replaces it. */
	const UNDERLINE_VARIABLES = [
		'thickness' => [
			'legacy'  => '--sk-hover-underline-thickness',
			'current' => '--lm-underline-thickness',
		],
		'gap'       => [
			'legacy'  => '--sk-hover-underline-gap',
			'current' => '--lm-underline-gap',
		],
		'color'     => [
			'legacy'  => '--sk-hover-underline-color',
			'current' => '--lm-underline-color',
		],
		'duration'  => [
			'legacy'  => '--sk-hover-underline-duration',
			'current' => '--lm-underline-duration',
		],
	];

	/** Reference value of each underline setting, used when neither variable is set. */
	const UNDERLINE_DEFAULTS = [
		'thickness' => '2px',
		'gap'       => '0.12em',
		'color'     => 'currentColor',
		'duration'  => '0.4s',
	];

	/** JavaScript global exposed by the former snippet. */
	const ENGINE_GLOBAL = 'StudioKyneMotion';

	/** Name of the former snippet, to deactivate once the migration is done. */
	const SNIPPET_NAME = 'SK Motion';

	/** Start of the former underline variables (thickness, gap, color, duration): what a stylesheet or a snippet searches for. */
	const VARIABLE_NEEDLE = '--sk-hover-underline';

	/** Plain strings that mark a snippet file as the former one, besides the class names. */
	const SNIPPET_MARKERS = [ self::VARIABLE_NEEDLE, self::ENGINE_GLOBAL, self::SNIPPET_NAME ];

	/**
	 * Class that replaces a legacy class.
	 *
	 * @param string $legacy A legacy class name, without the dot.
	 * @return string|null The new class name, or null when the class is not a legacy one.
	 */
	public static function new_class_name( string $legacy ): ?string {
		$slug = self::CLASS_ALIASES[ $legacy ] ?? null;

		return null === $slug ? null : 'lm-' . $slug;
	}

	/**
	 * CSS that lets the legacy underline variables still style the underline.
	 *
	 * The fallbacks sit on the drawing rules of motion.css, same selectors and same
	 * declarations, each value reading `--lm-underline-*`, then `--sk-hover-underline-*`, then
	 * the reference value. They do not depend on the legacy class: once the migration has
	 * renamed it, the element carries `lm-underline` (or `data-lm`) and its custom CSS still
	 * sets the legacy variables. Assigning `--lm-underline-*` on the element instead would hide
	 * a value inherited from a wrapper. Printed after motion.css, it wins by source order.
	 */
	public static function underline_css(): string {
		$value = static function ( string $key ): string {
			return sprintf(
				'var(%s, var(%s, %s))',
				self::UNDERLINE_VARIABLES[ $key ]['current'],
				self::UNDERLINE_VARIABLES[ $key ]['legacy'],
				self::UNDERLINE_DEFAULTS[ $key ]
			);
		};

		$box  = '.lm-underline,[data-lm~="underline"]';
		$line = '.lm-underline::after,[data-lm~="underline"]::after';

		return $box . '{padding-bottom:calc(' . $value( 'gap' ) . ' * 0.45)}'
			. $line . '{bottom:calc(-1 * ' . $value( 'gap' ) . ');height:' . $value( 'thickness' ) . ';background-color:' . $value( 'color' ) . ';transition-duration:' . $value( 'duration' ) . '}'
			. '@media (prefers-reduced-motion: reduce){' . $box . '{padding-bottom:' . $value( 'gap' ) . '}}';
	}
}
