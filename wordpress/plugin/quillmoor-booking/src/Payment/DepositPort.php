<?php
/**
 * Deposit contract.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Payment;

defined( 'ABSPATH' ) || exit;

/**
 * Starts a deposit payment for a held booking.
 */
interface DepositPort {

	/**
	 * Whether a test payment can be taken right now.
	 */
	public function is_available(): bool;

	/**
	 * URL that sends the customer to payment.
	 *
	 * @param array<string,mixed> $booking Booking row.
	 */
	public function pay_url( array $booking ): string;
}
