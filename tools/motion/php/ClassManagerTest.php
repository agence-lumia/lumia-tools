<?php
/**
 * Tests for the pure parts of ClassManager: creation and migration plans, the rewrite of a
 * class's custom CSS, the scan of Bricks elements, the legacy snippet files, the category.
 *
 * @package Lumia\Tools
 */

// Development-only test file: it is not plugin code. Assertions read as
// "actual === expected", so Yoda conditions would only make them harder to read.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.PHP.YodaConditions, WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions

declare( strict_types=1 );

use Lumia\Tools\Modules\Motion\ClassManager;
use Lumia\Tools\Modules\Motion\Effects;
use Lumia\Tools\Modules\Motion\Legacy;

/**
 * A Bricks global class as stored in the option.
 *
 * @param string $id   Class ID.
 * @param string $name Class name.
 * @return array<string, mixed>
 */
$lm_class = static function ( string $id, string $name ): array {
	return [
		'id'       => $id,
		'name'     => $name,
		'settings' => [],
	];
};

return [
	'creation skips a class that exists and one whose legacy class exists' => static function () use ( $lm_class ): void {
		$plan = ClassManager::plan_creation( [ $lm_class( 'a', 'sk-fade' ), $lm_class( 'b', 'lm-reveal' ) ] );
		lm_assert( in_array( 'lm-blur-in', $plan, true ), 'lm-blur-in is missing from ' . implode( ',', $plan ) );
		lm_assert( ! in_array( 'lm-fade', $plan, true ), 'lm-fade must wait for the migration of its legacy class' );
		lm_assert( ! in_array( 'lm-reveal', $plan, true ), 'lm-reveal exists' );
	},
	'creation on an empty site gives the ten classes, in catalog order' => static function (): void {
		$plan = ClassManager::plan_creation( [] );
		$want = array_column( Effects::catalog(), 'class' );
		lm_assert( $plan === $want, 'got ' . implode( ',', $plan ) );
		lm_assert( count( $plan ) === 10, 'ten classes' );
	},
	'creation once everything exists gives nothing'        => static function (): void {
		$classes = [];
		foreach ( Effects::catalog() as $definition ) {
			$classes[] = [
				'id'   => 'x' . count( $classes ),
				'name' => $definition['class'],
			];
		}
		lm_assert( ClassManager::plan_creation( $classes ) === [], 'nothing to create' );
	},
	'creation reads every legacy class from Legacy, not a copy' => static function () use ( $lm_class ): void {
		$classes = [];
		foreach ( array_keys( Legacy::CLASS_ALIASES ) as $i => $legacy ) {
			$classes[] = $lm_class( 'l' . $i, $legacy );
		}
		lm_assert( ClassManager::plan_creation( $classes ) === [], 'a site with the ten legacy classes needs no new class' );
	},
	'creation ignores a malformed entry'                   => static function (): void {
		$plan = ClassManager::plan_creation( [ 'oops', 3, [ 'id' => 'z' ], [ 'name' => [ 'sk-fade' ] ] ] );
		lm_assert( count( $plan ) === 10, 'garbage entries must not hide a class' );
	},
	'migration renames what is free and reports what collides' => static function () use ( $lm_class ): void {
		$plan = ClassManager::plan_migration(
			[ $lm_class( 'a', 'sk-fade' ), $lm_class( 'b', 'sk-reveal' ), $lm_class( 'c', 'lm-reveal' ) ]
		);
		lm_assert(
			$plan['rename'] === [
				'a' => [
					'from' => 'sk-fade',
					'to'   => 'lm-fade',
				],
			],
			'rename ' . json_encode( $plan['rename'] )
		);
		lm_assert( $plan['conflicts'] === [ 'sk-reveal' ], 'conflicts ' . json_encode( $plan['conflicts'] ) );
	},
	'migration keeps the id as the key, numeric ids included' => static function () use ( $lm_class ): void {
		$plan = ClassManager::plan_migration( [ $lm_class( '552528', 'sk-counter' ) ] );
		lm_assert( array_keys( $plan['rename'] ) === [ 552528 ], 'PHP turns a numeric string key into an int' );
		lm_assert( array_key_exists( '552528', $plan['rename'] ), 'the string id still finds it' );
	},
	'migration of a migrated site is empty'                => static function () use ( $lm_class ): void {
		$plan = ClassManager::plan_migration( [ $lm_class( 'a', 'lm-fade' ), $lm_class( 'b', 'nodrag' ) ] );
		lm_assert( $plan['rename'] === [] && $plan['conflicts'] === [], 'nothing left to do' );
	},
	'migration never renames two classes into one name'    => static function () use ( $lm_class ): void {
		$plan = ClassManager::plan_migration( [ $lm_class( 'a', 'sk-fade' ), $lm_class( 'b', 'sk-fade' ) ] );
		lm_assert( array_keys( $plan['rename'] ) === [ 'a' ], 'only the first one is renamed' );
		lm_assert( $plan['conflicts'] === [ 'sk-fade' ], 'the second one is a conflict: ' . json_encode( $plan['conflicts'] ) );
	},
	'migration target comes from Legacy'                   => static function () use ( $lm_class ): void {
		$classes = [];
		foreach ( array_keys( Legacy::CLASS_ALIASES ) as $i => $legacy ) {
			$classes[] = $lm_class( 'i' . $i, $legacy );
		}
		$plan = ClassManager::plan_migration( $classes );
		lm_assert( count( $plan['rename'] ) === 10 && $plan['conflicts'] === [], 'ten renames' );
		foreach ( $plan['rename'] as $rename ) {
			lm_assert( Legacy::new_class_name( $rename['from'] ) === $rename['to'], $rename['from'] . ' goes to ' . Legacy::new_class_name( $rename['from'] ) );
		}
	},
	'rewrite replaces the class as a whole word, in every custom CSS key' => static function (): void {
		$settings = [
			'_cssCustom'                  => ".sk-fade:hover{}\n.sk-fade-extra{}\n.sk-fade .a, a.sk-fade{}",
			'_cssCustom:mobile_portrait'  => '.sk-fade{opacity:1}',
			'_cssCustom:tablet_landscape' => '.sk-fade.is-on{}',
			'_typography'                 => [ 'color' => '.sk-fade' ],
			'_margin'                     => '.sk-fade',
		];
		$out      = ClassManager::rewrite_class_css( $settings, 'sk-fade', 'lm-fade' );
		lm_assert( $out['_cssCustom'] === ".lm-fade:hover{}\n.sk-fade-extra{}\n.lm-fade .a, a.lm-fade{}", 'base ' . $out['_cssCustom'] );
		lm_assert( $out['_cssCustom:mobile_portrait'] === '.lm-fade{opacity:1}', 'mobile_portrait' );
		lm_assert( $out['_cssCustom:tablet_landscape'] === '.lm-fade.is-on{}', 'tablet_landscape' );
		lm_assert( $out['_typography'] === [ 'color' => '.sk-fade' ], 'only _cssCustom keys are touched' );
		lm_assert( $out['_margin'] === '.sk-fade', 'only _cssCustom keys are touched (string)' );
	},
	'rewrite leaves settings without the class alone'      => static function (): void {
		$settings = [
			'_cssCustom' => '.other{color:red}',
			'_lmEffect'  => 'fade',
		];
		lm_assert( ClassManager::rewrite_class_css( $settings, 'sk-fade', 'lm-fade' ) === $settings, 'unchanged' );
		lm_assert( ClassManager::rewrite_class_css( [], 'sk-fade', 'lm-fade' ) === [], 'empty' );
	},
	'rewrite copes with a non-string value and regex characters' => static function (): void {
		$settings = [
			'_cssCustom'        => [ 'not' => 'a string' ],
			'_cssCustom:tablet' => '.a$1b.sk-fade{}',
		];
		$out      = ClassManager::rewrite_class_css( $settings, 'sk-fade', 'lm-$0fade' );
		lm_assert( $out['_cssCustom'] === [ 'not' => 'a string' ], 'array value kept' );
		lm_assert( $out['_cssCustom:tablet'] === '.a$1b.lm-$0fade{}', 'replacement is literal: ' . $out['_cssCustom:tablet'] );
	},
	'legacy css is found by the class selector and the underline variable' => static function (): void {
		lm_assert( ClassManager::has_legacy_css( [ '_cssCustom' => '.x .sk-fade{}' ] ), 'selector' );
		lm_assert( ClassManager::has_legacy_css( [ '_cssCustom:mobile_portrait' => 'a{--sk-hover-underline-gap:3px}' ] ), 'variable' );
		lm_assert( ClassManager::has_legacy_css( [ 'customCss' => '.sk-counter{}' ] ), 'page or theme custom CSS' );
		lm_assert( ClassManager::has_legacy_css( [ 'general' => [ 'customCss' => '.sk-counter{}' ] ] ), 'nested (theme styles)' );
		lm_assert( ! ClassManager::has_legacy_css( [ '_cssCustom' => '.mask-image{} .task-list{} .lm-fade{}' ] ), 'no false positive' );
		lm_assert( ! ClassManager::has_legacy_css( [ '_typography' => '.sk-fade' ] ), 'only custom CSS keys count' );
	},
	'scan counts elements by class id and by raw class name' => static function (): void {
		$elements = [
			[
				'id'       => 'e1',
				'settings' => [ '_cssGlobalClasses' => [ 'idfade', 'other' ] ],
			],
			[
				'id'       => 'e2',
				'settings' => [ '_cssGlobalClasses' => [ 'other' ] ],
			],
			[
				'id'       => 'e3',
				'settings' => [ '_cssClasses' => 'card sk-reveal  big' ],
			],
			[
				'id'       => 'e4',
				'settings' => [ '_cssClasses' => 'sk-reveal-more' ],
			],
			[
				'id'       => 'e5',
				'settings' => [ '_cssCustom' => '.sk-fade{}' ],
			],
			[
				'id'       => 'e6',
				'settings' => [],
			],
		];
		$scan     = ClassManager::scan_elements( $elements, [ 'idfade' ], [ 'sk-fade', 'sk-reveal' ] );
		lm_assert( $scan['by_id'] === [ 'e1' ], 'by_id ' . json_encode( $scan['by_id'] ) );
		lm_assert( $scan['raw'] === [ 'e3' ], 'raw ' . json_encode( $scan['raw'] ) );
		lm_assert( $scan['css'] === [ 'e5' ], 'css ' . json_encode( $scan['css'] ) );
	},
	'scan tolerates malformed elements'                    => static function (): void {
		$scan = ClassManager::scan_elements(
			[
				'oops',
				[ 'id' => 'a' ],
				[
					'id'       => 'b',
					'settings' => 'x',
				],
				[
					'id'       => 'c',
					'settings' => [ '_cssGlobalClasses' => 'idfade' ],
				],
				[
					'id'       => 'd',
					'settings' => [ '_cssGlobalClasses' => [ 7, null, 'idfade' ] ],
				],
			],
			[ 'idfade' ],
			[]
		);
		lm_assert( $scan['by_id'] === [ 'd' ], 'by_id ' . json_encode( $scan['by_id'] ) );
	},
	'snippet files are reported, never the unrelated ones' => static function (): void {
		$dir = sys_get_temp_dir() . '/lm-snippets-' . bin2hex( random_bytes( 4 ) );
		mkdir( $dir );
		file_put_contents( $dir . '/4-former.php', "<?php\n// @name: " . Legacy::SNIPPET_NAME . "\n\$c = 'sk-fade sk-counter'; window." . Legacy::ENGINE_GLOBAL . ";\n" );
		file_put_contents( $dir . '/6-blur.php', "<?php\n.mask-image{ -webkit-mask-image: x; } .task-sk-fade{}\n" );
		file_put_contents( $dir . '/9-var.php', "<?php\n\$css = 'a{--sk-hover-underline-gap:2px}';\n" );
		file_put_contents( $dir . '/readme.txt', 'sk-fade' );
		try {
			$hits = ClassManager::snippet_hits( $dir );
		} finally {
			array_map( 'unlink', (array) glob( $dir . '/*' ) );
			rmdir( $dir );
		}
		lm_assert( count( $hits ) === 2, 'two files: ' . json_encode( $hits ) );
		lm_assert( strpos( $hits[0], '4-former.php' ) !== false, 'first ' . $hits[0] );
		lm_assert( strpos( $hits[0], 'sk-fade' ) !== false && strpos( $hits[0], 'sk-counter' ) !== false, 'names listed: ' . $hits[0] );
		lm_assert( strpos( $hits[0], Legacy::ENGINE_GLOBAL ) !== false, 'engine global listed: ' . $hits[0] );
		lm_assert( strpos( $hits[1], '9-var.php' ) !== false, 'second ' . $hits[1] );
	},
	'snippet scan of a missing directory is empty'         => static function (): void {
		lm_assert( ClassManager::snippet_hits( sys_get_temp_dir() . '/lm-does-not-exist-' . bin2hex( random_bytes( 4 ) ) ) === [], 'no directory, no hit' );
	},
	'category is found by name, created otherwise'         => static function (): void {
		$found = ClassManager::with_category(
			[
				[
					'id'   => 'c1',
					'name' => 'UTILITY',
				],
				[
					'id'   => 'c2',
					'name' => 'motion',
				],
			]
		);
		lm_assert( $found['id'] === 'c2' && ! $found['created'], 'a category named Motion, whatever the case, is reused' );
		lm_assert( count( $found['categories'] ) === 2, 'nothing added' );

		$made = ClassManager::with_category(
			[
				[
					'id'   => 'c1',
					'name' => 'UTILITY',
				],
			]
		);
		lm_assert( $made['created'] && count( $made['categories'] ) === 2, 'created' );
		lm_assert(
			end( $made['categories'] ) === [
				'id'   => $made['id'],
				'name' => ClassManager::CATEGORY_NAME,
			],
			'new category'
		);
		lm_assert( $made['id'] !== 'c1' && preg_match( '/^[a-z]{6}$/', $made['id'] ) === 1, 'id ' . $made['id'] );
	},
	'new ids are six letters and never taken'              => static function (): void {
		$taken = [];
		for ( $i = 0; $i < 200; $i++ ) {
			$id = ClassManager::new_id( $taken );
			lm_assert( preg_match( '/^[a-z]{6}$/', $id ) === 1, 'format ' . $id );
			lm_assert( ! in_array( $id, $taken, true ), 'duplicate ' . $id );
			$taken[] = $id;
		}
	},
];
