<?php
/**
 * Tests for freeze scopes: what each one covers and how every guard respects it.
 *
 * @package AryanX\Axle\ReadOnlyMode
 */

namespace AryanX\Axle\ReadOnlyMode\Tests\Unit;

use AryanX\Axle\ReadOnlyMode\Abilities;
use AryanX\Axle\ReadOnlyMode\BlockedLog;
use AryanX\Axle\ReadOnlyMode\Editor\EditorLock;
use AryanX\Axle\ReadOnlyMode\Freezer;
use AryanX\Axle\ReadOnlyMode\Guards\Blocker;
use AryanX\Axle\ReadOnlyMode\Guards\RestGuard;
use AryanX\Axle\ReadOnlyMode\Guards\SideEffects;
use AryanX\Axle\ReadOnlyMode\History;
use AryanX\Axle\ReadOnlyMode\Scopes;
use AryanX\Axle\ReadOnlyMode\State;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class ScopeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'wp_date' )->justReturn( '14:30' );
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		Functions\when( 'sanitize_key' )->alias( 'strtolower' );
		Functions\when( 'get_userdata' )->justReturn( false );
		Functions\when( 'get_user_by' )->justReturn( false );
		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ) {
				return $thing instanceof \WP_Error;
			}
		);
		$this->options['time_format'] = 'H:i';
	}

	private function freeze( string $area = 'all', array $more = array() ): void {
		$this->options[ State::OPTION ] = array(
			'active' => true,
			'start'  => time() - 60,
			'end'    => time() + 600,
			'area'   => $area,
		) + $more;
	}

	private function request( string $route, string $method = 'POST' ): \WP_REST_Request {
		$request = \Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_method' )->andReturn( $method );
		$request->shouldReceive( 'get_route' )->andReturn( $route );
		$request->shouldReceive( 'get_json_params' )->andReturn( array() );
		$request->shouldReceive( 'get_body_params' )->andReturn( array() );

		return $request;
	}

	private function rest_guard(): RestGuard {
		return new RestGuard( new Blocker( new State(), new BlockedLog( new History() ) ) );
	}

	public function test_a_freeze_without_a_scope_covers_everything(): void {
		$this->options[ State::OPTION ] = array(
			'active' => true,
			'start'  => time() - 60,
			'end'    => time() + 600,
		);
		$state = new State();

		$this->assertSame( 'all', $state->scope() );
		$this->assertTrue( $state->freezes( 'content' ) );
		$this->assertTrue( $state->freezes( 'code' ) );
	}

	public function test_each_scope_covers_its_own_areas(): void {
		$this->assertTrue( Scopes::covers( 'content', 'content' ) );
		$this->assertFalse( Scopes::covers( 'content', 'code' ) );
		$this->assertTrue( Scopes::covers( 'code', 'code' ) );
		$this->assertFalse( Scopes::covers( 'code', 'content' ) );
		$this->assertTrue( Scopes::covers( 'all', 'code' ) );
	}

	public function test_an_unknown_scope_falls_back_to_everything(): void {
		$this->freeze( 'nonsense' );

		$this->assertSame( 'all', ( new State() )->scope() );
		$this->assertSame( 'all', Scopes::normalize( 'nonsense' ) );
	}

	public function test_code_can_add_a_scope_and_change_the_default(): void {
		Filters\expectApplied( 'axle_read_only_mode_scopes' )->andReturnUsing(
			static function ( array $scopes ) {
				$scopes['media'] = array(
					'label'  => 'Media only',
					'covers' => array( 'media' ),
				);

				return $scopes;
			}
		);

		$this->assertTrue( Scopes::covers( 'media', 'media' ) );
		$this->assertFalse( Scopes::covers( 'media', 'content' ) );
		$this->assertSame( 'Media only', Scopes::status( 'media' ) );
	}

	public function test_the_default_scope_has_a_filter(): void {
		Filters\expectApplied( 'axle_read_only_mode_default_scope' )->once()->andReturn( 'content' );

		$this->assertSame( 'content', Scopes::default_scope() );
	}

	public function test_the_message_names_what_is_frozen(): void {
		$this->freeze( 'content' );
		$this->assertStringContainsString( 'content of this site is frozen', ( new State() )->message() );
		$this->assertStringContainsString( 'settings, plugins and themes still work', ( new State() )->message() );

		$this->freeze( 'code' );
		$this->assertStringContainsString( 'settings and code of this site are frozen', ( new State() )->message() );
		$this->assertStringContainsString( 'content can still be saved', ( new State() )->message() );

		$this->freeze( 'all' );
		$this->assertStringContainsString( 'This site is frozen for maintenance', ( new State() )->message() );
	}

	public function test_a_freeze_stores_its_scope_and_the_history_keeps_it(): void {
		$history = new History();
		$state   = new State();
		$freezer = new Freezer( $state, new BlockedLog( $history ), $history );

		Actions\expectDone( 'axle_read_only_mode_started' )->once();
		$freezer->freeze( array( 'minutes' => 30, 'area' => 'content' ) );

		$this->assertSame( 'content', $this->options[ State::OPTION ]['area'] );

		$freezer->unfreeze( 'user' );
		$this->assertSame( 'content', $history->all()[0]['scope'] );
	}

	public function test_a_freeze_with_a_bad_scope_is_stored_as_everything(): void {
		$history = new History();
		$freezer = new Freezer( new State(), new BlockedLog( $history ), $history );
		$freezer->freeze( array( 'minutes' => 30, 'area' => 'whatever' ) );

		$this->assertSame( 'all', $this->options[ State::OPTION ]['area'] );
	}

	public function test_requests_are_sorted_into_content_or_code(): void {
		$this->assertSame( 'code', Scopes::admin_area( 'options.php' ) );
		$this->assertSame( 'code', Scopes::admin_area( 'plugins.php' ) );
		$this->assertSame( 'code', Scopes::admin_area( 'admin.php', '', 'my-plugin-settings' ) );
		$this->assertSame( 'code', Scopes::admin_area( 'admin.php', '', 'x', false, true ) );
		$this->assertSame( 'code', Scopes::admin_area( 'edit.php', '', '', true ) );
		$this->assertSame( 'content', Scopes::admin_area( 'post.php' ) );
		$this->assertSame( 'content', Scopes::admin_area( 'edit.php' ) );
		$this->assertSame( 'content', Scopes::admin_area( 'admin-post.php', 'some_form' ) );

		$this->assertSame( 'code', Scopes::rest_area( '/wp/v2/settings' ) );
		$this->assertSame( 'code', Scopes::rest_area( '/wp/v2/global-styles/12' ) );
		$this->assertSame( 'content', Scopes::rest_area( '/wp/v2/posts/12' ) );
		$this->assertSame( 'content', Scopes::rest_area( '/wp/v2/menu-items' ) );
		$this->assertSame( 'content', Scopes::rest_area( '/wc/v3/orders' ) );

		$this->assertSame( 'code', Scopes::ajax_area( 'install-plugin' ) );
		$this->assertSame( 'content', Scopes::ajax_area( 'inline-save' ) );

		$this->assertSame( 'code', Scopes::ability_area( 'core/update-option' ) );
		$this->assertSame( 'content', Scopes::ability_area( 'acme/create-post' ) );

		$this->assertSame( 'code', Scopes::cron_area( 'wp_version_check' ) );
		$this->assertSame( 'content', Scopes::cron_area( 'publish_future_post' ) );
		$this->assertSame( 'content', Scopes::cron_area( 'acme_nightly_import' ), 'An event nobody recognises counts as content.' );
	}

	public function test_code_can_fix_the_area_of_one_request(): void {
		Filters\expectApplied( 'axle_read_only_mode_request_area' )->once()->with( 'content', 'rest', '/acme/v1/config', \Mockery::any() )->andReturn( 'code' );

		$this->assertSame( 'code', Scopes::rest_area( '/acme/v1/config' ) );
	}

	public function test_a_content_freeze_refuses_content_and_lets_settings_through(): void {
		$this->freeze( 'content' );
		$guard = $this->rest_guard();

		$this->assertInstanceOf( \WP_Error::class, $guard->guard( null, null, $this->request( '/wp/v2/posts/12' ) ) );
		$this->assertNull( $guard->guard( null, null, $this->request( '/wp/v2/settings' ) ) );
		$this->assertCount( 1, $this->options[ BlockedLog::OPTION ], 'Only the refused request is logged.' );
		$this->assertSame( 'content', $this->options[ BlockedLog::OPTION ][0]['area'] );
	}

	public function test_a_settings_and_code_freeze_refuses_settings_and_lets_content_through(): void {
		$this->freeze( 'code' );
		$guard = $this->rest_guard();

		$this->assertNull( $guard->guard( null, null, $this->request( '/wp/v2/posts/12' ) ) );
		$this->assertNull( $guard->guard( null, null, $this->request( '/wp/v2/comments' ) ) );
		$error = $guard->guard( null, null, $this->request( '/wp/v2/settings' ) );

		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'code', $this->options[ BlockedLog::OPTION ][0]['area'] );
	}

	public function test_everything_refuses_both(): void {
		$this->freeze( 'all' );
		$guard = $this->rest_guard();

		$this->assertInstanceOf( \WP_Error::class, $guard->guard( null, null, $this->request( '/wp/v2/posts/12' ) ) );
		$this->assertInstanceOf( \WP_Error::class, $guard->guard( null, null, $this->request( '/wp/v2/settings' ) ) );
	}

	public function test_a_scope_that_does_not_cover_a_request_does_not_log_the_allow_list(): void {
		$this->freeze( 'code' );
		$this->options['axle_read_only_mode_settings'] = array( 'allow' => array( 'rest' => array( '/wp/v2/posts' ) ) );

		$this->assertNull( $this->rest_guard()->guard( null, null, $this->request( '/wp/v2/posts/12' ) ) );
		$this->assertArrayNotHasKey( BlockedLog::OPTION, $this->options, 'Not frozen, so neither blocked nor allowed.' );
	}

	public function test_abilities_follow_the_scope(): void {
		Functions\when( 'wp_get_ability' )->justReturn(
			new class() {
				public function get_meta(): array {
					return array( 'annotations' => array( 'readonly' => false ) );
				}
			}
		);

		$history   = new History();
		$state     = new State();
		$log       = new BlockedLog( $history );
		$abilities = new Abilities( $state, new Freezer( $state, $log, $history ), $log );

		$this->freeze( 'content' );
		$this->assertInstanceOf( \WP_Error::class, $abilities->gate( null, 'acme/create-post', array() ) );
		$this->assertNull( $abilities->gate( null, 'core/update-option', array() ) );

		$this->freeze( 'code' );
		$this->assertNull( $abilities->gate( null, 'acme/create-post', array() ) );
		$this->assertInstanceOf( \WP_Error::class, $abilities->gate( null, 'core/update-option', array() ) );
		$this->assertSame( 'code', $this->options[ BlockedLog::OPTION ][0]['area'] );
	}

	public function test_the_editor_is_only_locked_when_content_is_frozen(): void {
		Functions\when( 'plugins_url' )->justReturn( '' );
		$lock    = new EditorLock( new State() );
		$message = static function ( EditorLock $lock ): array {
			return $lock->heartbeat( array() );
		};

		$this->freeze( 'code' );
		$this->assertSame( '', $message( $lock )['axle_read_only_mode'] );

		$this->freeze( 'content' );
		$this->assertNotSame( '', $message( $lock )['axle_read_only_mode'] );
	}

	public function test_cron_is_held_per_scope(): void {
		$now = time();
		Functions\when( '_get_cron_array' )->justReturn(
			array(
				$now - 20 => array(
					'wp_version_check'    => array( 'a' => array() ),
					'publish_future_post' => array( 'b' => array() ),
				),
				$now + 3600 => array( 'later' => array( 'c' => array() ) ),
			)
		);

		$this->freeze( 'all' );
		$this->assertSame( array(), ( new SideEffects( new State() ) )->no_cron_jobs() );

		$this->freeze( 'content' );
		$ready = ( new SideEffects( new State() ) )->no_cron_jobs();
		$this->assertSame( array( 'wp_version_check' ), array_keys( $ready[ $now - 20 ] ), 'Update checks keep running in a content freeze.' );
		$this->assertCount( 1, $ready );

		$this->freeze( 'code' );
		$ready = ( new SideEffects( new State() ) )->no_cron_jobs();
		$this->assertSame( array( 'publish_future_post' ), array_keys( $ready[ $now - 20 ] ), 'Only update checks are held in a settings and code freeze.' );
	}

	public function test_side_effects_register_what_the_scope_covers(): void {
		$this->freeze( 'content' );
		( new SideEffects( new State() ) )->register();
		$this->assertNotFalse( has_filter( 'xmlrpc_enabled', '__return_false' ) );
		$this->assertFalse( has_filter( 'automatic_updater_disabled', '__return_true' ) );
		$this->assertNotFalse( has_filter( 'comments_open', '__return_false' ) );
	}

	public function test_a_code_freeze_keeps_comments_and_xmlrpc_but_holds_updates(): void {
		$this->freeze( 'code' );
		( new SideEffects( new State() ) )->register();

		$this->assertNotFalse( has_filter( 'automatic_updater_disabled', '__return_true' ) );
		$this->assertFalse( has_filter( 'comments_open', '__return_false' ) );
		$this->assertFalse( has_filter( 'xmlrpc_enabled', '__return_false' ) );
		$this->assertSame( array( 'wp.getPosts' => 'x' ), ( new SideEffects( new State() ) )->drop_code_methods( array( 'wp.getPosts' => 'x', 'wp.setOptions' => 'y' ) ) );
	}
}
