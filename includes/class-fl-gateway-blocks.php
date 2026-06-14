<?php
defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Registers ForgeLayer as a WooCommerce Blocks-compatible payment method.
 * This makes the gateway visible in the block-based Cart & Checkout pages.
 */
final class FL_Gateway_Blocks extends AbstractPaymentMethodType {

	protected $name = 'forgelayer';

	public function initialize() {
		$this->settings = get_option( 'woocommerce_forgelayer_settings', array() );
	}

	public function is_active() {
		return ! empty( $this->settings['enabled'] ) && $this->settings['enabled'] === 'yes';
	}

	public function get_payment_method_script_handles() {
		wp_register_script(
			'fl-blocks-checkout',
			FL_PLUGIN_URL . 'assets/js/fl-blocks-checkout.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			FL_PLUGIN_VERSION,
			true
		);
		return array( 'fl-blocks-checkout' );
	}

	public function get_payment_method_data() {
		// Build the enabled options list so JS can render the radio grid
		require_once FL_PLUGIN_DIR . 'includes/class-fl-price.php';
		require_once FL_PLUGIN_DIR . 'includes/class-fl-api.php';
		require_once FL_PLUGIN_DIR . 'includes/class-fl-gateway.php';

		$gateway = new FL_Gateway();
		$options = array();
		foreach ( $gateway->get_enabled_options() as $value => $label ) {
			$options[] = array(
				'value' => $value,
				'label' => $label,
			);
		}

		return array(
			'title'       => isset( $this->settings['title'] )       ? $this->settings['title']       : __( 'Pay with Cryptocurrency', 'forgelayer-woocommerce' ),
			'description' => isset( $this->settings['description'] ) ? $this->settings['description'] : '',
			'options'     => $options,
			'supports'    => $this->get_supported_features(),
			'i18n'        => array(
				'selectNetwork' => __( 'Select network & currency:', 'forgelayer-woocommerce' ),
				'noOptions'     => __( 'No payment options available. Please contact the store owner.', 'forgelayer-woocommerce' ),
			),
		);
	}

	public function get_supported_features() {
		return array( 'products' );
	}
}
