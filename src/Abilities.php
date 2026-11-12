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
	 * The "Before you freeze" checks.
	 *
	 * @var Checks
	 */
	private Checks $checks;

	/**
	 * Planned freezes.
	 *
	 * @var Planner|null
	 */
	private ?Planner $planner;

	/**
	 * Sets up the abilities.
	 *
	 * @param State        $state   The freeze state.
	 * @param Freezer      $freezer Freeze controls.
	 * @param BlockedLog   $log     What the freeze stopped.
	 * @param Checks|null  $checks  The "Before you freeze" checks.
	 * @param Planner|null $planner Planned freezes.
	 */
	public function __construct( State $state, Freezer $freezer, BlockedLog $log, ?Checks $checks = null, ?Planner $planner = null ) {
		$this->planner = $planner;
		$this->checks  = $checks ?? new Checks();
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
			'check-freeze',
			__( 'Check before freezing', 'axle-read-only-mode' ),
			__( 'What a freeze would interrupt right now, for the length, start and scope given: people with a post open in the editor, scheduled posts and scheduled tasks due during the freeze, and WooCommerce orders waiting for payment. It only reports. Run it before the freeze ability and tell the people involved.', 'axle-read-only-mode' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'minutes'  => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'Length of the freeze in minutes. 0 means until someone unfreezes it.', 'axle-read-only-mode' ),
						'default'     => Freezer::default_duration(),
					),
					'start'    => array(
						'type'        => 'string',
						'description' => __( 'When the freeze would start, as a date and time. Empty for now.', 'axle-read-only-mode' ),
					),
					'scope'    => $this->scope_schema(),
					'cron'     => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'visitors' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'editing'   => array(
						'type'        => 'integer',
						'description' => 'People with a post open in the editor right now, not counting the caller.',
					),
					'attention' => array(
						'type'        => 'integer',
						'description' => 'Items whose tone is warning or error.',
					),
					'items'     => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'id'     => array( 'type' => 'string' ),
								'tone'   => array(
									'type' => 'string',
									'enum' => array( 'good', 'warning', 'error', 'info', 'neutral' ),
								),
								'word'   => array( 'type' => 'string' ),
								'title'  => array( 'type' => 'string' ),
								'detail' => array( 'type' => 'string' ),
								'rows'   => array(
									'type'  => 'array',
									'items' => array( 'type' => 'string' ),
								),
								'count'  => array( 'type' => 'integer' ),
							),
						),
					),
				),
			),
			array( $this, 'check_freeze' ),
			$can_manage,
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'freeze',
			__( 'Freeze the site', 'axle-read-only-mode' ),
			__( 'Makes the site read-only: by default nothing can be saved in wp-admin, the REST API or forms, while visitors can still read it. With a scope it freezes only content, or only settings and code. Ends on its own after the given minutes.', 'axle-read-only-mode' ),
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
					'scope'           => $this->scope_schema(),
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

		if ( $this->planner ) {
			$plan_schema = $this->plan_schema();

			$this->ability(
				'list-plans',
				__( 'List planned freezes', 'axle-read-only-mode' ),
				__( 'The freezes planned for later: when each starts, how long it lasts, what it covers, and whether it repeats every week.', 'axle-read-only-mode' ),
				array(),
				array(
					'type'  => 'array',
					'items' => $plan_schema,
				),
				array( $this, 'list_plans' ),
				$can_manage,
				array(
					'readonly'   => true,
					'idempotent' => true,
				)
			);

			$this->ability(
				'plan-freeze',
				__( 'Plan a freeze', 'axle-read-only-mode' ),
				__( 'Plans a freeze for a time in the future. Everyone who is writing gets a countdown before it starts, and it starts by itself even when WP-Cron is late. It can repeat every week on chosen days. Check what it would interrupt first with check-freeze.', 'axle-read-only-mode' ),
				array(
					'type'                 => 'object',
					'properties'           => array(
						'start'    => array(
							'type'        => 'string',
							'description' => __( 'When it starts, as a date and time in the site time zone, such as 2026-11-20 02:00. Must be in the future. For a repeating plan this sets the time of day.', 'axle-read-only-mode' ),
						),
						'minutes'  => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'default'     => Freezer::default_duration(),
							'description' => __( 'Length in minutes. 0 means until someone unfreezes it.', 'axle-read-only-mode' ),
						),
						'reason'   => array(
							'type'      => 'string',
							'maxLength' => 200,
						),
						'scope'    => $this->scope_schema(),
						'days'     => array(
							'type'        => 'array',
							'items'       => array(
								'type'    => 'integer',
								'minimum' => 0,
								'maximum' => 6,
							),
							'description' => __( 'Repeat every week on these weekdays, 0 for Sunday to 6 for Saturday. Empty for a freeze that happens once.', 'axle-read-only-mode' ),
						),
						'lead'     => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'maximum'     => 1440,
							'description' => __( 'Minutes of heads-up before it starts. 0 for the default.', 'axle-read-only-mode' ),
						),
						'email'    => array(
							'type'        => 'boolean',
							'description' => __( 'Email people who have a post open when the heads-up starts.', 'axle-read-only-mode' ),
						),
						'visitors' => array(
							'type'    => 'boolean',
							'default' => true,
						),
						'cron'     => array(
							'type'    => 'boolean',
							'default' => true,
						),
					),
					'required'             => array( 'start' ),
					'additionalProperties' => false,
				),
				$plan_schema,
				array( $this, 'plan_freeze' ),
				$can_manage,
				array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				)
			);

			$this->ability(
				'cancel-plan',
				__( 'Cancel a planned freeze', 'axle-read-only-mode' ),
				__( 'Cancels a planned freeze so it does not start. A plan that repeats stops repeating. A freeze it already started keeps running: use unfreeze for that.', 'axle-read-only-mode' ),
				array(
					'type'                 => 'object',
					'properties'           => array(
						'id' => array(
							'type'        => 'string',
							'description' => __( 'The plan ID, from list-plans.', 'axle-read-only-mode' ),
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				array(
					'type'       => 'object',
					'properties' => array(
						'cancelled' => array( 'type' => 'boolean' ),
					),
				),
				array( $this, 'cancel_plan' ),
				$can_manage,
				array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => true,
				)
			);
		}

		$this->ability(
			'get-blocked',
			__( 'List blocked changes', 'axle-read-only-mode' ),
			__( 'What the current or last freeze blocked, and the requests the allow-list let through: when, who and what. Useful after a migration to see which edits to redo on the new server.', 'axle-read-only-mode' ),
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
						'result' => array(
							'type'        => 'string',
							'description' => 'blocked, or allowed when the allow-list or a filter let the request through.',
						),
						'where'  => array( 'type' => 'string' ),
						'area'   => array(
							'type'        => 'string',
							'description' => 'What the request would have changed: content or code. Empty for entries from before scopes existed.',
						),
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

		$this->ability(
			'get-allow-list',
			__( 'List what keeps working during a freeze', 'axle-read-only-mode' ),
			__( 'The allow-list in the settings: REST route prefixes, front-end paths and query arguments, ajax actions and users that keep working while the site is frozen. Read-only: changing the list is left to people, because it loosens the freeze.', 'axle-read-only-mode' ),
			array(),
			array(
				'type'       => 'object',
				'properties' => array(
					'rest'  => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'paths' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'ajax'  => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'users' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'integer' ),
						'description' => 'User IDs.',
					),
				),
			),
			array( $this, 'get_allow_list' ),
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
			'scheduled'          => $this->state->is_scheduled(),
			'source'             => $this->state->source(),
			'start'              => $state['start'] ? gmdate( 'c', (int) $state['start'] ) : '',
			'end'                => $end ? gmdate( 'c', $end ) : '',
			'seconds_left'       => $this->state->remaining(),
			'reason'             => (string) $state['reason'],
			'visitors_paused'    => (bool) $state['visitors'],
			'cron_paused'        => (bool) $state['cron'],
			'scope'              => $this->state->scope(),
			'forced_by_constant' => $this->state->forced_by_constant(),
			'blocked'            => $this->log->count( $this->state->log_since() ),
		);
	}

	/**
	 * Ability: what a freeze would interrupt.
	 *
	 * @param array|null $input Ability input.
	 * @return array
	 */
	public function check_freeze( $input = null ): array {
		$input  = (array) $input;
		$result = $this->checks->run(
			array(
				'minutes'  => isset( $input['minutes'] ) ? (int) $input['minutes'] : Freezer::default_duration(),
				'start'    => ToolsPage::parse_start( (string) ( $input['start'] ?? '' ) ),
				'scope'    => (string) ( $input['scope'] ?? Scopes::default_scope() ),
				'cron'     => ! isset( $input['cron'] ) || (bool) $input['cron'],
				'visitors' => ! isset( $input['visitors'] ) || (bool) $input['visitors'],
			)
		);

		return array(
			'editing'   => $result['editing'],
			'attention' => $result['attention'],
			'items'     => $result['items'],
		);
	}

	/**
	 * The output schema of one planned freeze.
	 *
	 * @return array
	 */
	private function plan_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'      => array( 'type' => 'string' ),
				'start'   => array(
					'type'        => 'string',
					'description' => 'ISO 8601, UTC. The next time it starts.',
				),
				'minutes' => array( 'type' => 'integer' ),
				'scope'   => array( 'type' => 'string' ),
				'reason'  => array( 'type' => 'string' ),
				'days'    => array(
					'type'  => 'array',
					'items' => array( 'type' => 'integer' ),
				),
				'lead'    => array( 'type' => 'integer' ),
				'email'   => array( 'type' => 'boolean' ),
			),
		);
	}

	/**
	 * One plan, as the abilities return it.
	 *
	 * @param array $plan Plan.
	 * @return array
	 */
	private function plan_output( array $plan ): array {
		return array(
			'id'      => (string) $plan['id'],
			'start'   => gmdate( 'c', (int) $plan['start'] ),
			'minutes' => (int) $plan['minutes'],
			'scope'   => (string) $plan['area'],
			'reason'  => (string) $plan['reason'],
			'days'    => array_map( 'intval', (array) $plan['days'] ),
			'lead'    => Planner::lead_minutes( $plan ),
			'email'   => (bool) $plan['email'],
		);
	}

	/**
	 * Ability: the planned freezes.
	 *
	 * @return array
	 */
	public function list_plans(): array {
		return $this->planner ? array_values( array_map( array( $this, 'plan_output' ), $this->planner->all() ) ) : array();
	}

	/**
	 * Ability: plans a freeze.
	 *
	 * @param array|null $input Ability input.
	 * @return array|\WP_Error
	 */
	public function plan_freeze( $input = null ) {
		if ( ! $this->planner ) {
			return new \WP_Error( 'axle_read_only_mode_no_planner', __( 'Planned freezes are not available.', 'axle-read-only-mode' ) );
		}

		$input = (array) $input;
		$plan  = $this->planner->save(
			array(
				'start'    => ToolsPage::parse_start( (string) ( $input['start'] ?? '' ) ),
				'minutes'  => isset( $input['minutes'] ) ? (int) $input['minutes'] : Freezer::default_duration(),
				'reason'   => (string) ( $input['reason'] ?? '' ),
				'area'     => isset( $input['scope'] ) ? (string) $input['scope'] : Scopes::default_scope(),
				'days'     => (array) ( $input['days'] ?? array() ),
				'lead'     => (int) ( $input['lead'] ?? 0 ),
				'email'    => ! empty( $input['email'] ),
				'visitors' => ! isset( $input['visitors'] ) || (bool) $input['visitors'],
				'cron'     => ! isset( $input['cron'] ) || (bool) $input['cron'],
			)
		);

		return is_wp_error( $plan ) ? $plan : $this->plan_output( $plan );
	}

	/**
	 * Ability: cancels a planned freeze.
	 *
	 * @param array|null $input Ability input.
	 * @return array
	 */
	public function cancel_plan( $input = null ): array {
		return array( 'cancelled' => $this->planner && null !== $this->planner->cancel( sanitize_key( (string) ( ( (array) $input )['id'] ?? '' ) ) ) );
	}

	/**
	 * The scope input, shared by the abilities that take one.
	 *
	 * @return array
	 */
	private function scope_schema(): array {
		$scopes = Scopes::all();

		return array(
			'type'        => 'string',
			'enum'        => array_keys( $scopes ),
			'default'     => Scopes::default_scope(),
			'description' => __( 'What the freeze covers: all (everything, the default), content (posts, pages, media, comments, terms, menus and other content, while settings, plugins, themes and updates keep working) or code (options, plugins, themes, updates and user accounts, while content keeps working).', 'axle-read-only-mode' ),
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
				'area'            => isset( $input['scope'] ) ? (string) $input['scope'] : Scopes::default_scope(),
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

		if ( $this->state->network_applies() && ! $this->state->is_running() ) {
			return new \WP_Error( 'axle_read_only_mode_network', __( 'The network froze this site. Only the network can unfreeze it.', 'axle-read-only-mode' ) );
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
					'result' => empty( $entry['allowed'] ) ? 'blocked' : 'allowed',
					'where'  => (string) $entry['where'],
					'area'   => (string) ( $entry['area'] ?? '' ),
				);
			},
			array_slice( $this->log->all( $this->state->log_since() ), 0, max( 1, min( 100, $limit ) ) )
		);
	}

	/**
	 * Ability: the allow-list in force on this site.
	 *
	 * @return array
	 */
	public function get_allow_list(): array {
		$list = $this->state->allow_list();

		return array(
			'rest'  => array_map( 'strval', $list->entries( 'rest' ) ),
			'paths' => array_map( 'strval', $list->entries( 'paths' ) ),
			'ajax'  => $list->ajax_actions(),
			'users' => array_map( 'intval', $list->entries( 'users' ) ),
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

		// A freeze that does not cover this ability's area leaves it alone, and does not log it.
		$area = Scopes::ability_area( $name );
		if ( ! $this->state->freezes( $area ) ) {
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
		do_action( 'axle_read_only_mode_blocked', 'ability ' . $name, 'ability', get_current_user_id(), $area );
		$this->log->add( 'ability ' . $name, 'ability', $this->state->log_since(), $area );

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
				'scope'              => array(
					'type'        => 'string',
					'description' => 'What the freeze covers: all, content or code.',
				),
				'forced_by_constant' => array( 'type' => 'boolean' ),
				'source'             => array(
					'type'        => 'string',
					'description' => 'What holds the site frozen: site, network (a multisite network freeze) or constant (wp-config.php). Empty when it is not frozen.',
				),
				'blocked'            => array( 'type' => 'integer' ),
			),
		);
	}
}
