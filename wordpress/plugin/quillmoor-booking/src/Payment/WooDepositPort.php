<?php
/**
 * Deposit through WooCommerce.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Payment;

use Quillmoor\Booking\Config;
use Quillmoor\Booking\Security\BookingPolicy;
use Quillmoor\Booking\Security\StripeKeyGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Hands the booking to the cart, which WooHooks fills on a normal page request.
 */
final class WooDepositPort implements DepositPort {

	/**
	 * Whether a test payment can be taken.
	 */
	public function is_available(): bool {
		if ( StripeKeyGuard::is_blocked() ) {
			return false;
		}
		return 'fake' === Config::payment_mode() || StripeKeyGuard::has_test_keys();
	}

	/**
	 * URL that adds the deposit to the cart.
	 *
	 * @param array<string,mixed> $booking Booking row.
	 */
	public function pay_url( array $booking ): string {
		return add_query_arg(
			array(
				'qhs_pay'   => (int) $booking['id'],
				'qhs_token' => BookingPolicy::token_for( $booking ),
			),
			home_url( '/' )
		);
	}
}
