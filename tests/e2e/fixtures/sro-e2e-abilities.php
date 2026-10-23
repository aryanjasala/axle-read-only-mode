<?php
/**
 * Plugin Name: Site Read-Only Mode e2e abilities
 * Description: Throwaway abilities for the end-to-end tests. Copied into mu-plugins by the abilities test.
 *
 * They are registered on the Abilities API's own hooks, not late from `wp eval`,
 * because a plugin such as the MCP adapter can build the registry during
 * bootstrap. Anything registered after that is never seen.
 *
 * @package SiteReadOnlyMode
 */

// phpcs:ignoreFile

add_action(
	'wp_abilities_api_categories_init',
	static function () {
		wp_register_ability_category(
			'e2e',
			array(
				'label'       => 'E2E',
				'description' => 'Test abilities.',
			)
		);
	}
);

add_action(
	'wp_abilities_api_init',
	static function () {
		foreach ( array( 'read' => true, 'write' => false ) as $name => $readonly ) {
			wp_register_ability(
				'e2e/' . $name,
				array(
					'label'               => $name,
					'description'         => 'Test ability.',
					'category'            => 'e2e',
					'output_schema'       => array( 'type' => 'string' ),
					'execute_callback'    => static function () use ( $name ) {
						return $name . ' ran';
					},
					'permission_callback' => '__return_true',
					'meta'                => array(
						'annotations' => array( 'readonly' => $readonly ),
						'public'      => true,
						// The MCP adapter only runs abilities marked public for MCP.
						'mcp'         => array( 'public' => true ),
					),
				)
			);
		}
	}
);

// A stand-in for the MCP adapter's execute tool, with the same input and output,
// for when the adapter is not active. It runs after the adapter's own hooks, so a
// real adapter keeps its ability and the test goes through it instead.
add_action(
	'wp_abilities_api_categories_init',
	static function () {
		if ( ! wp_has_ability_category( 'mcp-adapter' ) ) {
			wp_register_ability_category(
				'mcp-adapter',
				array(
					'label'       => 'MCP',
					'description' => 'Stand-in.',
				)
			);
		}
	},
	100
);

add_action(
	'wp_abilities_api_init',
	static function () {
		if ( wp_has_ability( 'mcp-adapter/execute-ability' ) ) {
			return;
		}
		wp_register_ability(
			'mcp-adapter/execute-ability',
			array(
				'label'               => 'Execute',
				'description'         => 'Runs another ability.',
				'category'            => 'mcp-adapter',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'ability_name' => array( 'type' => 'string' ),
						'parameters'   => array( 'type' => 'object' ),
					),
				),
				'execute_callback'    => static function ( $input ) {
					$result = wp_get_ability( $input['ability_name'] )->execute();
					return is_wp_error( $result )
						? array(
							'success' => false,
							'error'   => $result->get_error_message(),
						)
						: array(
							'success' => true,
							'data'    => $result,
						);
				},
				'permission_callback' => 'is_user_logged_in',
				'meta'                => array( 'annotations' => array( 'readonly' => false ) ),
			)
		);
	},
	100
);
