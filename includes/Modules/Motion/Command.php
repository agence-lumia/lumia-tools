<?php
namespace Lumia\Tools\Modules\Motion;

defined( 'ABSPATH' ) || exit;

/**
 * WP-CLI commands of the Motion module: `wp lumia motion classes|migrate`.
 *
 * Writing needs an administrator (--user=<admin>): Bricks records who changed the global classes.
 * Listing does not. Design and pitfalls: ClassManager.
 */
final class Command {

	/** Report lines printed per list before the rest is summarized. */
	private const MAX_HITS = 25;

	/**
	 * Creates the missing Motion classes and lists the state of all of them.
	 *
	 * Each class that is missing is created empty, locked, in the Motion category. A class is not
	 * created while its legacy twin exists (the migration renames the twin), and an existing class is
	 * never modified. An administrator (--user) is needed only when there is a class to create.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp lumia motion classes --user=admin
	 *
	 * @param string[]              $args       Positional arguments (none).
	 * @param array<string, string> $assoc_args Options.
	 */
	public function classes( array $args, array $assoc_args ): void {
		$this->require_bricks();
		if ( [] !== ClassManager::missing() ) {
			$this->require_user();
		}

		try {
			$created = ClassManager::create_missing();
		} catch ( \RuntimeException $e ) {
			\WP_CLI::error( $e->getMessage() );
			return;
		}

		$existing = get_option( ClassManager::OPTION_CLASSES, [] );
		$names    = [];
		foreach ( is_array( $existing ) ? $existing : [] as $class ) {
			if ( is_array( $class ) && is_string( $class['name'] ?? null ) ) {
				$names[ $class['name'] ] = (string) ( $class['id'] ?? '' );
			}
		}

		$rows = [];
		foreach ( Effects::catalog() as $definition ) {
			$name   = $definition['class'];
			$legacy = array_search( $name, $this->legacy_targets(), true );

			if ( in_array( $name, $created, true ) ) {
				$state = 'created';
			} elseif ( isset( $names[ $name ] ) ) {
				$state = 'present';
			} elseif ( false !== $legacy && isset( $names[ $legacy ] ) ) {
				$state = 'waits for the migration of ' . $legacy;
			} else {
				$state = 'missing';
			}

			$rows[] = [
				'class' => $name,
				'id'    => $names[ $name ] ?? '',
				'state' => $state,
			];
		}

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			\WP_CLI::print_value( $rows, [ 'format' => 'json' ] );
			return;
		}

		\WP_CLI\Utils\format_items( 'table', $rows, [ 'class', 'id', 'state' ] );
		if ( [] === $created ) {
			\WP_CLI::success( 'No class to create.' );
		} else {
			\WP_CLI::success( sprintf( '%d class(es) created: %s.', count( $created ), implode( ', ', $created ) ) );
		}
	}

	/**
	 * Moves a site from the classes of the former animation snippet to the Motion classes.
	 *
	 * Without --apply nothing is written: the command lists the classes it would rename, the
	 * classes it cannot (a Motion class of the same name already exists: nothing is merged), the
	 * elements and pages that use them, and everything that still names a legacy class or variable,
	 * which is never rewritten automatically: selectors that stop matching once the classes are
	 * renamed (to fix before or right after), names that keep working through the compatibility
	 * setting, and published FluentSnippets files.
	 *
	 * With --apply each class is renamed in place (same ID, so every element follows), moved to the
	 * Motion category, its own custom CSS is rewritten, the classes are saved through Bricks and the
	 * Bricks CSS files are regenerated. Running it again changes nothing.
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Write the changes. This is a dry run without it.
	 *
	 * [--dry-run]
	 * : Report only. This is the default; the flag exists to say it out loud.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp lumia motion migrate
	 *     wp lumia motion migrate --apply --user=admin
	 *
	 * @param string[]              $args       Positional arguments (none).
	 * @param array<string, string> $assoc_args Options.
	 */
	public function migrate( array $args, array $assoc_args ): void {
		$this->require_bricks();

		$apply = ! empty( $assoc_args['apply'] );
		if ( $apply && ! empty( $assoc_args['dry-run'] ) ) {
			\WP_CLI::error( '--apply and --dry-run exclude each other.' );
			return;
		}
		if ( $apply ) {
			$this->require_user();
		}

		try {
			$result = ClassManager::migrate( $apply );
		} catch ( \RuntimeException $e ) {
			\WP_CLI::error( $e->getMessage() );
			return;
		}

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			\WP_CLI::print_value(
				array_merge( [ 'applied' => $apply ], $result ),
				[ 'format' => 'json' ]
			);
			return;
		}

		$this->report( $result, $apply );
	}

	/* ================================================================
	 * OUTPUT
	 * ================================================================ */

	/**
	 * Prints the result of a migration.
	 *
	 * @param array{renamed: string[], conflicts: string[], elements: int, posts: int, selectors: string[], compat: string[], snippets: string[], css_hits: string[]} $result Result of ClassManager::migrate().
	 * @param bool                                                                                                                                                      $apply  Whether the changes were written.
	 */
	private function report( array $result, bool $apply ): void {
		$rows = [];
		foreach ( $result['renamed'] as $from ) {
			$rows[] = [
				'class'  => $from,
				'to'     => (string) Legacy::new_class_name( $from ),
				'result' => $apply ? 'renamed' : 'would be renamed',
			];
		}
		foreach ( $result['conflicts'] as $from ) {
			$rows[] = [
				'class'  => $from,
				'to'     => (string) Legacy::new_class_name( $from ),
				'result' => 'conflict',
			];
		}

		if ( [] === $rows ) {
			\WP_CLI::log( 'No legacy class to rename.' );
		} else {
			\WP_CLI\Utils\format_items( 'table', $rows, [ 'class', 'to', 'result' ] );
		}

		foreach ( $result['conflicts'] as $from ) {
			\WP_CLI::warning( sprintf( '%1$s is left as it is: a class named %2$s already exists (or another class would take that name). Nothing is merged; merge them by hand in Bricks.', $from, (string) Legacy::new_class_name( $from ) ) );
		}

		\WP_CLI::log(
			sprintf(
				'%1$d element(s) on %2$d post(s) use a class %3$s.',
				$result['elements'],
				$result['posts'],
				$apply ? 'that was renamed' : 'that would be renamed'
			)
		);

		$this->list_hits(
			$result['selectors'],
			$apply
				? 'selector(s) stopped matching: the classes are renamed, the markup carries the new names, and the compatibility setting does not bring the old ones back. Fix them now'
				: 'selector(s) will stop matching at --apply: the markup will carry the new names, and the compatibility setting does not bring the old ones back. Fix them before, or right after',
			true
		);
		$this->list_hits(
			$result['compat'],
			'place(s) keep working through the compatibility setting (names typed in a CSS classes field, underline variables, selectors of classes that keep their name). They stop the day it is turned off',
			false
		);
		$this->list_hits( $result['snippets'], 'published snippet(s) still name the former classes. Deactivate (or delete) them in FluentSnippets', true );

		if ( ! $apply ) {
			if ( [] !== $result['renamed'] ) {
				\WP_CLI::success( 'Dry run: nothing was written. Run it again with --apply to rename the classes.' );
			} else {
				\WP_CLI::success( 'Dry run: nothing was written, and there is nothing to rename.' );
			}
			return;
		}

		\WP_CLI::success( sprintf( '%d class(es) renamed.', count( $result['renamed'] ) ) );
		\WP_CLI::log( 'Next:' );
		\WP_CLI::log( sprintf( '  1. Deactivate (or delete) the "%s" snippet in FluentSnippets (it is not touched).', Legacy::SNIPPET_NAME ) );
		\WP_CLI::log( '  2. Fix the selectors listed above, if there are any.' );
		\WP_CLI::log( '  3. Purge the page cache.' );
		\WP_CLI::log( '  4. Check the site: the animations must be the same.' );
		\WP_CLI::log( '  5. Turn off the compatibility setting of the Motion module once the "keep working" list is empty.' );
	}

	/**
	 * Prints a list of places, capped, under a one-line heading.
	 *
	 * @param string[] $hits    Report lines.
	 * @param string   $heading What the lines are, after the count.
	 * @param bool     $warn    Whether the heading is a warning (something breaks) or a plain note.
	 */
	private function list_hits( array $hits, string $heading, bool $warn ): void {
		if ( [] === $hits ) {
			return;
		}

		$line = sprintf( '%d %s:', count( $hits ), $heading );
		if ( $warn ) {
			\WP_CLI::warning( $line );
		} else {
			\WP_CLI::log( $line );
		}
		foreach ( array_slice( $hits, 0, self::MAX_HITS ) as $hit ) {
			\WP_CLI::log( '  - ' . $hit );
		}
		if ( count( $hits ) > self::MAX_HITS ) {
			\WP_CLI::log( sprintf( '  ... and %d more (use --format=json for the whole list).', count( $hits ) - self::MAX_HITS ) );
		}
	}

	/* ================================================================
	 * GUARDS
	 * ================================================================ */

	/**
	 * Stops unless Bricks is the active theme.
	 */
	private function require_bricks(): void {
		if ( ! Module::is_bricks_active() ) {
			\WP_CLI::error( 'Bricks is not the active theme: the Motion classes only exist on a Bricks site.' );
		}
	}

	/**
	 * Stops unless an administrator is given: Bricks stores who last changed the classes, and only
	 * an administrator may change them.
	 */
	private function require_user(): void {
		if ( 0 === get_current_user_id() ) {
			\WP_CLI::error( 'Give an administrator with --user=<login or ID>: Bricks records who changed the global classes.' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			\WP_CLI::error( 'The user given with --user is not an administrator: only an administrator may change the global classes.' );
		}
	}

	/**
	 * Motion class name => legacy class name.
	 *
	 * Private on purpose: a public method would be listed as a subcommand.
	 *
	 * @return array<string, string>
	 */
	private function legacy_targets(): array {
		$map = [];
		foreach ( array_keys( Legacy::CLASS_ALIASES ) as $legacy ) {
			$map[ $legacy ] = (string) Legacy::new_class_name( $legacy );
		}

		return $map;
	}
}
