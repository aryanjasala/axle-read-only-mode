<?php
/**
 * Abilities: freeze controls for AI agents and other Abilities API clients.
 *
 * @package AryanX\Axle\ReadOnlyMode
 */

namespace AryanX\Axle\ReadOnlyMode;

use AryanX\Axle\ReadOnlyMode\Admin\ToolsPage;

defined( 'ABSPATH' ) || exit;

/**
 * Registers Axle Read-Only Mode's abilities, and keeps other abilities from writing during a freeze.
 *
 * Abilities are the WordPress 6.9+ way to describe what a site can do, with
 * input and output schemas and a permission check, so AI agents (through the
 * MCP adapter) and the REST API can discover and run them. On older WordPress
 * nothing here runs.
 *
 * During a freeze, every ability that is not read-only is refused, from any
 * plugin, wherever it is called from, WP-CLI included, because agents call
 * abilities over WP-CLI too. Axle Read-Only Mode's own abilities keep working, or
 * an agent could freeze the site and never unfreeze it. WordPress 7.1 and later
 * check through the `wp_pre_execute_ability` filter; 6.9 and 7.0 through a
 * wrapper around each ability's callback.
 */
final class Abilities implements Service {

	public const CATEGORY = 'axle-read-only-mode';

	/**
	 * The freeze state.
	 *
	 * @var State
	 */
	private State $state;

	/**
	 * Freeze controls.
	 *
	 * @var Freezer
	 */
	private Freezer $freezer;

	/**
	 * What the freeze stopped.
	 *
	 * @var BlockedLog
	 */
	private BlockedLog $log;

	/**
	 * Sets up the abilities.
	 *
	 * @param State      $state   The freeze state.
	 * @param Freezer    $freezer Freeze controls.
	 * @param BlockedLog $log     What the freeze stopped.
	 */
	public function __construct( State $state, Freezer $freezer, BlockedLog $log ) {
		$this->state   = $state;
		$this->freezer = $freezer;
		$this->log     = $log;
	}

	/**
	 * Hooks into the Abilities API when WordPress has it.
	 */
	public function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
		add_filter( 'wp_pre_execute_ability', array( $this, 'gate' ), 10, 3 );
		add_filter( 'wp_register_ability_args', array( $this, 'gate_on_register' ), 10, 2 );
	}

	/**
	 * Registers the Axle Read-Only Mode category.
	 */
	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Axle Read-Only Mode', 'axle-read-only-mode' ),
				'description' => __( 'Freeze the site read-only during migrations and deploys, and see what the freeze blocked.', 'axle-read-only-mode' ),
			)
		);
	}

	/**
	 * Registers the abilities.
	 */
	public function register_abilities(): void {
		$can_manage = static function (): bool {
			return current_user_can( ToolsPage::capability() );
		};
		$status     = $this->status_schema();

		$this->ability(
			'get-status',
			__( 'Get freeze status', 'axle-read-only-mode' ),
			__( 'Whether the site is frozen right now, until when and why. Check this before changing content during a migration or deploy.', 'axle-read-only-mode' ),
			array(),
			$status,
			array( $this, 'get_status' ),
			static function (): bool {
				return current_user_can( 'edit_posts' );
			},
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'freeze',
			__( 'Freeze the site', 'axle-read-only-mode' ),
			__( 'Makes the site read-only: nothing can be saved in wp-admin, the REST API or forms, while visitors can still read it. Ends on its own after the given minutes.', 'axle-read-only-mode' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'minutes'         => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'Minutes until the freeze ends on its own. 0 means until someone unfreezes it.', 'axle-read-only-mode' ),
						'default'     => Freezer::default_duration(),
					),
					'reason'          => array(
						'type'        => 'string',
						'maxLength'   => 200,
						'description' => __( 'Shown to people who try to save something.', 'axle-read-only-mode' ),
					),
					'visitor_message' => array(
						'type'        => 'string',
						'maxLength'   => 500,
						'description' => __( 'Shown to visitors who send a form. Empty for the default message.', 'axle-read-only-mode' ),
					),
					'visitors'        => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => __( 'Pause comments, forms, checkouts and sign-ups.', 'axle-read-only-mode' ),
					),
					'cron'            => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => __( 'Pause scheduled tasks until the freeze ends.', 'axle-read-only-mode' ),
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			$status,
			array( $this, 'freeze' ),
			$can_manage,
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			)
		);

		$this->ability(
			'extend',
			__( 'Extend the freeze', 'axle-read-only-mode' ),
			__( 'Adds minutes to the current freeze. Fails when the site is not frozen or the freeze has no end time.', 'axle-read-only-mode' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'minutes' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Minutes to add.', 'axle-read-only-mode' ),
					),
				),
				'required'             => array( 'minutes' ),
				'additionalProperties' => false,
			),
			$status,
			array( $this, 'extend' ),
			$can_manage,
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			)
		);

		$this->ability(
			'unfreeze',
			__( 'Unfreeze the site', 'axle-read-only-mode' ),
			__( 'Ends the freeze now, so saving works again. Does nothing when the site is not frozen.', 'axle-read-only-mode' ),
			array(),
			$status,
			array( $this, 'unfreeze' ),
			$can_manage,
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			)
		);

		$this->ability(
			'get-blocked',
			__( 'List blocked changes', 'axle-read-only-mode' ),
			__( 'What the current or last freeze blocked: when, who and what. Useful after a migration to see which edits to redo on the new server.', 'axle-read-only-mode' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'limit' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 20,
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			array(
				'type'  => 'array',
				'items' => array(
					'type'       => 'object',
					'properties' => array(
						'time'   => array( 'type' => 'string' ),
						'who'    => array( 'type' => 'string' ),
						'source' => array( 'type' => 'string' ),
						'where'  => array( 'type' => 'string' ),
					),
				),
			),
			array( $this, 'get_blocked' ),
			$can_manage,
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);
	}

	/**
	 * Ability: the freeze status.
	 *
	 * @return array
	 */
	public function get_status(): array {
		$state = $this->state->all();
		$end   = (int) $state['end'];

		return array(
			'frozen'             => $this->state->is_frozen(),
			'scheduled'          => (bool) $state['active'] && (int) $state['start'] > time(),
			'start'              => $state['start'] ? gmdate( 'c', (int) $state['start'] ) : '',
			'end'                => $end ? gmdate( 'c', $end ) : '',
			'seconds_left'       => $this->state->remaining(),
			'reason'             => (string) $state['reason'],
			'visitors_paused'    => (bool) $state['visitors'],
			'cron_paused'        => (bool) $state['cron'],
			'forced_by_constant' => $this->state->forced_by_constant(),
			'blocked'            => $this->log->count(),
		);
	}

	/**
	 * Ability: freezes the site.
	 *
	 * @param array|null $input Ability input.
	 * @return array|\WP_Error
	 */
	public function freeze( $input = null ) {
		if ( $this->state->forced_by_constant() ) {
			return new \WP_Error( 'axle_read_only_mode_constant', __( 'AXLE_READ_ONLY_MODE in wp-config.php controls the freeze on this site.', 'axle-read-only-mode' ) );
		}

		$input = (array) $input;
		$this->freezer->freeze(
			array(
				'minutes'         => isset( $input['minutes'] ) ? (int) $input['minutes'] : Freezer::default_duration(),
				'reason'          => (string) ( $input['reason'] ?? '' ),
				'visitor_message' => (string) ( $input['visitor_message'] ?? '' ),
				'visitors'        => ! isset( $input['visitors'] ) || (bool) $input['visitors'],
				'cron'            => ! isset( $input['cron'] ) || (bool) $input['cron'],
			)
		);

		return $this->get_status();
	}

	/**
	 * Ability: extends the freeze.
	 *
	 * @param array|null $input Ability input.
	 * @return array|\WP_Error
	 */
	public function extend( $input = null ) {
		if ( ! $this->freezer->extend( (int) ( ( (array) $input )['minutes'] ?? 0 ) ) ) {
			return new \WP_Error( 'axle_read_only_mode_not_extended', __( 'Only a running freeze with an end time can be extended.', 'axle-read-only-mode' ) );
		}

		return $this->get_status();
	}

	/**
	 * Ability: ends the freeze.
	 *
	 * @return array|\WP_Error
	 */
	public function unfreeze() {
		if ( $this->state->forced_by_constant() ) {
			return new \WP_Error( 'axle_read_only_mode_constant', __( 'AXLE_READ_ONLY_MODE in wp-config.php controls the freeze on this site. Remove it to unfreeze.', 'axle-read-only-mode' ) );
		}

		$this->freezer->unfreeze( 'ability' );

		return $this->get_status();
	}

	/**
	 * Ability: what the freeze blocked.
	 *
	 * @param array|null $input Ability input.
	 * @return array[]
	 */
	public function get_blocked( $input = null ): array {
		$limit = (int) ( ( (array) $input )['limit'] ?? 20 );

		return array_map(
			static function ( array $entry ): array {
				return array(
					'time'   => gmdate( 'c', (int) $entry['time'] ),
					'who'    => BlockedLog::who( $entry ),
					'source' => (string) $entry['source'],
					'where'  => (string) $entry['where'],
				);
			},
			array_slice( $this->log->all(), 0, max( 1, min( 100, $limit ) ) )
		);
	}

	/**
	 * Refuses abilities that write while the site is frozen.
	 *
	 * @param mixed  $pre          Default sentinel; anything else short-circuits.
	 * @param string $ability_name Ability being run.
	 * @param mixed  $input        Its input.
	 * @return mixed
	 */
	public function gate( $pre, $ability_name, $input ) {
		$name = (string) $ability_name;

		if ( 0 === strpos( $name, self::CATEGORY . '/' ) || ! $this->state->is_frozen() || $this->state->can_bypass() ) {
			return $pre;
		}

		/**
		 * Filters the abilities that only run other abilities, such as the MCP
		 * adapter's execute tool. They are let through, because the ability they
		 * run comes through this check itself.
		 *
		 * @param string[] $abilities Ability names. Default mcp-adapter/execute-ability.
		 */
		if ( in_array( $name, (array) apply_filters( 'axle_read_only_mode_dispatch_abilities', array( 'mcp-adapter/execute-ability' ) ), true ) ) {
			return $pre;
		}

		$ability     = function_exists( 'wp_get_ability' ) ? wp_get_ability( $name ) : null;
		$annotations = $ability ? (array) ( $ability->get_meta()['annotations'] ?? array() ) : array();
		if ( ! empty( $annotations['readonly'] ) ) {
			return $pre;
		}

		/**
		 * Filters whether an ability that writes may run during a freeze.
		 *
		 * @param bool   $allow        Default false.
		 * @param string $ability_name Ability being run.
		 * @param mixed  $input        Its input.
		 */
		if ( apply_filters( 'axle_read_only_mode_allow_ability', false, $name, $input ) ) {
			return $pre;
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Our own hook, documented in Guards/Blocker.php.
		do_action( 'axle_read_only_mode_blocked', 'ability ' . $name, 'ability', get_current_user_id() );
		$this->log->add( 'ability ' . $name, 'ability' );

		return new \WP_Error( 'axle_read_only_mode', $this->state->message(), array( 'status' => 423 ) );
	}

	/**
	 * Runs the freeze check inside each ability on WordPress 6.9 and 7.0.
	 *
	 * Those versions have no `wp_pre_execute_ability` filter, so the ability's
	 * own callback asks gate() first. On 7.1 and later the filter does it and
	 * this leaves abilities alone. `WP_Filter_Sentinel` arrived with the filter.
	 *
	 * @param mixed  $args Ability arguments.
	 * @param string $name Ability name.
	 * @return mixed
	 */
	public function gate_on_register( $args, $name ) {
		if ( class_exists( 'WP_Filter_Sentinel' ) || ! is_array( $args ) || ! isset( $args['execute_callback'] ) || ! is_callable( $args['execute_callback'] ) ) {
			return $args;
		}

		$execute                  = $args['execute_callback'];
		$args['execute_callback'] = function ( $input = null ) use ( $execute, $name ) {
			$refused = $this->gate( null, $name, $input );
			if ( is_wp_error( $refused ) ) {
				return $refused;
			}

			// Core passes no argument when there is no input, so neither does this.
			return null === $input ? $execute() : $execute( $input );
		};

		return $args;
	}

	/**
	 * Registers one ability in the Axle Read-Only Mode category.
	 *
	 * @param string   $name        Name without the namespace.
	 * @param string   $label       Label.
	 * @param string   $description What it does, for people and agents.
	 * @param array    $input       Input schema, or empty for none.
	 * @param array    $output      Output schema.
	 * @param callable $execute     Execute callback.
	 * @param callable $permission  Permission callback.
	 * @param array    $annotations readonly, destructive, idempotent.
	 */
	private function ability( string $name, string $label, string $description, array $input, array $output, callable $execute, callable $permission, array $annotations ): void {
		$args = array(
			'label'               => $label,
			'description'         => $description,
			'category'            => self::CATEGORY,
			'output_schema'       => $output,
			'execute_callback'    => $execute,
			'permission_callback' => $permission,
			'meta'                => array(
				'annotations'  => $annotations,
				'show_in_rest' => true,
				// Clients such as the MCP adapter only list abilities marked public:
				// `public` from WordPress 7.1, `mcp.public` for the adapter on 6.9 and 7.0.
				'public'       => true,
				'mcp'          => array( 'public' => true ),
			),
		);
		if ( $input ) {
			$args['input_schema'] = $input;
		}

		wp_register_ability( self::CATEGORY . '/' . $name, $args );
	}

	/**
	 * Output schema of the status, shared by every ability that changes the freeze.
	 *
	 * @return array
	 */
	private function status_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'frozen'             => array( 'type' => 'boolean' ),
				'scheduled'          => array( 'type' => 'boolean' ),
				'start'              => array(
					'type'        => 'string',
					'description' => 'ISO 8601, UTC.',
				),
				'end'                => array(
					'type'        => 'string',
					'description' => 'ISO 8601, UTC. Empty when the freeze has no end time.',
				),
				'seconds_left'       => array( 'type' => 'integer' ),
				'reason'             => array( 'type' => 'string' ),
				'visitors_paused'    => array( 'type' => 'boolean' ),
				'cron_paused'        => array( 'type' => 'boolean' ),
				'forced_by_constant' => array( 'type' => 'boolean' ),
				'blocked'            => array( 'type' => 'integer' ),
			),
		);
	}
}
