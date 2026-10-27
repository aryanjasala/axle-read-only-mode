<?php
/**
 * Tests for the freeze check inside abilities on WordPress 6.9 and 7.0.
 *
 * @package AryanX\Axle\ReadOnlyMode
 */

namespace AryanX\Axle\ReadOnlyMode\Tests\Unit;

use AryanX\Axle\ReadOnlyMode\Abilities;
use AryanX\Axle\ReadOnlyMode\BlockedLog;
use AryanX\Axle\ReadOnlyMode\Freezer;
use AryanX\Axle\ReadOnlyMode\History;
use AryanX\Axle\ReadOnlyMode\State;
use Brain\Monkey\Functions;

final class AbilitiesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'wp_date' )->justReturn( '14:30' );
		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ) {
				return $thing instanceof \WP_Error;
			}
		);
		$this->options['time_format'] = 'H:i';
	}

	private function freeze(): void {
		$this->options[ State::OPTION ] = array(
			'active' => true,
			'start'  => time() - 60,
			'end'    => time() + 600,
		);
	}

	/**
	 * Registers an ability through the wrapper, the way WordPress 6.9 would.
	 *
	 * @param string $name     Ability name.
	 * @param bool   $readonly Whether the ability only reads.
	 * @return callable The execute callback WordPress would keep.
	 */
	private function register( string $name, bool $readonly ): callable {
		Functions\when( 'wp_get_ability' )->justReturn(
			new class( $readonly ) {
				/** @var bool */
				private $readonly;

				public function __construct( bool $readonly ) {
					$this->readonly = $readonly;
				}

				public function get_meta(): array {
					return array( 'annotations' => array( 'readonly' => $this->readonly ) );
				}
			}
		);

		$history = new History();
		$state   = new State();
		$log     = new BlockedLog( $history );
		$args    = ( new Abilities( $state, new Freezer( $state, $log, $history ), $log ) )->gate_on_register(
			array(
				'execute_callback' => static function ( $input = null ) {
					return null === $input ? 'ran' : 'ran with ' . $input['id'];
				},
			),
			$name
		);

		return $args['execute_callback'];
	}

	public function test_an_ability_that_writes_is_refused_and_logged_during_a_freeze(): void {
		$this->freeze();
		$result = $this->register( 'e2e/write', false )( array( 'id' => 7 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'axle_read_only_mode', $result->get_error_code() );
		$this->assertSame( 'ability e2e/write', $this->options[ BlockedLog::OPTION ][0]['where'] );
	}

	public function test_read_only_abilities_and_our_own_still_run_during_a_freeze(): void {
		$this->freeze();

		$this->assertSame( 'ran', $this->register( 'e2e/read', true )() );
		$this->assertSame( 'ran with 7', $this->register( 'e2e/read', true )( array( 'id' => 7 ) ) );
		$this->assertSame( 'ran', $this->register( 'axle-read-only-mode/unfreeze', false )() );
	}

	public function test_abilities_that_write_run_when_the_site_is_not_frozen(): void {
		$this->assertSame( 'ran with 7', $this->register( 'e2e/write', false )( array( 'id' => 7 ) ) );
	}
}
