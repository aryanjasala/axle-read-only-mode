<?php
/**
 * Tests for the "Before you freeze" checks.
 *
 * @package AryanX\Axle\ReadOnlyMode
 */

namespace AryanX\Axle\ReadOnlyMode\Tests\Unit;

use AryanX\Axle\ReadOnlyMode\Checks;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class ChecksTest extends TestCase {

	/**
	 * What the fake database returns for the edit locks.
	 *
	 * @var array[]
	 */
	private array $locks = array();

	protected function setUp(): void {
		parent::setUp();

		$this->locks = array();

		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'human_time_diff' )->justReturn( '2 minutes' );
		Functions\when( 'get_post_status' )->justReturn( 'publish' );
		Functions\when( 'get_the_title' )->alias( static fn( $id ) => 'Post ' . ( is_object( $id ) ? $id->ID : $id ) );
		Functions\when( 'get_post' )->alias( static fn( $post ) => $post );
		Functions\when( 'get_userdata' )->alias( static fn( $id ) => in_array( (int) $id, array( 1, 2, 3 ), true ) ? (object) array( 'display_name' => 'User ' . $id ) : false );
		Functions\when( '_get_cron_array' )->justReturn( array() );
		Functions\when( 'wp_list_pluck' )->alias( static fn( $list, $field ) => array_map( static fn( $row ) => $row[ $field ], $list ) );

		$locks         = &$this->locks;
		$GLOBALS['wpdb'] = new class( $locks ) {
			/** @var string */
			public $postmeta = 'wp_postmeta';
			/** @var array */
			private $locks;

			public function __construct( array &$locks ) {
				$this->locks = &$locks;
			}

			public function prepare( $query, ...$args ) {
				return $query;
			}

			public function get_results( $query, $output = null ) {
				return $this->locks;
			}
		};
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	private function lock( int $post_id, int $user_id, int $seconds_ago = 20 ): void {
		$this->locks[] = array(
			'post_id'    => (string) $post_id,
			'meta_value' => ( time() - $seconds_ago ) . ':' . $user_id,
		);
	}

	private function item( array $result, string $id ): array {
		foreach ( $result['items'] as $item ) {
			if ( $id === $item['id'] ) {
				return $item;
			}
		}

		$this->fail( "No item $id." );
	}

	public function test_nothing_to_report_is_all_clear(): void {
		$result = ( new Checks() )->run( array( 'minutes' => 60 ) );

		$this->assertSame( 0, $result['editing'] );
		$this->assertSame( 0, $result['attention'] );
		$this->assertSame( 'good', $this->item( $result, 'editing' )['tone'] );
		$this->assertSame( 'good', $this->item( $result, 'scheduled_posts' )['tone'] );
		$this->assertSame( 'good', $this->item( $result, 'cron' )['tone'] );
		if ( ! function_exists( 'wc_get_orders' ) ) {
			$this->assertCount( 3, $result['items'], 'The orders check only appears when WooCommerce is active.' );
		}
	}

	public function test_people_with_a_post_open_are_listed_with_who_which_post_and_when(): void {
		$this->lock( 10, 2, 40 );
		$this->lock( 11, 3, 5 );
		$result = ( new Checks() )->run( array( 'minutes' => 60 ) );
		$item   = $this->item( $result, 'editing' );

		$this->assertSame( 2, $result['editing'] );
		$this->assertSame( 'warning', $item['tone'] );
		$this->assertSame( 'Editing now', $item['word'] );
		$this->assertCount( 2, $item['rows'] );
		$this->assertStringContainsString( 'User 3 has "Post 11" open', $item['rows'][0], 'The most recent lock is first.' );
		$this->assertSame( 1, $result['attention'] );
	}

	public function test_the_person_freezing_is_not_counted(): void {
		$this->lock( 10, 1 );

		$this->assertSame( 0, ( new Checks() )->run()['editing'] );
	}

	public function test_a_lock_of_a_deleted_user_or_trashed_post_is_ignored(): void {
		$this->lock( 10, 99 );
		Functions\when( 'get_post_status' )->justReturn( 'trash' );
		$this->lock( 11, 2 );

		$this->assertSame( 0, ( new Checks() )->run()['editing'] );
	}

	public function test_a_settings_and_code_freeze_does_not_interrupt_editors(): void {
		$this->lock( 10, 2 );
		$result = ( new Checks() )->run( array( 'scope' => 'code' ) );
		$item   = $this->item( $result, 'editing' );

		$this->assertSame( 'neutral', $item['tone'] );
		$this->assertSame( 0, $result['editing'] );
	}

	public function test_scheduled_posts_are_late_when_cron_is_held_and_on_time_when_it_is_not(): void {
		$post = new \WP_Post( array( 'ID' => 7, 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() + 600 ) ) );

		\WP_Query::$queue[] = array( 'posts' => array( $post ), 'found_posts' => 3 );
		$held               = $this->item( ( new Checks() )->run( array( 'minutes' => 60 ) ), 'scheduled_posts' );

		$this->assertSame( 'warning', $held['tone'] );
		$this->assertSame( 'Will be late', $held['word'] );
		$this->assertSame( 3, $held['count'] );
		$this->assertStringContainsString( 'and 2 more', end( $held['rows'] ) );

		\WP_Query::$queue[] = array( 'posts' => array( $post ), 'found_posts' => 1 );
		$free               = $this->item( ( new Checks() )->run( array( 'minutes' => 60, 'cron' => false ) ), 'scheduled_posts' );
		$this->assertSame( 'info', $free['tone'] );
		$this->assertSame( 'Will publish', $free['word'] );
	}

	public function test_the_posts_query_covers_the_freeze_window(): void {
		( new Checks() )->run( array( 'minutes' => 30 ) );
		$query = \WP_Query::$made[0];

		$this->assertSame( 'future', $query['post_status'] );
		$after  = strtotime( $query['date_query'][0]['after'] . ' UTC' );
		$before = strtotime( $query['date_query'][0]['before'] . ' UTC' );
		$this->assertEqualsWithDelta( 1800, $before - $after, 3 );
	}

	public function test_a_freeze_with_no_end_looks_a_day_ahead(): void {
		$context = Checks::context( array( 'minutes' => 0 ) );

		$this->assertSame( 0, $context['end'] );
		$this->assertEqualsWithDelta( 86400, $context['until'] - $context['now'], 3 );
	}

	public function test_a_freeze_scheduled_for_later_looks_at_its_own_window(): void {
		$start   = time() + 7200;
		$context = Checks::context( array( 'minutes' => 60, 'start' => $start ) );

		$this->assertSame( $start, $context['start'] );
		$this->assertSame( $start + 3600, $context['until'] );
	}

	public function test_scheduled_tasks_due_in_the_window_are_grouped_by_hook(): void {
		$now = time();
		Functions\when( '_get_cron_array' )->justReturn(
			array(
				$now + 60   => array(
					'acme_sync'           => array( 'a' => array(), 'b' => array() ),
					'publish_future_post' => array( 'c' => array() ),
				),
				$now + 7200 => array( 'too_late' => array( 'd' => array() ) ),
			)
		);

		$item = $this->item( ( new Checks() )->run( array( 'minutes' => 60 ) ), 'cron' );

		$this->assertSame( 2, $item['count'], 'Scheduled posts have their own item, and tasks past the end are left out.' );
		$this->assertSame( 'info', $item['tone'] );
		$this->assertStringContainsString( 'acme_sync, 2 times', $item['rows'][0] );
	}

	public function test_tasks_that_keep_running_are_not_reported_as_held(): void {
		$now = time();
		Functions\when( '_get_cron_array' )->justReturn( array( $now + 60 => array( 'wp_version_check' => array( 'a' => array() ) ) ) );

		$this->assertSame( 0, $this->item( ( new Checks() )->run( array( 'scope' => 'content' ) ), 'cron' )['count'], 'A content freeze lets update checks run.' );
		$this->assertSame( 0, $this->item( ( new Checks() )->run( array( 'cron' => false ) ), 'cron' )['count'] );
		$this->assertSame( 1, $this->item( ( new Checks() )->run( array( 'scope' => 'code' ) ), 'cron' )['count'] );
	}

	public function test_orders_waiting_for_payment_show_only_with_woocommerce(): void {
		$order = new class() {
			public function get_order_number() {
				return '88';
			}

			public function get_date_created() {
				return new class() {
					public function getTimestamp() {
						return time() - 600;
					}
				};
			}
		};
		Functions\when( 'wc_get_orders' )->justReturn( (object) array( 'total' => 2, 'orders' => array( $order ) ) );

		$item = $this->item( ( new Checks() )->run(), 'orders' );
		$this->assertSame( 'warning', $item['tone'] );
		$this->assertSame( 2, $item['count'] );
		$this->assertStringContainsString( 'Order #88', $item['rows'][0] );

		$open = $this->item( ( new Checks() )->run( array( 'visitors' => false ) ), 'orders' );
		$this->assertSame( 'neutral', $open['tone'], 'Checkouts stay open, so nobody is interrupted.' );
	}

	public function test_other_plugins_add_a_check_and_a_bad_item_cannot_break_the_card(): void {
		Functions\when( 'wc_get_orders' )->justReturn( (object) array( 'total' => 0, 'orders' => array() ) );
		Filters\expectApplied( 'axle_read_only_mode_checks' )->once()->andReturnUsing(
			static function ( array $checks ) {
				$checks['acme_backup'] = static function ( array $context ) {
					return array(
						'tone'  => 'purple',
						'word'  => 'Running',
						'title' => 'A backup is running',
					);
				};
				$checks['acme_silent'] = static function () {
					return null;
				};
				$checks['acme_broken'] = 'not_a_function';

				return $checks;
			}
		);
		Actions\expectDone( 'axle_read_only_mode_checked' )->once();

		$item = $this->item( ( new Checks() )->run(), 'acme_backup' );

		$this->assertSame( 'neutral', $item['tone'], 'A tone outside the five becomes neutral.' );
		$this->assertSame( 'A backup is running', $item['title'] );
	}

	public function test_the_confirm_sentence_counts_people(): void {
		$this->assertSame( '', Checks::editing_sentence( 0 ) );
		$this->assertStringContainsString( '1 other person', Checks::editing_sentence( 1 ) );
		$this->assertStringContainsString( '3 other people', Checks::editing_sentence( 3 ) );
	}
}
