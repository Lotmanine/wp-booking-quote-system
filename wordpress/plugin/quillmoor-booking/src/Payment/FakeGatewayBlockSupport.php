<?php
/**
 * Checkout block support for the fake gateway.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Payment;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Quillmoor\Booking\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the fake gateway with the Checkout block, in fake mode only.
 */
final class FakeGatewayBlockSupport extends AbstractPaymentMethodType {

	/**
	 * Payment method name.
	 *
	 * @var string
	 */
	protected $name = FakeGateway::ID;

	/**
	 * No settings to load.
	 */
	public function initialize(): void {
	}

	/**
	 * Active only in fake mode.
	 */
	public function is_active() {
		return 'fake' === Config::payment_mode();
	}

	/**
	 * Script handles.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			'qhs-fake-gateway',
			QUILLMOOR_PLUGIN_URL . 'assets/fake-gateway-blocks.js',
			array( 'wc-blocks-registry', 'wp-element' ),
			QUILLMOOR_VERSION,
			true
		);
		return array( 'qhs-fake-gateway' );
	}

	/**
	 * Data for the script.
	 *
	 * @return array<string,mixed>
	 */
	public function get_payment_method_data() {
		return array(
			'title'    => 'Fake test gateway (not Stripe, no money moves)',
			'supports' => array( 'products' ),
		);
	}
}
