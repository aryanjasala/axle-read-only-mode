<?php
/**
 * What a freeze would interrupt.
 *
 * @package AryanX\Axle\ReadOnlyMode
 */

namespace AryanX\Axle\ReadOnlyMode;

defined( 'ABSPATH' ) || exit;

/**
 * The "Before you freeze" checks.
 *
 * Each check looks at the site as it is now, for the length, start and scope
 * that were chosen, and reports an item: a tone, a word, a title and a few
 * details. The checks inform. They never stop a freeze.
 *
 * Other plugins add checks with the `axle_read_only_mode_checks` filter.
 */
final class Checks {

	private const TONES = array( 'good', 'warning', 'error', 'info', 'neutral' );

	/**
	 * Builds the facts every check reads: when the freeze would run, and what it would pause.
	 *
	 * @param array $args {
	 *     What the freeze would be.
	 *
	 *     @type int    $start    Unix time it starts, 0 for now.
	 *     @type int    $minutes  Length in minutes, 0 for no end.
	 *     @type string $scope    all, content or code. Default: the default scope.
	 *     @type bool   $cron     Whether scheduled tasks would be held. Default true.
	 *     @type bool   $visitors Whether visitor submissions would be paused. Default true.
	 * }
	 * @return array{start: int, end: int, until: int, minutes: int, scope: string, cron: bool, visitors: bool, now: int, user_id: int}
	 */
	public static function context( array $args = array() ): array {
		$now     = time();
		$start   = ! empty( $args['start'] ) ? max( $now, (int) $args['start'] ) : $now;
		$minutes = isset( $args['minutes'] ) ? max( 0, (int) $args['minutes'] ) : Freezer::default_duration();
		$end     = $minutes ? $start + $minutes * MINUTE_IN_SECONDS : 0;

		/**
		 * Filters how far ahead the checks look when a freeze has no end time.
		 *
		 * @param int $seconds Default 86400, one day.
		 */
		$open_window = max( HOUR_IN_SECONDS, (int) apply_filters( 'axle_read_only_mode_check_open_window', DAY_IN_SECONDS ) );

		return array(
			'start'    => $start,
			'end'      => $end,
			'until'    => $end ? $end : $start + $open_window,
			'minutes'  => $minutes,
			'scope'    => Scopes::normalize( isset( $args['scope'] ) ? (string) $args['scope'] : Scopes::default_scope() ),
			'cron'     => ! isset( $args['cron'] ) || (bool) $args['cron'],
			'visitors' => ! isset( $args['visitors'] ) || (bool) $args['visitors'],
			'now'      => $now,
			'user_id'  => get_current_user_id(),
		);
	}

	/**
	 * Runs every check.
	 *
	 * @param array $args See context().
	 * @return array{items: array[], editing: int, attention: int, context: array}
	 */
	public function run( array $args = array() ): array {
		$context = self::context( $args );

		$checks = array(
			'editing'         => array( $this, 'check_editing' ),
			'scheduled_posts' => array( $this, 'check_scheduled_posts' ),
			'orders'          => array( $this, 'check_orders' ),
			'cron'            => array( $this, 'check_cron' ),
		);

		/**
		 * Filters the checks on the "Before you freeze" card.
		 *
		 * Add a check with an ID of your own and a callable. It receives the
		 * context (start, end, until, minutes, scope, cron, visitors, now and
		 * user_id) and returns an item, or null to say nothing. An item is an
		 * array: `tone` (good, warning, error, info or neutral), `word` (a short
		 * label for the badge), `title`, and optionally `detail` (a sentence),
		 * `rows` (a list of short lines) and `count`. Remove a built-in check by
		 * unsetting its ID: editing, scheduled_posts, orders or cron.
		 *
		 * @param array<string, mixed> $checks Check ID => callable( array $context ): ?array.
		 * @param array                $context What the freeze would be.
		 */
		$checks = (array) apply_filters( 'axle_read_only_mode_checks', $checks, $context );

		$items = array();
		foreach ( $checks as $id => $callback ) {
			if ( ! is_callable( $callback ) ) {
				continue;
			}

			$item = call_user_func( $callback, $context );
			if ( is_array( $item ) && ! empty( $item['title'] ) ) {
				$items[] = self::item( (string) $id, $item );
			}
		}

		/**
		 * Filters the items on the "Before you freeze" card, after every check ran.
		 *
		 * @param array[] $items   Items: id, tone, word, title, detail, rows, count.
		 * @param array   $context What the freeze would be.
		 */
		$items = array_values( (array) apply_filters( 'axle_read_only_mode_check_items', $items, $context ) );

		$editing = 0;
		foreach ( $items as $item ) {
			if ( 'editing' === ( $item['id'] ?? '' ) ) {
				$editing = (int) ( $item['count'] ?? 0 );
			}
		}

		$result = array(
			'items'     => $items,
			'editing'   => $editing,
			'attention' => count(
				array_filter(
					$items,
					static function ( array $item ): bool {
						return in_array( $item['tone'], array( 'warning', 'error' ), true );
					}
				)
			),
			'context'   => $context,
		);

		/**
		 * Fires after the checks ran, from the screen, WP-CLI or an ability.
		 *
		 * @param array $result Items, the number of people editing, how many items need a look, and the context.
		 */
		do_action( 'axle_read_only_mode_checked', $result );

		return $result;
	}

	/**
	 * Cleans an item from a check, so one from another plugin cannot break the card.
	 *
	 * @param string $id   Check ID.
	 * @param array  $item Item.
	 * @return array{id: string, tone: string, word: string, title: string, detail: string, rows: string[], count: int}
	 */
	public static function item( string $id, array $item ): array {
		$tone = (string) ( $item['tone'] ?? 'neutral' );

		return array(
			'id'     => $id,
			'tone'   => in_array( $tone, self::TONES, true ) ? $tone : 'neutral',
			'word'   => (string) ( $item['word'] ?? '' ),
			'title'  => (string) $item['title'],
			'detail' => (string) ( $item['detail'] ?? '' ),
			'rows'   => array_values( array_map( 'strval', (array) ( $item['rows'] ?? array() ) ) ),
			'count'  => (int) ( $item['count'] ?? 0 ),
		);
	}

	/**
	 * People who have a post open in the editor right now.
	 *
	 * WordPress writes a lock (`_edit_lock`, "time:user") to a post every
	 * time the editor's heartbeat runs, and treats it as live for 150 seconds.
	 * The same window is used here.
	 *
	 * @param int $exclude_user User to leave out, such as the person about to freeze. 0 for nobody.
	 * @return array[] Each: post_id, title, user_id, name, time. Newest first.
	 */
	public function editors( int $exclude_user = 0 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own filter, so the window matches the one WordPress uses.
		$window = (int) apply_filters( 'wp_check_post_lock_window', 150 );
		$since  = time() - max( 30, $window );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A live read of who is editing right now, which must not be cached.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND CAST( SUBSTRING_INDEX( meta_value, ':', 1 ) AS UNSIGNED ) >= %d ORDER BY meta_id DESC LIMIT 100", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core table name.
				'_edit_lock',
				$since
			),
			ARRAY_A
		);

		$editors = array();
		foreach ( (array) $rows as $row ) {
			$parts   = explode( ':', (string) $row['meta_value'] );
			$user_id = isset( $parts[1] ) ? (int) $parts[1] : 0;
			$post_id = (int) $row['post_id'];
			$user    = $user_id ? get_userdata( $user_id ) : false;
			$status  = get_post_status( $post_id );

			if ( ! $user || ! $status || 'trash' === $status || $user_id === $exclude_user ) {
				continue;
			}

			$title     = get_the_title( $post_id );
			$editors[] = array(
				'post_id' => $post_id,
				'title'   => '' !== $title ? $title : __( '(no title)', 'axle-read-only-mode' ),
				'user_id' => $user_id,
				'name'    => $user->display_name,
				'time'    => (int) $parts[0],
			);
		}

		usort(
			$editors,
			static function ( array $a, array $b ): int {
				return $b['time'] <=> $a['time'];
			}
		);

		/**
		 * Filters the people the "Before you freeze" card says are editing.
		 *
		 * @param array[] $editors Each: post_id, title, user_id, name, time.
		 */
		return array_values( (array) apply_filters( 'axle_read_only_mode_check_editors', $editors ) );
	}

	/**
	 * Check: people with a post open.
	 *
	 * @param array $context See context().
	 * @return array
	 */
	public function check_editing( array $context ): array {
		if ( ! Scopes::covers( $context['scope'], Scopes::CONTENT ) ) {
			return array(
				'tone'   => 'neutral',
				'word'   => __( 'Not affected', 'axle-read-only-mode' ),
				'title'  => __( 'Editors are not interrupted', 'axle-read-only-mode' ),
				'detail' => __( 'This freeze leaves content alone, so people can keep saving posts.', 'axle-read-only-mode' ),
			);
		}

		$editors = $this->editors( (int) $context['user_id'] );
		if ( ! $editors ) {
			return array(
				'tone'  => 'good',
				'word'  => __( 'Clear', 'axle-read-only-mode' ),
				'title' => __( 'Nobody else has a post open', 'axle-read-only-mode' ),
				'count' => 0,
			);
		}

		$rows = array();
		foreach ( $editors as $editor ) {
			$rows[] = sprintf(
				/* translators: 1: person, 2: post title, 3: how long ago, e.g. "2 minutes". */
				__( '%1$s has "%2$s" open, seen %3$s ago', 'axle-read-only-mode' ),
				$editor['name'],
				$editor['title'],
				human_time_diff( $editor['time'] )
			);
		}

		return array(
			'tone'   => 'warning',
			'word'   => __( 'Editing now', 'axle-read-only-mode' ),
			'title'  => sprintf(
				/* translators: %d: number of people. */
				_n( '%d person has a post open in the editor', '%d people have a post open in the editor', count( $editors ), 'axle-read-only-mode' ),
				count( $editors )
			),
			'detail' => __( 'Their next save will be refused, and the editor tells them why. They can copy their changes out.', 'axle-read-only-mode' ),
			'rows'   => $rows,
			'count'  => count( $editors ),
		);
	}

	/**
	 * Check: scheduled posts due inside the freeze.
	 *
	 * @param array $context See context().
	 * @return array
	 */
	public function check_scheduled_posts( array $context ): array {
		$query = new \WP_Query(
			array(
				'post_type'              => 'any',
				'post_status'            => 'future',
				'posts_per_page'         => 10,
				'orderby'                => 'date',
				'order'                  => 'ASC',
				'no_found_rows'          => false,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'date_query'             => array(
					array(
						'column'    => 'post_date_gmt',
						'after'     => gmdate( 'Y-m-d H:i:s', (int) $context['now'] ),
						'before'    => gmdate( 'Y-m-d H:i:s', (int) $context['until'] ),
						'inclusive' => true,
					),
				),
			)
		);

		$total = (int) $query->found_posts;
		if ( ! $total ) {
			return array(
				'tone'  => 'good',
				'word'  => __( 'Clear', 'axle-read-only-mode' ),
				'title' => __( 'No scheduled posts are due', 'axle-read-only-mode' ),
				'count' => 0,
			);
		}

		$rows = array();
		foreach ( $query->posts as $post ) {
			$post   = get_post( $post );
			$rows[] = sprintf(
				/* translators: 1: post title, 2: when it is due, e.g. "in 20 minutes". */
				__( '"%1$s" is due %2$s', 'axle-read-only-mode' ),
				$post ? get_the_title( $post ) : '',
				$post ? sprintf( /* translators: %s: time span. */ __( 'in %s', 'axle-read-only-mode' ), human_time_diff( (int) strtotime( $post->post_date_gmt . ' UTC' ) ) ) : ''
			);
		}

		$held = $context['cron'] && Scopes::covers( $context['scope'], Scopes::cron_area( 'publish_future_post' ) );
		if ( $total > count( $rows ) ) {
			/* translators: %d: number of posts not listed. */
			$rows[] = sprintf( __( 'and %d more', 'axle-read-only-mode' ), $total - count( $rows ) );
		}

		return array(
			'tone'   => $held ? 'warning' : 'info',
			'word'   => $held ? __( 'Will be late', 'axle-read-only-mode' ) : __( 'Will publish', 'axle-read-only-mode' ),
			'title'  => sprintf(
				/* translators: %d: number of posts. */
				_n( '%d scheduled post is due during the freeze', '%d scheduled posts are due during the freeze', $total, 'axle-read-only-mode' ),
				$total
			),
			'detail' => $held
				? __( 'Scheduled tasks are held, so these publish when the freeze ends, not on time.', 'axle-read-only-mode' )
				: __( 'Scheduled tasks keep running in this freeze, so these publish on time.', 'axle-read-only-mode' ),
			'rows'   => $rows,
			'count'  => $total,
		);
	}

	/**
	 * Check: WooCommerce orders waiting for payment. Only when WooCommerce is active.
	 *
	 * @param array $context See context().
	 * @return array|null
	 */
	public function check_orders( array $context ): ?array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return null;
		}

		/**
		 * Filters how recent an unpaid WooCommerce order must be to count as waiting for payment.
		 *
		 * @param int $seconds Default 7200, two hours. Older unpaid orders are abandoned carts.
		 */
		$recent = max( MINUTE_IN_SECONDS, (int) apply_filters( 'axle_read_only_mode_check_order_age', 2 * HOUR_IN_SECONDS ) );

		$orders = wc_get_orders(
			array(
				'status'       => 'pending',
				'limit'        => 10,
				'paginate'     => true,
				'orderby'      => 'date',
				'order'        => 'DESC',
				'date_created' => '>' . ( (int) $context['now'] - $recent ),
			)
		);

		$total = is_object( $orders ) && isset( $orders->total ) ? (int) $orders->total : 0;
		if ( ! $total ) {
			return array(
				'tone'  => 'good',
				'word'  => __( 'Clear', 'axle-read-only-mode' ),
				'title' => __( 'No orders are waiting for payment', 'axle-read-only-mode' ),
				'count' => 0,
			);
		}

		if ( ! $context['visitors'] || ! Scopes::covers( $context['scope'], Scopes::CONTENT ) ) {
			return array(
				'tone'   => 'neutral',
				'word'   => __( 'Not affected', 'axle-read-only-mode' ),
				'title'  => sprintf(
					/* translators: %d: number of orders. */
					_n( '%d order is waiting for payment', '%d orders are waiting for payment', $total, 'axle-read-only-mode' ),
					$total
				),
				'detail' => __( 'Checkouts stay open in this freeze, so they can pay.', 'axle-read-only-mode' ),
				'count'  => $total,
			);
		}

		$rows = array();
		foreach ( $orders->orders as $order ) {
			$created = $order->get_date_created();
			$rows[]  = sprintf(
				/* translators: 1: order number, 2: how long ago, e.g. "12 minutes". */
				__( 'Order #%1$s, started %2$s ago', 'axle-read-only-mode' ),
				$order->get_order_number(),
				$created ? human_time_diff( $created->getTimestamp() ) : '?'
			);
		}

		return array(
			'tone'   => 'warning',
			'word'   => __( 'Mid-checkout', 'axle-read-only-mode' ),
			'title'  => sprintf(
				/* translators: %d: number of orders. */
				_n( '%d order is waiting for payment', '%d orders are waiting for payment', $total, 'axle-read-only-mode' ),
				$total
			),
			'detail' => __( 'Checkouts are paused, so these customers cannot finish paying until the freeze ends. Payment provider callbacks need the allow-list.', 'axle-read-only-mode' ),
			'rows'   => $rows,
			'count'  => $total,
		);
	}

	/**
	 * Check: scheduled tasks due inside the freeze, apart from scheduled posts, which have their own.
	 *
	 * @param array $context See context().
	 * @return array
	 */
	public function check_cron( array $context ): array {
		$crons = function_exists( '_get_cron_array' ) ? (array) _get_cron_array() : array();
		// A freeze that starts later does not hold what is overdue now: it runs first.
		$from  = $context['start'] > $context['now'] + MINUTE_IN_SECONDS ? $context['start'] : 0;
		$hooks = array();

		foreach ( $crons as $timestamp => $events ) {
			if ( $timestamp < $from ) {
				continue;
			}
			if ( $timestamp > $context['until'] ) {
				break;
			}

			foreach ( (array) $events as $hook => $scheduled ) {
				if ( 'publish_future_post' === $hook ) {
					continue;
				}
				$hooks[ $hook ]['count'] = ( $hooks[ $hook ]['count'] ?? 0 ) + count( (array) $scheduled );
				$hooks[ $hook ]['first'] = min( $hooks[ $hook ]['first'] ?? PHP_INT_MAX, (int) $timestamp );
			}
		}

		$held = array();
		foreach ( $hooks as $hook => $info ) {
			if ( $context['cron'] && Scopes::covers( $context['scope'], Scopes::cron_area( (string) $hook ) ) ) {
				$held[ $hook ] = $info;
			}
		}

		$total = array_sum( wp_list_pluck( $held, 'count' ) );
		if ( ! $total ) {
			return array(
				'tone'   => 'good',
				'word'   => __( 'Clear', 'axle-read-only-mode' ),
				'title'  => __( 'No scheduled tasks would be held', 'axle-read-only-mode' ),
				'detail' => $context['cron'] ? '' : __( 'Scheduled tasks keep running in this freeze.', 'axle-read-only-mode' ),
				'count'  => 0,
			);
		}

		uasort(
			$held,
			static function ( array $a, array $b ): int {
				return $a['first'] <=> $b['first'];
			}
		);

		$rows = array();
		foreach ( array_slice( $held, 0, 8, true ) as $hook => $info ) {
			$rows[] = sprintf(
				/* translators: 1: task name, 2: number of times, 3: when the first is due, e.g. "in 5 minutes" or "now". */
				_n( '%1$s, %2$d time, first %3$s', '%1$s, %2$d times, first %3$s', $info['count'], 'axle-read-only-mode' ),
				$hook,
				$info['count'],
				$info['first'] <= $context['now'] ? __( 'now', 'axle-read-only-mode' ) : sprintf( /* translators: %s: time span. */ __( 'in %s', 'axle-read-only-mode' ), human_time_diff( $info['first'] ) )
			);
		}
		if ( count( $held ) > count( $rows ) ) {
			/* translators: %d: number of tasks not listed. */
			$rows[] = sprintf( __( 'and %d more', 'axle-read-only-mode' ), count( $held ) - count( $rows ) );
		}

		return array(
			'tone'   => 'info',
			'word'   => __( 'Delayed', 'axle-read-only-mode' ),
			'title'  => sprintf(
				/* translators: %d: number of runs. */
				_n( '%d scheduled task is due during the freeze', '%d scheduled tasks are due during the freeze', $total, 'axle-read-only-mode' ),
				$total
			),
			'detail' => __( 'They are held and run when the freeze ends.', 'axle-read-only-mode' ),
			'rows'   => $rows,
			'count'  => $total,
		);
	}

	/**
	 * One sentence for the freeze confirm step and the admin bar notice.
	 *
	 * @param int $editing People editing.
	 * @return string Empty when nobody is.
	 */
	public static function editing_sentence( int $editing ): string {
		if ( $editing < 1 ) {
			return '';
		}

		return sprintf(
			/* translators: %d: number of people. */
			_n( '%d other person has a post open in the editor. Their next save will be refused.', '%d other people have a post open in the editor. Their next save will be refused.', $editing, 'axle-read-only-mode' ),
			$editing
		);
	}
}
