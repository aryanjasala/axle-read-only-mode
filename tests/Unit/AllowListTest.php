<?php
/**
 * Tests for the settings allow-list: validation, matching, and the guards that use it.
 *
 * @package AryanX\Axle\ReadOnlyMode
 */

namespace AryanX\Axle\ReadOnlyMode\Tests\Unit;

use AryanX\Axle\ReadOnlyMode\AllowList;
use AryanX\Axle\ReadOnlyMode\AllowSuggestions;
use AryanX\Axle\ReadOnlyMode\BlockedLog;
use AryanX\Axle\ReadOnlyMode\Guards\AdminGuard;
use AryanX\Axle\ReadOnlyMode\Guards\Blocker;
use AryanX\Axle\ReadOnlyMode\Guards\RestGuard;
use AryanX\Axle\ReadOnlyMode\History;
use AryanX\Axle\ReadOnlyMode\Settings;
use AryanX\Axle\ReadOnlyMode\State;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class AllowListTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'home_url' )->justReturn( 'https://example.test' );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'get_userdata' )->justReturn( false );
		Functions\when( 'get_user_by' )->justReturn( false );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'sanitize_key' )->alias( 'strtolower' );
	}

	private function allow( array $entries ): AllowList {
		$this->options[ Settings::OPTION ] = array( 'allow' => $entries );

		return new AllowList( new Settings() );
	}

	private function state( array $entries = array() ): State {
		return new State( null, null, $this->allow( $entries ) );
	}

	/**
	 * Route prefixes.
	 */

	public function test_a_route_prefix_matches_everything_below_it(): void {
		$list = $this->allow( array( 'rest' => array( '/wc/v3/webhooks' ) ) );

		$this->assertTrue( $list->matches_route( '/wc/v3/webhooks' ) );
		$this->assertTrue( $list->matches_route( '/wc/v3/webhooks/12' ) );
		$this->assertFalse( $list->matches_route( '/wc/v3/orders' ) );
		$this->assertFalse( $list->matches_route( '/wp/v2/posts/1' ) );
	}

	public function test_routes_are_tidied_and_core_namespaces_refused(): void {
		$this->assertSame( '/wc/v3/webhooks', AllowList::validate( 'rest', 'wc/v3/webhooks' )['value'] );
		$this->assertSame( '/wc/v3/webhooks', AllowList::validate( 'rest', '/wp-json/wc/v3/webhooks?x=1' )['value'] );

		foreach ( array( '/', '/wp', '/wp/v2', '/wp/v2/' ) as $route ) {
			$this->assertNotSame( '', AllowList::validate( 'rest', $route )['error'], "$route would defeat the freeze" );
		}
		$this->assertNotSame( '', AllowList::validate( 'rest', 'https://example.test/wp-json/wc/v3' )['error'] );
		$this->assertNotSame( '', AllowList::validate( 'rest', '/wc v3/<script>' )['error'] );
		$this->assertSame( '', AllowList::validate( 'rest', '/wp/v2/block-renderer' )['error'] );
	}

	/**
	 * Front-end paths and query arguments.
	 */

	public function test_a_path_matches_by_prefix_and_a_query_argument_on_any_page(): void {
		$list = $this->allow( array( 'paths' => array( '/checkout/order-pay', '?wc-api=' ) ) );

		$this->assertTrue( $list->matches_uri( '/checkout/order-pay/55/?pay_for_order=true' ) );
		$this->assertTrue( $list->matches_uri( '/anything/?wc-api=WC_Gateway_Paypal' ) );
		$this->assertTrue( $list->matches_uri( '/?wc-api=wc_stripe' ) );
		$this->assertFalse( $list->matches_uri( '/checkout/' ) );
		$this->assertFalse( $list->matches_uri( '/wp-comments-post.php' ) );
	}

	public function test_a_query_value_and_a_path_can_be_combined(): void {
		$list = $this->allow( array( 'paths' => array( '?callback=gravityformsstripe', '/pay?token=abc' ) ) );

		$this->assertTrue( $list->matches_uri( '/?callback=gravityformsstripe' ) );
		$this->assertFalse( $list->matches_uri( '/?callback=other' ) );
		$this->assertTrue( $list->matches_uri( '/pay/now?token=abc' ) );
		$this->assertFalse( $list->matches_uri( '/pay/now?token=xyz' ) );
		$this->assertFalse( $list->matches_uri( '/elsewhere?token=abc' ) );
	}

	public function test_a_site_in_a_sub_directory_matches_without_its_folder(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.test/blog' );
		$list = $this->allow( array( 'paths' => array( '/checkout/order-pay' ) ) );

		$this->assertTrue( $list->matches_uri( '/blog/checkout/order-pay/5/' ) );
	}

	public function test_paths_that_would_match_every_page_are_refused(): void {
		foreach ( array( '/', '?', 'https://example.test/pay', 'checkout', '/pay now', '?a b=1', '/x?a=b c' ) as $entry ) {
			$this->assertNotSame( '', AllowList::validate( 'paths', $entry )['error'], "$entry should be refused" );
		}
		foreach ( array( '/checkout/order-pay', '?wc-api=', '?wc-api=wc_stripe', '/?wc-api=x', '/pay?token=abc' ) as $entry ) {
			$this->assertSame( '', AllowList::validate( 'paths', $entry )['error'], "$entry should be accepted" );
		}
	}

	/**
	 * Ajax actions and users.
	 */

	public function test_ajax_actions_are_lower_cased_and_checked(): void {
		$this->assertSame( 'subscribe_form', AllowList::validate( 'ajax', 'Subscribe_Form' )['value'] );
		$this->assertNotSame( '', AllowList::validate( 'ajax', 'bad action!' )['error'] );

		$list = $this->allow( array( 'ajax' => array( 'subscribe_form' ) ) );
		$this->assertTrue( $list->matches_ajax( 'subscribe_form' ) );
		$this->assertFalse( $list->matches_ajax( 'delete_everything' ) );
	}

	public function test_users_are_found_by_login_email_or_id(): void {
		$user = (object) array( 'ID' => 7 );
		Functions\when( 'get_user_by' )->alias(
			static function ( $field, $value ) use ( $user ) {
				$known = array(
					'login' => 'editor-in-chief',
					'email' => 'ops@example.com',
					'id'    => 7,
				);

				return isset( $known[ $field ] ) && $known[ $field ] === $value ? $user : false;
			}
		);

		$this->assertSame( 7, AllowList::validate( 'users', 'editor-in-chief' )['value'] );
		$this->assertSame( 7, AllowList::validate( 'users', 'ops@example.com' )['value'] );
		$this->assertSame( 7, AllowList::validate( 'users', '7' )['value'] );
		$this->assertNotSame( '', AllowList::validate( 'users', 'nobody' )['error'] );

		$list = $this->allow( array( 'users' => array( 7 ) ) );
		$this->assertTrue( $list->matches_user( 7 ) );
		$this->assertFalse( $list->matches_user( 8 ) );
		$this->assertFalse( $list->matches_user( 0 ) );
	}

	/**
	 * Parsing, saving, hooks.
	 */

	public function test_a_form_text_is_split_by_line_deduplicated_and_bad_lines_reported(): void {
		$parsed = AllowList::parse( 'rest', "/wc/v3/webhooks\n\n  /wc/v3/webhooks  \n/wp/v2\n/my-plugin/v1/callback" );

		$this->assertSame( array( '/wc/v3/webhooks', '/my-plugin/v1/callback' ), $parsed['entries'] );
		$this->assertCount( 1, $parsed['rejected'] );
		$this->assertSame( '/wp/v2', $parsed['rejected'][0]['line'] );
	}

	public function test_saving_keeps_good_entries_when_one_line_is_wrong(): void {
		$settings = new Settings();
		Actions\expectDone( 'axle_read_only_mode_allow_list_saved' )->once()->with( \Mockery::type( 'array' ), false );

		$rejected = $settings->save_allow(
			array(
				'rest' => "/wc/v3/webhooks\n/",
				'ajax' => 'subscribe_form',
			)
		);

		$this->assertSame( array( '/wc/v3/webhooks' ), $settings->allow( 'rest' ) );
		$this->assertSame( array( 'subscribe_form' ), $settings->allow( 'ajax' ) );
		$this->assertArrayHasKey( 'rest', $rejected );
		$this->assertArrayNotHasKey( 'ajax', $rejected );
	}

	public function test_one_entry_can_be_added_and_removed(): void {
		$settings = new Settings();

		$this->assertSame( '', $settings->add_allow( 'paths', '?wc-api=' ) );
		$this->assertSame( '', $settings->add_allow( 'paths', '?wc-api=' ), 'Adding twice is harmless.' );
		$this->assertSame( array( '?wc-api=' ), $settings->allow( 'paths' ) );
		$this->assertNotSame( '', $settings->add_allow( 'paths', '/' ) );

		$this->assertTrue( $settings->remove_allow( 'paths', '?wc-api=' ) );
		$this->assertFalse( $settings->remove_allow( 'paths', '?wc-api=' ) );
		$this->assertSame( array(), $settings->allow( 'paths' ) );
	}

	public function test_code_can_refuse_an_entry(): void {
		Filters\expectApplied( 'axle_read_only_mode_validate_allow_entry' )->once()->andReturn( 'Our policy does not allow that.' );

		$this->assertSame( 'Our policy does not allow that.', AllowList::validate( 'ajax', 'some_action' )['error'] );
	}

	public function test_each_part_of_the_list_holds_fifty_entries_unless_a_filter_says_otherwise(): void {
		$this->assertSame( 50, AllowList::limit() );
	}

	public function test_code_can_limit_the_list(): void {
		Filters\expectApplied( 'axle_read_only_mode_allow_limit' )->andReturn( 2 );
		$settings = new Settings();
		$settings->save_allow( array( 'ajax' => "a\nb\nc" ) );
		$this->assertSame( array( 'a', 'b' ), $settings->allow( 'ajax' ) );
	}

	public function test_each_entry_says_what_it_matches(): void {
		$routes = array( '/wc/v3/webhooks', '/wc/v3/webhooks/(?P<id>[\d]+)', '/wc/v3/orders' );

		$rest = AllowList::describe( 'rest', '/wc/v3/webhooks', $routes );
		$this->assertSame( 'good', $rest['tone'] );
		$this->assertSame( '2 routes', $rest['label'] );
		$this->assertSame( 'neutral', AllowList::describe( 'rest', '/nothing/here', $routes )['tone'] );

		$this->assertStringContainsString( 'wc-api argument', AllowList::describe( 'paths', '?wc-api=', array() )['detail'] );
		$this->assertStringContainsString( 'start with /checkout', AllowList::describe( 'paths', '/checkout', array() )['detail'] );
		$this->assertSame( 'neutral', AllowList::describe( 'ajax', 'no_such_action' )['tone'] );
		$this->assertSame( 'warning', AllowList::describe( 'users', 99 )['tone'], 'A removed user is flagged.' );
	}

	public function test_suggestions_only_come_from_filters_and_active_plugins_and_are_never_saved(): void {
		$this->assertSame( array(), AllowSuggestions::detect() );

		Filters\expectApplied( 'axle_read_only_mode_allow_suggestions' )->once()->andReturn(
			array(
				array(
					'plugin' => 'Shop',
					'type'   => 'paths',
					'value'  => '?shop-api=',
					'why'    => 'Callbacks.',
				),
				array( 'plugin' => 'Broken' ),
				array(
					'plugin' => 'Odd',
					'type'   => 'nonsense',
					'value'  => 'x',
					'why'    => 'y',
				),
			)
		);

		$suggestions = AllowSuggestions::detect();

		$this->assertCount( 1, $suggestions );
		$this->assertSame( '?shop-api=', $suggestions[0]['value'] );
		$this->assertArrayNotHasKey( Settings::OPTION, $this->options, 'Nothing is added without a click.' );
	}

	/**
	 * The guards.
	 */

	public function test_a_user_on_the_list_can_bypass_the_freeze(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 7 );

		$this->assertTrue( $this->state( array( 'users' => array( 7 ) ) )->can_bypass() );
		$this->assertFalse( $this->state( array( 'users' => array( 8 ) ) )->can_bypass() );
	}

	public function test_a_request_the_list_allows_goes_through_and_is_logged_as_allowed(): void {
		$_SERVER['REQUEST_URI']    = '/?wc-api=wc_stripe';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$state                     = $this->state( array( 'paths' => array( '?wc-api=' ) ) );
		$blocker                   = new Blocker( $state, new BlockedLog( new History() ) );

		Filters\expectApplied( 'axle_read_only_mode_allow_request' )->once()->with( true, 'POST /?wc-api=wc_stripe', 'front-end' )->andReturn( true );
		Actions\expectDone( 'axle_read_only_mode_allowed' )->once()->with( 'POST /?wc-api=wc_stripe', 'front-end', 0, 'settings' );

		$blocker->stop( 'POST /?wc-api=wc_stripe', 'front-end' );

		$entry = $this->options[ BlockedLog::OPTION ][0];
		$this->assertTrue( $entry['allowed'] );
		$this->assertSame( 'settings', $entry['via'] );
		$this->assertSame( 'POST /', $entry['where'], 'The query string is never stored.' );
		$this->assertSame( 0, ( new BlockedLog( new History() ) )->count() );
		$this->assertSame( 1, ( new BlockedLog( new History() ) )->count_allowed() );
	}

	public function test_code_can_still_stop_what_the_list_allows(): void {
		$_SERVER['REQUEST_URI']    = '/?wc-api=wc_stripe';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$state                     = $this->state( array( 'paths' => array( '?wc-api=' ) ) );
		$blocker                   = new Blocker( $state, new BlockedLog( new History() ) );

		Filters\expectApplied( 'axle_read_only_mode_allow_request' )->once()->andReturn( false );
		Functions\when( 'wp_die' )->alias(
			static function () {
				throw new \RuntimeException( 'stopped' );
			}
		);
		Functions\when( 'esc_html' )->returnArg();

		$this->expectException( \RuntimeException::class );
		try {
			$blocker->stop( 'POST /?wc-api=wc_stripe', 'front-end' );
		} finally {
			$this->assertFalse( $this->options[ BlockedLog::OPTION ][0]['allowed'] ?? false );
		}
	}

	public function test_code_can_allow_what_the_list_does_not(): void {
		$_SERVER['REQUEST_URI']    = '/sso/callback';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$blocker                   = new Blocker( $this->state(), new BlockedLog( new History() ) );

		Filters\expectApplied( 'axle_read_only_mode_allow_request' )->once()->with( false, 'POST /sso/callback', 'front-end' )->andReturn( true );

		$blocker->stop( 'POST /sso/callback', 'front-end' );

		$this->assertSame( 'code', $this->options[ BlockedLog::OPTION ][0]['via'] );
	}

	public function test_an_allowed_rest_route_gets_through_and_is_logged(): void {
		$request = \Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_method' )->andReturn( 'POST' );
		$request->shouldReceive( 'get_route' )->andReturn( '/wc/v3/webhooks/12' );
		$request->shouldReceive( 'get_json_params' )->andReturn( array() );
		$request->shouldReceive( 'get_body_params' )->andReturn( array() );
		$state = $this->state( array( 'rest' => array( '/wc/v3/webhooks' ) ) );
		$guard = new RestGuard( new Blocker( $state, new BlockedLog( new History() ) ) );

		Filters\expectApplied( 'axle_read_only_mode_allow_rest_request' )->once()->with( true, $request )->andReturn( true );

		$this->assertNull( $guard->guard( null, null, $request ) );
		$this->assertSame( 'REST POST /wc/v3/webhooks/12', $this->options[ BlockedLog::OPTION ][0]['where'] );
		$this->assertSame( 'rest', $this->options[ BlockedLog::OPTION ][0]['source'] );
	}

	public function test_an_ajax_action_on_the_list_gets_through_and_the_read_only_ones_are_not_logged(): void {
		$_REQUEST['action'] = 'subscribe_form';
		Functions\when( 'wp_doing_ajax' )->justReturn( true );
		Functions\when( 'remove_filter' )->justReturn( true );
		$state = $this->state( array( 'ajax' => array( 'subscribe_form' ) ) );
		$guard = new AdminGuard( new Blocker( $state, new BlockedLog( new History() ) ) );

		Actions\expectDone( 'axle_read_only_mode_allowed' )->once()->with( 'admin-ajax subscribe_form', 'ajax', 0, 'settings' );
		$guard->guard();
		$this->assertSame( 'ajax', $this->options[ BlockedLog::OPTION ][0]['source'] );

		$this->options = array();
		$_REQUEST['action'] = 'heartbeat';
		$guard->guard();
		$this->assertArrayNotHasKey( BlockedLog::OPTION, $this->options );

		unset( $_REQUEST['action'] );
	}

	public function test_the_log_keeps_a_few_allowed_requests_and_does_not_count_them_as_blocked(): void {
		$log = new BlockedLog( new History() );
		Filters\expectApplied( 'axle_read_only_mode_logged_allowed' )->andReturn( 2 );

		for ( $i = 0; $i < 5; $i++ ) {
			$log->add_allowed( 'POST /?wc-api=x', 'front-end', 'settings' );
		}
		$log->add( 'POST /checkout/', 'front-end' );

		$this->assertCount( 3, $this->options[ BlockedLog::OPTION ] );
		$this->assertSame( 1, $log->count() );
		$this->assertSame( 2, $log->count_allowed() );
	}

	public function test_the_log_keeps_twenty_five_allowed_requests_by_default(): void {
		$this->assertSame( 25, ( new BlockedLog( new History() ) )->allowed_limit() );
	}

	public function test_allowed_requests_do_not_use_up_the_visitor_limit(): void {
		Filters\expectApplied( 'axle_read_only_mode_logged_visitors' )->andReturn( 1 );
		Filters\expectApplied( 'axle_read_only_mode_logged_allowed' )->andReturn( 5 );
		$log = new BlockedLog( new History() );

		$log->add_allowed( 'POST /?wc-api=x', 'front-end', 'settings' );
		$log->add( 'POST /wp-comments-post.php', 'front-end' );

		$this->assertSame( 1, $log->count() );
	}
}
