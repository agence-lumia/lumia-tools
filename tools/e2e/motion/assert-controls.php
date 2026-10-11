<?php
/**
 * Checks the Motion panel on the Bricks elements: the 24 controls, the group, the
 * script and the `css` keys that make a control write a --lm-* variable.
 * Run through `wp eval-file assert-controls.php` on the local bench; exits 1 on the
 * first failure. Local bench only, never shipped.
 */

if ( ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}

$lm_keys = array(
	'_lmEffect',
	'_lmStartEdge',
	'_lmStartAt',
	'_lmEndEdge',
	'_lmEndAt',
	'_lmAdvancedSep',
	'_lmStart',
	'_lmEnd',
	'_lmTempo',
	'_lmDuration',
	'_lmDelay',
	'_lmStagger',
	'_lmEase',
	'_lmEaseCustom',
	'_lmParamsSep',
	'_lmDistance',
	'_lmBlur',
	'_lmScale',
	'_lmFromOpacity',
	'_lmStartValue',
	'_lmUnderlineThickness',
	'_lmUnderlineGap',
	'_lmUnderlineColor',
	'_lmHelp',
);

// Controls that write a variable, and the variable they write.
$lm_variables = array(
	'_lmEffect'             => '--lm-effect',
	'_lmStartEdge'          => '--lm-start-edge',
	'_lmStartAt'            => '--lm-start-at',
	'_lmEndEdge'            => '--lm-end-edge',
	'_lmEndAt'              => '--lm-end-at',
	'_lmStart'              => '--lm-start',
	'_lmEnd'                => '--lm-end',
	'_lmTempo'              => '--lm-tempo',
	'_lmDuration'           => '--lm-duration',
	'_lmDelay'              => '--lm-delay',
	'_lmStagger'            => '--lm-stagger',
	'_lmEase'               => '--lm-ease',
	'_lmEaseCustom'         => '--lm-ease-custom',
	'_lmDistance'           => '--lm-distance',
	'_lmBlur'               => '--lm-blur',
	'_lmScale'              => '--lm-scale',
	'_lmFromOpacity'        => '--lm-from-opacity',
	'_lmStartValue'         => '--lm-start-value',
	'_lmUnderlineThickness' => '--lm-underline-thickness',
	'_lmUnderlineGap'       => '--lm-underline-gap',
	'_lmUnderlineColor'     => '--lm-underline-color',
);

$lm_fail = static function ( string $message ): void {
	WP_CLI::error( $message );
};

if ( ! class_exists( '\Bricks\Elements' ) || ! class_exists( '\Lumia\Tools\Modules\Motion\Controls' ) ) {
	$lm_fail( 'Bricks or Lumia\Tools\Modules\Motion\Controls is not loaded.' );
}

// The module hooked its Controls instance at init 100, after Bricks registered its elements.
global $wp_filter;
$lm_hooked = false;
foreach ( $wp_filter['init']->callbacks[100] ?? array() as $lm_callback ) {
	if ( is_array( $lm_callback['function'] ) && $lm_callback['function'][0] instanceof \Lumia\Tools\Modules\Motion\Controls && 'register' === $lm_callback['function'][1] ) {
		$lm_hooked = true;
	}
}
if ( ! $lm_hooked ) {
	$lm_fail( 'Controls::register() is not hooked on init at priority 100.' );
}

// An element registered by third-party code, as an add-on does during init.
$lm_file = sys_get_temp_dir() . '/lm-test-element.php';
// Removed on every exit path, WP_CLI::error() included.
register_shutdown_function(
	static function () use ( $lm_file ): void {
		if ( file_exists( $lm_file ) ) {
			unlink( $lm_file );
		}
	}
);
file_put_contents(
	$lm_file,
	"<?php\nnamespace Bricks;\nclass Lm_Test_Element extends Element {\n\tpublic \$category = 'general';\n\tpublic \$name = 'lm-test-element';\n\tpublic function get_label() {\n\t\treturn 'LM test';\n\t}\n}\n"
);
\Bricks\Elements::register_element( $lm_file, 'lm-test-element', '\Bricks\Lm_Test_Element' );
// The module hooked register() at init 100, before this element existed: run it again.
( new \Lumia\Tools\Modules\Motion\Controls() )->register();

$lm_elements = array( 'heading', 'section', 'lm-test-element' );
foreach ( $lm_elements as $lm_name ) {
	$lm_controls = apply_filters( "bricks/elements/{$lm_name}/controls", array() );
	foreach ( $lm_keys as $lm_key ) {
		if ( ! isset( $lm_controls[ $lm_key ] ) ) {
			$lm_fail( "{$lm_name}: control {$lm_key} is missing." );
		}
	}
	if ( count( array_intersect( array_keys( $lm_controls ), $lm_keys ) ) !== 24 ) {
		$lm_fail( "{$lm_name}: expected exactly 24 Motion controls." );
	}
	foreach ( $lm_keys as $lm_key ) {
		if ( 'lm_motion' !== ( $lm_controls[ $lm_key ]['group'] ?? null ) ) {
			$lm_fail( "{$lm_name}: {$lm_key} is not in the lm_motion group." );
		}
		if ( 'style' !== ( $lm_controls[ $lm_key ]['tab'] ?? null ) ) {
			$lm_fail( "{$lm_name}: {$lm_key} is not on the style tab." );
		}
	}
	foreach ( $lm_variables as $lm_key => $lm_variable ) {
		$lm_css = $lm_controls[ $lm_key ]['css'] ?? null;
		if ( ! is_array( $lm_css ) || 1 !== count( $lm_css ) || $lm_variable !== ( $lm_css[0]['property'] ?? null ) || '' !== ( $lm_css[0]['selector'] ?? null ) ) {
			$lm_fail( "{$lm_name}: {$lm_key} must write {$lm_variable} on the element itself." );
		}
	}
	foreach ( array( '_lmHelp', '_lmAdvancedSep', '_lmParamsSep' ) as $lm_key ) {
		if ( isset( $lm_controls[ $lm_key ]['css'] ) ) {
			$lm_fail( "{$lm_name}: {$lm_key} must not have a css key." );
		}
	}

	$lm_groups = apply_filters( "bricks/elements/{$lm_name}/control_groups", array() );
	if ( 'style' !== ( $lm_groups['lm_motion']['tab'] ?? null ) || '' === ( $lm_groups['lm_motion']['title'] ?? '' ) ) {
		$lm_fail( "{$lm_name}: the lm_motion group is missing or has no title." );
	}

	// The builder script is declared for builder requests only: on the front end it would make
	// Bricks print a data-script-id attribute on every element.
	$lm_scripts = apply_filters( "bricks/elements/{$lm_name}/scripts", array( 'bricksSwiper' ) );
	if ( array( 'bricksSwiper' ) !== array_values( $lm_scripts ) ) {
		$lm_fail( "{$lm_name}: outside the builder the scripts must be left untouched." );
	}
	$_GET['bricks'] = 'run'; // Simulated builder request (BRICKS_BUILDER_PARAM).
	$lm_scripts     = apply_filters( "bricks/elements/{$lm_name}/scripts", array() );
	$lm_twice       = apply_filters( "bricks/elements/{$lm_name}/scripts", array( 'bricksSwiper', 'lumiaMotionBuilder' ) );
	unset( $_GET['bricks'] );
	if ( ! in_array( 'lumiaMotionBuilder', $lm_scripts, true ) ) {
		$lm_fail( "{$lm_name}: the lumiaMotionBuilder script is missing in the builder." );
	}
	// Scripts already declared by the element are kept, and ours is not added twice.
	if ( array( 'bricksSwiper', 'lumiaMotionBuilder' ) !== array_values( $lm_twice ) ) {
		$lm_fail( "{$lm_name}: existing scripts must be kept and ours not duplicated." );
	}
}

// Conditions, types and units.
$lm_controls = apply_filters( 'bricks/elements/heading/controls', array() );
$lm_expect   = array(
	array( '_lmEffect', 'type', 'select' ),
	array( '_lmStartAt', 'unit', '%' ),
	array( '_lmEndAt', 'unit', '%' ),
	array( '_lmStart', 'placeholder', 'top 90%' ),
	array( '_lmEaseCustom', 'placeholder', 'cubic-bezier(0.2, 0.8, 0.2, 1)' ),
	array( '_lmDuration', 'required', array( '_lmTempo', '=', 'custom' ) ),
	array( '_lmEaseCustom', 'required', array( '_lmEase', '=', 'custom' ) ),
	array( '_lmUnderlineColor', 'type', 'color' ),
	array( '_lmHelp', 'type', 'info' ),
);
foreach ( $lm_expect as $lm_row ) {
	if ( ( $lm_controls[ $lm_row[0] ][ $lm_row[1] ] ?? null ) !== $lm_row[2] ) {
		$lm_fail( "{$lm_row[0]}: wrong {$lm_row[1]}." );
	}
}
foreach ( array( '_lmStartAt', '_lmEndAt' ) as $lm_key ) {
	if ( 'number' !== $lm_controls[ $lm_key ]['type'] ) {
		$lm_fail( "{$lm_key}: must be a number." );
	}
}

foreach ( array( '_lmUnderlineGap', '_lmUnderlineThickness' ) as $lm_key ) {
	if ( empty( $lm_controls[ $lm_key ]['description'] ) || true !== $lm_controls[ $lm_key ]['units'] ) {
		$lm_fail( "{$lm_key}: must carry units and a description telling to include the unit." );
	}
}

// Effect options: every catalog effect plus none, each with a thumbnail span.
$lm_options = $lm_controls['_lmEffect']['options'] ?? array();
foreach ( array_merge( array_keys( \Lumia\Tools\Modules\Motion\Effects::catalog() ), array( 'none' ) ) as $lm_slug ) {
	if ( ! isset( $lm_options[ $lm_slug ] ) ) {
		$lm_fail( "_lmEffect: option {$lm_slug} is missing." );
	}
}
if ( false === strpos( $lm_options['fade'], '<span class="lm-thumb lm-thumb--fade"></span>' ) ) {
	$lm_fail( '_lmEffect: the fade option has no thumbnail.' );
}
if ( array_keys( $lm_controls['_lmTempo']['options'] ) !== \Lumia\Tools\Modules\Motion\Effects::TEMPO_KEYWORDS
	|| array_keys( $lm_controls['_lmEase']['options'] ) !== \Lumia\Tools\Modules\Motion\Effects::EASES
	|| array_keys( $lm_controls['_lmStartEdge']['options'] ) !== \Lumia\Tools\Modules\Motion\Effects::EDGES ) {
	$lm_fail( 'Tempo, ease or edge options do not match the Effects constants.' );
}

// variable_map(): the controls that write a variable, nothing else.
if ( \Lumia\Tools\Modules\Motion\Controls::variable_map() !== $lm_variables ) {
	$lm_fail( 'variable_map() does not match the variables written by the controls.' );
}

// Through Bricks itself: the real loader merges our controls with the element's own.
$_GET['bricks'] = 'run';
\Bricks\Elements::load_element( 'heading' );
unset( $_GET['bricks'] );
$lm_loaded = \Bricks\Elements::$elements['heading'] ?? array();
if ( ! isset( $lm_loaded['controls']['_lmEffect'], $lm_loaded['controls']['text'], $lm_loaded['controlGroups']['lm_motion'] ) || ! in_array( 'lumiaMotionBuilder', (array) $lm_loaded['scripts'], true ) ) {
	$lm_fail( 'Bricks did not load the Motion panel on the heading element.' );
}

WP_CLI::success( 'ok' );
