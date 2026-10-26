<?php
/**
 * TEST BENCH ONLY (MCP Connect campaign). Registers a public ability that
 * writes content, so the campaign can check that a change made through MCP is
 * attributed to the connection in the Activity Log. Copied by
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
	}
);
