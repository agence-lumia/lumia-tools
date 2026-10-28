<?php
/**
 * TEST BENCH ONLY (MCP Connect campaign). Registers two public abilities:
 * `e2e/create-draft` writes content, so the campaign can check that a change
 * made through MCP is attributed to the connection in the Activity Log;
 * `e2e/nested-rest` dispatches a REST request internally (rest_do_request(),
 * like the abilities that wrap REST endpoints), so the campaign can check that
 * the token audience does not refuse a dispatch nested in the MCP call, and
 * what the acting user may reach through it. Copied by
 * tools/e2e/mcp-connect/run.sh into wp-content/e2e-snippets/ (loaded by the
 * e2e-snippets mu-plugin) and removed at the end. Never shipped.
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'wp_abilities_api_categories_init',
	static function (): void {
		wp_register_ability_category(
			'e2e',
			[
				'label'       => 'E2E',
				'description' => 'Bench-only abilities.',
			]
		);
	}
);

add_action(
	'wp_abilities_api_init',
	static function (): void {
		wp_register_ability(
			'e2e/create-draft',
			[
				'label'               => 'Create a draft (bench)',
				'description'         => 'Creates a draft post with the given title.',
				'category'            => 'e2e',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'title' => [ 'type' => 'string' ],
					],
					'required'             => [ 'title' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'id'     => [ 'type' => 'integer' ],
						'author' => [ 'type' => 'integer' ],
					],
				],
				'execute_callback'    => static function ( $input ): array {
					$id = wp_insert_post(
						[
							'post_title'  => sanitize_text_field( (string) ( $input['title'] ?? '' ) ),
							'post_status' => 'draft',
							'post_type'   => 'post',
						]
					);

					return [
						'id'     => (int) $id,
						'author' => get_current_user_id(),
					];
				},
				'permission_callback' => static function (): bool {
					return current_user_can( 'edit_posts' );
				},
				'meta'                => [
					'annotations' => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					],
					'public'      => true,
				],
			]
		);

		wp_register_ability(
			'e2e/nested-rest',
			[
				'label'               => 'Nested REST request (bench)',
				'description'         => 'GETs a REST route through rest_do_request() and returns its status.',
				'category'            => 'e2e',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'route' => [
							'type' => 'string',
							'enum' => [ '/wp/v2/posts', '/wp/v2/settings' ],
						],
					],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'route'  => [ 'type' => 'string' ],
						'status' => [ 'type' => 'integer' ],
						'code'   => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => static function ( $input = null ): array {
					$route   = is_array( $input ) && isset( $input['route'] ) ? (string) $input['route'] : '/wp/v2/posts';
					$request = new WP_REST_Request( 'GET', $route );
					if ( '/wp/v2/posts' === $route ) {
						$request->set_param( 'per_page', 1 );
					}
					$response = rest_do_request( $request );
					$data     = $response->get_data();

					return [
						'route'  => $route,
						'status' => (int) $response->get_status(),
						'code'   => is_array( $data ) && isset( $data['code'] ) && is_string( $data['code'] ) ? $data['code'] : '',
					];
				},
				'permission_callback' => static function (): bool {
					return current_user_can( 'read' );
				},
				'meta'                => [
					'annotations' => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'public'      => true,
				],
			]
		);
	}
);
