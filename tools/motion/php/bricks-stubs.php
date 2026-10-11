<?php
/**
 * Minimal Bricks stand-ins for the tests of Marker::uses_class_selector() and Marker::render_context().
 *
 * Only what that method calls, with a state the tests set by hand. Loaded by the test that
 * needs it, after the tests that must run without any Bricks class. Development only, never
 * shipped.
 *
 * @package Lumia\Tools
 */

// Development-only file: the point is to declare the Bricks classes.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, Generic.Files.OneObjectStructurePerFile, Universal.Namespaces.DisallowCurlyBraceSyntax, Universal.Namespaces.OneDeclarationPerFile, Generic.CodeAnalysis.UnusedFunctionParameter

declare( strict_types=1 );

namespace Bricks;

if ( ! class_exists( '\Bricks\Query' ) ) {
	/**
	 * Query stand-in: the loop state is set by the test.
	 */
	class Query {

		/**
		 * What is_looping() answers, as Bricks does under the `bricks/query/force_is_looping` filter.
		 *
		 * @var bool
		 */
		public static bool $forced = false;

		/**
		 * Query being looped, false when none.
		 *
		 * @var string|false
		 */
		public static $looping = false;

		/**
		 * Element that holds the loop of the query being looped.
		 *
		 * @var string|false
		 */
		public static $loop_element = false;

		/**
		 * Whether a query loops; true whenever the filter forces it.
		 */
		public static function is_looping(): bool {
			return self::$forced || false !== self::$looping;
		}

		/**
		 * The query being looped, false when none.
		 *
		 * @return string|false
		 */
		public static function is_any_looping() {
			return self::$looping;
		}

		/**
		 * Element that holds the loop.
		 *
		 * @param string $query Query ID.
		 * @return string|false
		 */
		public static function get_query_element_id( $query = '' ) {
			return '' === $query ? false : self::$loop_element;
		}
	}
}

if ( ! class_exists( '\Bricks\Helpers' ) ) {
	/**
	 * Helpers stand-in: no global element.
	 */
	class Helpers {

		/**
		 * Global element ID of an element: none here.
		 *
		 * @param array<mixed, mixed> $element Element data.
		 * @param string              $key     Key to read.
		 * @return string
		 */
		public static function get_global_element( $element = [], $key = '' ) {
			return '';
		}
	}
}

if ( ! class_exists( '\Bricks\Frontend' ) ) {
	/**
	 * Frontend stand-in: the render area and the render context stack, set by the test.
	 */
	class Frontend {

		/**
		 * Area being rendered.
		 *
		 * @var string
		 */
		public static $area = 'content';

		/**
		 * One entry per render_data() in progress, outermost first.
		 *
		 * @var array<int, array{source_area: string, source_post_id: int}>
		 */
		public static $render_context_stack = [];
	}
}
