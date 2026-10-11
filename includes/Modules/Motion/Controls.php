<?php
namespace Lumia\Tools\Modules\Motion;

defined( 'ABSPATH' ) || exit;

/**
 * The "Motion" control group, added to every Bricks element.
 *
 * Each control writes one `--lm-*` CSS variable on the element itself. That is what
 * makes it work on a global class too: a Bricks control with a `css` key is saved on
 * the active class, has per-breakpoint values and updates the canvas without a
 * re-render. The engine reads the variables back (computed style), so the class and
 * the element settings combine through the plain CSS cascade.
 *
 * Bricks only loads the controls of an element on the `wp` hook, after every element
 * (its own and the add-ons') is registered during `init`: hooking the filters at
 * `init` 100 therefore sees every element name and no loaded control yet.
 */
class Controls {

	/** Control group key. */
	const GROUP = 'lm_motion';

	/** Script run by the builder each time an element is mounted or re-rendered. */
	const BUILDER_SCRIPT = 'lumiaMotionBuilder';

	/**
	 * Registers the hooks.
	 */
	public function hook(): void {
		add_action( 'init', [ $this, 'register' ], 100 );
	}

	/**
	 * Adds the Motion panel to every registered Bricks element.
	 *
	 * Does nothing when Bricks is not there, so a call outside a Bricks site is safe.
	 */
	public function register(): void {
		if ( ! class_exists( '\Bricks\Elements' ) ) {
			return;
		}

		foreach ( array_keys( \Bricks\Elements::$elements ) as $name ) {
			add_filter( "bricks/elements/{$name}/control_groups", [ $this, 'add_group' ] );
			add_filter( "bricks/elements/{$name}/controls", [ $this, 'add_controls' ] );
			add_filter( "bricks/elements/{$name}/scripts", [ $this, 'add_script' ] );
		}
	}

	/**
	 * Filter callback: adds the Motion group to the style tab.
	 *
	 * @param mixed $groups Control groups of the element.
	 * @return array<string, mixed>
	 */
	public function add_group( $groups ): array {
		$groups = is_array( $groups ) ? $groups : [];

		$groups[ self::GROUP ] = [
			'title' => __( 'Motion', 'lumia-tools' ),
			'tab'   => 'style',
		];

		return $groups;
	}

	/**
	 * Filter callback: adds the Motion controls.
	 *
	 * @param mixed $controls Controls of the element.
	 * @return array<string, mixed>
	 */
	public function add_controls( $controls ): array {
		$controls = is_array( $controls ) ? $controls : [];

		return array_merge( $controls, self::definitions() );
	}

	/**
	 * Filter callback: declares the builder script, once, for builder requests only.
	 *
	 * Bricks calls the function of this name on the canvas window each time the
	 * element is mounted or re-rendered, and skips it when it does not exist. A
	 * non-empty list also makes Bricks print a `data-script-id` attribute on every
	 * element it renders, so the front end must not get it: it is added only for the
	 * builder (main window, canvas) and its AJAX / REST element renders.
	 *
	 * @param mixed $scripts Scripts of the element.
	 * @return array<int, mixed>
	 */
	public function add_script( $scripts ): array {
		$scripts = is_array( $scripts ) ? $scripts : [];

		if ( ! self::is_builder_request() ) {
			return $scripts;
		}

		if ( ! in_array( self::BUILDER_SCRIPT, $scripts, true ) ) {
			$scripts[] = self::BUILDER_SCRIPT;
		}

		return $scripts;
	}

	/**
	 * Whether the request comes from the Bricks builder.
	 *
	 * The builder page itself (`?bricks=run`, canvas included) or one of its AJAX /
	 * REST calls, such as the render of an element added during the session.
	 */
	private static function is_builder_request(): bool {
		if ( function_exists( 'bricks_is_builder' ) && bricks_is_builder() ) {
			return true;
		}

		return function_exists( 'bricks_is_builder_call' ) && bricks_is_builder_call();
	}

	/**
	 * Control key => CSS variable, for the controls that write one.
	 *
	 * Read from the definitions, so the two cannot drift apart. The separators and
	 * the help text write nothing and are left out.
	 *
	 * @return array<string, string>
	 */
	public static function variable_map(): array {
		// Read for every marked element: built once per request (the properties do not depend on the locale).
		static $map = null;
		if ( null !== $map ) {
			return $map;
		}

		$built = [];
		foreach ( self::definitions() as $key => $control ) {
			$property = $control['css'][0]['property'] ?? null;
			if ( is_string( $property ) ) {
				$built[ $key ] = $property;
			}
		}
		$map = $built;

		return $map;
	}

	/**
	 * The 24 controls, keyed by Bricks control key.
	 *
	 * The parameters of an effect have no `required` condition on the effect: a
	 * condition only sees the settings of the element, not the effect brought by a
	 * class, so the parameters are all shown, under a separator.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definitions(): array {
		// Bricks loads every element, about a hundred, on each request: build the list once
		// per locale (the labels are translated).
		static $cache = [];

		$locale = determine_locale();
		if ( isset( $cache[ $locale ] ) ) {
			return $cache[ $locale ];
		}

		$controls = [];

		$controls['_lmEffect'] = self::variable(
			'--lm-effect',
			[
				'label'       => __( 'Effect', 'lumia-tools' ),
				'type'        => 'select',
				'options'     => self::effect_options(),
				'placeholder' => __( 'Not set', 'lumia-tools' ),
			]
		);

		$edges = self::options(
			Effects::EDGES,
			[
				'top'    => __( 'Top', 'lumia-tools' ),
				'center' => __( 'Center', 'lumia-tools' ),
				'bottom' => __( 'Bottom', 'lumia-tools' ),
			]
		);

		$controls['_lmStartEdge'] = self::variable(
			'--lm-start-edge',
			[
				'label'       => __( 'Start: element edge', 'lumia-tools' ),
				'type'        => 'select',
				'options'     => $edges,
				'placeholder' => __( 'Default', 'lumia-tools' ),
			]
		);

		$controls['_lmStartAt'] = self::variable(
			'--lm-start-at',
			[
				'label' => __( 'Start at (%)', 'lumia-tools' ),
				'type'  => 'number',
				'unit'  => '%',
				'step'  => 1,
			]
		);

		$controls['_lmEndEdge'] = self::variable(
			'--lm-end-edge',
			[
				'label'       => __( 'End: element edge', 'lumia-tools' ),
				'type'        => 'select',
				'options'     => $edges,
				'placeholder' => __( 'Default', 'lumia-tools' ),
			]
		);

		$controls['_lmEndAt'] = self::variable(
			'--lm-end-at',
			[
				'label' => __( 'End at (%)', 'lumia-tools' ),
				'type'  => 'number',
				'unit'  => '%',
				'step'  => 1,
			]
		);

		$controls['_lmAdvancedSep'] = self::plain(
			[
				'label' => __( 'Advanced', 'lumia-tools' ),
				'type'  => 'separator',
			]
		);

		$controls['_lmStart'] = self::variable(
			'--lm-start',
			[
				'label'       => __( 'Start position (ScrollTrigger)', 'lumia-tools' ),
				'type'        => 'text',
				'placeholder' => 'top 90%',
			]
		);

		$controls['_lmEnd'] = self::variable(
			'--lm-end',
			[
				'label'       => __( 'End position (ScrollTrigger)', 'lumia-tools' ),
				'type'        => 'text',
				'placeholder' => 'top -120%',
			]
		);

		$controls['_lmTempo'] = self::variable(
			'--lm-tempo',
			[
				'label'       => __( 'Tempo', 'lumia-tools' ),
				'type'        => 'select',
				'options'     => self::options(
					Effects::TEMPO_KEYWORDS,
					[
						'very-fast' => __( 'Very fast', 'lumia-tools' ),
						'fast'      => __( 'Fast', 'lumia-tools' ),
						'normal'    => __( 'Normal', 'lumia-tools' ),
						'slow'      => __( 'Slow', 'lumia-tools' ),
						'very-slow' => __( 'Very slow', 'lumia-tools' ),
						'custom'    => _x( 'Custom', 'tempo', 'lumia-tools' ),
					]
				),
				'placeholder' => __( 'Default', 'lumia-tools' ),
			]
		);

		$controls['_lmDuration'] = self::variable(
			'--lm-duration',
			[
				'label'    => __( 'Duration (seconds)', 'lumia-tools' ),
				'type'     => 'number',
				'min'      => 0,
				'step'     => 0.05,
				'required' => [ '_lmTempo', '=', 'custom' ],
			]
		);

		$controls['_lmDelay'] = self::variable(
			'--lm-delay',
			[
				'label' => __( 'Delay (seconds)', 'lumia-tools' ),
				'type'  => 'number',
				'min'   => 0,
				'step'  => 0.05,
			]
		);

		$controls['_lmStagger'] = self::variable(
			'--lm-stagger',
			[
				'label' => __( 'Stagger (seconds)', 'lumia-tools' ),
				'type'  => 'number',
				'min'   => 0,
				'step'  => 0.01,
			]
		);

		$controls['_lmEase'] = self::variable(
			'--lm-ease',
			[
				'label'       => __( 'Easing', 'lumia-tools' ),
				'type'        => 'select',
				'options'     => self::options(
					Effects::EASES,
					[
						'smooth' => __( 'Smooth', 'lumia-tools' ),
						'snappy' => __( 'Snappy', 'lumia-tools' ),
						'bouncy' => __( 'Bouncy', 'lumia-tools' ),
						'linear' => __( 'Linear', 'lumia-tools' ),
						'custom' => __( 'Custom', 'lumia-tools' ),
					]
				),
				'placeholder' => __( 'Default', 'lumia-tools' ),
			]
		);

		$controls['_lmEaseCustom'] = self::variable(
			'--lm-ease-custom',
			[
				'label'       => __( 'Custom easing', 'lumia-tools' ),
				'type'        => 'text',
				'placeholder' => 'cubic-bezier(0.2, 0.8, 0.2, 1)',
				'required'    => [ '_lmEase', '=', 'custom' ],
			]
		);

		$controls['_lmParamsSep'] = self::plain(
			[
				'label' => __( 'Effect settings', 'lumia-tools' ),
				'type'  => 'separator',
			]
		);

		$controls['_lmDistance'] = self::variable(
			'--lm-distance',
			[
				'label'       => __( 'Distance', 'lumia-tools' ),
				'type'        => 'number',
				'min'         => 0,
				'step'        => 1,
				'description' => __( 'Pixels, or percent of the line height for the line-by-line text effect.', 'lumia-tools' ),
			]
		);

		$controls['_lmBlur'] = self::variable(
			'--lm-blur',
			[
				'label' => __( 'Blur (pixels)', 'lumia-tools' ),
				'type'  => 'number',
				'min'   => 0,
				'step'  => 1,
			]
		);

		$controls['_lmScale'] = self::variable(
			'--lm-scale',
			[
				'label' => __( 'Start scale', 'lumia-tools' ),
				'type'  => 'number',
				'min'   => 0,
				'step'  => 0.01,
			]
		);

		$controls['_lmFromOpacity'] = self::variable(
			'--lm-from-opacity',
			[
				'label' => __( 'Start opacity', 'lumia-tools' ),
				'type'  => 'number',
				'min'   => 0,
				'max'   => 1,
				'step'  => 0.01,
			]
		);

		$controls['_lmStartValue'] = self::variable(
			'--lm-start-value',
			[
				'label' => __( 'Counter start value', 'lumia-tools' ),
				'type'  => 'number',
				'step'  => 1,
			]
		);

		$controls['_lmUnderlineThickness'] = self::variable(
			'--lm-underline-thickness',
			[
				'label'       => __( 'Underline thickness', 'lumia-tools' ),
				'type'        => 'number',
				'units'       => true,
				'description' => __( 'Line thickness. Empty = scales with the text size (1 px for body text, about 2 px on a 32 px title).', 'lumia-tools' ),
			]
		);

		$controls['_lmUnderlineGap'] = self::variable(
			'--lm-underline-gap',
			[
				'label'       => __( 'Underline gap', 'lumia-tools' ),
				'type'        => 'number',
				'units'       => true,
				'placeholder' => '0.12em',
				'description' => __( 'Include the unit, e.g. 0.12em.', 'lumia-tools' ),
			]
		);

		$controls['_lmUnderlineColor'] = self::variable(
			'--lm-underline-color',
			[
				'label' => __( 'Underline color', 'lumia-tools' ),
				'type'  => 'color',
			]
		);

		$controls['_lmHelp'] = self::plain(
			[
				'type'    => 'info',
				'content' => esc_html__( 'One effect per element: with two Motion classes, the first one wins. A desktop value also applies to mobile unless mobile has its own. On the main heading above the fold, prefer a text effect (LCP).', 'lumia-tools' ),
			]
		);

		$cache[ $locale ] = $controls;

		return $controls;
	}

	/**
	 * Select options for a list of keywords, in the order of the list.
	 *
	 * The keys come from the constants of Effects, so a keyword added there shows up
	 * in the panel; a keyword without a label falls back to its own name.
	 *
	 * @param string[]              $keywords Keywords, from an Effects constant.
	 * @param array<string, string> $labels   Translated label of each keyword.
	 * @return array<string, string>
	 */
	private static function options( array $keywords, array $labels ): array {
		$options = [];
		foreach ( $keywords as $keyword ) {
			$options[ $keyword ] = $labels[ $keyword ] ?? $keyword;
		}

		return $options;
	}

	/**
	 * Options of the effect select: a thumbnail span and the name, plus "Off".
	 *
	 * Bricks renders the labels of a select as HTML. The thumbnail is drawn in CSS
	 * by `.lm-thumb--<slug>`.
	 *
	 * @return array<string, string>
	 */
	private static function effect_options(): array {
		$names = [
			'fade'      => __( 'Fade', 'lumia-tools' ),
			'slide-up'  => __( 'Slide up', 'lumia-tools' ),
			'blur'      => __( 'Blur', 'lumia-tools' ),
			'blur-up'   => __( 'Blur up', 'lumia-tools' ),
			'zoom'      => __( 'Zoom', 'lumia-tools' ),
			'zoom-blur' => __( 'Zoom blur', 'lumia-tools' ),
			'lines'     => __( 'Lines', 'lumia-tools' ),
			'words'     => __( 'Words on scroll', 'lumia-tools' ),
			'letters'   => __( 'Letters on scroll', 'lumia-tools' ),
			'counter'   => __( 'Counter', 'lumia-tools' ),
			'underline' => __( 'Hover underline', 'lumia-tools' ),
		];

		$options = [];
		foreach ( array_keys( Effects::catalog() ) as $slug ) {
			$options[ $slug ] = sprintf(
				'<span class="lm-thumb lm-thumb--%1$s"></span> %2$s',
				esc_attr( $slug ),
				esc_html( $names[ $slug ] ?? $slug )
			);
		}
		$options['none'] = esc_html__( 'Off', 'lumia-tools' );

		return $options;
	}

	/**
	 * A control that writes a CSS variable on the element itself.
	 *
	 * @param string               $property CSS variable, with its leading dashes.
	 * @param array<string, mixed> $control  Type, label and options of the control.
	 * @return array<string, mixed>
	 */
	private static function variable( string $property, array $control ): array {
		return array_merge(
			[
				'tab'   => 'style',
				'group' => self::GROUP,
			],
			$control,
			[
				'css' => [
					[
						'property' => $property,
						'selector' => '',
					],
				],
			]
		);
	}

	/**
	 * A control that writes nothing (separator, help text).
	 *
	 * @param array<string, mixed> $control Type, label and content of the control.
	 * @return array<string, mixed>
	 */
	private static function plain( array $control ): array {
		return array_merge(
			[
				'tab'   => 'style',
				'group' => self::GROUP,
			],
			$control
		);
	}
}
