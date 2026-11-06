<?php
/**
 * Allow-list entries worth considering for plugins that are active.
 *
 * @package AryanX\Axle\ReadOnlyMode
 */

namespace AryanX\Axle\ReadOnlyMode;

defined( 'ABSPATH' ) || exit;

/**
 * Suggestions only. Nothing here is ever added by itself: the settings screen
 * shows an "Add" button for each, and the person decides.
 */
final class AllowSuggestions {

	/**
	 * Suggestions for the plugins found on this site.
	 *
	 * @return array<int, array{plugin: string, type: string, value: string, why: string}>
	 */
	public static function detect(): array {
		$found = array();

		if ( class_exists( 'WooCommerce' ) ) {
			$found[] = array(
				'plugin' => 'WooCommerce',
				'type'   => 'paths',
				'value'  => '?wc-api=',
				'why'    => __( 'Payment gateways such as PayPal call back through the wc-api argument.', 'axle-read-only-mode' ),
			);
			$found[] = array(
				'plugin' => 'WooCommerce',
				'type'   => 'rest',
				'value'  => '/wc/v3/webhooks',
				'why'    => __( 'Tools that manage WooCommerce webhooks through the REST API.', 'axle-read-only-mode' ),
			);
		}

		if ( defined( 'WC_STRIPE_VERSION' ) ) {
			$found[] = array(
				'plugin' => 'Stripe for WooCommerce',
				'type'   => 'paths',
				'value'  => '?wc-api=wc_stripe',
				'why'    => __( 'Stripe webhooks, such as payments confirmed while the site is frozen.', 'axle-read-only-mode' ),
			);
		}

		if ( class_exists( 'GFForms' ) ) {
			$found[] = array(
				'plugin' => 'Gravity Forms',
				'type'   => 'rest',
				'value'  => '/gf/v2/forms',
				'why'    => __( 'Form submissions through the Gravity Forms REST API. Each one saves an entry.', 'axle-read-only-mode' ),
			);
		}
		if ( function_exists( 'gf_stripe' ) ) {
			$found[] = array(
				'plugin' => 'Gravity Forms Stripe add-on',
				'type'   => 'paths',
				'value'  => '?callback=gravityformsstripe',
				'why'    => __( 'Stripe webhooks for Gravity Forms payments.', 'axle-read-only-mode' ),
			);
		}
		if ( function_exists( 'gf_paypal' ) ) {
			$found[] = array(
				'plugin' => 'Gravity Forms PayPal add-on',
				'type'   => 'paths',
				'value'  => '?callback=gravityformspaypal',
				'why'    => __( 'PayPal payment notifications for Gravity Forms.', 'axle-read-only-mode' ),
			);
		}

		/**
		 * Filters the allow-list suggestions shown on the settings screen.
		 *
		 * They only appear as "Add" buttons. Nothing is added without a click.
		 *
		 * @param array $suggestions Each suggestion is an array: plugin (name), type (rest, paths, ajax or users), value (the entry) and why (one sentence).
		 */
		$found = (array) apply_filters( 'axle_read_only_mode_allow_suggestions', $found );

		return array_values(
			array_filter(
				$found,
				static function ( $suggestion ): bool {
					return is_array( $suggestion ) && isset( $suggestion['plugin'], $suggestion['type'], $suggestion['value'], $suggestion['why'] ) && in_array( $suggestion['type'], AllowList::TYPES, true );
				}
			)
		);
	}
}
