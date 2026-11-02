<?php
/**
 * Labelled fake gateway.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Payment;

use Quillmoor\Booking\Config;
use WC_Payment_Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Local and automated tests only. It is not Stripe and it moves no money.
 * It exists only while QHS_PAYMENT_MODE=fake.
 */
final class FakeGateway extends WC_Payment_Gateway {

	public const ID = 'qhs_fake';

	/**
	 * Set up the gateway.
	 */
	public function __construct() {
		$this->id                 = self::ID;
		$this->method_title       = 'Fake test gateway';
		$this->method_description = 'Marks orders paid without any payment. Local tests only, never Stripe.';
		$this->title              = 'Fake test gateway (not Stripe, no money moves)';
		$this->description        = 'Local test mode. No card is taken.';
		$this->has_fields         = false;
		$this->supports           = array( 'products' );
		$this->enabled            = 'fake' === Config::payment_mode() ? 'yes' : 'no';
	}

	/**
	 * Available only in fake mode.
	 */
	public function is_available() {
		return 'fake' === Config::payment_mode();
	}

	/**
	 * Mark the order paid.
	 *
	 * @param int $order_id Order id.
	 * @return array<string,string>
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $this->is_available() ) {
			return array( 'result' => 'failure' );
		}
		$order->payment_complete( 'fake-' . $order_id );
		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}
}
