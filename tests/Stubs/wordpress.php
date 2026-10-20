<?php
/**
 * Minimal stand-ins for WordPress classes and constants used in unit tests.
 *
 * @package AryanX\SiteReadOnlyMode
 */

// phpcs:ignoreFile

foreach ( array(
	'MINUTE_IN_SECONDS' => 60,
	'HOUR_IN_SECONDS'   => 3600,
	'DAY_IN_SECONDS'    => 86400,
	'WEEK_IN_SECONDS'   => 604800,
	'ARRAY_N'           => 'ARRAY_N',
) as $name => $value ) {
	defined( $name ) || define( $name, $value );
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Enough of WP_Error for code that creates one and reads its message.
	 */
	class WP_Error {
		/** @var string */
		private $code;
		/** @var string */
		private $message;
		/** @var mixed */
		private $data;

		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}

if ( ! class_exists( 'WP_Query' ) ) {
	/**
	 * Minimal WP_Query for unit tests. Each new query takes the next queued result.
	 */
	class WP_Query {

		/**
		 * Results for the next queries: each array( 'posts' => array(), 'found_posts' => int ).
		 *
		 * @var array[]
		 */
		public static $queue = array();

		/**
		 * Arguments of every query made, oldest first.
		 *
		 * @var array[]
		 */
		public static $made = array();

		/**
		 * Posts or IDs found.
		 *
		 * @var array
		 */
		public $posts = array();

		/**
		 * Total matches.
		 *
		 * @var int
		 */
		public $found_posts = 0;

		/**
		 * Runs the "query".
		 *
		 * @param array $args Query arguments.
		 */
		public function __construct( array $args = array() ) {
			self::$made[]      = $args;
			$next              = array_shift( self::$queue );
			$this->posts       = $next['posts'] ?? array();
			$this->found_posts = $next['found_posts'] ?? count( $this->posts );
		}
	}
}
