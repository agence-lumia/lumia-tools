<?php
namespace Lumia\Tools\Modules\McpConnect\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Rank Math: the writes its own abilities do not offer.
 *
 * Rank Math 1.0.28x registers `rank-math/*` abilities that read a post's SEO
 * (rendered), the redirections, the 404 log and the settings, and write the
 * global settings; none writes the SEO of one post nor a redirection. These
 * fill that gap: the raw post fields (with their %variables%) and the
 * redirections, through Rank Math's own classes.
 */
final class Seo implements Family {

	/**
	 * Post SEO fields: input key => Rank Math meta key.
	 */
	const POST_FIELDS = [
		'title'                => 'rank_math_title',
		'description'          => 'rank_math_description',
		'focus_keyword'        => 'rank_math_focus_keyword',
		'canonical_url'        => 'rank_math_canonical_url',
		'robots'               => 'rank_math_robots',
		'advanced_robots'      => 'rank_math_advanced_robots',
		'pillar_content'       => 'rank_math_pillar_content',
		'facebook_title'       => 'rank_math_facebook_title',
		'facebook_description' => 'rank_math_facebook_description',
		'facebook_image'       => 'rank_math_facebook_image',
		'facebook_image_id'    => 'rank_math_facebook_image_id',
		'twitter_use_facebook' => 'rank_math_twitter_use_facebook',
		'twitter_title'        => 'rank_math_twitter_title',
		'twitter_description'  => 'rank_math_twitter_description',
	];

	/** Robots values Rank Math knows. */
	const ROBOTS = [ 'index', 'noindex', 'follow', 'nofollow', 'noarchive', 'noimageindex', 'nosnippet' ];

	/** Redirection codes Rank Math offers. */
	const REDIRECT_CODES = [ '301', '302', '307', '410', '451' ];

	public static function label(): string {
		return __( 'SEO (Rank Math)', 'lumia-tools' );
	}

	public static function description(): string {
		return __( 'Write the Rank Math SEO of posts and pages (title, description, keyword, robots, canonical, social) and manage redirections.', 'lumia-tools' );
	}

	public static function available(): bool {
		return defined( 'RANK_MATH_VERSION' );
	}

	public static function definitions(): array {
		$post_id = [
			'type'        => 'integer',
			'minimum'     => 1,
			'description' => 'ID of the post, page or other content.',
		];

		$fields = [
			'title'                => [
				'type'        => 'string',
				'description' => 'SEO title; Rank Math variables allowed (%title% %sep% %sitename%). Empty: the default of the post type.',
			],
			'description'          => [ 'type' => 'string' ],
			'focus_keyword'        => [
				'type'        => 'string',
				'description' => 'Comma-separated, the first one is the main keyword.',
			],
			'canonical_url'        => [ 'type' => 'string' ],
			'robots'               => [
				'type'        => 'array',
				'items'       => [
					'type' => 'string',
					'enum' => self::ROBOTS,
				],
				'description' => 'e.g. ["noindex","nofollow"]. Empty: the default of the post type.',
			],
			'advanced_robots'      => [
				'type'        => 'object',
				'description' => 'max-snippet, max-video-preview, max-image-preview.',
			],
			'pillar_content'       => [ 'type' => 'boolean' ],
			'facebook_title'       => [ 'type' => 'string' ],
			'facebook_description' => [ 'type' => 'string' ],
			'facebook_image'       => [ 'type' => 'string' ],
			'facebook_image_id'    => [ 'type' => 'integer' ],
			'twitter_use_facebook' => [ 'type' => 'boolean' ],
			'twitter_title'        => [ 'type' => 'string' ],
			'twitter_description'  => [ 'type' => 'string' ],
		];

		$definitions = [
			'seo-get-post'    => [
				'label'       => __( 'Read the SEO fields of a post', 'lumia-tools' ),
				'description' => 'Returns the Rank Math SEO fields of a post as stored (raw, with their %variables%), the ones lumia/seo-update-post writes. For the rendered values and the score, use rank-math/get-post-seo-meta when it exists.',
				'input'       => [ 'post_id' => $post_id ],
				'required'    => [ 'post_id' ],
				'execute'     => [ self::class, 'get_post' ],
				'capability'  => 'edit_posts',
				'readonly'    => true,
				'idempotent'  => true,
			],
			'seo-update-post' => [
				'label'       => __( 'Write the SEO fields of a post', 'lumia-tools' ),
				'description' => 'Writes Rank Math SEO fields of a post: only the fields given change; an empty string (or an empty robots list) removes the field so the post type default applies. Check the result with lumia/http-request format seo.',
				'input'       => array_merge( [ 'post_id' => $post_id ], $fields ),
				'required'    => [ 'post_id' ],
				'execute'     => [ self::class, 'update_post' ],
				'capability'  => 'edit_posts',
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			],
		];

		if ( self::redirections_available() ) {
			$definitions['seo-save-redirection']   = [
				'label'       => __( 'Save a redirection', 'lumia-tools' ),
				'description' => 'Creates a Rank Math redirection, or updates one when `id` is given. Sources are paths of this site (/old-page/) compared exactly by default; comparison can be exact, contains, start, end or regex. 410 and 451 need no destination.',
				'input'       => [
					'id'          => [
						'type'        => 'integer',
						'description' => 'Redirection to update (see rank-math/get-redirections).',
					],
					'sources'     => [
						'type'     => 'array',
						'minItems' => 1,
						'items'    => [
							'type'                 => 'object',
							'properties'           => [
								'pattern'     => [ 'type' => 'string' ],
								'comparison'  => [
									'type' => 'string',
									'enum' => [ 'exact', 'contains', 'start', 'end', 'regex' ],
								],
								'ignore_case' => [ 'type' => 'boolean' ],
							],
							'required'             => [ 'pattern' ],
							'additionalProperties' => false,
						],
					],
					'url_to'      => [
						'type'        => 'string',
						'description' => 'Destination URL or path.',
					],
					'header_code' => [
						'type'    => 'string',
						'enum'    => self::REDIRECT_CODES,
						'default' => '301',
					],
					'status'      => [
						'type'    => 'string',
						'enum'    => [ 'active', 'inactive' ],
						'default' => 'active',
					],
				],
				'required'    => [ 'sources' ],
				'execute'     => [ self::class, 'save_redirection' ],
				'capability'  => 'manage_options',
				'readonly'    => false,
				'destructive' => false,
			];
			$definitions['seo-delete-redirection'] = [
				'label'       => __( 'Delete redirections', 'lumia-tools' ),
				'description' => 'Deletes Rank Math redirections by id.',
				'input'       => [
					'ids' => [
						'type'     => 'array',
						'minItems' => 1,
						'items'    => [
							'type'    => 'integer',
							'minimum' => 1,
						],
					],
				],
				'required'    => [ 'ids' ],
				'execute'     => [ self::class, 'delete_redirections' ],
				'capability'  => 'manage_options',
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			];
		}

		return $definitions;
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function get_post( array $args ) {
		$post = self::editable_post( (int) ( $args['post_id'] ?? 0 ) );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$fields = [];
		foreach ( self::POST_FIELDS as $key => $meta ) {
			$fields[ $key ] = get_post_meta( $post->ID, $meta, true );
		}

		return [
			'post_id'   => $post->ID,
			'post_type' => $post->post_type,
			'permalink' => (string) get_permalink( $post ),
			'fields'    => $fields,
		];
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function update_post( array $args ) {
		$post = self::editable_post( (int) ( $args['post_id'] ?? 0 ) );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$changed = [];
		foreach ( self::POST_FIELDS as $key => $meta ) {
			if ( ! array_key_exists( $key, $args ) ) {
				continue;
			}

			$value = self::field_value( $key, $args[ $key ] );
			if ( null === $value ) {
				delete_post_meta( $post->ID, $meta );
			} else {
				update_post_meta( $post->ID, $meta, $value );
			}
			$changed[] = $key;
		}

		if ( [] === $changed ) {
			return Registry::error( 'invalid_input', 'No SEO field given.' );
		}

		// Rank Math and the page caches read the post: let them know it changed.
		clean_post_cache( $post->ID );

		$result = self::get_post( [ 'post_id' => $post->ID ] );
		if ( is_array( $result ) ) {
			$result['changed'] = $changed;
		}

		return $result;
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function save_redirection( array $args ) {
		$sources = [];
		foreach ( (array) ( $args['sources'] ?? [] ) as $source ) {
			if ( ! is_array( $source ) || '' === trim( (string) ( $source['pattern'] ?? '' ) ) ) {
				continue;
			}
			$sources[] = [
				'pattern'    => (string) $source['pattern'],
				'comparison' => (string) ( $source['comparison'] ?? 'exact' ),
				'ignore'     => ! empty( $source['ignore_case'] ),
			];
		}

		$code = in_array( (string) ( $args['header_code'] ?? '301' ), self::REDIRECT_CODES, true ) ? (string) ( $args['header_code'] ?? '301' ) : '301';
		$to   = trim( (string) ( $args['url_to'] ?? '' ) );

		if ( [] === $sources ) {
			return Registry::error( 'invalid_input', 'At least one source pattern is required.' );
		}
		if ( '' === $to && ! in_array( $code, [ '410', '451' ], true ) ) {
			return Registry::error( 'invalid_input', 'A destination (`url_to`) is required for a ' . $code . ' redirection.' );
		}

		$data = [
			'sources'     => $sources,
			'url_to'      => $to,
			'header_code' => $code,
			'status'      => 'inactive' === ( $args['status'] ?? '' ) ? 'inactive' : 'active',
		];

		$id = (int) ( $args['id'] ?? 0 );
		if ( $id > 0 ) {
			if ( ! \RankMath\Redirections\DB::get_redirection_by_id( $id ) ) {
				return Registry::error( 'unknown_redirection', 'No redirection with id ' . $id . '.' );
			}
			$data['id'] = $id;
		}

		$redirection = \RankMath\Redirections\Redirection::from( $data );
		if ( ! $redirection->has_sources() ) {
			return Registry::error( 'invalid_input', 'Rank Math refused every source pattern (invalid or empty once sanitized).' );
		}
		if ( $redirection->is_infinite_loop() ) {
			return Registry::error( 'redirect_loop', 'The destination is one of the sources: this redirection would loop.' );
		}

		$saved = $redirection->save();
		if ( ! $saved ) {
			return Registry::error( 'redirection_not_saved', 'Rank Math did not save the redirection.' );
		}

		return [
			'id'          => (int) $saved,
			'created'     => 0 === $id,
			'sources'     => $data['sources'],
			'url_to'      => $to,
			'header_code' => $code,
			'status'      => $data['status'],
		];
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public static function delete_redirections( array $args ): array {
		$ids = array_values( array_filter( array_map( 'absint', (array) ( $args['ids'] ?? [] ) ) ) );

		return [
			'ids'     => $ids,
			'deleted' => [] === $ids ? 0 : (int) \RankMath\Redirections\DB::delete( $ids ),
		];
	}

	/**
	 * Value to store for a field, null to delete it.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	private static function field_value( string $key, $value ) {
		switch ( $key ) {
			case 'robots':
				$robots = array_values( array_intersect( self::ROBOTS, (array) $value ) );

				return [] === $robots ? null : $robots;
			case 'advanced_robots':
				return is_array( $value ) && [] !== $value ? array_map( 'sanitize_text_field', array_map( 'strval', $value ) ) : null;
			case 'pillar_content':
				// Rank Math stores 'on' and nothing for off.
				return ! empty( $value ) ? 'on' : null;
			case 'twitter_use_facebook':
				return ! empty( $value ) ? 'on' : 'off';
			case 'facebook_image_id':
				return absint( $value ) > 0 ? absint( $value ) : null;
			case 'canonical_url':
			case 'facebook_image':
				$url = esc_url_raw( (string) $value );

				return '' === $url ? null : $url;
			case 'description':
			case 'facebook_description':
			case 'twitter_description':
				$text = sanitize_textarea_field( (string) $value );

				return '' === $text ? null : $text;
			default:
				$text = sanitize_text_field( (string) $value );

				return '' === $text ? null : $text;
		}
	}

	/**
	 * A post the current user may edit.
	 *
	 * @return \WP_Post|\WP_Error
	 */
	private static function editable_post( int $post_id ) {
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post ) {
			return Registry::error( 'unknown_post', 'No content with id ' . $post_id . '.' );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return Registry::error( 'forbidden', 'The Claude account cannot edit this content.' );
		}

		return $post;
	}

	/**
	 * Whether Rank Math's redirections module is on (its table exists then).
	 */
	private static function redirections_available(): bool {
		if ( ! class_exists( '\RankMath\Redirections\Redirection' ) || ! class_exists( '\RankMath\Redirections\DB' ) ) {
			return false;
		}

		return ! class_exists( '\RankMath\Helper' ) || \RankMath\Helper::is_module_active( 'redirections' );
	}
}
