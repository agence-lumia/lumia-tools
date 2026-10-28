<?php
namespace Lumia\Tools\Modules\McpConnect\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * FluentSnippets: list, read, create, update, publish, delete snippets.
 *
 * Every write goes through FluentSnippets' own code (Helper::createSnippet(),
 * Helper::updateSnippet(), the Snippet model) and fires its actions, so its
 * index and cache stay in step and its checks apply: a PHP snippet whose
 * syntax is invalid, or that starts with `<?php`, is refused before anything
 * is written, so a broken snippet never reaches the site. Its REST authoring
 * switch is not involved: these abilities sit behind manage_options and the
 * MCP Connect consent.
 */
final class Snippets implements Family {

	/** Snippet types of FluentSnippets. */
	const TYPES = [ 'PHP', 'php_content', 'css', 'js' ];

	public static function label(): string {
		return __( 'Snippets (FluentSnippets)', 'lumia-tools' );
	}

	public static function description(): string {
		return __( 'List, read, create, update, publish and delete FluentSnippets snippets (PHP, CSS, JS, HTML).', 'lumia-tools' );
	}

	public static function available(): bool {
		return class_exists( '\FluentSnippets\App\Helpers\Helper' ) && class_exists( '\FluentSnippets\App\Model\Snippet' );
	}

	public static function definitions(): array {
		$file   = [
			'type'        => 'string',
			'description' => 'Snippet file name as listed by lumia/list-snippets, e.g. 3-gsap-hero.php.',
		];
		$fields = [
			'name'        => [
				'type'        => 'string',
				'description' => 'Snippet name.',
			],
			'description' => [
				'type' => 'string',
			],
			'type'        => [
				'type'        => 'string',
				'enum'        => self::TYPES,
				'description' => 'PHP (PHP code without the opening tag), php_content (HTML with PHP, printed where run_at says), css, js.',
			],
			'run_at'      => [
				'type'        => 'string',
				'description' => 'Where it runs. PHP: all (everywhere), backend, frontend… CSS/JS/HTML: wp_head, wp_footer, wp_body_open, admin_head, admin_footer, before_content, after_content, shortcode.',
			],
			'priority'    => [
				'type'    => 'integer',
				'minimum' => 1,
			],
			'tags'        => [
				'type'        => 'string',
				'description' => 'Comma-separated tags.',
			],
			'group'       => [
				'type' => 'string',
			],
			'condition'   => [
				'type'        => 'object',
				'description' => 'FluentSnippets condition object ({status: yes|no, run_if: assertive|negative, items: [...]}), as lumia/get-snippet returns it.',
			],
			'status'      => [
				'type'        => 'string',
				'enum'        => [ 'draft', 'published' ],
				'description' => 'published runs the snippet on the site.',
			],
		];

		return [
			'list-snippets'  => [
				'label'       => __( 'List the snippets', 'lumia-tools' ),
				'description' => 'Lists the FluentSnippets snippets: file name, name, type, status, where it runs, priority, tags, and whether FluentSnippets disabled it after a fatal error.',
				'input'       => [
					'status' => [
						'type' => 'string',
						'enum' => [ 'draft', 'published' ],
					],
				],
				'execute'     => [ self::class, 'list' ],
				'capability'  => 'manage_options',
				'readonly'    => true,
				'idempotent'  => true,
			],
			'get-snippet'    => [
				'label'       => __( 'Read a snippet', 'lumia-tools' ),
				'description' => 'Returns a FluentSnippets snippet: its settings (meta) and its code.',
				'input'       => [ 'file_name' => $file ],
				'required'    => [ 'file_name' ],
				'execute'     => [ self::class, 'get' ],
				'capability'  => 'manage_options',
				'readonly'    => true,
				'idempotent'  => true,
			],
			'create-snippet' => [
				'label'       => __( 'Create a snippet', 'lumia-tools' ),
				'description' => 'Creates a FluentSnippets snippet. PHP code is written without the opening <?php tag; CSS and JS without their <style>/<script> tags. FluentSnippets checks the code first and refuses invalid PHP. Created as a draft unless `status` is published. For a GSAP animation, wrap the code in lumiaMotion.ready(function (m) { … }) (Lümia Motion loads GSAP, ScrollTrigger and SplitText): for a snippet shared by many pages, Motion > Load GSAP on every page must be on; on one page, prefer the page custom code or a Bricks Code element, which load GSAP by themselves.',
				'input'       => array_merge( $fields, [ 'code' => [ 'type' => 'string' ] ] ),
				'required'    => [ 'name', 'type', 'run_at', 'code' ],
				'execute'     => [ self::class, 'create' ],
				'capability'  => 'manage_options',
				'readonly'    => false,
				'destructive' => false,
			],
			'update-snippet' => [
				'label'       => __( 'Update a snippet', 'lumia-tools' ),
				'description' => 'Changes a FluentSnippets snippet: only the fields given change (code, settings, status). `reactivate` clears the fatal error FluentSnippets recorded, so a fixed snippet runs again. Invalid PHP is refused.',
				'input'       => array_merge(
					$fields,
					[
						'file_name'  => $file,
						'code'       => [ 'type' => 'string' ],
						'reactivate' => [
							'type'    => 'boolean',
							'default' => false,
						],
					]
				),
				'required'    => [ 'file_name' ],
				'execute'     => [ self::class, 'update' ],
				'capability'  => 'manage_options',
				'readonly'    => false,
				'destructive' => true,
			],
			'delete-snippet' => [
				'label'       => __( 'Delete a snippet', 'lumia-tools' ),
				'description' => 'Deletes a FluentSnippets snippet and its file. To stop it without losing it, update its status to draft instead.',
				'input'       => [ 'file_name' => $file ],
				'required'    => [ 'file_name' ],
				'execute'     => [ self::class, 'delete' ],
				'capability'  => 'manage_options',
				'readonly'    => false,
				'destructive' => true,
			],
		];
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public static function list( array $args ): array {
		$model  = new \FluentSnippets\App\Model\Snippet();
		$errors = self::error_files();
		$filter = isset( $args['status'] ) ? (string) $args['status'] : '';
		$list   = [];

		foreach ( (array) $model->get( [ 'order' => 'new_first' ] ) as $snippet ) {
			$meta   = is_array( $snippet['meta'] ?? null ) ? $snippet['meta'] : [];
			$file   = basename( (string) ( $snippet['file'] ?? '' ) );
			$status = (string) ( $snippet['status'] ?? 'draft' );
			if ( '' !== $filter && $filter !== $status ) {
				continue;
			}
			$list[] = [
				'file_name'   => $file,
				'name'        => (string) ( $meta['name'] ?? '' ),
				'type'        => (string) ( $meta['type'] ?? '' ),
				'status'      => $status,
				'run_at'      => (string) ( $meta['run_at'] ?? '' ),
				'priority'    => (int) ( $meta['priority'] ?? 10 ),
				'tags'        => (string) ( $meta['tags'] ?? '' ),
				'description' => (string) ( $meta['description'] ?? '' ),
				'updated_at'  => (string) ( $meta['updated_at'] ?? '' ),
				'error'       => $errors[ $file ] ?? null,
			];
		}

		return [ 'snippets' => $list ];
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function get( array $args ) {
		$snippet = self::find( (string) ( $args['file_name'] ?? '' ) );
		if ( is_wp_error( $snippet ) ) {
			return $snippet;
		}

		$file = (string) $args['file_name'];

		return [
			'file_name' => $file,
			'meta'      => $snippet['meta'],
			'code'      => $snippet['code'],
			'status'    => $snippet['status'],
			'error'     => self::error_files()[ $file ] ?? null,
		];
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function create( array $args ) {
		$meta           = self::meta_from( $args );
		$meta['status'] = 'draft';

		$file = \FluentSnippets\App\Helpers\Helper::createSnippet(
			[
				'meta' => $meta,
				'code' => (string) ( $args['code'] ?? '' ),
			]
		);

		if ( is_wp_error( $file ) ) {
			return self::explain( $file );
		}
		if ( ! is_string( $file ) || '' === $file ) {
			return Registry::error( 'snippet_not_created', 'FluentSnippets did not create the snippet.' );
		}

		// createSnippet() forces a draft (or follows the auto-publish setting):
		// the asked status is applied afterwards, like the editor's toggle.
		if ( 'published' === ( $args['status'] ?? '' ) ) {
			$published = self::set_status( $file, 'published' );
			if ( is_wp_error( $published ) ) {
				return $published;
			}
		}

		return self::get( [ 'file_name' => $file ] );
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function update( array $args ) {
		$file    = (string) ( $args['file_name'] ?? '' );
		$snippet = self::find( $file );
		if ( is_wp_error( $snippet ) ) {
			return $snippet;
		}

		$meta = array_merge( $snippet['meta'], self::meta_from( $args ) );
		$code = array_key_exists( 'code', $args ) ? (string) $args['code'] : $snippet['code'];

		$result = \FluentSnippets\App\Helpers\Helper::updateSnippet(
			[
				'meta'       => $meta,
				'code'       => $code,
				'file_name'  => $file,
				'reactivate' => ! empty( $args['reactivate'] ),
			]
		);

		if ( is_wp_error( $result ) ) {
			return self::explain( $result );
		}

		if ( isset( $args['status'] ) && ( $snippet['status'] ?? '' ) !== $args['status'] ) {
			do_action( 'fluent_snippets/snippet_status_updated', $file ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.UseUnderscores -- FluentSnippets' own action, fired as its status endpoint does.
		}

		return self::get( [ 'file_name' => $file ] );
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function delete( array $args ) {
		$file    = (string) ( $args['file_name'] ?? '' );
		$snippet = self::find( $file );
		if ( is_wp_error( $snippet ) ) {
			return $snippet;
		}

		$deleted = ( new \FluentSnippets\App\Model\Snippet() )->deleteSnippet( $file );
		if ( is_wp_error( $deleted ) ) {
			return $deleted;
		}

		do_action( 'fluent_snippets/snippet_deleted', $file ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.UseUnderscores -- FluentSnippets' own action: it rebuilds its index.

		return [
			'file_name' => $file,
			'deleted'   => true,
		];
	}

	/**
	 * Publishes or unpublishes a snippet the way FluentSnippets' status
	 * endpoint does: meta rewritten, then its two actions.
	 *
	 * @return true|\WP_Error
	 */
	private static function set_status( string $file, string $status ) {
		$snippet = self::find( $file );
		if ( is_wp_error( $snippet ) ) {
			return $snippet;
		}

		$meta           = $snippet['meta'];
		$meta['status'] = 'published' === $status ? 'published' : 'draft';

		$result = ( new \FluentSnippets\App\Model\Snippet() )->updateSnippet( $file, $snippet['raw'], $meta );
		if ( is_wp_error( $result ) ) {
			return self::explain( $result );
		}

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.UseUnderscores -- FluentSnippets' own actions.
		do_action( 'fluent_snippets/snippet_status_updated', $file );
		do_action( 'fluent_snippets/snippet_updated', $file );
		// phpcs:enable

		return true;
	}

	/**
	 * A snippet by file name, read by FluentSnippets' model; the name must be a
	 * plain file name (no path).
	 *
	 * @return array{meta: array<string, mixed>, code: string, raw: string, status: mixed}|\WP_Error
	 */
	private static function find( string $file ) {
		if ( '' === $file || sanitize_file_name( $file ) !== $file || '.php' !== substr( $file, -4 ) ) {
			return Registry::error( 'invalid_snippet', 'Give the snippet file name as listed by lumia/list-snippets (e.g. 3-gsap-hero.php).' );
		}

		$snippet = ( new \FluentSnippets\App\Model\Snippet() )->findByFileName( $file );
		if ( is_wp_error( $snippet ) ) {
			return $snippet;
		}
		if ( ! is_array( $snippet ) ) {
			return Registry::error( 'invalid_snippet', 'No snippet named "' . $file . '".' );
		}

		$meta = is_array( $snippet['meta'] ?? null ) ? $snippet['meta'] : [];
		$raw  = (string) ( $snippet['code'] ?? '' );
		$code = $raw;
		// FluentSnippets stores a PHP snippet with its opening tag, and refuses
		// one sent back with it: the editable code drops it, as its own
		// SnippetsController::editableCode() does. `raw` is what the model
		// writes back as is (set_status()).
		if ( 'PHP' === ( $meta['type'] ?? '' ) ) {
			$code = ltrim( (string) preg_replace( '/^<\?php/', '', $code ), PHP_EOL );
		}

		return [
			'meta'   => $meta,
			'code'   => $code,
			'raw'    => $raw,
			'status' => $snippet['status'] ?? 'draft',
		];
	}

	/**
	 * Snippet meta given in the input (only the keys FluentSnippets authors).
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	private static function meta_from( array $args ): array {
		$meta = [];
		foreach ( [ 'name', 'description', 'type', 'run_at', 'priority', 'tags', 'group', 'condition', 'status' ] as $key ) {
			if ( array_key_exists( $key, $args ) ) {
				$meta[ $key ] = $args[ $key ];
			}
		}

		return $meta;
	}

	/**
	 * Fatal errors FluentSnippets recorded, by file name.
	 *
	 * @return array<string, mixed>
	 */
	private static function error_files(): array {
		$config = \FluentSnippets\App\Helpers\Helper::getIndexedConfig();
		$errors = is_array( $config ) && isset( $config['error_files'] ) && is_array( $config['error_files'] ) ? $config['error_files'] : [];

		return $errors;
	}

	/**
	 * FluentSnippets errors carry a title, a reason and a fix for its editor:
	 * they become the message Claude reads.
	 */
	private static function explain( \WP_Error $error ): \WP_Error {
		$data    = $error->get_error_data();
		$details = is_array( $data ) && isset( $data['error_details'] ) && is_array( $data['error_details'] ) ? $data['error_details'] : [];
		if ( [] === $details ) {
			return $error;
		}

		$parts = array_filter(
			[
				(string) ( $details['message'] ?? $details['title'] ?? '' ),
				(string) ( $details['reason'] ?? '' ),
				(string) ( $details['fix'] ?? '' ),
			]
		);

		return new \WP_Error( $error->get_error_code(), implode( ' ', $parts ), $data );
	}
}
